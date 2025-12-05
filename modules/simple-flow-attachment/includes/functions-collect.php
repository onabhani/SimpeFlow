<?php
defined( 'ABSPATH' ) || exit;

function sfa_normalize_files( $raw ) {
	if ( empty( $raw ) ) return array();
	if ( is_array( $raw ) ) return array_map( 'esc_url_raw', array_filter( array_map( 'strval', $raw ) ) );
	$urls = array();
	if ( is_string( $raw ) ) {
		$decoded = json_decode( $raw, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			foreach ( (array) $decoded as $item ) {
				if ( is_array( $item ) && ! empty( $item['url'] ) ) $urls[] = $item['url'];
				elseif ( is_string( $item ) ) $urls[] = $item;
			}
		} else {
			$raw = str_replace( '|', ',', $raw );
			$urls = array_map( 'trim', explode( ',', $raw ) );
		}
	}
	$urls = array_values( array_filter( $urls ) );
	return array_map( 'esc_url_raw', $urls );
}

function sfa_collect_grouped_attachments( $form, $entry ) {
	$groups = array();
	if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) return $groups;

	foreach ( $form['fields'] as $field ) {
		if ( ! is_object( $field ) ) continue;
		$type = isset( $field->type ) ? $field->type : '';
		if ( ! in_array( $type, array( 'fileupload', 'post_image' ), true ) ) continue;

		$value = rgar( $entry, (string) $field->id );
		$files = sfa_normalize_files( $value );
		if ( empty( $files ) ) continue;

		$group = 'misc';
		$css   = isset( $field->cssClass ) ? (string) $field->cssClass : '';
		if ( $css && preg_match( '/sfa-group:([a-z0-9_\-]+)/i', $css, $m ) ) {
			$group = sanitize_key( $m[1] );
		} else {
			$label = isset( $field->adminLabel ) && $field->adminLabel ? $field->adminLabel : ( $field->label ?? '' );
			$group = $label ? sanitize_title( $label ) : 'misc';
		}

		foreach ( $files as $i => $url ) {
			$name = sfa_url_basename( $url );
			$ft   = wp_check_filetype( $url );
			$mime = $ft['type'] ?: 'application/octet-stream';
			$path = sfa_url_to_path( $url );
			$size = ( $path ) ? @filesize( $path ) : 0;
			$meta = sfa_mime_meta( $mime, $name );

			$groups[ $group ][] = array(
				'url'   => esc_url_raw( $url ),
				'name'  => $name,
				'mime'  => $mime,
				'ext'   => $meta['ext'],
				'icon'  => $meta['icon'],
				'size'  => $size,
				'local' => (bool) $path,
				'path'  => $path ?: '',
			);
		}
	}
	return apply_filters( 'sfa_groups_built', $groups, $form, $entry );
}
