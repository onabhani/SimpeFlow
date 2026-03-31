# SimpleFlow — Customer Module
## Build Plan v2.1 — Claude CLI

---

## Status Legend
- ✅ Built — do not touch
- 🔨 Modify — extend existing file
- 🆕 New — build from scratch

---

## Current File Structure

```
simple-flow/
└── modules/
    └── customer-lookup/
        ├── customer-lookup.php              ✅ bootstrap + autoloader
        └── src/
            ├── Ajax/
            │   └── LookupHandler.php        ✅ AJAX endpoint
            ├── Admin/
            │   └── SettingsPage.php         ✅ settings UI
            └── Database/
                └── CustomerRepository.php   🔨 add query_sf_table() path
        └── assets/
            ├── customer-lookup.js           ✅ lookup client
            ├── sf-customers-admin.css       🆕
            └── sf-customers-admin.js        🆕
```

---

## Target File Structure (after this build)

```
simple-flow/
└── modules/
    └── customer-lookup/
        ├── customer-lookup.php              🔨 add version-gated table install + CustomersAdmin init
        └── src/
            ├── Ajax/
            │   └── LookupHandler.php        ✅ no changes
            ├── Admin/
            │   ├── SettingsPage.php         🔨 add SF table toggle
            │   ├── CustomersAdmin.php       🆕 menu, routing, enqueue, AJAX
            │   └── CustomersListTable.php   🆕 extends WP_List_Table
            ├── Database/
            │   ├── CustomerRepository.php   🔨 add query_sf_table()
            │   ├── CustomerTable.php        🆕 schema + CRUD
            │   └── CustomerMigrate.php      🆕 GF → wp_sf_customers
        └── assets/
            ├── customer-lookup.js           ✅ no changes
            ├── sf-customers-admin.css       🆕
            └── sf-customers-admin.js        🆕
```

**Notes:**
- All new PHP classes live under `src/` and are resolved by the existing PSR-4 autoloader (`SFA\CustomerLookup\*` → `src/`). **Do not add `require_once` for any autoloaded class.**
- All assets live in `modules/customer-lookup/assets/` (top-level, not inside `src/`).

---

## Build Order

Run phases in this exact sequence. Each phase depends on the previous.

| Step | File | Type |
|---|---|---|
| 1 | `Database/CustomerTable.php` | 🆕 |
| 2 | Register version-gated table install in `customer-lookup.php` | 🔨 |
| 3 | `Database/CustomerMigrate.php` | 🆕 |
| 4 | `Database/CustomerRepository.php` — add `query_sf_table()` | 🔨 |
| 5 | `Admin/SettingsPage.php` — add SF table toggle | 🔨 |
| 6 | `Admin/CustomersListTable.php` | 🆕 |
| 7 | `Admin/CustomersAdmin.php` | 🆕 |
| 8 | `assets/sf-customers-admin.css` | 🆕 |
| 9 | `assets/sf-customers-admin.js` | 🆕 |
| 10 | Register `CustomersAdmin` in `customer-lookup.php` | 🔨 |

---

## Phone Normalization (applies to ALL paths)

All phone values must be normalized to digits-only before storage and lookup.

**Helper (add to `CustomerTable`):**
```php
public static function normalize_phone( string $phone ): string {
    return ltrim( preg_replace( '/[^0-9]/', '', $phone ), '0' );
}
```

**Rules:**
- Strip all non-digit characters (including `+`)
- Strip leading zeros (so `0561133336` and `561133336` resolve the same)
- Apply on every insert, update, and lookup path
- Migration must normalize before inserting
- The UNIQUE constraint on `phone` then correctly prevents true duplicates
- `query_sf_table()` does NOT need +prefix variant handling — data is already normalized on write

---

## Step 1 — `Database/CustomerTable.php` 🆕

Namespace: `SFA\CustomerLookup\Database`
Class: `CustomerTable`

### Table: `wp_sf_customers`

```sql
CREATE TABLE wp_sf_customers (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone         VARCHAR(20)  NOT NULL,
    phone_alt     VARCHAR(20)  DEFAULT NULL,
    name_arabic   VARCHAR(255) NOT NULL,
    name_english  VARCHAR(255) DEFAULT NULL,
    email         VARCHAR(255) DEFAULT NULL,
    address       TEXT         DEFAULT NULL,
    customer_type VARCHAR(20)  NOT NULL DEFAULT 'individual',
    branch        VARCHAR(100) DEFAULT NULL,
    file_number   VARCHAR(100) DEFAULT NULL,
    odoo_id       BIGINT UNSIGNED DEFAULT NULL,
    source        VARCHAR(20)  NOT NULL DEFAULT 'manual',
    status        VARCHAR(10)  NOT NULL DEFAULT 'active',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY   (id),
    UNIQUE KEY    uq_phone (phone),
    KEY           idx_phone_alt (phone_alt),
    KEY           idx_status_created (status, created_at),
    KEY           idx_odoo_id (odoo_id),
    KEY           idx_file_number (file_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Schema decisions (vs v2.0):**
- **VARCHAR instead of ENUM** for `customer_type`, `source`, `status` — `dbDelta()` misparses ENUM on updates, causing spurious ALTER TABLE on every activation. Validate allowed values in PHP instead.
- **No `ON UPDATE CURRENT_TIMESTAMP`** — `dbDelta()` silently ignores it. Set `updated_at` explicitly in the `update()` method.
- **`idx_status_created` composite index** replaces standalone `idx_status` — a 2-value column alone is useless to the optimizer. The composite covers the default list view (WHERE status + ORDER BY created_at).

### Allowed values (validate in PHP)
```php
const VALID_CUSTOMER_TYPES = [ 'individual', 'company', 'project' ];
const VALID_SOURCES        = [ 'manual', 'odoo', 'migration' ];
const VALID_STATUSES       = [ 'active', 'inactive' ];
```

### Methods

**`static table_name(): string`**
- Returns `$wpdb->prefix . 'sf_customers'`

**`static create_table(): void`**
- Use `dbDelta()` via `require_once ABSPATH . 'wp-admin/includes/upgrade.php'`
- Called from version-gated check in `customer-lookup.php` (not activation hook)

**`static normalize_phone( string $phone ): string`**
- Strip non-digits, strip leading zeros
- Used by all insert/update/lookup paths

**`static insert( array $data ): int|false`**
- Allowed columns: `phone, phone_alt, name_arabic, name_english, email, address, customer_type, branch, file_number, odoo_id, source, status`
- Strip any keys not in allowed list
- Normalize `phone` and `phone_alt` via `normalize_phone()`
- Validate `customer_type` against `VALID_CUSTOMER_TYPES`, `source` against `VALID_SOURCES`, `status` against `VALID_STATUSES` — reject with `false` if invalid
- Sanitize all string values with `sanitize_text_field()`, email with `sanitize_email()`
- `$wpdb->insert()` — return insert ID or false
- After insert: `do_action( 'sf_customer_created', $id, $data )`

**`static update( int $id, array $data ): bool`**
- Same allowed columns whitelist (exclude `id`, `created_at`)
- Normalize phone fields, validate enum-like fields (same as insert)
- Explicitly set `$data['updated_at'] = current_time( 'mysql', true )`
- `$wpdb->update()` — return bool
- After update: `do_action( 'sf_customer_updated', $id, $data )`

**`static get_by_phone( string $phone ): ?object`**
- Normalize input via `normalize_phone()`
- Query WHERE `phone = %s OR phone_alt = %s` AND `status = 'active'`
- Return single row as object or null

**`static get_by_id( int $id ): ?object`**
- Return single row regardless of status (admin use)

**`static phone_exists( string $phone, int $exclude_id = 0 ): bool`**
- Normalize input, check if phone exists in `phone` or `phone_alt` columns
- Optionally exclude a record by ID (for edit forms)
- Used by duplicate check AJAX and form validation

**`static deactivate( int $id ): bool`**
- Set `status = 'inactive'`, set `updated_at`
- `do_action( 'sf_customer_deactivated', $id )`

**`static reactivate( int $id ): bool`**
- Set `status = 'active'`, set `updated_at`

**`static search( string $term, int $limit = 50 ): array`**
- Search across: `phone, phone_alt, name_arabic, name_english, file_number`
- Phone and file_number: use prefix match `LIKE 'term%'` (can use index)
- Name columns: use contains match `LIKE '%term%'` (acceptable at current scale)
- Use `$wpdb->esc_like()` + `$wpdb->prepare()`
- Active records only
- Return array of objects

**`static list( array $args = [] ): array`**
- Args: `status` (active/inactive/all), `per_page` (default 50), `page` (default 1), `orderby` (default `created_at`), `order` (default `DESC`)
- **Whitelist `orderby`** against allowed columns: `[ 'id', 'name_arabic', 'name_english', 'phone', 'customer_type', 'branch', 'file_number', 'created_at' ]` — fall back to `created_at` if invalid
- **Restrict `order`** to `ASC`/`DESC` only — fall back to `DESC` if invalid
- Return `[ 'items' => [], 'total' => int ]`

**`static count( string $status = 'active' ): int`**

All queries use `$wpdb->prepare()`. No raw interpolation anywhere.

---

## Step 2 — Modify `customer-lookup.php` 🔨

**Do NOT use `register_activation_hook`** — this file is loaded via `require_once` from the parent plugin `simpleflow.php`, not activated as a standalone plugin. The activation hook would never fire.

Instead, add a **version-gated table install** in the `plugins_loaded` callback:

```php
add_action( 'plugins_loaded', function () {
    // Version-gated DB table install
    $installed_ver = get_option( 'sfa_cl_db_version', '0' );
    if ( version_compare( $installed_ver, SFA_CL_VER, '<' ) ) {
        \SFA\CustomerLookup\Database\CustomerTable::create_table();
        update_option( 'sfa_cl_db_version', SFA_CL_VER );
    }

    new SFA\CustomerLookup\Ajax\LookupHandler();

    if ( is_admin() ) {
        new SFA\CustomerLookup\Admin\SettingsPage();
        new SFA\CustomerLookup\Admin\CustomersAdmin();
    }
}, 20 );
```

**Do NOT add `require_once` lines** — the existing PSR-4 autoloader resolves all `SFA\CustomerLookup\*` classes automatically.

---

## Step 3 — `Database/CustomerMigrate.php` 🆕

Namespace: `SFA\CustomerLookup\Database`
Class: `CustomerMigrate`

### Method: `static run(): array`

Returns:
```php
[
    'inserted'           => int,
    'skipped_duplicate'  => int,
    'skipped_incomplete' => int,
    'errors'             => [ entry_id => error_message ],
]
```

Sequence:
1. **Guard: restrict to WP-CLI context only** — `if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return []; }` — prevents accidental invocation from web requests
2. Load settings via `CustomerRepository::get_settings()` (make this public)
3. Validate: form_id and field_map['phone'] must be set — abort with error if missing
4. Query all active entry IDs from `gf_entry` WHERE `form_id = X AND status = 'active'` ORDER BY `id ASC` — direct WPDB
   > **Intentional deviation from the "no direct GF queries" rule.** GFAPI::get_entries() loads full entry objects with all meta, filters, and hooks — prohibitively expensive for a bulk migration that may process thousands of entries. Direct WPDB is used here for batch performance. This is a one-time migration script restricted to WP-CLI.
5. Process in batches of 100 entry IDs:
   - **Batch-fetch all meta in one query:** `SELECT entry_id, meta_key, meta_value FROM gf_entry_meta WHERE entry_id IN (%d, %d, ...) AND form_id = %d` — then group by entry_id in PHP. (Not N+1 per-entry queries.)
   - For each entry in the batch:
     - Map meta_key → semantic name using flipped field_map
     - Validate: `phone` and `name_arabic` must be non-empty → `skipped_incomplete`
     - Normalize phone via `CustomerTable::normalize_phone()`
     - Check `CustomerTable::phone_exists()` → `skipped_duplicate`
     - `CustomerTable::insert()` with `source = 'migration'`
     - On insert failure → log to `errors[entry_id]`
6. Return summary

### WP-CLI trigger:

```php
public static function run_cli(): void {
    if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
        echo "This command can only be run via WP-CLI.\n";
        return;
    }
    $result = self::run();
    echo "Inserted: {$result['inserted']}\n";
    echo "Skipped (duplicate): {$result['skipped_duplicate']}\n";
    echo "Skipped (incomplete): {$result['skipped_incomplete']}\n";
    echo "Errors: " . count( $result['errors'] ) . "\n";
    if ( ! empty( $result['errors'] ) ) {
        foreach ( $result['errors'] as $eid => $msg ) {
            echo "  Entry {$eid}: {$msg}\n";
        }
    }
}
```

Run via:
```bash
wp eval 'SFA\CustomerLookup\Database\CustomerMigrate::run_cli();'
```

---

## Step 4 — Modify `Database/CustomerRepository.php` 🔨

### Changes only — do not touch existing methods

**1. Make `get_settings()` public** (needed by CustomerMigrate)

**2. Add `use_sf_table` to `get_settings()`:**
```php
self::$settings = [
    'form_id'       => (int) get_option( 'sfa_cl_source_form_id', 0 ),
    'field_map'     => get_option( 'sfa_cl_field_map', [] ),
    'use_wpdb'      => (bool) get_option( 'sfa_cl_use_direct_db', false ),
    'use_sf_table'  => (bool) get_option( 'sfa_cl_use_sf_table', false ),
];
```

**3. Update `find_by_phone()` query priority:**
```php
if ( $settings['use_sf_table'] ) {
    $result = self::query_sf_table( $phone );
} elseif ( $settings['use_wpdb'] ) {
    $result = self::query_wpdb( $phone, $form_id, $field_map );
} else {
    $result = self::query_gfapi( $phone, $form_id, $field_map );
}
```

**4. Add `query_sf_table()` private method:**

```php
private static function query_sf_table( string $phone ): ?array {
    // Phone is already digits-only from the AJAX handler.
    // Data in wp_sf_customers is normalized on write, so no +prefix handling needed.
    $customer = CustomerTable::get_by_phone( $phone );

    if ( ! $customer ) {
        return null;
    }

    $allowed = [
        'name_arabic', 'name_english', 'phone_alt',
        'email', 'address', 'customer_type', 'branch', 'file_number'
    ];

    $mapped = [];
    foreach ( $allowed as $key ) {
        if ( isset( $customer->$key ) && '' !== $customer->$key ) {
            $mapped[ $key ] = esc_html( $customer->$key );
        }
    }

    return ! empty( $mapped ) ? $mapped : null;
}
```

**Query priority after change:**
```
find_by_phone()
    ↓ transient cache hit → return
    ↓ use_sf_table = true  → query_sf_table()   ← normalized wp_sf_customers lookup
    ↓ use_wpdb = true      → query_wpdb()        ← existing GF meta fallback
    ↓ default              → query_gfapi()       ← existing GFAPI fallback
```

---

## Step 5 — Modify `Admin/SettingsPage.php` 🔨

### Changes only

**1. Add `use_sf_table` to settings load in `render_page()`:**
```php
$use_sf_table = (bool) get_option( 'sfa_cl_use_sf_table', false );
```

**2. Add toggle row in Advanced table (before the existing Direct DB row):**
```php
<tr>
    <th><label for="sfa_cl_use_sf_table"><?php esc_html_e( 'Use SF Customers Table', 'simpleflow' ); ?></label></th>
    <td>
        <label>
            <input type="checkbox" name="sfa_cl_use_sf_table"
                   id="sfa_cl_use_sf_table" value="1" <?php checked( $use_sf_table ); ?>>
            <?php esc_html_e( 'Query wp_sf_customers directly (fastest — enable after migration is complete)', 'simpleflow' ); ?>
        </label>
        <p class="description">
            <?php esc_html_e( 'Bypasses Gravity Forms entirely. Requires migration to have run successfully. Once enabled, new customers must be created via the Customers admin page.', 'simpleflow' ); ?>
        </p>
    </td>
</tr>
```

**3. Save in `save_settings()`:**
```php
update_option( 'sfa_cl_use_sf_table', ! empty( $_POST['sfa_cl_use_sf_table'] ) );
```

---

## Step 6 — `Admin/CustomersListTable.php` 🆕

Namespace: `SFA\CustomerLookup\Admin`
Class: `CustomersListTable extends \WP_List_Table`

Handles list rendering, pagination, sorting, search, and bulk actions.

### Columns
File No. | Name (AR) | Name (EN) | Phone | Type | Branch | Status | Actions

### Features
- Uses `CustomerTable::list()` for data and `CustomerTable::count()` for pagination
- Uses `CustomerTable::search()` when search term is present
- Filter tabs: Active | Inactive | All (with counts from `CustomerTable::count()`)
- Pagination: 50/page (via WP_List_Table built-in)
- Sortable columns: Name (AR), Name (EN), Phone, File No., Created
- Row actions: View | Edit | Deactivate / Reactivate
- All output escaped with `esc_html()` / `esc_attr()`

---

## Step 7 — `Admin/CustomersAdmin.php` 🆕

Namespace: `SFA\CustomerLookup\Admin`
Class: `CustomersAdmin`

Handles menu registration, view routing, form processing, and AJAX.

### Admin menu

Submenu under `simpleflow`:
- Slug: `sfa-customers`
- Label: `العملاء` (Customers)
- Capability: `gform_full_access`

### View routing (dispatched via `?view=` query param)

**Default — list**
- Renders `CustomersListTable`
- "Add Customer" button → `?view=create`

**`?view=create`**
- Form fields: name_arabic (required), name_english, phone (required), phone_alt, email, address, customer_type (radio: individual/company/project), branch, file_number
- On submit: verify nonce → validate required → `sanitize_text_field()` all inputs → duplicate phone check via `CustomerTable::phone_exists()` → `CustomerTable::insert()` → redirect list + success notice
- Duplicate phone → inline error, no submit

**`?view=edit&id=N`**
- Pre-filled form (same fields)
- Verify nonce on save, `absint()` for ID
- Phone: editable, duplicate check excludes current record via `phone_exists($phone, $id)`
- On submit: `CustomerTable::update()` → redirect list + notice

**`?view=profile&id=N`**
- Read-only display of all fields
- Show: source, odoo_id (if set), created_at, updated_at, status
- All values escaped with `esc_html()`
- Buttons: Edit | Deactivate (or Reactivate)

### Deactivate / Reactivate

- Triggered via `?action=deactivate&id=N&_wpnonce=X` or `reactivate`
- **Nonce action includes record ID:** `wp_verify_nonce( $_GET['_wpnonce'], 'sfa_deactivate_' . $id )` / `'sfa_reactivate_' . $id`
- Verify capability (`gform_full_access`) before acting
- Soft delete only — `status = inactive`
- Inactive customers: not returned by `get_by_phone()`, still visible in admin list

### AJAX: duplicate phone check

- Action: `wp_ajax_sfa_cl_check_phone_exists`
- `check_ajax_referer( 'sfa_cl_admin' )` + `current_user_can( 'gform_full_access' )` before any DB query
- Normalize phone, query `CustomerTable::phone_exists($phone, $exclude_id)`
- Return `wp_send_json_success( [ 'exists' => bool ] )`

### Enqueue

Enqueue `sf-customers-admin.css` and `sf-customers-admin.js` only on `admin.php?page=sfa-customers`.

---

## Step 8 — `assets/sf-customers-admin.css` 🆕

Scope all rules under `.sfa-customers-wrap`.

Cover:
- Filter tab bar (active/inactive/all)
- Table styling consistent with WP admin
- Inline error state for duplicate phone
- Status badge: active (green) / inactive (grey)
- Source badge: manual / odoo / migration
- RTL compatible (`margin-inline-start` not `margin-left`)

---

## Step 9 — `assets/sf-customers-admin.js` 🆕

**Duplicate phone check (create + edit forms):**
- On blur of phone field: AJAX POST `action: sfa_cl_check_phone_exists`
- Pass: phone, exclude_id (for edit view)
- Response: `{ exists: bool }`
- Show inline error if exists, disable submit button

---

## Step 10 — Verify `customer-lookup.php` 🔨

Confirm the `plugins_loaded` callback (modified in Step 2) includes all registrations:

```php
add_action( 'plugins_loaded', function () {
    // Version-gated DB table install
    $installed_ver = get_option( 'sfa_cl_db_version', '0' );
    if ( version_compare( $installed_ver, SFA_CL_VER, '<' ) ) {
        \SFA\CustomerLookup\Database\CustomerTable::create_table();
        update_option( 'sfa_cl_db_version', SFA_CL_VER );
    }

    new SFA\CustomerLookup\Ajax\LookupHandler();

    if ( is_admin() ) {
        new SFA\CustomerLookup\Admin\SettingsPage();
        new SFA\CustomerLookup\Admin\CustomersAdmin();
    }
}, 20 );
```

---

## Hook Layer (Odoo sync — Phase 2)

Already stubbed in `CustomerTable`. No work needed now.

Odoo sync (n8n) will hook into these actions:
- `sf_customer_created` — fires on every new manual customer
- `sf_customer_updated` — fires on every edit
- `sf_customer_deactivated` — optional, for sync

---

## Pre-Migration Checklist (manual, before running CLI)

- [ ] Settings page → select customer source form → save
- [ ] Map all field IDs → save
- [ ] Verify settings saved: `wp option get sfa_cl_source_form_id`
- [ ] Verify field map: `wp option get sfa_cl_field_map`
- [ ] Confirm `customer_type` values in GF match allowed values: `individual / company / project`
- [ ] Run migration: `wp eval 'SFA\CustomerLookup\Database\CustomerMigrate::run_cli();'`
- [ ] Verify row count: `wp db query "SELECT COUNT(*) FROM wp_sf_customers;"`
- [ ] Enable SF Table toggle in Settings → Advanced
- [ ] Test lookup on order form with known phone
- [ ] Disable Populate Anything on order forms

---

## Review Findings Applied (v2.0 → v2.1)

| # | Issue | Source | Fix |
|---|-------|--------|-----|
| 1 | `register_activation_hook` never fires for module files | Security, Architect | Version-gated install on `plugins_loaded` |
| 2 | Directory `modules/customers/` doesn't exist | Architect | Corrected to `modules/customer-lookup/` |
| 3 | Phone `+966...` vs `966...` breaks UNIQUE constraint | DB Optimizer | `normalize_phone()` strips to digits-only on all paths |
| 4 | `query_sf_table()` missing +prefix handling | DB Optimizer | Moot — data normalized on write |
| 5 | `require_once` conflicts with PSR-4 autoloader | Architect | Removed — autoloader handles all classes |
| 6 | `dbDelta` misparses ENUM, ignores ON UPDATE | Security, DB Optimizer | VARCHAR + PHP validation; explicit `updated_at` |
| 7 | Migration N+1 meta queries | DB Optimizer | Batch-fetch with `WHERE entry_id IN (...)` |
| 8 | `list()` orderby/order SQL injection | Security | Whitelist columns, restrict ASC/DESC |
| 9 | `search()` LIKE full table scan on 5 columns | DB Optimizer | Prefix match for phone/file_number |
| 10 | Deactivate nonce reusable across records | Security | Nonce action includes record ID |
| 11 | CustomersAdmin overloaded (500+ lines) | Architect | Split: CustomersAdmin + CustomersListTable |
| 12 | `idx_status` low-cardinality waste | DB Optimizer | Composite `idx_status_created` |
| 13 | Migration unrestricted — callable from web | Security | WP-CLI guard |
| 14 | Plan labels customer-lookup.php as "no changes" | Architect | Corrected label |
| 15 | Assets path `src/assets/` inconsistent | Architect | Corrected to `assets/` top-level |

---

## Out of Scope (this build)

- Odoo → `wp_sf_customers` sync (Phase 2, separate plan)
- Bulk CSV import
- Customer merge UI
- Activity log per customer
- Customer → orders relationship view
