<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MCPG_Logger {

    private $logger;
    private $enabled;
    private $source;

    public function __construct( $enabled = false, $source = 'mcpg-cascading' ) {
        $this->enabled = $enabled;
        $this->source  = $source;
        if ( $this->enabled && function_exists( 'wc_get_logger' ) ) {
            $this->logger = wc_get_logger();
        }
    }

    public function log( $msg, $level = 'debug' ) {
        if ( ! $this->enabled || ! $this->logger ) return;
        if ( is_array( $msg ) || is_object( $msg ) ) {
            $msg = print_r( $msg, true );
        }
        $this->logger->$level( $msg, array( 'source' => $this->source ) );
    }
}
