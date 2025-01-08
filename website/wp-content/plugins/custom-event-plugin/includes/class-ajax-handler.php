<?php
namespace EventRegistration;

class AjaxHandler {
    public function __construct() {
        add_action('wp_ajax_event_registration', [$this, 'handle_event_registration']);
        add_action('wp_ajax_nopriv_event_registration', [$this, 'handle_event_registration']);
    }

    public function handle_event_registration() {
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'event_registration_nonce')) {
            wp_send_json_error('Invalid nonce');
        }

        $event_id = absint($_POST['event_id']);
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name = sanitize_text_field($_POST['last_name']);
        $email = sanitize_email($_POST['email']);
        $phone = sanitize_text_field($_POST['phone']);

        if (!$event_id || !$first_name || !$last_name || !$email || !$phone) {
            wp_send_json_error('All fields are required');
        }

        
        $participants = get_field('participants', $event_id) ?: [];

        
        $participants[] = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
        ];

        
        update_field('participants', $participants, $event_id);

        wp_send_json_success('Registration successful');
    }
}
