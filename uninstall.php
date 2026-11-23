<?php
/**
 * Uninstall routine of Google Indexing Manager.
 *
 * Deletes only this plugin's own data:
 *   - its options (the encrypted service-account key, the hint, the schema
 *     marker, the encrypted OAuth access token and its expiry, settings,
 *     statistics snapshots, daily counters, quota marks and worker locks);
 *   - its transients (access token, statistics cache, update-check cache);
 *   - its cron events;
 *   - the two tables gim_log and gim_unindexed;
 *   - its user meta (stat card order, URLs per page).
 *
 * Nothing outside the gim_* namespace is touched. On multisite every site of
 * the network is cleaned, because the options and tables are per site.
 *
 * The service-account key is stored encrypted; deleting the option removes
 * both the ciphertext and its hint. The Google Cloud key itself stays valid —
 * uninstalling a plugin cannot revoke it, see the README.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Removes the plugin data of the current site.
 */
function gim_uninstall_site(): void {
    global $wpdb;

    $options = [
        'gim_json_key',
        'gim_json_key_hint',
        'gim_token_schema',
        'gim_access_token',
        'gim_access_token_expires',
        'gim_access_token_schema',
        'gim_site_url',
        'gim_check_interval',
        'gim_night_start_hour',
        'gim_night_end_hour',
        'gim_daily_send_cap',
        'gim_recheck_days',
        'gim_resend_days',
        'gim_log_lifetime_days',
        'gim_post_types',
        'gim_excluded_tags',
        'gim_db_version',
        'gim_cron_version',
        'gim_stats_current',
        'gim_stats_previous',
        'gim_db_backups',
    ];

    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // Dynamic option names: daily counters (gim_sent_YYYY-MM-DD), quota marks
    // (gim_quota_exhausted_<api>) and worker locks (gim_lock_<name>).
    foreach ( [ 'gim_sent_', 'gim_quota_exhausted_', 'gim_lock_' ] as $prefix ) {
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( $prefix ) . '%'
            )
        );

        if ( ! is_array( $names ) ) {
            continue;
        }

        foreach ( $names as $name ) {
            delete_option( (string) $name );
        }
    }

    $transients = [
        'gim_access_token',
        'gim_stats_cache',
        'gim_recheck_count_cache',
        'gim_resend_count_cache',
        'gim_check_running',
        'gim_send_running',
        'gim_json_key_error',
        'gim_github_release',
        'gim_github_release_error',
    ];

    foreach ( $transients as $transient ) {
        delete_transient( $transient );
    }

    $hooks = [
        'gim_cron_healthcheck',
        'gim_cron_check_tick',
        'gim_cron_send_tick',
        'gim_cron_sync_tick',
        'gim_cron_sync_urls_tick',
        'gim_cron_sync_tags_tick',
        'gim_stats_refresh_tick',
        'gim_cron_logs_cleanup_tick',
        'gim_check_unindexed',
        'gim_send_batch',
    ];

    foreach ( $hooks as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }

    $tables = [
        $wpdb->prefix . 'gim_log',
        $wpdb->prefix . 'gim_unindexed',
    ];

    foreach ( $tables as $table ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- DDL without placeholders; the table name comes from $wpdb->prefix.
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    }

    // Backup tables created before schema migrations (…_gim_unindexed_bak_<v>).
    $backup_prefix = $wpdb->prefix . 'gim_unindexed_bak_';
    $backups       = $wpdb->get_col(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like( $backup_prefix ) . '%'
        )
    );

    if ( is_array( $backups ) ) {
        foreach ( $backups as $backup ) {
            if ( 0 !== strpos( (string) $backup, $backup_prefix ) ) {
                continue;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- DDL; the name passed the prefix check above.
            $wpdb->query( "DROP TABLE IF EXISTS {$backup}" );
        }
    }

    // Per-user display preferences.
    delete_metadata( 'user', 0, 'gim_stats_order', '', true );
    delete_metadata( 'user', 0, 'gim_urls_per_page', '', true );
}

if ( is_multisite() ) {
    $gim_site_ids = get_sites(
        [
            'fields' => 'ids',
            'number' => 0,
        ]
    );

    foreach ( $gim_site_ids as $gim_site_id ) {
        switch_to_blog( (int) $gim_site_id );
        gim_uninstall_site();
        restore_current_blog();
    }
} else {
    gim_uninstall_site();
}
