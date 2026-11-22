<?php
/**
 * Логирование, базовые хелперы, проверка прав.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * Хелперы настроек.
 * ---------------------------------------------------------------------- */

function gim_get_option( string $key, $default = '' ) {
    return get_option( 'gim_' . $key, $default );
}

function gim_update_option( string $key, $value ): bool {
    return update_option( 'gim_' . $key, $value, false );
}

function gim_get_site_url(): string {
    $url = (string) gim_get_option( 'site_url', '' );
    if ( $url === '' ) {
        $url = home_url( '/' );
    }
    return untrailingslashit( $url );
}

/* -------------------------------------------------------------------------
 * Проверка прав.
 * ---------------------------------------------------------------------- */

function gim_current_user_is_admin(): bool {
    if ( ! is_user_logged_in() ) {
        return false;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return false;
    }

    if ( is_multisite() && ! is_super_admin() ) {
        return false;
    }

    $user = wp_get_current_user();
    if ( ! $user || ! is_array( $user->roles ) ) {
        return false;
    }

    return in_array( 'administrator', $user->roles, true );
}

function gim_current_user_can(): bool {
    return gim_current_user_is_admin();
}

/* -------------------------------------------------------------------------
 * Логирование.
 * ---------------------------------------------------------------------- */

function gim_log( string $level, string $message, array $context = [] ): void {
    global $wpdb;
    $table = $wpdb->prefix . 'gim_log';

    $wpdb->insert(
        $table,
        [
            'level'      => $level,
            'message'    => $message,
            'url'        => isset( $context['url'] ) ? (string) $context['url'] : null,
            'created_at' => current_time( 'mysql' ),
        ],
        [ '%s', '%s', '%s', '%s' ]
    );
}

function gim_log_debug( string $message, array $context = [] ): void {
    gim_log( 'debug', $message, $context );
}

function gim_log_info( string $message, array $context = [] ): void {
    gim_log( 'info', $message, $context );
}

function gim_log_warning( string $message, array $context = [] ): void {
    gim_log( 'warning', $message, $context );
}

function gim_log_error( string $message, array $context = [] ): void {
    gim_log( 'error', $message, $context );
}