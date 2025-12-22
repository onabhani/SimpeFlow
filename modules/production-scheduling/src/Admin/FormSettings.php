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

		// Build field options
		$number_fields = [];
		$date_fields = [];

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
				</table>

				<?php submit_button( 'Save Production Scheduling Settings' ); ?>
			</form>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$("#sfa_prod_enabled").on("change", function() {
				if ($(this).is(":checked")) {
					$("#sfa_prod_lm_field_row, #sfa_prod_install_field_row").show();
				} else {
					$("#sfa_prod_lm_field_row, #sfa_prod_install_field_row").hide();
				}
			}).trigger("change");
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
}
