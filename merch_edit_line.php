<?php
// Build: 2026-08-29-A
// Edits a single not-yet-invoiced line's Item/Quantity/Color/Size/
// Sleeve in place, called via fetch() from ourmerch.php's Item-column
// "Edit" button.
//
// 2026-08-23 (Steve, #2): "I ordered two of x, meant x and y" - a
// data-entry correction (two separate rows already exist, one item per
// row - see merch_invoice.php's grouping comment - and one row's Item
// was simply wrong), not a customer color-change. Previously the only
// fix was "the editor on the host": hand-typing a new Item string AND
// a new Price directly into merchandise.csv, with nothing checking that
// the item name was real or that the price was right. This reuses
// merch_order.php's OWN validation (item recognized, color allowed for
// the item, size/sleeve only for shirts) and pricing
// (merch_group_calculate(), one line) instead - the same math a
// brand-new order gets, never a hand-typed number. Same admin session
// gate, same flock+backup discipline as every other write here.
//
// Only allowed pre-invoice, same reasoning as the Cancel/Send-Invoice
// guard elsewhere in this codebase: an already-sent invoice shouldn't
// silently stop matching what's in the file. A post-invoice correction
// goes through Cancel instead.
//
// Original Color and Fulfillment are deliberately left untouched -
// Original Color is a record of the CUSTOMER's own original color
// request (see merch_order.php's comment on it), not something a
// Steve-side item correction should overwrite; Fulfillment (Ship vs.
// Pickup at retreat) isn't part of what this form edits at all.
//
// Note: the Price/Tax/Shipping this writes are bookkeeping snapshots
// only, same as merch_order.php's - merch_invoice.php always
// recomputes the real total fresh from Item/Quantity/Color/Size/Sleeve
// at send time (never reads these columns), so there's no risk of a
// stale per-line Shipping value (which can legitimately be blank/null
// for a single line - the real shipping tier is a property of the
// whole combined order) ever reaching a customer.

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_backup.php';

header('Content-Type: application/json');

merch_require_admin_json();
// 2026-08-29 (Finding 9): ourmerch.php's Item-edit "Save" button
// carries MERCH_CSRF_TOKEN now - see csrf.php.
merch_require_csrf_json();

$csvFile = __DIR__ . '/merchandise.csv';

$orderId = isset($_POST['orderId']) ? trim($_POST['orderId']) : '';
$item = isset($_POST['item']) ? trim($_POST['item']) : '';
$quantityRaw = isset($_POST['quantity']) ? trim($_POST['quantity']) : '';
$color = isset($_POST['color']) ? trim($_POST['color']) : '';
$size = isset($_POST['size']) ? trim($_POST['size']) : '';
$sleeve = isset($_POST['sleeve']) ? trim($_POST['sleeve']) : '';

if ($orderId === '' || !ctype_digit($orderId) || $item === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

// Same quantity validation as merch_order.php - a positive integer, no
// silent clamping, and capped at MAX_QUANTITY.
$quantity = filter_var($quantityRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($quantity === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Quantity must be a whole number of 1 or more.']);
    exit;
}
if ($quantity > MAX_QUANTITY) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Quantity is above the maximum allowed (' . MAX_QUANTITY . ').']);
    exit;
}

// Same color validation as merch_order.php: an item with no color
// choice at all silently drops whatever was submitted rather than
// trusting it; an item WITH color choices must get one from that
// item's own allowed list (or blank, to explicitly clear it).
$allowedColors = merch_color_options_for_item($item);
if (empty($allowedColors)) {
    $color = '';
} elseif ($color !== '' && !in_array($color, $allowedColors, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Not a valid color choice for this item.']);
    exit;
}

// Same size/sleeve validation as merch_order.php: only shirts get a
// choice at all; everything else is forced blank regardless of what
// was submitted.
if (in_array($item, SHIRT_ITEMS, true)) {
    if ($size !== '' && !in_array($size, MERCH_SIZES, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Not a valid size for this item.']);
        exit;
    }
    if ($sleeve !== '' && !in_array($sleeve, MERCH_SLEEVE_LENGTHS, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Not a valid sleeve length for this item.']);
        exit;
    }
} else {
    $size = '';
    $sleeve = '';
}

$handle = fopen($csvFile, 'c+');
if (!$handle) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not open file.']);
    exit;
}

// Locking matters here for the same reason it matters in merch_order.php
// and merch_update.php: without it, an order submitted (or another
// admin action) mid-read-modify-write could get silently dropped when
// this script writes its stale copy back.
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
// Same BOM issue as every other reader/writer of this file.
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}

$col = [];
foreach (['OrderID', 'Item', 'Quantity', 'Color', 'Size', 'Sleeve', 'Fulfillment', 'Invoice Date', 'Price', 'Tax', 'Shipping'] as $name) {
    $col[$name] = array_search($name, $header, true);
}
// Size/Sleeve/Price/Tax/Shipping are allowed to be missing (written
// conditionally below, same optional-column tolerance as elsewhere in
// this codebase) - a live CSV missing OrderID/Item/Quantity/Color/
// Fulfillment/Invoice Date has bigger problems than this endpoint, so
// those six fail loudly instead.
foreach (['OrderID', 'Item', 'Quantity', 'Color', 'Fulfillment', 'Invoice Date'] as $required) {
    if ($col[$required] === false) {
        flock($handle, LOCK_UN);
        fclose($handle);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Expected column not found in CSV header - has the header changed?']);
        exit;
    }
}

$foundIndex = null;
foreach ($rows as $i => $row) {
    if ($i === 0) {
        continue; // header
    }
    if (isset($row[$col['OrderID']]) && $row[$col['OrderID']] === $orderId) {
        $foundIndex = $i;
        break;
    }
}

if ($foundIndex === null) {
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Order not found - the page may be out of date, try refreshing.']);
    exit;
}

// Only pre-invoice - see the file-level comment above.
if (trim($rows[$foundIndex][$col['Invoice Date']] ?? '') !== '') {
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "This order has already been invoiced and can't be edited here - cancel it instead."]);
    exit;
}

// Price this single line exactly the way merch_order.php prices a
// brand-new one - one place this math happens, never a hand-typed
// number. Fulfillment comes from the row itself (this form doesn't
// edit it), same as every other field this endpoint leaves alone.
$fulfillment = trim($rows[$foundIndex][$col['Fulfillment']] ?? '');
$isShipping = ($fulfillment === 'Ship');
$isPrinted = merch_is_printed_item($item);
$pricing = merch_group_calculate(
    [['item' => $item, 'quantity' => $quantity, 'size' => $size, 'sleeve' => $sleeve, 'color' => $color]],
    $isShipping,
    $isPrinted
);
if ($pricing === null) {
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Item not recognized.']);
    exit;
}

$rows[$foundIndex][$col['Item']] = $item;
$rows[$foundIndex][$col['Quantity']] = $quantity;
$rows[$foundIndex][$col['Color']] = $color;
if ($col['Size'] !== false) {
    $rows[$foundIndex][$col['Size']] = $size;
}
if ($col['Sleeve'] !== false) {
    $rows[$foundIndex][$col['Sleeve']] = $sleeve;
}
if ($col['Price'] !== false) {
    $rows[$foundIndex][$col['Price']] = $pricing['subtotal'];
}
if ($col['Tax'] !== false) {
    $rows[$foundIndex][$col['Tax']] = $pricing['tax'];
}
if ($col['Shipping'] !== false) {
    $rows[$foundIndex][$col['Shipping']] = $pricing['shipping'] ?? '';
}

// Backup before writing - same insurance every other write path in
// this file's neighborhood takes.
merch_backup_csv($csvFile, __DIR__ . '/backups');

rewind($handle);
ftruncate($handle, 0);
foreach ($rows as $row) {
    fputcsv($handle, $row, ",", '"', "\\");
}
fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);

echo json_encode(['ok' => true]);
