=== WebP Media Handler ===
Contributors: majedtalal
Donate link: https://www.paypal.com/ncp/payment/2C3DDKHKMPMLC
Tags: webp, images, media, optimization, performance
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Disable image sizes, convert JPEG/PNG to WebP (new uploads or on-demand), and apply watermarks to images.

== Description ==

WebP Media Handler provides explicit control over image optimization and WebP format conversion. The plugin offers toggle-based features for persistent behavior and explicit action buttons for one-time operations.

Some actions on this page modify existing media files or database content. As a precaution, it's recommended to take a full website backup before running one-time actions. All destructive actions are user-triggered only and require explicit admin interaction.

= Features =

* **Disable WordPress Default Image Sizes** - Toggle to disable all intermediate image sizes (thumbnail, medium, large, etc.), big image scaling, and remove image size choices from media library. Also disables WooCommerce image sizes when WooCommerce is active.

* **Auto Convert Newly Uploaded Images to WebP** - Toggle to automatically convert newly uploaded JPEG/PNG images to WebP format. Only affects new uploads, never touches existing media. Requires GD library with WebP support.

* **Convert Existing Media Library Images to WebP** - One-time action button to convert all existing JPEG/PNG attachments to WebP format. Replaces original files and updates attachment metadata. This action cannot be undone automatically.


= Design Principles =

* No URL-based triggers - All actions require explicit admin interaction
* No query-string execution - No automatic execution via URL parameters
* No automatic destructive actions - All heavy operations require explicit button clicks
* Clear separation - Toggles (persistent behavior) vs Actions (one-time operations)
* Safe by default - Transparent, reversible where possible

= Requirements =

* WordPress 6.0 or higher
* PHP 7.4 or higher
* GD library with WebP support (for WebP conversion features)

= Security =

* All actions verify `current_user_can('manage_options')`
* All actions use WordPress nonces
* All database queries use prepared statements
* All user input is sanitized and validated
* Defensive coding against nulls, invalid data, and missing files

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/image-optimization-webp/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to 'Image Optimization' in the admin menu
4. Configure features using the toggle switches
5. Run actions using the action buttons when needed

No additional configuration or external dependencies required.

== Frequently Asked Questions ==

= Does this plugin require WebP support? =

WebP conversion features require the GD library with WebP support on your server. The plugin will detect if WebP is supported and disable conversion features if not available. Disabling image sizes does not require WebP support.

= Are actions reversible? =

The conversion actions cannot be undone automatically. Once images are converted to WebP or URLs are replaced, the original files are replaced. It's recommended to take a full website backup before running one-time actions.

= Does this plugin run automatically? =

No. The plugin does not run any actions automatically. Toggle features affect new uploads only when enabled. One-time actions (converting existing images, replacing URLs) must be explicitly triggered via admin action buttons.

= Is a backup recommended? =

Yes. Some actions modify existing media files or database content. It's recommended to take a full website backup before running one-time actions such as converting existing images or replacing image URLs.

= Does this work with WooCommerce? =

Yes. When the "Disable WordPress Default Image Sizes" feature is enabled, it also disables WooCommerce image sizes if WooCommerce is active.

= Will this affect my existing images? =

No, not automatically. Existing images are only modified when you explicitly click the action buttons to convert them. New uploads can be auto-converted if you enable that toggle.

= Does this plugin use external services? =

No. All image conversion is performed locally using PHP's GD library. No external services, APIs, or third-party tools are required.

== Screenshots ==

1. Plugin settings page with feature toggles and action buttons
2. One-time action buttons with progress feedback
3. Support This Plugin section at bottom of settings page

== Changelog ==

= 1.0.0 =
* Initial release
* Disable WordPress default image sizes feature
* Auto convert newly uploaded images to WebP
* Convert existing media library images to WebP

== Upgrade Notice ==

= 1.0.0 =
Initial release of WebP Media Handler plugin.
