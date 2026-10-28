<?php
/**
 * Plugin Name:       SB FB Profile Importer
 * Plugin URI:        https://softwarebrewery.net
 * Description:       Connects a Facebook Page via OAuth and imports posts into WordPress. Developed by Software Brewery.
 * Version:           2.8.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Software Brewery
 * Author URI:        https://softwarebrewery.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sb-fb-profile-importer
 *
 * @package SB_FB_Profile_Importer
 */

defined( 'ABSPATH' ) || exit;

define( 'SB_FB_PROFILE_IMPORTER_VERSION', '2.8.1' );
define( 'SB_FB_PROFILE_IMPORTER_FILE', __FILE__ );
define( 'SB_FB_PROFILE_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'SB_FB_PROFILE_IMPORTER_CRON_HEALTH', 'sb_fbpi_health_check' );
define( 'SB_FB_PROFILE_IMPORTER_CRON_IMPORT', 'sb_fbpi_import_posts' );
define( 'SB_FB_PROFILE_IMPORTER_AUTHOR_URL', 'https://softwarebrewery.net' );

require_once SB_FB_PROFILE_IMPORTER_DIR . 'includes/class-meta-client.php';
require_once SB_FB_PROFILE_IMPORTER_DIR . 'includes/class-admin.php';

/**
 * Move legacy fbpi_* options and post meta onto the SB keys.
 */
function sb_fb_profile_importer_migrate(): void {
    $legacy = [
        'fbpi_user_access_token' => 'fbpi_long_lived_user_token',
        'fbpi_page_name'         => 'fbpi_selected_page_name',
    ];
    foreach ( $legacy as $old => $new ) {
        $value = get_option( $old, null );
        if ( null === $value ) {
            continue;
        }
        if ( null === get_option( $new, null ) ) {
            update_option( $new, $value );
        }
        delete_option( $old );
    }

    $keys = [
        'app_id',
        'app_secret',
        'graph_version',
        'page_access_token',
        'page_id',
        'page_name',
        'selected_page_name',
        'user_access_token',
        'long_lived_user_token',
        'token_last_verified_at',
        'oauth_state',
        'health_status',
        'num_posts',
        'cron_import_enabled',
        'import_count',
        'import_category',
        'import_post_status',
        'last_import_result',
        'notify_email',
        'settings',
        'logs',
        'last_run',
    ];

    foreach ( $keys as $key ) {
        $old   = 'fbpi_' . $key;
        $new   = 'sb_fbpi_' . $key;
        $value = get_option( $old, null );
        if ( null === $value ) {
            continue;
        }
        if ( null === get_option( $new, null ) ) {
            update_option( $new, $value );
        }
        delete_option( $old );
    }

    if ( get_option( 'sb_fbpi_graph_version', '' ) === '' ) {
        update_option( 'sb_fbpi_graph_version', 'v19.0' );
    }

    if ( '1' !== get_option( 'sb_fbpi_meta_migrated', '' ) ) {
        global $wpdb;
        $meta_keys = [
            '_fbpi_fb_post_id'      => '_sb_fbpi_fb_post_id',
            '_fbpi_fb_permalink'    => '_sb_fbpi_fb_permalink',
            '_fbpi_fb_created_time' => '_sb_fbpi_fb_created_time',
        ];
        foreach ( $meta_keys as $old_key => $new_key ) {
            $wpdb->update(
                $wpdb->postmeta,
                [ 'meta_key' => $new_key ],
                [ 'meta_key' => $old_key ],
                [ '%s' ],
                [ '%s' ]
            );
        }
        update_option( 'sb_fbpi_meta_migrated', '1' );
    }

    wp_clear_scheduled_hook( 'fbpi_health_check' );
    wp_clear_scheduled_hook( 'fbpi_import_posts' );
}

/**
 * Schedule or unschedule the import cron job.
 *
 * @param bool $enable True to schedule hourly import, false to unschedule.
 */
function sb_fb_profile_importer_schedule_import_cron( bool $enable ): void {
    $timestamp = wp_next_scheduled( SB_FB_PROFILE_IMPORTER_CRON_IMPORT );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, SB_FB_PROFILE_IMPORTER_CRON_IMPORT );
    }
    if ( $enable ) {
        wp_schedule_event( time(), 'hourly', SB_FB_PROFILE_IMPORTER_CRON_IMPORT );
    }
}

/**
 * Bootstrap plugin: Meta client, admin page, hooks, cron.
 */
final class SB_FB_Profile_Importer {

    private static ?self $instance = null;
    private ?SB_FB_Profile_Importer_Meta_Client $client = null;
    private ?SB_FB_Profile_Importer_Admin $admin_page = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'on_admin_init' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
        add_action( 'load-toplevel_page_sb-fb-profile-importer', [ $this, 'on_load_admin_page' ] );
        add_action( SB_FB_PROFILE_IMPORTER_CRON_HEALTH, [ $this, 'run_health_check_cron' ] );
        add_action( SB_FB_PROFILE_IMPORTER_CRON_IMPORT, [ $this, 'run_import_cron' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( SB_FB_PROFILE_IMPORTER_FILE ), [ $this, 'action_links' ] );
    }

    /**
     * Settings and author links on the Plugins screen.
     *
     * @param string[] $links Existing links.
     * @return string[]
     */
    public function action_links( array $links ): array {
        $url = admin_url( 'admin.php?page=sb-fb-profile-importer' );
        array_unshift(
            $links,
            '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'sb-fb-profile-importer' ) . '</a>'
        );
        $links[] = '<a href="' . esc_url( SB_FB_PROFILE_IMPORTER_AUTHOR_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Software Brewery', 'sb-fb-profile-importer' ) . '</a>';
        return $links;
    }

    private function get_client(): SB_FB_Profile_Importer_Meta_Client {
        if ( $this->client === null ) {
            $this->client = new SB_FB_Profile_Importer_Meta_Client();
        }
        return $this->client;
    }

    private function get_admin_page(): SB_FB_Profile_Importer_Admin {
        if ( $this->admin_page === null ) {
            $this->admin_page = new SB_FB_Profile_Importer_Admin( $this->get_client() );
        }
        return $this->admin_page;
    }

    public function add_admin_menu(): void {
        add_menu_page(
            __( 'SB FB Profile Importer', 'sb-fb-profile-importer' ),
            __( 'SB FB Importer', 'sb-fb-profile-importer' ),
            'manage_options',
            'sb-fb-profile-importer',
            [ $this, 'render_admin_page' ],
            'dashicons-facebook',
            30
        );
    }

    /**
     * Admin init: OAuth callback first, then other actions.
     */
    public function on_admin_init(): void {
        sb_fb_profile_importer_migrate();
        $this->get_admin_page()->handle_oauth_callback();
        $this->get_admin_page()->handle_actions();
    }

    /**
     * On load of our admin page: run health check (lightweight).
     */
    public function on_load_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $this->get_admin_page()->run_health_check();
    }

    public function run_health_check_cron(): void {
        $admin = new SB_FB_Profile_Importer_Admin( new SB_FB_Profile_Importer_Meta_Client() );
        $admin->run_health_check();
    }

    public function run_import_cron(): void {
        $result = $this->get_admin_page()->run_import();
        update_option( 'sb_fbpi_last_import_result', $result );
        if ( ! empty( $result['error'] ) ) {
            $this->get_admin_page()->maybe_notify_import_failed( $result );
        }
    }

    public function enqueue_styles( string $hook ): void {
        $this->get_admin_page()->enqueue_styles( $hook );
    }

    public function render_admin_page(): void {
        $this->get_admin_page()->render();
    }
}

add_action( 'plugins_loaded', [ 'SB_FB_Profile_Importer', 'instance' ] );

register_activation_hook( __FILE__, function() {
    sb_fb_profile_importer_migrate();
    if ( ! wp_next_scheduled( SB_FB_PROFILE_IMPORTER_CRON_HEALTH ) ) {
        wp_schedule_event( time(), 'daily', SB_FB_PROFILE_IMPORTER_CRON_HEALTH );
    }
    if ( get_option( 'sb_fbpi_cron_import_enabled', '0' ) === '1' ) {
        sb_fb_profile_importer_schedule_import_cron( true );
    }
} );

register_deactivation_hook( __FILE__, function() {
    $t = wp_next_scheduled( SB_FB_PROFILE_IMPORTER_CRON_HEALTH );
    if ( $t ) {
        wp_unschedule_event( $t, SB_FB_PROFILE_IMPORTER_CRON_HEALTH );
    }
    $t = wp_next_scheduled( SB_FB_PROFILE_IMPORTER_CRON_IMPORT );
    if ( $t ) {
        wp_unschedule_event( $t, SB_FB_PROFILE_IMPORTER_CRON_IMPORT );
    }
} );
