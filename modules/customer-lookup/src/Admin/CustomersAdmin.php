<?php
namespace SFA\CustomerLookup\Admin;

use SFA\CustomerLookup\Database\CustomerTable;

/**
 * Customers Admin
 *
 * Menu registration, view routing, create/edit/profile forms,
 * deactivate/reactivate actions, and AJAX phone duplicate check.
 */
class CustomersAdmin {

	const MENU_SLUG = 'sfa-customers';

	/**
	 * Translatable label maps for stored enum-like values.
	 */
	private static function status_labels(): array {
		return [
			'active'       => __( 'Active', 'simpleflow' ),
			'inactive'     => __( 'Inactive', 'simpleflow' ),
			'needs_review' => __( 'Needs Review', 'simpleflow' ),
		];
	}

	private static function source_labels(): array {
		return [
			'manual'    => __( 'Manual', 'simpleflow' ),
			'odoo'      => __( 'Odoo', 'simpleflow' ),
			'migration' => __( 'Migration', 'simpleflow' ),
		];
	}

	private static function type_labels(): array {
		return [
			'individual' => __( 'Individual', 'simpleflow' ),
			'company'    => __( 'Company', 'simpleflow' ),
			'project'    => __( 'Project', 'simpleflow' ),
		];
	}

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], 99 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_sfa_cl_check_phone_exists', [ $this, 'ajax_check_phone' ] );
	}

	/**
	 * Add submenu page under SimpleFlow.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'simpleflow',
			__( 'Customers', 'simpleflow' ),
			__( 'Customers', 'simpleflow' ),
			'gform_full_access',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueue CSS/JS only on our admin page.
	 */
	public function enqueue_assets( $hook ): void {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'sfa-customers-admin',
			SFA_CL_URL . 'assets/sf-customers-admin.css',
			[],
			SFA_CL_VER
		);

		wp_enqueue_script(
			'sfa-customers-admin',
			SFA_CL_URL . 'assets/sf-customers-admin.js',
			[ 'jquery' ],
			SFA_CL_VER,
			true
		);

		wp_localize_script( 'sfa-customers-admin', 'sfaCustomers', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'sfa_cl_admin' ),
			'i18n'     => [
				'phone_exists' => __( 'This phone number is already registered.', 'simpleflow' ),
			],
		] );
	}

	/**
	 * Main page router.
	 */
	public function render_page(): void {
		// Handle deactivate/reactivate actions first
		$this->handle_status_action();

		$view = sanitize_text_field( $_GET['view'] ?? 'list' );

		echo '<div class="wrap sfa-customers-wrap">';

		switch ( $view ) {
			case 'create':
				$this->render_create();
				break;
			case 'edit':
				$this->render_edit();
				break;
			case 'profile':
				$this->render_profile();
				break;
			default:
				$this->render_list();
				break;
		}

		echo '</div>';
	}

	/**
	 * Handle deactivate/reactivate URL actions.
	 */
	private function handle_status_action(): void {
		$action = sanitize_text_field( $_GET['action'] ?? '' );

		if ( ! in_array( $action, [ 'deactivate', 'reactivate' ], true ) ) {
			return;
		}

		$id = absint( $_GET['id'] ?? 0 );

		if ( ! $id ) {
			return;
		}

		// Verify nonce with action-specific string (includes record ID)
		$nonce_action = 'sfa_' . $action . '_' . $id;
		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', $nonce_action ) ) {
			wp_die( __( 'Security check failed.', 'simpleflow' ) );
		}

		if ( ! current_user_can( 'gform_full_access' ) ) {
			wp_die( __( 'Insufficient permissions.', 'simpleflow' ) );
		}

		if ( 'deactivate' === $action ) {
			$success = CustomerTable::deactivate( $id );
			$notice  = $success ? 'deactivated' : 'status_update_failed';
		} else {
			$success = CustomerTable::reactivate( $id );
			$notice  = $success ? 'reactivated' : 'status_update_failed';
		}

		wp_safe_redirect( add_query_arg(
			[ 'page' => self::MENU_SLUG, 'notice' => $notice ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Render the list view.
	 */
	private function render_list(): void {
		$notice = sanitize_text_field( $_GET['notice'] ?? '' );

		if ( $notice ) {
			$messages = [
				'created'              => __( 'Customer created successfully.', 'simpleflow' ),
				'updated'              => __( 'Customer updated successfully.', 'simpleflow' ),
				'deactivated'          => __( 'Customer deactivated.', 'simpleflow' ),
				'reactivated'          => __( 'Customer reactivated.', 'simpleflow' ),
				'status_update_failed' => __( 'Failed to update customer status. Please try again.', 'simpleflow' ),
			];
			if ( isset( $messages[ $notice ] ) ) {
				$notice_type = ( 'status_update_failed' === $notice ) ? 'error' : 'success';
				printf(
					'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
					esc_attr( $notice_type ),
					esc_html( $messages[ $notice ] )
				);
			}
		}

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Customers', 'simpleflow' ) . '</h1>';
		printf(
			' <a href="%s" class="page-title-action">%s</a>',
			esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'view' => 'create' ], admin_url( 'admin.php' ) ) ),
			esc_html__( 'Add Customer', 'simpleflow' )
		);

		echo '<hr class="wp-header-end">';

		$table = new CustomersListTable();
		$table->prepare_items();
		$table->render_filter_tabs();
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '">';
		if ( ! empty( $_REQUEST['status'] ) ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_text_field( $_REQUEST['status'] ) ) . '">';
		}
		$table->search_box( __( 'Search Customers', 'simpleflow' ), 'sfa-customer-search' );
		$table->display();
		echo '</form>';
	}

	/**
	 * Render the create form.
	 */
	private function render_create(): void {
		$errors = [];
		$data   = [];

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			check_admin_referer( 'sfa_cl_create_customer' );

			$data = $this->sanitize_form_input();
			$errors = $this->validate_form_input( $data );

			if ( empty( $errors ) ) {
				$id = CustomerTable::insert( $data );
				if ( false !== $id ) {
					wp_safe_redirect( add_query_arg(
						[ 'page' => self::MENU_SLUG, 'notice' => 'created' ],
						admin_url( 'admin.php' )
					) );
					exit;
				}
				$errors[] = __( 'Failed to create customer. Please try again.', 'simpleflow' );
			}
		}

		echo '<h1>' . esc_html__( 'Add Customer', 'simpleflow' ) . '</h1>';
		$this->render_form( $data, $errors, 'create' );
	}

	/**
	 * Render the edit form.
	 */
	private function render_edit(): void {
		$id       = absint( $_GET['id'] ?? 0 );
		$customer = $id ? CustomerTable::get_by_id( $id ) : null;

		if ( ! $customer ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Customer not found.', 'simpleflow' ) . '</p></div>';
			return;
		}

		$errors = [];
		$data   = (array) $customer;

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			check_admin_referer( 'sfa_cl_edit_customer_' . $id );

			$data   = $this->sanitize_form_input();
			$errors = $this->validate_form_input( $data, $id );

			if ( empty( $errors ) ) {
				$success = CustomerTable::update( $id, $data );
				if ( $success ) {
					wp_safe_redirect( add_query_arg(
						[ 'page' => self::MENU_SLUG, 'notice' => 'updated' ],
						admin_url( 'admin.php' )
					) );
					exit;
				}
				$errors[] = __( 'Failed to update customer. Please try again.', 'simpleflow' );
			}
		}

		echo '<h1>' . esc_html__( 'Edit Customer', 'simpleflow' ) . '</h1>';
		$this->render_form( $data, $errors, 'edit', $id );
	}

	/**
	 * Render the read-only profile view.
	 */
	private function render_profile(): void {
		$id       = absint( $_GET['id'] ?? 0 );
		$customer = $id ? CustomerTable::get_by_id( $id ) : null;

		if ( ! $customer ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Customer not found.', 'simpleflow' ) . '</p></div>';
			return;
		}

		$base = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		echo '<h1>' . esc_html__( 'Customer Profile', 'simpleflow' ) . '</h1>';

		echo '<div class="sfa-profile-card">';
		echo '<table class="form-table">';

		$fields = [
			'id'            => __( 'ID', 'simpleflow' ),
			'name_arabic'   => __( 'Name (Arabic)', 'simpleflow' ),
			'name_english'  => __( 'Name (English)', 'simpleflow' ),
			'phone'         => __( 'Phone', 'simpleflow' ),
			'phone_alt'     => __( 'Phone (Alt)', 'simpleflow' ),
			'email'         => __( 'Email', 'simpleflow' ),
			'address'       => __( 'Address', 'simpleflow' ),
			'customer_type' => __( 'Type', 'simpleflow' ),
			'branch'        => __( 'Branch', 'simpleflow' ),
			'file_number'   => __( 'File Number', 'simpleflow' ),
			'source'        => __( 'Source', 'simpleflow' ),
			'status'        => __( 'Status', 'simpleflow' ),
			'created_at'    => __( 'Created', 'simpleflow' ),
			'updated_at'    => __( 'Updated', 'simpleflow' ),
		];

		if ( ! empty( $customer->odoo_id ) ) {
			$fields['odoo_id'] = __( 'Odoo ID', 'simpleflow' );
		}

		if ( ! empty( $customer->gf_entry_id ) ) {
			$fields['gf_entry_id'] = __( 'GF Entry ID', 'simpleflow' );
		}

		if ( ! empty( $customer->review_note ) ) {
			$fields['review_note'] = __( 'Review Note', 'simpleflow' );
		}

		$status_labels = self::status_labels();
		$source_labels = self::source_labels();
		$type_labels   = self::type_labels();

		foreach ( $fields as $key => $label ) {
			$value = $customer->$key ?? '';

			if ( 'status' === $key ) {
				$display = $status_labels[ $value ] ?? esc_html( $value );
				$cell = sprintf( '<span class="sfa-badge sfa-badge--%s">%s</span>', esc_attr( $value ), esc_html( $display ) );
			} elseif ( 'source' === $key ) {
				$display = $source_labels[ $value ] ?? esc_html( $value );
				$cell = sprintf( '<span class="sfa-badge sfa-badge--source-%s">%s</span>', esc_attr( $value ), esc_html( $display ) );
			} elseif ( 'customer_type' === $key ) {
				$cell = esc_html( $type_labels[ $value ] ?? $value ?: '—' );
			} else {
				$cell = esc_html( $value ?: '—' );
			}

			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $label ), $cell );
		}

		echo '</table>';

		echo '<p class="sfa-profile-actions">';
		printf(
			'<a href="%s" class="button button-primary">%s</a> ',
			esc_url( add_query_arg( [ 'view' => 'edit', 'id' => $id ], $base ) ),
			esc_html__( 'Edit', 'simpleflow' )
		);

		if ( 'active' === $customer->status ) {
			printf(
				'<a href="%s" class="button" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( wp_nonce_url(
					add_query_arg( [ 'action' => 'deactivate', 'id' => $id ], $base ),
					'sfa_deactivate_' . $id
				) ),
				esc_js( __( 'Deactivate this customer?', 'simpleflow' ) ),
				esc_html__( 'Deactivate', 'simpleflow' )
			);
		} else {
			printf(
				'<a href="%s" class="button">%s</a>',
				esc_url( wp_nonce_url(
					add_query_arg( [ 'action' => 'reactivate', 'id' => $id ], $base ),
					'sfa_reactivate_' . $id
				) ),
				esc_html__( 'Reactivate', 'simpleflow' )
			);
		}

		printf(
			' <a href="%s" class="button">%s</a>',
			esc_url( add_query_arg( 'page', self::MENU_SLUG, $base ) ),
			esc_html__( 'Back to List', 'simpleflow' )
		);

		echo '</p>';
		echo '</div>';

		// Orders panel — linked via GravityFlow Parent Entry Connector
		$this->render_orders_panel( $customer );
	}

	/**
	 * Render the linked orders panel on the profile page.
	 * Queries all configured order forms grouped by form name.
	 */
	private function render_orders_panel( object $customer ): void {
		$order_form_ids = get_option( 'sfa_cl_order_form_ids', [] );
		$source_form_id = (int) get_option( 'sfa_cl_source_form_id', 0 );
		$gf_entry_id    = (int) ( $customer->gf_entry_id ?? 0 );

		// Backward compat: migrate old single-form setting
		if ( empty( $order_form_ids ) ) {
			$legacy = (int) get_option( 'sfa_cl_order_form_id', 0 );
			if ( $legacy ) {
				$order_form_ids = [ $legacy ];
			}
		}

		if ( empty( $order_form_ids ) || ! $gf_entry_id || ! $source_form_id || ! class_exists( 'GFAPI' ) ) {
			return;
		}

		// GravityFlow Parent Entry Connector meta key format
		$meta_key    = 'workflow_parent_form_id_' . $source_form_id . '_entry_id';
		$total_count = 0;

		// Collect entries grouped by form
		$form_groups = [];
		foreach ( $order_form_ids as $fid ) {
			$fid  = (int) $fid;
			$form = \GFAPI::get_form( $fid );
			if ( ! $form || is_wp_error( $form ) ) {
				continue;
			}

			$entries = \GFAPI::get_entries( $fid, [
				'status'        => 'active',
				'field_filters' => [
					[ 'key' => $meta_key, 'value' => (string) $gf_entry_id ],
				],
			], [ 'key' => 'date_created', 'direction' => 'DESC' ], [ 'offset' => 0, 'page_size' => 100 ] );

			if ( is_wp_error( $entries ) || empty( $entries ) ) {
				continue;
			}

			$form_groups[] = [
				'form_id'   => $fid,
				'form_name' => $form['title'],
				'entries'   => $entries,
			];
			$total_count += count( $entries );
		}

		echo '<div class="sfa-profile-card" style="margin-top:16px;">';
		echo '<h2>' . esc_html__( 'Orders', 'simpleflow' ) . ' <span class="count">(' . $total_count . ')</span></h2>';

		if ( empty( $form_groups ) ) {
			echo '<p>' . esc_html__( 'No orders linked to this customer.', 'simpleflow' ) . '</p>';
			echo '</div>';
			return;
		}

		foreach ( $form_groups as $group ) {
			$fid           = $group['form_id'];
			$entry_url_base = admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $fid . '&lid=' );

			if ( count( $form_groups ) > 1 ) {
				echo '<h3 style="margin-top:12px;">' . esc_html( $group['form_name'] ) . ' <span class="count">(' . count( $group['entries'] ) . ')</span></h3>';
			}

			echo '<table class="widefat striped" style="margin-top:8px;">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Entry ID', 'simpleflow' ) . '</th>';
			echo '<th>' . esc_html__( 'Date', 'simpleflow' ) . '</th>';
			echo '<th>' . esc_html__( 'Created By', 'simpleflow' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'simpleflow' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'simpleflow' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $group['entries'] as $entry ) {
				$eid     = (int) $entry['id'];
				$date    = esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry['date_created'] ) ) );
				$user    = '';
				$user_id = (int) ( $entry['created_by'] ?? 0 );
				if ( $user_id ) {
					$u = get_userdata( $user_id );
					$user = $u ? esc_html( $u->display_name ) : '#' . $user_id;
				}

				$wf_status = gform_get_meta( $eid, 'workflow_final_status' );
				if ( ! $wf_status ) {
					$wf_status = gform_get_meta( $eid, 'workflow_current_status_timestamp' ) ? __( 'In Progress', 'simpleflow' ) : '—';
				}

				echo '<tr>';
				printf( '<td><a href="%s">%d</a></td>', esc_url( $entry_url_base . $eid ), $eid );
				echo '<td>' . $date . '</td>';
				echo '<td>' . $user . '</td>';
				echo '<td>' . esc_html( ucfirst( str_replace( '_', ' ', $wf_status ) ) ) . '</td>';
				printf(
					'<td><a href="%s">%s</a></td>',
					esc_url( $entry_url_base . $eid ),
					esc_html__( 'View', 'simpleflow' )
				);
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/**
	 * Render the create/edit form.
	 */
	private function render_form( array $data, array $errors, string $mode, int $id = 0 ): void {
		$base = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		if ( ! empty( $errors ) ) {
			echo '<div class="notice notice-error"><ul>';
			foreach ( $errors as $err ) {
				echo '<li>' . esc_html( $err ) . '</li>';
			}
			echo '</ul></div>';
		}

		$action_url = ( 'edit' === $mode )
			? add_query_arg( [ 'page' => self::MENU_SLUG, 'view' => 'edit', 'id' => $id ], $base )
			: add_query_arg( [ 'page' => self::MENU_SLUG, 'view' => 'create' ], $base );

		$nonce_action = ( 'edit' === $mode )
			? 'sfa_cl_edit_customer_' . $id
			: 'sfa_cl_create_customer';

		echo '<form method="post" action="' . esc_url( $action_url ) . '" id="sfa-customer-form" style="max-width:700px;">';
		wp_nonce_field( $nonce_action );

		if ( $id ) {
			echo '<input type="hidden" name="exclude_id" value="' . esc_attr( $id ) . '">';
		}

		echo '<table class="form-table">';

		// Name Arabic (required)
		$this->render_field( 'name_arabic', __( 'Name (Arabic)', 'simpleflow' ), $data, true );

		// Name English
		$this->render_field( 'name_english', __( 'Name (English)', 'simpleflow' ), $data );

		// Phone (required)
		$this->render_field( 'phone', __( 'Phone', 'simpleflow' ), $data, true, 'tel' );

		// Phone Alt
		$this->render_field( 'phone_alt', __( 'Phone (Alt)', 'simpleflow' ), $data, false, 'tel' );

		// Email
		$this->render_field( 'email', __( 'Email', 'simpleflow' ), $data, false, 'email' );

		// Address
		echo '<tr>';
		echo '<th><label for="sfa_address">' . esc_html__( 'Address', 'simpleflow' ) . '</label></th>';
		echo '<td><textarea name="address" id="sfa_address" class="large-text" rows="3">' . esc_textarea( $data['address'] ?? '' ) . '</textarea></td>';
		echo '</tr>';

		// Customer Type (radio)
		echo '<tr>';
		echo '<th>' . esc_html__( 'Customer Type', 'simpleflow' ) . '</th>';
		echo '<td>';
		$current_type = $data['customer_type'] ?? 'individual';
		$type_labels  = self::type_labels();
		foreach ( CustomerTable::VALID_CUSTOMER_TYPES as $type ) {
			printf(
				'<label style="margin-inline-end:16px;"><input type="radio" name="customer_type" value="%s" %s> %s</label>',
				esc_attr( $type ),
				checked( $current_type, $type, false ),
				esc_html( $type_labels[ $type ] ?? $type )
			);
		}
		echo '</td>';
		echo '</tr>';

		// Branch
		$this->render_field( 'branch', __( 'Branch', 'simpleflow' ), $data );

		// File Number
		$this->render_field( 'file_number', __( 'File Number', 'simpleflow' ), $data );

		echo '</table>';

		echo '<p class="submit">';
		printf(
			'<button type="submit" class="button button-primary" id="sfa-submit-btn">%s</button> ',
			( 'edit' === $mode ) ? esc_html__( 'Update Customer', 'simpleflow' ) : esc_html__( 'Create Customer', 'simpleflow' )
		);
		printf(
			'<a href="%s" class="button">%s</a>',
			esc_url( add_query_arg( 'page', self::MENU_SLUG, $base ) ),
			esc_html__( 'Cancel', 'simpleflow' )
		);
		echo '</p>';

		echo '</form>';
	}

	/**
	 * Render a single text/email/tel form field row.
	 */
	private function render_field( string $name, string $label, array $data, bool $required = false, string $type = 'text' ): void {
		$id    = 'sfa_' . $name;
		$value = $data[ $name ] ?? '';

		echo '<tr>';
		printf(
			'<th><label for="%s">%s%s</label></th>',
			esc_attr( $id ),
			esc_html( $label ),
			$required ? ' <span style="color:#d63638;">*</span>' : ''
		);
		printf(
			'<td><input type="%s" name="%s" id="%s" value="%s" class="regular-text"%s></td>',
			esc_attr( $type ),
			esc_attr( $name ),
			esc_attr( $id ),
			esc_attr( $value ),
			$required ? ' required' : ''
		);
		echo '</tr>';
	}

	/**
	 * Sanitize form POST data.
	 */
	private function sanitize_form_input(): array {
		return [
			'name_arabic'   => sanitize_text_field( $_POST['name_arabic'] ?? '' ),
			'name_english'  => sanitize_text_field( $_POST['name_english'] ?? '' ),
			'phone'         => sanitize_text_field( $_POST['phone'] ?? '' ),
			'phone_alt'     => sanitize_text_field( $_POST['phone_alt'] ?? '' ),
			'email'         => sanitize_email( $_POST['email'] ?? '' ),
			'address'       => sanitize_textarea_field( $_POST['address'] ?? '' ),
			'customer_type' => sanitize_text_field( $_POST['customer_type'] ?? 'individual' ),
			'branch'        => sanitize_text_field( $_POST['branch'] ?? '' ),
			'file_number'   => sanitize_text_field( $_POST['file_number'] ?? '' ),
		];
	}

	/**
	 * Validate form input. Returns array of error messages (empty = valid).
	 */
	private function validate_form_input( array $data, int $exclude_id = 0 ): array {
		$errors = [];

		if ( empty( $data['name_arabic'] ) ) {
			$errors[] = __( 'Name (Arabic) is required.', 'simpleflow' );
		}

		if ( empty( $data['phone'] ) ) {
			$errors[] = __( 'Phone is required.', 'simpleflow' );
		} elseif ( CustomerTable::phone_exists( $data['phone'], $exclude_id ) ) {
			$errors[] = __( 'This phone number is already registered.', 'simpleflow' );
		}

		if ( ! empty( $data['customer_type'] ) && ! in_array( $data['customer_type'], CustomerTable::VALID_CUSTOMER_TYPES, true ) ) {
			$errors[] = __( 'Invalid customer type.', 'simpleflow' );
		}

		return $errors;
	}

	/**
	 * AJAX handler: check if a phone number already exists.
	 */
	public function ajax_check_phone(): void {
		check_ajax_referer( 'sfa_cl_admin', '_wpnonce' );

		if ( ! current_user_can( 'gform_full_access' ) ) {
			wp_send_json_error( __( 'Unauthorized', 'simpleflow' ), 403 );
			return;
		}

		$phone      = sanitize_text_field( $_POST['phone'] ?? '' );
		$exclude_id = absint( $_POST['exclude_id'] ?? 0 );

		$exists = CustomerTable::phone_exists( $phone, $exclude_id );

		wp_send_json_success( [ 'exists' => $exists ] );
	}
}
