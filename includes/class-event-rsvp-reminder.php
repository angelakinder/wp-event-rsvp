<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Band_Event_RSVP_Reminder {
    const CRON_HOOK = 'band_event_rsvp_send_reminders';
    const OPTION_HOURS = 'band_event_rsvp_reminder_hours';
    const OPTION_ENABLED = 'band_event_rsvp_reminder_enabled';
    const OPTION_TARGET = 'band_event_rsvp_reminder_target';
    const OPTION_TEMPLATE = 'band_event_rsvp_reminder_template';

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'send_reminders' ) );
    }

    public static function get_reminder_hours() {
        $hours = intval( get_option( self::OPTION_HOURS, 24 ) );
        return $hours > 0 ? $hours : 24;
    }

    public static function get_reminder_target() {
        $target = get_option( self::OPTION_TARGET, 'all_invited' );
        $allowed = array( 'all_invited', 'tentative_or_unanswered', 'all_responded' );
        return in_array( $target, $allowed, true ) ? $target : 'all_invited';
    }

    public static function get_default_reminder_template() {
        return "Hi {display_name},\n\n"
            . "This is a reminder that \"{event_title}\" starts on {event_start} at {event_location}.\n\n"
            . "View event: {event_url}\n\n"
            . "Quick RSVP:\n{rsvp_buttons}\n\n"
            . "If your plans have changed, please update your RSVP.\n\n"
            . "{site_name}\n{site_url}";
    }

    public static function get_reminder_template() {
        $template = (string) get_option( self::OPTION_TEMPLATE, '' );
        if ( '' === trim( $template ) ) {
            return self::get_default_reminder_template();
        }
        return $template;
    }

    protected static function format_event_start_for_email( $post_id ) {
        $start = get_post_meta( $post_id, '_band_event_start', true );
        $start = (string) $start;
        if ( '' === $start ) {
            return __( 'TBD', 'band-event-rsvp' );
        }

        $timestamp = strtotime( $start );
        if ( false === $timestamp ) {
            return $start;
        }

        return wp_date( 'M j, Y g:i a', $timestamp, wp_timezone() );
    }

    protected static function get_email_message_for_user( $post_id, $user ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return '';
        }

        $template = self::get_reminder_template();
        $location = (string) get_post_meta( $post_id, '_band_event_location', true );
        if ( '' === trim( $location ) ) {
            $location = __( 'TBD', 'band-event-rsvp' );
        }

        $rsvp_yes_url = self::get_quick_rsvp_url( $post_id, intval( $user->ID ), 'yes' );
        $rsvp_maybe_url = self::get_quick_rsvp_url( $post_id, intval( $user->ID ), 'maybe' );
        $rsvp_no_url = self::get_quick_rsvp_url( $post_id, intval( $user->ID ), 'no' );
        $buttons_markup = self::get_rsvp_buttons_markup( $rsvp_yes_url, $rsvp_maybe_url, $rsvp_no_url );
        $buttons_token = '[[BAND_EVENT_RSVP_BUTTONS]]';

        $replacements = array(
            '{display_name}'   => (string) $user->display_name,
            '{event_title}'    => (string) $post->post_title,
            '{event_start}'    => self::format_event_start_for_email( $post_id ),
            '{event_location}' => $location,
            '{event_url}'      => (string) get_permalink( $post_id ),
            '{site_name}'      => (string) get_bloginfo( 'name' ),
            '{site_url}'       => (string) home_url(),
            '{rsvp_yes_url}'   => $rsvp_yes_url,
            '{rsvp_maybe_url}' => $rsvp_maybe_url,
            '{rsvp_no_url}'    => $rsvp_no_url,
            '{rsvp_buttons}'   => $buttons_token,
        );

        $message = strtr( $template, $replacements );
        $message = nl2br( esc_html( $message ) );
        $message = str_replace( esc_html( $buttons_token ), $buttons_markup, $message );
        $message = wp_kses_post( $message );

        return $message;
    }

    protected static function get_quick_rsvp_url( $post_id, $user_id, $status ) {
        $post_id = absint( $post_id );
        $user_id = absint( $user_id );
        $status = sanitize_key( (string) $status );

        if ( $post_id <= 0 || $user_id <= 0 || ! in_array( $status, array( 'yes', 'maybe', 'no' ), true ) ) {
            return '';
        }

        $args = array(
            'band_event_rsvp_quick' => 1,
            'band_event_id'         => $post_id,
            'band_event_user_id'    => $user_id,
            'band_event_response'   => $status,
            '_wpnonce'              => wp_create_nonce( 'band_event_quick_rsvp_' . $post_id . '_' . $user_id . '_' . $status ),
        );

        return add_query_arg( $args, get_permalink( $post_id ) );
    }

    protected static function get_rsvp_buttons_markup( $yes_url, $maybe_url, $no_url ) {
        return '<a href="' . esc_url( $yes_url ) . '" style="display:inline-block;margin:0 8px 8px 0;padding:10px 14px;background:#27ae60;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">Yes</a>'
            . '<a href="' . esc_url( $maybe_url ) . '" style="display:inline-block;margin:0 8px 8px 0;padding:10px 14px;background:#f1c40f;color:#111;text-decoration:none;border-radius:4px;font-weight:600;">Maybe</a>'
            . '<a href="' . esc_url( $no_url ) . '" style="display:inline-block;margin:0 8px 8px 0;padding:10px 14px;background:#e74c3c;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">No</a>';
    }

    protected static function send_event_emails( $post_id, $recipient_ids, $subject, $track_as_reminder ) {
        if ( empty( $recipient_ids ) ) {
            return 0;
        }

        $already_sent = array();
        if ( $track_as_reminder ) {
            $already_sent = get_post_meta( $post_id, '_band_event_reminder_sent', true );
            if ( ! is_array( $already_sent ) ) {
                $already_sent = array();
            }
        }

        $sent_count = 0;
        foreach ( $recipient_ids as $user_id ) {
            $user_id = intval( $user_id );
            if ( $track_as_reminder && in_array( $user_id, $already_sent, true ) ) {
                continue;
            }

            $user = get_userdata( $user_id );
            if ( ! $user || ! is_email( $user->user_email ) ) {
                continue;
            }

            $message = self::get_email_message_for_user( $post_id, $user );
            if ( '' === $message ) {
                continue;
            }

            $headers = array( 'Content-Type: text/html; charset=UTF-8' );
            wp_mail( $user->user_email, $subject, $message, $headers );

            $sent_count++;
            if ( $track_as_reminder ) {
                $already_sent[] = $user_id;
            }
        }

        if ( $track_as_reminder && ! empty( $already_sent ) ) {
            update_post_meta( $post_id, '_band_event_reminder_sent', array_values( array_unique( $already_sent ) ) );
        }

        return $sent_count;
    }

    public static function send_event_email_now( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 || 'event' !== get_post_type( $post_id ) ) {
            return 0;
        }

        $recipient_ids = Band_Event_RSVP_CPT::get_invited_member_user_ids( $post_id );
        if ( empty( $recipient_ids ) ) {
            return 0;
        }

        $subject = sprintf( __( 'New event: %s', 'band-event-rsvp' ), get_the_title( $post_id ) );
        return self::send_event_emails( $post_id, $recipient_ids, $subject, false );
    }

    protected static function get_member_query_args() {
        return apply_filters(
            'band_event_rsvp_member_query_args',
            array(
                'fields' => 'ID',
            )
        );
    }

    protected static function get_member_user_ids() {
        $users = get_users( self::get_member_query_args() );
        if ( ! is_array( $users ) ) {
            return array();
        }
        return array_map( 'intval', $users );
    }

    protected static function get_recipient_user_ids( $post_id ) {
        $target = self::get_reminder_target();
        $responses = get_post_meta( $post_id, '_band_event_rsvps', true );
        if ( ! is_array( $responses ) ) {
            $responses = array();
        }

        $responded_ids = array_map( 'intval', array_keys( $responses ) );
        $invited_ids = Band_Event_RSVP_CPT::get_invited_member_user_ids( $post_id );

        if ( 'all_invited' === $target ) {
            return $invited_ids;
        }

        if ( 'all_responded' === $target ) {
            return $responded_ids;
        }

        $recipient_ids = array();
        foreach ( $responses as $user_id => $resp ) {
            if ( isset( $resp['status'] ) && 'maybe' === $resp['status'] ) {
                $recipient_ids[] = intval( $user_id );
            }
        }

        foreach ( $invited_ids as $user_id ) {
            if ( in_array( $user_id, $recipient_ids, true ) ) {
                continue;
            }
            if ( in_array( $user_id, $responded_ids, true ) ) {
                continue;
            }
            $recipient_ids[] = $user_id;
        }

        return array_values( array_unique( $recipient_ids ) );
    }

    public static function get_reminders_enabled() {
        return filter_var( get_option( self::OPTION_ENABLED, true ), FILTER_VALIDATE_BOOLEAN );
    }

    public static function send_reminders() {
        if ( ! self::get_reminders_enabled() ) {
            return;
        }

        $hours = self::get_reminder_hours();
        $now = current_time( 'mysql' );
        $future = date( 'Y-m-d H:i:s', strtotime( "{$hours} hours" ) );

        $events = get_posts( array(
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => '_band_event_start',
                    'value'   => array( $now, $future ),
                    'compare' => 'BETWEEN',
                    'type'    => 'DATETIME',
                ),
            ),
        ) );

        if ( empty( $events ) ) {
            return;
        }

        foreach ( $events as $event ) {
            $post_id = $event->ID;
            $recipient_ids = self::get_recipient_user_ids( $post_id );
            if ( empty( $recipient_ids ) ) {
                continue;
            }

            $subject = sprintf( __( 'Reminder: %s is coming up', 'band-event-rsvp' ), get_the_title( $post_id ) );
            self::send_event_emails( $post_id, $recipient_ids, $subject, true );
        }
    }
}

Band_Event_RSVP_Reminder::init();
