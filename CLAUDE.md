# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WordPress plugin called "Taxonomy Combination Pages with Blocksy" that creates SEO-optimized virtual pages for taxonomy combinations. It's designed for local SEO targeting (e.g., "English Dentist in Tokyo") by automatically generating landing pages for every combination of two taxonomies.

## Key Configuration

The main plugin file `/taxonomy-combinations.php` contains critical configuration that must be customized for each deployment:

- **Line 16**: `$post_type` - Custom Post Type name (default: 'healthcare_provider')
- **Line 17**: `$taxonomy_1` - First taxonomy name (default: 'specialties')  
- **Line 18**: `$taxonomy_2` - Second taxonomy name (default: 'location')
- **Line 19**: `$url_base` - URL base path (empty for root-level URLs)
- **Line 20**: `$url_pattern` - URL pattern ('combined' or 'hierarchical')

## Architecture

### Core Components

1. **Main Plugin Class** (`TaxonomyCombinationPages` in `taxonomy-combinations.php`)
   - Handles virtual page generation and routing
   - Manages database operations for combination settings
   - Integrates with WordPress hooks and filters
   - Provides admin interface functionality

2. **Database Structure**
   - Custom table: `{prefix}_taxonomy_combinations`
   - Stores combination metadata (titles, descriptions, SEO settings, content blocks)
   - Auto-generates combinations when taxonomies are modified

3. **URL Routing System**
   - Creates rewrite rules for virtual pages
   - Supports two URL patterns:
     - Combined: `/english-dentist-in-tokyo/`
     - Hierarchical: `/tokyo/dentist/`
   - Handles 404 prevention for valid combinations

4. **Integration Points**
   - **Yoast SEO**: Full meta control, sitemap integration
   - **Blocksy Theme**: Content Blocks for custom layouts
   - **WordPress Core**: Hooks into query vars, template system

### Frontend Features

- Dynamic shortcodes for content insertion:
  - `[tc_field]` - Display combination data
  - `[tc_posts]` - Show matching posts grid
- Template hierarchy respects theme structure
- Virtual pages without database bloat

### Admin Interface

- List view with filters and bulk actions
- Tabbed edit interface:
  - General settings
  - SEO configuration
  - Blocksy Content Blocks assignment
  - Custom HTML content
- AJAX-powered operations via `admin.js`

## Development Commands

Since this is a WordPress plugin, there are no build or test commands. Development workflow:

1. **Installation**: Place in `/wp-content/plugins/` directory
2. **Activation**: Via WordPress admin → Plugins
3. **Configuration**: Edit lines 16-20 in main plugin file
4. **Permalinks**: Flush via Settings → Permalinks after changes

## Testing Approach

For testing changes:
1. Check virtual page generation at configured URLs
2. Verify admin interface functionality
3. Test bulk operations and AJAX endpoints
4. Validate SEO meta tags and sitemap generation
5. Confirm Blocksy Content Blocks integration if theme is active

## Important Considerations

- Plugin uses WordPress database table creation on activation
- Rewrite rules require permalink flush after configuration changes
- Virtual pages are generated on-the-fly, not stored as posts
- All combinations auto-generate when taxonomies are modified
- Admin JavaScript (`assets/admin.js`) handles UI interactions with localStorage for tab persistence

## File Structure

- `/taxonomy-combinations.php` - Main plugin file with all core functionality
- `/assets/admin.js` - Admin interface JavaScript
- `/assets/admin.css` - Admin styling
- `/readme.md` - User documentation
- `/readme.txt` - WordPress.org plugin directory format