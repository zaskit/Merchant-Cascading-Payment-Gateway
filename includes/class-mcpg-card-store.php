<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Secure temporary card data storage for cascade processing.
 *
 * Card data is AES-256-CBC encrypted in a WordPress transient (5 min TTL).
 * The decryption key is stored in order meta and deleted after cascade completes.
 * Card data never touches permanent storage.
 */
class MCPG_Card_Store {

    const CIPHER = 'aes-256-cbc';
    const TTL    = 300; // 5 minutes

    /**
     * Encrypt and store card data for an order's cascade.
     */
    public static function store( $order_id, array $card_data ) {
        $key_hex = bin2hex( random_bytes( 32 ) );
        $iv      = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );

        $encrypted = openssl_encrypt(
            wp_json_encode( $card_data ),
            self::CIPHER,
            hex2bin( $key_hex ),
            OPENSSL_RAW_DATA,
            $iv
        );

        if ( $encrypted === false ) {
            return false;
        }

        // Store encrypted blob in transient (short-lived, auto-expires)
        $blob = base64_encode( $iv . $encrypted );
        set_transient( 'mcpg_card_' . $order_id, $blob, self::TTL );

        // Store key in order meta (will be cleaned up after cascade)
        $order = wc_get_order( $order_id );
        $order->update_meta_data( '_mcpg_card_key', $key_hex );
        $order->save();

        return true;
    }

    /**
     * Retrieve and decrypt card data for an order.
     */
    public static function retrieve( $order_id ) {
        $blob = get_transient( 'mcpg_card_' . $order_id );
        if ( ! $blob ) return false;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return false;

        $key_hex = $order->get_meta( '_mcpg_card_key' );
        if ( ! $key_hex ) return false;

        $decoded  = base64_decode( $blob );
        $iv_len   = openssl_cipher_iv_length( self::CIPHER );
        $iv       = substr( $decoded, 0, $iv_len );
        $encrypted = substr( $decoded, $iv_len );

        $decrypted = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            hex2bin( $key_hex ),
            OPENSSL_RAW_DATA,
            $iv
        );

        if ( $decrypted === false ) return false;

        return json_decode( $decrypted, true );
    }

    /**
     * Destroy card data immediately after cascade completes.
     */
    public static function destroy( $order_id ) {
        delete_transient( 'mcpg_card_' . $order_id );

        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->delete_meta_data( '_mcpg_card_key' );
            $order->save();
        }
    }
}
