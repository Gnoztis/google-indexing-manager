<?php
/**
 * Ядро админки: меню, настройки, роутер вкладок.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'GIM_URLS_PER_PAGE' ) ) {
    define( 'GIM_URLS_PER_PAGE', 25 );
}

if ( ! defined( 'GIM_URLS_PER_PAGE_OPTIONS' ) ) {
    define( 'GIM_URLS_PER_PAGE_OPTIONS', [ 15, 25, 50, 100 ] );
}

if ( ! defined( 'GIM_LOGS_PER_PAGE' ) ) {
    define( 'GIM_LOGS_PER_PAGE', 20 );
}

function gim_admin_init(): void {
    add_action( 'admin_menu', 'gim_add_menu' );
    add_action( 'admin_init', 'gim_register_settings' );
    add_action( 'admin_enqueue_scripts', 'gim_enqueue_assets' );
    add_action( 'updated_option', 'gim_maybe_clear_token_on_option_update', 10, 3 );
    add_action( 'admin_post_gim_delete_json_key', 'gim_handle_delete_json_key' );
    add_action( 'admin_notices', 'gim_render_key_notice' );
}

function gim_add_menu(): void {
    add_options_page(
        __( 'Google Indexing', 'google-indexing-manager' ),
        __( 'Google Indexing', 'google-indexing-manager' ),
        'manage_options',
        GIM_SLUG,
        'gim_render_page'
    );
}

function gim_register_settings(): void {
    register_setting(
        'gim_settings',
        'gim_json_key',
        [
            'sanitize_callback' => 'gim_sanitize_json_key',
            // The service-account JSON must never be autoloaded with every
            // request; the crypto class also enforces this.
            'autoload'          => false,
        ]
    );
    register_setting( 'gim_settings', 'gim_site_url', [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'gim_settings', 'gim_check_interval', [ 'sanitize_callback' => 'sanitize_key' ] );
    register_setting( 'gim_settings', 'gim_night_start_hour', [ 'sanitize_callback' => 'absint' ] );
    register_setting( 'gim_settings', 'gim_night_end_hour', [ 'sanitize_callback' => 'absint' ] );
    register_setting( 'gim_settings', 'gim_daily_send_cap', [ 'sanitize_callback' => 'absint' ] );
    register_setting( 'gim_settings', 'gim_recheck_days', [ 'sanitize_callback' => 'absint' ] );
    register_setting( 'gim_settings', 'gim_resend_days', [ 'sanitize_callback' => 'absint' ] );
    register_setting( 'gim_settings', 'gim_log_lifetime_days', [ 'sanitize_callback' => 'absint' ] );
    register_setting( 'gim_settings', 'gim_post_types', [ 'sanitize_callback' => 'gim_sanitize_post_types' ] );
    register_setting( 'gim_settings', 'gim_excluded_tags', [ 'sanitize_callback' => 'gim_sanitize_excluded_tags' ] );
}

/**
 * Sanitizer of the service-account JSON field.
 *
 * The field is never prefilled: an empty submit keeps the stored key, a valid
 * JSON is validated and encrypted, and an invalid or unencryptable value keeps
 * the previous key instead of wiping it. The function returns the value that
 * options.php writes, so a plaintext secret never reaches the database.
 *
 * @param mixed $value Raw value from the settings form.
 * @return string Ciphertext, or the previously stored value.
 */
function gim_sanitize_json_key( $value ): string {
    $value = trim( (string) $value );

    // Never let options.php create the option as autoloaded.
    Gim_Token_Crypto::ensure_storage_options();

    // A legacy plaintext row is encrypted before any other work, so no return
    // path below can write plaintext back to the database.
    if ( Gim_Token_Crypto::has_plaintext_storage() ) {
        Gim_Token_Crypto::migrate_plaintext();
    }

    $stored = Gim_Token_Crypto::stored_value();

    // An empty field means "keep the current key": the form shows only a mask,
    // so a normal save of other settings must not delete the key.
    if ( '' === $value ) {
        return $stored;
    }

    // options.php runs the sanitizer once, but a second pass must not encrypt
    // an already encrypted value.
    if ( Gim_Token_Crypto::is_encrypted( $value ) ) {
        return $value;
    }

    $validation = Gim_Token_Crypto::validate( $value );

    if ( ! $validation['ok'] ) {
        add_settings_error( 'gim_json_key', 'invalid_json_key', $validation['message'], 'error' );
        return $stored;
    }

    if ( ! Gim_Token_Crypto::is_available() ) {
        add_settings_error(
            'gim_json_key',
            'gim_crypto_unavailable',
            sprintf(
                /* translators: %s: reason why encryption is unavailable. */
                __( 'Ключ не сохранён: шифрование недоступно (%s). Задайте GIM_KEY_ENCRYPTION_KEY в wp-config.php.', 'google-indexing-manager' ),
                Gim_Token_Crypto::key_error()
            ),
            'error'
        );
        return $stored;
    }

    if ( ! Gim_Token_Crypto::store( $value ) ) {
        add_settings_error(
            'gim_json_key',
            'gim_key_store_failed',
            __( 'Ключ не сохранён: ошибка шифрования. Подробности в логе плагина.', 'google-indexing-manager' ),
            'error'
        );
        return $stored;
    }

    Gim_Token_Crypto::reset_cache();
    gim_clear_token_cache();

    return Gim_Token_Crypto::stored_value();
}

/**
 * Explicit key deletion: admin-post action with a nonce and a capability check.
 */
function gim_handle_delete_json_key(): void {
    if ( ! gim_current_user_is_admin() ) {
        wp_die(
            esc_html__( 'Доступ к этой странице разрешён только администраторам.', 'google-indexing-manager' ),
            esc_html__( 'Доступ запрещён', 'google-indexing-manager' ),
            [ 'response' => 403 ]
        );
    }

    check_admin_referer( 'gim_delete_json_key' );

    gim_delete_json_key();

    wp_safe_redirect(
        add_query_arg(
            [
                'page'            => GIM_SLUG,
                'tab'             => 'settings',
                'gim_key_deleted' => '1',
            ],
            admin_url( 'options-general.php' )
        )
    );
    exit;
}

/**
 * Notice about the state of the service-account key.
 *
 * Shown on the plugin page and on the plugins screen; never contains the key
 * or the ciphertext.
 */
function gim_render_key_notice(): void {
    if ( ! gim_current_user_is_admin() ) {
        return;
    }

    $screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    $screen_id = is_object( $screen ) && isset( $screen->id ) ? (string) $screen->id : '';

    if ( 'settings_page_' . GIM_SLUG !== $screen_id && 'plugins' !== $screen_id ) {
        return;
    }

    $state        = gim_json_key_state();
    $settings_url = add_query_arg(
        [
            'page' => GIM_SLUG,
            'tab'  => 'settings',
        ],
        admin_url( 'options-general.php' )
    );

    if ( '' !== $state['key_error'] ) {
        echo '<div class="notice notice-error"><p><strong>Google Indexing Manager:</strong> ';
        printf(
            /* translators: %s: reason why encryption is unavailable. */
            esc_html__( 'шифрование ключа недоступно (%s). Ключ сервисного аккаунта не будет сохранён.', 'google-indexing-manager' ),
            esc_html( $state['key_error'] )
        );
        echo '</p></div>';
        return;
    }

    if ( ! $state['configured'] ) {
        echo '<div class="notice notice-info"><p><strong>Google Indexing Manager:</strong> ';
        echo esc_html__( 'ключ сервисного аккаунта не задан. Откройте настройки и выполните шаги 1–3 из блока «Как подключить».', 'google-indexing-manager' );
        echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Перейти к настройкам', 'google-indexing-manager' ) . '</a>';
        echo '</p></div>';
        return;
    }

    if ( ! $state['readable'] ) {
        echo '<div class="notice notice-error"><p><strong>Google Indexing Manager:</strong> ';
        echo esc_html__( 'ключ сервисного аккаунта не читается: изменились соли WordPress или константа GIM_KEY_ENCRYPTION_KEY. Вставьте JSON-ключ заново.', 'google-indexing-manager' );
        echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Открыть настройки', 'google-indexing-manager' ) . '</a>';
        echo '</p></div>';
        return;
    }

    if ( ! $state['encrypted'] ) {
        echo '<div class="notice notice-warning"><p><strong>Google Indexing Manager:</strong> ';
        echo esc_html__( 'ключ сервисного аккаунта хранится открытым текстом: нет ключа шифрования. Задайте GIM_KEY_ENCRYPTION_KEY в wp-config.php и сохраните ключ заново.', 'google-indexing-manager' );
        echo '</p></div>';
    }
}

function gim_sanitize_post_types( $value ): array {
    if ( ! is_array( $value ) ) {
        return [];
    }

    $allowed = get_post_types( [ 'public' => true ] );

    $ids = array_unique( array_merge( array_keys( $value ), array_values( $value ) ) );
    $ids = array_map( 'sanitize_key', $ids );
    $ids = array_values( array_intersect( $ids, $allowed ) );

    return $ids;
}

function gim_sanitize_excluded_tags( $value ): array {
    if ( is_array( $value ) ) {
        $parts = $value;
    } else {
        $parts = explode( ',', (string) $value );
    }

    $parts = array_map( 'trim', $parts );
    $parts = array_filter( $parts, static fn( $v ) => $v !== '' );
    $parts = array_map( 'absint', $parts );
    $parts = array_filter( $parts );

    return array_values( array_unique( $parts ) );
}

function gim_maybe_clear_token_on_option_update( string $option, $old_value, $value ): void {
    if ( ! in_array( $option, [ 'gim_json_key', 'gim_site_url' ], true ) ) {
        return;
    }
    if ( $old_value === $value ) {
        return;
    }

    if ( 'gim_json_key' === $option && class_exists( 'Gim_Token_Crypto' ) ) {
        // The decrypted JSON is cached per request: a new key must not be
        // shadowed by the previous one.
        Gim_Token_Crypto::reset_cache();
    }

    if ( function_exists( 'gim_clear_token_cache' ) ) {
        gim_clear_token_cache();
    }
}

/**
 * Подключить CSS и JS инлайн, только на странице плагина, только админу.
 *
 * Файлы лежат на диске (assets/admin.css, assets/admin.js), но в HTML
 * встраиваются содержимым — без <link> и <script src>.
 */
function gim_enqueue_assets( string $hook ): void {
    if ( $hook !== 'settings_page_' . GIM_SLUG ) {
        return;
    }
    if ( ! gim_current_user_is_admin() ) {
        return;
    }

    wp_register_style( 'gim-admin', false, [], GIM_VERSION );
    wp_enqueue_style( 'gim-admin' );

    $css_file = GIM_DIR . '/assets/admin.css';
    if ( is_readable( $css_file ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file inside the plugin directory.
        $css = (string) file_get_contents( $css_file );
        if ( $css !== '' ) {
            wp_add_inline_style( 'gim-admin', $css );
        }
    }

    wp_enqueue_script( 'jquery-ui-sortable' );

    wp_register_script( 'gim-admin', false, [ 'jquery', 'jquery-ui-sortable' ], GIM_VERSION, true );
    wp_enqueue_script( 'gim-admin' );

    wp_add_inline_script(
        'gim-admin',
        'var gimData = ' . wp_json_encode(
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'gim_ajax' ),
            ]
        ) . ';',
        'before'
    );

    $js_file = GIM_DIR . '/assets/admin.js';
    if ( is_readable( $js_file ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file inside the plugin directory.
        $js = (string) file_get_contents( $js_file );
        if ( $js !== '' ) {
            wp_add_inline_script( 'gim-admin', $js );
        }
    }
}

function gim_get_stats_order(): array {
    $default = [ 'total', 'unchecked', 'unindexed', 'indexed', 'excluded', 'recheck', 'resend' ];
    $order   = get_user_meta( get_current_user_id(), 'gim_stats_order', true );

    if ( ! is_array( $order ) || empty( $order ) ) {
        return $default;
    }

    foreach ( $default as $key ) {
        if ( ! in_array( $key, $order, true ) ) {
            $order[] = $key;
        }
    }

    return array_values( array_intersect( $order, $default ) );
}

function gim_render_page(): void {
    if ( ! gim_current_user_is_admin() ) {
        wp_die(
            esc_html__( 'Доступ к этой странице разрешён только администраторам.', 'google-indexing-manager' ),
            esc_html__( 'Доступ запрещён', 'google-indexing-manager' ),
            [ 'response' => 403 ]
        );
    }

    $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'stats';
    $allowed    = [ 'stats', 'urls', 'logs', 'settings', 'diagnostic' ];
    if ( ! in_array( $active_tab, $allowed, true ) ) {
        $active_tab = 'stats';
    }

    $base_url = admin_url( 'options-general.php?page=' . GIM_SLUG );

    $tabs = [
        'stats'      => __( 'Статистика', 'google-indexing-manager' ),
        'urls'       => __( 'URL', 'google-indexing-manager' ),
        'logs'       => __( 'Логи', 'google-indexing-manager' ),
        'diagnostic' => __( 'Диагностика', 'google-indexing-manager' ),
        'settings'   => __( 'Настройки', 'google-indexing-manager' ),
    ];
    ?>
    <div class="wrap gim-wrap">
        <?php settings_errors(); ?>

        <h2 class="nav-tab-wrapper gim-tabs">
            <?php foreach ( $tabs as $slug => $label ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base_url ) ); ?>"
                   class="nav-tab <?php echo esc_attr( $active_tab === $slug ? 'nav-tab-active' : '' ); ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </h2>

        <div class="tab-content gim-tab-content">
            <?php
            switch ( $active_tab ) {
                case 'urls':
                    gim_render_urls();
                    break;
                case 'logs':
                    gim_render_logs();
                    break;
                case 'diagnostic':
                    gim_render_diagnostic();
                    break;
                case 'settings':
                    gim_render_settings();
                    break;
                default:
                    gim_render_stats();
            }
            ?>
        </div>
    </div>
    <?php
}