<?php
/**
 * Вкладка «URL».
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Отформатировать дату для таблицы URL: d.m H:i.
 */
function gim_format_row_datetime( $datetime ): string {
    $datetime = (string) $datetime;

    if ( $datetime === '' || $datetime === '0000-00-00 00:00:00' ) {
        return '';
    }

    $ts = strtotime( $datetime );
    if ( $ts === false ) {
        return '';
    }

    return wp_date( 'd.m H:i', $ts );
}

/**
 * Определить per_page для вкладки URL.
 *
 * Возвращаемое значение гарантированно входит в GIM_URLS_PER_PAGE_OPTIONS.
 */
function gim_resolve_urls_per_page(): int {
    $allowed = GIM_URLS_PER_PAGE_OPTIONS;
    $default = GIM_URLS_PER_PAGE;
    $user_id = get_current_user_id();

    if ( isset( $_GET['per_page'] ) ) {
        $get_per_page = (int) $_GET['per_page'];

        if ( in_array( $get_per_page, $allowed, true ) ) {
            $saved = (int) get_user_meta( $user_id, 'gim_urls_per_page', true );

            if ( $saved !== $get_per_page ) {
                update_user_meta( $user_id, 'gim_urls_per_page', $get_per_page );
            }

            return $get_per_page;
        }
    }

    $saved = (int) get_user_meta( $user_id, 'gim_urls_per_page', true );

    if ( in_array( $saved, $allowed, true ) ) {
        return $saved;
    }

    if ( $saved !== 0 ) {
        update_user_meta( $user_id, 'gim_urls_per_page', $default );
    }

    return $default;
}

function gim_render_urls(): void {
    $filter  = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'unindexed';
    $allowed = [ 'all', 'unchecked', 'unindexed', 'indexed', 'excluded', 'recheck', 'resend' ];
    if ( ! in_array( $filter, $allowed, true ) ) {
        $filter = 'unindexed';
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

    $paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $per_page = gim_resolve_urls_per_page();

    $total = gim_get_urls_count( $filter, $search );
    $pages = max( 1, (int) ceil( $total / $per_page ) );

    if ( $paged > $pages ) {
        $paged = $pages;
    }

    $rows = gim_get_urls_for_table( $filter, $per_page, $paged, $search );

    $query_args = [
        'page'     => GIM_SLUG,
        'tab'      => 'urls',
        'filter'   => $filter,
        'per_page' => $per_page,
    ];
    if ( $search !== '' ) {
        $query_args['s'] = $search;
    }

    $base_url = add_query_arg( $query_args, admin_url( 'options-general.php' ) );

    $reset_url = add_query_arg(
        [
            'page'     => GIM_SLUG,
            'tab'      => 'urls',
            'filter'   => $filter,
            'per_page' => $per_page,
        ],
        admin_url( 'options-general.php' )
    );

    // Fallback для шаблона. Один option гарантированно получит selected.
    $per_page_options = GIM_URLS_PER_PAGE_OPTIONS;

    if ( ! is_array( $per_page_options ) || empty( $per_page_options ) ) {
        $per_page_options = [ $per_page ];
    }

    $per_page_options  = array_values( array_map( 'intval', $per_page_options ) );
    $per_page_selected = in_array( $per_page, $per_page_options, true )
        ? (int) $per_page
        : (int) $per_page_options[0];
    ?>
    <h3 class="gim-filter-title">
        <?php esc_html_e( 'Фильтр:', 'google-indexing-manager' ); ?>
        <?php
        $filters = [
            'unindexed' => __( 'Не проиндексированные', 'google-indexing-manager' ),
            'unchecked' => __( 'Непроверенные', 'google-indexing-manager' ),
            'indexed'   => __( 'Проиндексированные', 'google-indexing-manager' ),
            'excluded'  => __( 'Исключённые', 'google-indexing-manager' ),
            'recheck'   => __( 'Ждут перепроверки', 'google-indexing-manager' ),
            'resend'    => __( 'Ждут переотправки', 'google-indexing-manager' ),
            'all'       => __( 'Все', 'google-indexing-manager' ),
        ];
        $links = [];
        foreach ( $filters as $key => $label ) {
            $url_args = [
                'page'     => GIM_SLUG,
                'tab'      => 'urls',
                'filter'   => $key,
                'per_page' => $per_page,
            ];
            if ( $search !== '' ) {
                $url_args['s'] = $search;
            }
            $url     = add_query_arg( $url_args, admin_url( 'options-general.php' ) );
            $class   = $filter === $key ? 'gim-filter-active' : '';
            $links[] = '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
        }
        echo implode( ' ', $links );
        ?>
    </h3>

    <form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" class="gim-urls-search" autocomplete="off">
        <input type="hidden" name="page" value="<?php echo esc_attr( GIM_SLUG ); ?>">
        <input type="hidden" name="tab" value="urls">
        <input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
        <input type="hidden" name="per_page" value="<?php echo esc_attr( $per_page ); ?>">
        <input type="search"
               name="s"
               value="<?php echo esc_attr( $search ); ?>"
               class="gim-urls-search__input"
               placeholder="<?php esc_attr_e( 'Поиск по URL…', 'google-indexing-manager' ); ?>"
               autocomplete="off">
        <button type="submit" class="button">
            <?php esc_html_e( 'Найти', 'google-indexing-manager' ); ?>
        </button>
        <?php if ( $search !== '' ) : ?>
            <a href="<?php echo esc_url( $reset_url ); ?>" class="button button-link">
                <?php esc_html_e( 'Сбросить', 'google-indexing-manager' ); ?>
            </a>
        <?php endif; ?>
    </form>

    <div class="gim-urls-meta">
        <p class="description">
            <?php
            if ( $search !== '' ) {
                printf(
                    esc_html__( 'Найдено %1$d из %2$d по запросу «%5$s». Страница %3$d из %4$d.', 'google-indexing-manager' ),
                    count( $rows ),
                    (int) $total,
                    (int) $paged,
                    (int) $pages,
                    esc_html( $search )
                );
            } else {
                printf(
                    esc_html__( 'Показано %1$d из %2$d. Страница %3$d из %4$d.', 'google-indexing-manager' ),
                    count( $rows ),
                    (int) $total,
                    (int) $paged,
                    (int) $pages
                );
            }
            ?>
        </p>
        <div class="gim-per-page-select">
            <label for="gim-urls-per-page">
                <?php esc_html_e( 'Показывать:', 'google-indexing-manager' ); ?>
            </label>
            <span class="gim-per-page-select__wrap">
                <select
                    id="gim-urls-per-page"
                    name="per_page"
                    autocomplete="off"
                    data-selected="<?php echo (int) $per_page_selected; ?>">
                    <?php foreach ( $per_page_options as $opt ) : ?>
                        <?php $is_sel = ( (int) $per_page_selected === (int) $opt ); ?>
                        <option
                            value="<?php echo esc_attr( (string) $opt ); ?>"<?php echo $is_sel ? ' selected="selected"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- literal attribute, guarded by a strict comparison. ?>>
                            <?php echo (int) $opt; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </span>
        </div>
    </div>

    <table class="wp-list-table widefat fixed striped gim-urls-table">
        <thead>
        <tr>
            <th class="gim-col-checkbox">
                <input type="checkbox"
                       id="gim-select-all"
                       autocomplete="off"
                       aria-label="<?php esc_attr_e( 'Выбрать все', 'google-indexing-manager' ); ?>">
            </th>
            <th style="width:50%;"><?php esc_html_e( 'URL', 'google-indexing-manager' ); ?></th>
            <th style="width:250px;"><?php esc_html_e( 'Статус', 'google-indexing-manager' ); ?></th>
            <th style="width:15%;"><?php esc_html_e( 'Покрытие', 'google-indexing-manager' ); ?></th>
            <th style="width:110px;"><?php esc_html_e( 'Проверено', 'google-indexing-manager' ); ?></th>
            <th style="width:110px;"><?php esc_html_e( 'Отправлено', 'google-indexing-manager' ); ?></th>
            <th style="width:160px;"><?php esc_html_e( 'Действия', 'google-indexing-manager' ); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if ( empty( $rows ) ) : ?>
            <tr>
                <td colspan="7" class="gim-urls-empty">
                    <?php
                    if ( $search !== '' ) {
                        esc_html_e( 'По вашему запросу ничего не найдено.', 'google-indexing-manager' );
                    } else {
                        esc_html_e( 'Нет данных.', 'google-indexing-manager' );
                    }
                    ?>
                </td>
            </tr>
        <?php else : ?>
            <?php foreach ( $rows as $row ) : ?>
                <?php
                $checked_raw = (string) ( $row->checked_at ?? '' );
                $sent_raw    = (string) ( $row->sent_at ?? '' );
                ?>
                <tr>
                    <td class="gim-col-checkbox">
                        <input type="checkbox"
                               class="gim-row-checkbox"
                               value="<?php echo esc_attr( $row->url ); ?>"
                               autocomplete="off"
                               aria-label="<?php echo esc_attr( sprintf( __( 'Выбрать %s', 'google-indexing-manager' ), $row->url ) ); ?>">
                    </td>
                    <td class="gim-cell-url">
                        <a href="<?php echo esc_url( $row->url ); ?>"
                           target="_blank"
                           rel="noopener"
                           class="gim-ellipsis"
                           title="<?php echo esc_attr( $row->url ); ?>">
                            <?php echo esc_html( $row->url ); ?>
                        </a>
                    </td>
                    <td>
                        <span class="gim-status-badges">
                            <?php echo gim_get_status_badge_html( $row->indexed ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <?php if ( (int) $row->excluded === 1 ) : ?>
                                <span class="gim-badge gim-badge-excluded" title="<?php esc_attr_e( 'Исключён из отправки', 'google-indexing-manager' ); ?>">
                                    <?php esc_html_e( 'Исключён', 'google-indexing-manager' ); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ( gim_row_is_pending_recheck( $row ) ) : ?>
                                <span class="gim-badge gim-badge-recheck" title="<?php esc_attr_e( 'Пора перепроверить статус', 'google-indexing-manager' ); ?>">
                                    <?php esc_html_e( 'Ждёт перепроверки', 'google-indexing-manager' ); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ( gim_row_is_pending_resend( $row ) ) : ?>
                                <span class="gim-badge gim-badge-resend" title="<?php esc_attr_e( 'Пора отправить повторно', 'google-indexing-manager' ); ?>">
                                    <?php esc_html_e( 'Ждёт переотправки', 'google-indexing-manager' ); ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </td>
                    <?php $coverage = (string) ( $row->coverage ?? '' ); ?>
                    <td class="gim-cell-coverage">
                        <span class="gim-ellipsis" title="<?php echo esc_attr( $coverage ); ?>">
                            <?php echo esc_html( $coverage ); ?>
                        </span>
                    </td>
                    <td class="gim-cell-date" title="<?php echo esc_attr( $checked_raw ); ?>">
                        <?php echo esc_html( gim_format_row_datetime( $checked_raw ) ); ?>
                    </td>
                    <td class="gim-cell-date" title="<?php echo esc_attr( $sent_raw ); ?>">
                        <?php echo esc_html( gim_format_row_datetime( $sent_raw ) ); ?>
                    </td>
                    <td class="gim-row-actions">
                        <button class="button button-small gim-check-url"
                                data-url="<?php echo esc_attr( $row->url ); ?>">
                            <?php esc_html_e( 'Проверить', 'google-indexing-manager' ); ?>
                        </button>
                        <?php if ( (int) $row->indexed === 0 && (int) $row->excluded === 0 ) : ?>
                            <button class="button button-small button-primary gim-send-url"
                                    data-url="<?php echo esc_attr( $row->url ); ?>">
                                <?php esc_html_e( 'Отправить', 'google-indexing-manager' ); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $pages > 1 ) : ?>
        <div class="gim-pagination">
            <?php
            $links = paginate_links(
                [
                    'base'      => $base_url . '%_%',
                    'format'    => '&paged=%#%',
                    'current'   => $paged,
                    'total'     => $pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'type'      => 'array',
                    'end_size'  => 2,
                    'mid_size'  => 2,
                ]
            );

            if ( is_array( $links ) ) {
                echo '<ul class="gim-pagination-list">';
                foreach ( $links as $link ) {
                    echo '<li>' . $link . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
                }
                echo '</ul>';
            }
            ?>
        </div>
    <?php endif; ?>

    <div class="gim-bulk-bar" id="gim-bulk-bar" style="display:none;">
        <span class="gim-bulk-bar__count">
            <?php esc_html_e( 'Выбрано:', 'google-indexing-manager' ); ?>
            <strong id="gim-bulk-count">0</strong>
        </span>
        <button type="button" class="button" id="gim-bulk-check">
            <?php esc_html_e( 'Проверить выбранные', 'google-indexing-manager' ); ?>
        </button>
        <button type="button" class="button button-primary" id="gim-bulk-send">
            <?php esc_html_e( 'Отправить выбранные', 'google-indexing-manager' ); ?>
        </button>
        <button type="button" class="button button-link" id="gim-bulk-clear">
            <?php esc_html_e( 'Снять выделение', 'google-indexing-manager' ); ?>
        </button>
        <span class="gim-bulk-bar__progress" id="gim-bulk-progress" role="status" aria-live="polite" aria-atomic="true"></span>
    </div>
    <?php
}

function gim_get_status_badge_html( $indexed ): string {
    if ( $indexed === null ) {
        return '<span class="gim-badge gim-badge-unchecked">' . esc_html__( 'Не проверено', 'google-indexing-manager' ) . '</span>';
    }
    if ( (int) $indexed === 1 ) {
        return '<span class="gim-badge gim-badge-indexed">' . esc_html__( 'Индексировано', 'google-indexing-manager' ) . '</span>';
    }
    return '<span class="gim-badge gim-badge-unindexed">' . esc_html__( 'Не индексировано', 'google-indexing-manager' ) . '</span>';
}