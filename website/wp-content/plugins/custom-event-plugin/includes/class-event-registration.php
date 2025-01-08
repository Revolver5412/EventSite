<?php
namespace EventRegistration;

class EventRegistration {
    /**
     * Register Custom Post Type "Events"
     */
    public function register_event_post_type() {
        register_post_type('event', [
            'labels' => [
                'name' => 'Events',
                'singular_name' => 'Event',
            ],
            'public' => true,
            'has_archive' => true,
            'supports' => ['title', 'editor', 'thumbnail'],
        ]);
    }

    public function __construct() {
        add_action('init', [$this, 'register_event_post_type']);
    }
}
