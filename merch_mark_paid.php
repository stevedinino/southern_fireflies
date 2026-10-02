<?php
// Build: 2026-09-30-A
// ============================================================
// Admin-triggered "Mark Paid" one-click group action, mirroring
// merch_invoice.php's "Send Invoice" grouping (Steve: he gets ONE
// Venmo/PayPal payment covering a whole combined invoice, but marking
// each row's Pymt Date checkbox separately in ourmerch.php took as many
// clicks as that invoice had lines). Given ONE anchor OrderID, finds
// every OTHER row that carries the EXACT SAME Invoice Date as the
// anchor - i.e. every row that went out together in that one Send
// Invoice combine, since merch_invoice_stamp_invoice_date() (in
// merch_invoice.php) always stamps a whole group with one identical
// date in a single write - plus same identity (email, or name fallback
// for legacy blank-email rows, same as merch_invoice.php's own
// grouping) as the anchor, not yet paid, not cancelled - then stamps
// Pymt Date on the whole group at once.
//
// Matching on Invoice Date (not just identity) matters: a customer can
// have two SEPARATE invoices outstanding at once (ordered again before
// paying the first), each its own combine with its own date. Grouping
// only by identity would risk marking a still-unpaid second invoice
// paid just because the first one's payment came in - matching the
// anchor's exact Invoice Date scopes this to "the one invoice this row
// belongs to", the same group Send Invoice itself combined and stamped
// together. (Same caveat as merch_invoice.php's own grouping: two
// genuinely separate invoices sent to the same customer on the same
// calendar day would look like one group here - an accepted edge case,
// same spirit as the email/name-fallback tolerance already documented
// there.)
//
// Sets each matched row's Pymt Date to THAT ROW'S OWN Invoice Date
// value (not today) - same "matches, doesn't backfill with today"
// cascade rule merch_update.php already applies when Pymt Date is
// checked on a single already-invoiced row (see that file's header
// comment). Since every row in the group shares the anchor's Invoice
// Date by construction, this just writes that one value across the
// whole group - a group-marked row ends up with the exact same Pymt
// Date it would have gotten from checking its own checkbox by hand.
//
// Never touches Invoice Date itself, never touches Created/Fulfilled,
// never touches a row outside this one invoice group - same
// one-field-only discipline as every other write in merch_update.php.
// Unmarking a single row (a mistaken click) still goes through
// merch_update.php's existing field=Pymt Date&checked=0 path - that's
// a plain single-row clear, nothing about grouping applies to it, so it
// doesn't need a dedicated endpoint the way marking-paid does.
// ============================================================

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
header('Content-Type: application/json');
require __DIR__ . '/merch_backup.php';

// Shared implementation in admin_guard.php/csrf.php - same as
// merch_update.php and merch_invoice.php.
merch_require_admin_json();
merch_require_csrf_json();

// Same reasoning as merch_update.php: nothing past this point reads or
// writes $_SESSION, so release the session file lock now instead of
// holding it for the rest of this script (matters when several admin
// fetch() calls land close together).
session_write_close();

$csvFile = __DIR__ . '/merchandise.csv';

$orderId = isset($_POST['orderId']) ? trim($_POST['orderId']) : '';
if ($orderId === '' || !ctype_digit($orderId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

$handle = fopen($csvFile, 'c+');
if (!$handle) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not open file.']);
    exit;
}

// Locking matters here for the same reason it matters in
// merch_update.php/merch_order.php: without it, an order submitted
// while this script is mid-read-modify-write could get silently
// dropped when this script writes its stale copy back.
if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not lock file - try again.']);
    exit;
}

$rows = [];
while (($row = fgetcsv($handle)) !== false) {
    $rows[] = $row;
}

if (empty($rows)) {
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'File is empty.']);
    exit;
}

$header = $rows[0];
// Same BOM issue as ourmerch.php/merch_update.php/merch_invoice.php -
// strip it before matching column names.
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}

$col = [];
foreach (['OrderID', 'Name', 'Email', 'Invoice Date', 'Pymt Date', 'Cancelled'] as $name) {
    $col[$name] = array_search($name, $header, true);
}
if ($col['OrderID'] === false || $col['Invoice Date'] === false || $col['Pymt Date'] === false) {
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Expected column not found - has the CSV header changed?']);
    exit;
}
// Cancelled and Name/Email are allowed to be missing entirely (same
// tolerance as merch_invoice.php) - nothing here treats a missing
// Cancelled column as "cancelled," and a missing Email column just
// means every row groups by name instead.

// Find the row the button was clicked on.
$anchor = null;
foreach ($rows as $i => $row) {
    if ($i === 0) {
        continue; // header
    }
    if (($row[$col['OrderID']] ?? '') === $orderId) {
        $anchor = $row;
        break;
    }
}
if ($anchor === null) {
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Order not found - the page may be out of date, try refreshing.']);
    exit;
}

$anchorInvoiceDate = trim($anchor[$col['Invoice Date']] ?? '');
if ($anchorInvoiceDate === '') {
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'This order has not been invoiced yet.']);
    exit;
}
if (trim($anchor[$col['Pymt Date']] ?? '') !== '') {
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'This order is already marked paid.']);
    exit;
}
// Same guard as merch_invoice.php's anchor check - a cancelled row
// shouldn't anchor (or be swept into) a paid-marking group. ourmerch.php
// already hides the Mark Paid button on a cancelled row, but a stale
// page or a direct POST shouldn't be able to bypass that.
if ($col['Cancelled'] !== false && trim($anchor[$col['Cancelled']] ?? '') !== '') {
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'This order was cancelled.']);
    exit;
}

$anchorEmail = strtolower(trim($anchor[$col['Email']] ?? ''));
$anchorName = strtolower(trim($anchor[$col['Name']] ?? ''));
// Same legacy-data fallback as merch_invoice.php: rows submitted before
// Email was required all have a blank Email, so matching blank===blank
// would silently combine unrelated customers - fall back to matching by
// Name instead when the anchor's own Email is blank. A blank name
// matching another blank name is still never allowed.
$groupByName = ($anchorEmail === '');

// Gather every row matching: same identity (email, or name fallback),
// the SAME Invoice Date as the anchor (i.e. part of the same Send
// Invoice combine - see the file-level comment above), not yet paid,
// and not cancelled.
$paidOrderIds = [];
foreach ($rows as $i => &$row) {
    if ($i === 0) {
        continue; // header
    }
    $email = strtolower(trim($row[$col['Email']] ?? ''));
    $name = strtolower(trim($row[$col['Name']] ?? ''));
    $invoiceDate = trim($row[$col['Invoice Date']] ?? '');
    $pymtDate = trim($row[$col['Pymt Date']] ?? '');
    $cancelled = $col['Cancelled'] !== false && trim($row[$col['Cancelled']] ?? '') !== '';

    $identityMatches = $groupByName
        ? ($name !== '' && $name === $anchorName)
        : ($email === $anchorEmail);

    if ($identityMatches && $invoiceDate === $anchorInvoiceDate && $pymtDate === '' && !$cancelled) {
        // Match the anchor's own Invoice Date, not today - see the
        // file-level comment for why this mirrors merch_update.php's
        // single-row cascade instead of stamping "now."
        $row[$col['Pymt Date']] = $anchorInvoiceDate;
        $paidOrderIds[] = $row[$col['OrderID']];
    }
}
unset($row);

if (empty($paidOrderIds)) {
    // Shouldn't happen - the anchor itself always satisfies its own
    // checks above - but fail loud rather than silently writing nothing
    // back if it somehow does.
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Nothing matched to mark paid.']);
    exit;
}

// Backup before writing - cheap insurance against a bug corrupting live
// order data. Shared implementation in merch_backup.php, same as
// merch_update.php/merch_invoice.php.
merch_backup_csv($csvFile, __DIR__ . '/backups');

rewind($handle);
ftruncate($handle, 0);
foreach ($rows as $row) {
    fputcsv($handle, $row, ",", '"', "\\");
}
fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);

echo json_encode([
    'ok' => true,
    'paidOrderIds' => $paidOrderIds,
    'paidDate' => $anchorInvoiceDate,
    'build' => '2026-09-30-A',
]);
