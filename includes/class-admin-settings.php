<?php
/**
 * Admin Settings Panel & AJAX Handlers
 */

if (!defined('ABSPATH')) {
    exit;
}

class FB2WP_Admin_Settings {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_fb2wp_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_fb2wp_manual_sync', array($this, 'ajax_manual_sync'));
        add_action('wp_ajax_fb2wp_batch_sync', array($this, 'ajax_batch_sync'));
        add_action('wp_ajax_fb2wp_extend_token', array($this, 'ajax_extend_token'));
    }

    public function add_settings_page() {
        add_options_page(
            __('Auto Sync for FB', 'auto-sync-for-fb'),
            __('Auto Sync for FB', 'auto-sync-for-fb'),
            'manage_options',
            'auto-sync-for-fb',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('fb2wp_settings_group', 'fb2wp_page_id', 'sanitize_text_field');
        register_setting('fb2wp_settings_group', 'fb2wp_access_token', 'sanitize_text_field');
        register_setting('fb2wp_settings_group', 'fb2wp_app_id', 'sanitize_text_field');
        register_setting('fb2wp_settings_group', 'fb2wp_app_secret', 'sanitize_text_field');
        register_setting('fb2wp_settings_group', 'fb2wp_sync_interval', array($this, 'sanitize_interval'));
        register_setting('fb2wp_settings_group', 'fb2wp_post_status', 'sanitize_text_field');
        register_setting('fb2wp_settings_group', 'fb2wp_post_category', 'absint');
        register_setting('fb2wp_settings_group', 'fb2wp_post_author', 'absint');
        register_setting('fb2wp_settings_group', 'fb2wp_import_extra_images', 'absint');
        register_setting('fb2wp_settings_group', 'fb2wp_sync_since_date', array($this, 'sanitize_sync_date'));
        register_setting('fb2wp_settings_group', 'fb2wp_token_expiry_info', 'sanitize_text_field');
    }

    public function sanitize_sync_date($date) {
        $date = sanitize_text_field($date);
        if (!empty($date)) {
            $timestamp = strtotime($date);
            if ($timestamp) {
                return gmdate('Y-m-d', $timestamp);
            }
        }
        return '';
    }

    public function sanitize_interval($new_interval) {
        $valid_intervals = array('hourly', 'every_two_hours', 'twicedaily', 'daily');
        if (!in_array($new_interval, $valid_intervals, true)) {
            $new_interval = 'hourly';
        }

        // Reschedule WP-Cron event if interval changed
        $timestamp = wp_next_scheduled('fb2wp_cron_sync_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'fb2wp_cron_sync_event');
        }
        wp_schedule_event(time(), $new_interval, 'fb2wp_cron_sync_event');

        return $new_interval;
    }

    public function ajax_test_connection() {
        check_ajax_referer('fb2wp_admin_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized user.', 'auto-sync-for-fb')));
        }

        $page_id      = isset($_POST['page_id']) ? sanitize_text_field(wp_unslash($_POST['page_id'])) : '';
        $access_token = isset($_POST['access_token']) ? sanitize_text_field(wp_unslash($_POST['access_token'])) : '';

        $fb_api = new FB2WP_FB_API($page_id, $access_token);
        $result = $fb_api->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        $page_name   = isset($result['name']) ? $result['name'] : __('Facebook Page', 'auto-sync-for-fb');
        $expiry_info = isset($result['token_info']['expiry_text']) ? $result['token_info']['expiry_text'] : '';

        if (!empty($expiry_info)) {
            update_option('fb2wp_token_expiry_info', $expiry_info);
            /* translators: 1: Facebook Page Name, 2: Facebook Page ID, 3: Token Expiry Information */
            $msg = sprintf(__('Connected successfully to "%1$s"! (Page ID: %2$s) — Token Expiry: %3$s', 'auto-sync-for-fb'), $page_name, $result['id'], $expiry_info);
        } else {
            /* translators: 1: Facebook Page Name, 2: Facebook Page ID */
            $msg = sprintf(__('Connected successfully to "%1$s"! (Page ID: %2$s)', 'auto-sync-for-fb'), $page_name, $result['id']);
        }

        wp_send_json_success(array('message' => $msg, 'expiry_text' => $expiry_info));
    }

    public function ajax_manual_sync() {
        check_ajax_referer('fb2wp_admin_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized user.', 'auto-sync-for-fb')));
        }

        $engine = new FB2WP_Sync_Engine();
        $res    = $engine->run_sync(15);

        if (!$res['success']) {
            wp_send_json_error(array('message' => $res['message']));
        }

        /* translators: 1: Number of posts imported, 2: Number of posts skipped */
        $msg = sprintf(__('Sync Complete! %1$d imported, %2$d skipped (already imported).', 'auto-sync-for-fb'), $res['synced'], $res['skipped']);
        if (!empty($res['errors'])) {
            $msg .= ' Errors: ' . implode(' | ', $res['errors']);
        }

        wp_send_json_success(array('message' => $msg));
    }

    public function ajax_batch_sync() {
        check_ajax_referer('fb2wp_admin_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized user.', 'auto-sync-for-fb')));
        }

        $after_cursor = isset($_POST['after_cursor']) ? sanitize_text_field(wp_unslash($_POST['after_cursor'])) : '';
        $batch_limit  = isset($_POST['batch_limit']) ? min(max(1, intval(wp_unslash($_POST['batch_limit']))), 25) : 10;

        $engine = new FB2WP_Sync_Engine();
        $res    = $engine->run_batch_sync($batch_limit, $after_cursor);

        if (!$res['success']) {
            wp_send_json_error(array('message' => $res['message']));
        }

        wp_send_json_success(array(
            'synced'       => $res['synced'],
            'skipped'      => $res['skipped'],
            'errors'       => $res['errors'],
            'after_cursor' => $res['after_cursor'],
            'has_next'     => $res['has_next'],
            'batch_count'  => $res['batch_count'],
        ));
    }

    public function ajax_extend_token() {
        check_ajax_referer('fb2wp_admin_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized user.', 'auto-sync-for-fb')));
        }

        $app_id       = isset($_POST['app_id']) ? sanitize_text_field(wp_unslash($_POST['app_id'])) : '';
        $app_secret   = isset($_POST['app_secret']) ? sanitize_text_field(wp_unslash($_POST['app_secret'])) : '';
        $short_token  = isset($_POST['access_token']) ? sanitize_text_field(wp_unslash($_POST['access_token'])) : '';
        $page_id      = isset($_POST['page_id']) ? sanitize_text_field(wp_unslash($_POST['page_id'])) : '';

        $fb_api = new FB2WP_FB_API($page_id, $short_token);
        $result = $fb_api->exchange_for_long_lived_token($app_id, $app_secret, $short_token, $page_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        if (!empty($result['token'])) {
            update_option('fb2wp_access_token', $result['token']);
            if (!empty($app_id)) {
                update_option('fb2wp_app_id', $app_id);
            }
            if (!empty($app_secret)) {
                update_option('fb2wp_app_secret', $app_secret);
            }

            $fb_api_new  = new FB2WP_FB_API($page_id, $result['token']);
            $inspect     = $fb_api_new->inspect_token($result['token']);
            $expiry_info = isset($inspect['expiry_text']) ? $inspect['expiry_text'] : '';

            if (!empty($expiry_info)) {
                update_option('fb2wp_token_expiry_info', $expiry_info);
            }

            if (!empty($result['is_page'])) {
                $page_name = !empty($result['page_name']) ? $result['page_name'] : $page_id;
                if (!empty($expiry_info)) {
                    /* translators: 1: Facebook Page Name, 2: Token Expiry Information */
                    $msg = sprintf(__('Success! Exchanged short-lived token for permanent Page Access Token for "%1$s" (Token Expiry: %2$s). Settings updated!', 'auto-sync-for-fb'), $page_name, $expiry_info);
                } else {
                    /* translators: %s: Facebook Page Name */
                    $msg = sprintf(__('Success! Exchanged short-lived token for permanent Page Access Token for "%s". Settings updated!', 'auto-sync-for-fb'), $page_name);
                }
            } else {
                $msg = __('Success! Exchanged for a 60-day Long-Lived User Access Token. Settings updated!', 'auto-sync-for-fb');
            }

            wp_send_json_success(array('message' => $msg, 'new_token' => $result['token'], 'expiry_text' => $expiry_info));
        }

        wp_send_json_error(array('message' => __('Failed to exchange access token.', 'auto-sync-for-fb')));
    }

    public function render_settings_page() {
        $page_id         = get_option('fb2wp_page_id', '');
        $access_token    = get_option('fb2wp_access_token', '');
        $app_id          = get_option('fb2wp_app_id', '');
        $app_secret      = get_option('fb2wp_app_secret', '');
        $sync_interval   = get_option('fb2wp_sync_interval', 'hourly');
        $post_status     = get_option('fb2wp_post_status', 'publish');
        $post_category   = get_option('fb2wp_post_category', get_option('default_category', 1));
        $post_author     = get_option('fb2wp_post_author', get_current_user_id());
        $import_extra    = get_option('fb2wp_import_extra_images', 1);
        $sync_since_date = get_option('fb2wp_sync_since_date', '');
        $last_sync       = get_option('fb2wp_last_sync_time', __('Never', 'auto-sync-for-fb'));
        $token_expiry    = get_option('fb2wp_token_expiry_info', __('Unknown (Run connection test)', 'auto-sync-for-fb'));

        $categories = get_categories(array('hide_empty' => false));
        $users      = get_users(array('capability' => 'edit_posts'));
        $nonce      = wp_create_nonce('fb2wp_admin_nonce');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Auto Sync for FB', 'auto-sync-for-fb'); ?></h1>
            <p><?php esc_html_e('Automatically import Facebook Page posts into WordPress with image sideloading and WP-Cron automation.', 'auto-sync-for-fb'); ?></p>

            <div style="display: flex; gap: 20px; margin-top: 20px; flex-wrap: wrap;">
                <div style="flex: 2; min-width: 320px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('fb2wp_settings_group');
                        do_settings_sections('fb2wp_settings_group');
                        ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="fb2wp_page_id"><?php esc_html_e('Facebook Page ID', 'auto-sync-for-fb'); ?></label></th>
                                <td>
                                    <input type="text" id="fb2wp_page_id" name="fb2wp_page_id" value="<?php echo esc_attr($page_id); ?>" class="regular-text" placeholder="e.g. 1029384756" required />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fb2wp_access_token"><?php esc_html_e('Page Access Token', 'auto-sync-for-fb'); ?></label></th>
                                <td>
                                    <input type="password" id="fb2wp_access_token" name="fb2wp_access_token" value="<?php echo esc_attr($access_token); ?>" class="large-text" placeholder="EAA..." required />
                                    <p class="description"><?php esc_html_e('Your Facebook Graph API Page Access Token.', 'auto-sync-for-fb'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fb2wp_app_id"><?php esc_html_e('App ID (Optional)', 'auto-sync-for-fb'); ?></label></th>
                                <td>
                                    <input type="text" id="fb2wp_app_id" name="fb2wp_app_id" value="<?php echo esc_attr($app_id); ?>" class="regular-text" placeholder="e.g. 1234567890" />
                                    <p class="description"><?php esc_html_e('Required only for exchanging short-lived tokens for permanent Page Access Tokens.', 'auto-sync-for-fb'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fb2wp_app_secret"><?php esc_html_e('App Secret (Optional)', 'auto-sync-for-fb'); ?></label></th>
                                <td>
                                    <input type="password" id="fb2wp_app_secret" name="fb2wp_app_secret" value="<?php echo esc_attr($app_secret); ?>" class="regular-text" placeholder="e.g. a1b2c3d4..." />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fb2wp_sync_interval"><?php esc_html_e('Sync Frequency (WP-Cron)', 'auto-sync-for-fb'); ?></label></th>
                                <td>
                                    <select id="fb2wp_sync_interval" name="fb2wp_sync_interval">
                                        <option value="hourly" <?php selected($sync_interval, 'hourly'); ?>><?php esc_html_e('Every Hour', 'auto-sync-for-fb'); ?></option>
                                        <option value="every_two_hours" <?php selected($sync_interval, 'every_two_hours'); ?>><?php esc_html_e('Every 2 Hours', 'auto-sync-for-fb'); ?></option>
                                        <option value="twicedaily" <?php selected($sync_interval, 'twicedaily'); ?>><?php esc_html_e('Twice Daily (12 hours)', 'auto-sync-for-fb'); ?></option>
                                        <option value="daily" <?php selected($sync_interval, 'daily'); ?>><?php esc_html_e('Once Daily', 'auto-sync-for-fb'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fb2wp_post_status"><?php esc_html_e('Post Status', 'auto-sync-for-fb'); ?></label></th>
                                <td>
                                    <select id="fb2wp_post_status" name="fb2wp_post_status">
                                        <option value="publish" <?php selected($post_status, 'publish'); ?>><?php esc_html_e('Publish Immediately', 'auto-sync-for-fb'); ?></option>
                                        <option value="draft" <?php selected($post_status, 'draft'); ?>><?php esc_html_e('Save as Draft', 'auto-sync-for-fb'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fb2wp_post_category"><?php esc_html_e('Post Category', 'auto-sync-for-fb'); ?></label></th>
                                <td>
                                    <select id="fb2wp_post_category" name="fb2wp_post_category">
                                        <?php foreach ($categories as $cat) : ?>
                                            <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($post_category, $cat->term_id); ?>>
                                                <?php echo esc_html($cat->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fb2wp_post_author"><?php esc_html_e('Post Author', 'auto-sync-for-fb'); ?></label></th>
                                <td>
                                    <select id="fb2wp_post_author" name="fb2wp_post_author">
                                        <?php foreach ($users as $user) : ?>
                                            <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($post_author, $user->ID); ?>>
                                                <?php echo esc_html($user->display_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Multi-Photo Posts', 'auto-sync-for-fb'); ?></th>
                                <td>
                                    <label for="fb2wp_import_extra_images">
                                        <input type="checkbox" id="fb2wp_import_extra_images" name="fb2wp_import_extra_images" value="1" <?php checked($import_extra, 1); ?> />
                                        <?php esc_html_e('Import additional photos (2nd, 3rd, etc.) into the post body.', 'auto-sync-for-fb'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fb2wp_sync_since_date"><?php esc_html_e('Sync Posts Since Date', 'auto-sync-for-fb'); ?></label></th>
                                <td>
                                    <input type="date" id="fb2wp_sync_since_date" name="fb2wp_sync_since_date" value="<?php echo esc_attr($sync_since_date); ?>" class="regular-text" />
                                    <p class="description"><?php esc_html_e('Only import Facebook posts created on or after this date. Leave blank to sync ALL posts.', 'auto-sync-for-fb'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(__('Save Settings', 'auto-sync-for-fb')); ?>
                    </form>
                </div>

                <div style="flex: 1; min-width: 280px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); height: fit-content;">
                    <h2><?php esc_html_e('Actions & Status', 'auto-sync-for-fb'); ?></h2>
                    <p><strong><?php esc_html_e('Last Sync:', 'auto-sync-for-fb'); ?></strong> <?php echo esc_html($last_sync); ?></p>
                    <p><strong><?php esc_html_e('Token Status:', 'auto-sync-for-fb'); ?></strong> <span id="fb2wp-token-expiry"><?php echo esc_html($token_expiry); ?></span></p>

                    <hr style="margin: 15px 0; border: 0; border-top: 1px solid #eee;" />

                    <button type="button" id="fb2wp-test-btn" class="button button-secondary" style="width: 100%; margin-bottom: 10px; text-align: center;">
                        <?php esc_html_e('Test FB Connection', 'auto-sync-for-fb'); ?>
                    </button>

                    <button type="button" id="fb2wp-extend-btn" class="button button-secondary" style="width: 100%; margin-bottom: 10px; text-align: center;">
                        <?php esc_html_e('Exchange for Never-Expiring Token', 'auto-sync-for-fb'); ?>
                    </button>

                    <button type="button" id="fb2wp-sync-btn" class="button button-secondary" style="width: 100%; margin-bottom: 10px; text-align: center;">
                        <?php esc_html_e('Sync Latest Posts (Quick)', 'auto-sync-for-fb'); ?>
                    </button>

                    <button type="button" id="fb2wp-batch-sync-btn" class="button button-primary" style="width: 100%; text-align: center; margin-bottom: 10px;">
                        <?php esc_html_e('Import All Historical Posts', 'auto-sync-for-fb'); ?>
                    </button>

                    <button type="button" id="fb2wp-batch-stop-btn" class="button button-link-delete" style="width: 100%; text-align: center; display: none; margin-bottom: 10px; color: #d63638;">
                        <?php esc_html_e('Pause / Stop Migration', 'auto-sync-for-fb'); ?>
                    </button>

                    <div id="fb2wp-progress-card" style="display: none; margin-top: 15px; padding: 12px; background: #f0f6fc; border: 1px solid #cce5ff; border-radius: 4px;">
                        <div style="display: flex; justify-content: space-between; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: #004085;">
                            <span id="fb2wp-progress-status"><?php esc_html_e('Migrating posts...', 'auto-sync-for-fb'); ?></span>
                            <span id="fb2wp-progress-count">0 imported</span>
                        </div>
                        <div style="background: #e2e8f0; border-radius: 999px; height: 12px; overflow: hidden; position: relative;">
                            <div id="fb2wp-progress-bar" style="background: linear-gradient(90deg, #2271b1, #135e96); height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                        </div>
                        <div id="fb2wp-progress-details" style="font-size: 11px; color: #555; margin-top: 8px; line-height: 1.4;"></div>
                    </div>

                    <div id="fb2wp-status-box" style="margin-top: 15px; padding: 10px; display: none; border-radius: 4px;"></div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo esc_js($nonce); ?>';

            $('#fb2wp-test-btn').on('click', function() {
                var btn = $(this);
                var statusBox = $('#fb2wp-status-box');
                btn.prop('disabled', true).text('Testing Connection...');
                statusBox.hide();

                $.post(ajaxurl, {
                    action: 'fb2wp_test_connection',
                    security: nonce,
                    page_id: $('#fb2wp_page_id').val(),
                    access_token: $('#fb2wp_access_token').val()
                }, function(res) {
                    btn.prop('disabled', false).text('Test FB Connection');
                    statusBox.show();
                    if (res.success) {
                        if (res.data.expiry_text) {
                            $('#fb2wp-token-expiry').text(res.data.expiry_text);
                        }
                        statusBox.css({'background': '#e7f4e4', 'color': '#2e6b27', 'border': '1px solid #c2e1ba'}).html(res.data.message);
                    } else {
                        statusBox.css({'background': '#fce8e6', 'color': '#a80000', 'border': '1px solid #f5c2c0'}).html(res.data.message);
                    }
                });
            });

            $('#fb2wp-extend-btn').on('click', function() {
                var btn = $(this);
                var statusBox = $('#fb2wp-status-box');
                btn.prop('disabled', true).text('Exchanging Token...');
                statusBox.hide();

                $.post(ajaxurl, {
                    action: 'fb2wp_extend_token',
                    security: nonce,
                    app_id: $('#fb2wp_app_id').val(),
                    app_secret: $('#fb2wp_app_secret').val(),
                    access_token: $('#fb2wp_access_token').val(),
                    page_id: $('#fb2wp_page_id').val()
                }, function(res) {
                    btn.prop('disabled', false).text('Exchange for Never-Expiring Token');
                    statusBox.show();
                    if (res.success) {
                        if (res.data.new_token) {
                            $('#fb2wp_access_token').val(res.data.new_token);
                        }
                        if (res.data.expiry_text) {
                            $('#fb2wp-token-expiry').text(res.data.expiry_text);
                        }
                        statusBox.css({'background': '#e7f4e4', 'color': '#2e6b27', 'border': '1px solid #c2e1ba'}).html(res.data.message);
                    } else {
                        statusBox.css({'background': '#fce8e6', 'color': '#a80000', 'border': '1px solid #f5c2c0'}).html(res.data.message);
                    }
                });
            });

            $('#fb2wp-sync-btn').on('click', function() {
                var btn = $(this);
                var statusBox = $('#fb2wp-status-box');
                btn.prop('disabled', true).text('Syncing Facebook Posts...');
                statusBox.hide();

                $.post(ajaxurl, {
                    action: 'fb2wp_manual_sync',
                    security: nonce
                }, function(res) {
                    btn.prop('disabled', false).text('Sync Latest Posts (Quick)');
                    statusBox.show();
                    if (res.success) {
                        statusBox.css({'background': '#e7f4e4', 'color': '#2e6b27', 'border': '1px solid #c2e1ba'}).html(res.data.message);
                    } else {
                        statusBox.css({'background': '#fce8e6', 'color': '#a80000', 'border': '1px solid #f5c2c0'}).html(res.data.message);
                    }
                });
            });

            // Batch Historical Importer
            var isMigrating   = false;
            var stopRequested = false;
            var totalSynced   = 0;
            var totalSkipped  = 0;
            var totalCount    = 0;
            var batchNumber   = 0;

            $('#fb2wp-batch-sync-btn').on('click', function() {
                if (isMigrating) return;

                if (!confirm('<?php echo esc_js(__('Start importing historical posts in batches? This process will step backwards through your Facebook posts until all are migrated.', 'auto-sync-for-fb')); ?>')) {
                    return;
                }

                isMigrating   = true;
                stopRequested = false;
                totalSynced   = 0;
                totalSkipped  = 0;
                totalCount    = 0;
                batchNumber   = 0;

                $('#fb2wp-test-btn, #fb2wp-extend-btn, #fb2wp-sync-btn').prop('disabled', true);
                $('#fb2wp-batch-sync-btn').prop('disabled', true).text('<?php echo esc_js(__('Migrating...', 'auto-sync-for-fb')); ?>');
                $('#fb2wp-batch-stop-btn').show().prop('disabled', false).text('<?php echo esc_js(__('Pause / Stop Migration', 'auto-sync-for-fb')); ?>');
                $('#fb2wp-progress-card').show();
                $('#fb2wp-status-box').hide();
                $('#fb2wp-progress-bar').css('width', '5%');
                $('#fb2wp-progress-status').text('<?php echo esc_js(__('Starting migration...', 'auto-sync-for-fb')); ?>');
                $('#fb2wp-progress-count').text('0 imported, 0 skipped');
                $('#fb2wp-progress-details').text('<?php echo esc_js(__('Connecting to Facebook...', 'auto-sync-for-fb')); ?>');

                runNextBatch('');
            });

            $('#fb2wp-batch-stop-btn').on('click', function() {
                stopRequested = true;
                $(this).prop('disabled', true).text('<?php echo esc_js(__('Stopping...', 'auto-sync-for-fb')); ?>');
            });

            function runNextBatch(cursor) {
                if (stopRequested) {
                    finishMigration(false, '<?php echo esc_js(__('Migration stopped by user.', 'auto-sync-for-fb')); ?>');
                    return;
                }

                batchNumber++;
                $('#fb2wp-progress-status').text('Processing Batch #' + batchNumber + '...');
                $('#fb2wp-progress-details').html('Fetching batch from Facebook...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'fb2wp_batch_sync',
                        security: nonce,
                        after_cursor: cursor,
                        batch_limit: 10
                    },
                    timeout: 90000,
                    success: function(res) {
                        if (!res.success) {
                            finishMigration(false, res.data && res.data.message ? res.data.message : 'Error occurred during batch sync.');
                            return;
                        }

                        var data = res.data;
                        totalSynced  += data.synced;
                        totalSkipped += data.skipped;
                        totalCount   += data.batch_count;

                        $('#fb2wp-progress-count').text(totalSynced + ' imported, ' + totalSkipped + ' skipped');

                        var errorInfo = (data.errors && data.errors.length) ? ' (' + data.errors.length + ' image error(s))' : '';
                        $('#fb2wp-progress-details').html(
                            'Batch #' + batchNumber + ': ' + data.synced + ' imported, ' + data.skipped + ' skipped.' + errorInfo +
                            '<br><strong>Total Processed:</strong> ' + totalCount + ' posts across ' + batchNumber + ' batch(es).'
                        );

                        var progressPct = Math.min(95, 10 + (batchNumber * 5));
                        $('#fb2wp-progress-bar').css('width', progressPct + '%');

                        if (data.has_next && data.after_cursor && data.after_cursor !== cursor && !stopRequested) {
                            // 350ms delay between batches to respect Facebook rate limits
                            setTimeout(function() {
                                runNextBatch(data.after_cursor);
                            }, 350);
                        } else {
                            $('#fb2wp-progress-bar').css('width', '100%');
                            var msg = '<?php echo esc_js(__('Historical migration completed! Total: ', 'auto-sync-for-fb')); ?>' +
                                      totalSynced + ' <?php echo esc_js(__('imported, ', 'auto-sync-for-fb')); ?>' +
                                      totalSkipped + ' <?php echo esc_js(__('skipped.', 'auto-sync-for-fb')); ?>';
                            finishMigration(true, msg);
                        }
                    },
                    error: function(xhr, status, error) {
                        finishMigration(false, 'Request failed: ' + (error || status));
                    }
                });
            }

            function finishMigration(success, message) {
                isMigrating   = false;
                stopRequested = false;

                $('#fb2wp-test-btn, #fb2wp-extend-btn, #fb2wp-sync-btn').prop('disabled', false);
                $('#fb2wp-batch-sync-btn').prop('disabled', false).text('<?php echo esc_js(__('Import All Historical Posts', 'auto-sync-for-fb')); ?>');
                $('#fb2wp-batch-stop-btn').hide().prop('disabled', false).text('<?php echo esc_js(__('Pause / Stop Migration', 'auto-sync-for-fb')); ?>');

                var statusBox = $('#fb2wp-status-box');
                statusBox.show();
                if (success) {
                    $('#fb2wp-progress-status').text('<?php echo esc_js(__('Migration Completed!', 'auto-sync-for-fb')); ?>');
                    statusBox.css({'background': '#e7f4e4', 'color': '#2e6b27', 'border': '1px solid #c2e1ba'}).html(message);
                } else {
                    $('#fb2wp-progress-status').text('<?php echo esc_js(__('Migration Paused / Incomplete', 'auto-sync-for-fb')); ?>');
                    statusBox.css({'background': '#fce8e6', 'color': '#a80000', 'border': '1px solid #f5c2c0'}).html(message);
                }
            }
        });
        </script>
        <?php
    }
}
