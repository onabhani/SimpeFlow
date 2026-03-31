<?php
namespace SFA\CustomerLookup\Database;

/**
 * Customer Migrate
 *
 * One-time migration from Gravity Forms entries to sfa_cl_customers.
 * Restricted to WP-CLI context only.
 *
 * Intentional deviation from the "no direct GF queries" rule:
 * GFAPI::get_entries() loads full entry objects with all meta, filters, and hooks —
 * prohibitively expensive for bulk migration of thousands of entries. Direct WPDB
 * with batch meta fetching is used for performance. See CUSTOMER_MODULE_PLAN_v2.md.
 */
class CustomerMigrate {

	/**
	 * Map legacy GF customer_type values to valid enum values.
	 * Case-insensitive, unmapped values default to 'individual'.
	 */
	private static function normalize_customer_type( string $value ): string {
		$map = [
			'individual' => 'individual',
			'company'    => 'company',
			'project'    => 'project',
		];
		$lower = strtolower( trim( $value ) );
		return $map[ $lower ] ?? 'individual';
	}

	/**
	 * Run the migration.
	 *
	 * @param bool $dry_run If true, validate and report without inserting.
	 * @return array { inserted: int, skipped_duplicate: int, skipped_incomplete: int, would_insert: int, total_entries: int, errors: array }
	 */
	public static function run( bool $dry_run = false ): array {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return self::error_result( 'Migration can only be run via WP-CLI.' );
		}

		$settings  = CustomerRepository::get_settings();
		$form_id   = $settings['form_id'];
		$field_map = $settings['field_map'];

		if ( ! $form_id || empty( $field_map ) || empty( $field_map['phone'] ) ) {
			return self::error_result( 'Source form or phone field not configured. Check Customer Lookup settings.' );
		}

		global $wpdb;

		// Legacy phone field — fallback when primary phone is empty on older entries
		$legacy_phone_fid = (string) get_option( 'sfa_cl_legacy_phone_field', '' );

		// Flip field_map: GF field ID => semantic key
		$flipped = [];
		foreach ( $field_map as $semantic => $fid ) {
			if ( $fid ) {
				$flipped[ (string) $fid ] = $semantic;
			}
		}

		// Include legacy phone field in meta fetch (mapped to a temporary key)
		if ( $legacy_phone_fid && ! isset( $flipped[ $legacy_phone_fid ] ) ) {
			$flipped[ $legacy_phone_fid ] = '_legacy_phone';
		}

		// Get all active entry IDs for the source form
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$entry_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}gf_entry WHERE form_id = %d AND status = 'active' ORDER BY id ASC",
			$form_id
		) );

		if ( empty( $entry_ids ) ) {
			return self::error_result( 'No active entries found in source form.' );
		}

		$result = [
			'inserted'           => 0,
			'skipped_duplicate'  => 0,
			'skipped_incomplete' => 0,
			'would_insert'       => 0,
			'total_entries'      => count( $entry_ids ),
			'errors'             => [],
		];

		$field_ids = array_keys( $flipped );
		$batches   = array_chunk( $entry_ids, 100 );

		foreach ( $batches as $batch ) {
			$id_placeholders  = implode( ', ', array_fill( 0, count( $batch ), '%d' ) );
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

				$phone       = $fields['phone'] ?? '';
				$name_arabic = $fields['name_arabic'] ?? '';

				// Fallback to legacy phone field if primary is empty
				if ( '' === trim( $phone ) && ! empty( $fields['_legacy_phone'] ) ) {
					$phone = $fields['_legacy_phone'];
				}

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

				// Check if this GF entry was already imported (idempotent reruns)
				if ( CustomerTable::get_by_gf_entry_id( $entry_id ) ) {
					$result['skipped_duplicate']++;
					continue;
				}

				if ( CustomerTable::phone_exists( $normalized ) ) {
					$result['skipped_duplicate']++;
					continue;
				}

				// Build insert data — include gf_entry_id to link back to GF
				$insert_data = [
					'phone'       => $phone,
					'name_arabic' => $name_arabic,
					'gf_entry_id' => $entry_id,
					'source'      => 'migration',
				];

				// Map optional fields with legacy value normalization
				$optional = [ 'phone_alt', 'name_english', 'email', 'address', 'branch', 'file_number' ];
				foreach ( $optional as $key ) {
					if ( ! empty( $fields[ $key ] ) ) {
						$insert_data[ $key ] = $fields[ $key ];
					}
				}

				// Normalize customer_type from GF (may be capitalized or non-standard)
				if ( ! empty( $fields['customer_type'] ) ) {
					$insert_data['customer_type'] = self::normalize_customer_type( $fields['customer_type'] );
				}

				if ( $dry_run ) {
					// Run the same validation as insert() to get accurate counts
					$validated = CustomerTable::sanitize_data( $insert_data );
					if ( false === $validated ) {
						$result['errors'][ $entry_id ] = 'Validation failed — phone: "' . ( $insert_data['phone'] ?? '' ) . '", type: "' . ( $insert_data['customer_type'] ?? '' ) . '"';
					} else {
						$result['would_insert']++;
					}
					continue;
				}

				$id = CustomerTable::insert( $insert_data );

				if ( false === $id ) {
					$error = $wpdb->last_error;
					if ( ! $error ) {
						$error = 'Validation failed — phone: "' . ( $insert_data['phone'] ?? '' ) . '", name: "' . mb_substr( $insert_data['name_arabic'] ?? '', 0, 30 ) . '"';
					}
					$result['errors'][ $entry_id ] = $error;
				} else {
					$result['inserted']++;
				}
			}
		}

		return $result;
	}

	/**
	 * Verify migration completeness.
	 * Compares GF source entries against sfa_cl_customers to find gaps.
	 *
	 * @return array { total_gf: int, total_sf: int, missing: int[], orphaned: int[] }
	 */
	public static function verify(): array {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return [ 'error' => 'Verification can only be run via WP-CLI.' ];
		}

		$settings  = CustomerRepository::get_settings();
		$form_id   = $settings['form_id'];
		$field_map = $settings['field_map'];

		if ( ! $form_id || empty( $field_map['phone'] ) ) {
			return [ 'error' => 'Source form or phone field not configured.' ];
		}

		global $wpdb;

		$sf_table = CustomerTable::table_name();

		// All active GF entry IDs for the source form
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$gf_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}gf_entry WHERE form_id = %d AND status = 'active' ORDER BY id ASC",
			$form_id
		) );
		$gf_ids = array_map( 'intval', $gf_ids );

		// All gf_entry_id values stored in sfa_cl_customers
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sf_ids = $wpdb->get_col(
			"SELECT gf_entry_id FROM {$sf_table} WHERE gf_entry_id IS NOT NULL"
		);
		$sf_ids = array_map( 'intval', $sf_ids );

		// Missing = in GF but not in SF table (not yet migrated)
		$missing = array_values( array_diff( $gf_ids, $sf_ids ) );

		// Orphaned = in SF table but no longer in GF (entry was deleted/trashed)
		$orphaned = array_values( array_diff( $sf_ids, $gf_ids ) );

		return [
			'total_gf'  => count( $gf_ids ),
			'total_sf'  => count( $sf_ids ),
			'missing'   => $missing,
			'orphaned'  => $orphaned,
		];
	}

	/**
	 * CLI-friendly wrapper that prints results.
	 *
	 * Usage:
	 *   wp eval 'SFA\CustomerLookup\Database\CustomerMigrate::run_cli("dry-run");'
	 *   wp eval 'SFA\CustomerLookup\Database\CustomerMigrate::run_cli("verify");'
	 *   wp eval 'SFA\CustomerLookup\Database\CustomerMigrate::run_cli();'
	 *
	 * @param string $mode "dry-run", "verify", or empty for real migration.
	 */
	public static function run_cli( string $mode = '' ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			echo "This command can only be run via WP-CLI.\n";
			return;
		}

		$valid_modes = [ '', 'dry-run', 'verify' ];
		if ( ! in_array( $mode, $valid_modes, true ) ) {
			echo "Unknown mode: \"{$mode}\"\n";
			echo "Usage:\n";
			echo "  run_cli()          — real migration\n";
			echo "  run_cli(\"dry-run\") — preview without writing\n";
			echo "  run_cli(\"verify\")  — check migration completeness\n";
			return;
		}

		if ( 'verify' === $mode ) {
			self::print_verify();
			return;
		}

		$is_dry_run = ( 'dry-run' === $mode );
		$result     = self::run( $is_dry_run );

		// Detect startup/fatal errors (total_entries = 0 with errors present)
		if ( 0 === $result['total_entries'] && ! empty( $result['errors'] ) ) {
			echo "=== ERROR ===\n";
			foreach ( $result['errors'] as $msg ) {
				echo $msg . "\n";
			}
			return;
		}

		if ( $is_dry_run ) {
			echo "=== DRY RUN (no data written) ===\n";
			echo "Total GF entries: {$result['total_entries']}\n";
			echo "Would insert: {$result['would_insert']}\n";
			echo "Skipped (duplicate): {$result['skipped_duplicate']}\n";
			echo "Skipped (incomplete): {$result['skipped_incomplete']}\n";
			$accounted = $result['would_insert'] + $result['skipped_duplicate'] + $result['skipped_incomplete'];
			echo "Unaccounted: " . ( $result['total_entries'] - $accounted ) . "\n";
		} else {
			echo "=== MIGRATION COMPLETE ===\n";
			echo "Total GF entries: {$result['total_entries']}\n";
			echo "Inserted: {$result['inserted']}\n";
			echo "Skipped (duplicate): {$result['skipped_duplicate']}\n";
			echo "Skipped (incomplete): {$result['skipped_incomplete']}\n";
			echo "Errors: " . count( $result['errors'] ) . "\n";

			if ( ! empty( $result['errors'] ) ) {
				foreach ( $result['errors'] as $eid => $msg ) {
					echo "  Entry {$eid}: {$msg}\n";
				}
			}

			// Auto-verify after migration
			echo "\n";
			self::print_verify();
		}
	}

	/**
	 * Print verification results.
	 */
	private static function print_verify(): void {
		$v = self::verify();

		if ( isset( $v['error'] ) ) {
			echo "Verify error: {$v['error']}\n";
			return;
		}

		echo "=== VERIFICATION ===\n";
		echo "GF source entries: {$v['total_gf']}\n";
		echo "SF customer records (with gf_entry_id): {$v['total_sf']}\n";
		echo "Missing (in GF, not in SF): " . count( $v['missing'] ) . "\n";
		echo "Orphaned (in SF, not in GF): " . count( $v['orphaned'] ) . "\n";

		if ( ! empty( $v['missing'] ) ) {
			$sample = array_slice( $v['missing'], 0, 20 );
			echo "  Missing entry IDs (first 20): " . implode( ', ', $sample ) . "\n";
			if ( count( $v['missing'] ) > 20 ) {
				echo "  ... and " . ( count( $v['missing'] ) - 20 ) . " more\n";
			}
		}

		if ( empty( $v['missing'] ) && empty( $v['orphaned'] ) ) {
			echo "All entries accounted for.\n";
		}
	}

	/**
	 * Build an error result array.
	 */
	private static function error_result( string $message ): array {
		return [
			'inserted'           => 0,
			'skipped_duplicate'  => 0,
			'skipped_incomplete' => 0,
			'would_insert'       => 0,
			'total_entries'      => 0,
			'errors'             => [ 0 => $message ],
		];
	}
}
