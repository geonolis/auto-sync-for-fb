<?php
/*
Plugin Name: Auto Sync for FB
Plugin URI: https://wordpress.org/plugins/auto-sync-for-fb/
Description: Automatically clone Facebook Page posts into WordPress blog posts with image sideloading into the WP Media Library and WP-Cron scheduling.
Version: 1.1.0
Author: Geonolis
Author URI: https://wordpress.org/
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: auto-sync-for-fb
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('FB2WP_PATH', plugin_dir_path(__FILE__));
define('FB2WP_URL', plugin_dir_url(__FILE__));
define('FB2WP_VERSION', '1.1.0');

// Load required classes
require_once FB2WP_PATH . 'includes/class-fb-api.php';
require_once FB2WP_PATH . 'includes/class-sync-engine.php';
require_once FB2WP_PATH . 'includes/class-admin-settings.php';

/**
 * Plugin Main Initialization Class
 */
class FB2WP_Auto_Sync_Plugin {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Init Admin Panel
        if (is_admin()) {
            new FB2WP_Admin_Settings();
        }

        // Hook WP-Cron
        add_action('fb2wp_cron_sync_event', array($this, 'run_cron_sync'));

        // Register custom cron schedules
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
    }

    public function add_cron_schedules($schedules) {
        $schedules['every_two_hours'] = array(
            'interval' => 2 * HOUR_IN_SECONDS,
            'display'  => __('Every 2 Hours', 'auto-sync-for-fb')
        );
        return $schedules;
    }

    public function run_cron_sync() {
        $engine = new FB2WP_Sync_Engine();
        $engine->run_sync();
    }
}

// Activation & Deactivation Hooks
register_activation_hook(__FILE__, 'fb2wp_activate');
register_deactivation_hook(__FILE__, 'fb2wp_deactivate');

function fb2wp_activate() {
    if (!wp_next_scheduled('fb2wp_cron_sync_event')) {
        $interval = get_option('fb2wp_sync_interval', 'hourly');
        wp_schedule_event(time(), $interval, 'fb2wp_cron_sync_event');
    }
}

function fb2wp_deactivate() {
    wp_clear_scheduled_hook('fb2wp_cron_sync_event');
}

// Initialize Plugin
add_action('plugins_loaded', array('FB2WP_Auto_Sync_Plugin', 'get_instance'));
