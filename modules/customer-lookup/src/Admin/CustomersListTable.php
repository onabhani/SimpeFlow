<?php
namespace SFA\CustomerLookup\Admin;

use SFA\CustomerLookup\Database\CustomerTable;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Customers List Table
 *
 * Extends WP_List_Table for paginated, sortable, searchable customer listing.
 */
class CustomersListTable extends \WP_List_Table {

	public function __construct() {
		parent::__construct( [
			'singular' => __( 'Customer', 'simpleflow' ),
			'plural'   => __( 'Customers', 'simpleflow' ),
			'ajax'     => false,
		] );
	}

	/**
	 * Define table columns.
	 */
	public function get_columns(): array {
		return [
			'file_number'   => __( 'File No.', 'simpleflow' ),
			'name_arabic'   => __( 'Name (AR)', 'simpleflow' ),
			'name_english'  => __( 'Name (EN)', 'simpleflow' ),
			'phone'         => __( 'Phone', 'simpleflow' ),
			'customer_type' => __( 'Type', 'simpleflow' ),
			'branch'        => __( 'Branch', 'simpleflow' ),
			'status'        => __( 'Status', 'simpleflow' ),
			'actions'       => __( 'Actions', 'simpleflow' ),
		];
	}

	/**
	 * Define sortable columns.
	 */
	public function get_sortable_columns(): array {
		return [
			'file_number'  => [ 'file_number', false ],
			'name_arabic'  => [ 'name_arabic', false ],
			'name_english' => [ 'name_english', false ],
			'phone'        => [ 'phone', false ],
		];
	}

	/**
	 * Prepare items for display.
	 */
	public function prepare_items(): void {
		$per_page = 50;
		$page     = $this->get_pagenum();
		$status   = sanitize_text_field( $_REQUEST['status'] ?? 'active' );
		$search   = sanitize_text_field( $_REQUEST['s'] ?? '' );
		$orderby  = sanitize_text_field( $_REQUEST['orderby'] ?? 'created_at' );
		$order    = sanitize_text_field( $_REQUEST['order'] ?? 'DESC' );

		$result = CustomerTable::list( [
			'status'   => $status,
			'per_page' => $per_page,
			'page'     => $page,
			'orderby'  => $orderby,
			'order'    => $order,
			'search'   => $search,
		] );

		$this->items = $result['items'];

		$this->set_pagination_args( [
			'total_items' => $result['total'],
			'per_page'    => $per_page,
			'total_pages' => ceil( $result['total'] / $per_page ),
		] );

		$this->_column_headers = [
			$this->get_columns(),
			[],
			$this->get_sortable_columns(),
		];
	}

	/**
	 * Render status filter tabs above the table.
	 */
	public function render_filter_tabs(): void {
		$current = sanitize_text_field( $_REQUEST['status'] ?? 'active' );
		$base    = admin_url( 'admin.php?page=sfa-customers' );

		$tabs = [
			'active'   => __( 'Active', 'simpleflow' ),
			'inactive' => __( 'Inactive', 'simpleflow' ),
			'all'      => __( 'All', 'simpleflow' ),
		];

		echo '<ul class="subsubsub">';
		$parts = [];
		foreach ( $tabs as $key => $label ) {
			$count = CustomerTable::count( $key );
			$class = ( $current === $key ) ? 'current' : '';
			$url   = esc_url( add_query_arg( 'status', $key, $base ) );
			$parts[] = sprintf(
				'<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a></li>',
				$url,
				$class,
				esc_html( $label ),
				$count
			);
		}
		echo implode( ' | ', $parts );
		echo '</ul>';
	}

	/**
	 * Default column output.
	 */
	public function column_default( $item, $column_name ): string {
		return esc_html( $item->$column_name ?? '—' );
	}

	/**
	 * Status column with badge.
	 */
	public function column_status( $item ): string {
		$labels = [
			'active'   => __( 'Active', 'simpleflow' ),
			'inactive' => __( 'Inactive', 'simpleflow' ),
		];
		$status = esc_attr( $item->status );
		$label  = esc_html( $labels[ $item->status ] ?? $item->status );
		return sprintf( '<span class="sfa-badge sfa-badge--%s">%s</span>', $status, $label );
	}

	/**
	 * Actions column with row links.
	 */
	public function column_actions( $item ): string {
		$id   = (int) $item->id;
		$base = admin_url( 'admin.php?page=sfa-customers' );

		$links = [];

		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( [ 'view' => 'profile', 'id' => $id ], $base ) ),
			esc_html__( 'View', 'simpleflow' )
		);

		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( [ 'view' => 'edit', 'id' => $id ], $base ) ),
			esc_html__( 'Edit', 'simpleflow' )
		);

		if ( 'active' === $item->status ) {
			$links[] = sprintf(
				'<a href="%s" class="sfa-deactivate" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( wp_nonce_url(
					add_query_arg( [ 'action' => 'deactivate', 'id' => $id ], $base ),
					'sfa_deactivate_' . $id
				) ),
				esc_js( __( 'Deactivate this customer?', 'simpleflow' ) ),
				esc_html__( 'Deactivate', 'simpleflow' )
			);
		} else {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url(
					add_query_arg( [ 'action' => 'reactivate', 'id' => $id ], $base ),
					'sfa_reactivate_' . $id
				) ),
				esc_html__( 'Reactivate', 'simpleflow' )
			);
		}

		return implode( ' | ', $links );
	}

	/**
	 * Message when no items found.
	 */
	public function no_items(): void {
		esc_html_e( 'No customers found.', 'simpleflow' );
	}
}
