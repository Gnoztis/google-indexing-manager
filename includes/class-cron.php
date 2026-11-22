<?php
/**
 * Фоновые задачи: только WP-Cron, в фоне.
 *
 * Recurring:
 *   - gim_cron_healthcheck    — раз в 15 минут, проверяет и восстанавливает цепочки.
 *
 * Single events (сами себя переставляют после выполнения):
 *   - gim_cron_check_tick        — проверка URL, каждые 5 минут.
 *   - gim_cron_send_tick         — отправка URL, ночью каждые 15 минут.
 *   - gim_cron_sync_urls_tick    — сбор URL из WordPress, ежедневно в 03:00.
 *   - gim_cron_sync_tags_tick    — пересчёт исключений, ежедневно в 03:15.
 *   - gim_stats_refresh_tick     — обновление снапшота статистики, раз в час.
 *   - gim_cron_logs_cleanup_tick — очистка старых логов, ежедневно в 04:00.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const GIM_CHECK_BATCH_SIZE   = 4;
const GIM_CHECK_INTERVAL     = 300;   // 5 минут
const GIM_SEND_BATCH_SIZE    = 5;
const GIM_SEND_INTERVAL      = 900;   // 15 минут
const GIM_SEND_IDLE_INTERVAL = 3600;

const GIM_SYNC_URLS_HOUR     = 3;
const GIM_SYNC_URLS_MINUTE   = 0;
const GIM_SYNC_TAGS_HOUR     = 3;
const GIM_SYNC_TAGS_MINUTE   = 15;
const GIM_LOGS_CLEANUP_HOUR  = 4;
const GIM_LOGS_CLEANUP_MIN   = 0;

const GIM_HEALTHCHECK_INTERVAL   = 900;   // 15 минут
const GIM_STATS_REFRESH_INTERVAL = 3600;  // час

/* -------------------------------------------------------------------------
 * Инициализация.
 * ---------------------------------------------------------------------- */

function gim_cron_init(): void {
    add_action( 'gim_cron_healthcheck', 'gim_cron_healthcheck_tick' );
    add_action( 'gim_stats_refresh_tick', 'gim_cron_stats_refresh_tick' );
    add_action( 'gim_cron_logs_cleanup_tick', 'gim_cron_logs_cleanup_tick' );

    add_action( 'gim_cron_check_tick', 'gim_cron_check_tick' );
    add_action( 'gim_cron_send_tick', 'gim_cron_send_tick', 10, 1 );
    add_action( 'gim_cron_sync_urls_tick', 'gim_cron_sync_urls_tick' );
    add_action( 'gim_cron_sync_tags_tick', 'gim_cron_sync_tags_tick' );

    // Мгновенный пересчёт исключений при сохранении списка тегов.
    add_action(
        'update_option_gim_excluded_tags',
        static function ( $old_value, $value, $option ) {
            if ( $old_value === $value ) {
                return;
            }
            if ( ! wp_next_scheduled( 'gim_cron_sync_tags_tick' ) ) {
                wp_schedule_single_event( time() + 30, 'gim_cron_sync_tags_tick' );
            }
        },
        10,
        3
    );

    // Перезапуск синхронизации URL при изменении типов контента.
    // Пользователь поменял типы — сразу увидит эффект, не дожидаясь 03:00.
    add_action(
        'update_option_gim_post_types',
        static function ( $old_value, $value, $option ) {
            if ( $old_value === $value ) {
                return;
            }
            wp_clear_scheduled_hook( 'gim_cron_sync_urls_tick' );
            wp_schedule_single_event( time() + 30, 'gim_cron_sync_urls_tick' );
        },
        10,
        3
    );
}

function gim_cron_register_healthcheck(): void {
    if ( wp_next_scheduled( 'gim_cron_healthcheck' ) ) {
        return;
    }

    wp_schedule_event(
        time() + 60,
        'gim_15min',
        'gim_cron_healthcheck'
    );
}

function gim_cron_healthcheck_bootstrap(): void {
    gim_cron_register_healthcheck();
    gim_ensure_workers_scheduled();
}

add_filter(
    'cron_schedules',
    static function ( $schedules ) {
        if ( ! isset( $schedules['gim_15min'] ) ) {
            $schedules['gim_15min'] = [
                'interval' => GIM_HEALTHCHECK_INTERVAL,
                'display'  => __( 'Every 15 Minutes (GIM)', 'google-indexing-manager' ),
            ];
        }
        return $schedules;
    }
);

/* -------------------------------------------------------------------------
 * Healthcheck.
 * ---------------------------------------------------------------------- */

function gim_cron_healthcheck_tick(): void {
    gim_ensure_workers_scheduled();
}

function gim_ensure_workers_scheduled(): void {
    if ( ! wp_next_scheduled( 'gim_cron_check_tick' ) ) {
        wp_schedule_single_event( time() + 30, 'gim_cron_check_tick' );
        gim_log_debug( 'Восстановлена цепочка проверки URL' );
    }

    if ( ! wp_next_scheduled( 'gim_cron_send_tick' ) ) {
        wp_schedule_single_event( time() + 60, 'gim_cron_send_tick' );
        gim_log_debug( 'Восстановлена цепочка отправки URL' );
    }

    if ( ! wp_next_scheduled( 'gim_cron_sync_urls_tick' ) ) {
        gim_schedule_next_sync_urls();
        gim_log_debug( 'Восстановлена цепочка синхронизации URL' );
    }

    if ( ! wp_next_scheduled( 'gim_cron_sync_tags_tick' ) ) {
        gim_schedule_next_sync_tags();
        gim_log_debug( 'Восстановлена цепочка синхронизации исключений' );
    }

    if ( ! wp_next_scheduled( 'gim_stats_refresh_tick' ) ) {
        wp_schedule_single_event( time() + 90, 'gim_stats_refresh_tick' );
        gim_log_debug( 'Восстановлена цепочка обновления статистики' );
    }

    if ( ! wp_next_scheduled( 'gim_cron_logs_cleanup_tick' ) ) {
        gim_schedule_next_logs_cleanup();
        gim_log_debug( 'Восстановлена цепочка очистки логов' );
    }
}

/* -------------------------------------------------------------------------
 * Обновление статистики.
 * ---------------------------------------------------------------------- */

function gim_cron_stats_refresh_tick(): void {
    if ( ! wp_next_scheduled( 'gim_stats_refresh_tick' ) ) {
        wp_schedule_single_event( time() + GIM_STATS_REFRESH_INTERVAL + 30, 'gim_stats_refresh_tick' );
    }

    gim_refresh_stats_snapshot( true );

    wp_clear_scheduled_hook( 'gim_stats_refresh_tick' );
    wp_schedule_single_event( time() + GIM_STATS_REFRESH_INTERVAL, 'gim_stats_refresh_tick' );
}

/* -------------------------------------------------------------------------
 * Очистка логов.
 * ---------------------------------------------------------------------- */

function gim_cron_logs_cleanup_tick(): void {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 );
    }
    @ignore_user_abort( true );

    if ( ! wp_next_scheduled( 'gim_cron_logs_cleanup_tick' ) ) {
        gim_schedule_next_logs_cleanup();
    }

    try {
        $deleted = gim_purge_old_logs();
        $days    = gim_get_log_lifetime_days();

        if ( $days > 0 ) {
            gim_log_debug(
                sprintf(
                    'Очистка логов: удалено %d записей старше %d дней',
                    $deleted,
                    $days
                )
            );
        }
    } catch ( Throwable $e ) {
        gim_log_error( 'Ошибка очистки логов: ' . $e->getMessage() );
    }

    gim_schedule_next_logs_cleanup();
}

function gim_schedule_next_logs_cleanup(): void {
    $tz     = wp_timezone();
    $now    = new DateTimeImmutable( 'now', $tz );
    $target = $now->setTime( GIM_LOGS_CLEANUP_HOUR, GIM_LOGS_CLEANUP_MIN, 0 );

    if ( $target <= $now ) {
        $target = $target->modify( '+1 day' );
    }

    wp_clear_scheduled_hook( 'gim_cron_logs_cleanup_tick' );
    wp_schedule_single_event( $target->getTimestamp(), 'gim_cron_logs_cleanup_tick' );
}

/* -------------------------------------------------------------------------
 * Планировщики single events.
 * ---------------------------------------------------------------------- */

function gim_schedule_next_check( int $delay_seconds ): void {
    $delay_seconds = max( 60, $delay_seconds );
    wp_clear_scheduled_hook( 'gim_cron_check_tick' );
    wp_schedule_single_event( time() + $delay_seconds, 'gim_cron_check_tick' );
}

function gim_schedule_next_send( int $delay_seconds ): void {
    $delay_seconds = max( 60, $delay_seconds );
    wp_clear_scheduled_hook( 'gim_cron_send_tick' );
    wp_schedule_single_event( time() + $delay_seconds, 'gim_cron_send_tick' );
}

function gim_schedule_next_sync_urls(): void {
    $tz     = wp_timezone();
    $now    = new DateTimeImmutable( 'now', $tz );
    $target = $now->setTime( GIM_SYNC_URLS_HOUR, GIM_SYNC_URLS_MINUTE, 0 );

    if ( $target <= $now ) {
        $target = $target->modify( '+1 day' );
    }

    wp_clear_scheduled_hook( 'gim_cron_sync_urls_tick' );
    wp_schedule_single_event( $target->getTimestamp(), 'gim_cron_sync_urls_tick' );
}

function gim_schedule_next_sync_tags(): void {
    $tz     = wp_timezone();
    $now    = new DateTimeImmutable( 'now', $tz );
    $target = $now->setTime( GIM_SYNC_TAGS_HOUR, GIM_SYNC_TAGS_MINUTE, 0 );

    if ( $target <= $now ) {
        $target = $target->modify( '+1 day' );
    }

    wp_clear_scheduled_hook( 'gim_cron_sync_tags_tick' );
    wp_schedule_single_event( $target->getTimestamp(), 'gim_cron_sync_tags_tick' );
}

/* -------------------------------------------------------------------------
 * Воркер: синхронизация URL.
 * ---------------------------------------------------------------------- */

function gim_cron_sync_urls_tick(): void {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 );
    }
    @ignore_user_abort( true );

    if ( ! wp_next_scheduled( 'gim_cron_sync_urls_tick' ) ) {
        gim_schedule_next_sync_urls();
    }

    if ( ! gim_acquire_lock( 'sync_urls', 900 ) ) {
        return;
    }

    try {
        $urls  = gim_get_site_urls();
        $added = gim_add_urls( $urls );

        gim_log_info(
            sprintf(
                'Синхронизация URL: найдено %d, добавлено %d новых',
                count( $urls ),
                $added
            )
        );
    } catch ( Throwable $e ) {
        gim_log_error( 'Ошибка синхронизации URL: ' . $e->getMessage() );
    } finally {
        gim_release_lock( 'sync_urls' );
    }

    gim_schedule_next_sync_urls();
}

/* -------------------------------------------------------------------------
 * Воркер: синхронизация исключений.
 * ---------------------------------------------------------------------- */

function gim_cron_sync_tags_tick(): void {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 );
    }
    @ignore_user_abort( true );

    if ( ! wp_next_scheduled( 'gim_cron_sync_tags_tick' ) ) {
        gim_schedule_next_sync_tags();
    }

    if ( ! gim_acquire_lock( 'sync_tags', 900 ) ) {
        return;
    }

    try {
        $excluded = gim_recompute_exclusions();

        gim_log_info(
            sprintf( 'Синхронизация исключений: помечено %d URL', $excluded )
        );
    } catch ( Throwable $e ) {
        gim_log_error( 'Ошибка синхронизации исключений: ' . $e->getMessage() );
    } finally {
        gim_release_lock( 'sync_tags' );
    }

    gim_schedule_next_sync_tags();
}

/* -------------------------------------------------------------------------
 * Воркер: проверка URL.
 * ---------------------------------------------------------------------- */

function gim_cron_check_tick(): void {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 );
    }
    @ignore_user_abort( true );

    if ( ! wp_next_scheduled( 'gim_cron_check_tick' ) ) {
        wp_schedule_single_event( time() + GIM_CHECK_INTERVAL + 30, 'gim_cron_check_tick' );
    }

    if ( ! gim_acquire_lock( 'check_tick', GIM_CHECK_INTERVAL ) ) {
        // Another request is already checking URLs: do not start a second run.
        gim_schedule_next_check( GIM_CHECK_INTERVAL );
        return;
    }

    try {
        if ( gim_is_quota_exhausted( 'inspection' ) ) {
            gim_log_info( 'Проверки приостановлены: дневная квота URL Inspection API исчерпана' );
            gim_schedule_next_check( gim_seconds_until_tomorrow() + 300 );
            return;
        }

        $urls = gim_get_unchecked_urls( GIM_CHECK_BATCH_SIZE );
        $mode = 'проверка';

        if ( empty( $urls ) ) {
            $urls = gim_get_urls_to_recheck( GIM_CHECK_BATCH_SIZE );
            $mode = 'перепроверка';
        }

        if ( empty( $urls ) ) {
            gim_schedule_next_check( GIM_SEND_IDLE_INTERVAL );
            return;
        }

        $checked = 0;
        $errors  = 0;

        foreach ( $urls as $url ) {
            $result = gim_inspect_url( $url );

            if ( $result['status'] === 'error' ) {
                $errors++;
                if ( gim_is_quota_exhausted( 'inspection' ) ) {
                    break;
                }
                continue;
            }

            gim_save_inspection( $url, $result['indexed'], (string) $result['coverage'] );
            $checked++;
            usleep( 200000 );
        }

        $stats = gim_get_stats();
        gim_log_info(
            sprintf(
                'Проверка (%s): обработано %d URL, ошибок %d, осталось: непроверенных %d, на перепроверку %d',
                $mode,
                $checked,
                $errors,
                $stats['unchecked'],
                gim_get_recheck_count()
            )
        );

        $has_work = $stats['unchecked'] > 0 || gim_get_recheck_count() > 0;
        gim_schedule_next_check( $has_work ? GIM_CHECK_INTERVAL : GIM_SEND_IDLE_INTERVAL );

    } finally {
        gim_release_lock( 'check_tick' );
    }
}

/* -------------------------------------------------------------------------
 * Воркер: отправка URL.
 * ---------------------------------------------------------------------- */

function gim_cron_send_tick( $force = false ): void {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 );
    }
    @ignore_user_abort( true );

    if ( ! wp_next_scheduled( 'gim_cron_send_tick' ) ) {
        wp_schedule_single_event( time() + GIM_SEND_INTERVAL + 30, 'gim_cron_send_tick' );
    }

    $force = (bool) $force;

    if ( ! gim_acquire_lock( 'send_tick', GIM_SEND_INTERVAL ) ) {
        // Another request is already sending: do not double-spend the quota.
        gim_schedule_next_send( GIM_SEND_INTERVAL );
        return;
    }

    try {
        if ( ! $force && ! gim_is_night_now() ) {
            $wait = gim_seconds_until_night();
            gim_log_debug(
                sprintf(
                    'Отправка отложена: не ночное окно. Следующая попытка через %s',
                    human_time_diff( time(), time() + $wait )
                )
            );
            gim_schedule_next_send( $wait );
            return;
        }

        if ( gim_is_quota_exhausted( 'indexing' ) ) {
            $wait = $force ? GIM_SEND_INTERVAL : gim_seconds_until_night();
            gim_log_warning( 'Отправка приостановлена: дневная квота Indexing API исчерпана (429)' );
            gim_schedule_next_send( $wait );
            return;
        }

        if ( ! gim_can_send_local() ) {
            $wait = $force ? GIM_SEND_INTERVAL : gim_seconds_until_night();
            gim_log_debug(
                sprintf(
                    'Отправка приостановлена: достигнут локальный лимит %d/%d',
                    gim_get_sent_today(),
                    gim_get_daily_send_cap()
                )
            );
            gim_schedule_next_send( $wait );
            return;
        }

        $limit = min( GIM_SEND_BATCH_SIZE, gim_get_daily_send_cap() - gim_get_sent_today() );

        $pending = gim_get_pending_urls( $limit );
        $mode    = 'отправка';

        if ( empty( $pending ) ) {
            $pending = gim_get_urls_to_resend( $limit );
            $mode    = 'переотправка';
        }

        if ( empty( $pending ) ) {
            gim_schedule_next_send( $force ? GIM_SEND_INTERVAL : GIM_SEND_IDLE_INTERVAL );
            return;
        }

        $sent = 0;

        foreach ( $pending as $url ) {
            $result = gim_publish_single( $url );

            if ( $result['ok'] ) {
                gim_increment_count( 1 );
                gim_mark_sent( $url );
                $sent++;
            } elseif ( ! empty( $result['is_quota_error'] ) ) {
                gim_log_error( 'Квота Indexing API исчерпана при отправке: ' . $result['message'], [ 'url' => $url ] );
                break;
            } else {
                gim_log_error(
                    sprintf( 'Ошибка отправки (%d): %s', (int) $result['code'], $result['message'] ),
                    [ 'url' => $url ]
                );
            }

            usleep( 200000 );
        }

        if ( $sent > 0 ) {
            gim_log_info(
                sprintf(
                    'Отправлено (%s) %d URL (сегодня всего: %d/%d)',
                    $mode,
                    $sent,
                    gim_get_sent_today(),
                    gim_get_daily_send_cap()
                )
            );
        }

        gim_schedule_next_send( GIM_SEND_INTERVAL );

    } finally {
        gim_release_lock( 'send_tick' );
    }
}

/* -------------------------------------------------------------------------
 * Сбор URL сайта.
 *
 * Использует типы контента из настроек (gim_post_types). По умолчанию
 * post и page. Пользователь может добавить любые публичные post types.
 * ---------------------------------------------------------------------- */

function gim_get_site_urls(): array {
    if ( function_exists( 'set_time_limit' ) ) {
        $disabled = ini_get( 'disable_functions' );
        if ( false === strpos( (string) $disabled, 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
    }

    $post_types = gim_get_selected_post_types();

    if ( empty( $post_types ) ) {
        gim_log_warning( 'Синхронизация URL: не выбрано ни одного типа контента' );
        return [];
    }

    $posts = get_posts(
        [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]
    );

    $urls = [];
    foreach ( $posts as $post_id ) {
        $permalink = get_permalink( $post_id );
        if ( $permalink ) {
            $urls[] = esc_url_raw( $permalink );
        }
    }

    return array_values( array_unique( array_filter( $urls ) ) );
}