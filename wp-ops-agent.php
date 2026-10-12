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
 * Read and flatten stored cron events without changing the schedule.
 *
 * WordPress's internal cron structure is handled only in this function.
 *
 * @return array One row per scheduled event instance, earliest first.
 */
function wpoa_get_scheduled_events() {
    $cron = get_option( 'cron', array() );
    if ( ! is_array( $cron ) ) {
        return array();
    }

    unset( $cron['version'] );
    $rows = array();

    foreach ( $cron as $timestamp => $hooks ) {
        if ( ! ctype_digit( (string) $timestamp ) || ! is_array( $hooks ) ) {
            continue;
        }
        foreach ( $hooks as $hook => $events ) {
            if ( ! is_array( $events ) ) {
                continue;
            }
            foreach ( $events as $identifier => $event ) {
                if ( ! is_array( $event ) ) {
                    continue;
                }
                $rows[] = array(
                    'timestamp'  => (int) $timestamp,
                    'hook'       => (string) $hook,
                    'identifier' => (string) $identifier,
                    'schedule'   => isset( $event['schedule'] ) && is_string( $event['schedule'] ) ? $event['schedule'] : false,
                    'args'       => isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array(),
                );
            }
        }
    }

    usort( $rows, function ( $first, $second ) {
        return $first['timestamp'] <=> $second['timestamp'];
    } );

    return $rows;
}

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

    $scheduled_events = wpoa_get_scheduled_events();
    $total_jobs_count = count( $scheduled_events );
    $due_jobs_count = 0;
    $current_time = time();
    $date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

    foreach ( $scheduled_events as $event ) {
        if ( $event['timestamp'] <= $current_time ) {
            ++$due_jobs_count;
        }
    }

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
            <h2><?php esc_html_e( 'Scheduled Jobs', 'wp-ops-agent' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Due means ready to run; it does not mean the job has failed.', 'wp-ops-agent' ); ?></p>
            <p><?php echo esc_html( sprintf( __( 'Total scheduled jobs: %s', 'wp-ops-agent' ), number_format_i18n( $total_jobs_count ) ) ); ?></p>
            <p><?php echo esc_html( sprintf( __( 'Currently Due: %s', 'wp-ops-agent' ), number_format_i18n( $due_jobs_count ) ) ); ?></p>

            <?php if ( ! empty( $scheduled_events ) ) : ?>
                <table class="wp-list-table widefat striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Next Run', 'wp-ops-agent' ); ?></th>
                            <th><?php esc_html_e( 'Hook Name', 'wp-ops-agent' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'wp-ops-agent' ); ?></th>
                            <th><?php esc_html_e( 'Event Identifier', 'wp-ops-agent' ); ?></th>
                            <th><?php esc_html_e( 'Schedule & Arguments', 'wp-ops-agent' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $scheduled_events as $event ) :
                            $timestamp = $event['timestamp'];
                            $is_due = $timestamp <= $current_time;
                            $time_diff = human_time_diff( $current_time, $timestamp );
                            if ( $timestamp === $current_time ) {
                                $time_context = __( '(now)', 'wp-ops-agent' );
                            } else {
                                $time_context = $is_due
                                    ? sprintf( __( '(%s ago)', 'wp-ops-agent' ), $time_diff )
                                    : sprintf( __( '(in %s)', 'wp-ops-agent' ), $time_diff );
                            }
                            $schedule = $event['schedule'] ? $event['schedule'] : __( 'One-time', 'wp-ops-agent' );
                            $arguments = wp_json_encode( $event['args'] );
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( wp_date( $date_format, $timestamp ) ); ?></strong><br>
                                    <small><?php echo esc_html( $time_context ); ?></small>
                                </td>
                                <td><code><?php echo esc_html( $event['hook'] ); ?></code></td>
                                <td><?php echo esc_html( $is_due ? __( 'Due', 'wp-ops-agent' ) : __( 'Scheduled', 'wp-ops-agent' ) ); ?></td>
                                <td><small><?php echo esc_html( $event['identifier'] ); ?></small></td>
                                <td>
                                    <strong><?php esc_html_e( 'Recurrence:', 'wp-ops-agent' ); ?></strong> <code><?php echo esc_html( $schedule ); ?></code><br>
                                    <strong><?php esc_html_e( 'Args:', 'wp-ops-agent' ); ?></strong> <small><?php echo esc_html( $arguments ); ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php esc_html_e( 'No scheduled cron jobs found.', 'wp-ops-agent' ); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
