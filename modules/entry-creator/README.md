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

## Redirect codes

After submit the user is redirected back to the entry page with `?sfa_ec=<code>`:

| Code | Meaning |
|---|---|
| `updated` | Creator successfully changed. |
| `nochange` | Selected user matched the existing creator; nothing written. |
| `invalid_nonce` | Nonce verification failed. |
| `no_permission` | User lacks required capabilities (including the `manage_options` requirement for `created_by = 0`). |
| `invalid_user` | Target user does not exist, or entry could not be loaded. |
| `save_failed` | `GFAPI::update_entry_property()` returned a non-true result. |

## Constraints

- Gravity Forms must be active. The module silently does nothing (no admin notice) if `GFAPI` is not loaded, so it is safe to leave installed.
- Admin-only. No frontend, REST, or AJAX surface.
- Native `<select size="8">` with an inline search filter — no Select2 dependency. Default cap of 1000 users keeps the DOM payload manageable; swap to AJAX-backed search via the `sfa_entry_creator_selectable_users` filter if you need to scale past that.

## Future work

- Arabic translations for all strings marked with the `simpleflow` text domain.
- AJAX-backed user search for sites with >1000 users.
- Admin UI for viewing the audit log (currently readable only via `LogRepository::get_for_entry()`).
