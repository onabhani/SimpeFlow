<?php
namespace SFA\CodeValidation\GravityForms;

/**
 * Validator
 *
 * Validates that a value entered in a form field exists as an entry
 * in a source form's field. Used for confirmation code validation.
 *
 * Rules are stored in the 'sfa_cv_rules' option as JSON array.
 */
class Validator {

	/**
	 * @var array Loaded validation rules
	 */
	private $rules = [];

	/**
	 * @var array Rules indexed by target form ID: [ form_id => [ rule, ... ] ]
	 */
	private $rules_by_form = [];

	/**
	 * @var bool Whether the main JS has been output
	 */
	private static $is_script_output = false;

	public function __construct() {
		$this->rules = self::get_rules();

		if ( empty( $this->rules ) ) {
			return;
		}

		foreach ( $this->rules as $rule ) {
			$fid = (int) $rule['target_form_id'];
			$this->rules_by_form[ $fid ][] = $rule;
		}

		add_filter( 'gform_validation', [ $this, 'validate' ] );
		add_action( 'gform_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_filter( 'gform_pre_render', [ $this, 'maybe_output_script' ], 10, 2 );
		add_action( 'gform_register_init_scripts', [ $this, 'add_init_scripts' ] );
		add_action( 'wp_ajax_sfa_cv_check', [ $this, 'ajax_check' ] );
		add_action( 'wp_ajax_nopriv_sfa_cv_check', [ $this, 'ajax_check' ] );
	}

	/**
	 * Get all validation rules from settings
	 *
	 * @return array
	 */
	public static function get_rules() {
		$rules = get_option( 'sfa_cv_rules', '[]' );
		$decoded = json_decode( $rules, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Save validation rules to settings
	 *
	 * @param array $rules
	 */
	public static function save_rules( $rules ) {
		update_option( 'sfa_cv_rules', wp_json_encode( array_values( $rules ) ) );
	}

	/**
	 * Get rules applicable to a specific form
	 *
	 * @param int $form_id
	 * @return array
	 */
	private function get_rules_for_form( $form_id ) {
		return $this->rules_by_form[ (int) $form_id ] ?? [];
	}

	/**
	 * Build the field map for a rule
	 *
	 * @param array $rule
	 * @return array source_field_id => target_field_id
	 */
	private function get_field_map( $rule ) {
		if ( ! empty( $rule['field_map'] ) && is_array( $rule['field_map'] ) ) {
			return $rule['field_map'];
		}
		return [ $rule['source_field_id'] => $rule['target_field_id'] ];
	}

	/**
	 * Server-side validation on form submission
	 */
	public function validate( $result ) {
		$form_id = $result['form']['id'];
		$rules = $this->get_rules_for_form( $form_id );

		if ( empty( $rules ) ) {
			return $result;
		}

		foreach ( $rules as $rule ) {
			$field_map        = $this->get_field_map( $rule );
			$target_field_ids = array_values( $field_map );
			$validate_blank   = isset( $rule['validate_blank_values'] ) ? (bool) $rule['validate_blank_values'] : true;

			// Collect values once per rule (same for all target fields).
			$values = [];
			foreach ( $field_map as $source_fid => $target_fid ) {
				$value = rgpost( 'input_' . $target_fid );
				if ( ! rgblank( $value ) || $validate_blank ) {
					$values[ $source_fid ] = $value;
				}
			}

			if ( empty( $values ) ) {
				continue;
			}

			// Check existence once per rule instead of once per field.
			$exists = $this->values_exist( $values, $rule['source_form_id'] );

			if ( ! $exists ) {
				$message = ! empty( $rule['validation_message'] )
					? $rule['validation_message']
					: __( 'Please enter a valid value.' );

				foreach ( $result['form']['fields'] as &$field ) {
					if ( ! in_array( $field->id, $target_field_ids, false ) ) {
						continue;
					}
					if ( \GFFormsModel::is_field_hidden( $result['form'], $field, [] ) ) {
						continue;
					}

					$field->failed_validation  = true;
					$field->validation_message = $message;
					$result['is_valid']        = false;
				}
			}
		}

		return $result;
	}

	/**
	 * Check if values exist in source form entries
	 *
	 * @param array $values field_id => value pairs
	 * @param int   $form_id Source form ID
	 * @return bool
	 */
	private function values_exist( $values, $form_id ) {
		$field_filters = [];

		foreach ( $values as $field_id => $value ) {
			$field_filters[] = [
				'key'   => $field_id,
				'value' => $value,
			];
		}

		$search_criteria = [
			'status'        => 'active',
			'field_filters' => $field_filters,
		];

		$count = \GFAPI::count_entries( $form_id, $search_criteria );

		return $count > 0;
	}

	/**
	 * AJAX handler for real-time validation
	 */
	public function ajax_check() {
		if ( ! wp_verify_nonce( rgpost( 'nonce' ), 'sfa_cv_check' ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}

		// Rate limit: max 10 requests per minute per IP.
		$ip_hash    = md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
		$rate_key   = 'sfa_cv_rate_' . $ip_hash;
		$rate_count = (int) get_transient( $rate_key );

		if ( $rate_count >= 10 ) {
			wp_send_json_error( 'Too many requests. Please try again shortly.', 429 );
		}

		set_transient( $rate_key, $rate_count + 1, MINUTE_IN_SECONDS );

		$form_id = absint( rgpost( 'form_id' ) );
		$values  = rgpost( 'values' );

		if ( ! $form_id || ! is_array( $values ) ) {
			wp_send_json_error( 'Invalid request' );
		}

		// Validate that form_id and field keys match a configured rule.
		$matched_rule = $this->find_matching_rule_for_ajax( $form_id, array_keys( $values ) );

		if ( ! $matched_rule ) {
			wp_send_json_error( 'Invalid request' );
		}

		// Sanitize values: only keep keys that are valid field IDs from the matched rule.
		$field_map      = $this->get_field_map( $matched_rule );
		$allowed_keys   = array_map( 'intval', array_keys( $field_map ) );
		$clean_values   = [];

		foreach ( $values as $key => $value ) {
			$key = intval( $key );
			if ( in_array( $key, $allowed_keys, true ) ) {
				$clean_values[ $key ] = sanitize_text_field( $value );
			}
		}

		if ( empty( $clean_values ) ) {
			wp_send_json_error( 'Invalid request' );
		}

		$exists = $this->values_exist( $clean_values, $form_id );

		wp_send_json( [
			'doesValueExist' => $exists,
		] );
	}

	/**
	 * Find a configured rule that matches the AJAX request's source form and field keys.
	 *
	 * @param int   $source_form_id
	 * @param array $submitted_keys Field IDs submitted in the request
	 * @return array|null Matched rule or null
	 */
	private function find_matching_rule_for_ajax( $source_form_id, $submitted_keys ) {
		$submitted_keys = array_map( 'intval', $submitted_keys );
		sort( $submitted_keys );

		foreach ( $this->rules as $rule ) {
			if ( (int) $rule['source_form_id'] !== $source_form_id ) {
				continue;
			}

			$field_map  = $this->get_field_map( $rule );
			$rule_keys  = array_map( 'intval', array_keys( $field_map ) );
			sort( $rule_keys );

			if ( $rule_keys === $submitted_keys ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Enqueue GF scripts for applicable forms
	 */
	public function enqueue_scripts( $form ) {
		$rules = $this->get_rules_for_form( $form['id'] );
		if ( ! empty( $rules ) ) {
			wp_enqueue_script( 'gform_gravityforms' );
		}
	}

	/**
	 * Output the main validation JS on first applicable form render
	 */
	public function maybe_output_script( $form, $is_ajax_enabled ) {
		$rules = $this->get_rules_for_form( $form['id'] );

		if ( ! empty( $rules ) && ! self::$is_script_output && ! $this->is_ajax_submission( $form['id'], $is_ajax_enabled ) ) {
			wp_enqueue_script(
				'sfa-cv-validator',
				SFA_CV_URL . 'assets/js/validator.js',
				[ 'jquery', 'gform_gravityforms' ],
				SFA_CV_VER,
				true
			);
			self::$is_script_output = true;
		}

		return $form;
	}

	/**
	 * Register GF init scripts for each applicable rule
	 */
	public function add_init_scripts( $form ) {
		$rules = $this->get_rules_for_form( $form['id'] );

		if ( empty( $rules ) ) {
			return;
		}

		foreach ( $rules as $index => $rule ) {
			$field_map = $this->get_field_map( $rule );
			$selectors = $this->get_field_selectors( $form, array_values( $field_map ) );

			$args = [
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'sfa_cv_check' ),
				'targetFormId' => (int) $rule['target_form_id'],
				'sourceFormId' => (int) $rule['source_form_id'],
				'selectors'    => $selectors,
				'fieldMap'     => $field_map,
			];

			$script = 'new SFACodeValidator( ' . wp_json_encode( $args ) . ' );';
			$slug   = 'sfa_cv_rule_' . $rule['target_form_id'] . '_' . $index;

			\GFFormDisplay::add_init_script( $rule['target_form_id'], $slug, \GFFormDisplay::ON_PAGE_RENDER, $script );
		}
	}

	/**
	 * Build jQuery selectors for target fields
	 *
	 * @param array $form GF form array
	 * @param array $field_ids Target field IDs
	 * @return array
	 */
	private function get_field_selectors( $form, $field_ids ) {
		$selectors = [];

		foreach ( $form['fields'] as $field ) {
			if ( ! in_array( $field->id, $field_ids, false ) ) {
				continue;
			}

			$prefix = sprintf( '#input_%d_%d', $form['id'], $field->id );

			if ( is_array( $field->inputs ) ) {
				foreach ( $field->inputs as $input ) {
					$bits        = explode( '.', $input['id'] );
					$input_id    = $bits[1];
					$selectors[] = "{$prefix}_{$input_id}";
				}
			} else {
				$selectors[] = $prefix;
			}
		}

		return $selectors;
	}

	/**
	 * Check if this is an AJAX re-submission
	 */
	private function is_ajax_submission( $form_id, $is_ajax_enabled ) {
		return class_exists( 'GFFormDisplay' )
			&& isset( \GFFormDisplay::$submission[ $form_id ] )
			&& $is_ajax_enabled;
	}
}
