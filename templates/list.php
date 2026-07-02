<?php
/**
 * Event List Template
 * 
 * This template is used to display the event list with RSVP buttons.
 * You can override this template by copying it to your theme folder:
 * your-theme/band-event-rsvp/list.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$atts = shortcode_atts( array(
    'posts_per_page' => 10,
), $atts, 'band_event_list' );

$current_date = date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );

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
            'value'   => $current_date,
            'compare' => '>=',
            'type'    => 'DATETIME',
        ),
    ),
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
        $location = $fields['location'] ? esc_html( $fields['location'] ) : esc_html__( 'No location set', 'band-event-rsvp' );
        $permalink = get_permalink( $post_id );
        ?>
        <div class="band-event-item">
            <a href="<?php echo esc_url( $permalink ); ?>" class="band-event-link">
                <span class="band-event-title"><?php echo esc_html( $title ); ?></span>
                <span class="band-event-meta">
                    <?php echo esc_html( $start ); ?> | <?php echo esc_html( $location ); ?>
                </span>
            </a>

            <?php
            if ( ! Band_Event_RSVP_Frontend::is_event_in_past( $fields['start'] ) && Band_Event_RSVP_Frontend::get_current_member_status() ) {
                $user_id = get_current_user_id();
                $rsvp_data = Band_Event_RSVP_Frontend::get_rsvp_for_user( $post_id, $user_id );
                $current_status = $rsvp_data['status'] ?? '';
                ?>
                <div class="band-event-rsvp-inline">
                    <form method="post" class="band-event-rsvp-form-inline">
                        <?php wp_nonce_field( 'band_event_rsvp_form', 'band_event_rsvp_nonce' ); ?>
                        <input type="hidden" name="band_event_id" value="<?php echo esc_attr( $post_id ); ?>" />
                        <div class="band-event-rsvp-buttons">
                            <button type="submit" name="band_event_response" value="yes" class="band-event-rsvp-button<?php echo 'yes' === $current_status ? ' active' : ''; ?>">
                                <?php esc_html_e( 'Yes', 'band-event-rsvp' ); ?>
                            </button>
                            <button type="submit" name="band_event_response" value="maybe" class="band-event-rsvp-button<?php echo 'maybe' === $current_status ? ' active' : ''; ?>">
                                <?php esc_html_e( 'Maybe', 'band-event-rsvp' ); ?>
                            </button>
                            <button type="submit" name="band_event_response" value="no" class="band-event-rsvp-button<?php echo 'no' === $current_status ? ' active' : ''; ?>">
                                <?php esc_html_e( 'No', 'band-event-rsvp' ); ?>
                            </button>
                        </div>
                    </form>
                </div>
                <?php
            }
            ?>
        </div>
        <?php
    }
    wp_reset_postdata();
    ?>
</div>
