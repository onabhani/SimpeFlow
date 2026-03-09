<?php
namespace SFA\QualityGate;

if ( ! class_exists('GFForms') ) { return; }

class Field_Quality_Checklist extends \GF_Field {

	public $type = 'quality_checklist';

	public function get_form_editor_field_title() {
		return esc_html__('Quality Checklist','sfa-quality-gate');
	}

	public function get_form_editor_field_settings() {
		return array(
			'label_setting',
			'admin_label_setting',
			'rules_setting',
			'visibility_setting',
			'description_setting',
			'css_class_setting',
			'conditional_logic_field_setting',
			'sfa_qg_setting_require_note',
			'sfa_qg_setting_source_upload',
			'sfa_qg_setting_metrics',
		);
	}

public function get_field_input( $form, $value = '', $entry = null ) {

	// Try to resolve an entry if GF didn't pass it (front-end Inbox, etc.)
	if ( ! is_array( $entry ) ) {
		$lid = isset( $_GET['lid'] ) ? absint( $_GET['lid'] ) : 0;
		if ( ! $lid && isset( $_GET['entry_id'] ) ) {
			$lid = absint( $_GET['entry_id'] );
		}
		if ( $lid && class_exists( '\GFAPI' ) ) {
			$maybe = \GFAPI::get_entry( $lid );
			if ( ! is_wp_error( $maybe ) && is_array( $maybe ) ) {
				$entry = $maybe;
			}
		}
	}

	if ( function_exists( 'sfa_qg_log' ) ) {
		sfa_qg_log( 'QG render', array(
			'form_id'   => is_array( $form ) ? (int) rgar( $form, 'id' ) : 0,
			'field_id'  => isset( $this->id ) ? (int) $this->id : 0,
			'has_entry' => is_array( $entry ),
			'screen'    => ( function_exists( 'get_current_screen' ) && get_current_screen() ) ? get_current_screen()->id : '',
		) );
	}

	$form_id  = absint( $form['id'] );
	$field_id = intval( $this->id );
	$input_id = $this->is_entry_detail() ? "input_{$field_id}" : "input_{$form_id}_{$field_id}";

	wp_enqueue_script( 'sfa-qg' );
	wp_enqueue_style( 'sfa-qg' );

	$cfg = array(
		'formId'            => $form_id,
		'fieldId'           => $field_id,
		'entryId'           => ( is_array( $entry ) && isset( $entry['id'] ) ? (int) $entry['id'] : 0 ),
		'nonce'             => wp_create_nonce( 'sfa_qg' ),
		'requireNoteOnFail' => (bool) rgar( $this, 'sfa_qg_require_note_on_fail' ),
		'sourceId'          => absint( rgar( $this, 'sfa_qg_source_upload_field' ) ),
	);
	


// Before echoing HTML, extend $cfg with fixedItems and failedItems from meta
$entry_id = function_exists('sfa_qg_current_entry_id') ? sfa_qg_current_entry_id() : 0;
$fixed_from_meta = array();
$failed_from_meta = array();
if ( $entry_id ) {
    $tmp = json_decode( (string) gform_get_meta( $entry_id, '_qc_recheck_items' ), true );
    if ( is_array( $tmp ) ) {
        $fixed_from_meta = array_values( array_unique( array_filter( array_map( 'strval', $tmp ) ) ) );
    }

    // Load failed items so JS can show "PASSED PREVIOUSLY" for non-failed items
    $tmp_failed = json_decode( (string) gform_get_meta( $entry_id, '_qc_failed_items' ), true );
    if ( is_array( $tmp_failed ) ) {
        $failed_from_meta = array_values( array_unique( array_filter( array_map( 'strval', $tmp_failed ) ) ) );
    }
}
$cfg['fixedItems'] = $fixed_from_meta;
$cfg['failedItems'] = $failed_from_meta;



	// --- Detect current Gravity Flow step context (quality_gate vs user_input) ---
	$context = '';
	if ( function_exists( 'gravity_flow' ) ) {
		$step_id = function_exists( 'sfa_qg_current_step_id' ) ? sfa_qg_current_step_id() : 0;
		$step    = $step_id ? gravity_flow()->get_step( $step_id ) : ( ( is_array( $entry ) ) ? gravity_flow()->get_current_step( $form, $entry ) : null );
		if ( $step ) {
			$stype = property_exists( $step, '_step_type' ) ? (string) $step->_step_type : '';
			if (
				$stype === 'quality_gate' ||
				( class_exists( '\SFA\QualityGate\Step_Quality_Gate' ) && $step instanceof \SFA\QualityGate\Step_Quality_Gate )
			) {
				$context = 'quality_gate';
			} elseif (
				$stype === 'user_input' ||
				( class_exists( '\Gravity_Flow_Step_User_Input' ) && $step instanceof \Gravity_Flow_Step_User_Input )
			) {
				$context = 'user_input';
			}
		}
	}
	$cfg['context'] = $context;

	// QG-104 / QG-204: recheckOnly reuses the fixedItems already loaded above (line 77-88).
	// No need to re-read _qc_recheck_items — $fixed_from_meta already has it.
	if ( ! empty( $fixed_from_meta ) ) {
		$cfg['recheckOnly'] = $fixed_from_meta;
	}

	// Parse metric labels from field setting (comma or newline). Max 10.
	$metric_labels = array();
	$raw_labels    = (string) rgar( $this, 'sfa_qg_metric_labels' );
	if ( $raw_labels !== '' ) {
		$parts         = preg_split( '/\r\n|\r|\n|,/', $raw_labels );
		$parts         = array_map( 'trim', (array) $parts );
		$metric_labels = array_values(
			array_filter(
				$parts,
				function ( $s ) {
					return $s !== '';
				}
			)
		);
	}
	$metric_labels = array_slice( $metric_labels, 0, 10 );
	if ( ! empty( $metric_labels ) ) {
		$cfg['metricLabels'] = $metric_labels;
	}

	// Server-side embed of item names when we have entry + source.
	// Uses sfa_qg_normalize_files() (defined in quality-gate.php) for parsing.
	$items     = array();
	$source_id = $cfg['sourceId'];
	if ( $source_id && is_array( $entry ) && function_exists( 'sfa_qg_normalize_files' ) ) {
		$raw_upload = rgar( $entry, (string) $source_id );
		if ( ! $raw_upload && isset( $entry[ $source_id ] ) ) {
			$raw_upload = $entry[ $source_id ];
		}
		$items = sfa_qg_normalize_files( $raw_upload );
	}

	if ( function_exists( 'sfa_qg_log' ) ) {
		sfa_qg_log(
			'QG embed items',
			array(
				'field_id'  => $field_id,
				'source_id' => $source_id,
				'has_entry' => is_array( $entry ),
				'count'     => count( $items ),
			)
		);
	}

	if ( ! empty( $items ) ) {
		$cfg['items'] = $items;
	}

	// Expose cfg to footer-localize (front-end) and also keep in data-attr for admin
	$GLOBALS['sfa_qg_cfg']   = isset( $GLOBALS['sfa_qg_cfg'] ) ? $GLOBALS['sfa_qg_cfg'] : array();
	$GLOBALS['sfa_qg_cfg'][] = $cfg;

	$payload_val = is_string( $value ) && $value !== '' ? $value : '';

	// If empty yet we have items, prime a skeleton payload so edit UI restores cleanly
	if ( $payload_val === '' && ! empty( $items ) ) {
		$rows        = array();
		$has_metrics = ! empty( $metric_labels );

		foreach ( $items as $it ) {
			$metrics = array();
			if ( $has_metrics ) {
				foreach ( $metric_labels as $lbl ) {
					$k = sanitize_key( $lbl !== '' ? $lbl : 'metric' );
					if ( $k === '' ) {
						$k = 'metric';
					}
					$metrics[] = array(
						'k'      => $k,
						'label'  => $lbl,
						'result' => '',
						'note'   => '',
					);
				}
			}
			$rows[] = array(
				'name'    => $it['name'],
				'metrics' => $metrics,
			);
		}

		$metrics_total = 0;
		foreach ( $rows as $r ) {
			$metrics_total += is_array( $r['metrics'] ) ? count( $r['metrics'] ) : 0;
		}

		$payload_val = wp_json_encode(
			array(
				'items'   => $rows,
				'summary' => array(
					'items_total'    => count( $rows ),
					'metrics_total'  => $metrics_total,
					'metrics_failed' => 0,
				),
			)
		);
	}

	$data_config = esc_attr( wp_json_encode( $cfg ) );
	
	if ( function_exists('sfa_qg_log') ) {
    sfa_qg_log('QG field render cfg', array(
        'entry_id'     => (int) $cfg['entryId'],
        'form_id'      => (int) $form_id,
        'field_id'     => (int) $field_id,
        'context'      => (string) ($cfg['context'] ?? ''),
        'items_count'  => isset($cfg['items']) && is_array($cfg['items']) ? count($cfg['items']) : 0,
        'fixed_count'  => isset($cfg['fixedItems']) && is_array($cfg['fixedItems']) ? count($cfg['fixedItems']) : 0,
        'failed_count' => isset($cfg['failedItems']) && is_array($cfg['failedItems']) ? count($cfg['failedItems']) : 0,
        'fixedItems'   => isset($cfg['fixedItems']) ? $cfg['fixedItems'] : array(),
        'failedItems'  => isset($cfg['failedItems']) ? $cfg['failedItems'] : array(),
    ));
}


	return sprintf(
		'<div class="sfa-qg-field" data-form="%d" data-field="%d" data-config="%s">
			<div class="sfa-qg-placeholder">%s</div>
			<div class="sfa-qg-ui"></div>
			<input type="hidden" class="sfa-qg-input" name="input_%d" id="%s" value="%s" />
		</div>',
		$form_id,
		$field_id,
		$data_config,
		esc_html__( 'Quality checklist UI will render here.', 'sfa-quality-gate' ),
		$field_id,
		esc_attr( $input_id ),
		esc_attr( $payload_val )
	);
}




	public function validate( $value, $form ) {
		// Honor GF 'Required' first
		parent::validate( $value, $form );
		if ( $this->failed_validation ) {
			return;
		}

		$raw = rgpost("input_{$this->id}");
		if ($raw === null) $raw = $value;

		// If empty and not required, allow and let the Quality Gate step decide.
		if ($raw === '' || $raw === null) {
			return;
		}

		$decoded = json_decode($raw,true);
		if ( ! is_array($decoded) || ! isset($decoded['items']) || ! is_array($decoded['items']) ) {
			$this->failed_validation = true;
			$this->validation_message = esc_html__('Invalid quality data.','sfa-quality-gate');
			return;
		}

		$require_note = (bool) rgar($this,'sfa_qg_require_note_on_fail');
		if ( ! $require_note ) return;

		foreach ( $decoded['items'] as $it ) {
			if ( empty($it['metrics']) || ! is_array($it['metrics']) ) continue;
			foreach ( $it['metrics'] as $m ) {
				$result = isset($m['result']) ? $m['result'] : '';
				$note   = isset($m['note']) ? trim((string)$m['note']) : '';
				if ( $result === 'fail' && $note === '' ) {
					$this->failed_validation = true;
					$this->validation_message = esc_html__('A note is required for failed checks.','sfa-quality-gate');
					return;
				}
			}
		}
	}

	public function is_conditional_logic_supported(){
		return true;
	}

	/**
	 * Entry Detail / Read-only render
	 * Compact report: item name + PASS/FAIL badge + optional note.
	 */
	public function get_value_entry_detail( $value, $currency = null, $use_text = true, $format = 'html', $media = null ) {
		if ( $format !== 'html' ) {
			return parent::get_value_entry_detail( $value, $currency, $use_text, $format, $media );
		}

		// Try to decode the stored JSON
		$payload = array();
		if ( is_string( $value ) && $value !== '' ) {
			$dec = json_decode( $value, true );
			if ( is_array( $dec ) ) $payload = $dec;
		} elseif ( is_array( $value ) ) {
			$payload = $value;
		}

		// Normalize legacy flat structure
		if ( isset( $payload[0] ) && is_array( $payload[0] ) && ! isset( $payload['items'] ) ) {
			$items = array();
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
			$payload = array( 'items' => $items );
		}

		$items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();

		if ( empty( $items ) ) {
			return '<div class="sfa-qg-report"><div class="qg-summary">' . esc_html__( 'No checklist data.', 'sfa-quality-gate' ) . '</div></div>';
		}

		ob_start();
		echo '<div class="sfa-qg-report">';
foreach ( $items as $it ) {
	$name    = isset( $it['name'] ) ? (string) $it['name'] : '';
	$metrics = isset( $it['metrics'] ) && is_array( $it['metrics'] ) ? $it['metrics'] : array();

	// Aggregate result across all metrics: any FAIL => FAIL; else any PASS => PASS; else empty
	$item_res  = '';
	$item_note = '';

	if ( ! empty( $metrics ) ) {
		$any_pass = false;
		$any_fail = false;
		$notes    = array();

		foreach ( $metrics as $m ) {
			$r = isset( $m['result'] ) ? (string) $m['result'] : '';
			if ( $r === 'fail' ) { $any_fail = true; }
			if ( $r === 'pass' ) { $any_pass = true; }
			$note = isset( $m['note'] ) ? trim( (string) $m['note'] ) : '';
			if ( $note !== '' ) { $notes[] = $note; }
		}

		if ( $any_fail )      $item_res = 'fail';
		elseif ( $any_pass )  $item_res = 'pass';

		$item_note = ! empty( $notes ) ? implode( ' | ', array_slice( $notes, 0, 3 ) ) : '';
	}

	$badge = '<span class="sfa-qg-badge ' . ( $item_res === 'pass' ? 'is-pass' : ( $item_res === 'fail' ? 'is-fail' : 'is-empty' ) ) . '">'
			 . ( $item_res === 'pass' ? 'PASS' : ( $item_res === 'fail' ? 'FAIL' : '–' ) )
			 . '</span>';

	echo '<div class="qg-row">';
	echo '<div class="qg-name">' . esc_html( $name ) . ( $item_note ? '<span class="qg-note">' . esc_html( $item_note ) . '</span>' : '' ) . '</div>';
	echo '<div class="qg-meta">' . $badge . '</div>';
	echo '</div>';
}


		// Summary footer (best-effort)
		$summary = isset( $payload['summary'] ) && is_array( $payload['summary'] ) ? $payload['summary'] : array();
		$items_total   = isset( $summary['items_total'] ) ? (int) $summary['items_total'] : count( $items );
		$metrics_total = isset( $summary['metrics_total'] ) ? (int) $summary['metrics_total'] : 0;
		$metrics_failed= isset( $summary['metrics_failed'] ) ? (int) $summary['metrics_failed'] : 0;

		echo '<div class="qg-summary">' .
		     esc_html__( 'Items', 'sfa-quality-gate' ) . ': ' . (int) $items_total . ' · ' .
		     esc_html__( 'Checks', 'sfa-quality-gate' ) . ': ' . (int) $metrics_total . ' · ' .
		     esc_html__( 'Failed', 'sfa-quality-gate' ) . ': ' . (int) $metrics_failed .
		     '</div>';

		echo '</div>';
		return ob_get_clean();
	}
}
