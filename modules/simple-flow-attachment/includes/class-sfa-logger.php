<?php
defined( 'ABSPATH' ) || exit;
class SFA_Logger {
	public static function log( $msg, $ctx = array() ) {
		if ( ! defined( 'SFA_DEBUG' ) || ! SFA_DEBUG ) return;
		$line = '[' . gmdate('c') . '] ' . ( is_string($msg) ? $msg : wp_json_encode($msg) );
		if ( $ctx ) $line .= ' ' . wp_json_encode( $ctx );
		$line .= PHP_EOL;
		@file_put_contents( WP_CONTENT_DIR . '/sfa-debug.log', $line, FILE_APPEND );
	}
}
