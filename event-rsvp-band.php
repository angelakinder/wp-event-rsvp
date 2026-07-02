<?php
/**
 * Plugin Name: Simple RSVP
 * Description: Simple RSVP events plugin with Simple Membership support.
 * Version:     1.0.0
 * Author:      Band Admin
 * License:     GPLv2 or later
 * Text Domain: band-event-rsvp
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BAND_EVENT_RSVP_VERSION', '1.0.0' );
define( 'BAND_EVENT_RSVP_DIR', plugin_dir_path( __FILE__ ) );
define( 'BAND_EVENT_RSVP_URL', plugin_dir_url( __FILE__ ) );

require_once BAND_EVENT_RSVP_DIR . 'includes/class-event-rsvp-cpt.php';
require_once BAND_EVENT_RSVP_DIR . 'includes/class-event-rsvp-admin.php';
require_once BAND_EVENT_RSVP_DIR . 'includes/class-event-rsvp-frontend.php';
require_once BAND_EVENT_RSVP_DIR . 'includes/class-event-rsvp-reminder.php';

function band_event_rsvp_init_plugin() {
    Band_Event_RSVP_CPT::init();
    Band_Event_RSVP_Admin::init();
    Band_Event_RSVP_Frontend::init();
}
add_action( 'plugins_loaded', 'band_event_rsvp_init_plugin' );

function band_event_rsvp_template_loader( $template ) {
    if ( is_post_type_archive( 'event' ) ) {
        $theme_template = locate_template( array( 'band-event-rsvp/archive-event.php', 'archive-event.php' ) );
        if ( ! empty( $theme_template ) ) {
            return $theme_template;
        }

        $plugin_template = BAND_EVENT_RSVP_DIR . 'templates/archive-event.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }
    }

    return $template;
}
add_filter( 'template_include', 'band_event_rsvp_template_loader' );

function band_event_rsvp_activate() {
    Band_Event_RSVP_CPT::register_event_post_type();
    flush_rewrite_rules();
    if ( ! wp_next_scheduled( 'band_event_rsvp_send_reminders' ) ) {
        wp_schedule_event( time(), 'hourly', 'band_event_rsvp_send_reminders' );
    }
}
register_activation_hook( __FILE__, 'band_event_rsvp_activate' );

function band_event_rsvp_deactivate() {
    flush_rewrite_rules();
    wp_clear_scheduled_hook( 'band_event_rsvp_send_reminders' );
}
register_deactivation_hook( __FILE__, 'band_event_rsvp_deactivate' );
