# Quality Gate Module — Deep Investigation Report

**Date:** 2026-03-09
**Module Version:** 2.3.14
**Files Analyzed:** 11 (8 PHP, 2 JS, 1 CSS)

## Issues Summary

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 2 | 2 | 1 | — | 5 |
| Performance | — | 4 | 2 | 1 | 7 |
| Duplication | — | 4 | 1 | — | 5 |
| Correctness | — | 2 | 4 | 1 | 7 |
| Maintainability | — | — | — | 1 | 1 |
| **Total** | **2** | **12** | **8** | **3** | **25** |

## Detailed Findings

### #1 — CRITICAL — Security: Unauthenticated AJAX Endpoint
- **Location:** `quality-gate.php:972`
- **Issue:** `sfa_qg_ajax_items` is registered on `wp_ajax_nopriv_*`, exposing entry data to unauthenticated users.
- **Root Cause:** Registered on both `wp_ajax_` and `wp_ajax_nopriv_` without capability check beyond nonce.
- **Impact:** Any anonymous visitor can probe entry IDs and extract file upload names (information disclosure).
- **Fix:** Remove the `wp_ajax_nopriv_sfa_qg_items` hook, or add `current_user_can()` check inside `sfa_qg_ajax_items()`.

### #2 — CRITICAL — Security: XSS via Unescaped Photo URLs
- **Location:** `quality-gate.php:1464-1466`
- **Issue:** Photo `data:` URLs injected into `src` attribute without escaping in `sfa_qg_render_failed_table()`.
- **Root Cause:** Comment says "Don't escape data URLs" and injects `$data_url` directly.
- **Impact:** Malicious payload stored in QC JSON `photo` field can execute JavaScript via `javascript:` URI.
- **Fix:** Use `esc_attr()` on the `src` attribute and validate that URLs start with `data:image/`.

### #3 — HIGH — Security: Direct GF Table Queries
- **Location:** `collect.php`, `quality-gate.php` (multiple locations)
- **Issue:** Direct SQL queries to `wp_gf_entry` / `wp_gf_entry_meta` tables throughout reporting code.
- **Root Cause:** CLAUDE.md explicitly forbids direct queries to GF tables, but reporting bypasses this for performance.
- **Impact:** Breaks GF caching, breaks forward compatibility with GF schema changes.
- **Fix:** Refactor hot paths to use `GFAPI::get_entries()`, or document the deviation as intentional.

### #4 — HIGH — Security: Admin Endpoints Without Nonce Verification
- **Location:** `quality-gate.php:2326-2491`
- **Issue:** Admin debug endpoints (`sfa_qg_backfill`, `sfa_qg_cleanup`, `sfa_qg_auditpeek`) triggered by GET parameters without nonce.
- **Root Cause:** Only checks `current_user_can('manage_options')` but no nonce.
- **Impact:** CSRF-vulnerable — attacker can trick admin into visiting crafted URL that triggers backfill or cleanup.
- **Fix:** Add `wp_verify_nonce()` check or use a proper admin action with nonce.

### #5 — HIGH — Performance: Audit Table Check on Every Request
- **Location:** `quality-gate.php:595-610`
- **Issue:** `sfa_qg_maybe_install_audit_table()` runs on every single request via `init` priority 1.
- **Root Cause:** Executes `SHOW TABLES` + `update_option()` on every page load.
- **Impact:** Adds 1-2 DB queries per request for all visitors.
- **Fix:** Check option flag first; only run `SHOW TABLES` when option is missing.

### #6 — HIGH — Performance: Cache-Busting Assets with time()
- **Location:** `quality-gate.php:672-681`
- **Issue:** `$timestamp = time()` appended to every script/style URL.
- **Root Cause:** Hardcoded `time()` means assets get a new URL on every request.
- **Impact:** Every page load forces re-download of quality.js (50KB) and quality.css (24KB).
- **Fix:** Use `SFA_QG_VER` alone; add `filemtime()` only when `WP_DEBUG` is true.

### #7 — HIGH — Duplication: sfa_qg_report_collect() Defined Twice
- **Location:** `quality-gate.php:42-548` and `report/collect.php:7-311`
- **Issue:** Both wrapped in `function_exists()`, so whichever loads first wins.
- **Root Cause:** Copy-paste evolution.
- **Impact:** Maintenance nightmare — changes in one copy don't affect the other.
- **Fix:** Delete the copy in `quality-gate.php`; rely solely on `report/collect.php`.

### #8 — HIGH — Duplication: sfa_qg_fixed_report_collect() Defined Twice
- **Location:** `quality-gate.php:1954-2025` and `report/collect.php:313-413`
- **Root Cause / Impact / Fix:** Same as #7.

### #9 — HIGH — Duplication: sfa_qg_report_range_bounds() Defined Twice
- **Location:** `quality-gate.php:979-1013` and `report/utils.php:4-55`
- **Issue:** The `quality-gate.php` version is missing the `year_custom` case.
- **Impact:** Year custom reports silently show today's data if this copy loads first.
- **Fix:** Delete from `quality-gate.php`; keep only `report/utils.php`.

### #10 — HIGH — Duplication: sfa_qg_human_dur() Defined Twice
- **Location:** `quality-gate.php:2028-2036` and `report/utils.php:57-67`
- **Fix:** Keep only the `report/utils.php` version.

### #11 — HIGH — Correctness: fixedItems Overwritten to Empty Array
- **Location:** `Field_Quality_Checklist.php:74-89` then overwritten at `117-123`
- **Issue:** `fixedItems` is loaded from meta, then re-set to empty array before re-reading same meta.
- **Impact:** Wasted CPU; failedItems loaded alongside it is discarded silently.
- **Fix:** Remove the duplicate block at lines 115-123.

### #12 — HIGH — Correctness: _qc_recheck_items Read Three Times
- **Location:** `Field_Quality_Checklist.php:77, 119, 129`
- **Issue:** Same meta key read three separate times in one field render.
- **Impact:** Three redundant `gform_get_meta()` calls per field render.
- **Fix:** Consolidate into one read at the top; share the result.

### #13 — HIGH — Performance: N+1 Entry Fetching in Report Fallback
- **Location:** `collect.php:176-247` and `quality-gate.php:319-384`
- **Issue:** KPI recompute fallback calls `GFAPI::get_entry()` individually for every entry.
- **Impact:** For 500 entries, produces 500+ individual DB queries.
- **Fix:** Batch-fetch entries using `GFAPI::get_entries()` with paging.

### #14 — HIGH — Performance: sfa_qg_report_collect() Called Up to 5 Times
- **Location:** `render.php:8, 52, 53, 318-331, 92-99`
- **Issue:** Comparison view calls the collection function repeatedly for the same parameters.
- **Impact:** Total can exceed 15+ queries for a simple comparison.
- **Fix:** Cache results in a static variable keyed by `(range, form_id, ym)`.

### #15 — MEDIUM — Security: Inline onclick Handler
- **Location:** `quality-gate.php:1465`
- **Issue:** `onclick="window.open(this.src)"` bypasses CSP policies.
- **Fix:** Use `addEventListener` in a separate script block.

### #16 — MEDIUM — Correctness: Missing year_custom in Switch
- **Location:** `quality-gate.php:984-1010`
- **Issue:** `year_custom` case missing from switch, falls through to `today`.
- **Fix:** Resolved by fixing issue #9.

### #17 — MEDIUM — Correctness: Double GFAPI::get_entry() in populate_rework_choices
- **Location:** `quality-gate.php:1502-1506` and `1582-1588`
- **Issue:** Same entry fetched twice in `sfa_qg_populate_rework_choices()`.
- **Fix:** Reuse the first `$entry`.

### #18 — MEDIUM — Performance: sfa_qg_log() Defined Twice
- **Location:** `quality-gate.php:15-21` and `Step_Quality_Gate.php:7-13`
- **Fix:** Keep only in the main entry file.

### #19 — MEDIUM — Performance: $ym Variable Shadowed
- **Location:** `quality-gate.php:1988`
- **Issue:** `$ym` function parameter reassigned inside foreach loop.
- **Impact:** Original parameter value lost after loop.
- **Fix:** Rename loop variable.

### #20 — MEDIUM — Correctness: Duplicate Hook Registrations
- **Location:** `quality-gate.php:2300-2313` and `2306-2319`
- **Issue:** `sfa_qg_save_recheck_items_from_post` runs twice per entry save.
- **Impact:** Doubled work (GFAPI calls, meta writes, history pushes).
- **Fix:** Remove duplicate `add_action` calls.

### #21 — MEDIUM — Duplication: File Extraction Logic
- **Location:** `Field_Quality_Checklist.php:186-246` and `quality-gate.php:899-944`
- **Issue:** Same file parsing key chain duplicated verbatim.
- **Fix:** Refactor field to call `sfa_qg_normalize_files()`.

### #22 — MEDIUM — Correctness: Duplicate Audit Rows for Metrics
- **Location:** `quality-gate.php:2292`
- **Issue:** `sfa_qg_audit_log_fail()` called without dedup for `metric:*` keys.
- **Impact:** Inflated "Top Failing Metrics" counts.
- **Fix:** Add `sfa_qg_audit_fail_exists()` check for `metric:` keys.

### #23 — LOW — Performance: GFAPI::get_forms() on Every Report Page
- **Location:** `admin-page.php:46`
- **Fix:** Use transient cache.

### #24 — LOW — Maintainability: Monolithic 2492-line Entry File
- **Location:** `quality-gate.php`
- **Fix:** Extract into proper class files under `src/`.

### #25 — LOW — Correctness: Unsanitized $_GET in add_query_arg()
- **Location:** `render.php:360`
- **Issue:** `array_merge( $_GET, ... )` can propagate unsanitized parameters.
- **Fix:** Whitelist known parameters.

## Top 5 Priority Fixes

1. Remove `wp_ajax_nopriv_sfa_qg_items` (#1)
2. Escape photo data URLs (#2)
3. Add nonce verification to admin debug endpoints (#4)
4. Eliminate duplicate function definitions (#7-10)
5. Stop cache-busting assets with `time()` (#6)
