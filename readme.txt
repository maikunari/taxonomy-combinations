=== Taxonomy Combination Pages with Blocksy ===
Contributors: yourname
Tags: taxonomy, seo, virtual pages, blocksy, content blocks
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 2.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create SEO-optimized virtual pages for taxonomy combinations with full Blocksy Content Blocks support and Yoast SEO integration.

== Description ==

This plugin creates virtual pages for every combination of two taxonomies (e.g., "English Dentist in Tokyo"), perfect for local SEO targeting. Each combination gets its own URL, customizable content, and full SEO controls.

**Key Features:**

* **Automatic Virtual Pages** - Generates pages for all taxonomy combinations automatically
* **SEO-Optimized URLs** - Clean URLs like `/english-dentist-in-tokyo/`
* **Blocksy Integration** - Use Blocksy Content Blocks for custom layouts
* **Yoast SEO Support** - Full control over meta titles, descriptions, and robots settings
* **XML Sitemap** - Automatic sitemap generation for all combination pages
* **Bulk Management** - Edit multiple combinations at once
* **Dynamic Shortcodes** - Insert combination-specific content dynamically

**Perfect for:**

* Multi-location businesses
* Service directories
* Professional networks
* Healthcare providers
* Any site targeting "[service] in [location]" searches

== Installation ==

1. Upload the `taxonomy-combinations` folder to `/wp-content/plugins/`
2. Edit `taxonomy-combinations.php` line 16 to set your custom post type
3. Verify taxonomy names on lines 17-18 match yours
4. Activate the plugin through the 'Plugins' menu
5. Go to Settings → Permalinks and click "Save Changes"
6. Visit "Tax Combinations" in your admin menu to manage pages

== Configuration ==

**Required Setup:**

1. **Custom Post Type**: Edit line 16 to match your CPT name
   `private $post_type = 'your_post_type';`

2. **Taxonomies**: Edit lines 17-18 to match your taxonomy names
   `private $taxonomy_1 = 'specialties';`
   `private $taxonomy_2 = 'location';`  // Note: singular 'location'

3. **URL Pattern**: Choose your URL structure (lines 19-20)
   - Set `$url_base = ''` for root-level URLs
   - Set `$url_pattern = 'combined'` for SEO-friendly URLs

== Blocksy Content Blocks Integration ==

1. **Create Content Blocks** in Blocksy → Content Blocks
2. **Use Dynamic Shortcodes** in your blocks:
   - `[tc_field field="title"]` - Page title
   - `[tc_field field="location"]` - Location name
   - `[tc_field field="specialty"]` - Specialty name
   - `[tc_posts]` - Display matching posts
3. **Assign Blocks** via Tax Combinations → Edit → Blocksy Blocks tab

== Available Shortcodes ==

**Field Shortcode:**
`[tc_field field="XXX"]`

Available fields:
* `title` - Combination title
* `description` - Custom description
* `location` - Location name
* `location_slug` - Location URL slug
* `specialty` - Specialty name
* `specialty_slug` - Specialty URL slug
* `url` - Full page URL
* `post_count` - Number of posts

**Posts Grid Shortcode:**
`[tc_posts number="6" columns="3" show_excerpt="yes" show_image="yes"]`

== Frequently Asked Questions ==

= Do I need Blocksy Pro? =

No, the plugin works without Blocksy. However, Blocksy Pro's Content Blocks feature allows for much more powerful custom layouts.

= Will these pages appear in my sitemap? =

Yes! The plugin automatically generates an XML sitemap at `/tc-combinations-sitemap.xml` that integrates with Yoast SEO.

= Can I customize individual combination pages? =

Yes, each combination can have:
- Custom URL slug
- Unique title and description
- Custom meta tags for SEO
- Different Content Blocks
- Custom HTML content

= How are new combinations handled? =

When you add a new term to either taxonomy, the plugin automatically creates all necessary combinations with that new term.

= Can I bulk edit combinations? =

Yes, use the Bulk Edit feature to update multiple combinations at once (content blocks, SEO settings, etc.).

= What happens if I deactivate the plugin? =

The virtual pages will no longer be accessible, but all your settings are preserved in the database if you reactivate later.

== Changelog ==

= 2.0 =
* Added Blocksy Content Blocks integration
* Improved URL structure options
* Added XML sitemap generation
* Enhanced bulk editing capabilities
* Added custom slug support
* Improved admin interface with tabs

= 1.0 =
* Initial release
* Basic virtual page generation
* Yoast SEO integration
* Admin management interface

== Upgrade Notice ==

= 2.0 =
Major update with Blocksy integration and improved SEO features. Backup your site before upgrading.

== Screenshots ==

1. Main admin interface showing all combinations
2. Edit screen with tabbed interface
3. Blocksy Content Blocks assignment
4. SEO settings panel
5. Bulk edit interface
6. Example frontend page