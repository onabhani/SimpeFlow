# SimpleFlow — Customer Module
## Build Plan v2.2 — Final (as built)

---

## Status
- **Module version:** 2.0.1
- **Schema version:** 1.3.0
- **Plugin version:** 0.4.7
- **Migration:** Complete — 4,718 of 4,719 entries migrated (1 skipped: no phone)
- **Build status:** All steps complete and deployed

---

## File Structure (as built)

```text
modules/customer-lookup/
├── customer-lookup.php                  ✅ bootstrap, autoloader, version-gated dbDelta
├── assets/
│   ├── customer-lookup.js               ✅ frontend phone lookup (AJAX, debounce)
│   ├── sf-customers-admin.css           ✅ admin styles, badges, RTL-safe
│   └── sf-customers-admin.js            ✅ duplicate phone check on blur
└── src/
    ├── Ajax/
    │   └── LookupHandler.php            ✅ AJAX phone lookup endpoint
    ├── Admin/
    │   ├── SettingsPage.php             ✅ source form, field mapping, order forms, migration, advanced toggles
    │   ├── CustomersAdmin.php           ✅ menu, CRUD views, profile, orders panel, AJAX phone check
    │   └── CustomersListTable.php       ✅ WP_List_Table with search, filters, pagination, sorting
    └── Database/
        ├── CustomerTable.php            ✅ schema, CRUD, normalize_phone, sanitize_data, search, list
        ├── CustomerRepository.php       ✅ 3-tier lookup (SF table → WPDB → GFAPI), transient cache
        └── CustomerMigrate.php          ✅ GF→SF migration with dry-run, verify, needs_review
```

---

## Table Schema: `wp_sfa_cl_customers`

```sql
CREATE TABLE wp_sfa_cl_customers (
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
    gf_entry_id   BIGINT UNSIGNED DEFAULT NULL,
    source        VARCHAR(20)  NOT NULL DEFAULT 'manual',
    status        VARCHAR(20)  NOT NULL DEFAULT 'active',
    review_note   TEXT         DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY   (id),
    UNIQUE KEY    uq_phone (phone),
    UNIQUE KEY    uq_phone_alt (phone_alt),
    UNIQUE KEY    uq_gf_entry_id (gf_entry_id),
    KEY           idx_status_created (status, created_at),
    KEY           idx_odoo_id (odoo_id),
    KEY           idx_file_number (file_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Design decisions:**
- VARCHAR instead of ENUM — `dbDelta()` misparses ENUM on updates
- No `ON UPDATE CURRENT_TIMESTAMP` — `dbDelta()` ignores it; set explicitly in `update()`
- `gf_entry_id` UNIQUE — enforces 1:1 mapping to original GF entries
- `review_note` — stores conflict details for needs_review records
- `status` VARCHAR(20) — accommodates `active`, `inactive`, `needs_review`
- Composite `idx_status_created` — covers the default list view query

---

## Phone Normalization

All phone values are normalized via `CustomerTable::normalize_phone()` before storage and lookup.

```php
public static function normalize_phone( string $phone ): string {
    $digits = preg_replace( '/[^0-9]/', '', $phone );
    $digits = ltrim( $digits, '0' );

    // Fix Saudi trunk prefix: 9660XXXXXXXX → 966XXXXXXXX
    if ( preg_match( '/^9660(\d{8,})$/', $digits, $m ) ) {
        $digits = '966' . $m[1];
    }

    // Too short to be a real phone number (e.g. just "966")
    if ( strlen( $digits ) < 9 ) {
        return '';
    }

    return $digits;
}
```

**Handles:**
- `+966561133336` → `966561133336`
- `0561133336` → `561133336`
- `+9660561133336` → `966561133336` (Saudi trunk prefix fix)
- `+966 56 1133 336` → `966561133336` (spaces stripped)
- `+966` alone → `''` (too short, treated as empty)

---

## Settings Page

| Setting | Option Key | Purpose |
|---------|-----------|---------|
| Customer Source Form | `sfa_cl_source_form_id` | GF form that stores customer records |
| Field Mapping | `sfa_cl_field_map` | Maps GF field IDs to semantic keys (phone, name_arabic, etc.) |
| Order Forms | `sfa_cl_order_form_ids` | Multi-select of GF forms linked via Parent Entry Connector |
| Legacy Phone Field | `sfa_cl_legacy_phone_field` | Old text field ID for migration fallback (clear after migration) |
| Use SF Customers Table | `sfa_cl_use_sf_table` | Fastest lookup path — enable after migration |
| Use Direct DB Queries | `sfa_cl_use_direct_db` | WPDB fallback when GFAPI is too slow |

---

## Lookup: 3-Tier Query Priority

```text
find_by_phone()
    ↓ transient cache hit → return (5-min TTL)
    ↓ use_sf_table = true  → query_sf_table()   ← sfa_cl_customers (fastest)
    ↓ use_wpdb = true      → query_wpdb()        ← GF entry_meta direct
    ↓ default              → query_gfapi()       ← GFAPI::get_entries
```

- Frontend JS fires immediately on first valid input (9+ digits), debounces subsequent
- Repository returns raw values — escaping done at render time
- Transient cache persists across AJAX requests

---

## Admin UI: Customers Page

**Menu:** SimpleFlow → Customers (capability: `gform_full_access`)

### List View
- WP_List_Table with columns: File No., Name (AR), Name (EN), Phone, Type, Branch, Status, Review Note, Actions
- Filter tabs: Active | Needs Review | Inactive | All (with counts)
- Search box across phone, phone_alt, name_arabic, name_english, file_number
- Phone/file_number use prefix match (index-friendly); names use contains match
- Sortable columns, 50/page pagination
- Row actions: View | Edit | Deactivate/Reactivate

### Create/Edit Views
- Required fields: Name (Arabic), Phone
- Customer type radio: Individual / Company / Project
- AJAX duplicate phone check on blur (disables submit)
- Server-side validation + nonce verification

### Profile View
- Read-only display of all fields including source, GF Entry ID, review note
- Status/source badges with translated labels
- Edit / Deactivate / Reactivate / Back to List buttons
- **Orders panel** (see below)

### Deactivate/Reactivate
- Nonce includes record ID (`sfa_deactivate_{id}`)
- Only reports success when `$result > 0` (actual row change)
- Error notice on failure

---

## Orders Panel (Parent Entry Connector)

The profile page shows orders linked via GravityFlow's Parent Entry Connector.

**Meta key format:** `workflow_parent_form_id_{source_form_id}_entry_id`

**Features:**
- Supports multiple order forms (multi-select in settings)
- Groups orders by form name when multiple forms are configured
- Shows: Entry ID (linked), Date, Created By, Workflow Status, View action
- Uses GFAPI `total_count` to detect truncation beyond 100 entries
- Shows "showing X of Y" when results exceed page size
- Form name sub-headers shown whenever multiple forms are configured

---

## Migration

### Commands

```bash
# Dry run — validate without writing
wp eval 'SFA\CustomerLookup\Database\CustomerMigrate::run_cli("dry-run");'

# Real migration
wp eval 'SFA\CustomerLookup\Database\CustomerMigrate::run_cli();'

# Verify completeness
wp eval 'SFA\CustomerLookup\Database\CustomerMigrate::run_cli("verify");'
```

### Features
- **WP-CLI only** — web requests are rejected
- **Dry-run mode** — validates through `sanitize_data()` for accurate counts
- **Verify mode** — compares GF entry IDs vs SF table, reports missing/orphaned
- **Auto-verify** — runs verification automatically after real migration
- **Idempotent** — checks `gf_entry_id` before phone to prevent re-imports on reruns
- **Batch meta fetch** — 100 entries per batch, single meta query per batch (no N+1)
- **Legacy phone field** — falls back to configurable old field when primary is empty
- **Needs review** — phone duplicates inserted with `status=needs_review` and `review_note` instead of skipping; phone_alt collisions retried with phone_alt=NULL
- **Skipped details** — grouped by reason with sample entry IDs
- **Strict mode validation** — unknown CLI modes rejected with usage help
- **Error detection** — startup/fatal errors printed without success summary
- **Customer type normalization** — case-insensitive mapping of GF values to valid enum

### Migration Results (production)
```text
Total GF entries: 4,719
Inserted: 4,718
Skipped (incomplete): 1 (entry 10418 — no phone)
```

---

## Version Constants

| Constant | Purpose | Current |
|----------|---------|---------|
| `SFA_CL_VER` | Module release version | 2.0.1 |
| `SFA_CL_DB_VER` | Schema version — only incremented for DDL changes | 1.3.0 |
| `SIMPLEFLOW_VER` | Parent plugin version | 0.4.7 |

The `plugins_loaded` callback compares `sfa_cl_db_version` option against `SFA_CL_DB_VER` so only schema changes trigger `dbDelta()`, not every module version bump.

---

## Hook Layer (Odoo sync — Phase 2)

Actions fire on every write — ready for n8n/Odoo integration:
- `sf_customer_created` — fires after insert with `($id, $data)`
- `sf_customer_updated` — fires after update with `($id, $data)`
- `sf_customer_deactivated` — fires after deactivate with `($id)`

---

## Validation Rules

| Field | Insert/Update | Migration |
|-------|--------------|-----------|
| `customer_type` | Fail-fast if not in `VALID_CUSTOMER_TYPES` | Normalized via case-insensitive map, defaults to `individual` |
| `source` | Fail-fast if not in `VALID_SOURCES` | Always set to `migration` |
| `status` | Fail-fast if not in `VALID_STATUSES` | `active` or `needs_review` for conflicts |
| `phone` | Normalized, rejected if empty | Normalized, legacy fallback, needs_review for duplicates |
| `phone_alt` | Normalized, set to NULL if empty/short | Normalized, set to NULL on UNIQUE collision |

---

## Out of Scope (future builds)

- Odoo → `sfa_cl_customers` sync (Phase 2, separate plan)
- Bulk CSV import
- Customer merge UI
- Activity log per customer
