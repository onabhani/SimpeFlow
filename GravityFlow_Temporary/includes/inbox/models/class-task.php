<?php

namespace Gravity_Flow\Gravity_Flow\Inbox\Models;

use Gravity_Flow\Gravity_Flow\Models\Model;
use \Gravity_Flow_API;
use \GFAPI;
use \GFFormsModel;

/**
 * The Tasks Model
 *
 * @since 2.8
 */
class Task implements Model {

	const WIDTH_XSMALL = 20;
	const WIDTH_SMALL  = 50;
	const WIDTH_MED    = 100;
	const WIDTH_FULL   = 150;
	const WIDTH_WIDE   = 200;

	private static $forms = array();

	/**
	 * @var Gravity_Flow_API $api
	 */
	private $api;

	/**
	 * @var GFAPI
	 */
	private $gf_api;

	/**
	 * @var array
	 */
	private $sc_args = array();

	/**
	 * Constructor
	 *
	 * @since 2.8
	 *
	 * @param Gravity_Flow_API $api
	 * @param GFAPI            $gf_api
	 *
	 * @return void
	 */
	public function __construct( Gravity_Flow_API $api, GFAPI $gf_api ) {
		$this->api    = $api;
		$this->gf_api = $gf_api;
	}

	/**
	 * Parse arguments.
	 *
	 * @since 2.8
	 *
	 * @param $args
	 *
	 * @return mixed
	 */
	private function parse_args( $args ) {
		$args = wp_parse_args( $args, $this->get_defaults() );

		/**
		 * Allow the inbox page arguments to be overridden.
		 *
		 * @param array $args The inbox page arguments.
		 */
		return apply_filters( 'gravityflow_inbox_args', $args );
	}

	/**
	 * Add args for a shortcode.
	 *
	 * @since 2.8
	 *
	 * @param $args
	 */
	public function add_args_for_shortcode( $args ) {
		$sc_id                   = $this->get_unique_grid_id_from_args( $args );
		$this->sc_args[ $sc_id ] = $args;
	}

	/**
	 * Get args for a given shortcode.
	 *
	 * @since 2.8
	 *
	 * @param false $id
	 *
	 * @return array|mixed
	 */
	public function get_args_for_shortcode( $id = false ) {
		if ( empty( $id ) ) {
			$id = $this->get_unique_grid_id_from_args( array() );
		}

		return isset( $this->sc_args[ $id ] ) ? $this->sc_args[ $id ] : array();
	}

	public function get_all_stored_shortcodes() {
		return $this->sc_args;
	}

	/**
	 * Get table columns.
	 *
	 * @since 2.8
	 *
	 * @param array $args
	 *
	 * @return mixed|void
	 */
	public function get_table_columns( $args = array() ) {
		$args = $this->parse_args( $args );

		$columns     = array();
		$legacy_mode = ! empty( $args['legacy'] );

		if ( $args['step_highlight'] ) {
			$columns['step_highlight'] = 'step_highlight';
		}

		$columns['id'] = __( 'ID', 'gravityflow' );

		if ( $args['actions_column'] ) {
			$columns['actions'] = '';
		}

		if ( empty( $args['form_id'] ) || is_array( $args['form_id'] ) ) {
			$columns['form_title'] = __( 'Form', 'gravityflow' );
		}

		if ( $args['submitter_column'] ) {
			$columns['created_by'] = __( 'Submitter', 'gravityflow' );
		}

		if ( $args['step_column'] ) {
			$columns['workflow_step'] = __( 'Step', 'gravityflow' );
		}

		$columns['fid'] = __( 'Form ID', 'gravityflow' );

		$columns['date_created']                = __( 'Submitted', 'gravityflow' );
		$columns['date_created_human_readable'] = __( 'Submitted HR', 'gravityflow' );

		$columns = \Gravity_Flow_Common::get_field_columns( $columns, $args['form_id'], $args['field_ids'], $legacy_mode );


		if ( $args['last_updated'] ) {
			$columns['last_updated']                = __( 'Last Updated', 'gravityflow' );
			$columns['last_updated_human_readable'] = __( 'Last Updated HR', 'gravityflow' );
		}

		if ( $args['due_date'] ) {
			$columns['due_date']                = __( 'Due Date', 'gravityflow' );
			$columns['due_date_human_readable'] = __( 'Due Date HR', 'gravityflow' );
		}

		/**
		 * Allows the columns to be filtered for the inbox table.
		 *
		 * @since 2.2.4-dev
		 *
		 * @param array $columns The columns to be filtered
		 * @param array $args    The array of args for this inbox table.
		 */
		return apply_filters( 'gravityflow_columns_inbox_table', $columns, $args );
	}

	/**
	 * Column Config Map
	 *
	 * @since 2.8
	 *
	 * @param $args
	 *
	 * @return array
	 */
	private function column_config_map( $args ) {
		$map = array(
			'id'                          => array(
				'compareType'     => 'int',
				'width'           => 80,
				'minWidth'        => 80,
				'resizable'       => false,
				'filter'          => false,
				'suppressMovable' => true,
				'cellRenderer'    => 'cellLinkRenderer',
				'display'         => isset( $args['id_column'] ) ? $args['id_column'] : true,
			),
			'actions'                     => array(
				'width'        => 60,
				'minWidth'     => 60,
				'resizable'    => false,
				'cellRenderer' => 'actionsRenderer',
				'filter'       => false,
			),
			'form_title'                  => array(
				'minWidth'     => self::WIDTH_WIDE,
				'cellRenderer' => 'cellLinkRenderer',
			),
			'created_by'                  => array(
				'minWidth'     => self::WIDTH_FULL,
				'cellRenderer' => 'cellLinkRenderer',
			),
			'workflow_step'               => array(
				'minWidth'     => self::WIDTH_FULL,
				'cellRenderer' => 'cellLinkRenderer',
			),
			'date_created'                => array(
				'displayKey'   => 'date_created_human_readable',
				'compareType'  => 'date',
				'minWidth'     => self::WIDTH_FULL,
				'cellRenderer' => 'cellLinkRenderer',
			),
			'last_updated'                => array(
				'displayKey'   => 'last_updated_human_readable',
				'compareType'  => 'date',
				'minWidth'     => self::WIDTH_FULL,
				'cellRenderer' => 'cellLinkRenderer',
			),
			'due_date'                    => array(
				'displayKey'   => 'due_date_human_readable',
				'compareType'  => 'date',
				'minWidth'     => self::WIDTH_FULL,
				'cellRenderer' => 'cellLinkRenderer',
			),
			'due_date_human_readable'     => array(
				'display'  => false,
				'minWidth' => self::WIDTH_FULL,
			),
			'date_created_human_readable' => array(
				'display'  => false,
				'minWidth' => self::WIDTH_FULL,
			),
			'last_updated_human_readable' => array(
				'display'  => false,
				'minWidth' => self::WIDTH_FULL,
			),
			'fid'                         => array(
				'display'        => false,
				'minWidth'       => self::WIDTH_FULL,
			),
			'step_highlight'              => array(
				'display'  => false,
				'minWidth' => self::WIDTH_FULL,
			),
		);

		return $map;
	}

	/**
	 * Logic to determine if a column should support html in the inbox grid.
	 *
	 * @since 2.8.3
	 *
	 * @param $map
	 * @param $name
	 *
	 * @return bool
	 */
	private function column_supports_html( $map, $name ) {
		return ! isset( $map[ $name ] );
	}

	/**
	 * Apply the configuration for a column to the given columns.
	 *
	 * @since 2.8
	 *
	 * @param $columns
	 * @param $args
	 *
	 * @return mixed
	 */
	private function apply_config_to_columns( $columns, $args ) {
		$map = $this->column_config_map( $args );

		array_walk( $columns, function ( &$data, $name ) use ( $map, $args ) {
			$name_sanitized = $this->sanitize_input_id( $name );
			$defaults       = array(
				'headerName'  => $data,
				'field'       => $name_sanitized,
				'filter'      => 'agTextColumnFilter',
				'minWidth'    => self::WIDTH_MED,
				'sortable'    => true,
				'compareType' => 'string',
				'displayKey'  => $name_sanitized,
				'resizable'   => true,
				'display'     => strpos( $name, '_human_readable' ) === false,
			);

			$values = isset( $map[ $name ] ) ? $map[ $name ] : array();

			if ( is_numeric( $name ) && empty( $values ) ) {
				$values = $this->get_config_values_for_field( $name, $args['form_id'] );
			}

			if ( $this->column_supports_html( $map, $name ) ) {
				$values = wp_parse_args( $values, array(
					'autoHeight'     => true,
					'cellClassRules' => 'cellClassRules',
					'cellRenderer'   => 'cellLinkRenderer',
					'wrapText'       => true,
				) );
			}

			$data = wp_parse_args( $values, $defaults );
		} );

		return $columns;
	}

	/**
	 * Get config values for a given field.
	 *
	 * @since 2.8
	 *
	 * @param $field_id
	 * @param $form_id
	 *
	 * @return array
	 */
	private function get_config_values_for_field( $field_id, $form_id ) {
		$field = GFFormsModel::get_field( $form_id, $field_id );

		if ( ! is_object( $field ) ) {
			return array();
		}

		$config = array();

		if ( $field->type === 'date' ) {
			$config['compareType'] = 'date';
			$config['displayKey']  = $field_id . '_human_readable';
		}

		return $config;
	}

	/**
	 * Get the table header definitions.
	 *
	 * @since 2.8
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public function get_table_header_defs( $args = array() ) {
		$headers = array();
		$columns = $this->get_table_columns( $args );
		$columns = $this->apply_config_to_columns( $columns, $args );

		foreach ( $columns as $name => $data ) {
			if ( ! $data['display'] ) {
				continue;
			}

			$headers[] = $data;
		}

		return $headers;
	}

	/**
	 * Get the value for display in the current column for the entry being processed.
	 *
	 * @since 2.8
	 *
	 * @param string $id      The column id, the key to the value in the entry or form.
	 * @param array  $form    The form object for the current entry.
	 * @param array  $entry   The entry currently being processed for display.
	 * @param array  $columns The columns to be displayed.
	 *
	 * @return string
	 */
	private function get_column_value( $id, $form, $entry, $columns ) {
		$value = '';
		switch ( strtolower( $id ) ) {
			case 'step_highlight':
				$step_highlight_color = '';
				if ( array_key_exists( 'step_highlight', $columns ) && isset( $entry['workflow_step'] ) ) {
					$step = gravity_flow()->get_step( $entry['workflow_step'] );
					if ( $step ) {
						if ( $step->step_highlight && $step->step_highlight_type == 'color' && preg_match( '/^#[a-f0-9]{6}$/i', $step->step_highlight_color ) ) {
							$step_highlight_color = $step->step_highlight_color;
						}
					}
				}

				if ( isset( $entry['workflow_step'] ) ) {
					$step = gravity_flow()->get_step( $entry['workflow_step'], $entry );
					if ( $step && $step->due_date && $step->is_overdue() && $step->due_date_highlight_type == 'color' && preg_match( '/^#[a-f0-9]{6}$/i', $step->due_date_highlight_color ) ) {
						$step_highlight_color = $step->due_date_highlight_color;
					}
				}
				/**
				 * Allow the Step Highlight colour to be overridden.
				 *
				 * @since 1.9.2
				 *
				 * @param string $step_highlight_color The highlight color (hex value) of the row currently being processed.
				 * @param int    $form                 ['id'] The ID of form currently being processed.
				 * @param array  $entry                The entry object for the row currently being processed.
				 *
				 * @return string
				 */
				$value = apply_filters( 'gravityflow_step_highlight_color_inbox', $step_highlight_color, $form['id'], $entry );
				break;
			case 'form_title':
				$value = rgar( $form, 'title' );
				break;
			case 'created_by':
				$user           = get_user_by( 'id', (int) $entry['created_by'] );
				$submitter_name = $user ? $user->display_name : $entry['ip'];

				/**
				 * Allow the value displayed in the Submitter column to be overridden.
				 *
				 * @param string $submitter_name The display_name of the logged-in user who submitted the form or the guest ip address.
				 * @param array  $entry          The entry object for the row currently being processed.
				 * @param array  $form           The form object for the current entry.
				 */
				$value = apply_filters( 'gravityflow_inbox_submitter_name', $submitter_name, $entry, $form );
				break;
			case 'date_created_human_readable':
				$date_created = \Gravity_Flow_Common::format_date( $entry['date_created'], '', true, true );
				$value        = $date_created;
				break;
			case 'date_created':
				$value = strtotime( $entry['date_created'] );
				break;

			case 'last_updated_human_readable':
				$last_updated = date( 'Y-m-d H:i:s', $entry['workflow_timestamp'] );
				$value        = $entry['date_created'] != $last_updated ? \Gravity_Flow_Common::format_date( $last_updated, '', true, true ) : '-';
				break;
			case 'last_updated':
				$value = (int) $entry['workflow_timestamp'];
				break;

			case 'workflow_step':
				if ( isset( $entry['workflow_step'] ) ) {
					$step = gravity_flow()->get_step( $entry['workflow_step'] );
					if ( $step ) {
						return $step->get_name();
					}
				}

				$value = '';
				break;
			case 'actions':
				$api  = new Gravity_Flow_API( $form['id'] );
				$step = $api->get_current_step( $entry );
				if ( $step ) {
					$value = $this->format_actions( $step );
				}
				break;
			case 'payment_status':
				$value = rgar( $entry, 'payment_status' );
				if ( gravity_flow()->is_gravityforms_supported( '2.4' ) ) {
					$value = \GFCommon::get_entry_payment_status_text( $value );
				}
				break;

			case 'due_date_human_readable':
				$api  = new Gravity_Flow_API( $form['id'] );
				$step = $api->get_current_step( $entry );
				if ( $step && $step->due_date ) {
					$value = \Gravity_Flow_Common::format_date( date( 'Y-m-d H:i:s', $step->get_due_date_timestamp() ), '', true, true );
				} else {
					$value = '-';
				}
				break;
			case 'due_date':
				$api  = new Gravity_Flow_API( $form['id'] );
				$step = $api->get_current_step( $entry );
				if ( $step && $step->due_date ) {
					$value = $step->get_due_date_timestamp();
				} else {
					$value = 0;
				}
				break;
			case 'fid':
				$value = $form['id'];
				break;

			default:
				$field = \GFFormsModel::get_field( $form, $id );

				if ( is_object( $field ) ) {
					require_once( \GFCommon::get_base_path() . '/entry_list.php' );
					$value = $field->get_value_entry_list( rgar( $entry, $id ), $entry, $id, $columns, $form );
				} else {
					$value = rgar( $entry, $id );
				}

				$value = apply_filters( 'gform_entries_field_value', $value, $form['id'], $id, $entry );

		}

		/**
		 * Allows the inbox value to be filtered before displaying in a grid.
		 *
		 * @since 2.8.2
		 *
		 * @param mixed $value
		 * @param int   $form_id
		 * @param int   $field_id
		 * @param array $entry
		 *
		 * @return mixed
		 */
		$value = apply_filters( 'gravityflow_inbox_field_value', $value, $form['id'], $id, $entry );

		return $value;
	}

	/**
	 * Get the correct values for any fields which are dates.
	 *
	 * @since  2.8
	 *
	 * @param $value
	 * @param $form_id
	 * @param $id
	 * @param $entry
	 *
	 * @filter gform_entries_field_value 10, 4
	 *
	 * @return mixed
	 */
	public function get_date_field_values( $value, $form_id, $id, $entry ) {
		if ( strpos( $id, '_human_readable' ) !== false ) {
			$field_id = str_replace( '_human_readable', '', $id );
			$field    = \GFFormsModel::get_field( $form_id, $field_id );

			if ( is_object( $field ) ) {
				return $field->get_value_entry_list( rgar( $entry, $field_id ), $entry, $field_id, array(), $form_id );
			} else {
				return $value;
			}
		}

		$field = \GFFormsModel::get_field( $form_id, $id );

		if ( is_object( $field ) && $field->type === 'date' ) {

			// strtotime does not understand dd/mm/YYYY unless it uses dashes.
			if ( $field->dateFormat === 'dmy' ) {
				$value = str_replace( '/', '-', $value );
			}

			return strtotime( $value );
		}

		return $value;
	}

	/**
	 * Formats the actions for the action column.
	 *
	 * @since 2.8
	 *
	 * @param \Gravity_Flow_Step $step The current step.
	 *
	 * @return array
	 */
	public static function format_actions( $step ) {
		return array(
			'entryId' => $step->get_entry_id(),
			'actions' => $step->get_actions(),
		);
	}

	/**
	 * Get the default arguments used when rendering the inbox page.
	 *
	 * @since 2.8
	 *
	 * @return array
	 */
	private function get_defaults() {
		$field_ids = apply_filters( 'gravityflow_inbox_fields', array() );

		$filter = apply_filters( 'gravityflow_inbox_filter', array(
			'form_id'    => 0,
			'start_date' => '',
			'end_date'   => '',
		) );

		return array(
			'display_empty_fields' => true,
			'id_column'            => true,
			'submitter_column'     => true,
			'actions_column'       => false,
			'step_column'          => true,
			'check_permissions'    => true,
			'form_id'              => absint( rgar( $filter, 'form_id' ) ),
			'field_ids'            => $field_ids,
			'detail_base_url'      => admin_url( 'admin.php?page=gravityflow-inbox&view=entry' ),
			'last_updated'         => false,
			'due_date'             => false,
			'step_highlight'       => true,
			'paging'               => array(
				'page_size' => 999999,
			),
		);

	}

	/**
	 * Get a unique Grid ID from the given arguments.
	 *
	 * @since 2.8
	 *
	 * @param $args
	 *
	 * @return string
	 */
	public function get_unique_grid_id_from_args( $args ) {
		$grid     = 'inbox';
		$type     = 'default';
		$location = 'admin';

		if ( is_admin() ) {
			$user_id          = get_current_user_id();
			$user             = sprintf( 'user_id_%s', $user_id );
			$args['is_admin'] = true;
		} else {
			$location   = get_the_ID();
			$filter_key = $this->api->get_inbox_filter_key( $args );
			$assignee   = $this->get_assignee_from_filter_key( $filter_key );
			$user       = sprintf( '%s_%s', $assignee->get_type(), $assignee->get_id() );
		}

		if ( isset( $args['is_shortcode'] ) && $args['is_shortcode'] ) {
			$type = 'shortcode';
		}

		if ( isset( $args['is_block'] ) && $args['is_block'] ) {
			$type = 'block';
		}

		$full_args = array_merge( \Gravity_Flow_Inbox::get_defaults(), $args );
		$full_args = apply_filters( 'gravityflow_inbox_args', $full_args );

		ksort( $full_args );

		$encoded = json_encode( $full_args );

		$uuid = hash( 'adler32', $encoded );

		return sprintf( '%s_%s_%s_%s_%s', $user, $grid, $type, $location, $uuid );
	}

	/**
	 * Get the filter key for a given set of arguments.
	 *
	 * @since 2.8
	 *
	 * @param $args
	 *
	 * @return string
	 */
	public function get_filter_key_for_args( $args ) {
		return $this->api->get_inbox_filter_key( $args );
	}

	/**
	 * Get the unique assignee from a filter key.
	 *
	 * @since 2.8
	 *
	 * @param $key
	 *
	 * @return \Gravity_Flow_Assignee
	 */
	public function get_assignee_from_filter_key( $key ) {
		$key = str_replace( 'workflow_', '', $key );

		if ( empty( $key ) ) {
			return new \Gravity_Flow_Assignee();
		}

		$parts = explode( '_', $key );
		$user  = false;

		if ( $parts[0] === 'user' ) {
			$parts = array(
				'user_id',
				$parts[2],
			);
			$user  = get_user_by( 'id', $parts[1] );
		}

		return new \Gravity_Flow_Assignee(
			array(
				'id'   => $parts[1],
				'user' => $user,
				'type' => $parts[0],
			)
		);
	}

	/**
	 * Get the inbox tasks for the set of arguments.
	 *
	 * @since 2.8
	 *
	 * @param $args
	 *
	 * @return array
	 */
	public function get_inbox_tasks( $args ) {
		$args = $this->parse_args( $args );

		$tasks = array();

		$entries = $this->api->get_inbox_entries( $args );

		if ( empty( $entries ) ) {
			return $tasks;
		}

		$columns = $this->get_table_columns( $args );

		foreach ( $entries as $entry ) {
			$tasks[] = $this->get_data_for_row( $args, $entry, $columns );
		}

		return $tasks;
	}

	/**
	 * Get form by ID.
	 *
	 * @since 2.8
	 *
	 * @param $form_id
	 *
	 * @return false|mixed
	 */
	private function get_form( $form_id ) {
		if ( isset( self::$forms[ $form_id ] ) ) {
			return self::$forms[ $form_id ];
		}

		self::$forms[ $form_id ] = $this->gf_api->get_form( $form_id );

		return self::$forms[ $form_id ];
	}

	/**
	 * Get the data for a given row.
	 *
	 * @since 2.8
	 *
	 * @param $args
	 * @param $entry
	 * @param $columns
	 *
	 * @return array
	 */
	private function get_data_for_row( $args, $entry, $columns ) {
		$data      = array();
		$form      = $this->get_form( $entry['form_id'] );
		$url_entry = esc_url_raw( sprintf( '%s&id=%d&lid=%d', $args['detail_base_url'], $entry['form_id'], $entry['id'] ) );

		/**
		 * Allows the entry URL to be modified for each of the entries in the inbox table.
		 *
		 * @since 2.5.6
		 *
		 * @param string $url_entry The entry URL.
		 * @param string $entry     The current entry.
		 * @param string $args      The inbox page arguments.
		 * @param array  $form      The form object for the current entry.
		 *
		 * @return string
		 */

		$data['url_entry'] = apply_filters( 'gravityflow_entry_url_inbox_table', $url_entry, $entry, $args, $form );

		foreach ( $columns as $name => $label ) {
			$data[ $this->sanitize_input_id( $name ) ] = $this->get_column_value( $name, $form, $entry, $columns );
		}

		return $data;
	}

	/**
	 * Sanitize the Input ID to replace underscores with dots.
	 *
	 * @since 2.8
	 *
	 * @param $name
	 *
	 * @return array|string|string[]
	 */
	private function sanitize_input_id( $name ) {
		return str_replace( '.', '_', $name );
	}

}
