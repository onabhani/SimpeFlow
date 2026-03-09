# Update Requests Module — Audit Report

**Module Version:** 1.2.2
**Audit Date:** 2026-03-09
**Status:** Stage 1 & 2 (Critical/High/Medium) — Fixed

---

## Issues Found

| # | Severity | File | Line(s) | Type | Description | Status |
|---|----------|------|---------|------|-------------|--------|
| 1 | **Critical** | `ParentPanel.php` | 120 | XSS | `$status_color` output directly into inline CSS without `esc_attr()` | **Fixed** (v1.2.1) |
| 2 | **High** | `ApprovalGuards.php` | 207 | Type Bug | Loose `==` comparison on entry IDs instead of `===` | **Fixed** (v1.2.1) |
| 3 | **High** | `FileVersionApplier.php` | 140–237 | XSS | Unescaped `$reason` and filenames in HTML entry notes | **Fixed** (v1.2.1) |
| 4 | **High** | `ChildLinking.php` / `UpdateRequestModal.php` / `ApprovalGuards.php` | multiple | Race Condition | Concurrent read-modify-write on `_ur_children` JSON meta (no lock) | **Fixed** (v1.2.1) |
| 5 | **High** | `UpdateRequestModal.php` | 134–155 | Auth | Broad `edit_posts` capability check; entry creator check comes second | **Fixed** (v1.2.1) |
| 6 | **High** | `UpdateRequestModal.php` | 37 | CSRF | State-changing "apply" action uses GET instead of POST | **Fixed** (v1.2.1) |
| 7 | **Medium** | `VersionManager.php` | 27 | Error Handling | `json_decode()` without `json_last_error()` check — silently discards corrupt data | **Fixed** (v1.2.2) |
| 8 | **Medium** | `FormSettings.php` | 80–90 | Error Handling | GravityFlow API instantiated without try-catch; may throw if not initialized | **Fixed** (v1.2.2) |
| 9 | **Medium** | Multiple files | — | Safety | `wp_get_current_user()->display_name` called without `is_user_logged_in()` guard | **Fixed** (v1.2.2) |
| 10 | **Medium** | `ChildLinking.php` | — | Cleanup | No `gform_delete_entry` hook — orphaned child entries persist when parent is deleted | **Fixed** (v1.2.2) |
| 11 | **Medium** | `FileVersionApplier.php` | 270–292 | Logic | Ambiguous file value format detection (JSON vs comma-separated vs single) | **Fixed** (v1.2.2) |
| 12 | **Medium** | `UpdateRequestModal.php` | 383 | Security | File upload validates extension only, not MIME type | **Fixed** (v1.2.2) |
| 13 | **Medium** | `FormSettings.php` | 45 | Auth | `render_settings_page()` missing capability check | **Fixed** (v1.2.2) |
| 14 | **Medium** | `UpdateRequestModal.php` | 200–226 | Atomicity | Child entry creation + linking is not transactional; failure mid-way leaves orphans | **Fixed** (v1.2.2) |
| 15 | **Low** | `ParentPanel.php` | 125 | Time | `strtotime()` without timezone context — mismatch with WordPress UTC storage | Pending |
| 16 | **Low** | `FileVersionWidget.php` | 169–193 | Best Practice | Numeric data attributes output without `esc_attr()` | Pending |
| 17 | **Low** | Multiple files | — | Code Quality | Duplicate `is_json()` method in `FileVersionApplier` and `FileVersionWidget` | Pending |
| 18 | **Low** | `test-update-request.php` | — | Deployment | Test file shipped in production module directory | Pending |
| 19 | **Low** | `test-update-request.php` | 38 | Quality | References non-existent class `EntryUpdating` | Pending |
| 20 | **Low** | `FormSettings.php` | 575 | Documentation | Undocumented assumption that `created_by` is immutable | Pending |

---

## Recommended Fix Order

1. **Stage 1 — Critical/High** (Issues 1–6): XSS, race condition, auth, type safety
2. **Stage 2 — Medium** (Issues 7–14): Error handling, cleanup, atomicity, file validation
3. **Stage 3 — Low** (Issues 15–20): Best practices, code quality, cleanup
