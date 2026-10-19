<?php
/**
 * Scanning: run a scan, save the report, read it back. No permission checks here; callers check.
 *
 * @package WP_Ops_Agent
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
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

function wpoa_lock_scan() {
    global $wpdb;

    $uuid = wp_generate_uuid4();
    $time = time();
    $user_id = get_current_user_id();
    $lock_key = 'wpoa_scan_lock';
    $lock_value = "$uuid|$time|$user_id";

    $lock_acquired = $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
        $lock_key,
        $lock_value
    ) );

    if ( ! $lock_acquired ) {
        $locked_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT option_value FROM $wpdb->options WHERE option_name = %s",
            $lock_key
        ) );

        if ( empty( $locked_row ) ) {
            // Try to acquire the lock again.
            $lock_acquired = $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
                $lock_key,
                $lock_value
            ) );
        }
        
        if ( isset( $locked_row->option_value ) ) {
            list( , $locked_time, ) = explode( '|', $locked_row->option_value );

            if ( ( time() - (int) $locked_time ) > WPOA_SCAN_LOCK_TTL ) {
                // The lock is stale; delete it and try again.
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM $wpdb->options WHERE option_name = %s AND option_value = %s",
                    $lock_key,
                    $locked_row->option_value
                ) );

                // Try to acquire the lock again.
                $lock_acquired = $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
                    $lock_key,
                    $lock_value
                ) );
            }
        }
    }

    if ( ! $lock_acquired ) {
        throw new Exception( esc_html__( 'Another scan is already running. Please wait a few minutes and try again.', 'wp-ops-agent' ), WPOA_SCAN_BUSY );
    }

    return $lock_value;
}

function wpoa_unlock_scan( string $lock_value ) {
    global $wpdb;
    $lock_key = 'wpoa_scan_lock';

    $wpdb->query( $wpdb->prepare(
        "DELETE FROM $wpdb->options WHERE option_name = %s AND option_value = %s",
        $lock_key,
        $lock_value
    ) );
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
    $lock_value = wpoa_lock_scan();

    try {
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
    } finally {
        wpoa_unlock_scan( $lock_value );
    }
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
