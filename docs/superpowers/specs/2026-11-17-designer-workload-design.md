# Designer Workload page (design)

Date: 2026-11-17. Status: awaiting owner approval.
Mockup (approved in principle, English version): https://claude.ai/artifact/Av3EhStQdMeUMCowBamtNd

## Intent

Managers and supervisors need one place to see how many drawing tasks each designer holds right now, so they can balance work between designers. Today assignment is automatic by branch (field 229 "Current Branch" on form 1), not by load, and the load is uneven (2026-11-17: Aslam 59, Bejay 37, Alhanouf 17).

## Goals

- One front-end page listing every designer and the tasks currently assigned to them in forms DOFS (1) and Projects (46).
- Per task: order number (entry ID, linked to the entry), client name, form, current step, days waiting.
- Works well on phone and computer.
- Simple, fast, clear, secure.
- Arabic and English: follows the viewer's WordPress profile language; Arabic renders right to left.

## Non-goals (this version)

- Moving or reassigning tasks (owner: view only for now).
- Weighting tasks by type. Owner note: Alnuzhah and Alyasmin work is mostly washbasins, Touches work is larger marble jobs; a weighting rule comes later. The count is computed in one place so a weight can be added there.
- Notifications, exports, history or charts.

## Anti-goals

- Must not write to any entry or change workflow state.
- Must not slow down the inbox or other pages (runs only on its own page).
- Must not show the page to staff outside the allowed list.

## Facts from the live system (2026-11-17)

- Designers = users with role `designer`: Aslam Panwar (7, Alnuzhah), Bejay Ramos (6, Alyasmin), Alhanouf Mahzari (3919, Touches and all of Projects).
- Designer steps, form 1: 209 Files Check - Design Level, 17 Preparing Drawings, 21 Rejected Drawings, 25 Drawing Approval (routed). Form 46: 1718 Project Drawings, 1708 New Project Drawings.
- Pending assignments also appear on a few other form 1 steps (14, 1507: one each), so the page counts any pending designer assignment, not a fixed step list.
- Client name: form 1 field 171 "Client Name"; form 46 has no client field, use field 253 "Project Name".
- 11 users have profile language `ar`, 23 `en_US`, the rest use the site default (English).
- Existing entry link pattern in SimpleFlow: `home_url('/workflow-inbox/') . '?page=gravityflow-inbox&view=entry&id={form}&lid={entry}'`.

## Approaches considered

1. **New SimpleFlow module with a shortcode (chosen).** Same pattern as production-scheduling (`[production_schedule]`). Light, full control over access and mobile layout, toggled from Settings > SimpleFlow.
2. Gravity Flow's own inbox shortcode: shows only the viewer's own tasks and cannot group by designer.
3. SimpleViews / reports plugin: built for entry field listings, not workflow assignment state; more change for less fit.

## Design

- Module `modules/designer-workload/`, namespace `SFA\DesignerWorkload\`, constants `SFA_DW_*`, options `sfa_dw_*`.
- Shortcode `[designer_workload]` placed on a new page "Designer Workload" / "توزيع أعمال المصممين".
- **Designers:** read from role `designer` at render time, so a fourth designer appears with no code change.
- **Tasks:** active entries in the configured forms (default 1 and 46) whose workflow is pending and that have a pending assignment for the designer (`workflow_user_id_{ID}` = `pending`). Read through `GFAPI::get_entries()` with field filters, never raw SQL on GF tables (repo rule). Steps that route to a role rather than a user are out of scope (none route designer work by role today).
- **Columns:** Order # (entry ID, links to the workflow inbox entry view), Client (form 1 f171, form 46 f253), Form (form title), Current step (step name from Gravity Flow), Waiting (days since the current step started).
- **Waiting colours:** under 7 days green, 7 to 13 amber, 14 or more red (owner approved).
- **Order:** oldest waiting first inside each designer.
- **Layout:** summary cards on top (name, branch, open count, share bar, DOFS/Projects split), form filter chips (All / DOFS / Projects), then one collapsible list per designer. Below about 560px the summary stacks and rows become cards (CSS container query). No external libraries; module CSS/JS enqueued only when the shortcode renders.
- **Branch label:** taken from the designer's routing on step 17 (field 229 value), shown in a short form (Alnuzhah, Alyasmin, Touches); falls back to nothing if a designer has no branch routing.
- **Access:** allowed if the viewer is an administrator, OR has a selected role (default: `manager`, `sales_supervisor`), OR is one of the selected users. Users and roles are chosen in a module settings page under SimpleFlow (admin only). This lets the owner add two people who have no role yet. Everyone else, and logged-out visitors, gets a short "no access" message; nothing about tasks is rendered. Checked on the server on every render.
- **Speed:** results cached in a transient for 2 minutes (one key per form filter); page shows "Updated HH:MM". Filter chips work client-side on the already rendered rows, no extra requests.
- **Security:** read only; all output escaped; settings saved with nonce + `manage_options`; no AJAX endpoint.
- **Language:** all UI strings in `__()` with the `simpleflow` text domain; Arabic `.po/.mo` shipped with the module; Arabic wordings shown to the owner for approval before they are committed. Layout uses CSS logical properties so it mirrors in RTL. Step names and form titles stay as stored in Gravity Forms.

## Left to the builder

- Exact Gravity Flow meta key for the current step's start time (check what the inbox uses for "Due/Step started"); fall back to the entry's `date_created` if missing, and say so in the plan.
- Whether managers can open the inbox entry view for an entry not assigned to them (needs `gravityflow_status_view_all` or similar). If not, link to the status page view instead.
- Settings UI details (a searchable multi-select of users is enough).

## Verification

- `php -l` on every file (repo has no test suite).
- On live after a manual deploy: counts per designer match a read-only WP-CLI count of pending `workflow_user_id_{ID}` assignments; page checked as an allowed user and as a non-allowed user; checked at phone width and in Arabic.
- Rollback: disable the module in Settings > SimpleFlow, or remove the folder.
