<?php
/**
 * Очередь, статистика, работа с таблицей URL, учёт квот и времени.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * Кеш.
 * ---------------------------------------------------------------------- */

const GIM_STATS_CACHE_KEY    = 'gim_stats_cache';
const GIM_RECHECK_CACHE_KEY  = 'gim_recheck_count_cache';
const GIM_RESEND_CACHE_KEY   = 'gim_resend_count_cache';
const GIM_STATS_CACHE_TTL    = 60;

const GIM_STATS_CURRENT_OPTION  = 'gim_stats_current';
const GIM_STATS_PREVIOUS_OPTION = 'gim_stats_previous';

/**
 * Hard ceiling on the log table and the batch size of the trim. The ceiling is
 * independent of the retention period, so retention 0 (keep forever) still
 * cannot grow the table without bound.
 */
const GIM_LOG_MAX_ROWS      = 50000;
const GIM_LOG_MIN_MAX_ROWS  = 1000;
const GIM_LOG_DELETE_BATCH  = 1000;

function gim_invalidate_stats_cache(): void {
    delete_transient( GIM_STATS_CACHE_KEY );
    delete_transient( GIM_RECHECK_CACHE_KEY );
    delete_transient( GIM_RESEND_CACHE_KEY );
}

/* -------------------------------------------------------------------------
 * Атомарные блокировки.
 *
 * Блокировка — это опция, создаваемая add_option(): INSERT в wp_options
 * атомарен, поэтому только один процесс получает право на работу. Значение —
 * метка времени, до которой блокировка считается живой; протухшая
 * блокировка (процесс умер) снимается следующим вызовом.
 * ---------------------------------------------------------------------- */

function gim_lock_option( string $name ): string {
    return 'gim_lock_' . preg_replace( '/[^a-z0-9_]/', '', strtolower( $name ) );
}

function gim_acquire_lock( string $name, int $ttl = 300 ): bool {
    $option  = gim_lock_option( $name );
    $ttl     = max( 30, $ttl );
    $expires = (int) get_option( $option, 0 );

    // A missing lock reads as 0; a lock whose timestamp is in the past belongs
    // to a worker that died. Both are removed before the atomic add_option().
    if ( $expires <= 0 || $expires < time() ) {
        delete_option( $option );
    }

    return (bool) add_option( $option, time() + $ttl, '', false );
}

function gim_release_lock( string $name ): void {
    delete_option( gim_lock_option( $name ) );
}

/* -------------------------------------------------------------------------
 * Хранимая статистика.
 * ---------------------------------------------------------------------- */

function gim_calculate_stats(): array {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_unindexed';

    $recheck_days = gim_get_recheck_days();
    $resend_days  = gim_get_resend_days();

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- aggregate over our own table; the two INTERVAL values are integers from clamped settings.
    $row = $wpdb->get_row(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN indexed IS NULL THEN 1 ELSE 0 END) AS unchecked,
            SUM(CASE WHEN indexed = 0 AND excluded = 0 THEN 1 ELSE 0 END) AS unindexed,
            SUM(CASE WHEN indexed = 1 THEN 1 ELSE 0 END) AS indexed,
            SUM(CASE WHEN excluded = 1 THEN 1 ELSE 0 END) AS excluded,
            SUM(CASE
                WHEN indexed = 0
                     AND excluded = 0
                     AND ( checked_at IS NULL OR checked_at < DATE_SUB( NOW(), INTERVAL {$recheck_days} DAY ) )
                THEN 1 ELSE 0
            END) AS recheck,
            SUM(CASE
                WHEN indexed = 0
                     AND excluded = 0
                     AND sent_at IS NOT NULL
                     AND sent_at < DATE_SUB( NOW(), INTERVAL {$resend_days} DAY )
                THEN 1 ELSE 0
            END) AS resend
         FROM {$table}",
        ARRAY_A
    );

    return [
        'total'       => (int) ( $row['total'] ?? 0 ),
        'unchecked'   => (int) ( $row['unchecked'] ?? 0 ),
        'unindexed'   => (int) ( $row['unindexed'] ?? 0 ),
        'indexed'     => (int) ( $row['indexed'] ?? 0 ),
        'excluded'    => (int) ( $row['excluded'] ?? 0 ),
        'recheck'     => (int) ( $row['recheck'] ?? 0 ),
        'resend'      => (int) ( $row['resend'] ?? 0 ),
        'snapshot_at' => time(),
    ];
}

function gim_refresh_stats_snapshot( bool $shift_previous = true ): void {
    if ( $shift_previous ) {
        $current = get_option( GIM_STATS_CURRENT_OPTION, [] );

        if ( ! empty( $current ) ) {
            update_option( GIM_STATS_PREVIOUS_OPTION, $current, false );
        }
    }

    $fresh = gim_calculate_stats();
    update_option( GIM_STATS_CURRENT_OPTION, $fresh, false );

    gim_invalidate_stats_cache();
}

function gim_force_refresh_stats_snapshot(): void {
    gim_refresh_stats_snapshot( false );
}

function gim_get_stats_snapshot(): array {
    $current = get_option( GIM_STATS_CURRENT_OPTION, [] );

    if ( empty( $current ) || ! isset( $current['total'] ) ) {
        $current = gim_calculate_stats();
        update_option( GIM_STATS_CURRENT_OPTION, $current, false );
    }

    return $current;
}

function gim_get_stats_previous(): array {
    $previous = get_option( GIM_STATS_PREVIOUS_OPTION, [] );

    if ( ! is_array( $previous ) || ! isset( $previous['total'] ) ) {
        return [];
    }

    return $previous;
}

function gim_get_stats_trend( string $key ): ?int {
    $current  = gim_get_stats_snapshot();
    $previous = gim_get_stats_previous();

    if ( empty( $previous ) || ! isset( $previous[ $key ] ) ) {
        return null;
    }

    return (int) $current[ $key ] - (int) $previous[ $key ];
}

/* -------------------------------------------------------------------------
 * Быстрый подсчёт.
 * ---------------------------------------------------------------------- */

function gim_get_stats(): array {
    static $memo = null;

    if ( $memo !== null ) {
        return $memo;
    }

    $cached = get_transient( GIM_STATS_CACHE_KEY );
    if ( is_array( $cached ) && isset( $cached['total'] ) ) {
        $memo = $cached;
        return $memo;
    }

    $stats = gim_calculate_stats();
    unset( $stats['snapshot_at'] );

    set_transient( GIM_STATS_CACHE_KEY, $stats, GIM_STATS_CACHE_TTL );

    $memo = $stats;
    return $memo;
}

/* -------------------------------------------------------------------------
 * Дневной лимит отправок.
 * ---------------------------------------------------------------------- */

function gim_get_sent_today(): int {
    $date = current_time( 'Y-m-d' );
    return (int) get_option( 'gim_sent_' . $date, 0 );
}

function gim_increment_count( int $count = 1 ): void {
    $date   = current_time( 'Y-m-d' );
    $option = 'gim_sent_' . $date;

    // Read-modify-write on an option is not atomic: two concurrent workers
    // could both read N and write N+1. The counter is guarded by a lock so a
    // burst of AJAX requests cannot undercount the daily quota.
    $locked = gim_acquire_lock( 'sent_counter_' . $date, 15 );

    try {
        $current = (int) get_option( $option, 0 );
        update_option( $option, $current + $count, false );
    } finally {
        if ( $locked ) {
            gim_release_lock( 'sent_counter_' . $date );
        }
    }
}

function gim_get_daily_send_cap(): int {
    $cap = (int) gim_get_option( 'daily_send_cap', 100 );
    return max( 1, min( 200, $cap ) );
}

function gim_can_send_local(): bool {
    return gim_get_sent_today() < gim_get_daily_send_cap();
}

/* -------------------------------------------------------------------------
 * Метки исчерпания квоты Google.
 * ---------------------------------------------------------------------- */

function gim_quota_key( string $api ): string {
    return 'gim_quota_exhausted_' . $api;
}

function gim_mark_quota_exhausted( string $api ): void {
    update_option( gim_quota_key( $api ), time(), false );

    unset( $GLOBALS['gim_quota_memo'][ $api ] );
}

function gim_get_quota_exhausted_at( string $api ): int {
    return (int) get_option( gim_quota_key( $api ), 0 );
}

function gim_is_quota_exhausted( string $api ): bool {
    // Request-scoped memo in a global so that marking the quota (a 429 in the
    // same request) can invalidate it; a static inside this function would keep
    // answering "not exhausted" after the marker was written.
    if ( isset( $GLOBALS['gim_quota_memo'][ $api ] ) ) {
        return (bool) $GLOBALS['gim_quota_memo'][ $api ];
    }

    $ts = gim_get_quota_exhausted_at( $api );
    if ( $ts === 0 ) {
        $GLOBALS['gim_quota_memo'][ $api ] = false;
        return false;
    }

    $today         = current_time( 'Y-m-d' );
    $exhausted_day = wp_date( 'Y-m-d', $ts, wp_timezone() );

    $GLOBALS['gim_quota_memo'][ $api ] = ( $today === $exhausted_day );

    return (bool) $GLOBALS['gim_quota_memo'][ $api ];
}

function gim_clear_quota_exhausted( string $api ): void {
    delete_option( gim_quota_key( $api ) );

    unset( $GLOBALS['gim_quota_memo'][ $api ] );
}

/* -------------------------------------------------------------------------
 * Ночное окно.
 * ---------------------------------------------------------------------- */

function gim_get_night_start_hour(): int {
    return max( 0, min( 23, (int) gim_get_option( 'night_start_hour', 22 ) ) );
}

function gim_get_night_end_hour(): int {
    return max( 0, min( 23, (int) gim_get_option( 'night_end_hour', 6 ) ) );
}

function gim_is_night_now(): bool {
    $hour  = (int) current_time( 'G' );
    $start = gim_get_night_start_hour();
    $end   = gim_get_night_end_hour();

    if ( $start === $end ) {
        return true;
    }

    if ( $start < $end ) {
        return $hour >= $start && $hour < $end;
    }

    return $hour >= $start || $hour < $end;
}

function gim_get_next_night_start(): DateTimeImmutable {
    $tz    = wp_timezone();
    $now   = new DateTimeImmutable( 'now', $tz );
    $start = gim_get_night_start_hour();

    $candidate = $now->setTime( $start, 0, 0 );

    if ( $candidate <= $now ) {
        $candidate = $candidate->modify( '+1 day' );
    }

    return $candidate;
}

function gim_seconds_until_night(): int {
    $now  = new DateTimeImmutable( 'now', wp_timezone() );
    $next = gim_get_next_night_start();

    return max( 60, $next->getTimestamp() - $now->getTimestamp() );
}

function gim_seconds_until_tomorrow(): int {
    $tz       = wp_timezone();
    $now      = new DateTimeImmutable( 'now', $tz );
    $tomorrow = $now->modify( 'tomorrow' )->setTime( 0, 0, 0 );

    return max( 60, $tomorrow->getTimestamp() - $now->getTimestamp() );
}

/* -------------------------------------------------------------------------
 * Пороги перепроверки/переотправки.
 * ---------------------------------------------------------------------- */

function gim_get_recheck_days(): int {
    return max( 1, min( 365, (int) gim_get_option( 'recheck_days', 14 ) ) );
}

function gim_get_resend_days(): int {
    return max( 1, min( 365, (int) gim_get_option( 'resend_days', 30 ) ) );
}

/* -------------------------------------------------------------------------
 * Срок хранения логов.
 * ---------------------------------------------------------------------- */

function gim_get_log_lifetime_days(): int {
    $days = (int) gim_get_option( 'log_lifetime_days', 30 );
    return max( 0, min( 3650, $days ) );
}

function gim_purge_old_logs(): int {
    $days = gim_get_log_lifetime_days();

    if ( $days <= 0 ) {
        return 0;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'gim_log';

    $deleted = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < DATE_SUB( NOW(), INTERVAL %d DAY )",
            $days
        )
    );

    return (int) $deleted;
}

/**
 * Hard ceiling on the number of log rows.
 *
 * Retention is a period, so "0 — хранить бессрочно" would let the table grow
 * without any limit: the healthcheck writes a debug line every 15 minutes, and
 * every worker tick writes an info line. The ceiling keeps the newest rows and
 * is independent of the retention setting; filter `gim_log_max_rows` changes it.
 */
function gim_get_log_max_rows(): int {
    $max = (int) apply_filters( 'gim_log_max_rows', GIM_LOG_MAX_ROWS );

    return max( GIM_LOG_MIN_MAX_ROWS, $max );
}

/**
 * Deletes the oldest rows above the ceiling, in batches.
 *
 * One DELETE per 1000 rows keeps the table lock short; the loop stops as soon
 * as the table is under the ceiling or a statement deletes nothing.
 *
 * @param int $max_rows Ceiling to enforce; 0 means gim_get_log_max_rows().
 * @return int Number of deleted rows.
 */
function gim_trim_log_rows( int $max_rows = 0 ): int {
    global $wpdb;

    $table    = $wpdb->prefix . 'gim_log';
    $max_rows = $max_rows > 0 ? $max_rows : gim_get_log_max_rows();

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal COUNT; the table name comes from $wpdb->prefix.
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

    $excess = $total - $max_rows;

    if ( $excess <= 0 ) {
        return 0;
    }

    $deleted = 0;

    while ( $excess > 0 ) {
        $limit = min( GIM_LOG_DELETE_BATCH, $excess );

        $rows = $wpdb->query(
            $wpdb->prepare( "DELETE FROM {$table} ORDER BY id ASC LIMIT %d", $limit )
        );

        if ( ! $rows ) {
            break;
        }

        $deleted += (int) $rows;
        $excess  -= (int) $rows;
    }

    return $deleted;
}

/* -------------------------------------------------------------------------
 * Типы контента для синхронизации URL.
 * ---------------------------------------------------------------------- */

/**
 * Выбранные типы контента для сбора URL.
 *
 * По умолчанию — посты и страницы. Возвращает массив идентификаторов
 * публичных post types.
 *
 * @return string[]
 */
function gim_get_selected_post_types(): array {
    $default = [ 'post', 'page' ];

    $raw = gim_get_option( 'post_types', $default );

    if ( ! is_array( $raw ) || empty( $raw ) ) {
        return $default;
    }

    // WPOSA хранит multicheck как ['post' => 'post', 'page' => 'page'].
    // Плоский массив ['post', 'page'] тоже понимаем.
    $ids = array_unique( array_merge( array_keys( $raw ), array_values( $raw ) ) );

    $public = get_post_types( [ 'public' => true ] );
    $ids    = array_values( array_intersect( $ids, $public ) );

    return empty( $ids ) ? $default : $ids;
}

/* -------------------------------------------------------------------------
 * Флаги статусов.
 * ---------------------------------------------------------------------- */

function gim_row_is_pending_recheck( $row ): bool {
    if ( ! is_object( $row ) || ! isset( $row->indexed ) ) {
        return false;
    }
    if ( (int) $row->indexed !== 0 ) {
        return false;
    }
    if ( (int) $row->excluded === 1 ) {
        return false;
    }
    $days = gim_get_recheck_days();
    if ( empty( $row->checked_at ) ) {
        return true;
    }
    $ts = strtotime( (string) $row->checked_at );
    if ( $ts === false ) {
        return true;
    }
    return ( time() - $ts ) >= ( $days * DAY_IN_SECONDS );
}

function gim_row_is_pending_resend( $row ): bool {
    if ( ! is_object( $row ) || ! isset( $row->indexed ) ) {
        return false;
    }
    if ( (int) $row->indexed !== 0 ) {
        return false;
    }
    if ( (int) $row->excluded === 1 ) {
        return false;
    }
    if ( empty( $row->sent_at ) ) {
        return false;
    }
    $days = gim_get_resend_days();
    $ts = strtotime( (string) $row->sent_at );
    if ( $ts === false ) {
        return false;
    }
    return ( time() - $ts ) >= ( $days * DAY_IN_SECONDS );
}

/* -------------------------------------------------------------------------
 * Исключающие теги.
 * ---------------------------------------------------------------------- */

function gim_get_excluded_tag_ids(): array {
    $ids = gim_get_option( 'excluded_tags', [] );

    if ( is_string( $ids ) ) {
        $ids = array_filter( array_map( 'trim', explode( ',', $ids ) ) );
    }

    if ( ! is_array( $ids ) ) {
        return [];
    }

    $ids = array_map( 'absint', $ids );
    $ids = array_filter( $ids );

    return array_values( array_unique( $ids ) );
}

function gim_recompute_exclusions(): int {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_unindexed';

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- literal UPDATE; the table name comes from $wpdb->prefix.
    $wpdb->query( "UPDATE {$table} SET excluded = 0" );

    $tag_ids = gim_get_excluded_tag_ids();
    if ( empty( $tag_ids ) ) {
        gim_invalidate_stats_cache();
        return 0;
    }

    // Exclusions must follow the same content types the queue is filled from,
    // otherwise posts of a custom post type would never be excluded.
    $post_types = gim_get_selected_post_types();

    if ( empty( $post_types ) ) {
        gim_invalidate_stats_cache();
        return 0;
    }

    $tag_placeholders  = implode( ',', array_fill( 0, count( $tag_ids ), '%d' ) );
    $type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

    $post_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT tr.object_id
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt
                 ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->posts} p
                 ON p.ID = tr.object_id
             WHERE tt.taxonomy = 'post_tag'
               AND tt.term_id IN ({$tag_placeholders})
               AND p.post_status = 'publish'
               AND p.post_type IN ({$type_placeholders})",
            ...array_merge( $tag_ids, $post_types )
        )
    );

    if ( empty( $post_ids ) ) {
        gim_invalidate_stats_cache();
        return 0;
    }

    $urls = [];
    foreach ( $post_ids as $post_id ) {
        $url = get_permalink( (int) $post_id );
        if ( $url ) {
            $urls[] = esc_url_raw( $url );
        }
    }

    $urls = array_values( array_unique( array_filter( $urls ) ) );
    if ( empty( $urls ) ) {
        gim_invalidate_stats_cache();
        return 0;
    }

    $hashes = array_map( 'gim_url_hash', $urls );

    $updated = 0;
    foreach ( array_chunk( $hashes, 500 ) as $chunk ) {
        $phs = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
        $sql = "UPDATE {$table} SET excluded = 1 WHERE url_hash IN ({$phs})";
        $res = $wpdb->query( $wpdb->prepare( $sql, ...$chunk ) );
        $updated += (int) $res;
    }

    gim_invalidate_stats_cache();
    return $updated;
}

function gim_get_excluded_count(): int {
    $stats = gim_get_stats();
    return (int) ( $stats['excluded'] ?? 0 );
}

/* -------------------------------------------------------------------------
 * Работа с таблицей URL.
 * ---------------------------------------------------------------------- */

/**
 * Stable hash of a URL: the unique key of the queue.
 *
 * The url column is varchar(2048) and a MySQL prefix index on it compares only
 * the first 191 characters, so two long URLs sharing a prefix looked like the
 * same row. The hash is a full sha256 of the value exactly as it is stored, so
 * the runtime and the migration always agree.
 */
function gim_url_hash( string $url ): string {
    return hash( 'sha256', $url );
}

function gim_add_urls( array $urls ): int {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_unindexed';

    $urls = array_filter( array_map( 'esc_url_raw', $urls ) );
    $urls = array_values( array_unique( $urls ) );

    if ( empty( $urls ) ) {
        return 0;
    }

    $existing = [];
    foreach ( array_chunk( $urls, 500 ) as $chunk ) {
        $hashes       = array_map( 'gim_url_hash', $chunk );
        $placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
        $sql          = "SELECT url_hash FROM {$table} WHERE url_hash IN ({$placeholders})";
        $found        = $wpdb->get_col( $wpdb->prepare( $sql, ...$hashes ) );
        if ( is_array( $found ) ) {
            foreach ( $found as $f ) {
                $existing[ $f ] = true;
            }
        }
    }

    $added = 0;
    foreach ( $urls as $url ) {
        $hash = gim_url_hash( $url );

        if ( isset( $existing[ $hash ] ) ) {
            continue;
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'url'        => $url,
                'url_hash'   => $hash,
                'indexed'    => null,
                'coverage'   => null,
                'checked_at' => null,
                'sent_at'    => null,
                'excluded'   => 0,
            ],
            [ '%s', '%s', '%d', '%s', '%s', '%s', '%d' ]
        );

        if ( $inserted ) {
            $added++;
            $existing[ $hash ] = true;
        } else {
            // One row per hash: a failure here is either a race with another
            // request or a real database problem. Logged without the URL body
            // being turned into an error stream.
            gim_log_debug( 'URL не добавлен в очередь: запись не прошла', [ 'url' => $url ] );
        }
    }

    if ( $added > 0 ) {
        gim_invalidate_stats_cache();
    }

    return $added;
}

function gim_clear_all_urls(): int {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_unindexed';
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- literal DELETE; the table name comes from $wpdb->prefix.
    $deleted = $wpdb->query( "DELETE FROM {$table}" );

    gim_invalidate_stats_cache();

    return (int) $deleted;
}

/* -------------------------------------------------------------------------
 * Выборки URL.
 * ---------------------------------------------------------------------- */

function gim_get_unchecked_urls( int $limit = 4 ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_unindexed';

    $results = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT url FROM {$table} WHERE indexed IS NULL ORDER BY id ASC LIMIT %d",
            $limit
        )
    );

    return is_array( $results ) ? $results : [];
}

function gim_get_urls_to_recheck( int $limit = 5 ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_unindexed';
    $days  = gim_get_recheck_days();

    $results = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT url FROM {$table}
             WHERE indexed = 0
               AND excluded = 0
               AND ( checked_at IS NULL OR checked_at < DATE_SUB( NOW(), INTERVAL %d DAY ) )
             ORDER BY checked_at ASC, id ASC
             LIMIT %d",
            $days,
            $limit
        )
    );

    return is_array( $results ) ? $results : [];
}

function gim_get_pending_urls( int $limit = 5 ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_unindexed';

    $results = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT url FROM {$table}
             WHERE indexed = 0
               AND sent_at IS NULL
               AND excluded = 0
             ORDER BY id ASC
             LIMIT %d",
            $limit
        )
    );

    return is_array( $results ) ? $results : [];
}

function gim_get_urls_to_resend( int $limit = 5 ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_unindexed';
    $days  = gim_get_resend_days();

    $results = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT url FROM {$table}
             WHERE indexed = 0
               AND sent_at IS NOT NULL
               AND excluded = 0
               AND sent_at < DATE_SUB( NOW(), INTERVAL %d DAY )
             ORDER BY sent_at ASC, id ASC
             LIMIT %d",
            $days,
            $limit
        )
    );

    return is_array( $results ) ? $results : [];
}

function gim_save_inspection( string $url, bool $indexed, string $coverage ): void {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_unindexed';

    $wpdb->update(
        $table,
        [
            'indexed'    => $indexed ? 1 : 0,
            'coverage'   => $coverage,
            'checked_at' => current_time( 'mysql' ),
        ],
        [ 'url_hash' => gim_url_hash( $url ) ],
        [ '%d', '%s', '%s' ],
        [ '%s' ]
    );

    gim_invalidate_stats_cache();
}

function gim_mark_sent( string $url ): void {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_unindexed';

    $wpdb->update(
        $table,
        [ 'sent_at' => current_time( 'mysql' ) ],
        [ 'url_hash' => gim_url_hash( $url ) ],
        [ '%s' ],
        [ '%s' ]
    );

    gim_invalidate_stats_cache();
}

/* -------------------------------------------------------------------------
 * Дополнительные счётчики.
 * ---------------------------------------------------------------------- */

function gim_get_recheck_count(): int {
    $stats = gim_get_stats();
    return (int) ( $stats['recheck'] ?? 0 );
}

function gim_get_resend_count(): int {
    $stats = gim_get_stats();
    return (int) ( $stats['resend'] ?? 0 );
}

/* -------------------------------------------------------------------------
 * Логи.
 * ---------------------------------------------------------------------- */

function gim_get_logs_count( string $level = '' ): int {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_log';

    if ( $level !== '' && $level !== 'all' ) {
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE level = %s", $level )
        );
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal COUNT; the table name comes from $wpdb->prefix.
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
}

function gim_get_logs( int $limit = 20, int $page = 1, string $level = 'all' ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_log';

    $limit  = max( 1, $limit );
    $page   = max( 1, $page );
    $offset = ( $page - 1 ) * $limit;

    if ( $level !== '' && $level !== 'all' ) {
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE level = %s ORDER BY id DESC LIMIT %d OFFSET %d",
            $level,
            $limit,
            $offset
        );
    } else {
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        );
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the SQL string is built with $wpdb->prepare() above.
    $rows = $wpdb->get_results( $sql );

    return is_array( $rows ) ? $rows : [];
}

function gim_get_logs_count_by_level(): array {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_log';

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal GROUP BY; the table name comes from $wpdb->prefix.
    $rows = $wpdb->get_results(
        "SELECT level, COUNT(*) AS cnt FROM {$table} GROUP BY level",
        ARRAY_A
    );

    $result = [
        'all'     => 0,
        'info'    => 0,
        'warning' => 0,
        'error'   => 0,
        'debug'   => 0,
    ];

    if ( ! is_array( $rows ) ) {
        return $result;
    }

    foreach ( $rows as $row ) {
        $lvl = (string) ( $row['level'] ?? '' );
        $cnt = (int) ( $row['cnt'] ?? 0 );

        $result['all'] += $cnt;

        if ( isset( $result[ $lvl ] ) ) {
            $result[ $lvl ] = $cnt;
        }
    }

    return $result;
}

/* -------------------------------------------------------------------------
 * Таблица на вкладке «URL».
 * ---------------------------------------------------------------------- */

function gim_filter_to_where( string $filter ): string {
    switch ( $filter ) {
        case 'unchecked':
            return 'WHERE indexed IS NULL';

        case 'indexed':
            return 'WHERE indexed = 1';

        case 'excluded':
            return 'WHERE excluded = 1';

        case 'recheck':
            $days = gim_get_recheck_days();
            return "WHERE indexed = 0 AND excluded = 0 AND ( checked_at IS NULL OR checked_at < DATE_SUB( NOW(), INTERVAL {$days} DAY ) )";

        case 'resend':
            $days = gim_get_resend_days();
            return "WHERE indexed = 0 AND sent_at IS NOT NULL AND excluded = 0 AND sent_at < DATE_SUB( NOW(), INTERVAL {$days} DAY )";

        case 'all':
            return '';

        case 'unindexed':
        default:
            return 'WHERE indexed = 0 AND excluded = 0';
    }
}

function gim_build_where_with_search( string $filter, string $search ): array {
    global $wpdb;

    $where = gim_filter_to_where( $filter );
    $args  = [];

    if ( $search !== '' ) {
        $like = '%' . $wpdb->esc_like( $search ) . '%';

        if ( $where === '' ) {
            $where = 'WHERE url LIKE %s';
        } else {
            $where .= ' AND url LIKE %s';
        }

        $args[] = $like;
    }

    return [ $where, $args ];
}

function gim_get_urls_count( string $filter = 'unindexed', string $search = '' ): int {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_unindexed';

    [ $where, $args ] = gim_build_where_with_search( $filter, $search );

    $sql = "SELECT COUNT(*) FROM {$table} {$where}";

    if ( ! empty( $args ) ) {
        $sql = $wpdb->prepare( $sql, ...$args );
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the SQL string is built with $wpdb->prepare() above when it has arguments.
    return (int) $wpdb->get_var( $sql );
}

function gim_get_urls_for_table( string $filter = 'unindexed', int $limit = 25, int $page = 1, string $search = '' ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_unindexed';

    [ $where, $args ] = gim_build_where_with_search( $filter, $search );

    $limit  = max( 1, $limit );
    $page   = max( 1, $page );
    $offset = ( $page - 1 ) * $limit;

    $args[] = $limit;
    $args[] = $offset;

    $sql = "SELECT * FROM {$table} {$where} ORDER BY checked_at DESC, id DESC LIMIT %d OFFSET %d";

    $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );

    return is_array( $rows ) ? $rows : [];
}