<?php
/**
 * Single Event Template
 *
 * This template is used to display a single event with full details and RSVP form.
 * You can override this template by copying it to your theme folder:
 * your-theme/band-event-rsvp/single.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$post_id = isset( $post_id ) ? intval( $post_id ) : 0;
if ( ! $post_id ) {
    return;
}

echo Band_Event_RSVP_Frontend::render_event_detail_content( $post_id );
