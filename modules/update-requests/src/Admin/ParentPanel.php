<?php
namespace SFA\UpdateRequests\Admin;

/**
 * Parent Panel
 *
 * Displays all update request child entries in parent job entry detail page
 * Shows in both admin and workflow-inbox frontend
 */
class ParentPanel {

	public function __construct() {
		// Add panel to entry detail page (admin)
		add_action( 'gform_entry_detail_sidebar_middle', [ $this, 'render_panel' ], 10, 2 );

		// Add panel to workflow-inbox frontend
		add_action( 'gravityflow_entry_detail_sidebar_middle', [ $this, 'render_panel' ], 10, 2 );
	}

	/**
	 * Render update requests panel
	 *
	 * @param array $form
	 * @param array $entry
	 */
	public function render_panel( $form, $entry ) {
		$entry_id = (int) $entry['id'];

		// Get children from parent meta
		$children_json = gform_get_meta( $entry_id, '_ur_children' );
		$children = $children_json ? json_decode( $children_json, true ) : [];

		if ( ! is_array( $children ) || empty( $children ) ) {
			// No update requests for this entry
			return;
		}

		// Render panel
		?>
		<div class="postbox" style="margin-top: 20px;">
			<h3 class="hndle" style="padding: 10px; cursor: default;">
				<span>📋 Update Requests (<?php echo count( $children ); ?>)</span>
			</h3>
			<div class="inside" style="padding: 10px;">
				<p style="margin: 0 0 10px; color: #666; font-size: 13px;">
					All update requests submitted for this job entry
				</p>

				<table class="widefat striped" style="width: 100%; border: 1px solid #ddd;">
					<thead>
						<tr>
							<th style="padding: 8px;">Entry ID</th>
							<th style="padding: 8px;">Type</th>
							<th style="padding: 8px;">Status</th>
							<th style="padding: 8px;">Submitted</th>
							<th style="padding: 8px;">Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $children as $child ): ?>
							<?php
							$child_entry_id = isset( $child['entry_id'] ) ? $child['entry_id'] : 0;
							$request_type = isset( $child['request_type'] ) ? $child['request_type'] : 'unknown';
							$status = isset( $child['status'] ) ? $child['status'] : 'submitted';
							$submitted_at = isset( $child['submitted_at'] ) ? $child['submitted_at'] : '';
							$submitted_by = isset( $child['submitted_by'] ) ? $child['submitted_by'] : 0;

							// Get child entry
							$child_entry = \GFAPI::get_entry( $child_entry_id );
							if ( is_wp_error( $child_entry ) || ! $child_entry ) {
								continue;
							}

							// Get latest status from child entry meta
							$latest_status = gform_get_meta( $child_entry_id, '_ur_status' );
							if ( $latest_status ) {
								$status = $latest_status;
							}

							// Format type label
							$type_label = $request_type === 'entry_updating' ? 'Design Change' : 'Add Invoice Item';

							// Format status badge
							$status_color = $this->get_status_color( $status );
							$status_label = ucfirst( str_replace( '_', ' ', $status ) );

							// Get submitter
							$submitter = get_userdata( $submitted_by );
							$submitter_name = $submitter ? $submitter->display_name : 'Unknown';

							// Build entry URL (workflow-inbox)
							$entry_url = home_url( '/workflow-inbox/' ) . '?page=gravityflow-inbox&view=entry&id=' . $child_entry['form_id'] . '&lid=' . $child_entry_id;
							?>
							<tr>
								<td style="padding: 8px;">
									<a href="<?php echo esc_url( $entry_url ); ?>" target="_blank">
										#<?php echo $child_entry_id; ?>
									</a>
								</td>
								<td style="padding: 8px;">
									<?php echo esc_html( $type_label ); ?>
								</td>
								<td style="padding: 8px;">
									<span style="display: inline-block; padding: 3px 8px; background: <?php echo $status_color; ?>; color: white; border-radius: 3px; font-size: 11px; font-weight: 500;">
										<?php echo esc_html( $status_label ); ?>
									</span>
								</td>
								<td style="padding: 8px; font-size: 12px;">
									<?php echo date( 'M j, Y g:i a', strtotime( $submitted_at ) ); ?>
									<br>
									<small style="color: #666;">by <?php echo esc_html( $submitter_name ); ?></small>
								</td>
								<td style="padding: 8px;">
									<a href="<?php echo esc_url( $entry_url ); ?>" target="_blank" class="button button-small">
										View
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p style="margin: 10px 0 0; font-size: 12px; color: #666;">
					<strong>Tip:</strong> Click "View" to see full update request details
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Get status color for badge
	 *
	 * @param string $status
	 * @return string
	 */
	private function get_status_color( $status ) {
		$colors = [
			'submitted' => '#0073aa', // Blue
			'approved' => '#46b450', // Green
			'rejected' => '#dc3232', // Red
			'pending' => '#ffb900', // Yellow
		];

		return isset( $colors[ $status ] ) ? $colors[ $status ] : '#666';
	}
}
