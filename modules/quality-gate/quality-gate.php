<?php
/**
 * SFA Quality Gate
 * Mode: Per-item from Upload field (Advanced tab)
 * Honors GF "Required" on QC field
 * Version: 2.3.25
 * Author: Omar Alnabhani (hdqah.com)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** ----------------------------------------------------------------
 *  Utils / constants
 * ----------------------------------------------------------------*/
if ( ! function_exists( 'sfa_qg_log' ) ) {
	function sfa_qg_log( $msg, $ctx = array() ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[SFA QG] ' . $msg . ( $ctx ? ' | ' . wp_json_encode( $ctx ) : '' ) );
		}
	}
}

if ( ! defined( 'SFA_QG_VER' ) ) define( 'SFA_QG_VER', '2.3.25');
if ( ! defined( 'SFA_QG_DIR' ) ) define( 'SFA_QG_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'SFA_QG_URL' ) ) define( 'SFA_QG_URL', plugin_dir_url( __FILE__ ) );

// Load report utilities and collectors early — canonical implementations
// live in report/ files. See report/collect.php for sfa_qg_report_collect(),
// sfa_qg_fixed_report_collect(), sfa_qg_report_collect_history();
// report/utils.php for sfa_qg_report_range_bounds(), sfa_qg_human_dur().
//
// DEFERRED: report/ and admin-tools files are loaded only when needed
// (report page, export, shortcode, admin tools) to avoid ~1300 lines of
// PHP parsing on every page load.

// Load extracted function groups — audit, helpers, rendering (needed by GF hooks on any page).
require_once __DIR__ . '/src/audit-functions.php';
require_once __DIR__ . '/src/helper-functions.php';
require_once __DIR__ . '/src/render-functions.php';

/**
 * Lazy-load report files (collect, render, admin-page, utils).
 * Called before any report rendering or data collection.
 */
function sfa_qg_load_report_files() {
	static $loaded = false;
	if ( $loaded ) return;
	$loaded = true;
	require_once __DIR__ . '/report/utils.php';
	require_once __DIR__ . '/report/collect.php';
	require_once __DIR__ . '/report/admin-page.php';
	require_once __DIR__ . '/report/export.php';
}

/**
 * Lazy-load admin tools (backfill, cleanup, audit peek).
 */
function sfa_qg_load_admin_tools() {
	static $loaded = false;
	if ( $loaded ) return;
	$loaded = true;
	require_once __DIR__ . '/src/admin-tools.php';
}

/**
 * Flush all QG report transient caches so the next report read rebuilds
 * fresh data. Called after write operations that change QC metadata.
 */
function sfa_qg_invalidate_report_cache() {
	global $wpdb;
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '_transient_sfa_qg_rpt_%'
		    OR option_name LIKE '_transient_timeout_sfa_qg_rpt_%'
		    OR option_name LIKE '_transient_sfa_qg_fx_%'
		    OR option_name LIKE '_transient_timeout_sfa_qg_fx_%'
		    OR option_name LIKE '_transient_sfa_qg_hist_%'
		    OR option_name LIKE '_transient_timeout_sfa_qg_hist_%'"
	);
}


/** ----------------------------------------------------------------
 *  Audit table hooks
 * ----------------------------------------------------------------*/
register_activation_hook( __FILE__, 'sfa_qg_install_audit_table' );
add_action( 'init', 'sfa_qg_maybe_install_audit_table', 1 );


/** ----------------------------------------------------------------
 *  Assets (registered once; enqueued where needed)
 * ----------------------------------------------------------------*/
add_action( 'init', function () {
	// Use filemtime for cache-busting only when WP_DEBUG is on; otherwise use stable version.
	$version = SFA_QG_VER;
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$js_file  = SFA_QG_DIR . 'assets/js/quality.js';
		$css_file = SFA_QG_DIR . 'assets/css/quality.css';
		$version  = SFA_QG_VER . '.' . max(
			file_exists( $js_file ) ? (int) filemtime( $js_file ) : 0,
			file_exists( $css_file ) ? (int) filemtime( $css_file ) : 0
		);
	}

	wp_register_script( 'sfa-qg', SFA_QG_URL . 'assets/js/quality.js', array( 'jquery' ), $version, true );
	wp_register_style( 'sfa-qg', SFA_QG_URL . 'assets/css/quality.css', array(), $version );
}, 5);

/**
 * Localize AJAX on both front and admin when script is enqueued.
 */
function sfa_qg_localize_ajax() {
	if ( wp_script_is( 'sfa-qg', 'enqueued' ) ) {
		wp_localize_script( 'sfa-qg', 'SFA_QG_AJAX', array(
			'url'   => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'sfa_qg' ),
		) );
	}
}

/**
 * Enqueue on front where GF forms load.
 */
add_action( 'gform_enqueue_scripts', function( $form, $is_ajax ) {
	wp_enqueue_script( 'sfa-qg' );
	wp_enqueue_style( 'sfa-qg' );
	sfa_qg_localize_ajax();

	// Footer-localize collected per-field configs (set by the field renderer).
	add_action( 'wp_footer', function() {
		if ( empty( $GLOBALS['sfa_qg_cfg'] ) ) return;
		wp_localize_script( 'sfa-qg', 'SFA_QG_CFG', array(
			'fields' => array_values( (array) $GLOBALS['sfa_qg_cfg'] ),
		) );
	}, 100 );
}, 10, 2 );

/**
 * Enqueue on Gravity Flow admin screens (inbox, entry, etc.).
 */
add_action( 'admin_enqueue_scripts', function () {
	if ( ! function_exists( 'get_current_screen' ) ) return;
	$screen = get_current_screen();
	if ( ! $screen ) return;

	$match = (
		strpos( $screen->id, 'gravityflow' ) !== false
		|| $screen->id === 'forms_page_gf_entries'
		|| $screen->id === 'toplevel_page_gravityflow-inbox'
	);
	if ( $match ) {
		wp_enqueue_script( 'sfa-qg' );
		wp_enqueue_style( 'sfa-qg' );
		sfa_qg_localize_ajax();
	}
}, 20 );

/** ----------------------------------------------------------------
 *  Field & Step registration
 * ----------------------------------------------------------------*/

add_action( 'gform_loaded', function () {
	// Bail early if GF core classes aren't ready.
	if ( ! class_exists( '\GF_Fields' ) ) {
		return;
	}

	// If the type is already registered, don't register again.
	if ( method_exists( '\GF_Fields', 'get' ) ) {
		$existing = \GF_Fields::get( 'quality_checklist' );
		if ( $existing ) {
			return;
		}
	}

	$file = SFA_QG_DIR . 'src/Field_Quality_Checklist.php';
	if ( ! file_exists( $file ) ) {
		return;
	}

	require_once $file;

	if ( ! class_exists( '\SFA\QualityGate\Field_Quality_Checklist' ) ) {
		return;
	}

	// Double-check again in case something registered during include.
	if ( method_exists( '\GF_Fields', 'get' ) && \GF_Fields::get( 'quality_checklist' ) ) {
		return;
	}

	\GF_Fields::register( new \SFA\QualityGate\Field_Quality_Checklist() );
}, 5 );


add_action( 'gravityflow_loaded', function () {
	$file = SFA_QG_DIR . 'src/Step_Quality_Gate.php';
	if ( class_exists( 'Gravity_Flow_Step' ) && file_exists( $file ) ) {
		require_once $file;
	}
}, 1 );

/** ----------------------------------------------------------------
 *  Editor: Add field button + settings UI
 * ----------------------------------------------------------------*/

// QG-206 — show ONE "Quality Checklist" button, under Advanced
add_filter( 'gform_add_field_buttons', function ( $groups ) {
	// Ensure Advanced exists
	$adv_i = null;
	foreach ( $groups as $i => $g ) {
		if ( isset( $g['name'] ) && $g['name'] === 'advanced_fields' ) { $adv_i = $i; break; }
	}
	if ( $adv_i === null ) {
		$groups[] = array( 'name' => 'advanced_fields', 'label' => esc_html__( 'Advanced Fields', 'gravityforms' ), 'fields' => array() );
		$adv_i = count( $groups ) - 1;
	}

	// Remove any existing "quality_checklist" buttons from ALL groups
	foreach ( $groups as $i => $g ) {
		if ( empty( $g['fields'] ) || ! is_array( $g['fields'] ) ) continue;
		$kept = array();
		foreach ( $g['fields'] as $f ) {
			$type = $f['data-type'] ?? ( $f['type'] ?? '' );
			if ( $type === 'quality_checklist' ) { continue; } // drop duplicates
			$kept[] = $f;
		}
		$groups[$i]['fields'] = $kept;
	}

	// Add exactly one back to Advanced
	$groups[$adv_i]['fields'][] = array(
		'class'     => 'button',
		'value'     => esc_html__( 'Quality Checklist', 'simpleflow' ),
		'data-type' => 'quality_checklist',
	);

	return $groups;
}, PHP_INT_MAX ); // run absolutely last


// Note: recheck items are persisted in the post-save hooks
// (gform_after_submission / gform_after_update_entry), NOT during
// gform_validation, because validation must not mutate state.


add_action( 'gform_field_advanced_settings', function ( $position, $form_id ) {
	if ( (int) $position !== 200 ) return; ?>
	<li class="sfa_qg_setting_source_upload field_setting">
		<label for="sfa_qg_source_upload_field" class="section_label">
			<?php esc_html_e( 'QC Source Upload field', 'simpleflow' ); ?>
		</label>
		<select id="sfa_qg_source_upload_field" onchange="SetFieldProperty('sfa_qg_source_upload_field', this.value);"></select>
		<p class="description"><?php esc_html_e( 'Choose a File Upload field; filenames will become QC items.', 'simpleflow' ); ?></p>
	</li>
	<li class="sfa_qg_setting_metrics field_setting">
	<label for="sfa_qg_metric_labels" class="section_label">
		<?php esc_html_e( 'Metric labels (one per line)', 'simpleflow' ); ?>
	</label>
	<textarea id="sfa_qg_metric_labels" class="fieldwidth-3" rows="6"
	          oninput="SetFieldProperty('sfa_qg_metric_labels', this.value);"
	          placeholder="<?php echo esc_attr__( 'e.g.'."\n".'Dimensions'."\n".'Finish'."\n".'Holes'."\n"."Packaging", 'simpleflow' ); ?>"></textarea>
	<p class="description"><?php esc_html_e( 'Up to 10 metrics. Leave blank to use a single "Overall" check.', 'simpleflow' ); ?></p>
</li>
<?php }, 10, 2 );

add_action( 'gform_field_standard_settings', function ( $position, $form_id ) {
	if ( (int) $position !== 150 ) return; ?>
	<li class="sfa_qg_setting_require_note field_setting">
		<input type="checkbox" id="sfa_qg_require_note_on_fail"
			onclick="SetFieldProperty('sfa_qg_require_note_on_fail', this.checked ? 1 : 0);" />
		<label for="sfa_qg_require_note_on_fail" class="inline">
			<?php esc_html_e( 'Require note when a QC metric fails', 'simpleflow' ); ?>
		</label>
	</li>
<?php }, 10, 2 );

add_action( 'gform_editor_js', function () { ?>
<script>
( function( $ ) {
	fieldSettings.quality_checklist = ( fieldSettings.quality_checklist || '' )
		+ ', .sfa_qg_setting_require_note'
		+ ', .sfa_qg_setting_source_upload'
		+ ', .sfa_qg_setting_metrics'
        + ', .sfa_qg_setting_metric_labels';

	function sfaQGPopulateUploadSelect( field ) {
		var $sel = $( '#sfa_qg_source_upload_field' );
		if ( !$sel.length ) return;
		$sel.empty();
		$sel.append( $('<option/>').val('').text('<?php echo esc_js( __( '-- Select upload field --', 'simpleflow' ) ); ?>') );

		if ( typeof GetFieldsByType === 'function' ) {
			var uploads = GetFieldsByType( ['fileupload'] ) || [];
			for ( var i = 0; i < uploads.length; i++ ) {
				var f = uploads[i];
				var label = ( f.label || ('Field ' + f.id) ) + ' (ID ' + f.id + ')';
				$sel.append( $('<option/>').val( f.id ).text( label ) );
			}
		}

		if ( field && field.sfa_qg_source_upload_field ) {
			$sel.val( String( field.sfa_qg_source_upload_field ) );
		}
	}

	$( document ).on( 'gform_load_field_settings', function( e, field ) {
		if ( field.type !== 'quality_checklist' ) return;
		$('#sfa_qg_require_note_on_fail').prop('checked', !!( field.sfa_qg_require_note_on_fail ));
		$('#sfa_qg_metric_labels').val(field.sfa_qg_metric_labels ? field.sfa_qg_metric_labels : '');
		sfaQGPopulateUploadSelect(field);
	} );
} )( jQuery );
</script>
<?php } );

/** ----------------------------------------------------------------
 *  AJAX: item discovery from an entry's Upload field
 * ----------------------------------------------------------------*/
function sfa_qg_ajax_items() {
	check_ajax_referer( 'sfa_qg', 'nonce' );

	$eid       = isset( $_POST['eid'] )      ? absint( $_POST['eid'] )      : 0;
	$source_id = isset( $_POST['sourceId'] ) ? absint( $_POST['sourceId'] ) : 0;

	if ( ! $eid || ! $source_id || ! class_exists( 'GFAPI' ) ) {
		wp_send_json_error( array( 'message' => 'missing params' ) );
	}

	$entry = \GFAPI::get_entry( $eid );
	if ( is_wp_error( $entry ) || ! is_array( $entry ) ) {
		wp_send_json_error( array( 'message' => 'entry not found' ) );
	}

	$raw = rgar( $entry, (string) $source_id );
	if ( ! $raw && isset( $entry[ $source_id ] ) ) {
		$raw = $entry[ $source_id ];
	}

	$items = sfa_qg_normalize_files( $raw );

	wp_send_json_success( array( 'items' => $items ) );
}
add_action( 'wp_ajax_sfa_qg_items',        'sfa_qg_ajax_items' );

/**
 * ----------------------------------------------------------------
 * Tiny Reporting (shortcode + admin page)
 * ----------------------------------------------------------------
 */


if ( ! function_exists( 'sfa_qg_report_shortcode' ) ) {
  function sfa_qg_report_shortcode( $atts = array() ) {
    sfa_qg_load_report_files();
    $a = shortcode_atts( array(
      'range'   => 'today',
      'form_id' => 0,
      'ym'      => '',
      'ym2'     => '',
    ), $atts, 'sfa_qg_report' );

    return sfa_qg_report_render_html( $a['range'], (int)$a['form_id'], $a['ym'], $a['ym2'] );
  }
  add_shortcode( 'sfa_qg_report', 'sfa_qg_report_shortcode' );
}


if ( ! function_exists( 'sfa_qg_report_admin_menu' ) ) {
	function sfa_qg_report_admin_menu() {
		$cap = current_user_can( 'gravityflow_workflow' ) ? 'gravityflow_workflow' : 'gravityforms_view_entries';
		add_submenu_page(
			'simpleflow',
			__( 'Quality Gate Report', 'simpleflow' ),
			__( 'Quality Gate Report', 'simpleflow' ),
			$cap,
			'sfa-qg-report',
			function() {
				sfa_qg_load_report_files();
				sfa_qg_report_admin_page();
			}
		);
	}
	add_action( 'admin_menu', 'sfa_qg_report_admin_menu', 200 );
}



// QG-203 — hide the raw checkbox bullets for the Recheck field in read-only renders.
// Works in Gravity Flow entry detail and GF admin/front-end renders.
add_filter( 'gform_entry_field_value', function ( $value, $field, $entry, $form ) {
    // Only checkboxes
    if ( ! is_object( $field ) || $field->type !== 'checkbox' ) {
        return $value;
    }

    // A. Prefer CSS markers (Admin CSS Class on the checkbox field):
    //    qg-rework or qg-rework-field
    $classes   = ' ' . strtolower( trim( (string) rgar( (array) $field, 'cssClass' ) ) ) . ' ';
    $has_marker = ( strpos( $classes, ' qg-rework ' ) !== false ) || ( strpos( $classes, ' qg-rework-field ' ) !== false );

    // B. Fallback: resolve by field id (heuristic finder)
    $is_target = $has_marker;
    if ( ! $is_target && function_exists( 'sfa_qg_find_fixed_checkbox_field_id' ) ) {
        $target_id = (int) sfa_qg_find_fixed_checkbox_field_id( $form );
        $is_target = $target_id && ( (int) $field->id === $target_id );
    }
    if ( ! $is_target ) {
        return $value;
    }

    // Skip when this field is actually editable on a User Input step
    if (
        is_array( $entry )
        && function_exists( 'sfa_qg_is_field_editable_on_user_input' )
        && sfa_qg_is_field_editable_on_user_input( $form, $entry, $field )
    ) {
        return $value;
    }

    // Idempotent: already wrapped?
    if ( is_string( $value ) && strpos( $value, 'qg-rework-value' ) !== false ) {
        return $value;
    }

    // Wrap so we can target just this value with CSS
    return ( is_string( $value ) && $value !== '' )
        ? '<div class="qg-rework-value">' . $value . '</div>'
        : $value;
}, 9999, 4 );



/* ======================================================================
 * QG-102 & QG-103 — Portable rework UX (no hard-coded IDs)
 * ====================================================================== */

/** Populate the checkbox with failed items in entry context. */
add_filter( 'gform_pre_render',       'sfa_qg_populate_rework_choices', 9999 );
add_filter( 'gform_pre_validation',   'sfa_qg_populate_rework_choices', 9999 );
add_filter( 'gform_admin_pre_render', 'sfa_qg_populate_rework_choices', 9999 );

/**
 * Populate the Rework checkbox with failed items, show the mini table,
 * and render the "Mark all fixed" button. (QG-102 / QG-103)
 */
function sfa_qg_populate_rework_choices( $form ) {
	// CRITICAL: Only run on forms that have a quality_checklist field
	if ( ! sfa_qg_form_has_quality_checklist( $form ) ) {
		return $form;
	}

	$entry_id = sfa_qg_current_entry_id();
	if ( ! $entry_id ) { return $form; }
$entry = null;
if ( class_exists( 'GFAPI' ) ) {
	$entry = \GFAPI::get_entry( $entry_id );
	if ( is_wp_error( $entry ) ) {
		$entry = null;
	}
}

	$target_id = sfa_qg_find_fixed_checkbox_field_id( $form );
	if ( ! $target_id ) { return $form; }

	// Don't show on the Quality Gate step itself.
	$curr_step = sfa_qg_current_step_id();
	$qg_step   = sfa_qg_find_quality_gate_step_id( $form );

	if ( $curr_step && $qg_step && $curr_step === $qg_step ) {
		return $form;
	}

	// Canonical list of failed item NAMES (works in all contexts)
	$failed = json_decode( (string) gform_get_meta( $entry_id, '_qc_failed_items' ), true );
	$failed = is_array( $failed )
		? array_values( array_filter( array_map( static function( $v ){ return trim( (string) $v ); }, $failed ) ) )
		: array();

	// Stamp first-seen fail time for each failed item (once).
if ( $entry_id && $failed ) {
	sfa_qg_stamp_fail_times_if_missing( $entry_id, $failed );
}



	// Map item => failed metric labels (for the table only)
	$failed_map = sfa_qg_failed_metric_map( $entry_id, $form );

	// Already fixed (saved) + currently ticked (live POST) = union
	$fixed_saved = json_decode( (string) gform_get_meta( $entry_id, '_qc_recheck_items' ), true );
	$fixed_saved = is_array( $fixed_saved ) ? array_map( 'strval', $fixed_saved ) : array();

	$fixed_now   = sfa_qg_collect_rework_values_from_post( $form, $target_id );
	$fixed_union = array_values( array_unique( array_filter( array_map( 'strval', array_merge( $fixed_saved, $fixed_now ) ) ) ) );

	// Check if all failed items have been marked as fixed (re-check context)
	$all_failed_items_fixed = false;
	if ( ! empty( $failed ) && ! empty( $fixed_union ) ) {
		$all_failed_items_fixed = empty( array_diff( $failed, $fixed_union ) );
	}

	// Are we on the rework (User Input) step?
	$editable = sfa_qg_is_rework_context( $form );

	// Helper table (adds row checkboxes only if $editable is true)
	$table_html = sfa_qg_render_failed_table( $failed_map, $fixed_union, $entry_id, $editable, $target_id );

foreach ( $form['fields'] as &$field ) {
	$field_type = $field->type;
	if ( (int) $field->id !== (int) $target_id || ($field_type !== 'checkbox' && $field_type !== 'radio') ) { continue; }

	/* 1) Replace choices with the canonical failed list (keeps GF happy + POST fallback) */
	$field->choices = array();
	foreach ( $failed as $name ) {
		if ( $name === '' ) { continue; }
		$field->choices[] = array(
			'text'       => $name,
			'value'      => $name,
			'isSelected' => in_array( $name, $fixed_union, true ),
		);
	}
	/* 1b) Rebuild inputs to match choices */
	$field->inputs = array();
	$idx = 1;
	foreach ( $field->choices as $c ) {
		$field->inputs[] = array(
			'id'    => $field->id . '.' . $idx,
			'label' => $c['text'],
			'name'  => '',
		);
		$idx++;
	}

	/* $entry is already resolved above — reuse it. */

/* Determine per-field editability on the current User Input step (robust) */
$editable_field = false;
$step_type      = '';

// Additional safety: Never make rework field editable on the QC step itself
$on_qc_step = false;
if ( $curr_step && $qg_step && $curr_step === $qg_step ) {
    $on_qc_step = true;
}

if ( function_exists('gravity_flow') && $entry ) {
    $sid  = sfa_qg_current_step_id(); // may be 0 on first render after transition
    $step = $sid ? gravity_flow()->get_step($sid) : gravity_flow()->get_current_step($form, $entry);

    if ( $step ) {
        $step_type = property_exists($step, '_step_type') ? (string) $step->_step_type : '';

        $is_user_input = (
            $step_type === 'user_input' ||
            ( class_exists('\Gravity_Flow_Step_User_Input') && $step instanceof \Gravity_Flow_Step_User_Input )
        );

        $is_quality_gate = (
            $step_type === 'quality_gate' ||
            ( class_exists('\SFA\QualityGate\Step_Quality_Gate') && $step instanceof \SFA\QualityGate\Step_Quality_Gate )
        );

        // CRITICAL: Check if this is a QC context (QC field is editable on this step)
        // If QC field is editable, we're doing inspection, so fixing field must be read-only
        $qc_field_editable = false;
        if ( $is_user_input ) {
            // Find the QC field ID
            $qc_field_id = 0;
            foreach ( (array) rgar( $form, 'fields', array() ) as $f ) {
                if ( rgar( (array) $f, 'type' ) === 'quality_checklist' ) {
                    $qc_field_id = (int) rgar( (array) $f, 'id' );
                    break;
                }
            }

            if ( $qc_field_id ) {
                // Check if QC field is in the editable fields list for this step
                if ( method_exists($step, 'is_editable_field') ) {
                    // Find the QC field object
                    $qc_field_obj = null;
                    foreach ( (array) rgar( $form, 'fields', array() ) as $f ) {
                        if ( (int) rgar( (array) $f, 'id' ) === $qc_field_id ) {
                            $qc_field_obj = $f;
                            break;
                        }
                    }
                    if ( $qc_field_obj ) {
                        $qc_field_editable = (bool) $step->is_editable_field($qc_field_obj, $form, $entry);
                    }
                } else {
                    $ids = method_exists($step, 'get_editable_fields') ? (array) $step->get_editable_fields() : array();
                    $ids = apply_filters('gravityflow_editable_fields', $ids, $step, $form, $entry);
                    $qc_field_editable = in_array($qc_field_id, array_map('intval', $ids), true);
                }
            }
        }

        // CRITICAL: The "Quality Fixing" field should NEVER be editable when:
        // 1. QC field is editable (QC context)
        // 2. All failed items have been marked as fixed (re-check context)
        if ( $is_quality_gate || $on_qc_step || $qc_field_editable || $all_failed_items_fixed ) {
            // Force $editable_field to remain false - don't check editable fields list
        } elseif ( $is_user_input ) {
            // Only on user_input steps (fixing/rework step), check if field is editable
            if ( function_exists('sfa_qg_is_field_editable_on_user_input') ) {
                $editable_field = sfa_qg_is_field_editable_on_user_input($form, $entry, $field);
            } elseif ( method_exists($step, 'is_editable_field') ) {
                $editable_field = (bool) $step->is_editable_field($field, $form, $entry);
            } else {
                $ids = method_exists($step, 'get_editable_fields') ? (array) $step->get_editable_fields() : array();
                $ids = apply_filters('gravityflow_editable_fields', $ids, $step, $form, $entry);
                $editable_field = in_array((int)$field->id, array_map('intval', $ids), true);
            }
        }
    }
}

/* No extra guards here. $editable_field is final. */

	/* Build the helper table (row checkboxes appear only when $editable_field is true) */
	$table_html_local = sfa_qg_render_failed_table( $failed_map, $fixed_union, $entry_id, $editable_field, $target_id );

	/* 2) Description block above the (hidden) GF checkbox list */
	$field->descriptionPlacement = 'above';

$desc  = '<div class="qg-rework-help"'
      . ' data-field-id="' . esc_attr( $target_id ) . '"'
      . ' data-editable="' . ( $editable_field ? '1' : '0' ) . '"'
      . ' data-items="' . count( $failed ) . '"'
      . ' data-fixed="' . esc_attr( wp_json_encode( $fixed_union ) ) . '"'
      . '>';

	// HIDE native GF checkbox UI and any read-only bullet lists in ALL cases (editable or not).
	$desc .= '<style>
		/* Hide the native checkbox container (front/admin) */
		.gfield:has(.qg-rework-help) .ginput_container_checkbox,
		.gfield:has(.qg-rework-help) .gfield_checkbox { display:none!important; }

		/* Hide any UL/OL GF prints under the description (admin single entry, detail views) */
		.qg-rework-help + ul,
		.qg-rework-help ~ ul,
		.qg-rework-help + ol,
		.qg-rework-help ~ ol { display:none!important; }

		/* Common pattern: description followed by UL */
		.gfield:has(.qg-rework-help) .gfield_description + ul { display:none!important; }

		/* Belt-and-suspenders: if something still renders controls when not editable, hide them */
		.qg-rework-help[data-editable="0"] .qg-rework-controls { display:none!important; }
	</style>';

	// Show controls ONLY when the field is editable on this step and there are failed items.
	if ( $editable_field && ! empty( $failed ) ) {
		$desc .= '<div class="qg-rework-controls" style="margin:0 0 8px 0;">'
		       .   '<button type="button" class="button qg-select-all-fixed" data-field-id="' . esc_attr( $target_id ) . '">'
		       .       esc_html__( 'Mark all fixed', 'simpleflow' )
		       .   '</button>'
		       . '</div>';
	}

	// Table always visible for context (row checkboxes only if editable)
	$desc .= $table_html_local ?: '<p class="description" style="margin:0;">' . esc_html__( 'No failed items for this entry.', 'simpleflow' ) . '</p>';

	$desc .= '</div>';
	$field->description = $desc;



// Stable CSS hook + ensure 'qg-rework' for entry-value filter
$existing_css = isset( $field->cssClass ) ? trim( (string) $field->cssClass ) : '';
$classes = ' ' . $existing_css . ' ';

if ( strpos( $classes, ' qg-rework-field ' ) === false ) {
    $existing_css .= ' qg-rework-field';
    $classes      .= ' qg-rework-field ';
}
if ( strpos( $classes, ' qg-rework ' ) === false ) {
    $existing_css .= ' qg-rework';
}
$field->cssClass = trim( $existing_css );

	break;
}

return $form;

}



// QG-106 — Require all failed items to be ticked as fixed before submit.
// Require all failed items (the rework field choices) to be ticked before submit.
add_filter( 'gform_validation', function( $result ) {
	$form     = $result['form'];

	// Only enforce on the actual rework (User Input) step.
	if ( ! sfa_qg_is_rework_context( $form ) ) {
		return $result;
	}

	$field_id = sfa_qg_find_fixed_checkbox_field_id( $form );
	if ( ! $field_id ) { return $result; }

	// The required list = the choices we injected for the rework field (i.e., failed items).
	$required = array();
	foreach ( $form['fields'] as $f ) {
		$field_type = $f->type;
		if ( (int) $f->id !== (int) $field_id || ($field_type !== 'checkbox' && $field_type !== 'radio') ) { continue; }
		foreach ( (array) $f->choices as $c ) {
			$val = trim( (string) rgar( (array) $c, 'value', rgar( (array) $c, 'text', '' ) ) );
			if ( $val !== '' ) { $required[] = $val; }
		}
		break;
	}

	// If somehow choices are not present (edge case), fall back to meta.
	if ( ! $required ) {
		$entry_id = sfa_qg_current_entry_id();
		if ( $entry_id ) {
			$tmp = json_decode( (string) gform_get_meta( $entry_id, '_qc_failed_items' ), true );
			if ( is_array( $tmp ) ) {
				$required = array_values( array_unique( array_filter( array_map( 'strval', $tmp ) ) ) );
			}
		}
	}
	if ( ! $required ) { return $result; }

	// What the user ticked now (supports input_X_Y and input_X[]).
	$selected = sfa_qg_collect_rework_values_from_post( $form, $field_id );

	$missing = array_values( array_diff( $required, $selected ) );
	if ( $missing ) {
		$result['is_valid'] = false;
		foreach ( $form['fields'] as &$fld ) {
			if ( (int) $fld->id === (int) $field_id ) {
				$fld->failed_validation  = true;
				$fld->validation_message = sprintf(
					esc_html__( 'You must mark all failed items as fixed before submitting. Missing: %s', 'simpleflow' ),
					esc_html( implode( ', ', $missing ) )
				);
			}
		}
		$result['form'] = $form;
	}
	return $result;
}, 9999 );



/** ----------------------------------------------------------------
 *  Recheck items persistence
 * ----------------------------------------------------------------*/

function sfa_qg_save_recheck_items_from_post( $form, $entry_id ) {
	// Prevent double execution when multiple hooks fire for the same entry
	// (e.g. gravityflow_post_update_user_input + gform_after_update_entry).
	static $processed = array();
	if ( isset( $processed[ (int) $entry_id ] ) ) {
		return;
	}
	$processed[ (int) $entry_id ] = true;

	// CRITICAL: Only run on forms that have a quality_checklist field
	if ( ! sfa_qg_form_has_quality_checklist( $form ) ) {
		// Also check the full form in case a trimmed array was passed
		if ( class_exists( '\GFAPI' ) && ! empty( $form['id'] ) ) {
			$full = \GFAPI::get_form( (int) $form['id'] );
			if ( ! is_array( $full ) || ! sfa_qg_form_has_quality_checklist( $full ) ) {
				return;
			}
			$form = $full; // Use full form for subsequent processing
		} else {
			return;
		}
	}

	$field_id = sfa_qg_find_fixed_checkbox_field_id( $form );
if ( ! $field_id && class_exists('\GFAPI') && ! empty($form['id']) ) {
    // Fallback: load the full form (some hooks pass a trimmed form array)
    $full = \GFAPI::get_form( (int) $form['id'] );
    if ( is_array( $full ) ) {
        $field_id = sfa_qg_find_fixed_checkbox_field_id( $full );
    }
}
if ( ! $field_id ) {
    return;
}


	// 1) Try POST (supports input_X_Y and input_X[])
	$selected = sfa_qg_collect_rework_values_from_post( $form, $field_id );

	// 2) Fallback: read labels from UPDATED entry
	if ( empty($selected) && class_exists('\GFAPI') ) {
		$entry = \GFAPI::get_entry( $entry_id );
		if ( ! is_wp_error($entry) && is_array($entry) ) {
			$gf_field = class_exists('\GFFormsModel') ? \GFFormsModel::get_field( $form, $field_id ) : null;
			if ( $gf_field && $gf_field->type === 'checkbox' ) {
				$subs = is_callable([$gf_field,'get_entry_inputs']) ? $gf_field->get_entry_inputs() : null;
				if ( is_array($subs) && $subs ) {
					foreach ( $subs as $sub ) {
						$key = isset($sub['id']) ? (string)$sub['id'] : '';
						if ( $key === '' ) continue;
						$val = rgar($entry, $key);
						if ( is_string($val) && $val !== '' ) $selected[] = $val;
					}
				} else {
					$raw = rgar($entry, (string)$field_id);
					if ( is_array($raw) ) {
						foreach ($raw as $v) if (is_string($v) && $v!=='') $selected[]=$v;
					} elseif ( is_string($raw) && $raw!=='') {
						foreach (explode(',', $raw) as $v) { $v=trim($v); if($v!=='') $selected[]=$v; }
					}
				}
			}
		}
	}

	$selected = array_values( array_unique( array_filter( array_map('strval', $selected) ) ) );
	gform_update_meta( $entry_id, '_qc_recheck_items', wp_json_encode( $selected ) );

	// Flush report transient caches so the next report read picks up fresh data.
	sfa_qg_invalidate_report_cache();

	sfa_qg_history_push( $entry_id, 'REWORK_MARKED', ['items'=>$selected,'user'=>get_current_user_id()] );


	// === Ensure fixed events are logged whenever we persist the selection ===
try {
    $form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

    // Already logged (case-insensitive) => avoid duplicates
    $already = array();
    foreach ( sfa_qg_fixed_log_get( (int) $entry_id ) as $ev ) {
        $already[ strtolower( (string) ( $ev['item'] ?? '' ) ) ] = true;
    }

    // Only add newly-marked items
    $to_add = array();
    foreach ( (array) $selected as $name ) {
        $name = trim( (string) $name );
        if ( $name === '' ) continue;
        if ( ! isset( $already[ strtolower( $name ) ] ) ) {
            $to_add[] = $name;
        }
    }

    if ( $to_add ) {
        $step_id = function_exists( 'sfa_qg_current_step_id' ) ? sfa_qg_current_step_id() : 0;
        $added   = sfa_qg_fixed_log_append_items( $form_id, (int) $entry_id, $to_add, $step_id );
    }
} catch ( \Throwable $t ) {
    // Error handling
}

}


/** ----------------------------------------------------------------
 *  Failed items persistence from QC field + hook registrations
 * ----------------------------------------------------------------*/

// Fires when a Gravity Flow User Input step is saved (rework screen).
// Delegates to sfa_qg_save_recheck_items_from_post which handles meta
// persistence, history logging, and fixed-log dedup in one place.
add_action('gravityflow_post_update_user_input', function( $step, $entry_id, $form ) {
	if ( ! sfa_qg_form_has_quality_checklist( $form ) ) {
		return;
	}
	sfa_qg_save_recheck_items_from_post( $form, (int) $entry_id );
}, 10, 3);

// --- Persist failed items/metrics from the QC field JSON (robust) ---
if ( ! function_exists( 'sfa_qg_persist_fails_from_qc' ) ) {
	function sfa_qg_persist_fails_from_qc( $form, $entry_id ) {
		if ( ! class_exists( 'GFAPI' ) ) return;

		$entry = \GFAPI::get_entry( (int) $entry_id );
		if ( is_wp_error( $entry ) || ! is_array( $entry ) ) return;

		// Find the QC field id (try passed $form first, then full form).
		$qc_field_id = 0;
		foreach ( (array) rgar( $form, 'fields', array() ) as $f ) {
			if ( rgar( (array) $f, 'type' ) === 'quality_checklist' ) {
				$qc_field_id = (int) rgar( (array) $f, 'id' );
				break;
			}
		}
		if ( ! $qc_field_id && ! empty( $entry['form_id'] ) && class_exists( 'GFAPI' ) ) {
			$full = \GFAPI::get_form( (int) $entry['form_id'] );
			if ( is_array( $full ) ) {
				foreach ( (array) rgar( $full, 'fields', array() ) as $f ) {
					if ( rgar( (array) $f, 'type' ) === 'quality_checklist' ) {
						$qc_field_id = (int) rgar( (array) $f, 'id' );
						break;
					}
				}
			}
		}
		if ( ! $qc_field_id ) return;

		$raw = rgar( $entry, (string) $qc_field_id );
		if ( ! is_string( $raw ) || $raw === '' ) return;

		$val = json_decode( (string) $raw, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $val ) ) return;

		$failed_items   = array();
		$failed_metrics = array();

		foreach ( (array) rgar( $val, 'items', array() ) as $it ) {
			$name = trim( (string) rgar( $it, 'name' ) );
			if ( $name === '' ) continue;

			$item_failed = false;
			foreach ( (array) rgar( $it, 'metrics', array() ) as $m ) {
				$res   = strtolower( (string) rgar( $m, 'result', '' ) );
				$label = trim( (string) rgar( $m, 'label', '' ) );
				if ( $label === '' ) {
					$label = trim( (string) rgar( $m, 'k', '' ) ); // fallback for older payloads
				}
				if ( $res === 'fail' ) {
					$item_failed = true;
					if ( $label !== '' ) $failed_metrics[] = $label;
				}
			}
			if ( $item_failed ) $failed_items[] = $name;
		}

		$failed_items   = array_values( array_unique( array_filter( array_map( 'strval', $failed_items ) ) ) );
		$failed_metrics = array_values( array_unique( array_filter( array_map( 'strval', $failed_metrics ) ) ) );

		// Persist meta so reporting can read it.
		gform_update_meta( (int) $entry_id, '_qc_failed_items',   wp_json_encode( $failed_items ) );
		gform_update_meta( (int) $entry_id, '_qc_failed_metrics', wp_json_encode( $failed_metrics ) );

		// Flush report transient caches so the next report read picks up fresh data.
		sfa_qg_invalidate_report_cache();

		// Ensure per-item fail timestamps + audit FAIL rows (idempotent).
		if ( $failed_items ) {
			if ( function_exists( 'sfa_qg_stamp_fail_times_if_missing' ) ) {
				sfa_qg_stamp_fail_times_if_missing( (int) $entry_id, $failed_items );
			}
		}

		// Optional: also log metric-level fail events for the "Top failing metrics" panel.
		if ( function_exists( 'sfa_qg_audit_log_fail' ) && function_exists( 'sfa_qg_audit_fail_exists' ) ) {
			$form_id = isset( $form['id'] ) ? (int) $form['id'] : (int) rgar( $entry, 'form_id', 0 );
			foreach ( $failed_metrics as $label ) {
				$metric_key = 'metric:' . sanitize_title( $label );
				if ( ! sfa_qg_audit_fail_exists( (int) $entry_id, $metric_key ) ) {
					sfa_qg_audit_log_fail( $form_id, (int) $entry_id, $metric_key, $label );
				}
			}
		}

	}
}

// Call it whenever an entry is created/updated.
add_action( 'gform_after_submission', function( $entry, $form ) {
	if ( isset( $entry['id'] ) ) {
		sfa_qg_persist_fails_from_qc( $form, (int) $entry['id'] );
	}
}, 9, 2 ); // run before your existing handler if possible

add_action( 'gform_after_update_entry', function( $form, $entry_id ) {
	sfa_qg_persist_fails_from_qc( $form, (int) $entry_id );
}, 9, 2 );


add_action('gform_after_submission', function($entry, $form){
	sfa_qg_save_recheck_items_from_post($form, (int)$entry['id']);
}, 10, 2);



add_action('gform_after_update_entry', function($form, $entry_id){
	sfa_qg_save_recheck_items_from_post($form, (int)$entry_id);
}, 10, 2);


/** ----------------------------------------------------------------
 *  Entry detail sidebar + admin tools hook registrations
 * ----------------------------------------------------------------*/
add_action( 'gform_entry_detail_sidebar_middle', 'sfa_qg_entry_qc_summary_box', 10, 2 );

// Admin tools + export: load files on-demand only when relevant GET params are present.
add_action( 'admin_init', function() {
	// Admin tools (backfill, cleanup, auditpeek) — only load when triggered.
	if ( ! empty( $_GET['sfa_qg_backfill'] ) || ! empty( $_GET['sfa_qg_cleanup'] ) || ! empty( $_GET['sfa_qg_auditpeek'] ) ) {
		sfa_qg_load_admin_tools();
		if ( ! empty( $_GET['sfa_qg_backfill'] ) )  { sfa_qg_admin_backfill(); }
		if ( ! empty( $_GET['sfa_qg_cleanup'] ) )    { sfa_qg_admin_cleanup(); }
		if ( ! empty( $_GET['sfa_qg_auditpeek'] ) )  { sfa_qg_admin_auditpeek(); }
	}
}, 99 );

add_action( 'admin_init', function() {
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'sfa-qg-report' ) return;
	if ( ! isset( $_GET['qg_export'] ) ) return;
	sfa_qg_load_report_files();
	sfa_qg_handle_export();
}, 1 );
