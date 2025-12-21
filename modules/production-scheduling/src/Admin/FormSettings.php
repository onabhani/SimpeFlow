<?php
namespace SFA\ProductionScheduling\Admin;

/**
 * Gravity Forms Settings Integration
 *
 * Adds production scheduling settings to individual forms
 */
class FormSettings {

	public function __construct() {
		add_filter( 'gform_form_settings', [ $this, 'add_form_settings' ], 10, 2 );
		add_filter( 'gform_pre_form_settings_save', [ $this, 'save_form_settings' ] );
	}

	/**
	 * Add production scheduling settings to form settings page
	 */
	public function add_form_settings( $settings, $form ) {
		$form_id = $form['id'];

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

		$settings['Production Scheduling'] = '
			<tr>
				<th colspan="2" style="padding-top: 20px;">
					<h3 style="margin: 0;">Production Scheduling</h3>
				</th>
			</tr>
			<tr>
				<th><label for="sfa_prod_enabled">Enable Production Scheduling</label></th>
				<td>
					<input type="checkbox" name="sfa_prod_enabled" id="sfa_prod_enabled" value="1" ' . checked( $enabled, true, false ) . '>
					<span class="description">Enable automatic production scheduling for this form</span>
				</td>
			</tr>';

		if ( ! empty( $number_fields ) ) {
			$settings['Production Scheduling'] .= '
			<tr id="sfa_prod_lm_field_row" style="' . ( ! $enabled ? 'display:none;' : '' ) . '">
				<th><label for="sfa_prod_lm_field">Linear Meters Field</label></th>
				<td>
					<select name="sfa_prod_lm_field" id="sfa_prod_lm_field">
						<option value="">Select a field...</option>';

			foreach ( $number_fields as $field ) {
				$settings['Production Scheduling'] .= '<option value="' . $field['value'] . '" ' . selected( $lm_field_id, $field['value'], false ) . '>' . esc_html( $field['label'] ) . '</option>';
			}

			$settings['Production Scheduling'] .= '
					</select>
					<span class="description">Number field where sales enters LM required</span>
				</td>
			</tr>';
		} else {
			$settings['Production Scheduling'] .= '
			<tr id="sfa_prod_lm_field_row" style="' . ( ! $enabled ? 'display:none;' : '' ) . '">
				<td colspan="2" style="color: red;">
					⚠️ No number fields found in this form. Please add a number field for LM entry.
				</td>
			</tr>';
		}

		if ( ! empty( $date_fields ) ) {
			$settings['Production Scheduling'] .= '
			<tr id="sfa_prod_install_field_row" style="' . ( ! $enabled ? 'display:none;' : '' ) . '">
				<th><label for="sfa_prod_install_field">Installation Date Field</label></th>
				<td>
					<select name="sfa_prod_install_field" id="sfa_prod_install_field">
						<option value="">Select a field...</option>';

			foreach ( $date_fields as $field ) {
				$settings['Production Scheduling'] .= '<option value="' . $field['value'] . '" ' . selected( $install_field_id, $field['value'], false ) . '>' . esc_html( $field['label'] ) . '</option>';
			}

			$settings['Production Scheduling'] .= '
					</select>
					<span class="description">Date field for installation date (will be auto-filled with minimum date)</span>
				</td>
			</tr>';
		} else {
			$settings['Production Scheduling'] .= '
			<tr id="sfa_prod_install_field_row" style="' . ( ! $enabled ? 'display:none;' : '' ) . '">
				<td colspan="2" style="color: red;">
					⚠️ No date fields found in this form. Please add a date field for installation date.
				</td>
			</tr>';
		}

		$settings['Production Scheduling'] .= '
			<script>
			jQuery(document).ready(function($) {
				$("#sfa_prod_enabled").on("change", function() {
					if ($(this).is(":checked")) {
						$("#sfa_prod_lm_field_row, #sfa_prod_install_field_row").show();
					} else {
						$("#sfa_prod_lm_field_row, #sfa_prod_install_field_row").hide();
					}
				});
			});
			</script>';

		return $settings;
	}

	/**
	 * Save form settings
	 */
	public function save_form_settings( $form ) {
		$form['sfa_prod_enabled'] = (bool) rgpost( 'sfa_prod_enabled' );
		$form['sfa_prod_lm_field'] = absint( rgpost( 'sfa_prod_lm_field' ) );
		$form['sfa_prod_install_field'] = absint( rgpost( 'sfa_prod_install_field' ) );

		return $form;
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
