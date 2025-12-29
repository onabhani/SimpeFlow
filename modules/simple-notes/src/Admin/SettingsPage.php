<?php
namespace SFA\SimpleNotes\Admin;

/**
 * Settings Page for Simple Notes
 */
class SettingsPage {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], 110 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function add_menu_page() {
		add_submenu_page(
			'simple-notes',
			'Notes Settings',
			'Settings',
			'manage_options',
			'simple-notes-settings',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'simple_notes_settings', 'simple_notes_mention_roles' );
		register_setting( 'simple_notes_settings', 'simple_notes_email_notifications' );
		register_setting( 'simple_notes_settings', 'simple_notes_email_from_name' );
		register_setting( 'simple_notes_settings', 'simple_notes_email_from_email' );
	}

	public function render_page() {
		if ( isset( $_POST['submit'] ) ) {
			check_admin_referer( 'simple_notes_settings' );

			$mention_roles       = isset( $_POST['simple_notes_mention_roles'] ) ? $_POST['simple_notes_mention_roles'] : array();
			$email_notifications = isset( $_POST['simple_notes_email_notifications'] ) ? 1 : 0;
			$email_from_name     = sanitize_text_field( $_POST['simple_notes_email_from_name'] );
			$email_from_email    = sanitize_email( $_POST['simple_notes_email_from_email'] );

			update_option( 'simple_notes_mention_roles', $mention_roles );
			update_option( 'simple_notes_email_notifications', $email_notifications );
			update_option( 'simple_notes_email_from_name', $email_from_name );
			update_option( 'simple_notes_email_from_email', $email_from_email );

			echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
		}

		$mention_roles       = get_option( 'simple_notes_mention_roles', array( 'administrator', 'editor', 'author' ) );
		$email_notifications = get_option( 'simple_notes_email_notifications', 1 );
		$email_from_name     = get_option( 'simple_notes_email_from_name', get_bloginfo( 'name' ) );
		$email_from_email    = get_option( 'simple_notes_email_from_email', get_option( 'admin_email' ) );
		$all_roles           = wp_roles()->get_names();
		?>
		<div class="wrap">
			<h1>Notes Settings</h1>

			<form method="post" action="">
				<?php wp_nonce_field( 'simple_notes_settings' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">User Roles for Mentions</th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><span>User Roles for Mentions</span></legend>
								<?php foreach ( $all_roles as $role_key => $role_name ) : ?>
									<label>
										<input type="checkbox" name="simple_notes_mention_roles[]" value="<?php echo esc_attr( $role_key ); ?>"
											<?php checked( in_array( $role_key, $mention_roles ) ); ?> />
										<?php echo esc_html( $role_name ); ?>
									</label><br>
								<?php endforeach; ?>
								<p class="description">Select which user roles can be mentioned in notes.</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row">Email Notifications</th>
						<td>
							<label>
								<input type="checkbox" name="simple_notes_email_notifications" value="1"
									<?php checked( $email_notifications ); ?> />
								Send email notifications when users are mentioned
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Email From Name</th>
						<td>
							<input type="text" name="simple_notes_email_from_name" value="<?php echo esc_attr( $email_from_name ); ?>" class="regular-text" />
							<p class="description">Name that appears in the "From" field of notification emails.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Email From Address</th>
						<td>
							<input type="email" name="simple_notes_email_from_email" value="<?php echo esc_attr( $email_from_email ); ?>" class="regular-text" />
							<p class="description">Email address that appears in the "From" field of notification emails.</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<div style="background: #f9f9f9; padding: 15px; margin-top: 20px; border-left: 4px solid #0073aa;">
				<h3>📧 Email Troubleshooting</h3>
				<p>If email notifications are not working, try these steps:</p>
				<ol>
					<li><strong>Test WordPress email</strong> - Use the test email feature on the main plugin page</li>
					<li><strong>Check spam folder</strong> - Notification emails might be filtered as spam</li>
					<li><strong>Configure SMTP</strong> - Install an SMTP plugin like WP Mail SMTP for better email delivery</li>
					<li><strong>Check server logs</strong> - Look for email-related errors in your server error logs</li>
					<li><strong>Verify user emails</strong> - Ensure mentioned users have valid email addresses in their profiles</li>
				</ol>
			</div>
		</div>
		<?php
	}
}
