<?php
// Build: 2026-10-22-A
// ============================================================
// Customer order NOTES for the admin (ourmerch.php "Notes" view).
//
// Customers can leave a note on a merch order (merch_order.php stores it in
// merchandise.csv's Notes column, repeated on every line of the order).
// Until 2026-10-22 nothing in the admin showed them. This file finds the
// orders that have a note and remembers which ones Steve has dealt with.
//
// "Handled" is kept in its own small append-only log, merch_notes_handled.csv
// (Timestamp, NoteKey, Action, Source, IP) - same idea as merch_status_log /
// merch_email_log. Nothing here touches merchandise.csv. The LATEST row for
// a note wins, so un-ticking Handled simply appends an 'unhandled' row.
//
// BACKLOG RULE (Steve, 2026-10-22): an order that is fulfilled (every
// non-cancelled line) AND was placed before MERCH_NOTES_AUTO_HANDLED_BEFORE
// counts as handled without any log row. It is a rule, not a one-time
// write, so there is nothing to run; an explicit log row always overrides it.
//
// One "note" = one order. Lines of an order share OrderGroupID; older rows
// with no group id are grouped by email + timestamp.
//
// merch_notes_handled.csv is gitignored (customer data) and blocked from
// the web by the *.csv rule in .htaccess.
// ============================================================

require_once __DIR__ . '/csv_safety.php';

const MERCH_NOTES_AUTO_HANDLED_BEFORE = '2026-10-15';

function merch_notes_handled_file(): string
{
    if (defined('MERCH_NOTES_HANDLED_FILE')) {
        return MERCH_NOTES_HANDLED_FILE; // tests point this at a temp file
    }
    return __DIR__ . '/merch_notes_handled.csv';
}

function merch_notes_handled_header(): array
{
    return ['Timestamp', 'NoteKey', 'Action', 'Source', 'IP'];
}

/** Latest explicit action per note: [NoteKey => 'handled'|'unhandled']. */
function merch_notes_handled_state(): array
{
    $file = merch_notes_handled_file();
    $state = [];
    if (!is_file($file) || ($h = @fopen($file, 'rb')) === false) {
        return $state;
    }
    $first = true;
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        if ($first) {
            $first = false;
            continue; // header
        }
        $key = (string) ($r[1] ?? '');
        $act = (string) ($r[2] ?? '');
        if ($key !== '' && ($act === 'handled' || $act === 'unhandled')) {
            $state[$key] = $act;
        }
    }
    fclose($h);
    return $state;
}

/** Appends one row. False (and error_log) if it could not be written. */
function merch_notes_handled_append(string $noteKey, bool $handled, string $source = 'ourmerch.php'): bool
{
    $file = merch_notes_handled_file();
    $h = @fopen($file, 'ab');
    if (!$h) {
        error_log("Southern Fireflies: could not open notes log '{$file}' for append");
        return false;
    }
    $ok = false;
    if (flock($h, LOCK_EX)) {
        $stat = fstat($h);
        $ok = true;
        if (($stat['size'] ?? 0) === 0) {
            $ok = fputcsv($h, merch_notes_handled_header(), ',', '"', '') !== false;
        }
        if ($ok) {
            $ok = fputcsv($h, [
                date('Y-m-d H:i:s'),
                $noteKey,
                $handled ? 'handled' : 'unhandled',
                $source,
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ], ',', '"', '') !== false;
        }
        fflush($h);
        flock($h, LOCK_UN);
    }
    fclose($h);
    return $ok;
}

/** Placed-at timestamp: handles "7/5/2026" and "2026-10-20 13:32:24". Null if unreadable. */
function merch_notes_parse_ts(string $s): ?int
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
        return mktime(0, 0, 0, (int) $m[1], (int) $m[2], (int) $m[3]);
    }
    $t = strtotime($s);
    return $t === false ? null : $t;
}

/**
 * Note key for a CSV row (assoc by header). Stable for the life of the order.
 */
function merch_notes_key(array $row): string
{
    $g = trim((string) ($row['OrderGroupID'] ?? ''));
    if ($g !== '') {
        return 'g' . $g;
    }
    return 't' . strtolower(trim((string) ($row['Email'] ?? ''))) . '|' . trim((string) ($row['Timestamp'] ?? ''));
}

/**
 * Every order that has a note, newest first.
 * $header/$rows: the CSV with the header already split off (as ourmerch.php has them).
 * Each entry: key, orderIds[], name, email, note, items (one-line), ts (int|null),
 * placed (display), status, fulfilled (bool), handled (bool), auto (bool: handled by the backlog rule).
 */
function merch_notes_collect(array $header, array $rows): array
{
    $header = array_values($header);
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
    }
    $state = merch_notes_handled_state();
    $cutoff = strtotime(MERCH_NOTES_AUTO_HANDLED_BEFORE . ' 00:00:00');
    $orders = []; // key => accumulating entry
    foreach ($rows as $r) {
        $row = [];
        foreach ($header as $i => $col) {
            $row[$col] = (string) ($r[$i] ?? '');
        }
        // Replacement orders are made by Steve himself (merch_replacement.php,
        // Retreat = REPLACEMENT); their Notes are his own reason text, not a customer's.
        if (trim($row['Retreat'] ?? '') === 'REPLACEMENT') {
            continue;
        }
        $note = trim($row['Notes'] ?? '');
        if ($note === '') {
            // A note-less line still belongs to its order's group below only if
            // the group has a note elsewhere; lines share the note, so skip.
            continue;
        }
        $key = merch_notes_key($row);
        $cancelled = trim($row['Cancelled'] ?? '') !== '';
        $fulfilled = trim($row['Fulfilled'] ?? '') !== '';
        $paid = trim($row['Pymt Date'] ?? '') !== '';
        $invoiced = trim($row['Invoice Date'] ?? '') !== '';
        $qty = max(1, (int) ($row['Quantity'] ?? 1));
        $item = trim($row['Item'] ?? '');
        $bit = ($qty > 1 ? $qty . '× ' : '') . $item;
        $color = trim($row['Color'] ?? '');
        if ($color !== '') {
            $bit .= ' (' . $color . ')';
        }
        if (!isset($orders[$key])) {
            $ts = merch_notes_parse_ts($row['Timestamp'] ?? '');
            $orders[$key] = [
                'key' => $key,
                'orderIds' => [],
                'name' => trim($row['Name'] ?? ''),
                'email' => trim($row['Email'] ?? ''),
                'note' => $note,
                'itemBits' => [],
                'ts' => $ts,
                'placed' => $ts ? date('n/j/Y', $ts) : trim($row['Timestamp'] ?? ''),
                'live' => 0, 'liveFulfilled' => 0, 'livePaid' => 0, 'liveInvoiced' => 0,
            ];
        }
        $o = &$orders[$key];
        $o['orderIds'][] = trim($row['OrderID'] ?? '');
        if (!$cancelled) {
            $o['itemBits'][] = $bit;
            $o['live']++;
            $o['liveFulfilled'] += $fulfilled ? 1 : 0;
            $o['livePaid'] += $paid ? 1 : 0;
            $o['liveInvoiced'] += $invoiced ? 1 : 0;
        }
        unset($o);
    }

    $out = [];
    foreach ($orders as $o) {
        if ($o['live'] === 0) {
            $status = 'Cancelled';
            $fulfilled = true; // nothing left to do for the order
        } elseif ($o['liveFulfilled'] === $o['live']) {
            $status = 'Shipped';
            $fulfilled = true;
        } else {
            $fulfilled = false;
            if ($o['livePaid'] === $o['live']) {
                $status = 'Paid';
            } elseif ($o['liveInvoiced'] > 0) {
                $status = 'Invoiced';
            } else {
                $status = 'New';
            }
        }
        $auto = false;
        $explicit = $state[$o['key']] ?? null;
        if ($explicit === 'handled') {
            $handled = true;
        } elseif ($explicit === 'unhandled') {
            $handled = false;
        } else {
            $auto = $fulfilled && $o['ts'] !== null && $o['ts'] < $cutoff;
            $handled = $auto;
        }
        $items = $o['itemBits'];
        $summary = count($items) > 2
            ? implode('; ', array_slice($items, 0, 2)) . '; +' . (count($items) - 2) . ' more'
            : implode('; ', $items);
        $out[] = [
            'key' => $o['key'],
            'orderIds' => array_values(array_unique($o['orderIds'])),
            'name' => $o['name'],
            'email' => $o['email'],
            'note' => $o['note'],
            'items' => $summary,
            'ts' => $o['ts'],
            'placed' => $o['placed'],
            'status' => $status,
            'fulfilled' => $fulfilled,
            'handled' => $handled,
            'auto' => $auto,
        ];
    }
    usort($out, function ($a, $b) {
        return ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0);
    });
    return $out;
}

/**
 * mailto: link for replying to a note. Kept short: Outlook (and Windows'
 * ShellExecute) drop or truncate very long mailto URLs, so a long note is
 * quoted only as far as fits.
 */
function merch_notes_mailto(array $n): string
{
    $first = trim(preg_split('/\s+/', trim($n['name']))[0] ?? '');
    $ids = implode(', ', array_filter($n['orderIds']));
    $subject = "Re: your Mr. Firefly's order" . ($ids !== '' ? ' #' . $n['orderIds'][0] : '');
    $note = preg_replace('/\s+/', ' ', $n['note']);
    if (function_exists('mb_strimwidth')) {
        $note = mb_strimwidth($note, 0, 500, '…', 'UTF-8');
    } elseif (strlen($note) > 500) {
        $note = substr($note, 0, 497) . '...';
    }
    $body = 'Hi' . ($first !== '' ? ' ' . $first : '') . ",\r\n\r\n\r\n\r\n"
        . "You wrote on your order:\r\n> " . $note . "\r\n";
    // rawurlencode (not urlencode): mailto wants %20, not +.
    return 'mailto:' . rawurlencode($n['email']) . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
}
