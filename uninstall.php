<?php
/**
 * Removes plugin options when the plugin is deleted from the dashboard.
 *
 * @package SB_FB_Profile_Importer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$options = [
    'sb_fbpi_app_id',
    'sb_fbpi_app_secret',
    'sb_fbpi_graph_version',
    'sb_fbpi_page_access_token',
    'sb_fbpi_page_id',
    'sb_fbpi_page_name',
    'sb_fbpi_selected_page_name',
    'sb_fbpi_user_access_token',
    'sb_fbpi_long_lived_user_token',
    'sb_fbpi_token_last_verified_at',
    'sb_fbpi_oauth_state',
    'sb_fbpi_health_status',
    'sb_fbpi_num_posts',
    'sb_fbpi_cron_import_enabled',
    'sb_fbpi_import_count',
    'sb_fbpi_import_category',
    'sb_fbpi_import_post_status',
    'sb_fbpi_last_import_result',
    'sb_fbpi_notify_email',
    'sb_fbpi_settings',
    'sb_fbpi_logs',
    'sb_fbpi_last_run',
    'sb_fbpi_meta_migrated',
    'fbpi_app_id',
    'fbpi_app_secret',
    'fbpi_graph_version',
    'fbpi_page_access_token',
    'fbpi_page_id',
    'fbpi_page_name',
    'fbpi_selected_page_name',
    'fbpi_user_access_token',
    'fbpi_long_lived_user_token',
    'fbpi_token_last_verified_at',
    'fbpi_oauth_state',
    'fbpi_health_status',
    'fbpi_num_posts',
    'fbpi_cron_import_enabled',
    'fbpi_import_count',
    'fbpi_import_category',
    'fbpi_import_post_status',
    'fbpi_last_import_result',
    'fbpi_notify_email',
    'fbpi_settings',
    'fbpi_logs',
    'fbpi_last_run',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

delete_transient( 'sb_fbpi_last_token_notify' );
delete_transient( 'sb_fbpi_last_import_fail_notify' );
delete_transient( 'fbpi_last_token_notify' );
delete_transient( 'fbpi_last_import_fail_notify' );

wp_clear_scheduled_hook( 'sb_fbpi_health_check' );
wp_clear_scheduled_hook( 'sb_fbpi_import_posts' );
wp_clear_scheduled_hook( 'fbpi_health_check' );
wp_clear_scheduled_hook( 'fbpi_import_posts' );
