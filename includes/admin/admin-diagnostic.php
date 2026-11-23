<?php
/**
 * Вкладка «Диагностика».
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function gim_get_diagnostic_steps(): array {
    return [
        [
            'key'         => 'json_key',
            'name'        => __( 'JSON-ключ', 'google-indexing-manager' ),
            'description' => __( 'Валидность JSON и наличие client_email + private_key', 'google-indexing-manager' ),
        ],
        [
            'key'         => 'token',
            'name'        => __( 'Access token', 'google-indexing-manager' ),
            'description' => __( 'Получение OAuth-токена у Google', 'google-indexing-manager' ),
        ],
        [
            'key'         => 'sites',
            'name'        => __( 'Список ресурсов', 'google-indexing-manager' ),
            'description' => __( 'Доступные properties в Search Console', 'google-indexing-manager' ),
        ],
        [
            'key'         => 'site_match',
            'name'        => __( 'Совпадение с Search Console', 'google-indexing-manager' ),
            'description' => __( 'Настройка «URL сайта» должна совпадать с одним из ресурсов', 'google-indexing-manager' ),
        ],
        [
            'key'         => 'permissions',
            'name'        => __( 'Права в Search Console', 'google-indexing-manager' ),
            'description' => __( 'Сервисный аккаунт должен иметь права Owner или Full', 'google-indexing-manager' ),
        ],
        [
            'key'         => 'inspection_api',
            'name'        => __( 'URL Inspection API', 'google-indexing-manager' ),
            'description' => __( 'Пробный запрос проверки URL', 'google-indexing-manager' ),
        ],
        [
            'key'         => 'indexing_api',
            'name'        => __( 'Indexing API', 'google-indexing-manager' ),
            'description' => __( 'Пробная отправка URL — только по явному согласию: расходует 1 уведомление из суточной квоты', 'google-indexing-manager' ),
        ],
        [
            'key'         => 'db_schema',
            'name'        => __( 'Схема базы данных', 'google-indexing-manager' ),
            'description' => __( 'Таблицы gim_log и gim_unindexed должны существовать', 'google-indexing-manager' ),
        ],
        [
            'key'         => 'cron',
            'name'        => __( 'Cron воркеры', 'google-indexing-manager' ),
            'description' => __( 'Healthcheck и четыре воркера должны быть запланированы', 'google-indexing-manager' ),
        ],
        [
            'key'         => 'local_quota',
            'name'        => __( 'Локальная квота отправок', 'google-indexing-manager' ),
            'description' => __( 'Сколько URL отправлено сегодня из локального лимита', 'google-indexing-manager' ),
        ],
        [
            'key'         => 'tags',
            'name'        => __( 'Исключающие теги', 'google-indexing-manager' ),
            'description' => __( 'Корректность сохранённых ID тегов', 'google-indexing-manager' ),
        ],
    ];
}

function gim_render_diagnostic(): void {
    $steps = gim_get_diagnostic_steps();
    ?>
    <p class="description">
        <?php esc_html_e( 'Пошаговая проверка интеграции: ключ, токен, права, API, cron, база данных.', 'google-indexing-manager' ); ?>
    </p>

    <p class="gim-actions">
        <label class="gim-check gim-diagnostic-probe" for="gim-diagnostic-probe">
            <input type="checkbox" id="gim-diagnostic-probe" value="1" autocomplete="off">
            <?php esc_html_e( 'Выполнить пробную отправку URL в Indexing API (расходует 1 уведомление из суточной квоты)', 'google-indexing-manager' ); ?>
        </label>
    </p>

    <p class="gim-actions">
        <button class="button button-primary" id="gim-run-diagnostic">
            <?php esc_html_e( 'Запустить диагностику', 'google-indexing-manager' ); ?>
        </button>
    </p>

    <table class="wp-list-table widefat fixed striped gim-diagnostic-table">
        <thead>
        <tr>
            <th style="width:40px;">№</th>
            <th style="width:280px;"><?php esc_html_e( 'Проверка', 'google-indexing-manager' ); ?></th>
            <th style="width:110px;"><?php esc_html_e( 'Статус', 'google-indexing-manager' ); ?></th>
            <th><?php esc_html_e( 'Результат', 'google-indexing-manager' ); ?></th>
        </tr>
        </thead>
        <tbody id="gim-diagnostic-body">
        <?php $i = 0; ?>
        <?php foreach ( $steps as $step ) : ?>
            <?php $i++; ?>
            <tr data-step="<?php echo esc_attr( $step['key'] ); ?>">
                <td><?php echo (int) $i; ?></td>
                <td>
                    <strong><?php echo esc_html( $step['name'] ); ?></strong>
                    <div class="gim-diagnostic-desc"><?php echo esc_html( $step['description'] ); ?></div>
                </td>
                <td>
                    <span class="gim-badge gim-badge-pending" data-role="status">—</span>
                </td>
                <td class="gim-diagnostic-message" data-role="message">
                    <span class="gim-worker-value"><?php esc_html_e( 'Не проверено.', 'google-indexing-manager' ); ?></span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div id="gim-diagnostic-summary" class="gim-diagnostic-summary" hidden></div>

    <?php gim_render_db_backups(); ?>
    <?php
}

/**
 * Backup tables created before a schema migration, with a restore action.
 *
 * The queue table holds the verification history; a migration copies it first
 * and this block makes the copy usable instead of invisible.
 */
function gim_render_db_backups(): void {
    $backups = gim_db_backups_report();
    ?>
    <h3 class="gim-backups-title"><?php esc_html_e( 'Резервные копии таблицы URL', 'google-indexing-manager' ); ?></h3>

    <?php if ( empty( $backups ) ) : ?>
        <p class="description">
            <?php esc_html_e( 'Копий нет: миграции схемы ещё не выполнялись или очередь была пуста.', 'google-indexing-manager' ); ?>
        </p>
        <?php return; ?>
    <?php endif; ?>

    <table class="wp-list-table widefat fixed striped gim-backups-table">
        <thead>
        <tr>
            <th><?php esc_html_e( 'Таблица', 'google-indexing-manager' ); ?></th>
            <th style="width:110px;"><?php esc_html_e( 'Схема', 'google-indexing-manager' ); ?></th>
            <th style="width:90px;"><?php esc_html_e( 'Строк', 'google-indexing-manager' ); ?></th>
            <th style="width:150px;"><?php esc_html_e( 'Создана', 'google-indexing-manager' ); ?></th>
            <th style="width:170px;"><?php esc_html_e( 'Действие', 'google-indexing-manager' ); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ( $backups as $backup ) : ?>
            <tr>
                <td><code><?php echo esc_html( $backup['table'] ); ?></code></td>
                <td><?php echo (int) $backup['from']; ?> → <?php echo (int) $backup['to']; ?></td>
                <td><?php echo (int) $backup['rows']; ?></td>
                <td>
                    <?php
                    if ( $backup['created'] > 0 ) {
                        echo esc_html( wp_date( 'd.m.Y H:i', (int) $backup['created'] ) );
                    } else {
                        echo '—';
                    }
                    ?>
                </td>
                <td>
                    <button type="button"
                            class="button button-small gim-restore-backup"
                            data-backup="<?php echo esc_attr( $backup['table'] ); ?>"
                            <?php disabled( ! $backup['exists'] ); ?>>
                        <?php esc_html_e( 'Восстановить', 'google-indexing-manager' ); ?>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="description">
        <?php esc_html_e( 'Восстановление возвращает статусы только тем URL, у которых статуса сейчас нет (indexed IS NULL): более свежие проверки не перезаписываются. Отсутствующие строки добавляются заново.', 'google-indexing-manager' ); ?>
    </p>
    <?php
}