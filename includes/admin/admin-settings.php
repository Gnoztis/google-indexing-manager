<?php
/**
 * Вкладка «Настройки».
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function gim_render_settings(): void {
    $night_start  = gim_get_night_start_hour();
    $night_end    = gim_get_night_end_hour();
    $cap          = gim_get_daily_send_cap();
    $recheck_days = gim_get_recheck_days();
    $resend_days  = gim_get_resend_days();
    $log_lifetime = gim_get_log_lifetime_days();

    $selected_post_types = gim_get_selected_post_types();
    $public_post_types   = get_post_types( [ 'public' => true ], 'objects' );

    $excluded_ids   = gim_get_excluded_tag_ids();
    $excluded_terms = [];
    if ( ! empty( $excluded_ids ) ) {
        $terms = get_terms(
            [
                'taxonomy'   => 'post_tag',
                'include'    => $excluded_ids,
                'hide_empty' => false,
            ]
        );
        if ( ! is_wp_error( $terms ) ) {
            $excluded_terms = $terms;
        }
    }

    $excluded_count = gim_get_excluded_count();
    $logs_total     = gim_get_logs_count( 'all' );
    ?>
    <h2><?php esc_html_e( 'Настройки интеграции', 'google-indexing-manager' ); ?></h2>
    <form method="post" action="options.php">
        <?php settings_fields( 'gim_settings' ); ?>
        <table class="form-table">
            <tr>
                <th>
                    <label for="gim_json_key"><?php esc_html_e( 'JSON-ключ сервисного аккаунта', 'google-indexing-manager' ); ?></label>
                </th>
                <td>
                    <textarea id="gim_json_key" name="gim_json_key" rows="10" cols="60"
                              class="large-text code"><?php echo esc_textarea( (string) gim_get_option( 'json_key', '' ) ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Содержимое JSON-файла сервисного аккаунта Google Cloud.', 'google-indexing-manager' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th>
                    <label for="gim_site_url"><?php esc_html_e( 'URL сайта в Search Console', 'google-indexing-manager' ); ?></label>
                </th>
                <td>
                    <input type="text" id="gim_site_url" name="gim_site_url"
                           value="<?php echo esc_attr( (string) gim_get_option( 'site_url', '' ) ); ?>"
                           class="regular-text"
                           placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
                    <p class="description">
                        <?php esc_html_e( 'Для Domain-ресурса: sc-domain:example.com. Для URL-prefix: https://example.com/ (со слэшем в конце).', 'google-indexing-manager' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Синхронизация URL', 'google-indexing-manager' ); ?></h3>
        <p class="description">
            <?php esc_html_e( 'Какие типы контента собирать автоматически. Синхронизация URL запускается каждый день в 03:00 через WP-Cron.', 'google-indexing-manager' ); ?>
        </p>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Типы контента', 'google-indexing-manager' ); ?></th>
                <td>
                    <fieldset>
                        <?php foreach ( $public_post_types as $pt ) : ?>
                            <label style="display:block; margin-bottom:6px;">
                                <input type="checkbox"
                                       name="gim_post_types[<?php echo esc_attr( $pt->name ); ?>]"
                                       value="<?php echo esc_attr( $pt->name ); ?>"
                                       <?php checked( in_array( $pt->name, $selected_post_types, true ) ); ?>>
                                <?php echo esc_html( $pt->labels->singular_name ); ?>
                                <span class="description">(<?php echo esc_html( $pt->name ); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description">
                        <?php esc_html_e( 'По умолчанию собираются посты и страницы. Выбор применяется со следующей синхронизации.', 'google-indexing-manager' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Ночное окно отправки', 'google-indexing-manager' ); ?></h3>
        <p class="description">
            <?php esc_html_e( 'Отправка URL в Indexing API идёт только в указанном интервале.', 'google-indexing-manager' ); ?>
        </p>
        <table class="form-table">
            <tr>
                <th><label for="gim_night_start_hour"><?php esc_html_e( 'Начало окна', 'google-indexing-manager' ); ?></label></th>
                <td>
                    <select id="gim_night_start_hour" name="gim_night_start_hour">
                        <?php for ( $h = 0; $h < 24; $h++ ) : ?>
                            <option value="<?php echo (int) $h; ?>" <?php selected( $night_start, $h ); ?>>
                                <?php echo esc_html( sprintf( '%02d:00', $h ) ); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="gim_night_end_hour"><?php esc_html_e( 'Конец окна', 'google-indexing-manager' ); ?></label></th>
                <td>
                    <select id="gim_night_end_hour" name="gim_night_end_hour">
                        <?php for ( $h = 0; $h < 24; $h++ ) : ?>
                            <option value="<?php echo (int) $h; ?>" <?php selected( $night_end, $h ); ?>>
                                <?php echo esc_html( sprintf( '%02d:00', $h ) ); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Окно может пересекать полночь (22:00 → 06:00).', 'google-indexing-manager' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="gim_daily_send_cap"><?php esc_html_e( 'Суточный лимит отправок', 'google-indexing-manager' ); ?></label></th>
                <td>
                    <input type="number" id="gim_daily_send_cap" name="gim_daily_send_cap"
                           value="<?php echo esc_attr( $cap ); ?>"
                           min="1" max="200" step="1" class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'Google даёт 200/день на проект. Часть может использоваться другими плагинами — по умолчанию 100.', 'google-indexing-manager' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Перепроверка и переотправка', 'google-indexing-manager' ); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="gim_recheck_days"><?php esc_html_e( 'Перепроверять через (дней)', 'google-indexing-manager' ); ?></label></th>
                <td>
                    <input type="number" id="gim_recheck_days" name="gim_recheck_days"
                           value="<?php echo esc_attr( $recheck_days ); ?>"
                           min="1" max="365" step="1" class="small-text">
                </td>
            </tr>
            <tr>
                <th><label for="gim_resend_days"><?php esc_html_e( 'Переотправлять через (дней)', 'google-indexing-manager' ); ?></label></th>
                <td>
                    <input type="number" id="gim_resend_days" name="gim_resend_days"
                           value="<?php echo esc_attr( $resend_days ); ?>"
                           min="1" max="365" step="1" class="small-text">
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Логи', 'google-indexing-manager' ); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="gim_log_lifetime_days"><?php esc_html_e( 'Срок хранения (дней)', 'google-indexing-manager' ); ?></label></th>
                <td>
                    <input type="number" id="gim_log_lifetime_days" name="gim_log_lifetime_days"
                           value="<?php echo esc_attr( $log_lifetime ); ?>"
                           min="0" max="3650" step="1" class="small-text">
                    <p class="description">
                        <?php
                        esc_html_e(
                            'Логи старше указанного срока удаляются автоматически раз в сутки в 04:00. 0 — хранить бессрочно (очистка не запускается).',
                            'google-indexing-manager'
                        );
                        ?>
                    </p>
                    <p class="description">
                        <?php
                        printf(
                            esc_html__( 'Сейчас в таблице %d записей.', 'google-indexing-manager' ),
                            (int) $logs_total
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Исключающие теги', 'google-indexing-manager' ); ?></h3>
        <p class="description">
            <?php esc_html_e( 'Посты с этими тегами не будут отправляться в Indexing API, но продолжат проверяться через URL Inspection API.', 'google-indexing-manager' ); ?>
        </p>
        <table class="form-table">
            <tr>
                <th>
                    <label for="gim-tags-search"><?php esc_html_e( 'Теги-исключения', 'google-indexing-manager' ); ?></label>
                </th>
                <td>
                    <div class="gim-tags" id="gim-tags">
                        <div class="gim-tags__field" id="gim-tags-field">
                            <?php foreach ( $excluded_terms as $term ) : ?>
                                <span class="gim-tag-chip" data-id="<?php echo (int) $term->term_id; ?>">
                                    <span class="gim-tag-chip__name"><?php echo esc_html( $term->name ); ?></span>
                                    <button type="button" class="gim-tag-chip__remove" aria-label="<?php esc_attr_e( 'Удалить', 'google-indexing-manager' ); ?>">
                                        <span class="gim-tag-chip__icon" aria-hidden="true">×</span>
                                    </button>
                                </span>
                            <?php endforeach; ?>
                            <input type="text"
                                   class="gim-tags__input"
                                   id="gim-tags-search"
                                   placeholder="<?php echo $excluded_terms ? '' : esc_attr__( 'Начните вводить название тега…', 'google-indexing-manager' ); ?>"
                                   autocomplete="off"
                                   spellcheck="false">
                        </div>
                        <div class="gim-tags__dropdown" id="gim-tags-dropdown" hidden>
                            <div class="gim-tags__options" id="gim-tags-options"></div>
                            <div class="gim-tags__empty" id="gim-tags-empty" hidden>
                                <?php esc_html_e( 'Ничего не найдено', 'google-indexing-manager' ); ?>
                            </div>
                            <div class="gim-tags__hint">
                                <kbd>Enter</kbd> — выбрать, <kbd>Backspace</kbd> — удалить последний
                            </div>
                        </div>
                        <input type="hidden"
                               name="gim_excluded_tags"
                               id="gim-excluded-tags"
                               value="<?php echo esc_attr( implode( ',', $excluded_ids ) ); ?>">
                    </div>
                    <p class="description">
                        <?php
                        printf(
                            esc_html__( 'Сейчас помечено URL как исключённые: %d.', 'google-indexing-manager' ),
                            (int) $excluded_count
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <hr class="gim-divider">

    <h2><?php esc_html_e( 'Обслуживание', 'google-indexing-manager' ); ?></h2>
    <p>
        <button type="button" class="button" id="gim-clear-token">
            <?php esc_html_e( 'Сбросить access token', 'google-indexing-manager' ); ?>
        </button>
        <button type="button" class="button" id="gim-reset-statuses">
            <?php esc_html_e( 'Сбросить статусы проверок', 'google-indexing-manager' ); ?>
        </button>
        <button type="button" class="button" id="gim-recompute-exclusions">
            <?php esc_html_e( 'Пересчитать исключения сейчас', 'google-indexing-manager' ); ?>
        </button>
        <button type="button" class="button gim-danger" id="gim-purge-logs">
            <?php esc_html_e( 'Очистить логи сейчас', 'google-indexing-manager' ); ?>
        </button>
    </p>
    <p class="description">
        <?php esc_html_e( '«Очистить логи сейчас» удалит записи старше указанного срока хранения (поле выше). Если срок 0 — очистка не выполняется, потому что хранение бессрочное.', 'google-indexing-manager' ); ?>
    </p>
    <?php
}