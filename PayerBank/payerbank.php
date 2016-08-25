<?php

defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentPayerbank extends vmPSPlugin {

	function __construct(& $subject, $config) {
		parent::__construct($subject, $config);
		$this->_loggable = true;
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$this->tableFields = array_keys($this->getTableSQLFields());
		$varsToPush = $this->getVarsToPush();
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

	public function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Payment Payer All Table');
	}

	function getTableSQLFields() {
		$SQLfields = array(
			'id' => 'INT(11) unsigned NOT NULL AUTO_INCREMENT',
			'agentid' => 'varchar(25) DEFAULT \'*\'',
			'key1' => 'varchar(255) DEFAULT \'*\'',
			'key2' => 'varchar(255) DEFAULT \'*\'',
			'testmode' => 'enum(\'0\',\'1\') DEFAULT \'1\'',
			'virtuemart_order_id' => 'int(1) UNSIGNED',
			'order_number' => 'char(64)',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED'
		);

		return $SQLfields;
	}

	function plgVmConfirmedOrder($cart, $order) {
		if (!class_exists('payread_post_api')) {
			require_once("plugins/vmpayment/payerbank/payer/payread_post_api.php");
		}
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return null;
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		$lang = JFactory::getLanguage();
		$filename = 'com_virtuemart';
		$lang->load($filename, JPATH_ADMINISTRATOR);
		$vendorId = 0;
		$html = "";
		if (!class_exists('VirtueMartModelOrders'))
			require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		$this->getPaymentCurrency($method, true);

		// END printing out HTML Form code (Payment Extra Info)
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
		$db = &JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
		$totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);
		$cd = CurrencyDisplay::getInstance($cart->pricesCurrency);

		$this->_virtuemart_paymentmethod_id = $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['payment_name'] = $this->renderPluginName($method) . '<br />' . $method->payment_info;
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
		$dbValues['cost_per_transaction'] = $method->cost_per_transaction;
		$dbValues['cost_percent_total'] = $method->cost_percent_total;
		$dbValues['payment_currency'] = $currency_code_3;
		$dbValues['payment_order_total'] = $totalInPaymentCurrency;
		$dbValues['tax_id'] = $method->tax_id;
		$this->storePSPluginInternalData($dbValues);

		$payer = new payread_post_api();
		$payer->setAgentId($method->agentid);
		$payer->setKeys($method->key1, $method->key2);
		$Success_url = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);
		$Auth_url = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);
		$Settle_url = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);
		$Shop_url = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);
		
		$payer->set_success_redirect_url($Success_url);
		$payer->set_authorize_notification_url($Auth_url);
		$payer->set_settle_notification_url($Settle_url);
		$payer->set_redirect_back_to_shop_url($Shop_url);

		$payer->add_buyer_info($order['details']['BT']->first_name, $order['details']['BT']->last_name, $order['details']['BT']->address_1, $order['details']['BT']->address_2, $order['details']['BT']->zip, $order['details']['BT']->city, $order['details']['BT']->country, //MISSING
				$order['details']['BT']->phone_1, $order['details']['BT']->phone_2, $order['details']['BT']->phone_1, $order['details']['BT']->email);

		$lineNumber = 0;
		$taxAvg = 0;
		foreach ($cart->products as $productid => $product) {
			$tax = (($cart->pricesUnformatted[$productid]['subtotal_with_tax'] / $cart->pricesUnformatted[$productid]['priceBeforeTax'] - 1) * 100);
			if ($tax != 25 || $tax != 12 || $tax != 6 || $tax != 0) {
				$tax = 25;
			}
			$taxAvg += $tax;
			$payer->add_freeform_purchase(
					$lineNumber, 
					$product->product_name, 
					($cart->pricesUnformatted[$productid]['subtotal_with_tax'] / $product->quantity), 
					$tax, 
					$product->quantity
			);
			$lineNumber++;
		}
		$taxAvg = $taxAvg/count($cart->products);
		if ($cart->pricesUnformatted['salesPriceShipment']) {
			preg_match("/<span(.*)\">(.*)<\/span>/", $cart->cartData['shipmentName'], $shipmentName);
			$payer->add_freeform_purchase($lineNumber, $shipmentName[2], $cart->pricesUnformatted['salesPriceShipment'], $taxAvg, '1');
			$lineNumber++;
		}
		if (0 != $order['details']['BT']->coupon_discount) {
			$payer->add_freeform_purchase($lineNumber, $order['details']['BT']->coupon_code, $order['details']['BT']->coupon_discount, $taxAvg, '1');
			$lineNumber++;
		}
		$payer->add_payment_method("bank");
		$payer->set_language($_POST['language']);
		if (!class_exists('VirtueMartModelCurrency')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		}
		$currencyModel = new VirtueMartModelCurrency();
		$currencyObj = $currencyModel->getCurrency($order['details']['BT']->order_currency);
		$payer->set_currency($currencyObj->currency_code_3);
		$payer->set_reference_id($order['details']['BT']->order_number);
		if ($method->testmode == 0) {
			$payer->set_test_mode(false);
			$payer->set_debug_mode('silent');
		} else {
			$payer->set_test_mode(true);
			$payer->set_debug_mode('verbose');
		}

		echo '<script type="text/javascript">
        function sendform(){
            var frm = document.getElementById("order_form");
            frm.submit();
            }
            window.onload = sendform;
        </script>
        <form id="order_form" name="order_form" action="' . $payer->get_server_url() . '" method="post">
            ' . $payer->generate_form_str() . '
            <input type="submit" value="Click here to pay" />
        </form>';
	}

	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) {
		if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
			return null;
		}

		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
				. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
			vmWarn(500, $q . " " . $db->getErrorMsg());
			return '';
		}

		$html = '<table class="adminlist">' . "\n";
		$html .=$this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_NAME', $paymentTable->payment_name);
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
		$html .= '</table>' . "\n";
		return $html;
	}

	function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
		if (preg_match('/%$/', $method->cost_percent_total)) {
			$cost_percent_total = substr($method->cost_percent_total, 0, -1);
		} else {
			$cost_percent_total = $method->cost_percent_total;
		}
		return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
	}

	protected function checkConditions($cart, $method, $cart_prices) {
		$this->convert($method);
		// 		$params = new JParameter($payment->payment_params);
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		$amount = $cart_prices['salesPrice'];
		$amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
				OR ( $method->min_amount <= $amount AND ( $method->max_amount == 0)));
		if (!$amount_cond) {
			return false;
		}
		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array($method->countries)) {
				$countries[0] = $method->countries;
			} else {
				$countries = $method->countries;
			}
		}

		// probably did not gave his BT:ST address
		if (!is_array($address)) {
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id'])) {
			$address['virtuemart_country_id'] = 0;
		}
		if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
			return true;
		}

		return false;
	}

	function convert($method) {

		$method->min_amount = (float) $method->min_amount;
		$method->max_amount = (float) $method->max_amount;
	}

	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
		return $this->onStoreInstallPluginTable($jplugin_id);
	}

	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
		return $this->OnSelectCheck($cart);
	}

	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		return $this->displayListFE($cart, $selected, $htmlIn);
	}

	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}

	function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null;
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		$this->getPaymentCurrency($method);

		$paymentCurrencyId = $method->payment_currency;
	}

	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {
		return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
	}

	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint($order_number, $method_id);
	}

	function plgVmDeclarePluginParamsPaymentVM3(&$data) {
		return $this->declarePluginParams('payment', $data);
	}

	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
	}

	public function plgVmOnUpdateOrderPayment($_formData) {
		return null;
	}

	public function plgVmOnUpdateOrderLine($_formData) {
		return null;
	}

	public function plgVmInterpreteMathOp($calculationHelper, $rule, $price, $revert) {
		$rule = (object) $rule;
		$mathop = $rule->calc_value_mathop;
		$tax = 0.0;
		if ($mathop == 'avalara') {
			$requestedProductId = JRequest::getInt('virtuemart_product_id', 0);
			if (isset($calculationHelper->_product)) {
				$productId = $calculationHelper->_product->virtuemart_product_id;
			} else {
				$productId = $requestedProductId;
			}
			if (($productId != 0 and $productId == $requestedProductId) or $calculationHelper->inCart) {
				VmTable::bindParameterable($rule, $this->_xParams, $this->_varsToPushParam);
				if ($rule->activated == 0)
					return $price;
				if (empty($this->addresses)) {
					$this->addresses = $this->fillValidateAvalaraAddress($rule);
				}
				if ($this->addresses) {
					$tax = $this->getTax($calculationHelper, $rule, $price);
				}
			}
		}
		if ($revert) {
			$tax = -$tax;
		}
		return $price + (float) $tax;
	}

	public function plgVmOnEditOrderLineBEPayment($_orderId, $_lineId) {
		return null;
	}

	public function plgVmOnShowOrderLineFE($_orderId, $_lineId) {
		return null;
	}
	
	protected function renderPluginName($method, $where = 'checkout') {
		$payment_name = $method->payment_name;
		$html = $this->renderByLayout('render_pluginname', array(
			'where' => $where,
			'payment_name' => $payment_name,
			'payment_description' => $method->payment_desc,
		));

		return $html;
	}
	
	function plgVmOnPaymentNotification() {
		if (!($method = $this->getVmPluginMethod($_GET['pm']))) {
			return null;
		}
		if (!class_exists('payread_post_api')) {
			require_once("plugins/vmpayment/payerbank/payer/payread_post_api.php");
		}
		$payer = new payread_post_api();
		$payer->setAgentId($method->agentid);
		$payer->setKeys($method->key1, $method->key2);
		if ($payer->is_valid_ip()) {
			if ($payer->is_valid_callback()) {
				if ($_GET['payer_callback_type'] == 'settle') {
					if (!class_exists('VirtueMartModelOrders')) {
						require_once(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
					}
					$order_number = JRequest::getVar('on');
					$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
					if (!$virtuemart_order_id) {
						return false;
					}
					$vendorId = 0;
					$payment = $this->getDataByOrderId($virtuemart_order_id);
					$modelOrder = VmModel::getModel('orders');
					$order = array();
					$order['order_status'] = 'C';
					$order['customer_notified'] = 1;
					$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, false);
				}
				die("TRUE");
			}
			die("FALSE - CALLBACK");
		}
		die("FALSE - IP " . $_SERVER['REMOTE_ADDR']);
	}

	function plgVmOnUserPaymentCancel() {
		if (!class_exists('VirtueMartModelOrders')) {
			require_once(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		}

		$order_number = JRequest::getVar('on');
		if (!$order_number)
			return false;
		$db = JFactory::getDBO();
		$query = "SELECT #__virtuemart_orders.`virtuemart_order_id` FROM #__virtuemart_orders WHERE `order_number`= '" . $order_number . "'";

		$db->setQuery($query);
		$virtuemart_order_id = $db->loadResult();

		if (!$virtuemart_order_id) {
			return null;
		}
		$this->handlePaymentUserCancel($virtuemart_order_id);

		//JRequest::setVar('paymentResponse', $returnValue);
		return true;
	}

	function plgVmOnPaymentResponseReceived(&$html) {
		if (!class_exists('VirtueMartCart')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		}
		if (!class_exists('shopFunctionsF')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		}
		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		}
		$cart = VirtueMartCart::getCart();
		$cart->emptyCart();
	}

}
