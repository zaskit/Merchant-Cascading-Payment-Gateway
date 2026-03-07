<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MCPG_VProcessor_API {

    public static function endpoint( $env, $type, $version = '1' ) {
        $base = ( $env === 'live' ) ? 'https://vsafe.tech' : 'https://sandbox.vsafe.tech';
        return $base . '/api/v' . $version . '/' . $type . '/';
    }

    public static function sign( $key, $json ) {
        return hash( 'sha256', $key . $json . $key );
    }

    public static function post( $url, $key, $body, $timeout = 70 ) {
        $json = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return wp_remote_post( $url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Signature'    => self::sign( $key, $json ),
            ),
            'body'    => $json,
            'timeout' => $timeout,
        ));
    }

    /**
     * Map vSafe error codes to user-friendly messages.
     */
    public static function friendly_error( $code ) {
        $code = (string) $code;

        $map = array(
            '1050' => 'A security error occurred. Please try again or contact support.',
            '1051' => 'A security error occurred. Please try again or contact support.',
            '1060' => 'A security error occurred. Please try again or contact support.',
            '1052' => 'This payment method is temporarily unavailable.',
            '1053' => 'This payment method is temporarily unavailable.',
            '1055' => 'This payment method is temporarily unavailable.',
            '1061' => 'This payment method is temporarily unavailable.',
            '1062' => 'This payment method is temporarily unavailable.',
            '1065' => 'This payment method is temporarily unavailable.',
            '1067' => 'This payment method is temporarily unavailable.',
            '1068' => 'This payment method is temporarily unavailable.',
            '1093' => 'This payment method is temporarily unavailable.',
            '1102' => 'This payment method is temporarily unavailable.',
            '1103' => 'This payment method is temporarily unavailable.',
            '1054' => 'Your payment could not be processed. Please try again.',
            '1056' => 'Your payment could not be processed. Please verify your details.',
            '1059' => 'Your payment could not be processed. Please try again.',
            '1066' => 'Your payment could not be processed. Please try again.',
            '1057' => 'The payment processor is not responding. Please wait and try again.',
            '1058' => 'The payment processor is not responding. Please wait and try again.',
            '9827' => 'The payment processor is not responding. Please wait and try again.',
            '9831' => 'The payment processor is not responding. Please wait and try again.',
            '1074' => 'The payment system is under maintenance. Please try again later.',
            '1075' => 'The payment system is under maintenance. Please try again later.',
            '1076' => 'The payment system is under maintenance. Please try again later.',
            '1077' => 'The payment system is under maintenance. Please try again later.',
            '1078' => 'The payment system is under maintenance. Please try again later.',
            '1091' => 'The payment system is under maintenance. Please try again later.',
            '1080' => 'Please check the cardholder name and try again.',
            '1508' => 'The card number is invalid. Please check and try again.',
            '1514' => 'The expiration date is invalid. Please check and try again.',
            '1562' => 'Your card information could not be processed.',
            '9084' => 'The security code (CVV) is invalid.',
            '9779' => 'The security code (CVV) is invalid.',
            '9836' => 'The security code (CVV) is invalid.',
            '9573' => 'The card number is invalid.',
            '9563' => 'This card brand is not supported.',
            '9075' => 'This card brand is not supported.',
            '1509' => 'The order amount is below the minimum allowed.',
            '1511' => 'There was a problem with the payment amount.',
            '9617' => 'There was a problem with the payment amount.',
            '1512' => 'There was a currency error. Please contact support.',
            '1513' => 'There was a currency error. Please contact support.',
            '9566' => 'This currency is not supported.',
            '1082' => 'Please check your email address and try again.',
            '1083' => 'Please enter a valid email address.',
            '1084' => 'Please enter a valid phone number.',
            '1085' => 'The phone number is too long.',
            '1086' => 'Please check your zip/postal code.',
            '1088' => 'Please check your zip/postal code.',
            '1089' => 'Please check your phone number.',
            '1563' => 'First name is required.',
            '1564' => 'Last name is required.',
            '1565' => 'Please verify your billing information.',
            '1081' => 'A processing error occurred. Please try again.',
            '9037' => 'This transaction appears to be a duplicate. Please wait before trying again.',
            '9042' => 'Your payment was declined.',
            '1507' => 'Your bank does not support this transaction.',
            '9011' => 'Your card was declined by the bank.',
            '9832' => 'Your card was declined by the bank.',
            '9833' => 'Insufficient funds.',
            '9834' => 'Bank authorization is required.',
            '9840' => 'Your card has expired.',
            '9837' => 'Your card was declined.',
            '9841' => 'Your card was declined.',
            '9839' => 'Your card was declined. Please contact your bank.',
            '9663' => 'Your card is not active.',
            '9821' => 'Your card is blocked.',
            '9824' => 'Your card is blocked.',
            '9618' => 'Your card is blocked.',
            '9546' => 'Please contact your card issuer.',
            '9867' => 'Your card issuer is unavailable. Please try again later.',
            '9559' => 'Your card issuer is unavailable. Please try again later.',
            '9586' => 'Your card issuer is unavailable. Please try again later.',
            '9523' => 'Card not recognized.',
            '9544' => 'Invalid card account.',
            '9666' => 'This transaction is not permitted.',
            '9561' => 'This transaction is not permitted.',
            '9547' => 'This transaction cannot be completed.',
            '9079' => 'Your payment was declined.',
            '9081' => 'Your payment was declined.',
            '9083' => 'Your payment was declined.',
            '9085' => 'Your payment was declined.',
            '9835' => 'Your payment was declined.',
            '9838' => 'Your payment was declined.',
            '9777' => 'Your payment was declined.',
            '9537' => 'Your payment was declined.',
            '9539' => 'Your payment was declined.',
            '1517' => 'Payment verification failed.',
            '1518' => 'Payment verification failed.',
            '9549' => 'Additional verification is required.',
            '9556' => 'Additional verification is required.',
            '9849' => 'Card verification failed.',
            '1545' => 'Daily spending limit exceeded.',
            '9538' => 'Weekly transaction limit reached.',
            '9540' => 'Daily transaction limit reached.',
            '9845' => 'Daily transaction limit reached.',
            '9847' => 'Weekly transaction limit reached.',
            '9623' => 'Too many attempts.',
            '1079' => 'A system error occurred. Please try again later.',
            '9550' => 'A system error occurred. Please try again later.',
            '9605' => 'A system error occurred. Please try again later.',
            '9607' => 'A system error occurred. Please try again later.',
            '9776' => 'A system error occurred. Please try again later.',
            '9613' => 'A system error occurred. Please try again later.',
            '9614' => 'A system error occurred. Please try again later.',
            '9862' => 'A system error occurred. Please try again later.',
            '9825' => 'A processing error occurred. Please try again later.',
            '1552' => 'Your session has expired. Please refresh and try again.',
            '9635' => 'Your session has expired. Please refresh and try again.',
        );

        if ( isset( $map[ $code ] ) ) {
            return $map[ $code ];
        }

        $num = (int) $code;
        if ( $num >= 1050 && $num <= 1103 ) return 'This payment method is temporarily unavailable.';
        if ( $num >= 1507 && $num <= 1569 ) return 'Your payment was declined. Please check your card details.';
        if ( $num >= 1700 && $num <= 1796 ) return 'Your payment could not be processed.';
        if ( $num >= 9000 && $num <= 9099 ) return 'Your payment was declined.';
        if ( $num >= 9500 ) return 'Your payment was declined.';

        return 'Your payment could not be processed. Please try again.';
    }
}
