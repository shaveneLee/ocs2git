<?php

App::uses('AppModel', 'Model');
App::uses('OrderContext', 'Model');
App::uses('OrderFactory', 'Model');

class OrderHeader extends AppModel {

    var $mainField = 'id';
    var $prefix = '';
    var $redis_key_prefix = 'order_header_';

    // 查找主要字段信息
    function findMainField($id) {
        $this->recursive = -1;

        $model = $this->find('first', array(
            'conditions' => array(
                'id' => $id,
            ),
            'fields' => $this->mainField
        ));

        return !empty($model) ? $model[$this->name][$this->mainField] : null;
    }

    function switchGit() {
        return 'olo';
    }

    /**
     * 保存记录本次提交需要审核的订单头ID
     *
     * @Author   lishirong
     *
     * @DateTime 2017-05-20T10:30:29+0800
     */
    public function cacheNeedAuditHeaderIds($header_id = null) {
        global $g_UserId;
        $header_ids = Cache::read('OrderHeader/needAuditHeaderIds_' . $g_UserId);
        $header_ids[] = $header_id;
        $header_ids = array_unique($header_ids);
        Cache::write('OrderHeader/needAuditHeaderIds_' . $g_UserId, $header_ids);
    }

    /**
     * 获取本次保存需要审核的订单头ID
     *
     * @Author   lishirong
     *
     * @DateTime 2017-05-20T10:30:08+0800
     *
     * @return   [type]                   [description]
     */
    public function getNeedAuditHeaderIds() {
        global $g_UserId;
        return Cache::read('OrderHeader/needAuditHeaderIds_' . $g_UserId);
    }

    /**
     * 根据ID更新指定字段值
     *
     * @Author   lishirong
     *
     * @DateTime 2017-02-23T15:09:08+0800
     *
     * @param    [type]                   $id              [description]
     * @param    array                    $field_rel_value [description]
     *
     * @return   [type]                                    [description]
     */
    function updateFieldById($id = null, $field_rel_value = array()) {
        $this->recursive = -1;
        $model = $this->find('first', array(
            'conditions' => array(
                'id' => $id,
            ),
            'fields' => array(
                'id'
            )
        ));
        if (empty($model)) {
            return false;
        }

        $save_model = array();
        $save_model['id'] = $id;
        $save_model = array_merge($save_model, $field_rel_value);

        return $this->save($save_model);
    }

    /**
     * 保存log
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-11T11:31:09+0800
     *
     * @param    [type]                   $created [description]
     *
     * @return   [type]                            [description]
     */
    function afterSave($created) {
        if (isset($this->data[$this->name]['id']) && !empty($this->data[$this->name]['id'])) {
            global $g_Commons;
            $g_OrderLog = $g_Commons->GlobalModel('OrderLog');
            $line_ids = $this->find('list', array(
                'joins' => array(
                    array(
                        'table' => 'order_lines',
                        'alias' => 'OrderLine',
                        'type' => 'inner',
                        'conditions' => 'OrderHeader.id = OrderLine.header_id'
                    ),
                ),
                'conditions' => array(
                    'OrderHeader.id' => $this->data[$this->name]['id'],
                ),
                'fields' => array(
                    'OrderLine.id',
                )
            ));
            $g_OrderLog->createVersion($line_ids); //保存log版本
        }
    }

    /**
     * 检查url传参数是否正确
     *
     * @Author   lishirong
     *
     * @DateTime 2016-04-07T14:30:55+0800
     *
     * @param    [type]                   $all_params [description]
     * @param    [type]                   $method     [description]
     *
     * @return   [type]                               [description]
     */
    function checkUrlParams($all_params = null, $method = null) {
        switch ($method) {
            case 'pop_new_order':
                if (!isset($all_params['P']['action']) || !in_array($all_params['P']['action'], array('add', 'edit'))) {
                    return false;
                }
                $action = $all_params['P']['action'];
                if ('add' == $action) {
                    if (empty($all_params['P']['type'])) {
                        return false;
                    }
                    switch ($all_params['P']['type']) {
                        case OCS_ORDER_HEADER_TYPE_RETURN: //退货订单
                            if (!empty($all_params['P']['line_ids'])) {
                                return true;
                            }
                            if (!isset($all_params['P']['notice_detail_id_rel_line_id']) && empty($all_params['P']['notice_detail_id_rel_line_id'])) {
                                return false;
                            }
                            $rel_ids = explode(',', $all_params['P']['notice_detail_id_rel_line_id']);
                            foreach ($rel_ids as $rel_id) {
                                list($notice_detail_id, $ebs_order_line_id) = explode(':', $rel_id);
                                if (empty($notice_detail_id) || empty($ebs_order_line_id)) {
                                    return false;
                                }
                            }
                        break;
                        case OCS_ORDER_HEADER_TYPE_SWAP: //换货订单
                        case OCS_ORDER_HEADER_TYPE_SWAP_MTL: //换货订单(物料)
                            if (!isset($all_params['P']['line_ids']) && empty($all_params['P']['line_ids'])
                                 && !isset($all_params['P']['index_key']) && empty($all_params['P']['index_key'])) {
                                return false;
                            }
                        break;
                    }
                } else if ('edit' == $action) {
                    if (!isset($all_params['P']['header_id'])) {
                        return false;
                    }
                }
            break;
            default:
                return false;
            break;
        }

        return true;
    }

    /**
     * 验证创建在同一个退货订单的字段
     *
     * 对数据进行分组,以下条件相同的情况下,允许创建在一张退货订单里
     * 收单客户、收单地点；
     * 收货客户、收货地点；
     * 客户订单号、币种；
     * 发货组织、业务实体
     * 原销售订单订单类型
     *
     * @Author   zhangguocai
     *
     * @DateTime 2016-05-17T19:46:55+0800
     *
     * @return   [type]                   [description]
     */
    function sameRetrunOrderfields() {

        $verify_fields = array(
            'org_id' => array('label' => '组织ID'),
            'ship_site_use_id' => array('label' => '收货客户地址', 'ebs_field' => 'SHIP_TO_ORG_ID'),
            'bill_site_use_id' => array('label' => '收单客户地址', 'ebs_field' => 'INVOICE_TO_ORG_ID'),
            //'ebs_account_cno' => array('label' => '客户单号', 'ebs_field' => 'CUST_PO_NUMBER'),
            'currency_id' => array('label' => '币种', 'ebs_field' => 'TRANSACTIONAL_CURR_CODE'),
            'ship_from_org_id' => array('label' => '发货组织'),
            'order_type' => array('label' => '订单类型', 'ebs_field' => 'ORDER_TYPE_ID'),
        );

        return $verify_fields;
    }

    /**
     * 通过org_id获取退货订单默认的行类型
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-05T19:15:53+0800
     *
     * @param    [type]                   $org_id [业务实体]
     * @param    [type]                   $currency_id [币种]
     *
     * @return   [type]                           [description]
     */
    function getDefaultOrderLineTypeIdByOrgId($org_id = null, $currency_id = null) {
        if (empty($org_id)) {
            return null;
        }
        global $g_Commons;
        $g_Enum = $g_Commons->GlobalModel('Enum');

        $conds = array(
            'Enum.dict_name' => 'Ebs.order_detail_type',
            'Enum.alias' => $org_id,
        );
        if (OCS_CURRENCY_USD == $currency_id) {
            $conds['Enum.label LIKE'] = '%出口%退货%';
        } else {
            $conds['Enum.label LIKE'] = '%国内%退货%';
        }
        $enum = $g_Enum->find('first', array(
            'conditions' => $conds,
            'fields' => array(
                'Enum.value',
            )
        ));

        return !empty($enum) ? $enum['Enum']['value'] : 0;
    }

    /**
     * 初始化录单界面字段
     *
     * @Author   lishirong
     *
     * @DateTime 2016-04-07T14:31:07+0800
     *
     * @param    [type]                   $all_params [description]
     *
     * @return   [type]                               [description]
     */
    function initLayout($all_params) {
        global $g_Commons;
        global $g_BizId;
        $g_Enum = $g_Commons->GlobalModel('Enum');
        $g_EbsDbo = $g_Commons->GlobalModel('EbsDbo');

        $result = array();
        $layout = array();

        $order_line_type_list = array();
        $mto_no_list = array();
        $default_line_type_id = 0;
        $subinventory_codes = array();
        if (isset($all_params['P']['org_id'])) {
            $order_line_type_list = $g_Enum->getEnumListsByDictNameAndAlias('Ebs.order_detail_type', $all_params['P']['org_id']);
            $currency_id = isset($all_params['P']['currency_id']) ? $all_params['P']['currency_id'] : null;
            $default_line_type_id = $this->getDefaultOrderLineTypeIdByOrgId($all_params['P']['org_id'], $currency_id);
        }

        if (isset($all_params['P']['organization_id'])) {
            //通过发货组织找对应业务实体，再通过业务实体找对应工厂
            $organization_rel_org_id = $g_Enum->getEnumAliasByNameAndValue('Ebs.organization', $all_params['P']['organization_id']);
            $mto_no_list = $g_Enum->getMtoNoListByOrgId($organization_rel_org_id);
            foreach ($mto_no_list as $idx => $mto_no) {
                unset($mto_no_list[$idx]);
                $mto_no_list[$mto_no] = $mto_no;
            }
        }

        $subinventory_code_list = array();
        $locator_list = array();
        if (!empty($mto_no_list)) {
            $secondary = $g_EbsDbo->find('all', array(
                'joins' => array(
                    array(
                        'table' => 'Apps.Org_Organization_Definitions',
                        'alias' => 'Org',
                        'type' => 'inner',
                        'conditions' => 'SEC.Organization_Id = Org.Organization_Id',
                    ),
                    array(
                        'table' => 'Apps.Pjm_Seiban_Numbers_v',
                        'alias' => 'PJM',
                        'type' => 'inner',
                        'conditions' => 'SEC.Attribute1 = PJM.Project_Id',
                    ),
                ),
                'main_table' => array(
                    'Apps.Mtl_Secondary_Inventories' => 'SEC',
                ),
                'conditions' => array(
                    'PJM.PROJECT_NUMBER' => $mto_no_list,
                ),
                'fields' => array(
                    'PJM.Project_Id',
                    'SEC.Secondary_Inventory_Name',
                    'SEC.Description',
                    'SEC.Organization_Id',
                    'PJM.Project_Number',
                    'PJM.Project_Name',
                ),
                'order' => array(
                    'SEC.Secondary_Inventory_Name asc'
                )
            ));

            foreach ($secondary as $temp) {
                $subinventory_codes[$temp['SEC']['SECONDARY_INVENTORY_NAME']] = $temp['SEC']['SECONDARY_INVENTORY_NAME'];
                $locator = $temp['SEC']['SECONDARY_INVENTORY_NAME'] . '.' . $temp['PJM']['PROJECT_NUMBER'] . '.';
                $locator_list[$locator] = $locator;
            }
        }

        $return_reason_list = $g_Enum->getEnumListByDictName('Ebs.memo_reason'); //退货原因
        switch ($g_BizId) {
            default:
                //隐藏字段
                $layout[] = array('field' => 'OrderLine.rel_ebs_order_line_id', 'label' => 'rel_ebs_order_line_id', 'class' => 'hidden');
                $layout[] = array('field' => 'OrderLine.rel_req_id', 'label' => 'rel_req_id', 'class' => 'hidden');
                $layout[] = array('field' => 'OrderLine.product_id', 'label' => 'product_id', 'class' => 'hidden');
                $layout[] = array('field' => 'OrderLine.rel_notice_detail_id', 'label' => 'rel_notice_detail_id', 'class' => 'hidden');
                $layout[] = array('field' => 'OrderLine.rel_lot_number', 'label' => 'rel_lot_number', 'class' => 'hidden');
                $layout[] = array('field' => 'OrderLine.rel_notice_detail_quantity', 'label' => 'rel_notice_detail_quantity', 'class' => 'hidden');
                $layout[] = array('field' => 'OrderLine.account_mno', 'label' => 'account_mno', 'class' => 'hidden');
                $layout[] = array('field' => 'OrderLine.line_number', 'label' => 'line_number', 'class' => 'hidden');
                $layout[] = array('field' => 'OrderLine.is_onhold', 'label' => 'is_onhold', 'default' => 0, 'class' => 'hidden'); //标记该行是否需要暂挂

                if ('edit' == $all_params['P']['action']) {
                    $layout[] = array('field' => 'OrderLine.id', 'label' => 'line_id', 'class' => 'hidden');
                }
                $layout[] = array('field' => 'OrderLine.display_no', 'label' => 'display_no', 'class' => 'hidden'); //不保存，只记录显示的编号

                $layout[] = array('field' => 'DataView.display_no', 'label' => '编号', 'type' => 'readonly', 'style' => 'width:60px;');
                $layout[] = array('field' => 'DataView.rel_ebs_order_number', 'label' => '原EBS订单编号', 'type' => 'readonly', 'style' => 'width:120px;');
                $layout[] = array('field' => 'DataView.line_number', 'label' => '原EBS行号', 'type' => 'readonly', 'style' => 'width:90px;');
                $layout[] = array('field' => 'DataView.product_name', 'label' => '产品', 'type' => 'readonly', 'style' => 'width:130px;');
                $layout[] = array('field' => 'DataView.rel_lot_number', 'label' => '退货批次', 'type' => 'readonly', 'style' => 'width:120px;');
                $layout[] = array('field' => 'OrderLine.line_type_id', 'label' => '行类型', 'type' => 'select', 'options' => $order_line_type_list, 'default' => $default_line_type_id, 'style' => 'width:180px;', 'class' => 'required dup');
                $layout[] = array('field' => 'OrderLine.delivety_time', 'label' => '退货接收日期', 'default' => '', 'class' => 'input-datepicker required dup', 'style' => 'width:140px;');
                $layout[] = array('field' => 'DataView.sum_quantity', 'label' => '已退货数量', 'type' => 'readonly', 'style' => 'width:90px;', 'attr' => array('title' => '使用该订单行的所有有效退货订单数量之和（不包括当前行）'));
                $layout[] = array('field' => 'OrderLine.quantity', 'label' => '退货数量', 'class' => 'number quantity required dup', 'style' => 'width:120px;');
                $layout[] = array('field' => 'DataView.line_quantity', 'label' => '订单行数量', 'type' => 'readonly', 'style' => 'width:90px;', 'attr' => array('title' => '订单行数量'));
                $layout[] = array('field' => 'OrderLine.price', 'label' => '单价', 'class' => 'number price required', 'default' => 0, 'style' => 'width:100px;', 'attr' => array('readonly' => 'readonly'));
                $layout[] = array('field' => 'DataView.amount', 'label' => '总金额', 'type' => 'readonly', 'class' => 'amount', 'style' => 'width:100px');
                $layout[] = array('field' => 'DataView.account_cno', 'label' => '客户单号', 'type' => 'readonly', 'style' => 'width:120px;');
                $layout[] = array('field' => 'DataView.account_mno', 'label' => '客户料号', 'type' => 'readonly', 'style' => 'width:120px;');
                // $layout[] = array('field' => 'OrderLine.is_has_stock', 'label' => '出库存', 'type' => 'select', 'default' => $is_has_stock_default, 'class' => 'required dup', 'options' => array('0' => __('No'), '1' => __('Yes')), 'style' => 'width:100px;');
                $layout[] = array('field' => 'OrderLine.is_update_rel_contract', 'label' => '更新合同', 'type' => 'select', 'default' => 1, 'class' => 'required dup', 'attr' => array('readonly' => 'readonly'), 'options' => array('0' => __('No'), '1' => __('Yes')), 'style' => 'width:120px;');
                $layout[] = array('field' => 'OrderLine.mto_no', 'label' => '工厂', 'type' => 'select', 'options' => $mto_no_list, 'class' => 'chz-select mto-no required dup', 'style' => 'width:130px;');
                $layout[] = array('field' => 'OrderLine.subinventory_code', 'label' => '子库', 'type' => 'select', 'options' => $subinventory_codes, 'class' => 'chz-select subinventory-code required dup', 'style' => 'width:120px;');
                $layout[] = array('field' => 'OrderLine.locator', 'label' => '货位', 'type' => 'select', 'options' => $locator_list, 'class' => 'chz-select locator required dup', 'style' => 'width:200px;');
                $layout[] = array('field' => 'OrderLine.return_reason', 'label' => '退货原因', 'type' => 'select', 'options' => $return_reason_list, 'class' => 'required dup', 'style' => 'width:150px;');
                $layout[] = array('field' => 'OrderLine.remark', 'type' => 'textarea', 'label' => '备注', 'class' => 'dup', 'style' => 'width:140px;');
            break;
        }

        //确保layout里有设置这些key
        $attr_keys = array('type', 'class', 'style', 'attr', 'default');
        if (!empty($layout)) {
            foreach ($layout as $idx => $temp) {
                foreach ($attr_keys as $attr) {
                    if (!isset($temp[$attr])) {
                        $temp[$attr] = '';
                    }
                }
                $result[$temp['field']] = $temp;
            }
        }
        return $result;
    }

    /**
     * 通过field在data里索引对应值，如!isset则返回空。
     *
     * @Author   lishirong
     *
     * @DateTime 2016-01-13T16:57:08+0800
     *
     * @param    [type]                   $fields [description]
     * @param    [type]                   $data   [description]
     *
     * @return   [type]                           [description]
     */
    function getValueByFields($fields, $data) {
        if (empty($data)) {
            return '';
        }

        $value = '';
        $fields = explode('.', $fields);
        foreach ($fields as $idx => $field) {
            if ($idx > 0 && is_array($value) && !isset($value[$field])) {
                return '';
            }
            if (0 == $idx) {
                if (!isset($data[$field])) {
                    return '';
                }
                $value = $data[$field];
                continue;
            }
            if (!is_array($value)) {
                return '';
            }
            $value = $value[$field];
        }
        return $value;
    }

    /**
     * 设置layout对应字段值
     *
     * @Author   lishirong
     *
     * @DateTime 2016-01-13T16:57:46+0800
     *
     * @param    [type]                   $layout     [description]
     * @param    [type]                   $data       [description]
     */
    function setLayoutValue($layout, $data) {
        if (empty($layout)) {
            return $layout;
        }

        foreach ($layout as $idx => $temp) {
            $value = $this->getValueByFields($temp['field'], $data);
            if ('' === $value) {
                continue;
            }
            $temp['default'] = is_array($value) && array_key_exists('value', $value) ? $value['value'] : (!is_array($value) ? $value : '');
            if (is_array($value) && array_key_exists('options', $value)) {
                $temp['options'] = $value['options'];
            }
            if (is_array($value) && array_key_exists('option_datas', $value)) {
                $temp['option_datas'] = $value['option_datas'];
            }
            $layout[$idx] = $temp;
        }
        return $layout;
    }

    /**
     * 设置指定不可修改的字段
     *
     * @Author   lishirong
     *
     * @DateTime 2016-01-13T16:58:35+0800
     *
     * @param    [type]                   $layout          [description]
     * @param    array                    $disabled_fields [不可编辑字段]
     * @param    array                    $editable_fields [可编辑字段，当disabled_fields为*时 使用]
     */
    function setLayoutDisabledFields($layout = null, $disabled_fields = array(), $editable_fields = array()) {
        if (empty($disabled_fields)) {
            return $layout;
        }
        if (in_array('*', $disabled_fields)) {
            foreach ($layout as $field => $temp) {
                if (!empty($editable_fields) && in_array($field, $editable_fields)) { //不作处理
                    continue;
                }
                $temp['attr']['readonly'] = 'true';
                $layout[$field] = $temp;
            }
            return $layout;
        }

        foreach ($disabled_fields as $field) {
            if (isset($layout[$field])) {
                $layout[$field]['attr']['readonly'] = 'true';
            }
        }
        return $layout;
    }

    /**
     * 获取订单头行信息
     *
     * @Author   lishirong;
     *
     * @DateTime 2016-04-07T14:29:52+0800
     *
     * @param    [type]                   $all_params [description]
     *
     * @return   [type]                               [description]
     */
    function getOrderDatas($all_params) {
        global $g_Commons;
        global $g_BizId;
        $g_Enum = $g_Commons->GlobalModel('Enum');
        $g_AccountAddress = $g_Commons->GlobalModel('AccountAddress');

        $result = array();
        $header = array();
        $lines = array();
        $layouts = array();

        switch ($all_params['P']['action']) {
            case 'add':
                $type = $all_params['P']['type'];
                //获取初始化数据
                $model = new OrderContext($type);
                $po_data = $model->getInitModelData($all_params);
                // pr($po_data);

                $header = !empty($po_data['header']) ? $po_data['header'] : $header;
                $lines = !empty($po_data['lines']) ? $po_data['lines'] : $lines;
                $all_params = !empty($po_data['all_params']) ? $po_data['all_params'] : $all_params;
            break;
            case 'edit':
                $header_id = $all_params['P']['header_id'];
                //同步设置订单状态
                $this->syncOrderFlowStatusCode($header_id);

                $type = $this->get_by_id($header_id, 'type');

                //获取编辑数据
                $model = new OrderContext($type);
                $po_data = $model->getEditModelData($all_params);

                $header = !empty($po_data['header']) ? $po_data['header'] : $header;
                $lines = !empty($po_data['lines']) ? $po_data['lines'] : $lines;
                $index_orders = !empty($po_data['index_orders']) ? $po_data['index_orders'] : array(); //编辑订单行数据
                $all_params = !empty($po_data['all_params']) ? $po_data['all_params'] : $all_params;
            break;
        }

        //初始化layout
        $model = new OrderContext($type);
        $layout = $model->initLayout($all_params);

        //获取订单行类型list
        $g_OrderWorkflowAssignment = $g_Commons->GlobalModel('OrderWorkflowAssignment');
        $all_line_type_list = $g_OrderWorkflowAssignment->getAllAssignmentList(); //订单类型ID => array(订单行类型id => 订单行类型名称)
        $result['line_type_list'] = $all_line_type_list;

        //如果有默认订单类型，则订单行类型取对应的可用类型
        $available_line_type_list = array();
        if (!empty($header['order_type']['value']) || (!empty($header['order_type']['options']) && 1 == count($header['order_type']['options']))) {
            $order_type_id = !empty($header['order_type']['value']) ? $header['order_type']['value'] : current(array_keys($header['order_type']['options']));
            $available_line_type_list = !empty($all_line_type_list[$order_type_id]) ? $all_line_type_list[$order_type_id] : array();
        }

        //设置行信息
        if (!empty($lines)) {
            foreach ($lines as $idx => $line) {
                // 获取订单对应的全部收货地址数据
                $ship_use_datas = !empty($line['OrderLine']['shipping_site_use_id']['options']) ? $line['OrderLine']['shipping_site_use_id']['options'] : array();
                $ship_use_ids = array_keys($ship_use_datas);
                $account_addresses = $g_AccountAddress->getAddressAndContacts($ship_use_ids, 'SHIP_TO');
                foreach ($account_addresses as $addresses_idx => $address_data) {
                    $account_addresses[$addresses_idx] = array_change_key_case($address_data, CASE_LOWER);
                }
                $line['OrderLine']['shipping_site_use_id']['option_datas'] = $account_addresses;

                $line_id = isset($line['OrderLine']['id']) ? $line['OrderLine']['id'] : 0;
                //设置字段默认值以及options列表值（line数组以Model.field索引）
                $layout = $this->setLayoutValue($layout, $line);

                //设置可用订单行类型
                if (!empty($available_line_type_list) && !empty($layout['OrderLine.line_type_id'])) {
                    $layout['OrderLine.line_type_id']['options'] = $available_line_type_list;
                }

                //设置字段是否disabled
                $disabled_fields = array();
                $editable_fields = array();
                if (!empty($line_id)) {
                    if (isset($index_orders[$line_id])) {
                        $ebs_order_number = $index_orders[$line_id]['OrderHeader']['ebs_order_number'];
                        $flow_status_code = $index_orders[$line_id]['OrderLine']['flow_status_code'];

                        $delivered_qty = $index_orders[$line_id]['OrderHeader']['delivered_qty']; //已建交付数量
                        $posted_qty = $index_orders[$line_id]['OrderHeader']['posted_qty']; //已过帐数量
                        $order_quantity = $index_orders[$line_id]['OrderHeader']['quantity']; //订单总数量
                        if ('CLOSED' == $flow_status_code) { //订单行已经关闭，所有字段不允许修改
                            //获取disabled fields
                            $model = new OrderContext($type);
                            $disabled_fields = $model->getClosedLineDisabledFields();
                        }

                        //已建交付，则做相应限制
                        if (!empty($delivered_qty)) {
                            if ($delivered_qty == $order_quantity) {
                                $disabled_fields = array('*'); //所有字段不允许修改
                            } else if ($delivered_qty < $order_quantity) { //只有数量字段允许悠
                                $disabled_fields = array('*');
                                $editable_fields[] = 'OrderLine.quantity';
                            }
                        }
                    }
                }
                if (!empty($disabled_fields)) {
                    $disabled_fields = array_unique($disabled_fields);
                    $layout = $this->setLayoutDisabledFields($layout, $disabled_fields, $editable_fields);
                }

                $layouts[$idx] = $layout;
            }
        } else {
            $layouts[] = $layout;
        }

        //界面头
        $result['header'] = $header;

        //界面行
        $result['layouts'] = $layouts;

        //获取可进行复制的字段
        $dup_fields = array();
        if (!empty($result['layouts'])) {
            foreach ($result['layouts'] as $items) {
                foreach ($items as $item) {
                    if (isset($item['class']) && strstr($item['class'], 'dup')) {
                        $dup_fields[] = $item['field'];
                    }
                }
                break;
            }
        }
        $result['dup_fields'] = $dup_fields;

        //获取工厂、子库、货位联动数据
        $rel_mto_no_list = array();
        foreach ($layouts as $items) {
            foreach ($items as $item) {
                if (in_array($item['field'], array('OrderLine.mto_no', 'OrderLine.subinventory_code', 'OrderLine.locator'))) {
                    $rel_mto_no_list[$item['field']] = array_values($item['options']);
                }
            }
            break;
        }
        $result['rel_mto_no_list'] = $rel_mto_no_list;

        //界面标题
        $result['title_for_layout'] = $header['type']['label'];

        //订单类型与发货组织关系
        $type_id_rel_organization_id = array();
        if ('add' == $all_params['P']['action'] && !empty($header['order_type']['options'])) {
            $type_id_rel_organization_id = $g_Enum->getOrderTypeRelOrganizationByTypeIds(array_keys($header['order_type']['options']));

            //如果订单类型有默认值，则发货组织也默认对应的值
            if (!empty($header['order_type']['value']) && array_key_exists('ship_from_org_id', $header) && empty($header['ship_from_org_id']['value'])) {
                $header['ship_from_org_id']['value'] = !empty($type_id_rel_organization_id[$header['order_type']['value']]) ? $type_id_rel_organization_id[$header['order_type']['value']] : '';
            }
        }
        $result['type_id_rel_organization_id'] = $type_id_rel_organization_id;

        // 通过订单行数据提取各层地址信息
        $locations_set = $this->getAllLocationsFromDatas($lines);
        $result['locations_set'] = $locations_set;

        return $result;
    }

    /**
     * 新建订单时，根据发货明细行ID及订单行ID，获取退货行信息
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-13T17:22:23+0800
     *
     * @param    array                    $rel_ids [发货明细行ID：订单行ID]
     *
     * @return   [type]                            [description]
     */
    function getNewOrderLineData($rel_ids = array()) {
        if (empty($rel_ids)) {
            throw new Exception(__FUNCTION__ . '参数为空' , 1);
        }
        global $g_BizId;
        global $g_Commons;
        global $g_UserId;
        global $g_UserRealName;
        $g_EbsDbo = $g_Commons->GlobalModel('EbsDbo');
        $g_Req = $g_Commons->GlobalModel('Req');
        $g_Enum = $g_Commons->GlobalModel('Enum');
        $g_Type = $g_Commons->GlobalModel('Type');
        $g_User = $g_Commons->GlobalModel('User');
        $g_Account = $g_Commons->GlobalModel('Account');
        $g_ObjRelObj = $g_Commons->GlobalModel('ObjRelObj');
        $g_Currency = $g_Commons->GlobalModel('Currency');
        $g_OrderLine = $g_Commons->GlobalModel('OrderLine');
        $g_Product = $g_Commons->GlobalModel('Product');
        $g_AttrDics = $g_Commons->GlobalController('AttrDics');
        $g_AccountAddress = $g_Commons->GlobalModel('AccountAddress');

        $result = array();

        //获取发货相关信息
        $notice_details = $this->getNoticeDetailByDetailIdsAndLineIds($rel_ids);
        if (empty($notice_details)) {
            throw new Exception("满足条件的发货明细行不存在" , 1);
        }

        $is_set_header = false; //标记是否已经记录头信息
        $verify_rule_fields = array(); //验证唯一性

        $lines = array();
        $header = array();
        foreach ($notice_details as $num => $notice_detail) {
            $notice_detail_id = $notice_detail['DD']['NOTICE_DETAIL_ID'];
            /*
                需如下几个维度相同才可一起创建退货订单：
                收单客户、收单地点
                收货客户、收货地点
                订单类型
                客户订单号
                币种
                发货组织
                业务实体
             */
            $verify_fields = array(
                'OOL.ORG_ID' => '业务实体',
                'OOL.LINE_TYPE_ID' => '行类型',
                'OOL.SOLD_FROM_ORG_ID' => '发货实体',
                'OOL.SHIP_FROM_ORG_ID' => '发货组织',
                'OOL.SHIP_TO_ORG_ID' => '收货客户地址',
                'OOL.INVOICE_TO_ORG_ID' => '收单客户地址',
                // 'OOL.CUST_PO_NUMBER' => '客户单号',
                'OOH.ORDER_TYPE_ID' => '订单类型',
                'OOH.TRANSACTIONAL_CURR_CODE' => '币种',
            );
            $tmp_verify_rule_fields = array();
            foreach ($verify_fields as $key => $label) {
                list($idx, $field) = explode('.', $key);
                if (!array_key_exists($idx, $notice_detail) || !array_key_exists($field, $notice_detail[$idx])) {
                    throw new Exception('查询结果，字段#' . $key . '未select', 1);
                }
                $tmp_verify_rule_fields[$key] = $notice_detail[$idx][$field];
            }
            $verify_rule_fields = empty($verify_rule_fields) ? $tmp_verify_rule_fields : $verify_rule_fields;
            foreach ($verify_rule_fields as $key => $value) {
                if ($value != $tmp_verify_rule_fields[$key]) {
                    throw new Exception($verify_fields[$key] . '不相同，不允许创建退货订单', 1);
                }
            }

            //设置退货订单头信息（只取其中一行即可）
            if (!$is_set_header) {
                $req = $g_Req->find('first', array(
                    'joins' => array(
                        array(
                            'table' => 'contracts',
                            'alias' => 'Contract',
                            'type' => 'inner',
                            'conditions' => 'Contract.id = Req.rel_obj_id',
                        ),
                        array(
                            'table' => 'products',
                            'alias' => 'Product',
                            'type' => 'inner',
                            'conditions' => 'Product.id = Req.product_id',
                        ),
                    ),
                    'conditions' => array(
                        'Req.id' => $notice_detail['OOH']['ATTRIBUTE8'],
                    ),
                    'fields' => array(
                        'Req.id',
                        'Req.account_id',
                        'Req.currency_id',
                        'Req.exchange_ratio',
                        'Req.ship_site_use_id',
                        'Req.owner_user_id',
                        'Req.sales_user_id',
                        'Contract.account_mno',
                        'Contract.account_cno',
                        'Contract.mid_account_id',
                        'Contract.type_id',
                        'Contract.ship_from_org_id',
                        'Contract.bill_site_use_id',
                        'Product.id',
                        'Product.name',
                    )
                ));

                if (!empty($req)) {
                    $req = $g_AttrDics->formatAllModelData(array($req));
                    $req = current($req);
                    $type_arr = $req['Contract']['type_id'];
                    $currency_arr = $req['Req']['currency_id'];
                    $exchange_ratio = $req['Req']['exchange_ratio'];
                    $sales_user_arr = $req['Req']['sales_user_id'];
                } else { //在req表里找不到，则到order_headers表里查询
                    $order = $this->find('first', array(
                        'conditions' => array(
                            'OrderHeader.ebs_order_number' => $notice_detail['OOH']['ORDER_NUMBER'],
                        ),
                        'fields' => array(
                            'OrderHeader.currency_id',
                            'OrderHeader.exchange_ratio',
                            'OrderHeader.sales_user_id',
                            'OrderHeader.order_type',
                        )
                    ));
                    if (empty($order)) {
                        throw new Exception('非OCS订单，不支持此方式创建订单，EBS订单编号：' . $notice_detail['OOH']['ORDER_NUMBER'], 1);
                    }
                    $order = $g_AttrDics->formatAllModelData(array($order));
                    $order = current($order);

                    $type_arr = $order['OrderHeader']['order_type'];
                    $currency_arr = $order['OrderHeader']['currency_id'];
                    $exchange_ratio = $order['OrderHeader']['exchange_ratio'];
                    $sales_user_arr = $order['OrderHeader']['sales_user_id'];
                }

                //通过site_use_id获取客户信息
                $account = $g_AccountAddress->getAccountBySiteUseId($notice_detail['OOL']['SHIP_TO_ORG_ID']); //收货客户
                $mid_account = $g_AccountAddress->getAccountBySiteUseId($notice_detail['OOL']['INVOICE_TO_ORG_ID']); //收单客户（第三方付款平台）
                if (empty($account) || empty($mid_account)) {
                    throw new Exception('通过site_use_id(' . $notice_detail['OOL']['SHIP_TO_ORG_ID'] . ',' . $notice_detail['OOL']['INVOICE_TO_ORG_ID'] . ')获取客户信息，结果为空，请联系管理员处理', 1);
                }

                $header['org_id'] = $notice_detail['OOL']['ORG_ID'];

                //客户、第三方
                $header['account_id']['value'] = $account['Account']['id'];
                $header['account_id']['label'] = $account['Account']['short_name'];
                $header['mid_account_id']['value'] = $mid_account['Account']['id'];
                $header['mid_account_id']['label'] = $mid_account['Account']['short_name'];
                //负责客户list
                $header['account_id']['options'] = $g_Account->getList($g_Account->getMyChangeQuoteAccountIds(), array('id', 'short_name'), array('source_type' => 'EBS'));
                $header['mid_account_id']['options'] = $header['account_id']['options'];

                $header['ship_from_org_id']['value'] = $notice_detail['OOL']['SHIP_FROM_ORG_ID'];
                $header['ship_from_org_id']['label'] = $g_Enum->getLabelByDictNameAndValue('Ebs.organization', $notice_detail['OOL']['SHIP_FROM_ORG_ID']);
                //发货组织
                $header['ship_from_org_id']['options'] = $g_ObjRelObj->getAvailableOrgList();

                $header['ship_site_use_id']['value'] = $notice_detail['OOL']['SHIP_TO_ORG_ID'];
                $header['ship_site_use_id']['label'] = $g_AccountAddress->getAddressLabelBySiteUseId($notice_detail['OOL']['SHIP_TO_ORG_ID']);
                $header['bill_site_use_id']['value'] = $notice_detail['OOL']['INVOICE_TO_ORG_ID'];
                $header['bill_site_use_id']['label'] = $g_AccountAddress->getAddressLabelBySiteUseId($notice_detail['OOL']['INVOICE_TO_ORG_ID']);

                //获取收货、收单地址列表
                $po_data = $g_AccountAddress->getRelInfo($header['account_id']['value'], $header['mid_account_id']['value'], $header['org_id']);

                //收货地址
                $value = $header['ship_site_use_id'];
                $header['ship_site_use_id']['options'] = !empty($po_data['addrs']['SHIP_TO']) ? $po_data['addrs']['SHIP_TO'] : array();

                //收单地址
                $value = $header['bill_site_use_id'];
                $header['bill_site_use_id']['options'] = !empty($po_data['addrs']['BILL_TO']) ? $po_data['addrs']['BILL_TO'] : array();

                //币种
                $header['currency_id']['value'] = $currency_arr['value'];
                $header['currency_id']['label'] = $currency_arr['label'];
                $header['currency_id']['options'] = $g_Currency->getList(null, array('id', 'name'));
                $header['exchange_ratio'] = $exchange_ratio;
                $header['currency_id']['curr_exchange_rate'] = $g_Currency->getList(null, array('id', 'exchange_rate'));

                //记录参数
                $all_params['P']['org_id'] = $header['org_id'];
                $all_params['P']['organization_id'] = $header['ship_from_org_id']['value'];

                //负责内勤、销售
                $header['owner_user_name'] = $g_UserRealName;
                $header['sales_user_name'] = !empty($sales_user_arr['label']) ? $sales_user_arr['label'] : '';

                //订单类型
                $header['order_type']['value'] = $type_arr['value'];
                $header['order_type']['label'] = $type_arr['label'];
                $header['order_type']['options'] = $g_Type->geteTypeList();

                //订单类型关联业务实体
                $header['org_id_list'] = $g_Type->getOrgIdListByIds(array_keys($header['order_type']['options']));

                //接单日期
                $header['order_time'] = date('Y-m-d');

                //设置readonly字段
                $readonly_fields = array(
                    'order_type',
                    'account_id',
                    'mid_account_id',
                    'currency_id',
                    'ship_from_org_id',
                );
                foreach ($readonly_fields as $field) {
                    if (array_key_exists($field, $header)) {
                        $header[$field]['readonly'] = true;
                    }
                }

                $is_set_header = true;
            }

            //通过EBS物料ID获取对应OCS产品
            $product = $g_Product->getOcsProductByInventoryItemId($notice_detail['OOL']['INVENTORY_ITEM_ID']);
            if (empty($product)) {
                throw new Exception('产品在OCS不存在，INVENTORY_ITEM_ID=' . $notice_detail['OOL']['INVENTORY_ITEM_ID'], 1);
            }

            //设置行信息
            $line = array();
            //设置隐藏字段值
            $line['OrderLine']['rel_ebs_order_line_id'] = $notice_detail['OOL']['LINE_ID'];
            $line['OrderLine']['rel_req_id'] = $notice_detail['OOH']['ATTRIBUTE8'];
            $line['OrderLine']['product_id'] = $product['Product']['id'];
            $line['OrderLine']['rel_notice_detail_id'] = $notice_detail['DD']['NOTICE_DETAIL_ID'];
            $line['OrderLine']['rel_lot_number'] = $notice_detail['DD']['LOT_NUMBER'];
            $line['OrderLine']['rel_notice_detail_quantity'] = $notice_detail['DD']['DELIVERY_QUANTITY'];
            $line['OrderLine']['account_mno'] = $notice_detail['OOL']['ORDERED_ITEM'];
            $line['OrderLine']['line_number'] = $notice_detail['OOL']['LINE_NUMBER'];
            $line['DataView']['line_number'] = $notice_detail['OOL']['LINE_ID'] . '(' . $notice_detail['OOL']['LINE_NUMBER'] . '行)';
            $line['OrderLine']['is_update_rel_contract'] = 0; //新建，默认为“否”

            $line['DataView']['sum_quantity'] = $g_OrderLine->getDelivetyQty($notice_detail['OOL']['LINE_ID']);
            $line['DataView']['line_quantity'] = $notice_detail['OOL']['ORDERED_QUANTITY'];

            $line['OrderLine']['display_no'] = '#' . ($num + 1);
            $line['DataView']['display_no'] = '#' . ($num + 1);

            $line['DataView']['rel_ebs_order_number'] = $notice_detail['OOH']['ORDER_NUMBER'];
            $line['OrderLine']['rel_ebs_order_number'] = $notice_detail['OOH']['ORDER_NUMBER'];
            $line['DataView']['product_name'] = $product['Product']['name'];
            // $line['OrderLine']['line_type_id'] = $this->getDefaultOrderLineTypeIdByOrgId($notice_detail['OOL']['ORG_ID'], $header['currency_id']['value']);
            $line['OrderLine']['price'] = floatval($notice_detail['OOL']['UNIT_SELLING_PRICE']);
            $line['DataView']['account_cno'] = $notice_detail['OOL']['CUST_PO_NUMBER'];
            $line['DataView']['account_mno'] = $notice_detail['OOL']['ORDERED_ITEM'];
            $line['DataView']['rel_lot_number'] = $notice_detail['DD']['LOT_NUMBER'];
            $line['OrderLine']['mto_no'] = $g_Enum->getMtoNoByProjectId($notice_detail['OOL']['PROJECT_ID']);
            $line['OrderLine']['subinventory_code'] = $notice_detail['DD']['SUBINVENTORY_CODE'];
            $line['OrderLine']['locator'] = $line['OrderLine']['subinventory_code'] . '.' . $line['OrderLine']['mto_no'] . '.'; //货位

            $lines[] = $line;
        }
        $result['header'] = $header;
        $result['lines'] = $lines;

        return $result;
    }

    /**
     * 通过发货明细行ids和订单行ids 获取发货相关信息
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-05T16:31:34+0800
     *
     * @param    array                    $notice_detail_id_rel_line_id [发货明细行ID:订单行ID] eg: array('123141:190975', '123141:177277')
     *
     * @return   [type]                                      [description]
     */
    function getNoticeDetailByDetailIdsAndLineIds($notice_detail_id_rel_line_id = array()) {
        if (empty($notice_detail_id_rel_line_id)) {
            return array();
        }
        global $g_Commons;
        $g_EbsDbo = $g_Commons->GlobalModel('EbsDbo');

        $notice_details = array();
        foreach ($notice_detail_id_rel_line_id as $rel_ids) {
            list($notice_detail_id, $order_line_id) = explode(':', $rel_ids); //拆分
            $details = $g_EbsDbo->find('all', array(
                'joins' => array(
                    array(
                        'table' => 'Apps.Wsh_Delivery_Assignments',
                        'alias' => 'WDA',
                        'type' => 'inner',
                        'conditions' => 'WDD.Delivery_Detail_Id = WDA.Delivery_Detail_Id'
                    ),
                    array(
                        'table' => 'Apps.Wsh_New_Deliveries',
                        'alias' => 'WND',
                        'type' => 'inner',
                        'conditions' => 'WDA.Delivery_Id = WND.Delivery_Id'
                    ),
                    array(
                        'table' => 'XXapex.Xxom_Delivery_Headers_All',
                        'alias' => 'DH',
                        'type' => 'inner',
                        'conditions' => 'DH.Delivery_Number = WND.Name'
                    ),
                    array(
                        'table' => 'XXapex.Xxom_Delivery_Lines_All',
                        'alias' => 'DL',
                        'type' => 'inner',
                        'conditions' => 'DL.Delivery_Id = DH.Delivery_Id'
                    ),
                    array(
                        'table' => 'XXapex.Xxom_Delivery_Details_All',
                        'alias' => 'DD',
                        'type' => 'inner',
                        'conditions' => 'DD.Delivery_Line_Id = DL.Delivery_Line_Id'
                    ),
                    array(
                        'table' => 'xxcus.Xxom_Shipment_Notice_Lines',
                        'alias' => 'SL',
                        'type' => 'inner',
                        'conditions' => 'SL.Notice_Line_Id = DL.Notice_Line_Id',
                    ),
                    array(
                        'table' => 'xxcus.Xxom_Shipment_Notice_Headers',
                        'alias' => 'SH',
                        'type' => 'inner',
                        'conditions' => 'SH.Notice_Id = SL.Notice_Id',
                    ),
                    array(
                        'table' => 'Apps.Oe_Order_Lines_All',
                        'alias' => 'OOL',
                        'type' => 'inner',
                        'conditions' => 'OOL.Line_Id = WDD.Source_Line_Id',
                    ),
                    array(
                        'table' => 'Apps.Oe_Order_Headers_All',
                        'alias' => 'OOH',
                        'type' => 'inner',
                        'conditions' => 'OOH.Header_Id = OOL.Header_Id',
                    ),
                ),
                'main_table' => array(
                    'Apps.Wsh_Delivery_Details' => 'WDD',
                ),
                'conditions' => array(
                    'WDD.Source_Header_Id = SL.Order_Header_Id',
                    'WDD.Project_Id = DH.Factory_Id',
                    "Nvl(WDD.Lot_Number, 'X') = Nvl(DD.Lot_Number, 'X')",
                    //'WDD.Shipped_Quantity = DD.Delivery_Quantity',
                    'OOL.flow_status_code' => 'CLOSED',
                    'DD.Notice_Detail_Id' => $notice_detail_id,
                    'WDD.Source_Line_Id' => $order_line_id,
                ),
                'fields' => array(
                    'DD.NOTICE_DETAIL_ID',
                    'DD.LOT_NUMBER',
                    'DD.DELIVERY_QUANTITY',
                    'DD.SUBINVENTORY_CODE',
                    'DH.Delivery_Number',
                    'SH.Notice_Number',
                    'OOH.header_id',
                    'OOH.order_number',
                    'OOH.attribute8',
                    'OOL.line_id',
                    'OOL.UNIT_SELLING_PRICE',
                    'OOL.Inventory_Item_Id',
                    'OOL.ORG_ID',
                    'OOL.LINE_TYPE_ID',
                    'OOL.SOLD_FROM_ORG_ID',
                    'OOL.SHIP_FROM_ORG_ID',
                    'OOL.SHIP_TO_ORG_ID',
                    'OOL.INVOICE_TO_ORG_ID',
                    'OOL.PROJECT_ID',
                    'OOL.ORDERED_ITEM',
                    'OOL.ORDERED_QUANTITY',
                    'OOL.CUST_PO_NUMBER',
                    'OOH.ORDER_TYPE_ID',
                    'OOH.TRANSACTIONAL_CURR_CODE',
                    'OOL.LINE_NUMBER',
                    'OOL.SHIPMENT_NUMBER',
                )
            ));
            if (empty($details)) {
                continue;
            }
            foreach ($details as $detail) {
                $detail['OOL']['LINE_NUMBER'] = $detail['OOL']['LINE_NUMBER'] . '.' . $detail['OOL']['SHIPMENT_NUMBER'];
                $notice_details[] = $detail;
            }
        }

        return $notice_details;
    }

    /**
     * 保存订单
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-05T21:26:11+0800
     *
     * @param    [type]                   $submit_data [form提交的数据]
     *
     * @return   [type]                                [description]
     */
    function saveOrder($submit_data) {
        global $g_Commons;
        $g_OrderLine = $g_Commons->GlobalModel('OrderLine');
        $result = $g_Commons->initResult();

        //验证备货申请单信息
        $po_result = $this->verifyOrder($submit_data);
        if (!$po_result['success']) {
            return $po_result;
        }
        $type = $po_result['type'];
        $all_save_datas = $po_result['datas'];
        // pr($all_save_datas);return;

        try {
            $this->query('begin');
            if (empty($type)) {
                throw new Exception(__FUNCTION__ . '订单类别参数为空，保存失败', 1);
            }

            //保存数据，及处理保存后一系列相关操作
            $model = new OrderContext($type);
            $model->dualSaveOrder($all_save_datas, $submit_data['action']);

            //获取需要审核的订单头ids
            $need_audit_header_ids = $this->getNeedAuditHeaderIds();
            $result['datas']['need_audit_header_ids'] = $need_audit_header_ids;
        } catch (Exception $e) {
            $this->query('rollback');
            $result['success'] = false;
            $result['message'] = $e->getMessage();
            return $result;
        }

        $this->query('commit');
        $result['success'] = true;
        return $result;
    }

    /**
     * 验证提交的订单数据
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-05T21:27:38+0800
     *
     * @param    [type]                   $data [description]
     *
     * @return   [type]                         [description]
     */
    function verifyOrder($data) {
        global $g_Commons;
        global $g_UserId;
        global $g_BizId;
        $g_User = $g_Commons->GlobalModel('User');
        $g_Account = $g_Commons->GlobalModel('Account');
        $g_Contract = $g_Commons->GlobalModel('Contract');
        $g_OrderLine = $g_Commons->GlobalModel('OrderLine');

        $result = $g_Commons->initResult();
        $result['datas'] = $data;
        $save_datas = array();
        $all_save_datas = array();

        switch ($data['action']) {
            case 'add':
                $type = !empty($data['type']) ? $data['type'] : 0;
                if (empty($type)) {
                    $result['message'] = '参数type为空，保存失败，请联系管理员处理';
                    return $result;
                }

                //验证保存数据，并返回订单头行结构的Table数据
                $model = new OrderContext($type);
                $po_data = $model->verifyAddOrder($data);
                if (!$po_data['success']) {
                    $result['message'] = $po_data['message'];
                    return $result;
                }
                $all_save_datas = $po_data['datas'];
            break;
            case 'edit': //编辑
                if (empty($data['header_id'])) {
                    $result['message'] = '参数错误，header_id为空';
                    return $result;
                }
                $header_id = $data['header_id'];
                $type = $this->get_by_id($header_id, 'type');
                if (empty($type)) {
                    $result['message'] = '订单头type字段为空，保存失败，请联系管理员处理';
                    return $result;
                }

                //验证保存数据，并返回订单头行结构的Table数据
                $model = new OrderContext($type);
                $po_data = $model->verifyEditOrder($data);
                if (!$po_data['success']) {
                    $result['message'] = $po_data['message'];
                    return $result;
                }
                $all_save_datas = $po_data['datas'];
            break;
            default:
                $result['message'] = 'action未定义';
                return $result;
            break;
        }
        if (empty($all_save_datas)) {
            $result['message'] = '保存数据为空，保存失败';
            return $result;
        }
        foreach ($all_save_datas as $save_datas) {
            if (empty($save_datas['header']) || empty($save_datas['lines'])) {
                $result['success'] = false;
                $result['message'] = '订单头或订单行信息为空，保存失败';
                return $result;
            }
        }

        $result['success'] = true;
        $result['type'] = $type;
        $result['datas'] = $all_save_datas;
        return $result;
    }

    /**
     * 定义提交数据时，需要验证的字段
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-06T11:41:12+0800
     *
     * @return   [type]                   [description]
     */
    function getVerifyFields($entity = null, $action = null) {
        $fields = array();
        switch ($entity) {
            case 'header':
                switch ($action) {
                    case 'add':
                        $fields = array(
                            'org_id' => array(
                                'rule' => 'not Empty',
                                'label' => '业务实体',
                            ),
                            'account_cno' => array(
                                'rule' => '',
                                'label' => '客户单号',
                            ),
                            'order_type' => array(
                                'rule' => 'not Empty',
                                'label' => '订单类型',
                            ),
                            'account_id' => array(
                                'rule' => 'not Empty',
                                'label' => '客户',
                            ),
                            'ship_site_use_id' => array(
                                'rule' => 'not Empty',
                                'label' => '收货客户地址',
                            ),
                            'mid_account_id' => array(
                                'rule' => '',
                                'label' => '第三方付款平台',
                            ),
                            'bill_site_use_id' => array(
                                'rule' => 'not Empty',
                                'label' => '收单客户地址',
                            ),
                            'ship_from_org_id' => array(
                                'rule' => 'not Empty',
                                'label' => '库存组织',
                            ),
                            'order_time' => array(
                                'rule' => 'not Empty',
                                'label' => '接单日期',
                            ),
                            'currency_id' => array(
                                'rule' => 'not Empty',
                                'label' => '币种',
                            ),
                            'owner_user_name' => array(
                                'rule' => 'not Empty',
                                'label' => '负责内勤',
                            ),
                            'sales_user_name' => array(
                                'rule' => 'not Empty',
                                'label' => '负责销售',
                            ),
                        );
                    break;
                    case 'edit':
                        $fields = array(
                            'account_cno' => array(
                                'rule' => '',
                                'label' => '客户单号',
                            ),
                            'order_time' => array(
                                'rule' => 'not Empty',
                                'label' => '接单日期',
                            ),
                            'owner_user_name' => array(
                                'rule' => 'not Empty',
                                'label' => '负责内勤',
                            ),
                            'sales_user_name' => array(
                                'rule' => 'not Empty',
                                'label' => '负责销售',
                            ),
                        );
                    break;
                }
            break;
            case 'line':
                switch ($action) {
                    case 'add':
                        $fields = array(
                            'rel_ebs_order_line_id' => array(
                                'rule' => 'not Empty',
                                'label' => '关联订单行',
                            ),
                            'rel_req_id' => array(
                                'rule' => 'not Empty',
                                'label' => '关联req_id',
                            ),
                            'line_type_id' => array(
                                'rule' => '',
                                'label' => '行类型',
                            ),
                            'delivety_time' => array(
                                'rule' => 'not Empty',
                                'label' => '发运日期',
                            ),
                            'quantity' => array(
                                'rule' => 'not Empty',
                                'label' => '数量',
                            ),
                            'price' => array(
                                'rule' => 'not Empty',
                                'label' => '单价',
                            ),
                            'rel_notice_detail_id' => array(
                                'rule' => 'not Empty',
                                'label' => '关联发货明细行ID',
                            ),
                            'mto_no' => array(
                                'rule' => 'not Empty',
                                'label' => '工厂',
                            ),
                            'return_reason' => array(
                                'rule' => 'not Empty',
                                'label' => '退货原因',
                            ),
                        );
                    break;
                    case 'edit':
                        $fields = array(
                            // 'id' => array(
                            //     'rule' => 'not Empty',
                            //     'label' => '订单行ID',
                            // ),
                            'delivety_time' => array(
                                'rule' => 'not Empty',
                                'label' => '发运日期',
                            ),
                            'quantity' => array(
                                'rule' => 'not Empty',
                                'label' => '数量',
                            ),
                            'price' => array(
                                'rule' => 'not Empty',
                                'label' => '单价',
                            ),
                            'mto_no' => array(
                                'rule' => 'not Empty',
                                'label' => '工厂',
                            ),
                            'return_reason' => array(
                                'rule' => 'not Empty',
                                'label' => '退货原因',
                            ),
                        );
                    break;
                }
            break;
        }

        return $fields;
    }

    /**
     * 检查key是否存在
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-06T09:47:46+0800
     *
     * @param    array                    $fields [验证字段]
     * @param    array                    $data   [数据源]
     *
     * @return   [type]                           [description]
     */
    function checkFieldExist($fields = array(), $data = array()) {
        global $g_Commons;
        $result = $g_Commons->initResult();
        if (empty($fields) || empty($data)) {
            $result['message'] = __FUNCTION__ . '参数错误';
            return $result;
        }
        $error_msg = array();
        foreach ($fields as $key => $field) {
            if (!array_key_exists($key, $data)) {
                $error_msg[] = '#' . $field['label'] . ' 字段未定义';
                continue;
            }
            $rule = $field['rule'];
            if ('not Empty' == $rule && empty($data[$key])) {
                $error_msg[] = '#' . $field['label'] . ' 字段不允许为空';
                continue;
            }
        }
        if (!empty($error_msg)) {
            $result['message'] = implode(',', $error_msg);
            return $result;
        }

        $result['success'] = true;
        return $result;
    }

    /**
     * 同步订单至EBS
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-09T20:03:27+0800
     *
     * @param    [type]                   $header_id [OCS订单头ID]
     *
     * @return   [type]                              [description]
     */
    function syncOrderToEbs($header_id = null) {
        global $g_Commons;
        $result = $g_Commons->initResult();
        if (empty($header_id)) {
            $result['message'] = '参数错误，header_id为空';
            return $result;
        }
        $type = $this->get_by_id($header_id, 'type');
        if (empty($type)) {
            $result['message'] = '同步失败，订单类别字段为空，header_id=' . $header_id;
            return $result;
        }
        $model = new OrderContext($type);
        $po_result = $model->syncOrderToEbs($header_id);

        return $po_result;
    }

    /**
     * 同步订单至EBS后，自动执行一系列相关操作
     *
     * @Author   lishirong
     *
     * @DateTime 2016-11-07T16:28:05+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     *
     * @return   [type]                              [description]
     */
    function dualAfterSyncOrderToEbs($header_id = null) {
        global $g_Commons;
        $result = $g_Commons->initResult();
        if (empty($header_id)) {
            $result['message'] = '参数错误，header_id为空';
            return $result;
        }
        $type = $this->get_by_id($header_id, 'type');
        if (empty($type)) {
            $result['message'] = '操作失败，订单类别字段为空，header_id=' . $header_id;
            return $result;
        }
        $model = new OrderContext($type);
        $po_result = $model->dualAfterSyncOrderToEbs($header_id);

        return $po_result;
    }

    /**
     * 根据OCS订单头ID，登记EBS订单
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-11T22:05:40+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     *
     * @return   [type]                              [description]
     */
    public function bookEbsOrder($header_id = null) {
        global $g_Commons;
        $result = $g_Commons->initResult();
        if (empty($header_id)) {
            $result['message'] = '参数错误，header_id为空';
            return $result;
        }
        $type = $this->get_by_id($header_id, 'type');
        if (empty($type)) {
            $result['message'] = '操作失败，订单类别字段为空，header_id=' . $header_id;
            return $result;
        }
        $model = new OrderContext($type);
        $po_result = $model->bookEbsOrder($header_id);

        return $po_result;
    }

    /**
     * 设置EBS订单编号
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-10T17:13:01+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     */
    function setEbsOrderNumber($header_id = null) {
        if (empty($header_id)) {
            return;
        }
        global $g_Commons;
        global $g_UserId;
        $g_EbsDbo = $g_Commons->GlobalModel('EbsDbo');

        $order_header = $this->find('first', array(
            'conditions' => array(
                'OrderHeader.id' => $header_id,
            ),
            'fields' => array(
                'OrderHeader.id',
                'OrderHeader.ebs_order_number',
                'OrderHeader.update_user_id',
                'OrderHeader.update_time',
            )
        ));
        if (empty($order_header) || !empty($order_header['OrderHeader']['ebs_order_number'])) {
            return;
        }
        $ebs_order_header = $g_EbsDbo->find('first', array(
            'main_table' => array(
                'apps.oe_order_headers_all' => 'OOH',
            ),
            'conditions' => array(
                'OOH.attribute8' => $this->prefix . $header_id,
            ),
            'fields' => array(
                'OOH.attribute8',
                'OOH.order_number',
            )
        ));
        if (empty($ebs_order_header)) {
            return;
        }
        $ebs_order_number = $ebs_order_header['OOH']['ORDER_NUMBER'];
        $order_header['OrderHeader']['ebs_order_number'] = $ebs_order_number;
        $order_header['OrderHeader']['update_user_id'] = $g_UserId;
        $order_header['OrderHeader']['update_time'] = date('Y-m-d H:i:s');

        $this->save($order_header['OrderHeader']);
    }

    /**
     * 根据所有有效订单行计算总数量及总金额
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-11T14:25:12+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     *
     * @return   [type]                              [description]
     */
    function updateQuantityAndAmount($header_id = null) {
        global $g_Commons;
        $g_Account = $g_Commons->GlobalModel('Account');

        if (empty($header_id)) {
            return;
        }

        $count = $this->find('count', array(
            'conditions' => array(
                'OrderHeader.id' => $header_id,
            ),
        ));
        if (empty($count)) {
            return;
        }

        //计算总数
        $order = $this->find('first', array(
            'joins' => array(
                array(
                    'table' => 'order_lines',
                    'alias' => 'OrderLine',
                    'type' => 'inner',
                    'conditions' => 'OrderLine.header_id = OrderHeader.id',
                ),
            ),
            'conditions' => array(
                'OrderHeader.id' => $header_id,
                'OrderLine.status !=' => OCS_ORDER_LINE_STATUS_CANCELLED, //作废
            ),
            'fields' => array(
                'SUM(OrderLine.quantity) quantity',
                'SUM(OrderLine.amount) amount',
                'OrderHeader.order_time, OrderHeader.account_id'
            )
        ));
        $quantity = !empty($order[0]['quantity']) ? $order[0]['quantity'] : 0;
        $amount = !empty($order[0]['amount']) ? $order[0]['amount'] : 0;

        // 获取客户信息
        $account_name = $g_Account->findById($order['OrderHeader']['account_id'], 'short_name');

        $order_header = array();
        $order_header['id'] = $header_id;
        $order_header['quantity'] = $quantity;
        $order_header['amount'] = $amount;
        $order_time = empty($order['OrderHeader']['order_time']) ? '' : date('Y-m-d', strtotime($order['OrderHeader']['order_time']));
        $order_header['name'] = $account_name['Account']['short_name'] . '-' . $order_time .'-' . $quantity;

        $this->save($order_header);
    }

    /**
     * 重设订单审核数据（清空）
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-12T10:05:12+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     *
     * @return   [type]                              [description]
     */
    function resetOrderAuditStatus($header_id = null) {
        if (empty($header_id)) {
            return;
        }
        $order_header = $this->find('first', array(
            'conditions' => array(
                'OrderHeader.id' => $header_id,
            ),
            'fields' => array(
                'OrderHeader.id',
                'OrderHeader.status',
                'OrderHeader.audit_remark',
            )
        ));
        if (empty($order_header)) {
            return;
        }
        global $g_Commons;
        $g_AuditStageRelObj = $g_Commons->GlobalModel('AuditStageRelObj');

        //清空审核数据
        $g_AuditStageRelObj->setAuditStatus(OrderHeader, $header_id, OCS_ORDER_HEADER_AUDIT_STAGE_OADMIN, 'N', $order_header['OrderHeader']['audit_remark']);
    }

    /**
     * 通过退货订单头ID获取退货订单的相关信息并格式化
     *
     * @Author   zhangguocai
     *
     * @DateTime 2016-05-18T11:12:12+0800
     *
     * @param    [type]                   $header_id    [退货订单头ID]
     *
     * @return   [type]                              [description]
     */
    function getReturnOrderInfos($header_id = null) {

        global $g_Commons;
        $g_Enum = $g_Commons->GlobalModel('Enum');
        $g_AttrDics = $g_Commons->GlobalController('AttrDics');
        $g_AttrDics->AttrDic->recursive = -1;

        if (empty($header_id) || !is_numeric($header_id)) {
            return array();
        }

        // 查询条件
        $conditions = array();
        $conditions['OrderHeader.id'] = $header_id;
        if (!empty($line_id) && (is_numeric($line_id) || is_array($line_id))) {
            $conditions['OrderLine.id'] = $line_id;
        }

        // 获取退货订单信息
        $return_orders = $this->find('all', array(
            'joins' => array(
                array(
                    'table' => 'order_lines',
                    'alias' => 'OrderLine',
                    'type' => 'inner',
                    'conditions' => array('OrderHeader.id = OrderLine.header_id'),
                ),
                array(
                    'table' => 'products',
                    'alias' => 'Product',
                    'type' => 'inner',
                    'conditions' => 'Product.id = OrderLine.product_id'
                ),
                array(
                    'table' => 'prod_models',
                    'alias' => 'ProdModel',
                    'type' => 'inner',
                    'conditions' => 'ProdModel.id = Product.prod_model_id'
                ),
            ),
            'conditions' => $conditions,
            'fields' => array('OrderHeader.*', 'OrderLine.*', 'Product.code', 'ProdModel.name')
        ));

        if (empty($return_orders)) {
            return array();
        }

        // 格式化字段
        $return_orders = $g_AttrDics->formatAllModelData($return_orders);

        // 获取"退货原因"枚举值
        $memo_reason_lists = $g_Enum->getEnumListByDictName('Ebs.memo_reason');

        // 获取通知单明细ID和订单行ID
        $rel_notice_detail_ids = array();
        foreach ($return_orders as $key => $return_order) {
            $notice_detail_id = $return_order['OrderLine']['rel_notice_detail_id'];
            $order_line_id = $return_order['OrderLine']['rel_ebs_order_line_id'];
            $rel_notice_detail_ids[] = $notice_detail_id . ':' . $order_line_id;

            // 替换"退货原因"枚举值
            // $return_reason = array(
            //     'value' => $return_order['OrderLine']['return_reason'],
            //     'label' => !empty($memo_reason_lists[$return_order['OrderLine']['return_reason']]) ? $memo_reason_lists[$return_order['OrderLine']['return_reason']] : null,
            // );
            // $return_order['OrderLine']['return_reason'] = $return_reason;

            $return_orders[$key] = $return_order;
        }
        $order_notice_details = $this->getNoticeDetailByDetailIdsAndLineIds($rel_notice_detail_ids);

        // 将出对应的出库订单行分组
        $group_notice_details = array();
        if (!empty($order_notice_details)) {
            foreach ($order_notice_details as $order_notice_detail) {
                if (!isset($order_notice_detail['DD']['NOTICE_DETAIL_ID']) || !isset($order_notice_detail['OOL']['LINE_ID'])) {
                    continue;
                }
                $notice_detail_id = $order_notice_detail['DD']['NOTICE_DETAIL_ID'];
                $order_line_id = $order_notice_detail['OOL']['LINE_ID'];
                $group_notice_details[$notice_detail_id][$order_line_id] = $order_notice_detail;
            }
        }

        if (!empty($group_notice_details)) {
            foreach ($return_orders as $key => $return_order) {
                $notice_detail_id = $return_order['OrderLine']['rel_notice_detail_id'];
                $order_line_id = $return_order['OrderLine']['rel_ebs_order_line_id'];
                if (!isset($group_notice_details[$notice_detail_id][$order_line_id])) {
                    continue;
                }
                $group_notice_detail = $group_notice_details[$notice_detail_id][$order_line_id];

                $return_orders[$key] = array_merge($return_order, $group_notice_detail);
            }
        }

        return $return_orders;
    }

    /**
     * 退货订单相关邮件通知统一方法
     *
     * @Author   zhangguocai
     *
     * @DateTime 2016-05-13T09:49:52+0800
     *
     * @param    [type]                   $header_id [退货订单头ID]
     * @param    [type]                   $action    [对应的邮件通知模板方法]
     * @param    array                    $to_users  [指定收件人]
     *
     * @return   [type]                              [description]
     */
    function emailNotify($header_id = null, $action = null, $to_users = array()) {
        global $g_Commons;
        global $g_BizUrl;
        global $g_BizId;
        global $g_OCS_options;
        global $g_UserEmail;
        global $g_UserRealName;
        $g_User = $g_Commons->GlobalModel('User');
        $g_Enum = $g_Commons->GlobalModel('Enum');
        $g_ObjRelObj = $g_Commons->GlobalModel('ObjRelObj');
        $g_ERPWsClients = $g_Commons->GlobalController('ERPWsClients');
        $g_QueueJobs = $g_Commons->GlobalController('QueueJobs');
        $g_Logs = $g_Commons->GlobalController('Logs');
        $g_AttrDics = $g_Commons->GlobalController('AttrDics');
        $g_AttrDics->AttrDic->recursive = -1;

        $result = $g_Commons->initResult();

        // hardcode需要密送的用户,前期用于运维
        $bcc_user_emails = array(
            12 => 'lishirong@cvte.com',
            714 => 'zhangguocai@cvte.com',
            763 => 'zhangqinjing@cvte.com',
        );

        // 校验传入参数是否正常
        if (empty($header_id)) {
            $result['message'] = '退货订单头ID不能为空';
            return $result;
        }
        $g_Logs->write_log('OrderHeaders', $header_id, 'info', '调用邮件发送功能(' . $action . ').');

        $action_layout = array();
        // 已审核通知财务,触发时机：退货订单审核通过后。
        $action_layout['AuditedNotifyFinance'] = array(
            array('label' => '销管', 'label_field' => 'OrderHeader.owner_user_id.label'),
            array('label' => '收单客户简称', 'label_field' => 'OrderHeader.mid_account_id.label'),
            array('label' => '收货客户简称', 'label_field' => 'OrderHeader.account_id.label'),
            array('label' => '退货订单编号', 'label_field' => 'OrderHeader.ebs_order_number'),
            array('label' => '原EBS单号', 'label_field' => 'OrderHeader.rel_ebs_order_number'),
            array('label' => '原EBS行号', 'label_field' => 'OrderLine.line_number'),
            array('label' => '原发货单号', 'label_field' => 'SH.NOTICE_NUMBER'),
            array('label' => '原出库单号', 'label_field' => 'DH.DELIVERY_NUMBER'),
            array('label' => '产品型号', 'label_field' => 'ProdModel.name'),
            array('label' => '批号', 'label_field' => 'OrderLine.rel_lot_number'),
            array('label' => '数量', 'label_field' => 'OrderLine.quantity'),
            array('label' => '退货原因', 'label_field' => 'OrderLine.return_reason.label'),
        );
        // 变更后通知财务,触发时机：退货变更单审核通过后。
        $action_layout['AuditedChangeNotifyFinance'] = $action_layout['AuditedNotifyFinance'];
        // 邮件通知工厂,触发时机：EBS退货订单自动登记成功。
        $action_layout['BookedNotifyFactory'] = array(
            array('label' => '退货订单编号', 'label_field' => 'OrderHeader.ebs_order_number'),
            array('label' => '物料编号', 'label_field' => 'Product.code'),
            array('label' => '批号', 'label_field' => 'OrderLine.rel_lot_number'),
            array('label' => '数量', 'label_field' => 'OrderLine.quantity'),
            array('label' => '接收仓库', 'label_field' => 'OrderLine.subinventory_code'),
            array('label' => '接收货位', 'label_field' => 'OrderLine.locator'),
            array('label' => '退货原因', 'label_field' => 'OrderLine.return_reason.label'),
            array('label' => '接收日期', 'label_field' => 'OrderHeader.order_time'),
            array('label' => '销管', 'label_field' => 'OrderHeader.owner_user_id.label'),
        );
        // 邮件通知工厂,触发时机：EBS退货订单通知过工厂后，变更工厂、子库、货位、批次、数量自动更新EBS退货订单后。变更前后信息需要体现对比。
        $action_layout['ChangeNotifyFactory'] = $action_layout['BookedNotifyFactory'];

        try {
            if (empty($action)) {
                throw new Exception('邮件通知的模板不能为空', 1);
            }
            // 获取并判断该退货订单头是否存在
            $return_orders = $this->getReturnOrderInfos($header_id);
            if (empty($return_orders[0])) {
                throw new Exception('没找到 #' . (is_array($header_id) ? implode(',', $header_id) : $header_id) . '，对应的退货订单。', 1);
            } elseif (!isset($action_layout[$action])) {
                throw new Exception('缺少 #' . $action . ' 对应的邮件布局.', 1);
            }

            // 获取邮件布局
            $email_fields = $action_layout[$action];

            // 获取首行数据
            $return_order = $return_orders[0];
            // 获取组织ID
            $return_org_id = !empty($return_order['OrderHeader']['org_id']) ? $return_order['OrderHeader']['org_id'] : null;
            // 获取单号
            $return_order_number = !empty($return_order['OrderHeader']['ebs_order_number']) ? $return_order['OrderHeader']['ebs_order_number'] : null;
            // 获取审核状态
            $return_order_status = !empty($return_order['OrderHeader']['status']['label']) ? $return_order['OrderHeader']['status']['label'] : null;
            // 将变更信息整合到新的数据中
            $return_orders = $this->getEmailNoitfyChangeDatas($return_orders);

            // 获取对应的内勤的邮箱
            if (empty($return_org_id)) {
                throw new Exception('没获取到 #' . $header_id . ' 对应的组织ID.', 1);
            } elseif (empty($return_order['OrderHeader']['owner_user_id']['value'])) {
                throw new Exception('没获取到 #' . $header_id . ' 对应的内勤.', 1);
            }

            $owner_user_id = $return_order['OrderHeader']['owner_user_id']['value'];
            $cc_user_emails = array(
                $owner_user_id => $g_User->getUserEmailById($owner_user_id)
            );

            // 将数组转成html表格
            $view = new View($this, false);
            $view->loadHelper('Html');
            $view->loadHelper('CvtHtml');
            $common_html = $view->loadHelper('CommonHtml');

            // 邮件中用到的表格模板
            $table_html_tmpl = '<table border="1"><thead>{thead}</thead><tbody>{tbody}</tbody></table>';

            // 根据用户定义的模板,做相应的处理
            switch ($action) {
                case 'AuditedNotifyFinance': //已审核通知财务,触发时机：退货订单审核通过后。
                case 'AuditedChangeNotifyFinance': //变更后通知财务,触发时机：退货变更单审核通过后。
                case 'NotifyOwnerMangerAudite': //通知内勤经理审核退货订单
                case 'NotifyOwnerToModify': //审核不通过，通知内勤修改
                    if (!isset($return_order['OrderHeader']) || !isset($return_order['OrderHeader']['status']['value'])) {
                        throw new Exception('没获取到 #' . $header_id . ' 的审核状态.', 1);
                    }

                    // if ('AuditedNotifyFinance' == $action && OCS_ORDER_HEADER_STATUS_DONE != $return_order['OrderHeader']['status']['value']) {
                    //     $result['message'] = '该退货订单 #' . $header_id . ' 还没有审核.';
                    //     return $result;
                    // }

                    if ('AuditedNotifyFinance' == $action && empty($return_order['OrderHeader']['ebs_order_number'])) {
                        throw new Exception('该退货订单 #' . $header_id . ' 未成功同步至EBS.', 1);
                    }

                    //订单行类型=%退货不退票 的不需要通知财务 https://kb.cvte.com/pages/viewpage.action?pageId=51366549
                    if (in_array($action, array('AuditedNotifyFinance', 'AuditedChangeNotifyFinance'))) {
                        $unset_type_id_list = $g_Enum->find('list', array(
                            'conditions' => array(
                                'Enum.dict_name' => 'Ebs.order_detail_type',
                                "Enum.label LIKE '%退货不退票'",
                            ),
                            'fields' => array(
                                'Enum.id',
                                'Enum.value',
                            )
                        ));
                        foreach ($return_orders as $idx => $return_order) {
                            $line_type_id = is_array($return_order['OrderLine']['line_type_id']) ? $return_order['OrderLine']['line_type_id']['value'] : $return_order['OrderLine']['line_type_id'];
                            if (in_array($line_type_id, $unset_type_id_list)) {
                                unset($return_orders[$idx]);
                            }
                        }
                        if (empty($return_orders)) {
                            throw new Exception('全部订单行类型都为“%退货不退票”，不需要通知财务审核', 1);
                        }
                    }

                    // 生成表格html
                    $table_html = $common_html->simpleDataToTableHtml($email_fields, $return_orders, $table_html_tmpl);

                    // 获取当前事业部的“资金平台预分配提醒人”
                    $to_user_emails = $g_ObjRelObj->getRelObjApprovalUsers(null, $return_org_id, null, 'EbsTool.org_rel_fund_dist_notice_user');

                    // 发送前验证
                    if (empty($to_user_emails)) {
                        throw new Exception('该退货订单 #' . $header_id . ' 没有找到对应的提醒人.', 1);
                    } elseif (empty($table_html)) {
                        throw new Exception('该退货订单 #' . $header_id . ' 缺少需要发送的单据内容.', 1);
                    }

                    // 发送前,合并自定义需要通知的用户
                    $to_user_emails = array_merge($to_user_emails, $to_users);

                    // 发送邮件
                    $options = array();
                    $options['Email']['ToUsers'] = $to_user_emails;
                    $options['Email']['CcUsers'] = $cc_user_emails;
                    if (!empty($bcc_user_emails) && is_array($bcc_user_emails)) {
                        $options['Email']['bccUsers'] = $bcc_user_emails;
                    }
                    $options['Email']['invalid_email'] = true;//由于部分邮箱在OCS没有记录,因此设置为true允许OCS中不存在的邮箱
                    $options['Field']['return_order_status'] = $return_order_status;
                    $options['Field']['return_order_number'] = $return_order_number;
                    $options['Field']['Email_Subject'] = $return_order_number;
                    $options['Field']['login_user_email'] = $g_UserEmail;
                    $options['Field']['login_user_realname'] = $g_UserRealName;
                    $options['Field']['ocs_url'] = $g_OCS_options['url'] . $g_BizUrl;
                    $options['Field']['common_text'] = $table_html;
                    $queue_job_id = $g_QueueJobs->AddNoticeJob(array('Email'), OrderHeader, $header_id, $action, $options);

                    if (empty($queue_job_id) || !is_numeric($queue_job_id)) {
                        throw new Exception('该退货订单 #' . $header_id . ' 的邮件队列保存失败.', 1);
                    }

                    // 记录日志
                    $email_lists = array_merge($to_user_emails, $cc_user_emails);
                    $email_lists = implode(', ', $email_lists);
                    $g_Logs->write_log('OrderHeaders', $header_id, 'info',  '邮件发送成功(' . $action . ')。(包含邮箱：' . $email_lists . ')');

                    $result['success'] = true;
                    $result['message'] = '邮件发送成功.';
                    return $result;
                break;

                case 'BookedNotifyFactory'://邮件通知工厂,触发时机：EBS退货订单自动登记成功。
                case 'ChangeNotifyFactory'://邮件通知工厂,触发时机：EBS退货订单通知过工厂后，变更工厂、子库、货位、批次、数量自动更新EBS退货订单后。变更前后信息需要体现对比。

                    // hardcode通知工厂的邮件要抄送物流人员：
                    // wangyan@cvte.com;wangfulin@cvte.com;bailing@cvte.com;chenyao2@cvte.com
                    $cc_logistic_users = array(
                        'wangyan@cvte.com',
                        'wangfulin@cvte.com',
                        'bailing@cvte.com',
                        'chenyao2@cvte.com',
                    );
                    $cc_user_emails = array_merge($cc_user_emails, $cc_logistic_users);
                    $cc_user_emails = array_unique($cc_user_emails);

                    // 记录包含的所有工厂
                    $mto_return_orders_group = array();
                    foreach ($return_orders as $key => $return_order) {
                        $mto_no = !empty($return_order['OrderLine']['mto_no']) ? $return_order['OrderLine']['mto_no'] : null;
                        if (isset($return_order['OrderLine']['is_change']) && true == $return_order['OrderLine']['is_change']) {
                            $mto_return_orders_group[$mto_no]['is_change'] = true;
                        }
                        $mto_return_orders_group[$mto_no]['datas'][] = $return_order;
                    }
                    if (empty($mto_return_orders_group)) {
                        throw new Exception('没获取到 #' . $header_id . ' 对应的数据.', 1);
                    }

                    // 获取工厂对应的邮箱
                    $mto_nos = array_keys($mto_return_orders_group);
                    $mto_emails = $g_ERPWsClients->getMtoUserEmials($mto_nos);

                    foreach ($mto_emails as $mto => $mto_users) {
                        foreach ($mto_users as $mto_user_index => $mto_user) {
                            $mto_emails[$mto][$mto_user_index] = !empty($mto_user['EMAIL']) ? $mto_user['EMAIL'] : null;
                        }
                        $mto_emails[$mto] = array_filter($mto_emails[$mto]);
                        $mto_emails[$mto] = array_unique($mto_emails[$mto]);
                    }

                    // 逐个工厂发送
                    foreach ($mto_return_orders_group as $mto_no => $mto_return_data) {

                        // 如果是发送变更邮件,那么如果该退货订单下该工厂对应的退行没有任何变化,那么不需要邮件通知
                        if ('ChangeNotifyFactory' == $action) {
                            if (!isset($mto_return_data['is_change']) || empty($mto_return_data['is_change'])) {
                                $g_Logs->write_log('OrderHeaders', $header_id, 'info', '# ' . $mto_no . '工厂相关的退货行信息,没有变更,不需要发送邮件。');
                                continue;
                            }
                        }

                        // 获取该工厂对应的退货信息并获取工厂的负责人邮箱
                        $mto_return_orders = $mto_return_data['datas'];
                        if (!isset($mto_emails[$mto_no])) {
                            throw new Exception('没获取到 #' . $mto_no . ' 对应的负责人邮箱.', 1);
                        }
                        $to_user_emails = $mto_emails[$mto_no];

                        // 生成表格html
                        $table_html = $common_html->simpleDataToTableHtml($email_fields, $mto_return_orders, $table_html_tmpl);

                        // 发送前验证
                        if (empty($to_user_emails)) {
                            throw new Exception('该退货订单 #' . $header_id . ' 没有找到对应的提醒人.', 1);
                        } elseif (empty($table_html)) {
                            throw new Exception('该退货订单 #' . $header_id . ' 缺少需要发送的单据内容.', 1);
                        }

                        // 发送前,合并自定义需要通知的用户
                        $to_user_emails = array_merge($to_user_emails, $to_users);

                        // 发送邮件
                        $options = array();
                        $options['Email']['ToUsers'] = $to_user_emails;
                        $options['Email']['CcUsers'] = $cc_user_emails;
                        if (!empty($bcc_user_emails) && is_array($bcc_user_emails)) {
                            $options['Email']['bccUsers'] = $bcc_user_emails;
                        }
                        $options['Email']['invalid_email'] = true;//由于工厂邮箱在OCS没有记录,因此设置为true允许OCS中不存在的邮箱
                        $options['Field']['return_order_status'] = $return_order_status;
                        $options['Field']['return_order_number'] = $return_order_number;
                        $options['Field']['Email_Subject'] = $return_order_number;
                        $options['Field']['login_user_email'] = $g_UserEmail;
                        $options['Field']['login_user_realname'] = $g_UserRealName;
                        $options['Field']['ocs_url'] = $g_OCS_options['url'] . $g_BizUrl;
                        $options['Field']['common_text'] = $table_html;
                        $queue_job_id = $g_QueueJobs->AddNoticeJob(array('Email'), OrderHeader, $header_id, $action, $options);

                        // 日志记录
                        if (empty($queue_job_id) || !is_numeric($queue_job_id)) {
                            $log_message = '邮件发送失败';
                        } else {
                            $log_message = '邮件发送成功';
                        }
                        $email_lists = array_merge($to_user_emails, $cc_user_emails);
                        $email_lists = implode(', ', $email_lists);
                        $g_Logs->write_log('OrderHeaders', $header_id, 'info',  '工厂#' . $mto_no . ',' . $log_message . '(' . $action . ')。(包含邮箱：' . $email_lists . ')');
                    }

                    $result['success'] = true;
                    $result['message'] = '邮件发送成功.';
                    return $result;
                break;
                default:
                    throw new Exception('action 未定义', 1);
                break;
            }
        } catch (Exception $e) {
            $g_Logs->write_log('OrderHeaders', $header_id, 'info', $e->getMessage() . '(' . $action . ')');
            $result['message'] = __FUNCTION__ . $e->getMessage();
            return $result;
        }
    }

    /**
     * 检测关键信息是否变更，变更哪些属性
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-16T10:27:52+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     * @param    array                    $data      [保存的数据]
     *
     * @return   [type]                              [description]
     */
    function checkPrimaryAttrChange($header_id = null, $data = array()) {
        global $g_Commons;
        $g_Logs = $g_Commons->GlobalController('Logs');
        $g_Enum = $g_Commons->GlobalModel('Enum');
        $g_OrderLine = $g_Commons->GlobalModel('OrderLine');
        $g_EbsDbo = $g_Commons->GlobalModel('EbsDbo');
        $result = $g_Commons->initResult();
        if (empty($data) || empty($header_id)) {
            $result['message'] = __FUNCTION__ . '参数为空';
            return $result;
        }
        $memo_reason_list = $g_Enum->getEnumListByDictName('Ebs.memo_reason');
        $line_status_list = $g_Enum->getEnumListByDictName('OrderLine.status');
        $bool_list = array(0 => '否', 1 => '是');

        try {
            $order_lines = $this->find('all', array(
                'joins' => array(
                    array(
                        'table' => 'order_lines',
                        'alias' => 'OrderLine',
                        'type' => 'inner',
                        'conditions' => 'OrderLine.header_id = OrderHeader.id',
                    ),
                ),
                'conditions' => array(
                    'OrderLine.header_id' => $header_id,
                    // 'OrderHeader.status != ' => OCS_ORDER_HEADER_STATUS_CANCELLED,
                    // 'OrderLine.status != ' => OCS_ORDER_LINE_STATUS_CANCELLED,
                ),
                'fields' => array(
                    'OrderHeader.*',
                    'OrderLine.*',
                )
            ));
            if (empty($order_lines)) {
                throw new Exception(__FUNCTION__ . '订单不存在有效行', 1);
            }
            $old_order_lines = array();
            foreach ($order_lines as $order_line) {
                $line_id = $order_line['OrderLine']['id'];
                $old_order_lines[$line_id] = $order_line;
            }
            $order_header = $order_lines[0]['OrderHeader'];
            if (empty($order_header['ebs_order_number'])) { //订单未审核，未传EBS，可直接修改
                $result['success'] = true;
                return $result;
            }

            //保存变更内容
            $change_attrs = array();
            $po_datas = array();

            //定义检测变更的字段
            $primary_fields = array(
                'lines' => array(
                    'status' => '行状态',
                    'quantity' => '退货数量',
                    'is_update_rel_contract' => '是否更新合同',
                    'mto_no' => '工厂',
                    'subinventory_code' => '子库',
                    'locator' => '货位',
                    'return_reason' => '退货原因',
                    'new_line_id' => '新增订单行',
                ),
            );

            //检查字段是否变更
            foreach ($primary_fields['lines'] as $field => $label) {
                foreach ($data['lines'] as $line) {
                    $line_id = isset($line['id']) ? $line['id'] : 0;
                    if (!array_key_exists($field, $line) && !in_array($field, array('new_line_id')) || empty($line_id)) {
                        continue;
                    }

                    if ('new_line_id' == $field) {
                        $is_exist_ebs_order_line = $g_OrderLine->isEbsExistOrderLine($line_id); //判断该订单行在EBS是否存在
                        if ($is_exist_ebs_order_line) { //存在，说明不是新增行
                            continue;
                        }
                        $old_value = $old_label = 0;
                        $new_value = $new_label = $line_id;
                    } else {
                        $old_value = $old_order_lines[$line_id]['OrderLine'][$field];
                        $new_value = $line[$field];
                        switch ($field) {
                            case 'is_update_rel_contract': //bool
                                $old_label = $bool_list[$old_value];
                                $new_label = $bool_list[$new_value];
                            break;
                            case 'status': //enum
                                $old_label = $line_status_list[$old_value];
                                $new_label = $line_status_list[$new_value];
                            break;
                            case 'return_reason': //enum
                                $old_label = $memo_reason_list[$old_value];
                                $new_label = $memo_reason_list[$new_value];
                            break;
                            default:
                                $old_label = $old_value;
                                $new_label = $new_value;
                            break;
                        }
                    }

                    //比较是否变更
                    if ($new_value != $old_value) { //有变更
                        $notice_msg = 'line_id=' . $line_id . '，#[' . $field . ']' . $label . ' 内容变更：' . $old_label . ' => ' . $new_label;
                        $change_attrs[] = $notice_msg;

                        $po_datas[$field][$line_id]['field'] = $field;
                        $po_datas[$field][$line_id]['field_label'] = $label;
                        $po_datas[$field][$line_id]['line_id'] = $line_id;
                        $po_datas[$field][$line_id]['msg'] = $notice_msg;
                        $po_datas[$field][$line_id]['old_value'] = $old_value;
                        $po_datas[$field][$line_id]['new_value'] = $new_value;
                        $po_datas[$field][$line_id]['old_label'] = $old_label;
                        $po_datas[$field][$line_id]['new_label'] = $new_label;
                        continue;
                    }
                }
            }

            //变更处理
            if (!empty($change_attrs)) { //有变更
                $error_msg = implode('<br/>', $change_attrs);
                $g_Logs->write_log('OrderHeaders', $header_id, 'info', $error_msg);

                $result['message'] = $error_msg;
                $result['datas'] = $po_datas;
                return $result;
            } else {
                $result['success'] = true;
                return $result;
            }
        } catch (Exception $e) {
            $g_Logs->write_log('OrderHeaders', $header_id, 'info', $e->getMessage());

            $result['message'] = $e->getMessage();
            return $result;
        }
    }

    /**
     * 处理关键信息变更，变更前作相应的处理
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-16T14:46:48+0800
     *
     * @param    [type]                   $header_id    [订单头ID]
     * @param    array                    $change_datas [变更内容, 数据格式如下：
     * array(
     *     field => array(
     *         line_id => array(
     *              field => 'xxx',
     *              field_label => 'xxx',
     *              line_id => 'xxx',
     *              msg => 'xxx',
     *              old_value => 'xxx',
     *              new_value => 'xxx',
     *              old_label => 'xxx',
     *              new_label => 'xxx',
     *         )
     *     )
     * )
     * ]
     *
     * @return   [type]                                 [description]
     */
    function dualBeforePrimaryAttrChange($header_id = null, $change_datas = array()) {
        global $g_Commons;
        $g_Logs = $g_Commons->GlobalController('Logs');
        $g_OrderLine = $g_Commons->GlobalModel('OrderLine');
        $g_RelOrderHeader = $g_Commons->GlobalModel('RelOrderHeader');
        $result = $g_Commons->initResult();
        if (empty($header_id) || empty($change_datas)) {
            $result['message'] = __FUNCTION__ . '参数为空';
            return $result;
        }
        $order_lines = $this->find('all', array(
            'joins' => array(
                array(
                    'table' => 'order_lines',
                    'alias' => 'OrderLine',
                    'type' => 'inner',
                    'conditions' => 'OrderLine.header_id = OrderHeader.id',
                ),
            ),
            'conditions' => array(
                'OrderLine.header_id' => $header_id,
                // 'OrderHeader.status != ' => OCS_ORDER_HEADER_STATUS_CANCELLED,
                'OrderLine.status != ' => OCS_ORDER_LINE_STATUS_CANCELLED,
            ),
            'fields' => array(
                'OrderHeader.*',
                'OrderLine.*',
            )
        ));
        if (empty($order_lines)) {
            $result['message'] = __FUNCTION__ . '有效订单不存在';
            return $result;
        }

        //获取合并后保存变更内容
        $rel_order_header = $g_RelOrderHeader->find('first', array(
            'conditions' => array(
                'RelOrderHeader.header_id' => $header_id,
            ),
            'fields' => array(
                'RelOrderHeader.id',
                'RelOrderHeader.change_datas',
            )
        ));
        $old_change_datas = !empty($rel_order_header) && !empty($rel_order_header['RelOrderHeader']['change_datas']) ? json_decode($rel_order_header['RelOrderHeader']['change_datas'], true) : array();
        $new_chang_datas = array();
        if (empty($old_change_datas)) {
            $new_chang_datas = $change_datas;
        } else { //合并
            $new_chang_datas = $old_change_datas;
            foreach ($change_datas as $field => $data) {
                if (!isset($new_chang_datas[$field])) { //旧的未变更此字段，则直接使用新的
                    $new_chang_datas[$field] = $data;
                    continue;
                }
                foreach ($data as $line_id => $temp) {
                    $new_chang_datas[$field][$line_id] = $temp;
                }
            }
        }
        $new_chang_datas_json = json_encode($new_chang_datas);
        $g_RelOrderHeader->saveRelFields($header_id, array('change_datas' => $new_chang_datas_json));

        $order_header = $order_lines[0]['OrderHeader'];
        $is_audit = !empty($order_header['ebs_order_number']) ? 1 : 0; //通过EBS订单编号判断是否审核

        try {
            $error_msg = array();
            foreach ($change_datas as $field => $data) {
                //变更不同字段，作相应处理
                switch ($field) {
                    case 'is_update_rel_contract': //是否更新合同
                    case 'new_line_id': //新增订单行
                        $this->setOrderHeaderStatus($header_id, OCS_ORDER_HEADER_STATUS_TO_AUDIT); //头状态：待审核
                        $this->resetOrderAuditStatus($header_id); //清空审核数据
                    break;
                    case 'status': //行状态的变更
                    case 'quantity': //数量
                        $this->setOrderHeaderStatus($header_id, OCS_ORDER_HEADER_STATUS_TO_AUDIT); //头状态：待审核
                        $this->resetOrderAuditStatus($header_id); //清空审核数据

                        foreach ($data as $line_id => $temp) {
                            $is_exist_ebs_order_line = $g_OrderLine->isEbsExistOrderLine($line_id); //判断该订单行在EBS是否存在
                            if (!$is_exist_ebs_order_line) { //该行还未传EBS，不需处理
                                continue;
                            }
                            $po_result = $g_OrderLine->holdOrder($header_id, $line_id, 'Y'); //暂挂订单行
                            if (!$po_result['success']) {
                                $log_content = $temp['msg'] . '；暂挂订单行失败：' . $po_result['message'];
                                $g_Logs->write_log('OrderHeaders', $header_id, 'info', $log_content);
                                $error_msg[] = $log_content;
                            }
                        }
                    break;
                    // case 'status': //行状态的变更
                    //     $this->setOrderHeaderStatus($header_id, OCS_ORDER_HEADER_STATUS_TO_AUDIT); //头状态：待审核
                    //     $this->resetOrderAuditStatus($header_id); //清空审核数据

                    //     foreach ($data as $line_id => $temp) {
                    //         $hold_flag = '';
                    //         if (OCS_ORDER_LINE_STATUS_PRE_CANCELLED == $temp['new_value']) { //作废订单行
                    //             $hold_flag = 'Y';
                    //         } else if (OCS_ORDER_LINE_STATUS_PRE_CANCELLED == $temp['old_value']
                    //          && OCS_ORDER_LINE_STATUS_PRE_CANCELLED != $temp['new_value']
                    //          && OCS_ORDER_LINE_STATUS_CANCELLED != $temp['new_value']) {
                    //             $hold_flag = 'N'; //取消暂挂
                    //         }
                    //         if (empty($hold_flag)) { //不需处理
                    //             continue;
                    //         }
                    //         $po_result = $g_OrderLine->holdOrder($header_id, $line_id, $hold_flag); //暂挂/取消暂挂订单行
                    //         if (!$po_result['success']) {
                    //             $log_content = $temp['msg'] . '；暂挂订单操作：' . $hold_flag . '行失败：' . $po_result['message'];
                    //             $g_Logs->write_log('OrderHeaders', $header_id, 'info', $log_content);
                    //             $error_msg[] = $log_content;
                    //         }
                    //     }
                    // break;
                }
            }
            if (!empty($error_msg)) {
                $result['message'] = implode(';', $error_msg);
                return $result;
            }

            $g_Logs->write_log('OrderHeaders', $header_id, 'info', __FUNCTION__ . '变更保存前，相关操作成功');
            $result['success'] = true;
            return $result;
        } catch (Exception $e) {
            $g_Logs->write_log('OrderHeaders', $header_id, 'info', $e->getMessage());
            $result['message'] = __FUNCTION__ . $e->getMessage();
            return $result;
        }
    }

    /**
     * 关键信息变更且审核通过后，进行相关处理
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-16T21:20:46+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     *
     * @return   [type]                              [description]
     */
    function dualAfterPrimaryAttrChange($header_id = null) {
        global $g_Commons;
        $g_RelOrderHeader = $g_Commons->GlobalModel('RelOrderHeader');
        $g_OrderLine = $g_Commons->GlobalModel('OrderLine');
        $g_Logs = $g_Commons->GlobalController('Logs');
        $result = $g_Commons->initResult();
        if (empty($header_id)) {
            $result['message'] = __FUNCTION__ . '参数为空';
            return $result;
        }

        $order_header = $this->find('first', array(
            'conditions' => array(
                'OrderHeader.id' => $header_id,
            ),
            'fields' => array(
                'OrderHeader.id',
                'OrderHeader.status',
                'OrderHeader.ebs_order_number',
            )
        ));
        if (empty($order_header)) {
            $result['message'] = __FUNCTION__ . '订单不存在';
            return $result;
        }
        try {
            if (OCS_ORDER_HEADER_STATUS_DONE != $order_header['OrderHeader']['status']) {
                throw new Exception(__FUNCTION__ . '订单头状态非“审核完成”，执行关键信息变更后相关操作，失败。', 1);
            }
            if (empty($order_header['OrderHeader']['ebs_order_number'])) {
                throw new Exception(__FUNCTION__ . '订单未同步EBS，执行关键信息变更后相关操作，失败。', 1);
            }

            //获取合并后保存变更内容
            $rel_order_header = $g_RelOrderHeader->find('first', array(
                'conditions' => array(
                    'RelOrderHeader.header_id' => $header_id,
                ),
                'fields' => array(
                    'RelOrderHeader.id',
                    'RelOrderHeader.change_datas',
                )
            ));
            $change_datas = !empty($rel_order_header) && !empty($rel_order_header['RelOrderHeader']['change_datas']) ? json_decode($rel_order_header['RelOrderHeader']['change_datas'], true) : array();
            if (empty($change_datas)) { //无变更内容，无需处理
                $result['success'] = true;
                return $result;
            }
            foreach ($change_datas as $field => $data) {
                //变更不同字段，作相应处理
                switch ($field) {
                    case 'status': //状态
                    case 'quantity': //数量
                        //解除暂挂订单行
                        foreach ($data as $line_id => $temp) {
                            $is_exist_ebs_order_line = $g_OrderLine->isEbsExistOrderLine($line_id); //判断该订单行在EBS是否存在
                            if (!$is_exist_ebs_order_line) { //该行还未传EBS，不需处理
                                continue;
                            }
                            $po_result = $g_OrderLine->holdOrder($header_id, $line_id, 'N');
                            if (!$po_result['success']) {
                                $log_content = $temp['msg'] . '；解除暂挂订单行失败：' . $po_result['message'];
                                $g_Logs->write_log('OrderHeaders', $header_id, 'info', $log_content);
                                $error_msg[] = $log_content;
                            }
                        }

                        //通知财务
                        $this->emailNotify($header_id, 'AuditedChangeNotifyFinance');

                        //通知工厂
                        $this->emailNotify($header_id, 'ChangeNotifyFactory');
                    break;
                    case 'new_line_id': //新增订单行
                        //通知财务
                        $this->emailNotify($header_id, 'AuditedChangeNotifyFinance');

                        //通知工厂
                        $this->emailNotify($header_id, 'ChangeNotifyFactory');
                    break;
                    case 'mto_no': //工厂
                    case 'subinventory_code': //子库
                    case 'locator': //货位
                        //通知工厂
                        $this->emailNotify($header_id, 'ChangeNotifyFactory');
                    break;
                }
            }

            //处理完成，清空变更内容
            $g_Logs->write_log('RelOrderHeaders', $rel_order_header['RelOrderHeader']['id'], 'info', '审核通过，清空数据前：change_datas = ' . $rel_order_header['RelOrderHeader']['change_datas']);
            $g_RelOrderHeader->saveRelFields($header_id, array('change_datas' => ''));

            //成功，写log
            $g_Logs->write_log('OrderHeaders', $header_id, 'info', __FUNCTION__ . '审核通过后，相关操作成功');
            $result['success'] = true;
            return $result;
        } catch (Exception $e) {
            $g_Logs->write_log('OrderHeaders', $header_id, 'info', $e->getMessage());
            $result['message'] = __FUNCTION__ . $e->getMessage();
            return $result;
        }
    }

    /**
     * 设置订单头状态
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-16T15:06:21+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     * @param    [type]                   $status    [目标状态]
     */
    function setOrderHeaderStatus($header_id = null, $status = null) {
        global $g_Commons;
        $g_Enum = $g_Commons->GlobalModel('Enum');
        $g_Logs = $g_Commons->GlobalController('Logs');
        if (empty($header_id) || empty($status)) {
            return;
        }

        $order_header = $this->find('first', array(
            'conditions' => array(
                'OrderHeader.id' => $header_id,
            ),
            'fields' => array(
                'OrderHeader.id',
                'OrderHeader.status',
            )
        ));
        if (empty($order_header)) {
            return;
        }
        if ($order_header['OrderHeader']['status'] == $status) { //无需变更
            return;
        }
        $header_status_list = $g_Enum->getEnumListByDictName('OrderHeader.status');
        $old_status = $order_header['OrderHeader']['status'];

        $order_header['OrderHeader']['status'] = $status;
        $this->save($order_header['OrderHeader']);

        $log_content = '订单头状态变更：' . $header_status_list[$old_status] . ' => ' . $header_status_list[$status];
        $g_Logs->write_log('OrderHeaders', $header_id, 'info', $log_content);
    }

    /**
     * 根据OCS订单行状态，同步设置EBS对应订单行状态
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-17T09:43:54+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     *
     * @return   [type]                              [description]
     */
    function cancelRelOrderLine($header_id = null) {
        global $g_Commons;
        $g_EbsDbo = $g_Commons->GlobalModel('EbsDbo');
        $g_Logs = $g_Commons->GlobalController('Logs');
        $result = $g_Commons->initResult();
        if (empty($header_id)) {
            $result['message'] = __FUNCTION__ . '参数为空';
            return $result;
        }

        $order_lines = $this->find('all', array(
            'joins' => array(
                array(
                    'table' => 'order_lines',
                    'alias' => 'OrderLine',
                    'type' => 'inner',
                    'conditions' => 'OrderHeader.id = OrderLine.header_id'
                ),
            ),
            'conditions' => array(
                'OrderHeader.id' => $header_id,
                'OrderHeader.status' => OCS_ORDER_HEADER_STATUS_DONE, //订单必须审核通过
                'OrderHeader.ebs_order_number IS NOT NULL',
                'OrderLine.status' => OCS_ORDER_LINE_STATUS_CANCELLED, //订单行状态为“作废”的
            ),
            'fields' => array(
                'OrderHeader.id',
                'OrderLine.id',
            )
        ));
        if (empty($order_lines)) { //不存在作废行，无需处理
            $result['success'] = true;
            return $result;
        }

        $erp_db = new ERPDATABASE_CONFIG();
        $conn = oci_pconnect($erp_db->username, $erp_db->password, $erp_db->tns, $erp_db->charset); //连接erp
        if (!$conn) {
            $result['message'] = __FUNCTION__ . '连接EBS数据库，出错！';
            return $result;
        }

        $error_msg = array();
        foreach ($order_lines as $order_line) {
            $ebs_order_line = $g_EbsDbo->find('first', array(
                'joins' => array(
                    array(
                        'table' => 'apps.oe_order_headers_all',
                        'alias' => 'OOH',
                        'type' => 'inner',
                        'conditions' => 'OOH.header_id = OOL.header_id'
                    ),
                ),
                'main_table' => array(
                    'apps.oe_order_lines_all' => 'OOL',
                ),
                'conditions' => array(
                    'OOH.ATTRIBUTE8' => $this->prefix . $header_id,
                    'OOL.ATTRIBUTE12' => $this->prefix . $order_line['OrderLine']['id'],
                ),
                'fields' => array(
                    'OOH.header_id',
                    'OOH.order_number',
                    'OOL.line_id',
                    'OOL.FLOW_STATUS_CODE',
                )
            ));
            if (empty($ebs_order_line)) {
                // $error_msg[] =  __FUNCTION__ . '订单在EBS不存在，attribute8=' . $this->prefix . $header_id . '，attribute12=' . $this->prefix . $order_line['OrderLine']['id'];
                continue;
            }
            if ('CANCELLED' == $ebs_order_line['OOL']['FLOW_STATUS_CODE']) { //订单在EBS已经作废，无需处理
                continue;
            }
            $ebs_header_id = $ebs_order_line['OOH']['HEADER_ID'];
            $ebs_line_id = $ebs_order_line['OOL']['LINE_ID'];

            $sql = '
                begin
                  :po_status := apps.xxomp_process_sales_order.cancel_so_line(p_header_id => :p_header_id,
                                                                      p_line_id => :p_line_id);
                end;
            ';
            $po_status = '';
            $stid = oci_parse($conn, $sql);
            oci_bind_by_name($stid, ":p_header_id", $ebs_header_id, -1);
            oci_bind_by_name($stid, ":p_line_id", $ebs_line_id, -1);
            oci_bind_by_name($stid, ":po_status", $po_status, 2000);
            oci_execute($stid);

            if ('S' != $po_status) { //取消失败
                $error_msg[] = __FUNCTION__ . '作废订单行失败，p_header_id=' . $ebs_header_id . '，p_line_id=' . $ebs_line_id;
                continue;
            }
        }

        if (!empty($error_msg)) {
            $result['message'] = implode(';', $error_msg);
            $g_Logs->write_log('OrderHeaders', $header_id, 'info', $result['message']);
            return $result;
        }

        $result['success'] = true;
        return $result;
    }

    /**
     * 同步更新EBS订单行项目号（工厂）
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-17T11:33:28+0800
     *
     * @param    [type]                   $header_id [OCS订单头ID]
     *
     * @return   [type]                              [description]
     */
    function updateOrderLineProjectNo($header_id = null) {
        global $g_Commons;
        $g_Enum = $g_Commons->GlobalModel('Enum');
        $g_EbsDbo = $g_Commons->GlobalModel('EbsDbo');
        $g_Logs = $g_Commons->GlobalController('Logs');
        $result = $g_Commons->initResult();
        if (empty($header_id)) {
            $result['message'] = __FUNCTION__ . '参数为空';
            return $result;
        }

        $order_lines = $this->find('all', array(
            'joins' => array(
                array(
                    'table' => 'order_lines',
                    'alias' => 'OrderLine',
                    'type' => 'inner',
                    'conditions' => 'OrderHeader.id = OrderLine.header_id'
                ),
            ),
            'conditions' => array(
                'OrderHeader.id' => $header_id,
                'OrderHeader.status' => OCS_ORDER_HEADER_STATUS_DONE, //订单必须审核通过
                'OrderHeader.ebs_order_number IS NOT NULL',
                'OrderLine.status != ' => OCS_ORDER_LINE_STATUS_CANCELLED,
            ),
            'fields' => array(
                'OrderHeader.id',
                'OrderHeader.ebs_order_number',
                'OrderLine.id',
                'OrderLine.line_number',
                'OrderLine.mto_no',
            )
        ));
        if (empty($order_lines)) { //不存在作废行，无需处理
            $result['success'] = true;
            return $result;
        }

        $erp_db = new ERPDATABASE_CONFIG();
        $conn = oci_pconnect($erp_db->username, $erp_db->password, $erp_db->tns, $erp_db->charset); //连接erp
        if (!$conn) {
            $result['message'] = __FUNCTION__ . '连接EBS数据库，出错！';
            return $result;
        }

        $error_msg = array();
        foreach ($order_lines as $order_line) {
            $ebs_order_line = $g_EbsDbo->find('first', array(
                'joins' => array(
                    array(
                        'table' => 'apps.oe_order_headers_all',
                        'alias' => 'OOH',
                        'type' => 'inner',
                        'conditions' => 'OOH.header_id = OOL.header_id'
                    ),
                ),
                'main_table' => array(
                    'apps.oe_order_lines_all' => 'OOL',
                ),
                'conditions' => array(
                    'OOH.ATTRIBUTE8' => $this->prefix . $header_id,
                    'OOL.ATTRIBUTE12' => $this->prefix . $order_line['OrderLine']['id'],
                ),
                'fields' => array(
                    'OOH.header_id',
                    'OOH.order_number',
                    'OOL.line_id',
                    'OOL.FLOW_STATUS_CODE',
                    'OOL.LINE_NUMBER',
                    'OOL.SHIPMENT_NUMBER',
                    'OOL.PROJECT_ID',
                )
            ));
            if (empty($ebs_order_line)) {//该行未传EBS
                continue;
            }

            $ebs_order_number = $order_line['OrderHeader']['ebs_order_number'];
            $line_number = $ebs_order_line['OOL']['LINE_NUMBER'] . '.' . $ebs_order_line['OOL']['SHIPMENT_NUMBER']; //行号
            $mto_no = $order_line['OrderLine']['mto_no'];

            if (in_array($ebs_order_line['OOL']['FLOW_STATUS_CODE'], array('CANCELLED', 'CLOSED'))) { //订单在EBS已经作废或者关闭，不允许修改
                $error_msg[] = 'EBS订单行#' . $line_number . '已经' . $ebs_order_line['OOL']['FLOW_STATUS_CODE'] . '，允许修改工厂';
                continue;
            }

            //如两边系统的工厂一致，则不需执行
            $ocs_project_id = $g_Enum->getProjectIdByMtoNo($order_line['OrderLine']['mto_no']);
            if ($ocs_project_id == $ebs_order_line['OOL']['PROJECT_ID']) {
                continue;
            }

            $sql = '
                begin
                  -- Call the procedure
                  apps.xxsrm0401_om_update_mtono.main(errbuf => :errbuf,
                                                 retcode => :retcode,
                                                 p_order_number => :p_order_number,
                                                 p_order_line => :p_order_line,
                                                 p_new_proj_no => :p_new_proj_no);
                  COMMIT;
                end;
            ';
            $errbuf = '';
            $retcode = '';
            $stid = oci_parse($conn, $sql);
            //input
            oci_bind_by_name($stid, ":p_order_number", $ebs_order_number, -1);
            oci_bind_by_name($stid, ":p_order_line", $line_number, -1);
            oci_bind_by_name($stid, ":p_new_proj_no", $mto_no, -1);
            //output
            oci_bind_by_name($stid, ":errbuf", $errbuf, 2000);
            oci_bind_by_name($stid, ":retcode", $retcode, 2000);

            $po_status = oci_execute($stid);
            if ($po_status) { //成功
                $g_Logs->write_log('OrderHeaders', $header_id, 'info', __FUNCTION__ . '更新项目号成功：' . $mto_no);
            } else {
                $error_msg[] = __FUNCTION__ . 'sql执行出错，#' . $sql . '<br/> ebs_order_number=' . $ebs_order_number . ',line_number=' . $line_number . ',mto_no=' . $mto_no;
            }
        }

        if (!empty($error_msg)) {
            $result['message'] = implode(';', $error_msg);
            $g_Logs->write_log('OrderHeaders', $header_id, 'info', $result['message']);
            return $result;
        }

        $result['success'] = true;
        return $result;
    }

    /**
     * 获取退货订单信息，并标记变更信息
     *
     * 对本次变更的信息添加标识
     * 本次行数据变更为作废，需要显示并标识出来，其他情况不显示已作废的数据
     *
     * @Author   zhangguocai
     *
     * @DateTime 2016-05-17T16:45:56+0800
     *
     * @param    array             $return_orders [description]
     *
     * @return   [type]                           [description]
     */
    function getEmailNoitfyChangeDatas($return_orders = array()) {
        global $g_Commons;
        $g_RelOrderHeader = $g_Commons->GlobalModel('RelOrderHeader');

        if (empty($return_orders) || !is_array($return_orders)) {
            return array();
        }
        $return_order = end($return_orders);
        if (empty($return_order['OrderHeader']['id']) || !is_numeric($return_order['OrderHeader']['id'])) {
            return array();
        }
        $header_id = $return_order['OrderHeader']['id'];

        //获取合并后保存变更内容
        $rel_order_header = $g_RelOrderHeader->findByHeaderId($header_id);
        if (isset($rel_order_header['RelOrderHeader']['change_datas']) && !empty($rel_order_header['RelOrderHeader']['change_datas'])) {
            $change_datas = json_decode($rel_order_header['RelOrderHeader']['change_datas'], true);

            foreach ($return_orders as $order_index => $return_order) {
                $line_id = $return_order['OrderLine']['id'];

                // 获取行状态
                $line_status = isset($return_order['OrderLine']['status']) ? $return_order['OrderLine']['status'] : null;
                $line_status_value = (is_array($line_status) && isset($line_status['value'])) ? $line_status['value'] : $line_status;

                switch ($line_status_value) {
                    case OCS_ORDER_LINE_STATUS_CANCELLED://如果退货行已经作废,且有状态变更单,那么显示该行数据,否则不显示
                        if (!(isset($change_datas['status']) && isset($change_datas['status'][$line_id]))) {
                            unset($return_orders[$order_index]);
                            continue;
                        }
                        break;
                }

                foreach ($return_order['OrderLine'] as $line_field => $line_data) {
                    if (!isset($change_datas[$line_field][$line_id])) {
                        continue;
                    }

                    $change_label = '<span style="color:#f00;">(原:' . $change_datas[$line_field][$line_id]['old_label'] . ')</span>';
                    if (is_array($line_data) && isset($line_data['label'])) {
                        $line_data['label'] = $line_data['label'] . $change_label;
                    } else {
                        $line_data = $line_data . $change_label;
                    }

                    $return_orders[$order_index]['OrderLine']['is_change'] = true;
                    $return_orders[$order_index]['OrderLine'][$line_field] = $line_data;
                }

                // 如果是新插入的行,那么在这里增加标识
                $ebs_order_number = !empty($return_order['OrderHeader']['ebs_order_number']) ? $return_order['OrderHeader']['ebs_order_number'] : null;
                if (isset($change_datas['new_line_id'][$line_id])) {
                    $return_orders[$order_index]['OrderLine']['is_change'] = true;
                    $return_orders[$order_index]['OrderHeader']['ebs_order_number'] = '<b style="color:#32CD32;">' . $ebs_order_number . ' (新)</b>';
                }

                // 如果更新了状态,那么在这里添加删除线
                if (isset($change_datas['status'][$line_id])) {
                    $status_label = !empty($return_order['OrderLine']['status']['label']) ? $return_order['OrderLine']['status']['label'] : null;
                    $return_orders[$order_index]['OrderLine']['is_change'] = true;
                    $return_orders[$order_index]['OrderHeader']['ebs_order_number'] = '<s><b style="color:#f00;">' . $ebs_order_number . ' (' . $status_label . ')</b></s>';
                }
            }
        }

        return $return_orders;
    }

    /**
     * 同步设置OCS订单对应的工作流状态FLOW_STATUS_CODE
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-17T22:49:21+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     *
     * @return   [type]                              [description]
     */
    function syncOrderFlowStatusCode($header_id = null) {
        global $g_Commons;
        $g_OrderLine = $g_Commons->GlobalModel('OrderLine');
        $g_EbsDbo = $g_Commons->GlobalModel('EbsDbo');
        $result = $g_Commons->initResult();
        if (empty($header_id)) {
            $result['message'] = __FUNCTION__ . '参数为空';
            return $result;
        }

        //获取所有订单行
        $order_lines = $this->find('all', array(
            'joins' => array(
                array(
                    'table' => 'order_lines',
                    'alias' => 'OrderLine',
                    'type' => 'inner',
                    'conditions' => 'OrderLine.header_id = OrderHeader.id',
                ),
            ),
            'conditions' => array(
                'OrderHeader.id' => $header_id,
                'OrderHeader.ebs_order_number IS NOT NULL',
            ),
            'fields' => array(
                'OrderHeader.id',
                'OrderHeader.ebs_order_number',
                'OrderLine.id',
                'OrderLine.flow_status_code',
            )
        ));
        if (empty($order_lines)) {
            $result['success'] = true;
            return $result;
        }

        $ebs_order_lines = $g_EbsDbo->find('all', array(
            'joins' => array(
                array(
                    'table' => 'apps.oe_order_headers_all',
                    'alias' => 'OOH',
                    'type' => 'inner',
                    'conditions' => 'OOH.header_id = OOL.header_id'
                ),
            ),
            'main_table' => array(
                'apps.oe_order_lines_all' => 'OOL',
            ),
            'conditions' => array(
                'OOH.ATTRIBUTE8' => $this->prefix . $header_id,
            ),
            'fields' => array(
                'OOH.header_id',
                'OOH.ORDER_NUMBER',
                'OOL.LINE_ID',
                'OOL.ATTRIBUTE12',
                'OOL.FLOW_STATUS_CODE',
            )
        ));
        if (empty($ebs_order_lines)) {
            $result['message'] = __FUNCTION__ . 'EBS订单不存在';
            return $result;
        }
        $index_ebs_order_lines = array();
        foreach ($ebs_order_lines as $ebs_order_line) {
            $attribute12 = $ebs_order_line['OOL']['ATTRIBUTE12'];
            $index_ebs_order_lines[$attribute12] = $ebs_order_line;
        }

        foreach ($order_lines as $order_line) {
            $header_id = $order_line['OrderHeader']['id'];
            $line_id = $order_line['OrderLine']['id'];
            $attribute12 = $this->prefix . $line_id;
            if (!isset($index_ebs_order_lines[$attribute12])) {
                continue;
            }
            $ebs_order_line = $index_ebs_order_lines[$attribute12];

            if ($order_line['OrderLine']['flow_status_code'] == $ebs_order_line['OOL']['FLOW_STATUS_CODE']) {
                continue;
            }
            $flow_status_code = $ebs_order_line['OOL']['FLOW_STATUS_CODE'];

            $line = array();
            $line['id'] = $line_id;
            $line['flow_status_code'] = $flow_status_code;
            $g_OrderLine->save($line);
        }

        $result['success'] = true;
        return $result;
    }

    /**
     * 取消订单头
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-18T19:43:24+0800
     *
     * @param    [type]                   $header_id [OCS订单头ID]
     *
     * @return   [type]                              [description]
     */
    function cancelOrderHeader($header_id = null) {
        global $g_Commons;
        $result = $g_Commons->initResult();
        if (empty($header_id)) {
            $result['message'] = '参数错误，header_id为空';
            return $result;
        }
        $type = $this->get_by_id($header_id, 'type');
        if (empty($type)) {
            $result['message'] = '操作失败，订单类别字段为空，header_id=' . $header_id;
            return $result;
        }
        $model = new OrderContext($type);
        $po_result = $model->cancelOrderHeader($header_id);

        return $po_result;
    }

    /**
     * 根据EBS订单状态，同步设置OCS订单头状态
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-18T21:38:34+0800
     *
     * @return   [type]                   [description]
     */
    // function syncOrderHeaderStatus($header_ids = array()) {
    //     global $g_Commons;
    //     $g_EbsDbo = $g_Commons->GlobalModel('EbsDbo');
    //     $g_Logs = $g_Commons->GlobalController('Logs');
    //     $result = $g_Commons->initResult();

    //     $conds = array();
    //     $conds['OrderHeader.status NOT'] = array(OCS_ORDER_HEADER_STATUS_CANCELLED, OCS_ORDER_HEADER_STATUS_CLOSED); //排除“作废”、“关闭”订单
    //     if (!empty($header_ids)) {
    //         $conds['OrderHeader.id'] = $header_ids;
    //     }
    //     $conds[] = 'OrderHeader.ebs_order_number IS NOT NULL';

    //     $order_headers = $this->find('all', array(
    //         'conditions' => $conds,
    //         'fields' => array(
    //             'OrderHeader.id',
    //             'OrderHeader.status',
    //         )
    //     ));
    //     if (empty($order_headers)) {
    //         $result['message'] = '满足条件，需要同步的数据为空';
    //         return $result;
    //     }
    //     foreach ($order_headers as $order_header) {
    //         //同步行状态
    //         $this->syncOrderFlowStatusCode($order_header['OrderHeader']['id']);

    //         $ebs_order_lines = $g_EbsDbo->find('all', array(
    //             'joins' => array(
    //                 array(
    //                     'table' => 'apps.oe_order_headers_all',
    //                     'alias' => 'OOH',
    //                     'type' => 'inner',
    //                     'conditions' => 'OOH.header_id = OOL.header_id'
    //                 ),
    //             ),
    //             'main_table' => array(
    //                 'apps.oe_order_lines_all' => 'OOL',
    //             ),
    //             'conditions' => array(
    //                 'OOH.ATTRIBUTE8' => $this->prefix . $order_header['OrderHeader']['id'],
    //             ),
    //             'fields' => array(
    //                 'OOH.HEADER_ID',
    //                 'OOH.ATTRIBUTE8',
    //                 'OOH.ORDER_NUMBER',
    //                 'OOH.FLOW_STATUS_CODE',
    //                 'OOL.LINE_ID',
    //                 'OOL.ATTRIBUTE12',
    //                 'OOL.FLOW_STATUS_CODE',
    //                 'OOL.ORDERED_QUANTITY',
    //             )
    //         ));
    //         if (empty($ebs_order_lines)) {
    //             continue;
    //         }
    //         $ebs_order_header = $ebs_order_lines[0];

    //         //如订单头关闭，则订单关闭
    //         $is_closed = 'CLOSED' == $ebs_order_header['OOH']['FLOW_STATUS_CODE'] ? true : false;
    //         if ($is_closed) {
    //             $order_header['OrderHeader']['status'] = OCS_ORDER_HEADER_STATUS_CLOSED;
    //             $this->save($order_header['OrderHeader']);

    //             $g_Logs->write_log('OrderHeaders', $order_header['OrderHeader']['id'], 'info', 'EBS订单头关闭，OCS订单头状态自动设置为“关闭”状态');
    //         }

    //         $is_closed = true;
    //         $row_count = 0;
    //         foreach ($ebs_order_lines as $ebs_order_line) {
    //             if ('CANCELLED' == $ebs_order_line['OOL']['FLOW_STATUS_CODE'] || empty($ebs_order_line['OOL']['ORDERED_QUANTITY'])) {
    //                 continue;
    //             }
    //             if ('CLOSED' != $ebs_order_line['OOL']['FLOW_STATUS_CODE']) {
    //                 $is_closed = false;
    //                 break;
    //             } else {
    //                 $row_count++;
    //             }
    //         }
    //         if (!$is_closed) {
    //             continue;
    //         }
    //         if ($is_closed && $row_count > 0) { //全部订单行都已经关闭，则OCS订单头也关闭
    //             $order_header['OrderHeader']['status'] = OCS_ORDER_HEADER_STATUS_CLOSED;
    //             $this->save($order_header['OrderHeader']);

    //             $g_Logs->write_log('OrderHeaders', $order_header['OrderHeader']['id'], 'info', 'EBS所有订单行都已经关闭，OCS订单头状态自动设置为“关闭”状态');
    //         }
    //     }

    //     $result['success'] = true;
    //     return $result;
    // }

    /**
     * 通过headerId获取该订单已经关闭订单行的数量
     *
     * @Author   linfangjie
     *
     * @DateTime 2017-05-11T11:23:44+0800
     *
     * @param    [type]                   $header_id [订单行id]
     *
     * @return   [type]                                     [description]
     */
    function getCloseNumByHeaderId($header_id = null){
        global $g_Commons;
        $result = $g_Commons->initResult();
        if (empty($header_id)) {
            return 0;
        }
        $prefix_header_id = $this->prefix . $header_id;
        $g_EbsDbo = $g_Commons->GlobalModel('EbsDbo');
        $sql = "
            SELECT SUM(Ool.Ordered_Quantity) Ordered_Quantity -- 取消订单数量
              FROM Apps.Oe_Order_Lines_All   Ool
                  ,Apps.Oe_Order_Headers_All Ooh
             WHERE Ooh.Header_Id = Ool.Header_Id
               AND Ooh.Attribute8 = '$prefix_header_id'
               AND Ool.Flow_Status_Code = 'CLOSED'
        ";
        $ebs_order_lines = $g_EbsDbo->query($sql);

        $quantity = empty($ebs_order_lines[0]['ORDERED_QUANTITY']) ? 0 : $ebs_order_lines[0]['ORDERED_QUANTITY'];
        return $quantity;
    }

    /**
     * 批量通过headerIds获取该订单已经关闭订单行的数量
     *
     * @Author   linfangjie
     *
     * @DateTime 2017-05-10T19:57:44+0800
     *
     * @param    [type]                   $header_ids [订单行id]
     *
     * @return   [type]                                     [description]
     */
    function getCloseNumByHeaderIds($header_ids = null){
        global $g_Commons;
        $result = $g_Commons->initResult();
        if (empty($header_ids)) {
            $result['message'] = '不存在订单头id';
            return $result;
        }

        if (is_array($header_ids)) {
            foreach ($header_ids as  $key => $header_id) {
                $header_ids[$key] = $this->prefix . $header_id;
            }
        }else{
            $header_ids = $this->prefix . $header_ids;
        }

        $g_EbsDbo = $g_Commons->GlobalModel('EbsDbo');
        $ebs_order_lines = $g_EbsDbo->find('all', array(
            'joins' => array(
                array(
                    'table' => 'apps.oe_order_headers_all',
                    'alias' => 'OOH',
                    'type' => 'inner',
                    'conditions' => 'OOH.header_id = OOL.header_id'
                ),
            ),
            'main_table' => array(
                'apps.oe_order_lines_all' => 'OOL',
            ),
            'conditions' => array(
                'OOH.ATTRIBUTE8' => $header_ids,
                'OOL.FLOW_STATUS_CODE' => 'CLOSED'
            ),
            'fields' => array(
                'OOH.HEADER_ID',
                'OOH.ATTRIBUTE8',
                'OOL.LINE_ID',
                'OOL.ORDERED_QUANTITY',
            )
        ));

        if (empty($ebs_order_lines)) {
            $result['message'] = '没有找到满足条件的EBS订单';
            return $result;
        }

        // 整理EBS订单，以订单头attribute8为key进行分组
        $ebs_orders = array();
        foreach ($ebs_order_lines as $order_line) {
            $attribute8 = $order_line['OOH']['ATTRIBUTE8'];
            $ebs_line_id = $order_line['OOL']['LINE_ID'];

            $ebs_orders[$attribute8][$ebs_line_id] = $order_line;
        }

        // 计算订单的关闭订单行数量
        $closed_line_qty = array();
        foreach ($ebs_orders as $attribute8 => $lines) {
            foreach ($lines as $line) {
                if (!isset($closed_line_qty[$attribute8])) {
                    $closed_line_qty[$attribute8] = 0;
                }
                $closed_line_qty[$attribute8] += $line['OOL']['ORDERED_QUANTITY'];
            }
        }

        $result['datas'] = $closed_line_qty;
        $result['success'] = true;
        return $result;
    }

    /**
     * 同步订单相关信息[订单状态、已建交付数量、已过帐数量]
     *
     * @Author   lishirong
     *
     * @DateTime 2017-05-02T15:51:10+0800
     *
     * @param    array                    $header_ids [description]
     *
     * @return   [type]                               [description]
     */
    function syncOrderRelInfo($header_ids = array()) {
        global $g_Commons;
        $g_EbsDbo = $g_Commons->GlobalModel('EbsDbo');
        $g_OrderLine = $g_Commons->GlobalModel('OrderLine');
        $g_Logs = $g_Commons->GlobalController('Logs');
        $result = $g_Commons->initResult();

        $conds = array();
        $conds['OrderHeader.status NOT'] = array(OCS_ORDER_HEADER_STATUS_CANCELLED, OCS_ORDER_HEADER_STATUS_CLOSED); //排除“作废”、“关闭”订单
        if (!empty($header_ids)) {
            $conds['OrderHeader.id'] = $header_ids;
        }
        $conds[] = 'OrderHeader.ebs_order_number IS NOT NULL';

        $order_headers = $this->find('all', array(
            'conditions' => $conds,
            'fields' => array(
                'OrderHeader.id',
                'OrderHeader.status',
                'OrderHeader.delivered_qty',
                'OrderHeader.posted_qty',
            )
        ));
        if (empty($order_headers)) {
            $result['message'] = '没有需要同步的订单数据';
            return $result;
        }

        $ebs_attribute8s = array();
        $ocs_header_ids = array();
        $ocs_order_headers = array();
        foreach ($order_headers as $order_header) {
            $header_id = $order_header['OrderHeader']['id'];
            $ebs_attribute8s[$header_id] = $this->prefix . $order_header['OrderHeader']['id'];
            $ocs_header_ids[] = $header_id;

            $ocs_order_headers[$header_id]= $order_header;
        }
        $ebs_attribute8s = array_unique($ebs_attribute8s);

        //获取OCS订单行信息并以line_id为下标整理
        $order_lines = $g_OrderLine->find('all', array(
            'conditions' => array(
                'OrderLine.header_id' => $ocs_header_ids,
                'OrderLine.status != ' . OCS_ORDER_LINE_STATUS_CANCELLED,
            ),
            'fields' => array(
                'OrderLine.id',
                'OrderLine.header_id',
                'OrderLine.status',
                'OrderLine.flow_status_code',
            )
        ));
        $ocs_order_lines = array();
        foreach ($order_lines as $order_line) {
            $ocs_order_lines[$order_line['OrderLine']['id']] = $order_line;
        }

        //通过attribute8查找EBS对应的订单数据
        $ebs_order_lines = $g_EbsDbo->find('all', array(
            'joins' => array(
                array(
                    'table' => 'apps.oe_order_headers_all',
                    'alias' => 'OOH',
                    'type' => 'inner',
                    'conditions' => 'OOH.header_id = OOL.header_id'
                ),
            ),
            'main_table' => array(
                'apps.oe_order_lines_all' => 'OOL',
            ),
            'conditions' => array(
                'OOH.ATTRIBUTE8' => $ebs_attribute8s,
            ),
            'fields' => array(
                'OOH.HEADER_ID',
                'OOH.ATTRIBUTE8',
                'OOH.ORDER_NUMBER',
                'OOH.FLOW_STATUS_CODE',
                'OOL.LINE_ID',
                'OOL.ATTRIBUTE12',
                'OOL.FLOW_STATUS_CODE',
                'OOL.ORDERED_QUANTITY',
            )
        ));
        if (empty($ebs_order_lines)) {
            $result['message'] = '没有找到满足条件的EBS订单';
            return $result;
        }

        //整理EBS订单，以订单头attribute8为key进行分组
        $ebs_orders = array();
        foreach ($ebs_order_lines as $order_line) {
            $attribute8 = $order_line['OOH']['ATTRIBUTE8'];
            $ebs_line_id = $order_line['OOL']['LINE_ID'];

            $ebs_orders[$attribute8][$ebs_line_id] = $order_line;
        }

        $closed_line_qty = array(); //记录订单的关闭订单行数量
        foreach ($ebs_orders as $attribute8 => $lines) {
            //设置OCS订单行以及订单头状态
            $is_header_closed = false;
            $is_has_not_closed = false;
            $closed_row_count = 0;
            foreach ($lines as $line) {
                $ocs_line_id = str_replace($this->prefix, '', $line['OOL']['ATTRIBUTE12']);
                if (isset($ocs_order_lines[$ocs_line_id])) { //更新行状态
                    $ocs_line = array();
                    $ocs_line['id'] = $ocs_order_lines[$ocs_line_id]['OrderLine']['id'];
                    $ocs_line['flow_status_code'] = $line['OOL']['FLOW_STATUS_CODE'];

                    $g_OrderLine->save($ocs_line);
                }

                $is_header_closed = 'CLOSED' == $line['OOH']['FLOW_STATUS_CODE'] ? true : false;

                if ('CANCELLED' == $line['OOL']['FLOW_STATUS_CODE'] || empty($line['OOL']['ORDERED_QUANTITY'])) {
                    continue;
                }
                if ('CLOSED' != $line['OOL']['FLOW_STATUS_CODE']) {
                    $is_has_not_closed = true;
                } else {
                    $closed_row_count++;

                    if (!isset($closed_line_qty[$attribute8])) {
                        $closed_line_qty[$attribute8] = 0;
                    }
                    $closed_line_qty[$attribute8] += $line['OOL']['ORDERED_QUANTITY'];
                }
            }

            //订单头已经关闭
            if ($is_header_closed) {
                $order_header['OrderHeader']['status'] = OCS_ORDER_HEADER_STATUS_CLOSED;
                $this->save($order_header['OrderHeader']);

                $g_Logs->write_log('OrderHeaders', $order_header['OrderHeader']['id'], 'info', 'EBS订单头已经关闭，OCS订单头状态自动设置为“关闭”状态');
            } else if (!$is_has_not_closed && $closed_row_count > 0) { //全部订单行都已经关闭，则OCS订单头也关闭
                $order_header['OrderHeader']['status'] = OCS_ORDER_HEADER_STATUS_CLOSED;
                $this->save($order_header['OrderHeader']);

                $g_Logs->write_log('OrderHeaders', $order_header['OrderHeader']['id'], 'info', 'EBS所有订单行都已经关闭，OCS订单头状态自动设置为“关闭”状态');
            }
        }

        //获取已建交付数量
        $delivered_qtys = $this->getDeliveredQtyByAttribute8($ebs_attribute8s);

        //保存交付、出入库数量
        foreach ($delivered_qtys as $attribute8 => $delivered_qty) {
            $header_id = str_replace($this->prefix, '', $attribute8);
            if (!isset($ocs_order_headers[$header_id])) {
                continue;
            }
            $ocs_order_headers[$header_id]['OrderHeader']['delivered_qty'] = $delivered_qty; //已建交付数量
            if (isset($closed_line_qty[$attribute8])) { //已入库/出库数量
                $posted_qty = $closed_line_qty[$attribute8];
                $ocs_order_headers[$header_id]['OrderHeader']['delivered_qty'] = $posted_qty;
            }

            $this->save($ocs_order_headers[$header_id]['OrderHeader']);
        }

        $result['success'] = true;
        return $result;
    }

    /**
     * 获取订单对应的已建交付数量（发货通知审核通过数量）
     *
     * @Author   lishirong
     *
     * @DateTime 2017-05-06T10:21:39+0800
     *
     * @param    array                    $ebs_attribute8s [description]
     *
     * @return   [type]                                    [description]
     */
    function getDeliveredQtyByAttribute8($ebs_attribute8s = array()) {
        if (empty($ebs_attribute8s)) {
            return array();
        }
        $ebs_attribute8s = !is_array($ebs_attribute8s) ? array($ebs_attribute8s) : $ebs_attribute8s;
        global $g_Commons;
        $g_EbsDbo = $g_Commons->GlobalModel('EbsDbo');

        $ebs_attribute8s = $g_Commons->SpliteArray($ebs_attribute8s, 900);
        $in_values = array();
        foreach ($ebs_attribute8s as $value) {
            $in_values[] = implode(', ', $g_EbsDbo->value($value, 'string'));
        }
        $conds_str = ' 1= 1';
        if (!empty($in_values)) {
            $conds_str = ' ooh.attribute8 IN (' . implode(') OR ooh.attribute8 IN (', $in_values) . ')';
        }

        $sql = "
            SELECT ooh.attribute8, NVL(SUM(Xsnl.Requested_Quantity),0) delivered_qty
              FROM xxcus.Xxom_Shipment_Notice_Headers Xsnh
                  ,xxcus.Xxom_Shipment_Notice_Lines Xsnl
                  ,apps.oe_order_headers_all ooh
             WHERE Xsnh.Notice_Id = Xsnl.Notice_Id
               AND Xsnh.Status <> 'CANCELLED'
               AND Xsnh.Doc_Type_Code <> 'CANCEL'
               AND Xsnh.Status IN ('CREATED', 'APPROVING', 'APPROVED', 'CLOSED')
               AND Xsnl.Order_Header_Id = ooh.header_id
               AND $conds_str
               GROUP BY ooh.attribute8
        ";
        $delivered_qtys = $g_EbsDbo->query($sql);
        // pr($delivered_qtys);

        $result = array();
        foreach ($delivered_qtys as $delivered_qty) {
            $result[$delivered_qty['ATTRIBUTE8']] = $delivered_qty['DELIVERED_QTY'];
        }

        return $result;
    }

    /**
     * 检测变更，并保存变更内容
     *
     * @Author   lishirong
     *
     * @DateTime 2016-05-20T16:06:08+0800
     *
     * @param    [type]                   $header_id   [订单头ID]
     * @param    array                    $check_lines [变更行]
     *
     * @return   [type]                                [description]
     */
    function checkAndSaveChangePrimaryAttrs($header_id = null, $check_lines = array()) {
        global $g_Commons;
        $g_RelOrderHeader = $g_Commons->GlobalModel('RelOrderHeader');

        //检查关键信息是否变更
        $po_result = $this->checkPrimaryAttrChange($header_id, $check_lines);
        if ($po_result['success']) { //无变更，无需处理
            return;
        }

        //关键信息 有变更
        $change_datas = $po_result['datas'];
        //获取合并后保存变更内容
        $rel_order_header = $g_RelOrderHeader->find('first', array(
            'conditions' => array(
                'RelOrderHeader.header_id' => $header_id,
            ),
            'fields' => array(
                'RelOrderHeader.id',
                'RelOrderHeader.change_datas',
            )
        ));
        $old_change_datas = !empty($rel_order_header) && !empty($rel_order_header['RelOrderHeader']['change_datas']) ? json_decode($rel_order_header['RelOrderHeader']['change_datas'], true) : array();
        $new_chang_datas = array();
        if (empty($old_change_datas)) {
            $new_chang_datas = $change_datas;
        } else { //合并
            $new_chang_datas = $old_change_datas;
            foreach ($change_datas as $field => $data) {
                if (!isset($new_chang_datas[$field])) { //旧的未变更此字段，则直接使用新的
                    $new_chang_datas[$field] = $data;
                    continue;
                }
                foreach ($data as $line_id => $temp) {
                    $new_chang_datas[$field][$line_id] = $temp;
                }
            }
        }
        $new_chang_datas_json = json_encode($new_chang_datas);
        $g_RelOrderHeader->saveRelFields($header_id, array('change_datas' => $new_chang_datas_json));
    }

    /**
     * 任务处理：同步订单
     *
     * @Author   lishirong
     *
     * @DateTime 2016-11-09T14:50:17+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     * @param    [type]                   $job_type  [该任务类型]
     *
     * @return   [type]                              [description]
     */
    private function _doJobSyncOrderToEbs($header_id = null, $job_type = null) {
        global $g_Commons;
        $result = $g_Commons->initResult();

        //同步订单
        $po_result = $this->syncOrderToEbs($header_id);
        if (!$po_result['success']) {
            return $po_result;
        }

        //唤醒下一个串行的队列任务
        $model = new OrderFactory();
        return $model->startNextQueueJob($header_id, $job_type);
    }

    /**
     * 任务处理：下推订单
     *
     * @Author   lishirong
     *
     * @DateTime 2016-11-09T14:50:17+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     * @param    [type]                   $job_type  [该任务类型]
     *
     * @return   [type]                              [description]
     */
    private function _doJobSetOrderStage($header_id = null, $job_type = null) {
        global $g_Commons;
        $result = $g_Commons->initResult();

        //下推订单
        $model = new OrderFactory();
        $po_result = $model->syncOrderStageToEbs($header_id);
        if (!$po_result['success']) {
            return $po_result;
        }

        //唤醒下一个串行的队列任务
        return $model->startNextQueueJob($header_id, $job_type);
    }

    /**
     * 任务处理：登记订单
     *
     * @Author   lishirong
     *
     * @DateTime 2016-11-09T14:50:17+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     * @param    [type]                   $job_type  [该任务类型]
     *
     * @return   [type]                              [description]
     */
    private function _doJobBookOrder($header_id = null, $job_type = null) {
        global $g_Commons;
        $result = $g_Commons->initResult();

        //登记订单
        $model = new OrderFactory();
        $po_result = $model->bookEbsOrder($header_id);
        if (!$po_result['success']) {
            return $po_result;
        }

        //唤醒下一个串行的队列任务
        return $model->startNextQueueJob($header_id, $job_type);
    }

    /**
     * 任务处理：自动创建发货
     *
     * @Author   lishirong
     *
     * @DateTime 2016-11-09T14:50:17+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     * @param    [type]                   $job_type  [该任务类型]
     *
     * @return   [type]                              [description]
     */
    private function _doJobAutoCreateDeliverys($header_id = null, $job_type = null) {
        global $g_Commons;
        $result = $g_Commons->initResult();

        // 自动创建发货通知
        $model = new OrderFactory();
        $result = $model->autoCreateDeliverys($header_id);
        if (empty($result['success']) || true !== $result['success']) {
            return $result;
        }

        // 唤醒下一个串行的队列任务
        return $model->startNextQueueJob($header_id, $job_type);
    }

    /**
     * 自动下推发货通知单
     *
     * @Author   zhangguocai
     *
     * @DateTime 2016-11-17T11:06:39+0800
     *
     * @param    [type]                   $header_id [description]
     * @param    [type]                   $job_type  [description]
     *
     * @return   [type]                              [description]
     */
    private function _doJobAutoApprovalAction($header_id = null, $job_type = null) {
        global $g_Commons;
        $result = $g_Commons->initResult();

        // 自动创建发货通知
        $model = new OrderFactory();
        $result = $model->autoApprovalAction($header_id);
        if (empty($result['success']) || true !== $result['success']) {
            return $result;
        }

        // 唤醒下一个串行的队列任务
        return $model->startNextQueueJob($header_id, $job_type);
    }

    /**
     * 任务队列执行默认方法，根据不同任务类型作相应的业务处理
     *
     * @Author   lishirong
     *
     * @DateTime 2016-11-09T11:19:54+0800
     *
     * @param    array                    $data [description]
     *
     * @return   [type]                         [description]
     */
    public function runQueueJob($data = array()) {
        global $g_Commons;
        $result = $g_Commons->initResult();
        if (empty($data)) {
            $result['message'] = '参数data为空';
            return $result;
        }
        if (!isset($data['type']) || empty($data['type'])) {
            $result['message'] = '任务类型type未定义';
            return $result;
        }
        if (!isset($data['rel_obj_id']) || empty($data['rel_obj_id'])) {
            $result['message'] = '任务rel_obj_id未定义';
            return $result;
        }

        $job_type = $data['type'];
        $rel_obj_id = $data['rel_obj_id'];
        switch ($job_type) {
            case OCS_QUEUE_TASK_TYPE_SCYN_ORDER_STANDARD: //同步订单
                $result = $this->_doJobSyncOrderToEbs($rel_obj_id, $job_type);
            break;
            case OCS_QUEUE_TASK_TYPE_SET_ORDER_STAGE: //下推订单阶段
                $result = $this->_doJobSetOrderStage($rel_obj_id, $job_type);
            break;
            case OCS_QUEUE_TASK_TYPE_BOOK_ORDER_STANDARD: //登记订单
                $result = $this->_doJobBookOrder($rel_obj_id, $job_type);
            break;
            case OCS_QUEUE_TASK_TYPE_CREATE_DELIVERY: //创建发货
                $result = $this->_doJobAutoCreateDeliverys($rel_obj_id, $job_type);
            break;
            case OCS_QUEUE_TASK_TYPE_SET_DELIVERY_STAGE: //下推发货
                $result = $this->_doJobAutoApprovalAction($rel_obj_id, $job_type);
            break;
            default:
                $result['message'] = '该任务类型未定义';
            break;
        }
        if (!$result['success']) {
            $order_header = $this->find('first', array(
                'conditions' => array(
                    'OrderHeader.id' => $rel_obj_id,
                ),
                'fields' => array(
                    'OrderHeader.id',
                    'OrderHeader.is_sync_error',
                )
            ));
            if (!empty($order_header)) {
                $order_header['OrderHeader']['is_sync_error'] = 1;
                $this->save($order_header['OrderHeader']);
            }
        }

        return $result;
    }

    /**
     * 任务队列回调方法：设置订单状态，当全部队列都已处理完成，则将状态设置为“审核完成”
     *
     * @Author   lishirong
     *
     * @DateTime 2016-11-10T09:03:38+0800
     *
     * @param    array                    $data [description]
     *
     * @return   [type]                         [description]
     */
    public function runQueueJobCallBack($data = array()) {
        global $g_Commons;
        $g_QueueTask = $g_Commons->GlobalModel('QueueTask');
        $result = $g_Commons->initResult();
        if (empty($data['rel_obj_id'])) {
            $result['message'] = '参数rel_obj_id为空';
            return $result;
        }
        $header_id = $data['rel_obj_id'];

        //检查是否全部队列已成功处理，如已处理则将状态改为“已审核”
        $queue_tasks = $g_QueueTask->getQueueTasks(OrderHeader, $header_id, true);
        if (empty($queue_tasks)) {
            $this->setOrderHeaderStatus($header_id, OCS_ORDER_HEADER_STATUS_DONE);
        }

        $result['success'] = true;
        return $result;
    }

    /**
     * 获取订单允许出货的备品比例数
     * 允许出免费备品比例数：EBS订单已创建发货通知数*备品比例-已创建备品数
     *
     * @Author   lishirong
     *
     * @DateTime 2016-11-16T14:33:26+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     *
     * @return   [type]                              [description]
     */
    function getAvailableDeliveryQty($header_id = null) {
        global $g_Commons;
        $g_OrderLine = $g_Commons->GlobalModel('OrderLine');
        $g_RelAccount = $g_Commons->GlobalModel('RelAccount');

        $order_lines = $this->find('all', array(
            'joins' => array(
                array(
                    'table' => 'order_lines',
                    'alias' => 'OrderLine',
                    'type' => 'inner',
                    'conditions' => 'OrderLine.header_id = OrderHeader.id',
                )
            ),
            'conditions' => array(
                'OrderHeader.id' => $header_id,
                'OrderHeader.status !=' => OCS_ORDER_HEADER_STATUS_CANCELLED,
                'OrderLine.status !=' => OCS_ORDER_LINE_STATUS_CANCELLED,
                'OrderLine.rel_ebs_order_number is not null',
            ),
            'fields' => array(
                'OrderHeader.id',
                'OrderHeader.ebs_order_number',
                'OrderHeader.account_id',
                'OrderLine.product_id',
                'OrderLine.quantity',
                'OrderLine.rel_ebs_order_number',
            )
        ));
        if (empty($order_lines)) {
            return 0;
        }
        $sum_qty = 0;
        $cache_data = array();
        $rel_order_data = array();
        foreach ($order_lines as $order_line) {
            $product_id = $order_line['OrderLine']['product_id'];
            $account_id = $order_line['OrderHeader']['account_id'];
            $rel_ebs_order_number = $order_line['OrderLine']['rel_ebs_order_number'];

            //EBS订单已创建发货通知数
            if (empty($rel_order_data[$rel_ebs_order_number])) {
                $rel_order_data[$rel_ebs_order_number]['notice_qty'] = $g_OrderLine->geNoticeQtyByOrderNumber($rel_ebs_order_number);
            }

            //备品比例
            if (isset($cache_data[$product_id . '_' . $account_id])) {
                $free_percent = $cache_data[$product_id . '_' . $account_id];
            } else {
                $free_percent = $g_RelAccount->getAccountFreePercentByProductIdAndAccount($product_id, $account_id);
                $cache_data[$product_id . '_' . $account_id] = $free_percent;
            }
            $free_percent = !empty($free_percent) ? $free_percent / 1000 : 0; //千分位比例
            if (empty($rel_order_data[$rel_ebs_order_number]['free_percent']) || $rel_order_data[$rel_ebs_order_number]['free_percent'] > $free_percent) { //取最小的
                $rel_order_data[$rel_ebs_order_number]['free_percent'] = $free_percent;
            }

            //已创建备品数
            if (isset($cache_data[$product_id . '_' . $rel_ebs_order_number])) {
                $created_qty = $cache_data[$product_id . '_' . $rel_ebs_order_number];
            } else {
                $created_qty = $g_OrderLine->getQtyByRelOrderNumberAndProductId($rel_ebs_order_number, $product_id, $header_id);
                $cache_data[$product_id . '_' . $rel_ebs_order_number] = $created_qty;

                if (!isset($rel_order_data[$rel_ebs_order_number]['created_qty'])) {
                    $rel_order_data[$rel_ebs_order_number]['created_qty'] = $created_qty;
                } else {
                    $rel_order_data[$rel_ebs_order_number]['created_qty'] += $created_qty;
                }
            }
        }
        if (empty($rel_order_data)) {
            return 0;
        }

        //允许出免费备品比例数：EBS订单已创建发货通知数*备品比例-已创建备品数
        foreach ($rel_order_data as $tmp) {
            $sum_qty += ($tmp['notice_qty'] * $tmp['free_percent'] - $tmp['created_qty']);
        }

        //根据规则对结果数字进行取舍
        $model = new OrderFactory();
        $sum_qty = $model->formatSpareQty($sum_qty);

        return $sum_qty;
    }

    /**
     * 判断订单是否需要审核
     * 如不需要审核，则自动执行一系列操作
     *
     * @Author   lishirong
     *
     * @DateTime 2016-11-16T15:15:10+0800
     *
     * @param    [type]                   $header_id [订单头ID]
     *
     * @return   boolean                             [description]
     */
    function isNeedAudit($header_id = null) {
        $type = $this->get_by_id($header_id, 'type');
        $model = new OrderContext($type);

        return $model->isNeedAudit($header_id);
    }

    /**
     * 特殊订单审核通过
     *
     * @Author   lishirong
     *
     * @DateTime 2016-11-21T20:49:45+0800
     *
     * @param    [type]                   $header_id [description]
     *
     * @return   [type]                              [description]
     */
    function dualAuditPassBill($header_id = null) {
        $type = $this->get_by_id($header_id, 'type');
        $model = new OrderContext($type);

        return $model->dualAuditPassBill($header_id);
    }

    /**
     * 特殊订单审核不通过处理
     *
     * @Author   lishirong
     *
     * @DateTime 2016-11-21T20:49:45+0800
     *
     * @param    [type]                   $header_id [description]
     *
     * @return   [type]                              [description]
     */
    function dualAuditFailBill($header_id = null) {
        $type = $this->get_by_id($header_id, 'type');
        $model = new OrderContext($type);

        return $model->dualAuditFailBill($header_id);
    }

    /**
     * 从订单行数据中,获取地址ID信息
     *
     * @Author   zhangguocai
     *
     * @DateTime 2017-02-22T09:17:34+0800
     *
     * @param    array                    &$datas [description]
     *
     * @return   [type]                                       [description]
     */
    function getAllLocationsFromDatas(&$datas = array()) {
        global $g_Commons;
        $g_BatchDelivery = $g_Commons->GlobalModel('BatchDelivery');

        $result = $g_BatchDelivery->getAllLocationsFromDatas($datas);
        $result['config'] = array_change_key_case($result['config'], CASE_LOWER);
        foreach ($result['config'] as $ebs_field => $all_location_config) {
            $result['config'][$ebs_field]['rel_field'] = strtolower($all_location_config['rel_field']);
        }

        $location_code_levels = array();
        if (!empty($datas)) {
            foreach ($datas as $data) {

                // 获取当前订单行,如果只有一个地址,则默认帮用户选中
                $line_data = !empty($data['OrderLine']) ? $data['OrderLine'] : array();
                $all_addresses = !empty($line_data['shipping_site_use_id']['option_datas']) ? $line_data['shipping_site_use_id']['option_datas'] : array();
                $address_one = (1 === count($all_addresses)) ? current($all_addresses) : array();

                foreach ($result['config'] as $location_key => $location_config) {

                    // 获取当前客户收货地址的值
                    $rel_field = !empty($location_config['rel_field']) ? strtolower($location_config['rel_field']) : null;
                    $default_value = !empty($location_config['value']) ? $location_config['value'] : null;//当前级别的默认值
                    $level = isset($location_config['level']) ? $location_config['level'] : null;
                    $line_location_value = !empty($address_one[$rel_field]) ? $address_one[$rel_field] : $default_value;

                    // 如果行上的该级地址为空,那么获取客户关联的地址中的第一项地址信息
                    if (!empty($rel_field) && empty($line_data[$rel_field])) {
                        $line_data[$rel_field] = $line_location_value;
                    }
                    // 如果当前行的地址层字段值为空,那么使用该地质层的默认值
                    if (empty($line_data[$rel_field]) && !empty($location_config['value'])) {
                        $line_data[$rel_field] = $location_config['value'];
                    }
                    $this_location_code = !empty($line_data[$rel_field]) ? $line_data[$rel_field] : 0;

                    // 记录所有用到的地址编码(预加载)
                    $location_code_levels[$default_value] = $level;
                    $location_code_levels[$this_location_code] = $level;
                    foreach ($all_addresses as $address_data) {
                        if (empty($address_data[$rel_field])) {
                            continue;
                        }
                        $addres_location_code = $address_data[$rel_field];//当前地址编码
                        $location_code_levels[$addres_location_code] = $level;
                    }
                }
            }
        }

        // 获取所有指定地址编码作为父节点的全部地址
        $result['locations'] = $g_BatchDelivery->getFormatLocationDatas($location_code_levels);

        return $result;
    }

    /**
     * 获取导入订单模板定义列字段
     *
     * 警告：字段的顺序不能随便调整，须与EXCEL的模板列字段顺序一致！
     *
     * @Author   lishirong
     *
     * @DateTime 2017-05-09T11:00:31+0800
     *
     * @param    [type]                   $req_type [description]
     *
     * @return   [type]                             [description]
     */
    function getImportTmplDefCols() {
        global $g_BizId;

        $result = array();
        switch ($g_BizId) {
            default:
                $result = array(
                    'OrderLine.product_id' => '产品名称',
                    'OrderLine.quantity' => '数量',
                    'OrderLine.account_cno' => '客户单号',
                    'OrderLine.account_mno' => '客户料号',
                    'OrderLine.account_brand' => '客户品牌',
                );
            break;
        }

        return $result;
    }

    /**
     * 解析导入excel模板数据
     *
     * @Author   lishirong
     *
     * @DateTime 2017-05-09T10:57:36+0800
     *
     * @param    [type]                   $file_name [description]
     *
     * @return   [type]                              [description]
     */
    function parseOrderTmplDatas($file_name = null) {
        global $g_Commons;
        global $g_BizId;
        $g_Product = $g_Commons->GlobalModel('Product');
        $g_Enum = $g_Commons->GlobalModel('Enum');
        $g_Dic = $g_Commons->GlobalModel('Dic');
        $g_AttrDic = $g_Commons->GlobalModel('AttrDic');
        $g_ProdAttr = $g_Commons->GlobalModel('ProdAttr');
        $g_RelProduct = $g_Commons->GlobalModel('RelProduct');
        $g_Atom = $g_Commons->GlobalModel('Atom');
        $result = $g_Commons->initResult();

        try {
            if (empty($file_name)) {
                throw new Exception('参数错误，file_name参数为空', 1);
            } elseif (!file_exists($file_name)) {
                throw new Exception('请先上传模板', 1);
            }

            App::import('Vendor', 'PHPExcel/PHPExcel/IOFactory');
            App::import('Vendor', 'PHPExcel/PHPExcel');
            $extension = pathinfo($file_name, PATHINFO_EXTENSION);
            switch ($extension) {
                case 'xls':
                    $objReader = PHPExcel_IOFactory::createReader('Excel5');
                break;
                case 'xlsx':
                    $objReader = PHPExcel_IOFactory::createReader('Excel2007');
                break;
                default:
                    throw new Exception('文件类型不支持#' . $extension, 1);
                break;
            }
            $objPHPExcel = $objReader->load($file_name); //载入文件
            $objWorksheet = $objPHPExcel->getActiveSheet(); //获取当前工作簿
            $max_row = $objWorksheet->getHighestRow(); //获取最大行数

            //开始行号
            $row = 2;

            //获取模板列字段定义
            $tmpl_cols = $this->getImportTmplDefCols();
            $arr_types = array(OCS_ATTR_INPUT_TYPE_REF, OCS_ATTR_INPUT_TYPE_BOOL, OCS_ATTR_INPUT_TYPE_REL_OBJ_TYPE); //这些类型的 需用数组形式
            $error_msgs = array();

            $col_cache_datas = array(); //缓存相关查询数据，循环时不需再查
            for ($row; $row <= $max_row; $row++) {
                $datas = array();
                $col = 0; //开始列
                $sw_atom_ids_json = array();
                foreach ($tmpl_cols as $attr_key => $label) {
                    list($model, $field) = explode('.', $attr_key);
                    $columnLetter = PHPExcel_Cell::stringFromColumnIndex($col++);
                    $coordinate = $columnLetter . $row;

                    //获取单元格值
                    $value = $objWorksheet->getCell($coordinate)->getValue();
                    $value = trim($value);

                    if (isset($col_cache_datas[$attr_key]['input_type'])) {
                        $input_type = $col_cache_datas[$attr_key]['input_type'];
                    } else {
                        $input_type = $g_AttrDic->getInputTypeByName($attr_key); //获取程序字典类型
                    }
                    $col_cache_datas[$attr_key]['input_type'] = $input_type;

                    $datas[$model][$field] = $value;
                    if (in_array($input_type, $arr_types) || in_array($attr_key, array('Contract.line_type_id'))) {
                        $datas[$model][$field] = array(
                            'value' => 0,
                            'label' => $value,
                        );

                        //针对不同字段作不同处理
                        $is_bool = false;
                        switch ($attr_key) {
                            case 'OrderLine.product_id':
                                if (isset($col_cache_datas[$attr_key]['product'][$value])) {
                                    $product = $col_cache_datas[$attr_key]['product'][$value];
                                } else {
                                    $product = $g_Product->find('first', array(
                                        'conditions' => array(
                                            'OR' => array(
                                                'Product.code' => $value,
                                                'Product.name' => $value,
                                            )
                                        ),
                                        'fields' => array(
                                            'Product.id',
                                            'Product.name',
                                        )
                                    ));
                                    if (empty($product)) {
                                        $g_Product->autoGenerateMProduct($value);
                                    }
                                    $id = $g_Product->getProductIdByName($value);
                                    if (empty($id)) { //产品名称查找不到 再通过产品代码来找
                                        $product = $g_Product->find('first', array(
                                            'conditions' => array(
                                                'Product.code' => $value,
                                            ),
                                            'fields' => array(
                                                'Product.id',
                                                'Product.name',
                                            )
                                        ));
                                        $col_cache_datas[$attr_key]['product'][$value] = $product;
                                    }
                                }

                                if (!empty($product)) {
                                    $id = $product['Product']['id'];
                                    $datas[$model][$field]['label'] = $product['Product']['name'];
                                }
                            break;
                        }
                        if (!isset($id) || (empty($id) && !$is_bool)) {
                            // throw new Exception('模板值与系统值匹配失败，请修改后再提交，单元格：' . $coordinate . '，值' . $attr_key . '：' . $value, 1);
                            $error_msgs[] = $coordinate . '：' . '模板值与系统值匹配失败，请修改后再提交，单元格：' . $coordinate . '，值' . $attr_key . '：' . $value;
                        }
                        $datas[$model][$field]['value'] = $id;
                    }
                }
                $result['datas'][$row] = $datas;
            }
            if (empty($result['datas'])) {
                $error_msgs[] = 'excel订单数据为空，请埴写订单信息再上传提交';
            }

            if (!empty($error_msgs)) {
                $result['message'] = $error_msgs;
            } else {
                $result['success'] = true;
            }

            return $result;
        } catch (Exception $e) {
            $result['message'] = $e->getMessage();
            return $result;
        }
    }
}