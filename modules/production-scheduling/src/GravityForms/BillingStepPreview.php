<?php
namespace SFA\ProductionScheduling\GravityForms;

use SFA\ProductionScheduling\Engine\Scheduler;
use SFA\ProductionScheduling\Database\CapacityRepository;
use SFA\ProductionScheduling\Admin\FormSettings;

/**
 * Billing Step Preview Handler
 *
 * Provides real-time schedule calculation during form filling
 */
class BillingStepPreview {

	public function __construct() {
		add_action( 'gform_enqueue_scripts', [ $this, 'enqueue_scripts' ], 10, 2 );
	}

	/**
	 * Enqueue scripts on form pages
	 */
	public function enqueue_scripts( $form, $is_ajax ) {
		// Only load if production scheduling is enabled for this form
		if ( ! FormSettings::is_enabled( $form ) ) {
			return;
		}

		$lm_field_id = FormSettings::get_lm_field_id( $form );
		$install_field_id = FormSettings::get_install_field_id( $form );
		$prod_start_field_id = FormSettings::get_prod_start_field_id( $form );
		$prod_end_field_id = FormSettings::get_prod_end_field_id( $form );
		$production_fields = FormSettings::get_production_fields( $form );

		// Require either legacy LM field or production fields configuration
		if ( empty( $production_fields ) && ! $lm_field_id ) {
			return;
		}

		if ( ! $install_field_id ) {
			return;
		}

		wp_enqueue_script( 'sfa-prod-billing' );

		wp_localize_script( 'sfa-prod-billing', 'sfaProdConfig', [
			'ajaxurl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'sfa_prod_preview' ),
			'formId'           => $form['id'],
			'lmFieldId'        => $lm_field_id, // Legacy support
			'installFieldId'   => $install_field_id,
			'prodStartFieldId' => $prod_start_field_id,
			'prodEndFieldId'   => $prod_end_field_id,
			'productionFields' => $production_fields, // New multi-field configuration
		] );
	}

	/**
	 * Calculate schedule for given field values
	 *
	 * @param int|array $lm_or_field_values Legacy: int LM value, or array of field_id => value
	 * @param array|null $field_configs Field configurations (required if using multi-field)
	 * @param int|null $entry_id Entry ID to exclude from bookings (for edit mode)
	 * @return array|\WP_Error
	 */
	public static function calculate_schedule( $lm_or_field_values, $field_configs = null, $entry_id = null ) {
		// Handle legacy single LM value (backwards compatibility)
		if ( is_int( $lm_or_field_values ) || is_numeric( $lm_or_field_values ) ) {
			$lm_required = (int) $lm_or_field_values;
			if ( $lm_required <= 0 ) {
				return new \WP_Error( 'invalid_lm', 'LM must be greater than 0' );
			}
			$total_slots = $lm_required;
		} else {
			// Multi-field calculation
			if ( empty( $field_configs ) || ! is_array( $field_configs ) ) {
				return new \WP_Error( 'invalid_config', 'Field configurations required for multi-field calculation' );
			}

			$field_values = is_array( $lm_or_field_values ) ? $lm_or_field_values : array();
			$total_slots = FormSettings::calculate_total_slots( $field_values, $field_configs );

			if ( $total_slots <= 0 ) {
				return new \WP_Error( 'invalid_values', 'Total production slots must be greater than 0' );
			}
		}

		// Load settings
		$daily_capacity = (int) get_option( 'sfa_prod_daily_capacity', 10 );
		$installation_buffer = (int) get_option( 'sfa_prod_installation_buffer', 0 );
		$working_days_json = get_option( 'sfa_prod_working_days', wp_json_encode( [ 0, 1, 2, 3, 4, 6 ] ) );
		$working_days = json_decode( $working_days_json, true );
		$holidays_json = get_option( 'sfa_prod_holidays', wp_json_encode( [] ) );
		$holidays = json_decode( $holidays_json, true );
		$earliest_start_str = get_option( 'sfa_prod_earliest_start_date', '' );

		// Determine earliest start date
		if ( $earliest_start_str && strtotime( $earliest_start_str ) ) {
			$earliest_start = new \DateTime( $earliest_start_str );
		} else {
			$earliest_start = new \DateTime( 'today' );
		}

		// Never allow past dates
		$today = new \DateTime( 'today' );
		if ( $earliest_start < $today ) {
			$earliest_start = $today;
		}

		// Calculate off days (invert working days)
		$all_days = [ 0, 1, 2, 3, 4, 5, 6 ];
		$off_days = array_values( array_diff( $all_days, $working_days ) );

		// Load capacity overrides for next 90 days
		$end_date = clone $earliest_start;
		$end_date->modify( '+90 days' );

		$repo = new CapacityRepository();
		$capacity_overrides = $repo->get_range(
			$earliest_start->format( 'Y-m-d' ),
			$end_date->format( 'Y-m-d' )
		);

		// Load existing bookings (exclude current entry if editing)
		$existing_bookings = self::load_existing_bookings(
			$earliest_start->format( 'Y-m-d' ),
			$end_date->format( 'Y-m-d' ),
			$entry_id
		);

		// Calculate schedule
		$scheduler = new Scheduler();

		try {
			$schedule = $scheduler->calculate_schedule(
				$total_slots,
				$earliest_start,
				$daily_capacity,
				$capacity_overrides,
				$existing_bookings,
				$off_days,
				$holidays,
				$installation_buffer
			);

			return $schedule;
		} catch ( \Exception $e ) {
			return new \WP_Error( 'schedule_error', $e->getMessage() );
		}
	}

	/**
	 * Load existing bookings from entry meta
	 *
	 * @param string   $start_date
	 * @param string   $end_date
	 * @param int|null $exclude_entry_id Entry ID to exclude (for edit mode)
	 * @return array [date => total_lm_used]
	 */
	private static function load_existing_bookings( string $start_date, string $end_date, $exclude_entry_id = null ): array {
		global $wpdb;

		// Build exclude condition
		$exclude_sql = '';
		if ( $exclude_entry_id ) {
			$exclude_sql = $wpdb->prepare( ' AND entry_id != %d', $exclude_entry_id );
		}

		// Query all entries with production bookings in date range
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value
				FROM {$wpdb->prefix}gf_entry_meta
				WHERE meta_key = '_prod_slots_allocation'
				AND meta_value LIKE %s" . $exclude_sql,
				'%' . $wpdb->esc_like( substr( $start_date, 0, 7 ) ) . '%'
			),
			ARRAY_A
		);

		$bookings = [];

		foreach ( $results as $row ) {
			$allocation = json_decode( $row['meta_value'], true );

			if ( ! is_array( $allocation ) ) {
				continue;
			}

			foreach ( $allocation as $date => $lm ) {
				if ( $date >= $start_date && $date <= $end_date ) {
					if ( ! isset( $bookings[ $date ] ) ) {
						$bookings[ $date ] = 0;
					}
					$bookings[ $date ] += (int) $lm;
				}
			}
		}

		return $bookings;
	}
}
