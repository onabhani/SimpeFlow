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
		$form_id  = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;

		if ( ! $entry_id || ! $form_id ) {
			wp_die( esc_html__( 'Missing entry or form ID.', 'simpleflow' ), '', array( 'response' => 400 ) );
		}

		$nonce = isset( $_POST[ MetaBoxRenderer::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ MetaBoxRenderer::NONCE_FIELD ] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, MetaBoxRenderer::NONCE_ACTION_PREFIX . $entry_id ) ) {
			$this->redirect( $form_id, $entry_id, 'invalid_nonce' );
		}

		if ( ! MetaBoxRenderer::current_user_can_change() ) {
			$this->redirect( $form_id, $entry_id, 'no_permission' );
		}

		$entry = \GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || empty( $entry['id'] ) ) {
			$this->redirect( $form_id, $entry_id, 'invalid_user' );
		}

		$new_user_id = isset( $_POST['created_by'] ) ? absint( wp_unslash( $_POST['created_by'] ) ) : 0;
		$old_user_id = (int) ( $entry['created_by'] ?? 0 );

		if ( 0 === $new_user_id && ! current_user_can( 'manage_options' ) ) {
			$this->redirect( $form_id, $entry_id, 'no_permission' );
		}

		if ( $new_user_id > 0 && ! get_userdata( $new_user_id ) ) {
			$this->redirect( $form_id, $entry_id, 'invalid_user' );
		}

		if ( $old_user_id === $new_user_id ) {
			$this->redirect( $form_id, $entry_id, 'nochange' );
		}

		$result = \GFAPI::update_entry_property( $entry_id, 'created_by', $new_user_id );
		if ( true !== $result ) {
			$this->redirect( $form_id, $entry_id, 'save_failed' );
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

		$this->redirect( $form_id, $entry_id, 'updated' );
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

	private function redirect( int $form_id, int $entry_id, string $code ): void {
		$url = add_query_arg(
			array(
				'page'   => 'gf_entries',
				'view'   => 'entry',
				'id'     => $form_id,
				'lid'    => $entry_id,
				'sfa_ec' => $code,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
