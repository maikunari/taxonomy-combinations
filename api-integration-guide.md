# API Integration Guide for Taxonomy Combinations

## 🚀 Overview

With the new Version 3.0 using real WordPress posts (Custom Post Type), the REST API is now powered by WordPress's native REST API system. This means better performance, standard authentication, and automatic endpoints!

## 📋 Prerequisites

1. **WordPress 5.6+** (for Application Passwords)
2. **Taxonomy Combinations Plugin v3.0+** installed and activated
3. **CPT created** (either via CPT UI or the plugin will create it)

## 🔐 Authentication Methods

### Option 1: Application Passwords (Recommended)

1. Go to **Users → Your Profile** in WordPress admin
2. Scroll to **Application Passwords** section
3. Enter a name (e.g., "External App")
4. Click **Add New Application Password**
5. Copy the generated password (shown only once!)

**Usage:**
```javascript
const auth = btoa('username:xxxx-xxxx-xxxx-xxxx-xxxx');
fetch('https://kantanhealth.jp/wp-json/wp/v2/tc_combination', {
    headers: {
        'Authorization': 'Basic ' + auth
    }
});
```

### Option 2: JWT Authentication

1. Install [JWT Authentication plugin](https://wordpress.org/plugins/jwt-authentication-for-wp-rest-api/)
2. Configure as per plugin documentation
3. Get token via `/wp-json/jwt-auth/v1/token`

## 📍 REST API Endpoints

### Base URL
```
https://kantanhealth.jp/wp-json/wp/v2/tc_combination
```

### Available Operations

#### 1. Get All Combinations
```http
GET /wp-json/wp/v2/tc_combination
```

**Query Parameters:**
- `per_page` - Number of results (default: 10, max: 100)
- `page` - Page number
- `status` - Post status (publish, draft, private)
- `orderby` - Sort field (date, title, menu_order)
- `order` - Sort direction (asc, desc)

**Example:**
```javascript
fetch('https://kantanhealth.jp/wp-json/wp/v2/tc_combination?per_page=100&status=publish')
```

#### 2. Get Single Combination by ID
```http
GET /wp-json/wp/v2/tc_combination/{id}
```

**Example:**
```javascript
fetch('https://kantanhealth.jp/wp-json/wp/v2/tc_combination/123')
```

#### 3. Get Combination by Slug
```http
GET /wp-json/wp/v2/tc_combination?slug=english-dentistry-in-shibuya
```

**Example:**
```javascript
fetch('https://kantanhealth.jp/wp-json/wp/v2/tc_combination?slug=english-dentistry-in-shibuya')
    .then(res => res.json())
    .then(data => {
        const combination = data[0]; // First result
        console.log(combination);
    });
```

#### 4. Create New Combination
```http
POST /wp-json/wp/v2/tc_combination
```

**Request Body:**
```json
{
    "title": "English Orthodontics in Harajuku",
    "slug": "english-orthodontics-in-harajuku",
    "status": "publish",
    "content": "<p>Full HTML content here</p>",
    "meta": {
        "_tc_location_id": 5,
        "_tc_specialty_id": 12,
        "_tc_brief_intro": "Short introduction text",
        "_tc_full_description": "Detailed description",
        "_tc_header_block_id": 3668,
        "_tc_footer_block_id": 3670,
        "_tc_seo_title": "SEO Title Here",
        "_tc_seo_description": "SEO meta description"
    }
}
```

#### 5. Update Existing Combination
```http
POST /wp-json/wp/v2/tc_combination/{id}
```

**Request Body (partial update supported):**
```json
{
    "content": "<p>Updated content</p>",
    "meta": {
        "_tc_brief_intro": "Updated brief intro",
        "_tc_full_description": "Updated full description"
    }
}
```

#### 6. Delete Combination
```http
DELETE /wp-json/wp/v2/tc_combination/{id}
```

## 🐍 Python Integration Example

```python
import requests
from requests.auth import HTTPBasicAuth
import json

class TaxonomyCombinationAPI:
    def __init__(self, site_url, username, app_password):
        self.base_url = f"{site_url}/wp-json/wp/v2/tc_combination"
        self.auth = HTTPBasicAuth(username, app_password)
        self.headers = {'Content-Type': 'application/json'}
    
    def get_all_combinations(self, per_page=100):
        """Get all combination pages"""
        response = requests.get(
            self.base_url,
            params={'per_page': per_page, 'status': 'publish'},
            auth=self.auth
        )
        return response.json()
    
    def get_combination_by_slug(self, slug):
        """Get single combination by slug"""
        response = requests.get(
            self.base_url,
            params={'slug': slug},
            auth=self.auth
        )
        data = response.json()
        return data[0] if data else None
    
    def update_combination(self, combo_id, updates):
        """Update combination content and meta"""
        response = requests.post(
            f"{self.base_url}/{combo_id}",
            json=updates,
            auth=self.auth,
            headers=self.headers
        )
        return response.json()
    
    def update_by_location_specialty(self, location_slug, specialty_slug, content_data):
        """Update combination by location and specialty slugs"""
        slug = f"english-{specialty_slug}-in-{location_slug}"
        combo = self.get_combination_by_slug(slug)
        
        if not combo:
            # Create new if doesn't exist
            create_data = {
                'title': f'English {specialty_slug.title()} in {location_slug.title()}',
                'slug': slug,
                'status': 'publish',
                **content_data
            }
            response = requests.post(
                self.base_url,
                json=create_data,
                auth=self.auth,
                headers=self.headers
            )
            return response.json()
        else:
            # Update existing
            return self.update_combination(combo['id'], content_data)

# Usage example
api = TaxonomyCombinationAPI(
    site_url='https://kantanhealth.jp',
    username='your_username',
    app_password='xxxx-xxxx-xxxx-xxxx-xxxx'
)

# Get combination
combo = api.get_combination_by_slug('english-dentistry-in-shibuya')

# Update with AI-generated content
updates = {
    'content': '<p>Your AI-generated HTML content here...</p>',
    'meta': {
        '_tc_brief_intro': 'AI-generated brief introduction for above the listings',
        '_tc_full_description': 'AI-generated detailed description for below the listings',
        '_tc_seo_title': 'English Dentists in Shibuya | Find English-Speaking Dental Care',
        '_tc_seo_description': 'AI-optimized meta description for search engines (max 160 chars)'
    }
}

result = api.update_combination(combo['id'], updates)
print(f"Updated: {result['title']['rendered']}")
```

## 📊 JavaScript/Node.js Example

```javascript
class TaxonomyCombinationAPI {
    constructor(siteUrl, username, appPassword) {
        this.baseUrl = `${siteUrl}/wp-json/wp/v2/tc_combination`;
        this.auth = 'Basic ' + btoa(`${username}:${appPassword}`);
    }
    
    async getCombinationBySlug(slug) {
        const response = await fetch(`${this.baseUrl}?slug=${slug}`, {
            headers: { 'Authorization': this.auth }
        });
        const data = await response.json();
        return data[0] || null;
    }
    
    async updateCombination(id, updates) {
        const response = await fetch(`${this.baseUrl}/${id}`, {
            method: 'POST',
            headers: {
                'Authorization': this.auth,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(updates)
        });
        return response.json();
    }
    
    async bulkUpdateByLocation(locationSlug, updates) {
        // Get all combinations for a location
        const combos = await this.getAllCombinations();
        const locationCombos = combos.filter(c => 
            c.slug.includes(`-in-${locationSlug}`)
        );
        
        // Update each one
        const results = [];
        for (const combo of locationCombos) {
            const result = await this.updateCombination(combo.id, updates);
            results.push(result);
        }
        return results;
    }
}

// Usage
const api = new TaxonomyCombinationAPI(
    'https://kantanhealth.jp',
    'username',
    'xxxx-xxxx-xxxx-xxxx-xxxx'
);

// Update specific combination
const updates = {
    content: '<p>Updated content from external app</p>',
    meta: {
        '_tc_brief_intro': 'New brief intro',
        '_tc_full_description': 'New full description'
    }
};

api.updateCombination(123, updates)
    .then(result => console.log('Updated:', result));
```

## 📝 Available Meta Fields

All meta fields should be prefixed with underscore and passed in the `meta` object:

| Field | Type | Description |
|-------|------|-------------|
| `_tc_location_id` | integer | WordPress term ID for location |
| `_tc_specialty_id` | integer | WordPress term ID for specialty |
| `_tc_brief_intro` | string | Short intro text (displays above content) |
| `_tc_full_description` | string | Full description (displays below content) |
| `_tc_header_block_id` | integer | Blocksy content block ID for header |
| `_tc_content_block_id` | integer | Blocksy content block ID for main content |
| `_tc_footer_block_id` | integer | Blocksy content block ID for footer |
| `_tc_seo_title` | string | SEO title tag (max 60 chars recommended) |
| `_tc_seo_description` | string | SEO meta description (max 160 chars) |

## 🔄 Batch Operations

For bulk updates, you can use WordPress's batch API:

```javascript
// Batch update multiple combinations
const batchRequest = {
    requests: [
        {
            method: 'POST',
            path: '/wp/v2/tc_combination/123',
            body: { meta: { '_tc_footer_block_id': 3670 } }
        },
        {
            method: 'POST',
            path: '/wp/v2/tc_combination/124',
            body: { meta: { '_tc_footer_block_id': 3670 } }
        }
    ]
};

fetch('https://kantanhealth.jp/wp-json/batch/v1', {
    method: 'POST',
    headers: {
        'Authorization': auth,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify(batchRequest)
});
```

## 🎯 Best Practices

1. **Use slugs for identification** - More reliable than IDs across environments
2. **Batch operations** - Use batch API for multiple updates
3. **Cache responses** - Implement caching to reduce API calls
4. **Handle errors** - Always check response status and handle errors
5. **Partial updates** - Only send fields you want to change
6. **Rate limiting** - Implement delays between bulk operations

## 🐛 Troubleshooting

### 401 Unauthorized
- Check username and application password
- Ensure user has `edit_posts` capability
- Verify Application Passwords is enabled

### 404 Not Found
- Check CPT slug is `tc_combination`
- Verify permalinks are flushed (Settings → Permalinks → Save)
- Ensure post exists and is published

### Empty Response
- Check if combination exists with that slug
- Verify post status is 'publish' if not authenticated

### Meta Fields Not Updating
- Ensure meta keys start with underscore
- Pass meta fields in `meta` object
- Check field types match (integer vs string)

## 📚 Additional Resources

- [WordPress REST API Handbook](https://developer.wordpress.org/rest-api/)
- [Application Passwords Guide](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/)
- [Custom Post Types REST API](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-rest-api-support-for-custom-content-types/)

## 🎉 Migration from v2.x

If you're migrating from the old virtual pages system:

1. Run the migration tool in WordPress admin
2. Update your API calls to use `/wp/v2/tc_combination` instead of `/tc/v1/combinations`
3. Update authentication to use Application Passwords
4. Meta field names remain the same but now prefixed with underscore

That's it! The new system is simpler, more powerful, and fully compatible with WordPress standards.