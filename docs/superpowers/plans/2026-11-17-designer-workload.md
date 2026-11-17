# Designer Workload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only front-end page (shortcode) that lists every designer and the DOFS / Projects tasks currently pending for them, for managers to balance work.

**Architecture:** New SimpleFlow module `modules/designer-workload/`. Pure helpers (formatting, access rule) are plain PHP with no WordPress calls so they run in a local test script. A repository reads tasks through `GFAPI::get_entries()` and caches them for 120 s. A shortcode renders the page in the viewer's profile language; filtering by form is client-side.

**Tech Stack:** PHP 8.3 (code must stay PHP 7.4 compatible like the rest of SimpleFlow), WordPress, Gravity Forms, Gravity Flow, vanilla CSS/JS, gettext (`msgfmt` in WSL Ubuntu-24.04).

**Spec:** `docs/superpowers/specs/2026-11-17-designer-workload-design.md`

## Global Constraints

- Read only: no `GFAPI::update_*`, no `gform_update_meta`, no workflow calls that change state.
- Never raw SQL on `wp_gf_entry` / `wp_gf_entry_meta` (repo rule). Use `GFAPI::get_entries()`.
- Namespace `SFA\DesignerWorkload\`, constants `SFA_DW_VER`, `SFA_DW_DIR`, `SFA_DW_URL`, option `sfa_dw_settings`, transient `sfa_dw_tasks_v1`, shortcode `designer_workload`.
- Module version 1.0.0 (header + constant). SimpleFlow core bump 0.7.1 -> 0.8.0 (header + `SIMPLEFLOW_VER`).
- Text domain `simpleflow`; Arabic catalogue `modules/designer-workload/languages/simpleflow-ar.mo`.
- Forms default `[1, 46]`. Client field: form 1 -> `171`, form 46 -> `253`. Branch routing source: form 1 step `17`.
- Waiting buckets: `< 7` days `ok`, `7..13` `warn`, `>= 14` `crit`.
- Wait start: meta `workflow_user_id_{designerId}_timestamp`; fallback `workflow_step_{stepId}_timestamp`; fallback entry `date_created`.
- Entry link: `home_url('/workflow-inbox/') . '?page=gravityflow-inbox&view=entry&id={form}&lid={entry}'` (managers and Sales SP hold `gravityflow_status_view_all`, verified 2026-11-17).
- Access: administrator always; roles from settings (default `manager`, `sales_supervisor`); user IDs from settings (default empty).
- No em-dash character anywhere (code, comments, UI copy, commits).
- Arabic UI strings below are proposals; the owner approves them before Task 5 commits the catalogue.

## Review Focus

1. A designer with zero pending tasks: card shows 0, list shows "No open tasks", no PHP notice.
2. An entry whose current step no longer exists (deleted step): row shows "Unknown step", page still renders.
3. Empty client name: row shows "No name", not a blank cell.
4. Mixed Arabic/Latin client names in an RTL page: names wrapped in `<bdi>` so digits and Latin do not reorder.
5. More pending tasks than one query page: repository pages through results (page size 200) until done; a designer with 250 tasks shows 250.

---

### Task 1: Module skeleton + pure helpers

**Files:**
- Create: `modules/designer-workload/designer-workload.php`
- Create: `modules/designer-workload/src/Support/Format.php`
- Create: `modules/designer-workload/src/Support/Access.php`
- Create: `modules/designer-workload/tests/run.php`
- Modify: `simpleflow.php` (version header + `SIMPLEFLOW_VER` to 0.8.0)

**Interfaces:**
- Produces (all `public static`, no WP calls):
  - `Format::age_days(int $start_ts, int $now_ts): int` (floor of seconds/86400, never negative)
  - `Format::age_bucket(int $days): string` returns `'ok'|'warn'|'crit'`
  - `Format::branch_key(string $choice): string` maps `"DMNUZ - Alnuzhah Branch"` -> `'alnuzhah'`, `"DMYAS - Alyasmin Branch"` -> `'alyasmin'`, `"DM Touches"` -> `'touches'`, anything else -> `''`
  - `Format::sort_oldest_first(array $tasks): array` sorts by `$task['start_ts']` ascending, ties by `entry_id` ascending
  - `Format::count(array $tasks): int` (single place for the future weighting rule; today returns `count($tasks)`)
  - `Access::is_allowed(int $user_id, array $user_roles, array $allowed_roles, array $allowed_user_ids): bool` (false when `$user_id === 0`; true if `'administrator'` in roles, or any role in `$allowed_roles`, or `$user_id` in `$allowed_user_ids`)
- Task array shape used everywhere: `['entry_id'=>int,'form_id'=>int,'form_title'=>string,'client'=>string,'step_name'=>string,'start_ts'=>int]`

- [ ] **Step 1: Write `tests/run.php`** (plain PHP, defines `ABSPATH` stub, requires the two Support files, tiny `check($label, $actual, $expected)` helper that prints PASS/FAIL and exits 1 on any failure). Assertions:
  - `age_days(0, 86399) === 0`, `age_days(0, 86400) === 1`, `age_days(1000, 0) === 0`
  - `age_bucket(6) === 'ok'`, `age_bucket(7) === 'warn'`, `age_bucket(13) === 'warn'`, `age_bucket(14) === 'crit'`
  - `branch_key('DMNUZ - Alnuzhah Branch') === 'alnuzhah'`, `branch_key('DMYAS - Alyasmin Branch') === 'alyasmin'`, `branch_key('DM Touches') === 'touches'`, `branch_key('Other') === ''`
  - sort: input start_ts `[300, 100, 100(entry 9), 100(entry 5)]` -> entry order `5, 9, ...` then 300 last
  - `count([]) === 0`, `count([a,b,c]) === 3`
  - `is_allowed(0, ['administrator'], [], []) === false`
  - `is_allowed(5, ['administrator'], [], []) === true`
  - `is_allowed(5, ['manager'], ['manager','sales_supervisor'], []) === true`
  - `is_allowed(5, ['designer'], ['manager'], []) === false`
  - `is_allowed(42, [], ['manager'], [42]) === true` (user with no role, picked by name)
- [ ] **Step 2: Run** `php modules/designer-workload/tests/run.php`. Expected: fatal (classes missing).
- [ ] **Step 3: Implement** `Format` and `Access`, and the entry file `designer-workload.php` modelled on `modules/code-validation/code-validation.php` (header `Version: 1.0.0`, constants, PSR-4 autoloader for `SFA\DesignerWorkload\`, `plugins_loaded` priority 20 init that does nothing else yet). Bump `simpleflow.php` to 0.8.0.
- [ ] **Step 4: Run** the test script. Expected: all PASS, exit 0. Run `php -l` on each new/changed PHP file. Expected: "No syntax errors".
- [ ] **Step 5: Commit** `Designer Workload 1.0.0 / SimpleFlow 0.8.0: module skeleton + pure helpers`.

### Task 2: Settings page (who can view)

**Files:**
- Create: `modules/designer-workload/src/Admin/SettingsPage.php`
- Create: `modules/designer-workload/src/Support/Settings.php`
- Modify: `modules/designer-workload/designer-workload.php` (instantiate SettingsPage when `is_admin()`)

**Interfaces:**
- Produces: `Settings::get(): array` returns `['roles'=>string[], 'users'=>int[], 'forms'=>int[]]` with defaults `['manager','sales_supervisor']`, `[]`, `[1,46]` merged over `get_option('sfa_dw_settings')`.
- Produces: `Settings::sanitize(array $raw): array` (roles kept only if in `wp_roles()->get_names()`; users `absint`, kept only if `get_userdata()` exists; forms `absint`, kept only if `GFAPI::get_form()` returns an array; arrays de-duplicated).
- Submenu under parent `'simpleflow'`, title "Designer Workload", capability `manage_options`, slug `sfa-designer-workload`.

- [ ] **Step 1: Implement** the page: checkbox list of roles (excluding `administrator`, shown as "always allowed"), a multi-select of users (all users, label `display_name (user_login)`, ordered by display name), checkboxes for active GF forms. Save via `admin-post.php` action `sfa_dw_save` with `check_admin_referer('sfa_dw_save')` + `current_user_can('manage_options')`, then `update_option('sfa_dw_settings', Settings::sanitize($_POST['sfa_dw'] ?? []), false)` and `delete_transient('sfa_dw_tasks_v1')`, redirect back with `updated=1`.
- [ ] **Step 2: Run** `php -l` on both files. Expected: no syntax errors.
- [ ] **Step 3: Commit** `Designer Workload: settings page for allowed roles, users, forms`.

### Task 3: Task repository

**Files:**
- Create: `modules/designer-workload/src/Data/WorkloadRepository.php`
- Create: `scripts/designer-workload-check.php` (WP-CLI `eval-file`, read only)

**Interfaces:**
- Consumes: `Settings::get()`, `Format::sort_oldest_first()`, task array shape (Task 1).
- Produces: `WorkloadRepository::get_board(): array` returning
  `['generated_at'=>int, 'designers'=>[['id'=>int,'name'=>string,'branch'=>string /* Format::branch_key */,'tasks'=>task[] /* sorted */], ...]]`, designers ordered by display name.
- Produces: `WorkloadRepository::flush(): void` deletes the transient.

- [ ] **Step 1: Implement `get_board()`:**
  - Return `get_transient('sfa_dw_tasks_v1')` if it is an array.
  - Designers: `get_users(['role'=>'designer','fields'=>['ID','display_name']])`.
  - Branch map: `gravity_flow()->get_step(17)`; if it exists read `get_feed_meta()['routing']`, map `user_id|N` -> `Format::branch_key(value)`.
  - For each designer and each form in `Settings::get()['forms']`: `GFAPI::get_entries($form_id, ['status'=>'active','field_filters'=>[['key'=>'workflow_final_status','value'=>'pending'],['key'=>'workflow_user_id_'.$id,'value'=>'pending'],'mode'=>'all']], ['key'=>'id','direction'=>'ASC'], ['offset'=>$o,'page_size'=>200])`, looping offset until fewer than 200 come back (Review Focus 5). Skip `WP_Error`.
  - Per entry: client from field 171 (form 1) or 253 (form 46), trimmed; step name from `gravity_flow()->get_step((int) rgar($entry,'workflow_step'), $entry)`, `''` when null (Review Focus 2); `start_ts` per the Global Constraints fallback chain (`gform_get_meta`).
  - Form titles via one `GFAPI::get_form()` per form, cached in a local array.
  - `set_transient('sfa_dw_tasks_v1', $board, 120)`.
- [ ] **Step 2: Write `scripts/designer-workload-check.php`:** calls `WorkloadRepository::flush()` then `get_board()`, prints `designer_id name branch count oldest_entry` per designer, and per designer a second count from `GFAPI::count_entries()` with the same two filters summed over the forms; prints `MATCH` or `MISMATCH` per designer.
- [ ] **Step 3: Run** `php -l` on both files. Expected: no syntax errors.
- [ ] **Step 4: Commit** `Designer Workload: read-only task repository + WP-CLI check script`.

### Task 4: Shortcode, layout, mobile

**Files:**
- Create: `modules/designer-workload/src/Frontend/Shortcode.php`
- Create: `modules/designer-workload/assets/designer-workload.css`
- Create: `modules/designer-workload/assets/designer-workload.js`
- Modify: `modules/designer-workload/designer-workload.php` (instantiate Shortcode; register textdomain loader)

**Interfaces:**
- Consumes: `Access::is_allowed()`, `Settings::get()`, `WorkloadRepository::get_board()`, `Format::*`.
- Produces: shortcode `[designer_workload]`.

- [ ] **Step 1: Implement `Shortcode::render(): string`:**
  - Not logged in or `Access::is_allowed(...)` false -> return a `<p class="sfa-dw-denied">` with "You do not have access to this page." and nothing else.
  - `switch_to_user_locale(get_current_user_id())`, then `load_textdomain('simpleflow', SFA_DW_DIR.'languages/simpleflow-'.determine_locale().'.mo')` when that file exists; `restore_previous_locale()` before returning. Wrapper gets `dir="rtl"` when `is_rtl()` after the switch.
  - Enqueue the CSS/JS (version `SFA_DW_VER`) only here.
  - Markup follows the approved mockup (https://claude.ai/artifact/Av3EhStQdMeUMCowBamtNd): header (title, subtitle, filter chips All forms / one chip per form title), summary cards (name, branch label, count via `Format::count`, share bar, per-form split), one `<details open>` per designer with a table (Order #, Client, Form, Current step, Waiting), "Updated %s" stamp using `wp_date(get_option('time_format'), $board['generated_at'])`.
  - Each `<tr>` carries `data-form="{form_id}"`; each card/summary count carries per-form numbers in `data-*` so JS can recount on filter.
  - Client inside `<bdi>`; empty client -> "No name" (Review Focus 3, 4). Empty step -> "Unknown step". Zero tasks -> "No open tasks" row (Review Focus 1).
  - Waiting cell: `sprintf(_n('%d day','%d days',$d,'simpleflow'),$d)` in a pill with class `age-{bucket}`.
  - Branch labels: `alnuzhah` -> "Alnuzhah", `alyasmin` -> "Alyasmin", `touches` -> "Touches" (all `__()`); `''` -> no label.
  - All output escaped (`esc_html`, `esc_url`, `esc_attr`).
- [ ] **Step 2: CSS:** port the mockup styles, scoped under `.sfa-dw`, using logical properties (`margin-inline-start`, `text-align: start`, `inset-inline-*`) so RTL mirrors; container query at 560px turns rows into cards and stacks the summary; tabular numbers; visible focus states; no external fonts (theme font).
- [ ] **Step 3: JS (no dependencies):** chip click sets `aria-pressed`, hides `tr[data-form]` not matching, recomputes each designer's visible count and summary number from the `data-*` values; summary card click opens and scrolls to that designer's `<details>`.
- [ ] **Step 4: Run** `php -l` on the PHP file. Expected: no syntax errors. Run `php modules/designer-workload/tests/run.php`. Expected: all PASS.
- [ ] **Step 5: Commit** `Designer Workload: shortcode page with mobile layout and form filter`.

### Task 5: Arabic catalogue (after owner approves wordings)

**Files:**
- Create: `modules/designer-workload/languages/simpleflow-ar.po`
- Create: `modules/designer-workload/languages/simpleflow-ar.mo`

Proposed wordings (owner to approve; adjust before committing):

| English | Arabic |
|---|---|
| Designer Workload | توزيع أعمال المصممين |
| Open drawing tasks per designer, oldest first | مهام الرسم المفتوحة لكل مصمم، الأقدم أولاً |
| Filter by form | تصفية حسب النموذج |
| All forms | كل النماذج |
| open | مفتوحة |
| Order # | رقم الطلب |
| Client | العميل |
| Form | النموذج |
| Current step | الخطوة الحالية |
| Waiting | مدة الانتظار |
| %d day / %d days | 6 forms: `%d يوم` (0), `يوم واحد` (1), `يومان` (2), `%d أيام` (3-10), `%d يومًا` (11-99), `%d يوم` (100+) |
| Updated %s | آخر تحديث %s |
| Alnuzhah | النزهة |
| Alyasmin | الياسمين |
| Touches | تاتشز |
| No open tasks | لا توجد مهام مفتوحة |
| No name | بدون اسم |
| Unknown step | خطوة غير معروفة |
| No designers found. | لا يوجد مصممون. |
| You do not have access to this page. | ليس لديك صلاحية لعرض هذه الصفحة. |

- [ ] **Step 1: Get owner approval** of the table above (Arabic, in chat).
- [ ] **Step 2: Write the `.po`** with header `Plural-Forms: nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5);` and every `__()`/`_n()` string used by Tasks 2 and 4 (grep `'simpleflow'` in the module to confirm none is missing).
- [ ] **Step 3: Compile** from WSL: `msgfmt -c -o simpleflow-ar.mo simpleflow-ar.po` (write any helper script from the Windows side, run by path). Expected: exit 0, no warnings.
- [ ] **Step 4: Commit** `Designer Workload: Arabic catalogue`.

### Task 6: Review, deploy, verify (each server step needs owner GO)

- [ ] **Step 1:** Push branch, open PR, get a review on the exact head (Codex review on the head commit is enough). Fix findings, re-review the new head.
- [ ] **Step 2 (owner GO):** Merge. Deploy manually: back up `wp-content/plugins/SimpeFlow` on the server to `~/backups/simpleflow-<head>/pre.tgz`, copy the changed files, `php -l` them on the server, `wp cache flush`.
- [ ] **Step 3:** Run `wp eval-file .../scripts/designer-workload-check.php` on the server. Expected: one line per designer, all `MATCH`, counts close to 2026-11-17 numbers (Aslam ~59, Bejay ~37, Alhanouf ~17).
- [ ] **Step 4 (owner GO):** Create the page: `wp post create --post_type=page --post_status=publish --post_title='Designer Workload' --post_name=designer-workload --post_content='[designer_workload]'`.
- [ ] **Step 5:** Verify in the browser: as an administrator (English), at phone width, a user whose profile is Arabic (RTL, Arabic plurals), and a non-allowed user (sees only the denial line). Owner adds the two people in Settings > SimpleFlow > Designer Workload and confirms they can open both the page and an entry link (if an entry link is refused for them, they lack `gravityflow_status_view_all`; tell the owner).
- [ ] **Step 6:** Rollback note for the owner: disable the module in Settings > SimpleFlow, or restore `pre.tgz`.
