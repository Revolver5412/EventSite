<?php
/**
 * Plugin Name: Event Registration
 * Description: Custom event registration plugin with Bricks integration
 * Version: 1.0.0
 * Author: Your Name
 */

namespace EventRegistration;

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', function() {
    if (!function_exists('get_field')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><?php 
                    echo 'Плъгинът Event Registration изисква Advanced Custom Fields (ACF). ';
                    echo 'ACF не е зареден или не е инсталиран правилно.';
                ?></p>
            </div>
            <?php
        });
        return;
    }
});

require_once plugin_dir_path(__FILE__) . 'includes/class-event-registration.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-event-shortcode.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ajax-handler.php';

class EventRegistrationPlugin {
    public function __construct() {
        new EventRegistration();
        new EventShortcode();
        new AjaxHandler();
    }
}

new EventRegistrationPlugin();
