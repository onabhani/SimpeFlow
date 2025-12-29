<?php
namespace SFA\ProductionScheduling\Admin;

/**
 * Production Scheduling Settings Page
 */
class SettingsPage {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], 110 ); // Load after Schedule View
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Add settings page to admin menu
	 */
	public function add_menu_page() {
		add_submenu_page(
			'sfa-production-schedule', // Parent is the schedule page
			'Production Scheduling Settings',
			'Settings',
			'manage_options',
			'sfa-production-scheduling-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting( 'sfa_prod_settings', 'sfa_prod_daily_capacity', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 10,
		] );

		register_setting( 'sfa_prod_settings', 'sfa_prod_working_days', [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitize_working_days' ],
		] );

		register_setting( 'sfa_prod_settings', 'sfa_prod_installation_buffer', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		] );

		register_setting( 'sfa_prod_settings', 'sfa_prod_holidays', [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitize_holidays' ],
		] );

		register_setting( 'sfa_prod_settings', 'sfa_prod_earliest_start_date', [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitize_date' ],
		] );
	}

	/**
	 * Cleanup invalid bookings (0 LM)
	 *
	 * @return int Number of bookings deleted
	 */
	private function cleanup_invalid_bookings() {
		global $wpdb;

		// Find all entries with 0 or null LM
		$entries_to_clean = $wpdb->get_results(
			"SELECT entry_id
			FROM {$wpdb->prefix}gf_entry_meta
			WHERE meta_key = '_prod_total_slots'
			AND (meta_value = '0' OR meta_value IS NULL OR meta_value = '')",
			ARRAY_A
		);

		$deleted_count = 0;

		foreach ( $entries_to_clean as $row ) {
			$entry_id = $row['entry_id'];

			// Delete all production-related meta for this entry
			$meta_keys = [
				'_prod_lm_required',
				'_prod_total_slots',
				'_prod_field_breakdown',
				'_prod_slots_allocation',
				'_prod_start_date',
				'_prod_end_date',
				'_install_date',
				'_prod_booking_status',
				'_prod_booked_at',
				'_prod_booked_by',
				'_prod_daily_capacity_at_booking',
			];

			foreach ( $meta_keys as $meta_key ) {
				gform_delete_meta( $entry_id, $meta_key );
			}

			$deleted_count++;
		}

		// Clear cache
		wp_cache_flush();

		return $deleted_count;
	}

	/**
	 * Sanitize working days
	 */
	public function sanitize_working_days( $value ) {
		if ( ! is_array( $value ) ) {
			$value = json_decode( $value, true );
		}

		if ( ! is_array( $value ) ) {
			return wp_json_encode( [ 0, 1, 2, 3, 4, 6 ] ); // Default
		}

		// Only allow 0-6
		$value = array_filter( $value, function ( $day ) {
			return is_numeric( $day ) && $day >= 0 && $day <= 6;
		} );

		return wp_json_encode( array_values( array_map( 'intval', $value ) ) );
	}

	/**
	 * Sanitize holidays
	 */
	public function sanitize_holidays( $value ) {
		if ( is_string( $value ) ) {
			// Parse textarea input (one date per line)
			$lines = explode( "\n", $value );
			$dates = [];

			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( $line && strtotime( $line ) ) {
					$dates[] = date( 'Y-m-d', strtotime( $line ) );
				}
			}

			return wp_json_encode( array_unique( $dates ) );
		}

		return wp_json_encode( [] );
	}

	/**
	 * Sanitize date
	 */
	public function sanitize_date( $value ) {
		$value = trim( $value );

		if ( empty( $value ) ) {
			return '';
		}

		if ( strtotime( $value ) ) {
			return date( 'Y-m-d', strtotime( $value ) );
		}

		return '';
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Get current values
		$daily_capacity = (int) get_option( 'sfa_prod_daily_capacity', 10 );
		$working_days_json = get_option( 'sfa_prod_working_days', wp_json_encode( [ 0, 1, 2, 3, 4, 6 ] ) );
		$working_days = json_decode( $working_days_json, true );
		$installation_buffer = (int) get_option( 'sfa_prod_installation_buffer', 0 );
		$holidays_json = get_option( 'sfa_prod_holidays', wp_json_encode( [] ) );
		$holidays = json_decode( $holidays_json, true );
		$earliest_start = get_option( 'sfa_prod_earliest_start_date', '' );

		// Handle cleanup of invalid bookings
		if ( isset( $_POST['sfa_prod_cleanup_bookings'] ) && check_admin_referer( 'sfa_prod_cleanup' ) ) {
			$deleted = $this->cleanup_invalid_bookings();
			echo '<div class="notice notice-success"><p>Cleanup complete! Removed ' . $deleted . ' invalid booking(s) with 0 LM.</p></div>';
		}

		// Save settings
		if ( isset( $_POST['sfa_prod_save_settings'] ) && check_admin_referer( 'sfa_prod_settings' ) ) {
			update_option( 'sfa_prod_daily_capacity', absint( $_POST['daily_capacity'] ) );

			$selected_days = isset( $_POST['working_days'] ) ? array_map( 'intval', $_POST['working_days'] ) : [];
			update_option( 'sfa_prod_working_days', wp_json_encode( $selected_days ) );

			update_option( 'sfa_prod_installation_buffer', absint( $_POST['installation_buffer'] ) );

			$this->sanitize_holidays( $_POST['holidays'] );
			update_option( 'sfa_prod_holidays', $this->sanitize_holidays( $_POST['holidays'] ) );

			update_option( 'sfa_prod_earliest_start_date', $this->sanitize_date( $_POST['earliest_start_date'] ) );

			echo '<div class="notice notice-success"><p>Settings saved successfully.</p></div>';

			// Reload values
			$daily_capacity = (int) get_option( 'sfa_prod_daily_capacity' );
			$working_days = json_decode( get_option( 'sfa_prod_working_days' ), true );
			$installation_buffer = (int) get_option( 'sfa_prod_installation_buffer' );
			$holidays = json_decode( get_option( 'sfa_prod_holidays' ), true );
			$earliest_start = get_option( 'sfa_prod_earliest_start_date' );
		}

		$weekdays = [
			0 => 'Sunday',
			1 => 'Monday',
			2 => 'Tuesday',
			3 => 'Wednesday',
			4 => 'Thursday',
			5 => 'Friday',
			6 => 'Saturday',
		];

		?>
		<div class="wrap">
			<h1>Production Scheduling Settings</h1>

			<form method="post" action="">
				<?php wp_nonce_field( 'sfa_prod_settings' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="daily_capacity">Daily Production Capacity</label>
						</th>
						<td>
							<input type="number" name="daily_capacity" id="daily_capacity"
							       value="<?php echo esc_attr( $daily_capacity ); ?>"
							       min="1" step="1" class="small-text"> LM/day
							<p class="description">How many linear meters the factory can produce per day</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Working Days</th>
						<td>
							<fieldset>
								<?php foreach ( $weekdays as $day_num => $day_name ): ?>
									<label style="display: inline-block; width: 120px;">
										<input type="checkbox" name="working_days[]" value="<?php echo $day_num; ?>"
											<?php checked( in_array( $day_num, $working_days, true ) ); ?>>
										<?php echo $day_name; ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description">Select which days the factory operates</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="installation_buffer">Installation Buffer</label>
						</th>
						<td>
							<input type="number" name="installation_buffer" id="installation_buffer"
							       value="<?php echo esc_attr( $installation_buffer ); ?>"
							       min="0" step="1" class="small-text"> days
							<p class="description">Days after production before installation can happen (0 = same day)</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="earliest_start_date">Earliest Production Start Date</label>
						</th>
						<td>
							<input type="date" name="earliest_start_date" id="earliest_start_date"
							       value="<?php echo esc_attr( $earliest_start ); ?>">
							<p class="description">
								Leave empty to use current date. Set a future date to account for existing backlog
								(e.g., if you have orders until Feb 5, 2026, set this to Feb 6, 2026)
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="holidays">Holidays / Blocked Dates</label>
						</th>
						<td>
							<textarea name="holidays" id="holidays" rows="6" cols="50"
							          class="large-text"><?php echo esc_textarea( implode( "\n", $holidays ) ); ?></textarea>
							<p class="description">One date per line (YYYY-MM-DD format, e.g., 2026-01-01)</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save Settings', 'primary', 'sfa_prod_save_settings' ); ?>
			</form>

			<hr>

			<h2>Current Status</h2>
			<p><strong>Active Production Days:</strong>
				<?php
				$active_days = array_intersect_key( $weekdays, array_flip( $working_days ) );
				echo implode( ', ', $active_days );
				?>
			</p>
			<p><strong>Holidays Configured:</strong> <?php echo count( $holidays ); ?></p>
			<?php if ( $earliest_start ): ?>
				<p><strong>Next Available Production Slot:</strong> <?php echo date( 'F j, Y', strtotime( $earliest_start ) ); ?></p>
			<?php else: ?>
				<p><strong>Next Available Production Slot:</strong> Today (<?php echo date( 'F j, Y' ); ?>)</p>
			<?php endif; ?>

			<hr>

			<h2>Database Maintenance</h2>
			<p>Clean up invalid production bookings that were created before the booking validation fixes.</p>

			<form method="post" action="" onsubmit="return confirm('Are you sure you want to remove all invalid bookings with 0 LM? This cannot be undone.');">
				<?php wp_nonce_field( 'sfa_prod_cleanup' ); ?>
				<p>
					<button type="submit" name="sfa_prod_cleanup_bookings" class="button button-secondary">
						🗑️ Clean Up Invalid Bookings (0 LM)
					</button>
				</p>
				<p class="description">
					This will remove all production booking data from entries that have 0 LM or empty production fields.
					The entries themselves will not be deleted, only the production scheduling metadata.
				</p>
			</form>
		</div>
		<?php
	}
}
