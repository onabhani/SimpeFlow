<?php
namespace SFA\CustomerLookup\Database;

/**
 * Customer Migrate
 *
 * One-time migration from Gravity Forms entries to wp_sf_customers.
 * Restricted to WP-CLI context only.
 *
 * Intentional deviation from the "no direct GF queries" rule:
 * GFAPI::get_entries() loads full entry objects with all meta, filters, and hooks —
 * prohibitively expensive for bulk migration of thousands of entries. Direct WPDB
 * with batch meta fetching is used for performance. See CUSTOMER_MODULE_PLAN_v2.md.
 */
class CustomerMigrate {

	/**
	 * Run the migration.
	 *
	 * @return array { inserted: int, skipped_duplicate: int, skipped_incomplete: int, errors: array }
	 */
	public static function run(): array {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return [
				'inserted'           => 0,
				'skipped_duplicate'  => 0,
				'skipped_incomplete' => 0,
				'errors'             => [ 0 => 'Migration can only be run via WP-CLI.' ],
			];
		}

		$settings  = CustomerRepository::get_settings();
		$form_id   = $settings['form_id'];
		$field_map = $settings['field_map'];

		if ( ! $form_id || empty( $field_map ) || empty( $field_map['phone'] ) ) {
			return [
				'inserted'           => 0,
				'skipped_duplicate'  => 0,
				'skipped_incomplete' => 0,
				'errors'             => [ 0 => 'Source form or phone field not configured. Check Customer Lookup settings.' ],
			];
		}

		global $wpdb;

		// Flip field_map: GF field ID => semantic key
		$flipped = [];
		foreach ( $field_map as $semantic => $fid ) {
			if ( $fid ) {
				$flipped[ (string) $fid ] = $semantic;
			}
		}

		// Get all active entry IDs for the source form
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$entry_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}gf_entry WHERE form_id = %d AND status = 'active' ORDER BY id ASC",
			$form_id
		) );

		if ( empty( $entry_ids ) ) {
			return [
				'inserted'           => 0,
				'skipped_duplicate'  => 0,
				'skipped_incomplete' => 0,
				'errors'             => [ 0 => 'No active entries found in source form.' ],
			];
		}

		$result = [
			'inserted'           => 0,
			'skipped_duplicate'  => 0,
			'skipped_incomplete' => 0,
			'errors'             => [],
		];

		$field_ids    = array_keys( $flipped );
		$batches      = array_chunk( $entry_ids, 100 );

		foreach ( $batches as $batch ) {
			$id_placeholders = implode( ', ', array_fill( 0, count( $batch ), '%d' ) );
			$fid_placeholders = implode( ', ', array_fill( 0, count( $field_ids ), '%s' ) );

			// Batch-fetch all relevant meta for this chunk of entries
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$meta_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT entry_id, meta_key, meta_value
				 FROM {$wpdb->prefix}gf_entry_meta
				 WHERE entry_id IN ({$id_placeholders})
				   AND form_id = %d
				   AND meta_key IN ({$fid_placeholders})",
				array_merge(
					array_map( 'intval', $batch ),
					[ $form_id ],
					$field_ids
				)
			), ARRAY_A );

			// Group meta by entry_id
			$grouped = [];
			foreach ( $meta_rows as $row ) {
				$eid = (int) $row['entry_id'];
				$key = (string) $row['meta_key'];
				if ( isset( $flipped[ $key ] ) ) {
					$grouped[ $eid ][ $flipped[ $key ] ] = $row['meta_value'];
				}
			}

			// Process each entry
			foreach ( $batch as $entry_id ) {
				$entry_id = (int) $entry_id;
				$fields   = $grouped[ $entry_id ] ?? [];

				$phone      = $fields['phone'] ?? '';
				$name_arabic = $fields['name_arabic'] ?? '';

				// Validate required fields
				if ( '' === trim( $phone ) || '' === trim( $name_arabic ) ) {
					$result['skipped_incomplete']++;
					continue;
				}

				// Normalize and check duplicate
				$normalized = CustomerTable::normalize_phone( $phone );
				if ( '' === $normalized ) {
					$result['skipped_incomplete']++;
					continue;
				}

				if ( CustomerTable::phone_exists( $normalized ) ) {
					$result['skipped_duplicate']++;
					continue;
				}

				// Build insert data
				$insert_data = [
					'phone'      => $phone,
					'name_arabic' => $name_arabic,
					'source'     => 'migration',
				];

				// Map optional fields
				$optional = [ 'phone_alt', 'name_english', 'email', 'address', 'customer_type', 'branch', 'file_number' ];
				foreach ( $optional as $key ) {
					if ( ! empty( $fields[ $key ] ) ) {
						$insert_data[ $key ] = $fields[ $key ];
					}
				}

				$id = CustomerTable::insert( $insert_data );

				if ( false === $id ) {
					$result['errors'][ $entry_id ] = $wpdb->last_error ?: 'Insert failed (validation or DB error)';
				} else {
					$result['inserted']++;
				}
			}
		}

		return $result;
	}

	/**
	 * CLI-friendly wrapper that prints results.
	 */
	public static function run_cli(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			echo "This command can only be run via WP-CLI.\n";
			return;
		}

		$result = self::run();

		echo "Inserted: {$result['inserted']}\n";
		echo "Skipped (duplicate): {$result['skipped_duplicate']}\n";
		echo "Skipped (incomplete): {$result['skipped_incomplete']}\n";
		echo "Errors: " . count( $result['errors'] ) . "\n";

		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $eid => $msg ) {
				echo "  Entry {$eid}: {$msg}\n";
			}
		}
	}
}
