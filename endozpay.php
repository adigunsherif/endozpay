<?php
/**
 * Plugin Name: EndozPay Gateway
 * Description: WooCommerce Payment Gateway for Endoz OpenBanking with Block support.
 * Author: Disbuz by Celergate
 * Version: 1.0.1
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/api.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers.php';


add_action('plugins_loaded', 'endozpay_init_gateway_class');

function endozpay_init_gateway_class()
{
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Gateway_EndozPay extends WC_Payment_Gateway
    {
        public $clientid;
        public $public_key;
        public $secret_key;
        public $settlement_account_id;
        public $webhook_url;
        public $is_production;
        public $redirect_url;

        public function __construct()
        {
            $this->id = 'endozpay';
            $this->has_fields = false;
            $this->method_title = 'EndozPay';
            $this->method_description = 'Pay using EndozPay OpenBanking.';

            $this->init_form_fields();
            $this->init_settings();

            foreach ($this->settings as $key => $val) {
                $this->$key = $val;
            }

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_endozpay_webhook', array($this, 'handle_webhook'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable EndozPay',
                    'default' => 'yes'
                ),
                'clientid' => array(
                    'title' => 'Client ID',
                    'type' => 'text'
                ),
                'public_key' => array(
                    'title' => 'Public Key',
                    'type' => 'text'
                ),
                'secret_key' => array(
                    'title' => 'Secret Key',
                    'type' => 'text'
                ),
                'settlement_account_id' => array(
                    'title' => 'Settlement Account ID',
                    'type' => 'text'
                ),
                'webhook_url' => array(
                    'title' => 'Webhook URL',
                    'type' => 'text'
                ),
                'is_production' => array(
                    'title' => 'Production Mode',
                    'type' => 'checkbox'
                )
            );
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $base_url = $this->is_production === 'yes' ? 'https://endozapi.celergate.co.uk' : 'https://endozapi.celergate.net';


            $token = endozpay_api_login(
                $base_url,
                $this->clientid,
                $this->secret_key,
                $this->public_key
            );

            $payer = [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email_address' => $order->get_billing_email(),
                'address' => $order->get_billing_address_1()
            ];

            $ref = uniqid();

            $payload = [
                'amount' => (float) $order->get_total(),
                'currency' => "GBP",
                'settlement_account' => $this->settlement_account_id,
                'transaction_ref' => $ref,
                'narration' => 'Order #' . $order->get_id(),
                'webhook_url' => $this->webhook_url,
                'redirect_url' => $order->get_checkout_order_received_url(),
                'payer' => $payer
            ];

            $res = endozpay_api_initiate_payment(
                $base_url,
                $token,
                $this->clientid,
                $payload
            );
            $order->update_meta_data('_endozpay_reference', $res['reference']);
            $order->save();

            return [
                'result' => 'success',
                'redirect' => $res['hosted_url']
            ];
        }

        public function handle_webhook()
        {
            $data = json_decode(file_get_contents('php://input'), true);
            $reference = $data['reference'] ?? '';

            $orders = wc_get_orders([
                'limit' => 1,
                'meta_key' => '_endozpay_reference',
                'meta_value' => $reference
            ]);

            if (!empty($orders)) {
                $order = $orders[0];
                $order->update_status('completed', 'Payment confirmed via webhook');
                $order->save();
            }

            status_header(200);
            exit;
        }
    }
}

add_filter('woocommerce_payment_gateways', 'add_endozpay_gateway_class');
function add_endozpay_gateway_class($methods)
{
    $methods[] = 'WC_Gateway_EndozPay';
    return $methods;
}


add_action('woocommerce_blocks_loaded', 'endozpay_block_support');

function endozpay_block_support() {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }


    // Include the custom payment gateway support class for WooCommerce Blocks.
    require_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-blocks-support.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            $payment_method_registry->register( new WC_Gateway_EndozPay_Blocks() );
        }
    );
}



error_log('[EndozPay] Plugin loaded - current URL: ' . $_SERVER['REQUEST_URI']);
add_action('woocommerce_thankyou', 'endozpay_handle_jwt_payload_on_thankyou', 10, 1);
function endozpay_handle_jwt_payload_on_thankyou($order_id)
{
    wc_logger('[EndozPay JWT Payload] ');
    if (!isset($_GET['encoded_payload'])) {
        return;
    }

    $payload = $_GET['encoded_payload'];
    $decoded = endozpay_decode_jwt_payload($payload);
    wc_logger('[EndozPay JWT Payload] ' . print_r($decoded, true));

    if (!$decoded || !isset($decoded['paymentStatus'])) {
        return;
    }

    // If payment failed, redirect to retry payment
    if ($decoded['paymentStatus'] !== 'COMPLETED') {
        $order = wc_get_order($order_id);
        $retry_url = $order->get_checkout_payment_url();

        // Optional: set a failure note
        $order->add_order_note('EndozPay failed: ' . $decoded['paymentStatus']);
        $order->update_status('failed', 'Redirecting to retry payment.');

        wp_safe_redirect($retry_url);
        exit;
    }

    // Optional: mark order as paid
    $order = wc_get_order($order_id);
    if ($order->get_status() !== 'completed') {
        $order->update_status('processing');
    }
}
