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
add_action( 'admin_post_wpoa_run_scan', 'wpoa_handle_run_scan' );

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

    wpoa_save_scan_report( wpoa_run_scan() );

    wp_safe_redirect( add_query_arg(
        array(
            'page'         => 'wp-ops-agent',
            'wpoa_scanned' => 1,
        ),
        admin_url( 'tools.php' )
    ) );
    exit;
}

/**
 * Save a scan report. Autoload is off so the report never loads on every page.
 *
 * @param array $report The array returned by wpoa_run_scan().
 */
function wpoa_save_scan_report( $report ) {
    update_option( 'wpoa_last_report', $report, false );
}

/**
 * Run a full scan and return the report. Does not save it.
 *
 * Writes a start and an end line to the PHP error log, so the number of
 * scans that really ran can be counted. Define WPOA_TEST_SCAN_SECONDS in
 * wp-config.php to make the scan slow on purpose, for testing the lock.
 *
 * @return array The report: autoload figures, cron figures, timing, user.
 */
function wpoa_run_scan() {
    global $wpdb;

    $started_at = microtime( true );
    $user_id    = get_current_user_id();
    wpoa_log_scan( 'start', $user_id );

    if ( defined( 'WPOA_TEST_SCAN_SECONDS' ) ) {
        sleep( (int) WPOA_TEST_SCAN_SECONDS );
    }

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

    $cron = wpoa_scheduled_events( (int) $started_at );

    $finished_at = microtime( true );
    wpoa_log_scan( 'end', $user_id );

    return array(
        'autoload_option_count'      => (int) $autoload_option_count,
        'total_size_kb'              => $total_size_kb,
        'largest_autoloaded_options' => $largest_autoloaded_options,
        'scheduled_events'           => $cron['scheduled_events'],
        'total_jobs_count'           => $cron['total_jobs_count'],
        'due_jobs_count'             => $cron['due_jobs_count'],
        'current_time'               => $cron['current_time'],
        'started_at'                 => (int) $started_at,
        'finished_at'                => (int) $finished_at,
        'duration_ms'                => (int) round( ( $finished_at - $started_at ) * 1000 ),
        'user_id'                    => $user_id,
    );
}

/**
 * Write one witness line for a scan to the PHP error log.
 *
 * @param string $stage   'start' or 'end'.
 * @param int    $user_id The user who ran the scan; 0 for WP-CLI or a background job.
 */
function wpoa_log_scan( $stage, $user_id ) {
    error_log( sprintf( 'wpoa scan %s user=%d pid=%d server=%s', $stage, $user_id, getmypid(), gethostname() ) );
}

/**
 * Read the saved scan report.
 *
 * @return array|false The report, or false if no scan has been saved yet.
 */
function wpoa_last_report() {
    $report = get_option( 'wpoa_last_report', false );

    return is_array( $report ) ? $report : false;
}

/**
 * Count scheduled cron events, and how many are due at a given time.
 *
 * @param int $current_time The moment "due" is measured against: the scan's start.
 * @return array The event rows, the total, the due count and the time used.
 */
function wpoa_scheduled_events( $current_time ) {
    $scheduled_events = wpoa_get_scheduled_events();
    $due_jobs_count   = 0;

    foreach ( $scheduled_events as $event ) {
        if ( $event['timestamp'] <= $current_time ) {
            ++$due_jobs_count;
        }
    }

    return array(
        'scheduled_events' => $scheduled_events,
        'total_jobs_count' => count( $scheduled_events ),
        'due_jobs_count'   => $due_jobs_count,
        'current_time'     => $current_time,
    );
}

/**
 * Record one fix in the fix history.
 *
 * Called on every Fix click, whether or not the autoload value changed.
 *
 * @param string $option_name The option the fix was applied to.
 * @param bool   $changed     True if autoload was switched off, false if it was already off.
 */
function wpoa_record_fix( $option_name, $changed ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpoa_fix_history';

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'option_name' => $option_name,
            'changed'     => $changed,
            'timestamp'   => time(),
            'user'        => get_current_user_id(),
        ),
        array(
            '%s',
            '%d',
            '%d',
            '%d',
        )
    );

    if ( false === $inserted ) {
        error_log( $wpdb->last_error );
    }
}

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
 * Render the scan notices, the "Last scan" line and the Run scan button.
 */
function wpoa_render_scan_header() {
    $report = wpoa_last_report();
    $date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
    ?>
    <?php if ( isset( $_GET['wpoa_scanned'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Scan finished. The report below is up to date.', 'wp-ops-agent' ); ?></p>
        </div>
    <?php endif; ?>

    <div class="card" style="max-width: 800px; margin-top: 20px;">
        <?php if ( is_array( $report ) && isset( $report['finished_at'] ) ) :
            $user      = get_userdata( isset( $report['user_id'] ) ? (int) $report['user_id'] : 0 );
            $user_name = $user ? $user->display_name : __( 'unknown user', 'wp-ops-agent' );
            $seconds   = isset( $report['duration_ms'] ) ? $report['duration_ms'] / 1000 : 0;
            ?>
            <p>
                <?php
                echo esc_html( sprintf(
                    /* translators: 1: date and time, 2: seconds, 3: user name */
                    __( 'Last scan: %1$s · took %2$s s · by %3$s', 'wp-ops-agent' ),
                    wp_date( $date_format, (int) $report['finished_at'] ),
                    number_format_i18n( $seconds, 1 ),
                    $user_name
                ) );
                ?>
            </p>
        <?php else : ?>
            <p><?php esc_html_e( 'No scan yet. Click Run scan.', 'wp-ops-agent' ); ?></p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="wpoa_run_scan">
            <?php wp_nonce_field( 'wpoa_run_scan' ); ?>
            <?php submit_button( __( 'Run scan', 'wp-ops-agent' ), 'primary', 'submit', false ); ?>
        </form>
    </div>
    <?php
}

/**
 * Callback function to display the admin page content.
 * Render the HTML for the admin page.
 */
function wpoa_admin_page_callback() {
    // Check user capabilities.
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    ?>
    <div class="wrap">
        <h1>WP Ops Agent Dashboard</h1>
        <p>Performance report for your autoloaded WordPress configuration values.</p>

        <?php if ( isset( $_GET['wpoa_fixed'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html( sprintf( __( 'Autoload turned off for %s.', 'wp-ops-agent' ), sanitize_text_field( wp_unslash( $_GET['wpoa_fixed'] ) ) ) ); ?></p>
                <p><?php esc_html_e( 'This report is from before your fix. Run scan to refresh.', 'wp-ops-agent' ); ?></p>
            </div>
        <?php endif; ?>

        <?php
        wpoa_render_scan_header();
        wpoa_autoloaded_options_card();
        wpoa_scheduled_events_card();
        ?>
    </div>
    <?php
}

function wpoa_autoloaded_options_card() {
    $last_report = wpoa_last_report();
    $autoload_option_count = isset( $last_report['autoload_option_count'] ) ? (int) $last_report['autoload_option_count'] : 0;
    $total_size_kb = isset( $last_report['total_size_kb'] ) ? (float) $last_report['total_size_kb'] : 0.0;
    $largest_autoloaded_options = isset( $last_report['largest_autoloaded_options'] ) ? (array) $last_report['largest_autoloaded_options'] : array();
    ?>
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
                        <th style="width: 50%;"><strong>Option Name</strong></th>
                        <th style="width: 15%;"><strong>Autoload Status</strong></th>
                        <th style="width: 15%;"><strong>Size (KiB)</strong></th>
                        <th style="width: 20%;"><strong>Action</strong></th>
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
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="wpoa_disable_autoload">
                                    <input type="hidden" name="option_name" value="<?php echo esc_attr( $option->option_name ); ?>">
                                    <?php wp_nonce_field( 'wpoa_disable_autoload_' . $option->option_name ); ?>
                                    <?php submit_button( __( 'Fix: stop autoloading', 'wp-ops-agent' ), 'secondary small', 'submit', false ); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p>No autoloaded options found.</p>
        <?php endif; ?>
    </div>

    <?php
}


function wpoa_scheduled_events_card() {
    $last_report = wpoa_last_report();
    $scheduled_events = isset( $last_report['scheduled_events'] ) ? (array) $last_report['scheduled_events'] : array();
    $total_jobs_count = isset( $last_report['total_jobs_count'] ) ? (int) $last_report['total_jobs_count'] : 0;
    $due_jobs_count = isset( $last_report['due_jobs_count'] ) ? (int) $last_report['due_jobs_count'] : 0;
    $current_time = isset( $last_report['current_time'] ) ? (int) $last_report['current_time'] : time();
    $date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
    ?>
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
    <?php
}

function wpoa_activate_plugin() {
    // Add a new database table to store the fix history.
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpoa_fix_history';
    $charset_collate = $wpdb->get_charset_collate();
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        option_name VARCHAR(255) NOT NULL,
        changed TINYINT(1) NOT NULL,
        timestamp BIGINT(20) NOT NULL,
        user BIGINT(20) UNSIGNED NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;" );
}

register_activation_hook( __FILE__, 'wpoa_activate_plugin' );