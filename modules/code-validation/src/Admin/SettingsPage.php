<?php
namespace SFA\CodeValidation\Admin;

use SFA\CodeValidation\GravityForms\Validator;

/**
 * Settings Page
 *
 * Admin page for managing code validation rules.
 * Each rule maps a target form field to a source form field,
 * validating that the entered value exists in the source entries.
 */
class SettingsPage {

	const MENU_SLUG = 'sfa-code-validation';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], 99 );
		add_action( 'admin_post_sfa_cv_save_rules', [ $this, 'save_rules' ] );
		add_action( 'admin_post_sfa_cv_delete_rule', [ $this, 'delete_rule' ] );
	}

	/**
	 * Add submenu page under SimpleFlow
	 */
	public function add_menu_page() {
		add_submenu_page(
			'simpleflow',
			'Code Validation',
			'Code Validation',
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render settings page
	 */
	public function render_page() {
		$rules = Validator::get_rules();
		$forms = \GFAPI::get_forms();
		$editing = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : -1;
		$edit_rule = ( $editing >= 0 && isset( $rules[ $editing ] ) ) ? $rules[ $editing ] : null;

		?>
		<div class="wrap">
			<h1>Code Validation Rules</h1>
			<p class="description">
				Validate that a value entered in one form exists as an entry value in another form.
				Commonly used for confirmation codes shared with customers.
			</p>

			<?php if ( isset( $_GET['updated'] ) ): ?>
				<div class="notice notice-success is-dismissible"><p>Rule saved.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['deleted'] ) ): ?>
				<div class="notice notice-success is-dismissible"><p>Rule deleted.</p></div>
			<?php endif; ?>

			<!-- Existing rules table -->
			<?php if ( ! empty( $rules ) ): ?>
			<h2>Active Rules</h2>
			<table class="widefat striped" style="max-width:900px;">
				<thead>
					<tr>
						<th>#</th>
						<th>Label</th>
						<th>Target Form &rarr; Field</th>
						<th>Source Form &rarr; Field</th>
						<th>Message</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rules as $i => $rule ): ?>
						<?php
						$target_form_name = $this->get_form_title( $forms, $rule['target_form_id'] );
						$source_form_name = $this->get_form_title( $forms, $rule['source_form_id'] );
						?>
						<tr>
							<td><?php echo $i + 1; ?></td>
							<td><?php echo esc_html( $rule['label'] ?? '—' ); ?></td>
							<td><?php echo esc_html( $target_form_name ); ?> &rarr; Field <?php echo esc_html( $rule['target_field_id'] ); ?></td>
							<td><?php echo esc_html( $source_form_name ); ?> &rarr; Field <?php echo esc_html( $rule['source_field_id'] ); ?></td>
							<td><small><?php echo esc_html( $rule['validation_message'] ?? '' ); ?></small></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( 'edit', $i ) ); ?>" class="button button-small">Edit</a>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sfa_cv_delete_rule&rule_index=' . $i ), 'sfa_cv_delete_' . $i ) ); ?>"
								   class="button button-small"
								   onclick="return confirm('Delete this rule?');"
								   style="color:#a00;">Delete</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<!-- Add / Edit form -->
			<h2><?php echo $edit_rule ? 'Edit Rule' : 'Add New Rule'; ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:600px;">
				<input type="hidden" name="action" value="sfa_cv_save_rules">
				<?php wp_nonce_field( 'sfa_cv_save_rules' ); ?>

				<?php if ( $editing >= 0 ): ?>
					<input type="hidden" name="rule_index" value="<?php echo $editing; ?>">
				<?php endif; ?>

				<table class="form-table">
					<tr>
						<th><label for="rule_label">Label</label></th>
						<td>
							<input type="text" name="rule_label" id="rule_label" class="regular-text"
								value="<?php echo esc_attr( $edit_rule['label'] ?? '' ); ?>"
								placeholder="e.g. GPS Locator Mapping">
							<p class="description">A descriptive name for this rule (for your reference).</p>
						</td>
					</tr>
					<tr>
						<th><label for="target_form_id">Target Form</label></th>
						<td>
							<select name="target_form_id" id="target_form_id" required>
								<option value="">-- Select --</option>
								<?php foreach ( $forms as $f ): ?>
									<option value="<?php echo $f['id']; ?>" <?php selected( $edit_rule['target_form_id'] ?? '', $f['id'] ); ?>>
										<?php echo esc_html( $f['title'] ); ?> (ID: <?php echo $f['id']; ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">The form where the user enters the code.</p>
						</td>
					</tr>
					<tr>
						<th><label for="target_field_id">Target Field ID</label></th>
						<td>
							<input type="number" name="target_field_id" id="target_field_id" class="small-text" min="1" required
								value="<?php echo esc_attr( $edit_rule['target_field_id'] ?? '' ); ?>">
							<p class="description">The field ID in the target form where the code is entered.</p>
						</td>
					</tr>
					<tr>
						<th><label for="source_form_id">Source Form</label></th>
						<td>
							<select name="source_form_id" id="source_form_id" required>
								<option value="">-- Select --</option>
								<?php foreach ( $forms as $f ): ?>
									<option value="<?php echo $f['id']; ?>" <?php selected( $edit_rule['source_form_id'] ?? '', $f['id'] ); ?>>
										<?php echo esc_html( $f['title'] ); ?> (ID: <?php echo $f['id']; ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">The form whose entries contain the valid codes.</p>
						</td>
					</tr>
					<tr>
						<th><label for="source_field_id">Source Field ID</label></th>
						<td>
							<input type="number" name="source_field_id" id="source_field_id" class="small-text" min="1" required
								value="<?php echo esc_attr( $edit_rule['source_field_id'] ?? '' ); ?>">
							<p class="description">The field ID in the source form that holds the valid codes.</p>
						</td>
					</tr>
					<tr>
						<th><label for="validation_message">Validation Message</label></th>
						<td>
							<input type="text" name="validation_message" id="validation_message" class="regular-text"
								value="<?php echo esc_attr( $edit_rule['validation_message'] ?? '' ); ?>"
								placeholder="الرمز خاطئ, يرجى التأكد">
							<p class="description">Error message shown when the code is invalid.</p>
						</td>
					</tr>
				</table>

				<p>
					<button type="submit" class="button button-primary">
						<?php echo $edit_rule ? 'Update Rule' : 'Add Rule'; ?>
					</button>
					<?php if ( $edit_rule ): ?>
						<a href="<?php echo esc_url( remove_query_arg( 'edit' ) ); ?>" class="button">Cancel</a>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle save rule form submission
	 */
	public function save_rules() {
		check_admin_referer( 'sfa_cv_save_rules' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		$rules = Validator::get_rules();

		$rule = [
			'label'              => sanitize_text_field( $_POST['rule_label'] ?? '' ),
			'target_form_id'     => absint( $_POST['target_form_id'] ),
			'target_field_id'    => absint( $_POST['target_field_id'] ),
			'source_form_id'     => absint( $_POST['source_form_id'] ),
			'source_field_id'    => absint( $_POST['source_field_id'] ),
			'validation_message' => sanitize_text_field( $_POST['validation_message'] ?? '' ),
			'validate_blank_values' => true,
		];

		// Update existing or add new
		$rule_index = isset( $_POST['rule_index'] ) ? (int) $_POST['rule_index'] : -1;

		if ( $rule_index >= 0 && isset( $rules[ $rule_index ] ) ) {
			$rules[ $rule_index ] = $rule;
		} else {
			$rules[] = $rule;
		}

		Validator::save_rules( $rules );

		wp_redirect( add_query_arg(
			[ 'page' => self::MENU_SLUG, 'updated' => '1' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Handle delete rule
	 */
	public function delete_rule() {
		$rule_index = isset( $_GET['rule_index'] ) ? (int) $_GET['rule_index'] : -1;

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'sfa_cv_delete_' . $rule_index ) ) {
			wp_die( 'Invalid nonce' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		$rules = Validator::get_rules();

		if ( isset( $rules[ $rule_index ] ) ) {
			array_splice( $rules, $rule_index, 1 );
			Validator::save_rules( $rules );
		}

		wp_redirect( add_query_arg(
			[ 'page' => self::MENU_SLUG, 'deleted' => '1' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Get form title by ID
	 */
	private function get_form_title( $forms, $form_id ) {
		foreach ( $forms as $f ) {
			if ( (int) $f['id'] === (int) $form_id ) {
				return $f['title'] . ' (#' . $f['id'] . ')';
			}
		}
		return 'Form #' . $form_id;
	}
}
