<?php
/**
 * Meta Graph API & OAuth client for SB FB Profile Importer.
 *
 * Handles: code exchange, long-lived token, /me/accounts, page posts, health check.
 * No tokens in logs when WP_DEBUG is on.
 *
 * @package SB_FB_Profile_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SB_FB_Profile_Importer_Meta_Client
 */
class SB_FB_Profile_Importer_Meta_Client {

    private const OPTION_APP_ID       = 'sb_fbpi_app_id';
    private const OPTION_APP_SECRET    = 'sb_fbpi_app_secret';
    private const OPTION_GRAPH_VERSION = 'sb_fbpi_graph_version';
    private const OPTION_PAGE_TOKEN    = 'sb_fbpi_page_access_token';
    private const OPTION_PAGE_ID      = 'sb_fbpi_page_id';
    private const OPTION_LONG_LIVED    = 'sb_fbpi_long_lived_user_token';

    /**
     * Get Graph API base URL.
     */
    private function get_graph_base(): string {
        $version = get_option( self::OPTION_GRAPH_VERSION, 'v19.0' );
        $version = preg_replace( '/[^a-z0-9._-]/i', '', $version ) ?: 'v19.0';
        return 'https://graph.facebook.com/' . $version;
    }

    /**
     * Get Facebook OAuth dialog URL (same version as graph for consistency).
     */
    private function get_dialog_base(): string {
        $version = get_option( self::OPTION_GRAPH_VERSION, 'v19.0' );
        $version = preg_replace( '/[^a-z0-9._-]/i', '', $version ) ?: 'v19.0';
        return 'https://www.facebook.com/' . $version;
    }

    /**
     * Log debug message (no tokens).
     */
    private function log( string $message, array $context = [] ): void {
        if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            return;
        }
        $safe = $context;
        unset( $safe['token'], $safe['access_token'], $safe['code'] );
        if ( $safe !== $context ) {
            $context = $safe;
        }
        error_log( '[SB FBPI] ' . $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ) );
    }

    /**
     * Make GET request to Graph API.
     *
     * @param string $endpoint e.g. "me/accounts" or "{page-id}/posts"
     * @param array  $params   Query params (access_token added by caller or here).
     * @return array{data?: array, error?: array}|WP_Error
     */
    public function graph_get( string $endpoint, array $params = [] ): array|WP_Error {
        $url = $this->get_graph_base() . '/' . ltrim( $endpoint, '/' );
        $url = add_query_arg( $params, $url );

        $this->log( 'GET ' . $endpoint );

        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Request failed: ' . $response->get_error_message() );
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $this->log( 'Invalid JSON response' );
            return new WP_Error( 'sb_fbpi_invalid_json', __( 'Invalid response from Facebook.', 'sb-fb-profile-importer' ) );
        }

        if ( isset( $data['error'] ) ) {
            $err = $data['error'];
            $msg = $err['message'] ?? 'Unknown error';
            $code = $err['code'] ?? 0;
            $subcode = $err['error_subcode'] ?? null;
            $this->log( 'API error', [ 'code' => $code, 'subcode' => $subcode, 'message' => $msg ] );
            return new WP_Error( 'fb_error', $msg, [ 'code' => $code, 'error_subcode' => $subcode ] );
        }

        return $data;
    }

    /**
     * Exchange authorization code for short-lived user access token.
     *
     * @param string $code Authorization code from OAuth callback.
     * @return string|WP_Error Access token or error.
     */
    public function exchange_code_for_token( string $code ): string|WP_Error {
        $app_id     = get_option( self::OPTION_APP_ID, '' );
        $app_secret = get_option( self::OPTION_APP_SECRET, '' );
        if ( ! $app_id || ! $app_secret ) {
            return new WP_Error( 'sb_fbpi_config', __( 'App ID or App Secret not configured.', 'sb-fb-profile-importer' ) );
        }

        $url = add_query_arg( [
            'client_id'     => $app_id,
            'client_secret' => $app_secret,
            'redirect_uri'  => $this->get_redirect_uri(),
            'code'          => $code,
        ], $this->get_graph_base() . '/oauth/access_token' );

        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            $msg = $body['error']['message'] ?? 'Unknown error';
            $code_num = $body['error']['code'] ?? 0;
            $subcode  = $body['error']['error_subcode'] ?? null;
            $this->log( 'Token exchange error', [ 'code' => $code_num, 'subcode' => $subcode ] );
            return new WP_Error( 'fb_error', $msg, [ 'code' => $code_num, 'error_subcode' => $subcode ] );
        }

        $token = $body['access_token'] ?? null;
        if ( ! $token ) {
            return new WP_Error( 'sb_fbpi_no_token', __( 'No access token in response.', 'sb-fb-profile-importer' ) );
        }

        return $token;
    }

    /**
     * Exchange short-lived user token for long-lived user token.
     *
     * @param string $short_lived_token Short-lived user access token.
     * @return string|WP_Error Long-lived access token or error.
     */
    public function exchange_for_long_lived_token( string $short_lived_token ): string|WP_Error {
        $app_id     = get_option( self::OPTION_APP_ID, '' );
        $app_secret = get_option( self::OPTION_APP_SECRET, '' );
        if ( ! $app_id || ! $app_secret ) {
            return new WP_Error( 'sb_fbpi_config', __( 'App ID or App Secret not configured.', 'sb-fb-profile-importer' ) );
        }

        $url = add_query_arg( [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $app_id,
            'client_secret'     => $app_secret,
            'fb_exchange_token' => $short_lived_token,
        ], $this->get_graph_base() . '/oauth/access_token' );

        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            $msg = $body['error']['message'] ?? 'Unknown error';
            $code_num = $body['error']['code'] ?? 0;
            $subcode  = $body['error']['error_subcode'] ?? null;
            $this->log( 'Long-lived exchange error', [ 'code' => $code_num, 'subcode' => $subcode ] );
            return new WP_Error( 'fb_error', $msg, [ 'code' => $code_num, 'error_subcode' => $subcode ] );
        }

        $token = $body['access_token'] ?? null;
        if ( ! $token ) {
            return new WP_Error( 'sb_fbpi_no_token', __( 'No long-lived token in response.', 'sb-fb-profile-importer' ) );
        }

        return $token;
    }

    /**
     * Get redirect URI for OAuth callback.
     */
    public function get_redirect_uri(): string {
        return admin_url( 'admin.php?page=sb-fb-profile-importer' );
    }

    /**
     * Get list of Pages for the authenticated user (requires long-lived user token).
     *
     * @param string $user_access_token Long-lived user access token.
     * @return array<int, array{id: string, name: string, access_token: string}>|WP_Error
     */
    public function get_me_accounts( string $user_access_token ): array|WP_Error {
        $result = $this->graph_get( 'me/accounts', [
            'access_token' => $user_access_token,
            'fields'       => 'id,name,access_token',
        ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $data = $result['data'] ?? [];
        return is_array( $data ) ? $data : [];
    }

    /**
     * Fetch posts published by the Page, using the Page access token.
     *
     * @param string $page_id      Page ID.
     * @param string $page_token   Page access token.
     * @param int    $limit        Number of posts (default 5).
     * @return array|WP_Error List of posts or error.
     */
    public function get_page_feed( string $page_id, string $page_token, int $limit = 5 ): array|WP_Error {
        $limit = max( 1, min( 50, (int) $limit ) );
        $result = $this->graph_get( $page_id . '/posts', [
            'access_token' => $page_token,
            'fields'       => 'id,message,created_time,permalink_url,attachments{media,type,url,subattachments{media,image,url}}',
            'limit'        => $limit,
        ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $data = $result['data'] ?? [];
        return is_array( $data ) ? $data : [];
    }

    /**
     * Fetch Page posts for import (supports since timestamp).
     *
     * @param string $page_id    Page ID.
     * @param string $page_token Page access token.
     * @param array  $params     Optional. 'limit' (default 50), 'since' (unix timestamp).
     * @return array|WP_Error
     */
    public function get_page_feed_for_import( string $page_id, string $page_token, array $params = [] ): array|WP_Error {
        $limit = isset( $params['limit'] ) ? max( 1, min( 100, (int) $params['limit'] ) ) : 50;
        $query = [
            'access_token' => $page_token,
            'fields'       => 'id,message,created_time,permalink_url,attachments{media,type,url,subattachments{media,image,url}}',
            'limit'        => $limit,
        ];
        if ( ! empty( $params['since'] ) ) {
            $query['since'] = (int) $params['since'];
        }
        $result = $this->graph_get( $page_id . '/posts', $query );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $data = $result['data'] ?? [];
        return is_array( $data ) ? $data : [];
    }

    /**
     * Health check: GET /{page-id}?fields=id,name using Page Access Token.
     *
     * @param string $page_id    Page ID.
     * @param string $page_token Page access token.
     * @return true|WP_Error True if OK.
     */
    public function health_check( string $page_id, string $page_token ): true|WP_Error {
        $result = $this->graph_get( $page_id, [
            'access_token' => $page_token,
            'fields'       => 'id,name',
        ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( empty( $result['id'] ) ) {
            return new WP_Error( 'sb_fbpi_health', __( 'Invalid health check response.', 'sb-fb-profile-importer' ) );
        }

        return true;
    }

    /**
     * Get first image URL from post attachments (for thumbnail).
     *
     * @param array $post Single post from feed (with attachments).
     * @return string Empty string if none.
     */
    public static function get_post_thumbnail_url( array $post ): string {
        $attachments = $post['attachments']['data'] ?? [];
        foreach ( $attachments as $att ) {
            if ( isset( $att['media']['image']['src'] ) ) {
                return $att['media']['image']['src'];
            }
            if ( isset( $att['url'] ) && ( $att['type'] ?? '' ) === 'photo' ) {
                return $att['url'];
            }
        }
        return '';
    }
}
