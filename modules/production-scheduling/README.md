# Production Scheduling Module

**Version:** 1.0.0 (Foundation)
**Status:** Phase 1 Complete - Core Engine Built

## What's Implemented

### ✅ Phase 1: Foundation (COMPLETED)

1. **Core Scheduling Engine** (`src/Engine/Scheduler.php`)
   - Pure PHP, no WordPress dependencies
   - Multi-day slot allocation algorithm
   - Working days and holiday support
   - Capacity override support
   - Fully testable

2. **Database Layer**
   - `Installer.php` - Creates capacity_overrides table
   - `CapacityRepository.php` - CRUD operations for overrides

3. **Settings Page** (`src/Admin/SettingsPage.php`)
   - Daily production capacity configuration
   - Working days selection (checkboxes)
   - Installation buffer days
   - Backlog offset (earliest production start date)
   - Holidays management

4. **Gravity Forms Integration Stubs**
   - `BillingStepPreview.php` - Real-time schedule calculation
   - `ValidationHandler.php` - Form submission validation
   - `BookingHandler.php` - Save bookings after submission
   - `AjaxEndpoints.php` - AJAX handlers

5. **Frontend Assets**
   - `billing-step.js` - Form preview logic (stub)
   - `calendar-view.js` - Calendar interface (stub)
   - `production-schedule.css` - Styles (stub)

## Settings

Access: **Settings → Production Scheduling**

- **Daily Production Capacity:** 10 LM/day (default)
- **Working Days:** Mon-Thu, Sat-Sun (Friday off by default)
- **Installation Buffer:** 0 days (same-day installation allowed)
- **Earliest Production Start:** Empty = today, or set future date for backlog
- **Holidays:** One date per line (YYYY-MM-DD)

## Entry Meta Structure

When a booking is saved:

```php
_prod_lm_required          // int - Total LM ordered
_prod_slots_allocation     // JSON - {"2026-01-01": 2, "2026-01-02": 8}
_prod_start_date           // string - First production day
_prod_end_date             // string - Last production day
_install_date              // string - Installation date (customer-facing)
_prod_booking_status       // string - confirmed|completed|cancelled
_prod_booked_at            // datetime - When booked
_prod_booked_by            // int - User ID
```

## Next Steps (Phase 2)

- [ ] Complete GF field integration (LM field, installation date field)
- [ ] Implement full AJAX preview in billing step
- [ ] Implement validation handler
- [ ] Implement booking save handler
- [ ] Build calendar view (manager interface)
- [ ] Build list view (sales interface)
- [ ] Add capacity management UI

## Testing

To test the core scheduler:

```php
use SFA\ProductionScheduling\Engine\Scheduler;

$scheduler = new Scheduler();

$schedule = $scheduler->calculate_schedule(
    23, // LM required
    new DateTime('2026-01-01'), // Earliest start
    10, // Daily capacity
    [], // No overrides
    [], // No existing bookings
    [5], // Friday off
    [], // No holidays
    0 // No installation buffer
);

print_r($schedule);
```

## Files

```
production-scheduling/
├── production-scheduling.php (Main plugin, autoloader, initialization)
├── src/
│   ├── Engine/
│   │   └── Scheduler.php (Core algorithm - TESTABLE)
│   ├── Database/
│   │   ├── Installer.php (Table creation)
│   │   └── CapacityRepository.php (Capacity CRUD)
│   ├── GravityForms/
│   │   ├── BillingStepPreview.php (AJAX preview)
│   │   ├── ValidationHandler.php (Form validation)
│   │   └── BookingHandler.php (Save bookings)
│   ├── Admin/
│   │   └── SettingsPage.php (Settings UI)
│   └── API/
│       └── AjaxEndpoints.php (AJAX handlers)
├── assets/
│   ├── js/
│   │   ├── billing-step.js (Form preview)
│   │   └── calendar-view.js (Calendar UI)
│   └── css/
│       └── production-schedule.css (Styles)
└── README.md (This file)
```

## Architecture

- **Scheduler Engine:** Pure PHP (framework-agnostic, testable)
- **Data Storage:** Entry meta + capacity_overrides table
- **Caching:** WP object cache for availability calculations
- **Race Conditions:** Prevented by revalidation before save

## Version History

- **1.0.0** (2025-12-21) - Phase 1: Core engine and settings page
