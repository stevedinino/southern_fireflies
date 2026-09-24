<?php
// Build: 2026-09-24-A
// ============================================================
// Direct tests for print_plates.php's plate-packing logic - the
// consumption-order fix from 2026-09-20, the OrderGroupID completion
// fix from 2026-09-24 (see print_plates.php's own header comment for
// the full story on each), plus a regression check that
// capacity-forced splits still work the way they always have. Run
// from anywhere:
//
//     php tests/test_print_plates.php
//
// Calls print_plate_group_queue() directly (no web server, no CSV
// needed) against small hand-built row sets.
// ============================================================

error_reporting(E_ALL);
define('FILAMENT_COLOR_ITEMS', ['Oval Cutter Holder']);
require dirname(__DIR__) . '/print_plates.php';

$failures = [];
function expect(string $label, $got, $want): void
{
    global $failures;
    if ((string) $got !== (string) $want) {
        $failures[] = "[$label] expected '$want', got '$got'";
        echo "FAIL  $label: expected '$want', got '$got'\n";
    } else {
        echo "  ok  $label\n";
    }
}

/** Flattens print_plate_group_queue()'s output to "item x qty (orderA, orderB)" per plate, in order, for easy assertions. */
function plateSummaries(array $groups): array
{
    $out = [];
    foreach ($groups as $g) {
        foreach ($g['plateGroups'] as $pg) {
            foreach ($pg['plates'] as $plate) {
                foreach ($plate['items'] as $item => $data) {
                    $orders = [];
                    foreach ($data['orders'] as $o) {
                        $orders[] = $o['customerName'] . '(' . $o['qty'] . ')';
                    }
                    $out[] = $data['qty'] . 'x ' . $item . ' - ' . implode(', ', $orders);
                }
            }
        }
    }
    return $out;
}

// ---- Both orders complete either way -> the larger request should be
// consolidated onto one plate instead of fragmented (2026-09-20, the
// case Steve actually hit: 2 needed by one order, 1 by another, plate
// capacity 2 - the old per-unit comparison split the 2-unit order). ----
$rowsA = [
    ['item' => 'Oval Cutter Holder', 'color' => '#14 Sky Blue', 'qty' => 1, 'orderId' => '692', 'customerName' => 'Cindy Morgan'],
    ['item' => 'Oval Cutter Holder', 'color' => '#14 Sky Blue', 'qty' => 2, 'orderId' => '556', 'customerName' => 'Dale Monnier'],
];
$summariesA = plateSummaries(print_plate_group_queue($rowsA));
expect('tie on completion: plate 1 is Dale alone (not fragmented)', $summariesA[0] ?? '', '2x Oval Cutter Holder - Dale Monnier(2)');
expect('tie on completion: plate 2 is Cindy alone', $summariesA[1] ?? '', '1x Oval Cutter Holder - Cindy Morgan(1)');

// ---- Only one order actually completes -> that one still wins the
// first plate even though the other order's request is larger, since a
// REAL completion always outranks consolidation (Dale has a hat still
// outstanding elsewhere, so his 2 Ovals alone wouldn't finish him). ----
$rowsB = [
    ['item' => 'Oval Cutter Holder', 'color' => '#14 Sky Blue', 'qty' => 1, 'orderId' => '692', 'customerName' => 'Cindy Morgan'],
    ['item' => 'Oval Cutter Holder', 'color' => '#14 Sky Blue', 'qty' => 2, 'orderId' => '556', 'customerName' => 'Dale Monnier'],
    ['item' => 'Hat', 'color' => '', 'qty' => 1, 'orderId' => '556', 'customerName' => 'Dale Monnier'],
];
$summariesB = plateSummaries(print_plate_group_queue($rowsB));
expect('real completion beats consolidation: plate 1 has both', $summariesB[0] ?? '', '2x Oval Cutter Holder - Cindy Morgan(1), Dale Monnier(1)');
expect('real completion beats consolidation: plate 2 is Dale\'s leftover', $summariesB[1] ?? '', '1x Oval Cutter Holder - Dale Monnier(1)');

// ---- Capacity genuinely forces a split (5 needed by one order, cap 2)
// - unaffected by this fix, still full plates + a trailing partial. ----
$rowsC = [
    ['item' => 'Oval Cutter Holder', 'color' => '#14 Sky Blue', 'qty' => 5, 'orderId' => '900', 'customerName' => 'Solo Order'],
];
$summariesC = plateSummaries(print_plate_group_queue($rowsC));
expect('capacity-forced split: 3 plates', count($summariesC), 3);
expect('capacity-forced split: plate 1 full', $summariesC[0] ?? '', '2x Oval Cutter Holder - Solo Order(2)');
expect('capacity-forced split: plate 3 partial', $summariesC[2] ?? '', '1x Oval Cutter Holder - Solo Order(1)');

// ---- A multi-item order (same OrderGroupID, different OrderIDs)
// shouldn't be treated as "complete" the instant just ONE of its rows
// is taken - that's not a real completion, and letting it act like one
// wrongly lets a lone row win the FIFO tiebreak over genuinely-
// standalone orders, scattering the group across plates instead of
// keeping it together (2026-09-24, Steve's real example: a 3-line
// order that never once got paired with itself). Interleaved with two
// unrelated single-item orders (Ellen, Fiona) specifically so the old
// per-row keying and the new per-group keying disagree on the result -
// see this test's comment for the by-hand trace of both. ----
$rowsD = [
    ['item' => 'Oval Cutter Holder', 'color' => '#14 Sky Blue', 'qty' => 1, 'orderId' => 'D1', 'customerName' => 'Dale Monnier', 'orderGroupId' => 'G1'],
    ['item' => 'Oval Cutter Holder', 'color' => '#14 Sky Blue', 'qty' => 1, 'orderId' => 'E1', 'customerName' => 'Ellen Ashby', 'orderGroupId' => ''],
    ['item' => 'Oval Cutter Holder', 'color' => '#14 Sky Blue', 'qty' => 1, 'orderId' => 'D2', 'customerName' => 'Dale Monnier', 'orderGroupId' => 'G1'],
    ['item' => 'Oval Cutter Holder', 'color' => '#14 Sky Blue', 'qty' => 1, 'orderId' => 'F1', 'customerName' => 'Fiona Reyes', 'orderGroupId' => ''],
];
$summariesD = plateSummaries(print_plate_group_queue($rowsD));
expect('order-group fix: plate 1 is the two genuinely-standalone orders', $summariesD[0] ?? '', '2x Oval Cutter Holder - Ellen Ashby(1), Fiona Reyes(1)');
expect('order-group fix: plate 2 is Dale\'s own two-line order, together', $summariesD[1] ?? '', '2x Oval Cutter Holder - Dale Monnier(1), Dale Monnier(1)');

// ---- Verdict ---------------------------------------------------------
if ($failures) {
    echo 'FAIL - ' . count($failures) . " assertion(s) failed.\n";
    exit(1);
}
echo "PASS - all print-plate packing assertions matched.\n";
exit(0);
