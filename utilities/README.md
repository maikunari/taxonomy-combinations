# Utility Scripts for Taxonomy Combinations Plugin

This directory contains debug and utility scripts for the Taxonomy Combinations plugin. These files are not required for normal plugin operation.

## Debug Scripts

### debug-acf.php
- **Purpose:** Debug ACF field values and template loading
- **Usage:** Upload to WordPress root, visit `/debug-acf.php`
- **When to use:** When ACF fields aren't displaying correctly

### debug-template.php
- **Purpose:** Debug template hierarchy and hook registration
- **Usage:** Upload to WordPress root, visit `/debug-template.php?post_id=XXX`
- **When to use:** When templates aren't loading or hooks aren't firing

## Utility Scripts

### bulk-rename-dentistry.php
- **Purpose:** Bulk rename "Dentistry" to "Dentist" in all combination pages
- **Usage:** Upload to WordPress root, run once, then delete
- **When to use:** When updating taxonomy terms that need to be reflected in existing pages

### fix-single-slug.php
- **Purpose:** Fix individual post slugs that won't update through WordPress UI
- **Usage:** Upload to WordPress root, visit `/fix-single-slug.php`
- **When to use:** When a specific post's slug is locked or uneditable

### enable-slug-editing.php
- **Purpose:** Plugin to enable slug editing for tc_combination posts
- **Usage:** Upload to `/wp-content/plugins/` and activate
- **When to use:** When slug field is read-only in the post editor

## Important Notes

1. **Security:** These scripts check for admin capabilities but should be deleted after use
2. **Backup:** Always backup your database before running bulk operations
3. **Clear Cache:** After running any script, clear all caches and flush permalinks
4. **Temporary:** These are diagnostic/utility tools - not meant for production use

## After Using Scripts

1. Delete the script from the server (except enable-slug-editing.php if needed permanently)
2. Clear all caches (browser, CDN, WordPress)
3. Flush permalinks (Settings → Permalinks → Save)