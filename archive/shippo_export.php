<?php
// Build: 2026-07-26-H
// Admin-only download: reads merchandise.csv, filters to orders that
// are actually SAFE to buy a label for, and writes out a CSV in
// Shippo's bulk-import column format (per shippo_sample_csv_v3.csv).
//
// Safety filter (this is the whole point of this file):
//   - Paid = 'x'          -> money has actually landed. NOT "Invoiced"
//                            (that only means a total was emailed out,
//                            which has nothing to do with whether it
//                            was ever paid).
//   - Fulfillment = 'Ship' -> pickup orders don't need a label at all.
//   - Fulfilled is blank  -> already-shipped orders don't show up
//                            again in a later export. This reuses the
//                            Fulfilled checkbox you already check by
//                            hand after a label ships - no new column
//                            needed for that part.
//
// Rows that share the same customer AND destination address are
// grouped under one Shippo "Order Number", regardless of what day each
// one was invoiced - what matters for shipping is "hasn't shipped yet
// and going to the same place," not when you happened to click
// invoice/send. (Invoice Date grouping was tried first and produced
// spurious splits: two orders from the same person invoiced on
// different days came out as two separate shipments even though both
// were unshipped and going to the same address - fixed 2026-07-26.)
//
// Item Weight / dimensions: shipments with NO Tool Stand and 1-4
// Circle/Oval holders now get real poly-mailer weight and dimensions
// filled in automatically (see POLY_MAILER_WEIGHT_OZ in pricing.php -
// same tier boundaries as invoicing, so this can't drift out of sync
// with what customers were actually charged for shipping). Anything
// with a Tool Stand still ships in a scavenged one-off box, so those
// rows are deliberately left blank for Steve to weigh/measure by hand
// directly in Shippo.

session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/pricing.php';

if (empty($_SESSION['sff_admin_ok'])) {
    header('Location: ourmerch.php');
    exit;
}

$csvFile = __DIR__ . '/merchandise.csv';
if (!file_exists($csvFile)) {
    die('merchandise.csv not found.');
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    die('Could not open merchandise.csv.');
}

$rows = [];
while (($row = fgetcsv($handle)) !== false) {
    $rows[] = $row;
}
fclose($handle);

if (empty($rows)) {
    die('merchandise.csv is empty.');
}

$header = $rows[0];
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}

$col = [];
foreach ([
    'OrderID', 'Item', 'Quantity', 'Name', 'Fulfillment', 'Address', 'City',
    'State', 'Zip', 'Email', 'Phone', 'Color', 'Timestamp', 'Price', 'Fulfilled',
    'Invoice Date', 'Pymt Date',
] as $name) {
    $col[$name] = array_search($name, $header, true);
}
foreach (['OrderID', 'Pymt Date', 'Fulfillment', 'Fulfilled'] as $required) {
    if ($col[$required] === false) {
        die("Expected column '{$required}' not found in merchandise.csv header - has it changed?");
    }
}

// ---- Filter to rows that are actually safe to make a label for ----
$eligible = [];
foreach ($rows as $i => $row) {
    if ($i === 0) continue; // header

    // Pymt Date is the single source of truth for "actually paid" now
    // (the separate 'Paid' marker column was removed 2026-07-26 - a
    // date here already means paid, same as every other status column).
    $paid = $col['Pymt Date'] !== false ? trim($row[$col['Pymt Date']] ?? '') : '';
    $fulfillment = trim($row[$col['Fulfillment']] ?? '');
    $fulfilled = trim($row[$col['Fulfilled']] ?? '');

    if ($paid !== '' && $fulfillment === 'Ship' && $fulfilled === '') {
        $eligible[] = $row;
    }
}

if (empty($eligible)) {
    die('No paid, unshipped orders found to export. (Nothing shows up here until a row has Paid = x, Fulfillment = Ship, and Fulfilled is still blank.)');
}

// ---- Group into shipments: same customer (by name + zip), not email ----
// Tried email+address first and both turned out unreliable in practice:
// the same real customer sometimes types their address differently
// across separate orders ("St" vs "Street", "E" vs "East"), and in a
// couple of cases even used two different email addresses for two
// orders (one looked like an autofill glitch, one was genuinely aol vs.
// gmail). Name + zip, normalized (lowercased, whitespace collapsed),
// held up as the one thing that stayed consistent for the same person
// across every case actually found in the data (2026-07-26).
//
// Known tradeoff: two DIFFERENT customers who happen to share both a
// name and a zip code would incorrectly merge into one shipment. Given
// this export is always manually reviewed before buying labels anyway,
// that's an acceptable risk versus the alternative (real shipments
// splintering apart because of how someone abbreviated "Street").
$groups = []; // key -> array of rows
foreach ($eligible as $row) {
    $normalizedName = strtolower(trim(preg_replace('/\s+/', ' ', $row[$col['Name']] ?? '')));
    $zip = strtolower(trim($row[$col['Zip']] ?? ''));
    $key = $normalizedName . '|' . $zip;
    $groups[$key][] = $row;
}

// ---- Write the Shippo-format CSV ----
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="shippo_export_' . date('Y-m-d_His') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'Order Number', 'Order Date', 'Recipient Name', 'Company', 'Email', 'Phone',
    'Street Line 1', 'Street Number', 'Street Line 2', 'City', 'State/Province',
    'Zip/Postal Code', 'Country', 'Item Title', 'SKU', 'Quantity', 'Item Weight',
    'Item Weight Unit', 'Item Price', 'Item Currency', 'Order Weight',
    'Order Weight Unit', 'Package Width', 'Package Height', 'Package Length',
    'Package Dimension Unit', 'Order Amount', 'Order Currency',
]);

foreach ($groups as $groupRows) {
    // Lowest OrderID in the group becomes the Shippo Order Number, so
    // it's stable and traceable back to merchandise.csv.
    $orderIds = array_map(fn($r) => (int)($r[$col['OrderID']] ?? 0), $groupRows);
    $orderNumber = '#' . min($orderIds);

    $orderAmount = 0.0;
    $toolStandQty = 0;
    $circleOvalQty = 0;
    foreach ($groupRows as $r) {
        $orderAmount += (float)($col['Price'] !== false ? ($r[$col['Price']] ?? 0) : 0);
        $rowItem = trim($r[$col['Item']] ?? '');
        $rowQty = (int)($r[$col['Quantity']] ?? 1);
        if ($rowItem === TOOL_STAND_ITEM) {
            $toolStandQty += $rowQty;
        } elseif (in_array($rowItem, CIRCLE_OVAL_ITEMS, true)) {
            $circleOvalQty += $rowQty;
        }
    }

    // Same tiers as invoicing (merch_printed_shipping() in pricing.php):
    // a shipment with NO Tool Stand and 1-4 Circle/Oval holders ships in
    // one poly mailer with a known weight. Anything with a Tool Stand,
    // or more Circle/Oval than a mailer holds, uses a scavenged one-off
    // box - Steve weighs/measures those by hand directly in Shippo, so
    // this export deliberately leaves those blank rather than guessing.
    $orderWeight = '';
    $packageWidth = '';
    $packageLength = '';
    if ($toolStandQty === 0 && $circleOvalQty >= 1 && $circleOvalQty <= CIRCLE_OVAL_ALONE_MAX
        && isset(POLY_MAILER_WEIGHT_OZ[$circleOvalQty])) {
        $orderWeight = POLY_MAILER_WEIGHT_OZ[$circleOvalQty];
        $packageWidth = POLY_MAILER_WIDTH_IN;
        $packageLength = POLY_MAILER_LENGTH_IN;
    }

    foreach ($groupRows as $row) {
        $name = trim($row[$col['Name']] ?? '');
        $address = trim($row[$col['Address']] ?? '');
        $city = trim($row[$col['City']] ?? '');
        $state = trim($row[$col['State']] ?? '');
        $zip = trim($row[$col['Zip']] ?? '');
        $email = trim($row[$col['Email']] ?? '');
        $phone = trim($row[$col['Phone']] ?? '');
        $item = trim($row[$col['Item']] ?? '');
        $quantity = (int)($row[$col['Quantity']] ?? 1);
        $price = (float)($col['Price'] !== false ? ($row[$col['Price']] ?? 0) : 0);
        $unitPrice = $quantity > 0 ? round($price / $quantity, 2) : $price;

        $timestamp = $col['Timestamp'] !== false ? trim($row[$col['Timestamp']] ?? '') : '';
        $orderDate = $timestamp !== '' ? date('Y-m-d', strtotime($timestamp)) : '';
        $color = trim($row[$col['Color']] ?? '');

        fputcsv($out, [
            $orderNumber,
            $orderDate,
            $name,
            '', // Company
            $email,
            $phone,
            $address,
            '', // Street Number - included in Street Line 1 above
            '', // Street Line 2
            $city,
            $state,
            $zip,
            'US',
            $item,
            $color, // Repurposing SKU (not otherwise used) to carry Color, so it's visible for the inventory cross-check
            $quantity,
            '', // Item Weight - per-item weight isn't meaningful here; Order Weight below is the real total
            'oz',
            $unitPrice,
            'USD',
            $orderWeight,   // blank unless this is a no-Tool-Stand mailer shipment (see tier check above)
            'oz',
            $packageWidth,  // blank unless mailer shipment
            '',             // Package Height - poly mailers are flat; leaving blank rather than guessing a depth
            $packageLength, // blank unless mailer shipment
            'in',
            round($orderAmount, 2),
            'USD',
        ]);
    }
}

fclose($out);
