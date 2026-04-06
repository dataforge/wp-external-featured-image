<?php
/**
 * Flickr resolver utilities for WP External Featured Image.
 *
 * @package WP_External_Featured_Image
 */

namespace XEFI;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles resolving Flickr photo page URLs to direct image URLs.
 */
class Flickr_Resolver {
    /**
     * Singleton instance.
     *
     * @var Flickr_Resolver|null
     */
    protected static $instance = null;

    /**
     * Get singleton instance.
     */
    public static function instance(): Flickr_Resolver {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Extract the Flickr photo ID from a page URL.
     *
     * @param string $url Flickr page URL.
     * @return string|null The extracted photo ID or null.
     */
    public function extract_photo_id( string $url ): ?string {
        $pattern = '#^https://(?:www\.)?flickr\.com/photos/[^/]+/(\d+)(?:/|$)#i';
        if ( ! preg_match( $pattern, $url, $matches ) ) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Resolve a Flickr photo page URL to the best matching image URL.
     *
     * @param string $url Flickr page URL.
     * @param array  $settings Plugin settings array.
     * @return array|WP_Error Array with keys url, photo_id or WP_Error on failure.
     */
    public function resolve( string $url, array $settings ) {
        $photo_id = $this->extract_photo_id( $url );
        if ( ! $photo_id ) {
            return new WP_Error( 'xefi_invalid_flickr_url', __( 'Unable to determine Flickr photo ID from URL.', 'wp-external-featured-image' ) );
        }

        $preference = $settings['size_preference'] ?? 'optimize_social';
        $cache_ttl  = absint( $settings['cache_ttl'] ?? DAY_IN_SECONDS );

        /**
         * Filters the Flickr cache TTL before it is applied.
         *
         * @param int    $ttl      Cache TTL in seconds.
         * @param string $photo_id Flickr photo ID.
         */
        $cache_ttl = (int) apply_filters( 'xefi_cache_ttl', $cache_ttl, $photo_id );
        if ( $cache_ttl <= 0 ) {
            $cache_ttl = DAY_IN_SECONDS;
        }

        $api_key = $settings['flickr_api_key'] ?? '';
        // The 8-char API key fingerprint busts the cache when the key is rotated;
        // old transients simply expire on their own TTL.
        $cache_key = 'xefi_flickr_' . md5( $photo_id . '|' . $preference . '|' . substr( wp_hash( $api_key ), 0, 8 ) );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['url'] ) ) {
            return [
                'url'      => $cached['url'],
                'sizes'    => isset( $cached['sizes'] ) && is_array( $cached['sizes'] ) ? $cached['sizes'] : [],
                'photo_id' => $photo_id,
                'from'     => 'cache',
            ];
        }

        if ( empty( $api_key ) ) {
            return new WP_Error( 'xefi_missing_api_key', __( 'Flickr API key is not configured.', 'wp-external-featured-image' ) );
        }

        $response = wp_remote_get(
            add_query_arg(
                [
                    'method'         => 'flickr.photos.getSizes',
                    'api_key'        => $api_key,
                    'photo_id'       => $photo_id,
                    'format'         => 'json',
                    'nojsoncallback' => '1',
                ],
                'https://www.flickr.com/services/rest/'
            ),
            [
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'xefi_flickr_http_error', $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            /* translators: %d: HTTP response code returned by Flickr */
            return new WP_Error( 'xefi_flickr_http_error', sprintf( __( 'Unexpected Flickr response code: %d', 'wp-external-featured-image' ), $code ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( empty( $data['sizes']['size'] ) ) {
            if ( ! empty( $data['message'] ) ) {
                return new WP_Error( 'xefi_flickr_api_error', $data['message'] );
            }

            return new WP_Error( 'xefi_flickr_api_error', __( 'Unexpected Flickr API response.', 'wp-external-featured-image' ) );
        }

        $sizes = $data['sizes']['size'];
        $url   = $this->choose_best_size( $sizes, $preference );
        if ( empty( $url ) ) {
            return new WP_Error( 'xefi_no_suitable_size', __( 'Unable to determine a suitable Flickr image size.', 'wp-external-featured-image' ) );
        }

        $context = [
            'photo_id'   => $photo_id,
            'preference' => $preference,
        ];

        /**
         * Allow overriding the resolved Flickr image URL.
         *
         * Note: if a filter returns a URL that is not present in $sizes, the
         * cached slim_sizes table will not contain it, and pick_size_for() will
         * not be able to honor WP image-size keywords for that override.
         *
         * @param string $url     The selected URL.
         * @param array  $sizes   Array of Flickr size data.
         * @param array  $context Contextual data including photo_id and preference.
         */
        $url = apply_filters( 'xefi_resolve_flickr_sizes', $url, $sizes, $context );

        if ( ! $url ) {
            return new WP_Error( 'xefi_no_suitable_size', __( 'Flickr size selection was overridden to an empty value.', 'wp-external-featured-image' ) );
        }

        $slim_sizes = [];
        foreach ( $sizes as $size ) {
            if ( empty( $size['source'] ) ) {
                continue;
            }
            if ( ! isset( $size['media'] ) || 'photo' !== $size['media'] ) {
                continue;
            }
            $slim_sizes[] = [
                'label'  => isset( $size['label'] ) ? (string) $size['label'] : '',
                'width'  => (int) ( $size['width'] ?? 0 ),
                'height' => (int) ( $size['height'] ?? 0 ),
                'source' => (string) $size['source'],
            ];
        }

        set_transient( $cache_key, [ 'url' => $url, 'sizes' => $slim_sizes ], $cache_ttl );

        return [
            'url'      => $url,
            'sizes'    => $slim_sizes,
            'photo_id' => $photo_id,
            'from'     => 'api',
        ];
    }

    /**
     * Pick the best stored size for a requested WP image size keyword.
     *
     * @param array        $sizes    Slim sizes array (label/width/height/source).
     * @param string|int[] $wp_size  WP size keyword or [w,h].
     * @return string|null
     */
    public function pick_size_for( array $sizes, $wp_size ): ?string {
        if ( empty( $sizes ) ) {
            return null;
        }

        $target_w = 0;
        if ( is_array( $wp_size ) ) {
            $target_w = (int) ( $wp_size[0] ?? 0 );
        } else {
            switch ( (string) $wp_size ) {
                case 'thumbnail':    $target_w = 150;  break;
                case 'medium':       $target_w = 300;  break;
                case 'medium_large': $target_w = 768;  break;
                case 'large':        $target_w = 1024; break;
                case 'full':
                default:             $target_w = 0;    break;
            }
        }

        if ( $target_w <= 0 ) {
            // Want the largest.
            usort( $sizes, static function ( $a, $b ) { return (int) $b['width'] <=> (int) $a['width']; } );
            return $sizes[0]['source'] ?? null;
        }

        // Smallest size with width >= target; otherwise the largest available.
        $eligible = array_filter( $sizes, static function ( $s ) use ( $target_w ) {
            return (int) $s['width'] >= $target_w;
        } );

        if ( ! empty( $eligible ) ) {
            usort( $eligible, static function ( $a, $b ) { return (int) $a['width'] <=> (int) $b['width']; } );
            return $eligible[0]['source'];
        }

        usort( $sizes, static function ( $a, $b ) { return (int) $b['width'] <=> (int) $a['width']; } );
        return $sizes[0]['source'] ?? null;
    }

    /**
     * Choose the best Flickr size based on the selection rules.
     *
     * @param array  $sizes      Sizes array from the API.
     * @param string $preference Preference key.
     * @return string|null
     */
    protected function choose_best_size( array $sizes, string $preference ): ?string {
        $candidates = array_filter(
            $sizes,
            static function ( $size ) {
                if ( ! isset( $size['media'] ) || 'photo' !== $size['media'] ) {
                    return false;
                }

                return ! empty( $size['source'] );
            }
        );

        if ( 'largest_available' === $preference ) {
            usort(
                $candidates,
                static function ( $a, $b ) {
                    $aw = (int) ( $a['width'] ?? 0 );
                    $bw = (int) ( $b['width'] ?? 0 );
                    if ( $aw === $bw ) {
                        $ah = (int) ( $a['height'] ?? 0 );
                        $bh = (int) ( $b['height'] ?? 0 );
                        return $bh <=> $ah;
                    }

                    return $bw <=> $aw;
                }
            );

            return $candidates[0]['source'] ?? null;
        }

        if ( empty( $candidates ) ) {
            return null;
        }

        usort(
            $candidates,
            static function ( $a, $b ) {
                $aw = (int) ( $a['width'] ?? 0 );
                $bw = (int) ( $b['width'] ?? 0 );
                $ah = (int) ( $a['height'] ?? 0 );
                $bh = (int) ( $b['height'] ?? 0 );

                $a_pref = ( $aw >= 1200 && $aw >= $ah ) ? 0 : 1;
                $b_pref = ( $bw >= 1200 && $bw >= $bh ) ? 0 : 1;

                if ( $a_pref !== $b_pref ) {
                    return $a_pref <=> $b_pref;
                }

                if ( $aw !== $bw ) {
                    return $bw <=> $aw;
                }

                return $bh <=> $ah;
            }
        );

        return $candidates[0]['source'] ?? null;
    }
}
