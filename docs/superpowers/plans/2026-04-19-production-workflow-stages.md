# Production Workflow Stages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-form "workflow stages" (name + hex color + list of GravityFlow steps) configurable in the form-level Production Scheduling Settings, and render a colored pill next to each entry's number on both the backend (`ScheduleView`) and frontend (`FrontendCalendar`) production bookings tables when the entry's current workflow step belongs to a configured stage.

**Architecture:** Two small helper classes (`StageResolver`, `StageBadge`) in a new `src/Stages/` namespace handle runtime resolution and rendering. The existing form-settings page gains a new "Workflow Stages" section with WP core `wp-color-picker` + a small vanilla-JS file for add/remove/exclusivity. The existing save handler is extended. Both bookings-list renderers call the resolver once per render and append the badge inside their Entry # cell. No DB migrations, no new options, no new AJAX endpoints.

**Tech Stack:** PHP 8.2+, WordPress, Gravity Forms (`GFAPI`, `gform_get_meta`), Gravity Flow (`Gravity_Flow_API`), WordPress core `wp-color-picker`, vanilla JS.

**Project testing conventions (important):** This project has **no automated test suite**. CLAUDE.md mandates `php -l <file>` for syntax checks and manual browser verification for behavior. Each behavioral task in this plan therefore substitutes "syntax check + documented manual smoke test" for TDD. Do not introduce PHPUnit, Jest, or any new test harness as part of this work — doing so expands scope beyond the spec.

**Spec:** `docs/superpowers/specs/2026-04-19-production-workflow-stages-design.md`

---

## File Structure

**New files:**

- `modules/production-scheduling/src/Stages/StageResolver.php` — `resolve_for_entries( [entry_id => form_id] ): [entry_id => stage|null]`. Caches per-form step→stage index and per-entry current-step ID within a single request.
- `modules/production-scheduling/src/Stages/StageBadge.php` — static `render( array $stage ): string`. Escapes name, validates hex, picks luminance-based text color.
- `modules/production-scheduling/src/Stages/StageRepository.php` — static helpers `get_stages( array $form ): array`, `sanitize_stages( mixed $raw, array $form ): array`. Single source of truth for the shape/validation rules so both the save handler and the renderers agree.
- `modules/production-scheduling/assets/js/form-settings-stages.js` — add row, remove row, live exclusivity toggling, color-picker init.

**Modified files:**

- `modules/production-scheduling/src/Admin/FormSettings.php` — insert "Workflow Stages" section markup before the closing `</table>`, enqueue color picker + new JS, extend `save_form_settings()` to persist stages via `StageRepository::sanitize_stages()`.
- `modules/production-scheduling/src/Admin/ScheduleView.php` — inside `render_bookings_list()`, call resolver once, append badge inside the Entry # cell.
- `modules/production-scheduling/src/Frontend/FrontendCalendar.php` — same inside its own `render_bookings_list()`.
- `modules/production-scheduling/production-scheduling.php` — bump `Version:` header and `SFA_PROD_VER` constant from `1.4.0` → `1.5.0`.

---

## Task 1: Create `StageRepository` (data shape + validation)

**Files:**
- Create: `modules/production-scheduling/src/Stages/StageRepository.php`

Rationale: This is the single source of truth for what a stage looks like. Writing it first means every other task (save handler, resolver, UI rendering) consumes the same shape.

- [ ] **Step 1: Create the file with namespace skeleton**

Create `modules/production-scheduling/src/Stages/StageRepository.php`:

```php
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
 *  - `step_ids` is a non-empty int list, each ID present in the form's
 *    GravityFlow workflow, and unique across the whole stage list
 *    (exclusivity: a step can belong to at most one stage).
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
		$clean          = [];

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
			$name = sanitize_text_field( $name );
			if ( $name === '' ) {
				continue;
			}
			if ( function_exists( 'mb_substr' ) ) {
				$name = mb_substr( $name, 0, 60 );
			} else {
				$name = substr( $name, 0, 60 );
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
			$name  = isset( $row['name'] ) ? (string) $row['name'] : '';
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
```

- [ ] **Step 2: Syntax check**

Run: `php -l modules/production-scheduling/src/Stages/StageRepository.php`
Expected: `No syntax errors detected in modules/production-scheduling/src/Stages/StageRepository.php`

- [ ] **Step 3: Commit**

```bash
git add modules/production-scheduling/src/Stages/StageRepository.php
git commit -m "Production stages: add StageRepository with read/sanitize helpers"
```

---

## Task 2: Create `StageBadge` renderer

**Files:**
- Create: `modules/production-scheduling/src/Stages/StageBadge.php`

- [ ] **Step 1: Write the class**

Create `modules/production-scheduling/src/Stages/StageBadge.php`:

```php
<?php
namespace SFA\ProductionScheduling\Stages;

/**
 * StageBadge
 *
 * Renders the inline colored pill shown next to an entry number on the
 * production bookings tables. Defense-in-depth: re-validates the hex at
 * render time and drops the badge if the shape is wrong.
 */
class StageBadge {

	/**
	 * @param array $stage Stage array from StageRepository.
	 * @return string HTML snippet or empty string.
	 */
	public static function render( $stage ) {
		if ( ! is_array( $stage ) ) {
			return '';
		}
		$name  = isset( $stage['name'] ) ? (string) $stage['name'] : '';
		$color = isset( $stage['color'] ) ? strtolower( (string) $stage['color'] ) : '';
		if ( $name === '' || ! preg_match( '/^#[0-9a-f]{6}$/', $color ) ) {
			return '';
		}

		$text_color = self::best_text_color( $color );

		return sprintf(
			'<span class="sfa-prod-stage-badge" style="background:%1$s;color:%2$s;">%3$s</span>',
			esc_attr( $color ),
			esc_attr( $text_color ),
			esc_html( $name )
		);
	}

	/**
	 * Pick #222 on light backgrounds and #fff on dark ones, using the
	 * relative-luminance formula from WCAG.
	 */
	private static function best_text_color( $hex ) {
		$r = hexdec( substr( $hex, 1, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 3, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 5, 2 ) ) / 255;
		$lin = function ( $c ) {
			return ( $c <= 0.03928 ) ? ( $c / 12.92 ) : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};
		$L = 0.2126 * $lin( $r ) + 0.7152 * $lin( $g ) + 0.0722 * $lin( $b );
		return $L > 0.5 ? '#222222' : '#ffffff';
	}

	/**
	 * The small CSS block that styles the badge. Embedded once per table
	 * render so neither ScheduleView nor FrontendCalendar needs a new CSS
	 * enqueue.
	 */
	public static function css() {
		return '<style>.sfa-prod-stage-badge{display:inline-block;margin-left:6px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;line-height:1.4;vertical-align:middle;white-space:nowrap;}</style>';
	}
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l modules/production-scheduling/src/Stages/StageBadge.php`
Expected: `No syntax errors detected in modules/production-scheduling/src/Stages/StageBadge.php`

- [ ] **Step 3: Commit**

```bash
git add modules/production-scheduling/src/Stages/StageBadge.php
git commit -m "Production stages: add StageBadge renderer"
```

---

## Task 3: Create `StageResolver`

**Files:**
- Create: `modules/production-scheduling/src/Stages/StageResolver.php`

- [ ] **Step 1: Write the class**

Create `modules/production-scheduling/src/Stages/StageResolver.php`:

```php
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

	/**
	 * @param array<int,int> $entry_form_map [ entry_id => form_id ]
	 * @return array<int, ?array>            [ entry_id => stage|null ]
	 */
	public function resolve_for_entries( array $entry_form_map ) {
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
			$form                              = \GFAPI::get_form( $form_id );
			$stages                            = $form ? StageRepository::get_stages( $form ) : [];
			$this->index_by_form[ $form_id ]   = StageRepository::index_by_step( $stages );
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
			// Fallback: only used when the meta key is missing/zero.
			$form  = \GFAPI::get_form( $form_id );
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
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l modules/production-scheduling/src/Stages/StageResolver.php`
Expected: `No syntax errors detected in modules/production-scheduling/src/Stages/StageResolver.php`

- [ ] **Step 3: Commit**

```bash
git add modules/production-scheduling/src/Stages/StageResolver.php
git commit -m "Production stages: add StageResolver with per-request memoization"
```

---

## Task 4: Persist stages in the form-settings save handler

**Files:**
- Modify: `modules/production-scheduling/src/Admin/FormSettings.php:502-530`

- [ ] **Step 1: Add `StageRepository` use statement**

At the top of `FormSettings.php` (after `namespace SFA\ProductionScheduling\Admin;` on line 2), insert:

```php
use SFA\ProductionScheduling\Stages\StageRepository;
```

- [ ] **Step 2: Extend `save_form_settings()` to persist stages**

Inside `save_form_settings()` in `FormSettings.php`, locate the block that ends with:

```php
		$form['sfa_prod_fields'] = $production_fields;

		// Save form
		\GFAPI::update_form( $form );
```

Replace that first assignment with the existing line plus the new stages persistence immediately after:

```php
		$form['sfa_prod_fields'] = $production_fields;

		// Save workflow stages configuration.
		$stages_raw            = isset( $_POST['sfa_prod_stages'] ) ? wp_unslash( $_POST['sfa_prod_stages'] ) : [];
		$form['sfa_prod_stages'] = StageRepository::sanitize_stages( $stages_raw, $form );

		// Save form
		\GFAPI::update_form( $form );
```

- [ ] **Step 3: Syntax check**

Run: `php -l modules/production-scheduling/src/Admin/FormSettings.php`
Expected: `No syntax errors detected in modules/production-scheduling/src/Admin/FormSettings.php`

- [ ] **Step 4: Commit**

```bash
git add modules/production-scheduling/src/Admin/FormSettings.php
git commit -m "Production stages: persist sanitized stages in form settings save handler"
```

---

## Task 5: Render the "Workflow Stages" section in the form settings UI

**Files:**
- Modify: `modules/production-scheduling/src/Admin/FormSettings.php` — add a new method `render_stages_section()`, call it before `</table>` in `render_settings_page()`, enqueue the color picker + new JS.

- [ ] **Step 1: Enqueue the color picker + stages JS**

Near the top of `render_settings_page()` in `FormSettings.php`, right after the `$form = \GFAPI::get_form( $form_id );` null check (around the line where the method currently loads `$enabled`), add:

```php
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_script(
			'sfa-prod-form-settings-stages',
			SFA_PROD_URL . 'assets/js/form-settings-stages.js',
			[ 'jquery', 'wp-color-picker' ],
			SFA_PROD_VER,
			true
		);
```

- [ ] **Step 2: Load existing stages before rendering**

In the block where `$workflow_steps` is built (around lines 72–87), immediately after the `foreach ( $steps as $step ) { ... }` closing brace (still inside the method), add:

```php
		// Load saved workflow stages for this form.
		$stages = StageRepository::get_stages( $form );
```

- [ ] **Step 3: Add the `render_stages_section()` helper method**

Add this new method to the `FormSettings` class, positioned immediately before `save_form_settings()`:

```php
	/**
	 * Render the "Workflow Stages" section on the form-settings page.
	 *
	 * @param array $workflow_steps List of [id, name, type] from this form's workflow.
	 * @param array $stages         Currently-configured stages (sanitized shape).
	 */
	private function render_stages_section( array $workflow_steps, array $stages ) {
		$has_steps = ! empty( $workflow_steps );
		?>
		<tr>
			<td colspan="2">
				<hr style="margin: 20px 0;">
				<h4 style="margin: 10px 0;">Workflow Stages</h4>
				<p class="description">
					Group workflow steps into colored stages. Each stage shows a colored pill next to the entry number on the production bookings table. A step can only belong to one stage.
				</p>
			</td>
		</tr>
		<tr>
			<td colspan="2">
				<?php if ( ! $has_steps ): ?>
					<p style="color: #666; font-style: italic;">Add workflow steps to this form to configure stages.</p>
				<?php else: ?>
					<div id="sfa-prod-stages-list">
						<?php foreach ( $stages as $index => $stage ): ?>
							<?php $this->render_stage_row( $workflow_steps, $stages, $index, $stage ); ?>
						<?php endforeach; ?>
					</div>
					<p style="margin-top: 10px;">
						<button type="button" class="button" id="sfa-prod-add-stage">+ Add Stage</button>
					</p>

					<template id="sfa-prod-stage-row-template">
						<?php $this->render_stage_row( $workflow_steps, $stages, '__INDEX__', null ); ?>
					</template>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render one stage row. Used both for existing stages and as the
	 * cloneable <template> body for newly-added rows.
	 *
	 * @param array       $workflow_steps
	 * @param array       $all_stages
	 * @param int|string  $index           Numeric index for saved rows, '__INDEX__' for the template.
	 * @param array|null  $stage           Existing stage data, or null when rendering the template.
	 */
	private function render_stage_row( array $workflow_steps, array $all_stages, $index, $stage ) {
		$is_template = ( $index === '__INDEX__' );
		$stage_id    = $is_template ? '' : ( isset( $stage['id'] ) ? $stage['id'] : '' );
		$name        = $is_template ? '' : ( isset( $stage['name'] ) ? $stage['name'] : '' );
		$color       = $is_template ? '#ff9900' : ( isset( $stage['color'] ) ? $stage['color'] : '#ff9900' );
		$step_ids    = $is_template ? [] : ( isset( $stage['step_ids'] ) ? array_map( 'intval', $stage['step_ids'] ) : [] );

		// Build a step->owner-stage-name map for exclusivity display.
		$owner_of_step = [];
		foreach ( $all_stages as $other ) {
			if ( ! is_array( $other ) || empty( $other['step_ids'] ) ) {
				continue;
			}
			$other_id = isset( $other['id'] ) ? $other['id'] : '';
			if ( $other_id === $stage_id ) {
				continue;
			}
			foreach ( $other['step_ids'] as $sid ) {
				$owner_of_step[ (int) $sid ] = isset( $other['name'] ) ? $other['name'] : '';
			}
		}

		$name_prefix = 'sfa_prod_stages[' . esc_attr( $index ) . ']';
		?>
		<div class="sfa-prod-stage-row" style="margin:15px 0;padding:15px;background:#f9f9f9;border-left:3px solid #0073aa;">
			<input type="hidden" name="<?php echo $name_prefix; ?>[id]" value="<?php echo esc_attr( $stage_id ); ?>" class="sfa-prod-stage-id">
			<div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;">
				<div style="flex:2;min-width:220px;">
					<label style="display:block;font-weight:bold;margin-bottom:5px;">Stage Name</label>
					<input type="text" name="<?php echo $name_prefix; ?>[name]" value="<?php echo esc_attr( $name ); ?>" maxlength="60" class="widefat">
				</div>
				<div style="flex:1;min-width:140px;">
					<label style="display:block;font-weight:bold;margin-bottom:5px;">Color</label>
					<input type="text" name="<?php echo $name_prefix; ?>[color]" value="<?php echo esc_attr( $color ); ?>" class="sfa-prod-stage-color" data-default-color="<?php echo esc_attr( $color ); ?>">
				</div>
				<div style="padding-top:25px;">
					<button type="button" class="button sfa-prod-remove-stage" style="color:#dc3232;">Remove</button>
				</div>
			</div>
			<div style="margin-top:12px;">
				<label style="display:block;font-weight:bold;margin-bottom:5px;">Workflow Steps</label>
				<div class="sfa-prod-stage-steps" style="display:flex;flex-wrap:wrap;gap:8px 20px;">
					<?php foreach ( $workflow_steps as $step ):
						$sid        = (int) $step['id'];
						$checked    = in_array( $sid, $step_ids, true );
						$owned_by   = isset( $owner_of_step[ $sid ] ) ? $owner_of_step[ $sid ] : '';
						$is_owned   = $owned_by !== '' && ! $checked;
						?>
						<label style="<?php echo $is_owned ? 'color:#999;' : ''; ?>">
							<input type="checkbox"
							       class="sfa-prod-stage-step-checkbox"
							       name="<?php echo $name_prefix; ?>[step_ids][]"
							       value="<?php echo esc_attr( $sid ); ?>"
							       data-step-id="<?php echo esc_attr( $sid ); ?>"
							       <?php checked( $checked ); ?>
							       <?php disabled( $is_owned ); ?>>
							<?php echo esc_html( $step['name'] ); ?>
							<?php if ( $is_owned ): ?>
								<em style="font-size:11px;color:#999;">(used by: <?php echo esc_html( $owned_by ); ?>)</em>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}
```

- [ ] **Step 4: Invoke the section inside the form markup**

Inside `render_settings_page()`, find the closing `</table>` that ends the main settings `<table class="form-table">` (immediately before `<?php submit_button( 'Save Production Scheduling Settings' ); ?>` around line 407). Immediately before that `</table>`, call the new method:

```php
						<?php $this->render_stages_section( $workflow_steps, $stages ); ?>
					</table>
```

- [ ] **Step 5: Syntax check**

Run: `php -l modules/production-scheduling/src/Admin/FormSettings.php`
Expected: `No syntax errors detected in modules/production-scheduling/src/Admin/FormSettings.php`

- [ ] **Step 6: Commit**

```bash
git add modules/production-scheduling/src/Admin/FormSettings.php
git commit -m "Production stages: add Workflow Stages section to form settings UI"
```

---

## Task 6: Write the form-settings stages JS

**Files:**
- Create: `modules/production-scheduling/assets/js/form-settings-stages.js`

- [ ] **Step 1: Write the JS file**

Create `modules/production-scheduling/assets/js/form-settings-stages.js`:

```javascript
/* global jQuery */
/**
 * Production Scheduling — Workflow Stages form settings UI.
 *
 * Handles:
 *  - WP color picker init
 *  - Add / remove stage rows
 *  - Live exclusivity: disable a step checkbox in all other rows once it's
 *    checked in any row, re-enable it when unchecked.
 */
(function ($) {
	'use strict';

	var $list     = null;
	var $template = null;
	var nextIndex = 0;

	function initColorPicker($input) {
		if (!$input.hasClass('wp-color-picker')) {
			$input.wpColorPicker();
		}
	}

	function randomStageId() {
		var hex = '';
		var alphabet = '0123456789abcdef';
		for (var i = 0; i < 6; i++) {
			hex += alphabet.charAt(Math.floor(Math.random() * 16));
		}
		return 'stg_' + hex;
	}

	function collectOwnedSteps() {
		// Map of step_id -> owning stage name, based on current checked boxes.
		var owners = {};
		$list.find('.sfa-prod-stage-row').each(function () {
			var $row = $(this);
			var name = ($row.find('input[name$="[name]"]').val() || '').trim() || '(unnamed)';
			$row.find('.sfa-prod-stage-step-checkbox:checked').each(function () {
				var sid = $(this).data('step-id');
				if (!owners.hasOwnProperty(sid)) {
					owners[sid] = name;
				}
			});
		});
		return owners;
	}

	function refreshExclusivity() {
		var owners = collectOwnedSteps();

		$list.find('.sfa-prod-stage-row').each(function () {
			var $row     = $(this);
			var rowName  = ($row.find('input[name$="[name]"]').val() || '').trim() || '(unnamed)';
			$row.find('.sfa-prod-stage-step-checkbox').each(function () {
				var $cb     = $(this);
				var sid     = $cb.data('step-id');
				var owner   = owners[sid];
				var checked = $cb.is(':checked');
				var isOwnedElsewhere = !checked && owner && owner !== rowName;
				$cb.prop('disabled', isOwnedElsewhere);
				var $label = $cb.closest('label');
				$label.find('em.sfa-prod-step-owner').remove();
				if (isOwnedElsewhere) {
					$label.css('color', '#999').append(
						' <em class="sfa-prod-step-owner" style="font-size:11px;color:#999;">(used by: ' +
						$('<div>').text(owner).html() +
						')</em>'
					);
				} else {
					$label.css('color', '');
				}
			});
		});
	}

	function addStageRow() {
		if (!$template.length) {
			return;
		}
		var html = $template.html().replace(/__INDEX__/g, String(nextIndex));
		var $new = $(html);
		$new.find('.sfa-prod-stage-id').val(randomStageId());
		$list.append($new);
		initColorPicker($new.find('.sfa-prod-stage-color'));
		nextIndex += 1;
		refreshExclusivity();
	}

	function removeStageRow($row) {
		$row.remove();
		refreshExclusivity();
	}

	$(function () {
		$list     = $('#sfa-prod-stages-list');
		$template = $('#sfa-prod-stage-row-template');
		if (!$list.length || !$template.length) {
			return;
		}

		// Initial index starts past the last server-rendered row.
		nextIndex = $list.find('.sfa-prod-stage-row').length;

		// Init color pickers on server-rendered rows.
		$list.find('.sfa-prod-stage-color').each(function () {
			initColorPicker($(this));
		});

		$('#sfa-prod-add-stage').on('click', function (e) {
			e.preventDefault();
			addStageRow();
		});

		$(document).on('click', '.sfa-prod-remove-stage', function (e) {
			e.preventDefault();
			removeStageRow($(this).closest('.sfa-prod-stage-row'));
		});

		$(document).on('change', '.sfa-prod-stage-step-checkbox', function () {
			refreshExclusivity();
		});

		$(document).on('input', 'input[name$="[name]"]', function () {
			refreshExclusivity();
		});
	});
})(jQuery);
```

- [ ] **Step 2: Commit**

```bash
git add modules/production-scheduling/assets/js/form-settings-stages.js
git commit -m "Production stages: add form-settings JS for add/remove/exclusivity"
```

---

## Task 7: Render the badge on the backend bookings table

**Files:**
- Modify: `modules/production-scheduling/src/Admin/ScheduleView.php:566-646` (the `render_bookings_list()` method).

- [ ] **Step 1: Add `use` statements at the top of the file**

Find the `namespace` line at the top of `ScheduleView.php` and add, just below it:

```php
use SFA\ProductionScheduling\Stages\StageResolver;
use SFA\ProductionScheduling\Stages\StageBadge;
```

- [ ] **Step 2: Resolve stages once per render and inject the badge**

Locate `render_bookings_list()`. After the existing `$all_entries` flattening loop and **before** the `foreach ( $all_entries as $entry_data ):` loop (approximately around line 600), add:

```php
						// Resolve the current workflow stage (if any) for each entry.
						$entry_form_map = [];
						foreach ( $all_entries as $eid => $edata ) {
							$entry_form_map[ (int) $eid ] = (int) $edata['form_id'];
						}
						$stages_by_entry = ( new StageResolver() )->resolve_for_entries( $entry_form_map );

						echo StageBadge::css();
```

Then change the Entry # cell (currently lines ~609–612) from:

```php
							<tr>
								<td><a href="<?php echo esc_url( $workflow_url ); ?>" target="_blank">
									#<?php echo $entry_data['entry_id']; ?>
								</a></td>
```

to:

```php
							<tr>
								<td>
									<a href="<?php echo esc_url( $workflow_url ); ?>" target="_blank">
										#<?php echo (int) $entry_data['entry_id']; ?>
									</a>
									<?php
									$stage = isset( $stages_by_entry[ (int) $entry_data['entry_id'] ] ) ? $stages_by_entry[ (int) $entry_data['entry_id'] ] : null;
									if ( $stage ) {
										echo StageBadge::render( $stage );
									}
									?>
								</td>
```

- [ ] **Step 3: Syntax check**

Run: `php -l modules/production-scheduling/src/Admin/ScheduleView.php`
Expected: `No syntax errors detected in modules/production-scheduling/src/Admin/ScheduleView.php`

- [ ] **Step 4: Commit**

```bash
git add modules/production-scheduling/src/Admin/ScheduleView.php
git commit -m "Production stages: render stage badge in backend bookings table"
```

---

## Task 8: Render the badge on the frontend bookings table

**Files:**
- Modify: `modules/production-scheduling/src/Frontend/FrontendCalendar.php:809-950` (`render_bookings_list()`).

- [ ] **Step 1: Add `use` statements at the top of the file**

Just below the `namespace` line in `FrontendCalendar.php`, add:

```php
use SFA\ProductionScheduling\Stages\StageResolver;
use SFA\ProductionScheduling\Stages\StageBadge;
```

- [ ] **Step 2: Build the entry→form map and resolve stages**

Inside `render_bookings_list()`, locate the `uasort` call that sorts `$entries_list` by install date (around line 854–856). Immediately **after** the `uasort(...)` line and **before** the closing HTML block, add:

```php
		// Resolve the current workflow stage (if any) for each entry.
		$entry_form_map = [];
		foreach ( $entries_list as $eid => $edata ) {
			$entry_form_map[ (int) $eid ] = (int) $edata['form_id'];
		}
		$stages_by_entry = ( new StageResolver() )->resolve_for_entries( $entry_form_map );
```

- [ ] **Step 3: Emit the badge CSS once and render it in the Entry # cell**

Locate the `<style>` block inside `render_bookings_list()` (the one that defines `.sfa-bookings-table { ... }`). Immediately after that closing `</style>` tag (around line 886), add:

```php
					<?php echo StageBadge::css(); ?>
```

Then locate the Entry # cell inside the `foreach ( $entries_list as $entry_data )` loop (lines ~903–908). Change it from:

```php
								<tr>
									<td>
										<?php $workflow_url = home_url( '/workflow-inbox/' ) . '?page=gravityflow-inbox&view=entry&id=' . $entry_data['form_id'] . '&lid=' . $entry_data['entry_id']; ?>
										<a href="<?php echo esc_url( $workflow_url ); ?>" target="_blank" style="color: #0073aa;">
											<strong>#<?php echo $entry_data['entry_id']; ?></strong>
										</a>
									</td>
```

to:

```php
								<tr>
									<td>
										<?php $workflow_url = home_url( '/workflow-inbox/' ) . '?page=gravityflow-inbox&view=entry&id=' . $entry_data['form_id'] . '&lid=' . $entry_data['entry_id']; ?>
										<a href="<?php echo esc_url( $workflow_url ); ?>" target="_blank" style="color: #0073aa;">
											<strong>#<?php echo (int) $entry_data['entry_id']; ?></strong>
										</a>
										<?php
										$stage = isset( $stages_by_entry[ (int) $entry_data['entry_id'] ] ) ? $stages_by_entry[ (int) $entry_data['entry_id'] ] : null;
										if ( $stage ) {
											echo StageBadge::render( $stage );
										}
										?>
									</td>
```

- [ ] **Step 4: Syntax check**

Run: `php -l modules/production-scheduling/src/Frontend/FrontendCalendar.php`
Expected: `No syntax errors detected in modules/production-scheduling/src/Frontend/FrontendCalendar.php`

- [ ] **Step 5: Commit**

```bash
git add modules/production-scheduling/src/Frontend/FrontendCalendar.php
git commit -m "Production stages: render stage badge in frontend bookings table"
```

---

## Task 9: Bump module version

**Files:**
- Modify: `modules/production-scheduling/production-scheduling.php:5, 12`

Per CLAUDE.md workflow rules, any module change requires bumping both the plugin header and the constant.

- [ ] **Step 1: Update version header**

In `production-scheduling.php`, change line 5 from:

```
 * Version: 1.4.0
```

to:

```
 * Version: 1.5.0
```

- [ ] **Step 2: Update constant**

Change line 12 from:

```php
if ( ! defined( 'SFA_PROD_VER' ) ) define( 'SFA_PROD_VER', '1.4.0' );
```

to:

```php
if ( ! defined( 'SFA_PROD_VER' ) ) define( 'SFA_PROD_VER', '1.5.0' );
```

- [ ] **Step 3: Syntax check**

Run: `php -l modules/production-scheduling/production-scheduling.php`
Expected: `No syntax errors detected in modules/production-scheduling/production-scheduling.php`

- [ ] **Step 4: Commit**

```bash
git add modules/production-scheduling/production-scheduling.php
git commit -m "Production stages: bump production-scheduling to v1.5.0"
```

---

## Task 10: Manual verification

Because this project has no automated tests, run this smoke test before declaring the plan done. Each item maps to one numbered test in the spec's Test Plan.

- [ ] **Test 1 — Settings save/load.** In WP admin, pick any form with Production Scheduling enabled and at least two GravityFlow steps. Open Form Settings → Production Scheduling. Add two stages with different names, different hex colors, and disjoint step selections. Save. Reload the page. Confirm both stages come back with the same names, colors, and step selections.

- [ ] **Test 2 — Exclusivity UI.** With two stages saved, open the page again. Check a step in Stage A that isn't currently in either stage. Observe the same checkbox go disabled in Stage B with a `(used by: <Stage A name>)` annotation. Uncheck it in Stage A; observe it re-enable in Stage B. Refresh without saving; confirm no persisted change.

- [ ] **Test 3 — Invalid inputs.** Add a third stage with an empty name, save → stage is silently dropped. Add one with a valid name but no checked steps, save → dropped. Add one with a typed color of `red`, save → dropped. Confirm only the two valid stages from Test 1 remain.

- [ ] **Test 4 — Backend badge render.** Pick an entry on the form whose current workflow step belongs to Stage A. Open `Admin → Production Schedule` and scroll to the Production Bookings list. Confirm the Entry # cell shows the entry number and, after a short gap, a pill with Stage A's name and color. Confirm the text inside the pill is readable (white on dark colors, dark-gray on light colors).

- [ ] **Test 5 — Frontend badge render.** Place the `[production_schedule]` shortcode on a test page and view it while logged in. Confirm the same entry gets the same pill next to its entry number in the frontend bookings table.

- [ ] **Test 6 — No-stage entries.** Find an entry whose current step is in none of the configured stages (or whose workflow has completed). Confirm its row renders exactly as it did before — no pill, no layout shift.

- [ ] **Test 7 — Multi-form rendering.** If two forms each define different stages, submit entries under both, navigate the bookings table back to a month containing both, and confirm each row's pill reflects its own form's stage configuration (colors/names don't leak across forms).

- [ ] **Test 8 — Query budget.** Define `SAVEQUERIES` in `wp-config.php` (`define('SAVEQUERIES', true);`). Load the backend Production Schedule page for a busy month. After the page renders, dump `$wpdb->queries` at the end of `ScheduleView::render_bookings_list()` in a temporary debug line and confirm no new `gf_entry_meta` queries were added beyond what the page issued before this change (badge resolution must ride the existing per-entry meta cache). Remove the debug line before committing anything else.

If any test fails, fix the offending task's code and re-run the failing test. Do not mark this task complete until all tests pass.

---

## Post-completion Self-Review Checklist

- [ ] All ten tasks committed atomically (one logical change per commit).
- [ ] `git grep "TODO\|TBD\|FIXME" modules/production-scheduling/src/Stages/` returns nothing added by this work.
- [ ] The branch is `feature/production-workflow-stages` (set up when the spec was committed).
- [ ] No files outside `modules/production-scheduling/` were modified — the change is fully module-local.
- [ ] The version bump matches both the file header and `SFA_PROD_VER`.

---

## Out-of-Scope Reminders (do not do as part of this plan)

- No changes to `simpleflow.php` (especially not the admin validation bypass block near lines 530–590).
- No new options, no new AJAX endpoints, no new custom DB tables.
- No changes to `simple-customer-info`, `quality-gate`, `update-requests`, `simple-notes`, `simple-flow-attachment`, `code-validation`.
- No changes to the production-schedule **calendar** cells — stages only appear on the bookings **table**.
- No stage reordering UI — exclusivity makes ordering unnecessary per the spec.
