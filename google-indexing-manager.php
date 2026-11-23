<?php
/**
 * Plugin Name:       Google Indexing Manager
 * Description:       Проверяет не проиндексированные URL и отправляет их на повторную индексацию через Google API.
 * Version:           1.12.7
 * Requires at least: 7.0
 * Requires PHP:      8.0
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

define( 'GIM_VERSION', '1.12.7' );
define( 'GIM_PLUGIN_FILE', __FILE__ );
define( 'GIM_DIR', __DIR__ );
define( 'GIM_URL', plugin_dir_url( __FILE__ ) );
define( 'GIM_SLUG', 'google-indexing-manager' );
define( 'GIM_DB_VERSION', 7 );
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
        url_hash char(64) NOT NULL DEFAULT '',
        indexed tinyint(1) DEFAULT NULL,
        coverage varchar(255) DEFAULT NULL,
        checked_at datetime DEFAULT NULL,
        sent_at datetime DEFAULT NULL,
        excluded tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY gim_url_hash (url_hash),
        KEY gim_idx_url (url(191)),
        KEY gim_idx_unindexed (indexed, excluded, checked_at),
        KEY gim_idx_unchecked (indexed, checked_at),
        KEY gim_idx_excluded (excluded, checked_at),
        KEY gim_idx_checked_at (checked_at),
        KEY gim_idx_sent_at (sent_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_log );
    dbDelta( $sql_unindexed );

    // The schema version is a tiny integer and is checked on every request, so
    // it is autoloaded instead of costing a query per page view.
    update_option( 'gim_db_version', GIM_DB_VERSION, true );

    if ( function_exists( 'wp_set_option_autoload' ) ) {
        wp_set_option_autoload( 'gim_db_version', true );
    }
}

/**
 * Moves the unique key of the URL table from a URL prefix to a full hash.
 *
 * The old `UNIQUE KEY url_unique (url(191))` compared only the first 191
 * characters of a varchar(2048) column: two long URLs sharing a prefix were
 * treated as the same row, the second insert failed and the URL never entered
 * the queue. `url_hash` is a sha256 of the stored URL, so the unique key is
 * exact and the column stays free for display and search.
 *
 * Runs before dbDelta: on an existing table the column is added and backfilled
 * first, so dbDelta never tries to create a unique index over a column that is
 * still empty for every row (that ALTER would fail with a duplicate key).
 */
function gim_maybe_upgrade_url_hash(): void {
    global $wpdb;

    $table = $wpdb->prefix . 'gim_unindexed';

    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        // A fresh install: gim_create_tables() creates the new schema.
        return;
    }

    $column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'url_hash' ) );

    if ( null === $column ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL without placeholders; the table name comes from $wpdb->prefix.
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN url_hash char(64) NOT NULL DEFAULT '' AFTER url" );
    }

    // Backfill: one CASE UPDATE per 500 rows, so a large queue is a few queries
    // instead of one per URL.
    $guard = 0;
    do {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL; the table name comes from $wpdb->prefix.
        $rows = $wpdb->get_results( "SELECT id, url FROM {$table} WHERE url_hash = '' ORDER BY id ASC LIMIT 500", ARRAY_A );

        if ( ! is_array( $rows ) || empty( $rows ) ) {
            break;
        }

        $cases = [];
        $args  = [];
        $ids   = [];

        foreach ( $rows as $row ) {
            $id    = (int) ( $row['id'] ?? 0 );
            $url   = (string) ( $row['url'] ?? '' );
            if ( $id <= 0 ) {
                continue;
            }
            $cases[] = 'WHEN %d THEN %s';
            $args[]  = $id;
            $args[]  = gim_url_hash( $url );
            $ids[]   = $id;
        }

        if ( empty( $ids ) ) {
            break;
        }

        $id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql             = "UPDATE {$table} SET url_hash = CASE id " . implode( ' ', $cases ) . " END WHERE id IN ({$id_placeholders})";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the placeholders are built above and the values are passed to prepare().
        $updated = $wpdb->query( $wpdb->prepare( $sql, ...array_merge( $args, $ids ) ) );

        if ( false === $updated || 0 === (int) $updated ) {
            // No progress: stop instead of looping forever on a read-only table.
            break;
        }

        $guard++;
    } while ( $guard < 1000 );

    // The unique hash key cannot be created while duplicate hashes or empty
    // hashes exist. Real duplicates are impossible under the old prefix key,
    // but an install where the key was dropped manually may have them; keep the
    // newest row. If any row still has an empty hash, the migration stops and
    // the old unique key is left in place rather than leaving the table without
    // any unique constraint.
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL; the table name comes from $wpdb->prefix.
    $duplicates = $wpdb->get_results( "SELECT url_hash, COUNT(*) AS cnt FROM {$table} GROUP BY url_hash HAVING cnt > 1", ARRAY_A );

    if ( is_array( $duplicates ) ) {
        foreach ( $duplicates as $duplicate ) {
            $hash = (string) ( $duplicate['url_hash'] ?? '' );
            if ( '' === $hash ) {
                continue;
            }

            $keep = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s ORDER BY id DESC LIMIT 1", $hash )
            );

            if ( $keep > 0 ) {
                $wpdb->query(
                    $wpdb->prepare( "DELETE FROM {$table} WHERE url_hash = %s AND id <> %d", $hash, $keep )
                );
            }
        }
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal COUNT; the table name comes from $wpdb->prefix.
    $unhashed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE url_hash = ''" );

    if ( $unhashed > 0 ) {
        gim_log_error(
            sprintf( 'Миграция url_hash: %d записей остались без хэша, уникальный ключ не создан', $unhashed )
        );
        return;
    }

    // Unique hash key.
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SHOW INDEX without placeholders; the table name comes from $wpdb->prefix.
    $hash_index = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'gim_url_hash'", ARRAY_A );

    if ( ! is_array( $hash_index ) || empty( $hash_index ) ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL without placeholders; the table name comes from $wpdb->prefix.
        $created = $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY gim_url_hash (url_hash)" );

        if ( false === $created ) {
            gim_log_error( 'Миграция url_hash: не удалось создать уникальный ключ, старый ключ оставлен' );
            return;
        }
    }

    // Drop the old prefix unique key and keep a plain index for exact lookups.
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SHOW INDEX without placeholders; the table name comes from $wpdb->prefix.
    $old_unique = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'url_unique'", ARRAY_A );

    if ( is_array( $old_unique ) && ! empty( $old_unique ) ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL without placeholders; the table name comes from $wpdb->prefix.
        $wpdb->query( "ALTER TABLE {$table} DROP INDEX url_unique" );
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SHOW INDEX without placeholders; the table name comes from $wpdb->prefix.
    $url_index = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'gim_idx_url'", ARRAY_A );

    if ( ! is_array( $url_index ) || empty( $url_index ) ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL without placeholders; the table name comes from $wpdb->prefix.
        $wpdb->query( "ALTER TABLE {$table} ADD INDEX gim_idx_url (url(191))" );
    }
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

    // The hash migration must run before dbDelta: the column and the unique key
    // exist by the time the schema is compared.
    gim_maybe_upgrade_url_hash();
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

    // The schema check runs on every request: WP-Cron and AJAX may hit the new
    // queue code before any admin page is opened, and a new column must exist by
    // then. The version option is autoloaded, so a current installation pays no
    // query for this check.
    add_action( 'plugins_loaded', 'gim_maybe_upgrade_schema', 5 );

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