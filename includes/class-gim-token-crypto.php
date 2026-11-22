<?php
/**
 * Encryption of the Google service-account JSON at rest.
 *
 * The service-account JSON contains private_key. That key signs JWTs for the
 * Indexing API and the Search Console API and stays valid until it is revoked
 * in Google Cloud, so a database dump without wp-config.php must not hand it
 * out. The JSON is therefore stored as an AEAD ciphertext, never as clear text.
 *
 * Key source, in order:
 *   1. GIM_KEY_ENCRYPTION_KEY in wp-config.php — base64 of exactly 32 bytes;
 *   2. otherwise HKDF-SHA256 over AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_SALT
 *      with info "gim|json_key|v1", 32 bytes;
 *   3. otherwise fail closed: the key cannot be saved and the plugin keeps
 *      working with whatever it already has.
 *
 * Cipher ladder (AEAD only), each value carries its algorithm:
 *   1. sodium_crypto_aead_xchacha20poly1305_ietf with AAD — "v1s:";
 *   2. sodium_crypto_secretbox with the AAD packed as AAD."\0".plaintext — "v1b:";
 *   3. openssl aes-256-gcm with a fresh 12-byte IV and a 16-byte tag — "v1g:".
 *
 * AAD is "gim|json_key|v1|gim_json_key", so a ciphertext copied into another
 * option or another plugin's settings does not decrypt. A fresh nonce/IV is
 * generated for every encryption.
 *
 * The settings screen never decrypts anything: it shows a fixed mask, the
 * non-secret client_email and the save date, all taken from the hint option.
 * A ciphertext that cannot be decrypted is reported as "no key": the admin is
 * asked to enter the JSON again and the ciphertext is never logged.
 *
 * This is the GIM implementation of the shared SEC-003 scheme; the AFI and EC
 * plugins use the same construction under their own prefixes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Gim_Token_Crypto {

    /** Option that holds the ciphertext. */
    private const OPTION = 'gim_json_key';

    /** Non-secret hint: client_email and the save date, for the settings mask. */
    private const HINT_OPTION = 'gim_json_key_hint';

    /** Schema version of the storage format. */
    private const SCHEMA_OPTION = 'gim_token_schema';

    /** Transient with the reason of the last failure. */
    private const ERROR_TRANSIENT = 'gim_json_key_error';

    /** wp-config.php constant with the base64 key (preferred). */
    private const KEY_CONSTANT = 'GIM_KEY_ENCRYPTION_KEY';

    /** Key derivation context; also the base of the additional data. */
    private const INFO = 'gim|json_key|v1';

    private const SCHEMA    = 1;
    private const KEY_BYTES = 32;
    private const IV_BYTES  = 12;
    private const TAG_BYTES = 16;
    private const CIPHER    = 'aes-256-gcm';

    private const PREFIX_SODIUM_AEAD = 'v1s:';
    private const PREFIX_SODIUM_BOX  = 'v1b:';
    private const PREFIX_OPENSSL     = 'v1g:';

    private const SALT_CONSTANTS = [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_SALT' ];

    /**
     * Per-request cache of the decrypted JSON.
     *
     * @var string|null
     */
    private static ?string $cached = null;

    /**
     * Per-request failure code: no_key, decrypt, format, encrypt.
     *
     * @var string
     */
    private static string $error = '';

    /* ============================================================
     *  Hooks
     * ============================================================ */

    /**
     * Second line of defence: a plaintext JSON written through the Options API
     * is encrypted before it reaches the database, and a row inserted around
     * the filter is migrated right after the write.
     */
    public static function register_hooks(): void {
        add_filter( 'pre_update_option_' . self::OPTION, [ __CLASS__, 'filter_incoming_value' ], 10, 2 );
        add_action( 'added_option', [ __CLASS__, 'after_option_written' ], 10, 2 );
        add_action( 'updated_option', [ __CLASS__, 'after_option_written' ], 10, 2 );
    }

    /* ============================================================
     *  Availability and key source
     * ============================================================ */

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
            return __( 'нет ни константы GIM_KEY_ENCRYPTION_KEY, ни солей WordPress', 'google-indexing-manager' );
        }
        if ( '' === self::key() ) {
            return __( 'константа GIM_KEY_ENCRYPTION_KEY должна быть base64 от 32 байт', 'google-indexing-manager' );
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
     */
    private static function key(): string {
        static $key = null;

        if ( null !== $key ) {
            return $key;
        }

        $key = '';

        if ( defined( self::KEY_CONSTANT ) ) {
            $decoded = base64_decode( trim( (string) constant( self::KEY_CONSTANT ) ), true );

            if ( is_string( $decoded ) && self::KEY_BYTES === strlen( $decoded ) ) {
                $key = $decoded;
            }

            return $key;
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
            $key = $derived;
        }

        return $key;
    }

    /**
     * Additional data of the AEAD: binds the plugin and the option name.
     */
    private static function additional_data(): string {
        return self::INFO . '|' . self::OPTION;
    }

    /**
     * Whether at least one rung of the cipher ladder is usable.
     */
    private static function cipher_available(): bool {
        return self::sodium_aead_available() || self::sodium_box_available() || self::openssl_available();
    }

    private static function sodium_aead_available(): bool {
        return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
            && function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' )
            && defined( 'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES' );
    }

    private static function sodium_box_available(): bool {
        return function_exists( 'sodium_crypto_secretbox' )
            && function_exists( 'sodium_crypto_secretbox_open' )
            && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
    }

    private static function openssl_available(): bool {
        if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
            return false;
        }

        $methods = openssl_get_cipher_methods();

        return is_array( $methods ) && in_array( self::CIPHER, $methods, true );
    }

    /* ============================================================
     *  Encrypt / decrypt
     * ============================================================ */

    /**
     * Encrypts a value.
     *
     * @param string $plaintext Value to encrypt.
     * @return string "v1?:" prefixed value, empty string on failure.
     */
    public static function encrypt( string $plaintext ): string {
        if ( '' === $plaintext ) {
            return '';
        }

        $key = self::key();
        if ( '' === $key ) {
            self::note_error( 'no_key' );
            return '';
        }

        $additional_data = self::additional_data();

        // 1. sodium_crypto_aead_xchacha20poly1305_ietf — the preferred AEAD.
        if ( self::sodium_aead_available() ) {
            try {
                $nonce  = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
                $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, $additional_data, $nonce, $key );

                if ( is_string( $cipher ) && '' !== $cipher ) {
                    return self::PREFIX_SODIUM_AEAD . base64_encode( $nonce . $cipher );
                }
            } catch ( Throwable $exception ) {
                // Fall through to the next rung of the ladder.
            }
        }

        // 2. sodium_crypto_secretbox — no additional data in the API, so it is
        //    prepended to the plaintext and verified on the way back.
        if ( self::sodium_box_available() ) {
            try {
                $nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
                $cipher = sodium_crypto_secretbox( $additional_data . "\0" . $plaintext, $nonce, $key );

                if ( is_string( $cipher ) && '' !== $cipher ) {
                    return self::PREFIX_SODIUM_BOX . base64_encode( $nonce . $cipher );
                }
            } catch ( Throwable $exception ) {
                // Fall through to openssl.
            }
        }

        // 3. openssl aes-256-gcm with a 12-byte IV and a 16-byte tag.
        if ( self::openssl_available() ) {
            try {
                $iv = random_bytes( self::IV_BYTES );
            } catch ( Throwable $exception ) {
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
     * Decrypts a stored value.
     *
     * @param string $stored "v1?:" prefixed value.
     * @return string Clear text, empty string on any failure (wrong key, flipped
     *                byte, foreign AAD, malformed value).
     */
    public static function decrypt( string $stored ): string {
        $stored = trim( $stored );

        if ( '' === $stored ) {
            return '';
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

        $additional_data = self::additional_data();
        $plain           = '';

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

    private static function decrypt_sodium_aead( string $payload, string $additional_data, string $key ): string {
        if ( ! self::sodium_aead_available() ) {
            return '';
        }

        $nonce_bytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        // Nonce plus at least the 16-byte tag.
        if ( strlen( $payload ) <= $nonce_bytes + self::TAG_BYTES ) {
            return '';
        }

        $nonce  = substr( $payload, 0, $nonce_bytes );
        $cipher = substr( $payload, $nonce_bytes );

        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $cipher, $additional_data, $nonce, $key );
        } catch ( Throwable $exception ) {
            return '';
        }

        return is_string( $plain ) ? $plain : '';
    }

    private static function decrypt_sodium_box( string $payload, string $additional_data, string $key ): string {
        if ( ! self::sodium_box_available() ) {
            return '';
        }

        $nonce_bytes = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

        if ( strlen( $payload ) <= $nonce_bytes + self::TAG_BYTES ) {
            return '';
        }

        $nonce  = substr( $payload, 0, $nonce_bytes );
        $cipher = substr( $payload, $nonce_bytes );

        try {
            $plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
        } catch ( Throwable $exception ) {
            return '';
        }

        if ( ! is_string( $plain ) ) {
            return '';
        }

        $binding = $additional_data . "\0";

        // The additional data was prepended before encryption; a value that was
        // encrypted elsewhere does not carry our binding.
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

    /* ============================================================
     *  Value API
     * ============================================================ */

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
     * The service-account JSON in clear text, for JWT signing only.
     *
     * Order: the ciphertext option (a legacy plaintext row is migrated on the
     * first read). A value that does not decrypt is treated as "no key", never
     * as a fatal error, and the ciphertext is never logged.
     */
    public static function read(): string {
        if ( null !== self::$cached ) {
            return self::$cached;
        }

        $stored = self::stored_value();

        if ( '' === $stored ) {
            self::$cached = '';
            return self::$cached;
        }

        if ( ! self::is_encrypted( $stored ) ) {
            // A plaintext JSON written by an older version, an importer or a
            // direct update_option() call outside the filter.
            if ( self::migrate_plaintext() ) {
                $stored = self::stored_value();
            }

            if ( ! self::is_encrypted( $stored ) ) {
                if ( self::looks_like_service_account( $stored ) ) {
                    // No key to encrypt with: keep the site working, but tell
                    // the administrator that the JSON is still in clear text.
                    self::note_error( 'no_key' );
                    self::$cached = $stored;
                    return self::$cached;
                }

                self::note_error( 'format' );
                self::$cached = '';
                return self::$cached;
            }
        }

        self::$cached = self::decrypt( $stored );

        return self::$cached;
    }

    /**
     * Encrypts and stores the JSON together with its hint and schema version.
     *
     * @param string $json Valid service-account JSON.
     * @return bool True when the ciphertext was written.
     */
    public static function store( string $json ): bool {
        $json = trim( $json );

        if ( ! self::looks_like_service_account( $json ) ) {
            self::note_error( 'format' );
            return false;
        }

        if ( ! self::is_available() ) {
            self::note_error( 'no_key' );
            return false;
        }

        $cipher = self::encrypt( $json );

        if ( '' === $cipher ) {
            self::note_error( 'encrypt' );
            return false;
        }

        $client_email = self::client_email_of( $json );

        self::prepare_option( self::OPTION );
        update_option( self::OPTION, $cipher, false );

        self::prepare_option( self::HINT_OPTION );
        update_option(
            self::HINT_OPTION,
            [
                'client_email' => $client_email,
                'saved_at'     => time(),
            ],
            false
        );

        self::ensure_schema();
        self::set_autoload_off();
        self::clear_error();

        self::$cached = $json;

        return true;
    }

    /**
     * Removes the ciphertext, the hint and the schema marker.
     */
    public static function forget(): void {
        delete_option( self::OPTION );
        delete_option( self::HINT_OPTION );
        delete_option( self::SCHEMA_OPTION );
        delete_transient( self::ERROR_TRANSIENT );

        self::$cached = '';
        self::$error  = '';
    }

    /**
     * Encrypts a plaintext JSON left by an older version, once.
     *
     * @return bool True when the value was migrated in this call.
     */
    public static function migrate_plaintext(): bool {
        $stored = self::stored_value();

        if ( '' === $stored || self::is_encrypted( $stored ) || ! self::looks_like_service_account( $stored ) ) {
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

        update_option( self::OPTION, $cipher, false );
        update_option(
            self::HINT_OPTION,
            [
                'client_email' => self::client_email_of( $stored ),
                'saved_at'     => time(),
            ],
            false
        );
        update_option( self::SCHEMA_OPTION, self::SCHEMA, false );
        self::set_autoload_off();
        self::clear_error();

        self::$cached = $stored;

        // One line, once, without the JSON or the ciphertext.
        if ( function_exists( 'gim_log_info' ) ) {
            gim_log_info( 'JSON-ключ сервисного аккаунта перенесён в зашифрованное хранилище' );
        }

        return true;
    }

    /**
     * Hint stored next to the ciphertext: client_email and save timestamp.
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
     * Masked value for the settings screen: eight bullets.
     *
     * The ciphertext is not decrypted and the JSON is not touched for this.
     */
    public static function mask(): string {
        return str_repeat( '•', 8 );
    }

    /**
     * Whether a key is configured: an encrypted value or a legacy plaintext row.
     */
    public static function has_key(): bool {
        $stored = self::stored_value();

        return '' !== $stored
            && ( self::is_encrypted( $stored ) || self::looks_like_service_account( $stored ) );
    }

    /**
     * Whether the stored ciphertext cannot be decrypted with the current key.
     *
     * A stale ciphertext is reported once, and the admin is asked to enter the
     * JSON again — the ciphertext itself is never shown or logged.
     */
    public static function is_unreadable(): bool {
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
     * Whether a legacy plaintext JSON is still stored (no key to migrate it).
     */
    public static function has_plaintext_storage(): bool {
        $stored = self::stored_value();

        return '' !== $stored && ! self::is_encrypted( $stored ) && self::looks_like_service_account( $stored );
    }

    /**
     * Reason of the last failure in this request, if any.
     *
     * @return string "no_key", "decrypt", "format", "encrypt" or "".
     */
    public static function last_error(): string {
        return self::$error;
    }

    /**
     * Drops the per-request cache (after a write outside store()/forget()).
     */
    public static function reset_cache(): void {
        self::$cached = null;
    }

    /**
     * Creates the storage options with autoload disabled and the schema marker.
     *
     * Called from the settings sanitizer before options.php writes a value, so
     * the option never becomes autoloaded and the schema exists from the first
     * save.
     */
    public static function ensure_storage_options(): void {
        self::prepare_option( self::OPTION );
        self::prepare_option( self::HINT_OPTION );
        self::ensure_schema();
        self::set_autoload_off();
    }

    /* ============================================================
     *  Validation
     * ============================================================ */

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
            ? gim_normalize_private_key( $private_key )
            : str_replace( '\\n', "\n", $private_key );

        if ( false === strpos( $normalized, '-----BEGIN' ) || false === strpos( $normalized, 'PRIVATE KEY-----' ) ) {
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

    /* ============================================================
     *  Write-path guards
     * ============================================================ */

    /**
     * Encrypts a plaintext JSON arriving through update_option(), whatever the
     * source. Fail closed: never write a plaintext secret when there is no key.
     *
     * @param mixed $value     New value.
     * @param mixed $old_value Previous value.
     * @return mixed
     */
    public static function filter_incoming_value( $value, $old_value = null ) {
        if ( ! is_string( $value ) ) {
            return $value;
        }

        $value = trim( $value );

        if ( '' === $value || self::is_encrypted( $value ) || ! self::looks_like_service_account( $value ) ) {
            return $value;
        }

        $cipher = self::encrypt( $value );

        if ( '' === $cipher ) {
            self::note_error( 'no_key' );
            return is_string( $old_value ) ? $old_value : '';
        }

        self::prepare_option( self::HINT_OPTION );
        update_option(
            self::HINT_OPTION,
            [
                'client_email' => self::client_email_of( $value ),
                'saved_at'     => time(),
            ],
            false
        );
        self::ensure_schema();
        self::set_autoload_off();
        self::clear_error();

        self::$cached = $value;

        return $cipher;
    }

    /**
     * Migrates a plaintext value inserted directly (add_option) or written
     * around the update filter.
     *
     * @param string $option Option name.
     * @param mixed  $value  Stored value.
     */
    public static function after_option_written( $option, $value ): void {
        if ( self::OPTION !== $option ) {
            return;
        }

        self::set_autoload_off();

        if ( is_string( $value ) && ! self::is_encrypted( $value ) && self::looks_like_service_account( $value ) ) {
            self::migrate_plaintext();
        }
    }

    /* ============================================================
     *  Helpers
     * ============================================================ */

    /**
     * Whether a string looks like a Google service-account JSON with a private key.
     */
    private static function looks_like_service_account( string $value ): bool {
        $value = trim( $value );

        if ( '' === $value || '{' !== $value[0] ) {
            return false;
        }

        return false !== strpos( $value, '"private_key"' ) && false !== strpos( $value, '"client_email"' );
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
     * update_option() would otherwise create a missing option as autoloaded.
     */
    private static function prepare_option( string $option ): void {
        if ( false === get_option( $option, false ) ) {
            add_option( $option, '', '', false );
        }
    }

    /**
     * Creates the schema version option (autoload disabled).
     */
    private static function ensure_schema(): void {
        if ( false === get_option( self::SCHEMA_OPTION, false ) ) {
            add_option( self::SCHEMA_OPTION, self::SCHEMA, '', false );
        } elseif ( self::SCHEMA !== (int) get_option( self::SCHEMA_OPTION, 0 ) ) {
            update_option( self::SCHEMA_OPTION, self::SCHEMA, false );
        }
    }

    /**
     * Forces autoload off for the key storage options (WP 6.4+).
     */
    private static function set_autoload_off(): void {
        if ( ! function_exists( 'wp_set_option_autoload' ) ) {
            return;
        }

        wp_set_option_autoload( self::OPTION, false );
        wp_set_option_autoload( self::HINT_OPTION, false );
        wp_set_option_autoload( self::SCHEMA_OPTION, false );
    }

    /**
     * Remembers a failure for the admin notice. Only the code is stored.
     */
    private static function note_error( string $code ): void {
        self::$error = $code;

        set_transient( self::ERROR_TRANSIENT, $code, 15 * MINUTE_IN_SECONDS );
    }

    /**
     * Clears a remembered failure after a successful write.
     */
    private static function clear_error(): void {
        self::$error = '';

        delete_transient( self::ERROR_TRANSIENT );
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
    $hint      = Gim_Token_Crypto::hint();
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
