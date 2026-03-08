<?php

trait Editable_Fields {

	/**
	 * An array of field IDs which the user can edit.
	 *
	 * @var array
	 */
	protected $_editable_fields = array();

	/**
	 * An array of field IDs to be processed when updating the post created from the current entry.
	 *
	 * @var array
	 */
	protected $_update_post_fields = array(
		'fields' => array(),
		'images' => array(),
	);

	/**
	 * Get the settings array for Editable Fields.
	 *
	 * @since 2.9
	 *
	 * @return array[]
	 */
	public function get_editable_fields_setting() {
		return array(
			array(
				'id'       => 'editable_fields',
				'name'     => 'editable_fields[]',
				'label'    => __( 'Editable fields', 'gravityflow' ),
				'multiple' => 'multiple',
				'type'     => 'editable_fields',
			),
		);
	}

	/**
	 * Get the step settings from the parent class, and then merge in our Editable Fields.
	 *
	 * @since 2.9
	 *
	 * @return array
	 */
	public function get_step_settings() {
		$settings = $this->get_settings();
		$fields   = $settings['fields'];

		$insert_key = array_search( 'assignees[]', array_column( $fields, 'name' ) );

		if ( $insert_key === false ) {
			$insert_key = 0;
		} else {
			$insert_key = $insert_key + 1;
		}

		array_splice( $fields, $insert_key, 0, $this->get_editable_fields_setting() );

		$settings['fields'] = $fields;

		return $settings;
	}

	/**
	 * Get the fields conditional logic settings array for the current step.
	 *
	 * @since 2.9
	 *
	 * @return array
	 */
	public function get_fields_conditional_logic_settings() {
		$form = $this->get_form();
		if ( false === $this->fields_have_conditional_logic( $form ) ) {
			return array();
		}

		/**
		 * Filters the value that controls wether or not the conditional logic setting for this Step has option for "page load" or "dynamic".
		 *
		 * @since  2.9.1 Added $this parameter. Expanded to also call a step type specific filter.
		 *
		 * @param boolean           $do_display Wether or not to display the "page load" vs. "dynamic" option for the conditional logic setting. Defaults to false.
		 * @param Gravity_Flow_Step $this       The current step object.
		 */
		$display_page_load_logic_setting = gf_apply_filters( array( 'gravityflow_page_load_logic_setting', $this->get_type() ), false, $this );
		if ( $display_page_load_logic_setting && GFCommon::has_pages( $form ) && $this->pages_have_conditional_logic( $form ) ) {
			$settings = array(
				'name'     => 'conditional_logic_editable_fields_enabled',
				'label'    => esc_html__( 'Conditional Logic', 'gravityflow' ),
				'type'     => 'checkbox_and_select',
				'checkbox' => array(
					'label'         => esc_html__( 'Enable field conditional logic', 'gravityflow' ),
					'name'          => 'conditional_logic_editable_fields_enabled',
					'default_value' => '1',
				),
				'select'   => array(
					'name'    => 'conditional_logic_editable_fields_mode',
					'choices' => array(
						array(
							'value' => 'dynamic',
							'label' => esc_html__( 'Dynamic', 'gravityflow' ),
						),
						array(
							'value' => 'page_load',
							'label' => esc_html__( 'Only when the page loads', 'gravityflow' ),
						),
					),
					'tooltip' => esc_html__( 'Fields and Sections support dynamic conditional logic. Pages do not support dynamic conditional logic so they will only be shown or hidden when the page loads.', 'gravityflow' ),
				),
			);
		} else {
			$settings = array(
				'name'    => 'conditional_logic_editable_fields_enabled',
				'label'   => esc_html__( 'Conditional Logic', 'gravityflow' ),
				'type'    => 'checkbox',
				'choices' => array(
					array(
						'label'         => esc_html__( 'Enable field conditional logic', 'gravityflow' ),
						'name'          => 'conditional_logic_editable_fields_enabled',
						'default_value' => '1',
					),
				),
			);
		}

		return $settings;
	}

	/**
	 * Get the editable fields for the current step.
	 *
	 * @since 2.9
	 *
	 * @return array
	 */
	public function get_editable_fields() {
		if ( ! empty( $this->_editable_fields ) ) {
			return $this->_editable_fields;
		}

		$editable_fields  = array();
		$assignee_details = $this->get_assignees();

		foreach ( $assignee_details as $assignee ) {
			if ( $assignee->is_current_user() && is_array( $assignee->get_editable_fields() ) ) {
				$assignee_editable_fields = $assignee->get_editable_fields();
				$editable_fields          = array_merge( $editable_fields, $assignee_editable_fields );
			}
		}

		/**
		 * Allow the editable fields array to be modified.
		 *
		 * @since  2.9.1 moved to trait-editable-fields.php and expanded from a general filter as well as by step type that supports editable fields.
		 * @since  2.5.8 only available as gravityflow_editable_fields_user_input
		 *
		 * @param array             $editable_fields The current array of editable fields
		 * @param Gravity_Flow_Step $this            The current step.
		 */
		$editable_fields = gf_apply_filters( array( 'gravityflow_editable_fields', $this->get_type() ), $editable_fields, $this );

		$this->_editable_fields = $editable_fields;

		return $editable_fields;
	}

	/**
	 * Perform editable fields logic before calling the parent process_status_update method.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 *
	 * @param $form
	 * @param $entry
	 *
	 * @return bool|string|WP_Error
	 */
	public function process_status_update( $form, $entry ) {
		if ( ! isset( $_POST['gforms_save_entry'] ) || rgpost( 'step_id' ) != $this->get_id() || ! check_admin_referer( 'gforms_save_entry', 'gforms_save_entry' ) ) {
			return false;
		}

		// Validate and save the entry.
		$result = $this->pre_process_status_update( $form, $entry, rgpost( 'gravityflow_status' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Call parent method to process status update.
		return parent::process_status_update( $form, $entry );
	}

	/**
	 * Validates the status update and saves the entry if validation is successful.
	 *
	 * @since 3.0.0
	 *
	 * @param array  $form        The form currently being processed.
	 * @param array  $entry       The entry currently being processed.
	 * @param string $step_status The new step status to update to. Valid values are 'in_progress' or 'complete'.
	 *
	 * @return bool|WP_Error Returns true if validation is successful, otherwise returns a WP_Error object.
	 */
	private function pre_process_status_update( $form, $entry, $step_status ) {

		// Populate GFFormsModel::$uploaded_files[ $form_id ] with the files from the gform_uploaded_files input.
		$files = GFFormsModel::set_uploaded_files( rgar( $form, 'id' ) );

		$valid = $this->validate_editable_fields( true, $form, $step_status );

		// Perform parent validation if editable fields pass validation.
		if ( ! is_wp_error( $valid ) ) {
			$valid = $this->validate_status_update( $step_status, $form );
		}

		if ( is_wp_error( $valid ) ) {
			$this->log_debug( __METHOD__ . '(): Failed validation.' );

			// If error code is validation_error, add status code 422
			if ( $valid->get_error_code() === 'validation_result' ) {
				$valid->error_data[ 'validation_result' ]['status'] = 422;
			}

			// Upload valid temp single files.
			$this->maybe_upload_files( $form, $files );

			return $valid;
		}

		$editable_fields = $this->get_editable_fields();
		$this->save_entry( $form, $entry, $editable_fields );

		return true;
	}

	/**
	 * Determine if the editable fields for this step are valid.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 3.0.0 added $new_status parameter.
	 *
	 * @param bool  $valid The steps current validation state.
	 * @param array $form  The form currently being processed.
	 * @param string $new_status The new status to update to.
	 *
	 * @return bool
	 */
	public function validate_editable_fields( $valid, &$form, $new_status = null ) {

		/**
		 * Allows the form to be filtered before the editable fields are validated.
		 *
		 * @since 2.5.10
		 *
		 * @param array $form The current form.
		 */
		$form = gf_apply_filters( array( 'gform_pre_validation', rgar( $form, 'id' ) ), $form );

		$new_status      = ! empty( $new_status ) ? $new_status : rgpost( 'gravityflow_status' );

		$conditional_logic_enabled           = $this->fields_have_conditional_logic( $form ) && $this->conditional_logic_editable_fields_enabled;
		$page_load_conditional_logic_enabled = $conditional_logic_enabled && $this->conditional_logic_editable_fields_mode == 'page_load';
		$dynamic_conditional_logic_enabled   = $conditional_logic_enabled && $this->conditional_logic_editable_fields_mode != 'page_load';

		$saved_entry = $this->get_entry();

		if ( ! $conditional_logic_enabled || $page_load_conditional_logic_enabled ) {
			$entry = $saved_entry;
		} else {
			$entry = GFFormsModel::create_lead( $form );
		}

		foreach ( $form['fields'] as $field ) {

			if ( $this->is_editable_field_posted( $field ) ) {
				if ( ( $dynamic_conditional_logic_enabled && GFFormsModel::is_field_hidden( $form, $field, array() ) ) ) {
					continue;
				}

				$submission_is_empty = $field->is_value_submission_empty( $form['id'] );
				$gravityflow_status  = ! empty( $new_status ) ? $new_status : 'complete';

				if ( $field->get_input_type() == 'fileupload' ) {

					if ( $field->isRequired && $submission_is_empty && rgempty( $field->id, $saved_entry ) && $gravityflow_status == 'complete' ) {
						$field->failed_validation  = true;
						$field->validation_message = empty( $field->errorMessage ) ? esc_html__( 'This field is required.', 'gravityflow' ) : $field->errorMessage;
						$valid                     = false;

						continue;
					}

					$field->validate( GFFormsModel::get_field_value( $field ), $form );
					if ( $field->failed_validation ) {
						$valid = false;
					}

					continue;
				}

				if ( $page_load_conditional_logic_enabled ) {
					$field_is_hidden = GFFormsModel::is_field_hidden( $form, $field, array(), $entry );
				} elseif ( $dynamic_conditional_logic_enabled ) {
					$field_is_hidden = GFFormsModel::is_field_hidden( $form, $field, array() );
				} else {
					$field_is_hidden = false;
				}

				if ( ! $field_is_hidden && $submission_is_empty && $field->isRequired && $gravityflow_status == 'complete' ) {
					$field->failed_validation  = true;
					$field->validation_message = empty( $field->errorMessage ) ? esc_html__( 'This field is required.', 'gravityflow' ) : $field->errorMessage;
					$valid                     = false;
				} elseif ( ! $field_is_hidden && ! $submission_is_empty ) {
					$value = GFFormsModel::get_field_value( $field );

					if ( ! empty( $field->fields ) &&  $new_status == 'in_progress' ) {
						// Temporarily set isRequired for all sub-fields to false to allow required fields to be saved when saving progress.
						$this->set_field_property( $field, 'isRequired', false );
						$field->validate( $value, $form );
						$this->restore_field_property( $field, 'isRequired' );
					} else {
						$field->validate( $value, $form );
					}

					$custom_validation_result = gf_apply_filters( array( 'gform_field_validation', $form['id'], $field->id ), array(
						'is_valid' => $field->failed_validation ? false : true,
						'message'  => $field->validation_message,
					), $value, $form, $field );

					$field->failed_validation  = rgar( $custom_validation_result, 'is_valid' ) ? false : true;
					$field->validation_message = rgar( $custom_validation_result, 'message' );

					if ( $field->failed_validation ) {
						$valid = false;
					}
				}
			}
		}

		return $this->get_validation_result( $valid, $form, $new_status );
	}

	/**
	 * Updates the entry.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 *
	 * @param array $form            The current form.
	 * @param array $entry           The current entry.
	 * @param array $editable_fields The editable fields for the current user.
	 *
	 * @return void
	 */
	public function save_entry( $form, &$entry, $editable_fields ) {
		global $wpdb;

		$this->log_debug( __METHOD__ . '(): Saving entry.' );

		$is_new_lead = $entry == null;

		// Bailing if null.
		if ( $is_new_lead ) {
			return;
		}

		$original_entry = $entry = $this->refresh_entry();

		$entry_meta_table = GFFormsModel::get_entry_meta_table_name();

		$sql = gravity_flow()->is_gravityforms_supported( '2.4-rc-1' ) ? "SELECT id, meta_key, item_index FROM $entry_meta_table WHERE entry_id=%d" : "SELECT id, meta_key FROM $entry_meta_table WHERE entry_id=%d";

		$current_fields = $wpdb->get_results( $wpdb->prepare( $sql, $entry['id'] ) );

		$total_fields       = array();
		$calculation_fields = array();

		GFFormsModel::begin_batch_field_operations();

		/**
		 * The field properties.
		 *
		 * @var GF_Field $field
		 */
		foreach ( $form['fields'] as &$field ) {

			// Ignore fields that are marked as display only.
			if ( $field->displayOnly && $field->type != 'password' ) {
				continue;
			}

			// Process total field after all fields have been saved.
			if ( $field->type == 'total' ) {
				$total_fields[] = $field;
				continue;
			}

			// Process calculation fields after all fields have been saved (moved after the is hidden check).
			if ( $field->has_calculation() ) {
				$calculation_fields[] = $field;
				continue;
			}

			if ( ! $this->is_editable_field_posted( $field ) ) {
				continue;
			}

			if ( ! $this->conditional_logic_editable_fields_enabled ) {
				$field->conditionalLogic = null;
			}

			if ( in_array( $field->get_input_type(), array( 'fileupload', 'post_image' ) ) ) {
				$this->maybe_save_field_files( $field, $form, $entry );
				continue;
			}

			if ( $field->type == 'post_category' ) {
				$field = GFCommon::add_categories_as_choices( $field, '' );
			}

			$inputs = $field->get_entry_inputs();

			if ( is_array( $inputs ) ) {
				foreach ( $inputs as $input ) {
					$this->save_input( $form, $field, $entry, $current_fields, $input['id'] );
				}
			} else {
				$this->save_input( $form, $field, $entry, $current_fields, $field->id );
			}
		}

		$results = GFFormsModel::commit_batch_field_operations();

		if ( ! empty( $calculation_fields ) ) {
			GFFormsModel::begin_batch_field_operations();
			$this->log_debug( __METHOD__ . '(): Saving calculation fields.' );

			/**
			 * The calculation field properties.
			 *
			 * @var GF_Field $calculation_field
			 */
			foreach ( $calculation_fields as $calculation_field ) {
				// Make sure that the value gets recalculated.
				if ( ! $this->conditional_logic_editable_fields_enabled ) {
					$calculation_field->conditionalLogic = null;
				}

				$inputs = $calculation_field->get_entry_inputs();

				if ( is_array( $inputs ) ) {

					if ( ! in_array( $calculation_field->id, $editable_fields ) ) {
						// Make sure calculated product names and quantities are saved as if they're submitted.
						$value                                             = array( $calculation_field->id . '.1' => $entry[ $calculation_field->id . '.1' ] );
						$_POST[ 'input_' . $calculation_field->id . '_1' ] = $calculation_field->get_field_label( false, $value );
						$quantity                                          = trim( $entry[ $calculation_field->id . '.3' ] );
						if ( $calculation_field->disableQuantity && empty( $quantity ) ) {
							$_POST[ 'input_' . $calculation_field->id . '_3' ] = 1;
						} else {
							$_POST[ 'input_' . $calculation_field->id . '_3' ] = $quantity;
						}
					}
					foreach ( $inputs as $input ) {
						$this->save_input( $form, $calculation_field, $entry, $current_fields, $input['id'] );
					}
				} else {
					$this->save_input( $form, $calculation_field, $entry, $current_fields, $calculation_field->id );
				}

				$results = GFFormsModel::commit_batch_field_operations();
			}
		}

		GFFormsModel::refresh_product_cache( $form, $entry = RGFormsModel::get_lead( $entry['id'] ) );

		// Saving total field as the last field of the form.
		if ( ! empty( $total_fields ) ) {
			GFFormsModel::begin_batch_field_operations();
			$this->log_debug( __METHOD__ . '(): Saving total fields.' );

			/**
			 * The total field properties.
			 *
			 * @var GF_Field $total_field
			 */
			foreach ( $total_fields as $total_field ) {
				$this->save_input( $form, $total_field, $entry, $current_fields, $total_field->id );
			}

			$results = GFFormsModel::commit_batch_field_operations();
		}

		if ( gravity_flow()->is_gravityforms_supported( '2.4-rc-1' ) ) {
			GFFormsModel::hydrate_repeaters( $entry, $form );
		}

		remove_action( 'gform_after_update_entry', array( gravity_flow(), 'filter_after_update_entry' ) );

		/**
		 * Allows custom actions to be performed after the entry is updated from the workflow detail page.
		 *
		 * @since unknown
		 *
		 * @param array $form           The form object for the entry.
		 * @param int   $entry_id       The entry ID.
		 * @param array $original_entry The entry object before being updated.
		 */
		gf_do_action( array( 'gform_after_update_entry', rgar( $form, 'id' ) ), $form, rgar( $entry, 'id' ), $original_entry );

		$entry = GFFormsModel::get_lead( rgar( $entry, 'id' ) );
		GFFormsModel::set_entry_meta( $entry, $form );

		$this->refresh_entry();

		GFCache::flush();
	}

	/**
	 * Get the temporary file path and create the folder if it does not already exist.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 3.0 Updated to use GFFormsModel::get_tmp_upload_location(), which was added in GF 2.9.3.
	 *
	 * @param int $form_id The ID of the form currently being processed.
	 *
	 * @return string
	 */
	public function get_temp_files_path( $form_id ) {
		$target_path = rgar( GFFormsModel::get_tmp_upload_location( $form_id ), 'path' );

		if ( ! is_dir( $target_path ) && wp_mkdir_p( $target_path ) ) {
			GFCommon::recursive_add_index_file( $target_path );
		}

		return $target_path;
	}

	/**
	 * Determines if there are any fields which need files uploading to the temporary folder.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 *
	 * @param array $form The form currently being processed.
	 * @param array $files An array of files which have already been uploaded.
	 */
	public function maybe_upload_files( $form, $files ) {
		if ( empty( $_FILES ) ) {
			return;
		}

		$this->log_debug( __METHOD__ . '(): Checking for fields to process.' );

		$form_id         = absint( rgar( $form, 'id' ) );
		$target_path     = $this->get_temp_files_path( $form_id );
		$editable_fields = $this->get_editable_fields();

		foreach ( $form['fields'] as $field ) {
			if ( ! in_array( $field->id, $editable_fields )
			     || ! $field instanceof GF_Field_FileUpload
			     || $field->multipleFiles
			     || $field->failed_validation
			) {
				// Skip fields which are not editable, are the wrong type, or have failed validation.
				continue;
			}

			$files = $this->maybe_upload_temp_file( $field, $files, $target_path );
		}

		GFFormsModel::$uploaded_files[ $form_id ] = $files;
	}

	/**
	 * Upload the file to the temporary folder for the current field.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 3.0 Updated to use GF_Field_FileUpload::upload_submission_tmp_files() with GF 2.9.18+.
	 *
	 * @param GF_Field $field       The field properties.
	 * @param array    $files       An array of files which have already been uploaded.
	 * @param string   $target_path The path to the tmp folder the file should be moved to.
	 *
	 * @return array
	 */
	public function maybe_upload_temp_file( $field, $files, $target_path ) {
		$input_name = 'input_' . absint( $field->id );

		if ( method_exists( $field, 'upload_submission_tmp_files' ) ) {
			$uploaded_files = $field->upload_submission_tmp_files();
			if ( empty( $uploaded_files ) ) {
				return $files;
			}

			$files[ $input_name ] = $uploaded_files;
			$this->log_debug( __METHOD__ . "(): Temporary file uploaded for field: {$field->label}({$field->id} - {$field->type}). File info => " . print_r( $uploaded_files[0], true ) );

			return $files;
		}

		if ( empty( $_FILES[ $input_name ]['name'] ) ) {
			return $files;
		}

		$file_info = GFFormsModel::get_temp_filename( $field->formId, $input_name );
		$this->log_debug( __METHOD__ . "(): Uploading temporary file for field: {$field->label}({$field->id} - {$field->type}). File info => " . print_r( $file_info, true ) );

		if ( $file_info && move_uploaded_file( $_FILES[ $input_name ]['tmp_name'], $target_path . $file_info['temp_filename'] ) ) {
			GFFormsModel::set_permissions( $target_path . $file_info['temp_filename'] );
			$files[ $input_name ] = $file_info['uploaded_filename'];
			$this->log_debug( __METHOD__ . '(): File uploaded successfully.' );
		} else {
			$this->log_debug( __METHOD__ . "(): File could not be uploaded: tmp_name: {$_FILES[ $input_name ]['tmp_name']} - target location: " . $target_path . $file_info['temp_filename'] );
		}

		return $files;
	}

	/**
	 * If any new files where uploaded save them to the entry.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 3.0 Updated to use GF_Field_FileUpload::is_submission_files_empty() with GF 2.9.18+.
	 *
	 * @param GF_Field|GF_Field_FileUpload $field The current fields properties.
	 * @param array                        $form  The form currently being processed.
	 * @param array                        $entry The entry currently being processed.
	 *
	 * @return void
	 */
	public function maybe_save_field_files( $field, $form, $entry ) {
		$field_id   = absint( $field->id );
		$input_name = 'input_' . $field_id;
		$form_id    = absint( rgar( $form, 'id' ) );

		// Abort if there are no new files uploaded for a multi-file enabled field.
		if ( $field->multipleFiles ) {
			if ( method_exists( $field, 'is_submission_files_empty' ) ) {
				if ( $field->is_submission_files_empty() ) {
					return;
				}
			} elseif ( ! isset( GFFormsModel::$uploaded_files[ $form_id ][ $input_name ] ) ) {
				return;
			}
		}

		$entry_id       = absint( rgar( $entry, 'id' ) );
		$existing_value = rgar( $entry, $field_id );
		$this->maybe_pre_process_post_image_field( $field, $existing_value, $input_name );
		$value = $field->get_value_save_entry( $existing_value, $form, $input_name, $entry_id, $entry );

		if ( ! empty( $value ) && $existing_value != $value ) {
			$result = GFAPI::update_entry_field( $entry_id, $field_id, $value );
			$this->log_debug( __METHOD__ . "(): Saving: {$field->label}(#{$field_id} - {$field->type}). Result: " . var_export( $result, 1 ) );

			if ( GFCommon::is_post_field( $field ) && ! in_array( $field_id, $this->_update_post_fields['images'] ) ) {
				$this->_update_post_fields['images'][] = $field_id;

				$post_images = gform_get_meta( $entry_id, '_post_images' );
				if ( $post_images && isset( $post_images[ $field_id ] ) ) {
					wp_delete_attachment( $post_images[ $field_id ] );
					unset( $post_images[ $field_id ] );
					gform_update_meta( $entry_id, '_post_images', $post_images, $form_id );
				}
			}
		}
	}

	/**
	 * Add the existing post image URL to the $_gf_uploaded_files global so the image title, caption, and description can be updated.
	 *
	 * @since 2.1.2-dev
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 3.0 Added early return for GF 2.9.18+, it no longer needs this workaround.
	 *
	 * @param GF_Field $field          The current field object.
	 * @param string   $existing_value The current fields existing entry value.
	 * @param string   $input_name     The input name to use when accessing the current fields values in the submission.
	 */
	public function maybe_pre_process_post_image_field( $field, $existing_value, $input_name ) {
		if ( method_exists( $field, 'populate_file_url' ) ) {
			return;
		}

		if ( $existing_value && $field->type === 'post_image' && empty( $_FILES[ $input_name ]['name'] ) ) {
			$parts             = explode( '|:|', $existing_value );
			$existing_filename = basename( rgar( $parts, 0 ) );
			$new_filename      = rgar( GFFormsModel::$uploaded_files[ $field->formId ], $input_name );

			if ( ! empty( $new_filename ) && $new_filename === $existing_filename ) {
				global $_gf_uploaded_files;
				$_gf_uploaded_files[ $input_name ] = $parts[0];
			}
		}
	}

	/**
	 * If a post exists for this entry initiate the update.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 1.5.1-dev
	 *
	 * @param array $form    The form currently being processed.
	 * @param int   $post_id The ID of the post created from the current entry.
	 *
	 * @return void
	 */
	public function maybe_process_post_fields( $form, $post_id ) {
		$this->log_debug( __METHOD__ . '(): running.' );

		if ( empty( $post_id ) ) {
			$this->log_debug( __METHOD__ . '(): aborting; no post id' );

			return;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			$this->log_debug( __METHOD__ . '(): aborting; unable to get post.' );

			return;
		}

		$result = $this->process_post_fields( $form, $post );
		$this->log_debug( __METHOD__ . '(): wp_update_post result => ' . print_r( $result, 1 ) );
	}

	/**
	 * Update the post with the field values which have changed.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 1.5.1-dev
	 *
	 * @param array   $form The form currently being processed.
	 * @param WP_Post $post The post to be updated.
	 *
	 * @return int|WP_Error
	 */
	public function process_post_fields( $form, $post ) {
		$entry                = $this->get_entry();
		$post_images          = $this->process_post_images( $form, $entry );
		$has_content_template = rgar( $form, 'postContentTemplateEnabled' );

		foreach ( $this->_update_post_fields['fields'] as $field_id ) {

			$field = GFFormsModel::get_field( $form, $field_id );
			$value = GFFormsModel::get_lead_field_value( $entry, $field );

			switch ( $field->type ) {
				case 'post_title' :
					$post_title       = $this->get_post_title( $value, $form, $entry, $post_images );
					$post->post_title = $post_title;
					$post->post_name  = $post_title;
					break;

				case 'post_content' :
					if ( ! $has_content_template ) {
						$post->post_content = GFCommon::encode_shortcodes( $value );
					}
					break;

				case 'post_excerpt' :
					$post->post_excerpt = GFCommon::encode_shortcodes( $value );
					break;

				case 'post_tags' :
					$this->set_post_tags( $value, $post->ID );
					break;

				case 'post_category' :
					$this->set_post_categories( $value, $post->ID );
					break;

				case 'post_custom_field' :
					$this->set_post_meta( $field, $value, $form, $entry, $post_images );
					break;
			}
		}

		if ( $has_content_template ) {
			$post->post_content = GFFormsModel::process_post_template( $form['postContentTemplate'], 'post_content', $post_images, array(), $form, $entry );
		}

		return wp_update_post( $post, true );
	}

	/**
	 * Attach any new images to the post and set the featured image.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 1.5.1-dev
	 *
	 * @param array $form  The form currently being processed.
	 * @param array $entry The entry currently being processed.
	 *
	 * @return array
	 */
	public function process_post_images( $form, $entry ) {
		$post_id     = $entry['post_id'];
		$post_images = gform_get_meta( $entry['id'], '_post_images' );
		if ( ! $post_images ) {
			$post_images = array();
		}

		foreach ( $this->_update_post_fields['images'] as $field_id ) {
			$value = rgar( $entry, $field_id );
			list( $url, $title, $caption, $description ) = rgexplode( '|:|', $value, 4 );

			if ( empty( $url ) ) {
				continue;
			}

			$image_meta = array(
				'post_excerpt' => $caption,
				'post_content' => $description,
			);

			// Adding title only if it is not empty. It will default to the file name if it is not in the array.
			if ( ! empty( $title ) ) {
				$image_meta['post_title'] = $title;
			}

			$media_id = GFFormsModel::media_handle_upload( $url, $post_id, $image_meta );

			if ( $media_id ) {
				$post_images[ $field_id ] = $media_id;

				// Setting the featured image.
				$field = RGFormsModel::get_field( $form, $field_id );
				if ( $field && $field->postFeaturedImage ) {
					$result = set_post_thumbnail( $post_id, $media_id );
				}
			}

		}

		if ( ! empty( $post_images ) ) {
			gform_update_meta( $entry['id'], '_post_images', $post_images, $form['id'] );
		}

		return $post_images;
	}

	/**
	 * Get the post title.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 1.5.1-dev
	 *
	 * @param string $value       The entry field value.
	 * @param array  $form        The form currently being processed.
	 * @param array  $entry       The entry currently being processed.
	 * @param array  $post_images The images which have been attached to the post.
	 *
	 * @return string
	 */
	public function get_post_title( $value, $form, $entry, $post_images ) {
		if ( rgar( $form, 'postTitleTemplateEnabled' ) ) {
			return GFFormsModel::process_post_template( $form['postTitleTemplate'], 'post_title', $post_images, array(), $form, $entry );
		}

		return GFCommon::encode_shortcodes( $value );
	}

	/**
	 * Set the post tags.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 1.5.1-dev
	 *
	 * @param string|array $value   The entry field value.
	 * @param int          $post_id The ID of the post created from the current entry.
	 *
	 * @return void
	 */
	public function set_post_tags( $value, $post_id ) {
		$post_tags = is_array( $value ) ? array_values( $value ) : explode( ',', $value );

		wp_set_post_tags( $post_id, $post_tags, false );
	}

	/**
	 * Set the post categories.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 1.5.1-dev
	 *
	 * @param string|array $value   The entry field value.
	 * @param int          $post_id The ID of the post created from the current entry.
	 *
	 * @return void
	 */
	public function set_post_categories( $value, $post_id ) {
		$post_categories = array();

		foreach ( explode( ',', $value ) as $cat_string ) {
			$cat_array = explode( ':', $cat_string );
			// The category id is the last item in the array, access it using end() in case the category name includes colons.
			array_push( $post_categories, end( $cat_array ) );
		}

		wp_set_post_categories( $post_id, $post_categories, false );
	}

	/**
	 * Set the post meta.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 1.5.1-dev
	 *
	 * @param GF_Field     $field       The Post Custom Field.
	 * @param string|array $value       The entry field value.
	 * @param array        $form        The form currently being processed.
	 * @param array        $entry       The entry currently being processed.
	 * @param array        $post_images The images which have been attached to the post.
	 *
	 * @return void
	 */
	public function set_post_meta( $field, $value, $form, $entry, $post_images ) {
		$post_id = $entry['post_id'];

		delete_post_meta( $post_id, $field->postCustomFieldName );

		if ( ! empty( $field->customFieldTemplateEnabled ) ) {
			$value = GFFormsModel::process_post_template( $field->customFieldTemplate, 'post_custom_field', $post_images, array(), $form, $entry );
		}

		switch ( $field->inputType ) {
			case 'list' :
				$value = maybe_unserialize( $value );
				if ( is_array( $value ) ) {
					foreach ( $value as $item ) {
						if ( is_array( $item ) ) {
							$item = implode( '|', $item );
						}

						if ( ! rgblank( $item ) ) {
							add_post_meta( $post_id, $field->postCustomFieldName, $item );
						}
					}
				}
				break;

			case 'multiselect' :
			case 'checkbox' :
				$value = ! is_array( $value ) ? explode( ',', $value ) : $value;
				foreach ( $value as $item ) {
					if ( ! rgblank( $item ) ) {
						add_post_meta( $post_id, $field->postCustomFieldName, $item );
					}
				}
				break;

			case 'date' :
				$value = GFCommon::date_display( $value, $field->dateFormat );
				if ( ! rgblank( $value ) ) {
					add_post_meta( $post_id, $field->postCustomFieldName, $value );
				}
				break;

			default :
				if ( ! rgblank( $value ) ) {
					add_post_meta( $post_id, $field->postCustomFieldName, $value );
				}
				break;
		}
	}

	/**
	 * Add the gform_after_create_post filter.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 1.5.1-dev
	 *
	 * @return void
	 */
	public function intercept_submission() {
		$form_id = $this->get_form_id();
		add_filter( "gform_after_create_post_{$form_id}", array( $this, 'action_after_create_post' ), 10, 3 );
	}

	/**
	 * Store the media IDs for the processed post images in the entry meta.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 1.5.1-dev
	 *
	 * @param int   $post_id The ID of the post created from the current entry.
	 * @param array $entry   The entry currently being processed.
	 * @param array $form    The form currently being processed.
	 *
	 * @return void
	 */
	public function action_after_create_post( $post_id, $entry, $form ) {
		$post_images = gform_get_meta( $entry['id'], '_post_images' );

		if ( $post_images ) {
			return;
		}

		$post_images = array();

		foreach ( $form['fields'] as $field ) {
			if ( $field->type !== 'post_image' || rgempty( $field->id, $entry ) ) {
				continue;
			}

			$props = rgexplode( '|:|', $entry[ $field->id ], 5 );

			if ( ! empty( $props[4] ) ) {
				$post_images[ $field->id ] = $props[4];
			}
		}

		if ( ! empty( $post_images ) ) {
			gform_add_meta( $entry['id'], '_post_images', $post_images );
		}
	}

	/**
	 * Update the input value in the entry.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 2.4 added the $current_fields parameter
	 * @since 1.5.1-dev
	 *
	 * @param array      $form           The form currently being processed.
	 * @param GF_Field   $field          The current fields properties.
	 * @param array      $entry          The entry currently being processed.
	 * @param array      $current_fields The array of current field values in the database.
	 * @param int|string $input_id       The ID of the field or input currently being processed.
	 *
	 * @return void
	 */
	public function save_input( $form, $field, &$entry, $current_fields, $input_id ) {

		if ( gravity_flow()->is_gravityforms_supported( '2.4-rc-1' ) && isset( $field->fields ) && is_array( $field->fields ) ) {
			foreach ( $field->fields as $sub_field ) {
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
						$result                = GFFormsModel::queue_batch_field_operation( $form, $entry, $sub_field, $current_field->id, $current_field->meta_key, '', $current_field->item_index );
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
				$result        = GFFormsModel::queue_batch_field_operation( $form, $entry, $field, $entry_meta_id, $input_id, $value );
				$this->log_debug( __METHOD__ . "(): Updating: {$field->label}(#{$field->id} - {$field->type}). Result: " . var_export( $result, 1 ) );
			}
		}

		if ( GFCommon::is_post_field( $field ) && ! in_array( $field->id, $this->_update_post_fields['fields'] ) ) {
			$this->_update_post_fields['fields'][] = $field->id;
		}
	}

	/**
	 * Determines if the field is editable and has been posted.
	 *
	 * @since 3.0
	 *
	 * @param GF_Field $field The field object.
	 *
	 * @return bool Returns true if the field is editable and has been posted, false otherwise.
	 */
	public function is_editable_field_posted( $field ) {
		$is_editable_field = in_array( $field->id, $this->get_editable_fields() );

		return $is_editable_field && $this->is_field_posted( $field );
	}

	/**
	 * Determines if the field has been posted.
	 *
	 * @since 3.0
	 *
	 * @param GF_Field $field The field object.
	 *
	 * @return bool Returns true if the field has been posted, false otherwise.
	 */
	public function is_field_posted( $field ) {

		// The UI always posts all fields, so if this is not a REST request assume the field is posted.
		$is_rest_request = defined( 'REST_REQUEST' ) && REST_REQUEST;
		if ( ! $is_rest_request ) {
			return true;
		}

		// For REST requests, inputs can be ommitted if they are not being updated, so check for their presence.
		$inputs = $field->get_entry_inputs();
		if ( is_array( $inputs ) ) {
			foreach ( $inputs as $input ) {
				$input_name = 'input_' . str_replace( '.', '_', $input['id'] );
				if ( isset( $_POST[ $input_name ] ) ) {
					return true;
				}
			}
		} else {
			$input_name = 'input_' . $field->id;
			return isset( $_POST[ $input_name ] ) || isset( $_FILES[ $input_name ] );
		}
	}

	/**
	 * Triggers updating of the usage counts for any applied and/or removed coupon codes.
	 *
	 * @since 2.9 moved to trait-editable-fields.php
	 * @since 2.5.10
	 *
	 * @param string $value          The current field value.
	 * @param string $previous_value The existing entry value.
	 *
	 * @return void
	 */
	public function maybe_update_coupon_usage_counts( $value, $previous_value ) {
		$current_codes  = ! empty( $value ) ? array_map( 'trim', explode( ',', $value ) ) : array();
		$previous_codes = ! empty( $previous_value ) ? array_map( 'trim', explode( ',', $previous_value ) ) : array();

		foreach ( $current_codes as $code ) {
			if ( ! in_array( $code, $previous_codes ) ) {
				$this->update_coupon_usage_count( $code );
			}
		}

		foreach ( $previous_codes as $code ) {
			if ( ! in_array( $code, $current_codes ) ) {
				$this->update_coupon_usage_count( $code, false );
			}
		}
	}

	/**
	 * Updates the coupon usage count.
	 *
	 * @since 2.8 moved to trait-editable-fields.php
	 * @since 2.5.10
	 *
	 * @param string $code      The coupon code.
	 * @param bool   $increment Indicates if the usage count should be incremented or decremented.
	 */
	public function update_coupon_usage_count( $code, $increment = true ) {
		$feed = gf_coupons()->get_config( array( 'id' => $this->get_form_id() ), $code );
		if ( ! $feed ) {
			return;
		}

		$meta      = $feed['meta'];
		$count     = empty( $meta['usageCount'] ) ? 0 : intval( $meta['usageCount'] );
		$old_count = $count;
		$dirty     = false;

		if ( $increment ) {
			$dirty              = true;
			$meta['usageCount'] = ++ $count;
		} elseif ( $count > 0 ) {
			$dirty              = true;
			$meta['usageCount'] = -- $count;
		}

		if ( $dirty ) {
			gf_coupons()->log_debug( sprintf( '%s(): Updating usage count for coupon %s from %d to %d.', __METHOD__, $code, $old_count, $count ) );
			gf_coupons()->update_feed_meta( $feed['id'], $meta );
		}
	}

	/**
	 * Determines if this forms page fields have conditional logic configured.
	 *
	 * @param array $form The current form.
	 *
	 * @return bool
	 */
	public function pages_have_conditional_logic( $form ) {
		return gravity_flow()->pages_have_conditional_logic( $form );
	}

	/**
	 * Determines if this forms fields have conditional logic configured.
	 *
	 * @param array $form The current form.
	 *
	 * @return bool
	 */
	public function fields_have_conditional_logic( $form ) {
		return gravity_flow()->fields_have_conditional_logic( $form );
	}
}
