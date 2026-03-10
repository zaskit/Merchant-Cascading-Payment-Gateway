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

define( 'MCPG_VERSION', '2.1.0' );
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

    // Percentage fee — registered here so it fires even before the gateway object is loaded
    add_action( 'woocommerce_cart_calculate_fees', 'mcpg_add_percentage_fee' );

    // Descriptor in customer emails — registered here so it fires regardless of gateway instantiation
    add_action( 'woocommerce_email_after_order_table', 'mcpg_show_descriptor_email', 10, 4 );
}

function mcpg_add_percentage_fee( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    if ( ! $cart ) return;

    $settings = get_option( 'woocommerce_mcpg_cascading_settings', array() );
    if ( ( $settings['enabled'] ?? 'no' ) !== 'yes' ) return;

    $pct = floatval( $settings['percentage_on_top'] ?? '' );
    if ( $pct <= 0 ) return;

    $gateway_id = 'mcpg_cascading';

    // Determine if our gateway is the active payment method
    $chosen = '';
    if ( ! empty( $_POST['payment_method'] ) ) {
        $chosen = sanitize_text_field( $_POST['payment_method'] );
    } elseif ( WC()->session ) {
        $chosen = WC()->session->get( 'chosen_payment_method', '' );
    }

    if ( ! empty( $chosen ) ) {
        if ( $chosen !== $gateway_id ) return;
    } else {
        // No method chosen yet — apply if we're the first available gateway
        $available = WC()->payment_gateways()->get_available_payment_gateways();
        if ( empty( $available ) || array_key_first( $available ) !== $gateway_id ) return;
    }

    $total = $cart->get_cart_contents_total() + $cart->get_shipping_total();
    $fee   = round( $total * ( $pct / 100 ), 2 );
    if ( $fee > 0 ) {
        $label = $settings['fee_label'] ?? 'Transaction Fee';
        $cart->add_fee( sprintf( '%s (%s%%)', $label, $pct ), $fee, true );
    }
}

function mcpg_show_descriptor_email( $order, $sent_to_admin, $plain_text, $email ) {
    if ( $sent_to_admin ) return;
    if ( $order->get_payment_method() !== 'mcpg_cascading' ) return;

    $settings   = get_option( 'woocommerce_mcpg_cascading_settings', array() );
    $processor  = $order->get_meta( '_mcpg_payment_processor' );
    $descriptor = $processor ? ( $settings[ $processor . '_descriptor' ] ?? '' ) : '';
    if ( empty( $descriptor ) ) return;

    $msg = sprintf(
        'Your payment has been processed securely. The charge will appear on your statement as "%s". If you have any questions, please contact our support team.',
        esc_html( $descriptor )
    );

    if ( $plain_text ) {
        echo "\n" . wp_strip_all_tags( $msg ) . "\n\n";
    } else {
        echo '<div style="background:#f0f7ff;border-left:4px solid #6366f1;padding:14px 18px;margin:16px 0;font-size:15px;line-height:1.6;color:#1d2327;">';
        echo wp_kses_post( $msg );
        echo '</div>';
    }
}

/* ── Block Checkout integration ── */
add_action( 'woocommerce_blocks_loaded', function () {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) return;

    require_once MCPG_PLUGIN_DIR . 'includes/class-mcpg-blocks-integration.php';

    add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
        $registry->register( new MCPG_Blocks_Integration() );
    });
});

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
