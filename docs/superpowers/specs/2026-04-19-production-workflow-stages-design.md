# Production Workflow Stages — Design Spec

**Date:** 2026-04-19
**Module:** `modules/production-scheduling`
**Status:** Approved for implementation

## Summary

Add a per-form configuration to group GravityFlow workflow steps into named, colored "stages". On the Production Bookings table (both backend admin view and frontend shortcode view), display a small colored pill/badge next to each entry number showing the stage that the entry's current workflow step belongs to.

## Motivation

The Production Bookings table currently lists entries across multiple forms and does not show where each entry sits in its workflow. Operators need an at-a-glance signal of workflow progress while viewing the production schedule, without opening each entry individually. Stages let admins collapse multiple low-level workflow steps into meaningful phases (e.g., "Production", "Quality", "Ready to Ship") and give each a distinct color.

## Scope

**In scope:**

- Form-level settings UI for creating, editing, and removing stages.
- Per-row badge rendering in the backend production-schedule view (`ScheduleView::render_bookings_list`).
- Per-row badge rendering in the frontend `[production_schedule]` shortcode view (`FrontendCalendar::render_bookings_list`).
- Exclusivity constraint: a single workflow step may belong to at most one stage within a form.

**Out of scope:**

- Stage display anywhere other than the two bookings tables (no changes to calendar day cells, entry detail pages, workflow inbox, or Gravity Forms entry lists).
- Global/cross-form stage libraries (each form configures its own stages).
- Reordering stages for priority — exclusivity makes ordering irrelevant.
- Migrating or inferring stages from existing workflow data.

## User Flow

1. Admin opens a form's settings → "Production Scheduling Settings".
2. Below existing fields, a new "Workflow Stages" section appears.
3. Admin clicks "+ Add Stage", enters a stage name, picks a hex color via the WordPress color picker, and checks the workflow steps that belong to this stage.
4. Admin adds additional stages; steps already used by another stage are disabled in the remaining stages.
5. Admin saves the form settings. Stages persist on the form.
6. When the production bookings table renders (backend or frontend), entries whose current workflow step is in a configured stage show a colored pill showing the stage name next to the entry link. Other entries render unchanged.

## Data Model

Per-form setting stored alongside the existing `sfa_prod_*` form settings. Saved via the same mechanism used by `sfa_prod_booking_step` and `sfa_prod_fields` (form object / form meta, written in the `sfa_prod_save_form_settings` admin-post handler).

**Key:** `sfa_prod_stages`

**Shape:** JSON-serialized (or native array when written to form meta) list of stage objects:

```json
[
  {
    "id": "stg_a1b2c3",
    "name": "Production",
    "color": "#ff9900",
    "step_ids": [12, 45]
  },
  {
    "id": "stg_d4e5f6",
    "name": "Quality",
    "color": "#00a86b",
    "step_ids": [78]
  }
]
```

**Field rules (enforced on save):**

- `id` — 10-char stable identifier (`stg_` + 6 random hex chars). Generated client-side on "Add Stage"; round-tripped on edit. If missing on save, server regenerates.
- `name` — required, trimmed, 1–60 characters, `sanitize_text_field` applied.
- `color` — required, must match `/^#[0-9a-fA-F]{6}$/`. Normalized to lowercase on save.
- `step_ids` — required non-empty array. Each element must be an integer and must be a valid step ID in this form's GravityFlow workflow (validated via `Gravity_Flow_API::get_steps()`). Any step ID may appear in at most one stage in the list; duplicates across stages cause the duplicated ID to be kept only in the earliest stage and removed from later stages.
- Stages failing validation (empty name, no valid step IDs) are dropped silently to support the "clear row then save" removal flow described in the Settings UI.

## Runtime Resolution

A new class `SFA\ProductionScheduling\Stages\StageResolver` encapsulates mapping entries to stages. Located at `modules/production-scheduling/src/Stages/StageResolver.php`.

```php
class StageResolver {
    /**
     * @param array<int,int> $entry_form_map  [ entry_id => form_id ]
     * @return array<int, ?array>             [ entry_id => stage|null ]
     */
    public function resolve_for_entries( array $entry_form_map ): array;
}
```

Both rendering sites already carry `form_id` alongside each `entry_id`, so they pass the full map directly — no extra query needed to discover form IDs.

Algorithm:

1. Bail out early if the map is empty.
2. Group entry IDs by form ID. For each unique `form_id`: `GFAPI::get_form( $form_id )` (GF-cached), read `sfa_prod_stages`, and build an index `step_id → stage` (guaranteed unique due to the exclusivity constraint). Forms with no configured stages are skipped.
3. For each entry in the map, call `gform_get_meta( $entry_id, 'workflow_step' )` to get the current step ID. This uses GF's per-entry meta cache — if the caller (the rendering code) has already loaded other meta for that entry via `gform_get_meta`, this is a cached array lookup, not a fresh query. Direct queries to `gf_entry_meta` are forbidden by project rules (see CLAUDE.md).
4. Resolve: `stages_by_form[ form_id ][ step_id ]` → the matched stage, or `null` if no step, no stage index, or no match.

**Meta key compatibility:** `workflow_step` is the documented GravityFlow entry meta that holds the current step ID. If a GravityFlow version is encountered where this key is absent or renamed, the implementation phase falls back to `Gravity_Flow_API::get_current_step( $form, $entry )`. This fallback is called only on the first lookup per request; its result is cached in-memory for the remainder of the request to avoid repeated API instantiation.

Query cost: **0** extra DB queries on top of what the existing tables already issue (entry meta is cached by GF; form loads are cached by GF).

**Edge cases:**

- `workflow_step` entry meta missing, empty, or `0` → no stage.
- Form has no `sfa_prod_stages` configured → no stage for any of its entries.
- Current step ID exists but is not in any stage → no stage.
- Entry in cancelled/completed workflow → `workflow_step` is typically absent → no stage (existing cancelled/status pill continues to render unchanged).

## Rendering

A thin helper `SFA\ProductionScheduling\Stages\StageBadge` exposes a single method:

```php
class StageBadge {
    public static function render( array $stage ): string;
}
```

Returns:

```html
<span class="sfa-prod-stage-badge" style="background:#ff9900;color:#ffffff;">Production</span>
```

- Stage `name` is passed through `esc_html`.
- `color` is re-validated against the hex regex at render time (defense in depth); if invalid, the badge is not rendered.
- Text color (`#ffffff` or `#222222`) is computed from the stage color's perceived luminance so the name stays readable on any admin-chosen background.

Shared CSS injected once per table render (both views):

```css
.sfa-prod-stage-badge {
  display: inline-block;
  margin-left: 6px;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 600;
  line-height: 1.4;
  vertical-align: middle;
  white-space: nowrap;
}
```

Both `ScheduleView::render_bookings_list()` and `FrontendCalendar::render_bookings_list()` are updated to:

1. Collect entry IDs.
2. Call `StageResolver::resolve_for_entries()` once.
3. In the loop that renders each row, after the entry link, append `StageBadge::render($stage)` if a stage was resolved for that entry.

No changes to any other column; rows with no resolved stage are visually identical to today.

## Settings UI

New "Workflow Stages" section added to `modules/production-scheduling/src/Admin/FormSettings.php`, rendered below the existing fields and above the Save button.

**Markup outline:**

```
<div class="sfa-prod-stages-section">
  <h4>Workflow Stages</h4>
  <p class="description">Group workflow steps into colored stages. A step can only belong to one stage.</p>

  <div class="sfa-prod-stages-list">
    <!-- One .sfa-prod-stage-row per configured stage, server-rendered -->
  </div>

  <template id="sfa-prod-stage-row-template">
    <!-- Empty row clone target for "Add Stage" -->
  </template>

  <button type="button" class="button sfa-prod-add-stage">+ Add Stage</button>
</div>
```

Each stage row contains:

- Hidden `stages[i][id]` input.
- Text input `stages[i][name]`, max 60 chars.
- Color input `stages[i][color]` bound to `wp-color-picker`.
- A fieldset of checkboxes, one per workflow step on this form, named `stages[i][step_ids][]`.
- A "Remove" button that empties the name input and unchecks all step boxes (empty rows are dropped on save).

**Exclusivity behavior:**

- On initial render, each step checkbox is disabled in every row except the one that currently owns it; disabled rows show `(used by: <owner name>)`.
- A vanilla JS handler listens for `change` on every step checkbox across all rows: when a box is checked, boxes with the same step ID in other rows become disabled; when unchecked, they become enabled again. No server round-trip.
- When "+ Add Stage" clones the template row, its step checkboxes reflect current ownership state.

**Asset loading:**

- `wp-color-picker` style and script (bundled with WordPress core) are enqueued only on the form-settings screen where this section renders.
- A small new JS file (`assets/js/form-settings-stages.js`) handles add/remove/exclusivity logic.

**Degraded state:** If the form's GravityFlow API returns no steps, the section shows "Add workflow steps to this form to configure stages." and the "+ Add Stage" button is hidden.

**Save handler:** The existing `sfa_prod_save_form_settings` admin-post handler extends to read `$_POST['stages']`, apply the validation rules in the Data Model section, and write the result to the form via the same persistence call used for the other `sfa_prod_*` settings. On save success, the existing redirect-with-`updated=1` pattern is preserved.

## File/Class Plan

**New files:**

- `modules/production-scheduling/src/Stages/StageResolver.php` — runtime resolution helper.
- `modules/production-scheduling/src/Stages/StageBadge.php` — rendering helper.
- `modules/production-scheduling/assets/js/form-settings-stages.js` — add/remove/exclusivity JS.

**Modified files:**

- `modules/production-scheduling/src/Admin/FormSettings.php` — add the Workflow Stages section markup and extend the save handler.
- `modules/production-scheduling/src/Admin/ScheduleView.php` — call `StageResolver`, render badge in the bookings list loop.
- `modules/production-scheduling/src/Frontend/FrontendCalendar.php` — call `StageResolver`, render badge in the bookings list loop.
- `modules/production-scheduling/production-scheduling.php` — bump module version header and `SFA_PROD_VER` constant (per project workflow rules).

No database migrations. No new options. No new AJAX endpoints.

## Non-Goals / Invariants Preserved

- Existing booking logic, allocation, capacity, and cancel paths are untouched.
- The admin validation bypass in `simpleflow.php` is not touched.
- `workflow_step` is read but never written.
- No changes to any GravityForms or GravityFlow tables; only reads via stable GF meta keys and the GF API.

## Test Plan (verification, not automated tests)

1. **Settings save/load.** Add two stages with different colors and disjoint steps; save; reopen the form settings; confirm the stages come back intact.
2. **Exclusivity UI.** Check a step in stage A, confirm the same step is disabled in stage B with the `(used by: A)` label; uncheck it in A and confirm it re-enables in B.
3. **Invalid inputs.** Try to save a stage with an empty name (dropped), invalid hex `red` (rejected), and a step ID that doesn't belong to the form (dropped). Confirm the final saved value is clean.
4. **Badge render (backend).** Set a test entry's current workflow step to one of the configured step IDs; open the Production Bookings page; confirm the colored pill renders next to the entry number with the correct text color for contrast.
5. **Badge render (frontend).** Same as above but via the `[production_schedule]` shortcode on a front-end page.
6. **No-stage entries.** Confirm an entry with no `workflow_step` meta renders exactly as it does today.
7. **Multi-form table.** With two forms each defining different stages, confirm each row picks its stage from the correct form's configuration.
8. **Query budget.** With `SAVEQUERIES` enabled, confirm rendering a 100-row bookings table adds zero new queries beyond the existing per-entry meta loads — `gform_get_meta` hits GF's entry meta cache once the entry's meta bundle has been loaded for the existing fields.

## Risks and Gotchas

- **GF form cache drift.** If `GFAPI::get_form()` returns stale form data mid-request, stages may momentarily render against an old step list. Mitigation: the resolver reads each form once per request; no writes between reads.
- **Workflow-step meta semantics.** `workflow_step` is GravityFlow-internal; if a future GF release renames it, the resolver falls back to `Gravity_Flow_API::get_current_step()`. Worst case (both unavailable) is the badge silently not rendering — cosmetic failure, no data impact.
- **Color contrast on chosen hex.** Luminance-based text color picks `#fff` or `#222` — edge cases (mid-luminance gray) may still be borderline but remain legible. If operators pick unreadable combinations they can change them.
