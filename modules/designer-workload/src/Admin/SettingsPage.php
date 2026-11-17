<?php
namespace SFA\DesignerWorkload\Admin;

use SFA\DesignerWorkload\Data\WorkloadRepository;
use SFA\DesignerWorkload\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings > SimpleFlow > Designer Workload: who may view the page, which forms count.
 */
class SettingsPage {

	const MENU_SLUG = 'sfa-designer-workload';
	const ACTION    = 'sfa_dw_save';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 99 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page(
			'simpleflow',
			__( 'Designer Workload', 'simpleflow' ),
			__( 'Designer Workload', 'simpleflow' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'simpleflow' ) );
		}
		check_admin_referer( self::ACTION );

		$raw = isset( $_POST['sfa_dw'] ) && is_array( $_POST['sfa_dw'] ) ? wp_unslash( $_POST['sfa_dw'] ) : array();
		update_option( Settings::OPTION, Settings::sanitize( $raw ), false );
		WorkloadRepository::flush();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::MENU_SLUG,
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'simpleflow' ) );
		}

		$settings = Settings::get();
		$roles    = wp_roles()->get_names();
		unset( $roles['administrator'] );
		asort( $roles );

		$users = get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name', 'user_login' ),
			)
		);
		$forms = \GFAPI::get_forms( true, false, 'title' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Designer Workload', 'simpleflow' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Settings saved.', 'simpleflow' ); ?></p></div>
			<?php endif; ?>
			<p>
				<?php echo esc_html__( 'Place the shortcode [designer_workload] on a page. Administrators can always view it.', 'simpleflow' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Roles that can view', 'simpleflow' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $roles as $slug => $name ) : ?>
									<label style="display:block">
										<input type="checkbox" name="sfa_dw[roles][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $settings['roles'], true ) ); ?>>
										<?php echo esc_html( translate_user_role( $name ) ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sfa-dw-users"><?php echo esc_html__( 'Users that can view', 'simpleflow' ); ?></label></th>
						<td>
							<select id="sfa-dw-users" name="sfa_dw[users][]" multiple size="12" style="min-width:320px">
								<?php foreach ( $users as $user ) : ?>
									<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( in_array( (int) $user->ID, $settings['users'], true ) ); ?>>
										<?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Hold Ctrl (Cmd on Mac) to pick more than one. Use this for people who have no role yet.', 'simpleflow' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Forms to include', 'simpleflow' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $forms as $form ) : ?>
									<label style="display:block">
										<input type="checkbox" name="sfa_dw[forms][]" value="<?php echo esc_attr( (string) $form['id'] ); ?>" <?php checked( in_array( (int) $form['id'], $settings['forms'], true ) ); ?>>
										<?php echo esc_html( $form['title'] . ' (#' . $form['id'] . ')' ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
