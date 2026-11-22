<?php
/**
 * Plugin updates through the native WordPress mechanism, with GitHub as the
 * source. The repository is public, so no token is involved: the update check
 * and the package download are anonymous HTTPS requests.
 *
 * Behaviour follows the shared updater specification:
 *   - the latest release and the newest vX.Y.Z tag are both queried, the
 *     highest version wins, and on a tie the release wins (it has a changelog);
 *   - only strict semver is accepted, prereleases and four-part versions are
 *     dropped;
 *   - the package must be https on a GitHub host;
 *   - the installed version is read from the plugin header on disk, so the
 *     request that replaces the files cannot offer the same version again;
 *   - the transient key is plugin_basename() at runtime, and stale entries
 *     under another key are removed.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GIM_GitHub_Updater {

    private const REPO            = 'Gnoztis/google-indexing-manager';
    private const SLUG            = 'google-indexing-manager';
    private const TRANSIENT       = 'gim_github_release';
    private const ERROR_TRANSIENT = 'gim_github_release_error';
    private const CACHE_TTL       = 6 * HOUR_IN_SECONDS;
    private const ERROR_TTL       = 15 * MINUTE_IN_SECONDS;
    private const REQUEST_TIMEOUT = 8;

    /** Hosts a package may be downloaded from. */
    private const PACKAGE_HOSTS = [
        'api.github.com',
        'github.com',
        'codeload.github.com',
        'objects.githubusercontent.com',
    ];

    /**
     * Version headers of the plugin file, read once per request.
     *
     * @var array{requires:string,requires_php:string,tested:string}|null
     */
    private static ?array $header = null;

    public function register_hooks(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_source_selection', [ $this, 'rename_source' ], 10, 4 );
        add_action( 'upgrader_process_complete', [ $this, 'after_upgrade' ], 10, 2 );
        add_action( 'load-plugins.php', [ $this, 'maybe_force_check' ] );
        add_filter( 'plugin_action_links_' . self::basename(), [ $this, 'action_links' ] );
    }

    /**
     * Basename of the installed main plugin file, e.g.
     * "google-indexing-manager/google-indexing-manager.php".
     *
     * Computed at runtime, never taken from a constant: WordPress keys the
     * update_plugins response by this basename and get_plugin_updates() lists
     * only entries whose key matches an installed plugin file. A hard-coded key
     * produces an update badge with an empty update list when the directory is
     * renamed (for example after unpacking a GitHub zipball).
     */
    public static function basename(): string {
        $main_file = self::main_file();
        $basename  = plugin_basename( $main_file );

        if ( '' === $basename ) {
            $basename = self::SLUG . '/' . wp_basename( $main_file );
        }

        return $basename;
    }

    /**
     * Absolute path of the main plugin file.
     */
    private static function main_file(): string {
        if ( defined( 'GIM_PLUGIN_FILE' ) ) {
            return (string) GIM_PLUGIN_FILE;
        }

        $dir = defined( 'GIM_DIR' ) ? (string) GIM_DIR : __DIR__ . '/..';

        return $dir . '/google-indexing-manager.php';
    }

    /* ============================================================
     *  Updates
     * ============================================================ */

    /**
     * Adds our entry to the update_plugins transient when GitHub has a newer
     * release or tag.
     *
     * @param mixed $transient
     * @return mixed
     */
    public function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = [];
        }

        $basename  = self::basename();
        $installed = $this->installed_version();

        // Runs before the early returns: a dangling entry is a leftover of the
        // previous key and must disappear even when GitHub is unreachable.
        $this->purge_stale_entries( $transient, $basename );
        $this->purge_installed_entry( $transient, $basename, $installed );

        $release = $this->get_release();

        if ( null === $release ) {
            return $transient;
        }

        $const = defined( 'GIM_VERSION' ) ? (string) GIM_VERSION : '';

        if ( version_compare( $release['version'], $installed, '<=' ) ) {
            return $transient;
        }

        if ( '' !== $const && version_compare( $release['version'], $const, '<=' ) ) {
            return $transient;
        }

        $transient->response[ $basename ] = (object) [
            'id'            => $basename,
            'slug'          => self::SLUG,
            'plugin'        => $basename,
            'new_version'   => $release['version'],
            'url'           => 'https://github.com/' . self::REPO,
            'package'       => $release['package'],
            'tested'        => $release['tested'],
            'requires'      => $release['requires'],
            'requires_php'  => $release['requires_php'],
            'icons'         => [],
            'banners'       => [],
            'compatibility' => new stdClass(),
        ];

        if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
            unset( $transient->no_update[ $basename ] );
        }

        return $transient;
    }

    /**
     * Version of the main plugin file as it is on disk.
     *
     * Inside the request that replaces the files the GIM_VERSION constant still
     * holds the old value, while the header already holds the new one.
     */
    private function installed_version(): string {
        $data = function_exists( 'get_file_data' )
            ? get_file_data( self::main_file(), [ 'Version' => 'Version' ], 'plugin' )
            : [];

        $version = isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';

        if ( '' !== $version ) {
            return $version;
        }

        return defined( 'GIM_VERSION' ) ? (string) GIM_VERSION : '';
    }

    /**
     * Removes our own update entry when it offers what is already installed.
     *
     * @param mixed  $transient
     * @param string $basename  Our key in the transient.
     * @param string $installed Version from the file on disk.
     */
    private function purge_installed_entry( $transient, string $basename, string $installed ): void {
        if ( ! isset( $transient->response[ $basename ] ) || ! is_object( $transient->response[ $basename ] ) ) {
            return;
        }

        $entry   = $transient->response[ $basename ];
        $offered = isset( $entry->new_version ) ? trim( (string) $entry->new_version ) : '';

        if ( '' === $offered || '' === $installed || version_compare( $offered, $installed, '<=' ) ) {
            unset( $transient->response[ $basename ] );
        }
    }

    /**
     * Drops update entries that point at our main file under a foreign key.
     *
     * @param mixed  $transient
     * @param string $basename Our real basename, kept as is.
     */
    private function purge_stale_entries( $transient, string $basename ): void {
        $main = wp_basename( self::main_file() );

        foreach ( [ 'response', 'no_update' ] as $bucket ) {
            if ( ! isset( $transient->$bucket ) || ! is_array( $transient->$bucket ) ) {
                continue;
            }

            foreach ( $transient->$bucket as $key => $entry ) {
                if ( (string) $key === $basename || ! is_object( $entry ) ) {
                    continue;
                }
                if ( empty( $entry->plugin ) || wp_basename( (string) $entry->plugin ) !== $main ) {
                    continue;
                }

                unset( $transient->$bucket[ $key ] );
            }
        }
    }

    /**
     * Data for the "View details" modal.
     *
     * @param mixed  $result
     * @param string $action
     * @param mixed  $args
     * @return mixed
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
            return $result;
        }

        if ( self::SLUG !== (string) $args->slug ) {
            return $result;
        }

        $release = $this->get_release();

        if ( null === $release ) {
            return $result;
        }

        $header = $this->header_data();

        return (object) [
            'name'          => 'Google Indexing Manager',
            'slug'          => self::SLUG,
            'version'       => $release['version'],
            'author'        => '<a href="https://github.com/Gnoztis">Gnostis</a>',
            'homepage'      => 'https://github.com/' . self::REPO,
            'requires'      => $header['requires'],
            'tested'        => $this->tested_version( $header['tested'] ),
            'requires_php'  => $header['requires_php'],
            'download_link' => $release['package'],
            'last_updated'  => $release['published_at'],
            'sections'      => [
                'description' => __( 'Отправляет не проиндексированные URL в Google Indexing API и проверяет статус через URL Inspection API. Ключ сервисного аккаунта хранится в зашифрованном виде.', 'google-indexing-manager' ),
                'changelog'   => '' !== $release['body']
                    ? wpautop( $release['body'] )
                    : __( 'В релизе нет описания изменений.', 'google-indexing-manager' ),
            ],
        ];
    }

    /**
     * GitHub archives unpack into a directory like
     * Gnoztis-google-indexing-manager-1a2b3c4; rename it to the directory the
     * site actually uses, otherwise WordPress would install a second copy.
     *
     * @param string $source
     * @param string $remote_source
     * @param mixed  $upgrader
     * @param array  $hook_extra
     * @return string
     */
    public function rename_source( $source, $remote_source, $upgrader, $hook_extra = [] ) {
        if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
            return $source;
        }
        if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) ) {
            return $source;
        }
        if ( self::basename() !== (string) $hook_extra['plugin'] ) {
            return $source;
        }
        if ( ! is_dir( $source ) ) {
            return $source;
        }
        if ( ! is_file( trailingslashit( $source ) . wp_basename( self::main_file() ) ) ) {
            return $source;
        }

        $folder = dirname( self::basename() );

        if ( '.' === $folder || '' === $folder ) {
            return $source;
        }

        $desired = trailingslashit( $remote_source ) . $folder;

        if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
            return $source;
        }

        global $wp_filesystem;

        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ( ! $wp_filesystem ) {
            return $source;
        }

        if ( $wp_filesystem->move( $source, $desired, true ) ) {
            return trailingslashit( $desired );
        }

        return $source;
    }

    /**
     * Resets the update-check cache after our upgrade.
     *
     * @param mixed $upgrader
     * @param array $hook_extra
     */
    public function after_upgrade( $upgrader = null, $hook_extra = [] ): void {
        if ( ! is_array( $hook_extra ) ) {
            return;
        }

        $ours = false;

        if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
            $ours = in_array( self::basename(), $hook_extra['plugins'], true );
        } elseif ( ! empty( $hook_extra['plugin'] ) ) {
            $ours = self::basename() === (string) $hook_extra['plugin'];
        }

        if ( ! $ours ) {
            return;
        }

        $this->flush();
    }

    /**
     * Handles the "Проверить обновления" click on plugins.php.
     */
    public function maybe_force_check(): void {
        if ( ! isset( $_GET['gim-force-check'] ) ) {
            return;
        }
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }
        check_admin_referer( 'gim_force_check' );

        $this->flush();

        require_once ABSPATH . 'wp-admin/includes/update.php';

        if ( function_exists( 'wp_update_plugins' ) ) {
            wp_update_plugins();
        }

        wp_safe_redirect( admin_url( 'plugins.php' ) );
        exit;
    }

    /**
     * "Проверить обновления" in the plugin row.
     *
     * @param string[] $links
     * @return string[]
     */
    public function action_links( $links ): array {
        $links = (array) $links;

        if ( ! current_user_can( 'update_plugins' ) ) {
            return $links;
        }

        $url = wp_nonce_url(
            add_query_arg( 'gim-force-check', '1', admin_url( 'plugins.php' ) ),
            'gim_force_check'
        );

        $links['gim_check'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Проверить обновления', 'google-indexing-manager' ) . '</a>';

        return $links;
    }

    /* ============================================================
     *  GitHub API
     * ============================================================ */

    /**
     * Latest version data (6 hour cache, 15 minute backoff after an error).
     */
    private function get_release(): ?array {
        $cached = get_transient( self::TRANSIENT );

        if ( is_array( $cached ) && isset( $cached['version'], $cached['package'] ) ) {
            return $cached;
        }

        if ( get_transient( self::ERROR_TRANSIENT ) ) {
            return null;
        }

        $release = $this->fetch_release();

        if ( null === $release ) {
            set_transient( self::ERROR_TRANSIENT, [ 'code' => 0 ], self::ERROR_TTL );
            return null;
        }

        delete_transient( self::ERROR_TRANSIENT );
        set_transient( self::TRANSIENT, $release, self::CACHE_TTL );

        return $release;
    }

    /**
     * Highest version of both sources: the latest release and the newest
     * vX.Y.Z tag. A tag can be newer than the published release, so both are
     * always queried; on equal versions the release wins.
     */
    private function fetch_release(): ?array {
        $release = $this->fetch_latest_release();
        $tag     = $this->fetch_latest_tag();

        if ( null === $release && null === $tag ) {
            return null;
        }
        if ( null === $release ) {
            return $tag;
        }
        if ( null === $tag ) {
            return $release;
        }

        return version_compare( $tag['version'], $release['version'], '>' ) ? $tag : $release;
    }

    private function fetch_latest_release(): ?array {
        $body = $this->api_get( sprintf( 'https://api.github.com/repos/%s/releases/latest', self::REPO ) );

        if ( null === $body ) {
            return null;
        }

        $version = $this->normalize_version( isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '' );

        if ( '' === $version ) {
            return null;
        }

        $package = $this->find_package( $body );

        if ( '' === $package ) {
            return null;
        }

        return $this->build_release(
            $version,
            $package,
            isset( $body['body'] ) ? (string) $body['body'] : '',
            isset( $body['published_at'] ) ? (string) $body['published_at'] : ''
        );
    }

    /**
     * Newest semver tag of the repository (a tag without a release is normal).
     */
    private function fetch_latest_tag(): ?array {
        $tags = $this->api_get( sprintf( 'https://api.github.com/repos/%s/tags?per_page=30', self::REPO ) );

        if ( ! is_array( $tags ) ) {
            return null;
        }

        $best_version = '';
        $best_tag     = '';

        foreach ( $tags as $tag ) {
            if ( ! is_array( $tag ) || empty( $tag['name'] ) ) {
                continue;
            }

            $version = $this->normalize_version( (string) $tag['name'] );

            if ( '' === $version ) {
                continue;
            }

            if ( '' === $best_version || version_compare( $version, $best_version, '>' ) ) {
                $best_version = $version;
                $best_tag     = (string) $tag['name'];
            }
        }

        if ( '' === $best_version || '' === $best_tag ) {
            return null;
        }

        $package = $this->safe_package_url(
            sprintf(
                'https://api.github.com/repos/%s/zipball/%s',
                self::REPO,
                rawurlencode( $best_tag )
            )
        );

        if ( '' === $package ) {
            return null;
        }

        return $this->build_release( $best_version, $package, '', '' );
    }

    /**
     * @return array{version:string,package:string,body:string,published_at:string,tested:string,requires:string,requires_php:string}
     */
    private function build_release( string $version, string $package, string $body, string $published_at ): array {
        $header = $this->header_data();

        return [
            'version'      => $version,
            'package'      => $package,
            'body'         => $body,
            'published_at' => $published_at,
            'tested'       => $this->tested_version( $header['tested'] ),
            'requires'     => $header['requires'],
            'requires_php' => $header['requires_php'],
        ];
    }

    /**
     * "Tested up to" turned into the value WordPress compares with.
     *
     * The header holds a branch claim ("7.1") while the update screen compares
     * against the full running version ("7.1.5"); a branch claim is always
     * lower, so it would render "Not tested". Expand the claim to the running
     * patch release of the same branch.
     */
    private function tested_version( string $tested ): string {
        $tested = trim( $tested );

        if ( '' === $tested ) {
            return '';
        }

        $running = function_exists( 'wp_get_wp_version' ) ? (string) wp_get_wp_version() : '';

        if ( '' === $running && isset( $GLOBALS['wp_version'] ) ) {
            $running = (string) $GLOBALS['wp_version'];
        }

        $running = trim( (string) preg_replace( '/-.*/', '', $running ) );

        if ( '' === $running ) {
            return $tested;
        }

        $tested_parts  = explode( '.', $tested );
        $running_parts = explode( '.', $running );

        if ( count( $tested_parts ) >= count( $running_parts ) ) {
            return $tested;
        }

        if ( array_slice( $running_parts, 0, count( $tested_parts ) ) === $tested_parts ) {
            return $running;
        }

        return $tested;
    }

    /**
     * GET to the GitHub API. Anonymous: the repository is public.
     *
     * @return array<string,mixed>|null
     */
    private function api_get( string $url ): ?array {
        // phpcs:ignore WordPress.WP.AlternativeFunctions -- URL built from the repository constant and the API paths above, never from user input.
        $response = wp_remote_get(
            $url,
            [
                'timeout'     => self::REQUEST_TIMEOUT,
                'redirection' => 3,
                'headers'     => [
                    'Accept'               => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent'           => 'Google-Indexing-Manager/' . ( defined( 'GIM_VERSION' ) ? GIM_VERSION : '1.12.0' ),
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( 200 !== $code ) {
            return null;
        }

        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        return is_array( $body ) ? $body : null;
    }

    /**
     * Archive URL: a release zip asset first, then the GitHub zipball.
     *
     * @param array $body
     */
    private function find_package( array $body ): string {
        if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
            foreach ( $body['assets'] as $asset ) {
                if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
                    continue;
                }

                $name = isset( $asset['name'] ) ? (string) $asset['name'] : '';

                if ( ! preg_match( '/\.zip$/i', $name ) ) {
                    continue;
                }

                $url = $this->safe_package_url( (string) $asset['browser_download_url'] );

                if ( '' !== $url ) {
                    return $url;
                }
            }
        }

        if ( ! empty( $body['zipball_url'] ) ) {
            return $this->safe_package_url( (string) $body['zipball_url'] );
        }

        return '';
    }

    /**
     * HTTPS only and GitHub hosts only: the package must not come from a field
     * a third-party service could tamper with.
     */
    private function safe_package_url( string $url ): string {
        $url = esc_url_raw( trim( $url ) );

        if ( '' === $url ) {
            return '';
        }

        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        $host   = wp_parse_url( $url, PHP_URL_HOST );

        if ( 'https' !== strtolower( (string) $scheme ) ) {
            return '';
        }

        if ( ! in_array( strtolower( (string) $host ), self::PACKAGE_HOSTS, true ) ) {
            return '';
        }

        return $url;
    }

    private function normalize_version( string $tag ): string {
        $tag = trim( $tag );

        if ( ! preg_match( '/^v?(\d+\.\d+(?:\.\d+)?)$/', $tag, $matches ) ) {
            return '';
        }

        return $matches[1];
    }

    /**
     * Version headers of the plugin file.
     *
     * @return array{requires:string,requires_php:string,tested:string}
     */
    private function header_data(): array {
        if ( null === self::$header ) {
            $data = function_exists( 'get_file_data' )
                ? get_file_data(
                    self::main_file(),
                    [
                        'Requires at least' => 'Requires at least',
                        'Requires PHP'      => 'Requires PHP',
                        'Tested up to'      => 'Tested up to',
                    ],
                    'plugin'
                )
                : [];

            self::$header = [
                'requires'     => isset( $data['Requires at least'] ) ? (string) $data['Requires at least'] : '',
                'requires_php' => isset( $data['Requires PHP'] ) ? (string) $data['Requires PHP'] : '',
                'tested'       => isset( $data['Tested up to'] ) ? (string) $data['Tested up to'] : '',
            ];
        }

        return self::$header;
    }

    /**
     * Drops every cached value of this class.
     */
    private function flush(): void {
        delete_transient( self::TRANSIENT );
        delete_transient( self::ERROR_TRANSIENT );
        delete_site_transient( 'update_plugins' );

        self::$header = null;
    }
}

/**
 * Registers the GitHub updater on every request (front end, admin, WP-Cron).
 */
function gim_updater_init(): void {
    static $registered = false;

    if ( $registered ) {
        return;
    }

    $registered = true;

    $updater = new GIM_GitHub_Updater();
    $updater->register_hooks();
}
