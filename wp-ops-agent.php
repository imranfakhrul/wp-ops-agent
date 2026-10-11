<?php

/**
 * Plugin Name: WP Ops Agent
 * Plugin URI: https://wp-ops-agent.com
 * Description: A WordPress plugin that audits your website performance issues and provides actionable insights to improve your website speed and user experience. 
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Md. Fakhrul Islam
 * Author URI: https://mdfakhrulislam.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ops-agent
 * Domain Path: /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the admin page under the Tools menu.
 */
function wpoa_register_admin_page() {
    add_management_page(
        __( 'WP Ops Agent', 'wp-ops-agent' ),
        __( 'WP Ops Agent', 'wp-ops-agent' ),
        'manage_options',
        'wp-ops-agent',
        'wpoa_admin_page_callback'
    );
}
add_action( 'admin_menu', 'wpoa_register_admin_page' );

/**
 * Callback function to display the admin page content.
 * Render the HTML for the admin page.
 */
function wpoa_admin_page_callback() {
    // Check user capabilities.
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    };

    global $wpdb;

    // Count the number of autoloaded options in the database.
    $autoload_option_count = $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->options WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')" );

    // Calculate the stored option-value size in kibibytes (1 KiB = 1,024 bytes).
    $autoload_option_size = $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM $wpdb->options WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')" );
    
    $total_size_kb = round( $autoload_option_size / 1024, 2 );

    // Fetch the top 20 largest autoloaded options.
    $largest_autoloaded_options = $wpdb->get_results( "
        SELECT option_name, autoload, ROUND(LENGTH(option_value) / 1024, 2) as size_kb
        FROM $wpdb->options
        WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')
        ORDER BY LENGTH(option_value) DESC
        LIMIT 20
    " );

     // Fetch Scheduled Jobs (Cron Arrays)
    $cron_array = _get_cron_array();

    ?>
    <div class="wrap">
        <h1>WP Ops Agent Dashboard</h1>
        <p>Performance report for your autoloaded WordPress configuration values.</p>
        
        <!-- Summary Section -->
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Autoloaded Options Summary</h2>
            <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                <tbody>
                    <tr>
                        <td><strong>Number of Autoloaded Options</strong></td>
                        <td><?php echo esc_html( number_format_i18n( $autoload_option_count ) ); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Combined Stored Value Size</strong></td>
                        <td><?php echo esc_html( number_format_i18n( $total_size_kb, 2 ) ); ?> KiB</td>
                    </tr>
                </tbody>
            </table>

            <!-- Top 20 Largest Options Table -->
            <h2 style="margin-top: 30px;">Top 20 Largest Autoloaded Options</h2>
            <?php if ( ! empty( $largest_autoloaded_options ) ) : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th style="width: 60%;"><strong>Option Name</strong></th>
                            <th style="width: 20%;"><strong>Autoload Status</strong></th>
                            <th style="width: 20%;"><strong>Size (KiB)</strong></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $largest_autoloaded_options as $option ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $option->option_name ); ?></code></td>
                                <td>
                                    <span class="badge" style="background: #eee; padding: 3px 8px; border-radius: 3px; font-size: 11px;">
                                        <?php echo esc_html( $option->autoload ); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( number_format_i18n( $option->size_kb, 2 ) ); ?> KiB</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p>No autoloaded options found.</p>
            <?php endif; ?>
        </div>

        <div class="card" style="max-width: 900px; margin-top: 20px;">
            <h2><?php esc_html_e( 'Scheduled Cron Jobs Execution Tree', 'wp-ops-agent' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Hierarchical breakdown of registered background events waiting to run.', 'wp-ops-agent' ); ?></p>
            
            <?php if ( ! empty( $cron_array ) ) : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th style="width: 25%;"><strong><?php esc_html_e( 'Scheduled Timestamp', 'wp-ops-agent' ); ?></strong></th>
                            <th style="width: 25%;"><strong><?php esc_html_e( 'Hook Name', 'wp-ops-agent' ); ?></strong></th>
                            <th style="width: 25%;"><strong><?php esc_html_e( 'Event Identifier (MD5 Hash)', 'wp-ops-agent' ); ?></strong></th>
                            <th style="width: 25%;"><strong><?php esc_html_e( 'Schedule & Arguments', 'wp-ops-agent' ); ?></strong></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        foreach ( $cron_array as $timestamp => $hooks ) : 
                            // Convert UTC timestamp to local human-readable time configured in WordPress settings
                            $date_format     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
                            $readable_time   = wp_date( $date_format, $timestamp );
                            $time_diff       = human_time_diff( current_time( 'timestamp', 1 ), $timestamp );
                            $is_past         = $timestamp < current_time( 'timestamp', 1 );
                            $time_context    = $is_past ? sprintf( __( '(%s ago)', 'wp-ops-agent' ), $time_diff ) : sprintf( __( '(in %s)', 'wp-ops-agent' ), $time_diff );
                            
                            foreach ( $hooks as $hook_name => $events ) : 
                                foreach ( $events as $event_key => $event_details ) : 
                                    $schedule  = ! empty( $event_details['schedule'] ) ? $event_details['schedule'] : __( 'Non-repeating (Single)', 'wp-ops-agent' );
                                    $arguments = ! empty( $event_details['args'] ) ? wp_json_encode( $event_details['args'] ) : '[]';
                                    ?>
                                    <tr>
                                        <!-- Scheduled Timestamp Column -->
                                        <td>
                                            <strong><?php echo esc_html( $readable_time ); ?></strong><br>
                                            <small style="color: <?php echo $is_past ? '#d63638' : '#666'; ?>;">
                                                <?php echo esc_html( $time_context ); ?>
                                            </small>
                                        </td>
                                        
                                        <!-- Hook Name Column -->
                                        <td><code style="color: #2271b1; font-weight: 600;"><?php echo esc_html( $hook_name ); ?></code></td>
                                        
                                        <!-- Event Identifier Column -->
                                        <td><small style="font-family: monospace; color: #50575e;"><?php echo esc_html( $event_key ); ?></small></td>
                                        
                                        <!-- Schedule and Arguments Column -->
                                        <td>
                                            <strong><?php esc_html_e( 'Recurrence:', 'wp-ops-agent' ); ?></strong> <code><?php echo esc_html( $schedule ); ?></code><br>
                                            <strong><?php esc_html_e( 'Args:', 'wp-ops-agent' ); ?></strong> <small style="font-family: monospace;"><?php echo esc_html( $arguments ); ?></small>
                                        </td>
                                    </tr>
                                <?php 
                                endforeach;
                            endforeach;
                        endforeach; 
                        ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php esc_html_e( 'No scheduled cron jobs found.', 'wp-ops-agent' ); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
