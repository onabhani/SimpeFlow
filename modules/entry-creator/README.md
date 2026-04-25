# Entry Creator

Lets authorized admins reassign the `created_by` property on a Gravity Forms entry from the entry detail page. Replaces the dropdown that GravityView previously provided.

## What it does

- Adds a **Change Entry Creator** meta box in the sidebar of the GF entry detail page (`admin.php?page=gf_entries&view=entry&id=<form>&lid=<entry>`).
- Shows the current entry creator, a search input, and a `<select>` of all selectable WordPress users (any role).
- The search input filters the `<select>` options client-side by display name, username, email, or user ID.
- On save, updates the entry's `created_by` via `GFAPI::update_entry_property()`, writes a GF entry note, and appends a row to the audit log table.
- Fires `sfa_entry_creator_changed` so other modules can react.

## Capabilities

A user must have **both**:

- `gravityforms_edit_entries`
- `list_users`

to see or use the dropdown. Reassigning to "no user" (`created_by = 0`) additionally requires `manage_options`.

## Database

Table: `{prefix}sfa_entry_creator_log`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT unsigned, PK | |
| `entry_id` | BIGINT unsigned | indexed |
| `form_id` | BIGINT unsigned | |
| `old_user_id` | BIGINT unsigned | 0 means previously no user |
| `new_user_id` | BIGINT unsigned | 0 means reassigned to no user |
| `changed_by` | BIGINT unsigned | indexed — WP user who made the change |
| `changed_at` | DATETIME | indexed — WP local time |
| `ip_address` | VARCHAR(45) | IPv4/IPv6; empty if unavailable |
| `reason` | TEXT | optional free-text reason supplied in the meta box |

## Hooks

### Action: `sfa_entry_creator_changed`

```php
do_action( 'sfa_entry_creator_changed', int $entry_id, int $old_user_id, int $new_user_id, int $changed_by_user_id );
```

Fires **after** the `created_by` property has been persisted, the audit row has been inserted, and the GF entry note has been written. Use this to sync downstream systems (workflow reassignment, notifications, external dashboards). Does not fire when the new user matches the old one (no-op case).

### Filter: `sfa_entry_creator_selectable_users`

```php
add_filter( 'sfa_entry_creator_selectable_users', function ( array $args ): array {
    $args['role__in'] = array( 'administrator', 'shop_manager' );
    return $args;
} );
```

Args are passed directly to `get_users()`. Default returns all users regardless of role, ordered by display name, capped at 1000.

### Filter: `sfa_entry_creator_restart_workflow_step`

```php
add_filter( 'sfa_entry_creator_restart_workflow_step', function ( bool $restart, int $entry_id, int $form_id, int $old_user_id, int $new_user_id ): bool {
    // Example: skip the auto-restart for form 42 only.
    return $form_id === 42 ? false : $restart;
}, 10, 5 );
```

After a successful creator change, the module calls `Gravity_Flow_API::send_to_step()` on the entry's current step so per-step assignment records are re-resolved against the new `created_by` (otherwise GravityFlow keeps the old assignee or shows an empty assignee until "Restart Step" is clicked manually). Default: `true`. The restart is skipped silently when GravityFlow is not active or the entry has no current step. A GF entry note records each restart by step name.

## Redirect codes

After submit the user is redirected back to the entry page with `?sfa_ec=<code>`:

| Code | Meaning |
|---|---|
| `updated` | Creator successfully changed. |
| `nochange` | Selected user matched the existing creator; nothing written. |
| `invalid_nonce` | Nonce verification failed. |
| `no_permission` | User lacks required capabilities (including the `manage_options` requirement for `created_by = 0`). |
| `invalid_user` | Target user does not exist, or entry could not be loaded. |
| `save_failed` | The write failed **or** could not be verified. See [Troubleshooting → Partial state after save_failed](#partial-state-after-save_failed). |

## Diagnostics

The save path always logs to a plugin-owned file:

```
{wp-content}/sfa-entry-creator-logs-<auth-key-hash>/debug.log
```

Falls back to a similarly-named directory under `wp-content/uploads/` when `wp-content` is not writable. The directory contains a `Deny from all` `.htaccess` and an empty `index.php` so direct web access is blocked on Apache (and the directory name is suffixed with a hash derived from `AUTH_KEY` so the path is not guessable on hosts that ignore `.htaccess`, e.g. nginx — operators on nginx should add their own deny rule).

The file is rotated at 256 KB to one generation back (`debug.log.1`).

**Render-path logging** (one line per entry-detail page render) is **off by default**. To turn it on while diagnosing a "module never loaded" or "filter never fired" suspicion, add to `wp-config.php`:

```php
define( 'SFA_EC_DIAG', true );
```

`WP_DEBUG = true` also enables it. Save-path logging stays always-on regardless, so a real `save_failed` in production still leaves a trail.

## Troubleshooting

### Partial state after `save_failed`

There is a narrow window where the save path can leave an entry in a partial state: the `created_by` property is written to the DB, but no audit row is inserted, no entry note is written, and the `sfa_entry_creator_changed` action never fires. The user sees a red "Could not save the new entry creator" notice.

**When it happens**

- **1.1.2 installs (historic).** The strict `true !== $result` check redirected every successful write to `save_failed`. Every 1.1.2 save that "failed" visually actually landed in the DB without an audit trail. Fixed in 1.1.3.
- **1.1.3+ installs (edge case).** `SaveHandler` now verifies by re-reading the entry via `GFAPI::get_entry()`. If that re-read returns `WP_Error` or a stale `created_by` (aggressive object cache, a plugin filtering `gform_get_entry`, or the row being modified by a concurrent request), we redirect to `save_failed` even though the write may have landed.

Affected code paths: `SaveHandler::handle()` → `GFAPI::update_entry_property()` → `GFAPI::get_entry()` verification → `LogRepository::insert()` / `GFAPI::add_note()` / `do_action( 'sfa_entry_creator_changed' )` (all three skipped on `save_failed`).

**How to detect affected entries**

For entries that the operator *believes* were reassigned, look for the absence of an audit row:

```sql
-- Entries whose current created_by has no matching 'new_user_id' row in the audit log
SELECT e.id AS entry_id, e.form_id, e.created_by
FROM wp_gf_entry e
LEFT JOIN wp_sfa_entry_creator_log l
       ON l.entry_id = e.id
      AND l.new_user_id = e.created_by
WHERE e.status = 'active'
  AND l.id IS NULL
  AND e.form_id IN (<forms you use this module on>);
```

Absence does not prove a partial save — it just means either the creator was never changed via this module, or the change happened under a partial-state window. Cross-check against GF entry notes (a healthy change leaves a note starting with `Entry creator changed from`).

**How to heal**

Pick one of:

1. **Back-fill audit + note manually** (preserves the real created-by timeline):
   ```php
   use SFA\EntryCreator\Database\LogRepository;

   LogRepository::insert( array(
       'entry_id'    => $entry_id,
       'form_id'     => $form_id,
       'old_user_id' => $old_user_id_you_remember,
       'new_user_id' => (int) GFAPI::get_entry( $entry_id )['created_by'],
       'changed_by'  => get_current_user_id(),
       'ip_address'  => '',
       'reason'      => 'Retroactive backfill after 1.1.2/1.1.3 save_failed partial state',
   ) );

   GFAPI::add_note( $entry_id, get_current_user_id(), wp_get_current_user()->display_name,
       'Retroactive: entry creator was reassigned during a save_failed partial state. Audit row backfilled.' );

   do_action( 'sfa_entry_creator_changed', $entry_id, $old_user_id_you_remember, (int) GFAPI::get_entry( $entry_id )['created_by'], get_current_user_id() );
   ```

2. **Double-save trick** (simpler, but produces two extra audit rows with a throwaway intermediate user):
   1. Reassign the entry to any throwaway user that is *not* the target.
   2. Reassign it back to the intended target.
   3. You now have two healthy audit rows; the intermediate row documents the workaround.

3. **Ignore.** If the correct `created_by` is already in place and you do not need the audit trail or downstream `sfa_entry_creator_changed` side-effects for the historic change, do nothing.

## Constraints

- Gravity Forms must be active. The module silently does nothing (no admin notice) if `GFAPI` is not loaded, so it is safe to leave installed.
- Admin-only. No frontend, REST, or AJAX surface.
- Native `<select size="8">` with an inline search filter — no Select2 dependency. Default cap of 1000 users keeps the DOM payload manageable; swap to AJAX-backed search via the `sfa_entry_creator_selectable_users` filter if you need to scale past that.

## Future work

- Arabic translations for all strings marked with the `simpleflow` text domain.
- AJAX-backed user search for sites with >1000 users.
- Admin UI for viewing the audit log (currently readable only via `LogRepository::get_for_entry()`).
- Optional filter to hide email addresses from the dropdown label/search token (e.g., for sites where the module is opened to lower-trust roles). The current build relies on the `list_users` capability — same bar as `/wp-admin/users.php` — so there is no new exposure under the default gate.
