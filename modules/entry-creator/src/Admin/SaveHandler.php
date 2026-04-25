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
		global $wpdb;

		$entry_id = isset( $_POST['entry_id'] ) ? absint( wp_unslash( $_POST['entry_id'] ) ) : 0;
		$form_id  = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;

		self::diag_log( 'handle: entered', array(
			'entry_id'  => $entry_id,
			'form_id'   => $form_id,
			'post_keys' => array_keys( $_POST ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'actor'     => get_current_user_id(),
		) );

		if ( ! $entry_id || ! $form_id ) {
			self::diag_log( 'handle: missing entry/form id, 400' );
			wp_die( esc_html__( 'Missing entry or form ID.', 'simpleflow' ), '', array( 'response' => 400 ) );
		}

		$nonce = isset( $_POST[ MetaBoxRenderer::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ MetaBoxRenderer::NONCE_FIELD ] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, MetaBoxRenderer::NONCE_ACTION_PREFIX . $entry_id ) ) {
			self::diag_log( 'handle: invalid nonce' );
			$this->redirect( $form_id, $entry_id, 'invalid_nonce' );
		}

		if ( ! MetaBoxRenderer::current_user_can_change() ) {
			self::diag_log( 'handle: caps failed (current_user_can_change)' );
			$this->redirect( $form_id, $entry_id, 'no_permission' );
		}

		$entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || empty( $entry['id'] ) ) {
			self::diag_log( 'handle: entry not loadable', array(
				'is_wp_error' => is_wp_error( $entry ),
				'error'       => is_wp_error( $entry ) ? $entry->get_error_message() : 'empty',
			) );
			$this->redirect( $form_id, $entry_id, 'invalid_user', 'entry_not_found' );
		}

		$new_user_id = isset( $_POST['created_by'] ) ? absint( wp_unslash( $_POST['created_by'] ) ) : 0;
		$old_user_id = (int) ( $entry['created_by'] ?? 0 );

		self::diag_log( 'handle: ids resolved', array(
			'old_user_id' => $old_user_id,
			'new_user_id' => $new_user_id,
		) );

		if ( 0 === $new_user_id && ! current_user_can( 'manage_options' ) ) {
			self::diag_log( 'handle: no_permission (0-user requires manage_options)' );
			$this->redirect( $form_id, $entry_id, 'no_permission' );
		}

		if ( $new_user_id > 0 && ! get_userdata( $new_user_id ) ) {
			self::diag_log( 'handle: target user does not exist', array( 'new_user_id' => $new_user_id ) );
			$this->redirect( $form_id, $entry_id, 'invalid_user' );
		}

		if ( $old_user_id === $new_user_id ) {
			self::diag_log( 'handle: nochange (old === new)' );
			$this->redirect( $form_id, $entry_id, 'nochange' );
		}

		// Reset wpdb error state so we can attribute any SQL error to this write.
		$wpdb->last_error = '';

		self::diag_log( 'handle: calling GFAPI::update_entry_property', array(
			'entry_id'    => $entry_id,
			'property'    => 'created_by',
			'new_user_id' => $new_user_id,
		) );

		$result = \GFAPI::update_entry_property( $entry_id, 'created_by', $new_user_id );

		self::diag_log( 'handle: update_entry_property returned', array(
			'result'          => is_wp_error( $result ) ? 'WP_Error: ' . $result->get_error_message() : $result,
			'type'            => gettype( $result ),
			'wpdb_last_error' => $wpdb->last_error,
		) );

		if ( is_wp_error( $result ) || false === $result ) {
			$this->redirect( $form_id, $entry_id, 'save_failed', 'update_returned_false' );
		}

		// Flush GF / WP caches so the verify re-read cannot return a stale value.
		wp_cache_delete( $entry_id, 'gravityforms' );
		wp_cache_delete( 'GFFormsModel::get_lead_' . $entry_id, 'gravityforms' );
		if ( class_exists( '\GFCache' ) && is_callable( array( '\GFCache', 'delete' ) ) ) {
			\GFCache::delete( 'GFFormsModel::get_lead_' . $entry_id );
		}

		// Authoritative verify: read created_by straight from wp_gf_entry,
		// bypassing every object cache and every gform_get_entry filter. We
		// normally avoid touching this table directly, but for "did the write
		// actually land?" the GF API is the suspect.
		$entry_table = \GFFormsModel::get_entry_table_name();
		$raw_result  = $wpdb->get_var( $wpdb->prepare( "SELECT created_by FROM {$entry_table} WHERE id = %d", $entry_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		self::diag_log( 'handle: raw verify from wp_gf_entry', array(
			'raw_result'      => $raw_result,
			'expected'        => $new_user_id,
			'wpdb_last_error' => $wpdb->last_error,
		) );

		if ( null === $raw_result ) {
			$this->redirect( $form_id, $entry_id, 'save_failed', 'verify_read_error' );
		}

		if ( (int) $raw_result !== $new_user_id ) {
			$this->redirect( $form_id, $entry_id, 'save_failed', 'verify_mismatch' );
		}

		$changed_by = get_current_user_id();
		$reason_raw = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$reason     = '' !== $reason_raw ? mb_substr( $reason_raw, 0, 255 ) : null;

		LogRepository::insert( array(
			'entry_id'    => $entry_id,
			'form_id'     => $form_id,
			'old_user_id' => $old_user_id,
			'new_user_id' => $new_user_id,
			'changed_by'  => $changed_by,
			'ip_address'  => $this->get_client_ip(),
			'reason'      => $reason,
		) );

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

		// Re-route the entry through the current GravityFlow step so step
		// assignees are re-resolved against the new created_by. Without this
		// the existing per-step assignment records (wp_gravityflow_assignments)
		// keep pointing at the old user and the inbox shows an empty assignee
		// until an admin manually clicks "Restart Step".
		$this->maybe_restart_workflow_step( $entry_id, $form_id, $old_user_id, $new_user_id, $changed_by );

		self::diag_log( 'handle: success, redirecting with updated', array(
			'entry_id'    => $entry_id,
			'old_user_id' => $old_user_id,
			'new_user_id' => $new_user_id,
		) );

		$this->redirect( $form_id, $entry_id, 'updated' );
	}

	private function maybe_restart_workflow_step( int $entry_id, int $form_id, int $old_user_id, int $new_user_id, int $changed_by ): void {
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
			self::diag_log( 'restart_step: skipped by filter' );
			return;
		}

		if ( ! class_exists( '\Gravity_Flow_API' ) ) {
			self::diag_log( 'restart_step: GravityFlow not active, nothing to restart' );
			return;
		}

		$entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || empty( $entry['id'] ) ) {
			self::diag_log( 'restart_step: could not reload entry', array(
				'error' => is_wp_error( $entry ) ? $entry->get_error_message() : 'empty',
			) );
			return;
		}

		$api  = new \Gravity_Flow_API( $form_id );
		$step = $api->get_current_step( $entry );

		if ( ! $step ) {
			self::diag_log( 'restart_step: no current step (workflow complete or never started)' );
			return;
		}

		$step_id   = $step->get_id();
		$step_name = $step->get_name();

		self::diag_log( 'restart_step: calling Gravity_Flow_API::send_to_step', array(
			'step_id'   => $step_id,
			'step_name' => $step_name,
		) );

		try {
			$api->send_to_step( $entry, $step_id );
		} catch ( \Throwable $e ) {
			self::diag_log( 'restart_step: send_to_step threw', array(
				'step_id' => $step_id,
				'error'   => $e->getMessage(),
			) );
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

		self::diag_log( 'restart_step: success', array(
			'step_id'   => $step_id,
			'step_name' => $step_name,
		) );
	}

	private function add_entry_note( int $entry_id, int $old_user_id, int $new_user_id, int $changed_by, ?string $reason ): void {
		$old_label    = MetaBoxRenderer::format_user_label( $old_user_id );
		$new_label    = MetaBoxRenderer::format_user_label( $new_user_id );
		$actor        = get_userdata( $changed_by );
		$actor_name   = $actor ? $actor->display_name : __( 'System', 'simpleflow' );
		$when         = current_time( 'mysql' );

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

	/**
	 * Resolves the plugin-owned diagnostic log path.
	 *
	 * Uses wp-content/ when writable (preferred — same convention as
	 * debug.log) and falls back to the uploads dir. The filename is
	 * suffixed with a hash derived from AUTH_KEY so it is not guessable
	 * by an attacker who does not have DB or filesystem access.
	 */
	public static function diag_log_path(): string {
		$suffix = '';
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			$suffix = '-' . substr( hash( 'sha256', 'sfa_ec_diag' . AUTH_KEY ), 0, 12 );
		}

		$basename = 'sfa-entry-creator-debug' . $suffix . '.log';

		if ( defined( 'WP_CONTENT_DIR' ) && WP_CONTENT_DIR && is_dir( WP_CONTENT_DIR ) && is_writable( WP_CONTENT_DIR ) ) {
			return rtrim( WP_CONTENT_DIR, '/\\' ) . '/' . $basename;
		}

		$upload = wp_upload_dir();
		if ( ! empty( $upload['basedir'] ) ) {
			return trailingslashit( $upload['basedir'] ) . $basename;
		}

		return '';
	}

	/**
	 * Always-on diagnostic logger. Writes to a plugin-owned file in
	 * wp-content/ (independent of WP_DEBUG_LOG and the host's
	 * error_log routing) and additionally mirrors to error_log()
	 * when WP_DEBUG is on.
	 */
	public static function diag_log( string $msg, array $ctx = array() ): void {
		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] [SFA EntryCreator] ' . $msg;

		if ( ! empty( $ctx ) ) {
			$encoded = wp_json_encode( $ctx );
			if ( $encoded ) {
				$line .= ' ' . $encoded;
			}
		}

		$path = self::diag_log_path();
		if ( $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $path, $line . "\n", FILE_APPEND | LOCK_EX );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
