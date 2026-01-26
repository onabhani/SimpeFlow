# Production Scheduling - Security & Edge Case Analysis

**Date:** 2026-01-25
**Status:** ⚠️ CRITICAL VULNERABILITIES FOUND
**Priority:** IMMEDIATE ATTENTION REQUIRED

---

## 🔴 CRITICAL VULNERABILITIES

### 1. Race Condition in Concurrent Bookings (CRITICAL - P0)

**Severity:** CRITICAL
**Impact:** Can cause overbooking under normal load
**Likelihood:** HIGH (happens with 2+ concurrent users)

**Description:**
The booking process has a classic Time-of-Check to Time-of-Use (TOCTOU) race condition:

```
Time 0: User A reads existing bookings → sees 0/19 LM used on March 1
Time 1: User B reads existing bookings → sees 0/19 LM used on March 1
Time 2: User A saves 19 LM booking for March 1
Time 3: User B saves 19 LM booking for March 1
Result: March 1 now has 38/19 LM (200% capacity!)
```

**Code Flow:**
1. `BookingHandler.php` line 241: `calculate_schedule()` reads DB
2. Lines 270-275: Read existing entry meta
3. Lines 366-405: Save new booking meta

**No database locking between read and write operations.**

**Attack Vector:**
- Natural occurrence with 2+ users submitting forms simultaneously
- Can be exploited by submitting same form multiple times rapidly
- More likely during peak hours or promotional campaigns

**Fix Required:**
```php
// Option 1: Database row locking
$wpdb->query("SELECT * FROM {$wpdb->prefix}gf_entry_meta
              WHERE meta_key = '_prod_slots_allocation'
              AND meta_value LIKE '%2026-03%'
              FOR UPDATE");

// Option 2: Optimistic locking with version numbers
// Option 3: Atomic increment/decrement using database transactions
// Option 4: Redis/Memcached distributed lock
```

---

### 2. Validation Bypass in Admin (CRITICAL - P0)

**Severity:** CRITICAL
**Impact:** Admin users can create unlimited bookings bypassing capacity
**Likelihood:** MEDIUM (requires admin access, but could be intentional or accidental)

**Description:**
Capacity validation is completely skipped for admin users.

**Code Location:** `ValidationHandler.php` lines 26-30

```php
if ( is_admin() ) {
    return $validation_result; // NO VALIDATION!
}
```

**Attack Scenarios:**
1. **Admin Manual Entry Creation:**
   - Admin creates entry manually in WordPress admin
   - Capacity validation: SKIPPED
   - Result: Can book 1000 LM on a 19 LM capacity day

2. **Admin Entry Editing:**
   - Admin edits existing entry via WordPress admin
   - Increases LM from 5 to 500
   - Capacity validation: SKIPPED
   - Result: Immediate overbooking

3. **Workflow Admin Actions:**
   - Admin manually advances workflow step
   - Capacity validation: SKIPPED (lines 34-46)

4. **Entry Import:**
   - Import CSV with 100 entries all scheduled for same day
   - Validation: SKIPPED for admin imports

**Impact:**
- Intentional: Admin deliberately schedules during blocked periods
- Accidental: Admin doesn't realize they're overbooking
- Data migration: Bulk imports bypass all validation

**Fix Required:**
Remove admin bypass OR add separate admin-only capacity warning (non-blocking).

---

### 3. No Database Transaction Safety (HIGH - P1)

**Severity:** HIGH
**Impact:** Partial booking saves on failure, data inconsistency
**Likelihood:** LOW (requires DB failure or PHP timeout)

**Description:**
Booking saves use multiple individual `gform_update_meta()` calls without database transaction wrapper.

**Code Location:** `BookingHandler.php` lines 366-412

```php
gform_update_meta( $entry_id, '_prod_slots_allocation', $allocation_to_save );
gform_update_meta( $entry_id, '_prod_start_date', $prod_start_date );
gform_update_meta( $entry_id, '_prod_end_date', $prod_end_date );
gform_update_meta( $entry_id, '_install_date', $installation_date );
// ... 7 more meta updates
```

**Failure Scenario:**
1. First 3 meta updates succeed
2. Database connection dies / PHP times out
3. Last 4 meta updates fail
4. Result: Entry has partial booking data (corrupted state)

**Example Corrupted State:**
- Has `_prod_slots_allocation` (consumes capacity)
- Missing `_prod_booking_status`
- Calendar shows booking but status is unknown
- Capacity calculation includes it but admin can't see it properly

**Fix Required:**
```php
global $wpdb;
$wpdb->query('START TRANSACTION');

try {
    gform_update_meta(...);
    gform_update_meta(...);
    // All updates
    $wpdb->query('COMMIT');
} catch (Exception $e) {
    $wpdb->query('ROLLBACK');
    throw $e;
}
```

---

## 🟡 HIGH SEVERITY ISSUES

### 4. Stale AJAX Preview Data (HIGH - P1)

**Severity:** HIGH
**Impact:** User sees available capacity, but it's gone when they submit
**Likelihood:** MEDIUM (happens with concurrent users)

**Description:**
The real-time preview uses data that can become stale before form submission.

**Timeline:**
```
09:00:00 - User A loads form, sees March 1 has 10/19 LM available
09:00:05 - User B submits form, books remaining 9 LM on March 1
09:00:10 - User A clicks submit (still thinks 10 LM available)
09:00:11 - Validation runs, recalculates, finds capacity full
09:00:12 - Form submission fails with error
```

**Impact:**
- Poor user experience (false hope)
- User fills entire form, then gets rejected
- Validation runs but may fail after minutes of work

**Current Mitigation:**
Validation DOES recalculate at submission time, so this prevents overbooking.

**Fix Recommended:**
Add optimistic locking warning: "Capacity may change before submission"

---

### 5. Manual Admin Date Changes Can Still Cause Overbooking (HIGH - P1)

**Severity:** HIGH
**Impact:** Admin rescheduling can create overbooking
**Likelihood:** MEDIUM (admin manual operations)

**Description:**
When admin manually changes dates:

1. Old allocation is NOT freed immediately
2. New allocation is calculated
3. If new dates also exist in old allocation, duplication occurs

**Code Location:** `BookingHandler.php` lines 290-360

**Scenario:**
```
Original booking: March 1-5 (5 days, 50 LM total)
Admin changes install date to March 3
New calculation: March 1-3 (3 days, 50 LM total)

Old cache cleared: March 1-5
New saved: March 1-3

Result on March 1-3: OLD booking + NEW booking = DOUBLE capacity used!
```

**Current Code:**
```php
// Clear cache for old allocation dates (only if recalculating)
if ( ! $use_existing_allocation ) {
    $old_allocation_json = gform_get_meta( $entry_id, '_prod_slots_allocation' );
    if ( $old_allocation_json ) {
        // Only clears CACHE, doesn't subtract old capacity from calculations!
        wp_cache_delete( 'sfa_prod_availability_' . $year_month );
    }
}
```

**Fix Required:**
Before saving new allocation, the scheduler needs to EXCLUDE the old allocation from its capacity calculations.

---

### 6. Entry Deletion Doesn't Validate Workflow State (MEDIUM - P2)

**Severity:** MEDIUM
**Impact:** Deleted entries free capacity even if they shouldn't
**Likelihood:** LOW (admin deletion)

**Description:**
When entry is deleted via WordPress admin, capacity is freed immediately without checking if workflow allows it.

**Code Location:** `BookingHandler.php` lines 590-627

```php
public function handle_entry_deletion( $entry_id ) {
    // No workflow state check!
    // Immediately frees capacity
    gform_delete_meta( $entry_id, '_prod_slots_allocation' );
}
```

**Scenario:**
1. Order in "Production In Progress" workflow step
2. Admin accidentally deletes entry
3. Capacity freed immediately
4. New order books those slots
5. Original order is actually still in production!

**Fix Recommended:**
Add workflow state check before freeing capacity.

---

## 🟢 MEDIUM SEVERITY ISSUES

### 7. No Audit Trail for Capacity Changes (MEDIUM - P2)

**Severity:** MEDIUM
**Impact:** Can't track who overboked or why
**Likelihood:** N/A (audit/compliance issue)

**Description:**
No logging of:
- Who changed capacity settings
- When overbooking occurred
- Why validation was bypassed

**Fix Recommended:**
Add audit log table for capacity changes.

---

### 8. Bulk Entry Operations Not Handled (MEDIUM - P2)

**Severity:** MEDIUM
**Impact:** Bulk delete/trash operations don't free capacity properly
**Likelihood:** LOW (bulk admin operations)

**Description:**
GravityForms bulk operations might not trigger `gform_delete_entry` hook consistently.

**Fix Required:**
Test bulk operations and add hooks if needed.

---

### 9. Entry Restore from Trash Doesn't Restore Booking (LOW - P3)

**Severity:** LOW
**Impact:** Restored entries lose their production schedule
**Likelihood:** LOW (trash restore)

**Description:**
When entry is trashed, booking meta is deleted. When restored, it's not recreated.

**Fix Recommended:**
Mark bookings as "trashed" instead of deleting them.

---

## 🔵 EDGE CASES TO CONSIDER

### 10. Time Zone Handling (LOW - P3)

**Current Implementation:**
- Uses `date('Y-m-d')` for "today"
- May have issues with server timezone vs user timezone
- Midnight bookings could behave unexpectedly

**Recommendation:**
Use WordPress timezone functions: `current_time('Y-m-d')`

---

### 11. Daylight Saving Time Transitions (LOW - P3)

**Potential Issue:**
Date calculations during DST transitions might skip or duplicate days.

**Current Mitigation:**
Using date strings (YYYY-MM-DD) instead of timestamps should prevent this.

---

### 12. Leap Year Edge Cases (LOW - P3)

**Potential Issue:**
February 29 handling in non-leap years.

**Current Status:**
DateTime class handles this correctly, no action needed.

---

### 13. Maximum Entry ID Overflow (LOW - P3)

**Potential Issue:**
Entry IDs are INT. At 2.1 billion entries, overflow could occur.

**Likelihood:**
Effectively zero for this use case.

---

## 📊 RISK MATRIX

| Issue | Severity | Likelihood | Priority | Fix Complexity |
|-------|----------|------------|----------|----------------|
| Race Condition | CRITICAL | HIGH | P0 | HIGH |
| Validation Bypass (Admin) | CRITICAL | MEDIUM | P0 | LOW |
| No Transactions | HIGH | LOW | P1 | MEDIUM |
| Stale Preview Data | HIGH | MEDIUM | P1 | LOW |
| Admin Date Change Overlap | HIGH | MEDIUM | P1 | MEDIUM |
| Deletion Without Workflow Check | MEDIUM | LOW | P2 | LOW |
| No Audit Trail | MEDIUM | N/A | P2 | MEDIUM |
| Bulk Operations | MEDIUM | LOW | P2 | LOW |
| Entry Restore | LOW | LOW | P3 | LOW |

---

## 🛠️ RECOMMENDED IMMEDIATE ACTIONS

### Priority 0 (Critical - Fix Immediately)

1. **Add Database Locking for Booking Queries**
   - Implement row-level locking during capacity check
   - Use SELECT FOR UPDATE or Redis distributed lock
   - Prevents race conditions

2. **Remove Admin Validation Bypass OR Add Warnings**
   - Option A: Run validation for admin too
   - Option B: Show non-blocking warning about overbooking
   - Option C: Add permission check: only superadmin can override

### Priority 1 (High - Fix This Week)

3. **Wrap Meta Updates in Database Transaction**
   - Ensures atomic saves
   - Prevents partial booking corruption

4. **Fix Admin Date Change Logic**
   - Properly exclude old allocation when recalculating
   - Prevent overlapping bookings

5. **Add "Optimistic Lock" Warning to Preview**
   - Show message: "Availability shown is not reserved. Capacity may change before submission."

### Priority 2 (Medium - Fix This Month)

6. **Add Workflow State Check to Deletion**
7. **Add Audit Logging**
8. **Test and Fix Bulk Operations**

### Priority 3 (Low - Fix When Convenient)

9. **Handle Entry Restore**
10. **Standardize Timezone Handling**

---

## 🧪 TESTING RECOMMENDATIONS

### Concurrent Booking Test
```bash
# Simulate race condition
for i in {1..10}; do
  curl -X POST "https://site.com/form" \
    --data "lm=19&install_date=2026-03-01" &
done
wait

# Check if capacity exceeded
```

### Admin Bypass Test
1. Login as admin
2. Manually create entry with LM=1000
3. Set date to already-full day
4. Save entry
5. Check if validation ran (it shouldn't)

### Transaction Rollback Test
1. Add intentional error after 5th meta update
2. Submit form
3. Check if ANY meta was saved (should be none if transactions work)

---

## 📝 ADDITIONAL NOTES

### Why These Bugs Existed

1. **Race Condition:** Common in systems without distributed locking
2. **Admin Bypass:** Intentional for flexibility, but dangerous
3. **No Transactions:** Gravity Forms doesn't provide transaction wrapper
4. **Manual Edits:** Edge case not considered in original design

### Production Hotfix Recommendations

If deploying fixes to production immediately:

1. **Temporary Workaround for Race Condition:**
   - Add random 0-500ms delay in booking process
   - This reduces (but doesn't eliminate) collision probability

2. **Admin Bypass Warning:**
   - Add JavaScript alert: "WARNING: You are admin. Capacity validation is disabled!"

3. **Monitor for Overbooking:**
   - Daily cron job to scan for capacity > 100%
   - Email alert to admin

---

## ✅ CONCLUSION

**Summary:**
- 2 CRITICAL vulnerabilities that can cause overbooking RIGHT NOW
- 5 HIGH/MEDIUM issues that could cause problems under specific conditions
- 4 LOW severity edge cases

**Immediate Risk:**
The race condition vulnerability means that normal concurrent usage WILL cause overbooking. This is not a theoretical risk - it's happening in production (the 119/19 case may have been caused by this).

**Recommended Action:**
1. Fix race condition immediately (database locking)
2. Fix admin bypass immediately (add validation or warnings)
3. Monitor capacity daily until fixes deployed
4. Schedule fixes for P1 issues this week

---

**Document Version:** 1.0
**Last Updated:** 2026-01-25
**Reviewed By:** Claude (AI Security Analysis)
**Next Review:** After fixes implemented
