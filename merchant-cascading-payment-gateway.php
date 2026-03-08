<?php
/**
 * Plugin Name: Merchant Cascading Payment Gateway for WooCommerce
 * Description: Cascading payment orchestration across multiple processors (V-Processor 2D, E-Processor 2D, V-Processor 3D) with real-time customer-facing progress UI.
 * Version: 2.0.0
 * Author: Salman Khan
 * Author URI: https://zask.it
 * Text Domain: merchant-cascading-gateway
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.6
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MCPG_VERSION', '2.0.0' );
define( 'MCPG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCPG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MCPG_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/* ── HPOS + Block Checkout compatibility ── */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
});

/* ── WooCommerce dependency check ── */
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="error"><p><strong>Merchant Cascading Payment Gateway</strong> requires WooCommerce to be installed and active.</p></div>';
    });
    return;
}

/* ── Load plugin ── */
add_action( 'plugins_loaded', 'mcpg_init', 11 );
function mcpg_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

    require_once MCPG_PLUGIN_DIR . 'includes/class-mcpg-logger.php';
    require_once MCPG_PLUGIN_DIR . 'includes/class-mcpg-card-store.php';
    require_once MCPG_PLUGIN_DIR . 'includes/class-mcpg-vprocessor-api.php';
    require_once MCPG_PLUGIN_DIR . 'includes/class-mcpg-eprocessor-api.php';
    require_once MCPG_PLUGIN_DIR . 'includes/class-mcpg-cascade-engine.php';
    require_once MCPG_PLUGIN_DIR . 'includes/class-mcpg-webhook-handler.php';
    require_once MCPG_PLUGIN_DIR . 'includes/class-mcpg-gateway.php';

    add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
        $gateways[] = 'MCPG_Gateway';
        return $gateways;
    });

    // Register AJAX handlers directly (gateway may not be instantiated during AJAX)
    if ( wp_doing_ajax() ) {
        $gateway = new MCPG_Gateway();
    }

    // Initialize webhook handler
    MCPG_Webhook_Handler::init();
}

/* ── Phone field required (processors need it) ── */
add_filter( 'woocommerce_billing_fields', function ( $fields ) {
    if ( isset( $fields['billing_phone'] ) ) {
        $fields['billing_phone']['required'] = true;
    }
    return $fields;
}, 20 );

add_filter( 'woocommerce_get_country_locale_default', function ( $locale ) {
    $locale['phone'] = array( 'required' => true );
    return $locale;
});

add_action( 'woocommerce_store_api_checkout_update_order_from_request', function ( $order, $request ) {
    if ( empty( $order->get_billing_phone() ) ) {
        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
            'missing_phone', 'Phone number is required for payment processing.', 400
        );
    }
}, 10, 2 );

/* ── E-Processor rewrite endpoints ── */
add_action( 'init', function () {
    add_rewrite_endpoint( 'mcpg-ep-callback', EP_ROOT );
    add_rewrite_endpoint( 'mcpg-ep-return', EP_ROOT );
});

/* ── VP3D webhook endpoints ── */
add_action( 'woocommerce_api_vsafe_webhook', array( 'MCPG_Webhook_Handler', 'handle_vp3d_webhook' ) );
add_action( 'woocommerce_api_vsafe_3ds_return', array( 'MCPG_Webhook_Handler', 'handle_vp3d_3ds_return' ) );

/* ── Activation / Deactivation ── */
register_activation_hook( __FILE__, function () {
    flush_rewrite_rules();
    set_transient( 'mcpg_activation_redirect', true, 30 );
});
register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
});

/* ── Redirect to settings on activation ── */
add_action( 'admin_init', function () {
    if ( ! get_transient( 'mcpg_activation_redirect' ) ) return;
    delete_transient( 'mcpg_activation_redirect' );
    if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) ) return;
    wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=mcpg_cascading' ) );
    exit;
});

/* ── Settings link ── */
add_filter( 'plugin_action_links_' . MCPG_PLUGIN_BASENAME, function ( $links ) {
    array_unshift( $links, '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=mcpg_cascading' ) . '">Settings</a>' );
    return $links;
});
