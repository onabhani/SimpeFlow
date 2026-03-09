# Update Requests Module — Audit Report

**Module Version:** 1.2.4
**Audit Date:** 2026-03-09
**Baseline Commit:** `7cd9beb` (pre-audit snapshot)
**Current Revision:** PR #51 on branch `audit/update-requests-issues`
**Status:** Verified — all findings reviewed against source code

---

## Issues Found

| # | Severity | File | Line(s) | Type | Description | Verification | Status |
|---|----------|------|---------|------|-------------|--------------|--------|
| 1 | Low | `ParentPanel.php` | 120 | Best Practice | `$status_color` unescaped in CSS — source is hardcoded array, not user-controlled | Overstated as Critical; source is safe, `esc_attr()` is defense-in-depth | **Fixed** (v1.2.1) |
| 2 | Low | `ApprovalGuards.php` | 207 | Best Practice | Loose `==` on entry IDs — PHP 8.2+ (project target) has no type juggling risk here | Overstated as High; not a real bug on PHP 8.2+ | **Fixed** (v1.2.1) |
| 3 | Low | `FileVersionApplier.php` | 140–237 | Best Practice | Unescaped values in entry notes — inputs already sanitized via `sanitize_textarea_field()` | Overstated as High; defense-in-depth only | **Fixed** (v1.2.1) |
| 4 | **High** | `ChildLinking.php` / `UpdateRequestModal.php` / `ApprovalGuards.php` | multiple | Race Condition | Concurrent read-modify-write on `_ur_children` JSON meta (no lock) | **Verified** — real race condition, fix follows production-scheduling pattern | **Fixed** (v1.2.1) |
| 5 | N/A | `UpdateRequestModal.php` | 134–155 | Auth | Claimed broad `edit_posts` check needed tightening | **False positive** — `is_entry_creator()` IS the auth gate; fix introduced unintended admin bypass | **Reverted** (v1.2.4) |
| 6 | Low | `UpdateRequestModal.php` | 37 | Best Practice | Manual apply uses GET — nonce IS verified, WP uses GET+nonce for many admin actions | Overstated as High; not a real CSRF vulnerability | **Fixed** (v1.2.1) |
| 7 | Low | `VersionManager.php` | 27 | Best Practice | `json_decode()` without `json_last_error()` — original already returns `[]` on corrupt JSON | Overstated as Medium; graceful handling existed, logging is nice-to-have | **Fixed** (v1.2.2) |
| 8 | Low | `FormSettings.php` | 80–90 | Best Practice | GravityFlow API without try-catch — GF API uses WP_Error pattern, not exceptions | Overstated as Medium; try-catch is harmless but unnecessary | **Fixed** (v1.2.2) |
| 9 | Low | Multiple files | — | Best Practice | `wp_get_current_user()` without `is_user_logged_in()` — all call sites require login | Overstated as Medium; all paths are gated by AJAX/admin hooks | **Fixed** (v1.2.2) |
| 10 | Low | `ChildLinking.php` | — | Cleanup | No `gform_delete_entry` hook — stale refs are harmless (ParentPanel skips missing entries) | Improvement for data cleanliness, not a functional bug | **Fixed** (v1.2.2) |
| 11 | Medium | `FileVersionApplier.php` | 270–292 | Logic | Comma-separated file branch is dead code — GF always stores multi-file as JSON | **Verified** — code smell, fix normalizes to GF standard | **Fixed** (v1.2.2) |
| 12 | Low | `UpdateRequestModal.php` | 383 | Best Practice | Extension-only validation — `wp_handle_upload()` already validates MIME internally | Overstated as Medium; added check is redundant but harmless | **Fixed** (v1.2.2) |
| 13 | Low | `FormSettings.php` | 45 | Best Practice | Settings page capability check — GF already gates form settings with `gravityforms_edit_forms` | Overstated as Medium; defense-in-depth | **Fixed** (v1.2.2) |
| 14 | N/A | `UpdateRequestModal.php` | 200–226 | Atomicity | Claimed non-atomic entry creation needed try-catch | **False positive** — `gform_update_meta()` doesn't throw exceptions; try-catch was ineffective | **Reverted** (v1.2.4) |
| 15 | Low | `ParentPanel.php` / `FileVersionWidget.php` | 125 / 162 | Best Practice | `date()` replaced with `wp_date()` for WP timezone awareness | Marginal improvement, not a bug | **Fixed** (v1.2.3) |
| 16 | Low | `FileVersionWidget.php` | 169–193 | Best Practice | Added `esc_attr()` to numeric data attributes and hidden inputs | Correct best practice, not a vulnerability | **Fixed** (v1.2.3) |
| 17 | Low | Multiple files | — | Code Quality | Duplicate `is_json()` in two classes | Trivial 3-line method, not worth abstracting | **Accepted** |
| 18 | Low | `test-update-request.php` | — | Deployment | Test file in module root moved to `tests/` | Minor improvement | **Fixed** (v1.2.3) |
| 19 | Low | `test-update-request.php` | 38 | Quality | Referenced non-existent `EntryUpdating` class | **Verified** — real bug in test file | **Fixed** (v1.2.3) |
| 20 | Low | `FormSettings.php` | 582 | Documentation | Documented `created_by` immutability assumption | Documentation improvement | **Fixed** (v1.2.3) |

---

## Verification Summary

- **1 verified defect**: Issue #4 (race condition) — real concurrency bug, properly fixed with `GET_LOCK`
- **1 verified code smell**: Issue #11 (dead comma-separated branch) — normalized to GF JSON standard
- **1 verified test bug**: Issue #19 (non-existent class reference)
- **2 false positives reverted**: Issue #5 (auth change introduced admin bypass), Issue #14 (ineffective try-catch)
- **15 best-practice improvements**: Correct but overstated in severity; fixes are defense-in-depth
