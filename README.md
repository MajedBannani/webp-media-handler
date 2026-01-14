# Image Optimization & WebP Migration

A WordPress plugin focused on Image Optimization & WebP Migration with a modular, switch-based control system and explicit action buttons.

## Features

### 1. Disable WordPress Default Image Sizes (Toggle)
When enabled:
- Disables all intermediate image sizes (thumbnail, medium, large, etc.)
- Disables big image scaling
- Disables medium_large explicitly
- Disables WooCommerce image sizes if WooCommerce is active
- Removes image size choices from media library

When disabled:
- WordPress will generate default image sizes again for new uploads

### 2. Auto Convert Newly Uploaded Images to WebP (Toggle)
When enabled:
- Automatically converts newly uploaded JPEG/PNG images to WebP format
- Replaces the original file
- Updates upload metadata correctly
- Only affects new uploads, never touches existing media
- Uses GD functions only
- Skips if WebP is not supported by your server

### 3. Convert Existing Media Library Images to WebP (Action Button)
- One-time action triggered only by admin button
- Converts existing JPEG/PNG attachments to WebP
- Replaces original files
- Updates attachment metadata and MIME type
- Shows progress and completion message
- Non-blocking to admin UI
- **Cannot be undone automatically**

### 4. Replace Existing Image URLs with WebP (Action Button)
- One-time action triggered only by admin button
- Replaces JPG/PNG URLs with .webp URLs in:
  - `post_content` (all post types)
  - `theme_mods` (IDs and URLs)
  - `wp_options` (strings, arrays, serialized data)
- Only replaces if WebP file exists
- Never replaces external URLs
- Handles serialized and JSON data safely
- **Cannot be undone automatically**

## Design Principles

- **No URL-based triggers** - All actions require explicit admin interaction
- **No query-string execution** - No automatic execution via URL parameters
- **No REST API** - Uses standard WordPress AJAX
- **No automatic destructive actions** - All heavy operations require explicit button clicks
- **Clear separation** - Toggles (persistent behavior) vs Actions (one-time operations)
- **Safe by default** - Transparent, reversible where possible

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- GD library with WebP support (for WebP conversion features)

## Installation

1. Upload the plugin files to `/wp-content/plugins/image-optimization-webp/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to 'Image Optimization' in the admin menu
4. Configure features using the toggle switches
5. Run actions using the action buttons when needed

## Security

- All actions verify `current_user_can('manage_options')`
- All actions use WordPress nonces
- No direct database queries without sanitization
- Defensive coding against nulls, invalid data, and missing files

## License

GPLv2 or later

## Support

For issues, feature requests, or contributions, please use the plugin's support channels.
