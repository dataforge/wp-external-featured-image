<?php
/**
 * Plugin Name: WP External Featured Image
 * Plugin URI: https://github.com/dataforge/wp-external-featured-image
 * Description: Use external or Flickr-hosted images as featured images, complete with social meta tags.
 * Version: 1.1.0
 * Author: Dataforge
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Update URI: https://github.com/dataforge/wp-external-featured-image
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'XEFI_PLUGIN_FILE' ) ) {
    define( 'XEFI_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'XEFI_PLUGIN_DIR' ) ) {
    define( 'XEFI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'XEFI_PLUGIN_URL' ) ) {
    define( 'XEFI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'XEFI_PLUGIN_VERSION' ) ) {
    if ( ! function_exists( 'get_file_data' ) ) {
        require_once ABSPATH . 'wp-includes/functions.php';
    }
    $xefi_header = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
    define( 'XEFI_PLUGIN_VERSION', $xefi_header['Version'] ?: '0.0.0' );
}

require_once XEFI_PLUGIN_DIR . 'includes/class-xefi-encryption.php';
require_once XEFI_PLUGIN_DIR . 'includes/class-xefi-flickr-resolver.php';
require_once XEFI_PLUGIN_DIR . 'includes/class-xefi-plugin.php';
require_once XEFI_PLUGIN_DIR . 'includes/class-xefi-updater.php';

\XEFI\Plugin::instance()->init();
\XEFI\Updater::init();
