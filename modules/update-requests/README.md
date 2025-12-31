# Update Requests Module v1.0.0

A comprehensive system for submitting, tracking, and applying update requests to existing job entries in SimpleFlow.

---

## 📋 Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Installation](#installation)
- [Setup](#setup)
- [Usage](#usage)
- [Architecture](#architecture)
- [Entry Meta Reference](#entry-meta-reference)
- [Hooks & Filters](#hooks--filters)
- [Troubleshooting](#troubleshooting)

---

## Overview

The Update Requests Module enables dual-mode forms that can function both as regular job submissions and as update request submissions for existing jobs. When in update request mode, entries are linked to their parent jobs, require approval before application, and support controlled file uploads.

### Two Request Types

1. **Entry Updating** - Modify existing entry fields (design changes, corrections)
2. **Following Invoice** - Add new items to existing invoice (future feature)

---

## Features

### ✅ v0.1.0 - Visibility Fix
- Dual-mode form detection via URL parameters
- Hidden field population (`_ur_mode`, `_ur_parent_id`, `_ur_type`)
- Child entry linking with full metadata tracking
- Parent panel showing all update requests
- Drawing selection from parent entry field 45
- Basic status tracking (submitted, approved, rejected)

### ✅ v0.2.0 - Approval Workflow Guards
- Prevents skipping approval step
- Enforces approval/rejection before proceeding
- Tracks approval/rejection with timestamps and user info
- Updates parent's children array automatically
- Integrates with GravityFlow validation

### ✅ v0.3.0 - File Attachments After Approval
- Hides file upload field until approval
- Shows status-based notices
- Validates file uploads (only after approval)
- Conditional field visibility
- User-friendly approval status display

### ✅ v0.4.0 - Entry Updating Logic
- Applies approved changes to parent entry
- Field mapping and synchronization
- Audit trail via entry notes
- Manual apply trigger for admins
- Tracks which fields were updated

---

## Installation

The module is automatically loaded by SimpleFlow. No manual installation required.

**Requirements:**
- SimpleFlow Core
- Gravity Forms
- GravityFlow (for approval workflows)
- WordPress 6.0+
- PHP 7.4+

---

## Setup

### 1. Form Configuration

Your form needs these hidden fields (use **Admin Labels**):

| Admin Label | Type | Purpose |
|------------|------|---------|
| `_ur_mode` | Hidden | Tracks if entry is update request |
| `_ur_parent_id` | Hidden | Parent entry ID |
| `_ur_type` | Hidden | Request type (entry_updating/following_invoice) |
| `_ur_drawing_selection` | Checkbox | Drawings from parent (optional) |
| `_ur_files` | File Upload | Attachments after approval (optional) |
| `_ur_file_notice` | HTML | Status notice for file uploads (optional) |

### 2. URL Parameters

To open form in update request mode:

```
/your-form/?update_request=1&parent_id=40507&request_type=entry_updating
```

**Parameters:**
- `update_request=1` - Activates update request mode
- `parent_id=123` - Parent job entry ID
- `request_type=entry_updating` - Type of request

### 3. Workflow Setup

Create a GravityFlow approval step:

1. Go to Form → Settings → Workflow
2. Add **Approval Step**
3. Configure assignees (who can approve/reject)
4. Save workflow

---

## Usage

### Submitting an Update Request

1. Navigate to parent job entry
2. Click "Submit Update Request" link (custom implementation)
3. URL opens form with parameters: `?update_request=1&parent_id=123&request_type=entry_updating`
4. Fill out fields to update
5. Submit form
6. Entry is created and linked to parent

### Viewing Update Requests

**In Parent Entry:**
- Open parent entry in workflow-inbox or admin
- Look for **"📋 Update Requests"** panel in right sidebar
- Shows all child update requests with:
  - Entry ID (clickable link)
  - Type (Design Change / Add Invoice Item)
  - Status badge (Submitted/Approved/Rejected)
  - Submitted date and user
  - View button

**In Child Entry:**
- Entry meta shows `_ur_mode = update_request`
- Entry meta shows `_ur_parent_id = 123`
- Entry meta shows `_ur_status = submitted/approved/rejected`

### Approval Workflow

1. Approver receives notification (via GravityFlow)
2. Approver opens update request entry
3. Reviews proposed changes
4. Clicks **Approve** or **Reject** in workflow step
5. Status updates automatically:
   - If approved: `_ur_status = approved`, entry update applied to parent
   - If rejected: `_ur_status = rejected`, no changes applied

### File Uploads (After Approval)

1. Submit update request (without files)
2. Wait for approval
3. After approval, re-open entry
4. File upload field becomes visible
5. Upload files related to update
6. Files are attached to update request entry

### Manual Apply (Admins Only)

If automatic application fails:

```php
// Trigger manual application
do_action( 'sfa_update_request_approved', $child_entry_id, $user_id );
```

Or use admin action (future feature):
```
/wp-admin/admin-post.php?action=sfa_ur_apply_update&entry_id=123&_wpnonce=...
```

---

## Architecture

### Module Structure

```
modules/update-requests/
├── update-requests.php (main loader)
└── src/
    ├── GravityForms/
    │   ├── ModeDetector.php (URL parameter handling)
    │   ├── ChildLinking.php (parent-child linking)
    │   ├── DrawingPopulation.php (populate checkboxes)
    │   ├── ApprovalGuards.php (workflow enforcement)
    │   ├── FileAttachments.php (conditional file uploads)
    │   └── EntryUpdating.php (apply changes to parent)
    └── Admin/
        └── ParentPanel.php (display update requests)
```

### Data Flow

```
1. User opens form with URL params
   ↓
2. ModeDetector populates hidden fields
   ↓
3. User submits form
   ↓
4. ChildLinking creates entry and links to parent
   ↓
5. Entry status = 'submitted'
   ↓
6. Approver reviews and approves
   ↓
7. ApprovalGuards tracks approval
   ↓
8. EntryUpdating applies changes to parent
   ↓
9. Entry status = 'approved'
   ↓
10. FileAttachments allows file uploads
```

---

## Entry Meta Reference

### Parent Entry Meta

| Meta Key | Type | Description |
|----------|------|-------------|
| `_ur_children` | JSON Array | All child update request IDs with metadata |

**Example:**
```json
[
  {
    "entry_id": 40508,
    "request_type": "entry_updating",
    "status": "approved",
    "submitted_at": "2025-12-30 10:30:00",
    "submitted_by": 1,
    "approved_at": "2025-12-30 14:00:00",
    "approved_by": 2
  }
]
```

### Child Entry Meta

| Meta Key | Type | Description |
|----------|------|-------------|
| `_ur_mode` | String | "update_request" or empty |
| `_ur_parent_id` | Integer | Parent entry ID |
| `_ur_type` | String | "entry_updating" or "following_invoice" |
| `_ur_status` | String | "submitted", "approved", "rejected" |
| `_ur_submitted_at` | DateTime | When submitted |
| `_ur_submitted_by` | Integer | User ID who submitted |
| `_ur_approved_at` | DateTime | When approved (if approved) |
| `_ur_approved_by` | Integer | User ID who approved (if approved) |
| `_ur_rejected_at` | DateTime | When rejected (if rejected) |
| `_ur_rejected_by` | Integer | User ID who rejected (if rejected) |
| `_ur_applied_at` | DateTime | When changes applied to parent |
| `_ur_applied_by` | Integer | User ID who applied changes |
| `_ur_updated_fields` | JSON Array | Fields updated in parent |

---

## Hooks & Filters

### Actions

**`sfa_update_request_linked`**
```php
do_action( 'sfa_update_request_linked', $entry_id, $parent_id, $request_type );
```
Fires after child entry is linked to parent.

**`sfa_update_request_approved`**
```php
do_action( 'sfa_update_request_approved', $entry_id, $user_id );
```
Fires after update request is approved. Triggers entry update.

**`sfa_update_request_rejected`**
```php
do_action( 'sfa_update_request_rejected', $entry_id, $user_id );
```
Fires after update request is rejected.

**`sfa_update_request_applied`**
```php
do_action( 'sfa_update_request_applied', $child_entry_id, $parent_id, $updated_fields );
```
Fires after changes are applied to parent entry.

### Filters

**`gravityflow_validation_step`**
Prevents skipping approval step for update requests.

**`gform_field_visibility`**
Controls file upload field visibility based on approval status.

**`gform_field_validation`**
Validates file uploads only allowed after approval.

**`gform_pre_render`**
Populates drawing checkboxes from parent entry field 45.

---

## Troubleshooting

### Update request not linking to parent

**Check:**
1. Hidden fields exist with correct Admin Labels
2. URL parameters are correct: `?update_request=1&parent_id=123`
3. Parent entry exists and is accessible
4. Check error logs for "Update Requests:" messages

### File upload field not showing after approval

**Check:**
1. File upload field has Admin Label: `_ur_files`
2. Entry status is actually "approved": `gform_get_meta( $entry_id, '_ur_status' )`
3. Entry ID is being detected correctly
4. Check error logs for field visibility issues

### Changes not applied to parent entry

**Check:**
1. Approval workflow completed successfully
2. Entry status changed to "approved"
3. `sfa_update_request_approved` action fired (check logs)
4. Parent entry is not locked or in use
5. Check entry notes in parent for "Update Request Applied"

### Approval step can be skipped

**Check:**
1. GravityFlow is active and loaded
2. ApprovalGuards class is initialized (check logs)
3. SimpleFlow validation bypass filters are not interfering
4. User is not admin bypassing workflow

### Drawing selection not populated

**Check:**
1. Checkbox field has Admin Label: `_ur_drawing_selection`
2. Parent entry has data in field 45
3. Data format is comma-separated, newline-separated, or JSON
4. Form is in update request mode (`?update_request=1`)

---

## Version History

- **v1.0.0** - Full production release with all features
- **v0.4.0** - Entry updating logic
- **v0.3.0** - File attachments after approval
- **v0.2.0** - Approval workflow guards
- **v0.1.0** - Visibility fix (initial release)

---

## Support

For issues or questions:
1. Check error logs for "Update Requests:" messages
2. Verify form field Admin Labels match documentation
3. Test URL parameters are being passed correctly
4. Review GravityFlow workflow configuration

---

**Author:** Omar Alnabhani (hdqah.com)
**License:** GPLv2 or later
**SimpleFlow Version:** 0.1.3+
