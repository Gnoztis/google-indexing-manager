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
            'description' => __( 'Пробная отправка URL на индексацию', 'google-indexing-manager' ),
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
    <?php
}