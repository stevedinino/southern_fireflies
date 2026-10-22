<?php
// Build: 2026-10-21-A
// ============================================================
// Per-IP limit on merch order SUBMISSIONS (2026-10-21, shop redesign).
// Once the page stops asking Steve to approve each request by hand,
// a script posting junk to merch_order.php would just fill
// merchandise.csv. This allows a normal number of real orders from one
// address per hour and politely refuses the rest.
//
// Same shape as login_throttle.php, and for the same reasons: state is a
// small JSON file (merch_order_rate.json, gitignored - live server
// state, never deployed over), keyed by client IP, read and written
// under one exclusive lock. It fails OPEN: if the file can't be opened
// or locked, the order goes through - a broken limiter must never
// become the reason a real customer can't order.
//
// Only orders that reach the point of being written count (the caller
// invokes this after all validation), so a customer fixing a typo isn't
// penalised for the bounced attempts.
//
// An address can be exempted (e.g. Steve testing from home) by defining
// MERCH_ORDER_RATE_EXEMPT_IPS as an array in config.php:
//   define('MERCH_ORDER_RATE_EXEMPT_IPS', ['203.0.113.7']);
// ============================================================

define('MERCH_ORDER_RATE_FILE', __DIR__ . '/merch_order_rate.json');
const MERCH_ORDER_RATE_MAX = 10;        // orders per window, per IP
const MERCH_ORDER_RATE_WINDOW = 3600;   // seconds

/**
 * Records one order attempt for $ip and returns true if it is allowed,
 * false if that IP has already used up its orders for the window.
 */
function merch_order_rate_allow(string $ip, ?int $now = null): bool
{
    if (defined('MERCH_ORDER_RATE_EXEMPT_IPS') && in_array($ip, (array) MERCH_ORDER_RATE_EXEMPT_IPS, true)) {
        return true;
    }
    $now = $now ?? time();

    $fh = @fopen(MERCH_ORDER_RATE_FILE, 'c+');
    if (!$fh) {
        return true; // fail open
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return true; // fail open
    }

    $data = json_decode((string) stream_get_contents($fh), true);
    if (!is_array($data)) {
        $data = [];
    }

    // Forget every IP's attempts that have aged out of the window, so
    // the file stays tiny.
    foreach ($data as $key => $times) {
        $fresh = array_values(array_filter(
            is_array($times) ? $times : [],
            fn($t) => is_int($t) && $t > $now - MERCH_ORDER_RATE_WINDOW
        ));
        if ($fresh) {
            $data[$key] = $fresh;
        } else {
            unset($data[$key]);
        }
    }

    $mine = $data[$ip] ?? [];
    $allowed = count($mine) < MERCH_ORDER_RATE_MAX;
    if ($allowed) {
        $mine[] = $now;
        $data[$ip] = $mine;
    }

    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $allowed;
}
