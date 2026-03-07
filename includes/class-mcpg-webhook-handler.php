<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Webhook & callback handler for the cascading gateway.
 * Handles VP3D webhooks, VP3D 3DS returns, and EP callbacks/returns.
 */
class MCPG_Webhook_Handler {

    /**
     * Register EP rewrite endpoint handlers.
     */
    public static function init() {
        add_action( 'template_redirect', array( __CLASS__, 'handle_ep_endpoints' ) );
    }

    /* ═══════════════════ VP3D WEBHOOK ═══════════════════ */
    public static function handle_vp3d_webhook() {
        $settings  = get_option( 'woocommerce_mcpg_cascading_settings', array() );
        $testmode  = ( $settings['vp3d_testmode'] ?? 'yes' ) === 'yes';
        $api_token = $testmode ? ( $settings['vp3d_test_api_token'] ?? '' ) : ( $settings['vp3d_live_api_token'] ?? '' );
        $debug     = ( $settings['debug'] ?? 'yes' ) === 'yes';
        $logger    = $debug ? wc_get_logger() : null;
        $ctx       = array( 'source' => 'mcpg-webhook' );

        $raw_post = file_get_contents( 'php://input' );
        $data     = json_decode( $raw_post, true );

        if ( $debug ) {
            $logger->debug( '=== MCPG VP3D WEBHOOK ===', $ctx );
            $logger->debug( 'Body: ' . $raw_post, $ctx );
        }

        // Validate JSON
        if ( empty( $data ) || ! is_array( $data ) ) {
            self::vp3d_response( 'ERROR', 'Invalid JSON', '', '', 400 );
        }

        // Validate signature
        $signature = $_SERVER['HTTP_SIGNATURE'] ?? '';
        $expected  = hash( 'sha256', $api_token . $raw_post . $api_token );

        if ( ! hash_equals( $expected, $signature ) ) {
            if ( $debug ) $logger->debug( 'Signature mismatch', $ctx );
            self::vp3d_response( 'ERROR', 'Invalid signature', '', '', 400 );
        }

        $transaction_id     = $data['transactionId'] ?? '';
        $external_reference = $data['externalReference'] ?? '';
        $transaction_type   = $data['transactionType'] ?? '';

        if ( $debug ) {
            $logger->debug( 'Type: ' . $transaction_type . ' TX: ' . $transaction_id . ' Ref: ' . $external_reference, $ctx );
        }

        if ( $transaction_type === 'payment' || $transaction_type === 'deposit' ) {
            $result = self::process_vp3d_payment_webhook( $data, $debug, $logger, $ctx );
        } elseif ( $transaction_type === 'refund' ) {
            $result = self::process_vp3d_refund_webhook( $data, $debug, $logger, $ctx );
        } else {
            self::vp3d_response( 'ERROR', 'Unknown type', $transaction_id, $external_reference, 400 );
        }

        $status = $result ? 'OK' : 'ERROR';
        self::vp3d_response( $status, 'Processed', $transaction_id, $external_reference, 200 );
    }

    private static function process_vp3d_payment_webhook( $data, $debug, $logger, $ctx ) {
        $ext_ref  = $data['externalReference'] ?? '';
        $order_id = (int) explode( '-', $ext_ref )[0];

        if ( ! $order_id ) return false;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return false;

        // Only process orders paid via our cascade gateway
        if ( $order->get_payment_method() !== 'mcpg_cascading' ) return false;

        // Already finalized
        if ( $order->has_status( array( 'processing', 'completed' ) ) ) return true;

        $status = $data['result']['status'] ?? '';
        $tx_id  = $data['transactionId'] ?? '';

        if ( $debug ) $logger->debug( 'Webhook status: ' . $status . ' for order #' . $order_id, $ctx );

        // Store card metadata
        foreach ( array( 'cardBrand' => '_mcpg_card_brand', 'lastFour' => '_mcpg_last_four' ) as $key => $meta ) {
            if ( ! empty( $data[ $key ] ) ) {
                $order->update_meta_data( $meta, sanitize_text_field( $data[ $key ] ) );
            }
        }

        // Check for 3DS redirect in webhook
        $redirect_url = $data['result']['redirectUrl'] ?? $data['redirectUrl'] ?? '';
        if ( ! empty( $redirect_url ) ) {
            $order->update_meta_data( '_mcpg_vp3d_3ds_redirect_url', esc_url_raw( $redirect_url ) );
            $order->add_order_note( 'VP3D webhook: 3DS redirect received. TX: ' . $tx_id );
            $order->save();
            return true;
        }

        switch ( $status ) {
            case 'approved':
                $order->update_meta_data( '_mcpg_cascade_active', 'no' );
                $order->update_meta_data( '_mcpg_transaction_id', $tx_id );
                $order->update_meta_data( '_mcpg_payment_processor', 'vp3d' );
                $order->save();
                $order->payment_complete( $tx_id );
                wc_reduce_stock_levels( $order_id );
                $order->add_order_note( 'Cascade: VP3D approved via webhook. TX: ' . $tx_id );
                MCPG_Card_Store::destroy( $order_id );
                return true;

            case 'declined':
            case 'error':
                $error = $data['result']['errorDetail'] ?? $status;
                $order->add_order_note( 'VP3D webhook: ' . $status . ' — ' . $error );
                // Don't fail the order here — the cascade or 3DS return handler will decide
                $order->update_meta_data( '_mcpg_vp3d_webhook_status', $status );
                $order->save();
                return true;

            case 'pending':
                $order->add_order_note( 'VP3D webhook: pending. TX: ' . $tx_id );
                return true;

            default:
                return false;
        }
    }

    private static function process_vp3d_refund_webhook( $data, $debug, $logger, $ctx ) {
        $ref_tx = $data['refenceTransactionId'] ?? '';
        if ( ! $ref_tx ) return false;

        $orders = wc_get_orders( array(
            'limit'      => 1,
            'meta_key'   => '_mcpg_vp3d_transaction_id',
            'meta_value' => $ref_tx,
        ));

        if ( empty( $orders ) ) return false;

        $order  = $orders[0];
        $status = $data['result']['status'] ?? '';
        $amount = $data['amount'] ?? 0;

        if ( $status === 'approved' ) {
            $order->add_order_note( sprintf( 'VP3D refund approved via webhook. Amount: %s', wc_price( $amount ) ) );
        }

        return true;
    }

    private static function vp3d_response( $status, $description, $tx_id, $merchant_tx_id, $http_code ) {
        status_header( $http_code );
        header( 'Content-Type: application/json' );
        echo wp_json_encode( array(
            'status'                => $status,
            'description'           => $description,
            'transactionId'         => $tx_id,
            'merchantTransactionId' => $merchant_tx_id,
        ));
        exit;
    }

    /* ═══════════════════ VP3D 3DS RETURN ═══════════════════ */
    public static function handle_vp3d_3ds_return() {
        $settings = get_option( 'woocommerce_mcpg_cascading_settings', array() );
        $debug    = ( $settings['debug'] ?? 'yes' ) === 'yes';
        $logger   = $debug ? wc_get_logger() : null;
        $ctx      = array( 'source' => 'mcpg-3ds-return' );

        if ( $debug ) {
            $logger->debug( '=== MCPG 3DS RETURN ===', $ctx );
            $logger->debug( 'GET: ' . wp_json_encode( $_GET ), $ctx );
        }

        $order_id    = 0;
        $threed_result = null;

        // Try to resolve order ID
        if ( isset( $_GET['order_id'] ) ) {
            $order_id = absint( $_GET['order_id'] );
        } elseif ( isset( $_GET['externalReference'] ) ) {
            $order_id = absint( explode( '-', $_GET['externalReference'] )[0] );
        }

        // VSafe base64-encoded result parameter
        if ( isset( $_GET['result'] ) ) {
            $result_data = json_decode( base64_decode( $_GET['result'] ), true );
            if ( is_array( $result_data ) ) {
                $threed_result = $result_data;

                if ( ! $order_id && isset( $result_data['reference'] ) ) {
                    $orders = wc_get_orders( array(
                        'limit'      => 1,
                        'meta_key'   => '_mcpg_vp3d_transaction_id',
                        'meta_value' => sanitize_text_field( $result_data['reference'] ),
                    ));
                    if ( ! empty( $orders ) ) {
                        $order_id = $orders[0]->get_id();
                    }
                }
            }
        }

        if ( $debug ) $logger->debug( 'Resolved order: #' . $order_id, $ctx );

        if ( ! $order_id ) {
            wp_redirect( wc_get_checkout_url() );
            exit;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== 'mcpg_cascading' ) {
            wp_redirect( wc_get_checkout_url() );
            exit;
        }

        // Already completed
        if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
            wp_redirect( $order->get_checkout_order_received_url() );
            exit;
        }

        // Try to finalize via 3DS result
        $success = MCPG_Cascade_Engine::handle_3ds_result( $order_id, $threed_result );

        if ( $success ) {
            // Payment approved
            if ( $debug ) $logger->debug( 'Order completed via 3DS return', $ctx );
            wp_redirect( $order->get_checkout_order_received_url() );
            exit;
        }

        // 3DS failed — check if cascade can continue
        $cascade_active = $order->get_meta( '_mcpg_cascade_active' );
        $processors     = $order->get_meta( '_mcpg_cascade_processors' ) ?: array();
        $step           = (int) $order->get_meta( '_mcpg_cascade_step' );

        if ( $cascade_active === 'yes' && $step < count( $processors ) ) {
            // More processors — redirect back to cascade page
            if ( $debug ) $logger->debug( 'Continuing cascade after 3DS failure', $ctx );
            $cascade_url = add_query_arg( 'mcpg_cascade', '1', $order->get_checkout_order_received_url() );
            wp_redirect( $cascade_url );
            exit;
        }

        // All exhausted
        if ( $debug ) $logger->debug( 'All processors exhausted after 3DS', $ctx );
        wc_add_notice( 'We were unable to process your payment. Please try again or use a different card.', 'error' );
        wp_redirect( wc_get_checkout_url() );
        exit;
    }

    /* ═══════════════════ EP CALLBACK / RETURN ═══════════════════ */
    public static function handle_ep_endpoints() {
        global $wp_query;

        // EP Callback (processor sends JSON)
        if ( isset( $wp_query->query_vars['mcpg-ep-callback'] ) ) {
            $raw  = file_get_contents( 'php://input' );
            $data = json_decode( $raw, true );
            if ( empty( $data ) || ! is_array( $data ) ) {
                $data = $_POST;
            }

            if ( ! empty( $data ) ) {
                self::process_ep_callback( $data );
            }

            status_header( 200 );
            echo 'OK';
            exit;
        }

        // EP Return (customer redirect via GET)
        if ( isset( $wp_query->query_vars['mcpg-ep-return'] ) ) {
            self::process_ep_return( $_REQUEST );
            exit;
        }
    }

    private static function process_ep_callback( $data ) {
        $settings = get_option( 'woocommerce_mcpg_cascading_settings', array() );
        $debug    = ( $settings['debug'] ?? 'yes' ) === 'yes';
        $logger   = $debug ? wc_get_logger() : null;
        $ctx      = array( 'source' => 'mcpg-ep-callback' );

        if ( $debug ) $logger->debug( 'EP Callback: ' . wp_json_encode( $data ), $ctx );

        $order_id = isset( $data['resp_merchant_data1'] ) ? (int) $data['resp_merchant_data1'] : 0;
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== 'mcpg_cascading' ) return;
        if ( $order->has_status( array( 'processing', 'completed' ) ) ) return;

        // Verify order key
        if ( isset( $data['resp_merchant_data2'] ) && ! empty( $data['resp_merchant_data2'] ) ) {
            if ( substr( $order->get_order_key(), 0, 20 ) !== substr( $data['resp_merchant_data2'], 0, 20 ) ) {
                if ( $debug ) $logger->debug( 'Order key mismatch', $ctx );
                return;
            }
        }

        $passphrase = $settings['ep2d_account_passphrase'] ?? '';

        if ( ! MCPG_EProcessor_API::verify_response_sha( $passphrase, $data ) ) {
            if ( $debug ) $logger->debug( 'SHA verification failed', $ctx );
            return;
        }

        $parsed = MCPG_EProcessor_API::parse_transaction_status( $data );

        if ( $parsed['is_success'] ) {
            $order->update_meta_data( '_mcpg_cascade_active', 'no' );
            $order->update_meta_data( '_mcpg_transaction_id', $parsed['transaction_id'] );
            $order->update_meta_data( '_mcpg_payment_processor', 'ep2d' );
            $order->save();
            $order->payment_complete( $parsed['transaction_id'] );
            $order->add_order_note( 'Cascade: EP2D approved via callback. TX: ' . $parsed['transaction_id'] );
            MCPG_Card_Store::destroy( $order_id );
        } elseif ( $parsed['is_pending'] ) {
            $order->update_status( 'on-hold', 'EP2D callback: pending. TX: ' . $parsed['transaction_id'] );
            $order->save();
        } else {
            $order->add_order_note( 'EP2D callback: failed — ' . $parsed['description'] );
            $order->save();
        }
    }

    private static function process_ep_return( $data ) {
        $order_id = isset( $data['order_id'] ) ? (int) $data['order_id'] : 0;
        if ( ! $order_id ) {
            wp_redirect( wc_get_page_permalink( 'cart' ) );
            exit;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_redirect( wc_get_page_permalink( 'cart' ) );
            exit;
        }

        // Already finalized
        if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
            wp_redirect( $order->get_checkout_order_received_url() );
            exit;
        }

        // Try to process return data
        if ( isset( $data['resp_trans_status'] ) ) {
            $settings   = get_option( 'woocommerce_mcpg_cascading_settings', array() );
            $passphrase = $settings['ep2d_account_passphrase'] ?? '';

            if ( MCPG_EProcessor_API::verify_response_sha( $passphrase, $data ) ) {
                $parsed = MCPG_EProcessor_API::parse_transaction_status( $data );
                if ( $parsed['is_success'] ) {
                    $order->update_meta_data( '_mcpg_cascade_active', 'no' );
                    $order->update_meta_data( '_mcpg_transaction_id', $parsed['transaction_id'] );
                    $order->update_meta_data( '_mcpg_payment_processor', 'ep2d' );
                    $order->save();
                    $order->payment_complete( $parsed['transaction_id'] );
                    $order->add_order_note( 'Cascade: EP2D approved via return. TX: ' . $parsed['transaction_id'] );
                    MCPG_Card_Store::destroy( $order_id );
                }
            }
        }

        if ( $order->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) {
            wp_redirect( $order->get_checkout_order_received_url() );
        } else {
            wc_add_notice( 'Payment was not completed. Please try again.', 'error' );
            wp_redirect( wc_get_checkout_url() );
        }
        exit;
    }
}
