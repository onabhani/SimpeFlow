<?php
namespace SFA\ProductionScheduling\GravityForms;

use SFA\ProductionScheduling\Engine\Scheduler;
use SFA\ProductionScheduling\Database\CapacityRepository;

/**
 * Billing Step Preview Handler
 *
 * Provides real-time schedule calculation during form filling
 */
class BillingStepPreview {

	public function __construct() {
		add_action( 'gform_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Enqueue scripts on form pages
	 */
	public function enqueue_scripts( $form ) {
		// TODO: Add form ID check - only load on forms with production scheduling

		wp_enqueue_script( 'sfa-prod-billing' );

		wp_localize_script( 'sfa-prod-billing', 'sfaProdConfig', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sfa_prod_preview' ),
			'lm_field_id' => $this->get_lm_field_id( $form ),
			'install_field_id' => $this->get_install_field_id( $form ),
		] );
	}

	/**
	 * Get LM field ID from form
	 * TODO: Make configurable per form
	 */
	private function get_lm_field_id( $form ) {
		// For now, return placeholder
		// Will be configured in form settings
		return 0;
	}

	/**
	 * Get installation date field ID from form
	 * TODO: Make configurable per form
	 */
	private function get_install_field_id( $form ) {
		// For now, return placeholder
		return 0;
	}

	/**
	 * Calculate schedule for given LM
	 *
	 * @param int $lm_required
	 * @return array|WP_Error
	 */
	public static function calculate_schedule( int $lm_required ) {
		if ( $lm_required <= 0 ) {
			return new \WP_Error( 'invalid_lm', 'LM must be greater than 0' );
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

		// Load existing bookings
		$existing_bookings = self::load_existing_bookings(
			$earliest_start->format( 'Y-m-d' ),
			$end_date->format( 'Y-m-d' )
		);

		// Calculate schedule
		$scheduler = new Scheduler();

		try {
			$schedule = $scheduler->calculate_schedule(
				$lm_required,
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
	 * @param string $start_date
	 * @param string $end_date
	 * @return array [date => total_lm_used]
	 */
	private static function load_existing_bookings( string $start_date, string $end_date ): array {
		global $wpdb;

		// Query all entries with production bookings in date range
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value
				FROM {$wpdb->prefix}gf_entry_meta
				WHERE meta_key = '_prod_slots_allocation'
				AND meta_value LIKE %s",
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
