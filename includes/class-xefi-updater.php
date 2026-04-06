<?php
/**
 * GitHub-based plugin updater for WP External Featured Image.
 */

namespace XEFI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Updater {

    const GITHUB_REPO = 'dataforge/wp-external-featured-image';
    const SLUG        = 'wp-external-featured-image';
    const CACHE_KEY   = 'xefi_github_release';
    const CACHE_TTL   = 12 * HOUR_IN_SECONDS;

    public static function init() {
        add_filter( 'update_plugins_github.com', [ __CLASS__, 'check_update' ], 10, 4 );
        add_filter( 'upgrader_install_package_result', [ __CLASS__, 'fix_directory' ], 10, 2 );
        add_filter( 'plugins_api', [ __CLASS__, 'plugin_info' ], 10, 3 );
        add_action( 'admin_post_xefi_check_updates', [ __CLASS__, 'handle_check_updates' ] );
    }

    public static function get_check_updates_url(): string {
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=xefi_check_updates' ),
            'xefi_check_updates'
        );
    }

    public static function check_update( $update, $plugin_data, $plugin_file, $locales ) {
        if ( plugin_basename( XEFI_PLUGIN_FILE ) !== $plugin_file ) {
            return $update;
        }

        $release = self::fetch_latest_release();
        if ( ! $release ) {
            return $update;
        }

        $remote_version = (string) preg_replace( '/^v/', '', (string) $release->tag_name );

        if ( version_compare( XEFI_PLUGIN_VERSION, $remote_version, '>=' ) ) {
            return $update;
        }

        return [
            'slug'    => self::SLUG,
            'version' => $remote_version,
            'url'     => $release->html_url,
            'package' => self::get_asset_url( $release ),
        ];
    }

    public static function fix_directory( $result, $options ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( ! isset( $options['plugin'] ) || plugin_basename( XEFI_PLUGIN_FILE ) !== $options['plugin'] ) {
            return $result;
        }

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! WP_Filesystem() ) {
            return $result;
        }

        global $wp_filesystem;

        $expected_dir = trailingslashit( WP_PLUGIN_DIR ) . self::SLUG;
        $actual_dir   = isset( $result['destination'] ) ? rtrim( $result['destination'], '/' ) : '';

        if ( $actual_dir === $expected_dir ) {
            return $result;
        }

        // Some WP_Filesystem transports don't honor move()'s overwrite flag.
        // Move any pre-existing destination to a backup path first so that, if
        // the new move fails, we can restore the previous install rather than
        // leaving the plugin missing.
        $backup_dir = '';
        if ( $wp_filesystem->exists( $expected_dir ) ) {
            $backup_dir = $expected_dir . '.bak-' . time();
            if ( ! $wp_filesystem->move( $expected_dir, $backup_dir, true ) ) {
                return $result;
            }
        }

        if ( $wp_filesystem->move( $actual_dir, $expected_dir, true ) ) {
            $result['destination']        = $expected_dir;
            $result['destination_name']   = self::SLUG;
            $result['remote_destination'] = $expected_dir;

            if ( '' !== $backup_dir && $wp_filesystem->exists( $backup_dir ) ) {
                $wp_filesystem->delete( $backup_dir, true );
            }
        } elseif ( '' !== $backup_dir ) {
            // New move failed — restore the previous install.
            $wp_filesystem->move( $backup_dir, $expected_dir, true );
        }

        return $result;
    }

    public static function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
            return $result;
        }

        $release = self::fetch_latest_release();
        if ( ! $release ) {
            return $result;
        }

        $remote_version = (string) preg_replace( '/^v/', '', (string) $release->tag_name );

        $info                = new \stdClass();
        $info->name          = 'WP External Featured Image';
        $info->slug          = self::SLUG;
        $info->version       = $remote_version;
        $info->author        = '<a href="https://github.com/dataforge">Dataforge</a>';
        $info->homepage      = 'https://github.com/' . self::GITHUB_REPO;
        $info->requires      = '6.2';
        $info->requires_php  = '8.0';
        $info->download_link = self::get_asset_url( $release );
        $info->sections      = [
            'description' => 'Use external or Flickr-hosted images as featured images, complete with social meta tags.',
            'changelog'   => nl2br( esc_html( $release->body ?? '' ) ),
        ];

        return $info;
    }

    public static function handle_check_updates() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'xefi_check_updates' );

        delete_transient( self::CACHE_KEY );
        wp_clean_plugins_cache( true );
        wp_update_plugins();

        wp_safe_redirect( add_query_arg(
            [ 'update_check' => '1' ],
            admin_url( 'options-general.php?page=xefi-settings' )
        ) );
        exit;
    }

    private static function get_asset_url( $release ) {
        if ( ! empty( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( '.zip' === substr( $asset->name, -4 ) ) {
                    return $asset->browser_download_url;
                }
            }
        }
        return $release->zipball_url;
    }

    private static function fetch_latest_release() {
        $force = ! empty( $_GET['force-check'] ) || ( defined( 'DOING_CRON' ) && DOING_CRON ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( false !== $cached ) {
                if ( is_array( $cached ) && ! empty( $cached['__xefi_error'] ) ) {
                    return false;
                }
                return $cached;
            }
        }

        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

        $response = wp_remote_get( $url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            set_transient( self::CACHE_KEY, [ '__xefi_error' => true ], 5 * MINUTE_IN_SECONDS );
            return false;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ) );
        if ( ! $release || empty( $release->tag_name ) ) {
            set_transient( self::CACHE_KEY, [ '__xefi_error' => true ], 5 * MINUTE_IN_SECONDS );
            return false;
        }

        $slim               = new \stdClass();
        $slim->tag_name     = $release->tag_name;
        $slim->html_url     = $release->html_url ?? '';
        $slim->body         = $release->body ?? '';
        $slim->zipball_url  = $release->zipball_url ?? '';
        $slim->assets       = [];
        if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                $a                       = new \stdClass();
                $a->name                 = $asset->name ?? '';
                $a->browser_download_url = $asset->browser_download_url ?? '';
                $slim->assets[]          = $a;
            }
        }

        set_transient( self::CACHE_KEY, $slim, self::CACHE_TTL );

        return $slim;
    }
}
