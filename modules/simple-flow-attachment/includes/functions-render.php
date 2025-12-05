<?php
defined( 'ABSPATH' ) || exit;

function sfa_render_attachments_block( $form, $entry, $args = array() ) {
	$args = wp_parse_args( $args, array(
		'context'  => 'gravityflow',
		'position' => 'above_timeline',
	) );

	sfa_ensure_assets();

	$entry_id  = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
	$cache_key = $entry_id ? 'sfa_attachments_' . $entry_id : '';

	$groups = false;
	if ( $cache_key ) $groups = get_transient( $cache_key );
	if ( false === $groups ) {
		$groups = sfa_collect_grouped_attachments( $form, $entry );
		if ( $cache_key ) set_transient( $cache_key, $groups, 12 * HOUR_IN_SECONDS );
	}
	if ( empty( $groups ) ) return '';

	ob_start();
	$context = array(
		'title'  => __( 'Order Attachment', 'simple-flow-attachment' ),
		'groups' => $groups,
		'entry'  => $entry,
		'args'   => $args,
		'nonce'  => wp_create_nonce( 'sfa_zip_' . $entry_id ),
	);
	$template = sfa_locate_template( 'attachment-block.php' );
	include $template;
	return ob_get_clean();
}
