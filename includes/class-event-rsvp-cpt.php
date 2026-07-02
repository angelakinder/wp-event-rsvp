<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Band_Event_RSVP_CPT {
    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_event_post_type' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_event_meta_boxes' ) );
        add_action( 'save_post', array( __CLASS__, 'save_event_meta' ), 10, 2 );
        add_filter( 'manage_event_posts_columns', array( __CLASS__, 'set_custom_columns' ) );
        add_action( 'manage_event_posts_custom_column', array( __CLASS__, 'custom_column' ), 10, 2 );
    }

    public static function register_event_post_type() {
        $labels = array(
            'name'               => __( 'Events', 'band-event-rsvp' ),
            'singular_name'      => __( 'Event', 'band-event-rsvp' ),
            'menu_name'          => __( 'Events', 'band-event-rsvp' ),
            'name_admin_bar'     => __( 'Event', 'band-event-rsvp' ),
            'add_new'            => __( 'Add New', 'band-event-rsvp' ),
            'add_new_item'       => __( 'Add New Event', 'band-event-rsvp' ),
            'new_item'           => __( 'New Event', 'band-event-rsvp' ),
            'edit_item'          => __( 'Edit Event', 'band-event-rsvp' ),
            'view_item'          => __( 'View Event', 'band-event-rsvp' ),
            'all_items'          => __( 'All Events', 'band-event-rsvp' ),
            'search_items'       => __( 'Search Events', 'band-event-rsvp' ),
            'parent_item_colon'  => __( 'Parent Events:', 'band-event-rsvp' ),
            'not_found'          => __( 'No events found.', 'band-event-rsvp' ),
            'not_found_in_trash' => __( 'No events found in Trash.', 'band-event-rsvp' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'show_in_menu'       => true,
            'supports'           => array( 'title', 'editor' ),
            'has_archive'        => true,
            'rewrite'            => array( 'slug' => 'rsvp' ),
            'show_in_rest'       => true,
            'capability_type'    => 'post',
            'menu_position'      => 20,
            'menu_icon'          => 'dashicons-calendar-alt',
        );

        register_post_type( 'event', $args );
    }

    public static function add_event_meta_boxes() {
        add_meta_box(
            'band_event_details',
            __( 'Event Details', 'band-event-rsvp' ),
            array( __CLASS__, 'render_event_details_meta_box' ),
            'event',
            'normal',
            'default'
        );
    }

    public static function render_event_details_meta_box( $post ) {
        wp_nonce_field( 'save_band_event_details', 'band_event_details_nonce' );

        $location = get_post_meta( $post->ID, '_band_event_location', true );
        $start     = get_post_meta( $post->ID, '_band_event_start', true );
        $end       = get_post_meta( $post->ID, '_band_event_end', true );
        $recurring_count = get_post_meta( $post->ID, '_band_event_recurring_count', true );
        $recurring_unit  = get_post_meta( $post->ID, '_band_event_recurring_unit', true );
        $recurrence_occurrences = get_post_meta( $post->ID, '_band_event_recurrence_occurrences', true );
        $recurrence_end_date = get_post_meta( $post->ID, '_band_event_recurrence_end_date', true );
        $contact   = get_post_meta( $post->ID, '_band_event_contact_person', true );

        $recurring_count = $recurring_count ? intval( $recurring_count ) : 0;
        $recurring_unit  = $recurring_unit ? $recurring_unit : 'none';
        $recurrence_occurrences = $recurrence_occurrences ? intval( $recurrence_occurrences ) : 0;
        $contact_options = self::get_contact_person_options();
        ?>
        <p>
            <label for="band_event_location"><?php esc_html_e( 'Location', 'band-event-rsvp' ); ?></label><br />
            <input type="text" id="band_event_location" name="band_event_location" value="<?php echo esc_attr( $location ); ?>" class="widefat" />
        </p>
        <?php
        $start_date = '';
        $start_time = '';
        if ( $start ) {
            $start_dt = date_create( $start );
            if ( $start_dt ) {
                $start_date = $start_dt->format( 'Y-m-d' );
                $start_time = $start_dt->format( 'H:i' );
            }
        } else {
            $start_date = current_time( 'Y-m-d' );
            $start_time = current_time( 'H:i' );
        }

        $end_date = '';
        $end_time = '';
        if ( $end ) {
            $end_dt = date_create( $end );
            if ( $end_dt ) {
                $end_date = $end_dt->format( 'Y-m-d' );
                $end_time = $end_dt->format( 'H:i' );
            }
        } else {
            $end_date = current_time( 'Y-m-d' );
            $end_time = current_time( 'H:i' );
        }
        ?>
        <p>
            <label for="band_event_start_date"><?php esc_html_e( 'Start Date', 'band-event-rsvp' ); ?></label><br />
            <input type="date" id="band_event_start_date" name="band_event_start_date" value="<?php echo esc_attr( $start_date ); ?>" class="widefat" />
        </p>
        <p>
            <label for="band_event_start_time"><?php esc_html_e( 'Start Time', 'band-event-rsvp' ); ?></label><br />
            <input type="time" id="band_event_start_time" name="band_event_start_time" value="<?php echo esc_attr( $start_time ); ?>" class="widefat" />
        </p>
        <p>
            <label for="band_event_end_date"><?php esc_html_e( 'End Date', 'band-event-rsvp' ); ?></label><br />
            <input type="date" id="band_event_end_date" name="band_event_end_date" value="<?php echo esc_attr( $end_date ); ?>" class="widefat" />
        </p>
        <p>
            <label for="band_event_end_time"><?php esc_html_e( 'End Time', 'band-event-rsvp' ); ?></label><br />
            <input type="time" id="band_event_end_time" name="band_event_end_time" value="<?php echo esc_attr( $end_time ); ?>" class="widefat" />
        </p>
        <p>
            <label for="band_event_recurring_count"><?php esc_html_e( 'Recurring every', 'band-event-rsvp' ); ?></label><br />
            <input type="number" id="band_event_recurring_count" name="band_event_recurring_count" value="<?php echo esc_attr( $recurring_count ); ?>" min="0" class="small-text" />
            <select id="band_event_recurring_unit" name="band_event_recurring_unit">
                <option value="none" <?php selected( $recurring_unit, 'none' ); ?>><?php esc_html_e( 'None', 'band-event-rsvp' ); ?></option>
                <option value="days" <?php selected( $recurring_unit, 'days' ); ?>><?php esc_html_e( 'Days', 'band-event-rsvp' ); ?></option>
                <option value="weeks" <?php selected( $recurring_unit, 'weeks' ); ?>><?php esc_html_e( 'Weeks', 'band-event-rsvp' ); ?></option>
                <option value="months" <?php selected( $recurring_unit, 'months' ); ?>><?php esc_html_e( 'Months', 'band-event-rsvp' ); ?></option>
            </select>
        </p>
        <p>
            <label for="band_event_recurrence_occurrences"><?php esc_html_e( 'Number of occurrences', 'band-event-rsvp' ); ?></label><br />
            <input type="number" id="band_event_recurrence_occurrences" name="band_event_recurrence_occurrences" value="<?php echo esc_attr( $recurrence_occurrences ); ?>" min="0" class="small-text" />
        </p>
        <p>
            <label for="band_event_recurrence_end_date"><?php esc_html_e( 'Or end date', 'band-event-rsvp' ); ?></label><br />
            <input type="date" id="band_event_recurrence_end_date" name="band_event_recurrence_end_date" value="<?php echo esc_attr( $recurrence_end_date ); ?>" class="widefat" />
        </p>
        <p>
            <label for="band_event_contact_person"><?php esc_html_e( 'Contact Person', 'band-event-rsvp' ); ?></label><br />
            <?php if ( ! empty( $contact_options ) ) : ?>
                <select id="band_event_contact_person" name="band_event_contact_person" class="widefat">
                    <option value=""><?php esc_html_e( 'Select a member', 'band-event-rsvp' ); ?></option>
                    <?php
                    if ( ! empty( $contact ) && ! isset( $contact_options[ $contact ] ) ) {
                        echo '<option value="' . esc_attr( $contact ) . '" selected="selected">' . esc_html( $contact ) . '</option>';
                    }
                    foreach ( $contact_options as $contact_value => $contact_label ) :
                        ?>
                        <option value="<?php echo esc_attr( $contact_value ); ?>" <?php selected( $contact, $contact_value ); ?>><?php echo esc_html( $contact_label ); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else : ?>
                <input type="text" id="band_event_contact_person" name="band_event_contact_person" value="<?php echo esc_attr( $contact ); ?>" class="widefat" />
            <?php endif; ?>
        </p>
        <?php
        $invited_levels = self::get_invited_membership_levels( $post->ID );
        $available_levels = self::get_available_membership_levels();
        if ( ! empty( $available_levels ) ) : ?>
            <p>
                <label for="band_event_invited_levels"><?php esc_html_e( 'Invite membership levels', 'band-event-rsvp' ); ?></label><br />
                <select id="band_event_invited_levels" name="band_event_invited_levels[]" class="widefat" multiple size="4">
                    <?php foreach ( $available_levels as $level_id => $level_label ) : ?>
                        <option value="<?php echo esc_attr( $level_id ); ?>" <?php selected( in_array( $level_id, $invited_levels, true ) ); ?>><?php echo esc_html( $level_label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="description"><?php esc_html_e( 'Select the Simple Membership levels invited to this event. Leave blank to invite all members.', 'band-event-rsvp' ); ?></span>
            </p>
        <?php elseif ( class_exists( 'SwpmMembershipLevelUtils' ) ) : ?>
            <p class="description"><?php esc_html_e( 'Create Simple Membership levels first to invite members to this event.', 'band-event-rsvp' ); ?></p>
        <?php else : ?>
            <p class="description"><?php esc_html_e( 'Simple Membership is not active. Install and activate Simple Membership to invite members by level.', 'band-event-rsvp' ); ?></p>
        <?php endif; ?>
        <?php
    }

    public static function get_available_membership_levels() {
        if ( ! class_exists( 'SwpmMembershipLevelUtils' ) ) {
            return array();
        }

        return SwpmMembershipLevelUtils::get_all_membership_levels_in_array();
    }

    public static function get_contact_person_options() {
        if ( ! class_exists( 'SwpmMemberUtils' ) ) {
            return array();
        }

        global $wpdb;
        $query = "SELECT user_name, first_name, last_name, email FROM {$wpdb->prefix}swpm_members_tbl ORDER BY first_name ASC, last_name ASC, user_name ASC";
        $rows = $wpdb->get_results( $query );
        if ( ! is_array( $rows ) ) {
            return array();
        }

        $options = array();
        foreach ( $rows as $row ) {
            $full_name = trim( (string) $row->first_name . ' ' . (string) $row->last_name );
            $fallback = ! empty( $row->user_name ) ? (string) $row->user_name : (string) $row->email;
            $label = ! empty( $full_name ) ? $full_name : $fallback;
            if ( empty( $label ) ) {
                continue;
            }
            $options[ $label ] = $label;
        }

        return $options;
    }

    public static function get_invited_membership_levels( $post_id ) {
        $levels = get_post_meta( $post_id, '_band_event_invited_levels', true );
        if ( ! is_array( $levels ) ) {
            return array();
        }
        return array_values( array_filter( array_map( 'absint', $levels ) ) );
    }

    public static function get_all_member_user_ids() {
        if ( class_exists( 'SwpmMemberUtils' ) ) {
            global $wpdb;
            $query = "SELECT member_id FROM {$wpdb->prefix}swpm_members_tbl";
            $members = $wpdb->get_results( $query );
            if ( is_array( $members ) ) {
                return array_values( array_filter( array_map( function( $row ) {
                    return isset( $row->member_id ) ? intval( $row->member_id ) : 0;
                }, $members ) ) );
            }
        }

        $users = get_users( array( 'fields' => 'ID' ) );
        return is_array( $users ) ? array_map( 'intval', $users ) : array();
    }

    public static function get_invited_member_user_ids( $post_id ) {
        $levels = self::get_invited_membership_levels( $post_id );
        $invited_ids = array();

        if ( class_exists( 'SwpmMemberUtils' ) && ! empty( $levels ) ) {
            foreach ( $levels as $level_id ) {
                $members = SwpmMemberUtils::get_all_members_of_a_level( $level_id );
                if ( ! is_array( $members ) ) {
                    continue;
                }
                foreach ( $members as $member ) {
                    $user = null;
                    if ( ! empty( $member->user_name ) ) {
                        $user = get_user_by( 'login', $member->user_name );
                    }
                    if ( ! $user && ! empty( $member->email ) ) {
                        $user = get_user_by( 'email', $member->email );
                    }
                    if ( $user ) {
                        $invited_ids[] = intval( $user->ID );
                    }
                }
            }
        }

        if ( empty( $invited_ids ) ) {
            return self::get_all_member_user_ids();
        }

        return array_values( array_unique( $invited_ids ) );
    }

    public static function save_event_meta( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! isset( $_POST['band_event_details_nonce'] ) || ! wp_verify_nonce( $_POST['band_event_details_nonce'], 'save_band_event_details' ) ) {
            return;
        }

        if ( $post->post_type !== 'event' ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $location = isset( $_POST['band_event_location'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_location'] ) ) : '';
        $start_date = isset( $_POST['band_event_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_start_date'] ) ) : '';
        $start_time = isset( $_POST['band_event_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_start_time'] ) ) : '';
        $end_date = isset( $_POST['band_event_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_end_date'] ) ) : '';
        $end_time = isset( $_POST['band_event_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_end_time'] ) ) : '';
        $start = '';
        $end = '';

        if ( $start_date ) {
            $start = $start_date . ' ' . ( $start_time ? $start_time : '00:00' );
        } elseif ( isset( $_POST['band_event_start'] ) ) {
            $start = sanitize_text_field( wp_unslash( $_POST['band_event_start'] ) );
        }

        if ( $end_date ) {
            $end = $end_date . ' ' . ( $end_time ? $end_time : '00:00' );
        } elseif ( isset( $_POST['band_event_end'] ) ) {
            $end = sanitize_text_field( wp_unslash( $_POST['band_event_end'] ) );
        }

        $recurring_count = isset( $_POST['band_event_recurring_count'] ) ? intval( $_POST['band_event_recurring_count'] ) : 0;
        $recurring_unit  = isset( $_POST['band_event_recurring_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_recurring_unit'] ) ) : 'none';
        $recurrence_occurrences = isset( $_POST['band_event_recurrence_occurrences'] ) ? intval( $_POST['band_event_recurrence_occurrences'] ) : 0;
        $recurrence_end_date = isset( $_POST['band_event_recurrence_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_recurrence_end_date'] ) ) : '';
        $contact   = isset( $_POST['band_event_contact_person'] ) ? sanitize_text_field( wp_unslash( $_POST['band_event_contact_person'] ) ) : '';

        update_post_meta( $post_id, '_band_event_location', $location );
        update_post_meta( $post_id, '_band_event_start', $start );
        update_post_meta( $post_id, '_band_event_end', $end );
        update_post_meta( $post_id, '_band_event_recurring_count', $recurring_count );
        update_post_meta( $post_id, '_band_event_recurring_unit', $recurring_unit );
        update_post_meta( $post_id, '_band_event_recurrence_occurrences', $recurrence_occurrences );
        if ( ! empty( $recurrence_end_date ) ) {
            update_post_meta( $post_id, '_band_event_recurrence_end_date', $recurrence_end_date );
        } else {
            delete_post_meta( $post_id, '_band_event_recurrence_end_date' );
        }
        update_post_meta( $post_id, '_band_event_contact_person', $contact );

        // Create recurrence series only once for the base event when recurrence is configured.
        if ( class_exists( 'Band_Event_RSVP_Admin' )
            && 'none' !== $recurring_unit
            && $recurring_count > 0
            && ! empty( $start )
            && ( $recurrence_occurrences > 1 || ! empty( $recurrence_end_date ) )
            && ! get_post_meta( $post_id, '_band_event_skip_recurrence_generation', true )
            && ! get_post_meta( $post_id, '_band_event_recurrence_id', true )
        ) {
            $series_id = Band_Event_RSVP_Admin::get_recurrence_series_id();
            update_post_meta( $post_id, '_band_event_recurrence_id', $series_id );
            update_post_meta( $post_id, '_band_event_recurrence_index', 1 );

            $series_total = Band_Event_RSVP_Admin::create_recurrence_series_posts(
                $post_id,
                $post->post_title,
                $post->post_content,
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

        $invited_levels = isset( $_POST['band_event_invited_levels'] ) && is_array( $_POST['band_event_invited_levels'] ) ? array_map( 'absint', $_POST['band_event_invited_levels'] ) : array();
        if ( ! empty( $invited_levels ) ) {
            update_post_meta( $post_id, '_band_event_invited_levels', $invited_levels );
        } else {
            delete_post_meta( $post_id, '_band_event_invited_levels' );
        }
    }

    public static function set_custom_columns( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $label ) {
            if ( $key === 'title' ) {
                $new_columns['title'] = $label;
                $new_columns['event_date'] = __( 'Start', 'band-event-rsvp' );
                $new_columns['event_location'] = __( 'Location', 'band-event-rsvp' );
                $new_columns['event_recurrence'] = __( 'Recurrence', 'band-event-rsvp' );
            } else {
                $new_columns[ $key ] = $label;
            }
        }
        return $new_columns;
    }

    public static function custom_column( $column, $post_id ) {
        switch ( $column ) {
            case 'event_date':
                $start = get_post_meta( $post_id, '_band_event_start', true );
                echo esc_html( $start ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $start ) ) : '—' );
                break;
            case 'event_location':
                $location = get_post_meta( $post_id, '_band_event_location', true );
                echo esc_html( $location ? $location : '—' );
                break;
            case 'event_recurrence':
                $series_id = get_post_meta( $post_id, '_band_event_recurrence_id', true );
                $index = get_post_meta( $post_id, '_band_event_recurrence_index', true );
                $total = get_post_meta( $post_id, '_band_event_recurrence_total', true );
                $count = get_post_meta( $post_id, '_band_event_recurring_count', true );
                $unit = get_post_meta( $post_id, '_band_event_recurring_unit', true );

                if ( $series_id ) {
                    if ( $index && $total ) {
                        echo esc_html( sprintf( __( 'Series %d/%d', 'band-event-rsvp' ), intval( $index ), intval( $total ) ) );
                    } elseif ( $index ) {
                        echo esc_html( sprintf( __( 'Series %d', 'band-event-rsvp' ), intval( $index ) ) );
                    } else {
                        echo esc_html__( 'Series', 'band-event-rsvp' );
                    }
                } elseif ( $count > 0 && $unit && 'none' !== $unit ) {
                    echo esc_html( sprintf( __( 'Repeats every %d %s', 'band-event-rsvp' ), intval( $count ), $unit ) );
                } else {
                    echo '—';
                }
                break;
        }
    }

    public static function get_event_fields( $post_id ) {
        return array(
            'location'          => get_post_meta( $post_id, '_band_event_location', true ),
            'start'             => get_post_meta( $post_id, '_band_event_start', true ),
            'end'               => get_post_meta( $post_id, '_band_event_end', true ),
            'recurring_count'   => get_post_meta( $post_id, '_band_event_recurring_count', true ),
            'recurring_unit'    => get_post_meta( $post_id, '_band_event_recurring_unit', true ),
            'contact_person'    => get_post_meta( $post_id, '_band_event_contact_person', true ),
            'recurrence_id'     => get_post_meta( $post_id, '_band_event_recurrence_id', true ),
            'recurrence_index'  => get_post_meta( $post_id, '_band_event_recurrence_index', true ),
            'recurrence_total'  => get_post_meta( $post_id, '_band_event_recurrence_total', true ),
            'recurrence_end_date' => get_post_meta( $post_id, '_band_event_recurrence_end_date', true ),
        );
    }
}
