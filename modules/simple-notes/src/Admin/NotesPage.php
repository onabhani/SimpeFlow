<?php
namespace SFA\SimpleNotes\Admin;

/**
 * Main Notes Admin Page
 */
class NotesPage {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], 100 );
	}

	public function add_menu_page() {
		add_submenu_page(
			'simpleflow',
			'Simple Notes',
			'Simple Notes',
			'edit_posts',
			'simple-notes',
			[ $this, 'render_page' ]
		);
	}

	public function render_page() {
		?>
		<div class="wrap">
			<h1>Simple Notes System</h1>

			<div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccc;">
				<h2>✅ Auto-Positioning Enabled</h2>
				<p><strong>Your notes system is now fully automatic!</strong> Notes widgets will appear automatically on:</p>
				<ul>
					<li><strong>Gravity Forms entry pages</strong> - Right sidebar</li>
					<li><strong>Gravity Flow admin pages</strong> - Above Admin section</li>
					<li><strong>Workflow-inbox frontend pages</strong> - After Workflow widget in sidebar</li>
				</ul>
				<p>No functions.php modifications needed - everything is handled by this module!</p>
			</div>

			<div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccc;">
				<h2>📧 Email Notification Test</h2>
				<p>Test if your WordPress installation can send emails:</p>

				<form id="email-test-form" style="margin-bottom: 20px;">
					<label>Test Email Address:
						<input type="email" id="test-email" placeholder="your@email.com"
						       value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" style="width: 250px;" />
					</label>
					<br><br>
					<button type="button" onclick="testEmail()" style="background: #0073aa; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer;">
						Send Test Email
					</button>
				</form>

				<div id="email-test-result"></div>

				<script>
				function testEmail() {
					var email = document.getElementById("test-email").value;
					var button = document.querySelector("button[onclick='testEmail()']");
					var result = document.getElementById("email-test-result");

					if (!email) {
						alert("Please enter an email address");
						return;
					}

					button.textContent = "Sending...";
					button.disabled = true;
					result.innerHTML = "";

					jQuery.ajax({
						url: ajaxurl,
						method: "POST",
						data: {
							action: "simple_notes_test_email",
							nonce: "<?php echo wp_create_nonce( 'simple_notes_nonce' ); ?>",
							email: email
						},
						success: function(response) {
							button.textContent = "Send Test Email";
							button.disabled = false;

							if (response.success) {
								result.innerHTML = '<div style="color: green; font-weight: bold;">✅ Test email sent successfully! Check your inbox.</div>';
							} else {
								result.innerHTML = '<div style="color: red; font-weight: bold;">❌ Failed to send email: ' + (response.data || 'Unknown error') + '</div>';
							}
						},
						error: function() {
							button.textContent = "Send Test Email";
							button.disabled = false;
							result.innerHTML = '<div style="color: red; font-weight: bold;">❌ Network error occurred</div>';
						}
					});
				}
				</script>
			</div>

			<div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccc;">
				<h2>Test the Notes System</h2>
				<p>This is a working demo. Try adding a note below and test the @username mentions:</p>

				<div class="simple-notes-widget" data-entity-type="demo" data-entity-id="test-123"></div>
			</div>

			<div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccc;">
				<h2>Manual Notes Widget</h2>
				<p>Use this tool to add notes to any specific entity:</p>

				<form id="notes-widget-form" style="margin-bottom: 20px;">
					<label>Entity Type:
						<select id="entity-type">
							<option value="gravity_form_entry">Gravity Form Entry</option>
							<option value="workflow_step">Workflow Step</option>
							<option value="post">Post/Page</option>
						</select>
					</label>
					<br><br>
					<label>Entity ID:
						<input type="text" id="entity-id" placeholder="e.g., 28953" />
					</label>
					<br><br>
					<button type="button" onclick="loadCustomWidget()">Load Notes Widget</button>
				</form>

				<div id="custom-widget-container"></div>

				<script>
				function loadCustomWidget() {
					var entityType = document.getElementById("entity-type").value;
					var entityId = document.getElementById("entity-id").value;

					if (!entityId) {
						alert("Please enter an Entity ID");
						return;
					}

					SimpleNotes.init("#custom-widget-container", entityType, entityId);
				}
				</script>
			</div>
		</div>
		<?php
	}
}
