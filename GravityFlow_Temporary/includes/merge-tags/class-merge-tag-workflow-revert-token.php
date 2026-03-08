<?php
/**
 * Gravity Flow Workflow Revert Token Merge Tag
 *
 * @package     GravityFlow
 * @copyright   Copyright (c) 2015-2022, Steven Henty S.L.
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 */

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

/**
 * Class Gravity_Flow_Merge_Tag_Approve_Token
 *
 * @since 2.7.9-dev
 */
class Gravity_Flow_Merge_Tag_Revert_Token extends Gravity_Flow_Merge_Tag_Assignee_Base {

	/**
	 * The name of the merge tag.
	 *
	 * @since 2.7.9-dev
	 *
	 * @var string
	 */
	public $name = 'workflow_revert_token';

	/**
	 * The regular expression to use for the matching.
	 *
	 * @since 2.7.9-dev
	 *
	 * @var string
	 */
	protected $regex = '/{workflow_revert_token}/';

	/**
	 * Replace the {workflow_token_link} merge tags.
	 *
	 * @since 2.7.9-dev
	 *
	 * @param string $text The text being processed.
	 *
	 * @return string
	 */
	public function replace( $text ) {

		$matches = $this->get_matches( $text );

		if ( ! empty( $matches ) ) {

			if ( empty( $this->step ) || empty( $this->assignee ) ) {
				foreach ( $matches as $match ) {
					$full_tag = $match[0];
					$text     = str_replace( $full_tag, '', $text );
				}

				return $text;
			}

			$token = $this->get_token( 'revert' );

			$token = $this->format_value( $token );

			$text = str_replace( '{workflow_revert_token}', $token, $text );
		}

		return $text;
	}

	/**
	 * Get the number of days the token will remain valid for.
	 *
	 * @since 2.1.2-dev
	 *
	 * @return int
	 */
	protected function get_token_expiration_days() {
		return apply_filters( 'gravityflow_revert_token_expiration_days', 2, $this->assignee );
	}
}

Gravity_Flow_Merge_Tags::register( new Gravity_Flow_Merge_Tag_Revert_Token );
