<?php
/**
 * Facebook Graph API Client
 */

if (!defined('ABSPATH')) {
    exit;
}

class FB2WP_FB_API {

    private $page_id;
    private $access_token;
    private $api_version = 'v19.0';

    public function __construct($page_id = '', $access_token = '') {
        $this->page_id      = $page_id ?: get_option('fb2wp_page_id', '');
        $this->access_token = $access_token ?: get_option('fb2wp_access_token', '');
    }

    /**
     * Test connection to Facebook Graph API
     */
    public function test_connection() {
        if (empty($this->page_id) || empty($this->access_token)) {
            return new WP_Error('missing_credentials', __('Please enter Facebook Page ID and Access Token.', 'auto-sync-for-fb'));
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s?fields=id,name,picture&access_token=%s',
            $this->api_version,
            urlencode($this->page_id),
            urlencode($this->access_token)
        );

        $response = wp_remote_get($url, array('timeout' => 15));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('fb_api_error', $body['error']['message']);
        }

        $token_info = $this->inspect_token();
        if ($token_info) {
            $body['token_info'] = $token_info;
        }

        return $body;
    }

    /**
     * Inspect token expiration status via debug_token endpoint
     */
    public function inspect_token($token = '') {
        $token = $token ?: $this->access_token;
        if (empty($token)) {
            return false;
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/debug_token?input_token=%s&access_token=%s',
            $this->api_version,
            urlencode($token),
            urlencode($token)
        );

        $response = wp_remote_get($url, array('timeout' => 10));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['data']['is_valid']) && $body['data']['is_valid']) {
            $expires_at = isset($body['data']['expires_at']) ? intval($body['data']['expires_at']) : 0;
            $type       = isset($body['data']['type']) ? $body['data']['type'] : '';

            if ($expires_at === 0) {
                return array(
                    'is_valid'    => true,
                    'expires_at'  => 0,
                    'expiry_text' => __('Never (Permanent Page Token)', 'auto-sync-for-fb'),
                    'type'        => $type
                );
            } else {
                $date_str = gmdate(get_option('date_format') . ' ' . get_option('time_format'), $expires_at);
                return array(
                    'is_valid'    => true,
                    'expires_at'  => $expires_at,
                    /* translators: %s: Formatted token expiration date */
                    'expiry_text' => sprintf(__('Expires on %s', 'auto-sync-for-fb'), $date_str),
                    'type'        => $type
                );
            }
        }

        return false;
    }

    /**
     * Fetch a paginated page of posts from Facebook Page
     *
     * @param int    $limit        Number of posts to fetch per page.
     * @param string $after_cursor Cursor for cursor-based pagination.
     * @param string $since_date   Optional since date string.
     * @return array|WP_Error Array with 'data', 'after', 'has_next' on success, or WP_Error on failure.
     */
    public function fetch_posts_page($limit = 10, $after_cursor = '', $since_date = '') {
        if (empty($this->page_id) || empty($this->access_token)) {
            return new WP_Error('missing_credentials', __('Missing Facebook Page ID or Access Token.', 'auto-sync-for-fb'));
        }

        $since_date  = $since_date ?: get_option('fb2wp_sync_since_date', '');
        $since_param = '';
        if (!empty($since_date)) {
            $since_ts = strtotime($since_date);
            if ($since_ts > 0) {
                $since_param = '&since=' . intval($since_ts);
            }
        }

        $after_param = '';
        if (!empty($after_cursor)) {
            $after_param = '&after=' . urlencode($after_cursor);
        }

        // Fields: message, created_time, full_picture, attachments with media and subattachments
        $fields = 'id,message,created_time,full_picture,attachments{media,subattachments,type,title}';
        
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/posts?fields=%s&limit=%d%s%s&access_token=%s',
            $this->api_version,
            urlencode($this->page_id),
            urlencode($fields),
            intval($limit),
            $since_param,
            $after_param,
            urlencode($this->access_token)
        );

        $response = wp_remote_get($url, array('timeout' => 25));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('fb_api_error', $body['error']['message']);
        }

        $posts        = isset($body['data']) && is_array($body['data']) ? $body['data'] : array();
        $next_cursor  = isset($body['paging']['cursors']['after']) ? (string) $body['paging']['cursors']['after'] : '';
        $has_next_url = !empty($body['paging']['next']);

        $has_next = !empty($posts) && !empty($next_cursor) && $has_next_url;

        return array(
            'data'     => $posts,
            'after'    => $next_cursor,
            'has_next' => $has_next,
        );
    }

    /**
     * Fetch latest posts from Facebook Page
     *
     * @param int    $limit      Number of posts to fetch.
     * @param string $since_date Optional since date string.
     * @return array|WP_Error Array of posts or WP_Error.
     */
    public function fetch_latest_posts($limit = 10, $since_date = '') {
        $result = $this->fetch_posts_page($limit, '', $since_date);
        if (is_wp_error($result)) {
            return $result;
        }
        return isset($result['data']) ? $result['data'] : array();
    }

    /**
     * Exchange short-lived token for long-lived/permanent Page Access Token
     */
    public function exchange_for_long_lived_token($app_id, $app_secret, $short_token, $page_id = '') {
        if (empty($app_id) || empty($app_secret) || empty($short_token)) {
            return new WP_Error('missing_data', __('App ID, App Secret, and Short-Lived Access Token are required.', 'auto-sync-for-fb'));
        }

        // Step 1: Exchange short-lived token for 60-day Long-Lived User Access Token
        $url = sprintf(
            'https://graph.facebook.com/%s/oauth/access_token?grant_type=fb_exchange_token&client_id=%s&client_secret=%s&fb_exchange_token=%s',
            $this->api_version,
            urlencode($app_id),
            urlencode($app_secret),
            urlencode($short_token)
        );

        $response = wp_remote_get($url, array('timeout' => 15));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error']['message'])) {
            return new WP_Error('fb_api_error', $body['error']['message']);
        }

        $long_user_token = isset($body['access_token']) ? $body['access_token'] : '';

        if (empty($long_user_token)) {
            return new WP_Error('token_exchange_failed', __('Failed to retrieve long-lived access token from Facebook.', 'auto-sync-for-fb'));
        }

        // Step 2: Request permanent Page Access Token using long-lived User Token
        $target_page = !empty($page_id) ? $page_id : $this->page_id;
        $endpoint    = !empty($target_page) ? urlencode($target_page) : 'me/accounts';

        $page_url = sprintf(
            'https://graph.facebook.com/%s/%s?fields=access_token,name&access_token=%s',
            $this->api_version,
            $endpoint,
            urlencode($long_user_token)
        );

        $page_response = wp_remote_get($page_url, array('timeout' => 15));

        if (!is_wp_error($page_response)) {
            $page_body = json_decode(wp_remote_retrieve_body($page_response), true);

            // Direct Page Access Token response
            if (!empty($page_body['access_token'])) {
                return array(
                    'token'     => $page_body['access_token'],
                    'page_name' => isset($page_body['name']) ? $page_body['name'] : '',
                    'is_page'   => true
                );
            }

            // Accounts list response (me/accounts)
            if (isset($page_body['data']) && is_array($page_body['data'])) {
                foreach ($page_body['data'] as $page_item) {
                    if (empty($target_page) || (isset($page_item['id']) && $page_item['id'] === $target_page)) {
                        if (!empty($page_item['access_token'])) {
                            return array(
                                'token'     => $page_item['access_token'],
                                'page_name' => isset($page_item['name']) ? $page_item['name'] : '',
                                'page_id'   => isset($page_item['id']) ? $page_item['id'] : '',
                                'is_page'   => true
                            );
                        }
                    }
                }
            }
        }

        // Fallback: Return 60-day User Token if Page Token fetch was unsuccessful
        return array(
            'token'   => $long_user_token,
            'is_page' => false
        );
    }
}
