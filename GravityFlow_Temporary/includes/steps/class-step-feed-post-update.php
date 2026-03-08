<?php
/**
 * Gravity Flow Step Feed Post Update
 *
 * @package     GravityFlow
 * @subpackage  Classes/Gravity_Flow_Step_Feed_Post_Update
 * @copyright   Copyright (c) 2016-2022, Steven Henty S.L.
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       2.7.9
 */

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

/**
 * Class Gravity_Flow_Step_Feed_Post_Update
 */
class Gravity_Flow_Step_Feed_Post_Update extends Gravity_Flow_Step_Feed_Add_On {

	/**
	 * The step type.
	 *
	 * @since 2.7.9
	 *
	 * @var string
	 */
	public $_step_type = 'post_update';

	/**
	 * The name of the class used by the add-on.
	 *
	 * @since 2.7.9
	 *
	 * @var string
	 */
	protected $_class_name = 'GF_Advanced_Post_Creation';


	/**
	 * Determines if this step can be used on this site. APC v1.0+ is required.
	 *
	 * @since 2.7.9
	 *
	 * @return bool
	 */
	public function is_supported() {
		return parent::is_supported() && is_callable( array( gf_advancedpostcreation(), 'update_post' ) );
	}

	/**
	 * Returns the step label.
	 *
	 * @since 2.7.9
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Update Post', 'gravityflow' );
	}

	/**
	 * Returns the HTML for the step icon.
	 *
	 * @since 2.7.9
	 *
	 * @return string
	 */
	public function get_icon_url() {
		return '<i class="fa fa-file-text-o"></i>';
	}

	/**
	 * Returns an array of settings for this step type.
	 *
	 * @since 2.7.9
	 *
	 * @return array
	 */
	public function get_settings() {
		$settings = parent::get_settings();

		if ( ! $this->is_supported() ) {
			return $settings;
		}

		/** @var GF_Advanced_Post_Creation $add_on */
		$add_on = $this->get_add_on_instance();

		$fields = array(
			array(
				'name'    => 'post_status',
				'label'   => __( 'Post Status', 'gravityflow' ),
				'type'    => 'select',
				'choices' => array_merge( array(
					array(
						'label' => __( 'Retain existing status', 'gravityflow' ),
						'value' => ''
					)
				), $add_on->get_post_statuses_as_choices() ),
			),
			array(
				'name'    => 'post_author',
				'label'   => __( 'Post Author', 'gravityflow' ),
				'type'    => 'select',
				'choices' => array(
					array( 'label' => __( 'Use feed setting', 'gravityflow' ), 'value' => '' ),
					array(
						'label'   => __( 'Select a User field', 'gravityflow' ),
						'choices' => gravity_flow()->get_form_fields_as_choices( $this->get_form(), array(
							'disable_first_choice' => true,
							'input_types'          => array( 'workflow_user' ),
						) )
					)
				),
			),
		);

		$settings['fields'] = array_merge( $settings['fields'], $fields );

		return $settings;
	}

	/**
	 * Processes the given feed for the add-on.
	 *
	 * @since 2.7.9
	 *
	 * @param array $feed The add-on feed properties.
	 *
	 * @return bool Is feed processing complete?
	 */
	public function process_feed( $feed ) {

		$post_id = $this->get_update_post_id( (int) $feed['id'] );
		$post    = get_post( $post_id, ARRAY_A );

		if ( $post_id && $post ) {
			add_filter( 'gravityflow_discussion_items_display_toggle', '__return_false', 99 );
			add_filter( 'gform_advancedpostcreation_update_post_data', array( $this, 'prepare_updated_post' ), 10, 3 );
			$this->update_post( $post, $feed );
			remove_filter( 'gform_advancedpostcreation_update_post_data', array( $this, 'prepare_updated_post' ), 10 );
			remove_filter( 'gravityflow_discussion_items_display_toggle', '__return_false', 99 );
		} else {
			if ( $post_id ) {
				$this->log_debug( sprintf( '%s() - post #%d does not exist to be updated.', __METHOD__, $post_id ) );
			} else {
				$this->log_debug( sprintf( '%s() - No post ID identified to be updated.', __METHOD__ ) );
			}
			add_filter( 'gravityflow_timeline_note_add', array( $this, 'post_not_updated' ), 10, 5 );
		}
		return true;
	}

	/**
	 * Prepare the updated post with values from step settings.
	 *
	 * @since 2.7.9
	 *
	 * @param array $post  The post array being updated.
	 * @param array $feed  The feed being processed.
	 * @param array $entry The entry linked to the post.
	 *
	 * @return array $post The post array being updated.
	 */
	public function prepare_updated_post( $post, $feed, $entry ) {

		$post['post_status'] = $this->post_status ? $this->post_status : $post['post_status'];
		$post['post_author'] = $this->post_author ? rgar( $entry, $this->post_author ) : $post['post_author'];

		return $post;
	}

	/**
	 * Revise the note which is added to timeline when post does not exist for update.
	 *
	 * @since 2.7.9
	 *
	 * @param string                 $note       The message to be added to the timeline.
	 * @param int                    $entry_id   The entry of the current step.
	 * @param bool|int               $user_id    The ID of user performing the current step action.
	 * @param string                 $user_name  The username of user performing the current step action.
	 * @param bool|Gravity_Flow_Step $step       If it is a step based action the current step.
	 *
	 * @return bool|string
	 */
	public function post_not_updated( $note, $entry_id, $user_id, $user_name, $step ) {
		if ( $user_name == 'post_update' ) {
			$note .= esc_html__( ' - Post did not exist for update.', 'gravityflow' );
			remove_filter( 'gravityflow_timeline_note_add', array( $this, 'post_not_updated' ), 10 );
		}
		return $note;
	}

	/**
	 * Returns the ID of the post previously created by the current feed.
	 *
	 * @since 2.7.9
	 *
	 * @param int $feed_id The current feed ID.
	 *
	 * @return int|null
	 */
	public function get_update_post_id( $feed_id ) {
		/** @var GF_Advanced_Post_Creation $add_on */
		$add_on = $this->get_add_on_instance();

		$post_ids = gform_get_meta( $this->get_entry_id(), $add_on->get_slug() . '_post_id' );

		// This does not return early to ensure latest generated post is compared. Covers scenarios where a created post has been deleted.
		$created_post_id = null;

		if ( is_array( $post_ids ) ) {
			foreach ( $post_ids as $id ) {
				$post_feed_id = (int) rgar( $id, 'feed_id' );
				if ( $post_feed_id === $feed_id ) {
					$created_post_id = $id['post_id'];
				}
			}
		}

		return $created_post_id;
	}

	/**
	 * Updates the supplied post based on the given feed configuration.
	 *
	 * @since 2.7.9
	 *
	 * @param array $post The post to be updated.
	 * @param array $feed The feed being processed.
	 */
	public function update_post( $post, $feed ) {

		$post_id = $post['ID'];
		$this->log_debug( __METHOD__ . '(): Running for post #' . $post_id );

		$form  = $this->get_form();
		$entry = $this->get_entry();

		/** @var GF_Advanced_Post_Creation $add_on */
		$add_on = $this->get_add_on_instance();
		$result = $add_on->update_post( $post_id, $feed, $entry, $form );

		if ( ! $result || is_wp_error( $result ) ) {
			$this->log_debug( __METHOD__ . '(): The APC update for post #' . $post_id . ' did not complete successfully.' );
		}

		if ( gravity_flow()->is_gravityforms_supported( '2.9.3.2' ) ) {
			$add_on->save_entry_feed_status( $result, $this->get_entry_id(), (int) rgar( $feed, 'id' ), $this->get_form_id() );
			$add_on->post_process_feed( $feed, $entry, $form );
		}

		/**
		 * Allow custom actions to be performed after the post is updated.
		 *
		 * @since 2.7.9
		 *
		 * @param array                              $post         The post which was updated.
		 * @param array                              $feed         The feed which was processed.
		 * @param Gravity_Flow_Step_Feed_Post_Update $current_step The current step.
		 */
		do_action( 'gravityflow_post_update_post', $post, $feed, $current_step = $this );

	}

}

Gravity_Flow_Steps::register( new Gravity_Flow_Step_Feed_Post_Update() );
