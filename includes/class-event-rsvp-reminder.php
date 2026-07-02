<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Band_Event_RSVP_Reminder {
    const CRON_HOOK = 'band_event_rsvp_send_reminders';
    const OPTION_HOURS = 'band_event_rsvp_reminder_hours';
    const OPTION_ENABLED = 'band_event_rsvp_reminder_enabled';
    const OPTION_TARGET = 'band_event_rsvp_reminder_target';

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
        $future = date( 'Y-m-d H:i:s', strtotime( "${hours} hours" ) );

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

            $already_sent = get_post_meta( $post_id, '_band_event_reminder_sent', true );
            if ( ! is_array( $already_sent ) ) {
                $already_sent = array();
            }

            foreach ( $recipient_ids as $user_id ) {
                $user_id = intval( $user_id );
                if ( in_array( $user_id, $already_sent, true ) ) {
                    continue;
                }

                $responses = get_post_meta( $post_id, '_band_event_rsvps', true );
                if ( ! is_array( $responses ) ) {
                    $responses = array();
                }

                $status = isset( $responses[ $user_id ]['status'] ) ? $responses[ $user_id ]['status'] : '';
                $target = self::get_reminder_target();

                if ( 'all_responded' === $target && ! array_key_exists( $user_id, $responses ) ) {
                    continue;
                }

                if ( 'tentative_or_unanswered' === $target ) {
                    if ( $status && 'maybe' !== $status ) {
                        continue;
                    }
                }

                $user = get_userdata( $user_id );
                if ( ! $user || ! is_email( $user->user_email ) ) {
                    continue;
                }

                $subject = sprintf( __( 'Reminder: %s is coming up', 'band-event-rsvp' ), $event->post_title );
                $start = get_post_meta( $post_id, '_band_event_start', true );
                $location = get_post_meta( $post_id, '_band_event_location', true );
                $permalink = get_permalink( $post_id );

                $message = sprintf( __( 'Hi %s,', 'band-event-rsvp' ), $user->display_name ) . "\n\n";
                $message .= sprintf( __( 'This is a reminder that "%s" starts on %s at %s.', 'band-event-rsvp' ), $event->post_title, $start, $location ) . "\n\n";
                $message .= sprintf( __( 'View event: %s', 'band-event-rsvp' ), $permalink ) . "\n\n";
                $message .= __( 'If your plans have changed, please update your RSVP.', 'band-event-rsvp' ) . "\n\n";
                $message .= get_bloginfo( 'name' ) . "\n" . home_url();

                $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

                wp_mail( $user->user_email, $subject, $message, $headers );

                $already_sent[] = $user_id;
            }

            if ( ! empty( $already_sent ) ) {
                update_post_meta( $post_id, '_band_event_reminder_sent', $already_sent );
            }
        }
    }
}

Band_Event_RSVP_Reminder::init();
