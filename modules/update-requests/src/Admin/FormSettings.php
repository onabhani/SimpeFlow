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
		$update_cutoff_step = (int) rgar( $form, 'sfa_ur_update_cutoff_step' );
		$following_cutoff_step = (int) rgar( $form, 'sfa_ur_following_cutoff_step' );
		$approver_id = (int) rgar( $form, 'sfa_ur_approver' );

		// Get users for approver dropdown
		$users = get_users( [
			'role__in' => [ 'administrator', 'editor', 'gravityflow_user_update_requests' ],
			'orderby'  => 'display_name',
		] );

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

				<!-- Approver -->
				<div class="sfa-ur-field-row">
					<label for="sfa_ur_approver">Update Request Approver</label>
					<select name="sfa_ur_approver" id="sfa_ur_approver">
						<option value="0">-- Select Approver --</option>
						<?php foreach ( $users as $user ): ?>
							<option value="<?php echo $user->ID; ?>" <?php selected( $approver_id, $user->ID ); ?>>
								<?php echo esc_html( $user->display_name ); ?> (<?php echo esc_html( $user->user_email ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						Select the user who will approve/reject update requests and following invoices.
					</p>
				</div>

				<!-- Update Request Cutoff Step -->
				<div class="sfa-ur-field-row">
					<label for="sfa_ur_update_cutoff_step">Drawing Update Cutoff Step</label>
					<?php if ( empty( $workflow_steps ) ): ?>
						<p class="description" style="color: #dc3545;">
							No GravityFlow steps found. Please configure workflow steps first.
						</p>
					<?php else: ?>
						<select name="sfa_ur_update_cutoff_step" id="sfa_ur_update_cutoff_step">
							<option value="0">-- Always Allowed --</option>
							<?php foreach ( $workflow_steps as $step ): ?>
								<option value="<?php echo $step['id']; ?>" <?php selected( $update_cutoff_step, $step['id'] ); ?>>
									<?php echo esc_html( $step['name'] ); ?> (Step #<?php echo $step['id']; ?> - <?php echo ucfirst( $step['type'] ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<p class="description">
						Drawing updates can be submitted <strong>until</strong> this step is completed.
						Once the entry passes this step, drawing updates are no longer allowed.
					</p>
				</div>

				<!-- Following Invoice Cutoff Step -->
				<div class="sfa-ur-field-row">
					<label for="sfa_ur_following_cutoff_step">Following Invoice Cutoff Step</label>
					<?php if ( empty( $workflow_steps ) ): ?>
						<p class="description" style="color: #dc3545;">
							No GravityFlow steps found. Please configure workflow steps first.
						</p>
					<?php else: ?>
						<select name="sfa_ur_following_cutoff_step" id="sfa_ur_following_cutoff_step">
							<option value="0">-- Always Allowed --</option>
							<?php foreach ( $workflow_steps as $step ): ?>
								<option value="<?php echo $step['id']; ?>" <?php selected( $following_cutoff_step, $step['id'] ); ?>>
									<?php echo esc_html( $step['name'] ); ?> (Step #<?php echo $step['id']; ?> - <?php echo ucfirst( $step['type'] ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<p class="description">
						Following invoices can be submitted <strong>until</strong> this step is completed.
						Once the entry passes this step, following invoices are no longer allowed.
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

		// Save approver
		$form['sfa_ur_approver'] = isset( $_POST['sfa_ur_approver'] ) ? absint( $_POST['sfa_ur_approver'] ) : 0;

		// Save cutoff steps (requests allowed UNTIL these steps are passed)
		$form['sfa_ur_update_cutoff_step'] = isset( $_POST['sfa_ur_update_cutoff_step'] ) ? absint( $_POST['sfa_ur_update_cutoff_step'] ) : 0;
		$form['sfa_ur_following_cutoff_step'] = isset( $_POST['sfa_ur_following_cutoff_step'] ) ? absint( $_POST['sfa_ur_following_cutoff_step'] ) : 0;

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
	 * Get approver user ID for form
	 *
	 * @param array|int $form Form array or form ID
	 * @return int
	 */
	public static function get_approver( $form ) {
		if ( is_numeric( $form ) ) {
			$form = \GFAPI::get_form( $form );
		}

		return (int) rgar( $form, 'sfa_ur_approver' );
	}

	/**
	 * Get update request cutoff step ID for form
	 *
	 * @param array|int $form Form array or form ID
	 * @return int
	 */
	public static function get_update_cutoff_step( $form ) {
		if ( is_numeric( $form ) ) {
			$form = \GFAPI::get_form( $form );
		}

		return (int) rgar( $form, 'sfa_ur_update_cutoff_step' );
	}

	/**
	 * Get following invoice cutoff step ID for form
	 *
	 * @param array|int $form Form array or form ID
	 * @return int
	 */
	public static function get_following_cutoff_step( $form ) {
		if ( is_numeric( $form ) ) {
			$form = \GFAPI::get_form( $form );
		}

		return (int) rgar( $form, 'sfa_ur_following_cutoff_step' );
	}

	/**
	 * Check if update requests are allowed at current step
	 *
	 * Logic: Allowed UNTIL the cutoff step is passed
	 * - If cutoff = 0, always allowed
	 * - If current step < cutoff step, allowed
	 * - If current step >= cutoff step, NOT allowed (step has been reached/passed)
	 *
	 * @param array|int $form Form array or form ID
	 * @param int       $current_step_id Current workflow step ID
	 * @param array     $entry Entry array (to check completed steps)
	 * @return bool
	 */
	public static function can_submit_update_request( $form, $current_step_id, $entry = null ) {
		$cutoff_step = self::get_update_cutoff_step( $form );

		// If no cutoff step configured, always allowed
		if ( $cutoff_step === 0 ) {
			return true;
		}

		// Check if cutoff step has been completed
		if ( $entry ) {
			$completed_steps = self::get_completed_step_ids( $entry );
			if ( in_array( $cutoff_step, $completed_steps, true ) ) {
				return false; // Cutoff step already passed
			}
		}

		// If current step is at or past cutoff, not allowed
		// Note: Step IDs are not always sequential, so we check if cutoff is in completed list
		return true;
	}

	/**
	 * Check if following invoices are allowed at current step
	 *
	 * Logic: Allowed UNTIL the cutoff step is passed
	 *
	 * @param array|int $form Form array or form ID
	 * @param int       $current_step_id Current workflow step ID
	 * @param array     $entry Entry array (to check completed steps)
	 * @return bool
	 */
	public static function can_submit_following_invoice( $form, $current_step_id, $entry = null ) {
		$cutoff_step = self::get_following_cutoff_step( $form );

		// If no cutoff step configured, always allowed
		if ( $cutoff_step === 0 ) {
			return true;
		}

		// Check if cutoff step has been completed
		if ( $entry ) {
			$completed_steps = self::get_completed_step_ids( $entry );
			if ( in_array( $cutoff_step, $completed_steps, true ) ) {
				return false; // Cutoff step already passed
			}
		}

		return true;
	}

	/**
	 * Get list of completed step IDs for an entry
	 *
	 * @param array $entry Entry array
	 * @return array Array of completed step IDs
	 */
	public static function get_completed_step_ids( $entry ) {
		$completed_steps = [];

		if ( ! class_exists( 'Gravity_Flow_API' ) ) {
			return $completed_steps;
		}

		$entry_id = $entry['id'];
		$form_id = $entry['form_id'];

		$api = new \Gravity_Flow_API( $form_id );
		$steps = $api->get_steps();

		foreach ( $steps as $step ) {
			$step_id = $step->get_id();
			$step_status = $step->get_status_key_for_entry( $entry_id );

			// Step is complete if status is 'complete' or any terminal status
			if ( in_array( $step_status, [ 'complete', 'approved', 'rejected' ], true ) ) {
				$completed_steps[] = $step_id;
			}
		}

		return $completed_steps;
	}

	/**
	 * Check if user is the entry creator
	 *
	 * @param array $entry Entry array
	 * @param int   $user_id User ID to check (defaults to current user)
	 * @return bool
	 */
	public static function is_entry_creator( $entry, $user_id = null ) {
		if ( $user_id === null ) {
			$user_id = get_current_user_id();
		}

		$creator_id = (int) rgar( $entry, 'created_by' );

		return $creator_id === $user_id;
	}
}
