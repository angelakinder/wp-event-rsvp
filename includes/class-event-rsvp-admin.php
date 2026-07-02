<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Band_Event_RSVP_Admin {
    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_recurrence_actions' ) );
        add_filter( 'post_row_actions', array( __CLASS__, 'add_recurrence_row_actions' ), 10, 2 );
        add_shortcode( 'band_event_admin_form', array( __CLASS__, 'render_frontend_event_form' ) );
    }

    public static function register_settings() {
        register_setting(
            'band_event_rsvp_settings',
            Band_Event_RSVP_Reminder::OPTION_HOURS,
            array(
                'type'         => 'integer',
                'sanitize_callback' => array( __CLASS__, 'sanitize_reminder_hours' ),
                'default'      => 24,
            )
        );

        register_setting(
            'band_event_rsvp_settings',
            Band_Event_RSVP_Reminder::OPTION_ENABLED,
            array(
                'type'         => 'boolean',
                'sanitize_callback' => array( __CLASS__, 'sanitize_reminder_enabled' ),
                'default'      => true,
            )
        );

        register_setting(
            'band_event_rsvp_settings',
            Band_Event_RSVP_Reminder::OPTION_TARGET,
            array(
                'type'         => 'string',
                'sanitize_callback' => array( __CLASS__, 'sanitize_reminder_target' ),
                'default'      => 'all_invited',
            )
        );
    }

    public static function sanitize_reminder_hours( $value ) {
        $hours = intval( $value );
        return $hours > 0 ? $hours : 24;
    }

    public static function sanitize_reminder_enabled( $value ) {
        return boolval( $value );
    }

    public static function sanitize_reminder_target( $value ) {
        $allowed = array( 'all_invited', 'tentative_or_unanswered' );
        return in_array( $value, $allowed, true ) ? $value : 'all_invited';
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=event',
            __( 'Simple RSVP Settings', 'band-event-rsvp' ),
            __( 'Simple RSVP', 'band-event-rsvp' ),
            'manage_options',
            'band-event-rsvp-settings',
            array( __CLASS__, 'render_settings_page' )
        );
    }

    public static function render_settings_page() {
        $enabled = get_option( Band_Event_RSVP_Reminder::OPTION_ENABLED, true );
        $hours = get_option( Band_Event_RSVP_Reminder::OPTION_HOURS, 24 );
        $target = get_option( Band_Event_RSVP_Reminder::OPTION_TARGET, 'all_invited' );
        if ( 'all_responded' === $target ) {
            $target = 'all_invited';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Simple RSVP Settings', 'band-event-rsvp' ); ?></h1>

            <form method="post" action="options.php" class="band-event-settings-form">
                <?php
                settings_fields( 'band_event_rsvp_settings' );
                do_settings_sections( 'band_event_rsvp_settings' );
                ?>

                <div class="card">
                    <h2><?php esc_html_e( 'Reminder Email Settings', 'band-event-rsvp' ); ?></h2>
                    <p><?php esc_html_e( 'Configure how reminder emails are sent to members for upcoming events.', 'band-event-rsvp' ); ?></p>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="band_event_rsvp_reminder_enabled"><?php esc_html_e( 'Enable reminders', 'band-event-rsvp' ); ?></label></th>
                            <td>
                                <input type="hidden" name="band_event_rsvp_reminder_enabled" value="0" />
                                <input type="checkbox" id="band_event_rsvp_reminder_enabled" name="band_event_rsvp_reminder_enabled" value="1" <?php checked( $enabled, true ); ?> />
                                <p class="description"><?php esc_html_e( 'Toggle reminder emails for upcoming events.', 'band-event-rsvp' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="band_event_rsvp_reminder_target"><?php esc_html_e( 'Reminder recipients', 'band-event-rsvp' ); ?></label></th>
                            <td>
                                <select id="band_event_rsvp_reminder_target" name="band_event_rsvp_reminder_target">
                                    <option value="all_invited" <?php selected( $target, 'all_invited' ); ?>><?php esc_html_e( 'All invited members', 'band-event-rsvp' ); ?></option>
                                    <option value="tentative_or_unanswered" <?php selected( $target, 'tentative_or_unanswered' ); ?>><?php esc_html_e( 'Tentative or unanswered members', 'band-event-rsvp' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Choose which invited members should receive reminder emails.', 'band-event-rsvp' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="band_event_rsvp_reminder_hours"><?php esc_html_e( 'Send reminders before', 'band-event-rsvp' ); ?></label></th>
                            <td>
                                <input type="number" id="band_event_rsvp_reminder_hours" name="band_event_rsvp_reminder_hours" value="<?php echo esc_attr( $hours ); ?>" min="1" class="small-text" />
                                <span><?php esc_html_e( 'hours', 'band-event-rsvp' ); ?></span>
                                <p class="description"><?php esc_html_e( 'Reminder emails are sent by WordPress cron hourly.', 'band-event-rsvp' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(); ?>
                </div>

                <div class="card">
                    <h2><?php esc_html_e( 'Available Shortcodes', 'band-event-rsvp' ); ?></h2>
                    <p><?php esc_html_e( 'Use these shortcodes in your pages or posts to display events and RSVP forms.', 'band-event-rsvp' ); ?></p>

                    <h3><?php esc_html_e( 'Event List', 'band-event-rsvp' ); ?></h3>
                    <p><?php esc_html_e( 'Displays all upcoming events with expandable details:', 'band-event-rsvp' ); ?></p>
                    <code>[band_event_list posts_per_page="10"]</code>
                    <ul>
                        <li><strong>posts_per_page</strong> — Number of events to display (default: 10)</li>
                    </ul>

                    <h3><?php esc_html_e( 'Single Event Detail', 'band-event-rsvp' ); ?></h3>
                    <p><?php esc_html_e( 'Displays a single event with full details and RSVP form (used automatically on event pages):', 'band-event-rsvp' ); ?></p>
                    <code>[band_event_detail id="123"]</code>
                    <ul>
                        <li><strong>id</strong> — Event post ID (required)</li>
                    </ul>

                    <h3><?php esc_html_e( 'Admin Event Creation Form', 'band-event-rsvp' ); ?></h3>
                    <p><?php esc_html_e( 'Allows admins to create events from the front-end:', 'band-event-rsvp' ); ?></p>
                    <code>[band_event_admin_form]</code>

                    <h3><?php esc_html_e( 'Template Loading', 'band-event-rsvp' ); ?></h3>
                    <p><?php esc_html_e( 'The plugin automatically loads the event archive template from the plugin.', 'band-event-rsvp' ); ?></p>
                    <p><?php esc_html_e( 'To override it in your theme, create: your-theme/band-event-rsvp/archive-event.php', 'band-event-rsvp' ); ?></p>

                    <h2><?php esc_html_e( 'Member Access', 'band-event-rsvp' ); ?></h2>
                    <p><?php esc_html_e( 'Only logged-in Simple Membership members can view detailed event information and RSVP to events.', 'band-event-rsvp' ); ?></p>

                    <h2><?php esc_html_e( 'Event Fields', 'band-event-rsvp' ); ?></h2>
                    <ul>
                        <li><?php esc_html_e( 'Event Name (title)', 'band-event-rsvp' ); ?></li>
                        <li><?php esc_html_e( 'Description (content)', 'band-event-rsvp' ); ?></li>
                        <li><?php esc_html_e( 'Location', 'band-event-rsvp' ); ?></li>
                        <li><?php esc_html_e( 'Start Date/Time', 'band-event-rsvp' ); ?></li>
                        <li><?php esc_html_e( 'End Date/Time', 'band-event-rsvp' ); ?></li>
                        <li><?php esc_html_e( 'Recurring Interval (days, weeks, months)', 'band-event-rsvp' ); ?></li>
                        <li><?php esc_html_e( 'Contact Person', 'band-event-rsvp' ); ?></li>
                    </ul>
                </div>
            </form>
        </div>
        <?php
    }

    public static function enqueue_admin_assets( $hook ) {
        if ( 'post-new.php' === $hook || 'post.php' === $hook ) {
            global $post_type;
            if ( 'event' === $post_type ) {
                wp_enqueue_style( 'band-event-admin', BAND_EVENT_RSVP_URL . 'assets/admin.css', array(), BAND_EVENT_RSVP_VERSION );
            }
        }

        if ( 'event_page_band-event-rsvp-settings' === $hook ) {
            wp_enqueue_style( 'band-event-admin', BAND_EVENT_RSVP_URL . 'assets/admin.css', array(), BAND_EVENT_RSVP_VERSION );
        }
    }

    public static function is_admin_user() {
        return current_user_can( 'manage_options' ) || current_user_can( 'edit_posts' );
    }

    public static function render_frontend_event_form() {
        if ( ! self::is_admin_user() ) {
            return '<p>' . esc_html__( 'You do not have permission to add events.', 'band-event-rsvp' ) . '</p>';
        }

        $now_date = current_time( 'Y-m-d' );
        $now_time = current_time( 'H:i' );
        $start_date_value = isset( $_POST['band_event_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_start_date'] ) ) : $now_date;
        $start_time_value = isset( $_POST['band_event_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_start_time'] ) ) : $now_time;
        $end_date_value = isset( $_POST['band_event_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_end_date'] ) ) : $now_date;
        $end_time_value = isset( $_POST['band_event_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_end_time'] ) ) : $now_time;
        $recurring_count_value = isset( $_POST['band_event_recurring_count'] ) ? intval( wp_unslash( $_POST['band_event_recurring_count'] ) ) : 0;
        $recurring_unit_value = isset( $_POST['band_event_recurring_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_recurring_unit'] ) ) : 'none';
        $recurrence_occurrences_value = isset( $_POST['band_event_recurrence_occurrences'] ) ? intval( wp_unslash( $_POST['band_event_recurrence_occurrences'] ) ) : 0;
        $recurrence_end_date_value = isset( $_POST['band_event_recurrence_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_recurrence_end_date'] ) ) : '';
        $contact_value = isset( $_POST['band_event_contact_person'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_contact_person'] ) ) : '';
        $contact_options = class_exists( 'Band_Event_RSVP_CPT' ) ? Band_Event_RSVP_CPT::get_contact_person_options() : array();

        $output = '';
        $output .= '<form method="post" class="band-event-form">';
        $output .= wp_nonce_field( 'band_event_frontend_form', 'band_event_frontend_nonce', true, false );
        $output .= '<p><label>' . esc_html__( 'Event Name', 'band-event-rsvp' ) . '<br /><input type="text" name="band_event_title" class="widefat" required></label></p>';
        $output .= '<p><label>' . esc_html__( 'Description', 'band-event-rsvp' ) . '<br /><textarea name="band_event_description" class="widefat" rows="5"></textarea></label></p>';
        $output .= '<p><label>' . esc_html__( 'Location', 'band-event-rsvp' ) . '<br /><input type="text" name="band_event_location" class="widefat"></label></p>';
        $output .= '<div class="date-time-row">';
        $output .= '<div class="date-time-field"><label>' . esc_html__( 'Start Date', 'band-event-rsvp' ) . '<br /><input type="date" name="band_event_start_date" class="widefat" value="' . esc_attr( $start_date_value ) . '"></label></div>';
        $output .= '<div class="date-time-field"><label>' . esc_html__( 'Start Time', 'band-event-rsvp' ) . '<br /><input type="time" name="band_event_start_time" class="widefat" value="' . esc_attr( $start_time_value ) . '"></label></div>';
        $output .= '</div>';
        $output .= '<div class="date-time-row">';
        $output .= '<div class="date-time-field"><label>' . esc_html__( 'End Date', 'band-event-rsvp' ) . '<br /><input type="date" name="band_event_end_date" class="widefat" value="' . esc_attr( $end_date_value ) . '"></label></div>';
        $output .= '<div class="date-time-field"><label>' . esc_html__( 'End Time', 'band-event-rsvp' ) . '<br /><input type="time" name="band_event_end_time" class="widefat" value="' . esc_attr( $end_time_value ) . '"></label></div>';
        $output .= '</div>';
        $output .= '<p><label>' . esc_html__( 'Recurring every', 'band-event-rsvp' ) . '<br /><input type="number" name="band_event_recurring_count" min="0" class="small-text" value="' . esc_attr( $recurring_count_value ) . '"> <select name="band_event_recurring_unit"><option value="none"' . selected( $recurring_unit_value, 'none', false ) . '>' . esc_html__( 'None', 'band-event-rsvp' ) . '</option><option value="days"' . selected( $recurring_unit_value, 'days', false ) . '>' . esc_html__( 'Days', 'band-event-rsvp' ) . '</option><option value="weeks"' . selected( $recurring_unit_value, 'weeks', false ) . '>' . esc_html__( 'Weeks', 'band-event-rsvp' ) . '</option><option value="months"' . selected( $recurring_unit_value, 'months', false ) . '>' . esc_html__( 'Months', 'band-event-rsvp' ) . '</option></select></label></p>';
        $output .= '<p><label>' . esc_html__( 'Number of occurrences', 'band-event-rsvp' ) . '<br /><input type="number" name="band_event_recurrence_occurrences" min="2" class="small-text" value="' . esc_attr( $recurrence_occurrences_value ) . '"></label></p>';
        $output .= '<p><label>' . esc_html__( 'Or end date', 'band-event-rsvp' ) . '<br /><input type="date" name="band_event_recurrence_end_date" class="widefat" value="' . esc_attr( $recurrence_end_date_value ) . '" /></label></p>';
        if ( ! empty( $contact_options ) ) {
            $output .= '<p><label>' . esc_html__( 'Contact Person', 'band-event-rsvp' ) . '<br />';
            $output .= '<select name="band_event_contact_person" class="widefat">';
            $output .= '<option value="">' . esc_html__( 'Select a member', 'band-event-rsvp' ) . '</option>';
            if ( ! empty( $contact_value ) && ! isset( $contact_options[ $contact_value ] ) ) {
                $output .= '<option value="' . esc_attr( $contact_value ) . '" selected="selected">' . esc_html( $contact_value ) . '</option>';
            }
            foreach ( $contact_options as $option_value => $option_label ) {
                $output .= '<option value="' . esc_attr( $option_value ) . '"' . selected( $contact_value, $option_value, false ) . '>' . esc_html( $option_label ) . '</option>';
            }
            $output .= '</select></label></p>';
        } else {
            $output .= '<p><label>' . esc_html__( 'Contact Person', 'band-event-rsvp' ) . '<br /><input type="text" name="band_event_contact_person" class="widefat" value="' . esc_attr( $contact_value ) . '"></label></p>';
        }
        $output .= '<p><button type="submit" name="band_event_submit" class="button button-primary">' . esc_html__( 'Create Event', 'band-event-rsvp' ) . '</button></p>';
        $output .= '</form>';

        if ( isset( $_POST['band_event_submit'] ) ) {
            $output .= self::process_frontend_event_form();
        }

        return $output;
    }

    public static function process_frontend_event_form() {
        if ( ! isset( $_POST['band_event_frontend_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['band_event_frontend_nonce'] ), 'band_event_frontend_form' ) ) {
            return '<p>' . esc_html__( 'Invalid form submission.', 'band-event-rsvp' ) . '</p>';
        }

        $title = isset( $_POST['band_event_title'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_title'] ) ) : '';
        if ( empty( $title ) ) {
            return '<p>' . esc_html__( 'Event title is required.', 'band-event-rsvp' ) . '</p>';
        }

        $start_date = isset( $_POST['band_event_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_start_date'] ) ) : '';
        $start_time = isset( $_POST['band_event_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_start_time'] ) ) : '';
        $end_date = isset( $_POST['band_event_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_end_date'] ) ) : '';
        $end_time = isset( $_POST['band_event_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_end_time'] ) ) : '';

        $start = '';
        if ( $start_date ) {
            $start = $start_date . ' ' . ( $start_time ? $start_time : '00:00' );
        } elseif ( isset( $_POST['band_event_start'] ) ) {
            $start = sanitize_text_field( wp_unslash( $_POST['band_event_start'] ) );
        }

        $end = '';
        if ( $end_date ) {
            $end = $end_date . ' ' . ( $end_time ? $end_time : '00:00' );
        } elseif ( isset( $_POST['band_event_end'] ) ) {
            $end = sanitize_text_field( wp_unslash( $_POST['band_event_end'] ) );
        }

        $post_id = wp_insert_post( array(
            'post_title'   => $title,
            'post_content' => isset( $_POST['band_event_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['band_event_description'] ) ) : '',
            'post_status'  => 'publish',
            'post_type'    => 'event',
        ) );

        if ( is_wp_error( $post_id ) ) {
            return '<p>' . esc_html__( 'Unable to create event.', 'band-event-rsvp' ) . '</p>';
        }

        $location = isset( $_POST['band_event_location'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_location'] ) ) : '';
        $recurring_count = isset( $_POST['band_event_recurring_count'] ) ? intval( wp_unslash( $_POST['band_event_recurring_count'] ) ) : 0;
        $recurring_unit = isset( $_POST['band_event_recurring_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_recurring_unit'] ) ) : 'none';
        $recurrence_occurrences = isset( $_POST['band_event_recurrence_occurrences'] ) ? intval( wp_unslash( $_POST['band_event_recurrence_occurrences'] ) ) : 0;
        $recurrence_end_date = isset( $_POST['band_event_recurrence_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_recurrence_end_date'] ) ) : '';
        $contact = isset( $_POST['band_event_contact_person'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_contact_person'] ) ) : '';

        if ( 'none' !== $recurring_unit && empty( $start ) ) {
            return '<p>' . esc_html__( 'Recurring events require a start date/time.', 'band-event-rsvp' ) . '</p>';
        }

        if ( 'none' !== $recurring_unit && $recurrence_occurrences < 2 && empty( $recurrence_end_date ) ) {
            return '<p>' . esc_html__( 'Recurring events require either a number of occurrences or an end date.', 'band-event-rsvp' ) . '</p>';
        }

        update_post_meta( $post_id, '_band_event_location', $location );
        update_post_meta( $post_id, '_band_event_start', $start );
        update_post_meta( $post_id, '_band_event_end', $end );
        update_post_meta( $post_id, '_band_event_recurring_count', $recurring_count );
        update_post_meta( $post_id, '_band_event_recurring_unit', $recurring_unit );
        update_post_meta( $post_id, '_band_event_contact_person', $contact );

        if ( 'none' !== $recurring_unit && ( $recurrence_occurrences > 1 || ! empty( $recurrence_end_date ) ) ) {
            $series_id = self::get_recurrence_series_id();
            update_post_meta( $post_id, '_band_event_recurrence_id', $series_id );
            update_post_meta( $post_id, '_band_event_recurrence_index', 1 );
            if ( ! empty( $recurrence_end_date ) ) {
                update_post_meta( $post_id, '_band_event_recurrence_end_date', $recurrence_end_date );
            }

            $series_total = self::create_recurrence_series_posts(
                $post_id,
                $title,
                isset( $_POST['band_event_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['band_event_description'] ) ) : '',
                $location,
                $start,
                $end,
                $recurring_count,
                $recurring_unit,
                $contact,
                $series_id,
                $recurrence_occurrences,
                $recurrence_end_date
            );

            if ( $series_total > 1 ) {
                update_post_meta( $post_id, '_band_event_recurrence_total', $series_total );
            }
        }

        return '<p class="notice notice-success is-dismissible">' . esc_html__( 'Event created successfully.', 'band-event-rsvp' ) . '</p>';
    }

    public static function get_recurrence_series_id() {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }
        return uniqid( 'band_event_recurrence_', true );
    }

    public static function get_recurrence_date_interval( $count, $unit ) {
        if ( $count < 1 ) {
            return false;
        }

        switch ( $unit ) {
            case 'days':
                return new DateInterval( 'P' . absint( $count ) . 'D' );
            case 'weeks':
                return new DateInterval( 'P' . absint( $count ) . 'W' );
            case 'months':
                return new DateInterval( 'P' . absint( $count ) . 'M' );
            default:
                return false;
        }
    }

    public static function create_recurrence_series_posts( $base_post_id, $title, $content, $location, $start, $end, $interval_count, $interval_unit, $contact, $series_id, $occurrences, $end_date ) {
        if ( 'none' === $interval_unit || $interval_count < 1 || empty( $start ) ) {
            return 1;
        }

        $max_generated_posts = 50;

        try {
            $current_start = new DateTime( $start );
        } catch ( Exception $e ) {
            return 1;
        }

        $current_end = null;
        if ( ! empty( $end ) ) {
            try {
                $current_end = new DateTime( $end );
            } catch ( Exception $e ) {
                $current_end = null;
            }
        }

        $interval = self::get_recurrence_date_interval( $interval_count, $interval_unit );
        if ( ! $interval ) {
            return 1;
        }

        $series_end_date = null;
        if ( ! empty( $end_date ) ) {
            try {
                $series_end_date = new DateTime( $end_date );
            } catch ( Exception $e ) {
                $series_end_date = null;
            }
        }

        $series_total = 1;
        $next_start = clone $current_start;
        $next_end = $current_end ? clone $current_end : null;
        $index = 2;

        while ( true ) {
            $next_start = ( clone $next_start )->add( $interval );
            if ( $current_end ) {
                $next_end = ( clone $next_end )->add( $interval );
            }

            if ( ( $series_total - 1 ) >= $max_generated_posts ) {
                break;
            }

            if ( $occurrences > 1 && $index > $occurrences ) {
                break;
            }

            if ( $series_end_date && $next_start->format( 'Y-m-d' ) > $series_end_date->format( 'Y-m-d' ) ) {
                break;
            }

            $next_post_id = wp_insert_post( array(
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_type'    => 'event',
                'meta_input'   => array(
                    '_band_event_skip_recurrence_generation' => 1,
                ),
            ) );

            if ( is_wp_error( $next_post_id ) ) {
                break;
            }

            update_post_meta( $next_post_id, '_band_event_location', $location );
            update_post_meta( $next_post_id, '_band_event_start', $next_start->format( 'Y-m-d\TH:i' ) );
            update_post_meta( $next_post_id, '_band_event_end', $next_end ? $next_end->format( 'Y-m-d\TH:i' ) : '' );
            update_post_meta( $next_post_id, '_band_event_recurring_count', $interval_count );
            update_post_meta( $next_post_id, '_band_event_recurring_unit', $interval_unit );
            update_post_meta( $next_post_id, '_band_event_contact_person', $contact );
            update_post_meta( $next_post_id, '_band_event_recurrence_id', $series_id );
            update_post_meta( $next_post_id, '_band_event_recurrence_index', $index );
            if ( ! empty( $end_date ) ) {
                update_post_meta( $next_post_id, '_band_event_recurrence_end_date', $end_date );
            }

            delete_post_meta( $next_post_id, '_band_event_skip_recurrence_generation' );

            $series_total++;
            $index++;
        }

        return $series_total;
    }

    public static function add_recurrence_row_actions( $actions, $post ) {
        if ( 'event' !== $post->post_type ) {
            return $actions;
        }

        $series_id = get_post_meta( $post->ID, '_band_event_recurrence_id', true );
        if ( empty( $series_id ) ) {
            return $actions;
        }

        $url = add_query_arg(
            array(
                'action' => 'delete_event_series',
                'post'   => $post->ID,
            ), admin_url( 'edit.php?post_type=event' )
        );
        $url = wp_nonce_url( $url, 'delete_event_series_' . $post->ID );

        $actions['delete_series'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Delete series', 'band-event-rsvp' ) . '</a>';

        return $actions;
    }

    public static function handle_recurrence_actions() {
        if ( ! isset( $_GET['action'], $_GET['post'] ) || 'delete_event_series' !== $_GET['action'] ) {
            return;
        }

        $post_id = intval( $_GET['post'] );
        if ( ! $post_id || ! current_user_can( 'delete_post', $post_id ) ) {
            return;
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'delete_event_series_' . $post_id ) ) {
            wp_die( esc_html__( 'Invalid request.', 'band-event-rsvp' ) );
        }

        $series_id = get_post_meta( $post_id, '_band_event_recurrence_id', true );
        if ( empty( $series_id ) ) {
            return;
        }

        $events = get_posts( array(
            'post_type'      => 'event',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => '_band_event_recurrence_id',
                    'value'   => $series_id,
                    'compare' => '=',
                ),
            ),
            'fields'         => 'ids',
        ) );

        foreach ( $events as $event_id ) {
            wp_delete_post( $event_id, true );
        }

        $redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=event' );
        $redirect = remove_query_arg( array( 'action', 'post', '_wpnonce' ), $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }
}
