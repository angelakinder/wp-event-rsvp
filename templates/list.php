<?php
/**
 * Event List Template
 * 
 * This template is used to display the event list with attendance summary totals.
 * You can override this template by copying it to your theme folder:
 * your-theme/band-event-rsvp/list.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
    'meta_query'     => Band_Event_RSVP_Frontend::get_upcoming_event_meta_query( $today_midnight ),
) );

if ( ! $events->have_posts() ) {
    echo '<p>' . esc_html__( 'No upcoming events found.', 'band-event-rsvp' ) . '</p>';
    return;
}
?>

<div class="band-event-list">
    <?php
    while ( $events->have_posts() ) {
        $events->the_post();
        $post_id = get_the_ID();
        $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
        $title = get_the_title( $post_id );
        $start = $fields['start'] ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $fields['start'] ) ) : esc_html__( 'No start time set', 'band-event-rsvp' );
        $end = $fields['end'] ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $fields['end'] ) ) : esc_html__( 'No end time set', 'band-event-rsvp' );
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
        ?>
        <div class="band-event-item">
            <a href="<?php echo esc_url( $permalink ); ?>" class="band-event-link">
                <span class="band-event-title"><?php echo esc_html( $title ); ?></span>
                <span class="band-event-meta">
                    <?php esc_html_e( 'Start:', 'band-event-rsvp' ); ?> <?php echo esc_html( $start ); ?> |
                    <?php esc_html_e( 'End:', 'band-event-rsvp' ); ?> <?php echo esc_html( $end ); ?> |
                    <?php esc_html_e( 'Location:', 'band-event-rsvp' ); ?> <?php echo esc_html( $location ); ?>
                </span>
                <?php if ( $recurrence_note ) : ?>
                    <span class="band-event-recurring"><?php echo esc_html( $recurrence_note ); ?></span>
                <?php endif; ?>
                <span class="band-event-invited-levels">
                    <?php esc_html_e( 'Invited levels:', 'band-event-rsvp' ); ?>
                    <?php echo esc_html( Band_Event_RSVP_Frontend::get_invited_levels_display( $post_id ) ); ?>
                </span>
            </a>

            <?php echo Band_Event_RSVP_Frontend::render_add_to_calendar_button( $post_id ); ?>

            <?php if ( current_user_can( 'edit_post', $post_id ) ) : ?>
                <p class="band-event-admin-actions">
                    <a class="band-event-admin-edit" href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php esc_html_e( 'Edit', 'band-event-rsvp' ); ?></a>
                    <?php if ( current_user_can( 'delete_post', $post_id ) ) : ?>
                        | <a class="band-event-admin-delete" href="<?php echo esc_url( get_delete_post_link( $post_id, '', false ) ); ?>"><?php esc_html_e( 'Move to Trash', 'band-event-rsvp' ); ?></a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php echo Band_Event_RSVP_Frontend::render_event_attendance_summary( $post_id ); ?>
        </div>
        <?php
    }
    wp_reset_postdata();
    ?>
</div>
