<?php
/**
 * Event Archive Template
 *
 * Displays the archive of `event` posts including inline RSVP buttons.
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
                $fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
                $title = get_the_title( $post_id );
                $start = $fields['start'] ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $fields['start'] ) ) : esc_html__( 'No start time set', 'band-event-rsvp' );
                $location = $fields['location'] ? esc_html( $fields['location'] ) : esc_html__( 'No location set', 'band-event-rsvp' );
                $permalink = get_permalink( $post_id );
                ?>
                <article id="post-<?php echo esc_attr( $post_id ); ?>" <?php post_class( 'band-event-item' ); ?>>
                    <a href="<?php echo esc_url( $permalink ); ?>" class="band-event-link">
                        <span class="band-event-title"><?php echo esc_html( $title ); ?></span>
                        <span class="band-event-meta"><?php echo esc_html( $start ); ?> | <?php echo esc_html( $location ); ?></span>
                    </a>

                    <?php
                    if ( ! Band_Event_RSVP_Frontend::is_event_in_past( $fields['start'] ) && Band_Event_RSVP_Frontend::get_current_member_status() ) {
                        echo Band_Event_RSVP_Frontend::render_event_list_rsvp( $post_id );
                    }
                    ?>
                </article>
            <?php endwhile; wp_reset_postdata(); ?>
        </div>

        <?php the_posts_pagination(); ?>

    <?php else : ?>
        <p><?php esc_html_e( 'No upcoming events found.', 'band-event-rsvp' ); ?></p>
    <?php endif; ?>
</main>

<?php get_footer();
