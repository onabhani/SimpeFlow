<?php
namespace SFA\EntryCreator\Admin;

use SFA\EntryCreator\Database\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SaveHandler {

	public function __construct() {
		add_action( 'admin_post_' . MetaBoxRenderer::POST_ACTION, array( $this, 'handle' ) );
	}

	public function handle() {
		$entry_id = isset( $_POST['entry_id'] ) ? absint( wp_unslash( $_POST['entry_id'] ) ) : 0;

		// POSTed form_id is only used for the early-bail redirect display.
		// Once we load the entry we use $entry['form_id'] as the source of
		// truth so a forged POST cannot route the audit row, the GF entry
		// note, or send_to_step() against an unrelated form.
		$post_form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;

		if ( ! $entry_id || ! $post_form_id ) {
			wp_die( esc_html__( 'Missing entry or form ID.', 'simpleflow' ), '', array( 'response' => 400 ) );
		}

		$nonce = isset( $_POST[ MetaBoxRenderer::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ MetaBoxRenderer::NONCE_FIELD ] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, MetaBoxRenderer::NONCE_ACTION_PREFIX . $entry_id ) ) {
			$this->redirect( $post_form_id, $entry_id, 'invalid_nonce' );
			return;
		}

		if ( ! MetaBoxRenderer::current_user_can_change() ) {
			$this->redirect( $post_form_id, $entry_id, 'no_permission' );
			return;
		}

		$entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || empty( $entry['id'] ) ) {
			$this->redirect( $post_form_id, $entry_id, 'invalid_user', 'entry_not_found' );
			return;
		}

		// Authoritative form_id from the loaded entry. Ignore POST form_id
		// from here onward.
		$form_id = (int) ( $entry['form_id'] ?? 0 );
		if ( ! $form_id ) {
			$this->redirect( $post_form_id, $entry_id, 'invalid_user', 'entry_not_found' );
			return;
		}

		$new_user_id = isset( $_POST['created_by'] ) ? absint( wp_unslash( $_POST['created_by'] ) ) : 0;
		$old_user_id = (int) ( $entry['created_by'] ?? 0 );

		if ( 0 === $new_user_id && ! current_user_can( 'manage_options' ) ) {
			$this->redirect( $form_id, $entry_id, 'no_permission' );
			return;
		}

		if ( $new_user_id > 0 && ! get_userdata( $new_user_id ) ) {
			$this->redirect( $form_id, $entry_id, 'invalid_user' );
			return;
		}

		if ( $old_user_id === $new_user_id ) {
			$this->redirect( $form_id, $entry_id, 'nochange' );
			return;
		}

		$result = \GFAPI::update_entry_property( $entry_id, 'created_by', $new_user_id );
		if ( is_wp_error( $result ) || false === $result ) {
			$this->redirect( $form_id, $entry_id, 'save_failed', 'update_returned_false' );
			return;
		}

		// Verify the property actually persisted. Invalidate any GF / WP
		// object caches so GFAPI::get_entry() re-reads from the DB.
		wp_cache_delete( $entry_id, 'gravityforms' );
		wp_cache_delete( 'GFFormsModel::get_lead_' . $entry_id, 'gravityforms' );
		if ( class_exists( '\GFCache' ) && is_callable( array( '\GFCache', 'delete' ) ) ) {
			\GFCache::delete( 'GFFormsModel::get_lead_' . $entry_id );
		}

		$fresh = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $fresh ) || empty( $fresh['id'] ) ) {
			$this->redirect( $form_id, $entry_id, 'save_failed', 'verify_read_error' );
			return;
		}

		if ( (int) ( $fresh['created_by'] ?? -1 ) !== $new_user_id ) {
			$this->redirect( $form_id, $entry_id, 'save_failed', 'verify_mismatch' );
			return;
		}

		$changed_by = get_current_user_id();
		$reason_raw = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$reason     = '' !== $reason_raw ? mb_substr( $reason_raw, 0, 255 ) : null;

		$audit_id = LogRepository::insert( array(
			'entry_id'    => $entry_id,
			'form_id'     => $form_id,
			'old_user_id' => $old_user_id,
			'new_user_id' => $new_user_id,
			'changed_by'  => $changed_by,
			'ip_address'  => $this->get_client_ip(),
			'reason'      => $reason,
		) );

		if ( ! $audit_id ) {
			// Audit failure is non-fatal — created_by is already correctly
			// updated and the user-visible action succeeded — but the
			// compliance trail is missing. Surface to the host's error log
			// so an operator can investigate post-hoc.
			error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'SimpleFlow Entry Creator: audit insert failed for entry %d (form %d). created_by changed from %d to %d by user %d.',
				$entry_id,
				$form_id,
				$old_user_id,
				$new_user_id,
				$changed_by
			) );
		}

		$this->add_entry_note( $entry_id, $old_user_id, $new_user_id, $changed_by, $reason );

		/**
		 * Fires after the entry creator has been successfully changed.
		 *
		 * @param int $entry_id
		 * @param int $old_user_id
		 * @param int $new_user_id
		 * @param int $changed_by_user_id
		 */
		do_action( 'sfa_entry_creator_changed', $entry_id, $old_user_id, $new_user_id, $changed_by );

		$this->maybe_restart_workflow_step( $fresh, $form_id, $old_user_id, $new_user_id, $changed_by );

		$this->redirect( $form_id, $entry_id, 'updated' );
	}

	/**
	 * Re-route the entry through its current GravityFlow step so per-step
	 * assignment records (wp_gravityflow_assignments) are re-resolved
	 * against the new created_by. Without this the inbox keeps the old
	 * assignee or shows empty until an admin clicks "Restart Step".
	 *
	 * @param array $entry The fresh entry array from GFAPI::get_entry, passed
	 *                     in so we don't re-fetch it on the save path.
	 */
	private function maybe_restart_workflow_step( array $entry, int $form_id, int $old_user_id, int $new_user_id, int $changed_by ): void {
		$entry_id = (int) ( $entry['id'] ?? 0 );
		if ( ! $entry_id ) {
			return;
		}

		/**
		 * Filter — opt out of the automatic GravityFlow step restart that
		 * normally runs after the entry creator changes. Default: true.
		 *
		 * @param bool $restart      Whether to call send_to_step on the current step.
		 * @param int  $entry_id
		 * @param int  $form_id
		 * @param int  $old_user_id
		 * @param int  $new_user_id
		 */
		if ( ! apply_filters( 'sfa_entry_creator_restart_workflow_step', true, $entry_id, $form_id, $old_user_id, $new_user_id ) ) {
			return;
		}

		if ( ! class_exists( '\Gravity_Flow_API' ) ) {
			return;
		}

		$api  = new \Gravity_Flow_API( $form_id );
		$step = $api->get_current_step( $entry );

		if ( ! $step ) {
			return;
		}

		$step_name = $step->get_name();

		try {
			$api->send_to_step( $entry, $step->get_id() );
		} catch ( \Throwable $e ) {
			return;
		}

		$actor      = get_userdata( $changed_by );
		$actor_name = $actor ? $actor->display_name : __( 'System', 'simpleflow' );

		\GFAPI::add_note(
			$entry_id,
			$changed_by,
			$actor_name,
			sprintf(
				/* translators: %s: GravityFlow step name */
				__( 'Workflow step "%s" was restarted to re-resolve assignees after the entry creator changed.', 'simpleflow' ),
				$step_name
			)
		);
	}

	private function add_entry_note( int $entry_id, int $old_user_id, int $new_user_id, int $changed_by, ?string $reason ): void {
		$old_label  = MetaBoxRenderer::format_user_label( $old_user_id );
		$new_label  = MetaBoxRenderer::format_user_label( $new_user_id );
		$actor      = get_userdata( $changed_by );
		$actor_name = $actor ? $actor->display_name : __( 'System', 'simpleflow' );
		$when       = current_time( 'mysql' );

		$note = sprintf(
			/* translators: 1: old user label, 2: new user label, 3: admin name, 4: date/time */
			__( 'Entry creator changed from %1$s to %2$s by %3$s on %4$s', 'simpleflow' ),
			$old_label,
			$new_label,
			$actor_name,
			$when
		);

		if ( $reason ) {
			$note .= "\n" . sprintf( __( 'Reason: %s', 'simpleflow' ), $reason );
		}

		\GFAPI::add_note( $entry_id, $changed_by, $actor_name, $note );
	}

	private function get_client_ip(): string {
		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = (string) wp_unslash( $_SERVER['REMOTE_ADDR'] );
		}

		$filtered = filter_var( $ip, FILTER_VALIDATE_IP );
		return $filtered ? $filtered : '';
	}

	private function redirect( int $form_id, int $entry_id, string $code, ?string $why = null ): void {
		$args = array(
			'page'   => 'gf_entries',
			'view'   => 'entry',
			'id'     => $form_id,
			'lid'    => $entry_id,
			'sfa_ec' => $code,
		);

		if ( null !== $why && '' !== $why ) {
			$args['sfa_ec_why'] = $why;
		}

		$url = add_query_arg( $args, admin_url( 'admin.php' ) );

		wp_safe_redirect( $url );
		exit;
	}
}
