<?php
namespace SFA\ProductionScheduling\Database;

/**
 * Capacity Repository
 *
 * CRUD operations for capacity overrides
 */
class CapacityRepository {

	private $table_name;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'sfa_prod_capacity_overrides';
	}

	/**
	 * Get capacity override for a specific date
	 *
	 * @param string $date "2026-01-01"
	 * @return array|null
	 */
	public function get_by_date( string $date ) {
		global $wpdb;

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE date = %s",
				$date
			),
			ARRAY_A
		);

		return $result ?: null;
	}

	/**
	 * Get all capacity overrides within a date range
	 *
	 * @param string $start_date "2026-01-01"
	 * @param string $end_date   "2026-01-31"
	 * @return array [date => capacity]
	 */
	public function get_range( string $start_date, string $end_date ): array {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date, custom_capacity FROM {$this->table_name}
				WHERE date >= %s AND date <= %s
				ORDER BY date ASC",
				$start_date,
				$end_date
			),
			ARRAY_A
		);

		$overrides = [];
		foreach ( $results as $row ) {
			$overrides[ $row['date'] ] = (int) $row['custom_capacity'];
		}

		return $overrides;
	}

	/**
	 * Set capacity override for a specific date
	 *
	 * @param string $date            "2026-01-01"
	 * @param int    $custom_capacity New capacity (0 = block day)
	 * @param string $reason          Optional reason
	 * @return bool Success
	 */
	public function set_override( string $date, int $custom_capacity, string $reason = '' ): bool {
		global $wpdb;

		$existing = $this->get_by_date( $date );

		if ( $existing ) {
			// Update existing override
			$result = $wpdb->update(
				$this->table_name,
				[
					'custom_capacity' => $custom_capacity,
					'reason'          => $reason,
				],
				[ 'date' => $date ],
				[ '%d', '%s' ],
				[ '%s' ]
			);
		} else {
			// Insert new override
			$result = $wpdb->insert(
				$this->table_name,
				[
					'date'            => $date,
					'custom_capacity' => $custom_capacity,
					'reason'          => $reason,
					'created_at'      => current_time( 'mysql' ),
					'created_by'      => get_current_user_id(),
				],
				[ '%s', '%d', '%s', '%s', '%d' ]
			);
		}

		// Clear cache
		wp_cache_delete( 'sfa_prod_capacity_overrides_' . date( 'Y-m', strtotime( $date ) ) );

		return $result !== false;
	}

	/**
	 * Remove capacity override for a specific date
	 *
	 * @param string $date "2026-01-01"
	 * @return bool Success
	 */
	public function remove_override( string $date ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			$this->table_name,
			[ 'date' => $date ],
			[ '%s' ]
		);

		// Clear cache
		wp_cache_delete( 'sfa_prod_capacity_overrides_' . date( 'Y-m', strtotime( $date ) ) );

		return $result !== false;
	}

	/**
	 * Get all overrides (for admin management)
	 *
	 * @param int $limit
	 * @param int $offset
	 * @return array
	 */
	public function get_all( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name}
				ORDER BY date DESC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
	}
}
