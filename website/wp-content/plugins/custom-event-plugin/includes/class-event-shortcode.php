<?php
namespace EventRegistration;

class EventShortcode {
    /**
     * Generate a popup form for event registration.
     * 
     * @param array $atts Shortcode attributes.
     * @return string HTML of the form.
     */
    public function event_registration_popup($atts) {
        // Assume the form is displayed on an event page where the post ID is the event's ID
        $atts = shortcode_atts([
            'event_id' => get_the_ID(), // Default to current post ID
        ], $atts);

        ob_start();
        ?>
        <div class="event-registration-popup">
            <form id="event-registration-form" data-event-id="<?php echo esc_attr($atts['event_id']); ?>">
                <input type="text" name="first_name" placeholder="Име" required>
                <input type="text" name="last_name" placeholder="Фамилия" required>
                <input type="email" name="email" placeholder="Email" required>
                <input type="tel" name="phone" placeholder="Телефон" required>
                <input type="hidden" name="action" value="event_registration">
                <input type="hidden" name="security" value="<?php echo wp_create_nonce('event_registration_nonce'); ?>">
                <input type="hidden" name="event_id" value="<?php echo esc_attr($atts['event_id']); ?>">
                <button type="submit">Запис</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Enqueue scripts required for event registration.
     */
    public function enqueue_registration_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_script('event-registration', plugin_dir_url(__FILE__) . '../assets/js/event-registration.js', ['jquery'], '1.0', true);
        wp_localize_script('event-registration', 'eventRegistrationAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
        ]);
    }

    public function __construct() {
        add_shortcode('event_registration', [$this, 'event_registration_popup']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_registration_scripts']);
    }
}

new EventShortcode();
