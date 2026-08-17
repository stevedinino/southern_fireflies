<?php
// Build: 2026-08-15-B
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
//   - Created for EVERY item in the shipment (2026-08-15, per Steve) ->
//                            a shipment only ships once the whole box is
//                            ready. One un-printed item now holds back
//                            every other item bound for the same
//                            address, not just itself - checked AFTER
//                            grouping below, since it's a property of
//                            the whole shipment, not any single row.
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
// Item Weight / dimensions: shipments with NO Tool Stand, at most one
// Tape Gun Holder, and up to CIRCLE_OVAL_ALONE_MAX small items total
// now get real poly-mailer weight and dimensions filled in
// automatically - computed from ITEM_WEIGHT_OZ (real per-item-type
// weights, pricing.php) plus MAILER_TARE_OZ for packaging, not a
// per-count guess table. Same tier boundaries as invoicing, so this
// can't drift out of sync with what customers were actually charged
// for shipping. Anything with a Tool Stand, or more than one Tape Gun
// Holder, still ships in a scavenged one-off box or needs a hand-pack
// look, so those rows are deliberately left blank for Steve to
// weigh/measure by hand directly in Shippo.

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
    'Invoice Date', 'Pymt Date', 'Created',
] as $name) {
    $col[$name] = array_search($name, $header, true);
}
foreach (['OrderID', 'Pymt Date', 'Fulfillment', 'Fulfilled', 'Created'] as $required) {
    if ($col[$required] === false) {
        die("Expected column '{$required}' not found in merchandise.csv header - has it changed?");
    }
}

// ---- Filter to rows that are actually safe to make a label for ----
// This is just the raw per-row membership (paid, Ship, not yet
// Fulfilled) - the Created check happens AFTER grouping below, because
// it needs to be checked across the whole shipment, not one row at a
// time.
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

// ---- Only export shipments where EVERY item is Created ----
// A shipment ships as one box, so it only counts as ready once every
// item bound for that address is Created - one un-printed item holds
// the whole shipment back, not just its own row. Same rule as
// ourmerch.php's "Needs Shipping" view and packing_slips.php
// (2026-08-15, per Steve).
$groups = array_filter($groups, function ($groupRows) use ($col) {
    foreach ($groupRows as $row) {
        if (trim($row[$col['Created']] ?? '') === '') {
            return false;
        }
    }
    return true;
});

if (empty($groups)) {
    die('No shipments are fully ready to export yet. (Every item bound for the same address needs to be marked Created - not just this one - before that shipment will appear here.)');
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
    $mailerTierQty = 0;
    $tapeGunQty = 0;
    $mailerWeightOz = 0;
    foreach ($groupRows as $r) {
        $orderAmount += (float)($col['Price'] !== false ? ($r[$col['Price']] ?? 0) : 0);
        $rowItem = trim($r[$col['Item']] ?? '');
        $rowQty = (int)($r[$col['Quantity']] ?? 1);
        if ($rowItem === TOOL_STAND_ITEM) {
            $toolStandQty += $rowQty;
        } elseif (in_array($rowItem, MAILER_TIER_ITEMS, true)) {
            $mailerTierQty += $rowQty;
            if (isset(ITEM_WEIGHT_OZ[$rowItem])) {
                $mailerWeightOz += ITEM_WEIGHT_OZ[$rowItem] * $rowQty;
            }
            if ($rowItem === TAPE_GUN_ITEM) {
                $tapeGunQty += $rowQty;
            }
        }
    }

    // Same tiers as invoicing (merch_printed_shipping() in pricing.php):
    // a shipment with NO Tool Stand, at most one Tape Gun Holder (more
    // needs hand-packing, same bulky-item rule as multiple Tool Stands),
    // and up to CIRCLE_OVAL_ALONE_MAX small items total ships in one
    // poly mailer with a real computed weight (real per-item-type
    // weights as of 2026-08-10, not a per-count guess). Anything with a
    // Tool Stand, more than one Tape Gun Holder, or more small items
    // than a mailer holds, uses a scavenged one-off box or needs its
    // own look - Steve weighs/measures those by hand directly in
    // Shippo, so this export deliberately leaves those blank rather
    // than guessing.
    //
    // Package Height is filled in here too now (2026-08-15) - it used
    // to stay blank along with Width/Length whenever this whole block
    // was skipped, which was correct, but it ALSO used to stay blank
    // even when this block DID run and filled in Weight/Width/Length.
    // Shippo's bulk importer needs all three dimensions to create a
    // parcel, not just weight, so a mailer shipment with two out of
    // three dimensions filled in failed exactly the same "package
    // dimensions incomplete" way as a fully-blank one. POLY_MAILER_HEIGHT_IN
    // (pricing.php) is a nominal real-world estimate, not a placeholder.
    $orderWeight = '';
    $packageWidth = '';
    $packageHeight = '';
    $packageLength = '';
    if ($toolStandQty === 0 && $tapeGunQty <= TAPE_GUN_MAX_QTY
        && $mailerTierQty >= 1 && $mailerTierQty <= CIRCLE_OVAL_ALONE_MAX) {
        $orderWeight = $mailerWeightOz + MAILER_TARE_OZ;
        $packageWidth = POLY_MAILER_WIDTH_IN;
        $packageHeight = POLY_MAILER_HEIGHT_IN;
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
            $packageHeight, // blank unless mailer shipment (POLY_MAILER_HEIGHT_IN when filled - see comment above)
            $packageLength, // blank unless mailer shipment
            'in',
            round($orderAmount, 2),
            'USD',
        ]);
    }
}

fclose($out);
