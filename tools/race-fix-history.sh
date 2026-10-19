#!/usr/bin/env bash
#
# Fire many Fix requests at the same moment and count how many reach the fix history.
#
# Usage: tools/race-fix-history.sh [requests] [parallel]
#   requests  how many Fix clicks to send (default 100)
#   parallel  how many are in flight at once (default 20)
#
# What it does:
#   1. Inside WordPress: makes sure a local test admin exists (random password, never
#      stored or used), creates a throwaway option to "fix", empties the fix-history table,
#      and logs the test admin in by generating its cookies and the form's nonce.
#   2. From this machine: sends the Fix requests through the load balancer, in parallel.
#   3. Inside WordPress: counts the rows in the fix-history table, then deletes the throwaway option
#      and logs the test admin out.
#
# Local development only. It empties the wp_wpoa_fix_history table before every run.

set -euo pipefail
cd "$(dirname "$0")/.."

REQUESTS="${1:-100}"
PARALLEL="${2:-20}"
BASE_URL="http://localhost:8080"
TARGET="wpoa_race_test_target"
TESTER="wpoa_race_tester"

# Run PHP from stdin inside the first WordPress container, with WordPress loaded.
wp_php() {
    docker compose exec -T -w /var/www/html -e TARGET="$TARGET" -e TESTER="$TESTER" wordpress php
}

echo "Setting up: test admin, throwaway option, empty history..."
# PHP is read into variables first: macOS ships bash 3.2, which misparses heredocs inside $( ).
read -r -d '' SETUP_PHP <<'PHP' || true
<?php
$_SERVER['HTTP_HOST'] = 'localhost:8080';
require 'wp-load.php';

$target = getenv( 'TARGET' );
$login  = getenv( 'TESTER' );

$user = get_user_by( 'login', $login );
if ( $user ) {
    $user_id = $user->ID;
} else {
    $user_id = wp_insert_user( array(
        'user_login' => $login,
        'user_pass'  => wp_generate_password( 32, true, true ),
        'user_email' => 'race-tester@example.test',
        'role'       => 'administrator',
    ) );
    if ( is_wp_error( $user_id ) ) {
        fwrite( STDERR, $user_id->get_error_message() . "\n" );
        exit( 1 );
    }
}

delete_option( $target );
add_option( $target, str_repeat( 'x', 1024 ), '', true );
delete_option( 'wpoa_fix_history' );

// History lives in the plugin's own table since step 2, part 4. Empty it if it exists.
global $wpdb;
$table = $wpdb->prefix . 'wpoa_fix_history';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
    fwrite( STDERR, "Table $table does not exist. Deactivate and reactivate the plugin to create it.\n" );
    exit( 1 );
}
$wpdb->query( "TRUNCATE TABLE $table" );

// Log the tester in the way wp-login.php would: a session token plus signed cookies.
$expiration = time() + HOUR_IN_SECONDS;
$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
$auth       = wp_generate_auth_cookie( $user_id, $expiration, 'auth', $token );
$logged_in  = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );

// The nonce is tied to the session token, which WordPress reads from the logged-in cookie.
$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
wp_set_current_user( $user_id );
$nonce = wp_create_nonce( 'wpoa_disable_autoload_' . $target );

echo AUTH_COOKIE . '=' . $auth . '; ' . LOGGED_IN_COOKIE . '=' . $logged_in . "\n";
echo $nonce . "\n";
echo $user_id . "\n";
PHP
SETUP=$(wp_php <<<"$SETUP_PHP")

COOKIE=$(sed -n 1p <<<"$SETUP")
NONCE=$(sed -n 2p <<<"$SETUP")
TESTER_ID=$(sed -n 3p <<<"$SETUP")

RESULTS=$(mktemp)
trap 'rm -f "$RESULTS"' EXIT

echo "Firing $REQUESTS Fix requests, $PARALLEL at a time..."
START=$(date +%s)
seq "$REQUESTS" | xargs -P "$PARALLEL" -I{} curl -s -o /dev/null \
    -w '%{http_code} %header{x-upstream}\n' \
    -b "$COOKIE" \
    --data-urlencode "action=wpoa_disable_autoload" \
    --data-urlencode "option_name=$TARGET" \
    --data-urlencode "_wpnonce=$NONCE" \
    "$BASE_URL/wp-admin/admin-post.php" >> "$RESULTS"
SECONDS_TAKEN=$(( $(date +%s) - START ))

read -r -d '' COUNT_PHP <<'PHP' || true
<?php
$_SERVER['HTTP_HOST'] = 'localhost:8080';
require 'wp-load.php';

global $wpdb;
echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpoa_fix_history" );

delete_option( getenv( 'TARGET' ) );
$user = get_user_by( 'login', getenv( 'TESTER' ) );
if ( $user ) {
    WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
}
PHP
RECORDED=$(wp_php <<<"$COUNT_PHP")

HANDLED=$(awk '$1 == 302' "$RESULTS" | wc -l | tr -d ' ')
LOST=$(( HANDLED - RECORDED ))

echo
echo "Requests sent:      $REQUESTS in ${SECONDS_TAKEN}s"
echo "Handled (302):      $HANDLED"
awk '$1 != 302 { print "  other response:   " $1 }' "$RESULTS" | sort | uniq -c
echo "By server:"
awk '{ print $2 }' "$RESULTS" | sort | uniq -c | awk '{ printf "  %-18s %s\n", $2, $1 }'
echo "Fixes recorded:     $RECORDED"
echo "Lost:               $LOST"
echo
echo "Test admin user ID: $TESTER_ID (its history entries carry this ID)"
