<?php
/**
 * Integrations for Gravity Flow with GravityView actions and filters
 *
 * @package     GravityFlow
 * @copyright   Copyright (c) 2015-2022, Steven Henty S.L.
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       2.7.7
 */

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

/**
 * Class Gravity_Flow_GravityView_Hooks
 *
 * Integrations for Gravity Flow with GravityView actions and filters
 */
class Gravity_Flow_GravityView_Hooks extends Gravity_Flow {

	/**
	 * Gravity_Flow_GravityView_Hooks constructor.
	 */
	public function __construct() {
		$this->add_hooks();
	}

	/**
	 * Adds hooks for GravityView.
	 *
	 * @since 2.7.7
	 */
	private function add_hooks() {
		add_filter( 'gk/query-filters/field-filters', array( $this, 'filter_gravityview_adv_filter_field_filters' ), 10, 2 );
		add_filter( 'gravityview_search_criteria', array( $this, 'filter_gravityview_search_criteria' ), 999, 3 ); // Advanced Filter v1.0.
		add_filter( 'gk/advanced-filters/filters', array( $this, 'filter_gravityview_adv_filter_filters' ), 999, 2 ); // GravityView 2.11+.
		add_filter( 'gravityview/common/get_entry/check_entry_display', array( $this, 'filter_gravityview_common_get_entry_check_entry_display' ), 999, 2 );
		add_action( 'gravityview/edit_entry/after_update', array( $this, 'action_gravityview_user_input' ), 999, 2 );
	}

	/**
	 * Target for the gravityview_adv_filter_field_filters filter.
	 *
	 * Adds the Gravity Flow assignees as field filters.
	 *
	 * @since 1.5.1-dev
	 *
	 * @param array $field_filters The field filters used by GravityView.
	 * @param int   $form_id       The form ID.
	 *
	 * @return array
	 */
	public function filter_gravityview_adv_filter_field_filters( $field_filters, $form_id ) {
		$steps = $this->get_steps( $form_id );

		$workflow_assignees = array();

		foreach ( $steps as $step ) {
			if ( empty( $step ) || ! $step->is_active() ) {
				continue;
			}

			$step_assignees = $step->get_assignees();

			$step_assignee_choices = array();

			foreach ( $step_assignees as $assignee ) {
				$step_assignee_choices[] = array(
					'value' => $assignee->get_key(),
					'text'  => $assignee->get_display_name(),
				);
			}

			$workflow_assignees = array_merge( $workflow_assignees, $step_assignee_choices );
		}
		// Remove duplicate assignees.
		$workflow_assignees = array_map( 'unserialize', array_unique( array_map( 'serialize', $workflow_assignees ) ) );
		$workflow_assignees = array_values( $workflow_assignees );

		$workflow_assignees[] = array(
			'value' => 'current_user',
			'text'  => esc_html__( 'Current User', 'gravityflow' ),
		);

		$filter                    = array();
		$filter['key']             = 'workflow_assignee';
		$filter['preventMultiple'] = false;
		$filter['text']            = esc_html__( 'Workflow Assignee', 'gravityflow' );
		$filter['operators']       = array( 'is' );
		$filter['values']          = $workflow_assignees;
		$field_filters[]           = $filter;

		return $field_filters;
	}

	/**
	 * Target for the gravityview_search_criteria filter.
	 *
	 * @since 1.5.1-dev
	 *
	 * @param array $search_criteria Search criteria used by GravityView.
	 * @param array $form_ids        Forms to search.
	 * @param int   $view_id         ID of the view being used to search.
	 *
	 * @return array
	 */
	public function filter_gravityview_search_criteria( $search_criteria, $form_ids, $view_id ) {
		if ( isset( $search_criteria['search_criteria']['field_filters'] ) && is_array( $search_criteria['search_criteria']['field_filters'] ) ) {
			$field_filters = $search_criteria['search_criteria']['field_filters'];
			foreach ( $field_filters as &$field_filter ) {
				if ( is_array( $field_filter ) && isset( $field_filter['key'] ) && $field_filter['key'] == 'workflow_assignee' ) {
					$assignee_key          = $field_filter['value'] == 'current_user' ? gravity_flow()->get_current_user_assignee_key() : $field_filter['value'];
					$field_filter['key']   = 'workflow_' . str_replace( '|', '_', $assignee_key );
					$field_filter['value'] = 'pending';
				}
			}
			$search_criteria['search_criteria']['field_filters'] = $field_filters;
		}

		return $search_criteria;
	}

	/**
	 * Target for the `gk/advanced-filters/filters` filter.
	 *
	 * @since 2.5.11
	 *
	 * @param array|null $filters Search filters used by GravityView.
	 * @param \GV\View   $view    GravityView View object.
	 *
	 * @return array
	 */
	public function filter_gravityview_adv_filter_filters( $filters, $view ) {

		$modify_filter_conditions = function ( &$filters ) use ( &$modify_filter_conditions ) {

			foreach ( $filters['conditions'] as &$filter_condition ) {
				if ( ! empty( $filter_condition['conditions'] ) ) {
					$modify_filter_conditions( $filter_condition );
				}

				if ( ! empty( $filter_condition['key'] ) && ! empty( $filter_condition['value'] ) && 'workflow_assignee' === $filter_condition['key'] ) {
					$assignee_key              = ( 'current_user' === $filter_condition['value'] ) ? gravity_flow()->get_current_user_assignee_key() : $filter_condition['value'];
					$filter_condition['key']   = 'workflow_' . str_replace( '|', '_', $assignee_key );
					$filter_condition['value'] = 'pending';
				}
			}

			return $filters;
		};

		return ! empty( $filters['conditions'] ) ? $modify_filter_conditions( $filters ) : $filters;
	}

	/**
	 * Target for the gravityview/common/get_entry/check_entry_display filter.
	 *
	 * Performs the permission check if a Gravity Flow assignee key is specified in the criteria.
	 *
	 * @since 1.5.1-dev
	 *
	 * @param bool  $check_entry_display Check whether the entry is visible for the current View configuration. Default: true.
	 * @param array $entry               The current entry.
	 *
	 * @return bool
	 */
	public function filter_gravityview_common_get_entry_check_entry_display( $check_entry_display, $entry ) {

		global $_fields;

		$criteria = GVCommon::calculate_get_entries_criteria();

		$keys = array();

		// Add the workflow assignee entry meta to the entry.
		// This is necessary because assignee meta keys are not registered so they're not added automatically to the entry.
		if ( isset( $criteria['search_criteria']['field_filters'] ) && is_array( $criteria['search_criteria']['field_filters'] ) ) {
			foreach ( $criteria['search_criteria']['field_filters'] as $filter ) {
				if ( is_array( $filter ) && strpos( $filter['key'], 'workflow_' ) !== false && ! isset( $entry[ $filter['key'] ] ) ) {
					$meta_value              = gform_get_meta( $entry['id'], $filter['key'] );
					$entry[ $filter['key'] ] = $meta_value;
					$keys[]                  = $filter['key'];
				}
			}
		}

		if ( empty( $keys ) ) {
			return $check_entry_display;
		}

		$form_id = $entry['form_id'];
		// Hack to ensure that the meta values for assignees are returned when rule matching in GVCommon::check_entry_display().
		foreach ( $keys as $key ) {
			$_fields[ $form_id . '_' . $key ] = array( 'id' => $key );
		}

		$entry = GVCommon::check_entry_display( $entry );

		// Clean up the hack.
		foreach ( $keys as $key ) {
			unset( $_fields[ $form_id . '_' . $key ] );
		}

		// GVCommon::check_entry_display() returns the entry if permission is granted otherwise false or maybe a WP_Error instance.
		// If permission is granted then we can tell GravityView not to check permissions again.
		$check_entry_display = ! $entry || is_wp_error( $entry );

		return $check_entry_display;
	}

	/**
	 * Target for the gravityview/edit_entry/after_update action.
	 *
	 * Process any current user input steps for the assignee/entry on GravityView edit activity.
	 *
	 * @since 2.7.7
	 *
	 * @param array $form     The current form.
	 * @param int   $entry_id The current entry id.
	 *
	 * @return void
	 */
	public function action_gravityview_user_input( $form, $entry_id ) {

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			return;
		}

		// If the edited entry has matching step/assignee, this function will update assignee status and attempt to process the workflow. Disabling async feeds to avoid duplicate or race conditions.
		add_filter( 'gform_is_feed_asynchronous', '__return_false', 9999999 );
		$api    = new Gravity_Flow_API( $form['id'] );
		$status = $api->get_status( $entry );
		if ( $status !== 'pending' ) {
			return;
		}
		$step                  = $api->get_current_step( $entry );
		$previous_assignees    = $step && $step instanceof Gravity_Flow_Step ? $step->get_assignees() : array();
		$step_assignee_updates = false; // Track whether any view based edits initiated user input step assignee actions.

		$app_settings = $this->get_app_settings();

		$allow_user_input_setting = boolval( rgar( $app_settings, 'gravityview_allow_edit_user_input' ) );

		foreach ( $previous_assignees as $assignee ) {
			if ( $assignee->is_current_user() ) {
				/**
				 * Allows a GravityView edit to trigger completion of assignee's user input step. Default true/false is based on Workflow > Settings > Advanced > GravityView Integrations setting.
				 *
				 * @since 2.7.7
				 *
				 * @param array                 $bool         Whether to trigger the User Input step for the matching assignee.
				 * @param array                 $entry        The current entry.
				 * @param array                 $form         The current form.
				 * @param Gravity_Flow_Step     $current_step The current step for this entry.
				 * @param Gravity_Flow_Assignee $assignee     The current assignee.
				 */
				$process_assignee_status = apply_filters( 'gravityflow_user_input_by_view_edit', $allow_user_input_setting, $entry, $form, $step, $assignee );

				if ( $process_assignee_status ) {
					$step_assignee_updates = true;
					gravity_flow()->log_debug( 'gravityview/edit_entry/after_update: triggering assignee user input.' );
					$step->process_assignee_status( $assignee, 'complete', $form );
				}
				break;
			}
		}

		if ( $step_assignee_updates ) {
			$step->refresh_entry();
			gravity_flow()->log_debug( 'gravityview/approve_entries/updated: triggering workflow.' );
			$api->process_workflow( $entry_id );
		}
		remove_filter( 'gform_is_feed_asynchronous', '__return_false', 9999999 );
	}
}

new Gravity_Flow_GravityView_Hooks();
