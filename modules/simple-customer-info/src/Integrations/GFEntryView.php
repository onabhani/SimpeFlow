<?php namespace SFA\SCI\Integrations;

use SFA\SCI\MapRepository;
use SFA\SCI\Renderer;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GFEntryView {
	private $repo;
	private $renderer;

	public function __construct( MapRepository $r, Renderer $v ) {
		$this->repo     = $r;
		$this->renderer = $v;
	}

	public function register() : void {
		// Ensure our output is injected at the very top of the GF entry detail
		add_action( 'load-forms_page_gf_entries', function () {
			add_action( 'gform_entry_detail_content_before', array( $this, 'render' ), 1, 2 );
		} );
	}

	public function render( $form = null, $entry = null ) : void {
		try {
		if ( ! $form || ! $entry ) { return; }

		$map = $this->repo->get( (int) rgar( $form, 'id' ) );
		echo $this->renderer->build( $form, $entry, $map );

		if ( ! empty( $map['options']['hide_native'] ) ) {
			$field_labels = array();
			
			// Collect field labels from preset and extra mappings
			foreach ( array_merge( $map['preset'] ?? array(), $map['extra'] ?? array() ) as $slot ) {
				$fid = isset( $slot['field_id'] ) ? (int) $slot['field_id'] : 0;
				$label = isset( $slot['label'] ) ? trim( $slot['label'] ) : '';
				
				if ( $fid > 0 && ! empty( $label ) ) {
					// Get the actual field label from the form
					$field_label = '';
					if ( isset( $form['fields'] ) ) {
						foreach ( $form['fields'] as $field ) {
							if ( $field->id == $fid ) {
								$field_label = ! empty( $field->label ) ? trim( $field->label ) : '';
								break;
							}
						}
					}
					
					// Use the actual field label if available, otherwise use the mapped label
					$target_label = ! empty( $field_label ) ? $field_label : $label;
					if ( ! empty( $target_label ) ) {
						$field_labels[] = $target_label;
					}
				}
			}
			
			$field_labels = array_values( array_unique( $field_labels ) );
			
			// Output both field IDs and labels for maximum compatibility
			$ids = array();
			foreach ( array_merge( $map['preset'] ?? array(), $map['extra'] ?? array() ) as $slot ) {
				$fid = isset( $slot['field_id'] ) ? (int) $slot['field_id'] : 0;
				if ( $fid > 0 ) {
					$ids[] = $fid;
				}
			}
			$ids = array_values( array_unique( $ids ) );
			
			if ( ! empty( $field_labels ) || ! empty( $ids ) ) {
				printf('<script>window.SFA_SCI_HIDE_IDS=%s;window.SFA_SCI_HIDE_LABELS=%s;window.SFA_SCI_HIDE_ENABLED=true;</script>', 
					wp_json_encode($ids), 
					wp_json_encode($field_labels)
				);
			} else {
				echo '<script>window.SFA_SCI_HIDE_ENABLED=false;</script>';
			}
		} else {
			echo '<script>window.SFA_SCI_HIDE_ENABLED=false;</script>';
		}
		} catch (\Throwable $e) { error_log('[SFA SCI] GFEntryView render error: '.$e->getMessage()); }
	}
}
