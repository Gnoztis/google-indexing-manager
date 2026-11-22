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
    register_setting( 'gim_settings', 'gim_json_key', [ 'sanitize_callback' => 'gim_sanitize_json_key' ] );
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

function gim_sanitize_json_key( $value ): string {
    $value = trim( (string) $value );
    if ( $value === '' ) {
        return '';
    }
    $decoded = json_decode( $value, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        add_settings_error( 'gim_json_key', 'invalid_json', 'JSON-ключ невалиден: ' . json_last_error_msg(), 'error' );
        return '';
    }
    if ( empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) ) {
        add_settings_error( 'gim_json_key', 'invalid_service_account', 'JSON-ключ не является ключом сервисного аккаунта.', 'error' );
        return '';
    }
    return $value;
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
                   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
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