<?php
/**
 * Plugin Name:       Google Indexing Manager
 * Description:       Проверяет не проиндексированные URL и отправляет их на повторную индексацию через Google API.
 * Version:           1.12.3
 * Requires at least: 7.0
 * Requires PHP:      8.4
 * Tested up to:      7.1
 * Author:            Gnostis
 * Author URI:        https://github.com/Gnoztis
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       google-indexing-manager
 * Domain Path:       /languages
 * Update URI:        https://github.com/Gnoztis/google-indexing-manager
 *
 * @package GoogleIndexingManager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GIM_VERSION', '1.12.3' );
define( 'GIM_PLUGIN_FILE', __FILE__ );
define( 'GIM_DIR', __DIR__ );
define( 'GIM_URL', plugin_dir_url( __FILE__ ) );
define( 'GIM_SLUG', 'google-indexing-manager' );
define( 'GIM_DB_VERSION', 6 );
define( 'GIM_CRON_VERSION', 8 );

/**
 * Registers the GitHub updater.
 *
 * The class itself is a generated file (tools/shared/updater, synced with
 * `node tools/scripts/sync-updater.mjs google-indexing-manager`); it must never
 * be edited by hand. The instantiation lives here so the plugin has one clear
 * place that wires the updater to WordPress.
 */
function gim_updater_init(): void {
    static $registered = false;

    if ( $registered || ! class_exists( 'GIM_GitHub_Updater' ) ) {
        return;
    }

    $registered = true;

    $updater = new GIM_GitHub_Updater();
    $updater->register_hooks();
}

$gim_required_files = [
    'includes/class-logger.php',
    'includes/class-queue.php',
    'includes/class-gim-token-crypto.php',
    'includes/class-gim-access-token.php',
    'includes/class-google-api.php',
    'includes/class-cron.php',
    'includes/class-gim-github-updater.php',
    'includes/class-admin.php',
    'includes/admin/admin-stats.php',
    'includes/admin/admin-urls.php',
    'includes/admin/admin-logs.php',
    'includes/admin/admin-settings.php',
    'includes/admin/admin-diagnostic.php',
    'includes/admin/admin-ajax.php',
];

$gim_missing_files = [];
foreach ( $gim_required_files as $gim_file ) {
    $gim_path = GIM_DIR . '/' . $gim_file;
    if ( file_exists( $gim_path ) ) {
        // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- path built from GIM_DIR and a literal list above, never from user input.
        require_once $gim_path;
    } else {
        $gim_missing_files[] = $gim_file;
    }
}

$gim_required_functions = [
    'gim_log'                      => 'includes/class-logger.php',
    'gim_current_user_is_admin'    => 'includes/class-logger.php',
    'gim_acquire_lock'             => 'includes/class-queue.php',
    'gim_release_lock'             => 'includes/class-queue.php',
    'gim_get_sent_today'           => 'includes/class-queue.php',
    'gim_get_urls_to_recheck'      => 'includes/class-queue.php',
    'gim_refresh_stats_snapshot'   => 'includes/class-queue.php',
    'gim_get_stats_snapshot'       => 'includes/class-queue.php',
    'gim_purge_old_logs'           => 'includes/class-queue.php',
    'gim_get_log_lifetime_days'    => 'includes/class-queue.php',
    'gim_get_json_key'             => 'includes/class-gim-token-crypto.php',
    'gim_json_key_state'           => 'includes/class-gim-token-crypto.php',
    'gim_delete_json_key'          => 'includes/class-gim-token-crypto.php',
    'gim_token_crypto_init'        => 'includes/class-gim-token-crypto.php',
    'gim_access_token_init'        => 'includes/class-gim-access-token.php',
    'gim_access_token_migrate'     => 'includes/class-gim-access-token.php',
    'gim_get_access_token'         => 'includes/class-google-api.php',
    'gim_cron_init'                => 'includes/class-cron.php',
    'gim_cron_register_healthcheck' => 'includes/class-cron.php',
    'gim_updater_init'             => 'google-indexing-manager.php',
    'gim_admin_init'               => 'includes/class-admin.php',
    'gim_render_stats'             => 'includes/admin/admin-stats.php',
    'gim_render_urls'              => 'includes/admin/admin-urls.php',
    'gim_render_logs'              => 'includes/admin/admin-logs.php',
    'gim_render_settings'          => 'includes/admin/admin-settings.php',
    'gim_render_diagnostic'        => 'includes/admin/admin-diagnostic.php',
    'gim_ajax_init'                => 'includes/admin/admin-ajax.php',
];

$gim_missing_functions = [];
foreach ( $gim_required_functions as $gim_function => $gim_source ) {
    if ( ! function_exists( $gim_function ) ) {
        $gim_missing_functions[] = $gim_source . ' — нет функции ' . $gim_function . '()';
    }
}

/* -------------------------------------------------------------------------
 * Схема БД.
 * ---------------------------------------------------------------------- */

function gim_create_tables( bool $recreate_unindexed = false ): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $table_log = $wpdb->prefix . 'gim_log';
    $sql_log   = "CREATE TABLE {$table_log} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        level varchar(20) NOT NULL DEFAULT 'info',
        message text NOT NULL,
        url varchar(2048) DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY gim_idx_level (level),
        KEY gim_idx_level_id (level, id),
        KEY gim_idx_created_at (created_at)
    ) {$charset};";

    if ( $recreate_unindexed ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL without placeholders; the table name comes from $wpdb->prefix.
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gim_unindexed" );
    }

    $table_unindexed = $wpdb->prefix . 'gim_unindexed';
    $sql_unindexed   = "CREATE TABLE {$table_unindexed} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        url varchar(2048) NOT NULL,
        indexed tinyint(1) DEFAULT NULL,
        coverage varchar(255) DEFAULT NULL,
        checked_at datetime DEFAULT NULL,
        sent_at datetime DEFAULT NULL,
        excluded tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY url_unique (url(191)),
        KEY gim_idx_unindexed (indexed, excluded, checked_at),
        KEY gim_idx_unchecked (indexed, checked_at),
        KEY gim_idx_excluded (excluded, checked_at),
        KEY gim_idx_checked_at (checked_at),
        KEY gim_idx_sent_at (sent_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_log );
    dbDelta( $sql_unindexed );

    update_option( 'gim_db_version', GIM_DB_VERSION, false );
}

function gim_maybe_add_indexes(): void {
    global $wpdb;

    $table  = $wpdb->prefix . 'gim_unindexed';
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists === $table ) {
        $wanted = [
            'gim_idx_unindexed'  => '(indexed, excluded, checked_at)',
            'gim_idx_unchecked'  => '(indexed, checked_at)',
            'gim_idx_excluded'   => '(excluded, checked_at)',
            'gim_idx_checked_at' => '(checked_at)',
            'gim_idx_sent_at'    => '(sent_at)',
        ];
        $existing = [];
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SHOW INDEX without placeholders; the table name comes from $wpdb->prefix.
        $rows = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $existing[ (string) ( $row['Key_name'] ?? '' ) ] = true;
            }
        }
        foreach ( $wanted as $name => $cols ) {
            if ( ! isset( $existing[ $name ] ) ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL without placeholders; names are built from $wpdb->prefix and the literal list above.
                $wpdb->query( "ALTER TABLE {$table} ADD INDEX {$name} {$cols}" );
            }
        }
    }

    $table_log  = $wpdb->prefix . 'gim_log';
    $exists_log = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_log ) );
    if ( $exists_log === $table_log ) {
        $wanted_log = [
            'gim_idx_level_id' => '(level, id)',
            'gim_idx_level'    => '(level)',
        ];
        $existing_log = [];
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SHOW INDEX without placeholders; the table name comes from $wpdb->prefix.
        $rows_log = $wpdb->get_results( "SHOW INDEX FROM {$table_log}", ARRAY_A );
        if ( is_array( $rows_log ) ) {
            foreach ( $rows_log as $row ) {
                $existing_log[ (string) ( $row['Key_name'] ?? '' ) ] = true;
            }
        }
        foreach ( $wanted_log as $name => $cols ) {
            if ( ! isset( $existing_log[ $name ] ) ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL without placeholders; names are built from $wpdb->prefix and the literal list above.
                $wpdb->query( "ALTER TABLE {$table_log} ADD INDEX {$name} {$cols}" );
            }
        }
    }
}

function gim_maybe_upgrade_schema(): void {
    $current = (int) get_option( 'gim_db_version', 0 );
    if ( $current >= GIM_DB_VERSION ) {
        return;
    }

    gim_create_tables( false );
    gim_maybe_add_indexes();
}

function gim_maybe_upgrade_cron(): void {
    $ver = (int) get_option( 'gim_cron_version', 0 );
    if ( $ver >= GIM_CRON_VERSION ) {
        return;
    }

    wp_clear_scheduled_hook( 'gim_check_unindexed' );
    wp_clear_scheduled_hook( 'gim_send_batch' );
    wp_clear_scheduled_hook( 'gim_cron_check_tick' );
    wp_clear_scheduled_hook( 'gim_cron_send_tick' );
    wp_clear_scheduled_hook( 'gim_cron_sync_tick' );
    wp_clear_scheduled_hook( 'gim_cron_sync_urls_tick' );
    wp_clear_scheduled_hook( 'gim_cron_sync_tags_tick' );
    wp_clear_scheduled_hook( 'gim_cron_healthcheck' );
    wp_clear_scheduled_hook( 'gim_stats_refresh_tick' );
    wp_clear_scheduled_hook( 'gim_cron_logs_cleanup_tick' );

    gim_cron_register_healthcheck();

    wp_schedule_single_event( time() + 60, 'gim_cron_check_tick' );
    wp_schedule_single_event( time() + 120, 'gim_cron_send_tick' );
    wp_schedule_single_event( time() + 180, 'gim_cron_sync_urls_tick' );
    wp_schedule_single_event( time() + 240, 'gim_cron_sync_tags_tick' );
    wp_schedule_single_event( time() + 300, 'gim_stats_refresh_tick' );

    update_option( 'gim_cron_version', GIM_CRON_VERSION, false );
}

/* -------------------------------------------------------------------------
 * Активация/деактивация.
 * ---------------------------------------------------------------------- */

function gim_activate(): void {
    gim_create_tables( true );

    wp_clear_scheduled_hook( 'gim_cron_check_tick' );
    wp_clear_scheduled_hook( 'gim_cron_send_tick' );
    wp_clear_scheduled_hook( 'gim_cron_sync_tick' );
    wp_clear_scheduled_hook( 'gim_cron_sync_urls_tick' );
    wp_clear_scheduled_hook( 'gim_cron_sync_tags_tick' );
    wp_clear_scheduled_hook( 'gim_cron_healthcheck' );
    wp_clear_scheduled_hook( 'gim_stats_refresh_tick' );
    wp_clear_scheduled_hook( 'gim_cron_logs_cleanup_tick' );

    gim_cron_register_healthcheck();

    wp_schedule_single_event( time() + 60, 'gim_cron_check_tick' );
    wp_schedule_single_event( time() + 120, 'gim_cron_send_tick' );
    wp_schedule_single_event( time() + 180, 'gim_cron_sync_urls_tick' );
    wp_schedule_single_event( time() + 240, 'gim_cron_sync_tags_tick' );
    wp_schedule_single_event( time() + 300, 'gim_stats_refresh_tick' );

    update_option( 'gim_cron_version', GIM_CRON_VERSION, false );
}

function gim_deactivate(): void {
    wp_clear_scheduled_hook( 'gim_cron_check_tick' );
    wp_clear_scheduled_hook( 'gim_cron_send_tick' );
    wp_clear_scheduled_hook( 'gim_cron_sync_tick' );
    wp_clear_scheduled_hook( 'gim_cron_sync_urls_tick' );
    wp_clear_scheduled_hook( 'gim_cron_sync_tags_tick' );
    wp_clear_scheduled_hook( 'gim_cron_healthcheck' );
    wp_clear_scheduled_hook( 'gim_stats_refresh_tick' );
    wp_clear_scheduled_hook( 'gim_cron_logs_cleanup_tick' );
    wp_clear_scheduled_hook( 'gim_check_unindexed' );
    wp_clear_scheduled_hook( 'gim_send_batch' );
}

register_activation_hook( __FILE__, 'gim_activate' );
register_deactivation_hook( __FILE__, 'gim_deactivate' );

/* -------------------------------------------------------------------------
 * Инициализация.
 * ---------------------------------------------------------------------- */

if ( ! empty( $gim_missing_files ) || ! empty( $gim_missing_functions ) ) {

    add_action(
        'admin_notices',
        static function () use ( $gim_missing_files, $gim_missing_functions ) {
            if ( ! current_user_can( 'activate_plugins' ) ) {
                return;
            }
            ?>
            <div class="notice notice-error">
                <p><strong>Google Indexing Manager:</strong> плагин не запущен.</p>

                <?php if ( ! empty( $gim_missing_files ) ) : ?>
                    <p><strong>Отсутствуют файлы:</strong></p>
                    <ul style="margin-left:20px; list-style:disc;">
                        <?php foreach ( $gim_missing_files as $f ) : ?>
                            <li><code><?php echo esc_html( $f ); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ( ! empty( $gim_missing_functions ) ) : ?>
                    <p><strong>Не найдены функции:</strong></p>
                    <ul style="margin-left:20px; list-style:disc;">
                        <?php foreach ( $gim_missing_functions as $f ) : ?>
                            <li><code><?php echo esc_html( $f ); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <?php
        }
    );

} else {

    add_action( 'plugins_loaded', 'gim_init' );
    add_action( 'admin_init', 'gim_maybe_upgrade_schema' );
    add_action( 'admin_init', 'gim_maybe_upgrade_cron' );
    add_action( 'admin_init', 'gim_cron_healthcheck_bootstrap' );

    function gim_init(): void {
        gim_token_crypto_init();
        gim_access_token_init();
        gim_updater_init();
        gim_admin_init();
        gim_ajax_init();
        gim_cron_init();
    }
}