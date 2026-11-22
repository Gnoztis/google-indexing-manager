<?php
/**
 * Вкладка «Логи».
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function gim_render_logs(): void {
    $allowed_levels = [ 'all', 'info', 'warning', 'error', 'debug' ];

    // По умолчанию открывается фильтр «Info».
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : 'info';
    if ( ! in_array( $level, $allowed_levels, true ) ) {
        $level = 'info';
    }

    $paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $per_page = GIM_LOGS_PER_PAGE;

    $total = gim_get_logs_count( $level );
    $pages = max( 1, (int) ceil( $total / $per_page ) );

    if ( $paged > $pages ) {
        $paged = $pages;
    }

    $rows = gim_get_logs( $per_page, $paged, $level );

    $counts = gim_get_logs_count_by_level();

    $base_url = add_query_arg(
        [
            'page'  => GIM_SLUG,
            'tab'   => 'logs',
            'level' => $level,
        ],
        admin_url( 'options-general.php' )
    );

    $labels = [
        'all'     => __( 'Все', 'google-indexing-manager' ),
        'info'    => __( 'Info', 'google-indexing-manager' ),
        'warning' => __( 'Warning', 'google-indexing-manager' ),
        'error'   => __( 'Error', 'google-indexing-manager' ),
        'debug'   => __( 'Debug', 'google-indexing-manager' ),
    ];

    $level_classes = [
        'debug'   => 'gim-badge-debug',
        'info'    => 'gim-badge-indexed',
        'warning' => 'gim-badge-unchecked',
        'error'   => 'gim-badge-unindexed',
    ];
    ?>
    <h3 class="gim-filter-title">
        <?php esc_html_e( 'Уровень:', 'google-indexing-manager' ); ?>
        <?php
        $filter_links = [];
        foreach ( $labels as $slug => $label ) {
            $url = add_query_arg(
                [
                    'page'  => GIM_SLUG,
                    'tab'   => 'logs',
                    'level' => $slug,
                ],
                admin_url( 'options-general.php' )
            );

            $class   = $level === $slug ? 'gim-filter-active' : '';
            $count   = isset( $counts[ $slug ] ) ? (int) $counts[ $slug ] : 0;

            $filter_links[] = sprintf(
                '<a href="%s" class="%s">%s <span class="gim-filter-count">%d</span></a>',
                esc_url( $url ),
                esc_attr( $class ),
                esc_html( $label ),
                $count
            );
        }
        echo implode( ' ', $filter_links );
        ?>
    </h3>

    <p class="description">
        <?php
        printf(
            esc_html__( 'Показано %1$d из %2$d. Страница %3$d из %4$d.', 'google-indexing-manager' ),
            count( $rows ),
            (int) $total,
            (int) $paged,
            (int) $pages
        );
        ?>
    </p>

    <table class="wp-list-table widefat fixed striped gim-logs-table">
        <thead>
        <tr>
            <th style="width:160px;"><?php esc_html_e( 'Дата', 'google-indexing-manager' ); ?></th>
            <th style="width:110px;"><?php esc_html_e( 'Уровень', 'google-indexing-manager' ); ?></th>
            <th><?php esc_html_e( 'Сообщение', 'google-indexing-manager' ); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if ( empty( $rows ) ) : ?>
            <tr>
                <td colspan="3" class="gim-logs-empty">
                    <?php esc_html_e( 'Записей нет.', 'google-indexing-manager' ); ?>
                </td>
            </tr>
        <?php else : ?>
            <?php foreach ( $rows as $row ) : ?>
                <?php
                $lvl   = (string) $row->level;
                $class = $level_classes[ $lvl ] ?? 'gim-badge-debug';
                ?>
                <tr>
                    <td class="gim-logs-date"><?php echo esc_html( $row->created_at ); ?></td>
                    <td>
                        <span class="gim-badge <?php echo esc_attr( $class ); ?>">
                            <?php echo esc_html( $lvl ); ?>
                        </span>
                    </td>
                    <td class="gim-logs-message">
                        <?php
                        if ( ! empty( $row->url ) ) {
                            printf(
                                '%s — <a href="%s" target="_blank" rel="noopener">%s</a>',
                                esc_html( $row->message ),
                                esc_url( $row->url ),
                                esc_html( $row->url )
                            );
                        } else {
                            echo esc_html( $row->message );
                        }
                        ?>
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
    <?php
}