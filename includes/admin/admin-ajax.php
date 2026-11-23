<?php
/**
 * AJAX-обработчики админки.
 *
 * Синхронизация URL и пересчёт исключений выполняются синхронно.
 * Массовые проверка и отправка идут циклом через `gim_check_url` /
 * `gim_send_url` со стороны браузера — по одному URL за запрос.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function gim_ajax_init(): void {
    $actions = [
        'gim_sync'                 => 'gim_ajax_sync',
        'gim_check_batch'          => 'gim_ajax_check_batch',
        'gim_check_url'            => 'gim_ajax_check_url',
        'gim_send_batch'           => 'gim_ajax_send_batch',
        'gim_send_url'             => 'gim_ajax_send_url',
        'gim_clear_urls'           => 'gim_ajax_clear_urls',
        'gim_clear_token'          => 'gim_ajax_clear_token',
        'gim_reset_statuses'       => 'gim_ajax_reset_statuses',
        'gim_run_check_now'        => 'gim_ajax_run_check_now',
        'gim_run_send_now'         => 'gim_ajax_run_send_now',
        'gim_diagnostic'           => 'gim_ajax_diagnostic',
        'gim_search_tags'          => 'gim_ajax_search_tags',
        'gim_recompute_exclusions' => 'gim_ajax_recompute_exclusions',
        'gim_save_stats_order'     => 'gim_ajax_save_stats_order',
        'gim_purge_logs'           => 'gim_ajax_purge_logs',
        'gim_restore_backup'       => 'gim_ajax_restore_backup',
    ];

    foreach ( $actions as $action => $callback ) {
        add_action( 'wp_ajax_' . $action, $callback );
    }
}

/* -------------------------------------------------------------------------
 * Хелперы.
 * ---------------------------------------------------------------------- */

function gim_ajax_guard(): void {
    if ( ! check_ajax_referer( 'gim_ajax', 'nonce', false ) ) {
        gim_security_log( 'nonce_failed', 'ajax' );
        wp_send_json_error( [ 'code' => 'gim_bad_nonce', 'message' => 'Недействительный nonce. Обновите страницу.' ], 403 );
    }
    if ( ! gim_current_user_is_admin() ) {
        gim_security_log( 'cap_denied', 'ajax' );
        wp_send_json_error( [ 'code' => 'gim_cap_denied', 'message' => 'Доступ запрещён.' ], 403 );
    }
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 );
    }
    @ignore_user_abort( true );
}

function gim_ajax_success( string $message, array $extra = [] ): void {
    wp_send_json_success( array_merge( [ 'message' => $message ], $extra ) );
}

function gim_ajax_error( string $message, int $status = 400 ): void {
    wp_send_json_error( [ 'message' => $message ], $status );
}

function gim_is_valid_http_url( string $url ): bool {
    $parts = wp_parse_url( $url );
    if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
        return false;
    }
    return in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true );
}

/**
 * Hosts a URL may belong to: the site home URL and the configured Search
 * Console property (URL-prefix or Domain property).
 *
 * The Indexing API quota and the service-account quota belong to this site, so
 * a foreign URL must never be submitted: it would spend the quota and abuse the
 * credentials. Only the exact host and its "www." variant are accepted.
 *
 * @return string[]
 */
function gim_site_hosts(): array {
    $candidates = [ home_url( '/' ) ];

    $property = gim_get_site_url();
    if ( 0 === stripos( $property, 'sc-domain:' ) ) {
        $candidates[] = 'https://' . substr( $property, strlen( 'sc-domain:' ) ) . '/';
    } else {
        $candidates[] = $property;
    }

    $hosts = [];
    foreach ( $candidates as $candidate ) {
        $host = strtolower( (string) wp_parse_url( (string) $candidate, PHP_URL_HOST ) );
        if ( '' === $host ) {
            continue;
        }
        $bare    = (string) preg_replace( '/^www\./', '', $host );
        $hosts[] = $bare;
        $hosts[] = 'www.' . $bare;
    }

    return array_values( array_unique( $hosts ) );
}

/**
 * Whether the URL belongs to this site (and may therefore be sent to Google).
 */
function gim_url_belongs_to_site( string $url ): bool {
    $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

    if ( '' === $host ) {
        return false;
    }

    return in_array( $host, gim_site_hosts(), true );
}

function gim_url_is_excluded( string $url ): bool {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_unindexed';
    $val   = $wpdb->get_var(
        $wpdb->prepare( "SELECT excluded FROM {$table} WHERE url_hash = %s LIMIT 1", gim_url_hash( $url ) )
    );
    return (int) $val === 1;
}

function gim_force_cron(): void {
    if ( function_exists( 'spawn_cron' ) ) {
        spawn_cron( time() );
    }
}

function gim_requeue_cron( string $hook, array $args = [], int $delay = 0 ): void {
    wp_clear_scheduled_hook( $hook );
    wp_schedule_single_event( time() + max( 0, $delay ), $hook, $args );
}

/* -------------------------------------------------------------------------
 * Сохранение порядка карточек статистики.
 * ---------------------------------------------------------------------- */

function gim_ajax_save_stats_order(): void {
    gim_ajax_guard();

    $allowed = [ 'total', 'unchecked', 'unindexed', 'indexed', 'excluded' ];

    $raw = isset( $_POST['order'] ) ? (array) wp_unslash( $_POST['order'] ) : [];
    $raw = array_map( 'sanitize_key', $raw );
    $raw = array_values( array_intersect( $raw, $allowed ) );

    if ( empty( $raw ) ) {
        $raw = $allowed;
    }

    update_user_meta( get_current_user_id(), 'gim_stats_order', $raw );

    gim_ajax_success( 'Порядок сохранён.' );
}

/* -------------------------------------------------------------------------
 * Поиск тегов.
 * ---------------------------------------------------------------------- */

function gim_ajax_search_tags(): void {
    gim_ajax_guard();

    $q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';

    $args = [
        'taxonomy'   => 'post_tag',
        'hide_empty' => false,
        'number'     => 30,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ];

    if ( $q !== '' ) {
        $args['name__like'] = $q;
    }

    $terms = get_terms( $args );

    if ( is_wp_error( $terms ) ) {
        gim_ajax_error( 'Ошибка получения тегов.' );
    }

    $result = [];
    foreach ( $terms as $term ) {
        $result[] = [
            'id'    => (int) $term->term_id,
            'name'  => (string) $term->name,
            'slug'  => (string) $term->slug,
            'count' => (int) $term->count,
        ];
    }

    gim_ajax_success( '', [ 'tags' => $result ] );
}

/* -------------------------------------------------------------------------
 * Синхронизация — синхронно.
 * ---------------------------------------------------------------------- */

function gim_ajax_sync(): void {
    gim_ajax_guard();

    try {
        $urls  = gim_get_site_urls();
        $added = gim_add_urls( $urls );

        $excluded = gim_recompute_exclusions();

        gim_force_refresh_stats_snapshot();

        gim_log_info(
            sprintf(
                'Синхронизация вручную: найдено %d URL, добавлено %d новых, помечено исключёнными %d',
                count( $urls ),
                $added,
                $excluded
            )
        );

        gim_ajax_success(
            sprintf(
                'Найдено %d URL. Добавлено %d новых. Помечено исключёнными %d.',
                count( $urls ),
                $added,
                $excluded
            )
        );
    } catch ( Throwable $e ) {
        gim_log_error( 'Ошибка синхронизации вручную' );
        gim_log_exception( $e );
        gim_ajax_error( 'Не удалось выполнить синхронизацию. Подробности — в логе.', 500 );
    }
}

/* -------------------------------------------------------------------------
 * Пересчёт исключений — синхронно.
 * ---------------------------------------------------------------------- */

function gim_ajax_recompute_exclusions(): void {
    gim_ajax_guard();

    try {
        $excluded = gim_recompute_exclusions();

        gim_force_refresh_stats_snapshot();

        gim_log_info( sprintf( 'Пересчёт исключений вручную: помечено %d URL', $excluded ) );

        gim_ajax_success( sprintf( 'Пересчёт выполнен. Помечено исключёнными: %d URL.', $excluded ) );
    } catch ( Throwable $e ) {
        gim_log_error( 'Ошибка пересчёта исключений' );
        gim_log_exception( $e );
        gim_ajax_error( 'Не удалось выполнить пересчёт. Подробности — в логе.', 500 );
    }
}

/* -------------------------------------------------------------------------
 * Очистка логов — синхронно.
 * ---------------------------------------------------------------------- */

function gim_ajax_purge_logs(): void {
    gim_ajax_guard();

    $days = gim_get_log_lifetime_days();

    try {
        $deleted = 0;

        if ( $days > 0 ) {
            $deleted = gim_purge_old_logs();

            gim_log_debug(
                sprintf( 'Очистка логов вручную: удалено %d записей старше %d дней', $deleted, $days )
            );
        }

        // The row ceiling is independent of the retention period, so it runs
        // even when the site keeps logs "forever".
        $trimmed = gim_trim_log_rows();

        if ( $trimmed > 0 ) {
            gim_log_debug(
                sprintf(
                    'Очистка логов вручную: удалено %d записей сверх потолка %d',
                    $trimmed,
                    gim_get_log_max_rows()
                )
            );
        }

        if ( $days > 0 ) {
            gim_ajax_success(
                sprintf(
                    'Удалено %d записей старше %d дней; сверх потолка — %d.',
                    $deleted,
                    $days,
                    $trimmed
                )
            );
            return;
        }

        gim_ajax_success(
            sprintf(
                'Срок хранения 0 (бессрочно): по возрасту ничего не удалено. Удалено %d записей сверх потолка %d.',
                $trimmed,
                gim_get_log_max_rows()
            )
        );
    } catch ( Throwable $e ) {
        gim_log_error( 'Ошибка очистки логов' );
        gim_log_exception( $e );
        gim_ajax_error( 'Не удалось очистить лог. Подробности — в логе.', 500 );
    }
}

/* -------------------------------------------------------------------------
 * Восстановление статусов из резервной копии.
 * ---------------------------------------------------------------------- */

function gim_ajax_restore_backup(): void {
    gim_ajax_guard();

    $backup = isset( $_POST['backup'] ) ? sanitize_text_field( wp_unslash( $_POST['backup'] ) ) : '';

    if ( '' === $backup ) {
        gim_ajax_error( 'Не указана резервная копия.' );
    }

    // Only a table recorded at migration time is accepted; the helper checks
    // the list again, so a forged table name can never be touched.
    $result = gim_restore_queue_backup( $backup );

    if ( empty( $result['ok'] ) ) {
        gim_ajax_error( $result['message'], 422 );
    }

    gim_ajax_success(
        $result['message'],
        [
            'updated'  => (int) $result['updated'],
            'inserted' => (int) $result['inserted'],
        ]
    );
}

/* -------------------------------------------------------------------------
 * Очистка URL.
 * ---------------------------------------------------------------------- */

function gim_ajax_clear_urls(): void {
    gim_ajax_guard();

    $deleted = gim_clear_all_urls();

    gim_force_refresh_stats_snapshot();

    gim_log_info( sprintf( 'Таблица URL очищена: удалено %d записей', $deleted ) );

    gim_ajax_success( sprintf( 'Таблица очищена. Удалено %d записей.', $deleted ) );
}

function gim_ajax_clear_token(): void {
    gim_ajax_guard();

    gim_clear_token_cache();
    gim_log_info( 'Кэш access token сброшен вручную' );

    gim_ajax_success( 'Кэш access token сброшен.' );
}

function gim_ajax_reset_statuses(): void {
    gim_ajax_guard();

    global $wpdb;
    $table = $wpdb->prefix . 'gim_unindexed';

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- literal UPDATE; the table name comes from $wpdb->prefix.
    $updated = $wpdb->query(
        "UPDATE {$table} SET indexed = NULL, coverage = NULL, checked_at = NULL"
    );

    gim_force_refresh_stats_snapshot();

    gim_log_info( sprintf( 'Статусы проверок сброшены: обновлено %d записей', (int) $updated ) );

    gim_ajax_success( sprintf( 'Статусы сброшены. Обновлено %d записей.', (int) $updated ) );
}

/* -------------------------------------------------------------------------
 * Проверка URL.
 * ---------------------------------------------------------------------- */

function gim_ajax_check_batch(): void {
    gim_ajax_guard();

    gim_requeue_cron( 'gim_cron_check_tick', [], 0 );
    gim_force_cron();

    gim_ajax_success( 'Задача проверки URL поставлена в очередь.' );
}

/**
 * Проверка одного URL.
 *
 * Используется и построчно, и в цикле массовых действий со стороны клиента.
 * При ошибке API возвращает ошибку (а не «Проверено» с coverage="error"),
 * чтобы клиент мог корректно отличить успех от сбоя.
 */
function gim_ajax_check_url(): void {
    gim_ajax_guard();

    $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
    if ( $url === '' || strlen( $url ) > 2000 || ! gim_is_valid_http_url( $url ) ) {
        gim_ajax_error( 'Некорректный URL.' );
    }

    if ( ! gim_url_belongs_to_site( $url ) ) {
        gim_ajax_error( 'URL не принадлежит этому сайту: проверка чужих адресов запрещена.', 422 );
    }

    if ( gim_is_quota_exhausted( 'inspection' ) ) {
        gim_ajax_error( 'Квота URL Inspection API исчерпана.', 429 );
    }

    $result = gim_inspect_url( $url );

    if ( $result['status'] === 'error' ) {
        gim_ajax_error( 'Ошибка проверки: ' . $result['coverage'], 500 );
    }

    gim_save_inspection( $url, $result['indexed'], (string) $result['coverage'] );

    $status_text = $result['indexed'] ? 'проиндексировано' : 'не проиндексировано';

    gim_ajax_success(
        sprintf( 'Проверено. Статус: %s. Покрытие: %s.', $status_text, $result['coverage'] ),
        [
            'indexed'    => (bool) $result['indexed'],
            'coverage'   => (string) $result['coverage'],
            'checked_at' => current_time( 'mysql' ),
        ]
    );
}

/* -------------------------------------------------------------------------
 * Отправка URL.
 * ---------------------------------------------------------------------- */

function gim_ajax_send_batch(): void {
    gim_ajax_guard();

    if ( gim_is_quota_exhausted( 'indexing' ) ) {
        gim_ajax_error( 'Квота Indexing API исчерпана.', 429 );
    }
    if ( ! gim_can_send_local() ) {
        gim_ajax_error( 'Достигнут локальный суточный лимит.', 429 );
    }

    gim_requeue_cron( 'gim_cron_send_tick', [ true ], 0 );
    gim_force_cron();

    gim_ajax_success( 'Задача отправки поставлена в очередь.' );
}

/**
 * Отправка одного URL. Используется построчно и в цикле массовых действий.
 */
function gim_ajax_send_url(): void {
    gim_ajax_guard();

    $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
    if ( $url === '' || strlen( $url ) > 2000 || ! gim_is_valid_http_url( $url ) ) {
        gim_ajax_error( 'Некорректный URL.' );
    }

    if ( ! gim_url_belongs_to_site( $url ) ) {
        gim_ajax_error( 'URL не принадлежит этому сайту: отправка чужих адресов запрещена.', 422 );
    }

    if ( gim_url_is_excluded( $url ) ) {
        gim_ajax_error( 'URL исключён из отправки (совпадает с исключающим тегом).', 422 );
    }

    if ( gim_is_quota_exhausted( 'indexing' ) ) {
        gim_ajax_error( 'Квота Indexing API исчерпана.', 429 );
    }
    if ( ! gim_can_send_local() ) {
        gim_ajax_error( 'Достигнут локальный суточный лимит.', 429 );
    }

    $sent = gim_publish_batch( [ $url ] );
    if ( $sent > 0 ) {
        gim_increment_count( $sent );
        gim_mark_sent( $url );

        gim_log_info( 'URL отправлен на индексацию', [ 'url' => $url ] );

        gim_ajax_success(
            'URL отправлен.',
            [ 'sent_at' => current_time( 'mysql' ) ]
        );
    }

    gim_ajax_error( 'Ошибка отправки. См. логи.', 500 );
}

/* -------------------------------------------------------------------------
 * Ручной запуск воркеров.
 * ---------------------------------------------------------------------- */

function gim_ajax_run_check_now(): void {
    gim_ajax_guard();

    if ( gim_is_quota_exhausted( 'inspection' ) ) {
        gim_ajax_error( 'Квота URL Inspection API исчерпана.', 429 );
    }

    gim_requeue_cron( 'gim_cron_check_tick', [], 0 );
    gim_force_cron();

    gim_ajax_success( 'Задача проверки поставлена в очередь.' );
}

function gim_ajax_run_send_now(): void {
    gim_ajax_guard();

    if ( gim_is_quota_exhausted( 'indexing' ) ) {
        gim_ajax_error( 'Квота Indexing API исчерпана.', 429 );
    }
    if ( ! gim_can_send_local() ) {
        gim_ajax_error(
            sprintf( 'Достигнут локальный лимит: %d/%d.', gim_get_sent_today(), gim_get_daily_send_cap() ),
            429
        );
    }

    gim_requeue_cron( 'gim_cron_send_tick', [ true ], 0 );
    gim_force_cron();

    gim_ajax_success( 'Задача отправки поставлена в очередь.' );
}

/* -------------------------------------------------------------------------
 * Диагностика.
 * ---------------------------------------------------------------------- */

function gim_ajax_diagnostic(): void {
    gim_ajax_guard();

    $results = [];

    // 1. JSON-ключ. The stored value is encrypted; only client_email, which is
    // not a secret, ever reaches the response.
    $key_state = gim_json_key_state();

    if ( ! $key_state['configured'] ) {
        $results['json_key'] = gim_diagnostic_result( 'fail', 'JSON-ключ не задан.' );
    } elseif ( ! $key_state['readable'] ) {
        $results['json_key'] = gim_diagnostic_result(
            'fail',
            'Ключ не читается: изменились соли WordPress или константа GIM_KEY_ENCRYPTION_KEY. Вставьте JSON-ключ заново на вкладке «Настройки».'
        );
    } else {
        $validation = Gim_Token_Crypto::validate( gim_get_json_key() );

        if ( ! $validation['ok'] ) {
            $results['json_key'] = gim_diagnostic_result( 'fail', esc_html( $validation['message'] ) );
        } else {
            $email = $key_state['client_email'] !== '' ? $key_state['client_email'] : $validation['client_email'];
            $results['json_key'] = gim_diagnostic_result(
                'ok',
                'Аккаунт: <code>' . esc_html( $email ) . '</code>'
            );
        }
    }

    // 2. Access token.
    gim_clear_token_cache();
    $token = gim_get_access_token();
    if ( ! $token ) {
        $results['token'] = gim_diagnostic_result( 'fail', 'Не удалось получить токен. См. логи.' );
        gim_diagnostic_finish( $results );
        return;
    }
    $results['token'] = gim_diagnostic_result( 'ok', 'Токен получен.' );

    // 3. Список ресурсов.
    $list = gim_list_sites();
    if ( ! $list['ok'] || empty( $list['sites'] ) ) {
        $results['sites'] = gim_diagnostic_result( 'fail', 'Нет доступных ресурсов.' );
        gim_diagnostic_finish( $results );
        return;
    }

    $sites_lines = [];
    foreach ( $list['sites'] as $site ) {
        $sites_lines[] = sprintf(
            '<code>%s</code> <span class="gim-worker-value">(%s)</span>',
            esc_html( $site['siteUrl'] ),
            esc_html( $site['permissionLevel'] )
        );
    }
    $results['sites'] = gim_diagnostic_result(
        'ok',
        sprintf( 'Доступно: %d.<br>%s', count( $list['sites'] ), implode( '<br>', $sites_lines ) )
    );

    // 4. Совпадение siteUrl.
    $check = gim_diagnostic_check_site_url();
    $results['site_match'] = $check['ok']
        ? gim_diagnostic_result( 'ok', esc_html( $check['message'] ) )
        : gim_diagnostic_result( 'fail', esc_html( $check['message'] ) );

    // 5. Права.
    $configured = gim_get_site_url();
    $permission = '';
    foreach ( $list['sites'] as $site ) {
        if ( $site['siteUrl'] === $configured || $site['siteUrl'] === trailingslashit( $configured ) ) {
            $permission = $site['permissionLevel'];
            break;
        }
    }

    if ( $permission === '' ) {
        $results['permissions'] = gim_diagnostic_result( 'warning', 'Ресурс не найден. Не удалось определить уровень прав.' );
    } elseif ( in_array( $permission, [ 'siteOwner', 'siteFullUser' ], true ) ) {
        $results['permissions'] = gim_diagnostic_result( 'ok', sprintf( 'Уровень прав: <code>%s</code>.', esc_html( $permission ) ) );
    } else {
        $results['permissions'] = gim_diagnostic_result( 'warning', sprintf( 'Уровень прав <code>%s</code> — недостаточно. Нужен siteOwner или siteFullUser.', esc_html( $permission ) ) );
    }

    // 6. URL Inspection API.
    $test_url = home_url( '/' );
    $inspect  = gim_inspect_url( $test_url );
    if ( $inspect['status'] === 'error' ) {
        $results['inspection_api'] = gim_diagnostic_result(
            'fail',
            sprintf( 'Проверка <code>%s</code>: %s', esc_html( $test_url ), esc_html( $inspect['coverage'] ) )
        );
    } else {
        $results['inspection_api'] = gim_diagnostic_result(
            'ok',
            sprintf( 'URL <code>%s</code>. Покрытие: %s', esc_html( $test_url ), esc_html( $inspect['coverage'] ) )
        );
    }

    // 7. Indexing API — only sends anything when the administrator asked for it.
    $probe = isset( $_POST['probe'] ) && '1' === sanitize_key( wp_unslash( $_POST['probe'] ) );

    $results['indexing_api'] = gim_diagnostic_indexing_probe( (bool) $probe );

    // 8. Схема БД.
    global $wpdb;
    $table_log       = $wpdb->prefix . 'gim_log';
    $table_unindexed = $wpdb->prefix . 'gim_unindexed';

    $log_exists       = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_log ) ) === $table_log );
    $unindexed_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_unindexed ) ) === $table_unindexed );

    if ( $log_exists && $unindexed_exists ) {
        $results['db_schema'] = gim_diagnostic_result( 'ok', 'Обе таблицы существуют.' );
    } else {
        $missing = [];
        if ( ! $log_exists ) {
            $missing[] = $table_log;
        }
        if ( ! $unindexed_exists ) {
            $missing[] = $table_unindexed;
        }
        $results['db_schema'] = gim_diagnostic_result(
            'fail',
            'Отсутствуют таблицы: <code>' . implode( '</code>, <code>', array_map( 'esc_html', $missing ) ) . '</code>'
        );
    }

    // 9. Cron.
    $hooks = [
        'gim_cron_healthcheck'       => 'Healthcheck',
        'gim_cron_check_tick'        => 'Проверка URL',
        'gim_cron_send_tick'         => 'Отправка URL',
        'gim_cron_sync_urls_tick'    => 'Синхронизация URL',
        'gim_cron_sync_tags_tick'    => 'Синхронизация исключений',
        'gim_stats_refresh_tick'     => 'Обновление статистики',
        'gim_cron_logs_cleanup_tick' => 'Очистка логов',
    ];
    $missing_hooks = [];
    $scheduled     = [];
    foreach ( $hooks as $hook => $label ) {
        $next = wp_next_scheduled( $hook );
        if ( $next ) {
            $scheduled[] = sprintf(
                '%s — %s',
                esc_html( $label ),
                esc_html( human_time_diff( time(), $next ) )
            );
        } else {
            $missing_hooks[] = $label;
        }
    }

    if ( empty( $missing_hooks ) ) {
        $results['cron'] = gim_diagnostic_result( 'ok', implode( '<br>', $scheduled ) );
    } else {
        $results['cron'] = gim_diagnostic_result(
            'warning',
            'Не запланированы: <strong>' . esc_html( implode( ', ', $missing_hooks ) ) . '</strong>. Healthcheck восстановит при следующем запуске.'
        );
    }

    // 10. Локальная квота.
    $sent = gim_get_sent_today();
    $cap  = gim_get_daily_send_cap();

    if ( $sent >= $cap ) {
        $results['local_quota'] = gim_diagnostic_result(
            'warning',
            sprintf( 'Отправлено сегодня: %d из %d. Лимит исчерпан.', $sent, $cap )
        );
    } else {
        $results['local_quota'] = gim_diagnostic_result(
            'ok',
            sprintf( 'Отправлено сегодня: %d из %d.', $sent, $cap )
        );
    }

    // 11. Исключающие теги.
    $tag_ids = gim_get_excluded_tag_ids();
    if ( empty( $tag_ids ) ) {
        $results['tags'] = gim_diagnostic_result( 'ok', 'Теги-исключения не заданы.' );
    } else {
        $terms = get_terms(
            [
                'taxonomy'   => 'post_tag',
                'include'    => $tag_ids,
                'hide_empty' => false,
            ]
        );

        $valid_ids = [];
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $valid_ids[] = $term->term_id;
            }
        }

        $invalid = array_diff( $tag_ids, $valid_ids );
        if ( ! empty( $invalid ) ) {
            $results['tags'] = gim_diagnostic_result(
                'warning',
                sprintf( 'Часть тегов не найдена: %s.', esc_html( implode( ', ', $invalid ) ) )
            );
        } else {
            $results['tags'] = gim_diagnostic_result(
                'ok',
                sprintf( 'Активных тегов: %d.', count( $valid_ids ) )
            );
        }
    }

    gim_diagnostic_finish( $results );
}

/**
 * Indexing API step of the diagnostics.
 *
 * By default it proves as much as possible without writing: the token, the
 * property list, the permissions and the URL Inspection call already show that
 * the service account works. A real URL notification is sent only when the
 * administrator explicitly asks for it, because it spends one of the 200 daily
 * notifications; such a probe is counted in the local daily counter, so it
 * cannot silently bypass the cap.
 *
 * @param bool $probe Whether to send a real URL notification.
 * @return array{status:string,status_label:string,status_class:string,message:string}
 */
function gim_diagnostic_indexing_probe( bool $probe ): array {
    $test_url = home_url( '/' );

    if ( ! $probe ) {
        return gim_diagnostic_result(
            'warning',
            sprintf(
                'Пробная отправка не выполнялась, чтобы не расходовать суточную квоту. Для реальной проверки включите «Выполнить пробную отправку URL» и запустите диагностику снова. URL для проверки: <code>%s</code>.',
                esc_html( $test_url )
            )
        );
    }

    if ( gim_is_quota_exhausted( 'indexing' ) ) {
        return gim_diagnostic_result(
            'fail',
            'Пробная отправка не выполнялась: суточная квота Indexing API уже исчерпана.'
        );
    }

    $publish = gim_publish_single( $test_url );

    if ( ! $publish['ok'] ) {
        return gim_diagnostic_result(
            'fail',
            sprintf( 'HTTP %d: %s', (int) $publish['code'], esc_html( $publish['message'] ) )
        );
    }

    // One real notification was consumed: count it against the daily cap.
    gim_increment_count( 1 );

    return gim_diagnostic_result(
        'ok',
        sprintf(
            'Пробная отправка прошла успешно; израсходовано 1 уведомление (сегодня %d из %d).',
            gim_get_sent_today(),
            gim_get_daily_send_cap()
        )
    );
}

function gim_diagnostic_result( string $status, string $message ): array {
    $map = [
        'ok'      => [ 'label' => 'OK',       'class' => 'gim-badge-indexed' ],
        'fail'    => [ 'label' => 'Ошибка',   'class' => 'gim-badge-unindexed' ],
        'warning' => [ 'label' => 'Внимание', 'class' => 'gim-badge-unchecked' ],
    ];

    $meta = $map[ $status ] ?? $map['fail'];

    return [
        'status'       => $status,
        'status_label' => $meta['label'],
        'status_class' => $meta['class'],
        'message'      => $message,
    ];
}

function gim_diagnostic_finish( array $results ): void {
    $has_error   = false;
    $has_warning = false;
    foreach ( $results as $r ) {
        if ( $r['status'] === 'fail' ) {
            $has_error = true;
        } elseif ( $r['status'] === 'warning' ) {
            $has_warning = true;
        }
    }

    if ( $has_error ) {
        $summary = 'Диагностика выявила проблемы — обратите внимание на строки со статусом «Ошибка».';
    } elseif ( $has_warning ) {
        $summary = 'Есть предупреждения. Функционально плагин работает, но некоторые настройки стоит проверить.';
    } else {
        $summary = 'Все проверки пройдены успешно.';
    }

    wp_send_json_success(
        [
            'results'     => $results,
            'has_error'   => $has_error,
            'has_warning' => $has_warning,
            'summary'     => $summary,
        ]
    );
}