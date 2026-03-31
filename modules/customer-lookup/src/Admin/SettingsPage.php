<?php
namespace SFA\CustomerLookup\Admin;

/**
 * Settings Page
 *
 * Admin page for configuring the customer lookup source form and field mapping.
 * Registers as a submenu under SimpleFlow.
 */
class SettingsPage {

	const MENU_SLUG = 'sfa-customer-lookup';

	/**
	 * Semantic field keys and their labels.
	 */
	const FIELD_KEYS = [
		'phone'         => 'Phone (Primary)',
		'phone_alt'     => 'Phone (Alternate)',
		'name_arabic'   => 'Name (Arabic)',
		'name_english'  => 'Name (English)',
		'email'         => 'Email',
		'address'       => 'Address',
		'customer_type' => 'Customer Type',
		'branch'        => 'Branch',
		'file_number'   => 'File Number',
	];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], 99 );
		add_action( 'admin_post_sfa_cl_save_settings', [ $this, 'save_settings' ] );
	}

	/**
	 * Add submenu page under SimpleFlow
	 */
	public function add_menu_page() {
		add_submenu_page(
			'simpleflow',
			__( 'Customer Lookup', 'simpleflow' ),
			__( 'Customer Lookup', 'simpleflow' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render settings page
	 */
	public function render_page() {
		$forms     = class_exists( 'GFAPI' ) ? \GFAPI::get_forms() : [];
		$form_id   = (int) get_option( 'sfa_cl_source_form_id', 0 );
		$field_map = get_option( 'sfa_cl_field_map', [] );
		$use_wpdb     = (bool) get_option( 'sfa_cl_use_direct_db', false );
		$use_sf_table = (bool) get_option( 'sfa_cl_use_sf_table', false );

		// Get fields for selected form
		$form_fields = [];
		if ( $form_id && class_exists( 'GFAPI' ) ) {
			$form = \GFAPI::get_form( $form_id );
			if ( $form && ! empty( $form['fields'] ) ) {
				foreach ( $form['fields'] as $field ) {
					$form_fields[] = [
						'id'    => $field->id,
						'label' => $field->label,
						'type'  => $field->type,
					];
				}
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Customer Lookup Settings', 'simpleflow' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure the source form and field mapping for phone-based customer lookup on order forms.', 'simpleflow' ); ?>
			</p>

			<?php if ( isset( $_GET['updated'] ) ): ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'simpleflow' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px;">
				<input type="hidden" name="action" value="sfa_cl_save_settings">
				<?php wp_nonce_field( 'sfa_cl_save_settings' ); ?>

				<table class="form-table">
					<tr>
						<th><label for="sfa_cl_source_form_id"><?php esc_html_e( 'Customer Source Form', 'simpleflow' ); ?></label></th>
						<td>
							<select name="sfa_cl_source_form_id" id="sfa_cl_source_form_id">
								<option value=""><?php esc_html_e( '-- Select Form --', 'simpleflow' ); ?></option>
								<?php foreach ( $forms as $f ): ?>
									<option value="<?php echo esc_attr( $f['id'] ); ?>" <?php selected( $form_id, (int) $f['id'] ); ?>>
										<?php echo esc_html( $f['title'] ); ?> (ID: <?php echo esc_html( $f['id'] ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The Gravity Forms form that stores customer records.', 'simpleflow' ); ?></p>
						</td>
					</tr>
				</table>

				<?php if ( $form_id && ! empty( $form_fields ) ): ?>
					<h2><?php esc_html_e( 'Field Mapping', 'simpleflow' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Map each customer field to its corresponding Gravity Forms field in the source form. "Phone (Primary)" is required.', 'simpleflow' ); ?>
					</p>

					<table class="form-table">
						<?php foreach ( self::FIELD_KEYS as $key => $label ): ?>
							<tr>
								<th>
									<label for="sfa_cl_field_<?php echo esc_attr( $key ); ?>">
										<?php echo esc_html( $label ); ?>
										<?php if ( 'phone' === $key ): ?>
											<span style="color:#d63638;">*</span>
										<?php endif; ?>
									</label>
								</th>
								<td>
									<select name="sfa_cl_field_map[<?php echo esc_attr( $key ); ?>]"
											id="sfa_cl_field_<?php echo esc_attr( $key ); ?>">
										<option value=""><?php esc_html_e( '-- Not Mapped --', 'simpleflow' ); ?></option>
										<?php foreach ( $form_fields as $ff ): ?>
											<option value="<?php echo esc_attr( $ff['id'] ); ?>"
												<?php selected( $field_map[ $key ] ?? '', $ff['id'] ); ?>>
												<?php echo esc_html( $ff['label'] ); ?> (ID: <?php echo esc_html( $ff['id'] ); ?>, <?php echo esc_html( $ff['type'] ); ?>)
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php elseif ( $form_id ): ?>
					<div class="notice notice-warning"><p><?php esc_html_e( 'Selected form has no fields. Please verify the form exists and has fields.', 'simpleflow' ); ?></p></div>
				<?php else: ?>
					<p class="description" style="margin-top:16px;">
						<?php esc_html_e( 'Select a source form above and save to configure field mapping.', 'simpleflow' ); ?>
					</p>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Advanced', 'simpleflow' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="sfa_cl_use_sf_table"><?php esc_html_e( 'Use SF Customers Table', 'simpleflow' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="sfa_cl_use_sf_table" id="sfa_cl_use_sf_table" value="1" <?php checked( $use_sf_table ); ?>>
								<?php esc_html_e( 'Query wp_sf_customers directly (fastest — enable after migration is complete)', 'simpleflow' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Bypasses Gravity Forms entirely. Requires migration to have run successfully. Once enabled, new customers must be created via the Customers admin page.', 'simpleflow' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="sfa_cl_use_direct_db"><?php esc_html_e( 'Use Direct DB Queries', 'simpleflow' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="sfa_cl_use_direct_db" id="sfa_cl_use_direct_db" value="1" <?php checked( $use_wpdb ); ?>>
								<?php esc_html_e( 'Bypass GFAPI and query the database directly (only enable if GFAPI is too slow)', 'simpleflow' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'GFAPI is used by default. Enable this only after benchmarking shows GFAPI cannot meet performance targets at your entry count.', 'simpleflow' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'simpleflow' ); ?></button>
				</p>
			</form>

			<?php if ( $form_id && ! empty( $field_map['phone'] ) ): ?>
				<hr>
				<h2><?php esc_html_e( 'CSS Classes for Order Forms', 'simpleflow' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Add these CSS classes to the corresponding fields in each order form:', 'simpleflow' ); ?></p>
				<table class="widefat striped" style="max-width:500px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'CSS Class', 'simpleflow' ); ?></th>
							<th><?php esc_html_e( 'Purpose', 'simpleflow' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>sf-customer-phone</code></td>
							<td><?php esc_html_e( 'Triggers lookup (phone input)', 'simpleflow' ); ?></td>
						</tr>
						<?php foreach ( self::FIELD_KEYS as $key => $label ):
							if ( 'phone' === $key ) continue;
							if ( empty( $field_map[ $key ] ) ) continue;
							$css_class = 'sf-field-' . str_replace( '_', '-', $key );
						?>
							<tr>
								<td><code><?php echo esc_html( $css_class ); ?></code></td>
								<td><?php echo esc_html( $label ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle settings save
	 */
	public function save_settings() {
		check_admin_referer( 'sfa_cl_save_settings' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions', 'simpleflow' ) );
		}

		$form_id = absint( $_POST['sfa_cl_source_form_id'] ?? 0 );

		// Validate form exists
		if ( $form_id && class_exists( 'GFAPI' ) ) {
			$form = \GFAPI::get_form( $form_id );
			if ( ! $form || is_wp_error( $form ) ) {
				$form_id = 0;
			}
		}

		update_option( 'sfa_cl_source_form_id', $form_id );

		// Save field map (sanitize each value as absint)
		$raw_map   = $_POST['sfa_cl_field_map'] ?? [];
		$field_map = [];

		if ( is_array( $raw_map ) ) {
			foreach ( array_keys( self::FIELD_KEYS ) as $key ) {
				$field_map[ $key ] = isset( $raw_map[ $key ] ) ? (string) absint( $raw_map[ $key ] ) : '';
			}
		}

		update_option( 'sfa_cl_field_map', $field_map );

		// SF Customers table toggle
		$use_sf_table = ! empty( $_POST['sfa_cl_use_sf_table'] );
		update_option( 'sfa_cl_use_sf_table', $use_sf_table );

		// Direct DB toggle
		$use_wpdb = ! empty( $_POST['sfa_cl_use_direct_db'] );
		update_option( 'sfa_cl_use_direct_db', $use_wpdb );

		wp_safe_redirect( add_query_arg(
			[ 'page' => self::MENU_SLUG, 'updated' => '1' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
