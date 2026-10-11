<?php

/**
 * Plugin Name: WP Ops Agent
 * Plugin URI: https://wp-ops-agent.com
 * Description: A WordPress plugin that audits your website performance issues and provides actionable insights to improve your website speed and user experience. 
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Fakhrul
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

    // Fetch the top 5 largest autoloaded options.
    $largest_autoloaded_options = $wpdb->get_results( "
        SELECT option_name, autoload, ROUND(LENGTH(option_value) / 1024, 2) as size_kb
        FROM $wpdb->options
        WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')
        ORDER BY size_kb DESC
        LIMIT 20
    " );

    $total_size_kb = array_sum( wp_list_pluck( $largest_autoloaded_options, 'size_kb' ) );

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
                        <td><strong>Combined Size</strong></td>
                        <td><?php echo esc_html( number_format_i18n( $total_size_kb, 2 ) ); ?> KB</td>
                    </tr>
                </tbody>
            </table>

            <!-- Top 5 Largest Options Table -->
            <h2 style="margin-top: 30px;">Top 5 Largest Autoloaded Options</h2>
            <?php if ( ! empty( $largest_autoloaded_options ) ) : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th style="width: 60%;"><strong>Option Name</strong></th>
                            <th style="width: 20%;"><strong>Autoload Status</strong></th>
                            <th style="width: 20%;"><strong>Size (KB)</strong></th>
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
                                <td><?php echo esc_html( number_format_i18n( $option->size_kb, 2 ) ); ?> KB</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p>No autoloaded options found.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
