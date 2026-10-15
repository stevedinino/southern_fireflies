<?php
// Build: 2026-10-15-A
// ============================================================
// Shared logic for the "please be patient" email (merch_patience.php
// preview page, merch_send_patience.php send endpoint). New
// 2026-10-15, per Steve: customers are getting restless about wait
// times for parts, and the real fix is shipping, so this is a one-
// click holding message to everyone who has PAID and is still waiting.
//
// Who gets it (Steve's decisions, 2026-10-15):
//   - Pymt Date filled in (paid)
//   - Fulfilled blank (still waiting)
//   - Cancelled blank
//   - Fulfillment === 'Ship' only: pickup-at-retreat orders are
//     excluded, because the email talks about tracking numbers
//   - PRINTED items only (merch_is_printed_item): shirts and hats are
//     Janet's own pipeline and are excluded
//   - a usable email address on the row
//   - ONE email per customer, matched by email address (case-
//     insensitive), listing everything of theirs that is paid and
//     still unfulfilled
//
// Like merch_reminder_groups.php, both the preview page and the send
// endpoint derive recipients fresh from a live CSV read, and the send
// endpoint trusts only WHICH email addresses were checked, never the
// contents - so a stale preview can never send to someone who has
// since been fulfilled or cancelled; they are simply skipped.
//
// Read-only with respect to merchandise.csv. The only thing written is
// the send log (merch_email_log.php).
//
// Wording lives in strings/emails/patience.{subject,html,text}.txt so
// Steve and Janet can edit it without touching PHP. Tokens:
//   {{firstName}}  {{lineItemsHtml}} (html file)  {{lineItemsText}} (text file)
//
// Requires (by the caller): pricing.php (merch_is_printed_item) and
// merch_shipments.php (merch_load_csv, merch_csv_column_map).
// ============================================================

require_once __DIR__ . '/strings.php';
require_once __DIR__ . '/merch_email_log.php';

/** The "Kind" value written to the send log for this email. */
function merch_patience_kind(): string
{
    return 'patience';
}

/**
 * Days after a successful send during which the preview unchecks (and
 * the send endpoint refuses, unless told otherwise) a customer who
 * already got this email - so a repeat click or a second tab cannot
 * email people twice by accident. Steve can still re-send on purpose.
 */
function merch_patience_resend_guard_days(): int
{
    return 7;
}

/** Column names both files pass to merch_csv_column_map(). */
function merch_patience_required_columns(): array
{
    return ['OrderID', 'Item', 'Quantity', 'Name', 'Fulfillment', 'Email', 'Color', 'Pymt Date', 'Fulfilled', 'Cancelled'];
}

/** Columns that must exist (the rest degrade quietly if missing). */
function merch_patience_hard_required_columns(): array
{
    return ['OrderID', 'Item', 'Fulfillment', 'Email', 'Pymt Date', 'Fulfilled'];
}

/**
 * Everything about "is this row a paid, still-waiting printed Ship
 * line" EXCEPT the email check, so the preview can also report paid
 * customers who cannot be emailed (see merch_patience_unreachable()).
 */
function merch_patience_row_waiting(array $row, array $col): bool
{
    $fulfillment = trim($row[$col['Fulfillment']] ?? '');
    $paid = trim($row[$col['Pymt Date']] ?? '');
    $fulfilled = trim($row[$col['Fulfilled']] ?? '');
    $cancelled = $col['Cancelled'] !== false && trim($row[$col['Cancelled']] ?? '') !== '';
    $item = trim($row[$col['Item']] ?? '');
    return $fulfillment === 'Ship'
        && $paid !== ''
        && $fulfilled === ''
        && !$cancelled
        && merch_is_printed_item($item);
}

/** True if the row is waiting AND has a syntactically valid email. */
function merch_patience_row_eligible(array $row, array $col): bool
{
    if (!merch_patience_row_waiting($row, $col)) {
        return false;
    }
    $email = trim($row[$col['Email']] ?? '');
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * The greeting name, taken from the free-text Name field at send time
 * (no schema change - see the 2026-10-15 TODO decision): the first
 * word, capitalized if it was typed all-lowercase or all-uppercase
 * (mixed case such as "DeAnna" or "McKenzie" is left exactly as
 * typed). Returns 'there' ("Hi there,") for anything that is not
 * clearly a first name: a single word, "&" / "and" / "/" / "+" / a
 * comma / digits / "@", a leading title (Dr., Mr., Mrs., ...), or a
 * leading initial ("J. Smith").
 */
function merch_patience_first_name(string $fullName): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $fullName));
    if ($name === '') {
        return 'there';
    }
    if (preg_match('/[&\/+@,\d]/u', $name) || preg_match('/\band\b/iu', $name)) {
        return 'there';
    }
    $parts = explode(' ', $name);
    if (count($parts) < 2) {
        return 'there';
    }
    $first = $parts[0];
    if (preg_match('/^(dr|mr|mrs|ms|miss|mx|rev|pastor|fr|sr|sgt|capt|col|lt|prof)\.?$/iu', $first)) {
        return 'there';
    }
    if (substr($first, -1) === '.' || mb_strlen($first, 'UTF-8') < 2) {
        return 'there'; // an initial like "J." or "J"
    }
    if (!preg_match('/^\p{L}[\p{L}\'\x{2019}\-]*$/u', $first)) {
        return 'there';
    }
    if ($first === mb_strtolower($first, 'UTF-8') || $first === mb_strtoupper($first, 'UTF-8')) {
        $first = mb_convert_case($first, MB_CASE_TITLE, 'UTF-8');
    }
    return $first;
}

/**
 * Sums quantity per item+color across a customer's waiting rows,
 * preserving first-seen order. Returns a list of
 * ['item' => string, 'quantity' => int, 'color' => string].
 */
function merch_patience_aggregate_lines(array $rows, array $col): array
{
    $order = [];
    $lines = [];
    foreach ($rows as $row) {
        $item = trim($row[$col['Item']] ?? '');
        $color = $col['Color'] !== false ? trim($row[$col['Color']] ?? '') : '';
        if (stripos($color, 'Not applicable') === 0) {
            $color = '';
        }
        $qty = $col['Quantity'] !== false ? max(1, (int) ($row[$col['Quantity']] ?? 1)) : 1;
        $key = $item . '|' . $color;
        if (!isset($lines[$key])) {
            $lines[$key] = ['item' => $item, 'quantity' => 0, 'color' => $color];
            $order[] = $key;
        }
        $lines[$key]['quantity'] += $qty;
    }
    return array_map(fn($k) => $lines[$k], $order);
}

/**
 * Display strings for aggregate lines, e.g. "Circle Cutter Holder (x2) - Sky Blue".
 * Same shape the payment-received email uses, so a customer reads their
 * items the way every other email names them.
 */
function merch_patience_format_item_lines(array $lines): array
{
    return array_map(function ($l) {
        $label = $l['item'];
        if ($l['quantity'] > 1) {
            $label .= ' (x' . $l['quantity'] . ')';
        }
        if ($l['color'] !== '') {
            $label .= ' - ' . $l['color'];
        }
        return $label;
    }, $lines);
}

/** Timestamp of a Pymt Date cell, or null if unparseable. */
function merch_patience_paid_timestamp(string $raw): ?int
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    return $ts === false ? null : $ts;
}

/**
 * Builds the recipient list: one entry per customer email, longest-
 * waiting (oldest payment) first, then by name. Each entry:
 *   email, name, firstName, lines (aggregate lines), lineCount,
 *   orderIds (the waiting rows' OrderIDs), oldestPaid (Y-m-d or '')
 */
function merch_patience_build_recipients(array $rows, array $col): array
{
    $byEmail = [];
    foreach ($rows as $row) {
        if (!merch_patience_row_eligible($row, $col)) {
            continue;
        }
        $email = strtolower(trim($row[$col['Email']]));
        $byEmail[$email][] = $row;
    }

    $recipients = [];
    foreach ($byEmail as $email => $custRows) {
        $name = '';
        $oldest = null;
        foreach ($custRows as $row) {
            if ($name === '') {
                $name = trim($row[$col['Name']] ?? '');
            }
            $ts = merch_patience_paid_timestamp($row[$col['Pymt Date']] ?? '');
            if ($ts !== null && ($oldest === null || $ts < $oldest)) {
                $oldest = $ts;
            }
        }
        $lines = merch_patience_aggregate_lines($custRows, $col);
        $recipients[] = [
            'email' => $email,
            'name' => $name,
            'firstName' => merch_patience_first_name($name),
            'lines' => $lines,
            'lineCount' => count($lines),
            'orderIds' => array_map(fn($r) => trim($r[$col['OrderID']] ?? ''), $custRows),
            'oldestPaid' => $oldest === null ? '' : date('Y-m-d', $oldest),
            '_sort' => $oldest ?? PHP_INT_MAX,
        ];
    }

    usort($recipients, function ($a, $b) {
        return [$a['_sort'], strtolower($a['name']), $a['email']] <=> [$b['_sort'], strtolower($b['name']), $b['email']];
    });
    foreach ($recipients as &$r) {
        unset($r['_sort']);
    }
    unset($r);
    return $recipients;
}

/** One recipient re-derived from a fresh read, or null if no longer eligible. */
function merch_patience_recipient_for_email(array $rows, array $col, string $email): ?array
{
    $email = strtolower(trim($email));
    foreach (merch_patience_build_recipients($rows, $col) as $r) {
        if ($r['email'] === $email) {
            return $r;
        }
    }
    return null;
}

/**
 * Paid, still-waiting Ship customers who CANNOT be emailed (blank or
 * invalid address on the row), so the preview can say so instead of
 * silently leaving them out. Returns a list of ['name' => string,
 * 'email' => string, 'orderIds' => [..]] grouped by name+email.
 */
function merch_patience_unreachable(array $rows, array $col): array
{
    $groups = [];
    foreach ($rows as $row) {
        if (!merch_patience_row_waiting($row, $col) || merch_patience_row_eligible($row, $col)) {
            continue;
        }
        $name = trim($row[$col['Name']] ?? '');
        $email = trim($row[$col['Email']] ?? '');
        $key = strtolower($name . '|' . $email);
        if (!isset($groups[$key])) {
            $groups[$key] = ['name' => $name, 'email' => $email, 'orderIds' => []];
        }
        $groups[$key]['orderIds'][] = trim($row[$col['OrderID']] ?? '');
    }
    return array_values($groups);
}

/**
 * The words of the email for one recipient, with no sending involved
 * (so tests and the preview page can show exactly what a customer
 * would read). Returns ['subject', 'html', 'text'].
 */
function merch_patience_content(array $recipient): array
{
    $lineItemsHtml = '';
    $lineItemsText = '';
    foreach (merch_patience_format_item_lines($recipient['lines']) as $line) {
        $lineItemsHtml .= '<li>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
        $lineItemsText .= '- ' . $line . "\n";
    }
    $first = $recipient['firstName'];

    return [
        'subject' => merch_load_string('emails/patience.subject'),
        'html' => merch_load_string('emails/patience.html', [
            'firstName' => htmlspecialchars($first, ENT_QUOTES, 'UTF-8'),
            'lineItemsHtml' => $lineItemsHtml,
        ]),
        'text' => merch_load_string('emails/patience.text', [
            'firstName' => $first,
            'lineItemsText' => rtrim($lineItemsText),
        ]),
    ];
}

/**
 * True if the log shows a successful send to this email within the
 * resend-guard window. $lastSent is merch_email_log_last_sent()'s map.
 */
function merch_patience_recently_sent(array $lastSent, string $email, ?int $now = null): ?string
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
    return ($now - $ts) < merch_patience_resend_guard_days() * 86400 ? $lastSent[$email] : null;
}
