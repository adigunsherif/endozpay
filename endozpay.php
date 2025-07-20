<?php
/**
 * Plugin Name: EndozPay Gateway
 * Description: WooCommerce Payment Gateway for Endoz OpenBanking.
 * Author: Disbuz by Celergate
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
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
        public $is_production;
        public $webhook_url;
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
            $this->webhook_url = home_url('/?wc-api=endozpay_webhook');

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
                'is_production' => array(
                    'title' => 'Production Mode',
                    'type' => 'checkbox'
                )
            );
        }

        public function is_available() {
            // Only enable if WooCommerce is using GBP
            if (get_woocommerce_currency() !== 'GBP') {
                return false;
            }

            return parent::is_available();
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

            if (!$token) {
                wc_add_notice('Error authenticating with EndozPay. Please contact support.', 'error');
                return;
            }

            $payer = [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email_address' => $order->get_billing_email(),
                'address' => $order->get_billing_address_1()
            ];

            $payload = [
                'amount' => (float) $order->get_total(),
                'currency' => "GBP",
                'settlement_account' => $this->settlement_account_id,
                'transaction_ref' => $order->get_order_key(),
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

            if (!$res || !isset($res['hosted_url'])) {
                wc_add_notice('Error initiating payment with EndozPay. Please try again.', 'error');
                return;
            }
            $order->update_meta_data('_endozpay_reference', $res['reference']);
            $order->set_transaction_id($your_transaction_ref);
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

            if (!$reference) {
                status_header(400);
                echo 'Missing reference';
                exit;
            }

            #log decoded message
            wc_logger('EndozPay Webhook received', [
                'data' => $data,
            ]);

            $orders = wc_get_orders([
                'limit' => 1,
                'meta_key' => '_endozpay_reference',
                'meta_value' => $reference
            ]);

            if (empty($orders)) {
                status_header(404);
                echo 'Order not found';
                exit;
            }

            $order = $orders[0];

            if (!in_array($order->get_status(), ['processing', 'completed'], true)) {
                $new_status = $order->needs_processing() ? 'processing' : 'completed';
                $order->update_status($new_status, 'Payment confirmed via webhook');
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

add_action('woocommerce_thankyou', 'endozpay_handle_jwt_payload_on_thankyou', 10, 1);
function endozpay_handle_jwt_payload_on_thankyou($order_id)
{
    if (!isset($_GET['encoded_payload'])) {
        return;
    }

    $payload = $_GET['encoded_payload'];
    $decoded = endozpay_decode_jwt_payload($payload);

    if (!$decoded || !isset($decoded['paymentStatus'])) {
        return;
    }

    $order = wc_get_order($order_id);
    $payment_status = strtoupper($decoded['paymentStatus']);

    // If payment is processing
    if (in_array($payment_status, ['PROCESSING', 'COMPLETED', 'SETTLED'])) {
        if ($order->get_status() !== 'completed') {
            $order->update_status('on-hold');
        }
    } elseif ($payment_status === 'FAILED') {
        // if payment failed, fail the order.
        if ($order->get_status() !== 'failed') {
            $order->update_status('failed', 'EndozPay payment failed: ' . $payment_status);
        }

    } else {
        // If payment is not processing or failed, redirect to retry payment.
        $order = wc_get_order($order_id);
        $retry_url = $order->get_checkout_payment_url();

        if ($payment_status === 'CANCELLED') {
            wc_add_notice('Payment was cancelled. Please try again.', 'error');
        } else {
            wc_add_notice('Payment failed. Please try again.', 'error');
        }

        // Optional: set a failure note
        $order->add_order_note('EndozPay failed: ' . $payment_status);
        $order->update_status('failed', 'Redirecting to retry payment.');

        wp_safe_redirect($retry_url);
        exit;
    }

}

add_action('admin_notices', 'endozpay_admin_currency_notice');
function endozpay_admin_currency_notice() {
    if (!class_exists('WooCommerce')) {
        return;
    }

    $currency = get_woocommerce_currency();
    if ($currency !== 'GBP') {
        echo '<div class="notice notice-error"><p>';
        echo '⚠️ <strong>EndozPay Gateway</strong> only supports GBP. Current store currency is <strong>' . esc_html($currency) . '</strong>. Please switch to GBP in <a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=general')) . '">WooCommerce settings</a>.';
        echo '</p></div>';
    }
}
