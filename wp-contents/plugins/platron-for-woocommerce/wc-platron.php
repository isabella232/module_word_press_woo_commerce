<?php 

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once('PG_Signature.php');
require_once('ofd.php');

/**
  Plugin Name: Platron Payment Gateway
  Plugin URI: http://patron.ru/integration/woocommerce
  Description: Provides a Platron Payment Gateway.
  Version: 1.0.0
  Author: Platron
 */


/* Add a custom payment class to WC
  ------------------------------------------------------------ */
add_action('plugins_loaded', 'woocommerce_platron', 0);
function woocommerce_platron(){
	if (!class_exists('WC_Payment_Gateway'))
		return; // if the WC payment gateway class is not available, do nothing
	if(class_exists('WC_Platron'))
		return;
	
class WC_Platron extends WC_Payment_Gateway{
	const ERROR_TYPE = 'platron';

	public function __construct(){
		
		$plugin_dir = plugin_dir_url(__FILE__);

		global $woocommerce;

		$this->id = 'platron';
		$this->icon = apply_filters('woocommerce_platron_icon', ''.$plugin_dir.'platron.png');
		$this->has_fields = false;

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->title = $this->get_option('title');
		$this->merchant_id = $this->get_option('merchant_id');
		$this->secret_key = $this->get_option('secret_key');
		$this->lifetime = $this->get_option('lifetime');
		$this->testmode = $this->get_option('testmode');

		$this->send_receipt = 'yes' === $this->get_option('ofd_send_receipt', 'no');
		$this->tax_type = $this->get_option('ofd_tax_type', 'none');

		$this->description = $this->get_option('description');
		$this->instructions = $this->get_option('instructions');

		// Actions
		//add_action('woocommerce_receipt_platron', array($this, 'receipt_page'));

		// Save options
		add_action( 'woocommerce_update_options_payment_gateways_platron', array( $this, 'process_admin_options' ) );

		// Payment listener/API hook
		add_action('woocommerce_api_wc_platron', array($this, 'check_assistant_response'));

		if (!$this->is_valid_for_use()){
			$this->enabled = false;
		}
	}

	/**
	 * Check if this gateway is enabled and available in the user's country
	 */
	function is_valid_for_use(){
		if (!in_array(get_option('woocommerce_currency'), array('RUB', 'EUR', 'USD', 'RUR'))){
			return false;
		}
		return true;
	}
	
	/**
	* Admin Panel Options 
	* - Options for bits like 'title' and availability on a country-by-country basis
	*
	* @since 0.1
	**/
	public function admin_options() {
		?>
		<h3><?php _e('Platron', 'woocommerce'); ?></h3>
		<p><?php _e('Настройка приема электронных платежей через Platron.', 'woocommerce'); ?></p>

	  <?php if ( $this->is_valid_for_use() ) : ?>

		<table class="form-table">

		<?php    	
    			// Generate the HTML For the settings form.
    			$this->generate_settings_html();
    ?>
    </table><!--/.form-table-->
    		
    <?php else : ?>
		<div class="inline error"><p><strong><?php _e('Шлюз отключен', 'woocommerce'); ?></strong>: <?php _e('Platron не поддерживает валюты Вашего магазина.', 'woocommerce' ); ?></p></div>
		<?php
			endif;

    } // End admin_options()

  /**
  * Initialise Gateway Settings Form Fields
  *
  * @access public
  * @return void
  */
	function init_form_fields(){
		$this->form_fields = array(
				'enabled' => array(
					'title' => __('Включить/Выключить', 'woocommerce'),
					'type' => 'checkbox',
					'label' => __('Включен', 'woocommerce'),
					'default' => 'yes'
				),
				'title' => array(
					'title' => __('Название', 'woocommerce'),
					'type' => 'text', 
					'description' => __( 'Это название, которое пользователь видит во время проверки.', 'woocommerce' ), 
					'default' => __('Platron', 'woocommerce')
				),
				'merchant_id' => array(
					'title' => __('Номер магазина', 'woocommerce'),
					'type' => 'text',
					'description' => __('Пожалуйста введите Номер магазина', 'woocommerce'),
					'default' => ''
				),
				'secret_key' => array(
					'title' => __('Секретный ключ', 'woocommerce'),
					'type' => 'text',
					'description' => __('Секретный ключ для взаимодействия по API.', 'woocommerce'),
					'default' => ''
				),
				'lifetime' => array(
					'title' => __('Время жизни счета', 'woocommerce'),
					'type' => 'text',
					'description' => __('Считается в минутах. Максимальное значение 7 дней', 'woocommerce'),
					'default' => ''
				),
				'payment_system_name' => array(
					'title' => __('Платежная система', 'woocommerce'),
					'type' => 'text',
					'description' => __('Заполняется только в случае, когда выбор ПС происходит на стороне магазина', 'woocommerce'),
					'default' => ''
				),
				'testmode' => array(
					'title' => __('Тестовый режим', 'woocommerce'),
					'type' => 'checkbox', 
					'label' => __('Включен', 'woocommerce'),
					'description' => __('Тестовый режим используется для проверки взаимодействия.', 'woocommerce'),
					'default' => 'no'
				),
				'description' => array(
					'title' => __( 'Description', 'woocommerce' ),
					'type' => 'textarea',
					'description' => __( 'Описанием метода оплаты которое клиент будет видеть на вашем сайте.', 'woocommerce' ),
					'default' => 'Оплата с помощью platron.'
				),
				'instructions' => array(
					'title' => __( 'Instructions', 'woocommerce' ),
					'type' => 'textarea',
					'description' => __( 'Инструкции, которые будут добавлены на страницу благодарностей.', 'woocommerce' ),
					'default' => 'Оплата с помощью platron.'
				),
				'ofd_send_receipt' => array(
					'title' => __('Отправлять чек в ОФД', 'woocommerce'),
					'type' => 'checkbox',
					'label' => __('Включен', 'woocommerce'),
					'description' => __('Создать чек для отправки в ОФД.', 'woocommerce'),
					'default' => 'no',
				),
				'ofd_tax_type' => array(
					'title' => __('Ставка НДС', 'woocommerce'),
					'type' => 'select',
					'description' => __('Ставка НДС, которая будет указана в чеке'),
					'default' => 'none',
					'options' => array(
						'none' => __('Не облагается', 'woocommerce'),
						'0' => __('0 %', 'woocommerce'),
						'10' => __('10 %', 'woocommerce'),
						'18' => __('18 %', 'woocommerce'),
						'110' => __('10 / 110', 'woocommerce'),
						'118' => __('18 / 118', 'woocommerce'),
					),
				),
			);
	}

	/**
	* Дополнительная информация в форме выбора способа оплаты
	**/
	function payment_fields(){
		if ($this->description){
			echo wpautop(wptexturize($this->description));
		}
	}

	/**
	 * Process the payment and return the result
	 **/
	function process_payment($order_id){
		global $woocommerce;

		$order = wc_get_order($order_id);

		$requestUrl = add_query_arg('wc-api', 'WC_Platron', home_url('/'));

		$initPaymentParams = array(
			'pg_merchant_id'		=> $this->merchant_id,
			'pg_order_id'			=> $order_id,
			'pg_currency'			=> get_woocommerce_currency(),
			'pg_amount'				=> number_format($order->order_total, 2, '.', ''),
			'pg_user_phone'			=> $order->billing_phone,
			'pg_user_email'			=> $order->billing_email,
			'pg_user_contact_email'	=> $order->billing_email,
			'pg_lifetime'			=> ($this->lifetime) ? ($this->lifetime) * 60 : 0,
			'pg_testing_mode'		=> ($this->testmode == 'yes') ? 1 : 0,
			'pg_description'		=> $this->generateOrderDescription($order),
			//'pg_user_ip'			=> $_SERVER['REMOTE_ADDR'],
			'pg_language'			=> (get_locale() == 'ru_RU') ? 'ru' : 'en',
			'pg_check_url'			=> add_query_arg('type', 'check', $requestUrl),
			'pg_result_url'			=> add_query_arg('type', 'result', $requestUrl),
			'pg_request_method'		=> 'POST',
			'pg_success_url'		=> add_query_arg('type', 'success', $requestUrl),
			'pg_failure_url'		=> add_query_arg('type', 'failed', $requestUrl),
			'pg_salt'				=> rand(21,43433),
		);
		if (!empty($this->payment_system_name)) {
			$initPaymentParams['payment_system_name'] = $this->payment_system_name;
		}
		$initPaymentParams['cms_payment_module'] = 'WOO_COMMERCE';
		$initPaymentParams['pg_sig'] = PG_Signature::make('init_payment.php', $initPaymentParams, $this->secret_key);

		$init_payment_response = $this->do_platron_request('init_payment.php', $initPaymentParams);
		if (is_wp_error($init_payment_response)) {
			$error_message = $init_payment_response->get_error_message();
			wc_add_notice( __('Payment error: ', 'woothemes') . $error_message, 'error' );
			return;
		}

		if ($this->send_receipt) {
			$receipt = new OfdReceiptRequest($this->merchant_id, (string) $init_payment_response->pg_payment_id);
			$receipt->items = $this->prepareOfdItems($order);
			if ($order->get_shipping_total() > 0) {
				$ofdItem = new OfdReceiptItem();
				$ofdItem->label = __('Доставка', 'woocommerce');
				$ofdItem->price = round($order->get_shipping_total(), 2);
				$ofdItem->quantity = 1;
				$ofdItem->amount = round($order->get_shipping_total(), 2);
				$ofdItem->vat = '18';
				$receipt->items[] = $ofdItem;
			}
			$receipt->prepare();
			$receipt->sign($this->secret_key);

			$create_receipt_response = $this->do_platron_request('receipt.php', array('pg_xml'=>$receipt->asXml()));
			if (is_wp_error($create_receipt_response)) {
				$error_message = $create_receipt_response->get_error_message();
				wc_add_notice( __('Payment error: ', 'woothemes') . $error_message, 'error' );
				return;
			}
		}

		$woocommerce->cart->empty_cart();

		return array(
			'result' => 'success',
			'redirect'	=> (string) $init_payment_response->pg_redirect_url,
		);
	}
	
	private function generateOrderDescription($order) {
		$itemDescriptions = array();
		foreach ($order->get_items() as $item) {
			var_dump($item);echo "<br>";
			$itemDescriptions[] = $item->get_product()->get_name() . ' * ' . $item->get_quantity();
		}
		return implode('; ', $itemDescriptions);
	}

	private function prepareOfdItems($order) {
		$ofdItems = array();
		foreach ($order->get_items() as $item) {
			$ofdItem = new OfdReceiptItem();
			$ofdItem->label = substr($item->get_product()->get_name(), 0, 128);
			$ofdItem->price = round($item->get_product()->get_price(), 2);
			$ofdItem->quantity = round($item->get_quantity(), 2);
			$ofdItem->amount = round($item->get_total(), 2);
			$ofdItem->vat = $this->tax_type;
			$ofdItems[] = $ofdItem;
		}

		return $ofdItems;
	}

	private function do_platron_request($script, $params) {
		$url = 'https://www.platron.ru/' . $script;
		$response = wp_remote_post($url, array('body' => $params));
		if (is_wp_error($response)) {
			return new WP_Error(self::ERROR_TYPE, $response->get_error_message());
		}
		if (wp_remote_retrieve_response_code($response) != '200') {
			return new WP_Error(self::ERROR_TYPE, 'Invalid response code: ' . wp_remote_retrieve_response_code($response));
		}
		try {
			$responseXmlElement = new SimpleXMLElement(wp_remote_retrieve_body($response));
		} catch (Exception $e) {
			return new WP_Error(self::ERROR_TYPE, 'Can not load xml from request');
		}
		if (!PG_Signature::checkXML($script, $responseXmlElement, $this->secret_key)) {
			return new WP_Error(self::ERROR_TYPE, 'Invalid response signature');
		}
		if ($responseXmlElement->pg_status == 'error') {
			return new WP_Error(self::ERROR_TYPE, $responseXmlElement->pg_error_description);
		}

		return $responseXmlElement;
	}
	
	/**
	* Check Response
	**/
	function check_assistant_response(){
		global $woocommerce;
		
		if(!empty($_POST))
			$arrRequest = $_POST;
		else
			$arrRequest = $_GET;
		
		$thisScriptName = PG_Signature::getOurScriptName();
		if (empty($arrRequest['pg_sig']) || !PG_Signature::check($arrRequest['pg_sig'], $thisScriptName, $arrRequest, $this->secret_key))
			die("Wrong signature");

		$objOrder = new WC_Order($arrRequest['pg_order_id']);

		$arrResponse = array();
		$aGoodCheckStatuses = array('pending','processing');
		$aGoodResultStatuses = array('pending','processing','completed');
		
		switch($_GET['type']){
			case 'check':
				$bCheckResult = 1;			
				if(empty($objOrder) || !in_array($objOrder->status, $aGoodCheckStatuses)){
					$bCheckResult = 0;
					$error_desc = 'Order status '.$objOrder->status.' or deleted order';
				}
				if(intval($objOrder->order_total) != intval($arrRequest['pg_amount'])){
					$bCheckResult = 0;
					$error_desc = 'Wrong amount';
				}

				$arrResponse['pg_salt']              = $arrRequest['pg_salt']; 
				$arrResponse['pg_status']            = $bCheckResult ? 'ok' : 'error';
				$arrResponse['pg_error_description'] = $bCheckResult ?  ""  : $error_desc;
				$arrResponse['pg_sig']				 = PG_Signature::make($thisScriptName, $arrResponse, $this->secret_key);

				$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
				$objResponse->addChild('pg_salt', $arrResponse['pg_salt']);
				$objResponse->addChild('pg_status', $arrResponse['pg_status']);
				$objResponse->addChild('pg_error_description', $arrResponse['pg_error_description']);
				$objResponse->addChild('pg_sig', $arrResponse['pg_sig']);
				break;
				
			case 'result':	
				if(intval($objOrder->order_total) != intval($arrRequest['pg_amount'])){
					$strResponseDescription = 'Wrong amount';
					if($arrRequest['pg_can_reject'] == 1)
						$strResponseStatus = 'rejected';
					else
						$strResponseStatus = 'error';
				}
				elseif((empty($objOrder) || !in_array($objOrder->status, $aGoodResultStatuses)) && 
						!($arrRequest['pg_result'] == 0 && $objOrder->status == 'failed')){
					$strResponseDescription = 'Order status '.$objOrder->status.' or deleted order';
					if($arrRequest['pg_can_reject'] == 1)
						$strResponseStatus = 'rejected';
					else
						$strResponseStatus = 'error';
				} else {
					$strResponseStatus = 'ok';
					$strResponseDescription = "Request cleared";
					if ($arrRequest['pg_result'] == 1){
						$objOrder->update_status('completed', __('Платеж успешно оплачен', 'woocommerce'));
						WC()->cart->empty_cart();
					}
					else{
						$objOrder->update_status('failed', __('Платеж не оплачен', 'woocommerce'));
						WC()->cart->empty_cart();
					}
				}

				$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
				$objResponse->addChild('pg_salt', $arrRequest['pg_salt']);
				$objResponse->addChild('pg_status', $strResponseStatus);
				$objResponse->addChild('pg_description', $strResponseDescription);
				$objResponse->addChild('pg_sig', PG_Signature::makeXML($thisScriptName, $objResponse, $this->secret_key));
				
				break;
			case 'success':
				wp_redirect( $this->get_return_url( $objOrder ) );
				break;
			case 'failed':
				wp_redirect($objOrder->get_cancel_order_url());
				break;
			default :
				die('wrong type');
		}
		
		header("Content-type: text/xml");
		echo $objResponse->asXML();
		die();
	}

}

/**
 * Add the gateway to WooCommerce
 **/
function add_platron_gateway($methods){
	$methods[] = 'WC_Platron';
	return $methods;
}

add_filter('woocommerce_payment_gateways', 'add_platron_gateway');
}
?>