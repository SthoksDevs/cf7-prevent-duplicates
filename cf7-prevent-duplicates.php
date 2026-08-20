<?php
/**
 * Plugin Name: CF7 Prevent Duplicate Submissions
 * Description: Prevents duplicate Contact Form 7 submissions by comparing submission content and blocking identical submissions for a configurable period.
 * Version:     2.0.1
 * Author:      Thokozane Charles Nhlangulela
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CF7_DEDUP_PREFIX', 'cf7dd_' );

/**
 * Default settings.
 */
function cf7dd_get_settings() {

    $defaults = array(
        'duration' => 1,
        'unit'     => 'hours',
    );

    return wp_parse_args(
        get_option( 'cf7dd_settings', array() ),
        $defaults
    );
}

/**
 * Convert admin setting to seconds.
 */
function cf7dd_get_lock_seconds() {

    $settings = cf7dd_get_settings();

    $duration = max( 1, absint( $settings['duration'] ) );
    $unit     = sanitize_text_field( $settings['unit'] );

    switch ( $unit ) {

        case 'days':
            return $duration * DAY_IN_SECONDS;

        case 'hours':
        default:
            return $duration * HOUR_IN_SECONDS;
    }
}

/**
 * Admin settings page.
 */
add_action( 'admin_menu', function() {

    add_options_page(
        'CF7 Duplicate Protection',
        'CF7 Duplicate Protection',
        'manage_options',
        'cf7dd-settings',
        'cf7dd_settings_page'
    );

} );

add_action( 'admin_init', function() {

    register_setting(
        'cf7dd_settings_group',
        'cf7dd_settings'
    );

} );

function cf7dd_settings_page() {
    ?>
    <div class="wrap">
        <h1>CF7 Duplicate Protection</h1>

        <form method="post" action="options.php">

            <?php settings_fields( 'cf7dd_settings_group' ); ?>

            <?php $settings = cf7dd_get_settings(); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        Duplicate Submission Lock Period
                    </th>

                    <td>

                        <input
                            type="number"
                            min="1"
                            name="cf7dd_settings[duration]"
                            value="<?php echo esc_attr( $settings['duration'] ); ?>"
                        />

                        <select name="cf7dd_settings[unit]">

                            <option
                                value="hours"
                                <?php selected( $settings['unit'], 'hours' ); ?>>
                                Hours
                            </option>

                            <option
                                value="days"
                                <?php selected( $settings['unit'], 'days' ); ?>>
                                Days
                            </option>

                        </select>

                        <p class="description">
                            Default: 1 hour.
                        </p>

                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>

        </form>

    </div>
    <?php
}

/**
 * Recursively sort arrays for consistent hashing.
 */
function cf7dd_recursive_ksort( &$array ) {

    if ( ! is_array( $array ) ) {
        return;
    }

    foreach ( $array as &$value ) {

        if ( is_array( $value ) ) {
            cf7dd_recursive_ksort( $value );
        }
    }

    ksort( $array );
}

/**
 * Compare full submission payload.
 */
add_filter(
    'wpcf7_before_send_mail',
    'cf7dd_prevent_duplicate_content',
    10,
    3
);

function cf7dd_prevent_duplicate_content(
    $contact_form,
    &$abort,
    $submission
) {

    if ( ! $submission ) {
        return $contact_form;
    }

    $data = $submission->get_posted_data();

    foreach ( $data as $field_key => $value ) {

        // Ignore CF7 internal fields.
        if ( strpos( $field_key, '_wpcf7' ) === 0 ) {
            unset( $data[ $field_key ] );
            continue;
        }

        // Ignore WP nonces.
        if ( strpos( $field_key, '_wp' ) === 0 ) {
            unset( $data[ $field_key ] );
            continue;
        }

        // Ignore reCAPTCHA.
        if ( strpos( $field_key, 'g-recaptcha' ) !== false ) {
            unset( $data[ $field_key ] );
            continue;
        }

        // Ignore honeypot fields.
        if ( strpos( $field_key, '_wpcf7_honeypot' ) === 0 ) {
            unset( $data[ $field_key ] );
            continue;
        }

        // Ignore common tracking fields.
        if (
            preg_match(
                '/^(utm_|ga_|fbclid|gclid|msclkid)/i',
                $field_key
            )
        ) {
            unset( $data[ $field_key ] );
            continue;
        }

        // Ignore file upload temp paths.
        if (
            is_array( $value ) &&
            isset( $value['tmp_name'] )
        ) {
            unset( $data[ $field_key ] );
            continue;
        }
    }

    // Normalise values.
    array_walk_recursive(
        $data,
        function( &$value ) {
            $value = trim( (string) $value );
        }
    );

    // Ensure stable ordering.
    cf7dd_recursive_ksort( $data );

    // Create unique signature of submission.
    $hash = md5(
        wp_json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
        )
    );

    $transient_key = CF7_DEDUP_PREFIX . $hash;

    if ( get_transient( $transient_key ) !== false ) {

        $abort = true;

        $submission->set_response(
            $contact_form->filter_message(
                __(
                    'An identical submission was already received recently. Please modify the form content or wait before submitting again.',
                    'cf7-prevent-duplicates'
                )
            )
        );

        return $contact_form;
    }

    set_transient(
        $transient_key,
        1,
        cf7dd_get_lock_seconds()
    );

    return $contact_form;
}

/**
 * Disable submit button while processing.
 */
add_action(
    'wp_footer',
    'cf7dd_enqueue_inline_script'
);

function cf7dd_enqueue_inline_script() {

    if ( ! function_exists( 'wpcf7' ) ) {
        return;
    }

    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {

        document.querySelectorAll('.wpcf7 form').forEach(function(form){

            const btn = form.querySelector('[type="submit"]');

            if (!btn) {
                return;
            }

            form.addEventListener('submit', function(){
                btn.disabled = true;
            });

            [
                'wpcf7mailsent',
                'wpcf7invalid',
                'wpcf7spam',
                'wpcf7mailfailed',
                'wpcf7submit'
            ].forEach(function(evt){

                form.closest('.wpcf7').addEventListener(evt,function(){
                    btn.disabled = false;
                });

            });

        });

    });
    </script>
    <?php
}