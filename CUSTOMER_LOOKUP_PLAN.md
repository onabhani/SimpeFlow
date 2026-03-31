# SimpleFlow — Customer Lookup Module
## Build Plan for Claude CLI

---

## Context

Replace Gravity Forms "Populate Anything" on DOFS order forms with a purpose-built AJAX lookup.
Populate Anything is slow because it runs PHP-side DB queries on every field interaction.
This module does one fast indexed WPDB query, returns only whitelisted customer fields as JSON, and JS populates the order form client-side.

Must handle 10,000+ GF entries with no performance degradation.

---

## Constraints

- Lives inside the `simpleflow` plugin under `modules/customer-lookup/`
- Follows existing SimpleFlow module conventions: PSR-4 autoloading, `SFA_` constants, `sfa_cl_*` options, auto-discovered by core loader
- English code, Arabic UI strings only
- Works across ALL order forms without per-form code changes (CSS class convention)
- No new plugin dependencies
- Must not break any existing SimpleFlow modules
- All WPDB queries MUST use `$wpdb->prepare()` — non-negotiable
- All SQL comparisons use exact match (`=`), never `LIKE` or `REGEXP`

---

## Architecture Decision: GFAPI vs Direct WPDB

The project CLAUDE.md prohibits direct queries to `wp_gf_entry_meta`. Before implementing direct WPDB:

1. **First benchmark `GFAPI::get_entries()`** with `search_criteria` field filters + `paging['page_size'] = 1` against 10K+ entries
2. **If GFAPI meets the <50ms target** — use GFAPI exclusively
3. **If GFAPI is too slow** — use direct WPDB isolated in a Repository class, with:
   - A code comment documenting the benchmark results that justify the exception
   - A GF version compatibility check: `version_compare( GFCommon::$version, '2.5', '>=' )`
   - All raw SQL confined to `src/Database/CustomerRepository.php` — never in the AJAX handler

This plan documents both paths. The Repository class abstracts the implementation so switching between GFAPI and WPDB requires changing one file.

---

## File Structure

```
modules/
└── customer-lookup/
    ├── customer-lookup.php          # Entry point, constants, autoloader
    ├── src/
    │   ├── Admin/
    │   │   └── SettingsPage.php     # Form ID + field map config UI
    │   ├── Ajax/
    │   │   └── LookupHandler.php    # Nonce, auth, sanitization, response
    │   └── Database/
    │       └── CustomerRepository.php  # All DB queries isolated here
    └── assets/
        └── customer-lookup.js
```

No modifications to `simpleflow.php` — the core loader auto-discovers `modules/customer-lookup/customer-lookup.php` automatically.

---

## File 1: `customer-lookup.php` (Entry Point)

### Constants

```php
if ( ! defined( 'SFA_CL_VER' ) ) {
    define( 'SFA_CL_VER', '1.0.0' );
}
if ( ! defined( 'SFA_CL_DIR' ) ) {
    define( 'SFA_CL_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'SFA_CL_URL' ) ) {
    define( 'SFA_CL_URL', plugin_dir_url( __FILE__ ) );
}
```

### Autoloader

```php
spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'SFA\\CustomerLookup\\' ) !== 0 ) {
        return;
    }
    $relative_class = str_replace( 'SFA\\CustomerLookup\\', '', $class );
    $file = SFA_CL_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );
```

### Initialization

```php
add_action( 'plugins_loaded', function () {
    new SFA\CustomerLookup\Ajax\LookupHandler();

    if ( is_admin() ) {
        new SFA\CustomerLookup\Admin\SettingsPage();
    }
}, 20 );
```

---

## File 2: `src/Admin/SettingsPage.php`

### Class: `SFA\CustomerLookup\Admin\SettingsPage`

Registers a submenu page under SimpleFlow (`add_submenu_page( 'simpleflow', ... )`).

#### Settings stored as WordPress options:

| Option Key | Type | Description |
|-----------|------|-------------|
| `sfa_cl_source_form_id` | int | GF form ID containing customer records |
| `sfa_cl_field_map` | array | Associative array mapping semantic names to GF field IDs |

#### Admin UI provides:

- Dropdown to select the source form (populated via `GFAPI::get_forms()`)
- Dynamic field map builder: for each semantic key, a dropdown of fields from the selected form
- Save handler with `admin_post_sfa_cl_save_settings` + nonce verification
- Validation that form ID exists and field IDs belong to the selected form

#### Default field map structure:

```php
[
    'phone'         => '',   // Primary phone — triggers lookup
    'phone_alt'     => '',   // Alternate phone — fallback lookup
    'name_arabic'   => '',
    'name_english'  => '',
    'email'         => '',
    'address'       => '',
    'customer_type' => '',
    'branch'        => '',
    'file_number'   => '',
]
```

> Field IDs are configured through the admin UI, not hardcoded.

---

## File 3: `src/Ajax/LookupHandler.php`

### Class: `SFA\CustomerLookup\Ajax\LookupHandler`

#### Constructor

- Register `wp_ajax_sf_customer_lookup` → `$this->handle()`
- Do NOT register `wp_ajax_nopriv_*` — unauthenticated requests get WordPress default `0` response
- Register `wp_enqueue_scripts` and `admin_enqueue_scripts` → `$this->enqueue()`

#### Method: `enqueue()`

- Only enqueue when a Gravity Forms page/shortcode is detected (check `gform_is_form_rendered` or similar)
- Enqueue `customer-lookup.js` from `SFA_CL_URL . 'assets/customer-lookup.js'`
- Localize script with:
  - `ajax_url` → `admin_url( 'admin-ajax.php' )`
  - `nonce` → `wp_create_nonce( 'sfa_cl_lookup' )`
  - `field_map_keys` → array keys from saved field map (so JS knows which `sf-field-*` classes to target)

#### Method: `handle()`

Sequence:

1. **Verify nonce** (`sfa_cl_lookup`) — `wp_send_json_error( 'Forbidden', 403 )` on fail
2. **Check capability** — `current_user_can( 'gform_full_access' )` — `wp_send_json_error( 'Forbidden', 403 )` on fail
3. **Sanitize phone:**
   ```php
   $raw   = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
   $phone = preg_replace( '/[^0-9+]/', '', $raw );
   // Normalize: only leading + allowed, rest must be digits
   $phone = preg_replace( '/(?!^)\+/', '', $phone );
   $digits_only = ltrim( $phone, '+' );
   ```
4. **Validate:** `preg_match( '/^\d{9,15}$/', $digits_only )` — return generic error if invalid
5. **Rate limit:** transient `sfa_cl_rl_{user_id}`, max 10/min — return 429 if exceeded
   - Document: this is best-effort rate limiting, not a hard security boundary
   - Transient race conditions accepted at this limit threshold
6. **Query:** `CustomerRepository::find_by_phone( $phone )`
7. **Audit log:** `do_action( 'sfa_cl_lookup_performed', get_current_user_id(), $phone, $found )`
8. **Response (always same shape):**
   - Found: `wp_send_json_success( [ 'found' => true, 'fields' => $result ] )`
   - Not found: `wp_send_json_success( [ 'found' => false ] )`
   - Error: `wp_send_json_success( [ 'found' => false ] )` — same shape, never leak internal errors

> All failure modes return the same response shape to prevent phone enumeration.

---

## File 4: `src/Database/CustomerRepository.php`

### Class: `SFA\CustomerLookup\Database\CustomerRepository`

All database access for this module is isolated in this single class. If benchmarking shows GFAPI is sufficient, this class wraps GFAPI calls instead of raw WPDB.

#### Method: `find_by_phone( string $phone ): ?array`

Loads settings from options:

```php
$form_id   = (int) get_option( 'sfa_cl_source_form_id', 0 );
$field_map = get_option( 'sfa_cl_field_map', [] );
```

##### Object Cache Layer (check cache first):

```php
$cache_key = 'sfa_cl_' . md5( $phone );
$cached    = wp_cache_get( $cache_key, 'sfa_customer_lookup' );
if ( false !== $cached ) {
    return $cached;
}
```

##### Path A — GFAPI (preferred, use if benchmark passes):

```php
$results = GFAPI::get_entries( $form_id, [
    'status'        => 'active',
    'field_filters' => [
        'mode' => 'any',
        [
            'key'   => $field_map['phone'],
            'value' => $phone,
        ],
        [
            'key'   => $field_map['phone_alt'],
            'value' => $phone,
        ],
    ],
], null, [ 'offset' => 0, 'page_size' => 1 ] );
```

Then extract only FIELD_MAP keys from the entry.

##### Path B — Direct WPDB (only if GFAPI benchmark fails):

**Query 1 — find entry_id by phone (both fields in one query):**

```php
$entry_id = $wpdb->get_var( $wpdb->prepare(
    "SELECT em.entry_id
     FROM {$wpdb->prefix}gf_entry_meta em
     INNER JOIN {$wpdb->prefix}gf_entry e ON e.id = em.entry_id
     WHERE em.form_id = %d
       AND em.meta_key IN (%s, %s)
       AND em.meta_value = %s
       AND e.status = 'active'
     ORDER BY em.entry_id DESC
     LIMIT 1",
    $form_id,
    $field_map['phone'],
    $field_map['phone_alt'],
    $phone
) );
```

> Note: Uses `IN (%s, %s)` to search both phone fields in one query (2 queries total instead of 3).
> Uses `ORDER BY entry_id DESC` to return most recent match.
> If primary-phone priority is required later, split into two sequential queries.

If no result, return `null`.

**Query 2 — fetch only whitelisted fields for entry_id:**

```php
$field_ids    = array_values( array_filter( $field_map ) );
$placeholders = implode( ', ', array_fill( 0, count( $field_ids ), '%s' ) );

$results = $wpdb->get_results( $wpdb->prepare(
    "SELECT meta_key, meta_value
     FROM {$wpdb->prefix}gf_entry_meta
     WHERE entry_id = %d
       AND form_id = %d
       AND meta_key IN ({$placeholders})",
    array_merge( [ $entry_id, $form_id ], $field_ids )
), ARRAY_A );
```

> Query-level filtering: only requested `meta_key` values are fetched. No fetch-all-then-filter.

Map results using flipped field map (`field_id → semantic_name`).
Return flat associative array keyed by semantic name.

##### Cache the result (both hits and misses):

```php
$result = $found ? $mapped_fields : null;
wp_cache_set( $cache_key, $result ?? [ '__null' => true ], 'sfa_customer_lookup', 300 );
return $result;
```

> Cache null results using a sentinel value to prevent cache stampede on invalid phone numbers.

---

## File 5: `assets/customer-lookup.js`

### Behavior

**Trigger detection:**
- On `input` event on any field with class `sf-customer-phone`
- Debounce: 600ms
- Strip non-numeric before length check: `value.replace(/[^0-9]/g, '')`
- Fire only when stripped digit count >= 9
- Cancel any in-flight AJAX request before sending a new one (`xhr.abort()`)

**AJAX call:**

```js
$.post(sfClLookup.ajax_url, {
    action:   'sf_customer_lookup',
    _wpnonce: sfClLookup.nonce,
    phone:    strippedPhone
})
```

**On success (`found: true`):**
- For each key in `fields`, find element with class `sf-field-{key}` (underscores become dashes)
- Set `.val(value).trigger('change')` — trigger change so GF conditional logic still fires
- Add class `sf-lookup-populated` to populated fields (for visual indicator)

**On success (`found: false`):**
- Clear all `sf-field-*` elements that have class `sf-lookup-populated`
- Remove `sf-lookup-populated` class
- Show inline message next to phone field (configurable text, default: `رقم غير مسجل`)
- Remove message after 3 seconds

**On error / network failure:**
- Fail silently — do not block form submission
- `console.warn('SF Customer Lookup failed:', error)` only

**CSS class convention — order form fields:**

| CSS Class | Receives |
|---|---|
| `sf-customer-phone` | triggers lookup (phone input) |
| `sf-field-name-arabic` | name_arabic |
| `sf-field-name-english` | name_english |
| `sf-field-phone-alt` | phone_alt |
| `sf-field-email` | email |
| `sf-field-address` | address |
| `sf-field-customer-type` | customer_type |
| `sf-field-branch` | branch |
| `sf-field-file-number` | file_number |

These CSS classes are set once per field in GF field settings > "CSS Class Name".
No JS changes needed when adding a new order form — just add the CSS classes.

---

## Database — Index Verification

Only needed if using Path B (direct WPDB). Before go-live, check for existing indexes:

```sql
SHOW INDEX FROM wp_gf_entry_meta WHERE Key_name = 'form_id_meta_key';
```

If the composite index covering `meta_value` is missing, add:

```sql
ALTER TABLE wp_gf_entry_meta
ADD INDEX idx_sfa_cl_lookup (form_id, meta_key, meta_value(20));
```

Notes:
- Column order `(form_id, meta_key, meta_value(20))` is correct: equality-equality-equality for B-tree
- Prefix length 20 is sufficient for phone numbers (max 15 digits + leading `+`)
- This index is a superset of the existing `form_id_meta_key` — both can coexist safely
- Do NOT drop the existing GF index — GF manages its own schema
- Storage overhead: ~150-250MB at 5M rows, acceptable
- Without this index, queries at 10K+ entries will be full table scans

---

## Security Checklist

| Layer | Implementation |
|---|---|
| Nonce | `sfa_cl_lookup`, verified on every request |
| Capability | `gform_full_access` required |
| Unauthenticated | No `nopriv` handler registered — default WP `0` response |
| Input | `sanitize_text_field()` + strip to digits/`+` only + normalize leading `+` + digit count validated (9-15 digits, not string length) |
| SQL injection | All queries use `$wpdb->prepare()` with typed placeholders — non-negotiable |
| SQL operators | Exact match (`=`) only, never `LIKE` or `REGEXP` |
| Rate limit | 10 req/min per user via transient (best-effort, documented limitation) |
| Response shape | Identical for "not found" and "error" — prevents phone enumeration |
| Data filtering | Query-level `WHERE meta_key IN (...)` — only FIELD_MAP keys fetched from DB |
| No entry_id leak | Response contains semantic keys only, no raw GF field IDs or entry IDs |
| Audit trail | `do_action( 'sfa_cl_lookup_performed' )` fires on every lookup with user ID, phone, and result |
| GF compat | Version check before direct WPDB queries (if Path B is used) |
| Script loading | Only enqueued on pages with GF forms, not globally |

---

## Performance Targets

| Scenario | Target |
|---|---|
| 10K entries, index present | < 50ms query time (expected: 2-10ms) |
| 50K entries, index present | < 100ms query time (expected: 5-15ms) |
| Repeated lookup (same phone) | 0ms DB — served from object cache |
| Keystrokes before trigger | Blocked by 600ms debounce + 9-digit minimum |
| In-flight requests | Previous AJAX aborted before new one fires |

---

## One-Time Setup After Build

1. Go to SimpleFlow > Customer Lookup settings page
2. Select the customer source form from the dropdown
3. Map each semantic field to the correct GF field using the dropdowns
4. Save settings
5. Open each order form > add CSS classes to phone field + all target fields
6. If using Path B: verify DB index exists
7. Disable Populate Anything rules on order forms
8. Test: enter known phone > confirm all fields populate
9. Test: enter unknown phone > confirm inline message appears
10. Test: enter invalid input (letters, short numbers) > confirm no AJAX fires

---

## Out of Scope (this build)

- Odoo > DOFS sync (separate plan)
- Customer creation from order form
- Edit/update existing customer via lookup
- Redis/object cache persistence (basic `wp_cache` included; persistent cache depends on hosting setup)
- WAF-level rate limiting (recommended for production but outside plugin scope)
