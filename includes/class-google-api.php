<?php
/**
 * Работа с Google API без внешних библиотек.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function gim_token_transient_key(): string {
    return 'gim_access_token';
}

function gim_clear_token_cache(): void {
    delete_transient( gim_token_transient_key() );

    // The per-request cache lives in a global so that clearing it also resets
    // the value already returned in this request.
    unset( $GLOBALS['gim_access_token_cache'] );
}

/**
 * Shorten a remote response body before it goes to the log.
 *
 * Google error bodies are long and may echo request details; the log keeps the
 * beginning only. The service-account key is never part of a response body.
 * mbstring is not guaranteed, so the byte fallback is used without it.
 */
function gim_short_error_body( string $body, int $limit = 300 ): string {
    $body = trim( $body );

    if ( '' === $body ) {
        return '';
    }

    if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) ) {
        return mb_strlen( $body ) <= $limit ? $body : mb_substr( $body, 0, $limit ) . '…';
    }

    return strlen( $body ) <= $limit ? $body : substr( $body, 0, $limit ) . '…';
}

/* -------------------------------------------------------------------------
 * OAuth2.
 * ---------------------------------------------------------------------- */

function gim_base64url_encode( string $data ): string {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/**
 * Нормализовать приватный ключ из JSON.
 *
 * Некоторые редакторы и панели сохраняют JSON с двойным экранированием:
 * вместо реальных \n в строке оказывается литерал "\\n". openssl_sign
 * в этом случае падает с ошибкой.
 */
function gim_normalize_private_key( string $key ): string {
    if ( strpos( $key, '\\n' ) !== false && strpos( $key, "\n" ) === false ) {
        $key = str_replace( '\\n', "\n", $key );
    }
    return $key;
}

function gim_get_access_token(): ?string {
    // Per-request cache: a failed fetch is cached too, so a batch of URLs does
    // not repeat the OAuth request for every item. gim_clear_token_cache()
    // drops both the transient and this value.
    if ( array_key_exists( 'gim_access_token_cache', $GLOBALS ) ) {
        $cached = $GLOBALS['gim_access_token_cache'];
        return is_string( $cached ) ? $cached : null;
    }

    $transient_key = gim_token_transient_key();
    $cached        = get_transient( $transient_key );

    if ( is_string( $cached ) && $cached !== '' ) {
        $GLOBALS['gim_access_token_cache'] = $cached;
        return $cached;
    }

    $json_key = gim_get_json_key();

    if ( '' === $json_key ) {
        gim_log_error( 'JSON-ключ сервисного аккаунта не задан или не читается' );
        $GLOBALS['gim_access_token_cache'] = null;
        return null;
    }

    $credentials = json_decode( $json_key, true );
    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $credentials ) ) {
        gim_log_error( 'Неверный JSON-ключ: ' . json_last_error_msg() );
        $GLOBALS['gim_access_token_cache'] = null;
        return null;
    }

    if ( empty( $credentials['client_email'] ) || empty( $credentials['private_key'] ) ) {
        gim_log_error( 'JSON-ключ не содержит client_email или private_key' );
        $GLOBALS['gim_access_token_cache'] = null;
        return null;
    }

    $private_key = gim_normalize_private_key( (string) $credentials['private_key'] );

    $now     = time();
    $header  = gim_base64url_encode( wp_json_encode( [ 'typ' => 'JWT', 'alg' => 'RS256' ] ) );
    $payload = gim_base64url_encode(
        wp_json_encode(
            [
                'iss'   => (string) $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/indexing https://www.googleapis.com/auth/webmasters.readonly',
                'aud'   => 'https://oauth2.googleapis.com/token',
                'exp'   => $now + 3600,
                'iat'   => $now - 60,
            ]
        )
    );

    $signature = '';
    $success   = openssl_sign(
        $header . '.' . $payload,
        $signature,
        $private_key,
        OPENSSL_ALGO_SHA256
    );

    if ( ! $success ) {
        gim_log_error( 'Не удалось подписать JWT. Проверьте private_key в JSON.' );
        $GLOBALS['gim_access_token_cache'] = null;
        return null;
    }

    $jwt = $header . '.' . $payload . '.' . gim_base64url_encode( $signature );

    $response = wp_remote_post(
        'https://oauth2.googleapis.com/token',
        [
            'timeout' => 20,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
        ]
    );

    if ( is_wp_error( $response ) ) {
        gim_log_error( 'OAuth2: ' . $response->get_error_message() );
        $GLOBALS['gim_access_token_cache'] = null;
        return null;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = (string) wp_remote_retrieve_body( $response );

    if ( $code !== 200 ) {
        gim_log_error( sprintf( 'OAuth2: HTTP %d — %s', $code, gim_short_error_body( $body ) ) );
        $GLOBALS['gim_access_token_cache'] = null;
        return null;
    }

    $data = json_decode( $body, true );
    if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
        gim_log_error( 'OAuth2: нет access_token. ' . gim_short_error_body( $body ) );
        $GLOBALS['gim_access_token_cache'] = null;
        return null;
    }

    $token = (string) $data['access_token'];
    set_transient( $transient_key, $token, 55 * MINUTE_IN_SECONDS );

    $GLOBALS['gim_access_token_cache'] = $token;
    return $token;
}

/* -------------------------------------------------------------------------
 * Список ресурсов в Search Console.
 * ---------------------------------------------------------------------- */

function gim_list_sites(): array {
    $token = gim_get_access_token();
    if ( ! $token ) {
        return [ 'ok' => false, 'sites' => [], 'error' => 'Не удалось получить access token.' ];
    }

    $response = wp_remote_get(
        'https://searchconsole.googleapis.com/webmasters/v3/sites',
        [
            'timeout' => 20,
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ]
    );

    if ( is_wp_error( $response ) ) {
        return [ 'ok' => false, 'sites' => [], 'error' => $response->get_error_message() ];
    }

    $code     = (int) wp_remote_retrieve_response_code( $response );
    $raw_body = wp_remote_retrieve_body( $response );
    $data     = json_decode( $raw_body, true );

    if ( $code !== 200 || ! is_array( $data ) ) {
        $msg = is_array( $data ) && isset( $data['error']['message'] )
            ? $data['error']['message']
            : $raw_body;
        return [ 'ok' => false, 'sites' => [], 'error' => sprintf( 'HTTP %d: %s', $code, $msg ) ];
    }

    $sites = [];
    if ( ! empty( $data['siteEntry'] ) && is_array( $data['siteEntry'] ) ) {
        foreach ( $data['siteEntry'] as $entry ) {
            $sites[] = [
                'siteUrl'         => (string) ( $entry['siteUrl'] ?? '' ),
                'permissionLevel' => (string) ( $entry['permissionLevel'] ?? '' ),
            ];
        }
    }

    return [ 'ok' => true, 'sites' => $sites, 'error' => '' ];
}

function gim_diagnostic_check_site_url(): array {
    $configured = gim_get_site_url();
    $list       = gim_list_sites();

    if ( ! $list['ok'] ) {
        return [
            'ok'      => false,
            'matched' => '',
            'message' => 'Не удалось получить список ресурсов: ' . $list['error'],
        ];
    }

    if ( empty( $list['sites'] ) ) {
        return [
            'ok'      => false,
            'matched' => '',
            'message' => 'Сервисный аккаунт не имеет доступа ни к одному ресурсу.',
        ];
    }

    foreach ( $list['sites'] as $site ) {
        if ( $site['siteUrl'] === $configured ) {
            return [
                'ok'      => true,
                'matched' => $site['siteUrl'],
                'message' => sprintf(
                    'Ресурс найден. Формат siteUrl совпадает. Уровень прав: %s.',
                    $site['permissionLevel']
                ),
            ];
        }
    }

    $configured_alt = trailingslashit( $configured );
    foreach ( $list['sites'] as $site ) {
        if ( $site['siteUrl'] === $configured_alt ) {
            return [
                'ok'      => true,
                'matched' => $site['siteUrl'],
                'message' => sprintf(
                    'Ресурс найден, но с другим форматом. В настройках «%s», в Search Console «%s». Исправьте на «%s».',
                    $configured,
                    $site['siteUrl'],
                    $site['siteUrl']
                ),
            ];
        }
    }

    $available = array_map(
        static function ( $s ) {
            return $s['siteUrl'] . ' (' . $s['permissionLevel'] . ')';
        },
        $list['sites']
    );

    return [
        'ok'      => false,
        'matched' => '',
        'message' => sprintf(
            'Ресурс «%s» не найден. Доступные: %s',
            $configured,
            implode( '; ', $available )
        ),
    ];
}

/* -------------------------------------------------------------------------
 * URL Inspection API.
 * ---------------------------------------------------------------------- */

function gim_is_coverage_indexed( string $coverage ): bool {
    $indexed_states = [
        'Submitted and indexed',
        'Indexed, not submitted in sitemap',
        'Страница отправлена и проиндексирована',
        'Проиндексировано, не отправлено в sitemap',
        'Проиндексировано, не добавлено в sitemap',
    ];

    return in_array( $coverage, $indexed_states, true );
}

function gim_inspect_url( string $url ): array {
    $token = gim_get_access_token();
    if ( ! $token ) {
        return [ 'indexed' => false, 'status' => 'error', 'coverage' => 'No token' ];
    }

    $body = [
        'inspectionUrl' => $url,
        'siteUrl'       => gim_get_site_url(),
        'languageCode'  => 'ru-RU',
    ];

    $response = wp_remote_post(
        'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
        [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
        ]
    );

    if ( is_wp_error( $response ) ) {
        gim_log_error( 'Inspection: ' . $response->get_error_message(), [ 'url' => $url ] );
        return [ 'indexed' => false, 'status' => 'error', 'coverage' => 'WP_Error' ];
    }

    $code     = (int) wp_remote_retrieve_response_code( $response );
    $raw_body = wp_remote_retrieve_body( $response );
    $data     = json_decode( $raw_body, true );

    if ( $code !== 200 || ! is_array( $data ) ) {
        $msg = is_array( $data ) && isset( $data['error']['message'] )
            ? (string) $data['error']['message']
            : gim_short_error_body( (string) $raw_body );
        gim_log_error( sprintf( 'Inspection: HTTP %d — %s', $code, $msg ), [ 'url' => $url ] );

        if ( $code === 401 ) {
            // The token is not accepted: drop it so the next attempt signs a
            // fresh JWT instead of replaying a rejected one.
            gim_clear_token_cache();
            return [ 'indexed' => false, 'status' => 'error', 'coverage' => 'Нет авторизации (401)' ];
        }
        if ( $code === 429 ) {
            gim_mark_quota_exhausted( 'inspection' );
            return [ 'indexed' => false, 'status' => 'error', 'coverage' => 'Превышен лимит (429)' ];
        }
        if ( $code === 403 ) {
            return [ 'indexed' => false, 'status' => 'error', 'coverage' => 'Нет доступа (403)' ];
        }

        return [ 'indexed' => false, 'status' => 'error', 'coverage' => 'HTTP ' . $code ];
    }

    $result   = $data['inspectionResult']['indexStatusResult'] ?? [];
    $coverage = (string) ( $result['coverageState'] ?? 'UNKNOWN' );
    $verdict  = (string) ( $result['verdict'] ?? 'UNKNOWN' );

    $indexed = gim_is_coverage_indexed( $coverage );
    if ( ! $indexed && $verdict === 'PASS' ) {
        $indexed = true;
    }

    return [
        'indexed'  => $indexed,
        'status'   => $verdict,
        'coverage' => $coverage,
    ];
}

/* -------------------------------------------------------------------------
 * Indexing API.
 * ---------------------------------------------------------------------- */

function gim_publish_single( string $url ): array {
    $token = gim_get_access_token();
    if ( ! $token ) {
        return [
            'ok'             => false,
            'code'           => 0,
            'message'        => 'No token',
            'is_quota_error' => false,
            'is_auth_error'  => true,
        ];
    }

    $response = wp_remote_post(
        'https://indexing.googleapis.com/v3/urlNotifications:publish',
        [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [ 'url' => $url, 'type' => 'URL_UPDATED' ] ),
        ]
    );

    if ( is_wp_error( $response ) ) {
        return [
            'ok'             => false,
            'code'           => 0,
            'message'        => $response->get_error_message(),
            'is_quota_error' => false,
            'is_auth_error'  => false,
        ];
    }

    $code     = (int) wp_remote_retrieve_response_code( $response );
    $raw_body = (string) wp_remote_retrieve_body( $response );

    if ( $code === 200 ) {
        return [
            'ok'             => true,
            'code'           => 200,
            'message'        => 'OK',
            'is_quota_error' => false,
            'is_auth_error'  => false,
        ];
    }

    $data = json_decode( $raw_body, true );
    $msg  = is_array( $data ) && isset( $data['error']['message'] )
        ? (string) $data['error']['message']
        : gim_short_error_body( $raw_body );

    if ( $code === 401 ) {
        gim_clear_token_cache();
    }

    if ( $code === 429 ) {
        gim_mark_quota_exhausted( 'indexing' );
        return [
            'ok'             => false,
            'code'           => 429,
            'message'        => $msg,
            'is_quota_error' => true,
            'is_auth_error'  => false,
        ];
    }

    return [
        'ok'             => false,
        'code'           => $code,
        'message'        => $msg,
        'is_quota_error' => false,
        'is_auth_error'  => in_array( $code, [ 401, 403 ], true ),
    ];
}

function gim_publish_batch( array $urls ): int {
    $success = 0;

    foreach ( $urls as $url ) {
        $result = gim_publish_single( $url );

        if ( $result['ok'] ) {
            $success++;
        } elseif ( ! empty( $result['is_quota_error'] ) ) {
            gim_log_error( 'Квота Indexing API исчерпана — прерываем отправку' );
            break;
        } elseif ( ! empty( $result['is_auth_error'] ) ) {
            // 401/403 will repeat for every URL: stop the batch instead of
            // burning one failed request per queued item.
            gim_log_error(
                sprintf( 'Отправка остановлена: доступ отклонён (HTTP %d)', (int) $result['code'] ),
                [ 'url' => $url ]
            );
            break;
        }

        usleep( 200000 );
    }

    return $success;
}