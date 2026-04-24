<?php
namespace SFA\EntryCreator\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MetaBoxRenderer {

	const NONCE_ACTION_PREFIX = 'sfa_ec_change_creator_';
	const NONCE_FIELD         = 'sfa_ec_nonce';
	const POST_ACTION         = 'sfa_ec_change_creator';

	public function __construct() {
		add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'register_meta_box' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * @param array  $meta_boxes
	 * @param array  $entry
	 * @param array  $form
	 */
	public function register_meta_box( $meta_boxes, $entry, $form ) {
		if ( ! self::current_user_can_change() ) {
			return $meta_boxes;
		}

		$meta_boxes['sfa_entry_creator'] = array(
			'title'    => esc_html__( 'Change Entry Creator', 'simpleflow' ),
			'callback' => array( $this, 'render_callback' ),
			'context'  => 'side',
			'priority' => 'default',
		);

		return $meta_boxes;
	}

	public function render_callback( $args ) {
		$entry = isset( $args['entry'] ) ? $args['entry'] : null;
		$form  = isset( $args['form'] ) ? $args['form'] : null;

		if ( ! $entry || empty( $entry['id'] ) || ! $form || empty( $form['id'] ) ) {
			return;
		}

		if ( ! self::current_user_can_change() ) {
			return;
		}

		$entry_id      = (int) $entry['id'];
		$form_id       = (int) $form['id'];
		$current_id    = (int) ( $entry['created_by'] ?? 0 );
		$current_label = self::format_user_label( $current_id );

		$users = self::get_selectable_users();

		$action_url = admin_url( 'admin-post.php' );
		$nonce      = wp_create_nonce( self::NONCE_ACTION_PREFIX . $entry_id );

		$allow_none = current_user_can( 'manage_options' );
		?>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin:0;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::POST_ACTION ); ?>" />
			<input type="hidden" name="entry_id" value="<?php echo esc_attr( $entry_id ); ?>" />
			<input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( self::NONCE_FIELD ); ?>" value="<?php echo esc_attr( $nonce ); ?>" />

			<p style="margin:0 0 6px;">
				<strong><?php esc_html_e( 'Current creator:', 'simpleflow' ); ?></strong>
				<span><?php echo esc_html( $current_label ); ?></span>
			</p>

			<p style="margin:0 0 6px;">
				<label for="sfa_ec_filter" style="display:block; margin-bottom:4px;">
					<?php esc_html_e( 'Search users:', 'simpleflow' ); ?>
				</label>
				<input type="search" id="sfa_ec_filter" placeholder="<?php esc_attr_e( 'Type a name, login, or email…', 'simpleflow' ); ?>" style="width:100%;" autocomplete="off" />
			</p>

			<p style="margin:0 0 6px;">
				<label for="sfa_ec_user_id" style="display:block; margin-bottom:4px;">
					<?php esc_html_e( 'Reassign to:', 'simpleflow' ); ?>
				</label>
				<select name="created_by" id="sfa_ec_user_id" size="8" style="width:100%;">
					<?php if ( $allow_none ) : ?>
						<option value="0" data-search="<?php echo esc_attr( self::search_token_for_none() ); ?>" <?php selected( 0, $current_id ); ?>>
							<?php esc_html_e( '— No user (system) —', 'simpleflow' ); ?>
						</option>
					<?php endif; ?>
					<?php foreach ( $users as $user ) : ?>
						<option value="<?php echo esc_attr( (int) $user->ID ); ?>" data-search="<?php echo esc_attr( self::search_token_for_user( $user ) ); ?>" <?php selected( (int) $user->ID, $current_id ); ?>>
							<?php echo esc_html( self::format_user_option( $user ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<small style="color:#666;">
					<?php
					printf(
						/* translators: %d: number of selectable users */
						esc_html__( '%d users available.', 'simpleflow' ),
						count( $users )
					);
					?>
				</small>
			</p>

			<p style="margin:0 0 6px;">
				<label for="sfa_ec_reason" style="display:block; margin-bottom:4px;">
					<?php esc_html_e( 'Reason (optional):', 'simpleflow' ); ?>
				</label>
				<input type="text" name="reason" id="sfa_ec_reason" maxlength="255" style="width:100%;" />
			</p>

			<p style="margin:0;">
				<?php submit_button( __( 'Save Creator', 'simpleflow' ), 'primary small', 'submit', false ); ?>
			</p>
		</form>
		<script>
		(function () {
			var filter = document.getElementById('sfa_ec_filter');
			var select = document.getElementById('sfa_ec_user_id');
			if (!filter || !select || filter.dataset.sfaEcBound) { return; }
			filter.dataset.sfaEcBound = '1';

			var master = Array.prototype.map.call(select.options, function (opt) {
				return {
					value: opt.value,
					label: opt.textContent,
					search: (opt.getAttribute('data-search') || opt.textContent).toLowerCase()
				};
			});

			filter.addEventListener('input', function () {
				var q = filter.value.trim().toLowerCase();
				var current = select.value;
				var matchedCurrent = false;

				while (select.firstChild) { select.removeChild(select.firstChild); }

				master.forEach(function (o) {
					if (!q || o.search.indexOf(q) !== -1) {
						var opt = document.createElement('option');
						opt.value = o.value;
						opt.textContent = o.label;
						if (o.value === current) {
							opt.selected = true;
							matchedCurrent = true;
						}
						select.appendChild(opt);
					}
				});

				if (!matchedCurrent && select.options.length > 0) {
					select.options[0].selected = true;
				}
			});
		})();
		</script>
		<?php
	}

	public function render_notices() {
		if ( ! isset( $_GET['page'] ) || 'gf_entries' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! isset( $_GET['sfa_ec'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code = sanitize_key( wp_unslash( $_GET['sfa_ec'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$map = array(
			'updated'       => array( 'success', __( 'Entry creator updated.', 'simpleflow' ) ),
			'nochange'      => array( 'info',    __( 'No change made — selected user is already the current creator.', 'simpleflow' ) ),
			'invalid_nonce' => array( 'error',   __( 'Security check failed. Please reload and try again.', 'simpleflow' ) ),
			'no_permission' => array( 'error',   __( 'You do not have permission to change the entry creator.', 'simpleflow' ) ),
			'invalid_user'  => array( 'error',   __( 'Invalid user selected.', 'simpleflow' ) ),
			'save_failed'   => array( 'error',   __( 'Could not save the new entry creator. Please try again.', 'simpleflow' ) ),
		);

		if ( ! isset( $map[ $code ] ) ) {
			return;
		}

		list( $type, $message ) = $map[ $code ];

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Capability gate — both caps required.
	 */
	public static function current_user_can_change(): bool {
		return current_user_can( 'gravityforms_edit_entries' ) && current_user_can( 'list_users' );
	}

	/**
	 * @return \WP_User[]
	 */
	public static function get_selectable_users(): array {
		$default_args = array(
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 1000,
		);

		$args = apply_filters( 'sfa_entry_creator_selectable_users', $default_args );

		$users = get_users( is_array( $args ) ? $args : $default_args );

		return is_array( $users ) ? $users : array();
	}

	public static function search_token_for_user( \WP_User $user ): string {
		$parts = array(
			$user->display_name,
			$user->user_login,
			$user->user_email,
			'#' . (int) $user->ID,
		);

		return strtolower( trim( implode( ' ', array_filter( array_map( 'strval', $parts ) ) ) ) );
	}

	public static function search_token_for_none(): string {
		return 'no user system none empty 0';
	}

	public static function format_user_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return __( '— No user (system) —', 'simpleflow' );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			/* translators: %d: WordPress user ID */
			return sprintf( __( '(deleted user #%d)', 'simpleflow' ), $user_id );
		}

		return sprintf( '%s (#%d)', $user->display_name, $user_id );
	}

	public static function format_user_option( \WP_User $user ): string {
		$name  = $user->display_name ? $user->display_name : $user->user_login;
		$email = $user->user_email ? ' — ' . $user->user_email : '';

		return sprintf( '%s (#%d)%s', $name, (int) $user->ID, $email );
	}
}
