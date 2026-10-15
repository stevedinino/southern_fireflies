<?php
// Build: 2026-07-26-H
// Admin-triggered: given ONE OrderID (the row the button was clicked
// on), finds every OTHER not-yet-invoiced order from the same email
// address that pays to the same account (printed vs. shop items) and
// uses the same fulfillment method, combines them into one invoice
// email using merch_group_calculate(), sends it, then stamps every
// included row's Invoice Date column with today's date so it won't be
// picked up again. Same admin session gate as merch_update.php - not
// a public endpoint.
//
// IMPORTANT: the file lock is released BEFORE the email send, not
// held across it. SMTP is a slow network round trip (worse during any
// throttling like the iPage incident), and merch_order.php needs an
// exclusive lock to write new customer orders - if this script held
// its lock through a slow send, every new order submission would hang
// waiting for it, which is exactly what caused real site outages
// (found 2026-07-26). Fixed by splitting this into three phases:
// read+compute (locked), send email (NOT locked), stamp result
// (locked again, briefly, by OrderID rather than array index in case
// the file changed in between).

session_start();
header('Content-Type: application/json');
require __DIR__ . '/config.php';
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_notify.php';

if (empty($_SESSION['sff_admin_ok'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    exit;
}

$csvFile = __DIR__ . '/merchandise.csv';

$orderId = isset($_POST['orderId']) ? trim($_POST['orderId']) : '';
if ($orderId === '' || !ctype_digit($orderId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

// ---- Phase 1: locked just long enough to read + figure out the group ----
$handle = fopen($csvFile, 'c+');
if (!$handle) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not open file.']);
    exit;
}

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
flock($handle, LOCK_UN);
fclose($handle);

if (empty($rows)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'File is empty.']);
    exit;
}

$header = $rows[0];
// Same BOM issue as ourmerch.php/merch_update.php.
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}

$col = [];
foreach (['OrderID', 'Item', 'Quantity', 'Name', 'Fulfillment', 'Email', 'Color', 'Size', 'Sleeve', 'Invoice Date'] as $name) {
    $col[$name] = array_search($name, $header, true);
}
if ($col['OrderID'] === false || $col['Invoice Date'] === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Expected column not found - has the CSV header changed? (Does it have an Invoice Date column?)']);
    exit;
}

// Find the row the button was clicked on.
$anchor = null;
foreach ($rows as $i => $row) {
    if ($i === 0) continue;
    if (($row[$col['OrderID']] ?? '') === $orderId) {
        $anchor = $row;
        break;
    }
}
if ($anchor === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Order not found - the page may be out of date, try refreshing.']);
    exit;
}
if (trim($anchor[$col['Invoice Date']] ?? '') !== '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'This order was already invoiced.']);
    exit;
}

$anchorEmail = strtolower(trim($anchor[$col['Email']] ?? ''));
$anchorFulfillment = trim($anchor[$col['Fulfillment']] ?? '');
$anchorIsPrinted = merch_is_printed_item(trim($anchor[$col['Item']] ?? ''));

// Gather every not-yet-invoiced row matching: same email, same
// fulfillment method, same payment-account type. Track by OrderID
// (not array index) since the file could change between phase 1 and
// phase 3 now that the lock isn't held continuously.
$groupOrderIds = [];
$items = [];
foreach ($rows as $i => $row) {
    if ($i === 0) continue;
    $email = strtolower(trim($row[$col['Email']] ?? ''));
    $fulfillment = trim($row[$col['Fulfillment']] ?? '');
    $item = trim($row[$col['Item']] ?? '');
    $invoiced = trim($row[$col['Invoice Date']] ?? '');
    if ($email === $anchorEmail
        && $fulfillment === $anchorFulfillment
        && merch_is_printed_item($item) === $anchorIsPrinted
        && $invoiced === '') {
        $groupOrderIds[] = $row[$col['OrderID']];
        $items[] = [
            'item' => $item,
            'quantity' => (int)($row[$col['Quantity']] ?? 1),
            'size' => trim($row[$col['Size']] ?? ''),
            'sleeve' => trim($row[$col['Sleeve']] ?? ''),
            'color' => trim($row[$col['Color']] ?? ''),
        ];
    }
}

$isShipping = ($anchorFulfillment === 'Ship');
$pricing = merch_group_calculate($items, $isShipping, $anchorIsPrinted);
if ($pricing === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not price one of the items - check the CSV for a typo in an Item value.']);
    exit;
}

$name = trim($anchor[$col['Name']] ?? '');
$email = trim($anchor[$col['Email']] ?? '');

// ---- Phase 2: send the email - deliberately NOT holding the file lock ----
$notifyResult = merch_send_notification($pricing, $name, $email, $anchorIsPrinted, $isShipping);

if (!$notifyResult['sentInvoice']) {
    // Not necessarily a failure - this is also the expected outcome when
    // the group needs a manual shipping quote (merch_send_notification
    // already sent the customer a "we'll follow up" note and flagged
    // Steve internally in that case). Only truly an error if shipping
    // wasn't the reason, meaning the actual email send itself failed.
    if ($pricing['shipping'] === null && $isShipping) {
        http_response_code(200);
        echo json_encode([
            'ok' => false,
            'needsManualQuote' => true,
            'error' => 'This order needs a manual shipping quote, so no invoice was sent - a "we\'ll follow up" note went to the customer instead, and you\'ve been sent a flagged internal alert.',
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Email failed to send: ' . $notifyResult['error']]);
    }
    exit;
}

// ---- Phase 3: mail sent - reopen briefly, re-read, stamp by OrderID ----
$handle = fopen($csvFile, 'c+');
if (!$handle || !flock($handle, LOCK_EX)) {
    if ($handle) fclose($handle);
    // The email genuinely went out at this point - don't tell the
    // customer-facing flow anything failed. Just surface that the
    // CSV stamp itself needs a manual look.
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Invoice email sent, but could not re-open the file to mark Invoice Date - please check merchandise.csv by hand for OrderID(s): ' . implode(', ', $groupOrderIds)]);
    exit;
}

$freshRows = [];
while (($row = fgetcsv($handle)) !== false) {
    $freshRows[] = $row;
}

$freshHeader = $freshRows[0] ?? [];
if (isset($freshHeader[0])) {
    $freshHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', $freshHeader[0]);
}
$freshOrderIdIdx = array_search('OrderID', $freshHeader, true);
$freshInvoiceDateIdx = array_search('Invoice Date', $freshHeader, true);

$invoicedDate = date('Y-m-d');
$invoicedOrderIds = [];
if ($freshOrderIdIdx !== false && $freshInvoiceDateIdx !== false) {
    foreach ($freshRows as $i => &$row) {
        if ($i === 0) continue;
        if (in_array($row[$freshOrderIdIdx] ?? null, $groupOrderIds, true)) {
            $row[$freshInvoiceDateIdx] = $invoicedDate;
            $invoicedOrderIds[] = $row[$freshOrderIdIdx];
        }
    }
    unset($row);

    // Backup before writing. Create backups/ if it doesn't exist yet
    // instead of silently doing nothing - @copy() alone fails quietly
    // if the target directory is missing, which is why the backups
    // folder was empty (found 2026-07-26).
    $backupDir = __DIR__ . '/backups';
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0755, true);
    }
    if (is_dir($backupDir)) {
        @copy($csvFile, $backupDir . '/merchandise_' . date('Ymd_His') . '.csv');
    }

    rewind($handle);
    ftruncate($handle, 0);
    foreach ($freshRows as $row) {
        fputcsv($handle, $row, ",", '"', "\\");
    }
    fflush($handle);
}

flock($handle, LOCK_UN);
fclose($handle);

echo json_encode([
    'ok' => true,
    'invoicedOrderIds' => $invoicedOrderIds,
    'total' => $pricing['total'],
    'invoicedDate' => $invoicedDate,
]);
