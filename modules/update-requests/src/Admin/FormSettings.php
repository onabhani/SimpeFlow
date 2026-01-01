<?php
namespace SFA\UpdateRequests\Admin;

/**
 * Gravity Forms Settings Integration
 *
 * Adds update requests settings to individual forms
 */
class FormSettings {

	const SLUG = 'sfa-update-requests';

	public function __construct() {
		add_filter( 'gform_form_settings_menu', [ $this, 'add_settings_tab' ], 10 );
		add_action( 'gform_form_settings_page_' . self::SLUG, [ $this, 'render_settings_page' ] );
		add_action( 'admin_post_sfa_ur_save_form_settings', [ $this, 'save_form_settings' ] );
	}

	/**
	 * Add Update Requests tab to form settings
	 */
	public function add_settings_tab( $tabs ) {
		// Check if tab already exists
		foreach ( (array) $tabs as $tab ) {
			if ( ( isset( $tab['name'] ) && $tab['name'] === self::SLUG ) ||
			     ( isset( $tab['label'] ) && $tab['label'] === 'Update Requests' ) ) {
				return $tabs;
			}
		}

		$tabs[] = [
			'name'  => self::SLUG,
			'label' => 'Update Requests',
			'icon'  => 'gform-icon--refresh',
		];

		return $tabs;
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		// Get form ID
		$form_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( ! $form_id ) {
			echo '<div class="notice notice-error"><p>Invalid form ID.</p></div>';
			return;
		}

		$form = \GFAPI::get_form( $form_id );

		if ( ! $form ) {
			echo '<div class="notice notice-error"><p>Form not found.</p></div>';
			return;
		}

		// Get current settings
		$enabled = (bool) rgar( $form, 'sfa_ur_enabled' );
		$drawing_field_id = (int) rgar( $form, 'sfa_ur_drawing_field' );
		$invoice_field_id = (int) rgar( $form, 'sfa_ur_invoice_field' );
		$approval_guards = rgar( $form, 'sfa_ur_approval_guards' );
		if ( ! is_array( $approval_guards ) ) {
			$approval_guards = array();
		}

		// Get GravityFlow steps if available
		$workflow_steps = [];
		if ( class_exists( 'Gravity_Flow_API' ) ) {
			$api = new \Gravity_Flow_API( $form_id );
			$steps = $api->get_steps();
			foreach ( $steps as $step ) {
				$workflow_steps[] = [
					'id'    => $step->get_id(),
					'name'  => $step->get_name(),
					'type'  => $step->get_type(),
				];
			}
		}

		// Build field options for file upload fields
		$file_fields = [];

		foreach ( $form['fields'] as $field ) {
			if ( $field->type === 'fileupload' ) {
				$file_fields[] = [
					'value' => $field->id,
					'label' => $field->label . ' (ID: ' . $field->id . ')',
				];
			}
		}

		// Show success message
		if ( isset( $_GET['updated'] ) && $_GET['updated'] === '1' ) {
			echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully.</p></div>';
		}

		?>
		<style>
			.sfa-ur-settings-wrap {
				max-width: 800px;
				margin: 20px 0;
			}
			.sfa-ur-settings-wrap h3 {
				margin-top: 0;
			}
			.sfa-ur-field-row {
				margin: 20px 0;
				padding: 15px;
				background: #f9f9f9;
				border-left: 3px solid #0073aa;
			}
			.sfa-ur-field-row label {
				display: block;
				font-weight: bold;
				margin-bottom: 8px;
			}
			.sfa-ur-field-row select {
				width: 100%;
				max-width: 400px;
			}
			.sfa-ur-field-row .description {
				margin-top: 5px;
				color: #666;
				font-size: 13px;
			}
			.sfa-ur-guards-list {
				margin-top: 10px;
			}
			.sfa-ur-guards-list label {
				display: block;
				margin: 5px 0;
				font-weight: normal;
			}
			.sfa-ur-guards-list input[type="checkbox"] {
				margin-right: 8px;
			}
		</style>

		<div class="sfa-ur-settings-wrap">
			<h3>Update Requests Configuration</h3>

			<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
				<input type="hidden" name="action" value="sfa_ur_save_form_settings">
				<input type="hidden" name="form_id" value="<?php echo $form_id; ?>">
				<?php wp_nonce_field( 'sfa_ur_form_settings_' . $form_id ); ?>

				<!-- Enable Update Requests -->
				<div class="sfa-ur-field-row">
					<label>
						<input type="checkbox" name="sfa_ur_enabled" value="1" <?php checked( $enabled ); ?>>
						Enable Update Requests for this form
					</label>
					<p class="description">
						Allow post-invoice file updates and following invoices for entries from this form.
					</p>
				</div>

				<!-- Drawing Field -->
				<div class="sfa-ur-field-row">
					<label for="sfa_ur_drawing_field">Drawing Field</label>
					<select name="sfa_ur_drawing_field" id="sfa_ur_drawing_field">
						<option value="">-- Select Field --</option>
						<?php foreach ( $file_fields as $field ): ?>
							<option value="<?php echo $field['value']; ?>" <?php selected( $drawing_field_id, $field['value'] ); ?>>
								<?php echo esc_html( $field['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						Select the multi-file upload field that contains drawings/files that can be updated.
					</p>
				</div>

				<!-- Invoice Field -->
				<div class="sfa-ur-field-row">
					<label for="sfa_ur_invoice_field">Invoice Field (Optional)</label>
					<select name="sfa_ur_invoice_field" id="sfa_ur_invoice_field">
						<option value="">-- Select Field --</option>
						<?php foreach ( $file_fields as $field ): ?>
							<option value="<?php echo $field['value']; ?>" <?php selected( $invoice_field_id, $field['value'] ); ?>>
								<?php echo esc_html( $field['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						Select the field where invoice files are stored. Leave empty if not using following invoices.
					</p>
				</div>

				<!-- Approval Guards (Placeholder - waiting for user decision) -->
				<div class="sfa-ur-field-row">
					<label>Approval Guards</label>
					<?php if ( empty( $workflow_steps ) ): ?>
						<p class="description" style="color: #dc3545;">
							No GravityFlow steps found. Please configure workflow steps first.
						</p>
					<?php else: ?>
						<div class="sfa-ur-guards-list">
							<p class="description" style="margin-bottom: 10px;">
								Select which approval steps must be completed for update requests:
							</p>
							<?php foreach ( $workflow_steps as $step ): ?>
								<?php if ( $step['type'] === 'approval' ): ?>
									<label>
										<input
											type="checkbox"
											name="sfa_ur_approval_guards[]"
											value="<?php echo $step['id']; ?>"
											<?php checked( in_array( $step['id'], $approval_guards ) ); ?>
										>
										<?php echo esc_html( $step['name'] ); ?> (Step #<?php echo $step['id']; ?>)
									</label>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<p class="description" style="margin-top: 10px;">
						Update requests will require approval from selected steps before files are applied to parent entry.
					</p>
				</div>

				<!-- Save Button -->
				<p>
					<button type="submit" class="button button-primary">Save Settings</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Save form settings
	 */
	public function save_form_settings() {
		// Get form ID
		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		if ( ! $form_id ) {
			wp_die( 'Invalid form ID' );
		}

		// Verify nonce
		check_admin_referer( 'sfa_ur_form_settings_' . $form_id );

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		// Get form
		$form = \GFAPI::get_form( $form_id );

		if ( ! $form ) {
			wp_die( 'Form not found' );
		}

		// Save settings
		$form['sfa_ur_enabled'] = isset( $_POST['sfa_ur_enabled'] ) ? 1 : 0;
		$form['sfa_ur_drawing_field'] = isset( $_POST['sfa_ur_drawing_field'] ) ? absint( $_POST['sfa_ur_drawing_field'] ) : 0;
		$form['sfa_ur_invoice_field'] = isset( $_POST['sfa_ur_invoice_field'] ) ? absint( $_POST['sfa_ur_invoice_field'] ) : 0;

		// Save approval guards
		$approval_guards = isset( $_POST['sfa_ur_approval_guards'] ) ? array_map( 'absint', $_POST['sfa_ur_approval_guards'] ) : array();
		$form['sfa_ur_approval_guards'] = $approval_guards;

		// Update form
		\GFAPI::update_form( $form );

		// Redirect back with success message
		$redirect_url = add_query_arg(
			[
				'page' => 'gf_edit_forms',
				'view' => 'settings',
				'subview' => self::SLUG,
				'id' => $form_id,
				'updated' => '1',
			],
			admin_url( 'admin.php' )
		);

		wp_redirect( $redirect_url );
		exit;
	}

	/**
	 * Check if update requests are enabled for form
	 *
	 * @param array|int $form Form array or form ID
	 * @return bool
	 */
	public static function is_enabled( $form ) {
		if ( is_numeric( $form ) ) {
			$form = \GFAPI::get_form( $form );
		}

		return (bool) rgar( $form, 'sfa_ur_enabled' );
	}

	/**
	 * Get drawing field ID for form
	 *
	 * @param array|int $form Form array or form ID
	 * @return int
	 */
	public static function get_drawing_field_id( $form ) {
		if ( is_numeric( $form ) ) {
			$form = \GFAPI::get_form( $form );
		}

		return (int) rgar( $form, 'sfa_ur_drawing_field' );
	}

	/**
	 * Get invoice field ID for form
	 *
	 * @param array|int $form Form array or form ID
	 * @return int
	 */
	public static function get_invoice_field_id( $form ) {
		if ( is_numeric( $form ) ) {
			$form = \GFAPI::get_form( $form );
		}

		return (int) rgar( $form, 'sfa_ur_invoice_field' );
	}

	/**
	 * Get approval guard step IDs for form
	 *
	 * @param array|int $form Form array or form ID
	 * @return array
	 */
	public static function get_approval_guards( $form ) {
		if ( is_numeric( $form ) ) {
			$form = \GFAPI::get_form( $form );
		}

		$guards = rgar( $form, 'sfa_ur_approval_guards' );
		return is_array( $guards ) ? $guards : array();
	}
}
