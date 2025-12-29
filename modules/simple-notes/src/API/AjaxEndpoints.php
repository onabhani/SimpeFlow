<?php
namespace SFA\SimpleNotes\API;

/**
 * AJAX Endpoints for Simple Notes
 */
class AjaxEndpoints {

	private $table_name;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'simple_notes';

		add_action( 'wp_ajax_simple_notes_add', [ $this, 'ajax_add_note' ] );
		add_action( 'wp_ajax_simple_notes_get', [ $this, 'ajax_get_notes' ] );
		add_action( 'wp_ajax_simple_notes_delete', [ $this, 'ajax_delete_note' ] );
		add_action( 'wp_ajax_simple_notes_search_users', [ $this, 'ajax_search_users' ] );
		add_action( 'wp_ajax_simple_notes_test_email', [ $this, 'ajax_test_email' ] );
	}

	public function ajax_search_users() {
		check_ajax_referer( 'simple_notes_nonce', 'nonce' );

		$query = sanitize_text_field( $_POST['query'] );
		$mention_roles = get_option( 'simple_notes_mention_roles', array( 'administrator', 'editor', 'author' ) );

		if ( empty( $mention_roles ) ) {
			wp_send_json_success( array() );
		}

		$users = get_users( array(
			'search'         => '*' . $query . '*',
			'search_columns' => array( 'user_login', 'display_name' ),
			'role__in'       => $mention_roles,
			'number'         => 10,
		) );

		$results = array();
		foreach ( $users as $user ) {
			$results[] = array(
				'id'           => $user->ID,
				'username'     => $user->user_login,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
			);
		}

		wp_send_json_success( $results );
	}

	public function ajax_add_note() {
		check_ajax_referer( 'simple_notes_nonce', 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$entity_type = sanitize_text_field( $_POST['entity_type'] );
		$entity_id   = sanitize_text_field( $_POST['entity_id'] );
		$content     = sanitize_textarea_field( $_POST['content'] );

		if ( empty( $content ) ) {
			wp_send_json_error( 'Content is required' );
		}

		// Extract mentions
		preg_match_all( '/@([a-zA-Z0-9_.-]+)/', $content, $matches );
		$mentioned_usernames = $matches[1];
		$mentioned_users     = array();

		if ( ! empty( $mentioned_usernames ) ) {
			foreach ( $mentioned_usernames as $username ) {
				$user = get_user_by( 'login', $username );
				if ( $user ) {
					$mentioned_users[] = array(
						'id'           => $user->ID,
						'username'     => $user->user_login,
						'display_name' => $user->display_name,
						'email'        => $user->user_email,
					);
				}
			}
		}

		global $wpdb;
		$current_user = wp_get_current_user();

		$result = $wpdb->insert(
			$this->table_name,
			array(
				'entity_type'     => $entity_type,
				'entity_id'       => $entity_id,
				'content'         => $content,
				'author_id'       => $current_user->ID,
				'author_name'     => $current_user->display_name,
				'mentioned_users' => json_encode( $mentioned_users ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( $result ) {
			// Send email notifications
			$mentions_sent = 0;
			if ( get_option( 'simple_notes_email_notifications', 1 ) && ! empty( $mentioned_users ) ) {
				$mentions_sent = $this->send_mention_notifications( $mentioned_users, $current_user, $content, $entity_type, $entity_id );
			}

			wp_send_json_success( array(
				'message'       => 'Note added',
				'mentions_sent' => $mentions_sent,
			) );
		} else {
			wp_send_json_error( 'Database error' );
		}
	}

	public function ajax_get_notes() {
		check_ajax_referer( 'simple_notes_nonce', 'nonce' );

		$entity_type     = sanitize_text_field( $_POST['entity_type'] );
		$entity_id       = sanitize_text_field( $_POST['entity_id'] );
		$current_user_id = get_current_user_id();

		global $wpdb;
		$notes = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE entity_type = %s AND entity_id = %s ORDER BY created_at DESC",
			$entity_type,
			$entity_id
		), ARRAY_A );

		// Add can_delete and author_username fields to each note
		foreach ( $notes as &$note ) {
			$can_delete           = current_user_can( 'delete_posts' ) || current_user_can( 'manage_options' );
			$note['can_delete']   = $can_delete;

			$author                  = get_user_by( 'id', $note['author_id'] );
			$note['author_username'] = $author ? $author->user_login : 'unknown';
		}

		wp_send_json_success( $notes );
	}

	public function ajax_delete_note() {
		check_ajax_referer( 'simple_notes_nonce', 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$note_id         = intval( $_POST['note_id'] );
		$current_user_id = get_current_user_id();

		global $wpdb;

		$note = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE id = %d",
			$note_id
		) );

		if ( ! $note ) {
			wp_send_json_error( 'Note not found' );
		}

		$can_delete = current_user_can( 'delete_posts' ) || current_user_can( 'manage_options' );

		if ( ! $can_delete ) {
			wp_send_json_error( 'You do not have permission to delete notes' );
		}

		$result = $wpdb->delete( $this->table_name, array( 'id' => $note_id ), array( '%d' ) );

		if ( $result ) {
			wp_send_json_success( 'Note deleted successfully' );
		} else {
			wp_send_json_error( 'Failed to delete note' );
		}
	}

	public function ajax_test_email() {
		check_ajax_referer( 'simple_notes_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$email = sanitize_email( $_POST['email'] );
		if ( ! $email ) {
			wp_send_json_error( 'Invalid email address' );
		}

		$subject = '[' . get_bloginfo( 'name' ) . '] Simple Notes Email Test';
		$message = "This is a test email from the Simple Notes System.\n\n";
		$message .= "If you received this email, your WordPress installation can send emails successfully.\n\n";
		$message .= "Test details:\n";
		$message .= "- Sent at: " . current_time( 'mysql' ) . "\n";
		$message .= "- From: Simple Notes Module v1.0.0\n";
		$message .= "- WordPress version: " . get_bloginfo( 'version' ) . "\n\n";
		$message .= "Best regards,\n" . get_bloginfo( 'name' );

		$headers    = array();
		$from_name  = get_option( 'simple_notes_email_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'simple_notes_email_from_email', get_option( 'admin_email' ) );
		$headers[]  = 'From: ' . $from_name . ' <' . $from_email . '>';

		$result = wp_mail( $email, $subject, $message, $headers );

		if ( $result ) {
			error_log( 'Simple Notes: Test email sent successfully to ' . $email );
			wp_send_json_success( 'Test email sent successfully' );
		} else {
			error_log( 'Simple Notes: Failed to send test email to ' . $email );
			wp_send_json_error( 'Failed to send test email. Check your WordPress email configuration.' );
		}
	}

	private function send_mention_notifications( $mentioned_users, $author, $content, $entity_type, $entity_id ) {
		$sent_count = 0;

		foreach ( $mentioned_users as $user ) {
			if ( $user['id'] == $author->ID ) {
				continue;
			}

			$subject = sprintf( '[%s] You were mentioned in a note', get_bloginfo( 'name' ) );

			$entry_url = '';
			if ( $entity_type === 'gravity_form_entry' || $entity_type === 'workflow_step' ) {
				$form_id = $this->get_form_id_from_entry( $entity_id );

				if ( $form_id ) {
					$site_url  = get_site_url();
					$entry_url = $site_url . '/workflow-inbox/?page=gravityflow-inbox&view=entry&id=' . $form_id . '&lid=' . $entity_id;
				}
			}

			if ( ! empty( $entry_url ) ) {
				$message = sprintf(
					"Hi %s,\n\n" .
					"You were mentioned in a note by %s:\n\n" .
					"\"%s\"\n\n" .
					"Entity: %s #%s\n\n" .
					"View Entry: %s\n\n" .
					"Best regards,\n%s",
					$user['display_name'],
					$author->display_name,
					$content,
					$entity_type,
					$entity_id,
					$entry_url,
					get_bloginfo( 'name' )
				);
			} else {
				$message = sprintf(
					"Hi %s,\n\n" .
					"You were mentioned in a note by %s:\n\n" .
					"\"%s\"\n\n" .
					"Entity: %s #%s\n\n" .
					"Best regards,\n%s",
					$user['display_name'],
					$author->display_name,
					$content,
					$entity_type,
					$entity_id,
					get_bloginfo( 'name' )
				);
			}

			if ( wp_mail( $user['email'], $subject, $message ) ) {
				$sent_count++;
			}
		}

		return $sent_count;
	}

	private function get_form_id_from_entry( $entry_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'gf_entry';

		$form_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT form_id FROM {$table_name} WHERE id = %d",
			$entry_id
		) );

		return $form_id;
	}
}
