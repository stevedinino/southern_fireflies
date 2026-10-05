<?php
// Build: 2026-10-04-A
// ============================================================
// Admin-only endpoint behind the "Done" button on merch_stock_report.php's
// "Ready to ship from stock" pick-list (Steve, 2026-10-04).
//
// He ticks a checkbox per part as he gathers an order off the shelf; Done
// only enables when every part is ticked, and NOTHING is written until he
// clicks it. Clicking it does two things, together or not at all:
//   1. marks the rows that were filled from stock as Created in
//      merchandise.csv (Created = today, Qty Created = Quantity - exactly
//      what ticking "Created" in Merchandise Requests does; Fulfilled is
//      never touched - shipping is still its own step), and
//   2. takes those pieces out of inventory.csv.
//
// It trusts nothing from the page except which order(s) and the signature
// of what the page showed. Everything is recomputed from the live files
// under both locks, and refused (409, nothing written) if the order is no
// longer ready from stock exactly as shown: someone changed the order, the
// inventory was re-uploaded, another Done used the pieces, etc.
//
// Same write pattern as every other endpoint here: admin + CSRF, release
// the session lock, 'c+' + flock(LOCK_EX), backup right before writing,
// truncate and rewrite, unlock. Two files are involved, always locked in
// the same order (merchandise.csv, then inventory.csv) so it can't
// deadlock with anything else, and if the second write fails the first is
// put back.
// ============================================================

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
header('Content-Type: application/json');
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_shipments.php';
require __DIR__ . '/print_plates.php';
require __DIR__ . '/merch_stock.php';
require __DIR__ . '/merch_backup.php';

merch_require_admin_json();
merch_require_csrf_json();
session_write_close();

function merch_stock_ship_fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ---- Input ------------------------------------------------------
$rawIds = array_map('trim', explode(',', (string) ($_POST['orderIds'] ?? '')));
$rawIds = array_values(array_filter($rawIds, fn($v) => $v !== ''));
foreach ($rawIds as $v) {
    if (!ctype_digit($v)) {
        merch_stock_ship_fail(400, 'Invalid request.');
    }
}
$postedIds = array_values(array_unique($rawIds));
sort($postedIds, SORT_STRING);
$postedSig = trim((string) ($_POST['sig'] ?? ''));
if (empty($postedIds) || count($postedIds) > 50 || $postedSig === '') {
    merch_stock_ship_fail(400, 'Invalid request.');
}

$csvFile = __DIR__ . '/merchandise.csv';
$invFile = __DIR__ . '/inventory.csv';
$backupDir = __DIR__ . '/backups';

if (!is_file($invFile)) {
    merch_stock_ship_fail(409, 'There is no inventory file yet - upload one first.');
}

// ---- Lock both files (always in this order) ----------------------
$mh = fopen($csvFile, 'c+');
if (!$mh) {
    merch_stock_ship_fail(500, 'Could not open merchandise.csv.');
}
if (!flock($mh, LOCK_EX)) {
    fclose($mh);
    merch_stock_ship_fail(500, 'Could not lock merchandise.csv - try again.');
}
$ih = fopen($invFile, 'c+');
if (!$ih || !flock($ih, LOCK_EX)) {
    if ($ih) {
        fclose($ih);
    }
    flock($mh, LOCK_UN);
    fclose($mh);
    merch_stock_ship_fail(500, 'Could not lock inventory.csv - try again.');
}

// Every exit below goes through here so both locks are always released.
$bail = function (int $code, string $msg) use ($mh, $ih): void {
    flock($ih, LOCK_UN);
    fclose($ih);
    flock($mh, LOCK_UN);
    fclose($mh);
    merch_stock_ship_fail($code, $msg);
};

// ---- Read both ---------------------------------------------------
$origMerch = (string) stream_get_contents($mh);
rewind($mh);
$rows = [];
while (($r = fgetcsv($mh)) !== false) {
    $rows[] = $r;
}
if (empty($rows)) {
    $bail(500, 'merchandise.csv is empty.');
}
$header = $rows[0];
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}
$col = [];
foreach (['OrderID', 'Name', 'Zip', 'Item', 'Quantity', 'Color', 'Fulfillment', 'Pymt Date', 'Created', 'Fulfilled', 'Cancelled', 'Qty Created'] as $name) {
    $col[$name] = array_search($name, $header, true);
}
foreach (['OrderID', 'Quantity', 'Created', 'Qty Created'] as $must) {
    if ($col[$must] === false) {
        $bail(500, "Expected column '{$must}' not found in merchandise.csv - has the header changed?");
    }
}

$origInv = (string) stream_get_contents($ih);
rewind($ih);
$invRows = [];
while (($r = fgetcsv($ih, 0, ',', '"', '\\')) !== false) {
    $invRows[] = $r;
}

// ---- Is this order still ready from stock, exactly as shown? -----
$dataRows = array_slice($rows, 1);
$built = merch_stock_build_shipments($dataRows, $col, FILAMENT_COLOR_ITEMS, PRINT_PLATE_EXCLUDED_COLORS);
$shipment = null;
foreach ($built['shipments'] as $s) {
    if (in_array($postedIds[0], array_map('strval', $s['lines'] ? array_column($s['lines'], 'orderId') : []), true)) {
        $shipment = $s;
        break;
    }
}
if ($shipment === null) {
    $bail(404, 'That order is no longer waiting to be pulled from stock - reload the page.');
}
if (!empty($shipment['other'])) {
    $bail(409, 'That order also has a shirt/hat that is not Created yet, so it cannot be completed from stock.');
}
if (merch_stock_shipment_commit_ids($shipment) !== $postedIds || merch_stock_shipment_signature($shipment) !== $postedSig) {
    $bail(409, 'That order changed since the page loaded - reload the page and check it again.');
}

$needs = merch_stock_shipment_needs($shipment);
$dec = merch_stock_decrement_grid($invRows, $needs, FILAMENT_COLOR_ITEMS, FILAMENT_COLORS);
if (!$dec['ok']) {
    $bail(409, $dec['error']);
}

// ---- Apply (in memory) -------------------------------------------
$today = date('Y-m-d');
$marked = [];
$postedSet = array_flip($postedIds);
foreach ($rows as $i => &$row) {
    if ($i === 0) {
        continue;
    }
    $id = (string) ($row[$col['OrderID']] ?? '');
    if ($id !== '' && isset($postedSet[$id])) {
        merch_stock_apply_created($row, $col, $today);
        $marked[] = $id;
    }
}
unset($row);
if (count($marked) !== count($postedIds)) {
    $bail(409, 'Could not find every row of that order - reload the page.');
}

// ---- Back up both, then write both -------------------------------
merch_backup_csv($csvFile, $backupDir);
merch_stock_backup_inventory($invFile, $backupDir);

$writeAll = function ($handle, array $outRows, array $csvArgs): bool {
    if (!rewind($handle) || !ftruncate($handle, 0)) {
        return false;
    }
    foreach ($outRows as $r) {
        if (fputcsv($handle, $r, ...$csvArgs) === false) {
            return false;
        }
    }
    return fflush($handle);
};
$putBack = function ($handle, string $contents): void {
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, $contents);
    fflush($handle);
};

$okMerch = $writeAll($mh, $rows, [',', '"', '\\']);
if (!$okMerch) {
    $putBack($mh, $origMerch);
    error_log('merch_stock_ship: could not write merchandise.csv - restored');
    $bail(500, 'Could not save merchandise.csv - nothing was changed.');
}
$okInv = $writeAll($ih, $dec['rows'], [',', '"', '\\']);
if (!$okInv) {
    $putBack($ih, $origInv);
    $putBack($mh, $origMerch);
    error_log('merch_stock_ship: could not write inventory.csv - both restored');
    $bail(500, 'Could not save inventory.csv - nothing was changed.');
}

flock($ih, LOCK_UN);
fclose($ih);
flock($mh, LOCK_UN);
fclose($mh);

$pulled = [];
foreach ($needs as $item => $byColor) {
    foreach ($byColor as $color => $q) {
        $pulled[] = ['item' => $item, 'color' => $color, 'qty' => $q];
    }
}
echo json_encode([
    'ok' => true,
    'created' => $marked,
    'pulled' => $pulled,
    'customer' => $shipment['name'],
    'build' => '2026-10-04-A',
]);
