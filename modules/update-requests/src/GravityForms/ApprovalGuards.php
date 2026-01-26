<?php
namespace SFA\UpdateRequests\GravityForms;

/**
 * Approval Guards
 *
 * Prevents users from skipping approval step in update request workflow
 * Enforces approval/rejection before proceeding to next steps
 */
class ApprovalGuards {

	public function __construct() {
		// Validate that approval step is not skipped
		add_filter( 'gravityflow_validation_step', [ $this, 'validate_approval_step' ], 10, 4 );

		// Track approval/rejection status changes
		add_action( 'gravityflow_step_complete', [ $this, 'track_approval_status' ], 10, 4 );

		// Prevent admin bypass for update requests
		add_filter( 'gravityflow_assignee_status_workflow_detail', [ $this, 'prevent_admin_bypass' ], 10, 3 );
	}

	/**
	 * Validate approval step is not skipped
	 *
	 * @param bool   $is_valid
	 * @param object $step
	 * @param int    $entry_id
	 * @param array  $form
	 * @return bool
	 */
	public function validate_approval_step( $is_valid, $step, $entry_id, $form ) {
		// Only apply to update request entries
		if ( ! $this->is_update_request_entry( $entry_id ) ) {
			return $is_valid;
		}

		// Check if this is an approval step
		if ( ! $this->is_approval_step( $step ) ) {
			return $is_valid;
		}

		// Get current status
		$current_status = gform_get_meta( $entry_id, '_ur_status' );

		// If status is still 'submitted', approval step hasn't been completed
		if ( $current_status === 'submitted' ) {
			// Approval is required - step must be completed properly
			$step_status = $step->get_status();

			if ( $step_status !== 'complete' ) {
				// Step is not complete, prevent skipping
				error_log( sprintf(
					'Update Requests: Preventing skip of approval step for entry %d (status: %s)',
					$entry_id,
					$current_status
				) );
				return false; // Force validation failure
			}
		}

		return $is_valid;
	}

	/**
	 * Track approval/rejection status changes
	 *
	 * @param int    $entry_id
	 * @param int    $step_id
	 * @param array  $form
	 * @param object $step
	 */
	public function track_approval_status( $entry_id, $step_id, $form, $step ) {
		// Only apply to update request entries
		if ( ! $this->is_update_request_entry( $entry_id ) ) {
			return;
		}

		// Check if this is an approval step
		if ( ! $this->is_approval_step( $step ) ) {
			return;
		}

		// Get step outcome (approved/rejected)
		$assignee_details = $step->get_assignees();

		foreach ( $assignee_details as $assignee ) {
			$assignee_status = $assignee->get_status();

			if ( $assignee_status === 'approved' ) {
				// Update request approved
				gform_update_meta( $entry_id, '_ur_status', 'approved' );
				gform_update_meta( $entry_id, '_ur_approved_at', current_time( 'mysql' ) );
				gform_update_meta( $entry_id, '_ur_approved_by', get_current_user_id() );

				// Update parent's children array
				$this->update_parent_child_status( $entry_id, 'approved' );

				error_log( sprintf(
					'Update Requests: Entry %d approved by user %d',
					$entry_id,
					get_current_user_id()
				) );

				do_action( 'sfa_update_request_approved', $entry_id, get_current_user_id() );

			} elseif ( $assignee_status === 'rejected' ) {
				// Update request rejected
				gform_update_meta( $entry_id, '_ur_status', 'rejected' );
				gform_update_meta( $entry_id, '_ur_rejected_at', current_time( 'mysql' ) );
				gform_update_meta( $entry_id, '_ur_rejected_by', get_current_user_id() );

				// Update parent's children array
				$this->update_parent_child_status( $entry_id, 'rejected' );

				error_log( sprintf(
					'Update Requests: Entry %d rejected by user %d',
					$entry_id,
					get_current_user_id()
				) );

				do_action( 'sfa_update_request_rejected', $entry_id, get_current_user_id() );
			}
		}
	}

	/**
	 * Prevent admin bypass for update request approval
	 *
	 * @param array  $assignee_details
	 * @param object $step
	 * @param array  $form
	 * @return array
	 */
	public function prevent_admin_bypass( $assignee_details, $step, $form ) {
		// Get entry ID from step object
		$entry_id = method_exists( $step, 'get_entry_id' ) ? $step->get_entry_id() : 0;

		if ( ! $entry_id ) {
			return $assignee_details;
		}

		// Only apply to update request entries
		if ( ! $this->is_update_request_entry( $entry_id ) ) {
			return $assignee_details;
		}

		// Check if this is an approval step
		if ( ! $this->is_approval_step( $step ) ) {
			return $assignee_details;
		}

		// Get current status
		$current_status = gform_get_meta( $entry_id, '_ur_status' );

		// If still submitted, require actual approval/rejection
		if ( $current_status === 'submitted' ) {
			// Ensure assignee status workflow is enforced
			// (This integrates with existing SimpleFlow validation bypass filters)
			foreach ( $assignee_details as $key => $assignee ) {
				if ( method_exists( $assignee, 'get_status' ) ) {
					$status = $assignee->get_status();

					// If admin is trying to bypass without approve/reject, block it
					if ( $status !== 'approved' && $status !== 'rejected' && $status !== 'pending' ) {
						error_log( sprintf(
							'Update Requests: Blocking invalid status "%s" for approval step on entry %d',
							$status,
							$entry_id
						) );

						// Reset to pending to force proper approval
						if ( method_exists( $assignee, 'update_status' ) ) {
							$assignee->update_status( 'pending' );
						}
					}
				}
			}
		}

		return $assignee_details;
	}

	/**
	 * Update parent's children array with new status
	 *
	 * @param int    $child_entry_id
	 * @param string $new_status
	 */
	private function update_parent_child_status( $child_entry_id, $new_status ) {
		$parent_id = gform_get_meta( $child_entry_id, '_ur_parent_id' );

		if ( ! $parent_id ) {
			return;
		}

		// Get parent's children array
		$children_json = gform_get_meta( $parent_id, '_ur_children' );
		$children = $children_json ? json_decode( $children_json, true ) : [];

		if ( ! is_array( $children ) ) {
			return;
		}

		// Find and update this child's status
		foreach ( $children as &$child ) {
			if ( isset( $child['entry_id'] ) && $child['entry_id'] == $child_entry_id ) {
				$child['status'] = $new_status;

				if ( $new_status === 'approved' ) {
					$child['approved_at'] = current_time( 'mysql' );
					$child['approved_by'] = get_current_user_id();
				} elseif ( $new_status === 'rejected' ) {
					$child['rejected_at'] = current_time( 'mysql' );
					$child['rejected_by'] = get_current_user_id();
				}

				break;
			}
		}

		// Update parent meta
		gform_update_meta( $parent_id, '_ur_children', wp_json_encode( $children ) );
	}

	/**
	 * Check if entry is an update request
	 *
	 * @param int $entry_id
	 * @return bool
	 */
	private function is_update_request_entry( $entry_id ) {
		$mode = gform_get_meta( $entry_id, '_ur_mode' );
		return $mode === 'update_request';
	}

	/**
	 * Check if step is an approval step
	 *
	 * @param object $step
	 * @return bool
	 */
	private function is_approval_step( $step ) {
		if ( ! is_object( $step ) || ! method_exists( $step, 'get_type' ) ) {
			return false;
		}

		$step_type = $step->get_type();

		// Check if it's an approval step type
		return $step_type === 'approval';
	}
}
