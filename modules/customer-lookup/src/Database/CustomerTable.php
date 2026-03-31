<?php
namespace SFA\CustomerLookup\Database;

/**
 * Customer Table
 *
 * Schema definition and CRUD operations for the wp_sf_customers table.
 * All phone values are normalized to digits-only before storage and lookup.
 */
class CustomerTable {

	const DB_VERSION = '1.0.0';

	const VALID_CUSTOMER_TYPES = [ 'individual', 'company', 'project' ];
	const VALID_SOURCES        = [ 'manual', 'odoo', 'migration' ];
	const VALID_STATUSES       = [ 'active', 'inactive' ];

	const ALLOWED_COLUMNS = [
		'phone', 'phone_alt', 'name_arabic', 'name_english',
		'email', 'address', 'customer_type', 'branch',
		'file_number', 'odoo_id', 'source', 'status',
	];

	const SORTABLE_COLUMNS = [
		'id', 'name_arabic', 'name_english', 'phone',
		'customer_type', 'branch', 'file_number', 'created_at',
	];

	/**
	 * Get the full table name with prefix.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'sf_customers';
	}

	/**
	 * Create or update the table schema via dbDelta.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			phone         VARCHAR(20)  NOT NULL,
			phone_alt     VARCHAR(20)  DEFAULT NULL,
			name_arabic   VARCHAR(255) NOT NULL,
			name_english  VARCHAR(255) DEFAULT NULL,
			email         VARCHAR(255) DEFAULT NULL,
			address       TEXT         DEFAULT NULL,
			customer_type VARCHAR(20)  NOT NULL DEFAULT 'individual',
			branch        VARCHAR(100) DEFAULT NULL,
			file_number   VARCHAR(100) DEFAULT NULL,
			odoo_id       BIGINT UNSIGNED DEFAULT NULL,
			source        VARCHAR(20)  NOT NULL DEFAULT 'manual',
			status        VARCHAR(10)  NOT NULL DEFAULT 'active',
			created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY   (id),
			UNIQUE KEY    uq_phone (phone),
			KEY           idx_phone_alt (phone_alt),
			KEY           idx_status_created (status, created_at),
			KEY           idx_odoo_id (odoo_id),
			KEY           idx_file_number (file_number)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Normalize a phone number to digits-only, strip leading zeros.
	 *
	 * @param string $phone Raw phone input.
	 * @return string Digits-only, no leading zeros.
	 */
	public static function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/[^0-9]/', '', $phone );
		return ltrim( $digits, '0' );
	}

	/**
	 * Insert a new customer record.
	 *
	 * @param array $data Column => value pairs.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$data = self::sanitize_data( $data );
		if ( false === $data ) {
			return false;
		}

		$result = $wpdb->insert( self::table_name(), $data );

		if ( false === $result ) {
			return false;
		}

		$id = (int) $wpdb->insert_id;

		do_action( 'sf_customer_created', $id, $data );

		return $id;
	}

	/**
	 * Update an existing customer record.
	 *
	 * @param int   $id   Customer ID.
	 * @param array $data Column => value pairs.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;

		$data = self::sanitize_data( $data );
		if ( false === $data ) {
			return false;
		}

		// Explicitly set updated_at — dbDelta cannot handle ON UPDATE CURRENT_TIMESTAMP
		$data['updated_at'] = current_time( 'mysql', true );

		$result = $wpdb->update(
			self::table_name(),
			$data,
			[ 'id' => $id ],
			null,
			[ '%d' ]
		);

		if ( false === $result ) {
			return false;
		}

		do_action( 'sf_customer_updated', $id, $data );

		return true;
	}

	/**
	 * Get a customer by phone number (primary or alternate).
	 * Active records only.
	 *
	 * @param string $phone Raw or normalized phone.
	 * @return object|null
	 */
	public static function get_by_phone( string $phone ): ?object {
		global $wpdb;

		$normalized = self::normalize_phone( $phone );
		if ( '' === $normalized ) {
			return null;
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE (phone = %s OR phone_alt = %s) AND status = 'active' LIMIT 1",
			$normalized,
			$normalized
		) );

		return $row ?: null;
	}

	/**
	 * Get a customer by ID (any status — admin use).
	 *
	 * @param int $id Customer ID.
	 * @return object|null
	 */
	public static function get_by_id( int $id ): ?object {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d",
			$id
		) );

		return $row ?: null;
	}

	/**
	 * Check if a phone number already exists.
	 *
	 * @param string $phone      Raw phone number.
	 * @param int    $exclude_id Optional ID to exclude (for edit forms).
	 * @return bool
	 */
	public static function phone_exists( string $phone, int $exclude_id = 0 ): bool {
		global $wpdb;

		$normalized = self::normalize_phone( $phone );
		if ( '' === $normalized ) {
			return false;
		}

		$table = self::table_name();

		if ( $exclude_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE (phone = %s OR phone_alt = %s) AND id != %d",
				$normalized,
				$normalized,
				$exclude_id
			) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE phone = %s OR phone_alt = %s",
				$normalized,
				$normalized
			) );
		}

		return (int) $found > 0;
	}

	/**
	 * Soft-deactivate a customer.
	 *
	 * @param int $id Customer ID.
	 * @return bool
	 */
	public static function deactivate( int $id ): bool {
		global $wpdb;

		$result = $wpdb->update(
			self::table_name(),
			[ 'status' => 'inactive', 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		if ( false !== $result ) {
			do_action( 'sf_customer_deactivated', $id );
			return true;
		}

		return false;
	}

	/**
	 * Reactivate a customer.
	 *
	 * @param int $id Customer ID.
	 * @return bool
	 */
	public static function reactivate( int $id ): bool {
		global $wpdb;

		$result = $wpdb->update(
			self::table_name(),
			[ 'status' => 'active', 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Search customers across multiple fields.
	 * Uses prefix match for phone/file_number (index-friendly),
	 * contains match for name columns.
	 *
	 * @param string $term  Search term.
	 * @param int    $limit Max results (default 50).
	 * @return array Array of row objects.
	 */
	public static function search( string $term, int $limit = 50 ): array {
		global $wpdb;

		$term = trim( $term );
		if ( '' === $term ) {
			return [];
		}

		$table   = self::table_name();
		$escaped = $wpdb->esc_like( $term );

		$prefix   = $escaped . '%';
		$contains = '%' . $escaped . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE status = 'active'
			   AND (
			       phone LIKE %s
			    OR phone_alt LIKE %s
			    OR file_number LIKE %s
			    OR name_arabic LIKE %s
			    OR name_english LIKE %s
			   )
			 ORDER BY name_arabic ASC
			 LIMIT %d",
			$prefix,
			$prefix,
			$prefix,
			$contains,
			$contains,
			$limit
		) );

		return $results ?: [];
	}

	/**
	 * List customers with pagination, filtering, and sorting.
	 *
	 * @param array $args {
	 *     @type string $status   active|inactive|all (default: active)
	 *     @type int    $per_page Items per page (default: 50)
	 *     @type int    $page     Current page (default: 1)
	 *     @type string $orderby  Column to sort by (default: created_at)
	 *     @type string $order    ASC|DESC (default: DESC)
	 *     @type string $search   Optional search term
	 * }
	 * @return array { items: object[], total: int }
	 */
	public static function list( array $args = [] ): array {
		global $wpdb;

		$table    = self::table_name();
		$status   = $args['status'] ?? 'active';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 50 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$orderby  = $args['orderby'] ?? 'created_at';
		$order    = $args['order'] ?? 'DESC';
		$search   = $args['search'] ?? '';

		// Whitelist orderby to prevent SQL injection
		if ( ! in_array( $orderby, self::SORTABLE_COLUMNS, true ) ) {
			$orderby = 'created_at';
		}

		// Restrict order direction
		$order = strtoupper( $order );
		if ( ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
			$order = 'DESC';
		}

		$where  = [];
		$values = [];

		// Status filter
		if ( 'all' !== $status && in_array( $status, self::VALID_STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}

		// Search filter
		if ( '' !== $search ) {
			$escaped  = $wpdb->esc_like( $search );
			$prefix   = $escaped . '%';
			$contains = '%' . $escaped . '%';

			$where[] = '(phone LIKE %s OR phone_alt LIKE %s OR file_number LIKE %s OR name_arabic LIKE %s OR name_english LIKE %s)';
			array_push( $values, $prefix, $prefix, $prefix, $contains, $contains );
		}

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$offset    = ( $page - 1 ) * $per_page;

		// Count query
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		if ( ! empty( $values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $values );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$total = (int) $wpdb->get_var( $count_sql );

		// Data query — orderby/order are whitelisted, safe to interpolate
		$data_sql = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$data_values = array_merge( $values, [ $per_page, $offset ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$items = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_values ) );

		return [
			'items' => $items ?: [],
			'total' => $total,
		];
	}

	/**
	 * Count customers by status.
	 *
	 * @param string $status active|inactive|all
	 * @return int
	 */
	public static function count( string $status = 'active' ): int {
		global $wpdb;

		$table = self::table_name();

		if ( 'all' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			$status = 'active';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status = %s",
			$status
		) );
	}

	/**
	 * Sanitize and validate data for insert/update.
	 * Strips unknown keys, normalizes phones, validates enum-like fields.
	 *
	 * @param array $data Raw input.
	 * @return array|false Sanitized data or false if validation fails.
	 */
	private static function sanitize_data( array $data ) {
		// Strip unknown keys
		$data = array_intersect_key( $data, array_flip( self::ALLOWED_COLUMNS ) );

		// Normalize phone fields
		if ( isset( $data['phone'] ) ) {
			$data['phone'] = self::normalize_phone( $data['phone'] );
			if ( '' === $data['phone'] ) {
				return false;
			}
		}

		if ( isset( $data['phone_alt'] ) ) {
			$normalized_alt = self::normalize_phone( $data['phone_alt'] );
			$data['phone_alt'] = '' !== $normalized_alt ? $normalized_alt : null;
		}

		// Validate enum-like fields
		if ( isset( $data['customer_type'] ) && ! in_array( $data['customer_type'], self::VALID_CUSTOMER_TYPES, true ) ) {
			return false;
		}

		if ( isset( $data['source'] ) && ! in_array( $data['source'], self::VALID_SOURCES, true ) ) {
			return false;
		}

		if ( isset( $data['status'] ) && ! in_array( $data['status'], self::VALID_STATUSES, true ) ) {
			return false;
		}

		// Sanitize strings
		$text_fields = [ 'name_arabic', 'name_english', 'branch', 'file_number', 'customer_type', 'source', 'status' ];
		foreach ( $text_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$data[ $field ] = sanitize_text_field( $data[ $field ] );
			}
		}

		if ( isset( $data['email'] ) ) {
			$data['email'] = sanitize_email( $data['email'] );
		}

		if ( isset( $data['address'] ) ) {
			$data['address'] = sanitize_textarea_field( $data['address'] );
		}

		if ( isset( $data['odoo_id'] ) ) {
			$data['odoo_id'] = absint( $data['odoo_id'] ) ?: null;
		}

		return $data;
	}
}
