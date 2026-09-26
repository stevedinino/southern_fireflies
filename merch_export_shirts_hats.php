<?php
// Build: 2026-09-26-A
// 2026-09-26 (Steve, for Janet): "We have several shirt orders outstanding
// in the merchandise list, but I don't have a way to give those to Janet
// to have them made. I know she gets emails - but those are lost in the
// flood of communication she gets daily." Admin-only CSV download of the
// shirts and hats still waiting to be made - the things Janet makes,
// everything else on the list being Steve's own 3D prints.
//
// "Shirts and hats" is GILDAN_COLOR_ITEMS (the items whose color list is
// the Gildan garment chart - see pricing.php/merch_items.php), the same
// definition the rest of the site uses, so a new shirt or hat added under
// /items/ shows up here without touching this file.
//
// A row is included while it is NOT Created, NOT Fulfilled and NOT
// Cancelled. Paid orders sort first, and a Paid? column says which are
// which - Steve doesn't start on unpaid orders and Janet may want to
// follow the same rule, but that's her call, so unpaid ones are listed
// rather than hidden.
//
// Deliberately leaves out email, phone and street address: Janet makes
// the item, Steve ships it, and she doesn't need any of those to do that.
//
// Same admin session gate as export_emails.php/shippo_export.php - not a
// public endpoint. Customer-supplied cells go through csv_safety.php's
// merch_csv_safe_cell(), same as those two exports (this one is read in
// Excel by a person, but the same rule costs nothing here). A UTF-8 BOM
// is written first so Excel shows the retreat names' en dashes and any
// accented names correctly instead of mojibake.

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require __DIR__ . '/pricing.php';
require __DIR__ . '/csv_safety.php';

merch_require_admin_redirect('ourmerch.php');

$csvFile = __DIR__ . '/merchandise.csv';
if (!file_exists($csvFile)) {
    die('merchandise.csv not found.');
}
$handle = fopen($csvFile, 'r');
if (!$handle) {
    die('Could not open merchandise.csv.');
}
$rows = [];
while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
    $rows[] = $row;
}
fclose($handle);
if (empty($rows)) {
    die('merchandise.csv is empty.');
}

$header = $rows[0];
// Same BOM issue as every other admin file that reads this CSV.
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}
$col = [];
foreach (['OrderID', 'Name', 'Item', 'Quantity', 'Color', 'Size', 'Sleeve', 'Fulfillment', 'Retreat', 'Timestamp', 'Pymt Date', 'Created', 'Fulfilled', 'Cancelled', 'Notes'] as $name) {
    $col[$name] = array_search($name, $header, true);
}
// Optional columns (older CSVs may not have them yet) just read as blank;
// these three are what the export can't work without.
foreach (['OrderID', 'Name', 'Item'] as $required) {
    if ($col[$required] === false) {
        die('Expected column not found in merchandise.csv: ' . $required);
    }
}
$cell = function (array $row, string $name) use ($col): string {
    return $col[$name] === false ? '' : trim($row[$col[$name]] ?? '');
};

$out = [];
foreach ($rows as $i => $row) {
    if ($i === 0) {
        continue; // header
    }
    if (!in_array($cell($row, 'Item'), GILDAN_COLOR_ITEMS, true)) {
        continue; // not a shirt or hat
    }
    if ($cell($row, 'Cancelled') !== '' || $cell($row, 'Created') !== '' || $cell($row, 'Fulfilled') !== '') {
        continue; // cancelled, or already made - nothing left for Janet to do
    }

    // Timestamp is "2026-09-09 03:38:18" on newer rows and "8/13/2026 18:37"
    // on older hand-migrated ones - show just the date, normalized when PHP
    // can parse it, as written when it can't.
    $orderedRaw = trim(explode(' ', $cell($row, 'Timestamp'))[0]);
    $orderedTs = $orderedRaw !== '' ? strtotime($orderedRaw) : false;
    $ordered = $orderedTs !== false ? date('Y-m-d', $orderedTs) : $orderedRaw;

    $isPaid = $cell($row, 'Pymt Date') !== '';
    $out[] = [
        'paid' => $isPaid,
        'orderId' => (int) $cell($row, 'OrderID'),
        'cells' => [
            $cell($row, 'OrderID'),
            $cell($row, 'Name'),
            $cell($row, 'Item'),
            $cell($row, 'Quantity'),
            $cell($row, 'Color'),
            $cell($row, 'Size'),
            $cell($row, 'Sleeve'),
            stripos($cell($row, 'Fulfillment'), 'pickup') === 0 ? 'Pickup' : 'Ship',
            $cell($row, 'Retreat'),
            $ordered,
            $isPaid ? 'Yes' : 'No',
            $cell($row, 'Notes'),
        ],
    ];
}

// Paid first, then oldest order first.
usort($out, function ($a, $b) {
    if ($a['paid'] !== $b['paid']) {
        return $a['paid'] ? -1 : 1;
    }
    return $a['orderId'] <=> $b['orderId'];
});

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="shirts_hats_to_make_' . date('Y-m-d') . '.csv"');

$fh = fopen('php://output', 'w');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, ['Order #', 'Customer', 'Item', 'Qty', 'Color', 'Size', 'Sleeve', 'Ship or Pickup', 'Retreat (pickup)', 'Ordered', 'Paid?', 'Notes'], ',', '"', '\\');
foreach ($out as $r) {
    fputcsv($fh, array_map('merch_csv_safe_cell', $r['cells']), ',', '"', '\\');
}
fclose($fh);
