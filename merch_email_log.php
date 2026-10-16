<?php
// Build: 2026-10-15-C
// ============================================================
// A plain, append-only log of customer emails the admin pages sent in
// bulk. New 2026-10-15 for the "please be patient" email; written
// generically (a "kind" column) so the payment reminders can start
// using the same file later. As of 2026-10-15 (build C) the payment
// reminders do: kind 'payment-reminder' (see merch_reminder_kind()). Nothing here touches merchandise.csv.
//
// One row per send ATTEMPT: Timestamp, Kind, Email, Name, Result
// ("sent" or "failed"), Detail (the error text for a failure).
// Steve's preference (2026-08 reminder discussion): a plain local log
// file, not a new column on merchandise.csv.
//
// merch_email_log.csv is gitignored (customer emails - same
// sensitivity as the other CSVs) and blocked from the web by the
// *.csv rule in .htaccess, like every other CSV here.
//
// Appends take an exclusive flock, so two requests (a double click, a
// second tab) can never interleave half-written rows.
// ============================================================

require_once __DIR__ . '/csv_safety.php';

function merch_email_log_file(): string
{
    if (defined('MERCH_EMAIL_LOG_FILE')) {
        return MERCH_EMAIL_LOG_FILE; // tests point this at a temp file
    }
    return __DIR__ . '/merch_email_log.csv';
}

function merch_email_log_header(): array
{
    return ['Timestamp', 'Kind', 'Email', 'Name', 'Result', 'Detail'];
}

/**
 * Appends one row. Returns false (and error_log()s) if the file could
 * not be written - callers should treat that as "send worked, record
 * missing", never as a reason to send again.
 */
function merch_email_log_append(string $kind, string $email, string $name, bool $sent, string $detail = ''): bool
{
    $file = merch_email_log_file();
    $handle = @fopen($file, 'ab');
    if (!$handle) {
        error_log("Southern Fireflies: could not open email log '{$file}' for append");
        return false;
    }
    $ok = false;
    if (flock($handle, LOCK_EX)) {
        $stat = fstat($handle);
        $ok = true;
        if (($stat['size'] ?? 0) === 0) {
            $ok = fputcsv($handle, merch_email_log_header(), ',', '"', '') !== false;
        }
        // Free-text customer fields go through the same formula-trigger
        // strip as the exports (csv_safety.php), since this file may be
        // opened in Excel.
        $ok = $ok && fputcsv($handle, [
            date('Y-m-d H:i:s'),
            $kind,
            strtolower(trim($email)),
            merch_csv_safe_cell($name),
            $sent ? 'sent' : 'failed',
            merch_csv_safe_cell(substr($detail, 0, 300)),
        ], ',', '"', '') !== false;
        fflush($handle);
        flock($handle, LOCK_UN);
    }
    fclose($handle);
    if (!$ok) {
        error_log("Southern Fireflies: could not write to email log '{$file}'");
    }
    return $ok;
}

/**
 * Most recent SUCCESSFUL send of $kind per email address:
 * ['someone@example.com' => 'Y-m-d H:i:s', ...]. Empty if the log does
 * not exist yet. Failed attempts are ignored - a failure should not
 * stop a retry.
 */
function merch_email_log_last_sent(string $kind): array
{
    $file = merch_email_log_file();
    if (!is_file($file)) {
        return [];
    }
    $handle = @fopen($file, 'rb');
    if (!$handle) {
        return [];
    }
    $last = [];
    $header = fgetcsv($handle, 0, ',', '"', '');
    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if (count($row) < 5 || $row[1] !== $kind || $row[4] !== 'sent') {
            continue;
        }
        $email = strtolower(trim($row[2]));
        if (!isset($last[$email]) || strcmp($row[0], $last[$email]) > 0) {
            $last[$email] = $row[0];
        }
    }
    fclose($handle);
    return $last;
}

/**
 * Generic "was this address sent this kind of email within the last
 * $days days?" check, against the array merch_email_log_last_sent()
 * returns. Returns the timestamp of that send, or null when there was
 * none inside the window (or none at all).
 */
function merch_email_log_recent_within(array $lastSent, string $email, int $days, ?int $now = null): ?string
{
    $email = strtolower(trim($email));
    if (!isset($lastSent[$email])) {
        return null;
    }
    $ts = strtotime($lastSent[$email]);
    if ($ts === false) {
        return null;
    }
    $now = $now ?? time();
    return ($now - $ts) < $days * 86400 ? $lastSent[$email] : null;
}
