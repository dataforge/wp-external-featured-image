<?php
/**
 * Encryption helper for sensitive data in WP External Featured Image.
 *
 * @package WP_External_Featured_Image
 */

namespace XEFI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles encryption and decryption of sensitive data.
 */
class Encryption {
    /**
     * Encrypt a string.
     *
     * @param string $value The value to encrypt.
     * @return string The encrypted value.
     */
    public static function encrypt( string $value ): string {
        if ( '' === $value ) {
            return '';
        }

        $key = self::get_key();
        try {
            $iv = random_bytes( 12 );
        } catch ( \Exception $e ) {
            throw new \RuntimeException( 'XEFI encryption failed: unable to generate IV.' );
        }

        $tag       = '';
        $encrypted = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

        if ( false === $encrypted ) {
            throw new \RuntimeException( 'XEFI encryption failed: openssl_encrypt returned false.' );
        }

        return 'gcm:' . base64_encode( $iv . $tag . $encrypted );
    }

    /**
     * Decrypt a string.
     *
     * @param string $value The encrypted value.
     * @return string The decrypted value.
     */
    public static function decrypt( string $value ): string {
        if ( '' === $value ) {
            return '';
        }

        $keys = self::get_decrypt_keys();

        if ( str_starts_with( $value, 'gcm:' ) ) {
            $data = base64_decode( substr( $value, 4 ), true );
            if ( false === $data || strlen( $data ) < 28 ) {
                return '';
            }
            $iv         = substr( $data, 0, 12 );
            $tag        = substr( $data, 12, 16 );
            $ciphertext = substr( $data, 28 );

            foreach ( $keys as $key ) {
                $decrypted = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
                if ( false !== $decrypted ) {
                    return $decrypted;
                }
            }

            return '';
        }

        $key = $keys[0];

        // Legacy CBC payload — decrypt once so callers can re-save and upgrade.
        $data = base64_decode( $value, true );
        if ( false === $data ) {
            return '';
        }

        $iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
        if ( strlen( $data ) < $iv_length ) {
            return '';
        }

        $iv        = substr( $data, 0, $iv_length );
        $encrypted = substr( $data, $iv_length );
        $decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, 0, $iv );

        return false === $decrypted ? '' : $decrypted;
    }

    /**
     * Obscure a stored value for display.
     *
     * Returns a fixed-width "xxxxxxxx" prefix followed by the last 4 chars of
     * the *stored* value. Callers always pass the encrypted blob (never the
     * plaintext key), so the visible tail is ciphertext, not key material —
     * the fixed prefix also hides the real length.
     *
     * @param string $value The value to obscure.
     * @return string The obscured value.
     */
    public static function obscure( string $value ): string {
        if ( '' === $value ) {
            return '';
        }

        $length = strlen( $value );
        if ( $length <= 4 ) {
            return str_repeat( 'x', $length );
        }

        // Fixed-length mask so the rendered value does not leak the real key length.
        return 'xxxxxxxx' . substr( $value, -4 );
    }

    /**
     * Get the encryption key.
     *
     * @return string
     */
    protected static function get_key(): string {
        $keys = self::get_decrypt_keys();
        return $keys[0];
    }

    /**
     * Return all candidate keys to attempt during decryption, primary first.
     *
     * Allows decryption to survive AUTH_KEY (salt) rotation by falling back to
     * the option-based key.
     *
     * @return string[]
     */
    protected static function get_decrypt_keys(): array {
        $keys = [];

        if ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ) {
            $keys[] = hash( 'sha256', AUTH_KEY, true );
        }

        // Only fall back to (and lazily create) the option-based key when AUTH_KEY is
        // unavailable, OR when an existing stored key is present from a prior install
        // and may still be needed to decrypt legacy ciphertexts.
        $stored = get_option( 'xefi_encryption_key', '' );
        if ( is_string( $stored ) && '' !== $stored ) {
            $keys[] = hash( 'sha256', $stored, true );
        } elseif ( empty( $keys ) ) {
            try {
                $stored = base64_encode( random_bytes( 32 ) );
            } catch ( \Exception $e ) {
                $stored = base64_encode( hash( 'sha256', wp_generate_password( 64, true, true ), true ) );
            }
            update_option( 'xefi_encryption_key', $stored, false );
            $keys[] = hash( 'sha256', $stored, true );
        }

        return $keys;
    }
}
