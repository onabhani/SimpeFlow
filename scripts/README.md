# SimpleFlow Scripts

Utility scripts for SimpleFlow maintenance and cleanup tasks.

## Production Bookings Cleanup

Two scripts are provided to clean up invalid production bookings (entries with 0 LM):

### Option 1: PHP Script (Recommended)

**File:** `cleanup-invalid-production-bookings.php`

This script safely removes production booking metadata from entries with 0 LM or empty production fields. The entries themselves are NOT deleted.

**Usage via command line:**
```bash
cd /path/to/wp-content/plugins/simpleflow/scripts/
php cleanup-invalid-production-bookings.php
```

**Usage via WP-CLI:**
```bash
wp eval-file scripts/cleanup-invalid-production-bookings.php
```

**What it does:**
1. Finds all entries with `_prod_total_slots = 0` or empty
2. Shows preview of entries to be cleaned (up to 10)
3. Asks for confirmation (yes/no)
4. Deletes production metadata for these entries:
   - `_prod_lm_required`
   - `_prod_total_slots`
   - `_prod_field_breakdown`
   - `_prod_slots_allocation`
   - `_prod_start_date`
   - `_prod_end_date`
   - `_install_date`
   - `_prod_booking_status`
   - `_prod_booked_at`
   - `_prod_booked_by`
   - `_prod_daily_capacity_at_booking`
5. Clears WordPress cache
6. Shows summary of deleted records

**Safety:**
- Interactive confirmation required
- Only deletes metadata, NOT entries
- Shows progress during cleanup
- Displays final summary

### Option 2: SQL Script (For Database Admins)

**File:** `cleanup-invalid-production-bookings.sql`

Direct SQL queries for database administrators who prefer to run cleanup via MySQL/phpMyAdmin.

**Usage:**
1. Open the SQL file and review the queries
2. **IMPORTANT:** Replace `wp_` table prefix with your actual prefix if different
3. **RECOMMENDED:** Create a backup first:
   ```sql
   mysqldump -u username -p database_name wp_gf_entry_meta > backup_gf_entry_meta.sql
   ```
4. Run the preview queries to see what will be affected
5. Uncomment the DELETE query and execute it

**What's included:**
- Preview query (shows affected entries)
- Count query (total rows to delete)
- Main cleanup DELETE query (commented by default)
- Verification query (check after cleanup)
- Alternative query (cleanup specific entry IDs only)

**Safety:**
- All destructive queries are commented by default
- Preview queries help you review before executing
- Backup recommendations provided
- Alternative for selective cleanup included

---

## Which Method to Use?

- **Use PHP script if:** You have SSH/command line access or WP-CLI installed
- **Use SQL script if:** You prefer phpMyAdmin or direct database access
- **Use Settings page if:** You prefer WordPress admin interface (SimpleFlow → Production Settings → Clean Up Invalid Bookings)

All three methods do the same cleanup - choose based on your preference and access level.
