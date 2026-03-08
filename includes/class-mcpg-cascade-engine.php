<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cascade Engine — orchestrates payment attempts across processors.
 *
 * Default order: VP2D -> EP2D -> VP3D
 * Each processor is tried in sequence; on failure the next is attempted.
 * VP3D may return a 3DS redirect which pauses the cascade for customer interaction.
 */
class MCPG_Cascade_Engine {

    private static $logger;

    private static function logger() {
        if ( ! self::$logger ) {
            $settings = get_option( 'woocommerce_mcpg_cascading_settings', array() );
            $debug    = ( $settings['debug'] ?? 'yes' ) === 'yes';
            self::$logger = new MCPG_Logger( $debug, 'mcpg-cascade' );
        }
        return self::$logger;
    }

    /**
     * Get gateway settings.
     */
    public static function settings() {
        return get_option( 'woocommerce_mcpg_cascading_settings', array() );
    }

    /**
     * Get the enabled cascade order.
     */
    public static function get_cascade_order() {
        $settings = self::settings();
        $order    = $settings['cascade_order'] ?? 'vp2d,ep2d,vp3d';
        $all      = array_map( 'trim', explode( ',', $order ) );

        // Filter to only enabled processors
        return array_values( array_filter( $all, function ( $id ) use ( $settings ) {
            return ( $settings[ $id . '_enabled' ] ?? 'no' ) === 'yes';
        }));
    }

    /**
     * Get processor display name (for order notes — not shown to customer).
     */
    public static function processor_name( $id ) {
        $names = array(
            'vp2d' => 'V-Processor 2D',
            'ep2d' => 'E-Processor 2D',
            'vp3d' => 'V-Processor 3D',
        );
        return $names[ $id ] ?? $id;
    }

    /**
     * Initialize cascade state on an order.
     */
    public static function init_cascade( $order_id ) {
        $order      = wc_get_order( $order_id );
        $processors = self::get_cascade_order();

        $order->update_meta_data( '_mcpg_cascade_active', 'yes' );
        $order->update_meta_data( '_mcpg_cascade_processors', $processors );
        $order->update_meta_data( '_mcpg_cascade_step', 0 );
        $order->update_meta_data( '_mcpg_cascade_results', array() );
        $order->save();

        self::logger()->log( '=== CASCADE INIT === Order #' . $order_id . ' Processors: ' . implode( ', ', $processors ) );

        return $processors;
    }

    /**
     * Process the next step in the cascade.
     *
     * @return array Result with keys: status, processor, message, redirect_url, step, total_steps
     */
    public static function process_step( $order_id ) {
        $order      = wc_get_order( $order_id );

        // Clear object cache to get fresh order data (webhook may have updated it)
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( 'order-' . $order_id, 'orders' );
            wp_cache_delete( $order_id, 'posts' );
        }
        $order = wc_get_order( $order_id );

        // If order was finalized by a webhook while we were waiting, return success
        if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
            MCPG_Card_Store::destroy( $order_id );
            return array(
                'status'  => 'approved',
                'message' => 'Payment confirmed',
                'step'    => (int) $order->get_meta( '_mcpg_cascade_step' ),
                'total'   => count( $order->get_meta( '_mcpg_cascade_processors' ) ?: array() ),
            );
        }

        // If we're awaiting a webhook, don't re-attempt — check for 3DS redirect or report pending
        $awaiting_webhook = $order->get_meta( '_mcpg_cascade_awaiting_webhook' );
        if ( $awaiting_webhook === 'yes' ) {
            $processors = $order->get_meta( '_mcpg_cascade_processors' ) ?: array();
            $step       = (int) $order->get_meta( '_mcpg_cascade_step' );
            $total      = count( $processors );

            // Check if webhook delivered a 3DS redirect URL
            $redirect_url = $order->get_meta( '_mcpg_vp3d_3ds_redirect_url' );
            if ( ! empty( $redirect_url ) ) {
                self::logger()->log( 'VP3D 3DS redirect URL found — sending to browser: ' . $redirect_url );
                // Clear it so we don't redirect again on return
                $order->delete_meta_data( '_mcpg_vp3d_3ds_redirect_url' );
                $order->update_meta_data( '_mcpg_cascade_awaiting_webhook', 'no' );
                $order->update_meta_data( '_mcpg_cascade_awaiting_3ds', 'yes' );
                $order->save();
                return array(
                    'status'       => '3ds_redirect',
                    'redirect_url' => $redirect_url,
                    'message'      => '3D Secure verification required',
                    'step'         => $step,
                    'total'        => $total,
                );
            }

            return array(
                'status'  => 'pending',
                'message' => 'Awaiting payment confirmation',
                'step'    => $step,
                'total'   => $total,
            );
        }

        $processors = $order->get_meta( '_mcpg_cascade_processors' );
        $step       = (int) $order->get_meta( '_mcpg_cascade_step' );
        $results    = $order->get_meta( '_mcpg_cascade_results' ) ?: array();

        if ( ! is_array( $processors ) || $step >= count( $processors ) ) {
            return array(
                'status'  => 'exhausted',
                'message' => 'All payment routes have been attempted.',
                'step'    => $step,
                'total'   => is_array( $processors ) ? count( $processors ) : 0,
            );
        }

        $processor_id = $processors[ $step ];
        $settings     = self::settings();

        self::logger()->log( '=== CASCADE STEP ' . ( $step + 1 ) . '/' . count( $processors ) . ' === Processor: ' . $processor_id . ' Order #' . $order_id );

        // Retrieve encrypted card data
        $card_data = MCPG_Card_Store::retrieve( $order_id );
        if ( ! $card_data ) {
            self::logger()->log( 'ERROR: Card data expired or unavailable' );
            return array(
                'status'  => 'error',
                'message' => 'Session expired. Please try again.',
                'step'    => $step,
                'total'   => count( $processors ),
            );
        }

        // Attempt payment with the current processor
        $result = self::attempt( $processor_id, $order, $card_data, $settings );
        $result['processor'] = $processor_id;
        $result['step']      = $step;
        $result['total']     = count( $processors );

        // Store result
        $results[] = array(
            'processor'  => $processor_id,
            'status'     => $result['status'],
            'error_code' => $result['error_code'] ?? '',
            'message'    => $result['message'] ?? '',
            'timestamp'  => current_time( 'mysql' ),
        );
        $order->update_meta_data( '_mcpg_cascade_results', $results );

        if ( $result['status'] === 'approved' ) {
            // Success — clean up
            $order->update_meta_data( '_mcpg_cascade_active', 'no' );
            $order->update_meta_data( '_mcpg_cascade_success_processor', $processor_id );
            $order->save();
            MCPG_Card_Store::destroy( $order_id );
            self::logger()->log( '=== CASCADE SUCCESS === via ' . $processor_id );
        } elseif ( $result['status'] === '3ds_redirect' ) {
            // VP3D needs 3DS — pause cascade
            $order->update_meta_data( '_mcpg_cascade_awaiting_3ds', 'yes' );
            $order->save();
            self::logger()->log( 'CASCADE PAUSED — awaiting 3DS for ' . $processor_id );
        } elseif ( $result['status'] === 'pending' ) {
            // Awaiting webhook confirmation — do NOT advance step
            $order->update_meta_data( '_mcpg_cascade_awaiting_webhook', 'yes' );
            $order->save();
            self::logger()->log( 'CASCADE PAUSED — awaiting webhook for ' . $processor_id );
        } else {
            // Failed — advance to next step
            $next_step = $step + 1;
            $order->update_meta_data( '_mcpg_cascade_step', $next_step );
            $order->save();

            $order->add_order_note( sprintf(
                'Cascade: %s failed [%s] %s',
                self::processor_name( $processor_id ),
                $result['error_code'] ?? '',
                $result['message'] ?? ''
            ) );

            self::logger()->log( 'Step failed: ' . ( $result['error_code'] ?? '' ) . ' — ' . ( $result['message'] ?? '' ) );

            if ( $next_step >= count( $processors ) ) {
                // All exhausted
                $result['status'] = 'exhausted';
                $order->update_meta_data( '_mcpg_cascade_active', 'no' );
                $order->update_status( 'on-hold', 'All payment routes exhausted. Customer may retry.' );
                $order->save();
                MCPG_Card_Store::destroy( $order_id );
                self::logger()->log( '=== CASCADE EXHAUSTED === All processors failed' );
            }
        }

        return $result;
    }

    /**
     * Swap card data with test card if processor is in sandbox and test card is configured.
     */
    private static function maybe_swap_test_card( $processor_id, $card_data, $settings ) {
        // Determine if processor is in sandbox mode
        $is_sandbox = false;
        switch ( $processor_id ) {
            case 'vp2d':
                $is_sandbox = ( $settings['vp2d_environment'] ?? 'sandbox' ) === 'sandbox';
                break;
            case 'ep2d':
                $is_sandbox = ( $settings['ep2d_environment'] ?? 'sandbox' ) === 'sandbox';
                break;
            case 'vp3d':
                $is_sandbox = ( $settings['vp3d_testmode'] ?? 'yes' ) === 'yes';
                break;
        }

        if ( ! $is_sandbox ) return $card_data;

        $test_number = $settings[ $processor_id . '_test_card_number' ] ?? '';
        if ( empty( $test_number ) ) return $card_data;

        // Parse test expiry (MM/YY or MM/YYYY)
        $test_expiry = $settings[ $processor_id . '_test_card_expiry' ] ?? '';
        if ( ! empty( $test_expiry ) && strpos( $test_expiry, '/' ) !== false ) {
            $parts = explode( '/', $test_expiry );
            $card_data['exp_month'] = (int) trim( $parts[0] );
            $exp_y = trim( $parts[1] );
            // Store as 2-digit; each processor's attempt function handles 4-digit conversion if needed
            $card_data['exp_year'] = strlen( $exp_y ) > 2 ? (int) substr( $exp_y, -2 ) : (int) $exp_y;
        }

        $card_data['number'] = preg_replace( '/\s+/', '', $test_number );

        $test_cvv = $settings[ $processor_id . '_test_card_cvv' ] ?? '';
        if ( ! empty( $test_cvv ) ) $card_data['cvv'] = $test_cvv;

        $test_name = $settings[ $processor_id . '_test_card_name' ] ?? '';
        if ( ! empty( $test_name ) ) $card_data['name'] = $test_name;

        self::logger()->log( 'TEST CARD SWAP for ' . $processor_id . ': using test card ****' . substr( $card_data['number'], -4 ) );

        return $card_data;
    }

    /**
     * Attempt payment with a specific processor.
     *
     * @return array ['status' => approved|failed|3ds_redirect|error, 'message' => '...', ...]
     */
    private static function attempt( $processor_id, $order, $card_data, $settings ) {
        // Swap in test card data if in sandbox mode
        $card_data = self::maybe_swap_test_card( $processor_id, $card_data, $settings );
        switch ( $processor_id ) {
            case 'vp2d':
                return self::attempt_vp2d( $order, $card_data, $settings );
            case 'ep2d':
                return self::attempt_ep2d( $order, $card_data, $settings );
            case 'vp3d':
                return self::attempt_vp3d( $order, $card_data, $settings );
            default:
                return array( 'status' => 'error', 'message' => 'Unknown processor: ' . $processor_id );
        }
    }

    /* ─────────────────── VP2D ─────────────────── */
    private static function attempt_vp2d( $order, $card_data, $settings ) {
        $merchant_id = $settings['vp2d_merchant_id'] ?? '';
        $api_key     = $settings['vp2d_api_key'] ?? '';
        $environment = $settings['vp2d_environment'] ?? 'sandbox';
        $order_id    = $order->get_id();

        if ( empty( $merchant_id ) || empty( $api_key ) ) {
            return array( 'status' => 'failed', 'message' => 'VP2D not configured', 'error_code' => 'NO_CONFIG' );
        }

        $attempt_ref = $order_id . '-vp2d-' . substr( md5( wp_generate_password( 12, false ) ), 0, 6 );
        $order->update_meta_data( '_mcpg_vp2d_external_ref', $attempt_ref );
        $order->save();

        $endpoint = MCPG_VProcessor_API::endpoint( $environment, 'charges' );

        $body = array(
            'serviceSecurity' => array( 'merchantId' => (int) $merchant_id ),
            'transactionDetails' => array(
                'amount'            => (float) $order->get_total(),
                'currency'          => strtoupper( $order->get_currency() ),
                'externalReference' => $attempt_ref,
                'custom1'           => 'MCPG-Cascade',
            ),
            'cardDetails' => array(
                'cardHolderName'  => $card_data['name'],
                'cardNumber'      => $card_data['number'],
                'cvv'             => $card_data['cvv'],
                'expirationMonth' => (int) $card_data['exp_month'],
                'expirationYear'  => (int) $card_data['exp_year'],
            ),
            'payerDetails' => array(
                'username'  => sanitize_user( $order->get_billing_email(), true ),
                'firstName' => $order->get_billing_first_name(),
                'lastName'  => $order->get_billing_last_name(),
                'email'     => $order->get_billing_email(),
                'phone'     => preg_replace( '/\D/', '', $order->get_billing_phone() ),
                'address'   => array(
                    'street'  => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
                    'city'    => $order->get_billing_city(),
                    'state'   => $order->get_billing_state(),
                    'country' => $order->get_billing_country(),
                    'zipCode' => substr( $order->get_billing_postcode(), 0, 9 ),
                ),
            ),
        );

        self::logger()->log( 'VP2D Request to: ' . $endpoint );
        self::logger()->log( 'VP2D Card (masked): ' . substr( $card_data['number'], 0, 6 ) . '****' . substr( $card_data['number'], -4 ) );

        $response = MCPG_VProcessor_API::post( $endpoint, $api_key, $body );

        if ( is_wp_error( $response ) ) {
            return array( 'status' => 'failed', 'message' => 'Connection error: ' . $response->get_error_message(), 'error_code' => 'HTTP_ERROR' );
        }

        $result = json_decode( wp_remote_retrieve_body( $response ), true );

        self::logger()->log( 'VP2D Response: ' . wp_remote_retrieve_body( $response ) );

        if ( ! is_array( $result ) || ! isset( $result['result']['status'] ) ) {
            return array( 'status' => 'failed', 'message' => 'Invalid API response', 'error_code' => 'INVALID_RESPONSE' );
        }

        if ( $result['result']['status'] === 'approved' ) {
            $tx_id = $result['transactionId'] ?? '';
            $order->payment_complete( $tx_id );
            $order->update_meta_data( '_mcpg_transaction_id', $tx_id );
            $order->update_meta_data( '_mcpg_payment_processor', 'vp2d' );
            $order->add_order_note( 'Cascade: V-Processor 2D payment approved. TX: ' . $tx_id );
            $order->save();

            return array(
                'status'         => 'approved',
                'message'        => 'Payment approved',
                'transaction_id' => $tx_id,
            );
        }

        $error_code   = $result['result']['errorCode'] ?? '';
        $error_detail = $result['result']['errorDetail'] ?? 'Payment declined';

        return array(
            'status'     => 'failed',
            'message'    => MCPG_VProcessor_API::friendly_error( $error_code ),
            'error_code' => $error_code,
            'raw_error'  => $error_detail,
        );
    }

    /* ─────────────────── EP2D ─────────────────── */
    private static function attempt_ep2d( $order, $card_data, $settings ) {
        $account_id         = $settings['ep2d_account_id'] ?? '';
        $account_password   = $settings['ep2d_account_password'] ?? '';
        $account_passphrase = $settings['ep2d_account_passphrase'] ?? '';
        $account_gateway    = $settings['ep2d_account_gateway'] ?? '1';
        $transaction_prefix = $settings['ep2d_transaction_prefix'] ?? 'MCPG-';
        $order_id           = $order->get_id();

        if ( empty( $account_id ) || empty( $account_passphrase ) ) {
            return array( 'status' => 'failed', 'message' => 'EP2D not configured', 'error_code' => 'NO_CONFIG' );
        }

        // Idempotent payment ID
        $attempt    = (int) $order->get_meta( '_mcpg_ep_attempt' ) + 1;
        $payment_id = $transaction_prefix . $order_id . '-' . $attempt;
        $order->update_meta_data( '_mcpg_ep_attempt', $attempt );
        $order->update_meta_data( '_mcpg_ep_merchant_payment_id', $payment_id );
        $order->save();

        $amount = number_format( (float) $order->get_total(), 2, '.', '' );

        // Build EP2D expiry (MM and YYYY)
        $exp_month = str_pad( (string) $card_data['exp_month'], 2, '0', STR_PAD_LEFT );
        $exp_year  = (string) $card_data['exp_year'];
        if ( strlen( $exp_year ) <= 2 ) $exp_year = '20' . str_pad( $exp_year, 2, '0', STR_PAD_LEFT );

        $data = array(
            'account_id'              => $account_id,
            'account_password'        => $account_password,
            'action_type'             => 'payment',
            'account_gateway'         => $account_gateway,
            'merchant_payment_id'     => $payment_id,
            'cust_email'              => $order->get_billing_email(),
            'cust_billing_last_name'  => $order->get_billing_last_name(),
            'cust_billing_first_name' => $order->get_billing_first_name(),
            'cust_billing_address'    => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
            'cust_billing_city'       => $order->get_billing_city(),
            'cust_billing_zipcode'    => $order->get_billing_postcode(),
            'cust_billing_state'      => $order->get_billing_state() ?: 'NA',
            'cust_billing_country'    => $order->get_billing_country(),
            'cust_billing_phone'      => preg_replace( '/\D/', '', $order->get_billing_phone() ),
            'transac_products_name'   => MCPG_EProcessor_API::get_order_items_string( $order ),
            'transac_amount'          => $amount,
            'transac_currency_code'   => $order->get_currency(),
            'customer_ip'             => $order->get_customer_ip_address(),
            'merchant_url_return'     => home_url( 'mcpg-ep-return' ) . '?order_id=' . $order_id,
            'merchant_url_callback'   => home_url( 'mcpg-ep-callback' ),
            'merchant_data1'          => (string) $order_id,
            'merchant_data2'          => substr( $order->get_order_key(), 0, 20 ),
            'option'                  => '',
            'transac_cc_number'       => $card_data['number'],
            'transac_cc_month'        => $exp_month,
            'transac_cc_year'         => $exp_year,
            'transac_cc_cvc'          => $card_data['cvv'],
        );

        if ( $order->has_shipping_address() ) {
            $data['cust_shipping_last_name']  = $order->get_shipping_last_name();
            $data['cust_shipping_first_name'] = $order->get_shipping_first_name();
            $data['cust_shipping_address']    = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
            $data['cust_shipping_city']       = $order->get_shipping_city();
            $data['cust_shipping_zipcode']    = $order->get_shipping_postcode();
            $data['cust_shipping_state']      = $order->get_shipping_state() ?: 'NA';
            $data['cust_shipping_country']    = $order->get_shipping_country();
            $data['cust_shipping_phone']      = preg_replace( '/\D/', '', $order->get_billing_phone() );
        }

        // SHA with card
        $data['account_sha'] = MCPG_EProcessor_API::sha_with_card(
            $account_passphrase,
            $amount,
            $account_id,
            $data['cust_email'],
            $card_data['number'],
            $data['customer_ip']
        );

        self::logger()->log( 'EP2D Request: payment_id=' . $payment_id . ', amount=' . $amount );

        $response = MCPG_EProcessor_API::post( MCPG_EProcessor_API::PROCESS_URL, $data );
        $result   = MCPG_EProcessor_API::parse_response( $response );

        if ( ! $result ) {
            return array( 'status' => 'failed', 'message' => 'No response from payment gateway', 'error_code' => 'NO_RESPONSE' );
        }

        self::logger()->log( 'EP2D Response: ' . wp_json_encode( $result ) );

        // Verify SHA
        if ( isset( $result['resp_trans_status'] ) && ! MCPG_EProcessor_API::verify_response_sha( $account_passphrase, $result ) ) {
            return array( 'status' => 'failed', 'message' => 'Response verification failed', 'error_code' => 'SHA_FAIL' );
        }

        if ( isset( $result['resp_trans_status'] ) ) {
            $parsed = MCPG_EProcessor_API::parse_transaction_status( $result );

            if ( $parsed['is_success'] ) {
                $order->update_meta_data( '_mcpg_transaction_id', $parsed['transaction_id'] );
                $order->update_meta_data( '_mcpg_payment_processor', 'ep2d' );
                $order->save();
                $order->payment_complete( $parsed['transaction_id'] );
                $order->add_order_note( 'Cascade: E-Processor 2D payment approved. TX: ' . $parsed['transaction_id'] );

                return array(
                    'status'         => 'approved',
                    'message'        => 'Payment approved',
                    'transaction_id' => $parsed['transaction_id'],
                );
            }

            if ( $parsed['is_pending'] ) {
                $order->update_meta_data( '_mcpg_transaction_id', $parsed['transaction_id'] );
                $order->update_meta_data( '_mcpg_payment_processor', 'ep2d' );
                $order->update_status( 'on-hold', 'EP2D: Payment pending. TX: ' . $parsed['transaction_id'] );
                $order->save();

                return array(
                    'status'         => 'approved', // Treat pending as success for cascade
                    'message'        => 'Payment processing',
                    'transaction_id' => $parsed['transaction_id'],
                );
            }

            return array(
                'status'     => 'failed',
                'message'    => $parsed['description'] ?: 'Payment declined',
                'error_code' => $parsed['status'],
            );
        }

        return array( 'status' => 'failed', 'message' => 'Invalid gateway response', 'error_code' => 'INVALID_RESPONSE' );
    }

    /* ─────────────────── VP3D ─────────────────── */
    private static function attempt_vp3d( $order, $card_data, $settings ) {
        $testmode    = ( $settings['vp3d_testmode'] ?? 'yes' ) === 'yes';
        $merchant_id = $testmode ? ( $settings['vp3d_test_merchant_id'] ?? '' ) : ( $settings['vp3d_live_merchant_id'] ?? '' );
        $api_token   = $testmode ? ( $settings['vp3d_test_api_token'] ?? '' ) : ( $settings['vp3d_live_api_token'] ?? '' );
        $order_id    = $order->get_id();

        if ( empty( $merchant_id ) || empty( $api_token ) ) {
            return array( 'status' => 'failed', 'message' => 'VP3D not configured', 'error_code' => 'NO_CONFIG' );
        }

        $attempt_ref = $order_id . '-vp3d-' . substr( md5( wp_generate_password( 12, false ) ), 0, 6 );
        $order->update_meta_data( '_mcpg_vp3d_external_ref', $attempt_ref );
        $order->save();

        $env = $testmode ? 'sandbox' : 'live';
        $url = MCPG_VProcessor_API::endpoint( $env, 'charges', '2' );

        $body = array(
            'serviceSecurity' => array( 'merchantId' => (int) $merchant_id ),
            'transactionDetails' => array(
                'amount'            => (float) $order->get_total(),
                'currency'          => $order->get_currency(),
                'externalReference' => $attempt_ref,
            ),
            'cardDetails' => array(
                'cardHolderName'  => $card_data['name'],
                'cardNumber'      => $card_data['number'],
                'cvv'             => (int) $card_data['cvv'],
                'expirationMonth' => sprintf( '%02d', $card_data['exp_month'] ),
                'expirationYear'  => (int) $card_data['exp_year'],
            ),
            'payerDetails' => array(
                'firstName' => $order->get_billing_first_name(),
                'lastName'  => $order->get_billing_last_name(),
                'email'     => $order->get_billing_email(),
                'phone'     => preg_replace( '/\D/', '', $order->get_billing_phone() ),
                'address'   => array(
                    'street'  => $order->get_billing_address_1(),
                    'city'    => $order->get_billing_city(),
                    'state'   => $order->get_billing_state(),
                    'country' => $order->get_billing_country(),
                    'zipCode' => $order->get_billing_postcode(),
                ),
            ),
        );

        self::logger()->log( 'VP3D Request to: ' . $url );

        $json     = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $sig      = MCPG_VProcessor_API::sign( $api_token, $json );
        $response = wp_remote_post( $url, array(
            'headers' => array( 'Content-Type' => 'application/json', 'Signature' => $sig ),
            'body'    => $json,
            'timeout' => 70,
        ));

        if ( is_wp_error( $response ) ) {
            return array( 'status' => 'failed', 'message' => 'Connection error', 'error_code' => 'HTTP_ERROR' );
        }

        $result = json_decode( wp_remote_retrieve_body( $response ), true );

        self::logger()->log( 'VP3D Response: ' . wp_remote_retrieve_body( $response ) );

        if ( ! is_array( $result ) || ! isset( $result['result']['status'] ) ) {
            return array( 'status' => 'failed', 'message' => 'Invalid API response', 'error_code' => 'INVALID_RESPONSE' );
        }

        $status = $result['result']['status'];
        $tx_id  = $result['transactionId'] ?? '';

        if ( $tx_id ) {
            $order->update_meta_data( '_mcpg_vp3d_transaction_id', $tx_id );
            $order->save();
        }

        if ( $status === 'approved' ) {
            $order->update_meta_data( '_mcpg_transaction_id', $tx_id );
            $order->update_meta_data( '_mcpg_payment_processor', 'vp3d' );
            $order->save();
            $order->payment_complete( $tx_id );
            wc_reduce_stock_levels( $order_id );
            $order->add_order_note( 'Cascade: V-Processor 3D payment approved (direct). TX: ' . $tx_id );

            return array(
                'status'         => 'approved',
                'message'        => 'Payment approved',
                'transaction_id' => $tx_id,
            );
        }

        if ( $status === 'pending' ) {
            $redirect_url = $result['result']['redirectUrl'] ?? $result['redirectUrl'] ?? '';

            if ( ! empty( $redirect_url ) ) {
                $order->update_meta_data( '_mcpg_payment_processor', 'vp3d' );
                $order->update_status( 'pending', 'Cascade: VP3D — redirecting to 3DS authentication.' );
                $order->save();

                return array(
                    'status'       => '3ds_redirect',
                    'message'      => 'Redirecting to card verification',
                    'redirect_url' => $redirect_url,
                );
            }

            // Pending without redirect — set up webhook waiting
            $order->update_meta_data( '_mcpg_payment_processor', 'vp3d' );
            $order->update_meta_data( '_mcpg_vp3d_awaiting_webhook', 'yes' );
            $order->update_status( 'pending', 'Cascade: VP3D — awaiting webhook confirmation.' );
            $order->save();

            return array(
                'status'  => 'pending',
                'message' => 'Awaiting payment confirmation',
            );
        }

        // Failed
        $error_code   = $result['result']['errorCode'] ?? '';
        $error_detail = $result['result']['errorDetail'] ?? 'Payment declined';

        return array(
            'status'     => 'failed',
            'message'    => MCPG_VProcessor_API::friendly_error( $error_code ),
            'error_code' => $error_code,
            'raw_error'  => $error_detail,
        );
    }

    /**
     * Handle VP3D 3DS return — called after customer completes 3DS challenge.
     * Returns true if payment was finalized, false if cascade should continue.
     */
    public static function handle_3ds_result( $order_id, $threed_result ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return false;

        $settings  = self::settings();
        $testmode  = ( $settings['vp3d_testmode'] ?? 'yes' ) === 'yes';
        $api_token = $testmode ? ( $settings['vp3d_test_api_token'] ?? '' ) : ( $settings['vp3d_live_api_token'] ?? '' );

        if ( $threed_result && isset( $threed_result['status'] ) ) {
            $status = strtoupper( $threed_result['status'] );
            $tx_id  = $order->get_meta( '_mcpg_vp3d_transaction_id' );

            self::logger()->log( '3DS result for order #' . $order_id . ': ' . $status );

            if ( $status === 'APPROVED' ) {
                $order->update_meta_data( '_mcpg_cascade_active', 'no' );
                $order->update_meta_data( '_mcpg_cascade_awaiting_3ds', 'no' );
                $order->update_meta_data( '_mcpg_cascade_awaiting_webhook', 'no' );
                $order->delete_meta_data( '_mcpg_vp3d_3ds_redirect_url' );
                $order->update_meta_data( '_mcpg_transaction_id', $tx_id );
                $order->save();
                $order->payment_complete( $tx_id );
                wc_reduce_stock_levels( $order_id );
                $order->add_order_note( 'Cascade: VP3D payment approved after 3DS. TX: ' . $tx_id );

                MCPG_Card_Store::destroy( $order_id );
                return true;
            }
        }

        // 3DS failed — check if more processors remain
        $processors = $order->get_meta( '_mcpg_cascade_processors' ) ?: array();
        $step       = (int) $order->get_meta( '_mcpg_cascade_step' );

        $order->update_meta_data( '_mcpg_cascade_awaiting_3ds', 'no' );
        $order->update_meta_data( '_mcpg_cascade_awaiting_webhook', 'no' );
        $order->delete_meta_data( '_mcpg_vp3d_3ds_redirect_url' );

        // Advance past the VP3D step
        $next_step = $step + 1;
        $order->update_meta_data( '_mcpg_cascade_step', $next_step );
        $order->save();

        if ( $next_step >= count( $processors ) ) {
            // All exhausted
            $order->update_meta_data( '_mcpg_cascade_active', 'no' );
            $order->update_status( 'on-hold', 'All payment routes exhausted after 3DS failure.' );
            $order->save();
            MCPG_Card_Store::destroy( $order_id );
            return false;
        }

        // More processors to try — continue cascade
        return false;
    }
}
