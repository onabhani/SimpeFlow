<?php
namespace SFA\CustomerLookup\Database;

/**
 * Customer Repository
 *
 * All database access for the Customer Lookup module is isolated here.
 * Uses GFAPI by default. Falls back to direct WPDB if configured.
 */
class CustomerRepository {

	/** @var array|null Cached settings loaded once per request. */
	private static ?array $settings = null;

	/**
	 * Load module settings once per request.
	 *
	 * @return array{form_id: int, field_map: array, use_wpdb: bool, use_sf_table: bool}
	 */
	public static function get_settings(): array {
		if ( null === self::$settings ) {
			self::$settings = [
				'form_id'      => (int) get_option( 'sfa_cl_source_form_id', 0 ),
				'field_map'    => get_option( 'sfa_cl_field_map', [] ),
				'use_wpdb'     => (bool) get_option( 'sfa_cl_use_direct_db', false ),
				'use_sf_table' => (bool) get_option( 'sfa_cl_use_sf_table', false ),
			];
		}
		return self::$settings;
	}

	/**
	 * Transient key for a phone lookup.
	 */
	private static function transient_key( string $phone ): string {
		return 'sfa_cl_' . substr( md5( $phone ), 0, 12 );
	}

	/**
	 * Invalidate cached lookup for a specific phone number.
	 *
	 * @param string $phone
	 */
	public static function invalidate_cache( string $phone ): void {
		delete_transient( self::transient_key( $phone ) );
	}

	/**
	 * Invalidate cache when a source form entry is updated.
	 * Hooked to gform_after_update_entry and gform_post_add_entry.
	 *
	 * @param array $entry
	 */
	public static function on_entry_changed( $entry ): void {
		$settings  = self::get_settings();
		$source_id = $settings['form_id'];
		$form_id   = (int) ( $entry['form_id'] ?? 0 );

		if ( ! $source_id || $form_id !== $source_id ) {
			return;
		}

		$field_map = $settings['field_map'];

		// Clear cache for both phone fields if present
		foreach ( [ 'phone', 'phone_alt' ] as $key ) {
			if ( ! empty( $field_map[ $key ] ) ) {
				$fid   = (string) $field_map[ $key ];
				$phone = $entry[ $fid ] ?? '';
				if ( $phone ) {
					$digits = preg_replace( '/[^0-9]/', '', $phone );
					if ( $digits ) {
						self::invalidate_cache( $digits );
					}
				}
			}
		}

		// Reset static cache so next call in this request re-reads
		self::$settings = null;
	}

	/**
	 * Find a customer entry by phone number.
	 *
	 * Searches both primary and alternate phone fields.
	 * Returns an associative array of semantic_name => value, or null if not found.
	 *
	 * @param string $phone Sanitized phone number
	 * @return array|null
	 */
	public static function find_by_phone( string $phone ): ?array {
		$settings  = self::get_settings();
		$form_id   = $settings['form_id'];
		$field_map = $settings['field_map'];

		if ( ! $form_id || empty( $field_map ) || empty( $field_map['phone'] ) ) {
			return null;
		}

		// Check transient cache (persists across AJAX requests)
		$t_key  = self::transient_key( $phone );
		$cached = get_transient( $t_key );

		if ( false !== $cached ) {
			if ( is_array( $cached ) && isset( $cached['__null'] ) ) {
				return null;
			}
			return $cached;
		}

		if ( $settings['use_sf_table'] ) {
			$result = self::query_sf_table( $phone );
		} elseif ( $settings['use_wpdb'] ) {
			$result = self::query_wpdb( $phone, $form_id, $field_map );
		} else {
			$result = self::query_gfapi( $phone, $form_id, $field_map );
		}

		// Cache both hits and misses (5-minute TTL)
		set_transient( $t_key, $result ?? [ '__null' => true ], 300 );

		return $result;
	}

	/**
	 * SF Customers table lookup (fastest path).
	 * Data is normalized on write, so no +prefix variant handling needed.
	 *
	 * @param string $phone Digits-only phone from AJAX handler.
	 * @return array|null
	 */
	private static function query_sf_table( string $phone ): ?array {
		$customer = CustomerTable::get_by_phone( $phone );

		if ( ! $customer ) {
			return null;
		}

		$allowed = [
			'name_arabic', 'name_english', 'phone_alt',
			'email', 'address', 'customer_type', 'branch', 'file_number',
		];

		$mapped = [];
		foreach ( $allowed as $key ) {
			if ( isset( $customer->$key ) && '' !== $customer->$key ) {
				$mapped[ $key ] = $customer->$key;
			}
		}

		return ! empty( $mapped ) ? $mapped : null;
	}

	/**
	 * GFAPI-based lookup (preferred path).
	 *
	 * @param string $phone
	 * @param int    $form_id
	 * @param array  $field_map
	 * @return array|null
	 */
	private static function query_gfapi( string $phone, int $form_id, array $field_map ): ?array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return null;
		}

		// Search both digits-only and +prefixed to handle stored formats like +966...
		$variants = [ $phone, '+' . $phone ];

		$filters = [ 'mode' => 'any' ];

		foreach ( $variants as $variant ) {
			$filters[] = [
				'key'   => $field_map['phone'],
				'value' => $variant,
			];

			if ( ! empty( $field_map['phone_alt'] ) ) {
				$filters[] = [
					'key'   => $field_map['phone_alt'],
					'value' => $variant,
				];
			}
		}

		$entries = \GFAPI::get_entries( $form_id, [
			'status'        => 'active',
			'field_filters' => $filters,
		], [ 'key' => 'id', 'direction' => 'DESC' ], [ 'offset' => 0, 'page_size' => 1 ] );

		if ( empty( $entries ) || is_wp_error( $entries ) ) {
			return null;
		}

		$entry = $entries[0];

		return self::map_entry_to_fields( $entry, $field_map );
	}

	/**
	 * Direct WPDB lookup (fallback — only if GFAPI benchmark fails).
	 *
	 * Justified only when GFAPI::get_entries() cannot meet <50ms at 10K+ entries.
	 * All queries use $wpdb->prepare() — non-negotiable.
	 *
	 * @param string $phone
	 * @param int    $form_id
	 * @param array  $field_map
	 * @return array|null
	 */
	private static function query_wpdb( string $phone, int $form_id, array $field_map ): ?array {
		// Direct DB query against gf_entry/gf_entry_meta is an intentional performance
		// fallback gated by: (1) GFCommon::$version >= 2.5 check below, (2) the admin
		// toggle sfa_cl_use_direct_db, and (3) $wpdb->prepare() for parameterisation.
		// See CUSTOMER_LOOKUP_PLAN.md for full rationale on why GFAPI is not used here.

		// GF version guard
		if ( ! class_exists( 'GFCommon' ) || version_compare( \GFCommon::$version, '2.5', '<' ) ) {
			return null;
		}

		global $wpdb;

		$meta_keys = [ $field_map['phone'] ];
		if ( ! empty( $field_map['phone_alt'] ) ) {
			$meta_keys[] = $field_map['phone_alt'];
		}

		// Query 1: Find entry_id by phone (both fields, both +prefixed and digits-only)
		$key_placeholders   = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
		$phone_variants     = [ $phone, '+' . $phone ];
		$value_placeholders = implode( ', ', array_fill( 0, count( $phone_variants ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$entry_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT em.entry_id
			 FROM {$wpdb->prefix}gf_entry_meta em
			 INNER JOIN {$wpdb->prefix}gf_entry e ON e.id = em.entry_id
			 WHERE em.form_id = %d
			   AND em.meta_key IN ({$key_placeholders})
			   AND em.meta_value IN ({$value_placeholders})
			   AND e.status = 'active'
			 ORDER BY em.entry_id DESC
			 LIMIT 1",
			array_merge( [ $form_id ], $meta_keys, $phone_variants )
		) );

		if ( ! $entry_id ) {
			return null;
		}

		$entry_id = (int) $entry_id;

		// Query 2: Fetch only whitelisted fields for entry_id
		$field_ids    = array_values( array_filter( $field_map ) );
		$field_places = implode( ', ', array_fill( 0, count( $field_ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_key, meta_value
			 FROM {$wpdb->prefix}gf_entry_meta
			 WHERE entry_id = %d
			   AND form_id = %d
			   AND meta_key IN ({$field_places})",
			array_merge( [ $entry_id, $form_id ], $field_ids )
		), ARRAY_A );

		if ( empty( $results ) ) {
			return null;
		}

		// Flip field map: field_id => semantic_name
		$flipped = [];
		foreach ( $field_map as $semantic => $fid ) {
			if ( $fid ) {
				$flipped[ (string) $fid ] = $semantic;
			}
		}

		$mapped = [];
		foreach ( $results as $row ) {
			$key = (string) $row['meta_key'];
			if ( isset( $flipped[ $key ] ) ) {
				$mapped[ $flipped[ $key ] ] = $row['meta_value'];
			}
		}

		return ! empty( $mapped ) ? $mapped : null;
	}

	/**
	 * Map a GFAPI entry array to semantic field names using the field map.
	 *
	 * @param array $entry    GF entry array
	 * @param array $field_map semantic_name => field_id
	 * @return array|null
	 */
	private static function map_entry_to_fields( array $entry, array $field_map ): ?array {
		$mapped = [];

		foreach ( $field_map as $semantic => $fid ) {
			if ( ! $fid ) {
				continue;
			}

			$fid_str = (string) $fid;

			if ( isset( $entry[ $fid_str ] ) && '' !== $entry[ $fid_str ] ) {
				$mapped[ $semantic ] = $entry[ $fid_str ];
			}
		}

		return ! empty( $mapped ) ? $mapped : null;
	}
}
