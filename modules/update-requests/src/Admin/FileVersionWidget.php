<?php
namespace SFA\UpdateRequests\Admin;

use SFA\UpdateRequests\GravityForms\VersionManager;

/**
 * File Version Widget
 *
 * Displays file version table in GravityFlow entry detail page
 * Shows [Update] buttons and [+ Add Following Invoice] button
 */
class FileVersionWidget {

	public function __construct() {
		// Add widget below entry details in GravityFlow inbox
		add_action( 'gravityflow_entry_detail_content_below', [ $this, 'render_widget' ], 10, 3 );

		// Enqueue assets for modal (admin)
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Enqueue assets for modal (frontend workflow-inbox)
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
	}

	/**
	 * Enqueue JavaScript and CSS for modals (admin pages)
	 */
	public function enqueue_assets( $hook ) {
		// Only load on GravityFlow admin pages
		if ( strpos( (string) $hook, 'gravityflow' ) === false ) {
			return;
		}

		$this->do_enqueue_assets();
	}

	/**
	 * Enqueue JavaScript and CSS for modals (frontend workflow-inbox)
	 */
	public function enqueue_frontend_assets() {
		// Only load on workflow-inbox pages
		if ( strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), 'workflow-inbox' ) === false ) {
			return;
		}

		$this->do_enqueue_assets();
	}

	/**
	 * Shared asset enqueue logic
	 */
	private function do_enqueue_assets() {
		// Prevent double enqueue
		if ( wp_style_is( 'sfa-ur-modal', 'enqueued' ) ) {
			return;
		}

		// Enqueue modal styles
		wp_enqueue_style(
			'sfa-ur-modal',
			SFA_UR_URL . 'assets/css/modal.css',
			[],
			SFA_UR_VER
		);

		// Enqueue modal script
		wp_enqueue_script(
			'sfa-ur-modal',
			SFA_UR_URL . 'assets/js/modal.js',
			[ 'jquery' ],
			SFA_UR_VER,
			true
		);

		// Localize script with AJAX data
		wp_localize_script( 'sfa-ur-modal', 'sfaUrData', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'sfa_ur_modal' ),
		] );
	}

	/**
	 * Render widget below entry details
	 *
	 * @param array $form        Gravity Forms form array
	 * @param array $entry       Entry array
	 * @param object $current_step Current workflow step
	 */
	public function render_widget( $form, $entry, $current_step ) {
		// Check if Update Requests is enabled for this form
		if ( ! FormSettings::is_enabled( $form ) ) {
			return;
		}

		$entry_id = $entry['id'];
		$form_id = $form['id'];

		// Check if current user is the entry creator (only creators can submit update requests)
		if ( ! FormSettings::is_entry_creator( $entry ) ) {
			return;
		}

		// Get current step ID
		$current_step_id = is_object( $current_step ) && method_exists( $current_step, 'get_id' )
			? $current_step->get_id()
			: 0;

		// Check permissions (pass entry for cutoff step checking)
		$can_update = FormSettings::can_submit_update_request( $form, $current_step_id, $entry );
		$can_follow = FormSettings::can_submit_following_invoice( $form, $current_step_id, $entry );

		if ( ! $can_update && ! $can_follow ) {
			// Cutoff step has been passed, no longer allowed
			return;
		}

		// Get drawing field ID
		$drawing_field_id = FormSettings::get_drawing_field_id( $form );

		if ( ! $drawing_field_id ) {
			return;
		}

		// Get files from drawing field
		$files = $this->get_entry_files( $entry, $drawing_field_id );

		// Get version history
		$version_manager = new VersionManager();
		$version_history = $version_manager->get_versions( $entry_id );

		?>
		<div class="sfa-ur-widget" style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccc;">
			<h3 style="margin-top: 0;">📁 Drawings & Files</h3>

			<?php if ( empty( $files ) ): ?>
				<p style="color: #666;">No files uploaded yet.</p>
			<?php else: ?>
				<table class="widefat striped" style="margin: 15px 0;">
					<thead>
						<tr>
							<th>Drawing Name</th>
							<th>Current Version</th>
							<th>Uploaded</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $files as $file_url ): ?>
							<?php
							$filename = basename( $file_url );
							$version_info = $version_manager->get_current_version( $entry_id, $filename );
							$version_number = $version_info ? $version_info['version'] : 1;
							$upload_date = $version_info ? $version_info['date'] : '';
							?>
							<tr>
								<td>
									<a href="<?php echo esc_url( $file_url ); ?>" target="_blank">
										<?php echo esc_html( $filename ); ?>
									</a>
								</td>
								<td>v<?php echo $version_number; ?></td>
								<td><?php echo $upload_date ? date( 'M j, Y', strtotime( $upload_date ) ) : '-'; ?></td>
								<td>
									<?php if ( $can_update ): ?>
										<button
											type="button"
											class="button button-small sfa-ur-update-btn"
											data-entry-id="<?php echo $entry_id; ?>"
											data-form-id="<?php echo $form_id; ?>"
											data-filename="<?php echo esc_attr( $filename ); ?>"
											data-current-version="<?php echo $version_number; ?>"
										>
											Update
										</button>
									<?php else: ?>
										<span style="color: #999;">-</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $can_follow ): ?>
				<p style="margin-top: 20px;">
					<button
						type="button"
						class="button button-primary sfa-ur-following-btn"
						data-entry-id="<?php echo $entry_id; ?>"
						data-form-id="<?php echo $form_id; ?>"
					>
						+ Add Following Invoice
					</button>
				</p>
			<?php endif; ?>

			<?php if ( ! $can_update && ! $can_follow ): ?>
				<p style="color: #999; font-style: italic;">
					Update requests and following invoices are not available at this workflow step.
				</p>
			<?php endif; ?>
		</div>

		<!-- Update Request Modal (will be shown via JavaScript) -->
		<div id="sfa-ur-update-modal" class="sfa-ur-modal" style="display: none;">
			<div class="sfa-ur-modal-content">
				<span class="sfa-ur-modal-close">&times;</span>
				<h2>Update Drawing</h2>
				<form id="sfa-ur-update-form" enctype="multipart/form-data">
					<input type="hidden" name="action" value="sfa_ur_submit_update">
					<input type="hidden" name="entry_id" id="sfa-ur-entry-id">
					<input type="hidden" name="form_id" id="sfa-ur-form-id">
					<input type="hidden" name="filename" id="sfa-ur-filename">
					<input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'sfa_ur_submit' ); ?>">

					<p><strong>Current Drawing:</strong> <span id="sfa-ur-current-name"></span> (v<span id="sfa-ur-current-version"></span>)</p>

					<div class="sfa-ur-form-field">
						<label>New Drawing Version:</label>
						<input type="file" name="drawing_file" accept=".pdf,.dwg,.dxf,.jpg,.jpeg,.png" required>
					</div>

					<div class="sfa-ur-form-field">
						<label>Related Invoice (Optional):</label>
						<input type="file" name="invoice_file" accept=".pdf">
					</div>

					<div class="sfa-ur-form-field">
						<label>Reason for Update:</label>
						<textarea name="reason" rows="4" style="width: 100%;" required></textarea>
					</div>

					<div class="sfa-ur-form-actions">
						<button type="button" class="button sfa-ur-modal-cancel">Cancel</button>
						<button type="submit" class="button button-primary">Submit for Approval</button>
					</div>

					<div class="sfa-ur-form-message" style="display: none;"></div>
				</form>
			</div>
		</div>

		<!-- Following Invoice Modal -->
		<div id="sfa-ur-following-modal" class="sfa-ur-modal" style="display: none;">
			<div class="sfa-ur-modal-content">
				<span class="sfa-ur-modal-close">&times;</span>
				<h2>Add Following Invoice</h2>
				<form id="sfa-ur-following-form" enctype="multipart/form-data">
					<input type="hidden" name="action" value="sfa_ur_submit_following">
					<input type="hidden" name="entry_id" id="sfa-ur-following-entry-id">
					<input type="hidden" name="form_id" id="sfa-ur-following-form-id">
					<input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'sfa_ur_submit' ); ?>">

					<div class="sfa-ur-form-field">
						<label>Invoice File:</label>
						<input type="file" name="invoice_file" accept=".pdf" required>
					</div>

					<div class="sfa-ur-form-field">
						<label>Reason/Description:</label>
						<textarea name="reason" rows="4" style="width: 100%;" required></textarea>
					</div>

					<div class="sfa-ur-form-actions">
						<button type="button" class="button sfa-ur-modal-cancel">Cancel</button>
						<button type="submit" class="button button-primary">Submit for Approval</button>
					</div>

					<div class="sfa-ur-form-message" style="display: none;"></div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Get files from entry field
	 *
	 * @param array $entry Entry array
	 * @param int   $field_id Field ID
	 * @return array Array of file URLs
	 */
	private function get_entry_files( $entry, $field_id ) {
		if ( ! isset( $entry[ $field_id ] ) || empty( $entry[ $field_id ] ) ) {
			return [];
		}

		$field_value = $entry[ $field_id ];

		// Handle JSON array (multi-file upload)
		if ( $this->is_json( $field_value ) ) {
			$files = json_decode( $field_value, true );
			return is_array( $files ) ? $files : [];
		}

		// Handle comma-separated URLs
		if ( strpos( $field_value, ',' ) !== false ) {
			return array_map( 'trim', explode( ',', $field_value ) );
		}

		// Single file
		return [ $field_value ];
	}

	/**
	 * Check if string is JSON
	 *
	 * @param string $string
	 * @return bool
	 */
	private function is_json( $string ) {
		json_decode( $string );
		return json_last_error() === JSON_ERROR_NONE;
	}
}
