-- =====================================================
-- Production Bookings Cleanup Script (SQL Version)
-- =====================================================
-- This script removes all production booking metadata
-- from entries with 0 LM or empty production fields.
-- The entries themselves are NOT deleted.
-- =====================================================

-- IMPORTANT: Replace 'wp_' with your actual table prefix if different!
-- You can check your prefix by running: SHOW TABLES LIKE '%gf_entry_meta';

-- =====================================================
-- Step 1: Preview - See which entries will be affected
-- =====================================================
SELECT
    entry_id,
    meta_value as current_lm,
    COUNT(*) OVER() as total_entries_to_clean
FROM wp_gf_entry_meta
WHERE meta_key = '_prod_total_slots'
AND (meta_value = '0' OR meta_value IS NULL OR meta_value = '')
ORDER BY entry_id DESC
LIMIT 100;

-- =====================================================
-- Step 2: Count total production metadata to be deleted
-- =====================================================
SELECT
    COUNT(*) as total_rows_to_delete,
    COUNT(DISTINCT entry_id) as total_entries_affected
FROM wp_gf_entry_meta
WHERE entry_id IN (
    SELECT entry_id
    FROM wp_gf_entry_meta
    WHERE meta_key = '_prod_total_slots'
    AND (meta_value = '0' OR meta_value IS NULL OR meta_value = '')
)
AND meta_key IN (
    '_prod_lm_required',
    '_prod_total_slots',
    '_prod_field_breakdown',
    '_prod_slots_allocation',
    '_prod_start_date',
    '_prod_end_date',
    '_install_date',
    '_prod_booking_status',
    '_prod_booked_at',
    '_prod_booked_by',
    '_prod_daily_capacity_at_booking'
);

-- =====================================================
-- Step 3: CLEANUP - Delete production metadata
-- =====================================================
-- WARNING: This will permanently delete production booking data!
-- Make sure you've reviewed the preview above before running this!
-- RECOMMENDED: Create a backup first with:
--   mysqldump -u username -p database_name wp_gf_entry_meta > backup_gf_entry_meta.sql
-- =====================================================

-- Uncomment the lines below to execute the cleanup:

/*
DELETE FROM wp_gf_entry_meta
WHERE entry_id IN (
    SELECT entry_id
    FROM (
        SELECT entry_id
        FROM wp_gf_entry_meta
        WHERE meta_key = '_prod_total_slots'
        AND (meta_value = '0' OR meta_value IS NULL OR meta_value = '')
    ) AS invalid_entries
)
AND meta_key IN (
    '_prod_lm_required',
    '_prod_total_slots',
    '_prod_field_breakdown',
    '_prod_slots_allocation',
    '_prod_start_date',
    '_prod_end_date',
    '_install_date',
    '_prod_booking_status',
    '_prod_booked_at',
    '_prod_booked_by',
    '_prod_daily_capacity_at_booking'
);
*/

-- =====================================================
-- Step 4: Verify cleanup (run after deletion)
-- =====================================================
-- This should return 0 rows after successful cleanup:
/*
SELECT
    entry_id,
    meta_value as current_lm
FROM wp_gf_entry_meta
WHERE meta_key = '_prod_total_slots'
AND (meta_value = '0' OR meta_value IS NULL OR meta_value = '');
*/

-- =====================================================
-- Alternative: Delete for specific entry IDs only
-- =====================================================
-- If you want to clean only specific entries, use this:
/*
DELETE FROM wp_gf_entry_meta
WHERE entry_id IN (40467, 40468, 40471, 40475, 40477) -- Replace with your entry IDs
AND meta_key IN (
    '_prod_lm_required',
    '_prod_total_slots',
    '_prod_field_breakdown',
    '_prod_slots_allocation',
    '_prod_start_date',
    '_prod_end_date',
    '_install_date',
    '_prod_booking_status',
    '_prod_booked_at',
    '_prod_booked_by',
    '_prod_daily_capacity_at_booking'
);
*/
