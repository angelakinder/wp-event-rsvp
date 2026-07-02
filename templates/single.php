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

$post = get_post( $post_id );
if ( ! $post || 'event' !== $post->post_type ) {
    echo '<p>' . esc_html__( 'Event not found.', 'band-event-rsvp' ) . '</p>';
    return;
}

$fields = Band_Event_RSVP_CPT::get_event_fields( $post_id );
$is_past = Band_Event_RSVP_Frontend::is_event_in_past( $fields['start'] );
?>

<div class="band-event-detail">
    <div class="band-event-description">
        <?php echo wpautop( wp_kses_post( $post->post_content ) ); ?>
    </div>

    <ul class="band-event-data">
        <li>
            <strong><?php esc_html_e( 'Location:', 'band-event-rsvp' ); ?></strong>
            <?php echo esc_html( $fields['location'] ); ?>
        </li>
        <li>
            <strong><?php esc_html_e( 'Start:', 'band-event-rsvp' ); ?></strong>
            <?php echo esc_html( $fields['start'] ); ?>
        </li>
        <li>
            <strong><?php esc_html_e( 'End:', 'band-event-rsvp' ); ?></strong>
            <?php echo esc_html( $fields['end'] ); ?>
        </li>
        <?php
        if ( intval( $fields['recurring_count'] ) > 0 && 'none' !== $fields['recurring_unit'] ) {
            ?>
            <li>
                <strong><?php esc_html_e( 'Recurring:', 'band-event-rsvp' ); ?></strong>
                <?php
                echo esc_html( sprintf(
                    __( 'Every %d %s', 'band-event-rsvp' ),
                    intval( $fields['recurring_count'] ),
                    $fields['recurring_unit']
                ) );
                ?>
            </li>
            <?php
        }
        ?>
        <li>
            <strong><?php esc_html_e( 'Contact:', 'band-event-rsvp' ); ?></strong>
            <?php echo esc_html( $fields['contact_person'] ); ?>
        </li>
    </ul>

    <?php
    // Display RSVP section
    if ( ! $is_past && Band_Event_RSVP_Frontend::get_current_member_status() ) {
        $user_id = get_current_user_id();
        $rsvp_data = Band_Event_RSVP_Frontend::get_rsvp_for_user( $post_id, $user_id );
        $current_status = $rsvp_data['status'] ?? '';
        ?>
        <div class="band-event-rsvp">
            <h3><?php esc_html_e( 'RSVP', 'band-event-rsvp' ); ?></h3>
            <form method="post" class="band-event-rsvp-form">
                <?php wp_nonce_field( 'band_event_rsvp_form', 'band_event_rsvp_nonce' ); ?>
                <input type="hidden" name="band_event_id" value="<?php echo esc_attr( $post_id ); ?>" />

                <div class="band-event-rsvp-buttons-large">
                    <button type="submit" name="band_event_response" value="yes" class="band-event-rsvp-button band-event-rsvp-button-yes<?php echo 'yes' === $current_status ? ' active' : ''; ?>">
                        <?php esc_html_e( 'Yes', 'band-event-rsvp' ); ?>
                    </button>
                    <button type="submit" name="band_event_response" value="maybe" class="band-event-rsvp-button band-event-rsvp-button-maybe<?php echo 'maybe' === $current_status ? ' active' : ''; ?>">
                        <?php esc_html_e( 'Maybe', 'band-event-rsvp' ); ?>
                    </button>
                    <button type="submit" name="band_event_response" value="no" class="band-event-rsvp-button band-event-rsvp-button-no<?php echo 'no' === $current_status ? ' active' : ''; ?>">
                        <?php esc_html_e( 'No', 'band-event-rsvp' ); ?>
                    </button>
                </div>

                <p>
                    <label><?php esc_html_e( 'Comment', 'band-event-rsvp' ); ?><br />
                        <textarea name="band_event_comment" class="widefat" rows="4"><?php echo esc_textarea( $rsvp_data['comment'] ?? '' ); ?></textarea>
                    </label>
                </p>
            </form>
        </div>
        <?php
    } elseif ( $is_past ) {
        ?>
        <p class="band-event-past-message">
            <?php esc_html_e( 'This event has already occurred and RSVPs are no longer accepted.', 'band-event-rsvp' ); ?>
        </p>
        <?php
    } elseif ( ! Band_Event_RSVP_Frontend::get_current_member_status() ) {
        ?>
        <p class="band-event-login-message">
            <?php esc_html_e( 'Please log in as a member to RSVP.', 'band-event-rsvp' ); ?>
        </p>
        <?php
    }
    ?>

    <?php
    // Display attendee list
    $responses = Band_Event_RSVP_Frontend::get_rsvp_list( $post_id );
    if ( ! empty( $responses ) ) {
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
        ?>

        <div class="band-event-attendees">
            <h3><?php esc_html_e( 'Attendees', 'band-event-rsvp' ); ?></h3>

            <?php
            if ( ! empty( $grouped['yes'] ) ) {
                ?>
                <div class="band-event-attendees-group band-event-attendees-yes">
                    <h4>
                        <?php esc_html_e( 'Attending', 'band-event-rsvp' ); ?>
                        <span class="band-event-attendee-count">(<?php echo count( $grouped['yes'] ); ?>)</span>
                    </h4>
                    <ul>
                        <?php
                        foreach ( $grouped['yes'] as $response ) {
                            ?>
                            <li>
                                <?php echo esc_html( $response['display_name'] ); ?>
                                <?php
                                if ( ! empty( $response['comment'] ) ) {
                                    echo ' &mdash; ' . esc_html( $response['comment'] );
                                }
                                ?>
                            </li>
                            <?php
                        }
                        ?>
                    </ul>
                </div>
                <?php
            }
            ?>

            <?php
            if ( ! empty( $grouped['maybe'] ) ) {
                ?>
                <div class="band-event-attendees-group band-event-attendees-maybe">
                    <h4>
                        <?php esc_html_e( 'Maybe', 'band-event-rsvp' ); ?>
                        <span class="band-event-attendee-count">(<?php echo count( $grouped['maybe'] ); ?>)</span>
                    </h4>
                    <ul>
                        <?php
                        foreach ( $grouped['maybe'] as $response ) {
                            ?>
                            <li>
                                <?php echo esc_html( $response['display_name'] ); ?>
                                <?php
                                if ( ! empty( $response['comment'] ) ) {
                                    echo ' &mdash; ' . esc_html( $response['comment'] );
                                }
                                ?>
                            </li>
                            <?php
                        }
                        ?>
                    </ul>
                </div>
                <?php
            }
            ?>

            <?php
            if ( ! empty( $grouped['no'] ) ) {
                ?>
                <div class="band-event-attendees-group band-event-attendees-no">
                    <h4>
                        <?php esc_html_e( 'Not Attending', 'band-event-rsvp' ); ?>
                        <span class="band-event-attendee-count">(<?php echo count( $grouped['no'] ); ?>)</span>
                    </h4>
                    <ul>
                        <?php
                        foreach ( $grouped['no'] as $response ) {
                            ?>
                            <li>
                                <?php echo esc_html( $response['display_name'] ); ?>
                                <?php
                                if ( ! empty( $response['comment'] ) ) {
                                    echo ' &mdash; ' . esc_html( $response['comment'] );
                                }
                                ?>
                            </li>
                            <?php
                        }
                        ?>
                    </ul>
                </div>
                <?php
            }
            ?>
        </div>
        <?php
    }
    ?>
</div>
