<?php
defined( 'ABSPATH' ) || exit;

// Enqueue when Gravity Flow loads
add_action( 'gravityflow_enqueue_admin_scripts', function () {
	wp_enqueue_style( 'sfa-admin', SFA_URL . 'assets/css/admin.css', array(), SFA_VER );
	wp_enqueue_script( 'sfa-ui', SFA_URL . 'assets/js/sfa.js', array(), SFA_VER, true );
}, 20 );
add_action( 'gravityflow_enqueue_frontend_scripts', function () {
	wp_enqueue_style( 'sfa-frontend', SFA_URL . 'assets/css/frontend.css', array(), SFA_VER );
	wp_enqueue_script( 'sfa-ui', SFA_URL . 'assets/js/sfa.js', array(), SFA_VER, true );
}, 20 );
// GF entry screen
add_action( 'admin_enqueue_scripts', function () {
	if ( isset( $_GET['page'], $_GET['view'] ) && $_GET['page'] === 'gf_entries' && $_GET['view'] === 'entry' ) { // phpcs:ignore
		wp_enqueue_style( 'sfa-admin', SFA_URL . 'assets/css/admin.css', array(), SFA_VER );
		wp_enqueue_script( 'sfa-ui', SFA_URL . 'assets/js/sfa.js', array(), SFA_VER, true );
	}
} );

// Render above Gravity Flow timeline
add_action( 'gravityflow_entry_detail', function ( $form, $entry, $step ) {
	if ( ! sfa_can_view( $form, $entry ) ) return;
	echo sfa_render_attachments_block( $form, $entry, array('context'=>'gravityflow','position'=>'above_timeline') ); // phpcs:ignore
}, 5, 3 );

// GF entry page metabox
add_filter( 'gform_entry_detail_meta_boxes', function ( $meta_boxes, $entry, $form ) {
	$meta_boxes['sfa_attachments'] = array(
		'title'    => __( 'Order Attachment', 'simple-flow-attachment' ),
		'callback' => function ( $args ) {
			if ( ! sfa_can_view( $args['form'], $args['entry'] ) ) return;
			echo sfa_render_attachments_block( $args['form'], $args['entry'], array('context'=>'gf_entry','position'=>'metabox') ); // phpcs:ignore
		},
		'context'  => 'normal',
	);
	return $meta_boxes;
}, 10, 3 );

// Cache purge
add_action( 'gform_after_update_entry', function ( $form, $entry_id ) { delete_transient( 'sfa_attachments_' . absint( $entry_id ) ); }, 10, 2 );
add_action( 'gform_entry_post_delete', function ( $entry, $form ) { if ( isset( $entry['id'] ) ) delete_transient( 'sfa_attachments_' . absint( $entry['id'] ) ); }, 10, 2 );

/*** ZIP download ***/
add_action( 'admin_post_sfa_download_zip', 'sfa_handle_zip_download' );
add_action( 'admin_post_nopriv_sfa_download_zip', 'sfa_handle_zip_download' );

function sfa_parse_selected_tokens( $sel ) {
	$map = array();
	if ( ! $sel ) return $map;
	foreach ( explode( '|', $sel ) as $p ) {
		$p = trim( $p ); if ( ! $p ) continue;
		list( $g, $i ) = array_pad( explode( ':', $p, 2 ), 2, '' );
		$g = sanitize_key( $g ); $i = absint( $i );
		$map[ $g ][ $i ] = true;
	}
	return $map;
}

function sfa_get_allowed_hosts( $groups ) {
	$hosts = array();
	$home = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( $home ) $hosts[] = $home;
	if ( SFA_ZIP_ALLOWED_HOSTS && SFA_ZIP_ALLOWED_HOSTS !== 'auto' ) {
		$hosts = array_merge( $hosts, array_filter( array_map( 'trim', explode( ',', SFA_ZIP_ALLOWED_HOSTS ) ) ) );
	} else {
		foreach ( $groups as $items ) {
			foreach ( (array) $items as $it ) {
				$h = wp_parse_url( $it['url'], PHP_URL_HOST );
				if ( $h ) $hosts[] = $h;
			}
		}
	}
	return apply_filters( 'sfa_zip_allowed_hosts', array_values( array_unique( $hosts ) ) );
}

function sfa_handle_zip_download() {
	$entry_id = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0; // phpcs:ignore
	$group    = isset( $_GET['group'] ) ? sanitize_key( $_GET['group'] ) : ''; // phpcs:ignore
	$sel      = isset( $_GET['sel'] ) ? sanitize_text_field( wp_unslash( $_GET['sel'] ) ) : ''; // phpcs:ignore
	$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore

	if ( ! $entry_id || ! wp_verify_nonce( $nonce, 'sfa_zip_' . $entry_id ) ) wp_die( esc_html__( 'Invalid request.', 'simple-flow-attachment' ) );

	$entry = GFAPI::get_entry( $entry_id ); if ( is_wp_error( $entry ) ) wp_die( esc_html__( 'Entry not found.', 'simple-flow-attachment' ) );
	$form  = GFAPI::get_form( rgar( $entry, 'form_id' ) );
	if ( ! sfa_can_view( $form, $entry ) ) wp_die( esc_html__( 'Permission denied.', 'simple-flow-attachment' ) );

	$groups = sfa_collect_grouped_attachments( $form, $entry );
	if ( $group && isset( $groups[ $group ] ) ) $groups = array( $group => $groups[ $group ] );

	if ( $sel ) {
		$map = sfa_parse_selected_tokens( $sel );
		foreach ( $groups as $g => $items ) {
			if ( empty( $map[ $g ] ) ) { unset( $groups[ $g ] ); continue; }
			$keep = array();
			foreach ( $items as $i => $it ) if ( isset( $map[ $g ][ $i ] ) ) $keep[] = $it;
			$groups[ $g ] = $keep;
			if ( ! $keep ) unset( $groups[ $g ] );
		}
	}

	$files = array();
	$total_remote = 0;
	$allowed = sfa_get_allowed_hosts( $groups );
	$limit   = max( 1, (int) SFA_ZIP_MAX_TOTAL_MB ) * 1024 * 1024;

	foreach ( $groups as $g => $items ) {
		$sub = $g ? $g : 'attachments';
		foreach ( $items as $idx => $it ) {
			$name = sanitize_file_name( $it['name'] ?: ( $it['path'] ? wp_basename( $it['path'] ) : 'file-' . $idx ) );
			if ( ! empty( $it['local'] ) && ! empty( $it['path'] ) && file_exists( $it['path'] ) ) {
				$files[] = array( 'type'=>'local', 'path'=>$it['path'], 'inzip'=> $sub . '/' . $name );
			} elseif ( SFA_REMOTE_ZIP ) {
				$host = wp_parse_url( $it['url'], PHP_URL_HOST );
				if ( $host && in_array( $host, $allowed, true ) ) {
					$tmp = wp_tempnam( 'sfa_remote' );
					$resp = wp_remote_get( $it['url'], array( 'timeout'=>20, 'stream'=>true, 'filename'=>$tmp ) );
					if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) && file_exists( $tmp ) ) {
						$total_remote += filesize( $tmp );
						if ( $total_remote <= $limit ) $files[] = array( 'type'=>'remote', 'path'=>$tmp, 'inzip'=>$sub . '/' . $name );
						else { @unlink( $tmp ); break 2; }
					} else { @unlink( $tmp ); }
				}
			}
		}
	}

	if ( empty( $files ) ) wp_die( esc_html__( 'No files to download.', 'simple-flow-attachment' ) );

	$tmpzip = wp_tempnam( 'sfa_' . $entry_id . '.zip' );
	$zip = new ZipArchive();
	if ( true !== $zip->open( $tmpzip, ZipArchive::OVERWRITE ) ) wp_die( esc_html__( 'Cannot create ZIP.', 'simple-flow-attachment' ) );
	foreach ( $files as $f ) $zip->addFile( $f['path'], $f['inzip'] );
	$zip->close();

	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="attachments-' . $entry_id . '.zip"' );
	header( 'Content-Length: ' . filesize( $tmpzip ) );
	readfile( $tmpzip );
	@unlink( $tmpzip );
	foreach ( $files as $f ) if ( $f['type'] === 'remote' ) @unlink( $f['path'] );
	exit;
}
