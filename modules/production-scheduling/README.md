# Production Scheduling Module

**Version:** 1.1.0 (Phase 2 Complete - Fully Functional!)
**Status:** ✅ Ready for Production Use

---

## 🎉 **WHAT'S WORKING NOW**

### ✅ Phase 1: Foundation (COMPLETED)
- Core scheduling engine (pure PHP, testable)
- Database layer with capacity overrides
- Global settings page
- Entry meta structure defined

### ✅ Phase 2: Integration (COMPLETED)
- ✅ **Form Field Configuration** - Map LM and installation date fields per form
- ✅ **Real-time Preview** - AJAX schedule calculation as sales fills form
- ✅ **Auto-fill Installation Date** - Minimum date enforced
- ✅ **Form Validation** - Prevents invalid dates and overbooking
- ✅ **Automatic Booking** - Saves production schedule on form submission
- ✅ **Calendar View** - Visual month grid showing capacity
- ✅ **Bookings List** - Table of all production bookings

---

## 🚀 **HOW TO USE**

### **Step 1: Configure Global Settings**
1. Go to **Settings → Production Scheduling**
2. Set daily production capacity (e.g., 10 LM/day)
3. Select working days (uncheck off days like Friday)
4. Set installation buffer (0 = same day, 1 = next day, etc.)
5. Set "Earliest Production Start Date" to your backlog offset (e.g., Feb 5, 2026 if you have 45 days of existing orders)
6. Add holidays (one per line: YYYY-MM-DD format)

### **Step 2: Configure Your Form**
1. Edit your Gravity Form
2. Go to **Form Settings**
3. Scroll to **"Production Scheduling"** section
4. Check "Enable Production Scheduling"
5. Select your LM field (must be a Number field)
6. Select your Installation Date field (must be a Date field)
7. Save form settings

### **Step 3: Sales Experience**
When sales fills out the form:
1. They enter Linear Meters required
2. System auto-calculates and displays:
   - Production start date
   - Production end date
   - Total production days
   - Earliest installation date
3. Installation date field auto-fills with minimum date
4. Sales can choose a later installation date if customer wants
5. Form validates dates before submission

### **Step 4: View Schedule**
1. Go to **Production Schedule** in admin menu (calendar icon)
2. See monthly calendar with:
   - Green = Available (<70% capacity)
   - Yellow = Filling up (70-99%)
   - Red = Full (100%)
   - Gray = Off day
3. Navigate months with ◀ ▶ buttons
4. See bookings list below calendar

---

## 📊 **ENTRY META SAVED**

After successful form submission, these are saved:

```php
_prod_lm_required          // 23 (Total LM ordered)
_prod_slots_allocation     // {"2026-01-01": 2, "2026-01-02": 8, ...} (JSON)
_prod_start_date           // "2026-01-01" (First production day)
_prod_end_date             // "2026-01-04" (Last production day)
_install_date              // "2026-01-04" (Installation date chosen by sales)
_prod_booking_status       // "confirmed"
_prod_booked_at            // "2025-12-21 14:30:00"
_prod_booked_by            // 5 (User ID who submitted)
```

---

## 🔧 **SETTINGS**

### **Global Settings** (Settings → Production Scheduling)
- Daily Production Capacity: 10 LM/day (default)
- Working Days: Checkboxes for each weekday
- Installation Buffer: Days after production (0-7+)
- Earliest Production Start Date: Backlog offset
- Holidays: List of blocked dates

### **Form Settings** (Per-form configuration)
- Enable/disable production scheduling
- Map LM field (Number field)
- Map Installation Date field (Date field)

---

## 📁 **FILES**

```
production-scheduling/
├── production-scheduling.php (v1.1.0)
├── README.md
├── src/
│   ├── Engine/
│   │   └── Scheduler.php ✅ Core algorithm (testable)
│   ├── Database/
│   │   ├── Installer.php ✅ Table creation
│   │   └── CapacityRepository.php ✅ Capacity CRUD
│   ├── Admin/
│   │   ├── SettingsPage.php ✅ Global settings
│   │   ├── FormSettings.php ✅ Per-form config
│   │   └── ScheduleView.php ✅ Calendar & list view
│   ├── GravityForms/
│   │   ├── BillingStepPreview.php ✅ AJAX preview
│   │   ├── ValidationHandler.php ✅ Form validation
│   │   └── BookingHandler.php ✅ Save bookings
│   └── API/
│       └── AjaxEndpoints.php ✅ AJAX handlers
├── assets/
│   ├── js/
│   │   ├── billing-step.js ✅ Real-time preview
│   │   └── calendar-view.js (placeholder for v2)
│   └── css/
│       └── production-schedule.css (placeholder)
```

---

## 🎯 **FEATURES**

### **Automatic Scheduling**
- ✅ Finds earliest available production slots
- ✅ Spans multiple days if needed
- ✅ Respects working days and holidays
- ✅ Honors capacity overrides
- ✅ Prevents overbooking

### **Real-time Preview**
- ✅ AJAX calculation on LM field change
- ✅ Shows production dates
- ✅ Auto-fills installation date
- ✅ 500ms debounce (performance)

### **Form Validation**
- ✅ LM must be > 0
- ✅ Installation date cannot be in past
- ✅ Installation date cannot be before production completion
- ✅ Recalculates with live data before submission

### **Data Integrity**
- ✅ Saves complete production schedule
- ✅ Records who booked and when
- ✅ Clears cache after booking
- ✅ Action hook for extensibility

### **Manager Tools**
- ✅ Monthly calendar view
- ✅ Capacity indicators (color-coded)
- ✅ Bookings list with entry links
- ✅ Month navigation

---

## 📝 **EXAMPLE WORKFLOW**

**Sales submits order for 23 LM:**

1. **Calculation:**
   - Earliest start: Feb 5, 2026 (backlog offset)
   - Daily capacity: 10 LM/day
   - Allocation:
     - Feb 5: 2 LM (only 2 slots left)
     - Feb 6: 10 LM
     - Feb 7: 10 LM
     - Feb 8: 1 LM
   - Production: Feb 5-8
   - Installation minimum: Feb 8 (0 buffer)

2. **Form Display:**
   ```
   📅 Production Schedule
   Production Start: February 5, 2026
   Production Complete: February 8, 2026
   Total Days: 4 days
   Earliest Installation: February 8, 2026

   Installation Date: [Feb 8, 2026] ← Auto-filled, can choose later
   ```

3. **Saved Data:**
   - Entry #521 created
   - Slots reserved on Feb 5-8
   - Installation date: Feb 8 (or later if changed)
   - Booking confirmed

4. **Calendar Updated:**
   - Feb 5: Shows 10/10 (FULL)
   - Feb 6: Shows 10/10 (FULL)
   - Feb 7: Shows 10/10 (FULL)
   - Feb 8: Shows 1/10 (Available)

---

## 🔜 **FUTURE ENHANCEMENTS (v2)**

Planned for next version:
- [ ] Drag-and-drop to reschedule bookings
- [ ] Edit capacity directly in calendar
- [ ] Cancel/move bookings
- [ ] Export to CSV/PDF
- [ ] Email notifications
- [ ] Advanced reporting
- [ ] Timeline/Gantt view
- [ ] Multiple capacity profiles

---

## ✅ **TESTING CHECKLIST**

Before going live:
1. ✅ Configure global settings
2. ✅ Set backlog offset date
3. ✅ Enable on target form
4. ✅ Map LM and installation date fields
5. ✅ Test form submission (preview + save)
6. ✅ Check calendar shows booking
7. ✅ Verify entry meta is saved
8. ✅ Test validation (try invalid dates)

---

## 🐛 **TROUBLESHOOTING**

**Q: Preview not showing?**
- Check form has production scheduling enabled in Form Settings
- Verify LM and installation fields are mapped
- Check browser console for JavaScript errors

**Q: Installation date not auto-filling?**
- Ensure field is configured as Date field in GF
- Check field ID matches in form settings

**Q: Bookings not appearing in calendar?**
- Verify entry was successfully submitted
- Check entry has `_prod_slots_allocation` meta
- Clear browser cache

**Q: "Unable to schedule production" error?**
- Check daily capacity is > 0
- Verify backlog offset date is not too far in future
- Ensure working days are configured

---

## 📞 **SUPPORT**

Built by: Omar Alnabhani (hdqah.com)
Version: 1.1.0 (2025-12-21)

**Status:** ✅ Production Ready - Phase 2 Complete!
