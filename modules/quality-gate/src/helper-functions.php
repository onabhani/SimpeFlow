<?php
/**
 * Quality Gate — Detection and utility helper functions.
 *
 * Extracted from quality-gate.php for maintainability.
 * All functions are guarded with function_exists() where needed.
 *
 * @package SFA\QualityGate
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// --- Entry / step ID detection ---------------------------------------------------

// Robust entry-id detection for GF / Gravity Flow screens.
if ( ! function_exists( 'sfa_qg_current_entry_id' ) ) {
	function sfa_qg_current_entry_id() {
		$keys = array( 'lid', 'entry_id', 'entryId', 'eid' );
		foreach ( $keys as $k ) {
			if ( isset( $_GET[ $k ] ) && $_GET[ $k ] !== '' )  return absint( $_GET[ $k ] );
			if ( isset( $_POST[ $k ] ) && $_POST[ $k ] !== '' ) return absint( $_POST[ $k ] );
		}
		// GF helper if available
		if ( function_exists( 'rgget' ) ) {
			$lid = rgget( 'lid' );
			if ( $lid ) return absint( $lid );
		}
		return 0;
	}
}

/**
 * Get current Gravity Flow step ID from request parameters.
 *
 * SECURITY NOTE: Uses absint() to sanitize all input. This is safe for reading
 * step IDs which are always integers. Gravity Flow performs its own authorization
 * checks to ensure users can only access steps they have permission for.
 */
if ( ! function_exists( 'sfa_qg_current_step_id' ) ) {
	function sfa_qg_current_step_id() {
		// Gravity Flow sometimes uses different keys or none at all.
		$keys = array( 'step', 'step_id', 'gflow_step', 'workflow_step', 'current_step' );
		foreach ( $keys as $k ) {
			// Using absint() ensures we only get positive integers, preventing injection
			if ( isset( $_GET[ $k ] ) && $_GET[ $k ] !== '' ) return absint( $_GET[ $k ] );
			if ( isset( $_POST[ $k ] ) && $_POST[ $k ] !== '' ) return absint( $_POST[ $k ] );
		}
		return 0;
	}
}

// --- Form / field detection ------------------------------------------------------

/** Find the Quality Gate step for this form (future use / filters can rely on it). */
function sfa_qg_find_quality_gate_step_id( $form ) {
	$step_id = 0;
	if ( function_exists( 'gravity_flow' ) && ! empty( $form['id'] ) ) {
		$steps = gravity_flow()->get_steps( (int) $form['id'] );
		foreach ( (array) $steps as $step ) {
			$matches_type  = ( property_exists( $step, '_step_type' ) && $step->_step_type === 'quality_gate' );
			$matches_class = ( $step instanceof \SFA\QualityGate\Step_Quality_Gate );
			if ( $matches_type || $matches_class ) {
				$step_id = (int) ( method_exists( $step, 'get_id' ) ? $step->get_id() : $step->id );
				break;
			}
		}
	}
	return (int) apply_filters( 'sfa_qg/quality_gate_step_id', $step_id, $form );
}

/**
 * Check if a form has a quality_checklist field.
 * This is the core validation to prevent QG features from running on non-QG forms.
 */
if ( ! function_exists( 'sfa_qg_form_has_quality_checklist' ) ) {
	function sfa_qg_form_has_quality_checklist( $form ) {
		if ( empty( $form ) || ! is_array( $form ) ) {
			return false;
		}
		foreach ( (array) rgar( $form, 'fields', array() ) as $f ) {
			if ( rgar( (array) $f, 'type' ) === 'quality_checklist' ) {
				return true;
			}
		}
		return false;
	}
}

// Locate the "Fixed items" checkbox/radio reliably (no hard-coded IDs).
if ( ! function_exists( 'sfa_qg_find_fixed_checkbox_field_id' ) ) {
	function sfa_qg_find_fixed_checkbox_field_id( $form ) {
		$single_fix = 0;   // a checkbox/radio that has exactly one choice called "Fix"
		$empty      = 0;   // a checkbox/radio with no choices yet
		$first      = 0;   // first checkbox/radio id (for the 1-field legacy case)
		$count      = 0;   // number of checkbox/radio fields

		foreach ( (array) rgar( $form, 'fields', array() ) as $f ) {
			$fa = (array) $f;
			$field_type = rgar( $fa, 'type' );
			if ( $field_type !== 'checkbox' && $field_type !== 'radio' ) {
				continue;
			}
			$count++;
			$first = $first ?: (int) rgar( $fa, 'id' );

			$adm = strtolower( (string) rgar( $fa, 'adminLabel' ) );
			$lbl = strtolower( (string) rgar( $fa, 'label' ) );
			$css = strtolower( (string) rgar( $fa, 'cssClass' ) );

			// 1) Explicit markers (preferred).
			//    Arabic: \xd8\xa5\xd8\xb5\xd9\x84\xd8\xa7\xd8\xad = إصلاح (repair)
			//           \xd8\xaa\xd8\xb5\xd8\xad\xd9\x8a\xd8\xad = تصحيح (correction)
			if (
				$adm === 'qg_fixed' || $adm === 'qc_fixed' ||
				strpos( $css, 'qg-fixed' ) !== false ||
				strpos( $lbl, 'fixed' ) !== false || strpos( $lbl, 'rework' ) !== false || strpos( $lbl, 'fix' ) !== false ||
				strpos( $lbl, "\xd8\xa5\xd8\xb5\xd9\x84\xd8\xa7\xd8\xad" ) !== false || strpos( $lbl, "\xd8\xaa\xd8\xb5\xd8\xad\xd9\x8a\xd8\xad" ) !== false
			) {
				return (int) rgar( $fa, 'id' );
			}

			// 2) Heuristic: exactly one choice named "Fix".
			$choices = (array) rgar( $fa, 'choices', array() );
			if ( count( $choices ) === 1 ) {
				$txt = strtolower( trim( (string) rgar( $choices[0], 'text' ) ) );
				if ( $txt === 'fix' ) {
					$single_fix = (int) rgar( $fa, 'id' );
				}
			}

			// 3) Heuristic: empty checkbox/radio (no choices yet).
			if ( empty( $choices ) && ! $empty ) {
				$empty = (int) rgar( $fa, 'id' );
			}
		}

		// 4) Back-compat: if there is exactly one checkbox/radio in the form, use it.
		if ( $count === 1 && $first ) {
			return $first;
		}

		// 5) Prefer our heuristics; do NOT pick a random checkbox/radio.
		if ( $single_fix ) return $single_fix;
		if ( $empty )      return $empty;

		// Ambiguous → do nothing; avoids touching the wrong field.
		return 0;
	}
}

// --- Rework context detection ----------------------------------------------------

/**
 * Collect ONLY the selected values from the rework checkbox field (supports input_X_Y and input_X[]).
 *
 * SECURITY NOTE: This function accesses $_POST data but is designed to be called only within
 * Gravity Forms hooks (gform_pre_render, gform_validation, etc.) which already perform
 * nonce verification. Do not call this function outside of validated GF contexts.
 */
if ( ! function_exists( 'sfa_qg_collect_rework_values_from_post' ) ) {
	function sfa_qg_collect_rework_values_from_post( $form, $field_id ) {
		// Defensive check: ensure we're in a Gravity Forms context
		if ( ! is_array( $form ) || ! isset( $form['fields'] ) ) {
			return array();
		}

		$selected = array();

		// Build whitelist of allowed values from the field's choices
		$allowed_values = array();
		$target_field   = null;
		foreach ( (array) rgar( $form, 'fields', array() ) as $f ) {
			if ( (int) rgar( (array) $f, 'id' ) === (int) $field_id && $f->type === 'checkbox' ) {
				$target_field = $f;
				if ( ! empty( $f->choices ) ) {
					foreach ( $f->choices as $c ) {
						$cv = trim( (string) rgar( (array) $c, 'value', rgar( (array) $c, 'text', '' ) ) );
						if ( $cv !== '' ) {
							$allowed_values[] = $cv;
						}
					}
				}
				break;
			}
		}

		if ( ! $target_field || empty( $target_field->inputs ) ) {
			return array();
		}

		// Standard GF style: input_5_1, input_5_2, ...
		foreach ( $target_field->inputs as $inp ) {
			$key = 'input_' . str_replace( '.', '_', $inp['id'] );
			$val = isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
			if ( $val !== '' ) {
				$selected[] = $val;
			}
		}

		// Array style: input_5[]
		if ( empty( $selected ) ) {
			$key = 'input_' . $field_id;
			if ( isset( $_POST[ $key ] ) ) {
				$vals = (array) wp_unslash( $_POST[ $key ] );
				foreach ( $vals as $v ) {
					$v = (string) $v;
					if ( $v !== '' ) {
						$selected[] = $v;
					}
				}
			}
		}

		// Validate: only accept values that match known field choices
		if ( $allowed_values ) {
			$selected = array_values( array_intersect( $selected, $allowed_values ) );
		}

		return array_values( array_unique( $selected ) );
	}
}

/**
 * Detect if we are on a Gravity Flow **User Input** step that is a rework step
 * (i.e., QC field is NOT editable — if QC is editable, it's an inspection step).
 */
if ( ! function_exists( 'sfa_qg_is_rework_context' ) ) {
	function sfa_qg_is_rework_context( $form ) {
		if ( ! function_exists( 'gravity_flow' ) ) {
			return false;
		}

		// Helper: check if QC field is editable on the given step for a given entry.
		$qc_is_editable_on_step = function( $step, $form, $entry ) {
			$qc_field_id = 0;
			foreach ( (array) rgar( $form, 'fields', array() ) as $f ) {
				if ( rgar( (array) $f, 'type' ) === 'quality_checklist' ) {
					$qc_field_id = (int) rgar( (array) $f, 'id' );
					break;
				}
			}
			if ( ! $qc_field_id ) return false;

			if ( method_exists( $step, 'is_editable_field' ) ) {
				$qc_field_obj = null;
				foreach ( (array) rgar( $form, 'fields', array() ) as $f ) {
					if ( (int) rgar( (array) $f, 'id' ) === $qc_field_id ) {
						$qc_field_obj = $f;
						break;
					}
				}
				return $qc_field_obj ? (bool) $step->is_editable_field( $qc_field_obj, $form, $entry ) : false;
			}

			$ids = method_exists( $step, 'get_editable_fields' ) ? (array) $step->get_editable_fields() : array();
			$ids = apply_filters( 'gravityflow_editable_fields', $ids, $step, $form, $entry );
			return in_array( $qc_field_id, array_map( 'intval', $ids ), true );
		};

		// --- Try 1: explicit step id from the request ---
		$step_id = sfa_qg_current_step_id();
		if ( $step_id ) {
			$step = gravity_flow()->get_step( $step_id );
			if ( $step ) {
				// Not the Quality Gate itself
				if (
					( property_exists( $step, '_step_type' ) && $step->_step_type === 'quality_gate' ) ||
					( class_exists( '\SFA\QualityGate\Step_Quality_Gate' ) && $step instanceof \SFA\QualityGate\Step_Quality_Gate )
				) {
					return false;
				}
				// User Input step — but only if QC field is NOT editable (rework, not inspection)
				$is_user_input = (
					( property_exists( $step, '_step_type' ) && $step->_step_type === 'user_input' ) ||
					( class_exists( '\Gravity_Flow_Step_User_Input' ) && $step instanceof \Gravity_Flow_Step_User_Input )
				);
				if ( $is_user_input ) {
					$entry_id = sfa_qg_current_entry_id();
					$entry    = ( $entry_id && class_exists( 'GFAPI' ) ) ? \GFAPI::get_entry( $entry_id ) : null;
					if ( is_wp_error( $entry ) ) $entry = null;
					// If QC is editable, this is an inspection step, not rework
					if ( $entry && $qc_is_editable_on_step( $step, $form, $entry ) ) {
						return false;
					}
					return true;
				}
			}
		}

		// --- Try 2: resolve the entry's *current* step (works when no step param is present) ---
		$entry_id = sfa_qg_current_entry_id();
		if ( $entry_id && class_exists( 'GFAPI' ) ) {
			$entry = \GFAPI::get_entry( $entry_id );
			if ( ! is_wp_error( $entry ) ) {
				$curr = gravity_flow()->get_current_step( $form, $entry );
				if ( $curr ) {
					$is_user_input = (
						( property_exists( $curr, '_step_type' ) && $curr->_step_type === 'user_input' ) ||
						( class_exists( '\Gravity_Flow_Step_User_Input' ) && $curr instanceof \Gravity_Flow_Step_User_Input )
					);
					if ( $is_user_input ) {
						if ( $qc_is_editable_on_step( $curr, $form, $entry ) ) {
							return false;
						}
						return true;
					}
				}
			}
		}

		return false;
	}
}

if ( ! function_exists( 'sfa_qg_is_field_editable_on_user_input' ) ) {
	function sfa_qg_is_field_editable_on_user_input( $form, $entry, $field ) {
		if ( ! function_exists( 'gravity_flow' ) ) {
			return false;
		}

		// Resolve the step: explicit ?step=... first, else entry's current step
		$step_id = function_exists( 'sfa_qg_current_step_id' ) ? sfa_qg_current_step_id() : 0;
		$step    = $step_id ? gravity_flow()->get_step( $step_id ) : gravity_flow()->get_current_step( $form, $entry );
		if ( ! $step ) {
			return false;
		}

		// Must be a User Input step
		$is_user_input = (
			( property_exists( $step, '_step_type' ) && $step->_step_type === 'user_input' ) ||
			( class_exists( '\Gravity_Flow_Step_User_Input' ) && $step instanceof \Gravity_Flow_Step_User_Input )
		);
		if ( ! $is_user_input ) {
			return false;
		}

		// Prefer the step API if available
		if ( method_exists( $step, 'is_editable_field' ) ) {
			return (bool) $step->is_editable_field( $field, $form, $entry );
		}

		// Fallback to editable fields array + documented filter
		$editable_ids = method_exists( $step, 'get_editable_fields' ) ? (array) $step->get_editable_fields() : array();
		// Allow site-level overrides (per docs: gravityflow_editable_fields)
		$editable_ids = apply_filters( 'gravityflow_editable_fields', $editable_ids, $step, $form, $entry );

		// Normalize to ints/strings, check membership
		$field_id = (int) ( is_object( $field ) ? $field->id : rgar( (array) $field, 'id', 0 ) );
		$norm = array();
		foreach ( $editable_ids as $id ) { $norm[] = (int) $id; }

		return in_array( $field_id, $norm, true );
	}
}

// --- File normalization ----------------------------------------------------------

/**
 * Parse raw file data (JSON/CSV) from a GF upload field into normalized items.
 */
function sfa_qg_normalize_files( $raw ) {
	if ( empty( $raw ) ) return array();

	$push = function( $src, &$out ) {
		if ( ! is_string( $src ) || $src === '' ) return;
		$out[] = $src;
	};

	$urls = array();

	if ( is_string( $raw ) ) {
		$decoded = json_decode( $raw, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			foreach ( (array) $decoded as $item ) {
				if ( is_string( $item ) )           $push( $item, $urls );
				elseif ( is_array( $item ) ) {
					foreach ( array( 'uploaded_filename', 'uploaded_file', 'url', 'temp_filename', 'file', 'name', 'filename' ) as $k ) {
						if ( ! empty( $item[ $k ] ) ) { $push( (string) $item[ $k ], $urls ); break; }
					}
				}
			}
		} else {
			$raw = str_replace( '|', ',', $raw );
			foreach ( array_map( 'trim', explode( ',', $raw ) ) as $p ) {
				if ( $p !== '' ) $push( $p, $urls );
			}
		}
	} elseif ( is_array( $raw ) ) {
		foreach ( $raw as $item ) {
			if ( is_string( $item ) ) $push( $item, $urls );
			elseif ( is_array( $item ) ) {
				foreach ( array( 'uploaded_filename', 'uploaded_file', 'url', 'temp_filename', 'file', 'name', 'filename' ) as $k ) {
					if ( ! empty( $item[ $k ] ) ) { $push( (string) $item[ $k ], $urls ); break; }
				}
			}
		}
	}

	$items = array();
	foreach ( $urls as $s ) {
		$b = wp_basename( $s );
		if ( $b === '' ) continue;
		$n = preg_replace( '/\.[^.]+$/', '', $b );
		if ( $n !== '' ) $items[] = array( 'name' => $n );
	}
	return $items;
}
