<?php
namespace SFA\ProductionScheduling\Admin;

/**
 * Gravity Forms Settings Integration
 *
 * Adds production scheduling settings to individual forms
 */
class FormSettings {

	const SLUG = 'sfa-production-scheduling';

	public function __construct() {
		add_filter( 'gform_form_settings_menu', [ $this, 'add_settings_tab' ], 10 );
		add_action( 'gform_form_settings_page_' . self::SLUG, [ $this, 'render_settings_page' ] );
		add_action( 'admin_post_sfa_prod_save_form_settings', [ $this, 'save_form_settings' ] );
	}

	/**
	 * Add Production Scheduling tab to form settings
	 */
	public function add_settings_tab( $tabs ) {
		// Check if tab already exists
		foreach ( (array) $tabs as $tab ) {
			if ( ( isset( $tab['name'] ) && $tab['name'] === self::SLUG ) ||
			     ( isset( $tab['label'] ) && $tab['label'] === 'Production Scheduling' ) ) {
				return $tabs;
			}
		}

		$tabs[] = [
			'name'  => self::SLUG,
			'label' => 'Production Scheduling',
			'icon'  => 'gform-icon--calendar',
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
		$enabled = (bool) rgar( $form, 'sfa_prod_enabled' );
		$lm_field_id = (int) rgar( $form, 'sfa_prod_lm_field' );
		$install_field_id = (int) rgar( $form, 'sfa_prod_install_field' );
		$prod_start_field_id = (int) rgar( $form, 'sfa_prod_start_field' );
		$prod_end_field_id = (int) rgar( $form, 'sfa_prod_end_field' );

		// Get production fields configuration (new multi-field system)
		$production_fields = rgar( $form, 'sfa_prod_fields' );
		if ( ! is_array( $production_fields ) ) {
			$production_fields = array();
		}

		// Get workflow step setting
		$booking_step_id = rgar( $form, 'sfa_prod_booking_step' );

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

		// Get skip booking field setting
		$skip_booking_field_id = (int) rgar( $form, 'sfa_prod_skip_booking_field' );

		// Build field options
		$number_fields = [];
		$date_fields = [];
		$hidden_fields = [];
		$checkbox_fields = [];

		foreach ( $form['fields'] as $field ) {
			if ( $field->type === 'number' ) {
				$number_fields[] = [
					'value' => $field->id,
					'label' => $field->label . ' (ID: ' . $field->id . ')',
				];
			}
			if ( $field->type === 'date' ) {
				$date_fields[] = [
					'value' => $field->id,
					'label' => $field->label . ' (ID: ' . $field->id . ')',
				];
			}
			if ( $field->type === 'hidden' || $field->type === 'date' ) {
				$hidden_fields[] = [
					'value' => $field->id,
					'label' => $field->label . ' (ID: ' . $field->id . ') - ' . ucfirst( $field->type ),
				];
			}
			if ( $field->type === 'checkbox' ) {
				$checkbox_fields[] = [
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
			.sfa-prod-settings-wrap {
				max-width: 800px;
				margin: 20px 0;
			}
			.sfa-prod-settings-wrap h3 {
				margin-top: 0;
			}
			.sfa-prod-field-row {
				margin: 20px 0;
				padding: 15px;
				background: #f9f9f9;
				border-left: 3px solid #0073aa;
			}
			.sfa-prod-field-row label {
				display: block;
				font-weight: bold;
				margin-bottom: 8px;
			}
			.sfa-prod-field-row select {
				width: 100%;
				max-width: 400px;
			}
			.sfa-prod-field-row .description {
				margin-top: 5px;
				color: #666;
				font-size: 13px;
			}
		</style>

		<div class="sfa-prod-settings-wrap">
			<h3>Production Scheduling Settings</h3>
			<p>Configure production scheduling for this form.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sfa_prod_save_' . $form_id, 'sfa_prod_nonce' ); ?>
				<input type="hidden" name="action" value="sfa_prod_save_form_settings">
				<input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="sfa_prod_enabled">Enable Production Scheduling</label>
						</th>
						<td>
							<input type="checkbox" name="sfa_prod_enabled" id="sfa_prod_enabled" value="1" <?php checked( $enabled, true ); ?>>
							<span class="description">Enable automatic production scheduling for this form</span>
						</td>
					</tr>

					<?php if ( ! empty( $number_fields ) ): ?>
					<tr id="sfa_prod_lm_field_row" style="<?php echo ! $enabled ? 'display:none;' : ''; ?>">
						<th scope="row">
							<label for="sfa_prod_lm_field">Linear Meters Field</label>
						</th>
						<td>
							<select name="sfa_prod_lm_field" id="sfa_prod_lm_field" class="widefat">
								<option value="">Select a field...</option>
								<?php foreach ( $number_fields as $field ): ?>
									<option value="<?php echo esc_attr( $field['value'] ); ?>" <?php selected( $lm_field_id, $field['value'] ); ?>>
										<?php echo esc_html( $field['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Number field where sales enters LM required</p>
						</td>
					</tr>
					<?php else: ?>
					<tr id="sfa_prod_lm_field_row" style="<?php echo ! $enabled ? 'display:none;' : ''; ?>">
						<td colspan="2">
							<div class="notice notice-error inline">
								<p>⚠️ No number fields found in this form. Please add a number field for LM entry.</p>
							</div>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( ! empty( $date_fields ) ): ?>
					<tr id="sfa_prod_install_field_row" style="<?php echo ! $enabled ? 'display:none;' : ''; ?>">
						<th scope="row">
							<label for="sfa_prod_install_field">Installation Date Field</label>
						</th>
						<td>
							<select name="sfa_prod_install_field" id="sfa_prod_install_field" class="widefat">
								<option value="">Select a field...</option>
								<?php foreach ( $date_fields as $field ): ?>
									<option value="<?php echo esc_attr( $field['value'] ); ?>" <?php selected( $install_field_id, $field['value'] ); ?>>
										<?php echo esc_html( $field['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Date field for installation date (will be auto-filled with minimum date)</p>
						</td>
					</tr>
					<?php else: ?>
					<tr id="sfa_prod_install_field_row" style="<?php echo ! $enabled ? 'display:none;' : ''; ?>">
						<td colspan="2">
							<div class="notice notice-error inline">
								<p>⚠️ No date fields found in this form. Please add a date field for installation date.</p>
							</div>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( ! empty( $checkbox_fields ) ): ?>
					<tr id="sfa_prod_skip_booking_field_row" style="<?php echo ! $enabled ? 'display:none;' : ''; ?>">
						<th scope="row">
							<label for="sfa_prod_skip_booking_field">Skip Production Booking Checkbox</label>
						</th>
						<td>
							<select name="sfa_prod_skip_booking_field" id="sfa_prod_skip_booking_field" class="widefat">
								<option value="">None (optional)</option>
								<?php foreach ( $checkbox_fields as $field ): ?>
									<option value="<?php echo esc_attr( $field['value'] ); ?>" <?php selected( $skip_booking_field_id, $field['value'] ); ?>>
										<?php echo esc_html( $field['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">When this checkbox is checked, the entry will have an installation date but will NOT consume any production capacity. Entry will still appear on calendar with 0 LM.</p>
						</td>
					</tr>
					<?php endif; ?>

					<tr>
						<td colspan="2">
							<hr style="margin: 20px 0;">
							<h4 style="margin: 10px 0;">Production Fields Configuration</h4>
							<p class="description">Configure which fields contribute to production slot calculations. Each field type has different conversion rules.</p>
						</td>
					</tr>

					<tr id="sfa_prod_fields_row" style="<?php echo ! $enabled ? 'display:none;' : ''; ?>">
						<td colspan="2">
							<div id="sfa-prod-fields-container">
								<?php
								$field_types = self::get_field_types();

								// If no production fields configured yet, show one default row
								if ( empty( $production_fields ) ) {
									$production_fields = array(
										array(
											'field_id' => $lm_field_id, // Default to LM field
											'field_type' => 'lm',
										),
									);
								}

								foreach ( $production_fields as $index => $prod_field_config ):
									$field_id = isset( $prod_field_config['field_id'] ) ? $prod_field_config['field_id'] : 0;
									$field_type = isset( $prod_field_config['field_type'] ) ? $prod_field_config['field_type'] : 'lm';
								?>
								<div class="sfa-prod-field-config" style="margin: 15px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #0073aa;">
									<div style="display: flex; gap: 20px; align-items: flex-start;">
										<div style="flex: 1;">
											<label style="display: block; font-weight: bold; margin-bottom: 5px;">Form Field</label>
											<select name="sfa_prod_fields[<?php echo $index; ?>][field_id]" class="widefat sfa-prod-field-select" style="max-width: 300px;">
												<option value="">Select a field...</option>
												<?php foreach ( $number_fields as $field ): ?>
													<option value="<?php echo esc_attr( $field['value'] ); ?>" <?php selected( $field_id, $field['value'] ); ?>>
														<?php echo esc_html( $field['label'] ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>

										<div style="flex: 1;">
											<label style="display: block; font-weight: bold; margin-bottom: 5px;">Field Type</label>
											<select name="sfa_prod_fields[<?php echo $index; ?>][field_type]" class="widefat sfa-prod-type-select" style="max-width: 300px;">
												<?php foreach ( $field_types as $type_key => $type_info ): ?>
													<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $field_type, $type_key ); ?> data-description="<?php echo esc_attr( $type_info['description'] ); ?>">
														<?php echo esc_html( $type_info['label'] ); ?>
													</option>
												<?php endforeach; ?>
											</select>
											<p class="description sfa-prod-field-desc" style="margin-top: 5px;">
												<?php echo esc_html( $field_types[ $field_type ]['description'] ); ?>
											</p>
										</div>

										<div style="padding-top: 25px;">
											<button type="button" class="button sfa-prod-remove-field" style="color: #dc3232;">Remove</button>
										</div>
									</div>
								</div>
								<?php endforeach; ?>
							</div>

							<button type="button" id="sfa-prod-add-field" class="button" style="margin-top: 10px;">+ Add Production Field</button>

							<div style="margin-top: 20px; padding: 15px; background: #e7f5fe; border-left: 3px solid #00a0d2;">
								<strong>Field Type Conversion Rules:</strong>
								<ul style="margin: 10px 0; padding-left: 20px;">
									<?php foreach ( $field_types as $type_info ): ?>
										<li><strong><?php echo esc_html( $type_info['label'] ); ?>:</strong> <?php echo esc_html( $type_info['description'] ); ?></li>
									<?php endforeach; ?>
								</ul>
							</div>
						</td>
					</tr>

					<tr>
						<td colspan="2">
							<hr style="margin: 20px 0;">
							<h4 style="margin: 10px 0;">Optional: Production Date Fields</h4>
							<p class="description">Map hidden or date fields to store production start and end dates. These dates will be auto-populated and can be used in GravityView, notifications, and entry filtering.</p>
						</td>
					</tr>

					<?php if ( ! empty( $hidden_fields ) ): ?>
					<tr id="sfa_prod_start_field_row" style="<?php echo ! $enabled ? 'display:none;' : ''; ?>">
						<th scope="row">
							<label for="sfa_prod_start_field">Production Start Date Field</label>
						</th>
						<td>
							<select name="sfa_prod_start_field" id="sfa_prod_start_field" class="widefat">
								<option value="">None (optional)</option>
								<?php foreach ( $hidden_fields as $field ): ?>
									<option value="<?php echo esc_attr( $field['value'] ); ?>" <?php selected( $prod_start_field_id, $field['value'] ); ?>>
										<?php echo esc_html( $field['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Field to store production start date (will be auto-filled)</p>
						</td>
					</tr>

					<tr id="sfa_prod_end_field_row" style="<?php echo ! $enabled ? 'display:none;' : ''; ?>">
						<th scope="row">
							<label for="sfa_prod_end_field">Production End Date Field</label>
						</th>
						<td>
							<select name="sfa_prod_end_field" id="sfa_prod_end_field" class="widefat">
								<option value="">None (optional)</option>
								<?php foreach ( $hidden_fields as $field ): ?>
									<option value="<?php echo esc_attr( $field['value'] ); ?>" <?php selected( $prod_end_field_id, $field['value'] ); ?>>
										<?php echo esc_html( $field['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Field to store production end date (will be auto-filled)</p>
						</td>
					</tr>
					<?php endif; ?>

					<?php if ( ! empty( $workflow_steps ) ): ?>
					<tr>
						<td colspan="2">
							<hr style="margin: 20px 0;">
							<h4 style="margin: 10px 0;">Booking Trigger</h4>
							<p class="description">Choose when production bookings should be created.</p>
						</td>
					</tr>

					<tr id="sfa_prod_booking_step_row" style="<?php echo ! $enabled ? 'display:none;' : ''; ?>">
						<th scope="row">
							<label for="sfa_prod_booking_step">Create Booking After Step</label>
						</th>
						<td>
							<select name="sfa_prod_booking_step" id="sfa_prod_booking_step" class="widefat">
								<option value="">On Form Submission (Default)</option>
								<?php foreach ( $workflow_steps as $step ): ?>
									<option value="<?php echo esc_attr( $step['id'] ); ?>" <?php selected( $booking_step_id, $step['id'] ); ?>>
										<?php echo esc_html( $step['name'] . ' (' . ucfirst( str_replace( '_', ' ', $step['type'] ) ) . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								Select which workflow step should trigger the production booking.
								Leave as "On Form Submission" to create bookings immediately when the form is submitted.
								Choose a specific step (e.g., "Billing and Payment") to defer booking creation until that step is completed.
							</p>
						</td>
					</tr>
					<?php endif; ?>
				</table>

				<?php submit_button( 'Save Production Scheduling Settings' ); ?>
			</form>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var fieldIndex = <?php echo count( $production_fields ); ?>;

			// Toggle visibility of all production scheduling rows
			$("#sfa_prod_enabled").on("change", function() {
				if ($(this).is(":checked")) {
					$("#sfa_prod_lm_field_row, #sfa_prod_install_field_row, #sfa_prod_skip_booking_field_row, #sfa_prod_fields_row, #sfa_prod_start_field_row, #sfa_prod_end_field_row, #sfa_prod_booking_step_row").show();
				} else {
					$("#sfa_prod_lm_field_row, #sfa_prod_install_field_row, #sfa_prod_skip_booking_field_row, #sfa_prod_fields_row, #sfa_prod_start_field_row, #sfa_prod_end_field_row, #sfa_prod_booking_step_row").hide();
				}
			}).trigger("change");

			// Add new production field
			$("#sfa-prod-add-field").on("click", function() {
				var template = `
					<div class="sfa-prod-field-config" style="margin: 15px 0; padding: 15px; background: #f9f9f9; border-left: 3px solid #0073aa;">
						<div style="display: flex; gap: 20px; align-items: flex-start;">
							<div style="flex: 1;">
								<label style="display: block; font-weight: bold; margin-bottom: 5px;">Form Field</label>
								<select name="sfa_prod_fields[${fieldIndex}][field_id]" class="widefat sfa-prod-field-select" style="max-width: 300px;">
									<option value="">Select a field...</option>
									<?php foreach ( $number_fields as $field ): ?>
										<option value="<?php echo esc_attr( $field['value'] ); ?>">
											<?php echo esc_html( $field['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div style="flex: 1;">
								<label style="display: block; font-weight: bold; margin-bottom: 5px;">Field Type</label>
								<select name="sfa_prod_fields[${fieldIndex}][field_type]" class="widefat sfa-prod-type-select" style="max-width: 300px;">
									<?php foreach ( $field_types as $type_key => $type_info ): ?>
										<option value="<?php echo esc_attr( $type_key ); ?>" data-description="<?php echo esc_attr( $type_info['description'] ); ?>">
											<?php echo esc_html( $type_info['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description sfa-prod-field-desc" style="margin-top: 5px;">
									<?php echo esc_html( $field_types['lm']['description'] ); ?>
								</p>
							</div>
							<div style="padding-top: 25px;">
								<button type="button" class="button sfa-prod-remove-field" style="color: #dc3232;">Remove</button>
							</div>
						</div>
					</div>
				`;
				$("#sfa-prod-fields-container").append(template);
				fieldIndex++;
			});

			// Remove production field
			$(document).on("click", ".sfa-prod-remove-field", function() {
				// Prevent removing the last field
				if ($(".sfa-prod-field-config").length <= 1) {
					alert("You must have at least one production field configured.");
					return;
				}
				$(this).closest(".sfa-prod-field-config").remove();
			});

			// Update description when field type changes
			$(document).on("change", ".sfa-prod-type-select", function() {
				var description = $(this).find("option:selected").data("description");
				$(this).siblings(".sfa-prod-field-desc").text(description);
			});
		});
		</script>
		<?php
	}

	/**
	 * Save form settings
	 */
	public function save_form_settings() {
		// Get form ID
		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		// Verify nonce
		if ( ! wp_verify_nonce( $_POST['sfa_prod_nonce'], 'sfa_prod_save_' . $form_id ) ) {
			wp_die( 'Security check failed.' );
		}

		// Get form
		$form = \GFAPI::get_form( $form_id );

		if ( ! $form ) {
			wp_die( 'Form not found.' );
		}

		// Update form meta
		$form['sfa_prod_enabled'] = isset( $_POST['sfa_prod_enabled'] ) ? true : false;
		$form['sfa_prod_lm_field'] = isset( $_POST['sfa_prod_lm_field'] ) ? absint( $_POST['sfa_prod_lm_field'] ) : 0;
		$form['sfa_prod_install_field'] = isset( $_POST['sfa_prod_install_field'] ) ? absint( $_POST['sfa_prod_install_field'] ) : 0;
		$form['sfa_prod_skip_booking_field'] = isset( $_POST['sfa_prod_skip_booking_field'] ) ? absint( $_POST['sfa_prod_skip_booking_field'] ) : 0;
		$form['sfa_prod_start_field'] = isset( $_POST['sfa_prod_start_field'] ) ? absint( $_POST['sfa_prod_start_field'] ) : 0;
		$form['sfa_prod_end_field'] = isset( $_POST['sfa_prod_end_field'] ) ? absint( $_POST['sfa_prod_end_field'] ) : 0;
		$form['sfa_prod_booking_step'] = isset( $_POST['sfa_prod_booking_step'] ) ? absint( $_POST['sfa_prod_booking_step'] ) : 0;

		// Save production fields configuration
		$production_fields = array();
		if ( isset( $_POST['sfa_prod_fields'] ) && is_array( $_POST['sfa_prod_fields'] ) ) {
			foreach ( $_POST['sfa_prod_fields'] as $field_config ) {
				$field_id = isset( $field_config['field_id'] ) ? absint( $field_config['field_id'] ) : 0;
				$field_type = isset( $field_config['field_type'] ) ? sanitize_key( $field_config['field_type'] ) : '';

				// Only save if both field_id and field_type are provided
				if ( $field_id > 0 && ! empty( $field_type ) ) {
					$production_fields[] = array(
						'field_id' => $field_id,
						'field_type' => $field_type,
					);
				}
			}
		}
		$form['sfa_prod_fields'] = $production_fields;

		// Save form
		\GFAPI::update_form( $form );

		// Redirect back with success message
		$redirect_url = add_query_arg( [
			'page'    => 'gf_edit_forms',
			'view'    => 'settings',
			'subview' => self::SLUG,
			'id'      => $form_id,
			'updated' => '1',
		], admin_url( 'admin.php' ) );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Check if production scheduling is enabled for a form
	 */
	public static function is_enabled( $form ) {
		return (bool) rgar( $form, 'sfa_prod_enabled' );
	}

	/**
	 * Get LM field ID for a form
	 */
	public static function get_lm_field_id( $form ) {
		return (int) rgar( $form, 'sfa_prod_lm_field' );
	}

	/**
	 * Get installation date field ID for a form
	 */
	public static function get_install_field_id( $form ) {
		return (int) rgar( $form, 'sfa_prod_install_field' );
	}

	/**
	 * Get the date format configured on the installation date field.
	 *
	 * @param array $form The Gravity Forms form array.
	 * @return string 'dmy', 'mdy', 'dmy_dash', 'dmy_dot', 'ymd_slash', 'ymd_dash', 'ymd_dot', or '' if unknown.
	 */
	public static function get_install_field_date_format( $form ) {
		$field_id = self::get_install_field_id( $form );
		if ( ! $field_id || empty( $form['fields'] ) ) {
			return '';
		}
		foreach ( $form['fields'] as $field ) {
			if ( (int) $field->id === $field_id && 'date' === $field->type ) {
				// GF stores format as 'mdy' (MM/DD/YYYY), 'dmy' (DD/MM/YYYY),
				// 'dmy_dash', 'dmy_dot', 'ymd_slash', 'ymd_dash', 'ymd_dot'
				return isset( $field->dateFormat ) ? $field->dateFormat : '';
			}
		}
		return '';
	}

	/**
	 * Get production start date field ID for a form
	 */
	public static function get_prod_start_field_id( $form ) {
		return (int) rgar( $form, 'sfa_prod_start_field' );
	}

	/**
	 * Get production end date field ID for a form
	 */
	public static function get_prod_end_field_id( $form ) {
		return (int) rgar( $form, 'sfa_prod_end_field' );
	}

	/**
	 * Get production fields configuration
	 * Returns array of field configurations with their types and rules
	 */
	public static function get_production_fields( $form ) {
		$fields = rgar( $form, 'sfa_prod_fields' );
		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Get booking trigger step ID
	 * Returns 0 if booking should happen on form submission
	 */
	public static function get_booking_step_id( $form ) {
		return (int) rgar( $form, 'sfa_prod_booking_step' );
	}

	/**
	 * Get skip production booking checkbox field ID
	 * When this checkbox is checked, entry gets installation date but no production allocation
	 */
	public static function get_skip_booking_field_id( $form ) {
		return (int) rgar( $form, 'sfa_prod_skip_booking_field' );
	}

	/**
	 * Get available field types and their conversion rules
	 */
	public static function get_field_types() {
		return array(
			'lm' => array(
				'label' => 'Linear Meter (LM)',
				'description' => '1 LM = 1 slot. Decimals create additional slots.',
				'calculate' => function( $value ) {
					// 1 LM = 1 slot, any decimal rounds up
					return ceil( $value );
				},
			),
			'vanity' => array(
				'label' => 'Vanity Shelf',
				'description' => '0-0.5 LM = 0 slots, then 1 slot for each 2 LM (e.g., 0.51-2 = 1 slot, 2.01-4 = 2 slots)',
				'calculate' => function( $value ) {
					// 0-0.5 LM doesn't count as a slot
					if ( $value <= 0.5 ) {
						return 0;
					}
					// 1 slot for each 2 LM, round up
					return ceil( $value / 2 );
				},
			),
			'sqm' => array(
				'label' => 'SQM (Background)',
				'description' => 'Every 3 SQM = 1 slot (e.g., 6 SQM = 2 slots)',
				'calculate' => function( $value ) {
					// Ceiling of value / 3
					return ceil( $value / 3 );
				},
			),
		);
	}

	/**
	 * Calculate total slots from all production fields
	 *
	 * @param array $field_values Array of field_id => value
	 * @param array $field_configs Array of field configurations
	 * @return int Total slots required
	 */
	public static function calculate_total_slots( $field_values, $field_configs ) {
		$total_slots = 0;
		$field_types = self::get_field_types();

		foreach ( $field_configs as $config ) {
			$field_id = $config['field_id'];
			$field_type = $config['field_type'];

			if ( ! isset( $field_values[ $field_id ] ) || ! isset( $field_types[ $field_type ] ) ) {
				continue;
			}

			$value = floatval( $field_values[ $field_id ] );
			if ( $value <= 0 ) {
				continue;
			}

			$calculate_fn = $field_types[ $field_type ]['calculate'];
			$slots = $calculate_fn( $value );
			$total_slots += $slots;
		}

		return max( 1, $total_slots ); // Minimum 1 slot
	}
}
