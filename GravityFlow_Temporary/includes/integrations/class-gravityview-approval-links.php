<?php
/**
 * Integrates the Approval Links Merge Tag to GravityView.
 *
 * @package     GravityFlow
 * @copyright   Copyright (c) 2015-2022, Steven Henty S.L.
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       2.7.8
 */

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

/**
 * Integrates the Approval Links for an Approval Step to GravityView.
 *
 * @copyright   Copyright (c) 2015-2022, Steven Henty S.L.
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 */
class Gravity_Flow_GravityView_Approval_Links extends GravityView_Field {

	/**
	 * The name of the GravityView field type.
	 *
	 * @var string
	 */
	var $name = 'workflow_approval_links';

	/**
	 * The contexts in which a field is available.
	 *
	 * @var array
	 */
	var $contexts = array( 'multiple' );

	/**
	 * Can the field be sorted in search?
	 *
	 * @var bool
	 */
	var $is_sortable = false;

	/**
	 * Can the field be searched?
	 *
	 * @var bool
	 */
	var $is_searchable = false;

	/**
	 * The group this field belongs to.
	 *
	 * @var string
	 */
	var $group = 'meta';

	var $icon = 'dashicons-yes-alt';

	/**
	 * Gravity_Flow_GravityView_Approval_Links constructor.
	 */
	public function __construct() {
		$this->label = esc_html__( 'Workflow Approval Links', 'gravityflow' );

		$this->add_hooks();

		parent::__construct();
	}

	/**
	 * Adds hooks for GravityView.
	 *
	 * @since 2.7.8
	 */
	private function add_hooks() {
		add_filter( 'gravityview_entry_default_fields', array( $this, 'add_entry_default_field' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_scripts_and_styles' ) );
		add_filter( 'gravityview_template_workflow_approval_links_options', array( $this, 'approval_links_field_options' ), 10, 5 );
		add_filter( 'gravityview_field_entry_value_workflow_approval_links', array( $this, 'modify_entry_value_workflow_approval_links' ), 10, 4 );
	}

	/**
	 * Add Approval Links to the Add Field picker in Edit View
	 *
	 * @see   GravityView_Admin_Views::get_entry_default_fields()
	 *
	 * @since 2.7.8
	 *
	 * @param array  $entry_default_fields Fields configured to show in the picker.
	 * @param array  $form                 Gravity Forms form array.
	 * @param string $zone                 Current context: `directory`, `single`, `edit`.
	 *
	 * @return array Fields array with notes added, if in Multiple Entries or Single Entry context.
	 */
	public function add_entry_default_field( $entry_default_fields, $form, $zone ) {

		if ( in_array( $zone, array( 'directory' ) ) ) {
			$entry_default_fields['workflow_approval_links'] = array(
				'label' => __( 'Workflow Approval Links', 'gravityflow' ),
				'type'  => $this->name,
				'desc'  => __( 'Display link(s) to approve/reject/revert the entry when the entry is on an approval step.', 'gravityflow' ),
			);
		}

		return $entry_default_fields;
	}

	/**
	 * Register the scripts/styles field expects to use.
	 *
	 * @since 2.7.8
	 *
	 * @return void
	 */
	public function register_scripts_and_styles() {
		$script_debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_enqueue_style( 'gform_font_awesome', GFCommon::get_base_url() . "/css/font-awesome' . $script_debug . '.css", null, GRAVITY_FLOW_VERSION );
		wp_register_style( 'gravityview-field-workflow-approval-links', gravity_flow()->get_base_url() . '/includes/integrations/css/gravity-view-flow-fields.css', array(), gravity_flow()->get_version(), 'screen' );
		wp_enqueue_style( 'gravityview-field-workflow-approval-links' );
	}

	/**
	 * Add settings to the approval_links field settings
	 *
	 * @since 2.7.8
	 *
	 * @param array  $field_options Settings for the particular GV field.
	 * @param string $template_id   The current slug of the selected View template.
	 * @param int    $field_id      The internal Gravity Forms field ID.
	 * @param string $context       Current context: `directory`, `single`, `edit`.
	 * @param string $input_type    This is a thing.
	 *
	 * @return array $field_options
	 */
	public function approval_links_field_options( $field_options, $template_id, $field_id, $context, $input_type ) {

		// Always a link, never a filter.
		unset( $field_options['show_as_link'], $field_options['search_filter'] );

		$add_option['new_window'] = array(
			'type'     => 'checkbox',
			'label'    => __( 'Open links in a new tab or window?', 'gravityflow' ),
			'value'    => false,
			'group'    => 'display',
			'priority' => 1300,
		);
		$add_option['approve_link_text'] = array(
			'type'       => 'text',
			'label'      => __( 'Approve Link Text', 'gravityflow' ),
			'desc'       => null,
			'value'      => __( 'Approve', 'gravityflow' ),
			'merge_tags' => true,
		);
		$add_option['reject_link_text'] = array(
			'type'       => 'text',
			'label'      => __( 'Reject Link Text', 'gravityflow' ),
			'desc'       => null,
			'value'      => __( 'Reject', 'gravityflow' ),
			'merge_tags' => true,
		);
		return array_merge( $add_option, $field_options );
	}

	/**
	 * Generate the approval links.
	 *
	 * @since 2.7.8
	 *
	 * @param string $output         HTML value output.
	 * @param array  $entry          The GF entry array.
	 * @param array  $field_settings Settings for the particular GV field.
	 * @param array  $field          Current field being displayed.
	 *
	 * @return string
	 */
	public function modify_entry_value_workflow_approval_links( $output, $entry, $field_settings, $field ) {

		$api  = new Gravity_Flow_API( $entry['form_id'] );
		$step = $api->get_current_step( $entry );

		// Ensure links only display for approval step.
		if ( ! $step || $step->get_type() !== 'approval' ) {
			return $output;
		}

		$current_assignee = false;
		$assignees        = $step->get_assignees();

		// Ensure current user is an assignee with pending approval decision.
		foreach ( $assignees as $assignee ) {
			if ( $assignee->is_current_user() && $assignee->get_status() === 'pending' ) {
				$current_assignee = true;
				break;
			}
		}

		if ( ! $current_assignee ) {
			return $output;
		}

		// Build the approval/reject link(s) based on merge tags.
		$approve_url = GFCommon::replace_variables( '{workflow_approve_url}', $step->get_form(), $entry, false, false, false, 'text' );
		$reject_url  = GFCommon::replace_variables( '{workflow_reject_url}', $step->get_form(), $entry, false, false, false, 'text' );

		$approve_text = empty( $field_settings['approve_link_text'] ) ? __( 'Approve', 'gravityflow' ) : $field_settings['approve_link_text'];
		$reject_text  = empty( $field_settings['reject_link_text'] ) ? __( 'Reject', 'gravityflow' ) : $field_settings['reject_link_text'];

		$link_type = boolval( $field_settings['new_window'] ) ? 'target="_blank"' : '';

		$output  = sprintf( '<a href="%s" class="flow_approval_links" title="%s" %s><i class="fa fa-check approval_link"></i></a>', $approve_url, $approve_text, $link_type );
		$output .= sprintf( '<a href="%s" class="flow_approval_links" title="%s" %s><i class="fa fa-times reject_link"></i></a>', $reject_url, $reject_text, $link_type );

		return $output;
	}

}

new Gravity_Flow_GravityView_Approval_Links;
