# Taxonomy Combination Pages with Blocksy Integration

A WordPress plugin that creates SEO-optimized virtual pages for taxonomy combinations, with full Blocksy Content Blocks support and Yoast SEO integration.

## 🎯 Purpose

This plugin solves the problem of targeting location-based SEO searches like "English dentist in Tokyo" by automatically creating optimized landing pages for every combination of two taxonomies.

## ✨ Features

- **🔄 Automatic Virtual Pages** - Generates pages for all taxonomy combinations
- **🔍 SEO-Optimized URLs** - Clean URLs like `/english-dentist-in-tokyo/`
- **🎨 Blocksy Integration** - Full Content Blocks support for custom layouts
- **📊 Yoast SEO Support** - Complete meta control and sitemap integration
- **🗺️ XML Sitemap** - Automatic sitemap generation
- **⚡ Bulk Management** - Edit multiple combinations efficiently
- **🔧 Dynamic Shortcodes** - Insert combination-specific content

## 📋 Requirements

- WordPress 5.0+
- PHP 7.2+
- Custom Post Type with 2 taxonomies
- Blocksy Theme (optional, for Content Blocks)
- Yoast SEO (optional, for enhanced SEO)

## 🚀 Installation

1. **Download and Extract**
   ```bash
   cd wp-content/plugins/
   git clone [repository] taxonomy-combinations
   ```

2. **Configure the Plugin**
   
   Edit `taxonomy-combinations.php` and update these lines:
   ```php
   private $post_type = 'your_post_type';  // Line 16 - Your CPT name
   private $taxonomy_1 = 'specialties';    // Line 17 - First taxonomy
   private $taxonomy_2 = 'locations';      // Line 18 - Second taxonomy (note: 'location' not 'locations')
   private $url_base = '';                 // Line 19 - URL base (empty for root)
   private $url_pattern = 'combined';      // Line 20 - URL pattern
   ```

3. **Activate the Plugin**
   - Go to WordPress Admin → Plugins
   - Activate "Taxonomy Combination Pages with Blocksy"

4. **Flush Permalinks**
   - Go to Settings → Permalinks
   - Click "Save Changes" (no need to modify anything)

## 🎨 Blocksy Content Blocks Setup

### How It Works

The Blocksy integration allows you to create reusable templates for your combination pages using Blocksy's visual builder.

### Step-by-Step Guide

1. **Create Content Blocks First**
   - Go to **Blocksy → Content Blocks** in your WordPress admin
   - Click "Add New Content Block"
   - Design your layout using Blocksy's builder
   - Use the dynamic shortcodes (see below) to insert combination-specific data

2. **Example Content Block**
   ```html
   <div class="hero-section">
     <h1>[tc_field field="title"]</h1>
     <p>Find the best [tc_field field="specialty"] services in [tc_field field="location"]</p>
   </div>
   
   <div class="listings">
     [tc_posts number="6" columns="3" show_excerpt="yes"]
   </div>
   
   <div class="cta">
     <p>Book your appointment with our English-speaking [tc_field field="specialty"] today!</p>
   </div>
   ```

3. **Assign Blocks to Combinations**
   - Go to **Tax Combinations** in admin menu
   - Click "Edit" on any combination
   - Go to the **Blocksy Blocks** tab
   - Select your Content Blocks for:
     - Header Content Block (above main content)
     - Main Content Block (replaces default layout)
     - Footer Content Block (below main content)

4. **Save and View**
   - Click "Update Combination"
   - Visit the page URL to see your custom layout

### Content Block Strategy

- **Create Template Blocks**: Make a few reusable templates for different types of combinations
- **Use Dynamic Content**: Leverage shortcodes so one block works for many combinations
- **Fallback Layout**: Pages without assigned blocks use the default layout

## 📝 Available Shortcodes

### Field Shortcode
Display combination-specific data:

```
[tc_field field="title"]           // Page title
[tc_field field="description"]     // Custom description
[tc_field field="location"]        // Location name
[tc_field field="location_slug"]   // Location URL slug
[tc_field field="specialty"]       // Specialty name
[tc_field field="specialty_slug"]  // Specialty URL slug
[tc_field field="url"]            // Full page URL
[tc_field field="post_count"]     // Number of matching posts
```

### Posts Grid Shortcode
Display matching posts:

```
[tc_posts 
  number="6"           // Number of posts
  columns="3"          // Grid columns
  show_excerpt="yes"   // Show excerpt
  show_image="yes"     // Show featured image
  image_size="medium"  // Image size
  show_date="no"       // Show date
  show_author="no"     // Show author
]
```

## 🔧 Configuration Options

### URL Patterns

1. **Combined Pattern** (SEO-friendly)
   ```php
   $url_pattern = 'combined';  // Creates: /english-dentist-in-tokyo/
   ```

2. **Hierarchical Pattern**
   ```php
   $url_pattern = 'hierarchical';  // Creates: /tokyo/dentist/
   ```

### URL Base

- Set `$url_base = ''` for root-level URLs
- Set `$url_base = 'services'` for `/services/location/specialty/`

## 📊 Admin Interface

### Main Features

- **List View**: See all combinations with filters and search
- **Edit Interface**: Tabbed interface for easy management
- **Bulk Edit**: Update multiple combinations at once
- **Settings Page**: Global configuration options

### Tabs in Edit Screen

1. **General**: Title and description
2. **SEO**: Meta tags and robots settings
3. **Blocksy Blocks**: Assign Content Blocks
4. **Custom Content**: Additional HTML content

## 🗺️ XML Sitemap

The plugin automatically generates a sitemap at:
```
https://yoursite.com/tc-combinations-sitemap.xml
```

This integrates with Yoast SEO's main sitemap index automatically.

## 🎯 SEO Best Practices

1. **URL Structure**: Uses SEO-friendly URLs with target keywords
2. **Meta Control**: Full control over title tags and descriptions
3. **Robots Settings**: Control indexing per page
4. **Automatic Defaults**: Smart defaults for all SEO fields
5. **Sitemap Integration**: Ensures Google discovers all pages

## 🐛 Troubleshooting

### URLs Not Working
- Go to Settings → Permalinks and save
- Check for conflicts with other plugins
- Verify taxonomy names are correct

### Content Blocks Not Showing
- Ensure Blocksy Pro is activated
- Create Content Blocks before assigning them
- Check that blocks are published, not draft

### Sitemap Not Appearing
- Flush permalinks after activation
- Check Yoast SEO settings
- Visit the sitemap URL directly

## 📈 Performance Considerations

- Virtual pages mean no database bloat
- Efficient queries with proper indexing
- Caches well with standard WordPress caching plugins
- Lazy loads combination data only when needed

## 🤝 Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

## 📄 License

GPLv2 or later

## 💡 Use Cases

- **Medical Directories**: "English doctor in Shibuya"
- **Service Businesses**: "Emergency plumber in Brooklyn"
- **Professional Networks**: "Tax attorney in Manhattan"
- **Educational Services**: "Math tutor in Queens"
- **Any Local SEO**: "[service] in [location]" searches

## 🆘 Support

For issues and questions:
1. Check the FAQ in readme.txt
2. Review existing GitHub issues
3. Create a new issue with details

## 🔄 Changelog

### Version 2.0
- Added Blocksy Content Blocks integration
- Improved URL structure options
- Added XML sitemap generation
- Enhanced bulk editing capabilities
- Added custom slug support
- Improved admin interface with tabs

### Version 1.0
- Initial release
- Basic virtual page generation
- Yoast SEO integration
- Admin management interface