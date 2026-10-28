# SB FB Profile Importer

A WordPress plugin that connects a Facebook Page through OAuth and imports its posts. Developed by [Software Brewery](https://softwarebrewery.net).

## Requirements

- WordPress 6.0+
- PHP 8.0+
- A Facebook App (developers.facebook.com)

## Installation

1. Activate the plugin in WordPress
2. Open **SB FB Importer** in the admin menu

## Facebook App setup

### Step 1: Create a Facebook App

1. Go to [Facebook Developers](https://developers.facebook.com/)
2. Create a new app of type **Business**
3. Add the **Facebook Login for Business** product

### Step 2: Configure OAuth

1. In App Settings → Basic:
   - Copy the **App ID** and **App Secret**

2. In Facebook Login → Settings:
   - Add this **Valid OAuth Redirect URI**:
     ```
     https://your-site.com/wp-admin/admin.php?page=sb-fb-profile-importer
     ```

### Step 3: Connect in WordPress

1. Enter the App ID and App Secret in the plugin
2. Click **Connect with Facebook**
3. Sign in and grant the requested permissions
4. Select a Facebook Page
5. Posts then appear in the admin screen

## Permissions

The plugin requests:

- `pages_show_list` — list the Pages you manage
- `pages_read_engagement` — read posts from the selected Page

## Security

- OAuth `state` parameter for CSRF protection
- Nonces on every form
- Sanitized input and escaped output
- Long-lived tokens for ongoing access

## API endpoints

The plugin calls:

- `GET /me/accounts` — list of Pages
- `GET /{page-id}/posts` — posts published by the selected Page

## Version

2.8.1 — URLs in imported Facebook posts are stored as readable links in the WordPress post.
