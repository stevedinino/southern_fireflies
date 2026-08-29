<?php
// Build: 2026-08-29-A
// ============================================================
// Closes the login-throttling half of code review Finding 9
// (2026-08-19): the shared admin password (admin_guard.php) had no
// limit on failed attempts - a script could try passwords as fast as
// the server would answer. This tracks failures PER CLIENT IP in a
// small JSON file (login_throttle.json, gitignored like every other
// piece of live server state this app keeps outside its CSVs) and
// locks an IP out for a while after too many wrong passwords in a row.
//
// Deliberately IP-based, not session-based: a session-based counter
// (tracked in $_SESSION) sounds simpler, but a real attacker script
// just drops the session cookie between attempts and starts a brand
// new, unthrottled session every single time - it would throttle
// nothing. IP-based isn't perfect either (shared/rotating IPs, but
// this is a small single-admin site, not a target worth building
// anything heavier for) - it's a real deterrent against the actual
// threat (a script hammering the login form), not a defense against a
// determined attacker with a botnet.
//
// Fails OPEN, not closed: if the throttle file can't be opened/locked
// for some reason (permissions, disk full), login just proceeds as if
// throttling didn't exist, rather than locking Steve out of his own
// site because of an unrelated filesystem hiccup. A broken throttle
// mechanism should never become its own outage.
// ============================================================

define('MERCH_LOGIN_THROTTLE_FILE', __DIR__ . '/login_throttle.json');
const MERCH_LOGIN_MAX_FAILURES = 5;
const MERCH_LOGIN_LOCKOUT_SECONDS = 900;        // 15 minutes once locked out
const MERCH_LOGIN_FAILURE_WINDOW_SECONDS = 900; // failures older than this are forgotten, not just the lockout itself

function merch_login_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Seconds remaining in an active lockout for the current client IP, or
 * 0 if it isn't locked out right now.
 */
function merch_login_throttle_seconds_remaining(): int
{
    if (!file_exists(MERCH_LOGIN_THROTTLE_FILE)) {
        return 0;
    }
    $contents = @file_get_contents(MERCH_LOGIN_THROTTLE_FILE);
    $data = $contents !== false ? json_decode($contents, true) : null;
    if (!is_array($data)) {
        return 0;
    }
    $entry = $data[merch_login_client_ip()] ?? null;
    if ($entry === null) {
        return 0;
    }
    $remaining = (int) ($entry['lockedUntil'] ?? 0) - time();
    return $remaining > 0 ? $remaining : 0;
}

/**
 * Records one failed login attempt for the current client IP, locking
 * it out once MERCH_LOGIN_MAX_FAILURES is reached within the rolling
 * window. Also prunes any other IP's record that's both fully expired
 * and outside the failure window, so this file can't grow forever.
 */
function merch_login_throttle_record_failure(): void
{
    $handle = fopen(MERCH_LOGIN_THROTTLE_FILE, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) {
            fclose($handle);
        }
        return; // fail open - see header comment
    }

    $contents = stream_get_contents($handle);
    $data = ($contents !== false && trim($contents) !== '') ? json_decode($contents, true) : [];
    if (!is_array($data)) {
        $data = [];
    }

    $ip = merch_login_client_ip();
    $now = time();
    $entry = $data[$ip] ?? ['failures' => 0, 'firstFailureAt' => $now, 'lockedUntil' => 0];

    // A fresh run of failures outside the rolling window starts over,
    // rather than a handful of typos spread across separate days
    // eventually adding up to a lockout.
    if ($now - (int) ($entry['firstFailureAt'] ?? $now) > MERCH_LOGIN_FAILURE_WINDOW_SECONDS) {
        $entry = ['failures' => 0, 'firstFailureAt' => $now, 'lockedUntil' => 0];
    }

    $entry['failures'] = (int) $entry['failures'] + 1;
    if ($entry['failures'] >= MERCH_LOGIN_MAX_FAILURES) {
        $entry['lockedUntil'] = $now + MERCH_LOGIN_LOCKOUT_SECONDS;
    }
    $data[$ip] = $entry;

    foreach ($data as $key => $e) {
        $longExpiredLockout = (int) ($e['lockedUntil'] ?? 0) < $now;
        $outsideFailureWindow = ($now - (int) ($e['firstFailureAt'] ?? 0)) > MERCH_LOGIN_FAILURE_WINDOW_SECONDS;
        if ($longExpiredLockout && $outsideFailureWindow) {
            unset($data[$key]);
        }
    }

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($data));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

/**
 * Clears any failure record for the current client IP - called right
 * after a correct password, so a legitimate admin who mistyped a
 * couple of times isn't left one attempt away from a lockout next time.
 */
function merch_login_throttle_record_success(): void
{
    $handle = fopen(MERCH_LOGIN_THROTTLE_FILE, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) {
            fclose($handle);
        }
        return; // fail open - see header comment
    }

    $contents = stream_get_contents($handle);
    $data = ($contents !== false && trim($contents) !== '') ? json_decode($contents, true) : [];
    if (!is_array($data)) {
        $data = [];
    }
    unset($data[merch_login_client_ip()]);

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($data));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}
