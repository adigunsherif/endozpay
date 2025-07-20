<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;


class WC_Gateway_EndozPay_Blocks extends AbstractPaymentMethodType
{
    protected $name = 'endozpay';


    public function __construct() {
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( $this, 'checkout_process_payment_with_context' ), 10, 2 );
	}

    public function initialize() {
        $this->settings = get_option('woocommerce_endozpay_settings', []);
    }

    public function is_active() {
        return filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN );
    }

    public function get_payment_method_script_handles() {
        $plugin_url = plugin_dir_url(__DIR__);
        wp_register_script(
            'endozpay-block-js',
            plugin_dir_url( __DIR__ ) . 'assets/js/endozpay-block.js',
            [],
            '1.0',
            true
        );
        wp_add_inline_script(
            'endozpay-block-js',
            'window.endozpay_data = ' . json_encode([
                'logo_url' => $plugin_url . 'assets/img/logo.svg',
            ]) . ';',
            'before'
        );
        return [ 'endozpay-block-js' ];
    }

    public function get_payment_method_script_handles_for_admin() {
		return $this->get_payment_method_script_handles();
	}

    public function get_payment_method_data() {
        return [
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => $this->get_supported_features(),
		];
    }

    public function checkout_process_payment_with_context( $request, $context ) {
        if ( property_exists( $context, 'payment_data' )) {
            $order = $context->get_order();
            $order_id = $order->get_id();
            $result = $this->process_payment( $order_id );

            if ( ! empty( $result['redirect'] ) ) {
                return rest_ensure_response( [
                    'result'   => 'success',
                    'redirect_url' => $result['redirect'],
                ] );
            }

        }

        return new WP_Error( 'payment_error', 'Unable to initiate payment', [ 'status' => 500 ] );
    }

}
