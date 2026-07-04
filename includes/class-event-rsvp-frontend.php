<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Band_Event_RSVP_Frontend {
    protected static $current_loop_event_id = 0;
    protected static $current_block_context_event_id = 0;
    protected static $last_event_query_post_ids = array();
    protected static $contextless_sequence_index = -1;
    protected static $contextless_current_event_id = 0;

    public static function init() {
        add_shortcode( 'band_event_list', array( __CLASS__, 'render_event_list' ) );
        add_shortcode( 'band_event_detail', array( __CLASS__, 'render_event_detail' ) );
        add_shortcode( 'band_event_actions', array( __CLASS__, 'render_event_actions' ) );
        add_shortcode( 'band_event_start_datetime', array( __CLASS__, 'render_event_start_datetime_shortcode' ) );
        add_shortcode( 'band_event_end_datetime', array( __CLASS__, 'render_event_end_datetime_shortcode' ) );
        add_shortcode( 'band_event_member_levels', array( __CLASS__, 'render_event_member_levels_shortcode' ) );
        add_shortcode( 'band_event_calendar_button', array( __CLASS__, 'render_event_calendar_button_shortcode' ) );
        add_shortcode( 'band_event_admin_tools', array( __CLASS__, 'render_event_admin_tools_shortcode' ) );
        add_shortcode( 'band_event_contact_name', array( __CLASS__, 'render_event_contact_name_shortcode' ) );
        add_shortcode( 'band_event_rsvp_buttons', array( __CLASS__, 'render_event_rsvp_buttons_shortcode' ) );
        add_shortcode( 'band_event_attendee_dropdowns', array( __CLASS__, 'render_event_attendee_dropdowns_shortcode' ) );
        add_action( 'wp', array( __CLASS__, 'handle_rsvp_submission' ) );
        add_action( 'the_post', array( __CLASS__, 'capture_current_loop_event_id' ) );
        add_action( 'template_redirect', array( __CLASS__, 'handle_calendar_download' ) );
        add_action( 'template_redirect', array( __CLASS__, 'enforce_single_event_access' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'filter_event_archive_query' ) );
        add_filter( 'the_posts', array( __CLASS__, 'filter_event_posts_by_invitation' ), 10, 2 );
        add_filter( 'the_content', array( __CLASS__, 'render_single_event_content' ) );
        add_action( 'init', array( __CLASS__, 'ensure_shortcode_block_uses_post_context' ), 20 );
        add_filter( 'render_block_context', array( __CLASS__, 'capture_event_id_from_render_block_context' ), 10, 3 );
        add_filter( 'pre_render_block', array( __CLASS__, 'pre_render_shortcodes_in_query_loop_context' ), 10, 3 );
        add_filter( 'render_block_core/shortcode', array( __CLASS__, 'render_shortcode_block_with_query_loop_context' ), 10, 3 );
        add_filter( 'render_block_core/post-template', array( __CLASS__, 'fix_contextless_event_datetime_output' ), 20, 3 );
        add_filter( 'render_block_core/query', array( __CLASS__, 'fix_query_loop_event_datetime_output' ), 20, 3 );
    }

    protected static function get_event_ids_from_query_block_attrs( $parsed_block ) {
        if ( ! is_array( $parsed_block ) || empty( $parsed_block['attrs']['query'] ) || ! is_array( $parsed_block['attrs']['query'] ) ) {
            return array();
        }

        $query = $parsed_block['attrs']['query'];
        $post_type = isset( $query['postType'] ) ? sanitize_key( (string) $query['postType'] ) : 'post';
        if ( 'event' !== $post_type ) {
            return array();
        }

        $order = isset( $query['order'] ) ? strtoupper( sanitize_text_field( (string) $query['order'] ) ) : 'DESC';
        if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
            $order = 'DESC';
        }

        $orderby = isset( $query['orderBy'] ) ? sanitize_key( (string) $query['orderBy'] ) : 'date';
        $allowed_orderby = array( 'date', 'title', 'modified', 'menu_order', 'rand', 'meta_value', 'meta_value_num' );
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
            $orderby = 'date';
        }

        $per_page = isset( $query['perPage'] ) ? intval( $query['perPage'] ) : 10;
        if ( 0 === $per_page ) {
            $per_page = 10;
        }

        $offset = isset( $query['offset'] ) ? max( 0, intval( $query['offset'] ) ) : 0;

        $query_args = array(
            'post_type'              => 'event',
            'post_status'            => 'publish',
            'fields'                 => 'ids',
            'posts_per_page'         => $per_page,
            'offset'                 => $offset,
            'orderby'                => $orderby,
            'order'                  => $order,
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        if ( isset( $query['exclude'] ) && is_array( $query['exclude'] ) && ! empty( $query['exclude'] ) ) {
            $query_args['post__not_in'] = array_values( array_filter( array_map( 'absint', $query['exclude'] ) ) );
        }

        if ( isset( $query['author'] ) && '' !== (string) $query['author'] ) {
            $query_args['author'] = intval( $query['author'] );
        }

        $event_query = new WP_Query( $query_args );
        if ( empty( $event_query->posts ) || ! is_array( $event_query->posts ) ) {
            return array();
        }

        $event_ids = array();
        foreach ( $event_query->posts as $event_id ) {
            $event_id = absint( $event_id );
            if ( $event_id > 0 && self::can_current_user_view_event( $event_id ) ) {
                $event_ids[] = $event_id;
            }
        }

        return array_values( array_unique( $event_ids ) );
    }

    protected static function rewrite_event_datetime_markup_by_ids( $block_content, $event_ids ) {
        if ( ! is_string( $block_content ) || '' === $block_content || empty( $event_ids ) ) {
            return $block_content;
        }

        $row_index = -1;
        $pattern = '/<span class="band-event-(start|end)-datetime">.*?<\/span>(?:<span class="band-event-shortcode-debug"[^>]*data-shortcode="band_event_(start|end)_datetime"[^>]*><\/span>)?/s';

        $rewritten = preg_replace_callback(
            $pattern,
            function ( $matches ) use ( $event_ids, &$row_index ) {
                $type = isset( $matches[1] ) ? (string) $matches[1] : '';

                if ( 'start' === $type ) {
                    $row_index++;
                }

                if ( $row_index < 0 ) {
                    $row_index = 0;
                }

                $max_index = count( $event_ids ) - 1;
                if ( $row_index > $max_index ) {
                    $row_index = $max_index;
                }

                $post_id = absint( $event_ids[ $row_index ] );
                $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );

                if ( 'start' === $type ) {
                    $formatted = Band_Event_RSVP_Frontend::format_event_datetime_human( $fields['start'] );
                    return '<span class="band-event-start-datetime">' . esc_html( $formatted ) . '</span>' . Band_Event_RSVP_Frontend::get_shortcode_debug_marker( 'band_event_start_datetime', $post_id );
                }

                $formatted = Band_Event_RSVP_Frontend::format_event_datetime_human( $fields['end'] );
                return '<span class="band-event-end-datetime">' . esc_html( $formatted ) . '</span>' . Band_Event_RSVP_Frontend::get_shortcode_debug_marker( 'band_event_end_datetime', $post_id );
            },
            $block_content
        );

        return is_string( $rewritten ) ? $rewritten : $block_content;
    }

    public static function fix_contextless_event_datetime_output( $block_content, $parsed_block, $instance ) {
        if ( ! is_string( $block_content ) || '' === $block_content ) {
            return $block_content;
        }

        if ( false === strpos( $block_content, 'band-event-start-datetime' ) && false === strpos( $block_content, 'band-event-end-datetime' ) ) {
            return $block_content;
        }

        if ( empty( self::$last_event_query_post_ids ) || ! is_array( self::$last_event_query_post_ids ) ) {
            return $block_content;
        }

        $event_ids = array_values( array_filter( array_map( 'absint', self::$last_event_query_post_ids ) ) );
        if ( count( $event_ids ) <= 1 ) {
            return $block_content;
        }

        return self::rewrite_event_datetime_markup_by_ids( $block_content, $event_ids );
    }

    public static function fix_query_loop_event_datetime_output( $block_content, $parsed_block, $instance ) {
        if ( ! is_string( $block_content ) || '' === $block_content ) {
            return $block_content;
        }

        if ( false === strpos( $block_content, 'band-event-start-datetime' ) && false === strpos( $block_content, 'band-event-end-datetime' ) ) {
            return $block_content;
        }

        $event_ids = self::get_event_ids_from_query_block_attrs( $parsed_block );
        if ( empty( $event_ids ) ) {
            return $block_content;
        }

        self::$last_event_query_post_ids = $event_ids;
        self::$contextless_sequence_index = -1;
        self::$contextless_current_event_id = 0;

        return self::rewrite_event_datetime_markup_by_ids( $block_content, $event_ids );
    }

    public static function capture_event_id_from_render_block_context( $context, $parsed_block, $parent_block ) {
        if ( ! is_array( $context ) || ! isset( $context['postId'] ) ) {
            return $context;
        }

        $context_post_id = absint( $context['postId'] );
        if ( $context_post_id > 0 && 'event' === get_post_type( $context_post_id ) ) {
            self::$current_block_context_event_id = $context_post_id;
        }

        return $context;
    }

    protected static function get_event_id_from_wp_query_cursor() {
        global $wp_query;
        if ( ! ( $wp_query instanceof WP_Query ) ) {
            return 0;
        }

        if ( ! isset( $wp_query->current_post, $wp_query->posts ) || ! is_array( $wp_query->posts ) ) {
            return 0;
        }

        $loop_index = intval( $wp_query->current_post );
        if ( $loop_index < 0 || ! isset( $wp_query->posts[ $loop_index ] ) || ! ( $wp_query->posts[ $loop_index ] instanceof WP_Post ) ) {
            return 0;
        }

        $candidate_id = absint( $wp_query->posts[ $loop_index ]->ID );
        if ( $candidate_id <= 0 || 'event' !== get_post_type( $candidate_id ) ) {
            return 0;
        }

        return $candidate_id;
    }

    protected static function get_event_id_from_contextless_sequence( $shortcode_tag ) {
        if ( empty( self::$last_event_query_post_ids ) || ! is_array( self::$last_event_query_post_ids ) ) {
            return 0;
        }

        $is_row_starter = in_array(
            (string) $shortcode_tag,
            array( 'band_event_start_datetime', 'band_event_detail', 'band_event_actions' ),
            true
        );

        if ( $is_row_starter || self::$contextless_current_event_id <= 0 ) {
            self::$contextless_sequence_index++;
            if ( self::$contextless_sequence_index < 0 ) {
                self::$contextless_sequence_index = 0;
            }

            $max_index = count( self::$last_event_query_post_ids ) - 1;
            if ( self::$contextless_sequence_index > $max_index ) {
                self::$contextless_sequence_index = $max_index;
            }

            $candidate_id = absint( self::$last_event_query_post_ids[ self::$contextless_sequence_index ] );
            if ( $candidate_id > 0 && 'event' === get_post_type( $candidate_id ) ) {
                self::$contextless_current_event_id = $candidate_id;
            }
        }

        if ( self::$contextless_current_event_id > 0 && 'event' === get_post_type( self::$contextless_current_event_id ) ) {
            return self::$contextless_current_event_id;
        }

        return 0;
    }

    public static function ensure_shortcode_block_uses_post_context() {
        if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
            return;
        }

        $registry = WP_Block_Type_Registry::get_instance();
        $shortcode_block = $registry->get_registered( 'core/shortcode' );
        if ( ! $shortcode_block ) {
            return;
        }

        $uses_context = array();
        if ( isset( $shortcode_block->uses_context ) && is_array( $shortcode_block->uses_context ) ) {
            $uses_context = $shortcode_block->uses_context;
        }

        if ( ! in_array( 'postId', $uses_context, true ) ) {
            $uses_context[] = 'postId';
        }
        if ( ! in_array( 'postType', $uses_context, true ) ) {
            $uses_context[] = 'postType';
        }

        $shortcode_block->uses_context = array_values( array_unique( $uses_context ) );
    }

    public static function pre_render_shortcodes_in_query_loop_context( $pre_render, $parsed_block, $parent_block ) {
        if ( null !== $pre_render || ! is_array( $parsed_block ) ) {
            return $pre_render;
        }

        if ( empty( $parsed_block['blockName'] ) || 'core/shortcode' !== $parsed_block['blockName'] ) {
            return $pre_render;
        }

        $raw_content = self::get_shortcode_block_raw_content( $parsed_block );
        if ( '' === $raw_content || false === strpos( $raw_content, '[band_event_' ) ) {
            return $pre_render;
        }

        $context_post_id = self::extract_event_id_from_block_context( $parent_block );
        return self::render_band_event_shortcodes_with_context( $raw_content, $context_post_id );
    }

    public static function render_shortcode_block_with_query_loop_context( $block_content, $parsed_block, $instance ) {
        if ( ! is_array( $parsed_block ) ) {
            return $block_content;
        }

        $raw_content = self::get_shortcode_block_raw_content( $parsed_block );
        if ( '' === $raw_content || false === strpos( $raw_content, '[band_event_' ) ) {
            return $block_content;
        }

        $context_post_id = self::extract_event_id_from_block_context( $instance );
        return self::render_band_event_shortcodes_with_context( $raw_content, $context_post_id );
    }

    protected static function get_shortcode_block_raw_content( $parsed_block ) {
        if ( ! is_array( $parsed_block ) ) {
            return '';
        }

        if ( isset( $parsed_block['attrs']['text'] ) && is_scalar( $parsed_block['attrs']['text'] ) ) {
            return (string) $parsed_block['attrs']['text'];
        }

        if ( isset( $parsed_block['innerHTML'] ) && is_string( $parsed_block['innerHTML'] ) && '' !== $parsed_block['innerHTML'] ) {
            return $parsed_block['innerHTML'];
        }

        if ( isset( $parsed_block['innerContent'] ) && is_array( $parsed_block['innerContent'] ) ) {
            $fragments = array_filter( $parsed_block['innerContent'], 'is_string' );
            return implode( '', $fragments );
        }

        return '';
    }

    protected static function extract_event_id_from_block_context( $block_instance ) {
        if ( ! is_object( $block_instance ) || ! isset( $block_instance->context['postId'] ) ) {
            return 0;
        }

        $context_post_id = absint( $block_instance->context['postId'] );
        if ( $context_post_id <= 0 || 'event' !== get_post_type( $context_post_id ) ) {
            return 0;
        }

        return $context_post_id;
    }

    protected static function render_band_event_shortcodes_with_context( $raw_content, $context_post_id ) {
        $shortcode_content = (string) $raw_content;
        if ( $context_post_id > 0 ) {
            $shortcode_content = preg_replace_callback(
                '/\[(band_event_[a-z0-9_]+)([^\]]*)\]/i',
                function ( $matches ) use ( $context_post_id ) {
                    $tag = isset( $matches[1] ) ? (string) $matches[1] : '';
                    $attrs = isset( $matches[2] ) ? (string) $matches[2] : '';

                    if ( '' === $tag ) {
                        return '[' . $tag . $attrs . ']';
                    }

                    if ( preg_match( '/\b(?:id|post_id|event_id)\s*=\s*/i', $attrs ) ) {
                        return '[' . $tag . $attrs . ']';
                    }

                    $trimmed_attrs = rtrim( $attrs );
                    $self_closing = '';
                    if ( '' !== $trimmed_attrs && '/' === substr( $trimmed_attrs, -1 ) ) {
                        $trimmed_attrs = rtrim( substr( $trimmed_attrs, 0, -1 ) );
                        $self_closing = ' /';
                    }

                    $prefix = '' === $trimmed_attrs ? ' ' : ' ';
                    return '[' . $tag . $trimmed_attrs . $prefix . 'id="' . intval( $context_post_id ) . '"' . $self_closing . ']';
                },
                $shortcode_content
            );
        }

        $debug_marker = sprintf(
            '<span class="band-event-debug-marker" data-band-event-context-post-id="%d" style="display:none !important;"></span>',
            intval( $context_post_id )
        );

        $previous_context_event_id = self::$current_block_context_event_id;
        $previous_global_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

        if ( $context_post_id > 0 ) {
            self::$current_block_context_event_id = $context_post_id;
            $context_post = get_post( $context_post_id );
            if ( $context_post instanceof WP_Post ) {
                $GLOBALS['post'] = $context_post;
            }
        }

        $rendered = do_shortcode( shortcode_unautop( $shortcode_content ) );

        if ( $previous_global_post instanceof WP_Post ) {
            $GLOBALS['post'] = $previous_global_post;
        } elseif ( array_key_exists( 'post', $GLOBALS ) ) {
            unset( $GLOBALS['post'] );
        }

        self::$current_block_context_event_id = $previous_context_event_id;

        return $rendered . $debug_marker;
    }

    protected static function should_render_shortcode_debug() {
        return isset( $_GET['band_event_debug'] ) && '1' === (string) wp_unslash( $_GET['band_event_debug'] );
    }

    protected static function get_shortcode_debug_marker( $shortcode_tag, $post_id ) {
        if ( ! self::should_render_shortcode_debug() ) {
            return '';
        }

        $global_post_id = 0;
        $global_post_type = '';
        $contextless_current_event_id = absint( self::$contextless_current_event_id );
        $contextless_sequence_index = intval( self::$contextless_sequence_index );
        if ( isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post ) {
            $global_post_id = intval( $GLOBALS['post']->ID );
            $global_post_type = (string) $GLOBALS['post']->post_type;
        }

        return sprintf(
            '<span class="band-event-shortcode-debug" data-shortcode="%s" data-post-id="%d" data-block-context-post-id="%d" data-loop-event-id="%d" data-query-cursor-event-id="%d" data-contextless-current-event-id="%d" data-contextless-sequence-index="%d" data-global-post-id="%d" data-global-post-type="%s" style="display:none !important;"></span>',
            esc_attr( (string) $shortcode_tag ),
            intval( $post_id ),
            intval( self::$current_block_context_event_id ),
            intval( self::$current_loop_event_id ),
            intval( self::get_event_id_from_wp_query_cursor() ),
            $contextless_current_event_id,
            $contextless_sequence_index,
            intval( $global_post_id ),
            esc_attr( $global_post_type )
        );
    }

    public static function capture_current_loop_event_id( $post ) {
        if ( $post instanceof WP_Post && 'event' === $post->post_type ) {
            self::$current_loop_event_id = absint( $post->ID );
            return;
        }

        self::$current_loop_event_id = 0;
    }

    public static function enforce_single_event_access() {
        if ( ! is_singular( 'event' ) ) {
            return;
        }

        $post_id = get_queried_object_id();
        if ( $post_id <= 0 ) {
            return;
        }

        if ( self::can_current_user_view_event( $post_id ) ) {
            return;
        }

        wp_die( esc_html__( 'You are not invited to this event.', 'band-event-rsvp' ), esc_html__( 'Access denied', 'band-event-rsvp' ), array( 'response' => 403 ) );
    }

    public static function get_calendar_download_url( $post_id ) {
        return add_query_arg(
            array(
                'band_event_calendar' => intval( $post_id ),
                '_wpnonce'            => wp_create_nonce( 'band_event_calendar_' . intval( $post_id ) ),
            ),
            home_url( '/' )
        );
    }

    public static function render_add_to_calendar_button( $post_id ) {
        $url = self::get_calendar_download_url( $post_id );
        return '<div class="band-event-calendar"><a class="band-event-calendar-link" href="' . esc_url( $url ) . '">' . esc_html__( 'Add to Calendar', 'band-event-rsvp' ) . '</a></div>';
    }

    public static function escape_ics_text( $text ) {
        $text = str_replace( array( "\\", ";", ",", "\r\n", "\n", "\r" ), array( "\\\\", "\\;", "\\,", "\\n", "\\n", "\\n" ), (string) $text );
        return $text;
    }

    public static function handle_calendar_download() {
        if ( ! isset( $_GET['band_event_calendar'] ) ) {
            return;
        }

        $post_id = absint( wp_unslash( $_GET['band_event_calendar'] ) );
        if ( ! $post_id ) {
            wp_die( esc_html__( 'Invalid event.', 'band-event-rsvp' ) );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'band_event_calendar_' . $post_id ) ) {
            wp_die( esc_html__( 'Invalid request.', 'band-event-rsvp' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'event' !== $post->post_type ) {
            wp_die( esc_html__( 'Event not found.', 'band-event-rsvp' ) );
        }

        $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
        $start_raw = ! empty( $fields['start'] ) ? (string) $fields['start'] : '';
        $end_raw = ! empty( $fields['end'] ) ? (string) $fields['end'] : '';
        if ( '' === $start_raw ) {
            wp_die( esc_html__( 'Event start date is missing.', 'band-event-rsvp' ) );
        }

        try {
            $tz = wp_timezone();
            $start_dt = new DateTimeImmutable( $start_raw, $tz );
            $end_dt = '' !== $end_raw ? new DateTimeImmutable( $end_raw, $tz ) : $start_dt->modify( '+1 hour' );
            if ( $end_dt <= $start_dt ) {
                $end_dt = $start_dt->modify( '+1 hour' );
            }
        } catch ( Exception $e ) {
            wp_die( esc_html__( 'Event date is invalid.', 'band-event-rsvp' ) );
        }

        $start_utc = $start_dt->setTimezone( new DateTimeZone( 'UTC' ) );
        $end_utc = $end_dt->setTimezone( new DateTimeZone( 'UTC' ) );

        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        $uid = 'band-event-' . $post_id . '@' . ( $host ? $host : 'localhost' );

        $summary = self::escape_ics_text( get_the_title( $post_id ) );
        $description = self::escape_ics_text( wp_strip_all_tags( $post->post_content ) );
        $location = self::escape_ics_text( (string) $fields['location'] );
        $url = esc_url_raw( get_permalink( $post_id ) );
        $dtstamp = gmdate( 'Ymd\THis\Z' );

        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Simple RSVP//Event Export//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= 'UID:' . $uid . "\r\n";
        $ics .= 'DTSTAMP:' . $dtstamp . "\r\n";
        $ics .= 'DTSTART:' . $start_utc->format( 'Ymd\THis\Z' ) . "\r\n";
        $ics .= 'DTEND:' . $end_utc->format( 'Ymd\THis\Z' ) . "\r\n";
        $ics .= 'SUMMARY:' . $summary . "\r\n";
        if ( '' !== $description ) {
            $ics .= 'DESCRIPTION:' . $description . "\r\n";
        }
        if ( '' !== $location ) {
            $ics .= 'LOCATION:' . $location . "\r\n";
        }
        if ( '' !== $url ) {
            $ics .= 'URL:' . $url . "\r\n";
        }
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";

        nocache_headers();
        header( 'Content-Type: text/calendar; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="event-' . $post_id . '.ics"' );
        echo $ics;
        exit;
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
        wp_enqueue_script( 'band-event-datetime-sync', BAND_EVENT_RSVP_URL . 'assets/datetime-sync.js', array(), BAND_EVENT_RSVP_VERSION, true );
    }

    public static function render_template( $template_name, $args = array() ) {
        $template_path = locate_template( array( 'band-event-rsvp/' . $template_name ) );
        if ( empty( $template_path ) ) {
            $plugin_template = BAND_EVENT_RSVP_DIR . 'templates/' . $template_name;
            if ( file_exists( $plugin_template ) ) {
                $template_path = $plugin_template;
            }
        }

        if ( empty( $template_path ) ) {
            return '';
        }

        if ( ! empty( $args ) && is_array( $args ) ) {
            extract( $args, EXTR_SKIP );
        }

        ob_start();
        include $template_path;
        return ob_get_clean();
    }

    public static function get_upcoming_event_meta_query( $today_midnight ) {
        return array(
            'relation' => 'OR',
            array(
                'key'     => '_band_event_end',
                'value'   => $today_midnight,
                'compare' => '>=',
                'type'    => 'DATETIME',
            ),
            array(
                'relation' => 'AND',
                array(
                    'key'     => '_band_event_end',
                    'value'   => '',
                    'compare' => '=',
                ),
                array(
                    'key'     => '_band_event_start',
                    'value'   => $today_midnight,
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ),
            ),
            array(
                'relation' => 'AND',
                array(
                    'key'     => '_band_event_end',
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key'     => '_band_event_start',
                    'value'   => $today_midnight,
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ),
            ),
        );
    }

    public static function filter_event_archive_query( $query ) {
        if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'event' ) ) {
            return;
        }

        $today_midnight = date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );

        $query->set( 'orderby', 'meta_value' );
        $query->set( 'meta_key', '_band_event_start' );
        $query->set( 'order', 'ASC' );
        $query->set( 'meta_query', self::get_upcoming_event_meta_query( $today_midnight ) );
    }

    public static function filter_event_posts_by_invitation( $posts, $query ) {
        if ( is_admin() || empty( $posts ) || ! ( $query instanceof WP_Query ) ) {
            return $posts;
        }

        if ( is_singular( 'event' ) ) {
            return $posts;
        }

        $post_type = $query->get( 'post_type' );
        $is_event_query = false;

        if ( 'event' === $post_type ) {
            $is_event_query = true;
        } elseif ( is_array( $post_type ) && in_array( 'event', $post_type, true ) ) {
            $is_event_query = true;
        }

        if ( ! $is_event_query ) {
            return $posts;
        }

        $visible_posts = array();
        foreach ( $posts as $post ) {
            if ( ! ( $post instanceof WP_Post ) ) {
                continue;
            }

            if ( 'event' !== $post->post_type || self::can_current_user_view_event( $post->ID ) ) {
                $visible_posts[] = $post;
            }
        }

        $visible_event_ids = array();
        foreach ( $visible_posts as $visible_post ) {
            if ( $visible_post instanceof WP_Post && 'event' === $visible_post->post_type ) {
                $visible_event_ids[] = absint( $visible_post->ID );
            }
        }

        if ( ! empty( $visible_event_ids ) ) {
            self::$last_event_query_post_ids = array_values( array_unique( $visible_event_ids ) );
            self::$contextless_sequence_index = -1;
            self::$contextless_current_event_id = 0;
        }

        return $visible_posts;
    }

    public static function render_event_list( $atts ) {
        $atts = shortcode_atts( array(
            'posts_per_page' => 10,
        ), $atts, 'band_event_list' );

        $template_output = self::render_template( 'list.php', array( 'atts' => $atts ) );
        if ( '' !== $template_output ) {
            return $template_output;
        }

        $today_midnight = date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );

        $events = new WP_Query( array(
            'post_type'      => 'event',
            'posts_per_page' => intval( $atts['posts_per_page'] ),
            'post_status'    => 'publish',
            'orderby'        => 'meta_value',
            'meta_key'       => '_band_event_start',
            'order'          => 'ASC',
            'meta_query'     => self::get_upcoming_event_meta_query( $today_midnight ),
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

    public static function format_event_datetime_human( $datetime_value, $fallback = '' ) {
        $datetime_value = (string) $datetime_value;
        if ( '' === $datetime_value ) {
            return (string) $fallback;
        }

        try {
            $dt = new DateTimeImmutable( $datetime_value, wp_timezone() );
        } catch ( Exception $e ) {
            $timestamp = strtotime( $datetime_value );
            if ( false === $timestamp ) {
                return (string) $fallback;
            }

            return wp_date( 'M j, Y g:i a', $timestamp, wp_timezone() );
        }

        return $dt->format( 'M j, Y g:i a' );
    }

    public static function can_current_user_rsvp( $post_id = 0 ) {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        if ( $post_id > 0 ) {
            return self::is_current_user_invited_to_event( $post_id );
        }

        return self::get_current_member_status();
    }

    public static function is_current_user_invited_to_event( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return false;
        }

        if ( ! is_user_logged_in() ) {
            return false;
        }

        $current_user_id = get_current_user_id();
        if ( $current_user_id <= 0 ) {
            return false;
        }

        $invited_ids = Band_Event_RSVP_CPT::get_invited_member_user_ids( $post_id );
        if ( empty( $invited_ids ) ) {
            return false;
        }

        return in_array( intval( $current_user_id ), array_map( 'intval', $invited_ids ), true );
    }

    public static function can_current_user_view_event( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return false;
        }

        if ( current_user_can( 'edit_post', $post_id ) ) {
            return true;
        }

        return self::is_current_user_invited_to_event( $post_id );
    }

    public static function is_event_open_for_rsvp( $fields ) {
        $reference_time = '';

        if ( is_array( $fields ) ) {
            $reference_time = ! empty( $fields['end'] ) ? $fields['end'] : ( isset( $fields['start'] ) ? $fields['start'] : '' );
        }

        return ! self::is_event_in_past( $reference_time );
    }

    public static function render_event_summary( $post_id ) {
        if ( ! self::can_current_user_view_event( $post_id ) ) {
            return '';
        }

        $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
        $title = get_the_title( $post_id );
        $excerpt = get_the_excerpt( $post_id );
        $start = self::format_event_datetime_human( $fields['start'], esc_html__( 'No start time set', 'band-event-rsvp' ) );
        $end = self::format_event_datetime_human( $fields['end'], esc_html__( 'No end time set', 'band-event-rsvp' ) );
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
        $output .= '<span class="band-event-meta">' . esc_html__( 'Start:', 'band-event-rsvp' ) . ' ' . esc_html( $start ) . ' | ' . esc_html__( 'End:', 'band-event-rsvp' ) . ' ' . esc_html( $end ) . ' | ' . esc_html__( 'Location:', 'band-event-rsvp' ) . ' ' . $location . '</span>';
        if ( $recurrence_note ) {
            $output .= '<span class="band-event-recurring">' . esc_html( $recurrence_note ) . '</span>';
        }
        $output .= '<span class="band-event-invited-levels">' . esc_html__( 'Invited levels:', 'band-event-rsvp' ) . ' ' . esc_html( self::get_invited_levels_display( $post_id ) ) . '</span>';
        $output .= '</a>';
        $output .= self::render_add_to_calendar_button( $post_id );
        $output .= self::render_event_attendance_summary( $post_id );

        $output .= '</div>';

        return $output;
    }

    public static function get_invited_levels_display( $post_id ) {
        $invited_levels = Band_Event_RSVP_CPT::get_invited_membership_levels( $post_id );

        if ( empty( $invited_levels ) ) {
            return __( 'All members', 'band-event-rsvp' );
        }

        $available_levels = Band_Event_RSVP_CPT::get_available_membership_levels();
        $level_labels = array();

        foreach ( $invited_levels as $level_id ) {
            if ( isset( $available_levels[ $level_id ] ) ) {
                $level_labels[] = $available_levels[ $level_id ];
            } else {
                $level_labels[] = sprintf( __( 'Level %d', 'band-event-rsvp' ), intval( $level_id ) );
            }
        }

        if ( empty( $level_labels ) ) {
            return __( 'All members', 'band-event-rsvp' );
        }

        return implode( ', ', $level_labels );
    }

    public static function get_event_attendance_counts( $post_id ) {
        $responses = self::get_rsvp_list( $post_id );
        $counts = array(
            'yes'        => 0,
            'maybe'      => 0,
            'no'         => 0,
            'unanswered' => 0,
        );

        foreach ( $responses as $response ) {
            $status = isset( $response['status'] ) ? $response['status'] : 'no';
            if ( isset( $counts[ $status ] ) ) {
                $counts[ $status ]++;
            }
        }

        $invited_ids = Band_Event_RSVP_CPT::get_invited_member_user_ids( $post_id );
        if ( ! empty( $invited_ids ) ) {
            $responded_ids = array_map( 'intval', array_keys( $responses ) );
            $pending_ids = array_diff( $invited_ids, $responded_ids );
            $counts['unanswered'] = count( $pending_ids );
        }

        return $counts;
    }

    public static function render_event_attendance_summary( $post_id ) {
        $counts = self::get_event_attendance_counts( $post_id );

        $output  = '<p class="band-event-attendance-summary">';
        $output .= '<span class="band-event-attendance-chip band-event-attendance-yes">✅ ' . intval( $counts['yes'] ) . '</span>';
        $output .= '<span class="band-event-attendance-chip band-event-attendance-maybe">🤔 ' . intval( $counts['maybe'] ) . '</span>';
        $output .= '<span class="band-event-attendance-chip band-event-attendance-no">❌ ' . intval( $counts['no'] ) . '</span>';
        $output .= '<span class="band-event-attendance-chip band-event-attendance-unanswered">❔ ' . intval( $counts['unanswered'] ) . '</span>';
        $output .= '</p>';

        return $output;
    }

    public static function render_event_list_rsvp( $post_id ) {
        $user_id = get_current_user_id();
        $rsvp_data = self::get_rsvp_for_user( $post_id, $user_id );
        $current_status = isset( $rsvp_data['status'] ) ? $rsvp_data['status'] : '';
        $current_comment = isset( $rsvp_data['comment'] ) ? $rsvp_data['comment'] : '';

        $output = '<div class="band-event-rsvp-inline">';
        $output .= '<form method="post" class="band-event-rsvp-form-inline">';
        $output .= wp_nonce_field( 'band_event_rsvp_form', 'band_event_rsvp_nonce', true, false );
        $output .= '<input type="hidden" name="band_event_id" value="' . esc_attr( $post_id ) . '" />';
        $output .= '<p><label>' . esc_html__( 'Comment', 'band-event-rsvp' ) . '<br /><textarea name="band_event_comment" class="widefat" rows="3">' . esc_textarea( $current_comment ) . '</textarea></label></p>';
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

    public static function render_event_actions( $atts ) {
        $atts = shortcode_atts(
            array(
                'id'            => 0,
                'show_calendar' => '1',
                'show_admin'    => '1',
                'show_rsvp'     => '1',
            ),
            $atts,
            'band_event_actions'
        );

        $post_id = self::resolve_event_post_id_from_context( $atts['id'] );

        if ( $post_id <= 0 || 'event' !== get_post_type( $post_id ) ) {
            return '';
        }

        if ( ! self::can_current_user_view_event( $post_id ) ) {
            return '';
        }

        $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
        $output = '<div class="band-event-actions-shortcode">';

        if ( '0' !== (string) $atts['show_calendar'] ) {
            $output .= self::render_add_to_calendar_button( $post_id );
        }

        if ( '0' !== (string) $atts['show_admin'] && current_user_can( 'edit_post', $post_id ) ) {
            $output .= '<p class="band-event-admin-actions">';
            $output .= '<a class="band-event-admin-edit" href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html__( 'Edit', 'band-event-rsvp' ) . '</a>';
            if ( current_user_can( 'delete_post', $post_id ) ) {
                $output .= ' | <a class="band-event-admin-delete" href="' . esc_url( get_delete_post_link( $post_id, '', false ) ) . '">' . esc_html__( 'Move to Trash', 'band-event-rsvp' ) . '</a>';
            }
            $output .= '</p>';
        }

        if ( '0' !== (string) $atts['show_rsvp'] && self::is_event_open_for_rsvp( $fields ) && self::can_current_user_rsvp( $post_id ) ) {
            $output .= self::render_event_list_rsvp( $post_id );
        }

        $output .= '</div>';

        return $output;
    }

    public static function get_shortcode_event_post_id( $atts, $shortcode_tag ) {
        $atts = shortcode_atts(
            array(
                'id'       => 0,
                'post_id'  => 0,
                'event_id' => 0,
            ),
            $atts,
            $shortcode_tag
        );

        $explicit_ids = array(
            absint( $atts['id'] ),
            absint( $atts['post_id'] ),
            absint( $atts['event_id'] ),
        );

        foreach ( $explicit_ids as $explicit_id ) {
            if ( $explicit_id > 0 && 'event' === get_post_type( $explicit_id ) ) {
                return $explicit_id;
            }
        }

        if ( self::$current_block_context_event_id > 0 && 'event' === get_post_type( self::$current_block_context_event_id ) ) {
            return self::$current_block_context_event_id;
        }

        $query_cursor_event_id = self::get_event_id_from_wp_query_cursor();
        if ( $query_cursor_event_id > 0 ) {
            return $query_cursor_event_id;
        }

        $contextless_event_id = self::get_event_id_from_contextless_sequence( $shortcode_tag );
        if ( $contextless_event_id > 0 ) {
            return $contextless_event_id;
        }

        // Prefer the current loop context before broad global fallbacks.
        $context_post_id = self::resolve_event_post_id_from_context();
        if ( $context_post_id > 0 && 'event' === get_post_type( $context_post_id ) ) {
            return $context_post_id;
        }

        global $post;
        if ( $post instanceof WP_Post && 'event' === $post->post_type ) {
            return absint( $post->ID );
        }

        global $id;
        $legacy_post_id = absint( $id );
        if ( $legacy_post_id > 0 && 'event' === get_post_type( $legacy_post_id ) ) {
            return $legacy_post_id;
        }

        if ( self::$current_loop_event_id > 0 && 'event' === get_post_type( self::$current_loop_event_id ) ) {
            return self::$current_loop_event_id;
        }

        return 0;
    }

    public static function render_event_start_datetime_shortcode( $atts ) {
        $post_id = self::get_shortcode_event_post_id( $atts, 'band_event_start_datetime' );
        if ( ! $post_id ) {
            return '';
        }

        $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
        if ( empty( $fields['start'] ) ) {
            return '';
        }

        $formatted = self::format_event_datetime_human( $fields['start'] );
        return '<span class="band-event-start-datetime">' . esc_html( $formatted ) . '</span>' . self::get_shortcode_debug_marker( 'band_event_start_datetime', $post_id );
    }

    public static function render_event_end_datetime_shortcode( $atts ) {
        $post_id = self::get_shortcode_event_post_id( $atts, 'band_event_end_datetime' );
        if ( ! $post_id ) {
            return '';
        }

        $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
        if ( empty( $fields['end'] ) ) {
            return '';
        }

        $formatted = self::format_event_datetime_human( $fields['end'] );
        return '<span class="band-event-end-datetime">' . esc_html( $formatted ) . '</span>' . self::get_shortcode_debug_marker( 'band_event_end_datetime', $post_id );
    }

    public static function render_event_member_levels_shortcode( $atts ) {
        $post_id = self::get_shortcode_event_post_id( $atts, 'band_event_member_levels' );
        if ( ! $post_id ) {
            return '';
        }

        $levels = self::get_invited_levels_display( $post_id );
        return '<span class="band-event-shortcode-member-levels">' . esc_html( $levels ) . '</span>';
    }

    public static function render_event_calendar_button_shortcode( $atts ) {
        $post_id = self::get_shortcode_event_post_id( $atts, 'band_event_calendar_button' );
        if ( ! $post_id ) {
            return '';
        }

        return self::render_add_to_calendar_button( $post_id );
    }

    public static function render_event_admin_tools_shortcode( $atts ) {
        $post_id = self::get_shortcode_event_post_id( $atts, 'band_event_admin_tools' );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            return '';
        }

        $output = '<p class="band-event-admin-actions">';
        $output .= '<a class="band-event-admin-edit" href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html__( 'Edit', 'band-event-rsvp' ) . '</a>';
        if ( current_user_can( 'delete_post', $post_id ) ) {
            $output .= ' | <a class="band-event-admin-delete" href="' . esc_url( get_delete_post_link( $post_id, '', false ) ) . '">' . esc_html__( 'Move to Trash', 'band-event-rsvp' ) . '</a>';
        }
        $output .= '</p>';

        return $output;
    }

    public static function render_event_contact_name_shortcode( $atts ) {
        $post_id = self::get_shortcode_event_post_id( $atts, 'band_event_contact_name' );
        if ( ! $post_id ) {
            return '';
        }

        $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
        if ( empty( $fields['contact_person'] ) ) {
            return '';
        }

        return '<span class="band-event-contact-name">' . esc_html( $fields['contact_person'] ) . '</span>';
    }

    public static function render_event_rsvp_buttons_shortcode( $atts ) {
        $post_id = self::get_shortcode_event_post_id( $atts, 'band_event_rsvp_buttons' );
        if ( ! $post_id ) {
            return '';
        }

        return self::render_rsvp_section( $post_id );
    }

    public static function render_event_attendee_dropdowns_shortcode( $atts ) {
        $post_id = self::get_shortcode_event_post_id( $atts, 'band_event_attendee_dropdowns' );
        if ( ! $post_id ) {
            return '';
        }

        return self::render_attendee_list( $post_id );
    }

    public static function resolve_event_post_id_from_context( $raw_id = 0 ) {
        $explicit_id = absint( $raw_id );
        if ( $explicit_id > 0 ) {
            return $explicit_id;
        }

        if ( self::$current_block_context_event_id > 0 && 'event' === get_post_type( self::$current_block_context_event_id ) ) {
            return self::$current_block_context_event_id;
        }

        $query_cursor_event_id = self::get_event_id_from_wp_query_cursor();
        if ( $query_cursor_event_id > 0 ) {
            return $query_cursor_event_id;
        }

        $the_id = get_the_ID();
        if ( $the_id ) {
            $post_id = absint( $the_id );
            if ( 'event' === get_post_type( $post_id ) ) {
                return $post_id;
            }
        }

        if ( self::$current_loop_event_id > 0 && 'event' === get_post_type( self::$current_loop_event_id ) ) {
            return self::$current_loop_event_id;
        }

        global $post;
        if ( $post instanceof WP_Post ) {
            $post_id = absint( $post->ID );
            if ( 'event' === get_post_type( $post_id ) ) {
                return $post_id;
            }
        }

        $current_post = get_post();
        if ( $current_post instanceof WP_Post ) {
            $post_id = absint( $current_post->ID );
            if ( 'event' === get_post_type( $post_id ) ) {
                return $post_id;
            }
        }

        $request_post_id = 0;
        if ( isset( $_REQUEST['post_id'] ) && is_scalar( $_REQUEST['post_id'] ) ) {
            $request_post_id = absint( wp_unslash( (string) $_REQUEST['post_id'] ) );
        }
        if ( $request_post_id > 0 && 'event' === get_post_type( $request_post_id ) ) {
            return $request_post_id;
        }

        $queried_object_id = get_queried_object_id();
        if ( $queried_object_id && is_singular( 'event' ) ) {
            $post_id = absint( $queried_object_id );
            if ( 'event' === get_post_type( $post_id ) ) {
                return $post_id;
            }
        }

        $candidates = array();

        global $wp_query;
        if ( is_singular( 'event' ) && isset( $wp_query->post ) && $wp_query->post instanceof WP_Post ) {
            $candidates[] = absint( $wp_query->post->ID );
        }

        $candidates = array_values( array_unique( array_filter( $candidates ) ) );
        foreach ( $candidates as $candidate_id ) {
            if ( 'event' === get_post_type( $candidate_id ) ) {
                return $candidate_id;
            }
        }

        return 0;
    }

    public static function render_event_detail_content( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || 'event' !== $post->post_type ) {
            return '<p>' . esc_html__( 'Event not found.', 'band-event-rsvp' ) . '</p>';
        }

        if ( ! self::can_current_user_view_event( $post_id ) ) {
            return '<p class="band-event-login-message">' . esc_html__( 'You are not invited to this event.', 'band-event-rsvp' ) . '</p>';
        }

        $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
        $selected_levels = Band_Event_RSVP_CPT::get_invited_membership_levels( $post_id );
        $invited_levels_display = empty( $selected_levels )
            ? __( 'No membership levels selected (no members invited)', 'band-event-rsvp' )
            : self::get_invited_levels_display( $post_id );
        $archive_link = get_post_type_archive_link( 'event' );
        $output  = '<div class="band-event-detail">';
        // Back to events link for easier mobile navigation
        if ( $archive_link ) {
            $output .= '<p class="band-event-back"><a href="' . esc_url( $archive_link ) . '">&larr; ' . esc_html__( 'Back to events', 'band-event-rsvp' ) . '</a></p>';
        }
        $output .= '<div class="band-event-description">' . wpautop( wp_kses_post( $post->post_content ) ) . '</div>';
        $formatted_start = self::format_event_datetime_human( $fields['start'], esc_html__( 'No start time set', 'band-event-rsvp' ) );
        $formatted_end = self::format_event_datetime_human( $fields['end'], esc_html__( 'No end time set', 'band-event-rsvp' ) );
        $output .= '<ul class="band-event-data">';
        $output .= '<li><strong>' . esc_html__( 'Location:', 'band-event-rsvp' ) . '</strong> ' . esc_html( $fields['location'] ) . '</li>';
        $output .= '<li><strong>' . esc_html__( 'Start:', 'band-event-rsvp' ) . '</strong> ' . esc_html( $formatted_start ) . '</li>';
        $output .= '<li><strong>' . esc_html__( 'End:', 'band-event-rsvp' ) . '</strong> ' . esc_html( $formatted_end ) . '</li>';
        if ( intval( $fields['recurring_count'] ) > 0 && 'none' !== $fields['recurring_unit'] ) {
            $output .= '<li><strong>' . esc_html__( 'Recurring:', 'band-event-rsvp' ) . '</strong> ' . esc_html( sprintf( __( 'Every %d %s', 'band-event-rsvp' ), intval( $fields['recurring_count'] ), $fields['recurring_unit'] ) ) . '</li>';
        }
        $output .= '<li><strong>' . esc_html__( 'Contact:', 'band-event-rsvp' ) . '</strong> ' . esc_html( $fields['contact_person'] ) . '</li>';
        $output .= '<li><strong>' . esc_html__( 'Invited levels:', 'band-event-rsvp' ) . '</strong> ' . esc_html( $invited_levels_display ) . '</li>';
        $output .= '</ul>';
        $output .= self::render_location_map_if_enabled( $fields );
        $output .= self::render_add_to_calendar_button( $post_id );
        $output .= self::render_rsvp_section( $post_id );
        $output .= self::render_attendee_list( $post_id );
        $output .= '</div>';

        return $output;
    }

    public static function render_location_map_if_enabled( $fields ) {
        $show_map = get_option( 'band_event_show_location_map', false );
        if ( ! $show_map || ! is_array( $fields ) || empty( $fields['location'] ) ) {
            return '';
        }

        $location = trim( (string) $fields['location'] );
        if ( '' === $location ) {
            return '';
        }

        $query = rawurlencode( $location );
        $map_url = 'https://maps.google.com/maps?q=' . $query . '&output=embed';

        $output  = '<div class="band-event-location-map">';
        $output .= '<h3>' . esc_html__( 'Map', 'band-event-rsvp' ) . '</h3>';
        $output .= '<iframe src="' . esc_url( $map_url ) . '" width="100%" height="300" style="border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>';
        $output .= '</div>';

        return $output;
    }

    public static function render_rsvp_section( $post_id ) {
        if ( ! self::can_current_user_rsvp( $post_id ) ) {
            if ( ! is_user_logged_in() ) {
                return '<p class="band-event-login-message">' . esc_html__( 'Please log in to RSVP.', 'band-event-rsvp' ) . '</p>';
            }

            return '<p class="band-event-login-message">' . esc_html__( 'You are not invited to RSVP to this event.', 'band-event-rsvp' ) . '</p>';
        }

        $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
        if ( ! self::is_event_open_for_rsvp( $fields ) ) {
            return '<p class="band-event-past-message">' . esc_html__( 'This event has already occurred and RSVPs are no longer accepted.', 'band-event-rsvp' ) . '</p>';
        }

        $user_id = get_current_user_id();
        $rsvp_data = self::get_rsvp_for_user( $post_id, $user_id );
        $current_status = isset( $rsvp_data['status'] ) ? $rsvp_data['status'] : '';
        $current_comment = isset( $rsvp_data['comment'] ) ? $rsvp_data['comment'] : '';

        $output  = '<div class="band-event-rsvp">';
        $output .= '<h3>' . esc_html__( 'RSVP', 'band-event-rsvp' ) . '</h3>';
        $output .= '<form method="post" class="band-event-rsvp-form">';
        $output .= wp_nonce_field( 'band_event_rsvp_form', 'band_event_rsvp_nonce', true, false );
        $output .= '<input type="hidden" name="band_event_id" value="' . esc_attr( $post_id ) . '" />';
        $output .= '<p><label>' . esc_html__( 'Comment', 'band-event-rsvp' ) . '<br /><textarea name="band_event_comment" class="widefat" rows="4">' . esc_textarea( $current_comment ) . '</textarea></label></p>';
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
            $output .= self::render_attendee_group_accordion( 'yes', __( 'Attending', 'band-event-rsvp' ), $grouped['yes'], true );
        }

        if ( ! empty( $grouped['maybe'] ) ) {
            $output .= self::render_attendee_group_accordion( 'maybe', __( 'Maybe', 'band-event-rsvp' ), $grouped['maybe'] );
        }

        if ( ! empty( $grouped['no'] ) ) {
            $output .= self::render_attendee_group_accordion( 'no', __( 'Not Attending', 'band-event-rsvp' ), $grouped['no'] );
        }

        $output .= self::render_unanswered_attendee_list( $post_id );
        $output .= '</div>';

        return $output;
    }

    public static function render_attendee_group_accordion( $status, $label, $items, $open = false ) {
        $output  = '<div class="band-event-attendees-group band-event-attendees-' . esc_attr( $status ) . '">';
        $output .= '<details class="band-event-attendees-accordion"' . ( $open ? ' open' : '' ) . '>';
        $output .= '<summary><span class="band-event-attendees-title">' . esc_html( $label ) . '</span> <span class="band-event-attendee-count">(' . count( $items ) . ')</span></summary>';
        $output .= '<ul>';

        foreach ( $items as $item ) {
            $display_name = isset( $item['display_name'] ) ? $item['display_name'] : '';
            $comment = isset( $item['comment'] ) ? $item['comment'] : '';

            $output .= '<li>' . esc_html( $display_name );
            if ( ! empty( $comment ) ) {
                $output .= ' - ' . esc_html( $comment );
            }
            $output .= '</li>';
        }

        $output .= '</ul>';
        $output .= '</details>';
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
        $output .= '<details class="band-event-attendees-accordion">';
        $output .= '<summary><span class="band-event-attendees-title">' . esc_html__( 'Not Yet Responded', 'band-event-rsvp' ) . '</span> <span class="band-event-attendee-count">(' . count( $pending_ids ) . ')</span></summary>';
        $output .= '<ul>';
        foreach ( $pending_ids as $user_id ) {
            $user = get_userdata( $user_id );
            if ( $user ) {
                $name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
                if ( ! empty( $name ) ) {
                    $label = $name;
                } else {
                    $label = $user->user_login;
                }
            } else {
                $label = sprintf( esc_html__( 'User #%d', 'band-event-rsvp' ), intval( $user_id ) );
            }
            $output .= '<li>' . esc_html( $label ) . '</li>';
        }
        $output .= '</ul>';
        $output .= '</details>';
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

        $post_id = isset( $_POST['band_event_id'] ) ? intval( $_POST['band_event_id'] ) : 0;
        $status  = sanitize_text_field( wp_unslash( $_POST['band_event_response'] ) );
        $comment = isset( $_POST['band_event_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['band_event_comment'] ) ) : '';
        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        if ( ! $post_id ) {
            wp_die( 'Invalid event ID.' );
        }

        if ( ! self::can_current_user_rsvp( $post_id ) ) {
            wp_die( 'You must be logged in to RSVP.' );
        }

        if ( ! in_array( $status, array( 'yes', 'maybe', 'no' ), true ) ) {
            wp_die( 'Invalid RSVP status.' );
        }

        $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
        if ( ! self::is_event_open_for_rsvp( $fields ) ) {
            wp_die( 'Cannot RSVP to past events.' );
        }

        $responses = self::get_rsvp_list( $post_id );
        $full_name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
        if ( empty( $full_name ) ) {
            $full_name = $user->user_login;
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
        // Avoid duplicating the post content (description) - the event detail includes it.
        return $rsvp_msg . $event_detail;
    }
}
