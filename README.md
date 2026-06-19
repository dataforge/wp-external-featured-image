# WP External Featured Image

WP External Featured Image lets editors use remote images as if they were native featured images. Paste a direct `.jpg`/`.png` URL or a Flickr photo page and the plugin renders that image everywhere the theme calls `the_post_thumbnail()`. When enabled in the settings, it can also add Open Graph and Twitter Card tags so social shares pick up the same image.

## Features

- Toggle between native Media Library thumbnails and external URLs per post.
- Automatic Flickr integration (no authentication required) that resolves the best size for social sharing.
- Optional `<meta>` tags for Open Graph and Twitter when an external image is active, including sensible fallbacks so required tags are never blank.
- Works with any theme that relies on `has_post_thumbnail()` / `the_post_thumbnail()` (including Twenty Twenty-Five).
- Graceful error handling with inline editor notices and cached Flickr lookups to avoid repeat API calls.

## Installation

1. Copy the plugin folder into your WordPress `wp-content/plugins/` directory.
2. Activate **WP External Featured Image** from the Plugins screen.
3. Navigate to **Settings → External Featured Image** and enter your Flickr API key (required to resolve Flickr page URLs). Configure the default size preference, cache duration, and the Social Sharing options (toggle Open Graph/Twitter tags and provide a Facebook App ID if needed).

## Usage

1. Edit a post and open the **Featured Image Source** panel in the document settings sidebar.
2. Select **External** and paste either:
   - A direct HTTPS image URL ending in `.jpg`, `.jpeg`, `.png`, `.webp`, or `.avif`, or
   - A direct HTTPS URL with no file extension (CDN/image-proxy URLs such as `https://cdn.example.com/image?id=123`) — only when explicitly enabled via the `xefi_allow_extensionless_image_urls` filter (rejected by default), or
   - A Flickr photo page URL (e.g. `https://www.flickr.com/photos/user/1234567890/`).
3. Save or update the post. The plugin resolves Flickr URLs to the best available image size (preferring ≥1200px landscape when possible) and caches the result.
4. On the front end, the external image is output wherever the theme requests the featured image. If you set a native featured image from the Media Library, it automatically overrides the external URL.

When enabled, the plugin also injects Open Graph and Twitter Card tags for external images so social platforms share the correct thumbnail. SEO plugins can disable this behaviour via the `xefi_og_enabled` filter or simply leave the checkbox disabled if another tool manages the tags.

## Filters

- `xefi_should_override_thumbnail( $allow, $post_id )` — Return `false` to prevent external thumbnails for a post.
- `xefi_resolve_flickr_sizes( $url, $sizes, $context )` — Override the selected Flickr size URL.
- `xefi_thumbnail_img_attrs( $attrs, $post_id )` — Modify attributes on the generated `<img>` tag.
- `xefi_thumbnail_sizes_attr( $sizes, $post_id, $size )` — Override the `sizes` attribute used with the Flickr `srcset`.
- `xefi_og_enabled( $enabled, $post_id )` — Disable Open Graph/Twitter tag output.
- `xefi_og_site_name( $site_name )` — Modify the generated `og:site_name` value.
- `xefi_fb_app_id( $app_id )` — Filter the Facebook App ID used in Open Graph tags.
- `xefi_cache_ttl( $seconds, $photo_id )` — Adjust Flickr cache duration globally.

## Limitations

- Flickr short URLs (`https://flic.kr/p/...`) are not supported — paste the full `https://www.flickr.com/photos/<user>/<id>/` URL instead.
- Extensionless image URLs (e.g. `https://cdn.example.com/image?id=123`) are rejected by default to avoid treating HTML pages as images; sites that serve images this way can allow them by returning `true` from the `xefi_allow_extensionless_image_urls` filter.

## Requirements

- WordPress 6.2 or later.
- PHP 8.0 or later.
- A Flickr API key to resolve Flickr page URLs.

## Development

All plugin source lives in this repository. The editor UI is written in vanilla JavaScript and does not require a build step.

## Updates

The plugin self-updates from GitHub Releases using the `Update URI` header and WordPress's `update_plugins_github.com` filter — no third-party libraries (see `includes/class-xefi-updater.php`). WordPress checks for updates automatically; you can also force a check with the **Check for Updates** button on the settings page or the matching link on the Plugins screen.

## Releasing

Releases are automated via GitHub Actions (publish-on-tag). To cut a new version:

1. Bump the `Version:` header in `wp-external-featured-image.php`. This is the **single source of truth** — both the plugin and the updater read the version from it, so the tag below must match.
2. Commit and push to `main`. (Pushing commits does **not** create a release.)
3. Tag and push the tag:

   ```bash
   git tag -a vX.Y.Z -m "Release vX.Y.Z"
   git push origin vX.Y.Z
   ```

Pushing the `vX.Y.Z` tag triggers [`.github/workflows/release.yml`](.github/workflows/release.yml), which lints the PHP, builds `wp-external-featured-image.zip` with [`build_plugin.py`](build_plugin.py), and publishes a GitHub Release with the zip attached. The workflow **fails the release if the tag does not match the `Version:` header**, preventing the "update available" loop that a missing header bump would otherwise cause.

> The release zip must contain a single top-level folder named `wp-external-featured-image/` with forward-slash paths. `build_plugin.py` guarantees this — never build the zip with PowerShell's `Compress-Archive`, which writes backslash paths that break extraction on Linux servers.
