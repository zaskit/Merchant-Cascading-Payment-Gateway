<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MCPG_EProcessor_API {

    const PROCESS_URL = 'https://ts.secure1gateway.com/api/v2/processTx';
    const REFUND_URL  = 'https://ts.secure1gateway.com/api/v2/processRefund';
    const STATUS_URL  = 'https://ts.secure1gateway.com/api/v2/processTxGetStatus';

    public static function sha_with_card( $passphrase, $amount, $account_id, $email, $card_number, $customer_ip ) {
        return hash( 'sha256', $passphrase . $amount . $account_id . $email . $card_number . $customer_ip );
    }

    public static function sha_without_card( $passphrase, $amount, $account_id, $email, $customer_ip ) {
        return hash( 'sha256', $passphrase . $amount . $account_id . $email . $customer_ip );
    }

    public static function sha_refund( $passphrase, $account_id, $transaction_id ) {
        return hash( 'sha256', $passphrase . $account_id . $transaction_id );
    }

    public static function verify_response_sha( $passphrase, $response ) {
        if ( ! isset( $response['resp_sha'] ) ) return false;
        $expected = hash( 'sha256',
            $passphrase .
            ( $response['resp_trans_id'] ?? '' ) .
            ( $response['resp_trans_amount'] ?? '' ) .
            ( $response['resp_trans_status'] ?? '' )
        );
        return hash_equals( $expected, $response['resp_sha'] );
    }

    public static function post( $url, $data, $timeout = 95 ) {
        return wp_remote_post( $url, array(
            'method'      => 'POST',
            'timeout'     => $timeout,
            'httpversion' => '1.1',
            'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'body'        => $data,
            'sslverify'   => true,
        ));
    }

    public static function parse_response( $response ) {
        if ( is_wp_error( $response ) ) return false;
        $body   = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) return false;
        if ( isset( $result['success'] ) ) {
            if ( $result['success'] === true && isset( $result['data'] ) ) return $result['data'];
            if ( $result['success'] === false ) return false;
        }
        return $result;
    }

    public static function get_order_items_string( $order ) {
        $items = array();
        foreach ( $order->get_items() as $item ) {
            $items[] = $item->get_name() . ' x ' . $item->get_quantity();
        }
        return implode( ', ', $items );
    }

    public static function parse_transaction_status( $response ) {
        $status = $response['resp_trans_status'] ?? '';
        return array(
            'status'         => $status,
            'transaction_id' => $response['resp_trans_id'] ?? '',
            'description'    => $response['resp_trans_description_status'] ?? '',
            'is_success'     => ( $status === '00000' ),
            'is_pending'     => ( $status === 'PEND' ),
            'is_failed'      => ( $status !== '00000' && $status !== 'PEND' ),
        );
    }
}
