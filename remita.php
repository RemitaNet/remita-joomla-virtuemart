<?php

/**
 * @package       VM Payment - Remita
 * @author        SystemSpecs
 * @copyright     Copyright (C) 2019 SystemSpecs Ltd. All rights reserved.
 * @version       1.0.5, February 2019
 * @license       GNU General Public License version 2 or later; see LICENSE
 */

defined('_JEXEC') or die('Direct access to ' . basename(__FILE__) . ' is not allowed.');


if (!class_exists('vmPSPlugin')){
    require(JPATH_VM_PLUGINS . DIRECTORY_SEPARATOR . 'vmpsplugin.php');
}


class plgVmPaymentRemita extends vmPSPlugin{

    const COST_PER_TRANSACTION = 'cost_per_transaction';
    const COST_PERCENT_TOTAL = 'cost_percent_total';
    const TAX_ID = 'tax_id';

        function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable  = true;
        $this->_tablepkey = 'id';
        $this->_tableId   = 'id';

        $this->tableFields = array_keys($this->getTableSQLFields());

        $varsToPush = array(
            'test_mode' => array(
                1,
                'int'
            ), // remita.xml (test_mode)
            'remita_secret_key' => array(
                '',
                'char'
            ), // remita.xml (remita_secret_key)
            'remita_public_key' => array(
                '',
                'char'
            ), // remita.xml (remita_public_key)
            'status_pending' => array(
                '',
                'char'
            ),
            'status_success' => array(
                '',
                'char'
            ),
            'status_canceled' => array(
                '',
                'char'
            ),

            'min_amount' => array(
                0,
                'int'
            ),
            'max_amount' => array(
                0,
                'int'
            ),
            COST_PER_TRANSACTION => array(
                0,
                'int'
            ),
            'COST_PERCENT_TOTAL' => array(
                0,
                'int'
            ),
            'TAX_ID' => array(
                0,
                'int'
            )
        );

        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Remita Table');
    }

    function getTableSQLFields()
    {
        $SQLfields = array(
            'id'                     => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'    => 'int(1) UNSIGNED',
            'order_number'           => ' char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name'           => 'varchar(5000)',
            'payment_order_total'    => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency'       => 'char(3) ',
            'COST_PER_TRANSACTION'   => 'decimal(10,2)',
            'COST_PERCENT_TOTAL'     => 'decimal(10,2)',
            'TAX_ID'                 => 'smallint(1)',
            'remita_transaction_reference' => 'char(32) DEFAULT NULL'
        );

        return $SQLfields;
    }

    function getRemitaUrl($payment_method_id)
    {
        $remita_settings = $this->getPluginMethod($payment_method_id);

        if ($remita_settings->test_mode) {
            $remita_url = "https://remitademo.net/payment/v1/remita-pay-inline.bundle.js";
            $query_url = 'https://remitademo.net/payment/v1/payment/query/';

        } else {
            $remita_url = "https://login.remita.net/payment/v1/remita-pay-inline.bundle.js";
            $query_url = 'https://login.remita.net/payment/v1/payment/query/';

        }

        $remitaUrl = str_replace(' ', '', $remita_url);
        $queryUrl = str_replace(' ', '', $query_url);

        return array(
            'remita_url' => $remitaUrl,
            'query_url' => $queryUrl
        );

    }

    function verifyRemitaTransaction($transactionId, $payment_method_id)
    {

        $remita_settings = $this->getPluginMethod($payment_method_id);
        $url = $this->getRemitaUrl($payment_method_id);
        $query_url = $url['query_url'];

        $transactionStatus        = new stdClass();
        $transactionStatus->error = "";

        $secretKey = $remita_settings->remita_secret_key;
        $publickey = $remita_settings->remita_public_key;

        $txnHash = hash('sha512', $transactionId . $secretKey);

        $header = array(
            'Content-Type: application/json',
            'publicKey:' . $publickey,
            'TXN_HASH:' . $txnHash
        );

        $url 	= $query_url . $transactionId;

        //  Initiate curl
        $ch = curl_init();

        // Disable SSL verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // Will return the response, if false it print the response
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);


        // Set the url
        curl_setopt($ch, CURLOPT_URL, $url);

        // Set the header
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);


        // Execute
        $result = curl_exec($ch);

        // Closing
        curl_close($ch);

        // decode json
        return json_decode($result, true);
    }

    function plgVmConfirmedOrder($cart, $order)
    {
        $details = 'details';
        $order_details = $order[$details]['BT'];

        if (!($method = $this->getVmPluginMethod($order_details->virtuemart_paymentmethod_id))) {
            return null;
        }

        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $session = JFactory::getSession ();
        $return_context = $session->getId ();
        $this->logInfo ('plgVmConfirmedOrder order number: ' . $order_details->order_number, 'message');

        if (!class_exists('VirtueMartModelOrders')){
            require(JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'orders.php');
        }


        if (!class_exists('VirtueMartModelCurrency')){
            require(JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'currency.php');
        }


        // Get current order info
        $order_info   = $order_details;
        $address = ((isset($order[$details]['ST'])) ? $order[$details]['ST'] : $order_details);
        $orderId = $order_info->virtuemart_order_id;
        $uniqueId = uniqid();
        $trxref = $uniqueId. '_' .$orderId;

        // Get total amount for the current payment currency
        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order_details->order_total, $method->payment_currency);

        // Prepare data that should be stored in the database
        $dbValues['user_session'] = $return_context;
        $dbValues['order_number'] = $order_details->order_number;
        $dbValues['payment_name'] = $this->renderPluginName ($method, $order);
        $dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
        $dbValues['cost_per_transaction'] = $method->COST_PER_TRANSACTION;
        $dbValues['cost_percent_total'] = $method->COST_PERCENT_TOTAL;
        $dbValues['payment_currency'] = $method->payment_currency;
        $dbValues['payment_order_total'] = $totalInPaymentCurrency['value'];
        $dbValues['tax_id'] = $method->TAX_ID;
        $dbValues['remita_transaction_reference'] = $uniqueId. '_' .$dbValues['order_number'];

        $this->storePSPluginInternalData ($dbValues);

        // Return URL - Verify Remita payment
        $return_url = JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order_details->order_number . '&pm=' . $order_details->virtuemart_paymentmethod_id . '&Itemid=' . vRequest::getInt('Itemid') . '&lang=' . vRequest::getCmd('lang', '');

        // Remita Settings
        $payment_method_id = $dbValues['virtuemart_paymentmethod_id'];//vRequest::getInt('virtuemart_paymentmethod_id');
        $remita_settings = $this->getPluginMethod($payment_method_id);
        $url = $this->getRemitaUrl($payment_method_id);

        // Remita Gateway HTML code
        $html = '
        <p>Your order is being processed. Please wait...</p>
        <form id="remita-pay-form" action="' . $return_url . '" method="post">
          <script src="' . $url['remita_url'] . '"></script>
          <input type="hidden" value="' . $payment_method_id . '" name="payment_method_id" />
          <input type="hidden" value="' . $dbValues['remita_transaction_reference'] . '" name="transactionId" />
        </form>

        <script >
        
                var amt ="'. $totalInPaymentCurrency['value'] .'";
                var secret_key ="'. $remita_settings->remita_secret_key .'";
                var key ="'. $remita_settings->remita_public_key .'";
                var email ="'. $order_info->email .'";
                var firstname ="'. $address->first_name .'";
                var lastname ="'. $trxref .'";
                var transaction_Id ="'. $dbValues['remita_transaction_reference'] .'";

                var paymentEngine = RmPaymentEngine.init({
                    key: key,
                    customerId: email,
                    firstName: firstname,
                    lastName: lastname,
                    transactionId: transaction_Id,
                    narration: "bill pay",
                    email: email,
                    amount: amt,
                    onSuccess: function (response) {
                        document.getElementById(\'remita-pay-form\').submit();
                        console.log(\'callback Successful Response\', response);
                    },
                    onError: function (response) {
                        console.log(\'callback Error Response\', response);
                    },
                    onClose: function () {
                        console.log("closed");
                    }
                });
            
                paymentEngine.showPaymentWidget();

        </script>';

        $cart->_confirmDone   = FALSE;
        $cart->_dataValidated = FALSE;
        $cart->setCartIntoSession();

        vRequest::setVar('html', $html);
    }

    function plgVmOnPaymentResponseReceived(&$html)
    {
        if (!class_exists('VirtueMartCart')) {
            require(VMPATH_SITE . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'cart.php');
        }
        if (!class_exists('shopFunctionsF')) {
            require(VMPATH_SITE . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'shopfunctionsf.php');
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'orders.php');
        }

        VmConfig::loadJLang('com_virtuemart_orders', TRUE);
        $post_data = vRequest::getPost();

        // The payment itself should send the parameter needed.
        $virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
        $order_number                = vRequest::getString('on', 0);
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL;
        }

        if (!$this->selectedThisElement($method->payment_element)) {
            return NULL;
        }

        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
            return NULL;
        }

        VmConfig::loadJLang('com_virtuemart');
        $orderModel = VmModel::getModel('orders');
        $order      = $orderModel->getOrder($virtuemart_order_id);
        $details = 'details';
        $order_details = $order[$details]['BT'];


        $payment_name = $this->renderPluginName($method);
        $html = '<table>' . "\n";
        $html .= $this->getHtmlRow('Payment Name', $payment_name);
        $html .= $this->getHtmlRow('Order Number', $order_number);

        $response = $this->verifyRemitaTransaction($post_data['transactionId'], $post_data['payment_method_id']);

        $response_code = $response['responseCode'];
        $response_msg = $response['responseMsg'];
        $paymentState = $response['responseData']['0']['paymentState'];
        $payment_amount = $response['responseData']['0']['amount'];
        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order_details->order_total, $method->payment_currency);
        $order_status = 'order_status';
        $customer_notified = 'customer_notified';
        $totalAmount = 'Total Amount';
        $status = 'Status';

        if (($paymentState === 'SUCCESSFUL') && ($payment_amount == $totalInPaymentCurrency['value'])) {
            // Update order status - From pending to complete

            $order[$order_status]      = 'C';
            $order[$customer_notified] = 1;
            $orderModel->updateStatusForOneOrder($order_details->virtuemart_order_id, $order, TRUE);

            $html .= $this->getHtmlRow($totalAmount, number_format($payment_amount, 2));
            $html .= $this->getHtmlRow($status, $paymentState);
            $html .= '</table>' . "\n";
            // add order url
            $html.='<a href="'.JRoute::_('index.php?option=com_virtuemart&view=orders&layout=details&order_number='.$order_number,FALSE).'" class="vm-button-correct">'.vmText::_('COM_VIRTUEMART_ORDER_VIEW_ORDER').'</a>';

            // Empty cart
            $cart = VirtueMartCart::getCart();
            $cart->emptyCart();

            return True;
        } else if ($response_code == "34") {

            $html .= $this->getHtmlRow($totalAmount, number_format($payment_amount, 2));
            $html .= $this->getHtmlRow($status, strtoupper($response_msg));
            $html .= '</table>' . "\n";
            $html.='<a href="'.JRoute::_('index.php?option=com_virtuemart&view=cart',false).'" class="vm-button-correct">'.vmText::_('CART_PAGE').'</a>';

            // Update order status - From pending to canceled
            $order[$order_status]      = 'X';
            $order[$customer_notified] = 1;
            $orderModel->updateStatusForOneOrder($order_details->virtuemart_order_id, $order, TRUE);
        } else {

            $html .= $this->getHtmlRow($totalAmount, number_format($payment_amount, 2));
            $html .= $this->getHtmlRow($status, strtoupper($response_msg));
            $html .= '</table>' . "\n";
            $html.='<a href="'.JRoute::_('index.php?option=com_virtuemart&view=cart',false).'" class="vm-button-correct">'.vmText::_('CART_PAGE').'</a>';

            // Update order status - From pending to canceled
            $order[$order_status]      = 'X';
            $order[$customer_notified] = 1;
            $orderModel->updateStatusForOneOrder($order_details>virtuemart_order_id, $order, TRUE);
        }

        return False;
    }

    function plgVmOnUserPaymentCancel()
    {
        return true;
    }

    /**
     * Required functions by Joomla or VirtueMart. Removed code comments due to 'file length'.
     * All copyrights are (c) respective year of author or copyright holder, and/or the author.
     */
    function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        if (preg_match('/%$/', $method->COST_PERCENT_TOTAL)) {
            $cost_percent_tot = substr($method->COST_PERCENT_TOTAL, 0, -1);
        } else {
            $cost_percent_tot = $method->COST_PERCENT_TOTAL;
        }
        return ($method->COST_PER_TRANSACTION + ($cart_prices['salesPrice'] * $cost_percent_tot * 0.01));
    }

    protected function checkConditions($cart, $method, $cart_prices)
    {
        $this->convert_condition_amount($method);
        $address     = (($cart->ST == 0) ? $cart->BT : $cart->ST);
        $amount      = $this->getCartAmount($cart_prices);
        $amount_cond = ($amount >= $method->min_amount && $amount <= $method->max_amount || ($method->min_amount <= $amount && ($method->max_amount == 0)));
        $countries   = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }
        if (!is_array($address)) {
            $address                          = array();
            $address['virtuemart_country_id'] = 0;
        }
        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if ((in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) && $amount_cond) {
                return TRUE;
        }
        return FALSE;
    }

    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
        return $this->OnSelectCheck($cart);
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }


}