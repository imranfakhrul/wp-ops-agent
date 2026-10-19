#!/usr/bin/env bash
#
# Fire many Run scan clicks at the same moment and count how many scans really ran.
#
# Usage: tools/race-scan-lock.sh [requests] [parallel]
#   requests  how many Run scan clicks to send (default 10)
#   parallel  how many are in flight at once (default 10)
#
# What it does:
#   1. Inside WordPress: makes sure a local test admin exists (random password, never
#      stored or used), logs it in by generating its cookies, and creates one Run scan
#      nonce. Stops if a scan lock is already held, so a real scan is never disturbed.
#   2. From this machine: sends the clicks through the load balancer, in parallel.
#   3. Reads the witness lines ("wpoa scan start/busy/error") that both WordPress
#      containers wrote during this run, and where each click was redirected.
#
# Slow mode and the lock TTL come from wp-config (WPOA_TEST_SCAN_SECONDS,
# WPOA_SCAN_LOCK_TTL). The script prints the values the server is using.
# nginx gives up on a request after 60 seconds by default, so keep slow mode
# under that, or raise proxy_read_timeout.
#
# Local development only.

set -euo pipefail
cd "$(dirname "$0")/.."

REQUESTS="${1:-10}"
PARALLEL="${2:-10}"
BASE_URL="http://localhost:8080"
TESTER="wpoa_race_tester"

# Run PHP from stdin inside one WordPress container, with WordPress loaded.
wp_php() {
    docker compose exec -T -w /var/www/html -e TESTER="$TESTER" wordpress php
}

echo "Setting up: test admin, cookies, Run scan nonce..."
# PHP is read into variables first: macOS ships bash 3.2, which misparses heredocs inside $( ).
read -r -d '' SETUP_PHP <<'PHP' || true
<?php
$_SERVER['HTTP_HOST'] = 'localhost:8080';
require 'wp-load.php';

global $wpdb;
$held = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wpoa_scan_lock'" );
if ( $held ) {
    fwrite( STDERR, "A scan lock is already held ($held). Wait for it to finish or expire.\n" );
    exit( 1 );
}

$login = getenv( 'TESTER' );
$user  = get_user_by( 'login', $login );
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

// Log the tester in the way wp-login.php would: a session token plus signed cookies.
$expiration = time() + HOUR_IN_SECONDS;
$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
$auth       = wp_generate_auth_cookie( $user_id, $expiration, 'auth', $token );
$logged_in  = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );

// The nonce is tied to the session token, which WordPress reads from the logged-in cookie.
$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
wp_set_current_user( $user_id );

echo AUTH_COOKIE . '=' . $auth . '; ' . LOGGED_IN_COOKIE . '=' . $logged_in . "\n";
echo wp_create_nonce( 'wpoa_run_scan' ) . "\n";
echo $user_id . "\n";
echo ( defined( 'WPOA_TEST_SCAN_SECONDS' ) ? WPOA_TEST_SCAN_SECONDS : 'off' ) . "\n";
echo ( defined( 'WPOA_SCAN_LOCK_TTL' ) ? WPOA_SCAN_LOCK_TTL : 'not set' ) . "\n";
PHP
SETUP=$(wp_php <<<"$SETUP_PHP")

COOKIE=$(sed -n 1p <<<"$SETUP")
NONCE=$(sed -n 2p <<<"$SETUP")
TESTER_ID=$(sed -n 3p <<<"$SETUP")
SLOW=$(sed -n 4p <<<"$SETUP")
TTL=$(sed -n 5p <<<"$SETUP")

RESULTS=$(mktemp)
trap 'rm -f "$RESULTS"' EXIT

echo "Slow mode: ${SLOW}s   Lock TTL: ${TTL}s"
echo "Firing $REQUESTS Run scan clicks, $PARALLEL at a time..."
SINCE=$(date +%s)
seq "$REQUESTS" | xargs -P "$PARALLEL" -I{} curl -s -o /dev/null \
    -w '%{http_code} %header{x-upstream} %{redirect_url}\n' \
    -b "$COOKIE" \
    --data-urlencode "action=wpoa_run_scan" \
    --data-urlencode "_wpnonce=$NONCE" \
    "$BASE_URL/wp-admin/admin-post.php" >> "$RESULTS"
SECONDS_TAKEN=$(( $(date +%s) - SINCE ))

# Give the containers a moment to flush their logs.
sleep 1
WITNESS=$(docker compose logs --no-log-prefix --since "$SINCE" wordpress 2>/dev/null | grep -o 'wpoa scan [a-z]* user=[0-9]* pid=[0-9]* server=[^ ]*' || true)

read -r -d '' AFTER_PHP <<'PHP' || true
<?php
$_SERVER['HTTP_HOST'] = 'localhost:8080';
require 'wp-load.php';

global $wpdb;
$held = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wpoa_scan_lock'" );
echo $held ? $held : 'none';

$user = get_user_by( 'login', getenv( 'TESTER' ) );
if ( $user ) {
    WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
}
PHP
LOCK_AFTER=$(wp_php <<<"$AFTER_PHP")

count_redirect() { grep -c "$1" "$RESULTS" || true; }
count_witness() { grep -c "^wpoa scan $1 " <<<"$WITNESS" || true; }

echo
echo "Clicks sent:        $REQUESTS in ${SECONDS_TAKEN}s"
echo "Redirects:"
echo "  scanned           $(count_redirect 'wpoa_scanned=1')"
echo "  busy              $(count_redirect 'wpoa_scan_busy=1')"
echo "  error             $(count_redirect 'wpoa_scan_error=1')"
awk '$1 != 302 { print "  other response:   " $1 }' "$RESULTS" | sort | uniq -c
echo "By server:"
awk '{ print $2 }' "$RESULTS" | sort | uniq -c | awk '{ printf "  %-18s %s\n", $2, $1 }'
echo "Witness lines from both servers:"
echo "  start             $(count_witness start)"
echo "  end               $(count_witness end)"
echo "  busy              $(count_witness busy)"
echo "  error             $(count_witness error)"
echo "Lock row after:     $LOCK_AFTER"
echo
echo "Test admin user ID: $TESTER_ID (its witness lines carry this ID)"
