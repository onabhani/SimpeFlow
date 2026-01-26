# Simple Notes Module - Architecture Documentation

## ⚠️ CRITICAL: Dual Implementation Architecture

The Simple Notes system has **TWO COMPLETELY SEPARATE implementations** that do **NOT share code**. When making changes to any feature, you **MUST update BOTH implementations independently**.

---

## Implementation Overview

### 1. Backend/Admin Implementation

**Location:** Admin entry detail pages (WordPress admin)

**Entry Point:**
- File: `src/Frontend/AutoPositioning.php` → `add_admin_notes_widget()`
- Detects: Entry detail pages in WordPress admin

**JavaScript:**
- File: `assets/js/notes.js`
- Object: `window.SimpleNotes`
- Methods:
  - `SimpleNotes.init()`
  - `SimpleNotes.addNote()`
  - `SimpleNotes.loadNotes()`
  - `SimpleNotes.renderNotes()`
  - `SimpleNotes.deleteNote()`
  - `SimpleNotes.mentionUser()`

**Element IDs:**
- Textarea: `#note-content-{entityId}`
- Notes List: `#notes-list-{entityId}`
- Container: `#simple-notes-container`

**CSS Classes:**
- Author Name: `.author-name-clickable`
- Delete Button: `.sn-delete-btn`
- Add Button: `.sn-add-note-btn`

---

### 2. Frontend/Workflow Inbox Implementation

**Location:** Workflow inbox pages (frontend entry view)

**Entry Point:**
- File: `src/Frontend/AutoPositioning.php` → `add_frontend_notes_widget()`
- Detects: Workflow inbox pages with `?lid={id}&view=entry`

**JavaScript:**
- File: `src/Frontend/AutoPositioning.php` (inline JavaScript)
- Functions (global scope):
  - `addNoteFrontend(entityId)`
  - `loadNotesFrontend(entityId)`
  - `deleteFrontendNote(noteId, entityId)`
  - `setupFrontendMentions(entityId)`
  - `searchUsersFrontend(query, entityId)`
  - `insertFrontendMention(entityId, username)`

**Element IDs:**
- Textarea: `#note-content-frontend-{entityId}`
- Notes List: `#notes-list-frontend-{entityId}`
- Mention Dropdown: `#mention-dropdown-frontend-{entityId}`

**CSS Classes:**
- Author Name: `.author-name-clickable-frontend`
- Container: `.notes-widget-container`

---

## Common Features (Must Be Updated in Both)

### Clickable Author Names
- **Backend:** Added to `notes.js` → `renderNotes()` with class `.author-name-clickable`
- **Frontend:** Added to `AutoPositioning.php` → `loadNotesFrontend()` with class `.author-name-clickable-frontend`

### Delete Functionality
- **Backend:** `SimpleNotes.deleteNote()` in `notes.js`
- **Frontend:** `deleteFrontendNote()` in `AutoPositioning.php`

### Mentions System
- **Backend:** `SimpleNotes.setupMentions()` in `notes.js`
- **Frontend:** `setupFrontendMentions()` in `AutoPositioning.php`

### Note Display
- **Backend:** `SimpleNotes.renderNotes()` in `notes.js`
- **Frontend:** `loadNotesFrontend()` in `AutoPositioning.php` (inline HTML generation)

---

## File Structure

```
modules/simple-notes/
├── ARCHITECTURE.md                    # This file
├── simple-notes.php                   # Main plugin file
├── assets/
│   └── js/
│       └── notes.js                   # BACKEND implementation
├── src/
│   ├── API/
│   │   └── AjaxEndpoints.php          # Shared AJAX endpoints
│   ├── Database/
│   │   └── Installer.php              # Database tables
│   ├── Frontend/
│   │   └── AutoPositioning.php        # FRONTEND implementation + positioning logic
│   └── Admin/
│       ├── NotesPage.php              # Admin page
│       └── SettingsPage.php           # Settings page
```

---

## Change Checklist

When making changes to Simple Notes functionality:

- [ ] Update backend implementation (`assets/js/notes.js`)
- [ ] Update frontend implementation (`src/Frontend/AutoPositioning.php`)
- [ ] Test on admin entry detail page
- [ ] Test on workflow inbox page
- [ ] Verify AJAX endpoints work for both (if applicable)
- [ ] Update this documentation if architecture changes

---

## Why Two Implementations?

The dual implementation exists because:
1. **Different environments:** Admin pages vs. frontend pages
2. **Different loading contexts:** Enqueued script vs. inline script
3. **Different timing requirements:** DOM ready vs. delayed injection
4. **Historical reasons:** Frontend was added later as inline code

## Future Recommendations

Consider consolidating both implementations into a single shared JavaScript file that works in both contexts. This would:
- Reduce code duplication
- Ensure feature parity automatically
- Simplify maintenance
- Reduce bugs from forgetting to update both implementations

---

**Last Updated:** 2026-01-14
**Important:** This documentation should be updated whenever the architecture changes.
