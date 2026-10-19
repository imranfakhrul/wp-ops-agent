<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Helper functions for WP Ops Agent plugin.

/**
 * Write one witness line for a scan to the PHP error log.
 *
 * @param string $stage   'start' or 'end'.
 * @param int    $user_id The user who ran the scan; 0 for WP-CLI or a background job.
 */
function wpoa_log_scan( $stage, $user_id ) {
    error_log( sprintf( 'wpoa scan %s user=%d pid=%d server=%s', $stage, $user_id, getmypid(), gethostname() ) );
}

function wpoa_log_scan_error( Throwable $e ) {
    error_log( sprintf( 'wpoa scan error detail: %s "%s" at %s:%d', get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine(),  ) );
}
