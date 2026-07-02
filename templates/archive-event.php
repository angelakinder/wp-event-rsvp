<?php
/**
 * Event Archive Template
 *
 * Displays the archive of `event` posts including attendance summary totals.
 * Copy to your theme to override: your-theme/band-event-rsvp/archive-event.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

?>
<main class="site-main">
    <header class="page-header">
        <h1 class="page-title"><?php post_type_archive_title(); ?></h1>
    </header>

    <?php if ( have_posts() ) : ?>
        <div class="band-event-list">
            <?php while ( have_posts() ) : the_post();
                $post_id = get_the_ID();
                if ( ! Band_Event_RSVP_Frontend::can_current_user_view_event( $post_id ) ) {
                    continue;
                }
                $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
                $title = get_the_title( $post_id );
                $start = Band_Event_RSVP_Frontend::format_event_datetime_human( $fields['start'], esc_html__( 'No start time set', 'band-event-rsvp' ) );
                $end = Band_Event_RSVP_Frontend::format_event_datetime_human( $fields['end'], esc_html__( 'No end time set', 'band-event-rsvp' ) );
                $location = $fields['location'] ? esc_html( $fields['location'] ) : esc_html__( 'No location set', 'band-event-rsvp' );
                $permalink = get_permalink( $post_id );
                ?>
                <article id="post-<?php echo esc_attr( $post_id ); ?>" <?php post_class( 'band-event-item' ); ?>>
                    <a href="<?php echo esc_url( $permalink ); ?>" class="band-event-link">
                        <span class="band-event-title"><?php echo esc_html( $title ); ?></span>
                        <span class="band-event-meta">
                            <?php esc_html_e( 'Start:', 'band-event-rsvp' ); ?> <?php echo esc_html( $start ); ?> |
                            <?php esc_html_e( 'End:', 'band-event-rsvp' ); ?> <?php echo esc_html( $end ); ?> |
                            <?php esc_html_e( 'Location:', 'band-event-rsvp' ); ?> <?php echo esc_html( $location ); ?>
                        </span>
                        <span class="band-event-invited-levels">
                            <?php esc_html_e( 'Invited levels:', 'band-event-rsvp' ); ?>
                            <?php echo esc_html( Band_Event_RSVP_Frontend::get_invited_levels_display( $post_id ) ); ?>
                        </span>
                    </a>

                    <?php if ( current_user_can( 'edit_post', $post_id ) ) : ?>
                        <p class="band-event-admin-actions">
                            <a class="band-event-admin-edit" href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php esc_html_e( 'Edit', 'band-event-rsvp' ); ?></a>
                            <?php if ( current_user_can( 'delete_post', $post_id ) ) : ?>
                                | <a class="band-event-admin-delete" href="<?php echo esc_url( get_delete_post_link( $post_id, '', false ) ); ?>"><?php esc_html_e( 'Move to Trash', 'band-event-rsvp' ); ?></a>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <?php echo Band_Event_RSVP_Frontend::render_event_attendance_summary( $post_id ); ?>
                </article>
            <?php endwhile; wp_reset_postdata(); ?>
        </div>

        <?php the_posts_pagination(); ?>

    <?php else : ?>
        <p><?php esc_html_e( 'No upcoming events found.', 'band-event-rsvp' ); ?></p>
    <?php endif; ?>
</main>

<?php get_footer();
