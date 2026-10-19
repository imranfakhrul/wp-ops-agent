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

define( 'WPOA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'WPOA_SCAN_LOCK_TTL' ) ) {
    define( 'WPOA_SCAN_LOCK_TTL', 300 );
}
define( 'WPOA_SCAN_BUSY', 9 );

require_once WPOA_PLUGIN_DIR . 'includes/helpers.php';
require_once WPOA_PLUGIN_DIR . 'includes/scan.php';
require_once WPOA_PLUGIN_DIR . 'includes/fix-history.php';

if ( is_admin() ) {
    require_once WPOA_PLUGIN_DIR . 'includes/admin/actions.php';
    require_once WPOA_PLUGIN_DIR . 'includes/admin/page.php';
}

register_activation_hook( __FILE__, 'wpoa_activate_plugin' );
