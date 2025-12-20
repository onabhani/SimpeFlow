<?php
namespace SFA\QualityGate;

if ( ! class_exists( 'GFForms' ) ) { return; }
if ( ! class_exists( '\Gravity_Flow_Step' ) ) { return; }

if ( ! function_exists( 'sfa_qg_log' ) ) {
	function sfa_qg_log( $msg, $ctx = array() ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[SFA QG] ' . $msg . ( $ctx ? ' | ' . wp_json_encode( $ctx ) : '' ) );
		}
	}
}

class Step_Quality_Gate extends \Gravity_Flow_Step {

	public $_step_type    = 'quality_gate';
	protected $_rest_base = 'quality-gates';

	public function get_label()    { return esc_html__( 'Quality Gate', 'sfa-quality-gate' ); }
	public function get_icon_url() { return '<i class="fa fa-clipboard-check" style="color:#2563eb;"></i>'; }

	public function get_settings() {
		return array(
			'title'  => esc_html__( 'Quality Gate', 'sfa-quality-gate' ),
			'fields' => array(
				array(
					'name'  => 'qc_field_id',
					'label' => esc_html__( 'Quality Checklist Field', 'sfa-quality-gate' ),
					'type'  => 'field_select',
					'args'  => array( 'input_types' => array( 'quality_checklist' ) ),
				),
			),
		);
	}

	public function get_status_config() {
		return array(
			array(
				'status'                    => 'failed',
				'status_label'              => esc_html__( 'Failed', 'sfa-quality-gate' ),
				'destination_setting_label' => esc_html__( 'Next step if Failed', 'sfa-quality-gate' ),
				'default_destination'       => 'complete',
			),
			array(
				'status'                    => 'passed',
				'status_label'              => esc_html__( 'Passed', 'sfa-quality-gate' ),
				'destination_setting_label' => esc_html__( 'Next step if Passed', 'sfa-quality-gate' ),
				'default_destination'       => 'next',
			),
		);
	}

	public function supports_due_date()   { return true; }
	public function supports_expiration() { return true; }

	/**
	 * One-shot evaluation. Field is required on the User Input step,
	 * so we expect a complete payload here.
	 */
public function process() {
	$entry    = $this->get_entry();
	$entry_id = absint( rgar( $entry, 'id' ) );

	// If your compute_status_and_summary() does NOT accept $entry, remove the argument.
	list( $status, $summary, $err ) = $this->compute_status_and_summary( $entry );

	// Persist summary flags for UI/reporting.
	gform_update_meta( $entry_id, '_qc_summary',    wp_json_encode( $summary ) );
	gform_update_meta( $entry_id, '_qc_has_fail',   $status === 'failed' ? '1' : '0' );



	// Resolved => clear pending flags.
	gform_delete_meta( $entry_id, '_qc_nodata' );
	gform_delete_meta( $entry_id, '_qc_pending_noted' );

	// If compute ruled it invalid, fail the step and clear failed-items list.
	if ( $err ) {
		$this->add_note( sprintf( esc_html__( 'Quality Gate: invalid data (%s).', 'sfa-quality-gate' ), $err ), true );
		$this->update_step_status( 'failed' );
		gform_update_meta( $entry_id, '_qc_failed_items', wp_json_encode( array() ) );
		return true; // continue routing
	}

	// Compute per-item failed names once and persist.
	$field_id = absint( $this->qc_field_id );
	$raw = $field_id ? rgar( $entry, (string) $field_id ) : '';
	if ( $raw === '' || $raw === null ) {
		$raw = gform_get_meta( $entry_id, '_qc_payload' ); // legacy fallback
	}
	$payload = json_decode( $raw, true );

	$failed_items = array();
	if ( is_array( $payload ) && ! empty( $payload['items'] ) && is_array( $payload['items'] ) ) {
		foreach ( $payload['items'] as $it ) {
			$has_fail = false;
			if ( ! empty( $it['metrics'] ) && is_array( $it['metrics'] ) ) {
				foreach ( $it['metrics'] as $m ) {
					if ( isset( $m['result'] ) && $m['result'] === 'fail' ) { $has_fail = true; break; }
				}
			}
			if ( $has_fail && ! empty( $it['name'] ) ) {
				$failed_items[] = (string) $it['name'];
			}
		}
	}
	gform_update_meta( $entry_id, '_qc_failed_items', wp_json_encode( $failed_items ) );

	// Force cache clear to ensure meta is immediately available for next step
	if ( function_exists( 'wp_cache_delete' ) ) {
		wp_cache_delete( $entry_id . '_qc_failed_items', 'gform_meta' );
		wp_cache_delete( $entry_id, 'gform_entry_meta' );
	}

	sfa_qg_log('QG_STEP_PROCESS: Saved failed items', array(
		'entry_id' => $entry_id,
		'failed_count' => count($failed_items),
		'failed_items' => $failed_items
	));
	// Collect failed metric labels (count duplicates; used for reporting)
    $failed_metrics = array();
        if ( is_array( $payload ) && ! empty( $payload['items'] ) && is_array( $payload['items'] ) ) {
    	foreach ( $payload['items'] as $it ) {
		if ( empty( $it['metrics'] ) || ! is_array( $it['metrics'] ) ) { continue; }
		foreach ( $it['metrics'] as $m ) {
			if ( isset( $m['result'] ) && $m['result'] === 'fail' ) {
				$label = '';
				if ( isset( $m['label'] ) && is_string( $m['label'] ) && $m['label'] !== '' ) {
					$label = $m['label'];
				} elseif ( isset( $m['k'] ) && is_string( $m['k'] ) ) {
					$label = $m['k']; // fallback if label missing (older payloads)
				}
				if ( $label !== '' ) { $failed_metrics[] = $label; }
			}
		}
	}
}
gform_update_meta( $entry_id, '_qc_failed_metrics', wp_json_encode( $failed_metrics ) );


	// Final note + status.
	$this->add_note(
		( $status === 'failed' )
			? sprintf( esc_html__( 'Quality Gate: %d failed checks.', 'sfa-quality-gate' ), (int) ( $summary['metrics_failed'] ?? 0 ) )
			: esc_html__( 'Quality Gate: all checks passed.', 'sfa-quality-gate' ),
		true
	);
	$this->update_step_status( $status );

	return true; // continue routing this tick
}


	public function status_evaluation() {
		list( $status ) = $this->compute_status_and_summary( $this->get_entry() );
		return $status;
	}

public function is_complete() {
	$status = $this->status_evaluation();
	return in_array( $status, array( 'passed', 'failed' ), true );
}


public function workflow_detail_box( $form, $args ) {
    $entry_id = absint( rgar( $this->get_entry(), 'id' ) );
    $status   = $this->status_evaluation();
    $summary  = json_decode( gform_get_meta( $entry_id, '_qc_summary' ), true );

    $status_text = ( $status === 'pending' )
        ? esc_html__( 'Pending (waiting for QC data)', 'sfa-quality-gate' )
        : ( $status === 'failed' ? esc_html__( 'Failed', 'sfa-quality-gate' ) : esc_html__( 'Passed', 'sfa-quality-gate' ) );

    printf(
        '<div class="gravityflow-status-box-field"><h4>%s: %s</h4></div>',
        esc_html( $this->get_name() ),
        esc_html( $status_text )
    );

    if ( is_array( $summary ) ) {
        printf(
            '<div class="gravityflow-status-box-field sfa-qg-summary">%s: %d | %s: %d | %s: %d</div>',
            esc_html__( 'Items', 'sfa-quality-gate' ),
            (int) ( $summary['items_total'] ?? 0 ),
            esc_html__( 'Checks', 'sfa-quality-gate' ),
            (int) ( $summary['metrics_total'] ?? 0 ),
            esc_html__( 'Failed', 'sfa-quality-gate' ),
            (int) ( $summary['metrics_failed'] ?? 0 )
        );
    }
}


	/**
	 * @return array [ status('passed'|'failed'), summary(array), error_string|null ]
	 */
	private function compute_status_and_summary( $entry ) {
		$field_id = absint( $this->qc_field_id );
		$raw      = $field_id ? rgar( $entry, (string) $field_id ) : '';

		if ( $raw === '' || $raw === null ) {
			return array( 'failed', array( 'items_total' => 0, 'metrics_total' => 0, 'metrics_failed' => 1 ), 'missing' );
		}

		$payload = json_decode( (string) $raw, true );
		if ( ! is_array( $payload ) ) {
			return array( 'failed', array( 'items_total' => 0, 'metrics_total' => 0, 'metrics_failed' => 1 ), 'json' );
		}

		$items = array();
		if ( isset( $payload['items'] ) && is_array( $payload['items'] ) ) {
			$items = $payload['items'];
		} elseif ( is_array( $payload ) ) { // legacy flat structure
			foreach ( $payload as $row ) {
				$items[] = array(
					'name'    => isset( $row['name'] ) ? (string) $row['name'] : '',
					'metrics' => array(
						array(
							'k'      => 'overall',
							'result' => isset( $row['result'] ) ? (string) $row['result'] : '',
							'note'   => isset( $row['note'] ) ? (string) $row['note'] : '',
						),
					),
				);
			}
		}

		$summary = array(
			'items_total'    => is_array( $items ) ? count( $items ) : 0,
			'metrics_total'  => 0,
			'metrics_failed' => 0,
		);

		foreach ( (array) $items as $it ) {
			if ( empty( $it['metrics'] ) || ! is_array( $it['metrics'] ) ) { continue; }
			foreach ( $it['metrics'] as $m ) {
				$r = isset( $m['result'] ) ? (string) $m['result'] : '';
				if ( $r === 'pass' || $r === 'fail' ) {
					$summary['metrics_total']++;
					if ( $r === 'fail' ) { $summary['metrics_failed']++; }
				}
			}
		}

		$status = ( $summary['metrics_failed'] > 0 ) ? 'failed' : 'passed';
		return array( $status, $summary, null );
	}
}

\Gravity_Flow_Steps::register( new Step_Quality_Gate() );
