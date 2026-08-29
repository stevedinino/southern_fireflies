<?php
// Build: 2026-08-25-A
// Admin-triggered: given ONE OrderID (the row the button was clicked
// on), regenerates a printable "paid in full" receipt for that
// customer's already-invoiced, already-paid Pickup at Retreat orders -
// something to hand over alongside the merchandise at the retreat so a
// prepaid customer (and whoever's working the pickup table) has paper
// proof nothing is owed, instead of relying on memory or the admin
// table.
//
// 2026-08-25 (Steve): several pickup customers pay ahead of time -
// sometimes before Steve even gets to generating the real invoice
// (Pymt Date can predate Invoice Date in the CSV for exactly this
// reason) - and there was no document for that at all. merch_invoice.php
// only ever generates ONE document per order, at the moment it's first
// invoiced (see its "no email at all, generate a printable document"
// branch), and refuses to run again once Invoice Date is set. That's
// correct for the original invoice, but it means there's no way to
// print anything for a customer after the fact. This is that separate,
// repeatable path - it can be clicked as many times as needed (Steve
// misplaces the printout, wants a second copy, etc.) since unlike
// merch_invoice.php it makes NO changes to merchandise.csv at all: no
// Invoice Date, no Pymt Date, nothing stamped. Purely a read-and-render.
//
// Grouping mirrors merch_invoice.php's own identity/fulfillment/
// printed-vs-shop matching (see that file for why: blank-email legacy
// rows fall back to Name, printed items keep separate from shop items),
// but the eligibility condition is inverted - this wants rows that ARE
// already invoiced AND already paid, not ones still waiting on either,
// so a customer's already-settled pickup orders regroup into the same
// batch here as they did when the real invoice combined them.
//
// Deliberately refuses anything that isn't Fulfillment = Pickup at
// retreat: Ship customers already got a real emailed invoice/receipt,
// and there's no in-person hand-off moment for them that this document
// would accompany.

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
header('Content-Type: application/json');
require __DIR__ . '/pricing.php';
require_once __DIR__ . '/strings.php';

// Shared implementation in admin_guard.php as of 2026-08-20 (Finding
// 11, 2026-08-19 code review) - was previously duplicated here and in
// merch_update.php/merch_invoice.php.
merch_require_admin_json();

$csvFile = __DIR__ . '/merchandise.csv';

$orderId = isset($_POST['orderId']) ? trim($_POST['orderId']) : '';
if ($orderId === '' || !ctype_digit($orderId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

// Read-only end to end - no flock/write phase at all, unlike
// merch_invoice.php, since nothing here is ever written back.
$handle = fopen($csvFile, 'r');
if (!$handle) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not open file.']);
    exit;
}
$rows = [];
while (($row = fgetcsv($handle)) !== false) {
    $rows[] = $row;
}
fclose($handle);

if (empty($rows)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'File is empty.']);
    exit;
}

$header = $rows[0];
// Same BOM issue as every other reader in this codebase.
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}

$col = [];
foreach (['OrderID', 'Item', 'Quantity', 'Name', 'Fulfillment', 'Email', 'Color', 'Size', 'Sleeve', 'Invoice Date', 'Pymt Date', 'Cancelled'] as $name) {
    $col[$name] = array_search($name, $header, true);
}
if ($col['OrderID'] === false || $col['Invoice Date'] === false || $col['Pymt Date'] === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Expected column not found - has the CSV header changed? (Does it have Invoice Date and Pymt Date columns?)']);
    exit;
}
// Cancelled is allowed to be missing entirely (same "nothing is
// cancelled" default as everywhere else this is checked).

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

$anchorFulfillment = trim($anchor[$col['Fulfillment']] ?? '');
if ($anchorFulfillment !== 'Pickup at retreat') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Paid receipts are only for Pickup at Retreat orders.']);
    exit;
}
if (trim($anchor[$col['Invoice Date']] ?? '') === '' || trim($anchor[$col['Pymt Date']] ?? '') === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'This order needs to be both invoiced and paid before a paid receipt can be printed for it.']);
    exit;
}
if ($col['Cancelled'] !== false && trim($anchor[$col['Cancelled']] ?? '') !== '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'This order was cancelled.']);
    exit;
}

$anchorEmail = strtolower(trim($anchor[$col['Email']] ?? ''));
$anchorName = strtolower(trim($anchor[$col['Name']] ?? ''));
$anchorIsPrinted = merch_is_printed_item(trim($anchor[$col['Item']] ?? ''));

// Same legacy-blank-email fallback as merch_invoice.php - see that
// file's comment for the 2026-08-11 bug this guards against (unrelated
// customers who both left Email blank getting swept together).
$groupByName = ($anchorEmail === '');

$groupOrderIds = [];
$items = [];
foreach ($rows as $i => $row) {
    if ($i === 0) continue;
    $email = strtolower(trim($row[$col['Email']] ?? ''));
    $name = strtolower(trim($row[$col['Name']] ?? ''));
    $fulfillment = trim($row[$col['Fulfillment']] ?? '');
    $item = trim($row[$col['Item']] ?? '');
    $invoiced = trim($row[$col['Invoice Date']] ?? '');
    $paid = trim($row[$col['Pymt Date']] ?? '');
    $cancelled = $col['Cancelled'] !== false && trim($row[$col['Cancelled']] ?? '') !== '';

    $identityMatches = $groupByName
        ? ($name !== '' && $name === $anchorName)
        : ($email === $anchorEmail);

    // Inverted from merch_invoice.php's grouping condition on purpose:
    // this wants the customer's already-invoiced AND already-paid
    // pickup orders, not the still-pending ones.
    if ($identityMatches
        && $fulfillment === 'Pickup at retreat'
        && merch_is_printed_item($item) === $anchorIsPrinted
        && $invoiced !== ''
        && $paid !== ''
        && !$cancelled) {
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

// Pickup orders never carry shipping cost - same false as
// merch_invoice.php's pickup branch.
$pricing = merch_group_calculate($items, false, $anchorIsPrinted);
if ($pricing === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not price one of the items - check the CSV for a typo in an Item value.']);
    exit;
}

$name = trim($anchor[$col['Name']] ?? '');
$money = fn($n) => '$' . number_format((float) $n, 2);
$lineItemsText = '';
// Same 1:1 zip-by-index as merch_invoice.php - $items and
// $pricing['lines'] come from the same source array in the same order.
foreach ($pricing['lines'] as $i => $line) {
    $qtyLabel = $line['quantity'] > 1 ? " (x{$line['quantity']})" : '';
    $details = [];
    if (!empty($items[$i]['color'])) $details[] = $items[$i]['color'];
    if (!empty($items[$i]['size'])) $details[] = $items[$i]['size'];
    if (!empty($items[$i]['sleeve'])) $details[] = $items[$i]['sleeve'];
    $detailLabel = $details ? ' - ' . implode(', ', $details) : '';
    $lineItemsText .= '- ' . $line['item'] . $qtyLabel . $detailLabel . ': ' . $money($line['lineSubtotal']) . "\n";
}

$document = merch_load_string('shipping/pickup-paid-receipt-template', [
    'name' => $name,
    'date' => date('Y-m-d'),
    'orderIds' => implode(', ', $groupOrderIds),
    'lineItemsText' => rtrim($lineItemsText),
    'subtotal' => $money($pricing['subtotal']),
    'discountLineText' => !empty($pricing['bundleDiscount'])
        ? 'Bundle discount: -' . $money($pricing['bundleDiscount']) . "\n"
        : '',
    'tax' => $money($pricing['tax']),
    'total' => $money($pricing['total']),
]);

$safeNameForFilename = preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
echo json_encode([
    'ok' => true,
    'downloadDocument' => $document,
    'downloadFilename' => "paid-receipt-{$safeNameForFilename}-" . date('Y-m-d') . ".txt",
]);
