<?php
/**
 * Вкладка «Статистика».
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function gim_format_next_run( string $hook ): string {
    $next = wp_next_scheduled( $hook );
    if ( ! $next ) {
        return __( '— не запланировано —', 'google-indexing-manager' );
    }

    return sprintf(
        __( 'через %1$s (%2$s)', 'google-indexing-manager' ),
        human_time_diff( time(), $next ),
        wp_date( 'H:i:s', $next )
    );
}

function gim_get_trend_class( int $trend, bool $invert ): string {
    if ( $trend === 0 ) {
        return 'gim-trend--neutral';
    }
    $is_positive = $trend > 0;
    if ( $invert ) {
        $is_positive = ! $is_positive;
    }
    return $is_positive ? 'gim-trend--up' : 'gim-trend--down';
}

/**
 * Описание стат-карточек с трендами.
 *
 * @return array<string, array{label: string, accent: string, value: int, trend: int, invert: bool, hint: string}>
 */
function gim_build_stat_cards(): array {
    $current  = gim_get_stats_snapshot();
    $previous = gim_get_stats_previous();

    $has_previous = ! empty( $previous );

    $recheck_days = gim_get_recheck_days();
    $resend_days  = gim_get_resend_days();

    $make_trend = static function ( string $key ) use ( $current, $previous, $has_previous ): int {
        if ( ! $has_previous || ! isset( $current[ $key ] ) || ! isset( $previous[ $key ] ) ) {
            return 0;
        }
        return (int) $current[ $key ] - (int) $previous[ $key ];
    };

    $cards = [];

    $cards['total'] = [
        'label'  => __( 'Всего URL', 'google-indexing-manager' ),
        'accent' => '#2271b1',
        'value'  => (int) ( $current['total'] ?? 0 ),
        'trend'  => $make_trend( 'total' ),
        'invert' => false,
        'hint'   => __( 'Все записи в таблице URL', 'google-indexing-manager' ),
    ];

    $cards['unchecked'] = [
        'label'  => __( 'Не проверено', 'google-indexing-manager' ),
        'accent' => '#dba617',
        'value'  => (int) ( $current['unchecked'] ?? 0 ),
        'trend'  => $make_trend( 'unchecked' ),
        'invert' => true,
        'hint'   => __( 'Ещё не проходили проверку через API', 'google-indexing-manager' ),
    ];

    $cards['unindexed'] = [
        'label'  => __( 'Не проиндексировано', 'google-indexing-manager' ),
        'accent' => '#d63638',
        'value'  => (int) ( $current['unindexed'] ?? 0 ),
        'trend'  => $make_trend( 'unindexed' ),
        'invert' => true,
        'hint'   => __( 'Проверены, но не в индексе Google', 'google-indexing-manager' ),
    ];

    $cards['indexed'] = [
        'label'  => __( 'Проиндексировано', 'google-indexing-manager' ),
        'accent' => '#00a32a',
        'value'  => (int) ( $current['indexed'] ?? 0 ),
        'trend'  => $make_trend( 'indexed' ),
        'invert' => false,
        'hint'   => __( 'Найдены в индексе Google', 'google-indexing-manager' ),
    ];

    $cards['excluded'] = [
        'label'  => __( 'Исключено тегами', 'google-indexing-manager' ),
        'accent' => '#646970',
        'value'  => (int) ( $current['excluded'] ?? 0 ),
        'trend'  => $make_trend( 'excluded' ),
        'invert' => false,
        'hint'   => __( 'Пропускаются при отправке (не проверяются)', 'google-indexing-manager' ),
    ];

    $cards['recheck'] = [
        'label'  => __( 'Ждут перепроверки', 'google-indexing-manager' ),
        'accent' => '#9c27b0',
        'value'  => (int) ( $current['recheck'] ?? 0 ),
        'trend'  => $make_trend( 'recheck' ),
        'invert' => true,
        'hint'   => sprintf(
            /* translators: %d: days threshold */
            __( 'Не проиндексированы и не проверялись %d+ дн.', 'google-indexing-manager' ),
            (int) $recheck_days
        ),
    ];

    $cards['resend'] = [
        'label'  => __( 'Ждут переотправки', 'google-indexing-manager' ),
        'accent' => '#2196f3',
        'value'  => (int) ( $current['resend'] ?? 0 ),
        'trend'  => $make_trend( 'resend' ),
        'invert' => true,
        'hint'   => sprintf(
            /* translators: %d: days threshold */
            __( 'Не проиндексированы и не отправлялись %d+ дн.', 'google-indexing-manager' ),
            (int) $resend_days
        ),
    ];

    return $cards;
}

function gim_render_stats(): void {
    $sent_today = gim_get_sent_today();
    $cap        = gim_get_daily_send_cap();

    $inspection_exhausted = gim_is_quota_exhausted( 'inspection' );
    $indexing_exhausted   = gim_is_quota_exhausted( 'indexing' );

    $is_night    = gim_is_night_now();
    $night_start = gim_get_night_start_hour();
    $night_end   = gim_get_night_end_hour();

    $recheck_count = gim_get_recheck_count();
    $resend_count  = gim_get_resend_count();
    $recheck_days  = gim_get_recheck_days();
    $resend_days   = gim_get_resend_days();

    $check_batch = defined( 'GIM_CHECK_BATCH_SIZE' ) ? GIM_CHECK_BATCH_SIZE : 4;
    $send_batch  = defined( 'GIM_SEND_BATCH_SIZE' ) ? GIM_SEND_BATCH_SIZE : 5;

    $cards = gim_build_stat_cards();
    $order = gim_get_stats_order();

    $night_window = sprintf( '%02d:00–%02d:00', $night_start, $night_end );
    ?>
    <div class="gim-section">
        <div class="gim-stats-grid" id="gim-stats-grid">
            <?php foreach ( $order as $key ) : ?>
                <?php
                if ( ! isset( $cards[ $key ] ) ) {
                    continue;
                }
                $card = $cards[ $key ];
                ?>
                <div class="gim-stat gim-stat--<?php echo esc_attr( $key ); ?>"
                     data-key="<?php echo esc_attr( $key ); ?>"
                     style="--accent: <?php echo esc_attr( $card['accent'] ); ?>;"
                     title="<?php echo esc_attr( $card['hint'] ); ?>">
                    <div class="gim-stat__grip" aria-hidden="true">
                        <span></span><span></span><span></span>
                        <span></span><span></span><span></span>
                    </div>
                    <div class="gim-stat__value"><?php echo (int) $card['value']; ?></div>
                    <div class="gim-stat__label"><?php echo esc_html( $card['label'] ); ?></div>

                    <div class="gim-stat__trend <?php echo esc_attr( gim_get_trend_class( $card['trend'], $card['invert'] ) ); ?>">
                        <span class="gim-trend-arrow">
                            <?php if ( $card['trend'] > 0 ) : ?>
                                ↑
                            <?php elseif ( $card['trend'] < 0 ) : ?>
                                ↓
                            <?php else : ?>
                                →
                            <?php endif; ?>
                        </span>
                        <span class="gim-trend-value">
                            <?php echo esc_html( ( $card['trend'] > 0 ? '+' : '' ) . (int) $card['trend'] ); ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="gim-section">
        <table class="widefat striped gim-workers-table">
            <tbody>
            <tr>
                <td class="gim-workers-table__label"><?php esc_html_e( 'Проверка URL', 'google-indexing-manager' ); ?></td>
                <td>
                    <?php if ( $inspection_exhausted ) : ?>
                        <span class="gim-badge gim-badge-unindexed"><?php esc_html_e( 'Квота исчерпана', 'google-indexing-manager' ); ?></span>
                    <?php else : ?>
                        <span class="gim-badge gim-badge-indexed">OK</span>
                    <?php endif; ?>
                    <span class="gim-worker-value"><?php echo esc_html( gim_format_next_run( 'gim_cron_check_tick' ) ); ?></span>
                </td>
            </tr>
            <tr>
                <td class="gim-workers-table__label"><?php esc_html_e( 'Квота URL Inspection API', 'google-indexing-manager' ); ?></td>
                <td>
                    <?php if ( $inspection_exhausted ) : ?>
                        <span class="gim-badge gim-badge-unindexed"><?php esc_html_e( 'Исчерпана', 'google-indexing-manager' ); ?></span>
                        <span class="gim-worker-value">
                            <?php
                            printf(
                                esc_html__( 'сброс через %s', 'google-indexing-manager' ),
                                esc_html( human_time_diff( time(), time() + gim_seconds_until_tomorrow() ) )
                            );
                            ?>
                        </span>
                    <?php else : ?>
                        <span class="gim-badge gim-badge-indexed">OK</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td class="gim-workers-table__label"><?php esc_html_e( 'Отправка URL', 'google-indexing-manager' ); ?></td>
                <td>
                    <?php if ( $is_night ) : ?>
                        <span class="gim-badge gim-badge-indexed"><?php esc_html_e( 'Ночь', 'google-indexing-manager' ); ?></span>
                    <?php else : ?>
                        <span class="gim-badge gim-badge-unchecked"><?php esc_html_e( 'День', 'google-indexing-manager' ); ?></span>
                    <?php endif; ?>
                    <span class="gim-worker-value">
                        <?php
                        printf(
                            esc_html__( 'окно %1$s · %2$s', 'google-indexing-manager' ),
                            esc_html( $night_window ),
                            esc_html( gim_format_next_run( 'gim_cron_send_tick' ) )
                        );
                        ?>
                    </span>
                </td>
            </tr>
            <tr>
                <td class="gim-workers-table__label"><?php esc_html_e( 'Отправлено сегодня', 'google-indexing-manager' ); ?></td>
                <td>
                    <strong><?php echo (int) $sent_today; ?></strong>
                    <?php
                    printf(
                        esc_html__( 'из локального лимита %d', 'google-indexing-manager' ),
                        (int) $cap
                    );
                    ?>
                </td>
            </tr>
            <tr>
                <td class="gim-workers-table__label"><?php esc_html_e( 'Квота Indexing API', 'google-indexing-manager' ); ?></td>
                <td>
                    <?php if ( $indexing_exhausted ) : ?>
                        <span class="gim-badge gim-badge-unindexed">429</span>
                        <?php
                        $at = gim_get_quota_exhausted_at( 'indexing' );
                        if ( $at > 0 ) {
                            printf(
                                ' <span class="gim-worker-value">%s</span>',
                                esc_html( sprintf( __( 'последний 429 в %s', 'google-indexing-manager' ), wp_date( 'H:i:s', $at ) ) )
                            );
                        }
                        ?>
                    <?php else : ?>
                        <span class="gim-badge gim-badge-indexed">OK</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td class="gim-workers-table__label"><?php esc_html_e( 'Ждут перепроверки', 'google-indexing-manager' ); ?></td>
                <td>
                    <strong><?php echo (int) $recheck_count; ?></strong>
                    <?php
                    printf(
                        esc_html__( 'URL · порог %d дн.', 'google-indexing-manager' ),
                        (int) $recheck_days
                    );
                    ?>
                </td>
            </tr>
            <tr>
                <td class="gim-workers-table__label"><?php esc_html_e( 'Ждут переотправки', 'google-indexing-manager' ); ?></td>
                <td>
                    <strong><?php echo (int) $resend_count; ?></strong>
                    <?php
                    printf(
                        esc_html__( 'URL · порог %d дн.', 'google-indexing-manager' ),
                        (int) $resend_days
                    );
                    ?>
                </td>
            </tr>
            <tr>
                <td class="gim-workers-table__label"><?php esc_html_e( 'Синхронизация URL', 'google-indexing-manager' ); ?></td>
                <td>
                    <span class="gim-worker-value"><?php echo esc_html( gim_format_next_run( 'gim_cron_sync_urls_tick' ) ); ?></span>
                </td>
            </tr>
            <tr>
                <td class="gim-workers-table__label"><?php esc_html_e( 'Синхронизация исключений', 'google-indexing-manager' ); ?></td>
                <td>
                    <span class="gim-worker-value"><?php echo esc_html( gim_format_next_run( 'gim_cron_sync_tags_tick' ) ); ?></span>
                </td>
            </tr>
            <tr>
                <td class="gim-workers-table__label"><?php esc_html_e( 'Обновление статистики', 'google-indexing-manager' ); ?></td>
                <td>
                    <span class="gim-worker-value"><?php echo esc_html( gim_format_next_run( 'gim_stats_refresh_tick' ) ); ?></span>
                </td>
            </tr>
            </tbody>
        </table>
    </div>

    <div class="gim-section">
        <div class="gim-actions-bar">
            <button class="button button-primary" id="gim-run-check-now">
                <?php
                printf(
                    esc_html__( 'Проверить %d URL сейчас', 'google-indexing-manager' ),
                    (int) $check_batch
                );
                ?>
            </button>
            <button class="button" id="gim-run-send-now">
                <?php
                printf(
                    esc_html__( 'Отправить %d URL сейчас', 'google-indexing-manager' ),
                    (int) $send_batch
                );
                ?>
            </button>
            <button class="button" id="gim-sync">
                <?php esc_html_e( 'Синхронизировать URL', 'google-indexing-manager' ); ?>
            </button>
        </div>
        <p class="gim-actions-hint">
            <?php esc_html_e( 'Ручной запуск одного тика воркеров — выполняется в ближайший WP-Cron, игнорирует ночное окно.', 'google-indexing-manager' ); ?>
        </p>
        <p class="gim-progress" id="gim-progress" style="display:none;"></p>
    </div>
    <?php
}