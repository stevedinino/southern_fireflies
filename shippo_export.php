<?php
// Build: 2026-08-23-A
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
//   - Not Cancelled (2026-08-23) -> a cancelled row should never reach
//                            a shipping label, however it got here -
//                            Paid+Ship+not-yet-Fulfilled is rare for a
//                            cancelled order, but not impossible (e.g.
//                            cancelling after an accidental duplicate
//                            payment). See ourmerch.php/merch_update.php
//                            for where Cancelled itself is set.
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
// Item Weight / dimensions: shipments with NO box-base item (Tool
// Stand), no bulky-item cap exceeded (at most one Tape Gun Holder),
// and up to MAILER_TIER_ALONE_MAX small items total
// now get real poly-mailer weight and dimensions filled in
// automatically - computed from ITEM_WEIGHT_OZ (real per-item-type
// weights, derived from the class definitions in pricing.php) plus
// MAILER_TARE_OZ for packaging, not a per-count guess table. Same
// tier boundaries as invoicing, so this can't drift out of sync with
// what customers were actually charged for shipping. Anything with a
// box-base item or an exceeded cap still ships in a scavenged one-off
// box or needs a hand-pack look, so those rows are deliberately left
// blank for Steve to weigh/measure by hand directly in Shippo.
//
// 2026-08-19: the per-row Item Weight column (previously always left
// blank, since Order Weight above was the only total that mattered for
// the automatic mailer tier) now also gets filled in per-unit from that
// same ITEM_WEIGHT_OZ table, whenever a real number exists for that
// item - including Tool Holder Stand (its weight was added to the
// table for exactly this column; it still never feeds the mailer-tier
// Order Weight math above), blank for any shirt/hat since those have
// no per-unit weight on file. Per Steve: seeing each line item's own
// weight in Shippo (not just the mailer-tier group total) makes it
// easier to hand-group items into a box together. (Comment corrected
// 2026-08-21 - it previously claimed Tool Stand stayed blank here,
// which the code never did once its weight entered the table.)

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_shipments.php';
require __DIR__ . '/csv_safety.php';

// Shared implementation in admin_guard.php as of 2026-08-20 (Finding
// 11, 2026-08-19 code review) - was previously duplicated across 8 files.
merch_require_admin_redirect('ourmerch.php');

$csvFile = __DIR__ . '/merchandise.csv';
// Shared with packing_slips.php as of 2026-08-20 (Finding 10, same
// review) - see merch_shipments.php for why.
$loaded = merch_load_csv($csvFile, 'merchandise.csv');
$rows = $loaded['rows'];
$col = merch_csv_column_map($loaded['header'], [
    'OrderID', 'Item', 'Quantity', 'Name', 'Fulfillment', 'Address', 'City',
    'State', 'Zip', 'Email', 'Phone', 'Color', 'Timestamp', 'Price', 'Fulfilled',
    'Invoice Date', 'Pymt Date', 'Created', 'Cancelled',
], ['OrderID', 'Pymt Date', 'Fulfillment', 'Fulfilled', 'Created'], 'merchandise.csv');

// ---- Filter to rows that are actually safe to make a label for ----
// This is just the raw per-row membership (paid, Ship, not yet
// Fulfilled, not Cancelled) - the Created check happens AFTER grouping
// below, because it needs to be checked across the whole shipment, not
// one row at a time.
$eligible = [];
foreach ($rows as $row) {
    // Pymt Date is the single source of truth for "actually paid" now
    // (the separate 'Paid' marker column was removed 2026-07-26 - a
    // date here already means paid, same as every other status column).
    $paid = $col['Pymt Date'] !== false ? trim($row[$col['Pymt Date']] ?? '') : '';
    $fulfillment = trim($row[$col['Fulfillment']] ?? '');
    $fulfilled = trim($row[$col['Fulfilled']] ?? '');
    // Cancelled is optional (Steve may not have added the column to the
    // live CSV yet) - missing entirely means "nothing's cancelled,"
    // same convention as every other optional lookup in this file
    // (Price, below, follows the same $col['X'] !== false pattern).
    $cancelled = $col['Cancelled'] !== false && trim($row[$col['Cancelled']] ?? '') !== '';

    if ($paid !== '' && $fulfillment === 'Ship' && $fulfilled === '' && !$cancelled) {
        $eligible[] = $row;
    }
}

if (empty($eligible)) {
    die('No paid, unshipped orders found to export. (Nothing shows up here until a row has a Pymt Date, Fulfillment = Ship, and Fulfilled is still blank.)');
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
// Shared with packing_slips.php as of 2026-08-20 (Finding 10, same
// review) - see merch_shipments.php for the grouping-key formula and
// its known tradeoff.
$groups = merch_group_shipments($eligible, $col);

// ---- Only export shipments where EVERY item is Created ----
// A shipment ships as one box, so it only counts as ready once every
// item bound for that address is Created - one un-printed item holds
// the whole shipment back, not just its own row. Same rule as
// ourmerch.php's "Needs Shipping" view and packing_slips.php
// (2026-08-15, per Steve).
$groups = merch_split_groups_by_created($groups, $col)['complete'];

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
    $boxBaseQty = 0;
    $mailerTierQty = 0;
    $qtyByItem = []; // per-item totals for the bulky-item caps (2026-08-21)
    $mailerWeightOz = 0;
    foreach ($groupRows as $r) {
        $orderAmount += (float)($col['Price'] !== false ? ($r[$col['Price']] ?? 0) : 0);
        $rowItem = trim($r[$col['Item']] ?? '');
        $rowQty = (int)($r[$col['Quantity']] ?? 1);
        if (in_array($rowItem, BOX_BASE_ITEMS, true)) {
            $boxBaseQty += $rowQty;
        } elseif (in_array($rowItem, MAILER_TIER_ITEMS, true)) {
            $mailerTierQty += $rowQty;
            if (isset(ITEM_WEIGHT_OZ[$rowItem])) {
                $mailerWeightOz += ITEM_WEIGHT_OZ[$rowItem] * $rowQty;
            }
        }
        $qtyByItem[$rowItem] = ($qtyByItem[$rowItem] ?? 0) + $rowQty;
    }

    // Same tiers as invoicing (merch_printed_shipping() in pricing.php):
    // a shipment with NO box-base item (Tool Stand), no bulky-item cap
    // exceeded (merch_shipment_cap_note() - the same per-class
    // max_qty_per_shipment rule invoicing uses, today meaning at most
    // one Tape Gun Holder), and up to MAILER_TIER_ALONE_MAX small
    // items total ships in one poly mailer with a real computed weight
    // (real per-item-type weights as of 2026-08-10, not a per-count
    // guess). Anything with a box-base item, an exceeded cap, or more
    // small items than a mailer holds, uses a scavenged one-off box or
    // needs its own look - Steve weighs/measures those by hand
    // directly in Shippo, so this export deliberately leaves those
    // blank rather than guessing.
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
    if ($boxBaseQty === 0 && merch_shipment_cap_note($qtyByItem) === null
        && $mailerTierQty >= 1 && $mailerTierQty <= MAILER_TIER_ALONE_MAX) {
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
        // 2026-08-19: per-unit Item Weight, when a real number exists for
        // this item (ITEM_WEIGHT_OZ, pricing.php - the same weights
        // Order Weight above is built from). Blank for Tool Holder Stand
        // and any shirt/hat - those don't have a real per-unit weight on
        // file, so this stays blank rather than guessing, same principle
        // as Order Weight/Package dimensions above. Filling this in lets
        // Steve see each item's individual weight in Shippo when hand-
        // grouping items into a box together.
        $itemWeight = ITEM_WEIGHT_OZ[$item] ?? '';

        // 2026-08-29 (code review Finding 8): every customer-supplied
        // free-text field below goes through merch_csv_safe_cell()
        // (csv_safety.php) before fputcsv - see that file's header
        // comment for the CSV-formula-injection risk this closes.
        // Deliberately NOT applied to the numeric/computed columns
        // further down (Quantity, weights, prices, dimensions) - those
        // are never free text, and forcing a negative number to render
        // as text would break Shippo's bulk import.
        fputcsv($out, [
            $orderNumber,
            $orderDate,
            merch_csv_safe_cell($name),
            '', // Company
            merch_csv_safe_cell($email),
            merch_csv_safe_cell($phone),
            merch_csv_safe_cell($address),
            '', // Street Number - included in Street Line 1 above
            '', // Street Line 2
            merch_csv_safe_cell($city),
            merch_csv_safe_cell($state),
            $zip,
            'US',
            merch_csv_safe_cell($item),
            merch_csv_safe_cell($color), // Repurposing SKU (not otherwise used) to carry Color, so it's visible for the inventory cross-check
            $quantity,
            $itemWeight, // blank unless ITEM_WEIGHT_OZ has a real number for this item (see comment above)
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
