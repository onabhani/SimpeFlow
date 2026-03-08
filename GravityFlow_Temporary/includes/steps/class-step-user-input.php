<?php
/**
 * Gravity Flow Step User Input
 *
 * @package     GravityFlow
 * @subpackage  Classes/Gravity_Flow_Step_User_Input
 * @copyright   Copyright (c) 2015-2018, Steven Henty S.L.
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.0
 */

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

/**
 * Class Gravity_Flow_Step_User_Input
 */
class Gravity_Flow_Step_User_Input extends Gravity_Flow_Step {

	use Editable_Fields;
    use Rest_API_Support;
	/**
	 * The step type.
	 *
	 * @var string
	 */
	public $_step_type = 'user_input';

	/**
	 * Returns the step label.
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'User Input', 'gravityflow' );
	}

	/**
	 * Indicates this step supports due date.
	 *
	 * @since 2.5
	 *
	 * @return bool
	 */
	public function supports_due_date() {
		return true;
	}

	/**
	 * Indicates this step can expire without user input.
	 *
	 * @return bool
	 */
	public function supports_expiration() {
		return true;
	}

	/**
	 * Returns the HTML for the step icon.
	 *
	 * @return string
	 */
	public function get_icon_url() {
		return '<i class="fa fa-pencil" ></i>';
	}

	/**
	 * Returns an array of settings for this step type.
	 *
	 * @return array
	 */
	public function get_settings() {
		$form         = $this->get_form();
		$settings_api = $this->get_common_settings_api();

		$settings = array(
			'title'  => esc_html__( 'User Input', 'gravityflow' ),
			'fields' => array(
				$settings_api->get_setting_assignee_type(),
				$settings_api->get_setting_assignees(),
				$settings_api->get_setting_assignee_routing(),
				array(
					'id'            => 'assignee_policy',
					'name'          => 'assignee_policy',
					'label'         => __( 'Assignee Policy', 'gravityflow' ),
					'tooltip'       => __( 'Define how this step should be processed. If all assignees must complete this step then the entry will require input from every assignee before the step can be completed. If the step is assigned to a role only one user in that role needs to complete the step.', 'gravityflow' ),
					'type'          => 'radio',
					'default_value' => 'all',
					'choices'       => array(
						array(
							'label' => __( 'Only one assignee is required to complete the step', 'gravityflow' ),
							'value' => 'any',
						),
						array(
							'label' => __( 'All assignees must complete this step', 'gravityflow' ),
							'value' => 'all',
						),
					),
				),
			),
		);

		if ( $this->fields_have_conditional_logic( $this->get_form() ) ) {
			$settings['fields'][] = $this->get_fields_conditional_logic_settings();
		}

		$notification_tabs = array(
			array(
				'label'  => __( 'Assignee Email', 'gravityflow' ),
				'id'     => 'tab_assignee_notification',
				'fields' => $settings_api->get_setting_notification( array(
					'checkbox_default_value' => true,
					'default_message'        => __( 'A new entry requires your input.', 'gravityflow' ),
				) ),
			),
			array(
				'label'  => __( 'In Progress Email', 'gravityflow' ),
				'id'     => 'tab_in_progress_notification',
				'fields' => $settings_api->get_setting_notification( array(
					'name_prefix'      => 'in_progress',
					'checkbox_label'   => __( 'Send email when the step is in progress.', 'gravityflow' ),
					'checkbox_tooltip' => __( 'Enable this setting to send an email when the entry is updated but the step is not completed.', 'gravityflow' ),
					'default_message'  => __( 'Entry {entry_id} has been updated and remains in progress.', 'gravityflow' ),
					'send_to_fields'   => true,
					'resend_field'     => false,
				) ),
			),
			array(
				'label'  => __( 'Complete Email', 'gravityflow' ),
				'id'     => 'tab_complete_notification',
				'fields' => $settings_api->get_setting_notification( array(
					'name_prefix'      => 'complete',
					'checkbox_label'   => __( 'Send email when the step is complete.', 'gravityflow' ),
					'checkbox_tooltip' => __( 'Enable this setting to send an email when the entry is updated completing the step.', 'gravityflow' ),
					'default_message'  => __( 'Entry {entry_id} has been updated completing the step.', 'gravityflow' ),
					'send_to_fields'   => true,
					'resend_field'     => false,
				) ),
			),
		);

		$settings2 = array(
			array(
				'name'     => 'highlight_editable_fields',
				'label'    => esc_html__( 'Highlight Editable Fields', 'gravityflow' ),
				'type'     => 'checkbox_and_select',
				'checkbox' => array(
					'label'          => esc_html__( 'Enable', 'gravityflow' ),
					'name'           => 'highlight_editable_fields_enabled',
					'default_value' => '0',
				),
				'select'   => array(
					'name'    => 'highlight_editable_fields_class',
					'choices' => array(
						array(
							'value' => 'green-triangle',
							'label' => esc_html__( 'Green triangle', 'gravityflow' ),
						),
						array(
							'value' => 'green-background',
							'label' => esc_html__( 'Green Background', 'gravityflow' ),
						),
					),
				),
			),
			$settings_api->get_setting_instructions(),
			$settings_api->get_setting_display_fields(),
			array(
				'name'          => 'default_status',
				'type'          => 'select',
				'label'         => __( 'Save Progress', 'gravityflow' ),
				'tooltip'       => __( 'This setting allows the assignee to save the field values without submitting the form as complete. Select Disabled to hide the "in progress" option or select the default value for the radio buttons.', 'gravityflow' ),
				'default_value' => 'hidden',
				'choices'       => array(
					array( 'label' => __( 'Disabled', 'gravityflow' ), 'value' => 'hidden' ),
					array( 'label' => __( 'Radio buttons (default: In progress)', 'gravityflow' ), 'value' => 'in_progress' ),
					array( 'label' => __( 'Radio buttons (default: Complete)', 'gravityflow' ), 'value' => 'complete' ),
					array( 'label' => __( 'Submit buttons (Save and Submit)', 'gravityflow' ), 'value' => 'submit_buttons' ),
				),
			),
			array(
				'name'          => 'note_mode',
				'label'         => esc_html__( 'Workflow Note', 'gravityflow' ),
				'type'          => 'select',
				'tooltip'       => esc_html__( 'The text entered in the Note box will be added to the timeline. Use this setting to select the options for the Note box.', 'gravityflow' ),
				'default_value' => 'not_required',
				'choices'       => array(
					array( 'value' => 'hidden', 'label' => esc_html__( 'Hidden', 'gravityflow' ) ),
					array( 'value' => 'not_required', 'label' => esc_html__( 'Not required', 'gravityflow' ) ),
					array( 'value' => 'required', 'label' => esc_html__( 'Always Required', 'gravityflow' ) ),
					array(
						'value' => 'required_if_in_progress',
						'label' => esc_html__( 'Required if in progress', 'gravityflow' ),
					),
					array(
						'value' => 'required_if_complete',
						'label' => esc_html__( 'Required if complete', 'gravityflow' ),
					),
				),
			),
			$settings_api->get_setting_notification_tabs( $notification_tabs ),
			$settings_api->get_setting_confirmation_messasge( esc_html__( 'Thank you.', 'gravityflow' ) ),
		);

		$settings['fields'] = array_merge( $settings['fields'], $settings2 );

		return $settings;
	}

	/**
	 * Set the assignees for this step.
	 *
	 * @return bool
	 */
	public function process() {
		return $this->assign();
	}

	/**
	 * Determines the current status of the step.
	 *
	 * @return string
	 */
	public function status_evaluation() {
		$assignee_details = $this->get_assignees();
		$step_status      = 'complete';

		foreach ( $assignee_details as $assignee ) {
			$user_status = $assignee->get_status();

			if ( $this->assignee_policy == 'any' ) {
				if ( $user_status == 'complete' ) {
					$step_status = 'complete';
					break;
				} else {
					$step_status = 'pending';
				}
			} else if ( empty( $user_status ) || $user_status == 'pending' ) {
				$step_status = 'pending';
			}
		}

		return $step_status;
	}

	/**
	 * Process the REST request for an entry.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return mixed | WP_Error The result of the status update or a WP_Error instance if the status update failed.
	 */
	public function rest_process_step( $request ) {

		$new_status = rgar( $request, 'status' );

        // Validate the new status.
        if ( ! in_array( $new_status, array( 'in_progress', 'complete' ) ) ) {
	        return new WP_Error( 'invalid_status', esc_html__( 'The specified status is not valid. Only in_progress and complete are supported by User Input steps', 'gravityflow' ), array( 'status' => 422 ) );
		}

		// Validating that the revert option is enabled if the status is 'revert'.
        $can_save_progress = $this->default_status && $this->default_status !== 'hidden';
		if ( $new_status === 'in_progress' && ! $can_save_progress ) {
			return new WP_Error( 'in_progress_disabled', esc_html__( 'The Save Progress setting is not enabled for this step.', 'gravityflow' ), array( 'status' => 422 ) );
		}

		// Get assignee based on the request.
		$assignee = $this->rest_get_assignee( $request, $form, $entry );
		if ( is_wp_error( $assignee ) ) {
			return $assignee;
		}

		// Hydrate $_POST so that entry validation and save procedure works as expected.
		$this->rest_hydrate_post( $form['id'], $request );

		// Validate the status update and entry data. Then update the entry if validation passes.
		$result = $this->pre_process_status_update( $form, $entry, $new_status );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Processing status update and return result.
		$result = $this->update_status( $form, $entry, $new_status, $assignee );

		return $this->rest_format_result( $result );
	}

	/**
	 * Determines if all the editable fields are empty.
	 *
	 * @param array $entry           The current entry.
	 * @param array $editable_fields An array of field IDs which the user can edit.
	 *
	 * @return bool
	 */
	public function fields_empty( $entry, $editable_fields ) {

		foreach ( $editable_fields as $editable_field ) {
			if ( isset( $entry[ $editable_field ] ) && ! empty( $entry[ $editable_field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Handles POSTed values from the workflow detail page.
	 *
	 * @param array $form  The current form.
	 * @param array $entry The current entry.
	 *
	 * @return string|bool|WP_Error Return a success feedback message safe for page output or a WP_Error instance with an error.
	 */
	public function maybe_process_status_update( $form, $entry ) {
		if ( ! isset( $_POST['gforms_save_entry'] ) || rgpost( 'step_id' ) != $this->get_id() || ! check_admin_referer( 'gforms_save_entry', 'gforms_save_entry' ) ) {
			return false;
		}

		return $this->update_status( $form, $entry, rgpost( 'gravityflow_status' ), $this->get_current_assignee() );
	}


    /**
     * Updates the user input step status.
     *
     * @since 3.0.0
     *
     * @param array  $form       The current form.
     * @param array  $entry      The current entry.
     * @param string $new_status The new status.
     *
     * @return string|bool|WP_Error Return a success feedback message safe for page output or a WP_Error instance with an error.
     */
    private function update_status( $form, $entry, $new_status, $assignee ) {

        // Invalid status. Bail out.
        if ( ! in_array( $new_status, array( 'in_progress', 'complete' ) ) ) {
		    return false;
	    }

	    // Processing assignee status.
        $feedback = $this->process_assignee_status( $assignee, $new_status, $form );
        if ( is_wp_error( $feedback ) ) {
            return $feedback;
        }

	    // Remove and/or add assignees to the list.
	    $this->maybe_adjust_assignment( $this->get_assignees() );

        // Sending Gravity Flow notifications if configured.
	    $this->maybe_send_notification( $new_status );

        // Sending Gravity Forms 'workflow_user_input' notifications if configured.
	    GFAPI::send_notifications( $form, $this->get_entry( true ), 'workflow_user_input' );

	    return $feedback;
    }

	/**
	 * Validates and performs the assignees status update.
	 *
	 * @param Gravity_Flow_Assignee $assignee The assignee properties.
	 * @param string                $new_status The new status.
	 * @param array                 $form The current form.
	 *
	 * @return string|bool If processed return a message to be displayed to the user.
	 */
	public function process_assignee_status( $assignee, $new_status, $form ) {

		if ( $new_status == 'complete' ) {
			$success = $assignee->process_status( $new_status );
			if ( is_wp_error( $success ) ) {
				return $success;
			}
			$note_message = __( 'Entry updated and marked complete.', 'gravityflow' );
			if ( $this->confirmation_messageEnable && ! empty( $this->confirmation_messageValue ) ) {
				$feedback = $this->confirmation_messageValue;
				$feedback = $assignee->replace_variables( $feedback );
				$feedback = do_shortcode( $feedback );
				$feedback = wp_kses_post( $feedback );
			} else {
				$feedback = $note_message;
			}
		} else {
			$feedback     = esc_html__( 'Entry updated - in progress.', 'gravityflow' );
			$note_message = $feedback;
		}

		/**
		 * Allow the feedback message to be modified on the user input step.
		 *
		 * @param string                $feedback   The message to be displayed to the assignee when the detail page is redisplayed.
		 * @param string                $new_status The new status.
		 * @param Gravity_Flow_Assignee $assignee   The assignee properties.
		 * @param array                 $form       The current form.
		 * @param Gravity_Flow_Step     $this       The current step.
		 */
		$feedback = apply_filters( 'gravityflow_feedback_message_user_input', $feedback, $new_status, $assignee, $form, $this );

		$note = sprintf( '%s: %s', $this->get_name(), $note_message );
		$this->add_note( $note . $this->maybe_add_user_note(), true );

		$status = $this->evaluate_status();
		$this->update_step_status( $status );

		return $feedback;
	}

	/**
	 * Determine if this step is valid.
	 *
	 * @param string $new_status The new status for the current step.
	 * @param array  $form       The form currently being processed.
	 *
	 * @return bool
	 */
	public function validate_status_update( $new_status, $form ) {
		$valid = $this->validate_note( $new_status, $form );

		return $this->get_validation_result( $valid, $form, $new_status );
	}

	/**
	 * Determine if the note is valid.
	 *
	 * @param string $new_status The new status for the current step.
	 * @param string $note       The submitted note.
	 *
	 * @return bool
	 */
	public function validate_note_mode( $new_status, $note ) {
		$note = trim( $note );

		$valid = true;

		switch ( $this->note_mode ) {
			case 'required' :
				if ( empty( $note ) ) {
					$valid = false;
				}
				break;

			case 'required_if_in_progress' :
				if ( $new_status == 'in_progress' && empty( $note ) ) {
					$valid = false;
				};
				break;

			case 'required_if_complete' :
				if ( $new_status == 'complete' && empty( $note ) ) {
					$valid = false;
				};
		}

		/**
		 * Allows modification of note validity.
		 *
		 * @param bool              $valid         Indicates if the note is valid.
		 * @param string            $note          The submitted note.
		 * @param string            $new_status    The new status for the current step.
		 * @param Gravity_Flow_Step $this          The current workflow step.
		 */
		$valid = apply_filters( 'gravityflow_note_valid', $valid, $note, $new_status, $this );

		return $valid;
	}

	/**
	 * Set the value of a field property and optionally stash the current value to be restored later in
	 * $this->restore_field_property().
	 *
	 * Sets the value of sub-fields recursively.
	 *
	 * @since 2.4
	 *
	 * @param GF_Field $field
	 * @param string   $property
	 * @param mixed    $new_value
	 * @param bool     $stash_previous_value
	 */
	public function set_field_property( $field, $property, $new_value, $stash_previous_value = true ) {
		$value = $field->{$property};
		if ( $stash_previous_value ) {
			$field->set_context_property( 'gravityflow_stash_' . $property, $value );
		}
		$field->{$property} = $new_value;
		if ( ! empty( $field->fields ) && is_array( $field->fields ) ) {
			foreach ( $field->fields as $sub_field ) {
				$this->set_field_property( $sub_field, $property, $new_value, $stash_previous_value );
			}
		}
	}

	/**
	 * Set the value of a field property and optionally stash the current value to be restored later in
	 * $this->restore_field_property().
	 *
	 * Sets the value of sub-fields recursively.
	 *
	 * @since 2.4
	 *
	 * @param GF_Field $field
	 * @param string   $property
	 */
	public function restore_field_property( $field, $property ) {
		$value = $field->get_context_property( 'gravityflow_stash_' . $property );

		$field->{$property} = $value;
		if ( ! empty( $field->fields ) && is_array( $field->fields ) ) {
			foreach ( $field->fields as $sub_field ) {
				$this->restore_field_property( $sub_field, $property );
			}
		}
	}

	/**
	 * Allow the validation result to be overridden using the gravityflow_validation_user_input filter.
	 *
	 * @param array  $validation_result The validation result and form currently being processed.
	 * @param string $new_status        The new status for the current step.
	 *
	 * @return array
	 */
	public function maybe_filter_validation_result( $validation_result, $new_status ) {

		return apply_filters( 'gravityflow_validation_user_input', $validation_result, $this, $new_status );

	}

	/**
	 * Display the workflow detail box for this step.
	 *
	 * @param array $form The current form.
	 * @param array $args The page arguments.
	 */
	public function workflow_detail_box( $form, $args ) {
		?>
		<div>
			<?php

			$this->maybe_display_assignee_status_list( $args, $form );

			$assignees = $this->get_assignees();

			$can_update = false;
			foreach ( $assignees as $assignee ) {
				if ( $assignee->is_current_user() ) {
					$can_update = true;
					break;
				}
			}

			$this->maybe_enable_update_button( $can_update );

			/**
			 * Allows content to be added in the workflow box below the status list.
			 *
			 * @param Gravity_Flow_Step $this The current step.
			 * @param array             $form The current form.
			 */
			do_action( 'gravityflow_below_status_list_user_input', $this, $form );

			if ( $can_update ) {
				$this->maybe_display_note_box( $form );
				$this->display_status_inputs();
				$this->display_update_button( $form );
			}

			?>
		</div>
		<?php
	}

	/**
	 * Get the status string, including icon (if complete).
	 *
	 * @return string
	 */
	public function get_status_string() {
		$input_step_status = $this->get_status();
		$status_str        = __( 'Pending Input', 'gravityflow' );

		if ( $input_step_status == 'complete' ) {
			$approve_icon = '<i class="fa fa-check" style="color:green"></i>';
			$status_str   = $approve_icon . __( 'Complete', 'gravityflow' );
		} elseif ( $input_step_status == 'queued' ) {
			$status_str = __( 'Queued', 'gravityflow' );
		}

		return $status_str;
	}

	/**
	 * If applicable display the assignee status list.
	 *
	 * @param array $args The page arguments.
	 * @param array $form The current form.
	 */
	public function maybe_display_assignee_status_list( $args, $form ) {
		$display_step_status = (bool) $args['step_status'];

		/**
		 * Allows the assignee status list to be hidden.
		 *
		 * @param array             $form         The current form.
		 * @param array             $entry        The current entry.
		 * @param Gravity_Flow_Step $current_step The current step.
		 */
		$display_assignee_status_list = apply_filters( 'gravityflow_assignee_status_list_user_input', $display_step_status, $form, $this );
		if ( ! $display_assignee_status_list ) {
			return;
		}

		echo sprintf( '<div class="gravityflow-status-box-field gravityflow-status-box-field-step-status"><h4><span class="gravityflow-status-box-field-label">%s </span><span class="gravityflow-status-box-field-value">(%s)</span></h4></div>', $this->get_name(), $this->get_status_string() );

		echo '<div class="gravityflow-status-box-field gravityflow-status-box-field-assignees">';
		echo '<ul>';

		$assignees = $this->get_assignees();

		$this->log_debug( __METHOD__ . '(): assignee details: ' . print_r( $assignees, true ) );

		foreach ( $assignees as $assignee ) {
			$assignee_status_label = $assignee->get_status_label();
			$assignee_status_li    = sprintf( '<li>%s</li>', $assignee_status_label );

			echo $assignee_status_li;
		}

		echo '</ul>';
		echo '</div>';
	}

	/**
	 * If the user can update the step enable the update button.
	 *
	 * @param bool $can_update Indicates if the assignee or role status is pending.
	 */
	public function maybe_enable_update_button( $can_update ) {
		if ( ! $can_update ) {
			return;
		}

		?>
		<script>
			(function (GravityFlowUserInput, $) {
				$(document).ready(function () {
					<?php if ( $this->default_status == 'submit_buttons' ) { ?>
						$('#gravityflow_save_progress_button').prop('disabled', false);
						$('#gravityflow_submit_button').prop('disabled', false);
					<?php } else { ?>
						$('#gravityflow_update_button').prop('disabled', false);
					<?php } ?>
				});
			}(window.GravityFlowUserInput = window.GravityFlowUserInput || {}, jQuery));
		</script>

		<?php
	}

	/**
	 * Output the status inputs and associated labels.
	 */
	public function display_status_inputs() {
		$default_status = $this->default_status ? $this->default_status : 'complete';

		if ( in_array( $default_status, array( 'hidden', 'submit_buttons' ), true ) ) {
			?>
			<input type="hidden" id="gravityflow_status_hidden" name="gravityflow_status" value="complete"/>
			<?php
		} else {

			$in_progress_label = esc_html__( 'In progress', 'gravityflow' );

			/**
			 * Allows the 'in progress' label to be modified on the User Input step.
			 *
			 * @params string $in_progress_label The "In progress" label.
			 * @params Gravity_Flow_Step $this The current step.
			 */
			$in_progress_label = apply_filters( 'gravityflow_in_progress_label_user_input', $in_progress_label, $this );

			$complete_label = esc_html__( 'Complete', 'gravityflow' );

			/**
			 * Allows the 'complete' label to be modified on the User Input step.
			 *
			 * @params string $complete_label The "Complete" label.
			 * @params Gravity_Flow_Step $this The current step.
			 */
			$complete_label = apply_filters( 'gravityflow_complete_label_user_input', $complete_label, $this )
			?>
			<div class="gravityflow-status-box-field gravityflow-status-box-field-progress-buttons">
				<label for="gravityflow_in_progress">
					<input type="radio" id="gravityflow_in_progress" name="gravityflow_status" <?php checked( $default_status, 'in_progress' ); ?> value="in_progress"/><?php echo $in_progress_label; ?>
				</label>&nbsp;&nbsp;
				<label for="gravityflow_complete">
					<input type="radio" id="gravityflow_complete" name="gravityflow_status" value="complete" <?php checked( $default_status, 'complete' ); ?>/><?php echo $complete_label; ?>
				</label>
			</div>
			<?php
		}
	}

	/**
	 * Display the update button for this step.
	 *
	 * @param array $form The form for the current entry.
	 */
	public function display_update_button( $form ) {
		?>
		<br/>
		<div class="gravityflow-action-buttons">
			<?php
			if ( $this->default_status == 'submit_buttons' ) {

				$form_id          = absint( $form['id'] );

				$save_progress_button_text   = esc_html__( 'Save', 'gravityflow' );

				/**
				 * Allows the save_progress button label to be modified on the User Input step when the Save Progress option is set to 'Submit Buttons'.
				 *
				 * @since  1.9.2
				 *
				 * @params string $save_progress_label. The "Save" label.
				 * @params array  $form The form for the current entry.
				 * @params Gravity_Flow_Step $this The current step.
				 */
				$save_progress_button_text   = apply_filters( 'gravityflow_save_progress_button_text_user_input', $save_progress_button_text, $form, $this );
				$save_progress_button_click  = "jQuery('#action').val('update'); jQuery('#gravityflow_status_hidden').val('in_progress'); jQuery('#gform_{$form_id}').submit(); return false;";
				$save_progress_button        = '<input id="gravityflow_save_progress_button" disabled="disabled" class="button button-large button-secondary" type="submit" tabindex="4" value="' . $save_progress_button_text . '" name="in_progress" onclick="' . $save_progress_button_click . '" />';

				/**
				 * Allows the save_progress button to be modified on the User Input step when the Save Progress option is set to 'Submit Buttons'.
				 *
				 * @since  1.9.2
				 *
				 * @params string $save_progress_button The HTML for the "Save" button.
				 */
				echo apply_filters( 'gravityflow_save_progress_button_user_input', $save_progress_button );

				$submit_button_text   = esc_html__( 'Submit', 'gravityflow' );

				/**
				 * Allows the submit button label to be modified on the User Input step when the Save Progress option is set to 'Submit Buttons'.
				 *
				 * @since  1.9.2
				 *
				 * @params string $submit_label The "Submit" label.
				 * @params array  $form The form for the current entry.
				 * @params Gravity_Flow_Step $this The current step.
				 */
				$submit_button_text   = apply_filters( 'gravityflow_submit_button_text_user_input', $submit_button_text, $form, $this );
				$submit_button_click  = "jQuery('#action').val('update'); jQuery('#gravityflow_status_hidden').val('complete'); jQuery('#gform_{$form_id}').submit(); return false;";
				$submit_button        = '<input id="gravityflow_submit_button" disabled="disabled" class="button button-large button-primary" type="submit" tabindex="5" value="' . $submit_button_text . '" name="save" onclick="' . $submit_button_click . '"/>';

				/**
				* Allows the submit button to be modified on the User Input step when the Save Progress option is set to 'Submit Buttons'
				*
				* @since 1.9.2
				*
				* @params string $submit_button The HTML for the "Submit" button.
				*/
				echo apply_filters( 'gravityflow_submit_button_user_input', $submit_button );
			} else {

				$button_text = $this->default_status == 'hidden' ? esc_html__( 'Submit', 'gravityflow' ) : esc_html__( 'Update', 'gravityflow' );

				/**
				 * Allows the update button label to be modified on the User Input step when the Save Progress option is set to hidden or either radio button setting.
				 *
				 * @since  unknown
				 *
				 * @params string $update_label The "Update" label.
				 * @params array  $form The form for the current entry.
				 * @params Gravity_Flow_Step $this The current step.
				 */
				$button_text = apply_filters( 'gravityflow_update_button_text_user_input', $button_text, $form, $this );

				$form_id          = absint( $form['id'] );
				$button_click     = "jQuery('#action').val('update'); jQuery('#gform_{$form_id}').submit(); return false;";
				$update_button    = '<input id="gravityflow_update_button" disabled="disabled" class="button button-large button-primary" type="submit" value="' . $button_text . '" name="save" onclick="' . $button_click . '"/>';

				/**
				* Allows the update button to be modified on the User Input step when the Save Progress option is set to hidden or either radio button setting.
				*
				* @since unknown
				*
				* @params string $update_button The HTML for the "Update" button.
				*/
				echo apply_filters( 'gravityflow_update_button_user_input', $update_button );

			}
			?>
		</div>
		<?php
	}

	/**
	 * If applicable display the note section of the workflow detail box.
	 *
	 * @param array $form The form for the current entry.
	 */
	public function maybe_display_note_box( $form ) {
		if ( $this->note_mode === 'hidden' ) {
			return;
		}
		$invalid_note = ( isset( $form['workflow_note'] ) && is_array( $form['workflow_note'] ) && $form['workflow_note']['failed_validation'] );
		$posted_note  = '';
		if ( rgar( $form, 'failed_validation' ) ) {
			$posted_note = rgpost( 'gravityflow_note' );
		}
		?>

		<div class="gravityflow-status-box-field gravityflow-status-box-field-note">
			<label id="gravityflow-notes-label" for="gravityflow-note">
				<?php
				esc_html_e( 'Note', 'gravityflow' );
				$required_indicator = ( $this->note_mode == 'required' ) ? '*' : '';
				printf( "<span class='gfield_required'>%s</span>", $required_indicator );
				?>
			</label>

			<textarea id="gravityflow-note" rows="4" class="wide" name="gravityflow_note"><?php echo esc_textarea( $posted_note ) ?></textarea>
			<?php

			if ( $invalid_note ) {
				printf( "<div class='gfield_description validation_message'>%s</div>", $form['workflow_note']['validation_message'] );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Displays content inside the Workflow metabox on the Gravity Forms Entry Detail page.
	 *
	 * @param array $form The current form.
	 */
	public function entry_detail_status_box( $form ) {
		$status = $this->evaluate_status();
		?>
		<h4 style="padding:10px;"><?php echo $this->get_name() . ': ' . $status ?></h4>

		<div style="padding:10px;">
			<ul>
				<?php

				$assignees = $this->get_assignees();

				foreach ( $assignees as $assignee ) {
					$assignee_status_label = $assignee->get_status_label();
					$assignee_status_li    = sprintf( '<li>%s</li>', $assignee_status_label );

					echo $assignee_status_li;
				}

				?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Update the input value in the entry.
	 *
     * @since 2.4 added the $current_fields parameter
	 * @since 1.5.1-dev
	 *
	 * @param array      $form           The form currently being processed.
	 * @param GF_Field   $field          The current fields properties.
	 * @param array      $entry          The entry currently being processed.
	 * @param array      $current_fields The array of current field values in the database.
	 * @param int|string $input_id       The ID of the field or input currently being processed.
	 */
	public function save_input( $form, $field, &$entry, $current_fields, $input_id ) {

		if ( gravity_flow()->is_gravityforms_supported( '2.4-rc-1' ) && isset( $field->fields ) && is_array( $field->fields ) ) {
			foreach( $field->fields as $sub_field ) {
				$inputs = $sub_field->get_entry_inputs();
				if ( is_array( $inputs ) ) {
					foreach ( $inputs as $input ) {
						$this->save_input( $form, $sub_field, $entry, $current_fields, $input['id'] );
					}
				} else {
					$this->save_input( $form, $sub_field, $entry, $current_fields, $sub_field->id );
				}
				foreach ( $current_fields as $current_field ) {
					if ( intval( $current_field->meta_key ) == $sub_field->id && ! isset( $current_field->update ) ) {
						$current_field->delete = true;
						$result = GFFormsModel::queue_batch_field_operation( $form, $entry, $sub_field, $current_field->id, $current_field->meta_key, '', $current_field->item_index );
						$this->log_debug( __METHOD__ . "(): Deleting: {$field->label}(#{$sub_field->id}{$current_field->item_index} - {$field->type}). Result: " . var_export( $result, 1 ) );
					}
				}
			}
			return;
		}

		$input_name = 'input_' . str_replace( '.', '_', $input_id );

		if ( $field->enableCopyValuesOption && rgpost( 'input_' . $field->id . '_copy_values_activated' ) ) {
			$source_field_id   = $field->copyValuesOptionField;
			$source_input_name = str_replace( 'input_' . $field->id, 'input_' . $source_field_id, $input_name );
			$value             = rgpost( $source_input_name );
		} else {
			$value = rgpost( $input_name );
		}

		if ( function_exists( 'gf_coupons' ) && $field instanceof GF_Field_Coupon ) {
			$this->maybe_update_coupon_usage_counts( $value, rgar( $entry, $input_id ) );
		}

		if ( gravity_flow()->is_gravityforms_supported( '2.4-rc-1' ) ) {
			if ( GFFormsModel::is_field_hidden( $form, $field, array(), $entry ) ) {
				// Clearing existing value from the entry when field is hidden by conditional logic.
				GFFormsModel::queue_batch_field_operation( $form, $entry, $field, GFFormsModel::get_lead_detail_id( $current_fields, $input_id ), $input_id, '' );
			} else {
				GFFormsModel::queue_save_input_value( $value, $form, $field, $entry, $current_fields, $input_id );
			}
		} else {
			$existing_value = rgar( $entry, $input_id );
			$value          = GFFormsModel::maybe_trim_input( $value, $form['id'], $field );
			$value          = GFFormsModel::prepare_value( $form, $field, $value, $input_name, $entry['id'], $entry );
			if ( $existing_value != $value ) {
				$entry_meta_id = GFFormsModel::get_lead_detail_id( $current_fields, $input_id );
				$result = GFFormsModel::queue_batch_field_operation( $form, $entry, $field, $entry_meta_id, $input_id, $value );
				$this->log_debug( __METHOD__ . "(): Updating: {$field->label}(#{$field->id} - {$field->type}). Result: " . var_export( $result, 1 ) );
			}
		}

		if ( GFCommon::is_post_field( $field ) && ! in_array( $field->id, $this->_update_post_fields['fields'] ) ) {
			$this->_update_post_fields['fields'][] = $field->id;
		}
	}

}

Gravity_Flow_Steps::register( new Gravity_Flow_Step_User_Input() );
