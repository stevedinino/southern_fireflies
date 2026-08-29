<?php
// Build: 2026-08-29-A
// ============================================================
// Shared "assign the next ID safely" helper - closes code review
// Finding 3 (2026-08-19): OrderID (and, since 2026-08-28, RegID) were
// both assigned as max(existing rows in the CSV) + 1, recomputed fresh
// from the CSV every time. That silently reuses an old ID if the row
// holding the current max ever goes missing - a row hand-deleted in
// Excel instead of marked Cancelled, a bad find-and-replace, a restore
// from an older backup that drops the last few rows. Two different
// orders/registrations ending up with the same ID is worse than the
// gap it would "fix" - old emails, invoices, and Shippo labels all
// reference that number, and a reused one makes those ambiguous after
// the fact.
//
// Fix: a small counter file per sequence that only ever counts up,
// never derived from "what's currently in the CSV." It bootstraps
// itself from the CSV's own current max the first time it's used (so
// nothing needs a manual one-time migration when this ships), and from
// then on is the only source of truth for "next ID" - a hand-deleted
// row can no longer cause a reused ID, because the counter never
// forgets a number it already handed out.
// ============================================================

/**
 * Returns the next ID to assign for one sequence, and durably records
 * that it's been handed out - calling this twice never returns the
 * same value, even across two requests that overlap.
 *
 * Takes out its own lock on $counterFile, separate from whatever lock
 * the caller holds on its own CSV - callers (merch_order.php,
 * register.php) already flock() their CSV while assigning an ID, but
 * that lock says nothing about this file, so this function is safe to
 * call from inside or outside that section.
 *
 * @param string $counterFile Path to this sequence's own counter file
 *   (a plain text file holding just the last ID handed out).
 * @param int $csvCurrentMax The highest ID currently found in the live
 *   CSV (0 if none/empty) - used to bootstrap the counter file the
 *   first time it's created, and as a floor thereafter in case the
 *   counter file is ever missing or behind (e.g. restored from an
 *   older backup than the CSV it's paired with).
 * @return int
 */
function merch_next_persistent_id(string $counterFile, int $csvCurrentMax): int
{
    $handle = fopen($counterFile, 'c+');
    if (!$handle) {
        // Counter file couldn't be opened (permissions, disk full) -
        // fall back to the old CSV-max behavior rather than blocking
        // every new order/registration outright. Less safe against the
        // hand-deleted-row case, but no worse than before this existed.
        error_log("id_sequence.php: could not open counter file $counterFile - falling back to CSV-max ID assignment.");
        return $csvCurrentMax + 1;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        error_log("id_sequence.php: could not lock counter file $counterFile - falling back to CSV-max ID assignment.");
        return $csvCurrentMax + 1;
    }

    $contents = stream_get_contents($handle);
    $stored = ($contents !== false && trim($contents) !== '' && is_numeric(trim($contents)))
        ? (int) trim($contents)
        : 0;

    // Never hand out an ID at or below whatever the CSV itself already
    // shows, even if the counter file is missing, empty, or (from some
    // manual restore) behind the CSV's real max - the CSV's own max is
    // always a floor, never the sole source of truth.
    $next = max($stored, $csvCurrentMax) + 1;

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) $next);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $next;
}
