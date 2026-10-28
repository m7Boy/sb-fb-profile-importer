<?php
/**
 * Admin page for SB FB Profile Importer: render UI and handle actions.
 *
 * @package SB_FB_Profile_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SB_FB_Profile_Importer_Admin
 */
class SB_FB_Profile_Importer_Admin {

    private const OPTION_APP_ID           = 'sb_fbpi_app_id';
    private const OPTION_APP_SECRET        = 'sb_fbpi_app_secret';
    private const OPTION_GRAPH_VERSION     = 'sb_fbpi_graph_version';
    private const OPTION_PAGE_ID          = 'sb_fbpi_page_id';
    private const OPTION_PAGE_TOKEN       = 'sb_fbpi_page_access_token';
    private const OPTION_PAGE_NAME        = 'sb_fbpi_selected_page_name';
    private const OPTION_LONG_LIVED        = 'sb_fbpi_long_lived_user_token';
    private const OPTION_TOKEN_VERIFIED   = 'sb_fbpi_token_last_verified_at';
    private const OPTION_STATE            = 'sb_fbpi_oauth_state';
    private const OPTION_HEALTH_STATUS    = 'sb_fbpi_health_status';
    private const OPTION_NUM_POSTS           = 'sb_fbpi_num_posts';
    private const OPTION_CRON_IMPORT_ENABLED = 'sb_fbpi_cron_import_enabled';
    private const OPTION_IMPORT_COUNT        = 'sb_fbpi_import_count';
    private const OPTION_IMPORT_CATEGORY     = 'sb_fbpi_import_category';
    private const OPTION_IMPORT_POST_STATUS  = 'sb_fbpi_import_post_status';
    private const OPTION_LAST_IMPORT_RESULT   = 'sb_fbpi_last_import_result';
    private const OPTION_NOTIFY_EMAIL         = 'sb_fbpi_notify_email';
    private const META_FB_POST_ID            = '_sb_fbpi_fb_post_id';
    private const TRANSIENT_FEED_PREFIX      = 'sb_fbpi_feed_';
    private const TRANSIENT_FEED_EXPIRY      = 300; // 5 minutes
    private const TRANSIENT_TOKEN_NOTIFY     = 'sb_fbpi_last_token_notify';
    private const TRANSIENT_IMPORT_FAIL_NOTIFY = 'sb_fbpi_last_import_fail_notify';
    private const NOTIFY_THROTTLE_SECONDS    = 86400; // 24 hours

    private SB_FB_Profile_Importer_Meta_Client $client;

    public function __construct( SB_FB_Profile_Importer_Meta_Client $client ) {
        $this->client = $client;
    }

    /**
     * Mask token for display (first 4 + ... + last 4).
     */
    public static function mask_token( string $token ): string {
        if ( strlen( $token ) <= 12 ) {
            return '••••••••';
        }
        return substr( $token, 0, 4 ) . '…' . substr( $token, -4 );
    }

    /**
     * Run OAuth callback: validate state, exchange code -> short -> long, then redirect.
     */
    public function handle_oauth_callback(): void {
        if ( isset( $_GET['error'] ) ) {
            $msg = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : sanitize_text_field( wp_unslash( $_GET['error'] ) );
            $code = isset( $_GET['error_code'] ) ? absint( $_GET['error_code'] ) : 0;
            $subcode = isset( $_GET['error_subcode'] ) ? absint( $_GET['error_subcode'] ) : null;
            $display = $msg . ( $code ? ' (code: ' . $code . ( $subcode !== null ? ', subcode: ' . $subcode : '' ) . ')' : '' );
            $this->add_error( 'oauth_error', $display );
            return;
        }

        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        if ( $code === '' ) {
            if ( $this->is_user_authenticated() || $this->is_page_connected() ) {
                return;
            }
            $this->add_error( 'no_code', __( 'No authorization code received.', 'sb-fb-profile-importer' ) );
            return;
        }

        $short_token = $this->client->exchange_code_for_token( $code );
        if ( is_wp_error( $short_token ) ) {
            $this->add_error( 'token_error', $this->format_wp_error( $short_token ) );
            return;
        }

        $long_token = $this->client->exchange_for_long_lived_token( $short_token );
        if ( is_wp_error( $long_token ) ) {
            $this->add_error( 'long_token_error', $this->format_wp_error( $long_token ) );
            return;
        }

        update_option( self::OPTION_LONG_LIVED, $long_token );
        update_option( self::OPTION_TOKEN_VERIFIED, time() );
        delete_option( self::OPTION_HEALTH_STATUS );
        // Clear page so user re-selects and gets fresh page token from /me/accounts
        $old_page_id = get_option( self::OPTION_PAGE_ID, '' );
        delete_option( self::OPTION_PAGE_ID );
        delete_option( self::OPTION_PAGE_TOKEN );
        delete_option( self::OPTION_PAGE_NAME );
        if ( $old_page_id !== '' ) {
            delete_transient( self::TRANSIENT_FEED_PREFIX . $old_page_id . '_5' );
            for ( $n = 1; $n <= 50; $n++ ) {
                delete_transient( self::TRANSIENT_FEED_PREFIX . $old_page_id . '_' . $n );
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=sb-fb-profile-importer&connected=1' ) );
        exit;
    }

    /**
     * Format WP_Error for admin notice (include code/subcode if present).
     */
    private function format_wp_error( WP_Error $err ): string {
        $msg = $err->get_error_message();
        $code = $err->get_error_data( 'code' );
        $subcode = $err->get_error_data( 'error_subcode' );
        if ( $code !== null ) {
            $msg .= ' (code: ' . (int) $code;
            if ( $subcode !== null ) {
                $msg .= ', subcode: ' . (int) $subcode;
            }
            $msg .= ')';
        }
        return $msg;
    }

    private function add_error( string $code, string $message ): void {
        add_settings_error( 'sb_fbpi', $code, $message, 'error' );
    }

    /**
     * Handle form actions: save credentials, save page, disconnect, fetch posts.
     */
    public function handle_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['sb_fbpi_save_credentials'] ) ) {
            check_admin_referer( 'sb_fbpi_save_credentials', 'sb_fbpi_nonce' );
            $app_id     = isset( $_POST['sb_fbpi_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sb_fbpi_app_id'] ) ) : '';
            $app_secret = isset( $_POST['sb_fbpi_app_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['sb_fbpi_app_secret'] ) ) : '';
            $graph_ver  = isset( $_POST['sb_fbpi_graph_version'] ) ? sanitize_text_field( wp_unslash( $_POST['sb_fbpi_graph_version'] ) ) : 'v19.0';
            if ( $graph_ver === '' ) {
                $graph_ver = 'v19.0';
            }
            update_option( self::OPTION_APP_ID, $app_id );
            update_option( self::OPTION_APP_SECRET, $app_secret );
            update_option( self::OPTION_GRAPH_VERSION, $graph_ver );
            add_settings_error( 'sb_fbpi', 'saved', __( 'Settings saved.', 'sb-fb-profile-importer' ), 'success' );
        }

        if ( isset( $_POST['sb_fbpi_save_page'] ) ) {
            check_admin_referer( 'sb_fbpi_save_page', 'sb_fbpi_nonce' );
            $page_id    = isset( $_POST['sb_fbpi_page_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sb_fbpi_page_id'] ) ) : '';
            $page_token = isset( $_POST['sb_fbpi_page_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sb_fbpi_page_token'] ) ) : '';
            $page_name  = isset( $_POST['sb_fbpi_page_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sb_fbpi_page_name'] ) ) : '';
            if ( $page_id !== '' && $page_token !== '' ) {
                update_option( self::OPTION_PAGE_ID, $page_id );
                update_option( self::OPTION_PAGE_TOKEN, $page_token );
                update_option( self::OPTION_PAGE_NAME, $page_name );
                update_option( self::OPTION_TOKEN_VERIFIED, time() );
                delete_option( self::OPTION_HEALTH_STATUS );
                delete_transient( self::TRANSIENT_FEED_PREFIX . $page_id );
                add_settings_error( 'sb_fbpi', 'page_saved', __( 'Page saved.', 'sb-fb-profile-importer' ), 'success' );
            }
        }

        if ( isset( $_GET['sb_fbpi_disconnect'] ) && wp_verify_nonce( ( $_GET['_wpnonce'] ?? '' ), 'sb_fbpi_disconnect' ) ) {
            $old_page_id = get_option( self::OPTION_PAGE_ID, '' );
            delete_option( self::OPTION_PAGE_ID );
            delete_option( self::OPTION_PAGE_TOKEN );
            delete_option( self::OPTION_PAGE_NAME );
            delete_option( self::OPTION_LONG_LIVED );
            delete_option( self::OPTION_TOKEN_VERIFIED );
            delete_option( self::OPTION_HEALTH_STATUS );
            if ( $old_page_id !== '' ) {
                delete_transient( self::TRANSIENT_FEED_PREFIX . $old_page_id );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=sb-fb-profile-importer&disconnected=1' ) );
            exit;
        }

        if ( isset( $_POST['sb_fbpi_fetch_posts'] ) ) {
            check_admin_referer( 'sb_fbpi_fetch_posts', 'sb_fbpi_nonce' );
            $num = isset( $_POST['sb_fbpi_num_posts'] ) ? max( 1, min( 50, (int) $_POST['sb_fbpi_num_posts'] ) ) : 5;
            update_option( self::OPTION_NUM_POSTS, $num );
            $page_id = get_option( self::OPTION_PAGE_ID, '' );
            if ( $page_id !== '' ) {
                delete_transient( self::TRANSIENT_FEED_PREFIX . $page_id . '_' . get_option( self::OPTION_NUM_POSTS, 5 ) );
                delete_transient( self::TRANSIENT_FEED_PREFIX . $page_id . '_5' );
                for ( $i = 1; $i <= 50; $i++ ) {
                    delete_transient( self::TRANSIENT_FEED_PREFIX . $page_id . '_' . $i );
                }
            }
            add_settings_error( 'sb_fbpi', 'fetched', __( 'Posts refreshed.', 'sb-fb-profile-importer' ), 'success' );
        }

        if ( isset( $_POST['sb_fbpi_save_import_settings'] ) ) {
            check_admin_referer( 'sb_fbpi_save_import_settings', 'sb_fbpi_nonce' );
            $cron_enabled = ! empty( $_POST['sb_fbpi_cron_import_enabled'] );
            $count        = isset( $_POST['sb_fbpi_import_count'] ) ? max( 1, min( 100, (int) $_POST['sb_fbpi_import_count'] ) ) : 10;
            $category     = isset( $_POST['sb_fbpi_import_category'] ) ? max( 0, (int) $_POST['sb_fbpi_import_category'] ) : 0;
            $status       = isset( $_POST['sb_fbpi_import_post_status'] ) && in_array( $_POST['sb_fbpi_import_post_status'], [ 'draft', 'publish' ], true )
                ? sanitize_text_field( wp_unslash( $_POST['sb_fbpi_import_post_status'] ) )
                : 'draft';
            $notify_email = isset( $_POST['sb_fbpi_notify_email'] ) ? sanitize_email( wp_unslash( $_POST['sb_fbpi_notify_email'] ) ) : '';
            update_option( self::OPTION_CRON_IMPORT_ENABLED, $cron_enabled ? '1' : '0' );
            update_option( self::OPTION_IMPORT_COUNT, $count );
            update_option( self::OPTION_IMPORT_CATEGORY, $category );
            update_option( self::OPTION_IMPORT_POST_STATUS, $status );
            update_option( self::OPTION_NOTIFY_EMAIL, $notify_email );
            sb_fb_profile_importer_schedule_import_cron( $cron_enabled );
            add_settings_error( 'sb_fbpi', 'import_saved', __( 'Import settings saved.', 'sb-fb-profile-importer' ), 'success' );
        }

        if ( isset( $_POST['sb_fbpi_run_import_now'] ) ) {
            check_admin_referer( 'sb_fbpi_run_import_now', 'sb_fbpi_nonce' );
            $result = $this->run_import();
            update_option( self::OPTION_LAST_IMPORT_RESULT, $result );
            add_settings_error(
                'sb_fbpi',
                'import_done',
                sprintf(
                    /* translators: 1: imported count, 2: skipped count */
                    __( 'Import finished. Created: %1$d, Skipped (already exist): %2$d.', 'sb-fb-profile-importer' ),
                    $result['created'],
                    $result['skipped']
                ) . ( $result['error'] !== '' ? ' ' . $result['error'] : '' ),
                $result['error'] !== '' ? 'warning' : 'success'
            );
        }
    }

    /**
     * Run import: fetch FB feed, create WP posts for new items (dedupe by _sb_fbpi_fb_post_id).
     *
     * @return array{created: int, skipped: int, error: string}
     */
    public function run_import(): array {
        $out = [ 'created' => 0, 'skipped' => 0, 'error' => '' ];
        $page_id = get_option( self::OPTION_PAGE_ID, '' );
        $page_tok = get_option( self::OPTION_PAGE_TOKEN, '' );
        $limit   = max( 1, min( 100, (int) get_option( self::OPTION_IMPORT_COUNT, 10 ) ) );
        $status  = get_option( self::OPTION_IMPORT_POST_STATUS, 'draft' );

        if ( $page_id === '' || $page_tok === '' ) {
            $out['error'] = __( 'No page connected.', 'sb-fb-profile-importer' );
            return $out;
        }

        $posts = $this->client->get_page_feed_for_import( $page_id, $page_tok, [ 'limit' => $limit ] );
        if ( is_wp_error( $posts ) ) {
            $out['error'] = $posts->get_error_message();
            return $out;
        }

        foreach ( $posts as $fb_post ) {
            $fb_id = $fb_post['id'] ?? '';
            if ( $fb_id === '' ) {
                continue;
            }
            if ( $this->wp_post_exists_by_fb_id( $fb_id ) ) {
                $out['skipped']++;
                continue;
            }
            $wp_id = $this->create_wp_post_from_fb( $fb_post, $status );
            if ( $wp_id && ! is_wp_error( $wp_id ) ) {
                $out['created']++;
            }
        }

        return $out;
    }

    private function wp_post_exists_by_fb_id( string $fb_post_id ): bool {
        global $wpdb;
        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                self::META_FB_POST_ID,
                $fb_post_id
            )
        );
        return $id !== null && $id !== '';
    }

    /**
     * Split message into title (up to first punctuation) and rest for content.
     *
     * @return array{title: string, content_part: string}
     */
    private function split_title_and_content( string $message ): array {
        $title = '';
        $content_part = '';
        $message = trim( $message );
        if ( $message === '' ) {
            return [ 'title' => '', 'content_part' => '' ];
        }
        // Match up to first sentence-ending punctuation: . ! ?
        if ( preg_match( '/^(.+?)([.!?]\s*)(.*)/us', $message, $m ) ) {
            $title = trim( $m[1] );
            $content_part = trim( $m[3] ?? '' );
        } else {
            $title = $message;
        }
        return [ 'title' => $title, 'content_part' => $content_part ];
    }

    /**
     * Unique http(s) URLs found in plain text.
     *
     * @return string[]
     */
    private function extract_urls( string $text ): array {
        if ( ! preg_match_all( '#(?:https?://|www\.)[^\s<>"\']+#iu', $text, $matches ) ) {
            return [];
        }

        $urls = [];
        foreach ( $matches[0] as $raw ) {
            [ $url ] = $this->split_url_token( $raw );
            if ( $url !== '' && ! in_array( $url, $urls, true ) ) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /**
     * Separate a matched URL token from trailing punctuation.
     *
     * @return array{0: string, 1: string} Valid URL and leftover punctuation.
     */
    private function split_url_token( string $raw ): array {
        $url      = $raw;
        $trailing = '';
        while ( $url !== '' && str_contains( '.,;:!?)]}\'"', substr( $url, -1 ) ) ) {
            $trailing = substr( $url, -1 ) . $trailing;
            $url      = substr( $url, 0, -1 );
        }
        if ( stripos( $url, 'www.' ) === 0 ) {
            $url = 'https://' . $url;
        }
        if ( ! wp_http_validate_url( $url ) ) {
            return [ '', $raw ];
        }
        return [ $url, $trailing ];
    }

    /**
     * Whether a line is only a URL.
     */
    private function is_url_line( string $line ): bool {
        $line = trim( $line );
        if ( ! preg_match( '#^(?:https?://|www\.)\S+$#iu', $line ) ) {
            return false;
        }
        [ $url ] = $this->split_url_token( $line );
        return $url !== '';
    }

    /**
     * Remove URLs from a title and tidy the leftover separators.
     */
    private function strip_urls_from_title( string $text ): string {
        $text = preg_replace( '#(?:https?://|www\.)\S+#iu', '', $text );
        $text = preg_replace( '/[ \t]{2,}/', ' ', (string) $text );
        return trim( (string) $text, " \t\n\r\0\x0B-–—:|" );
    }

    /**
     * Short readable label for a URL.
     */
    private function link_label( string $url ): string {
        $parts = wp_parse_url( $url );
        $host  = isset( $parts['host'] ) ? (string) preg_replace( '/^www\./i', '', (string) $parts['host'] ) : '';
        $path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
        $label = $host . ( $path !== '/' ? $path : '' );
        if ( $label === '' ) {
            $label = $url;
        }
        if ( mb_strlen( $label ) > 70 ) {
            $label = mb_substr( $label, 0, 67 ) . '…';
        }
        return $label;
    }

    /**
     * Inline link for a URL that sits inside a sentence.
     */
    private function inline_link_html( string $url ): string {
        return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" style="color:#1877f2;text-decoration:underline;word-break:break-word;">' . esc_html( $this->link_label( $url ) ) . '</a>';
    }

    /**
     * Standalone link block.
     */
    private function link_card_html( string $url, string $label = '' ): string {
        if ( ! wp_http_validate_url( $url ) ) {
            return '';
        }
        $host = $this->link_label( $url );
        if ( $label === '' ) {
            $label = $host;
            $sub   = '';
        } else {
            $sub = '<span style="display:block;margin-top:0.2em;font-size:0.85em;font-weight:400;color:#646970;">' . esc_html( $host ) . '</span>';
        }
        return '<p style="margin:1.25em 0;"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;max-width:100%;padding:0.85em 1.1em;border:1px solid #d0d7de;border-left:4px solid #1877f2;border-radius:8px;background:#f6f7f7;color:#1d2327;text-decoration:none;font-weight:600;line-height:1.4;">' . esc_html( $label ) . $sub . '</a></p>';
    }

    /**
     * Escape text and turn URLs into links. A line that is only a URL becomes a link block.
     */
    private function format_message_html( string $text ): string {
        $text = trim( $text );
        if ( $text === '' ) {
            return '';
        }

        $html   = '';
        $buffer = [];
        $flush  = function () use ( &$buffer, &$html ): void {
            if ( $buffer === [] ) {
                return;
            }
            $html  .= wpautop( $this->linkify_inline( implode( "\n", $buffer ) ) );
            $buffer = [];
        };

        foreach ( preg_split( "/\r\n|\n|\r/", $text ) as $line ) {
            if ( $this->is_url_line( $line ) ) {
                $flush();
                [ $url ] = $this->split_url_token( trim( $line ) );
                $html   .= $this->link_card_html( $url );
                continue;
            }
            $buffer[] = $line;
        }
        $flush();
        return $html;
    }

    /**
     * Turn URLs inside a sentence into inline links. The rest of the text is escaped.
     */
    private function linkify_inline( string $text ): string {
        $parts = preg_split( '#(https?://[^\s<]+|www\.[^\s<]+)#iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( ! is_array( $parts ) ) {
            return esc_html( $text );
        }

        $out = '';
        foreach ( $parts as $i => $part ) {
            if ( $i % 2 === 0 ) {
                $out .= esc_html( $part );
                continue;
            }
            [ $url, $trailing ] = $this->split_url_token( $part );
            if ( $url === '' ) {
                $out .= esc_html( $part );
                continue;
            }
            $out .= $this->inline_link_html( $url ) . esc_html( $trailing );
        }
        return $out;
    }

    /**
     * Whether rendered HTML already links to this URL.
     */
    private function html_contains_url( string $html, string $url ): bool {
        return str_contains( $html, esc_url( $url ) );
    }

    /**
     * Collect image URLs and share URL from FB post attachments.
     *
     * @return array{images: string[], share_url: string}
     */
    private function get_attachments_images_and_share( array $fb_post ): array {
        $images   = [];
        $share_url = '';
        $attachments = $fb_post['attachments']['data'] ?? [];
        foreach ( $attachments as $att ) {
            
            
            $type = $att['type'] ?? '';
            if ( strtolower( $type ) === 'share' ) {
                if ( $share_url === '' && ! empty( $att['url'] ) ) {
                    $share_url = $att['url'];
                }
                
            }
            
            
            if ( isset( $att['media']['image']['src'] ) ) {
                $images[] = $att['media']['image']['src'];
            } elseif ( isset( $att['url'] ) && ( $type === 'photo' || $type === '' ) ) {
                $images[] = $att['url'];
            }
            if ( isset( $att['subattachments']['data'] ) ) {
                foreach ( $att['subattachments']['data'] as $sub ) {
                    if ( isset( $sub['media']['image']['src'] ) ) {
                        $images[] = $sub['media']['image']['src'];
                    } elseif ( ! empty( $sub['url'] ) ) {
                        $images[] = $sub['url'];
                    }
                }
            }
        }
        return [ 'images' => $images, 'share_url' => $share_url ];
    }

    /**
     * Sideload image from URL into Media Library and optionally set as featured image.
     *
     * @param string $image_url    Image URL.
     * @param int    $post_id     Post ID to attach to.
     * @param bool   $set_featured Whether to set as post thumbnail.
     * @return int|WP_Error Attachment ID or error.
     */
    private function sideload_image_to_post( string $image_url, int $post_id, bool $set_featured = true ): int|WP_Error {
        if ( empty( $image_url ) || empty( $post_id ) ) {
            return new WP_Error( 'sb_fbpi_invalid_args', __( 'Missing image_url or post_id.', 'sb-fb-profile-importer' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_sideload_image( $image_url, $post_id, null, 'id' );
        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        if ( $set_featured ) {
            set_post_thumbnail( $post_id, $attachment_id );
        }

        return $attachment_id;
    }

    private function create_wp_post_from_fb( array $fb_post, string $post_status ): int|WP_Error {
        $message = $fb_post['message'] ?? '';
        $split   = $this->split_title_and_content( $message );
        $title   = $this->strip_urls_from_title( $split['title'] );
        if ( $title === '' ) {
            $title = sprintf(
                /* translators: %s: date */
                __( 'Facebook post %s', 'sb-fb-profile-importer' ),
                ! empty( $fb_post['created_time'] ) ? wp_date( get_option( 'date_format' ), strtotime( $fb_post['created_time'] ) ) : wp_date( get_option( 'date_format' ) )
            );
        }
        $content = $this->format_message_html( $split['content_part'] );
        foreach ( $this->extract_urls( $message ) as $url ) {
            if ( ! $this->html_contains_url( $content, $url ) ) {
                $content .= $this->link_card_html( $url );
            }
        }

        $att_data = $this->get_attachments_images_and_share( $fb_post );
        if ( $att_data['share_url'] !== '' && ! $this->html_contains_url( $content, $att_data['share_url'] ) ) {
            $content .= $this->link_card_html( $att_data['share_url'], __( 'Прочети повече', 'sb-fb-profile-importer' ) );
        }

        $post_date = '';
        if ( ! empty( $fb_post['created_time'] ) ) {
            $post_date = wp_date( 'Y-m-d H:i:s', strtotime( $fb_post['created_time'] ) );
        }
        $post_arr = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $post_status,
            'post_type'    => 'post',
            'post_date'    => $post_date ?: current_time( 'mysql' ),
        ];
        $category_id = (int) get_option( self::OPTION_IMPORT_CATEGORY, 0 );
        if ( $category_id > 0 ) {
            $post_arr['post_category'] = [ $category_id ];
        }
        $wp_id = wp_insert_post( $post_arr, true );
        if ( is_wp_error( $wp_id ) ) {
            return $wp_id;
        }

        $image_ids = [];
        $is_first = true;
        
        foreach ( $att_data['images'] as $img_url ) {
            $aid = $this->sideload_image_to_post( $img_url, $wp_id, $is_first );
            if ( ! is_wp_error( $aid ) ) {
                $image_ids[] = $aid;
                $is_first = false;
            }
        }
        if ( count( $image_ids ) > 1 ) {
            $gallery = '[gallery ids="' . implode( ',', array_slice( $image_ids, 1 ) ) . '"]';
            $post = get_post( $wp_id );
            if ( $post ) {
                wp_update_post( [
                    'ID'           => $wp_id,
                    'post_content' => $post->post_content . "\n\n" . $gallery,
                ] );
            }
        }

        update_post_meta( $wp_id, self::META_FB_POST_ID, $fb_post['id'] );
        if ( ! empty( $fb_post['permalink_url'] ) ) {
            update_post_meta( $wp_id, '_sb_fbpi_fb_permalink', $fb_post['permalink_url'] );
        }
        if ( ! empty( $fb_post['created_time'] ) ) {
            update_post_meta( $wp_id, '_sb_fbpi_fb_created_time', $fb_post['created_time'] );
        }
        return $wp_id;
    }

    /**
     * Run health check and store status. Call on admin page load or cron.
     */
    public function run_health_check(): void {
        $page_id   = get_option( self::OPTION_PAGE_ID, '' );
        $page_tok  = get_option( self::OPTION_PAGE_TOKEN, '' );
        if ( $page_id === '' || $page_tok === '' ) {
            return;
        }

        $result = $this->client->health_check( $page_id, $page_tok );
        if ( $result === true ) {
            update_option( self::OPTION_TOKEN_VERIFIED, time() );
            update_option( self::OPTION_HEALTH_STATUS, 'ok' );
            delete_transient( self::TRANSIENT_TOKEN_NOTIFY );
        } else {
            $msg = is_wp_error( $result ) ? $result->get_error_message() : __( 'Token invalid or data access expired.', 'sb-fb-profile-importer' );
            update_option( self::OPTION_HEALTH_STATUS, $msg );
            $this->maybe_notify_token_invalid( $msg );
        }
    }

    /**
     * Send email when token is invalid (throttled to once per NOTIFY_THROTTLE_SECONDS).
     */
    private function maybe_notify_token_invalid( string $error_message ): void {
        $email = get_option( self::OPTION_NOTIFY_EMAIL, '' );
        $email = is_string( $email ) ? trim( $email ) : '';
        if ( $email === '' || ! is_email( $email ) ) {
            return;
        }
        if ( get_transient( self::TRANSIENT_TOKEN_NOTIFY ) ) {
            return;
        }
        $subject = sprintf(
            /* translators: %s: site name */
            __( '[%s] SB FB Profile Importer: Facebook token invalid', 'sb-fb-profile-importer' ),
            wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
        );
        $message = __( 'The Facebook Page access token is invalid or has expired.', 'sb-fb-profile-importer' ) . "\n\n"
            . __( 'Error:', 'sb-fb-profile-importer' ) . ' ' . $error_message . "\n\n"
            . __( 'Please go to the plugin settings and use "Reconnect Facebook" to authorize again.', 'sb-fb-profile-importer' ) . "\n"
            . admin_url( 'admin.php?page=sb-fb-profile-importer' );
        wp_mail( $email, $subject, $message );
        set_transient( self::TRANSIENT_TOKEN_NOTIFY, '1', self::NOTIFY_THROTTLE_SECONDS );
    }

    /**
     * Send email when scheduled import failed (throttled to once per NOTIFY_THROTTLE_SECONDS).
     *
     * @param array{created: int, skipped: int, error: string} $result
     */
    public function maybe_notify_import_failed( array $result ): void {
        if ( ( $result['error'] ?? '' ) === '' ) {
            return;
        }
        $email = get_option( self::OPTION_NOTIFY_EMAIL, '' );
        $email = is_string( $email ) ? trim( $email ) : '';
        if ( $email === '' || ! is_email( $email ) ) {
            return;
        }
        if ( get_transient( self::TRANSIENT_IMPORT_FAIL_NOTIFY ) ) {
            return;
        }
        $subject = sprintf(
            /* translators: %s: site name */
            __( '[%s] SB FB Profile Importer: scheduled import failed', 'sb-fb-profile-importer' ),
            wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
        );
        $message = __( 'The scheduled Facebook post import failed.', 'sb-fb-profile-importer' ) . "\n\n"
            . __( 'Error:', 'sb-fb-profile-importer' ) . ' ' . ( $result['error'] ?? '' ) . "\n\n"
            . __( 'Please check the plugin settings and reconnect Facebook if the token has expired.', 'sb-fb-profile-importer' ) . "\n"
            . admin_url( 'admin.php?page=sb-fb-profile-importer' );
        wp_mail( $email, $subject, $message );
        set_transient( self::TRANSIENT_IMPORT_FAIL_NOTIFY, '1', self::NOTIFY_THROTTLE_SECONDS );
    }

    /**
     * Get OAuth URL with state (nonce).
     */
    public function get_oauth_url(): string {
        $app_id = get_option( self::OPTION_APP_ID, '' );
        if ( $app_id === '' ) {
            return '';
        }
        $state = wp_generate_password( 32, false );
        update_option( self::OPTION_STATE, $state );
        return add_query_arg( [
            'client_id'     => $app_id,
            'redirect_uri' => $this->client->get_redirect_uri(),
            'scope'        => 'pages_show_list,pages_read_engagement',
            'response_type' => 'code',
            'state'        => $state,
        ], 'https://www.facebook.com/' . ( get_option( self::OPTION_GRAPH_VERSION, 'v19.0' ) ) . '/dialog/oauth' );
    }

    public function is_page_connected(): bool {
        return get_option( self::OPTION_PAGE_TOKEN, '' ) !== '';
    }

    public function is_user_authenticated(): bool {
        return get_option( self::OPTION_LONG_LIVED, '' ) !== '';
    }

    /**
     * Get feed posts (from transient if valid, else API).
     */
    public function get_feed_posts( int $limit = 5 ): array|WP_Error {
        $page_id   = get_option( self::OPTION_PAGE_ID, '' );
        $page_tok  = get_option( self::OPTION_PAGE_TOKEN, '' );
        if ( $page_id === '' || $page_tok === '' ) {
            return new WP_Error( 'sb_fbpi_not_connected', __( 'No page connected.', 'sb-fb-profile-importer' ) );
        }

        $limit = max( 1, min( 50, $limit ) );
        $transient_key = self::TRANSIENT_FEED_PREFIX . $page_id . '_' . $limit;
        $cached = get_transient( $transient_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $posts = $this->client->get_page_feed( $page_id, $page_tok, $limit );
        if ( is_wp_error( $posts ) ) {
            return $posts;
        }

        set_transient( $transient_key, $posts, self::TRANSIENT_FEED_EXPIRY );
        return $posts;
    }

    /**
     * Enqueue admin styles.
     */
    public function enqueue_styles( string $hook ): void {
        if ( 'toplevel_page_sb-fb-profile-importer' !== $hook ) {
            return;
        }
        wp_add_inline_style( 'wp-admin', '
            .sb-fbpi-wrap { max-width: 900px; }
            .sb-fbpi-card { background: #fff; border: 1px solid #c3c4c7; padding: 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .sb-fbpi-card h2 { margin-top: 0; }
            .sb-fbpi-btn-fb { background: #1877f2; color: #fff; border: none; padding: 12px 24px; font-size: 16px; cursor: pointer; border-radius: 6px; text-decoration: none; display: inline-block; }
            .sb-fbpi-btn-fb:hover { color: #fff; background: #166fe5; }
            .sb-fbpi-btn-disconnect { background: #dc3545; color: #fff; border: none; padding: 8px 16px; cursor: pointer; border-radius: 4px; text-decoration: none; }
            .sb-fbpi-posts-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            .sb-fbpi-posts-table th, .sb-fbpi-posts-table td { padding: 12px; text-align: left; border-bottom: 1px solid #e0e0e0; }
            .sb-fbpi-posts-table th { background: #f6f7f7; font-weight: 600; }
            .sb-fbpi-connected { color: #28a745; font-weight: 600; }
            .sb-fbpi-notice { padding: 12px 15px; margin: 15px 0; border-radius: 4px; }
            .sb-fbpi-notice-error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
            .sb-fbpi-notice-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
            .sb-fbpi-token-mask { font-family: monospace; color: #666; }
        ' );
    }

    /**
     * Render full admin page.
     */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'sb-fb-profile-importer' ) );
        }

        $app_id     = get_option( self::OPTION_APP_ID, '' );
        $app_secret = get_option( self::OPTION_APP_SECRET, '' );
        $graph_ver  = get_option( self::OPTION_GRAPH_VERSION, 'v19.0' );
        $page_name  = get_option( self::OPTION_PAGE_NAME, '' );
        $health     = get_option( self::OPTION_HEALTH_STATUS, 'ok' );

        settings_errors( 'sb_fbpi' );

        if ( isset( $_GET['connected'] ) ) {
            echo '<div class="sb-fbpi-notice sb-fbpi-notice-success">' . esc_html__( 'Successfully connected. Select a page below and click Save Page.', 'sb-fb-profile-importer' ) . '</div>';
        }
        if ( isset( $_GET['disconnected'] ) ) {
            echo '<div class="sb-fbpi-notice sb-fbpi-notice-success">' . esc_html__( 'Disconnected from Facebook.', 'sb-fb-profile-importer' ) . '</div>';
        }

        if ( $this->is_page_connected() && $health !== 'ok' ) {
            echo '<div class="sb-fbpi-notice sb-fbpi-notice-error">' . esc_html__( 'Token invalid or data access expired. Please reconnect.', 'sb-fb-profile-importer' ) . '</div>';
        }
        ?>
        <div class="wrap sb-fbpi-wrap">
            <h1><?php esc_html_e( 'SB FB Profile Importer', 'sb-fb-profile-importer' ); ?></h1>
            <p>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: Software Brewery website link. */
                        __( 'Developed by %s.', 'sb-fb-profile-importer' ),
                        '<a href="' . esc_url( SB_FB_PROFILE_IMPORTER_AUTHOR_URL ) . '" target="_blank" rel="noopener noreferrer">Software Brewery</a>'
                    ),
                    [
                        'a' => [
                            'href'   => true,
                            'target' => true,
                            'rel'    => true,
                        ],
                    ]
                );
                ?>
            </p>

            <div class="sb-fbpi-card">
                <h2><?php esc_html_e( '1. Facebook App', 'sb-fb-profile-importer' ); ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'sb_fbpi_save_credentials', 'sb_fbpi_nonce' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="sb_fbpi_app_id"><?php esc_html_e( 'App ID', 'sb-fb-profile-importer' ); ?></label></th>
                            <td><input type="text" id="sb_fbpi_app_id" name="sb_fbpi_app_id" value="<?php echo esc_attr( $app_id ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="sb_fbpi_app_secret"><?php esc_html_e( 'App Secret', 'sb-fb-profile-importer' ); ?></label></th>
                            <td><input type="password" id="sb_fbpi_app_secret" name="sb_fbpi_app_secret" value="<?php echo esc_attr( $app_secret ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="sb_fbpi_graph_version"><?php esc_html_e( 'Graph API version', 'sb-fb-profile-importer' ); ?></label></th>
                            <td><input type="text" id="sb_fbpi_graph_version" name="sb_fbpi_graph_version" value="<?php echo esc_attr( $graph_ver ); ?>" class="small-text" placeholder="v19.0">
                            <p class="description"><?php esc_html_e( 'e.g. v19.0', 'sb-fb-profile-importer' ); ?></p></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Redirect URI', 'sb-fb-profile-importer' ); ?></th>
                            <td><code><?php echo esc_html( $this->client->get_redirect_uri() ); ?></code>
                            <p class="description"><?php esc_html_e( 'Add this to your app\'s Valid OAuth Redirect URIs.', 'sb-fb-profile-importer' ); ?></p></td>
                        </tr>
                    </table>
                    <p><button type="submit" name="sb_fbpi_save_credentials" class="button button-primary"><?php esc_html_e( 'Save', 'sb-fb-profile-importer' ); ?></button></p>
                </form>
            </div>

            <?php if ( $app_id && $app_secret ) : ?>
            <div class="sb-fbpi-card">
                <h2><?php esc_html_e( '2. Connect / Reconnect Facebook', 'sb-fb-profile-importer' ); ?></h2>
                <?php if ( $this->is_page_connected() ) : ?>
                    <p class="sb-fbpi-connected">✓ <?php echo esc_html( sprintf( __( 'Page: %s', 'sb-fb-profile-importer' ), $page_name ) ); ?></p>
                    <?php
                    $long = get_option( self::OPTION_LONG_LIVED, '' );
                    $page_tok = get_option( self::OPTION_PAGE_TOKEN, '' );
                    if ( $long !== '' || $page_tok !== '' ) {
                        echo '<p class="sb-fbpi-token-mask">' . esc_html__( 'User token:', 'sb-fb-profile-importer' ) . ' ' . esc_html( self::mask_token( $long ) ) . ' &nbsp; ' . esc_html__( 'Page token:', 'sb-fb-profile-importer' ) . ' ' . esc_html( self::mask_token( $page_tok ) ) . '</p>';
                    }
                    ?>
                    <p>
                        <a href="<?php echo esc_url( $this->get_oauth_url() ); ?>" class="sb-fbpi-btn-fb"><?php esc_html_e( 'Reconnect Facebook', 'sb-fb-profile-importer' ); ?></a>
                        &nbsp;
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=sb-fb-profile-importer&sb_fbpi_disconnect=1' ), 'sb_fbpi_disconnect' ) ); ?>" class="sb-fbpi-btn-disconnect" onclick="return confirm('<?php esc_attr_e( 'Disconnect?', 'sb-fb-profile-importer' ); ?>');"><?php esc_html_e( 'Disconnect', 'sb-fb-profile-importer' ); ?></a>
                    </p>
                <?php elseif ( $this->is_user_authenticated() ) : ?>
                    <?php $this->render_page_selection(); ?>
                <?php else : ?>
                    <p><a href="<?php echo esc_url( $this->get_oauth_url() ); ?>" class="sb-fbpi-btn-fb"><?php esc_html_e( 'Connect with Facebook', 'sb-fb-profile-importer' ); ?></a></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ( $this->is_page_connected() ) : ?>
            <div class="sb-fbpi-card">
                <h2><?php esc_html_e( '3. Import to WordPress', 'sb-fb-profile-importer' ); ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'sb_fbpi_save_import_settings', 'sb_fbpi_nonce' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="sb_fbpi_cron_import_enabled"><?php esc_html_e( 'Scheduled import (cron)', 'sb-fb-profile-importer' ); ?></label></th>
                            <td>
                                <label><input type="checkbox" name="sb_fbpi_cron_import_enabled" id="sb_fbpi_cron_import_enabled" value="1" <?php checked( get_option( self::OPTION_CRON_IMPORT_ENABLED, '0' ), '1' ); ?>>
                                <?php esc_html_e( 'Enable hourly import', 'sb-fb-profile-importer' ); ?></label>
                                <p class="description"><?php esc_html_e( 'When enabled, new Facebook posts are imported every hour.', 'sb-fb-profile-importer' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="sb_fbpi_import_count"><?php esc_html_e( 'Number of posts to import', 'sb-fb-profile-importer' ); ?></label></th>
                            <td>
                                <input type="number" name="sb_fbpi_import_count" id="sb_fbpi_import_count" value="<?php echo esc_attr( (string) get_option( self::OPTION_IMPORT_COUNT, 10 ) ); ?>" min="1" max="100" class="small-text">
                                <p class="description"><?php esc_html_e( 'Used for both manual import and scheduled (cron) import. 1–100.', 'sb-fb-profile-importer' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="sb_fbpi_import_category"><?php esc_html_e( 'Category for imported posts', 'sb-fb-profile-importer' ); ?></label></th>
                            <td>
                                <?php
                                wp_dropdown_categories( [
                                    'name'             => 'sb_fbpi_import_category',
                                    'id'               => 'sb_fbpi_import_category',
                                    'selected'         => (int) get_option( self::OPTION_IMPORT_CATEGORY, 0 ),
                                    'show_option_none' => __( '— None —', 'sb-fb-profile-importer' ),
                                    'option_none_value'=> '0',
                                    'hide_empty'       => 0,
                                    'orderby'          => 'name',
                                ] );
                                ?>
                                <p class="description"><?php esc_html_e( 'Imported posts will be assigned to this category.', 'sb-fb-profile-importer' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="sb_fbpi_import_post_status"><?php esc_html_e( 'New post status', 'sb-fb-profile-importer' ); ?></label></th>
                            <td>
                                <select name="sb_fbpi_import_post_status" id="sb_fbpi_import_post_status">
                                    <option value="draft" <?php selected( get_option( self::OPTION_IMPORT_POST_STATUS, 'draft' ), 'draft' ); ?>><?php esc_html_e( 'Draft', 'sb-fb-profile-importer' ); ?></option>
                                    <option value="publish" <?php selected( get_option( self::OPTION_IMPORT_POST_STATUS, 'draft' ), 'publish' ); ?>><?php esc_html_e( 'Published', 'sb-fb-profile-importer' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="sb_fbpi_notify_email"><?php esc_html_e( 'Email for notifications', 'sb-fb-profile-importer' ); ?></label></th>
                            <td>
                                <input type="email" name="sb_fbpi_notify_email" id="sb_fbpi_notify_email" value="<?php echo esc_attr( get_option( self::OPTION_NOTIFY_EMAIL, 'dimitrov.martin1@gmail.com' ) ); ?>" class="regular-text" placeholder="email@example.com">
                                <p class="description"><?php esc_html_e( 'Optional. You will receive an email when the Facebook token becomes invalid or when the scheduled import fails (at most once per 24 hours).', 'sb-fb-profile-importer' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p><button type="submit" name="sb_fbpi_save_import_settings" class="button button-primary"><?php esc_html_e( 'Save import settings', 'sb-fb-profile-importer' ); ?></button></p>
                </form>
                <p>
                    <strong><?php esc_html_e( 'Manual import', 'sb-fb-profile-importer' ); ?>:</strong>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field( 'sb_fbpi_run_import_now', 'sb_fbpi_nonce' ); ?>
                        <button type="submit" name="sb_fbpi_run_import_now" class="button"><?php esc_html_e( 'Run import now', 'sb-fb-profile-importer' ); ?></button>
                    </form>
                </p>
                <?php
                $last = get_option( self::OPTION_LAST_IMPORT_RESULT, null );
                if ( is_array( $last ) ) {
                    echo '<p class="description">' . esc_html( sprintf(
                        /* translators: 1: created, 2: skipped */
                        __( 'Last run: %1$d created, %2$d skipped.', 'sb-fb-profile-importer' ),
                        (int) ( $last['created'] ?? 0 ),
                        (int) ( $last['skipped'] ?? 0 )
                    ) );
                    if ( ! empty( $last['error'] ) ) {
                        echo ' ' . esc_html( $last['error'] );
                    }
                    echo '</p>';
                }
                ?>
            </div>
            <div class="sb-fbpi-card">
                <h2><?php esc_html_e( '4. Page posts (preview)', 'sb-fb-profile-importer' ); ?></h2>
                <form method="post" style="margin-bottom:15px;">
                    <?php wp_nonce_field( 'sb_fbpi_fetch_posts', 'sb_fbpi_nonce' ); ?>
                    <label for="sb_fbpi_num_posts"><?php esc_html_e( 'Number of posts', 'sb-fb-profile-importer' ); ?></label>
                    <input type="number" id="sb_fbpi_num_posts" name="sb_fbpi_num_posts" value="<?php echo esc_attr( (string) get_option( self::OPTION_NUM_POSTS, 5 ) ); ?>" min="1" max="50" class="small-text" style="width:60px;">
                    <button type="submit" name="sb_fbpi_fetch_posts" class="button"><?php esc_html_e( 'Fetch posts', 'sb-fb-profile-importer' ); ?></button>
                </form>
                <?php
                $num_posts = (int) get_option( self::OPTION_NUM_POSTS, 5 );
                $num_posts = max( 1, min( 50, $num_posts ) );
                $this->render_posts_table( $num_posts );
                ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_page_selection(): void {
        $user_token = get_option( self::OPTION_LONG_LIVED, '' );
        $pages = $this->client->get_me_accounts( $user_token );

        if ( is_wp_error( $pages ) ) {
            echo '<div class="sb-fbpi-notice sb-fbpi-notice-error">' . esc_html( $this->format_wp_error( $pages ) ) . '</div>';
            return;
        }
        if ( empty( $pages ) ) {
            echo '<div class="sb-fbpi-notice sb-fbpi-notice-error">' . esc_html__( 'No pages found. You need admin access to at least one Facebook Page.', 'sb-fb-profile-importer' ) . '</div>';
            return;
        }
        ?>
        <p><?php esc_html_e( 'Select a page and click Save Page.', 'sb-fb-profile-importer' ); ?></p>
        <form method="post">
            <?php wp_nonce_field( 'sb_fbpi_save_page', 'sb_fbpi_nonce' ); ?>
            <p>
                <select name="sb_fbpi_page_id" id="sb_fbpi_page_select" required>
                    <option value=""><?php esc_html_e( '— Select Page —', 'sb-fb-profile-importer' ); ?></option>
                    <?php foreach ( $pages as $p ) : ?>
                        <option value="<?php echo esc_attr( $p['id'] ); ?>" data-token="<?php echo esc_attr( $p['access_token'] ); ?>" data-name="<?php echo esc_attr( $p['name'] ); ?>"><?php echo esc_html( $p['name'] ); ?> (<?php echo esc_html( $p['id'] ); ?>)</option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="sb_fbpi_page_token" id="sb_fbpi_page_token" value="">
                <input type="hidden" name="sb_fbpi_page_name" id="sb_fbpi_page_name" value="">
            </p>
            <p><button type="submit" name="sb_fbpi_save_page" class="button button-primary"><?php esc_html_e( 'Save Page', 'sb-fb-profile-importer' ); ?></button></p>
        </form>
        <script>
        document.getElementById('sb_fbpi_page_select').addEventListener('change', function() {
            var opt = this.options[this.selectedIndex];
            if (opt.value) {
                document.getElementById('sb_fbpi_page_token').value = opt.dataset.token || '';
                document.getElementById('sb_fbpi_page_name').value = opt.dataset.name || '';
            }
        });
        </script>
        <?php
    }

    private function render_posts_table( int $limit ): void {
        $posts = $this->get_feed_posts( $limit );

        if ( is_wp_error( $posts ) ) {
            echo '<div class="sb-fbpi-notice sb-fbpi-notice-error">' . esc_html( $posts->get_error_message() ) . '</div>';
            return;
        }
        if ( empty( $posts ) ) {
            echo '<p>' . esc_html__( 'No posts found.', 'sb-fb-profile-importer' ) . '</p>';
            return;
        }
        ?>
        <table class="sb-fbpi-posts-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date', 'sb-fb-profile-importer' ); ?></th>
                    <th><?php esc_html_e( 'Message', 'sb-fb-profile-importer' ); ?></th>
                    <th><?php esc_html_e( 'Image', 'sb-fb-profile-importer' ); ?></th>
                    <th><?php esc_html_e( 'Link', 'sb-fb-profile-importer' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $posts as $post ) : ?>
                    <tr>
                        <td><?php echo esc_html( ! empty( $post['created_time'] ) ? wp_date( 'M j, Y H:i', strtotime( $post['created_time'] ) ) : '—' ); ?></td>
                        <td><?php
                            $msg = $post['message'] ?? '';
                            echo esc_html( $msg !== '' ? ( mb_strlen( $msg ) > 120 ? mb_substr( $msg, 0, 120 ) . '…' : $msg ) : __( '(no text)', 'sb-fb-profile-importer' ) );
                        ?></td>
                        <td><?php
                            $thumb = SB_FB_Profile_Importer_Meta_Client::get_post_thumbnail_url( $post );
                            if ( $thumb !== '' ) {
                                echo '<img src="' . esc_url( $thumb ) . '" alt="" style="max-width:80px;max-height:60px;">';
                            } else {
                                echo '<span style="color:#999;">—</span>';
                            }
                        ?></td>
                        <td><?php
                            if ( ! empty( $post['permalink_url'] ) ) {
                                echo '<a href="' . esc_url( $post['permalink_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'sb-fb-profile-importer' ) . ' →</a>';
                            } else {
                                echo '—';
                            }
                        ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}