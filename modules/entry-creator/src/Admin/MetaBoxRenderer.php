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

		// Render-path logging is noisy on a normal entry detail page load,
		// so it is gated behind SFA_EC_DIAG (or WP_DEBUG). The save-path
		// logging in SaveHandler stays always-on so production save_failed
		// events still leave a trail.
		if ( SaveHandler::diag_render_logging_enabled() ) {
			SaveHandler::diag_log( 'render_callback: meta box rendered', array(
				'entry_id'   => $entry_id,
				'form_id'    => $form_id,
				'current_id' => $current_id,
				'actor'      => get_current_user_id(),
			) );
		}

		$user_args  = self::get_selectable_users_args();
		$users      = self::get_selectable_users();
		$user_count = count( $users );
		$user_cap   = isset( $user_args['number'] ) ? (int) $user_args['number'] : 0;
		$truncated  = $user_cap > 0 && $user_count >= $user_cap;

		$action_url = admin_url( 'admin-post.php' );
		$nonce      = wp_create_nonce( self::NONCE_ACTION_PREFIX . $entry_id );

		$allow_none = current_user_can( 'manage_options' );
		?>
		<div id="sfa_ec_panel" style="margin:0;">
			<input type="hidden" id="sfa_ec_entry_id_field" value="<?php echo esc_attr( $entry_id ); ?>" />
			<input type="hidden" id="sfa_ec_form_id_field" value="<?php echo esc_attr( $form_id ); ?>" />
			<input type="hidden" id="sfa_ec_nonce_field" value="<?php echo esc_attr( $nonce ); ?>" />

			<p style="margin:0 0 6px;">
				<strong><?php esc_html_e( 'Current creator:', 'simpleflow' ); ?></strong>
				<span><?php echo esc_html( $current_label ); ?></span>
			</p>

			<p style="margin:0 0 6px;">
				<label for="sfa_ec_filter" style="display:block; margin-bottom:4px;">
					<?php esc_html_e( 'Search users:', 'simpleflow' ); ?>
				</label>
				<input type="search" id="sfa_ec_filter" placeholder="<?php esc_attr_e( 'Type a name, login, email, or #ID…', 'simpleflow' ); ?>" style="width:100%;" autocomplete="off" />
			</p>

			<p style="margin:0 0 6px;">
				<label for="sfa_ec_user_id" style="display:block; margin-bottom:4px;">
					<?php esc_html_e( 'Reassign to:', 'simpleflow' ); ?>
				</label>
				<select id="sfa_ec_user_id" size="8" style="width:100%;">
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
				<small style="color:#666; display:block;">
					<?php
					printf(
						/* translators: %d: number of selectable users */
						esc_html( _n( '%d user available.', '%d users available.', $user_count, 'simpleflow' ) ),
						$user_count
					);
					?>
					<?php if ( $truncated ) : ?>
						<br />
						<span style="color:#b26a00;">
							<?php
							printf(
								/* translators: %d: user cap currently applied to the dropdown */
								esc_html__( 'Showing first %d. Adjust via the sfa_entry_creator_selectable_users filter.', 'simpleflow' ),
								$user_cap
							);
							?>
						</span>
					<?php endif; ?>
				</small>
			</p>

			<p style="margin:0 0 6px;">
				<label for="sfa_ec_reason" style="display:block; margin-bottom:4px;">
					<?php esc_html_e( 'Reason (optional):', 'simpleflow' ); ?>
				</label>
				<input type="text" id="sfa_ec_reason" maxlength="255" style="width:100%;" />
			</p>

			<p style="margin:0;">
				<button type="button" id="sfa_ec_submit" class="button button-primary"><?php esc_html_e( 'Save Creator', 'simpleflow' ); ?></button>
				<span id="sfa_ec_submit_status" style="margin-left:8px; font-size:11px; color:#666;"></span>
			</p>
		</div>
		<script>
		(function () {
			var filter = document.getElementById('sfa_ec_filter');
			var select = document.getElementById('sfa_ec_user_id');
			if (filter && select && !filter.dataset.sfaEcBound) {
				filter.dataset.sfaEcBound = '1';

				// Captured once at init. Always kept in the rebuilt list so the
				// original creator can never silently disappear while filtering.
				var originalSelected = select.value;

				var master = Array.prototype.map.call(select.options, function (opt) {
					return {
						value: opt.value,
						label: opt.textContent,
						search: (opt.getAttribute('data-search') || opt.textContent).toLowerCase()
					};
				});

				filter.addEventListener('input', function () {
					var q = filter.value.trim().toLowerCase();
					var userChoice = select.value;

					while (select.firstChild) { select.removeChild(select.firstChild); }

					master.forEach(function (o) {
						var matchesQuery = !q || o.search.indexOf(q) !== -1;
						var isOriginal   = o.value === originalSelected;
						var isUserChoice = o.value === userChoice;

						if (matchesQuery || isOriginal || isUserChoice) {
							var opt = document.createElement('option');
							opt.value = o.value;
							opt.textContent = o.label;
							if (o.value === userChoice) {
								opt.selected = true;
							}
							select.appendChild(opt);
						}
					});
				});
			}

			// AJAX submit. Avoids HTML's no-nested-<form> rule: GF wraps the
			// entry detail page in its own <form>, which silently dropped our
			// inner <form> tag during HTML parsing and caused the submit
			// button to POST GF's form instead of ours.
			var btn    = document.getElementById('sfa_ec_submit');
			var status = document.getElementById('sfa_ec_submit_status');
			if (btn && !btn.dataset.sfaEcBound) {
				btn.dataset.sfaEcBound = '1';
				btn.addEventListener('click', function () {
					var entryId = (document.getElementById('sfa_ec_entry_id_field') || {}).value || '';
					var formId  = (document.getElementById('sfa_ec_form_id_field')  || {}).value || '';
					var nonce   = (document.getElementById('sfa_ec_nonce_field')    || {}).value || '';
					var sel     = document.getElementById('sfa_ec_user_id');
					var reason  = (document.getElementById('sfa_ec_reason') || {}).value || '';

					if (!sel || !entryId || !formId || !nonce) { return; }

					btn.disabled = true;
					if (status) { status.textContent = '<?php echo esc_js( __( 'Saving…', 'simpleflow' ) ); ?>'; }

					var fd = new FormData();
					fd.append('action',   '<?php echo esc_js( self::POST_ACTION ); ?>');
					fd.append('entry_id', entryId);
					fd.append('form_id',  formId);
					fd.append('<?php echo esc_js( self::NONCE_FIELD ); ?>', nonce);
					fd.append('created_by', sel.value);
					fd.append('reason', reason);

					fetch('<?php echo esc_js( $action_url ); ?>', {
						method: 'POST',
						body: fd,
						credentials: 'same-origin',
						redirect: 'follow'
					}).then(function (resp) {
						// Server redirects back to the entry page with sfa_ec=<code>.
						// fetch with redirect:follow exposes the final URL on resp.url.
						window.location.href = resp.url || window.location.href;
					}).catch(function (err) {
						btn.disabled = false;
						if (status) { status.textContent = '<?php echo esc_js( __( 'Network error — see browser console.', 'simpleflow' ) ); ?>'; }
						if (window.console) { console.error('[SFA EntryCreator] submit failed', err); }
					});
				});
			}
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

		$why = isset( $_GET['sfa_ec_why'] ) ? sanitize_key( wp_unslash( $_GET['sfa_ec_why'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$why_map = array(
			'entry_not_found'       => __( 'Reason: entry could not be loaded (GFAPI::get_entry returned a WP_Error or empty payload).', 'simpleflow' ),
			'update_returned_false' => __( 'Reason: GFAPI::update_entry_property() reported failure. Check wp-content/debug.log with WP_DEBUG_LOG enabled for the underlying $wpdb error.', 'simpleflow' ),
			'verify_read_error'     => __( 'Reason: could not read the entry row back from wp_gf_entry after the write.', 'simpleflow' ),
			'verify_mismatch'       => __( 'Reason: the value stored in wp_gf_entry did not match what we tried to write. Likely a plugin filtering gform_get_entry, a conflicting UPDATE, or aggressive object caching.', 'simpleflow' ),
		);

		$extra = '';
		if ( $why && isset( $why_map[ $why ] ) ) {
			$extra = ' ' . $why_map[ $why ];
		}

		if ( 'save_failed' === $code ) {
			$diag_path = SaveHandler::diag_log_path();
			if ( $diag_path ) {
				/* translators: %s: absolute path to the plugin diagnostic log file */
				$extra .= ' ' . sprintf( __( 'Diagnostic log: %s', 'simpleflow' ), $diag_path );
			}
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s%3$s</p></div>',
			esc_attr( $type ),
			esc_html( $message ),
			esc_html( $extra )
		);
	}

	/**
	 * Capability gate — both caps required.
	 */
	public static function current_user_can_change(): bool {
		return current_user_can( 'gravityforms_edit_entries' ) && current_user_can( 'list_users' );
	}

	/**
	 * Returns the resolved get_users() args, after the
	 * sfa_entry_creator_selectable_users filter has run. Exposed so the render
	 * path can inspect the effective 'number' cap for truncation detection.
	 */
	public static function get_selectable_users_args(): array {
		$default_args = array(
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 1000,
		);

		$args = apply_filters( 'sfa_entry_creator_selectable_users', $default_args );

		return is_array( $args ) ? $args : $default_args;
	}

	/**
	 * Per-request cache so multiple meta box renders (or callers using the
	 * public API) do not re-run the get_users() query within the same
	 * request. Keyed by a serialized hash of the resolved args so a
	 * reasonable filter override does not collide with the default.
	 *
	 * @var array<string,\WP_User[]>
	 */
	private static $users_cache = array();

	/**
	 * @return \WP_User[]
	 */
	public static function get_selectable_users(): array {
		$args = self::get_selectable_users_args();
		$key  = md5( wp_json_encode( $args ) ?: serialize( $args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		if ( isset( self::$users_cache[ $key ] ) ) {
			return self::$users_cache[ $key ];
		}

		$users = get_users( $args );
		$users = is_array( $users ) ? $users : array();

		self::$users_cache[ $key ] = $users;
		return $users;
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
		// Mirror the translated label rendered in render_callback() so localized
		// admin searches hit the same option, then append language-agnostic
		// hints ("0", "none", "empty") so the row stays searchable even when the
		// translated label does not contain them.
		$label  = __( '— No user (system) —', 'simpleflow' );
		$tokens = array( $label, '0', 'none', 'empty' );

		return strtolower( trim( implode( ' ', $tokens ) ) );
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
