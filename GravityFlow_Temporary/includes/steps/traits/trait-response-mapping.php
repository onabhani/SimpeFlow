<?php

trait Response_Mapping {
	/**
	 * Prepare value map.
	 *
	 * @since 2.9.10
	 *
	 * @return array
	 */
	public function value_mappings() {
		return $this->get_field_map_choices( $this->get_form() );
	}

	/**
	 * Returns the choices for the source field mappings.
	 *
	 * @since 2.9.10
	 *
	 * @param array       $form                The current form.
	 * @param null|string $field_type          Field types to include as choices. Defaults to null.
	 * @param null|array  $exclude_field_types Field types to exclude as choices. Defaults to null.
	 *
	 * @return array
	 */
	public function get_field_map_choices( $form, $field_type = null, $exclude_field_types = null ) {

		$choices = array();

		// Setup first choice.
		if ( rgblank( $field_type ) || ( is_array( $field_type ) && count( $field_type ) > 1 ) ) {

			$first_choice_label = __( 'Select a Field', 'gravityflow' );

		} else {

			$type = is_array( $field_type ) ? $field_type[0] : $field_type;
			$type = ucfirst( GF_Fields::get( $type )->get_form_editor_field_title() );

			/* Translators: Placeholder is for the field type which should be selected */
			$first_choice_label = sprintf( __( 'Select a %s Field', 'gravityflow' ), $type );

		}

		$choices[] = array(
			'value' => '',
			'label' => $first_choice_label,
		);

		// Populate form fields.
		if ( is_array( $form['fields'] ) ) {
			$fields = array();

			foreach ( $form['fields'] as $field ) {
				$input_type          = $field->get_input_type();
				$inputs              = $field->get_entry_inputs();
				$field_is_valid_type = ( empty( $field_type ) || ( is_array( $field_type ) && in_array( $input_type, $field_type ) ) || ( ! empty( $field_type ) && $input_type == $field_type ) );

				if ( is_null( $exclude_field_types ) ) {
					$exclude_field = false;
				} elseif ( is_array( $exclude_field_types ) ) {
					if ( in_array( $input_type, $exclude_field_types ) ) {
						$exclude_field = true;
					} else {
						$exclude_field = false;
					}
				} else {
					// Not array, so should be single string.
					if ( $input_type == $exclude_field_types ) {
						$exclude_field = true;
					} else {
						$exclude_field = false;
					}
				}

				if ( is_array( $inputs ) && $field_is_valid_type && ! $exclude_field ) {
					// If this is an address field, add full name to the list.
					if ( $input_type == 'address' ) {
						$fields[] = array(
							'value' => $field->id,
							'label' => GFCommon::get_label( $field ) . ' (' . esc_html__( 'Full', 'gravityflow' ) . ')',
						);
					}
					// If this is a name field, add full name to the list.
					if ( $input_type == 'name' ) {
						$fields[] = array(
							'value' => $field->id,
							'label' => GFCommon::get_label( $field ) . ' (' . esc_html__( 'Full', 'gravityflow' ) . ')',
						);
					}
					// If this is a checkbox field, add to the list.
					if ( $input_type == 'checkbox' ) {
						$fields[] = array(
							'value' => $field->id,
							'label' => GFCommon::get_label( $field ) . ' (' . esc_html__( 'Selected', 'gravityflow' ) . ')',
						);
					}

					foreach ( $inputs as $input ) {
						$fields[] = array(
							'value' => $input['id'],
							'label' => GFCommon::get_label( $field, $input['id'] ),
						);
					}
				} elseif ( $input_type == 'list' && $field->enableColumns && $field_is_valid_type && ! $exclude_field ) {
					$fields[]  = array(
						'value' => $field->id,
						'label' => GFCommon::get_label( $field ) . ' (' . esc_html__( 'Full', 'gravityflow' ) . ')',
					);
					$col_index = 0;
					foreach ( $field->choices as $column ) {
						$fields[] = array(
							'value' => $field->id . '.' . $col_index,
							'label' => GFCommon::get_label( $field ) . ' (' . esc_html( rgar( $column, 'text' ) ) . ')',
						);
						$col_index ++;
					}
				} elseif ( ! rgar( $field, 'displayOnly' ) && $field_is_valid_type && ! $exclude_field ) {
					$fields[] = array(
						'value' => $field->id,
						'label' => GFCommon::get_label( $field ),
					);
				}
			}

			$choices[] = array(
				'label'   => esc_html__( 'Form Fields', 'gravityflow' ),
				'choices' => $fields,
			);
		}

		// If field types not restricted add the default fields and entry meta.
		if ( is_null( $field_type ) ) {
			$choices[] = array(
				'label'   => esc_html__( 'Entry Properties', 'gravityflow' ),
				'choices' => array(
					array(
						'label' => esc_html__( 'Entry Date', 'gravityflow' ),
						'value' => 'date_created',
					),
					array(
						'label' => esc_html__( 'User IP', 'gravityflow' ),
						'value' => 'ip',
					),
					array(
						'label' => esc_html__( 'Source Url', 'gravityflow' ),
						'value' => 'source_url',
					),
					array(
						'label' => esc_html__( 'Created By', 'gravityflow' ),
						'value' => 'created_by',
					),
				),
			);

			$entry_meta = GFFormsModel::get_entry_meta( $form['id'] );

			if ( ! empty( $entry_meta ) ) {
				$meta_choices = array();

				foreach ( $entry_meta as $meta_key => $meta ) {
					$meta_choices[] = array(
						'value' => $meta_key,
						'label' => rgar( $meta, 'label' ),
					);
				}

				$choices[] = array(
					'label'   => esc_html__( 'Entry Meta', 'gravityflow' ),
					'choices' => $meta_choices,
				);
			}
		}

		return $choices;
	}


	/**
	 * Updates the entry with values from response header and body mappings.
	 *
	 * @since 2.9.10
	 *
	 * @param array $response The response array returned by wp_remote_request().
	 * @param array $entry    The entry to be updated.
	 *
	 * @return array The updated entry.
	 */
	public function process_response_mapping( $response, $entry ) {
		$update_entry = false;

		if ( $this->response_header === 'select_fields' && is_array( $this->response_header_mappings ) ) {
			$this->log_debug( __METHOD__ . '(): Attempting to map response headers.' );
			$data = wp_remote_retrieve_headers( $response );

			foreach ( $this->response_header_mappings as $mapping ) {
				if ( rgblank( $mapping['key'] ) ) {
					continue;
				}

				$update_entry = true;
				$entry        = $this->add_mapping_to_entry( $entry, $mapping, $data, array( 'type' => 'header' ) );
			}
		}

		if ( $this->response_body === 'select_fields' && is_array( $this->response_mappings ) ) {
			$this->log_debug( __METHOD__ . '(): Attempting to map response body.' );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( is_array( $data ) ) {
				foreach ( $this->response_mappings as $mapping ) {
					if ( rgblank( $mapping['key'] ) ) {
						continue;
					}

					$update_entry = true;
					$entry        = $this->add_mapping_to_entry( $entry, $mapping, $data, array( 'type' => 'body', 'subtype' => 'json' ) );
				}
			} else {
				$this->log_debug( __METHOD__ . '(): Response body does not include properly formatted JSON.' );
			}
		}

		if ( $update_entry ) {
			$result = GFAPI::update_entry( $entry );

			if ( is_wp_error( $result ) ) {
				$this->log_debug( __METHOD__ . '(): There was an issue with updating the entry.' );
			} else {
				$this->log_debug( __METHOD__ . '(): Updated entry: ' . print_r( $entry, true ) );
			}
		}

		return $entry;
	}

	/**
	 * Add the mapped value of the response to the entry.
	 *
	 * @since 2.9.10
	 *
	 * @param array $entry        The entry to update.
	 * @param array $mapping      The properties for the mapping being processed.
	 * @param array $data         The response params.
	 * @param array $content_type The content type of incoming request.
	 *
	 * @return array
	 */
	public function add_mapping_to_entry( $entry, $mapping, $data, $content_type ) {
		$skip_mapping = false;

		$source_field_id = trim( $mapping['custom_key'] );

		$target_field_id = (string) $mapping['value'];

		$value = $this->parse_response_value( $data, $source_field_id );

		if ( is_wp_error( $value ) ) {
			$this->log_debug( __METHOD__ . '(): ' . $value->get_error_message() );
			return $entry;
		}

		$form = $this->get_form();

		$target_field = GFFormsModel::get_field( $form, $target_field_id );

		if ( $target_field instanceof GF_Field ) {

			if ( in_array( $target_field->type, array( 'fileupload', 'post_title', 'post_content', 'post_excerpt', 'post_tags', 'post_category', 'post_image', 'post_custom_field', 'product', 'singleproduct', 'quantity', 'option', 'shipping', 'singleshipping', 'total' ) ) ) {
				$skip_mapping = true;
			} else {
				$this->log_debug( __METHOD__ . '(): Mapping into Field #' . $target_field->id . ' / Type: ' . $target_field->type );

				$is_full_target      = $target_field_id === (string) intval( $target_field_id );
				$target_field_inputs = $target_field->get_entry_inputs();

				// Non-Choice Field Type.
				if ( $is_full_target && ! is_array( $target_field_inputs ) ) {

					if ( rgar( $content_type, 'subtype' ) == 'json' && in_array( $target_field->type, array( 'multiselect', 'workflow_multi_user' ) ) ) {

						if ( ! is_array( $value ) ) {
							$value = json_decode( $value );
						}

						$entry[ $target_field_id ] = $target_field->get_value_save_entry( $value, $form, false, $entry['id'], $entry );

					} elseif ( in_array( $target_field->type, array( 'workflow_discussion' ) ) ) {
						$entry[ $target_field_id ] = $target_field->get_value_save_entry( $value, $form, false, $entry['id'], $entry );
					} else {
						$entry[ $target_field_id ] = $target_field->sanitize_entry_value( $value, $form['id'] );
					}

					// Choice Field Types.
				} elseif ( is_array( $target_field_inputs ) ) {

					// Received Parent Input ID.
					if ( $target_field_id == $target_field['id'] ) {

						if ( is_array( $value ) ) {
							$choices = $value;
						} else {
							$choices = json_decode( $value, true );
						}

						foreach ( $choices as $source_field ) {

							$entry[ $source_field['id'] ] = $target_field->sanitize_entry_value( $source_field['value'], $form['id'] );
						}

						// Received Direct Input ID.
					} else {
						foreach ( $target_field_inputs as $input ) {
							if ( $target_field_id === $input['id'] ) {
								$entry[ $target_field_id ] = $target_field->sanitize_entry_value( $value, $form['id'] );
								break;
							}
						}
					}
				} else {
					$skip_mapping = true;
				}
			}
		} else {
			$skip_mapping = true;
		}

		if ( $skip_mapping ) {
			if ( is_object( $target_field ) ) {
				$this->log_debug( __METHOD__ . '(): Field Type ' . $target_field->type . ' - Not available for import yet.' );
			} else {
				$this->log_debug( __METHOD__ . '(): Incoming field mapping error.' );
			}
		}

		$mapping_type = rgar( $content_type, 'type' );
		/**
		 * Allow the entry to be modified during the response mapping of the webhook step.
		 *
		 * @since 2.2.4-dev
		 * @since 2.9.7 Added the $mapping_type param.
		 * @since 2.9.10 Moved from Gravity_Flow_Step_Webhook::add_mapping_to_entry() and added support for the Salesforce.
		 *
		 * @param array             $entry        The entry currently being processed.
		 * @param array             $mapping      The mapping currently being processed.
		 * @param array             $data         The response headers or body data.
		 * @param Gravity_Flow_Step $this         The current step.
		 * @param string            $mapping_type The mapping type: header or body.
		 *
		 * @return array
		 */
		return apply_filters( "gravityflow_entry_{$this->get_type()}_response_mapping", $entry, $mapping, $data, $this, $mapping_type );
	}

	/**
	 * Parses the response value. Uses the backslash to drill down into arrays.
	 *
	 * @since 2.9.10
	 *
	 * @param array  $value     The response values.
	 * @param string $key       The key used to lookup the value.
	 * @param string $default   The default return value.
	 *
	 * @return array|string|WP_Error
	 */
	public function parse_response_value( $value, $key, $default = '' ) {

		if ( ! is_array( $value ) && ! ( is_object( $value ) && $value instanceof ArrayAccess ) ) {
			return $default;
		}

		/* translators: %s is the key used to lookup the value in the REST API response */
		$error_message = sprintf( __( 'The key %s does not match any element in the response.', 'gravityflow' ), $key ) ;

		$this->log_debug( __METHOD__ . '(): key before replacing variables: ' . $key );
		$key = GFCommon::replace_variables( $key, $this->get_form(), $this->get_entry(), true, false, false, 'text' );
		$this->log_debug( __METHOD__ . '(): key after replacing variables: ' . $key );

		if ( strpos( $key, '\\' ) === false ) {
			return isset( $value[ $key ] ) ? $value[ $key ] : new WP_Error( 'invalid_key', $error_message );
		}

		$names = explode( '\\', $key );
		if ( $names === false ) {
			return new WP_Error( 'invalid_key_parsing', $error_message );
		}
		$val = $value;
		foreach ( $names as $current_name ) {
			$val = rgar( $val, $current_name, $default );
		}

		return $val;
	}
}