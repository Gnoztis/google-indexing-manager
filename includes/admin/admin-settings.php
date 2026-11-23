<?php
/**
 * Вкладка «Настройки».
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Steps of the "Как подключить" help.
 *
 * One place for the titles, texts and documentation links. Links point only at
 * developers.google.com and search.google.com and open in a new tab. Nothing
 * here contains key material: the real JSON stays in the administrator's file.
 *
 * @return array<int, array{title:string, text:string, link_label:string, link_url:string}>
 */
function gim_get_setup_steps(): array {
    return [
        [
            'title'      => __( 'Шаг 1. Google Cloud: сервисный аккаунт и JSON-ключ', 'google-indexing-manager' ),
            'text'       => __( 'Создайте проект в Google Cloud, включите в нём Indexing API, а для проверки статуса — ещё и Google Search Console API. Затем создайте сервисный аккаунт, откройте его ключи и выпустите ключ в формате JSON: файл скачивается один раз.', 'google-indexing-manager' ),
            'link_label' => __( 'Indexing API: включение API и создание сервисного аккаунта (developers.google.com)', 'google-indexing-manager' ),
            'link_url'   => 'https://developers.google.com/search/apis/indexing-api/v3/prereqs',
        ],
        [
            'title'      => __( 'Шаг 2. Search Console: доступ для сервисного аккаунта', 'google-indexing-manager' ),
            'text'       => __( 'Откройте Search Console нужного сайта, раздел «Настройки» → «Пользователи и разрешения», и добавьте адрес client_email из JSON-файла (вида name@project.iam.gserviceaccount.com) с правами «Владелец» или «Полный доступ». Без этого Google вернёт ошибку 403.', 'google-indexing-manager' ),
            'link_label' => __( 'Search Console: пользователи и разрешения (search.google.com)', 'google-indexing-manager' ),
            'link_url'   => 'https://search.google.com/search-console/users',
        ],
        [
            'title'      => __( 'Шаг 3. Плагин: вставить JSON и проверить подключение', 'google-indexing-manager' ),
            'text'       => __( 'Вставьте содержимое JSON-файла целиком в поле «JSON-ключ сервисного аккаунта» и сохраните настройки. Затем откройте вкладку «Диагностика» и нажмите «Запустить диагностику»: она проверит ключ, токен, доступ к ресурсу Search Console и оба API. После этого можно отправлять первый URL из очереди.', 'google-indexing-manager' ),
            'link_label' => __( 'Indexing API: первый запрос и суточные квоты (developers.google.com)', 'google-indexing-manager' ),
            'link_url'   => 'https://developers.google.com/search/apis/indexing-api/v3/quickstart',
        ],
    ];
}

/**
 * Collapsible setup help on the settings screen.
 *
 * Rendered open while the key is missing or unreadable, so the steps are
 * visible exactly when they are needed.
 *
 * @param array $key_state Result of gim_json_key_state().
 */
function gim_render_setup_help( array $key_state ): void {
    $steps      = gim_get_setup_steps();
    $open       = ( ! $key_state['configured'] || ! $key_state['readable'] );
    $diagnostic = add_query_arg(
        [
            'page' => GIM_SLUG,
            'tab'  => 'diagnostic',
        ],
        admin_url( 'options-general.php' )
    );
    ?>
    <details id="gim-howto" class="gim-howto"<?php if ( $open ) : ?> open<?php endif; ?>>
        <summary class="gim-howto__summary"><?php esc_html_e( 'Как подключить: три шага', 'google-indexing-manager' ); ?></summary>

        <div class="gim-howto__body">
            <ol class="gim-howto__steps">
                <?php foreach ( $steps as $step ) : ?>
                    <li class="gim-howto__step">
                        <strong class="gim-howto__step-title"><?php echo esc_html( $step['title'] ); ?></strong>
                        <p class="gim-howto__step-text"><?php echo esc_html( $step['text'] ); ?></p>
                        <a class="gim-howto__step-link"
                           href="<?php echo esc_url( $step['link_url'] ); ?>"
                           target="_blank"
                           rel="noopener noreferrer"><?php echo esc_html( $step['link_label'] ); ?></a>
                    </li>
                <?php endforeach; ?>
            </ol>

            <div class="gim-howto__crypto">
                <strong class="gim-howto__step-title"><?php esc_html_e( 'Константа шифрования GIM_KEY_ENCRYPTION_KEY', 'google-indexing-manager' ); ?></strong>
                <p>
                    <?php esc_html_e( 'JSON-ключ содержит private_key: он подписывает запросы к Google и действует, пока его не отозвали в Google Cloud. Поэтому плагин хранит JSON зашифрованным, а ключ шифрования берёт из wp-config.php. Дамп базы без wp-config.php тогда бесполезен.', 'google-indexing-manager' ); ?>
                </p>
                <p><?php esc_html_e( 'Сгенерируйте ключ (32 байта в base64) на сервере или локально:', 'google-indexing-manager' ); ?></p>
                <pre class="gim-howto__code"><code>php -r "echo base64_encode(random_bytes(32));"</code></pre>
                <p><?php esc_html_e( 'Полученную строку добавьте в wp-config.php до строки «That’s all, stop editing!»:', 'google-indexing-manager' ); ?></p>
                <pre class="gim-howto__code"><code>define( 'GIM_KEY_ENCRYPTION_KEY', 'вставьте-сюда-строку-base64' );</code></pre>
                <p>
                    <?php esc_html_e( 'Без этой константы ключ шифрования выводится из солей WordPress (AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_SALT). Если соли сменить, сохранённый JSON перестанет расшифровываться: плагин покажет «Ключ не читается» и попросит вставить JSON заново. С константой ключ от солей не зависит. Константу нельзя менять после сохранения ключа — иначе потребуется вставить JSON заново.', 'google-indexing-manager' ); ?>
                </p>
                <p>
                    <?php esc_html_e( 'Потеря ключа шифрования не отзывает ключ сервисного аккаунта в Google Cloud. Если JSON мог попасть в чужие руки, удалите ключ в Google Cloud и выпустите новый.', 'google-indexing-manager' ); ?>
                </p>
                <p>
                    <a class="gim-howto__step-link"
                       href="<?php echo esc_url( $diagnostic ); ?>"><?php esc_html_e( 'Проверить подключение на вкладке «Диагностика»', 'google-indexing-manager' ); ?></a>
                </p>
            </div>
        </div>
    </details>
    <?php
}

/**
 * Contextual notice above the key field.
 *
 * Tells the administrator what to do in the current state and anchors the
 * missing/unreadable case to the setup help block.
 *
 * @param array  $key_state      Result of gim_json_key_state().
 * @param string $diagnostic_url URL of the diagnostic tab.
 */
function gim_render_key_context_notice( array $key_state, string $diagnostic_url ): void {
    if ( '' !== $key_state['key_error'] ) {
        ?>
        <div class="notice notice-warning inline gim-key-notice">
            <p>
                <?php
                printf(
                    /* translators: %s: reason why encryption is unavailable. */
                    esc_html__( 'Шифрование ключа недоступно: %s. Сохранить новый ключ нельзя, уже сохранённый продолжает работать.', 'google-indexing-manager' ),
                    esc_html( $key_state['key_error'] )
                );
                ?>
                <a href="#gim-howto"><?php esc_html_e( 'Как задать константу шифрования', 'google-indexing-manager' ); ?></a>
            </p>
        </div>
        <?php
        return;
    }

    if ( ! $key_state['configured'] ) {
        ?>
        <div class="notice notice-info inline gim-key-notice">
            <p>
                <?php esc_html_e( 'Ключ не задан. Выполните шаги 1–3 ниже.', 'google-indexing-manager' ); ?>
                <a href="#gim-howto"><?php esc_html_e( 'Перейти к инструкции «Как подключить»', 'google-indexing-manager' ); ?></a>
            </p>
        </div>
        <?php
        return;
    }

    if ( ! $key_state['readable'] ) {
        ?>
        <div class="notice notice-error inline gim-key-notice">
            <p>
                <?php esc_html_e( 'Ключ не читается, вставьте JSON заново.', 'google-indexing-manager' ); ?>
                <a href="#gim-howto"><?php esc_html_e( 'Как подключить: шаги и константа шифрования', 'google-indexing-manager' ); ?></a>
            </p>
        </div>
        <?php
        return;
    }

    $saved_at = (int) $key_state['saved_at'];
    ?>
    <div class="notice notice-success inline gim-key-notice">
        <p>
            <?php
            if ( $saved_at > 0 ) {
                printf(
                    /* translators: %s: date and time when the key was saved. */
                    esc_html__( 'Ключ сохранён %s.', 'google-indexing-manager' ),
                    esc_html( wp_date( 'd.m.Y H:i', $saved_at ) )
                );
            } else {
                esc_html_e( 'Ключ сохранён.', 'google-indexing-manager' );
            }
            ?>
            <a href="<?php echo esc_url( $diagnostic_url ); ?>"><?php esc_html_e( 'Проверить подключение', 'google-indexing-manager' ); ?></a>
        </p>
    </div>
    <?php
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
    $key_state      = gim_json_key_state();
    $diagnostic_url = add_query_arg(
        [
            'page' => GIM_SLUG,
            'tab'  => 'diagnostic',
        ],
        admin_url( 'options-general.php' )
    );

    $delete_url = admin_url( 'admin-post.php' );

    $key_deleted = isset( $_GET['gim_key_deleted'] )
        ? '1' === sanitize_key( wp_unslash( $_GET['gim_key_deleted'] ) )
        : false;
    ?>
    <h2><?php esc_html_e( 'Настройки интеграции', 'google-indexing-manager' ); ?></h2>

    <?php if ( $key_deleted ) : ?>
        <div class="notice notice-success inline">
            <p><?php esc_html_e( 'JSON-ключ удалён. Пока не вставлен новый ключ, проверка и отправка URL работать не будут.', 'google-indexing-manager' ); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'gim_settings' ); ?>
        <table class="form-table">
            <tr id="gim-json-key-row">
                <th>
                    <label for="gim_json_key"><?php esc_html_e( 'JSON-ключ сервисного аккаунта', 'google-indexing-manager' ); ?></label>
                </th>
                <td>
                    <?php gim_render_key_context_notice( $key_state, $diagnostic_url ); ?>

                    <?php if ( $key_state['configured'] && $key_state['readable'] ) : ?>
                        <p class="gim-key-state">
                            <span class="gim-key-state__label"><?php esc_html_e( 'Сохранённый ключ:', 'google-indexing-manager' ); ?></span>
                            <code class="gim-key-mask"><?php echo esc_html( Gim_Token_Crypto::mask() ); ?></code>
                            <?php if ( '' !== $key_state['client_email'] ) : ?>
                                <code class="gim-key-email"><?php echo esc_html( $key_state['client_email'] ); ?></code>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <textarea id="gim_json_key" name="gim_json_key" rows="6" cols="60"
                              class="large-text code"
                              autocomplete="off"
                              spellcheck="false"
                              placeholder="<?php esc_attr_e( 'Вставьте содержимое JSON-файла сервисного аккаунта', 'google-indexing-manager' ); ?>"></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Поле всегда пустое: сохранённый ключ не показывается. Пустое сохранение оставляет текущий ключ, новый JSON заменяет его.', 'google-indexing-manager' ); ?>
                    </p>
                    <p class="description">
                        <?php if ( 'constant' === $key_state['source'] ) : ?>
                            <?php esc_html_e( 'Шифрование: ключ из константы GIM_KEY_ENCRYPTION_KEY в wp-config.php.', 'google-indexing-manager' ); ?>
                        <?php elseif ( 'salts' === $key_state['source'] ) : ?>
                            <?php esc_html_e( 'Шифрование: ключ, производный от солей WordPress. Задайте GIM_KEY_ENCRYPTION_KEY, чтобы ключ не зависел от смены солей.', 'google-indexing-manager' ); ?>
                        <?php else : ?>
                            <strong><?php esc_html_e( 'Шифрование недоступно: нет ни константы GIM_KEY_ENCRYPTION_KEY, ни солей WordPress. Сохранить ключ нельзя.', 'google-indexing-manager' ); ?></strong>
                        <?php endif; ?>
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

        <?php gim_render_setup_help( $key_state ); ?>

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
                            <label class="gim-check gim-check--stacked">
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
                        printf(
                            /* translators: %d: hard limit of stored log rows. */
                            esc_html__( 'Логи старше указанного срока удаляются автоматически раз в сутки в 04:00. 0 — по возрасту не удалять, но всегда действует потолок: хранятся последние %d записей.', 'google-indexing-manager' ),
                            (int) gim_get_log_max_rows()
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
                                   placeholder="<?php echo esc_attr( $excluded_terms ? '' : __( 'Начните вводить название тега…', 'google-indexing-manager' ) ); ?>"
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

    <?php if ( $key_state['configured'] ) : ?>
        <form method="post" action="<?php echo esc_url( $delete_url ); ?>" class="gim-key-delete">
            <?php wp_nonce_field( 'gim_delete_json_key' ); ?>
            <input type="hidden" name="action" value="gim_delete_json_key">
            <button type="submit" class="button gim-danger">
                <?php esc_html_e( 'Удалить JSON-ключ', 'google-indexing-manager' ); ?>
            </button>
            <span class="description">
                <?php esc_html_e( 'Удаляет сохранённый ключ из базы безвозвратно. После этого проверка и отправка URL не работают до вставки нового ключа.', 'google-indexing-manager' ); ?>
            </span>
        </form>
        <hr class="gim-divider">
    <?php endif; ?>

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
        <?php
        printf(
            /* translators: %d: hard limit of stored log rows. */
            esc_html__( '«Очистить логи сейчас» удалит записи старше указанного срока хранения (поле выше) и лишние записи сверх потолка %d. Если срок 0 — удаляются только записи сверх потолка.', 'google-indexing-manager' ),
            (int) gim_get_log_max_rows()
        );
        ?>
    </p>
    <?php
}