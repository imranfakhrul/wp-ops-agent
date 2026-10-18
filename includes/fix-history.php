<?php
/**
 * Fix history: the table that records every fix, one row per fix.
 *
 * @package WP_Ops_Agent
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
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
