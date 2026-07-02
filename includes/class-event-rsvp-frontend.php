<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Band_Event_RSVP_Frontend {
    public static function init() {
        add_shortcode( 'band_event_list', array( __CLASS__, 'render_event_list' ) );
        add_shortcode( 'band_event_detail', array( __CLASS__, 'render_event_detail' ) );
        add_action( 'wp', array( __CLASS__, 'handle_rsvp_submission' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
        add_filter( 'the_content', array( __CLASS__, 'render_single_event_content' ) );
    }

    public static function get_current_member_status() {
        if ( function_exists( 'swpm_is_member_logged_in' ) ) {
            return swpm_is_member_logged_in();
        }
        return is_user_logged_in();
    }

    public static function enqueue_styles() {
        wp_register_style( 'band-event-frontend', BAND_EVENT_RSVP_URL . 'assets/frontend.css', array(), BAND_EVENT_RSVP_VERSION );
        wp_enqueue_style( 'band-event-frontend' );
        wp_register_style( 'band-event-frontend-custom', BAND_EVENT_RSVP_URL . 'assets/frontend-custom.css', array( 'band-event-frontend' ), BAND_EVENT_RSVP_VERSION );
        wp_enqueue_style( 'band-event-frontend-custom' );
    }

    public static function render_event_list( $atts ) {
        $atts = shortcode_atts( array(
            'posts_per_page' => 10,
        ), $atts, 'band_event_list' );

        $today_midnight = date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );

        $events = new WP_Query( array(
            'post_type'      => 'event',
            'posts_per_page' => intval( $atts['posts_per_page'] ),
            'post_status'    => 'publish',
            'orderby'        => 'meta_value',
            'meta_key'       => '_band_event_start',
            'order'          => 'ASC',
            'meta_query'     => array(
                array(
                    'key'     => '_band_event_start',
                    'value'   => $today_midnight,
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ),
            ),
        ) );

        if ( ! $events->have_posts() ) {
            return '<p>' . esc_html__( 'No upcoming events found.', 'band-event-rsvp' ) . '</p>';
        }

        $output = '<div class="band-event-list">';
        while ( $events->have_posts() ) {
            $events->the_post();
            $output .= self::render_event_summary( get_the_ID() );
        }
        wp_reset_postdata();
        $output .= '</div>';

        return $output;
    }

    public static function is_event_in_past( $start_datetime ) {
        if ( empty( $start_datetime ) ) {
            return false;
        }

        $event_timestamp = strtotime( $start_datetime );
        if ( false === $event_timestamp ) {
            return false;
        }

        $current_timestamp = current_time( 'timestamp' );
        return $event_timestamp < $current_timestamp;
    }

    public static function render_event_summary( $post_id ) {
        $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
        $title = get_the_title( $post_id );
        $excerpt = get_the_excerpt( $post_id );
        $start = $fields['start'] ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $fields['start'] ) ) : esc_html__( 'No start time set', 'band-event-rsvp' );
        $location = $fields['location'] ? esc_html( $fields['location'] ) : esc_html__( 'No location set', 'band-event-rsvp' );
        $permalink = get_permalink( $post_id );

        $recurrence_note = '';
        if ( ! empty( $fields['recurrence_id'] ) ) {
            if ( ! empty( $fields['recurrence_index'] ) && ! empty( $fields['recurrence_total'] ) ) {
                $recurrence_note = sprintf( '%s %s/%s', esc_html__( 'Series', 'band-event-rsvp' ), intval( $fields['recurrence_index'] ), intval( $fields['recurrence_total'] ) );
            } elseif ( ! empty( $fields['recurrence_index'] ) ) {
                $recurrence_note = sprintf( '%s %s', esc_html__( 'Series', 'band-event-rsvp' ), intval( $fields['recurrence_index'] ) );
            } else {
                $recurrence_note = esc_html__( 'Series', 'band-event-rsvp' );
            }
        } elseif ( intval( $fields['recurring_count'] ) > 0 && 'none' !== $fields['recurring_unit'] ) {
            $recurrence_note = sprintf( esc_html__( 'Repeats every %d %s', 'band-event-rsvp' ), intval( $fields['recurring_count'] ), esc_html( $fields['recurring_unit'] ) );
        }

        $output  = '<div class="band-event-item">';
        $output .= '<a href="' . esc_url( $permalink ) . '" class="band-event-link">';
        $output .= '<span class="band-event-title">' . esc_html( $title ) . '</span>';
        $output .= '<span class="band-event-meta">' . esc_html( $start ) . ' | ' . $location . '</span>';
        if ( $recurrence_note ) {
            $output .= '<span class="band-event-recurring">' . esc_html( $recurrence_note ) . '</span>';
        }
        $output .= '</a>';

        if ( ! self::is_event_in_past( $fields['start'] ) && self::get_current_member_status() ) {
            $output .= self::render_event_list_rsvp( $post_id );
        }

        $output .= '</div>';

        return $output;
    }

    public static function render_event_list_rsvp( $post_id ) {
        $user_id = get_current_user_id();
        $rsvp_data = self::get_rsvp_for_user( $post_id, $user_id );
        $current_status = $rsvp_data['status'] ?? '';

        $output = '<div class="band-event-rsvp-inline">';
        $output .= '<form method="post" class="band-event-rsvp-form-inline">';
        $output .= wp_nonce_field( 'band_event_rsvp_form', 'band_event_rsvp_nonce', true, false );
        $output .= '<input type="hidden" name="band_event_id" value="' . esc_attr( $post_id ) . '" />';
        $output .= '<div class="band-event-rsvp-buttons">';
        $output .= '<button type="submit" name="band_event_response" value="yes" class="band-event-rsvp-button band-event-rsvp-button-yes ' . ( 'yes' === $current_status ? 'active' : '' ) . '">✅ ' . esc_html__( 'Yes', 'band-event-rsvp' ) . '</button>';
        $output .= '<button type="submit" name="band_event_response" value="maybe" class="band-event-rsvp-button band-event-rsvp-button-maybe ' . ( 'maybe' === $current_status ? 'active' : '' ) . '">🤔 ' . esc_html__( 'Maybe', 'band-event-rsvp' ) . '</button>';
        $output .= '<button type="submit" name="band_event_response" value="no" class="band-event-rsvp-button band-event-rsvp-button-no ' . ( 'no' === $current_status ? 'active' : '' ) . '">❌ ' . esc_html__( 'No', 'band-event-rsvp' ) . '</button>';
        $output .= '</div>';
        $output .= '</form>';
        $output .= '</div>';

        return $output;
    }

    public static function render_event_detail( $atts ) {
        $atts = shortcode_atts( array(
            'id' => 0,
        ), $atts, 'band_event_detail' );

        $post_id = intval( $atts['id'] );
        if ( ! $post_id ) {
            return '<p>' . esc_html__( 'No event selected.', 'band-event-rsvp' ) . '</p>';
        }

        return self::render_event_detail_content( $post_id );
    }

    public static function render_event_detail_content( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || 'event' !== $post->post_type ) {
            return '<p>' . esc_html__( 'Event not found.', 'band-event-rsvp' ) . '</p>';
        }

        $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
        $archive_link = get_post_type_archive_link( 'event' );
        $output  = '<div class="band-event-detail">';
        // Back to events link for easier mobile navigation
        if ( $archive_link ) {
            $output .= '<p class="band-event-back"><a href="' . esc_url( $archive_link ) . '">&larr; ' . esc_html__( 'Back to events', 'band-event-rsvp' ) . '</a></p>';
        }
        $output .= '<div class="band-event-description">' . wpautop( wp_kses_post( $post->post_content ) ) . '</div>';
        $output .= '<ul class="band-event-data">';
        $output .= '<li><strong>' . esc_html__( 'Location:', 'band-event-rsvp' ) . '</strong> ' . esc_html( $fields['location'] ) . '</li>';
        $output .= '<li><strong>' . esc_html__( 'Start:', 'band-event-rsvp' ) . '</strong> ' . esc_html( $fields['start'] ) . '</li>';
        $output .= '<li><strong>' . esc_html__( 'End:', 'band-event-rsvp' ) . '</strong> ' . esc_html( $fields['end'] ) . '</li>';
        if ( intval( $fields['recurring_count'] ) > 0 && 'none' !== $fields['recurring_unit'] ) {
            $output .= '<li><strong>' . esc_html__( 'Recurring:', 'band-event-rsvp' ) . '</strong> ' . esc_html( sprintf( __( 'Every %d %s', 'band-event-rsvp' ), intval( $fields['recurring_count'] ), $fields['recurring_unit'] ) ) . '</li>';
        }
        $output .= '<li><strong>' . esc_html__( 'Contact:', 'band-event-rsvp' ) . '</strong> ' . esc_html( $fields['contact_person'] ) . '</li>';
        $invited_levels = Band_Event_RSVP_CPT::get_invited_membership_levels( $post_id );
        if ( ! empty( $invited_levels ) ) {
            $available_levels = Band_Event_RSVP_CPT::get_available_membership_levels();
            $level_labels = array();
            foreach ( $invited_levels as $level_id ) {
                if ( isset( $available_levels[ $level_id ] ) ) {
                    $level_labels[] = $available_levels[ $level_id ];
                } else {
                    $level_labels[] = sprintf( esc_html__( 'Level %d', 'band-event-rsvp' ), intval( $level_id ) );
                }
            }
            if ( ! empty( $level_labels ) ) {
                $output .= '<li><strong>' . esc_html__( 'Invited levels:', 'band-event-rsvp' ) . '</strong> ' . esc_html( implode( ', ', $level_labels ) ) . '</li>';
            }
        }
        $output .= '</ul>';
        $output .= self::render_rsvp_section( $post_id );
        $output .= self::render_attendee_list( $post_id );
        $output .= '</div>';

        return $output;
    }

    public static function render_rsvp_section( $post_id ) {
        if ( ! self::get_current_member_status() ) {
            return '<p class="band-event-login-message">' . esc_html__( 'Please log in as a member to RSVP.', 'band-event-rsvp' ) . '</p>';
        }

        $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
        if ( self::is_event_in_past( $fields['start'] ) ) {
            return '<p class="band-event-past-message">' . esc_html__( 'This event has already occurred and RSVPs are no longer accepted.', 'band-event-rsvp' ) . '</p>';
        }

        $user_id = get_current_user_id();
        $rsvp_data = self::get_rsvp_for_user( $post_id, $user_id );
        $current_status = $rsvp_data['status'] ?? '';

        $output  = '<div class="band-event-rsvp">';
        $output .= '<h3>' . esc_html__( 'RSVP', 'band-event-rsvp' ) . '</h3>';
        $output .= '<form method="post" class="band-event-rsvp-form">';
        $output .= wp_nonce_field( 'band_event_rsvp_form', 'band_event_rsvp_nonce', true, false );
        $output .= '<input type="hidden" name="band_event_id" value="' . esc_attr( $post_id ) . '" />';
        $output .= '<p><label>' . esc_html__( 'Comment', 'band-event-rsvp' ) . '<br /><textarea name="band_event_comment" class="widefat" rows="4">' . esc_textarea( $rsvp_data['comment'] ?? '' ) . '</textarea></label></p>';
        $output .= '<div class="band-event-rsvp-buttons-large">';
        $output .= '<button type="submit" name="band_event_response" value="yes" class="band-event-rsvp-button band-event-rsvp-button-yes ' . ( 'yes' === $current_status ? 'active' : '' ) . '">✅ ' . esc_html__( 'Yes', 'band-event-rsvp' ) . '</button>';
        $output .= '<button type="submit" name="band_event_response" value="maybe" class="band-event-rsvp-button band-event-rsvp-button-maybe ' . ( 'maybe' === $current_status ? 'active' : '' ) . '">🤔 ' . esc_html__( 'Maybe', 'band-event-rsvp' ) . '</button>';
        $output .= '<button type="submit" name="band_event_response" value="no" class="band-event-rsvp-button band-event-rsvp-button-no ' . ( 'no' === $current_status ? 'active' : '' ) . '">❌ ' . esc_html__( 'No', 'band-event-rsvp' ) . '</button>';
        $output .= '</div>';
        $output .= '</form>';
        $output .= '</div>';

        return $output;
    }

    public static function get_rsvp_for_user( $post_id, $user_id ) {
        $responses = get_post_meta( $post_id, '_band_event_rsvps', true );
        if ( ! is_array( $responses ) ) {
            return array();
        }

        return isset( $responses[ $user_id ] ) ? $responses[ $user_id ] : array();
    }

    public static function get_rsvp_list( $post_id ) {
        $responses = get_post_meta( $post_id, '_band_event_rsvps', true );
        if ( ! is_array( $responses ) ) {
            return array();
        }
        return $responses;
    }

    public static function render_attendee_list( $post_id ) {
        $responses = self::get_rsvp_list( $post_id );
        $grouped = array(
            'yes'   => array(),
            'maybe' => array(),
            'no'    => array(),
        );

        foreach ( $responses as $response ) {
            $status = isset( $response['status'] ) ? $response['status'] : 'no';
            if ( ! isset( $grouped[ $status ] ) ) {
                $grouped[ $status ] = array();
            }
            $grouped[ $status ][] = $response;
        }

        $output  = '<div class="band-event-attendees">';
        $output .= '<h3>' . esc_html__( 'Attendees', 'band-event-rsvp' ) . '</h3>';
        if ( empty( $responses ) ) {
            $output .= '<p>' . esc_html__( 'No RSVPs yet.', 'band-event-rsvp' ) . '</p>';
        }

        if ( ! empty( $grouped['yes'] ) ) {
            $output .= '<div class="band-event-attendees-group band-event-attendees-yes">';
            $output .= '<h4>' . esc_html__( 'Attending', 'band-event-rsvp' ) . ' (' . count( $grouped['yes'] ) . ')</h4>';
            $output .= '<ul>';
            foreach ( $grouped['yes'] as $response ) {
                $output .= '<li>' . esc_html( $response['display_name'] );
                if ( ! empty( $response['comment'] ) ) {
                    $output .= ' — ' . esc_html( $response['comment'] );
                }
                $output .= '</li>';
            }
            $output .= '</ul>';
            $output .= '</div>';
        }

        if ( ! empty( $grouped['maybe'] ) ) {
            $output .= '<div class="band-event-attendees-group band-event-attendees-maybe">';
            $output .= '<h4>' . esc_html__( 'Maybe', 'band-event-rsvp' ) . ' (' . count( $grouped['maybe'] ) . ')</h4>';
            $output .= '<ul>';
            foreach ( $grouped['maybe'] as $response ) {
                $output .= '<li>' . esc_html( $response['display_name'] );
                if ( ! empty( $response['comment'] ) ) {
                    $output .= ' — ' . esc_html( $response['comment'] );
                }
                $output .= '</li>';
            }
            $output .= '</ul>';
            $output .= '</div>';
        }

        if ( ! empty( $grouped['no'] ) ) {
            $output .= '<div class="band-event-attendees-group band-event-attendees-no">';
            $output .= '<h4>' . esc_html__( 'Not Attending', 'band-event-rsvp' ) . ' (' . count( $grouped['no'] ) . ')</h4>';
            $output .= '<ul>';
            foreach ( $grouped['no'] as $response ) {
                $output .= '<li>' . esc_html( $response['display_name'] );
                if ( ! empty( $response['comment'] ) ) {
                    $output .= ' — ' . esc_html( $response['comment'] );
                }
                $output .= '</li>';
            }
            $output .= '</ul>';
            $output .= '</div>';
        }

        $output .= self::render_unanswered_attendee_list( $post_id );
        $output .= '</div>';

        return $output;
    }

    public static function render_unanswered_attendee_list( $post_id ) {
        $invited_ids = Band_Event_RSVP_CPT::get_invited_member_user_ids( $post_id );
        if ( empty( $invited_ids ) ) {
            return '';
        }

        $responses = self::get_rsvp_list( $post_id );
        $responded_ids = array_map( 'intval', array_keys( $responses ) );
        $pending_ids = array_diff( $invited_ids, $responded_ids );

        if ( empty( $pending_ids ) ) {
            return '';
        }

        $output  = '<div class="band-event-attendees-group band-event-attendees-unanswered">';
        $output .= '<h4>' . esc_html__( 'Not Yet Responded', 'band-event-rsvp' ) . ' (' . count( $pending_ids ) . ')</h4>';
        $output .= '<ul>';
        foreach ( $pending_ids as $user_id ) {
            $user = get_userdata( $user_id );
            if ( $user ) {
                $name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
                if ( ! empty( $name ) ) {
                    $label = $name;
                } elseif ( ! empty( $user->display_name ) ) {
                    $label = $user->display_name;
                } else {
                    $label = $user->user_login;
                }
            } else {
                $label = sprintf( esc_html__( 'User #%d', 'band-event-rsvp' ), intval( $user_id ) );
            }
            $output .= '<li>' . esc_html( $label ) . '</li>';
        }
        $output .= '</ul>';
        $output .= '</div>';

        return $output;
    }

    public static function handle_rsvp_submission() {
        if ( ! isset( $_POST['band_event_response'] ) ) {
            return;
        }

        if ( ! isset( $_POST['band_event_rsvp_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['band_event_rsvp_nonce'] ), 'band_event_rsvp_form' ) ) {
            wp_die( 'Security check failed.' );
        }

        if ( ! self::get_current_member_status() ) {
            wp_die( 'You must be logged in to RSVP.' );
        }

        $post_id = isset( $_POST['band_event_id'] ) ? intval( $_POST['band_event_id'] ) : 0;
        $status  = sanitize_text_field( wp_unslash( $_POST['band_event_response'] ) );
        $comment = isset( $_POST['band_event_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['band_event_comment'] ) ) : '';
        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        if ( ! $post_id ) {
            wp_die( 'Invalid event ID.' );
        }

        if ( ! in_array( $status, array( 'yes', 'maybe', 'no' ), true ) ) {
            wp_die( 'Invalid RSVP status.' );
        }

        $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
        if ( self::is_event_in_past( $fields['start'] ) ) {
            wp_die( 'Cannot RSVP to past events.' );
        }

        $responses = self::get_rsvp_list( $post_id );
        $full_name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
        if ( empty( $full_name ) ) {
            if ( ! empty( $user->display_name ) ) {
                $full_name = $user->display_name;
            } else {
                $full_name = $user->user_login;
            }
        }

        $responses[ $user_id ] = array(
            'status'       => $status,
            'comment'      => $comment,
            'display_name' => sanitize_text_field( $full_name ),
            'updated'      => current_time( 'mysql' ),
        );

        update_post_meta( $post_id, '_band_event_rsvps', $responses );

        wp_safe_redirect( add_query_arg( 'rsvp_saved', '1', get_permalink( $post_id ) ) );
        exit;
    }

    public static function render_single_event_content( $content ) {
        if ( ! is_singular( 'event' ) ) {
            return $content;
        }

        global $post;
        if ( 'event' !== $post->post_type ) {
            return $content;
        }

        $post_id = $post->ID;
        $rsvp_msg = '';

        if ( isset( $_GET['rsvp_saved'] ) ) {
            $rsvp_msg = '<div class="band-event-notice notice-success"><p>' . esc_html__( 'Your RSVP has been saved!', 'band-event-rsvp' ) . '</p></div>';
        }

        $event_detail = self::render_event_detail_content( $post_id );
        // Avoid duplicating the post content (description) — the event detail includes it.
        return $rsvp_msg . $event_detail;
    }
}
