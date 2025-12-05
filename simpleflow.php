<?php
/**
 * Plugin Name:       SimpleFlow
 * Description:       Core loader for SimpleFlow modules (Simple Flow Attachment, etc.).
 * Version:           0.1.3
 * Author:            Omar Alnabhani
 * Author URI:        https://hdqah.com
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPLv2 or later
 * Text Domain:       simpleflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Basic plugin constants
 */
define( 'SIMPLEFLOW_VER',  '0.1.3' );
define( 'SIMPLEFLOW_FILE', __FILE__ );
define( 'SIMPLEFLOW_PATH', plugin_dir_path( __FILE__ ) );
define( 'SIMPLEFLOW_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Debug logger (optional)
 */
if ( ! function_exists( 'simpleflow_log' ) ) {

	if ( ! defined( 'SIMPLEFLOW_DEBUG' ) ) {
		define( 'SIMPLEFLOW_DEBUG', false );
	}

	function simpleflow_log( $msg, array $ctx = array() ): void {
		$enabled = SIMPLEFLOW_DEBUG
			|| ( defined( 'WP_DEBUG' ) && WP_DEBUG )
			|| apply_filters( 'simpleflow_debug', false );

		if ( ! $enabled ) {
			return;
		}

		if ( ! is_string( $msg ) ) {
			$msg = print_r( $msg, true );
		}

		if ( ! empty( $ctx ) ) {
			$encoded = wp_json_encode( $ctx );
			if ( $encoded ) {
				$msg .= ' | ' . $encoded;
			}
		}

		error_log( '[SimpleFlow] ' . $msg );
	}
}

/**
 * Get modules directory path
 */
function simpleflow_get_modules_dir(): string {
	return trailingslashit( SIMPLEFLOW_PATH ) . 'modules';
}

/**
 * Scan /modules and return available modules.
 *
 * FIXED: Now properly validates directories and skips invalid entries
 */
function simpleflow_scan_modules(): array {
	$modules_dir = simpleflow_get_modules_dir();

	if ( ! is_dir( $modules_dir ) ) {
		simpleflow_log( 'Modules directory does not exist', array( 'dir' => $modules_dir ) );
		return array();
	}

	$dirs = glob( $modules_dir . '/*', GLOB_ONLYDIR );
	if ( empty( $dirs ) ) {
		simpleflow_log( 'No directories found in modules folder', array( 'dir' => $modules_dir ) );
		return array();
	}

	$list = array();

	foreach ( $dirs as $dir ) {
		$dir = trailingslashit( $dir );
		$slug = basename( rtrim( $dir, '/' ) );

		// FIXED: Skip hidden folders and special directories
		if ( empty( $slug ) || $slug[0] === '.' || $slug === 'modules' ) {
			simpleflow_log( 'Skipping invalid directory', array( 'slug' => $slug, 'dir' => $dir ) );
			continue;
		}

		// FIXED: Extra validation - make sure it's actually a directory
		if ( ! is_dir( $dir ) ) {
			simpleflow_log( 'Path is not a directory', array( 'slug' => $slug, 'path' => $dir ) );
			continue;
		}

		// Look for entry file: slug.php or first *.php in dir
		$entry = $dir . $slug . '.php';
		if ( ! file_exists( $entry ) ) {
			$candidates = glob( $dir . '*.php' );
			$entry = ! empty( $candidates ) ? $candidates[0] : '';
		}

		// Only add if we found an entry file
		if ( empty( $entry ) || ! file_exists( $entry ) ) {
			simpleflow_log(
				'No valid entry file found for module',
				array(
					'slug' => $slug,
					'dir' => $dir,
					'expected' => $dir . $slug . '.php',
				)
			);
			continue;
		}

		$list[ $slug ] = array(
			'slug'  => $slug,
			'dir'   => $dir,
			'entry' => $entry,
		);

		simpleflow_log( 'Module discovered', array( 'slug' => $slug, 'entry' => $entry ) );
	}

	return $list;
}

/**
 * Get stored module settings
 */
function simpleflow_get_module_settings(): array {
	$opt = get_option( 'simpleflow_modules', array() );
	return is_array( $opt ) ? $opt : array();
}

/**
 * Check if a module is enabled
 */
function simpleflow_is_module_enabled( string $slug ): bool {
	$settings = simpleflow_get_module_settings();

	if ( empty( $settings ) ) {
		return true;
	}

	if ( array_key_exists( $slug, $settings ) ) {
		return (bool) $settings[ $slug ];
	}

	return true;
}

/**
 * Module loader
 */
function simpleflow_load_modules(): void {
	$modules = simpleflow_scan_modules();

	if ( empty( $modules ) ) {
		simpleflow_log( 'No valid modules found to load' );
		return;
	}

	simpleflow_log( 'Starting module loading', array( 'count' => count( $modules ) ) );

	foreach ( $modules as $slug => $info ) {
		if ( ! simpleflow_is_module_enabled( $slug ) ) {
			simpleflow_log( 'Module disabled, skipping', array( 'slug' => $slug ) );
			continue;
		}

		if ( empty( $info['entry'] ) || ! file_exists( $info['entry'] ) ) {
			simpleflow_log(
				'Module entry file missing',
				array(
					'slug' => $slug,
					'entry' => $info['entry'] ?? 'none',
				)
			);
			continue;
		}

		simpleflow_log( 'Loading module', array( 'slug' => $slug, 'file' => $info['entry'] ) );

		try {
			require_once $info['entry'];
			simpleflow_log( 'Module loaded successfully', array( 'slug' => $slug ) );
		} catch ( Exception $e ) {
			simpleflow_log(
				'Module loading failed',
				array(
					'slug' => $slug,
					'error' => $e->getMessage(),
				)
			);
		}
	}
}

/**
 * Activation / deactivation hooks
 */
register_activation_hook(
	__FILE__,
	function () {
		do_action( 'simpleflow_activated' );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		do_action( 'simpleflow_deactivated' );
	}
);

/**
 * Admin menu
 */
add_action( 'admin_menu', 'simpleflow_admin_menu' );

function simpleflow_admin_menu(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	add_menu_page(
		__( 'SimpleFlow', 'simpleflow' ),
		'SimpleFlow',
		'manage_options',
		'simpleflow',
		'simpleflow_render_modules_page',
		'dashicons-randomize',
		65
	);
}

/**
 * Handle module settings save
 */
add_action( 'admin_post_simpleflow_save_modules', 'simpleflow_handle_save_modules' );

function simpleflow_handle_save_modules(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Access denied.', 'simpleflow' ) );
	}

	check_admin_referer( 'simpleflow_save_modules', 'simpleflow_nonce' );

	$available = simpleflow_scan_modules();
	$slugs     = array_keys( $available );

	$new_settings = array();

	$posted = isset( $_POST['modules'] ) && is_array( $_POST['modules'] )
		? (array) $_POST['modules']
		: array();

	foreach ( $slugs as $slug ) {
		$sanitized_slug = sanitize_key( $slug );
		$new_settings[ $sanitized_slug ] = isset( $posted[ $slug ] ) ? 1 : 0;
	}

	update_option( 'simpleflow_modules', $new_settings );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'    => 'simpleflow',
				'updated' => '1',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Render admin page
 */
function simpleflow_render_modules_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Access denied.', 'simpleflow' ) );
	}

	$modules  = simpleflow_scan_modules();
	$settings = simpleflow_get_module_settings();

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'SimpleFlow Modules', 'simpleflow' ); ?></h1>

		<?php if ( isset( $_GET['updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo esc_html__( 'Modules settings saved.', 'simpleflow' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( empty( $modules ) ) : ?>
			<div class="notice notice-warning">
				<p>
					<?php echo esc_html__( 'No valid modules were found in the /modules directory.', 'simpleflow' ); ?>
				</p>
				<p>
					<strong><?php echo esc_html__( 'Expected structure:', 'simpleflow' ); ?></strong><br>
					<code>
						/wp-content/plugins/simpleflow/modules/<br>
						&nbsp;&nbsp;├── simple-flow-attachment/<br>
						&nbsp;&nbsp;│&nbsp;&nbsp;&nbsp;└── simple-flow-attachment.php<br>
						&nbsp;&nbsp;└── simple-customer-info/<br>
						&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;└── simple-customer-info.php
					</code>
				</p>
			</div>
			</div>
			<?php
			return;
		endif;
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'simpleflow_save_modules', 'simpleflow_nonce' ); ?>
			<input type="hidden" name="action" value="simpleflow_save_modules" />

			<p class="description">
				<?php
				echo esc_html__(
					'Enable or disable SimpleFlow modules. All modules are enabled by default until you save settings.',
					'simpleflow'
				);
				?>
			</p>

			<table class="widefat striped" style="margin-top: 15px;">
				<thead>
					<tr>
						<th style="width: 60px;"><?php echo esc_html__( 'Active', 'simpleflow' ); ?></th>
						<th><?php echo esc_html__( 'Module', 'simpleflow' ); ?></th>
						<th><?php echo esc_html__( 'Entry File', 'simpleflow' ); ?></th>
						<th style="width: 120px;"><?php echo esc_html__( 'Status', 'simpleflow' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $modules as $slug => $info ) : ?>
					<?php
					$enabled = simpleflow_is_module_enabled( $slug );
					$label   = $slug;

					if ( ! empty( $info['entry'] ) && file_exists( $info['entry'] ) ) {
						$data = get_plugin_data( $info['entry'], false, false );
						if ( ! empty( $data['Name'] ) ) {
							$label = $data['Name'];
						}
					}
					?>
					<tr>
						<td style="text-align: center;">
							<label>
								<input type="checkbox"
									name="modules[<?php echo esc_attr( $slug ); ?>]"
									value="1"
									<?php checked( $enabled ); ?>
								/>
							</label>
						</td>
						<td>
							<strong><?php echo esc_html( $label ); ?></strong><br />
							<code style="font-size: 0.9em; color: #666;"><?php echo esc_html( $slug ); ?></code>
						</td>
						<td>
							<code style="font-size: 0.85em; color: #555;">
								<?php echo esc_html( basename( $info['entry'] ) ); ?>
							</code>
							<br />
							<small style="color: #999;">
								<?php echo esc_html( str_replace( ABSPATH, '/', $info['dir'] ) ); ?>
							</small>
						</td>
						<td style="text-align: center;">
							<?php if ( $enabled ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color:#46b450; font-size: 20px;"></span>
								<span style="color:#46b450; font-weight: 600;">
									<?php echo esc_html__( 'Enabled', 'simpleflow' ); ?>
								</span>
							<?php else : ?>
								<span class="dashicons dashicons-dismiss" style="color:#dc3232; font-size: 20px;"></span>
								<span style="color:#dc3232;">
									<?php echo esc_html__( 'Disabled', 'simpleflow' ); ?>
								</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="submit">
				<?php submit_button( __( 'Save Modules', 'simpleflow' ), 'primary', 'submit', false ); ?>
			</p>
		</form>

		<?php if ( defined( 'SIMPLEFLOW_DEBUG' ) && SIMPLEFLOW_DEBUG ) : ?>
			<div class="notice notice-info" style="margin-top: 20px;">
				<p><strong><?php echo esc_html__( 'Debug Info:', 'simpleflow' ); ?></strong></p>
				<pre style="background: #f5f5f5; padding: 10px; overflow: auto; max-height: 300px;">
<?php
echo 'Modules Directory: ' . simpleflow_get_modules_dir() . "\n";
echo 'Found Modules: ' . count( $modules ) . "\n\n";
foreach ( $modules as $slug => $info ) {
	echo "Slug: {$slug}\n";
	echo "  Entry: {$info['entry']}\n";
	echo "  Exists: " . ( file_exists( $info['entry'] ) ? 'Yes' : 'No' ) . "\n";
	echo "  Enabled: " . ( simpleflow_is_module_enabled( $slug ) ? 'Yes' : 'No' ) . "\n\n";
}
?>
				</pre>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * BOOT: Load modules immediately
 */
simpleflow_load_modules();