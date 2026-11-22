<?php
/**
 * Encrypted cache of the Google OAuth access token.
 *
 * The access token is a bearer credential: anyone who reads it can call the
 * Indexing API and the Search Console API as the service account until it
 * expires. It lives for about an hour, which is long enough for a database dump
 * or a backup to leak a working token, so it is stored the same way as the
 * service-account JSON: an AEAD ciphertext produced by Gim_Token_Crypto with
 * its own additional data, "gim|access_token|v1|gim_access_token".
 *
 * The expiry is a plain integer in a second option: it is not a secret, and a
 * stale value can be rejected without decrypting anything.
 *
 * A value that does not decrypt (rotated salts, changed GIM_KEY_ENCRYPTION_KEY,
 * flipped byte, value moved from another option) counts as "no token": both
 * options are dropped and the caller requests a fresh token from Google. The
 * ciphertext is never logged and never returned to the browser.
 *
 * The plaintext transient `gim_access_token` written by 1.12.0 and earlier is
 * removed once after the update (see gim_access_token_migrate()).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Gim_Access_Token {

    /** Option that holds the ciphertext. */
    private const OPTION = 'gim_access_token';

    /** Option that holds the expiry timestamp (not a secret). */
    private const EXPIRES_OPTION = 'gim_access_token_expires';

    /** Transient used up to 1.12.0; its value was plaintext. */
    private const LEGACY_TRANSIENT = 'gim_access_token';

    /** Marker of the encrypted storage format (autoloaded, not a secret). */
    private const STORAGE_OPTION = 'gim_access_token_schema';

    private const STORAGE_VERSION = 1;

    /** Never cache for less than a minute, never longer than 55 minutes. */
    private const MIN_TTL = 60;
    private const MAX_TTL = 3300;

    /**
     * The cached token in clear text, or '' when there is none.
     *
     * An expired value is rejected without decryption; a value that does not
     * decrypt is discarded so the next call fetches a new token.
     */
    public static function get(): string {
        $expires = self::expires_at();

        if ( $expires <= time() ) {
            return '';
        }

        $stored = trim( (string) get_option( self::OPTION, '' ) );

        if ( '' === $stored ) {
            return '';
        }

        if ( ! Gim_Token_Crypto::is_encrypted( $stored ) ) {
            // A plaintext value from an older version or another writer: it
            // must not be used, and it must not stay in the database.
            self::forget();
            return '';
        }

        $token = Gim_Token_Crypto::decrypt( $stored, Gim_Token_Crypto::AAD_ACCESS_TOKEN );

        if ( '' === $token ) {
            self::forget();
            return '';
        }

        return $token;
    }

    /**
     * Encrypts and stores a token together with its expiry.
     *
     * @param string $token     Valid access token.
     * @param int    $expires_in Lifetime in seconds reported by Google.
     * @return bool True when the ciphertext was written. False means the token
     *              is usable for the current request only (no encryption key).
     */
    public static function store( string $token, int $expires_in = 0 ): bool {
        $token = trim( $token );

        if ( '' === $token || ! Gim_Token_Crypto::is_available() ) {
            return false;
        }

        $cipher = Gim_Token_Crypto::encrypt( $token, Gim_Token_Crypto::AAD_ACCESS_TOKEN );

        if ( '' === $cipher ) {
            return false;
        }

        self::prepare_option( self::OPTION );
        self::prepare_option( self::EXPIRES_OPTION );

        // Autoload is forced off after the write as well: update_option() would
        // otherwise create a missing option as autoloaded.
        update_option( self::OPTION, $cipher, false );
        update_option( self::EXPIRES_OPTION, time() + self::ttl( $expires_in ), false );

        self::set_autoload_off();
        self::mark_storage();

        return true;
    }

    /**
     * Removes the ciphertext, the expiry and the legacy plaintext transient.
     */
    public static function forget(): void {
        delete_option( self::OPTION );
        delete_option( self::EXPIRES_OPTION );

        // Plaintext row written by 1.12.0 and earlier.
        delete_transient( self::LEGACY_TRANSIENT );
    }

    /**
     * Unix timestamp until which the cached token is valid, 0 when absent.
     */
    public static function expires_at(): int {
        return (int) get_option( self::EXPIRES_OPTION, 0 );
    }

    /**
     * Cache lifetime for a token: Google's expires_in minus a safety minute,
     * clamped to 1..55 minutes.
     */
    public static function ttl( int $expires_in = 0 ): int {
        if ( $expires_in <= 0 ) {
            $expires_in = HOUR_IN_SECONDS;
        }

        return max( self::MIN_TTL, min( self::MAX_TTL, $expires_in - MINUTE_IN_SECONDS ) );
    }

    /**
     * Drops the plaintext transient once and marks the new storage format.
     *
     * The marker is autoloaded and holds no secret, so the check costs nothing
     * on the options that WordPress already loads.
     */
    public static function migrate_legacy_transient(): void {
        if ( (int) get_option( self::STORAGE_OPTION, 0 ) >= self::STORAGE_VERSION ) {
            return;
        }

        delete_transient( self::LEGACY_TRANSIENT );

        if ( false === get_option( self::STORAGE_OPTION, false ) ) {
            add_option( self::STORAGE_OPTION, self::STORAGE_VERSION, '', 'yes' );
        } else {
            update_option( self::STORAGE_OPTION, self::STORAGE_VERSION, true );
        }

        self::set_autoload_off();
    }

    /**
     * Whether a non-expired ciphertext is stored (it is not decrypted).
     */
    public static function has_stored(): bool {
        return self::expires_at() > time() && '' !== trim( (string) get_option( self::OPTION, '' ) );
    }

    /**
     * Storage format version: 1 = encrypted option + expiry option.
     */
    public static function storage_version(): int {
        return (int) get_option( self::STORAGE_OPTION, 0 );
    }

    /* ============================================================
     *  Helpers
     * ============================================================ */

    /**
     * Creates an option with autoload disabled when it does not exist yet.
     */
    private static function prepare_option( string $option ): void {
        if ( false === get_option( $option, false ) ) {
            add_option( $option, '', '', false );
        }
    }

    /**
     * Forces autoload off for the token and expiry options (WP 6.4+).
     */
    private static function set_autoload_off(): void {
        if ( ! function_exists( 'wp_set_option_autoload' ) ) {
            return;
        }

        wp_set_option_autoload( self::OPTION, false );
        wp_set_option_autoload( self::EXPIRES_OPTION, false );
    }

    /**
     * Marks the encrypted storage format (the marker itself is autoloaded).
     */
    private static function mark_storage(): void {
        if ( self::storage_version() >= self::STORAGE_VERSION ) {
            return;
        }

        if ( false === get_option( self::STORAGE_OPTION, false ) ) {
            add_option( self::STORAGE_OPTION, self::STORAGE_VERSION, '', 'yes' );
        } else {
            update_option( self::STORAGE_OPTION, self::STORAGE_VERSION, true );
        }
    }
}

/**
 * Removes the plaintext token transient left by 1.12.0 and marks the new
 * storage. Runs on admin_init only: the marker check is free after the first
 * upgrade, and front-end requests must not pay for a migration that is done.
 */
function gim_access_token_migrate(): void {
    Gim_Access_Token::migrate_legacy_transient();
}

/**
 * Registers the one-time cleanup of the legacy plaintext token transient.
 */
function gim_access_token_init(): void {
    add_action( 'admin_init', 'gim_access_token_migrate' );
}
