<?php
namespace SFA\CustomerLookup\Ajax;

use SFA\CustomerLookup\Database\CustomerRepository;

/**
 * AJAX Lookup Handler
 *
 * Handles phone-based customer lookup requests.
 * Nonce, capability, sanitization, rate limiting, and response shaping.
 */
class LookupHandler {

	public function __construct() {
		add_action( 'wp_ajax_sfa_cl_lookup', [ $this, 'handle' ] );
		// No wp_ajax_nopriv — unauthenticated requests get WordPress default 0 response

		add_action( 'gform_enqueue_scripts', [ $this, 'enqueue_for_form' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );

		// Cache invalidation when source entries change
		add_action( 'gform_after_update_entry', [ CustomerRepository::class, 'on_entry_changed' ] );
		add_action( 'gform_entry_created', [ CustomerRepository::class, 'on_entry_changed' ] );
	}

	/**
	 * Enqueue the lookup JS when a GF form renders on the frontend.
	 * Hooked to gform_enqueue_scripts which fires per form.
	 *
	 * @param array $form
	 */
	public function enqueue_for_form( $form ) {
		$this->maybe_enqueue();
	}

	/**
	 * Enqueue the lookup JS on admin pages that may contain GF views
	 * (entry detail, GravityFlow inbox).
	 */
	public function enqueue_admin() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		// Only on GF entry pages or GravityFlow pages
		$allowed = [ 'toplevel_page_gf_edit_forms', 'forms_page_gf_entries' ];
		$is_gf   = strpos( $screen->id, 'gravityflow' ) !== false
				 || strpos( $screen->id, 'gf_' ) !== false
				 || in_array( $screen->id, $allowed, true );

		if ( ! $is_gf ) {
			return;
		}

		$this->maybe_enqueue();
	}

	/**
	 * Enqueue script and localize data if module is configured.
	 */
	private function maybe_enqueue() {
		// Prevent double-enqueue
		if ( wp_script_is( 'sfa-cl-lookup', 'enqueued' ) ) {
			return;
		}

		$form_id = (int) get_option( 'sfa_cl_source_form_id', 0 );
		if ( ! $form_id ) {
			return;
		}

		$field_map = get_option( 'sfa_cl_field_map', [] );
		if ( empty( $field_map ) || empty( $field_map['phone'] ) ) {
			return;
		}

		wp_enqueue_script(
			'sfa-cl-lookup',
			SFA_CL_URL . 'assets/customer-lookup.js',
			[ 'jquery' ],
			SFA_CL_VER,
			true
		);

		wp_localize_script( 'sfa-cl-lookup', 'sfaClLookup', [
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'sfa_cl_lookup' ),
			'field_map_keys' => array_keys( array_filter( $field_map ) ),
			'i18n'           => [
				'not_found' => __( 'Number not registered', 'simpleflow' ),
			],
		] );
	}

	/**
	 * Handle the AJAX lookup request.
	 */
	public function handle() {
		// 1. Verify nonce — same response shape as "not found" to prevent enumeration
		if ( ! check_ajax_referer( 'sfa_cl_lookup', '_wpnonce', false ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SFA Customer Lookup: nonce verification failed for user ' . get_current_user_id() );
			}
			wp_send_json_success( [ 'found' => false ] );
			return;
		}

		// 2. Capability check
		if ( ! current_user_can( 'gform_full_access' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SFA Customer Lookup: capability check failed for user ' . get_current_user_id() );
			}
			wp_send_json_success( [ 'found' => false ] );
			return;
		}

		// 3. Sanitize phone
		$raw   = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$phone = preg_replace( '/[^0-9+]/', '', $raw );
		// Only leading + allowed
		$phone      = preg_replace( '/(?!^)\+/', '', $phone );
		$digits_only = ltrim( $phone, '+' );

		// 4. Validate: 9-15 actual digits
		if ( ! preg_match( '/^\d{9,15}$/', $digits_only ) ) {
			wp_send_json_success( [ 'found' => false ] );
		}

		// 5. Rate limit: 10 req/min per user (best-effort via transient)
		$user_id   = get_current_user_id();
		$rate_key  = 'sfa_cl_rl_' . $user_id;
		$rate_count = (int) get_transient( $rate_key );

		if ( $rate_count >= 10 ) {
			wp_send_json_error(
				__( 'Too many requests. Please try again shortly.', 'simpleflow' ),
				429
			);
		}

		set_transient( $rate_key, $rate_count + 1, MINUTE_IN_SECONDS );

		// 6. Query
		$result = CustomerRepository::find_by_phone( $digits_only );
		$found  = is_array( $result ) && ! empty( $result );

		// 7. Audit log
		do_action( 'sfa_cl_lookup_performed', $user_id, $digits_only, $found );

		// 8. Response (same shape for found/not-found to prevent enumeration)
		if ( $found ) {
			wp_send_json_success( [ 'found' => true, 'fields' => $result ] );
		}

		wp_send_json_success( [ 'found' => false ] );
	}
}
