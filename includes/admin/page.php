<?php
/**
 * Admin page: the Tools menu entry, the page and its cards. Reads the saved report only.
 *
 * @package WP_Ops_Agent
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
    <?php elseif ( isset( $_GET['wpoa_scan_busy'] ) ) : ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php esc_html_e( 'Another scan is already running. Please wait a few minutes and try again.', 'wp-ops-agent' ); ?></p>
        </div>
    <?php elseif ( isset( $_GET['wpoa_scan_error'] ) ) : ?>
        <div class="notice notice-error is-dismissible">
            <p><?php esc_html_e( 'An error occurred while running the scan.', 'wp-ops-agent' ); ?></p>
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
