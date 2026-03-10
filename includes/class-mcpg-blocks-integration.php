<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class MCPG_Blocks_Integration extends AbstractPaymentMethodType {

    protected $name = 'mcpg_cascading';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_mcpg_cascading_settings', array() );
    }

    public function is_active() {
        return ( $this->settings['enabled'] ?? 'no' ) === 'yes';
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'mcpg-blocks',
            MCPG_PLUGIN_URL . 'assets/js/mcpg-blocks.js',
            array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
            MCPG_VERSION,
            true
        );

        // Also enqueue the card form CSS
        wp_enqueue_style( 'mcpg-checkout-css', MCPG_PLUGIN_URL . 'assets/css/mcpg-cascade.css', array(), MCPG_VERSION );

        return array( 'mcpg-blocks' );
    }

    public function get_payment_method_data() {
        $countries = array();
        if ( function_exists( 'WC' ) && WC()->countries ) {
            foreach ( WC()->countries->get_countries() as $code => $name ) {
                $countries[] = array( 'code' => $code, 'name' => $name );
            }
        }
        $default_country = '';
        if ( function_exists( 'WC' ) && WC()->customer ) {
            $default_country = WC()->customer->get_billing_country();
        }
        return array(
            'title'           => $this->settings['title'] ?? 'Credit / Debit Card',
            'description'     => $this->settings['description'] ?? '',
            'supports'        => array( 'products', 'refunds' ),
            'icon'            => '',
            'countries'       => $countries,
            'defaultCountry'  => $default_country,
        );
    }
}
