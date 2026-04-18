<?php
namespace SFA\ProductionScheduling\Stages;

/**
 * StageRepository
 *
 * Reads and sanitizes the `sfa_prod_stages` form setting — the list of
 * "workflow stages" (name + color + step IDs) configured for one form.
 *
 * Invariants enforced by sanitize_stages():
 *  - `name` required, trimmed, 1..60 chars
 *  - `color` matches /^#[0-9a-f]{6}$/i, normalized to lowercase
 *  - `step_ids` is a non-empty int list, and unique across the whole stage
 *    list (exclusivity: a step can belong to at most one stage). When
 *    Gravity Flow is available, each ID must belong to this form's
 *    workflow; when Gravity Flow is temporarily unavailable, membership is
 *    not checked so the admin can still edit stages.
 */
class StageRepository {

	const FORM_KEY = 'sfa_prod_stages';

	/**
	 * Return the sanitized list of stages configured for this form.
	 *
	 * @param array $form Gravity Forms form array.
	 * @return array<int, array{id:string,name:string,color:string,step_ids:int[]}>
	 */
	public static function get_stages( $form ) {
		if ( ! is_array( $form ) ) {
			return [];
		}
		$raw = isset( $form[ self::FORM_KEY ] ) ? $form[ self::FORM_KEY ] : [];
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw = is_array( $decoded ) ? $decoded : [];
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}
		return self::normalize_for_read( $raw );
	}

	/**
	 * Sanitize a raw POST payload into the stored shape.
	 *
	 * @param mixed $raw  Typically $_POST['sfa_prod_stages'].
	 * @param array $form Form array (used to validate step IDs belong to this form's workflow).
	 * @return array Sanitized stage list (possibly empty).
	 */
	public static function sanitize_stages( $raw, $form ) {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$valid_step_ids = self::collect_workflow_step_ids( $form );
		$used_step_ids  = [];
		$used_stage_ids = [];
		$clean          = [];

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = self::normalize_name( isset( $row['name'] ) ? $row['name'] : '' );
			if ( $name === '' ) {
				continue;
			}

			$color = isset( $row['color'] ) ? strtolower( trim( (string) $row['color'] ) ) : '';
			if ( ! preg_match( '/^#[0-9a-f]{6}$/', $color ) ) {
				continue;
			}

			$step_ids_raw = isset( $row['step_ids'] ) && is_array( $row['step_ids'] ) ? $row['step_ids'] : [];
			$step_ids     = [];
			foreach ( $step_ids_raw as $sid ) {
				$sid_int = (int) $sid;
				if ( $sid_int <= 0 ) {
					continue;
				}
				// Intentional: when Gravity Flow is unavailable, $valid_step_ids is empty
				// and we skip the workflow-membership check rather than fail closed.
				// Admins must still be able to edit stages when GF is briefly deactivated.
				if ( ! empty( $valid_step_ids ) && ! in_array( $sid_int, $valid_step_ids, true ) ) {
					continue;
				}
				if ( isset( $used_step_ids[ $sid_int ] ) ) {
					// Exclusivity: earliest stage wins.
					continue;
				}
				$step_ids[]                 = $sid_int;
				$used_step_ids[ $sid_int ]  = true;
			}
			if ( empty( $step_ids ) ) {
				continue;
			}

			$id = isset( $row['id'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $row['id'] ) ) : '';
			if ( $id === '' || strpos( $id, 'stg_' ) !== 0 ) {
				$id = self::generate_id();
			}
			// Regenerate on collision (e.g. client-side row duplication).
			while ( isset( $used_stage_ids[ $id ] ) ) {
				$id = self::generate_id();
			}
			$used_stage_ids[ $id ] = true;

			$clean[] = [
				'id'       => $id,
				'name'     => $name,
				'color'    => $color,
				'step_ids' => array_values( $step_ids ),
			];
		}

		return $clean;
	}

	/**
	 * Build a flat [step_id => stage] index for one form's stages.
	 *
	 * @param array $stages Sanitized stage list.
	 * @return array<int, array>
	 */
	public static function index_by_step( array $stages ) {
		$index = [];
		foreach ( $stages as $stage ) {
			if ( empty( $stage['step_ids'] ) ) {
				continue;
			}
			foreach ( $stage['step_ids'] as $sid ) {
				$index[ (int) $sid ] = $stage;
			}
		}
		return $index;
	}

	/**
	 * Generate a new stable stage ID.
	 */
	public static function generate_id() {
		return 'stg_' . substr( bin2hex( random_bytes( 3 ) ), 0, 6 );
	}

	/**
	 * Collect every GravityFlow step ID on this form's workflow.
	 *
	 * @return int[] (empty if Gravity Flow is unavailable)
	 */
	private static function collect_workflow_step_ids( $form ) {
		if ( ! is_array( $form ) || empty( $form['id'] ) ) {
			return [];
		}
		if ( ! class_exists( 'Gravity_Flow_API' ) ) {
			return [];
		}
		$api   = new \Gravity_Flow_API( (int) $form['id'] );
		$steps = $api->get_steps();
		if ( ! is_array( $steps ) ) {
			return [];
		}
		$ids = [];
		foreach ( $steps as $step ) {
			$ids[] = (int) $step->get_id();
		}
		return $ids;
	}

	/**
	 * Apply the canonical name pipeline: trim, strip tags/entities, cap at 60 chars.
	 * Used by both sanitize_stages() (write path) and normalize_for_read() (read
	 * path) so hand-edited or legacy form meta can't bypass the length cap or
	 * tag-stripping that the write path enforces.
	 *
	 * @param mixed $raw
	 * @return string '' when the result would be empty after cleaning.
	 */
	private static function normalize_name( $raw ) {
		$name = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		$name = sanitize_text_field( $name );
		if ( $name === '' ) {
			return '';
		}
		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, 60 );
		} else {
			$name = substr( $name, 0, 60 );
		}
		return $name;
	}

	/**
	 * Normalize already-stored data for read callers. Re-runs shape checks
	 * defensively — form meta has been edited by hand before in this codebase.
	 */
	private static function normalize_for_read( array $raw ) {
		$seen_steps = [];
		$out        = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name  = self::normalize_name( isset( $row['name'] ) ? $row['name'] : '' );
			$color = isset( $row['color'] ) ? strtolower( (string) $row['color'] ) : '';
			if ( $name === '' || ! preg_match( '/^#[0-9a-f]{6}$/', $color ) ) {
				continue;
			}
			$step_ids_raw = isset( $row['step_ids'] ) && is_array( $row['step_ids'] ) ? $row['step_ids'] : [];
			$step_ids     = [];
			foreach ( $step_ids_raw as $sid ) {
				$sid_int = (int) $sid;
				if ( $sid_int <= 0 || isset( $seen_steps[ $sid_int ] ) ) {
					continue;
				}
				$step_ids[]              = $sid_int;
				$seen_steps[ $sid_int ]  = true;
			}
			if ( empty( $step_ids ) ) {
				continue;
			}
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( $id === '' ) {
				$id = self::generate_id();
			}
			$out[] = [
				'id'       => $id,
				'name'     => $name,
				'color'    => $color,
				'step_ids' => $step_ids,
			];
		}
		return $out;
	}
}
