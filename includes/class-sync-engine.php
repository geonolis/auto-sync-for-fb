<?php
/**
 * Core Sync Engine for Auto Sync for FB
 */

if (!defined('ABSPATH')) {
    exit;
}

class FB2WP_Sync_Engine
{

    private $fb_api;

    public function __construct()
    {
        $this->fb_api = new FB2WP_FB_API();
    }

    /**
     * Run full sync workflow for latest posts
     *
     * @param int $limit Number of posts to fetch.
     * @return array Sync result.
     */
    public function run_sync($limit = 10)
    {
        return $this->run_batch_sync($limit, '');
    }

    /**
     * Run batch sync for a specific page / cursor
     *
     * @param int    $limit        Number of posts to fetch per batch.
     * @param string $after_cursor Cursor for older posts.
     * @return array Sync result including cursor and has_next.
     */
    public function run_batch_sync($limit = 10, $after_cursor = '')
    {
        $page_result = $this->fb_api->fetch_posts_page($limit, $after_cursor);

        if (is_wp_error($page_result)) {
            return array(
                'success' => false,
                'message' => $page_result->get_error_message()
            );
        }

        $fb_posts     = isset($page_result['data']) ? $page_result['data'] : array();
        $next_cursor  = isset($page_result['after']) ? $page_result['after'] : '';
        $has_next     = !empty($page_result['has_next']) && !empty($next_cursor);

        $synced_count  = 0;
        $skipped_count = 0;
        $errors        = array();

        $since_date     = get_option('fb2wp_sync_since_date', '');
        $since_ts       = !empty($since_date) ? strtotime($since_date) : 0;
        $reached_cutoff = false;

        foreach ($fb_posts as $fb_post) {
            $fb_id = isset($fb_post['id']) ? $fb_post['id'] : '';

            if (empty($fb_id)) {
                continue;
            }

            // Skip posts older than configured since_date
            if ($since_ts > 0 && isset($fb_post['created_time'])) {
                $post_ts = strtotime($fb_post['created_time']);
                if ($post_ts > 0 && $post_ts < $since_ts) {
                    $skipped_count++;
                    $reached_cutoff = true;
                    continue;
                }
            }

            // Check if already imported
            if ($this->post_exists($fb_id)) {
                $skipped_count++;
                continue;
            }

            $result = $this->import_single_post($fb_post);

            if (is_wp_error($result)) {
                /* translators: 1: Facebook Post ID, 2: Error message */
                $errors[] = sprintf(__('Post ID %1$s: %2$s', 'auto-sync-for-fb'), $fb_id, $result->get_error_message());
            } else {
                $synced_count++;
            }
        }

        if ($reached_cutoff || empty($fb_posts)) {
            $has_next = false;
        }

        update_option('fb2wp_last_sync_time', current_time('mysql'));

        return array(
            'success'      => true,
            'synced'       => $synced_count,
            'skipped'      => $skipped_count,
            'errors'       => $errors,
            'after_cursor' => $next_cursor,
            'has_next'     => $has_next,
            'batch_count'  => count($fb_posts),
        );
    }

    /**
     * Check if FB post has already been imported
     */
    private function post_exists($fb_id)
    {
        $cache_key = 'fb2wp_post_' . md5($fb_id);
        $cached_id = wp_cache_get($cache_key, 'fb2wp');

        if (false !== $cached_id) {
            return !empty($cached_id);
        }

        $synced_ids = get_option('fb2wp_synced_ids', array());
        if (is_array($synced_ids) && isset($synced_ids[$fb_id])) {
            wp_cache_set($cache_key, 1, 'fb2wp');
            return true;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom postmeta lookup with wp_cache_set caching.
        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                '_fb_post_id',
                $fb_id
            )
        );

        wp_cache_set($cache_key, $existing_id ? (int) $existing_id : 0, 'fb2wp');

        if (!empty($existing_id)) {
            $this->add_synced_id_to_cache($fb_id);
            return true;
        }

        return false;
    }

    /**
     * Add synced FB post ID to options lookup cache
     */
    private function add_synced_id_to_cache($fb_id)
    {
        $synced_ids = get_option('fb2wp_synced_ids', array());
        if (!is_array($synced_ids)) {
            $synced_ids = array();
        }
        $synced_ids[$fb_id] = 1;
        update_option('fb2wp_synced_ids', $synced_ids, false);

        $cache_key = 'fb2wp_post_' . md5($fb_id);
        wp_cache_set($cache_key, 1, 'fb2wp');
    }

    /**
     * Import single Facebook post to WordPress
     */
    private function import_single_post($fb_post)
    {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $fb_id = $fb_post['id'];
        $message = isset($fb_post['message']) ? $fb_post['message'] : '';
        $created_time = isset($fb_post['created_time']) ? $fb_post['created_time'] : current_time('mysql');

        // Generate Post Title
        $title = $this->generate_post_title($message, $created_time);

        // Format Post Content
        $content = wpautop(make_clickable(esc_html($message)));

        // Get Options
        $post_status = get_option('fb2wp_post_status', 'publish');
        $post_category = get_option('fb2wp_post_category', get_option('default_category', 1));
        $post_author = get_option('fb2wp_post_author', get_current_user_id() ?: 1);

        // Convert FB timestamp to MySQL format
        $post_date = gmdate('Y-m-d H:i:s', strtotime($created_time));

        // Insert WordPress Post
        $post_data = array(
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => $post_status,
            'post_author' => $post_author,
            'post_date' => $post_date,
            'post_category' => array($post_category)
        );

        $wp_post_id = wp_insert_post($post_data, true);

        if (is_wp_error($wp_post_id)) {
            return $wp_post_id;
        }

        // Store FB Post ID meta and cache lookup ID
        add_post_meta($wp_post_id, '_fb_post_id', $fb_id, true);
        $this->add_synced_id_to_cache($fb_id);

        // Download Images: set 1st as Featured Image, append 2nd, 3rd, etc. to Post Content
        $image_urls = $this->extract_all_image_urls($fb_post);

        if (!empty($image_urls)) {
            $extra_images_html = '';
            $import_extra_setting = get_option('fb2wp_import_extra_images', 1);

            foreach ($image_urls as $index => $image_url) {
                $img_title = sprintf('%s - Image %d', $title, $index + 1);
                $attachment_id = media_sideload_image($image_url, $wp_post_id, $img_title, 'id');

                if (is_wp_error($attachment_id)) {
                    continue;
                }

                if ($index === 0) {
                    // Set 1st photo as Featured Image
                    set_post_thumbnail($wp_post_id, $attachment_id);
                } elseif ($import_extra_setting) {
                    // Append 2nd, 3rd, etc. photos to post content
                    $img_html = wp_get_attachment_image($attachment_id, 'full', false, array('class' => 'aligncenter fb-extra-image'));
                    if ($img_html) {
                        $extra_images_html .= '<p class="fb-post-image-wrap">' . $img_html . '</p>' . "\n";
                    }
                }
            }

            if (!empty($extra_images_html)) {
                $updated_content = $content . "\n\n" . $extra_images_html;
                wp_update_post(array(
                    'ID' => $wp_post_id,
                    'post_content' => $updated_content,
                ));
            }
        }

        return $wp_post_id;
    }

    /**
     * Extract all image URLs from FB post structure (main picture + subattachments)
     */
    private function extract_all_image_urls($fb_post)
    {
        $urls = array();

        if (!empty($fb_post['attachments']['data'])) {
            foreach ($fb_post['attachments']['data'] as $att) {
                // If subattachments exist (multi-photo post), collect subattachment images
                if (!empty($att['subattachments']['data'])) {
                    foreach ($att['subattachments']['data'] as $sub) {
                        if (!empty($sub['media']['image']['src'])) {
                            $urls[] = $sub['media']['image']['src'];
                        }
                    }
                } elseif (!empty($att['media']['image']['src'])) {
                    $urls[] = $att['media']['image']['src'];
                }
            }
        }

        // If no attachment images found, check full_picture
        if (empty($urls) && !empty($fb_post['full_picture'])) {
            $urls[] = $fb_post['full_picture'];
        }

        return array_values(array_unique($urls));
    }

    /**
     * Generate Post Title from post text or creation date
     */
    private function generate_post_title($message, $created_time)
    {
        if (!empty($message)) {
            $clean = trim(preg_replace('/\s+/', ' ', $message));
            if (mb_strlen($clean) > 75) {
                return mb_substr($clean, 0, 72) . '...';
            }
            return $clean;
        }

        $formatted_date = wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($created_time));
        /* translators: %s: Formatted creation date of Facebook post */
        return sprintf(__('Facebook Post - %s', 'auto-sync-for-fb'), $formatted_date);
    }
}
