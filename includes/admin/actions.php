<?php
/**
 * Admin actions: the form handlers behind the Fix and Run scan buttons.
 *
 * @package WP_Ops_Agent
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handle the Fix button: turn autoload off for one option.
 */
function wpoa_handle_disable_autoload() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'wp-ops-agent' ), 403 );
    }

    $option_name = isset( $_POST['option_name'] ) ? sanitize_text_field( wp_unslash( $_POST['option_name'] ) ) : '';
    check_admin_referer( 'wpoa_disable_autoload_' . $option_name );

    if ( '' === $option_name || false === get_option( $option_name, false ) ) {
        wp_die( esc_html__( 'That option does not exist.', 'wp-ops-agent' ), 400 );
    }

    // wp_set_option_autoload() arrived in WordPress 6.4; it also clears the alloptions cache.
    if ( function_exists( 'wp_set_option_autoload' ) ) {
        $changed = wp_set_option_autoload( $option_name, false );
    } else {
        global $wpdb;
        $changed = (bool) $wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $option_name ) );
        wp_cache_delete( 'alloptions', 'options' );
    }

    wpoa_record_fix( $option_name, $changed );

    wp_safe_redirect( add_query_arg(
        array(
            'page'       => 'wp-ops-agent',
            'wpoa_fixed' => rawurlencode( $option_name ),
        ),
        admin_url( 'tools.php' )
    ) );
    exit;
}
add_action( 'admin_post_wpoa_disable_autoload', 'wpoa_handle_disable_autoload' );

/**
 * Handle the Run scan button: check who is asking, scan, save, go back.
 *
 * Permission is checked here, at the door. The scan and save functions
 * below do not check, so WP-CLI and background jobs can call them too.
 */
function wpoa_handle_run_scan() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'wp-ops-agent' ), 403 );
    }

    check_admin_referer( 'wpoa_run_scan' );

    if ( function_exists( 'as_enqueue_async_action' ) ) {
        // Use Action Scheduler to run the scan in the background.
        $scheduled = as_enqueue_async_action( 'as_wpoa_run_scan', array( 'user_id' => get_current_user_id() ), 'wp-ops-agent' );

        if ( $scheduled ) {
            wp_safe_redirect( add_query_arg(
                array(
                    'page'         => 'wp-ops-agent',
                    'wpoa_scan_scheduled' => 1,
                ),
                admin_url( 'tools.php' )
            ) );
            exit;
        } else {
            wp_safe_redirect( add_query_arg(
                array(
                    'page'         => 'wp-ops-agent',
                    'wpoa_scan_error' => 1,
                ),
                admin_url( 'tools.php' )
            ) );
            exit;
        }
    } else {
        wp_safe_redirect( add_query_arg(
            array(
                'page'         => 'wp-ops-agent',
                'wpoa_scan_error' => 1,
            ),
            admin_url( 'tools.php' )
        ) );
        exit;
    }

}
add_action( 'admin_post_wpoa_run_scan', 'wpoa_handle_run_scan' );

