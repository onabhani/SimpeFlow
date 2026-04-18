<?php
namespace SFA\ProductionScheduling\Stages;

/**
 * StageResolver
 *
 * Maps [entry_id => form_id] to [entry_id => stage|null] for one request.
 *
 * Per project rules (see CLAUDE.md) we never query gf_entry_meta directly.
 * We use gform_get_meta(), which is backed by Gravity Forms' in-request
 * entry-meta cache — once the rendering code has loaded any meta for an
 * entry, the `workflow_step` lookup is an array hit, not a DB query.
 *
 * If `workflow_step` is absent (some GF versions may not write it), we
 * fall back to Gravity_Flow_API::get_current_step(), but only once per
 * entry; the result is memoized in $this->step_cache.
 */
class StageResolver {

	/** @var array<int, array<int, array>> form_id => (step_id => stage) */
	private $index_by_form = [];

	/** @var array<int, int|null> entry_id => current_step_id|null (per-request memo) */
	private $step_cache = [];

	/** @var array<int, array|false> form_id => GF form array (or false when missing). Memoized to avoid a second GFAPI::get_form() call when the workflow_step fallback runs. */
	private $form_cache = [];

	/** @var bool Whether the "missing workflow_step meta" fallback has been logged this request. */
	private $fallback_logged = false;

	/** @var int Fallback invocations in this request (observability; the fallback runs per-entry). */
	private $fallback_calls = 0;

	/**
	 * @param array<int,int> $entry_form_map [ entry_id => form_id ]
	 * @return array<int, ?array>            [ entry_id => stage|null ]
	 */
	public function resolve_for_entries( array $entry_form_map ): array {
		$out = [];
		if ( empty( $entry_form_map ) ) {
			return $out;
		}

		// 1. Build per-form step->stage index (cached across calls on this instance).
		$form_ids = array_unique( array_map( 'intval', array_values( $entry_form_map ) ) );
		foreach ( $form_ids as $form_id ) {
			if ( isset( $this->index_by_form[ $form_id ] ) ) {
				continue;
			}
			$form                            = $this->get_form_cached( $form_id );
			$stages                          = $form ? StageRepository::get_stages( $form ) : [];
			$this->index_by_form[ $form_id ] = StageRepository::index_by_step( $stages );
		}

		// 2. Resolve each entry.
		foreach ( $entry_form_map as $entry_id => $form_id ) {
			$entry_id = (int) $entry_id;
			$form_id  = (int) $form_id;
			$index    = isset( $this->index_by_form[ $form_id ] ) ? $this->index_by_form[ $form_id ] : [];
			if ( empty( $index ) ) {
				$out[ $entry_id ] = null;
				continue;
			}
			$step_id = $this->get_current_step_id( $entry_id, $form_id );
			$out[ $entry_id ] = ( $step_id && isset( $index[ $step_id ] ) ) ? $index[ $step_id ] : null;
		}

		return $out;
	}

	/**
	 * Resolve the entry's current GravityFlow step ID, or null if none.
	 */
	private function get_current_step_id( $entry_id, $form_id ) {
		if ( array_key_exists( $entry_id, $this->step_cache ) ) {
			return $this->step_cache[ $entry_id ];
		}

		$raw = gform_get_meta( $entry_id, 'workflow_step' );
		$step_id = (int) $raw;

		if ( ! $step_id && class_exists( 'Gravity_Flow_API' ) ) {
			// Fallback: the `workflow_step` entry meta is normally written by
			// Gravity Flow during step processing. If it's missing we fall back
			// to Gravity_Flow_API::get_current_step(), which needs the full
			// entry — that's a DB read per miss, and this path can go O(N) if
			// meta is globally absent. Log the first occurrence per request so
			// the operational cost is visible; $step_cache still memoizes per
			// entry so we don't re-pay within a single resolve_for_entries call.
			$this->fallback_calls++;
			if ( ! $this->fallback_logged && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf(
					'SFA StageResolver: "workflow_step" meta missing for entry #%d (form %d); using Gravity_Flow_API fallback. Subsequent fallback calls this request will not be logged.',
					$entry_id,
					$form_id
				) );
				$this->fallback_logged = true;
			}

			$form  = $this->get_form_cached( $form_id );
			$entry = \GFAPI::get_entry( $entry_id );
			if ( $form && ! is_wp_error( $entry ) ) {
				$api  = new \Gravity_Flow_API( $form_id );
				$step = $api->get_current_step( $form, $entry );
				if ( $step ) {
					$step_id = (int) $step->get_id();
				}
			}
		}

		$this->step_cache[ $entry_id ] = $step_id ?: null;
		return $this->step_cache[ $entry_id ];
	}

	/**
	 * Return the GF form array for a form ID, cached on this instance.
	 * GF's own form cache also memoizes, but keeping a local reference
	 * lets us avoid any repeated calls into GFAPI when the fallback in
	 * get_current_step_id() runs.
	 */
	private function get_form_cached( $form_id ) {
		$form_id = (int) $form_id;
		if ( ! array_key_exists( $form_id, $this->form_cache ) ) {
			$this->form_cache[ $form_id ] = \GFAPI::get_form( $form_id );
		}
		return $this->form_cache[ $form_id ];
	}
}
