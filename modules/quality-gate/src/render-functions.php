<?php
/**
 * Quality Gate — Rendering functions (tables, summary boxes).
 *
 * Extracted from quality-gate.php for maintainability.
 * All functions are guarded with function_exists() where needed.
 *
 * @package SFA\QualityGate
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// --- Failed metric map -----------------------------------------------------------

/**
 * Build map Item => ['labels' => [...], 'photos' => [...]] from the saved QC JSON/meta.
 */
if ( ! function_exists( 'sfa_qg_failed_metric_map' ) ) {
	function sfa_qg_failed_metric_map( $entry_id, $form ) {
		$map = array();

		// 1) Try to compute from the QC field value (JSON)
		$qc_field_id = 0;
		foreach ( (array) rgar( $form, 'fields', array() ) as $f ) {
			if ( rgar( (array) $f, 'type' ) === 'quality_checklist' ) { $qc_field_id = (int) rgar( (array) $f, 'id' ); break; }
		}
		if ( $qc_field_id && class_exists( 'GFAPI' ) ) {
			$entry = \GFAPI::get_entry( $entry_id );
			if ( ! is_wp_error( $entry ) ) {
				$raw = rgar( $entry, (string) $qc_field_id );
				$val = json_decode( (string) $raw, true );

				if ( is_array( $val ) && ! empty( $val['items'] ) ) {
					foreach ( (array) $val['items'] as $it ) {
						$name  = (string) rgar( $it, 'name' );
						if ( $name === '' ) { continue; }
						$fails = array();
						$photos = array();
						foreach ( (array) rgar( $it, 'metrics' ) as $m ) {
							$label = trim( (string) rgar( $m, 'label' ) );
							$result = rgar( $m, 'result' );
							$photo = rgar( $m, 'photo' );

							if ( $result === 'fail' && $label !== '' ) {
								$fails[] = $label;
								// Collect photo if available
								if ( $photo ) {
									$photos[] = array(
										'label' => $label,
										'data' => $photo
									);
								}
							}
						}
						if ( $fails ) {
							$map[ $name ] = array(
								'labels' => array_values( array_unique( $fails ) ),
								'photos' => $photos
							);
						}
					}
				}
			}
		}

		// 2) Ensure every failed item exists as a key (even if no labels)
		$failed_items = json_decode( (string) gform_get_meta( $entry_id, '_qc_failed_items' ), true );
		if ( is_array( $failed_items ) ) {
			foreach ( $failed_items as $name ) {
				$name = trim( (string) $name );
				if ( $name !== '' && ! isset( $map[ $name ] ) ) {
					$map[ $name ] = array( 'labels' => array(), 'photos' => array() );
				}
			}
		}

		return $map;
	}
}

// --- Failed items table ----------------------------------------------------------

if ( ! function_exists( 'sfa_qg_render_failed_table' ) ) {
	function sfa_qg_render_failed_table( $map, $fixed_list = null, $entry_id = 0, $editable = false, $field_id = 0 ) {
		if ( empty( $map ) ) { return ''; }

		// Prefer an explicit list (live POST), otherwise read saved meta.
		if ( ! is_array( $fixed_list ) ) {
			$fixed_list = array();
			if ( $entry_id ) {
				$fixed_list = json_decode( (string) gform_get_meta( $entry_id, '_qc_recheck_items' ), true );
				$fixed_list = is_array( $fixed_list ) ? array_map( 'strval', $fixed_list ) : array();
			}
		} else {
			$fixed_list = array_map( 'strval', $fixed_list );
		}

		$out  = '<table class="qg-rework-table widefat striped">';
		$out .= '<thead><tr><th>' . esc_html__( 'Item', 'simpleflow' ) . '</th><th>' . esc_html__( 'Failed metrics', 'simpleflow' ) . '</th><th>' . esc_html__( 'Photos', 'simpleflow' ) . '</th></tr></thead><tbody>';

		foreach ( $map as $name => $data ) {
			// Support both old format (array of labels) and new format (array with 'labels' and 'photos')
			$labels = is_array( $data ) && isset( $data['labels'] ) ? $data['labels'] : ( is_array( $data ) ? $data : array() );
			$photos = is_array( $data ) && isset( $data['photos'] ) ? $data['photos'] : array();

			$is_fixed = in_array( (string) $name, $fixed_list, true );
			$badge = $is_fixed
				? ' <span class="sfa-qg-badge is-fixed">' . esc_html__( 'Fixed', 'simpleflow' ) . '</span>'
				: '';

			$chk = '';
			if ( $editable ) {
				$chk = sprintf(
					'<span class="qg-row-slot" data-field-id="%d" data-value="%s"></span> ',
					(int) $field_id,
					esc_attr( $name )
				);
			}

			// Build photos HTML
			$photos_html = '';
			if ( ! empty( $photos ) ) {
				$photos_html = '<div class="qg-fail-photos" style="display:flex;gap:8px;flex-wrap:wrap;">';
				foreach ( $photos as $photo_data ) {
					$label = isset( $photo_data['label'] ) ? esc_html( $photo_data['label'] ) : '';
					$data_url = isset( $photo_data['data'] ) ? $photo_data['data'] : '';
					// Validate data URL: only allow data:image/* scheme to prevent javascript: injection
					if ( $data_url && preg_match( '/^data:image\/[a-zA-Z0-9.+-]+;base64,[A-Za-z0-9+\/=\s]+$/', $data_url ) ) {
						$photos_html .= sprintf(
							'<div class="qg-photo-item" style="text-align:center;"><img src="%s" alt="%s" class="qg-photo-preview" style="max-width:100px;max-height:100px;border:2px solid #d1d5db;border-radius:8px;cursor:pointer;"><div style="font-size:11px;color:#6b7280;margin-top:4px;">%s</div></div>',
							esc_attr( $data_url ),
							esc_attr( $label ),
							$label
						);
					}
				}
				$photos_html .= '</div>';
			} else {
				$photos_html = '&ndash;';
			}

			$out .= '<tr><td>' . $chk . esc_html( $name ) . $badge . '</td><td>' . ( $labels ? esc_html( implode( ', ', $labels ) ) : '&ndash;' ) . '</td><td>' . $photos_html . '</td></tr>';
		}

		$out .= '</tbody></table>';
		// Delegated click handler for photo previews (replaces inline onclick)
		$out .= '<script>document.addEventListener("click",function(e){if(e.target.classList.contains("qg-photo-preview")){window.open(e.target.src);}});</script>';
		return $out;
	}
}

// --- Entry detail sidebar --------------------------------------------------------

/**
 * QG-010 — Entry Detail: compact read-only QC summary box.
 */
function sfa_qg_entry_qc_summary_box( $form, $entry ) {
	$sum = json_decode( (string) gform_get_meta( $entry['id'], '_qc_summary' ), true );
	if ( ! is_array( $sum ) ) return;

	$failed_items   = json_decode( (string) gform_get_meta( $entry['id'], '_qc_failed_items' ), true );
	$failed_metrics = json_decode( (string) gform_get_meta( $entry['id'], '_qc_failed_metrics' ), true );
	$failed_items   = is_array( $failed_items )   ? $failed_items   : array();
	$failed_metrics = is_array( $failed_metrics ) ? $failed_metrics : array();

	?>
	<div class="sfa-qg-report" style="margin-top:10px;">
		<h3 style="margin:0 0 6px;"><?php esc_html_e( 'Quality Gate', 'simpleflow' ); ?></h3>
		<div class="qg-row">
			<div class="qg-name"><?php esc_html_e( 'Totals', 'simpleflow' ); ?></div>
			<div class="qg-meta">
				<?php
				printf(
					esc_html__( '%d metrics (%d failed) across %d items', 'simpleflow' ),
					(int) ( $sum['metrics_total'] ?? 0 ),
					(int) ( $sum['metrics_failed'] ?? 0 ),
					(int) ( $sum['items_total'] ?? 0 )
				);
				?>
			</div>
		</div>
		<?php if ( $failed_items ) : ?>
			<div class="qg-row">
				<div class="qg-name"><?php esc_html_e( 'Failed items', 'simpleflow' ); ?></div>
				<div class="qg-meta"><?php echo esc_html( implode( ', ', $failed_items ) ); ?></div>
			</div>
		<?php endif; ?>
		<?php if ( $failed_metrics ) : ?>
			<div class="qg-row">
				<div class="qg-name"><?php esc_html_e( 'Failing metrics', 'simpleflow' ); ?></div>
				<div class="qg-meta"><?php echo esc_html( implode( ', ', $failed_metrics ) ); ?></div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
