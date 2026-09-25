<?php
// Build: 2026-09-25-A
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
define('FILAMENT_COLOR_ITEMS', ['Oval Cutter Holder', 'Circle Cutter Holder', 'Rectangle Cutter Holder', 'Hearts Cutter Holder', 'Tool Holder Stand', 'Tape Gun Holder', 'Tape Gun Add-On']);
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


/** Group labels in output order, e.g. "Hearts + Rectangle combo", one per plate. */
function plateLabels(array $groups): array
{
    $out = [];
    foreach ($groups as $g) {
        foreach ($g['plateGroups'] as $pg) {
            foreach ($pg['plates'] as $plate) {
                $out[] = $pg['group'];
            }
        }
    }
    return $out;
}

// ---- 2026-09-25: keep one customer's pieces on one plate. ----

// Case 1 (Shelly Brooks, CM Blue): two Rectangles, the rest of her order
// already made, plus a stranger's Hearts. The Hearts + Rectangle combo
// used to grab ONE of Shelly's Rectangles (leaving the other alone on a
// half-empty plate). Now both Rectangles share a solo plate, and the
// Hearts print on their own.
$rowsE = [
    ['item' => 'Hearts Cutter Holder', 'color' => '#15 CM Blue', 'qty' => 1, 'orderId' => '745', 'customerName' => 'Linda Hinshaw', 'orderGroupId' => '19', 'shipmentKey' => 'ship:linda|29000'],
    ['item' => 'Rectangle Cutter Holder', 'color' => '#15 CM Blue', 'qty' => 1, 'orderId' => '737', 'customerName' => 'Shelly Brooks', 'orderGroupId' => '17', 'shipmentKey' => 'ship:shelly|29001'],
    ['item' => 'Rectangle Cutter Holder', 'color' => '#15 CM Blue', 'qty' => 1, 'orderId' => '740', 'customerName' => 'Shelly Brooks', 'orderGroupId' => '17', 'shipmentKey' => 'ship:shelly|29001'],
];
$summariesE = plateSummaries(print_plate_group_queue($rowsE));
expect('Shelly: 2 plates', count($summariesE), 2);
expect('Shelly: both Rectangles on one plate', $summariesE[0] ?? '', '2x Rectangle Cutter Holder - Shelly Brooks(1), Shelly Brooks(1)');
expect('Shelly: Hearts on their own', $summariesE[1] ?? '', '1x Hearts Cutter Holder - Linda Hinshaw(1)');
// Same result from OrderGroupID alone (no shipmentKey passed).
$rowsE2 = array_map(function ($r) { unset($r['shipmentKey']); return $r; }, $rowsE);
expect('Shelly (OrderGroupID only): both Rectangles on one plate', plateSummaries(print_plate_group_queue($rowsE2))[0] ?? '', '2x Rectangle Cutter Holder - Shelly Brooks(1), Shelly Brooks(1)');

// Case 2 (Wendy Staples, Lilac): her Circle and Oval ARE a combo, but
// they were placed as separate submissions (no OrderGroupID) and the
// combo used to take Georgia's Oval instead. The shipment key ties
// Wendy's two rows together.
$rowsF = [
    ['item' => 'Oval Cutter Holder', 'color' => '#13 Lilac', 'qty' => 1, 'orderId' => '530', 'customerName' => 'Georgia Akin', 'orderGroupId' => '', 'shipmentKey' => 'ship:georgia|29002'],
    ['item' => 'Circle Cutter Holder', 'color' => '#13 Lilac', 'qty' => 1, 'orderId' => '673', 'customerName' => 'Wendy Staples', 'orderGroupId' => '', 'shipmentKey' => 'ship:wendy|29003'],
    ['item' => 'Oval Cutter Holder', 'color' => '#13 Lilac', 'qty' => 1, 'orderId' => '674', 'customerName' => 'Wendy Staples', 'orderGroupId' => '', 'shipmentKey' => 'ship:wendy|29003'],
];
$groupsF = print_plate_group_queue($rowsF);
$summariesF = plateSummaries($groupsF);
expect('Wendy: first plate is the Circle / Oval combo', plateLabels($groupsF)[0] ?? '', 'Circle / Oval combo');
expect('Wendy: combo Circle is Wendy\'s', in_array('1x Circle Cutter Holder - Wendy Staples(1)', $summariesF, true) ? 'yes' : 'no', 'yes');
expect('Wendy: combo Oval is Wendy\'s', in_array('1x Oval Cutter Holder - Wendy Staples(1)', $summariesF, true) ? 'yes' : 'no', 'yes');
expect('Wendy: Georgia\'s Oval is alone on the last plate', end($summariesF), '1x Oval Cutter Holder - Georgia Akin(1)');

// Case 3 (Jean McFadden, Coral): a Tool Holder Stand, a Tape Gun Holder
// and a Tape Gun Add-On. The two tape-gun pieces now share one plate;
// the Stand is its own.
$rowsG = [
    ['item' => 'Tool Holder Stand', 'color' => '#02 Coral', 'qty' => 1, 'orderId' => '795', 'customerName' => 'Jean McFadden', 'orderGroupId' => '46', 'shipmentKey' => 'ship:jean|29004'],
    ['item' => 'Tape Gun Add-On', 'color' => '#02 Coral', 'qty' => 1, 'orderId' => '796', 'customerName' => 'Jean McFadden', 'orderGroupId' => '46', 'shipmentKey' => 'ship:jean|29004'],
    ['item' => 'Tape Gun Holder', 'color' => '#02 Coral', 'qty' => 1, 'orderId' => '797', 'customerName' => 'Jean McFadden', 'orderGroupId' => '46', 'shipmentKey' => 'ship:jean|29004'],
];
$groupsG = print_plate_group_queue($rowsG);
expect('Jean: 2 plates (Stand, and the tape-gun pair)', count(plateLabels($groupsG)), 2);
expect('Jean: tape-gun pair share a mixed plate', in_array('Tape Gun Holder + Add-On', plateLabels($groupsG), true) ? 'yes' : 'no', 'yes');
$mixedFill = null;
foreach ($groupsG[0]['plateGroups'] as $pg) {
    if ($pg['group'] === 'Tape Gun Holder + Add-On') {
        $mixedFill = round($pg['plates'][0]['fillFraction'], 3);
    }
}
expect('Jean: mixed plate fill is the footprint sum (1/5 + 1/8)', $mixedFill, 0.325);

// Different customers' tape-gun pieces are NOT pooled onto a mixed plate
// (unchanged behavior - the mix only ever keeps ONE customer together).
$rowsH = [
    ['item' => 'Tape Gun Holder', 'color' => '#02 Coral', 'qty' => 1, 'orderId' => 'H1', 'customerName' => 'Ann', 'orderGroupId' => '', 'shipmentKey' => 'ship:ann|1'],
    ['item' => 'Tape Gun Add-On', 'color' => '#02 Coral', 'qty' => 1, 'orderId' => 'H2', 'customerName' => 'Bea', 'orderGroupId' => '', 'shipmentKey' => 'ship:bea|2'],
];
expect('unrelated customers keep separate tape-gun plates', count(plateSummaries(print_plate_group_queue($rowsH))), 2);
expect('unrelated customers: no mixed plate', in_array('Tape Gun Holder + Add-On', plateLabels(print_plate_group_queue($rowsH)), true) ? 'yes' : 'no', 'no');

// A one-piece customer is never given a reserved plate of their own -
// the confirmed combo with a stranger still wins for singles.
$rowsI = [
    ['item' => 'Hearts Cutter Holder', 'color' => '#15 CM Blue', 'qty' => 1, 'orderId' => 'I1', 'customerName' => 'Cy', 'orderGroupId' => '', 'shipmentKey' => 'ship:cy|1'],
    ['item' => 'Rectangle Cutter Holder', 'color' => '#15 CM Blue', 'qty' => 1, 'orderId' => 'I2', 'customerName' => 'Di', 'orderGroupId' => '', 'shipmentKey' => 'ship:di|2'],
];
expect('two single-piece customers still share the Hearts + Rectangle combo', plateLabels(print_plate_group_queue($rowsI))[0] ?? '', 'Hearts + Rectangle combo');

// ---- Verdict ---------------------------------------------------------
if ($failures) {
    echo 'FAIL - ' . count($failures) . " assertion(s) failed.\n";
    exit(1);
}
echo "PASS - all print-plate packing assertions matched.\n";
exit(0);
