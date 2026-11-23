<?php
/**
 * Encryption of the Google service-account JSON at rest.
 *
 * GENERATED FILE — do not edit by hand.
 * Source: tools/shared/token-crypto/class-token-crypto.php
 * Regenerate: node tools/scripts/sync-crypto.mjs google-indexing-manager
 *
 * The key never lives in the database: it comes from a wp-config.php constant
 * (base64 of 32 raw bytes) or is derived with HKDF-SHA256 from the WordPress
 * salts. Without either, saving is refused (fail closed).
 *
 * Values are AEAD ciphertexts with an algorithm prefix: "v1s:" (sodium
 * xchacha20poly1305-ietf), "v1b:" (sodium secretbox with the additional data
 * prepended) or "v1g:" (openssl aes-256-gcm). The additional data binds the
 * value to this plugin and this option, so a ciphertext copied elsewhere does
 * not decrypt. A fresh nonce/IV is generated for every encryption.
 */



if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Gim_Token_Crypto {

    /** Option that holds the ciphertext. */
    private const OPTION = 'gim_json_key';

    /** Non-secret hint shown next to the mask. */
    private const HINT_OPTION = 'gim_json_key_hint';

    /** Schema version of the storage format. */
    private const SCHEMA_OPTION = 'gim_token_schema';

    /** Transient with the reason of the last failure. */
    private const ERROR_TRANSIENT = 'gim_json_key_error';

    /** wp-config.php constant with the base64 key (preferred). */
    private const KEY_CONSTANT = 'GIM_KEY_ENCRYPTION_KEY';

    /** wp-config.php constant with a plaintext value (wins over the option). */
    private const TOKEN_CONSTANT = '';

    /** Key derivation context; also the base of the additional data. */
    private const INFO = 'gim|json_key|v1';

    /**
     * Additional data of the stored value. Fixed by the contract: a ciphertext
     * cannot be moved to another option or plugin.
     */
    public const AAD_JSON_KEY = 'gim|json_key|v1|gim_json_key';

    /** Additional data of the optional access-token cache (may be unused). */
    public const AAD_ACCESS_TOKEN = 'gim|access_token|v1|gim_access_token';

    /** What is stored: a GitHub token or a service-account JSON document. */
    private const PAYLOAD = 'json';

    /** Hint shape: the last four characters or client_email + saved_at. */
    private const HINT_KIND = 'email';

    /** Mask behaviour of the plugin settings screen. */
    private const MASK_MODE = 'gim';

    /** Whether a plaintext value must look like the expected payload. */
    private const STRICT_PAYLOAD = true;

    /** Whether forget() also removes the schema marker. */
    private const FORGET_SCHEMA = true;

    /** Whether forget() recreates the empty option (options.php needs it). */
    private const FORGET_REPREPARE = false;

    /** Optional logger for the migration line: '' means error_log(). */
    private const LOG_FUNCTION = 'gim_log_info';

    /** One-line migration message without the secret; '' disables logging. */
    private const LOG_MESSAGE = 'JSON-ключ сервисного аккаунта перенесён в зашифрованное хранилище';

    private const SCHEMA    = 1;
    private const KEY_BYTES = 32;
    private const IV_BYTES  = 12;
    private const TAG_BYTES = 16;
    private const CIPHER    = 'aes-256-gcm';

    private const PREFIX_SODIUM_AEAD = 'v1s:';
    private const PREFIX_SODIUM_BOX  = 'v1b:';
    private const PREFIX_OPENSSL     = 'v1g:';

    private const SALT_CONSTANTS = [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_SALT' ];

    /** Per-request cache of the decrypted value. */
    private static ?string $cached = null;

    /** Per-request failure code: no_key, decrypt, format, encrypt. */
    private static string $error = '';

    private static bool $key_computed = false;

    /** @var string|false */
    private static string|false $key_value = false;

    /*
     * ---------------------------------------------------------------------
     *  Hooks
     * ---------------------------------------------------------------------
     */

    /**
     * Registers the write-path guard: a plaintext value written through the
     * Options API is encrypted before it reaches the database.
     */
    public static function register_hooks(): void {
        add_filter( 'pre_update_option_' . self::OPTION, [ __CLASS__, 'filter_incoming_value' ], 10, 2 );
        add_action( 'added_option', [ __CLASS__, 'after_option_written' ], 10, 2 );
        add_action( 'updated_option', [ __CLASS__, 'after_option_written' ], 10, 2 );
    }

    /*
     * ---------------------------------------------------------------------
     *  Availability and key source
     * ---------------------------------------------------------------------
     */

    /**
     * Where the encryption key comes from: "constant", "salts" or "none".
     */
    public static function key_source(): string {
        if ( defined( self::KEY_CONSTANT ) ) {
            return 'constant';
        }

        foreach ( self::SALT_CONSTANTS as $name ) {
            if ( ! defined( $name ) || '' === (string) constant( $name ) ) {
                return 'none';
            }
        }

        return 'salts';
    }

    /**
     * Whether a key and at least one AEAD primitive are available.
     */
    public static function is_available(): bool {
        return '' !== self::key() && self::cipher_available();
    }

    /**
     * Human-readable reason why encryption is unavailable (never a secret).
     */
    public static function key_error(): string {
        if ( 'none' === self::key_source() ) {
            return sprintf(
                /* translators: %s: wp-config.php constant name. */
                __( 'нет ни константы %s, ни солей WordPress', 'google-indexing-manager' ),
                self::KEY_CONSTANT
            );
        }
        if ( '' === self::key() ) {
            return sprintf(
                /* translators: %s: wp-config.php constant name. */
                __( 'константа %s должна быть base64 от 32 байт', 'google-indexing-manager' ),
                self::KEY_CONSTANT
            );
        }
        if ( ! self::cipher_available() ) {
            return __( 'в PHP нет ни sodium, ни openssl с aes-256-gcm', 'google-indexing-manager' );
        }
        return '';
    }

    /**
     * Raw 32-byte key, or an empty string when no usable key exists.
     *
     * A constant that is defined but malformed is a hard failure: deriving
     * from the salts instead would create ciphertext that becomes unreadable
     * the moment the constant is fixed.
     *
     * @return string
     */
    private static function key(): string {
        if ( self::$key_computed ) {
            return false === self::$key_value ? '' : self::$key_value;
        }

        self::$key_computed = true;
        self::$key_value    = false;

        if ( defined( self::KEY_CONSTANT ) ) {
            $decoded = base64_decode( trim( (string) constant( self::KEY_CONSTANT ) ), true );

            if ( is_string( $decoded ) && self::KEY_BYTES === strlen( $decoded ) ) {
                self::$key_value = $decoded;
            }

            return false === self::$key_value ? '' : self::$key_value;
        }

        $material = '';

        foreach ( self::SALT_CONSTANTS as $name ) {
            if ( ! defined( $name ) ) {
                return '';
            }

            $value = (string) constant( $name );

            if ( '' === $value ) {
                return '';
            }

            $material .= ( '' === $material ? '' : '|' ) . $value;
        }

        if ( '' === $material || ! function_exists( 'hash_hkdf' ) ) {
            return '';
        }

        $derived = hash_hkdf( 'sha256', $material, self::KEY_BYTES, self::INFO );

        if ( is_string( $derived ) && self::KEY_BYTES === strlen( $derived ) ) {
            self::$key_value = $derived;
        }

        return false === self::$key_value ? '' : self::$key_value;
    }

    private static function cipher_available(): bool {
        return self::sodium_aead_available() || self::sodium_box_available() || self::openssl_available();
    }

    private static function sodium_aead_available(): bool {
        return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
            && function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' );
    }

    private static function sodium_box_available(): bool {
        return function_exists( 'sodium_crypto_secretbox' )
            && function_exists( 'sodium_crypto_secretbox_open' );
    }

    private static function openssl_available(): bool {
        if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
            return false;
        }

        $methods = openssl_get_cipher_methods();

        return is_array( $methods ) && in_array( self::CIPHER, $methods, true );
    }

    /*
     * ---------------------------------------------------------------------
     *  Encrypt / decrypt
     * ---------------------------------------------------------------------
     */

    /**
     * Encrypts a value.
     *
     * @param string $plaintext       Value to encrypt.
     * @param string $additional_data AEAD additional data; empty means the
     *                                option-bound AAD of this plugin.
     * @return string Prefixed ciphertext, empty string on failure.
     */
    public static function encrypt( string $plaintext, string $additional_data = '' ): string {
        if ( '' === $plaintext ) {
            return '';
        }

        $key = self::key();

        if ( '' === $key ) {
            self::note_error( 'no_key' );
            return '';
        }

        if ( '' === $additional_data ) {
            $additional_data = self::AAD_JSON_KEY;
        }

        if ( self::sodium_aead_available() ) {
            try {
                $nonce_bytes = defined( 'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES' )
                    ? (int) SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
                    : 24;
                $nonce       = random_bytes( $nonce_bytes );
                $cipher      = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, $additional_data, $nonce, $key );

                if ( is_string( $cipher ) && '' !== $cipher ) {
                    return self::PREFIX_SODIUM_AEAD . base64_encode( $nonce . $cipher );
                }
            } catch ( \Throwable $exception ) {
                // Fall through to the next rung of the ladder.
            }
        }

        if ( self::sodium_box_available() ) {
            try {
                $nonce_bytes = defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' )
                    ? (int) SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
                    : 24;
                $nonce       = random_bytes( $nonce_bytes );
                $cipher      = sodium_crypto_secretbox( $additional_data . "\0" . $plaintext, $nonce, $key );

                if ( is_string( $cipher ) && '' !== $cipher ) {
                    return self::PREFIX_SODIUM_BOX . base64_encode( $nonce . $cipher );
                }
            } catch ( \Throwable $exception ) {
                // Fall through to openssl.
            }
        }

        if ( self::openssl_available() ) {
            try {
                $iv = random_bytes( self::IV_BYTES );
            } catch ( \Throwable $exception ) {
                $iv = false;
            }

            if ( is_string( $iv ) ) {
                $tag    = '';
                $cipher = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $additional_data, self::TAG_BYTES );

                if ( is_string( $cipher ) && '' !== $cipher && is_string( $tag ) && self::TAG_BYTES === strlen( $tag ) ) {
                    return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $cipher );
                }
            }
        }

        self::note_error( 'encrypt' );

        return '';
    }

    /**
     * Encrypts a value bound to a specific option name.
     *
     * @return string Prefixed ciphertext, empty string on failure.
     */
    private static function encrypt_for( string $plaintext, string $option_name ): string {
        return self::encrypt( $plaintext, self::aad_for( $option_name ) );
    }

    /**
     * Decrypts a stored value.
     *
     * @param string $stored          "v1?:" prefixed value.
     * @param string $additional_data AEAD additional data; must match encrypt().
     * @return string Clear text, empty string on any failure.
     */
    public static function decrypt( string $stored, string $additional_data = '' ): string {
        $stored = trim( $stored );

        if ( '' === $stored ) {
            return '';
        }

        if ( '' === $additional_data ) {
            $additional_data = self::AAD_JSON_KEY;
        }

        $key = self::key();

        if ( '' === $key ) {
            self::note_error( 'no_key' );
            return '';
        }

        $position = strpos( $stored, ':' );

        if ( false === $position ) {
            self::note_error( 'format' );
            return '';
        }

        $prefix  = substr( $stored, 0, $position + 1 );
        $payload = base64_decode( substr( $stored, $position + 1 ), true );

        if ( ! is_string( $payload ) || '' === $payload ) {
            self::note_error( 'format' );
            return '';
        }

        $plain = '';

        switch ( $prefix ) {
            case self::PREFIX_SODIUM_AEAD:
                $plain = self::decrypt_sodium_aead( $payload, $additional_data, $key );
                break;

            case self::PREFIX_SODIUM_BOX:
                $plain = self::decrypt_sodium_box( $payload, $additional_data, $key );
                break;

            case self::PREFIX_OPENSSL:
                $plain = self::decrypt_openssl( $payload, $additional_data, $key );
                break;

            default:
                self::note_error( 'format' );
                return '';
        }

        if ( ! is_string( $plain ) || '' === $plain ) {
            self::note_error( 'decrypt' );
            return '';
        }

        return $plain;
    }

    /**
     * Decrypts a value bound to a specific option name.
     */
    private static function decrypt_for( string $stored, string $option_name ): string {
        return self::decrypt( $stored, self::aad_for( $option_name ) );
    }

    private static function aad_for( string $option_name ): string {
        return self::INFO . '|' . $option_name;
    }

    private static function decrypt_sodium_aead( string $payload, string $additional_data, string $key ): string {
        if ( ! self::sodium_aead_available() ) {
            return '';
        }

        $nonce_bytes = defined( 'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES' )
            ? (int) SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            : 24;

        if ( strlen( $payload ) <= $nonce_bytes + self::TAG_BYTES ) {
            return '';
        }

        $nonce  = substr( $payload, 0, $nonce_bytes );
        $cipher = substr( $payload, $nonce_bytes );

        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $cipher, $additional_data, $nonce, $key );
        } catch ( \Throwable $exception ) {
            return '';
        }

        return is_string( $plain ) ? $plain : '';
    }

    private static function decrypt_sodium_box( string $payload, string $additional_data, string $key ): string {
        if ( ! self::sodium_box_available() ) {
            return '';
        }

        $nonce_bytes = defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' )
            ? (int) SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            : 24;

        if ( strlen( $payload ) <= $nonce_bytes + self::TAG_BYTES ) {
            return '';
        }

        $nonce  = substr( $payload, 0, $nonce_bytes );
        $cipher = substr( $payload, $nonce_bytes );

        try {
            $plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
        } catch ( \Throwable $exception ) {
            return '';
        }

        if ( ! is_string( $plain ) ) {
            return '';
        }

        $binding = $additional_data . "\0";

        if ( 0 !== strpos( $plain, $binding ) ) {
            return '';
        }

        return substr( $plain, strlen( $binding ) );
    }

    private static function decrypt_openssl( string $payload, string $additional_data, string $key ): string {
        if ( ! self::openssl_available() ) {
            return '';
        }

        if ( strlen( $payload ) <= self::IV_BYTES + self::TAG_BYTES ) {
            return '';
        }

        $iv     = substr( $payload, 0, self::IV_BYTES );
        $tag    = substr( $payload, self::IV_BYTES, self::TAG_BYTES );
        $cipher = substr( $payload, self::IV_BYTES + self::TAG_BYTES );

        $plain = openssl_decrypt( $cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $additional_data );

        return is_string( $plain ) ? $plain : '';
    }

    /*
     * ---------------------------------------------------------------------
     *  Value API
     * ---------------------------------------------------------------------
     */

    /**
     * Whether a value is one of our ciphertexts.
     */
    public static function is_encrypted( string $value ): bool {
        $value = trim( $value );

        foreach ( [ self::PREFIX_SODIUM_AEAD, self::PREFIX_SODIUM_BOX, self::PREFIX_OPENSSL ] as $prefix ) {
            if ( 0 === strpos( $value, $prefix ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The raw stored value, without decrypting it. Never printed anywhere.
     */
    public static function stored_value(): string {
        return trim( (string) get_option( self::OPTION, '' ) );
    }

    /**
     * Whether a value exists: the wp-config constant, a ciphertext or a
     * legacy plaintext value.
     */
    private static function has_value(): bool {
        if ( '' !== self::TOKEN_CONSTANT && defined( self::TOKEN_CONSTANT ) ) {
            if ( '' !== trim( (string) constant( self::TOKEN_CONSTANT ) ) ) {
                return true;
            }
        }

        $stored = self::stored_value();

        if ( '' === $stored ) {
            return false;
        }

        if ( 'json' === self::PAYLOAD ) {
            return self::is_encrypted( $stored ) || self::looks_like_payload( $stored );
        }

        return true;
    }

    /**
     * Whether a value is configured (same as has_token() for token payloads).
     */
    public static function has_key(): bool {
        return self::has_value();
    }

    /**
     * Whether a value is configured (same as has_key() for JSON payloads).
     */
    public static function has_token(): bool {
        return self::has_value();
    }

    /**
     * Whether the stored ciphertext cannot be decrypted with the current key.
     */
    public static function is_unreadable(): bool {
        if ( '' !== self::TOKEN_CONSTANT && defined( self::TOKEN_CONSTANT )
            && '' !== trim( (string) constant( self::TOKEN_CONSTANT ) ) ) {
            return false;
        }

        $stored = self::stored_value();

        if ( '' === $stored || ! self::is_encrypted( $stored ) ) {
            return false;
        }

        if ( '' !== self::decrypt( $stored ) ) {
            return false;
        }

        self::note_error( 'decrypt' );

        return true;
    }

    /**
     * Whether a legacy plaintext value is still stored (no key to migrate it).
     */
    public static function has_plaintext_storage(): bool {
        if ( '' !== self::TOKEN_CONSTANT && defined( self::TOKEN_CONSTANT )
            && '' !== trim( (string) constant( self::TOKEN_CONSTANT ) ) ) {
            return false;
        }

        $stored = self::stored_value();

        return '' !== $stored && ! self::is_encrypted( $stored ) && self::looks_like_payload( $stored );
    }

    /**
     * The non-secret hint stored next to the ciphertext.
     *
     * @return array{client_email:string, saved_at:int}
     */
    public static function hint(): array {
        $hint = get_option( self::HINT_OPTION, [] );

        if ( ! is_array( $hint ) ) {
            $hint = [];
        }

        return [
            'client_email' => isset( $hint['client_email'] ) ? (string) $hint['client_email'] : '',
            'saved_at'     => isset( $hint['saved_at'] ) ? (int) $hint['saved_at'] : 0,
        ];
    }

    /**
     * String hint for the token mask: the last four characters.
     */
    public static function stored_hint(): string {
        if ( '' !== self::TOKEN_CONSTANT && defined( self::TOKEN_CONSTANT )
            && '' !== trim( (string) constant( self::TOKEN_CONSTANT ) ) ) {
            return self::hint_for( trim( (string) constant( self::TOKEN_CONSTANT ) ) );
        }

        $hint = get_option( self::HINT_OPTION, '' );

        if ( is_string( $hint ) && '' !== trim( $hint ) ) {
            return trim( $hint );
        }

        if ( is_array( $hint ) && isset( $hint['client_email'] ) ) {
            return (string) $hint['client_email'];
        }

        if ( 'tail' === self::HINT_KIND ) {
            $value = self::read();
            return '' !== $value ? self::hint_for( $value ) : '';
        }

        return '';
    }

    /**
     * Mask for the settings screen. The value is never decrypted for this.
     */
    public static function mask(): string {
        $bullets = str_repeat( '•', 8 );

        if ( 'json' === self::PAYLOAD || 'gim' === self::MASK_MODE ) {
            return $bullets;
        }

        if ( 'ec' === self::MASK_MODE ) {
            $hint = (string) get_option( self::HINT_OPTION, '' );

            if ( '' === $hint || 1 !== preg_match( '/^[A-Za-z0-9_]{1,4}$/', $hint ) ) {
                return '';
            }

            return $bullets . $hint;
        }

        return $bullets . self::stored_hint();
    }

    /**
     * The value in clear text, for the updater/API only.
     */
    public static function read(): string {
        if ( null !== self::$cached ) {
            return self::$cached;
        }

        if ( '' !== self::TOKEN_CONSTANT && defined( self::TOKEN_CONSTANT ) ) {
            $constant = trim( (string) constant( self::TOKEN_CONSTANT ) );

            if ( '' !== $constant ) {
                self::clear_error();
                self::$cached = $constant;
                return self::$cached;
            }
        }

        $stored = self::stored_value();

        if ( '' === $stored ) {
            self::$cached = '';
            return self::$cached;
        }

        if ( ! self::is_encrypted( $stored ) ) {
            self::migrate_plaintext();
            $stored = self::stored_value();

            if ( ! self::is_encrypted( $stored ) ) {
                if ( self::STRICT_PAYLOAD && ! self::looks_like_payload( $stored ) ) {
                    self::note_error( 'format' );
                    self::$cached = '';
                    return self::$cached;
                }

                // No key to encrypt with: keep the site working rather than
                // dropping a value the administrator already entered.
                self::note_error( 'no_key' );
                self::$cached = trim( $stored );
                return self::$cached;
            }
        }

        self::$cached = self::decrypt( $stored );

        return self::$cached;
    }

    /**
     * Encrypts and stores a value, updates the hint and the schema version.
     */
    public static function store( string $value ): bool {
        $value = trim( $value );

        if ( '' === $value ) {
            if ( self::STRICT_PAYLOAD ) {
                self::note_error( 'format' );
            }
            return false;
        }

        if ( self::STRICT_PAYLOAD && ! self::looks_like_payload( $value ) ) {
            self::note_error( 'format' );
            return false;
        }

        if ( ! self::is_available() ) {
            self::note_error( 'no_key' );
            return false;
        }

        $cipher = self::encrypt( $value );

        if ( '' === $cipher ) {
            self::note_error( 'encrypt' );
            return false;
        }

        self::prepare_option( self::OPTION );
        update_option( self::OPTION, $cipher, false );

        self::prepare_option( self::HINT_OPTION );
        update_option( self::HINT_OPTION, self::hint_payload( $value ), false );

        self::ensure_schema();
        self::set_autoload_off();
        self::clear_error();

        self::$cached = $value;

        return true;
    }

    /**
     * Removes the stored value and its hint. The schema marker is removed too
     * unless the plugin keeps it as an installation marker.
     */
    public static function forget(): void {
        delete_option( self::OPTION );
        delete_option( self::HINT_OPTION );

        if ( self::FORGET_SCHEMA ) {
            delete_option( self::SCHEMA_OPTION );
        }

        delete_transient( self::ERROR_TRANSIENT );

        self::$cached = '';
        self::$error  = '';

        if ( self::FORGET_REPREPARE ) {
            // options.php writes the returned empty value afterwards; recreate
            // the option with autoload disabled so it cannot become autoloaded.
            self::prepare_option( self::OPTION );
        }
    }

    /**
     * Encrypts a legacy plaintext value on the first read.
     *
     * @return bool True when a migration was performed.
     */
    public static function migrate_plaintext(): bool {
        $stored = self::stored_value();

        if ( '' === $stored || self::is_encrypted( $stored ) ) {
            return false;
        }

        if ( self::STRICT_PAYLOAD && ! self::looks_like_payload( $stored ) ) {
            return false;
        }

        if ( ! self::is_available() ) {
            self::note_error( 'no_key' );
            return false;
        }

        $cipher = self::encrypt( $stored );

        if ( '' === $cipher ) {
            self::note_error( 'encrypt' );
            return false;
        }

        self::prepare_option( self::OPTION );
        update_option( self::OPTION, $cipher, false );

        self::prepare_option( self::HINT_OPTION );
        update_option( self::HINT_OPTION, self::hint_payload( $stored ), false );

        self::ensure_schema();
        self::set_autoload_off();
        self::clear_error();

        self::$cached = null;

        self::log_migration();

        return true;
    }

    /**
     * Creates the schema version option (autoload disabled).
     */
    public static function ensure_schema(): void {
        if ( false === get_option( self::SCHEMA_OPTION, false ) ) {
            update_option( self::SCHEMA_OPTION, self::SCHEMA, false );
        } elseif ( self::SCHEMA !== (int) get_option( self::SCHEMA_OPTION, 0 ) ) {
            update_option( self::SCHEMA_OPTION, self::SCHEMA, false );
        }

        self::set_autoload_off();
    }

    /**
     * Creates the storage options with autoload disabled if missing.
     */
    public static function ensure_storage_options(): void {
        self::prepare_option( self::OPTION );
        self::prepare_option( self::HINT_OPTION );
        self::ensure_schema();
        self::set_autoload_off();
    }

    /**
     * Reason of the last failure in this request, if any.
     */
    public static function last_error(): string {
        if ( '' !== self::$error ) {
            return self::$error;
        }

        $code = (string) get_transient( self::ERROR_TRANSIENT );

        return in_array( $code, [ 'no_key', 'decrypt', 'format', 'encrypt' ], true ) ? $code : '';
    }

    /**
     * Drops the per-request caches (after a direct change or an upgrade).
     */
    public static function reset_cache(): void {
        self::$cached       = null;
        self::$key_computed = false;
        self::$key_value    = false;
    }

    /*
     * ---------------------------------------------------------------------
     *  Write-path guards
     * ---------------------------------------------------------------------
     */

    /**
     * Encrypts a plaintext value arriving through update_option().
     *
     * @param mixed $value     New value.
     * @param mixed $old_value Previous value.
     * @return mixed
     */
    public static function filter_incoming_value( $value, $old_value = null ) {
        return self::filter_incoming( $value, $old_value );
    }

    /**
     * Alias of filter_incoming_value() for token payloads.
     *
     * @param mixed $value     New value.
     * @param mixed $old_value Previous value.
     * @return mixed
     */
    public static function filter_incoming_token( $value, $old_value = null ) {
        return self::filter_incoming( $value, $old_value );
    }

    /**
     * @param mixed $value
     * @param mixed $old_value
     * @return mixed
     */
    private static function filter_incoming( $value, $old_value = null ) {
        if ( ! is_string( $value ) ) {
            return $value;
        }

        $value = trim( $value );

        if ( '' === $value || self::is_encrypted( $value ) || ! self::looks_like_payload( $value ) ) {
            return $value;
        }

        $cipher = self::encrypt( $value );

        if ( '' === $cipher ) {
            self::note_error( 'no_key' );
            return is_string( $old_value ) ? $old_value : '';
        }

        self::prepare_option( self::HINT_OPTION );
        update_option( self::HINT_OPTION, self::hint_payload( $value ), false );

        self::ensure_schema();
        self::set_autoload_off();
        self::clear_error();

        self::$cached = $value;

        return $cipher;
    }

    /**
     * Migrates a plaintext value inserted directly or written around the
     * update filter.
     *
     * @param string $option Option name.
     * @param mixed  $value  Stored value.
     */
    public static function after_option_written( $option, $value ): void {
        if ( self::OPTION !== $option ) {
            return;
        }

        self::set_autoload_off();

        if ( is_string( $value ) && ! self::is_encrypted( $value ) && self::looks_like_payload( $value ) ) {
            self::migrate_plaintext();
        }
    }

    /*
     * ---------------------------------------------------------------------
     *  Helpers
     * ---------------------------------------------------------------------
     */

    /**
     * Whether a string looks like the expected payload.
     */
    private static function looks_like_payload( string $value ): bool {
        $value = trim( $value );

        if ( '' === $value ) {
            return false;
        }

        if ( 'json' === self::PAYLOAD ) {
            if ( '{' !== $value[0] ) {
                return false;
            }

            return false !== strpos( $value, '"private_key"' ) && false !== strpos( $value, '"client_email"' );
        }

        return 1 === preg_match( '/^(?:gh[pous]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})$/', $value );
    }

    /**
     * Last four characters of the value, never the whole secret.
     */
    private static function hint_for( string $value ): string {
        $value = trim( $value );

        if ( strlen( $value ) < 8 ) {
            return '';
        }

        $hint = substr( $value, -4 );

        return 1 === preg_match( '/^[A-Za-z0-9_]{1,4}$/', $hint ) ? $hint : '';
    }

    /**
     * Hint payload written next to the ciphertext.
     *
     * @return string|array{client_email:string, saved_at:int}
     */
    private static function hint_payload( string $value ) {
        if ( 'email' === self::HINT_KIND ) {
            return [
                'client_email' => self::client_email_of( $value ),
                'saved_at'     => time(),
            ];
        }

        return self::hint_for( $value );
    }

    /**
     * client_email of a service-account JSON, without logging the document.
     */
    private static function client_email_of( string $json ): string {
        $decoded = json_decode( $json, true );

        if ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) ) {
            return '';
        }

        return (string) $decoded['client_email'];
    }

    /**
     * Creates an option with autoload disabled when it does not exist yet.
     */
    private static function prepare_option( string $option ): void {
        if ( false === get_option( $option, false ) ) {
            update_option( $option, '', false );
        }
    }

    /**
     * Forces autoload off for the storage options (WP 6.4+).
     */
    private static function set_autoload_off(): void {
        if ( ! function_exists( 'wp_set_option_autoload' ) ) {
            return;
        }

        wp_set_option_autoload( self::OPTION, false );
        wp_set_option_autoload( self::HINT_OPTION, false );
        wp_set_option_autoload( self::SCHEMA_OPTION, false );
    }

    private static function note_error( string $code ): void {
        self::$error = $code;

        set_transient( self::ERROR_TRANSIENT, $code, 15 * MINUTE_IN_SECONDS );
    }

    private static function clear_error(): void {
        self::$error = '';

        delete_transient( self::ERROR_TRANSIENT );
    }

    /**
     * Writes one migration line, without the secret. Exactly once per migration.
     */
    private static function log_migration(): void {
        if ( '' === self::LOG_MESSAGE ) {
            return;
        }

        if ( '' !== self::LOG_FUNCTION ) {
            if ( function_exists( self::LOG_FUNCTION ) ) {
                call_user_func( self::LOG_FUNCTION, self::LOG_MESSAGE );
            }
            return;
        }

        if ( function_exists( 'error_log' ) ) {
            error_log( self::LOG_MESSAGE ); // phpcs:ignore -- the message is a literal and contains no secret.
        }
    }

    
    /**
     * Validates a service-account JSON before it is encrypted or used.
     *
     * @param string $json Raw value from the settings field.
     * @return array{ok:bool, message:string, client_email:string}
     */
    public static function validate( string $json ): array {
        $json = trim( $json );

        if ( '' === $json ) {
            return [
                'ok'           => false,
                'message'      => __( 'Ключ не задан.', 'google-indexing-manager' ),
                'client_email' => '',
            ];
        }

        $decoded = json_decode( $json, true );

        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            return [
                'ok'           => false,
                'message'      => sprintf(
                    /* translators: %s: json_decode() error message. */
                    __( 'JSON-ключ невалиден: %s', 'google-indexing-manager' ),
                    json_last_error_msg()
                ),
                'client_email' => '',
            ];
        }

        $client_email = isset( $decoded['client_email'] ) ? trim( (string) $decoded['client_email'] ) : '';
        $private_key  = isset( $decoded['private_key'] ) ? (string) $decoded['private_key'] : '';

        if ( '' === $client_email || '' === $private_key ) {
            return [
                'ok'           => false,
                'message'      => __( 'JSON-ключ не является ключом сервисного аккаунта: нет client_email или private_key.', 'google-indexing-manager' ),
                'client_email' => '',
            ];
        }

        if ( false === strpos( $client_email, '@' ) ) {
            return [
                'ok'           => false,
                'message'      => __( 'client_email не похож на адрес сервисного аккаунта.', 'google-indexing-manager' ),
                'client_email' => '',
            ];
        }

        $normalized = function_exists( 'gim_normalize_private_key' )
            ? call_user_func( 'gim_normalize_private_key', $private_key )
            : str_replace( '\\n', "\n", $private_key );

        if ( false === strpos( (string) $normalized, '-----BEGIN' ) || false === strpos( (string) $normalized, 'PRIVATE KEY-----' ) ) {
            return [
                'ok'           => false,
                'message'      => __( 'private_key не похож на PEM-ключ RSA.', 'google-indexing-manager' ),
                'client_email' => '',
            ];
        }

        return [
            'ok'           => true,
            'message'      => '',
            'client_email' => $client_email,
        ];
    }

}
/* -------------------------------------------------------------------------
 *  Plugin-level accessors.
 * ---------------------------------------------------------------------- */

/**
 * Registers the write-path guards and schedules the legacy-value migration.
 */
function gim_token_crypto_init(): void {
    Gim_Token_Crypto::register_hooks();

    // The migration reads one non-autoloaded option, so it runs in admin
    // requests only; elsewhere the first read() migrates the row.
    add_action( 'admin_init', 'gim_maybe_migrate_json_key' );
}

/**
 * Encrypts a legacy plaintext JSON left by an earlier version.
 */
function gim_maybe_migrate_json_key(): void {
    if ( Gim_Token_Crypto::has_plaintext_storage() ) {
        Gim_Token_Crypto::migrate_plaintext();
    }
}

/**
 * The service-account JSON in clear text, or '' when there is none.
 *
 * Callers must never print, log or store the returned value: it contains
 * private_key. It exists for JWT signing only.
 */
function gim_get_json_key(): string {
    return Gim_Token_Crypto::read();
}

/**
 * Whether a service-account key is present (encrypted or legacy plaintext).
 */
function gim_json_key_configured(): bool {
    return Gim_Token_Crypto::has_key();
}

/**
 * State of the key for the settings and diagnostic screens. No secret inside.
 *
 * @return array{configured:bool, encrypted:bool, readable:bool, source:string, client_email:string, saved_at:int, key_error:string}
 */
function gim_json_key_state(): array {
    $hint       = Gim_Token_Crypto::hint();
    $configured = Gim_Token_Crypto::has_key();
    $encrypted  = Gim_Token_Crypto::is_encrypted( Gim_Token_Crypto::stored_value() );
    $source     = Gim_Token_Crypto::key_source();

    return [
        'configured'   => $configured,
        'encrypted'    => $encrypted,
        // A readable key is one the plugin can actually use: a decryptable
        // ciphertext, or a legacy plaintext row (which still signs JWTs).
        'readable'     => $configured ? ( $encrypted ? ! Gim_Token_Crypto::is_unreadable() : true ) : false,
        'source'       => $source,
        'client_email' => $hint['client_email'],
        'saved_at'     => $hint['saved_at'],
        'key_error'    => Gim_Token_Crypto::is_available() ? '' : Gim_Token_Crypto::key_error(),
    ];
}

/**
 * Deletes the stored service-account key: explicit action only.
 *
 * @return bool True when something was removed.
 */
function gim_delete_json_key(): bool {
    $had = Gim_Token_Crypto::has_key();

    Gim_Token_Crypto::forget();

    if ( function_exists( 'gim_clear_token_cache' ) ) {
        gim_clear_token_cache();
    }

    if ( function_exists( 'gim_log_info' ) ) {
        gim_log_info( 'Ключ сервисного аккаунта удалён из настроек' );
    }

    return $had;
}
