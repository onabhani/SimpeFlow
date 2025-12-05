<?php
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SFA_DISABLE_THEME_TEMPLATE' ) ) define( 'SFA_DISABLE_THEME_TEMPLATE', false );

function sfa_can_view( $form, $entry ) {
	if ( function_exists( 'gravity_flow' ) ) {
		return is_user_logged_in();
	}
	return current_user_can( 'gravityforms_view_entries' );
}

function sfa_url_basename( $url ) {
	$path = parse_url( (string) $url, PHP_URL_PATH );
	return $path ? wp_basename( $path ) : '';
}

function sfa_locate_template( $file ) {
	if ( SFA_DISABLE_THEME_TEMPLATE ) { return SFA_DIR . 'templates/' . $file; }
	$paths = array(
		trailingslashit( get_stylesheet_directory() ) . 'simple-flow-attachment/' . $file,
		trailingslashit( get_template_directory() ) . 'simple-flow-attachment/' . $file,
		SFA_DIR . 'templates/' . $file,
	);
	foreach ( $paths as $path ) {
		if ( file_exists( $path ) ) return $path;
	}
	return SFA_DIR . 'templates/' . $file;
}

function sfa_url_to_path( $url ) {
	$uploads = wp_get_upload_dir();
	if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) return false;
	$baseurl = trailingslashit( $uploads['baseurl'] );
	$basedir = trailingslashit( $uploads['basedir'] );
	$u = preg_replace( '#^https?://#i', '//', $url );
	$b = preg_replace( '#^https?://#i', '//', $baseurl );
	if ( strpos( $u, $b ) !== 0 ) return false;
	$rel  = ltrim( substr( $url, strlen( $baseurl ) ), '/' );
	$path = wp_normalize_path( $basedir . $rel );
	return file_exists( $path ) ? $path : false;
}

function sfa_format_bytes( $bytes ) {
	$bytes = (float) $bytes;
	if ( $bytes <= 0 ) return '0 B';
	$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
	$pow = floor( log( $bytes, 1024 ) );
	$pow = min( $pow, count( $units ) - 1 );
	$val = $bytes / pow( 1024, $pow );
	return number_format_i18n( $val, $pow ? 1 : 0 ) . ' ' . $units[$pow];
}

function sfa_mime_meta( $mime, $name ) {
	$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	$type = 'file';
	if ( strpos( $mime, 'pdf' ) !== false || $ext === 'pdf' ) $type = 'pdf';
	elseif ( strpos( $mime, 'image/' ) === 0 ) $type = 'image';
	elseif ( strpos( $mime, 'spreadsheet' ) !== false || in_array( $ext, array('xls','xlsx','csv'), true ) ) $type = 'sheet';
	elseif ( strpos( $mime, 'presentation' ) !== false || in_array( $ext, array('ppt','pptx'), true ) ) $type = 'ppt';
	elseif ( strpos( $mime, 'word' ) !== false || in_array( $ext, array('doc','docx'), true ) ) $type = 'doc';
	return array( 'ext' => $ext ? strtoupper( $ext ) : strtoupper( $mime ), 'icon' => $type );
}

/** Ensure CSS/JS are loaded */
function sfa_ensure_assets() {
	$h = is_admin() ? 'sfa-admin' : 'sfa-frontend';
	if ( ! wp_style_is( $h, 'enqueued' ) ) {
		wp_enqueue_style( $h, SFA_URL . ( is_admin() ? 'assets/css/admin.css' : 'assets/css/frontend.css' ), array(), SFA_VER );
	}
	if ( ! wp_script_is( 'sfa-ui', 'enqueued' ) ) {
		wp_enqueue_script( 'sfa-ui', SFA_URL . 'assets/js/sfa.js', array(), SFA_VER, true );
	}
}
