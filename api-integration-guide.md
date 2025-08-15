  🔌 API Integration Guide for Your External App

  Step 1: Generate Your API Key

  1. Log into WordPress admin
  2. Navigate to Tax Combinations → Settings
  3. Scroll to REST API Settings
  4. Click Generate API Key
  5. Copy and save the generated key securely

  Step 2: API Endpoint Information

  Base URL: https://kantanhealth.jp/wp-json/tc/v1/

  Authentication: Include your API key in every request header:
  X-TC-API-Key: YOUR_API_KEY_HERE

  Step 3: Available Operations

  Get Combination by URL Slug

  // Example: Get the "English Dentistry in Shibuya" page data
  fetch('https://kantanhealth.jp/wp-json/tc/v1/combinations/slug/english-dentistry-in-shibu
  ya', {
      headers: {
          'X-TC-API-Key': 'YOUR_API_KEY'
      }
  })
  .then(response => response.json())
  .then(data => console.log(data));

  Update Single Combination

  // Update SEO content for a specific combination
  fetch('https://kantanhealth.jp/wp-json/tc/v1/combinations/123', {  // Replace 123 with 
  actual ID
      method: 'PUT',
      headers: {
          'Content-Type': 'application/json',
          'X-TC-API-Key': 'YOUR_API_KEY'
      },
      body: JSON.stringify({
          custom_title: 'English Dentistry in Shibuya',
          custom_description: 'Your AI-generated description here...',
          meta_title: 'English-Speaking Dentists in Shibuya | Find English-Friendly 
  Healthcare',
          meta_description: 'Find Japanese dentists in Shibuya ranked by English 
  communication ability. Compare reviews and book appointments with English-friendly dental
   clinics.',
          custom_content: '<p>Additional HTML content if needed</p>'
      })
  })
  .then(response => response.json())
  .then(data => console.log('Updated:', data));

  Bulk Update Multiple Combinations

  // Update multiple combinations in one request
  fetch('https://kantanhealth.jp/wp-json/tc/v1/combinations/bulk', {
      method: 'POST',
      headers: {
          'Content-Type': 'application/json',
          'X-TC-API-Key': 'YOUR_API_KEY'
      },
      body: JSON.stringify({
          combinations: [
              {
                  slug: 'english-dentistry-in-shibuya',
                  custom_description: 'Updated description for Shibuya dentists...',
                  meta_description: 'SEO description for Shibuya...'
              },
              {
                  slug: 'english-cardiology-in-minato',
                  custom_description: 'Updated description for Minato cardiologists...',
                  meta_description: 'SEO description for Minato...'
              }
          ]
      })
  })
  .then(response => response.json())
  .then(data => console.log('Bulk update results:', data));

  Step 4: Python Example (for your analysis app)

  import requests
  import json

  # Configuration
  API_KEY = 'YOUR_API_KEY'
  BASE_URL = 'https://kantanhealth.jp/wp-json/tc/v1'

  headers = {
      'X-TC-API-Key': API_KEY,
      'Content-Type': 'application/json'
  }

  # Get combination data
  def get_combination(slug):
      response = requests.get(
          f'{BASE_URL}/combinations/slug/{slug}',
          headers=headers
      )
      return response.json()

  # Update combination with AI-generated content
  def update_combination(combo_id, data):
      response = requests.put(
          f'{BASE_URL}/combinations/{combo_id}',
          headers=headers,
          json=data
      )
      return response.json()

  # Example: Update Shibuya dentistry page
  combination = get_combination('english-dentistry-in-shibuya')
  combo_id = combination['data']['id']

  update_data = {
      'custom_description': 'Your AI-generated comprehensive description...',
      'meta_description': 'AI-optimized SEO description (max 160 chars)...',
      'custom_content': '<h2>Top English-Speaking Dentists</h2><p>Detailed content...</p>'
  }

  result = update_combination(combo_id, update_data)
  print(f"Updated combination {combo_id}: {result['success']}")

  Step 5: Fields You Can Update

  | Field              | Description           | Max Length | Example
                         |
  |--------------------|-----------------------|------------|------------------------------
  -----------------------|
  | custom_title       | Page H1 title         | 100 chars  | "English Dentistry in
  Shibuya"                      |
  | custom_description | Short description     | 500 chars  | "Find the best
  English-speaking dentists..."        |
  | meta_title         | SEO title tag         | 60 chars   | "English Dentists in Shibuya
  | Healthcare"          |
  | meta_description   | SEO meta description  | 160 chars  | "Top-rated English-speaking
  dentists in Shibuya..." |
  | custom_content     | Full HTML content     | Unlimited  | Full article/content HTML
                         |
  | content_block_id   | Blocksy block ID      | Number     | 123 (optional)
                         |
  | robots_index       | Allow indexing        | Boolean    | true/false
                         |
  | robots_follow      | Allow following links | Boolean    | true/false
                         |

  Step 6: Auto-Creation Feature

  If you try to update a combination that doesn't exist yet, it will be automatically
  created. For example:
  // This will create the combination if it doesn't exist
  fetch('https://kantanhealth.jp/wp-json/tc/v1/combinations/slug/english-orthopedics-in-rop
  pongi', {
      method: 'PUT',
      headers: {
          'Content-Type': 'application/json',
          'X-TC-API-Key': 'YOUR_API_KEY'
      },
      body: JSON.stringify({
          custom_title: 'English Orthopedics in Roppongi',
          custom_description: 'New combination description...'
      })
  })

  Step 7: Error Handling

  fetch('https://kantanhealth.jp/wp-json/tc/v1/combinations/1', {
      method: 'PUT',
      headers: {
          'Content-Type': 'application/json',
          'X-TC-API-Key': 'YOUR_API_KEY'
      },
      body: JSON.stringify({...})
  })
  .then(response => {
      if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
      }
      return response.json();
  })
  .then(data => {
      if (data.success) {
          console.log('Success:', data.data);
      } else {
          console.error('API Error:', data.message);
      }
  })
  .catch(error => {
      console.error('Network Error:', error);
  });

  📝 Quick Testing Script

  Save this as test-api.js and run with Node.js:
  const API_KEY = 'YOUR_API_KEY';
  const BASE_URL = 'https://kantanhealth.jp/wp-json/tc/v1';

  // Test connection
  fetch(`${BASE_URL}/combinations`, {
      headers: { 'X-TC-API-Key': API_KEY }
  })
  .then(res => res.json())
  .then(data => {
      console.log(`Found ${data.data.length} combinations`);
      console.log('First combination:', data.data[0]);
  })
  .catch(err => console.error('Error:', err));