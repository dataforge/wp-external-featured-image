<?php
/**
 * Plugin Name: WP External Featured Image
 * Plugin URI: https://github.com/dataforge/wp-external-featured-image
 * Description: Use external or Flickr-hosted images as featured images, complete with social meta tags.
 * Version: 1.0.1
 * Author: Dataforge
 * Text Domain: wp-external-featured-image
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Update URI: https://github.com/dataforge/wp-external-featured-image
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'XEFI_PLUGIN_VERSION' ) ) {
    define( 'XEFI_PLUGIN_VERSION', '1.0.1' );
}

define( 'XEFI_PLUGIN_FILE', __FILE__ );
define( 'XEFI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'XEFI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once XEFI_PLUGIN_DIR . 'includes/class-xefi-encryption.php';
require_once XEFI_PLUGIN_DIR . 'includes/class-xefi-flickr-resolver.php';
require_once XEFI_PLUGIN_DIR . 'includes/class-xefi-plugin.php';
require_once XEFI_PLUGIN_DIR . 'includes/class-xefi-updater.php';

\XEFI\Plugin::instance()->init();
XEFI_Updater::init();
