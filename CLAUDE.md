# SimpleFlow

Modular WordPress plugin suite built on Gravity Forms + GravityFlow for production scheduling, quality gates, update requests, notes, and customer info management; used internally by factory/operations teams.

## Commands

* **Dev:** No build step — edit PHP/JS/CSS directly, changes are live on page reload
* **Lint:** `php -l <file>` for syntax checks (no phpcs/eslint configured)
* **Deploy:** Manual — do not automate
* **Cache clear:** `wp cache flush` (WP-CLI) or flush from WP admin; module code also calls `wp_cache_delete()` per affected key
* **Purge orphaned bookings:** `wp eval-file scripts/cleanup-invalid-production-bookings.php` — removes orphaned 0-LM booking meta

## Dependencies

* **Required:** Gravity Forms (active + licensed), Gravity Flow (active + licensed)
* **No dependency on:** Simple HR Suite, DOFS (separate plugin ecosystems, no shared tables)

## Architecture

Core loader (`simpleflow.php`) scans `modules/` at `plugins_loaded` priority 20, auto-discovers each module's entry PHP file, and respects the enable/disable toggles in Settings > SimpleFlow. Each module is self-contained with its own PSR-4 autoloader (`SFA\ModuleName\` -> `src/`), database installer, and hook registrations done inside constructors. Business logic lives in `src/` classes; GravityForms integration in `src/GravityForms/`; admin UI in `src/Admin/`; database access in `src/Database/` repository classes.

* **Main plugin file:** `simpleflow.php`
* **Custom modules:** `modules/<module-slug>/<module-slug>.php` (entry point per module)
* **Config:** WordPress options table — all settings keyed `sfa_prod_*`, `sfa_qg_*`, `simple_notes_*`, `sfa_cv_*`, `simpleflow_modules`
* **DB migrations:** `src/Database/Installer.php` per module — uses `dbDelta()`, version-gated by `sfa_<mod>_db_version` option

### Modules

| Directory | Purpose | Status |
|-----------|---------|--------|
| `production-scheduling` | LM capacity booking, fill+spill, calendar views | Active — most frequently changed |
| `quality-gate` | Pass/fail checklists evaluated against GF entry fields | Stable |
| `update-requests` | Post-approval entry amendments with diff tracking | Stable |
| `simple-notes` | Sticky notes on entries — dual backend/frontend implementations | Stable |
| `simple-customer-info` | Customer data card panel in GF and GravityFlow | Stable |
| `simple-flow-attachment` | Grouped file attachments with collapsible UI, ZIP download | Stable |
| `code-validation` | Validate confirmation codes against existing entries | Stub — minimal logic |

## Boundaries

* **Never query `wp_gf_entry` / `wp_gf_entry_meta` directly** — always use `GFAPI::get_entry()`, `GFAPI::get_form()`, `gform_get_meta()`, `gform_update_meta()`. Direct queries break GF caching and future-proofing.
* **Custom DB tables** (`wp_sfa_prod_capacity_overrides`, `wp_simple_notes`) are accessed only through their Repository classes — don't write raw SQL elsewhere.
* **`simpleflow.php` intentionally bypasses GF validation in admin/workflow contexts** (lines ~530-590). Do not "fix" this — it lets admins move entries through workflows without field-level blocking.
* **Simple Notes has two independent implementations** (backend `notes.js` + frontend `AutoPositioning.php`). Any feature change must be applied to both — see `modules/simple-notes/ARCHITECTURE.md`.
* **GravityFlow hooks are the authoritative source for workflow state changes.** Cancellation is handled by multiple paths: the GravityFlow hooks `gravityflow_workflow_cancelled` and `gravityflow_status_updated` (when they fire), the `gravityflow_post_process_workflow` hook (checks `workflow_final_status` for cancelled entries), and the request-path handlers `check_cancel_workflow_request` and `handle_cancel_workflow_ajax` (which directly cancel bookings). The `sync_cancelled_workflow_bookings` safety net runs on both the production schedule page and Gravity Forms entry detail pages to catch any missed cancellations.

## Domain Terminology

| Term | Meaning |
|------|---------|
| LM (Linear Meters) | Unit of production capacity — each order consumes LM, each day has a daily cap |
| Slots / Total Slots | Synonym for LM in multi-field mode (multiple production field values summed) |
| Allocation | JSON map of `{ "YYYY-MM-DD": slots_consumed }` stored per entry |
| Fill+Spill | Default scheduling: fills today's remaining capacity, spills overflow to next working day |
| Over-capacity | Admin override: forces entire order onto one date, ignoring daily cap |
| Manual booking | User explicitly picked an installation date |
| Automatic booking | System calculated date from queue position |
| Booking step | The GravityFlow step ID after which production booking is created (deferred mode) |
| Skip booking | Checkbox field that creates a date-only booking with 0 LM (no capacity consumed) |
| Quality Gate | Pass/fail checklist evaluated against entry field values |
| Update Request | Workflow-driven entry amendment request with diff tracking |

## Conventions

* **Language in code:** English
* **Language in UI strings:** English (no RTL/Arabic support currently; all strings wrapped in `__()` with text domain)
* **Namespaces:** `SFA\<ModuleName>\<SubFolder>\<Class>` — maps 1:1 to `src/<SubFolder>/<Class>.php`
* **Constants per module:** `SFA_PROD_VER`, `SFA_PROD_DIR`, `SFA_PROD_URL` (pattern: `SFA_<ABBR>_*`)
* **Entry meta keys:** `_prod_*` (production), `_ur_*` (update requests), `_qc_*` (quality gate), `_sci_*` (customer info)
* **Custom DB tables:** `{$wpdb->prefix}sfa_<module>_<table>`
* **Options:** `sfa_<module>_*` for settings, `sfa_<module>_db_version` for migration tracking
* **Hook registration:** Done inside class `__construct()`, not statically
* **Debug logging:** Use `self::debug_log()` (gated behind `WP_DEBUG && WP_DEBUG_LOG`); reserve bare `error_log()` only for genuine errors/fatals

## Known Gotchas

* **Race condition on concurrent bookings** — production scheduling uses a MySQL named lock (`GET_LOCK('sfa_prod_booking', 15)`) to serialize writes. If you add new booking paths, they must also acquire this lock.
* **Admin validation bypass is intentional** — `simpleflow.php` removes GF validation filters during `is_admin()` and GravityFlow actions. Don't reintroduce validation in admin context without understanding the workflow inbox implications.
* **Transient-based capacity choice has a 5-minute window** — stored via AJAX before form save, read during `process_production_booking()`. If the save is delayed or transient expires, the choice is silently lost and fill+spill is used as default.
* **`_prod_field_breakdown` is written outside the transaction** — it's lightweight metadata stored before the allocation commit block. Don't rely on it being atomically consistent with `_prod_slots_allocation`.
* **Date format ambiguity** — GF fields may submit dates as `MM/DD/YYYY`, `DD/MM/YYYY`, or `YYYY-MM-DD`. The `normalize_date()` method uses the GF field's `dateFormat` setting to disambiguate; without it, ambiguous dates default to US format.
* **`sync_cancelled_workflow_bookings()` runs on every production schedule admin page load** — it's a safety net that queries for stale cancelled-but-still-confirmed entries. Don't add expensive logic inside `cancel_production_booking()` that would multiply with this bulk sync.
* **Version bump requires two edits** — the file header comment (`Version: X.Y.Z`) and the constant (`SFA_PROD_VER`, etc.) in each module's entry file.

## Workflow Rules

* **Always bump the module version** (both header and constant) when making changes to a module.
* **Always run `php -l <file>`** after editing PHP files to catch syntax errors.
* **Never push to `main` directly** — work on feature branches.
* **After changing any booking/allocation logic**, manually verify cache invalidation covers all affected date ranges (`wp_cache_delete('sfa_prod_availability_' . $year_month)`).
* **When modifying cancel/status hooks**, ensure the `cancel_production_booking()` path is only called from authoritative GravityFlow hooks, not from request-driven handlers.
* **When adding new entry meta keys**, add matching cleanup in both `handle_entry_deletion()` and `cancel_production_booking()`.
