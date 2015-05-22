<?php
/*
	Plugin Name: BitBayar - WooCommerce Payment Gateway
	Plugin URI: https://www.bitbayar.com/
	Description: Bitcoin Payment plugin for WooCommerce using BitBayar service.
	Version: 1.0
	Author: Teddy Fresnel
	Author URI: https://www.bitbayar.com/

	GitHub Plugin URI: https://github.com/btcid/bitbayar-woocommerce
*/

//~ Exit if accessed directly
if (false === defined('ABSPATH')) {
    exit;
}

//~ Register Payment Gateway with WooCommerce
add_action('plugins_loaded', 'woocommerce_bitbayar_init', 0);

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

	function woocommerce_bitbayar_init()
	{

		class WC_Gateway_Bitbayar extends WC_Payment_Gateway
		{
			//~ Define constructor
			public function __construct()
			{
				//~ General
				$this->id					= 'bitbayar';
				$this->icon					= WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/bitbayar.png';
				$this->has_fields			= false;
				$this->order_button_text	= __('Proceed to Bitbayar', 'bitbayar');
				$this->method_title			= 'Bitbayar';
				$this->method_description	= 'Bitbayar allows you to accept bitcoin payments on your WooCommerce store using BitBayar service.';

				//~ Load the settings.
				$this->init_form_fields();
				$this->init_settings();

				//~ Define user set variables
				$this->title		= $this->get_option('title');
				$this->description	= $this->get_option('description');

				//~ Define debugging & informational settings
				$this->debug_php_version	= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
				$this->debug_plugin_version	= get_option('woocommerce_bitbayar_version');

				//~ Actions
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
				add_action('woocommerce_receipt_bitbayar', array($this, 'receipt_page'));

				//~ Payment listener/API hook
				add_action('woocommerce_api_wc_gateway_bitbayar', array($this, 'check_bitbayar_callback'));
			}

			//~ Gateway settings page
			public function init_form_fields()
			{
				$this->form_fields = array(
					'enabled' => array(
						'title'		=> __('Enable/Disable', 'bitbayar'),
						'type'		=> 'checkbox',
						'label'		=> __('Enable Bitcoin Payments via Bitbayar', 'bitbayar'),
						'default'	=> 'yes'
					),
					'title' => array(
						'title'			=> __('Title', 'bitbayar'),
						'type'			=> 'text',
						'description'	=> __('Controls the name of this payment method as displayed to the customer during checkout.', 'bitbayar'),
						'default'		=> __('Bitcoin (Frey)', 'bitbayar'),
						'desc_tip'		=> true,
					),
					'description' => array(
						'title'			=> __('Customer Message', 'bitbayar'),
						'type'			=> 'textarea',
						'description'	=> __('Message to explain how the customer will be paying for the purchase.', 'bitbayar'),
						'default'		=> 'You will be redirected to bitbayar.com to complete your purchase.',
						'desc_tip'		=> true,
					),
					'apiToken' => array(
						'title'			=> __('API Token', 'bitbayar-woocommerce'),
						'type'			=> 'text',
						'description'	=> __('API Token available at "Setting & API" at your BitBayar merchant account."')
					)
			   );
			}

			//~ Handling payment and processing the order
			public function process_payment($order_id)
			{
				global $woocommerce;
				$order = new WC_Order( $order_id );

				//~ Mark as on-hold (we're awaiting the cheque)
				$order->update_status('on-hold', __( 'Awaiting cheque payment', 'bitbayar-woocommerce' ));

				//~ Reduce stock levels
				$order->reduce_order_stock();

				//~ Remove cart
				$woocommerce->cart->empty_cart();

				//~ Bitbayar mangles the order param so we have to put it somewhere else and restore it on init
				$success_url = add_query_arg('return_from_bitbayar', true, $this->get_return_url($order));
				$cancel_url = add_query_arg('return_from_bitbayar', true, $order->get_cancel_order_url());
				$cancel_url = add_query_arg('cancelled', true, $cancel_url);
				$cancel_url = add_query_arg('order_key', $order->order_key, $cancel_url);
				$checkout_url = $woocommerce->cart->get_checkout_url();

				$data = array(
					'token'			=>$this->get_option('apiToken'),
					'invoice_id'	=>$order_id,
					'rupiah'		=>$order->get_total(),
					'memo'			=>'Invoice #'.$order_id .' Woo',
					'callback_url'	=>WC()->api_request_url('WC_Gateway_Bitbayar'),
					'url_success'	=>$success_url,
					'url_failed'	=>$cancel_url
				);
				$this->log(' [Info] post        = ' . print_r($data, true));

				$url	= 'https://bitbayar.com/api/create_invoice';
				$ch		= curl_init($url);
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_POST, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
				$return		= curl_exec($ch);
				curl_close($ch);
				$response	= json_decode($return);

				return array(
					'result'	=> 'success',
					'response'	=> print_r($response, true),
					'redirect'	=> $response->payment_url,
				);
			}

			//~ Callback from BitBayar
			public function check_bitbayar_callback()
			{
				$this->log(' [Info] Bitbayar Callback        = '.print_r($_POST, true));

				$bitbayar_id 	= $_POST['id'];
				$order_id		= $_POST['invoice_id'];

				//~ cek invoice
				$data = array(
					'token'	=>$this->get_option('apiToken'),
					'id'	=>$bitbayar_id
				);

				$url	= 'https://bitbayar.com/api/check_invoice';
				$ch		= curl_init($url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_POST, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
				$return		= curl_exec($ch);
				curl_close($ch);
				$response	= json_decode($return);

				$this->log(' [Info] respon cek invoice        '.print_r($return, true));

				if($response->status=='paid'){
					$order = wc_get_order($order_id);
					$order->payment_complete();
					$order->update_status('completed');
					$order->add_order_note(__('Bitbayar payment completed', 'bitbayar-woocommerce'));
					$order->add_order_note(__('Bitcoin tx: '.print_r($response->txid, true), 'bitbayar-woocommerce'));
					$this->log(' [Info] complete payment        '.$_POST['invoice_id']);
				}else{
					$order = wc_get_order($order_id);
					$order->add_order_note(__('Invalid payment', 'bitbayar-woocommerce'));
					$this->log(' [Info] invalid payment        '.$response->status);
				}
			}

			//~ Allows log files to be written to for debugging purposes.
			public function log($message)
			{
				if (true === isset($this->debug) && 'yes' == $this->debug) {
					if (false === isset($this->logger) || true === empty($this->logger)) {
						$this->logger = new WC_Logger();
					}
					$this->logger->add('bitbayar', $message);
				}
			}
		}


		//~ Add BitBayar Payment Gateway to WooCommerce
		function wc_add_bitbayar($methods)
		{
			$methods[]	= 'WC_Gateway_Bitbayar';

			return $methods;
		}

		function woocommerce_handle_bitbayar_return() {
			$message	= ' [Info]        Enter woocommerce_handle_bitbayar_return';
			$logger		= new WC_Logger();
			$logger->add('bitbayar', $message);

			if (!isset($_GET['return_from_bitbayar']))
				return;

			if (isset($_GET['cancelled'])) {
				$order	= new WC_Order($_GET['invoice_id']);
				if ($order->status != 'completed') {
					$order->update_status('failed', __('Customer cancelled bitbayar payment', 'bitbayar-woocommerce'));
				}
			}

			//~ Bitbayar order param interferes with woocommerce
			unset($_GET['order']);
			unset($_REQUEST['order']);
			if (isset($_GET['order_key'])) {
				$_GET['order'] = $_GET['order_key'];
			}
		}

		add_action('init', 'woocommerce_handle_bitbayar_return');
		add_filter('woocommerce_payment_gateways', 'wc_add_bitbayar');
	}
}