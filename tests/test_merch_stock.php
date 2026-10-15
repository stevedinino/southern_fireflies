<?php
// Build: 2026-10-02-A
// ============================================================
// Direct tests for merch_stock.php - inventory-sheet parsing, strict color
// matching, whole-shipment stock allocation, and print-next row building
// (the three business rules in that file's header: never a partial
// shipment, paid first, no color substitution). Run from anywhere:
//
//     php tests/test_merch_stock.php
//
// No web server, no real CSV needed - small hand-built row sets, same
// approach as tests/test_print_plates.php.
// ============================================================

error_reporting(E_ALL);
const FILAMENT_COLOR_ITEMS = ['Blade Holder', 'Circle Cutter Holder', 'Oval Cutter Holder', 'Rectangle Cutter Holder', 'Hearts Cutter Holder', 'Tool Holder Stand', 'Tape Gun Holder', 'Tape Gun Add-On'];
const TEST_COLORS = ['#01 Red', '#03 Maroon', '#09 Magenta', '#12 Purple', '#13 Lilac', '#14 Sky Blue', '#15 CM Blue', '#17 Teal', '#26 Tan', 'Rainbow (+$2)', 'Stars & Stripes (+$7)'];
require dirname(__DIR__) . '/merch_shipments.php';
require dirname(__DIR__) . '/print_plates.php';
require dirname(__DIR__) . '/merch_stock.php';

$failures = [];
function expect(string $label, $got, $want): void
{
    global $failures;
    $g = is_array($got) ? json_encode($got) : (string) $got;
    $w = is_array($want) ? json_encode($want) : (string) $want;
    if ($g !== $w) {
        $failures[] = "[$label] expected '$w', got '$g'";
        echo "FAIL  $label: expected '$w', got '$g'\n";
    } else {
        echo "  ok  $label\n";
    }
}

// ---- Strict color resolution (rule 3) --------------------------
$idx = merch_stock_color_index(TEST_COLORS);
expect('exact color', merch_stock_resolve_color('#15 CM Blue', $idx)[0], '#15 CM Blue');
expect('unpadded number + right name', merch_stock_resolve_color('#9 Magenta', $idx)[0], '#09 Magenta');
expect('unpadded number only', merch_stock_resolve_color('#3', $idx)[0], '#03 Maroon');
expect('bare name, no number', merch_stock_resolve_color('CM Blue', $idx)[0], '#15 CM Blue');
expect('bare name matches despite (+$2) tail', merch_stock_resolve_color('Rainbow', $idx)[0], 'Rainbow (+$2)');
expect('case-insensitive', merch_stock_resolve_color('#14 sky blue', $idx)[0], '#14 Sky Blue');
expect('"Light Blue" is NOT guessed to be Sky Blue', merch_stock_resolve_color('Light Blue', $idx)[0], '');
expect('number/name disagreement refused', merch_stock_resolve_color('#9 Hot Pink', $idx)[0], '');
expect('unknown number refused', merch_stock_resolve_color('#99 Mystery', $idx)[0], '');
expect('unlisted color refused', merch_stock_resolve_color('Copper', $idx)[0], '');

// ---- Inventory grid parsing ------------------------------------
$grid = [
    ['Part', '#15 CM Blue', '#17 Teal', '#9 Magenta', 'Copper', '', 'Total'],
    ['Blade Holder', '5', '1', '', '', '', '6'],
    ['Circles', '1', '', '', '1', '', '2'],
    ['Tool Stand Holder', '2', '', '1', '', '', '3'],
    ['', '', '', '', '', '', ''],
    ['Total', '8', '1', '1', '1', '', '10'],
];
$p = merch_stock_parse_grid($grid, FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('grid: Blade Holder CM Blue', $p['stock']['Blade Holder']['#15 CM Blue'] ?? null, 5);
expect('grid: alias "Circles" -> Circle Cutter Holder', $p['stock']['Circle Cutter Holder']['#15 CM Blue'] ?? null, 1);
expect('grid: alias "Tool Stand Holder" + unpadded "#9 Magenta"', $p['stock']['Tool Holder Stand']['#09 Magenta'] ?? null, 1);
expect('grid: Total row/column ignored; Copper excluded (6 + 1 + 3 = 10 real pieces)', merch_stock_total($p['stock']), 10);
expect('grid: Copper reported, not silently dropped', count($p['unmatched']), 1);
expect('grid: Copper entry names the part', $p['unmatched'][0]['part'] ?? '', 'Circle Cutter Holder');
$badCell = merch_stock_parse_grid([['Part', '#15 CM Blue'], ['Blade Holder', 'two']], FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('grid: non-numeric cell warns', count($badCell['warnings']), 1);
$notAGrid = merch_stock_parse_grid([['Name', 'Email'], ['Jane', 'j@x.com']], FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('grid: a non-inventory file recognizes nothing', [$notAGrid['columnsRecognized'], $notAGrid['rowsRecognized']], [0, 0]);

// ---- Flipped layout: colors down the side, parts across the top ----
// (2026-10-03: 27 colors x 8 parts is easier to manage than 27 columns.)
$flipped = [
    ['Color', 'Blade Holder', 'Circles', 'Tool Stand Holder', 'Total'],
    ['#15 CM Blue', '5', '1', '2', '8'],
    ['#17 Teal', '1', '', '', '1'],
    ['#9 Magenta', '', '', '1', '1'],
    ['Copper', '', '1', '', '1'],
    ['', '', '', '', ''],
    ['Total', '6', '2', '3', '10'],
];
expect('orientation: parts-down grid detected', merch_stock_detect_orientation($grid, FILAMENT_COLOR_ITEMS, TEST_COLORS), 'parts-down');
expect('orientation: colors-down grid detected', merch_stock_detect_orientation($flipped, FILAMENT_COLOR_ITEMS, TEST_COLORS), 'colors-down');
$pf = merch_stock_parse_grid($flipped, FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('flipped: reports its orientation', $pf['orientation'], 'colors-down');
expect('flipped: identical stock to the parts-down sheet', $pf['stock'], $p['stock']);
expect('flipped: Copper still reported, with the part', [count($pf['unmatched']), $pf['unmatched'][0]['part'] ?? '', $pf['unmatched'][0]['color'] ?? ''], [1, 'Circle Cutter Holder', 'Copper']);
expect('flipped: sanity counts are colors/parts regardless of layout', [$pf['columnsRecognized'], $pf['rowsRecognized']], [$p['columnsRecognized'], $p['rowsRecognized']]);
expect('flipped: total pieces', merch_stock_total($pf['stock']), 10);

$ragged = [['Color', 'Blade Holder', 'Hearts'], ['#17 Teal', '2'], ['#15 CM Blue', '', '3']];
$pr = merch_stock_parse_grid($ragged, FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('flipped: short (ragged) rows are fine', [$pr['stock']['Blade Holder']['#17 Teal'] ?? null, $pr['stock']['Hearts Cutter Holder']['#15 CM Blue'] ?? null], [2, 3]);

$oneColor = merch_stock_parse_grid([['x', 'Blade Holder'], ['#15 CM Blue', '4']], FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('flipped: smallest possible grid (one part, one color)', [$oneColor['orientation'], $oneColor['stock']['Blade Holder']['#15 CM Blue'] ?? null], ['colors-down', 4]);

expect('non-inventory file still parts-down and recognizes nothing', [$notAGrid['orientation'], $notAGrid['columnsRecognized'], $notAGrid['rowsRecognized']], ['parts-down', 0, 0]);
$empty = merch_stock_parse_grid([], FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('empty file: parts-down, nothing recognized', [$empty['orientation'], $empty['rowsRecognized']], ['parts-down', 0]);

// ---- Shipments from merchandise.csv rows -----------------------
$header = ['OrderID', 'Name', 'Zip', 'Item', 'Quantity', 'Color', 'Fulfillment', 'Pymt Date', 'Created', 'Fulfilled', 'Cancelled', 'Qty Created'];
$col = array_flip($header);
$r = function (array $f) use ($header): array {
    $d = ['OrderID' => '1', 'Name' => 'Cust', 'Zip' => '29000', 'Item' => 'Blade Holder', 'Quantity' => '1', 'Color' => '#15 CM Blue', 'Fulfillment' => 'Ship', 'Pymt Date' => '9/1/2026', 'Created' => '', 'Fulfilled' => '', 'Cancelled' => '', 'Qty Created' => ''];
    $d = array_merge($d, $f);
    return array_map(fn($h) => $d[$h], $header);
};
$rows = [
    $r(['OrderID' => '10', 'Name' => 'Ann', 'Item' => 'Blade Holder', 'Color' => '#15 CM Blue']),
    $r(['OrderID' => '11', 'Name' => 'ann ', 'Item' => 'Tape Gun Holder', 'Color' => '#12 Purple', 'Quantity' => '3', 'Qty Created' => '1']), // same shipment (name+zip), 2 left
    $r(['OrderID' => '12', 'Name' => 'Unpaid Una', 'Pymt Date' => '']),                                                                       // unpaid Ship: ignored, counted
    $r(['OrderID' => '13', 'Name' => 'Pat Pickup', 'Fulfillment' => 'Pickup at retreat', 'Pymt Date' => '']),                                    // pickup, unpaid: included
    $r(['OrderID' => '14', 'Name' => 'Cal Cancelled', 'Cancelled' => '2026-09-17']),                                                           // cancelled: skipped
    $r(['OrderID' => '15', 'Name' => 'Fran Fulfilled', 'Created' => '9/2/2026', 'Fulfilled' => '9/3/2026']),                                    // shipped: skipped
    $r(['OrderID' => '16', 'Name' => 'Sam Shirt', 'Item' => 'Logo Shirt', 'Color' => '#70 Black']),                                            // shirt blocks Sam
    $r(['OrderID' => '17', 'Name' => 'Sam Shirt', 'Item' => 'Oval Cutter Holder', 'Color' => '#17 Teal']),
    $r(['OrderID' => '18', 'Name' => 'Done Dee', 'Created' => '9/2/2026']),                                                                    // printed, awaiting ship
];
$b = merch_stock_build_shipments($rows, $col, FILAMENT_COLOR_ITEMS, ['Stars & Stripes (+$7)']);
$byName = [];
foreach ($b['shipments'] as $s) {
    $byName[strtolower($s['name'])] = $s;
}
expect('ship: unpaid Ship row ignored', isset($byName['unpaid una']) ? 'included' : 'ignored', 'ignored');
expect('ship: unpaid pieces counted', $b['unpaidPieces'], 1);
expect('ship: pickup included regardless of payment', isset($byName['pat pickup']) ? 'included' : 'ignored', 'included');
expect('ship: cancelled skipped', isset($byName['cal cancelled']) ? 'included' : 'ignored', 'ignored');
expect('ship: fulfilled skipped', isset($byName['fran fulfilled']) ? 'included' : 'ignored', 'ignored');
expect('ship: printed-and-awaiting-ship not a print job', [isset($byName['done dee']), $b['awaitingShip']], [false, 1]);
expect('ship: Name+Zip groups separate OrderIDs (case/space-insensitive)', count($byName['ann']['orderIds'] ?? []), 2);
expect('ship: partial Qty Created leaves only the remainder (3 - 1 = 2)', $byName['ann']['lines'][1]['qty'] ?? null, 2);
expect('ship: shirt becomes a blocker, not a printable line', [count($byName['sam shirt']['other']), count($byName['sam shirt']['lines'])], [1, 1]);
expect('ship: minOrderId is the oldest', $byName['ann']['minOrderId'], 10);

// ---- Allocation (rules 1 and 3) --------------------------------
$sh = function (string $name, int $minId, array $lines, array $other = [], bool $special = false): array {
    $ls = [];
    foreach ($lines as $i => [$item, $color, $qty]) {
        $ls[] = ['orderId' => (string) ($minId + $i), 'item' => $item, 'color' => $color, 'qty' => $qty];
    }
    return ['key' => 'ship:' . strtolower($name), 'printKey' => 'ship:' . strtolower($name), 'type' => 'ship', 'name' => $name, 'orderIds' => [(string) $minId], 'minOrderId' => $minId, 'lines' => $ls, 'other' => $other, 'special' => $special, 'unpaidSiblingLines' => 0];
};
$names = fn(array $list) => array_map(fn($s) => $s['name'], $list);

// Whole-shipment only: Bea needs two, stock has one - NOT ready, even
// though one piece is on the shelf; the one piece is reserved toward her.
$a1 = merch_stock_allocate([$sh('Bea', 1, [['Blade Holder', '#15 CM Blue', 2]])], ['Blade Holder' => ['#15 CM Blue' => 1]]);
expect('alloc: no partial shipment - 1 of 2 on hand is not ready', count($a1['ready']), 0);
expect('alloc: ...it is reserved toward her instead', [$a1['partial'][0]['coveredUnits'], $a1['partial'][0]['missingUnits']], [1, 1]);

// Oldest-first, and a shipment that can't complete doesn't block newer ones.
$a2 = merch_stock_allocate([
    $sh('Old Big', 1, [['Blade Holder', '#15 CM Blue', 2], ['Oval Cutter Holder', '#17 Teal', 1]]), // can't complete (no Teal oval)
    $sh('Mid', 2, [['Blade Holder', '#15 CM Blue', 1]]),
    $sh('New', 3, [['Blade Holder', '#15 CM Blue', 1]]),
], ['Blade Holder' => ['#15 CM Blue' => 2]]);
expect('alloc: unfinishable older order does not block newer ones', $names($a2['ready']), ['Mid', 'New']);

// No color substitution: Sky Blue on the shelf never fills a Teal order.
$a3 = merch_stock_allocate([$sh('Teal Tess', 1, [['Blade Holder', '#17 Teal', 1]])], ['Blade Holder' => ['#14 Sky Blue' => 3]]);
expect('alloc: no color substitution', [count($a3['ready']), $a3['partial'][0]['missingUnits']], [0, 1]);
expect('alloc: the unusable Sky Blue stock is reported as left over', $a3['stockLeft']['Blade Holder']['#14 Sky Blue'] ?? null, 3);

// A shirt/hat outstanding means not ready, and it does not consume stock.
$a4 = merch_stock_allocate([$sh('Sam', 1, [['Blade Holder', '#15 CM Blue', 1]], [['orderId' => '9', 'item' => 'Logo Shirt', 'color' => '#70 Black', 'qty' => 1]])], ['Blade Holder' => ['#15 CM Blue' => 1]]);
expect('alloc: shirt/hat outstanding blocks readiness', [count($a4['ready']), count($a4['blocked'])], [0, 1]);

// Stranded stock = no remaining paid demand wants it.
$a5 = merch_stock_allocate([$sh('Al', 1, [['Blade Holder', '#15 CM Blue', 1]])], ['Blade Holder' => ['#15 CM Blue' => 1, '#26 Tan' => 1]]);
expect('alloc: stranded stock reported (Tan, nobody paid for it)', $a5['stockLeft'], ['Blade Holder' => ['#26 Tan' => 1]]);

// Smallest-first hint can beat oldest-first.
$hintShips = [
    $sh('Big', 1, [['Blade Holder', '#15 CM Blue', 2]]),
    $sh('Small A', 2, [['Blade Holder', '#15 CM Blue', 1]]),
    $sh('Small B', 3, [['Blade Holder', '#15 CM Blue', 1]]),
];
$hintStock = ['Blade Holder' => ['#15 CM Blue' => 2]];
expect('hint: oldest-first ships 1', count(merch_stock_allocate($hintShips, $hintStock)['ready']), 1);
expect('hint: smallest-first would ship 2', merch_stock_ready_count_smallest_first($hintShips, $hintStock), 2);

// ---- Print rows & close-out ranking ----------------------------
$a6 = merch_stock_allocate([
    $sh('Cy', 1, [['Blade Holder', '#15 CM Blue', 2]]),
    $sh('Di', 2, [['Oval Cutter Holder', '#17 Teal', 1]]),
], ['Blade Holder' => ['#15 CM Blue' => 1]]);
$rowsOut = merch_stock_print_rows(array_merge($a6['ready'], $a6['partial']));
expect('rows: only MISSING pieces are queued for printing (Cy 2 - 1 on shelf = 1)', array_map(fn($x) => $x['customerName'] . ':' . $x['qty'], $rowsOut), ['Cy:1', 'Di:1']);
expect('rows: carry the shipment key so completion is per shipment', $rowsOut[0]['shipmentKey'], 'ship:cy');

$rankIn = merch_stock_allocate([
    $sh('Solo', 1, [['Oval Cutter Holder', '#17 Teal', 1]]),                    // oldest, but alone on its plate
    $sh('P1', 2, [['Blade Holder', '#15 CM Blue', 1]]),
    $sh('P2', 3, [['Blade Holder', '#15 CM Blue', 1]]),
    $sh('P3', 4, [['Blade Holder', '#15 CM Blue', 1]]),
    $sh('Two', 5, [['Blade Holder', '#12 Purple', 1], ['Tape Gun Holder', '#12 Purple', 1]]),
    $sh('Special', 6, [['Blade Holder', 'Stars & Stripes (+$7)', 1]], [], true),
], [])['partial'];
$ranked = merch_stock_closeout_rank($rankIn);
expect('rank: pieces sharing a plate rank ahead of an older lone piece', $names($ranked), ['P1', 'P2', 'P3', 'Solo', 'Two']);
expect('rank: Stars & Stripes orders are never close-out candidates', in_array('Special', $names($ranked), true) ? 'ranked' : 'excluded', 'excluded');

// ---- Plan stats -------------------------------------------------
$groups = print_plate_group_queue(merch_stock_print_rows(array_slice($ranked, 0, 3)));
$stats = merch_stock_plan_stats($groups);
expect('stats: 3 CM Blue Blade Holders = 3 pieces on 1 plate', [$stats['units'], $stats['plates'], $stats['colors']], [3, 1, 1]);

// ============================================================
// 2026-10-04: oldest-first priority, ordered dates, and the pick-list
// "Done" support (grid decrement + Created marking).
// ============================================================

// ---- Timestamps (two formats live in the Timestamp column) ----------
expect('ts: old M/D/YYYY format', date('Y-m-d', merch_stock_parse_ts('7/5/2026')), '2026-07-05');
expect('ts: ISO datetime', date('Y-m-d H:i:s', merch_stock_parse_ts('2026-10-03 18:26:31')), '2026-10-03 18:26:31');
expect('ts: blank is null', merch_stock_parse_ts(''), '');
expect('ts: garbage is null', merch_stock_parse_ts('last tuesday'), '');
expect('ts: impossible date is null', merch_stock_parse_ts('13/45/2026'), '');
$nowTs = merch_stock_parse_ts('2026-10-04 12:00:00');
expect('age text: days', merch_stock_age_text(merch_stock_parse_ts('2026-08-22 17:36:00'), $nowTs), 'Aug 22 (42 days)');
expect('age text: today', merch_stock_age_text(merch_stock_parse_ts('2026-10-04 01:00:00'), $nowTs), 'Oct 4 (today)');
expect('age text: no timestamp', merch_stock_age_text(null, $nowTs), '');

// ---- build_shipments: ordered dates + already-made lines ------------
$hdr2 = ['OrderID', 'Name', 'Zip', 'Item', 'Quantity', 'Color', 'Size', 'Sleeve', 'Fulfillment', 'Pymt Date', 'Created', 'Fulfilled', 'Cancelled', 'Qty Created', 'Timestamp'];
$col2 = array_flip($hdr2);
$r2 = function (array $f) use ($hdr2): array {
    $d = ['OrderID' => '1', 'Name' => 'Cust', 'Zip' => '29000', 'Item' => 'Blade Holder', 'Quantity' => '1', 'Color' => '#15 CM Blue', 'Size' => '', 'Sleeve' => '', 'Fulfillment' => 'Ship', 'Pymt Date' => '9/1/2026', 'Created' => '', 'Fulfilled' => '', 'Cancelled' => '', 'Qty Created' => '', 'Timestamp' => ''];
    return array_map(fn($h) => array_merge($d, $f)[$h], $hdr2);
};
$b2 = merch_stock_build_shipments([
    $r2(['OrderID' => '30', 'Name' => 'Mia', 'Timestamp' => '2026-09-20 10:00:00']),
    $r2(['OrderID' => '31', 'Name' => 'Mia', 'Item' => 'Hearts Cutter Holder', 'Quantity' => '3', 'Qty Created' => '1', 'Timestamp' => '8/22/2026']),
    $r2(['OrderID' => '32', 'Name' => 'Mia', 'Item' => 'Logo Shirt', 'Color' => '#70 Black', 'Size' => 'L', 'Sleeve' => 'Long', 'Created' => '9/25/2026', 'Timestamp' => '2026-09-21 09:00:00']),
], $col2, FILAMENT_COLOR_ITEMS, []);
$mia = $b2['shipments'][0];
expect('ship: oldestTs is the earliest row in the shipment', date('Y-m-d', $mia['oldestTs']), '2026-08-22');
expect('ship: partly-made line carries made / rowQty', [$mia['lines'][1]['qty'], $mia['lines'][1]['made'], $mia['lines'][1]['rowQty']], [2, 1, 3]);
expect('ship: fully Created row is kept as a made line (for the pick-list)', [count($mia['madeLines']), $mia['madeLines'][0]['item'], $mia['madeLines'][0]['size'], $mia['madeLines'][0]['sleeve']], [1, 'Logo Shirt', 'L', 'Long']);
expect('ship: made rows still belong to the shipment', count($mia['orderIds']), 3);
expect('ship: made rows are NOT planned as print work', array_map(fn($l) => $l['orderId'], $mia['lines']), ['30', '31']);
$noTs = merch_stock_build_shipments([$r2(['OrderID' => '40', 'Name' => 'Noel'])], $col2, FILAMENT_COLOR_ITEMS, []);
expect('ship: no timestamp -> oldestTs null, nothing breaks', $noTs['shipments'][0]['oldestTs'], '');

// ---- Priority: oldest-first vs quickest-wins ------------------------
$prioShips = [
    $sh('Old Big', 1, [['Blade Holder', '#15 CM Blue', 1], ['Oval Cutter Holder', '#17 Teal', 1], ['Hearts Cutter Holder', '#12 Purple', 1]]),
    $sh('Mid Small', 5, [['Oval Cutter Holder', '#14 Sky Blue', 1]]),
    $sh('New Tiny', 9, [['Tape Gun Holder', '#09 Magenta', 1]]),
];
$partialsAge = merch_stock_allocate($prioShips, [], 'age')['partial'];
$partialsQuick = merch_stock_allocate($prioShips, [], 'quick')['partial'];
expect('rank: quickest-wins puts the cheapest order first', $names(merch_stock_closeout_rank($partialsQuick, 'quick')), ['Mid Small', 'New Tiny', 'Old Big']);
expect('rank: oldest-first puts the oldest first, however big', $names(merch_stock_closeout_rank($partialsAge, 'age')), ['Old Big', 'Mid Small', 'New Tiny']);
expect('rank: the default stays the original quickest-wins rule', $names(merch_stock_closeout_rank($partialsQuick)), ['Mid Small', 'New Tiny', 'Old Big']);
expect('rank: age still skips Stars & Stripes / shirt orders', $names(merch_stock_closeout_rank(merch_stock_allocate([
    $sh('Special', 1, [['Blade Holder', 'Stars & Stripes (+$7)', 1]], [], true),
    $sh('Shirty', 2, [['Blade Holder', '#15 CM Blue', 1]], [['orderId' => '9', 'item' => 'Logo Shirt', 'color' => '#70 Black', 'qty' => 1]]),
    $sh('Fine', 3, [['Blade Holder', '#15 CM Blue', 1]]),
], [], 'age')['partial'], 'age')), ['Fine']);
// Pass B: a lone shelf piece wanted by two orders goes to the older one
// under 'age', to the closer-to-done one under 'quick'.
$contend = [
    $sh('Older Far', 1, [['Blade Holder', '#15 CM Blue', 1], ['Oval Cutter Holder', '#17 Teal', 1]]),
    $sh('Newer Near', 2, [['Blade Holder', '#15 CM Blue', 1]]),
];
$contendStock = ['Blade Holder' => ['#15 CM Blue' => 1]];
// ("Newer Near" is ready outright in pass A, so make the shelf piece contested only among not-ready orders:)
$contend2 = [
    $sh('Older Far', 1, [['Blade Holder', '#15 CM Blue', 2], ['Oval Cutter Holder', '#17 Teal', 1]]),
    $sh('Newer Near', 2, [['Blade Holder', '#15 CM Blue', 2]]),
];
$cov = fn(array $alloc) => [$alloc['partial'][0]['name'], $alloc['partial'][0]['coveredUnits'], $alloc['partial'][1]['coveredUnits']];
expect('reserve: age gives the shelf piece to the older order first', $cov(merch_stock_allocate($contend2, $contendStock, 'age')), ['Older Far', 1, 0]);
expect('reserve: quick gives it to the closer-to-done order first', $cov(merch_stock_allocate($contend2, $contendStock, 'quick')), ['Newer Near', 1, 0]);

// ---- Done: what gets committed --------------------------------------
$doneShip = $sh('Dot', 20, [['Blade Holder', '#15 CM Blue', 2], ['Oval Cutter Holder', '#17 Teal', 1]]);
expect('commit ids: one entry per row being marked, sorted', merch_stock_shipment_commit_ids($doneShip), ['20', '21']);
$sig1 = merch_stock_shipment_signature($doneShip);
expect('signature: stable', merch_stock_shipment_signature($doneShip), $sig1);
$changedQty = $doneShip; $changedQty['lines'][0]['qty'] = 3;
$changedColor = $doneShip; $changedColor['lines'][1]['color'] = '#14 Sky Blue';
$reordered = $doneShip; $reordered['lines'] = array_reverse($reordered['lines']);
expect('signature: changes if a quantity changes', merch_stock_shipment_signature($changedQty) === $sig1 ? 'same' : 'differs', 'differs');
expect('signature: changes if a color changes', merch_stock_shipment_signature($changedColor) === $sig1 ? 'same' : 'differs', 'differs');
expect('signature: ignores line order', merch_stock_shipment_signature($reordered), $sig1);

// ---- Done: decrement inventory (parts-down, with Excel Total row/col) ----
$partsDown = [
    ['Part', '#15 CM Blue', '#17 Teal', 'Copper', 'Total'],
    ['Blade Holder', '5', '1', '', '6'],
    ['Circles', '1', '', '1', '2'],
    ['', '', '', '', ''],
    ['Total', '6', '1', '1', '8'],
];
$d1 = merch_stock_decrement_grid($partsDown, ['Blade Holder' => ['#15 CM Blue' => 2], 'Circle Cutter Holder' => ['#15 CM Blue' => 1]], FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('dec: ok', $d1['ok'], '1');
expect('dec: cells go down (5-2=3), zeroed cell goes blank (1-1)', [$d1['rows'][1][1], $d1['rows'][2][1]], ['3', '']);
expect('dec: row Total cells come down too', [$d1['rows'][1][4], $d1['rows'][2][4]], ['4', '1']);
expect('dec: column and grand Total come down too', [$d1['rows'][4][1], $d1['rows'][4][4]], ['3', '5']);
expect('dec: untouched cells, unmatched Copper and blank rows left exactly as they were', [$d1['rows'][2][3], $d1['rows'][1][2], $d1['rows'][3], $d1['rows'][0]], ['1', '1', ['', '', '', '', ''], $partsDown[0]]);
$re1 = merch_stock_parse_grid($d1['rows'], FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('dec: the parser reads back exactly the remaining stock', $re1['stock'], ['Blade Holder' => ['#15 CM Blue' => 3, '#17 Teal' => 1]]);

$tooMany = merch_stock_decrement_grid($partsDown, ['Blade Holder' => ['#15 CM Blue' => 2, '#17 Teal' => 2]], FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('dec: not enough -> refused with a clear message, no rows returned', [$tooMany['ok'], isset($tooMany['rows']), strpos($tooMany['error'], '#17 Teal') !== false], [false, false, true]);
$noSuch = merch_stock_decrement_grid($partsDown, ['Tape Gun Holder' => ['#15 CM Blue' => 1]], FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('dec: a part/color that was never stocked is refused', $noSuch['ok'], '');
$ccExactly = merch_stock_decrement_grid($partsDown, ['Blade Holder' => ['#15 CM Blue' => 5, '#17 Teal' => 1]], FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('dec: taking everything is fine', [$ccExactly['ok'], $ccExactly['rows'][1][1], $ccExactly['rows'][1][2], $ccExactly['rows'][1][4]], [true, '', '', '0']);

// ---- Done: decrement inventory (his new layout: colors down, parts across, BOM, no totals) ----
$flippedInv = [
    ["\xEF\xBB\xBF", 'Circles', 'Hearts', 'Blade Holder', 'Tool Stand Holder'],
    ['#1 Red', '', '', '', ''],
    ['#9 Magenta', '1', '', '', ''],
    ['#14 Sky Blue', '1', '1', '2', ''],
    ['#28 Copper', '1', '', '', ''],
    ['Rainbow', '', '', '1', ''],
];
$d2 = merch_stock_decrement_grid($flippedInv, ['Blade Holder' => ['#14 Sky Blue' => 2, 'Rainbow (+$2)' => 1], 'Circle Cutter Holder' => ['#09 Magenta' => 1]], FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('dec/flipped: ok', $d2['ok'], '1');
expect('dec/flipped: right cells (unpadded "#14", "#9" and bare "Rainbow" labels resolve)', [$d2['rows'][3][3], $d2['rows'][5][3], $d2['rows'][2][1]], ['', '', '']);
expect('dec/flipped: the rest untouched, Copper included, BOM corner cell intact', [$d2['rows'][3][1], $d2['rows'][3][2], $d2['rows'][4][1], $d2['rows'][0][0]], ['1', '1', '1', "\xEF\xBB\xBF"]);
$re2 = merch_stock_parse_grid($d2['rows'], FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('dec/flipped: parser reads back what is left', merch_stock_total($re2['stock']), 2);
$shortRows = [['', 'Blade Holder'], ['#15 CM Blue', '2'], ['#17 Teal']]; // ragged
$d3 = merch_stock_decrement_grid($shortRows, ['Blade Holder' => ['#15 CM Blue' => 1]], FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('dec: ragged rows are fine', [$d3['ok'], $d3['rows'][1][1]], [true, '1']);
// Same item+color in two cells (duplicate label): take from both, in order.
$dupe = [['Part', '#15 CM Blue', '#15 CM Blue'], ['Blade Holder', '1', '2']];
$d4 = merch_stock_decrement_grid($dupe, ['Blade Holder' => ['#15 CM Blue' => 2]], FILAMENT_COLOR_ITEMS, TEST_COLORS);
expect('dec: duplicate labels are drawn down together (1 + 1 of 1,2)', [$d4['ok'], $d4['rows'][1][1], $d4['rows'][1][2]], [true, '', '1']);
expect('dec: empty file refused', merch_stock_decrement_grid([], [], FILAMENT_COLOR_ITEMS, TEST_COLORS)['ok'], '');

// ---- Done: mark Created (exactly what ticking Created does) ---------
$cols3 = array_flip(['OrderID', 'Quantity', 'Created', 'Fulfilled', 'Qty Created']);
$rowA = ['50', '3', '', '', '1'];
merch_stock_apply_created($rowA, $cols3, '2026-10-04');
expect('created: stamps today, Qty Created = full Quantity, Fulfilled untouched', $rowA, ['50', '3', '2026-10-04', '', '3']);
$rowB = ['51', '1', '2026-09-01', '', ''];
merch_stock_apply_created($rowB, $cols3, '2026-10-04');
expect('created: an existing Created date is kept', [$rowB[2], $rowB[4]], ['2026-09-01', '1']);
$rowC = ['52', '2']; // short row from before the columns existed
merch_stock_apply_created($rowC, $cols3, '2026-10-04');
expect('created: short rows are padded so values land in the right columns', [count($rowC), $rowC[2], $rowC[3], $rowC[4]], [5, '2026-10-04', '', '2']);
$rowD = ['53', '', '', '', ''];
merch_stock_apply_created($rowD, $cols3, '2026-10-04');
expect('created: blank Quantity counts as 1', $rowD[4], '1');
$rowE = ['54', '2', '', '2026-09-30', '']; // Fulfilled already set: still never touched
merch_stock_apply_created($rowE, $cols3, '2026-10-04');
expect('created: never touches Fulfilled', $rowE[3], '2026-09-30');

// ---- Age = how long since PAID (2026-10-04) -------------------------
// Steve: "If they had submitted an order in July and paid yesterday, I
// wouldn't move them to the top of the list over people that paid a week ago."
$aged = merch_stock_build_shipments([
    $r2(['OrderID' => '60', 'Name' => 'July Jane',  'Timestamp' => '7/10/2026',           'Pymt Date' => '2026-10-03']),   // ordered long ago, paid yesterday
    $r2(['OrderID' => '70', 'Name' => 'Sept Sam',   'Timestamp' => '2026-09-25 10:00:00', 'Pymt Date' => '2026-09-26']),   // paid a week ago
    $r2(['OrderID' => '80', 'Name' => 'Aug Abe',    'Timestamp' => '2026-08-30 10:00:00', 'Pymt Date' => '2026-08-31']),   // paid longest ago
    $r2(['OrderID' => '90', 'Name' => 'Pat Pickup', 'Timestamp' => '2026-09-15 10:00:00', 'Pymt Date' => '', 'Fulfillment' => 'Pickup at retreat']),
], $col2, FILAMENT_COLOR_ITEMS, []);
$agedNames = array_map(fn($x) => $x['name'], $aged['shipments']);
expect('age: shipments rank by PAID date, not order number or order date', $agedNames, ['Aug Abe', 'Pat Pickup', 'Sept Sam', 'July Jane']);
$byN = [];
foreach ($aged['shipments'] as $x) { $byN[$x['name']] = $x; }
expect('age: paid row is dated by Pymt Date and labelled paid', [date('Y-m-d', $byN['July Jane']['ageTs']), $byN['July Jane']['ageBasis']], ['2026-10-03', 'paid']);
expect('age: the July order date is still kept separately', date('Y-m-d', $byN['July Jane']['oldestTs']), '2026-07-10');
expect('age: unpaid Pickup falls back to its order date', [date('Y-m-d', $byN['Pat Pickup']['ageTs']), $byN['Pat Pickup']['ageBasis']], ['2026-09-15', 'ordered']);
expect('age: lines carry the paid date too', date('Y-m-d', $byN['July Jane']['lines'][0]['ts']), '2026-10-03');
expect('age label: paid', merch_stock_age_label(merch_stock_parse_ts('2026-09-30'), 'paid', merch_stock_parse_ts('2026-10-04 12:00:00')), 'Paid Sep 30 (4 days)');
expect('age label: ordered', merch_stock_age_label(merch_stock_parse_ts('2026-09-30'), 'ordered', merch_stock_parse_ts('2026-10-04 12:00:00')), 'Ordered Sep 30 (4 days)');
expect('age label: no date', merch_stock_age_label(null, 'paid'), '');
// A shipment's age is its longest-waiting row.
$multi = merch_stock_build_shipments([
    $r2(['OrderID' => '100', 'Name' => 'Mo', 'Pymt Date' => '2026-09-10']),
    $r2(['OrderID' => '101', 'Name' => 'Mo', 'Pymt Date' => '2026-10-02', 'Item' => 'Hearts Cutter Holder']),
], $col2, FILAMENT_COLOR_ITEMS, []);
expect('age: a shipment is as old as its longest-paid row', date('Y-m-d', $multi['shipments'][0]['ageTs']), '2026-09-10');
// Same paid day: lowest OrderID first. Missing dates sort last, by OrderID.
$tie = [
    ['key' => 'b', 'minOrderId' => 7, 'ageTs' => 100],
    ['key' => 'a', 'minOrderId' => 5, 'ageTs' => 100],
    ['key' => 'z', 'minOrderId' => 1, 'ageTs' => null],
    ['key' => 'y', 'minOrderId' => 2, 'ageTs' => 50],
];
usort($tie, 'merch_stock_age_cmp');
expect('age cmp: earliest date, then order number, undated last', array_map(fn($x) => $x['key'], $tie), ['y', 'a', 'b', 'z']);
// The close-out ranking and the reserve pass both follow paid date.
$agedShips = array_map(fn($x) => $x + ['other' => [], 'special' => false, 'printKey' => $x['key'], 'type' => 'ship', 'orderIds' => [(string) $x['minOrderId']]], [
    ['key' => 'ship:jane', 'name' => 'July Jane', 'minOrderId' => 60, 'ageTs' => merch_stock_parse_ts('2026-10-03'), 'lines' => [['orderId' => '60', 'item' => 'Blade Holder', 'color' => '#15 CM Blue', 'qty' => 1]]],
    ['key' => 'ship:abe', 'name' => 'Aug Abe', 'minOrderId' => 80, 'ageTs' => merch_stock_parse_ts('2026-08-31'), 'lines' => [['orderId' => '80', 'item' => 'Blade Holder', 'color' => '#17 Teal', 'qty' => 1]]],
]);
$agedPartial = merch_stock_allocate($agedShips, [], 'age')['partial'];
expect('rank: close-out follows paid date even when order numbers say otherwise', array_map(fn($x) => $x['name'], merch_stock_closeout_rank($agedPartial, 'age')), ['Aug Abe', 'July Jane']);

echo "\n";
if (!empty($failures)) {
    echo count($failures) . " failure(s):\n" . implode("\n", $failures) . "\n";
    exit(1);
}
echo "All merch_stock tests passed.\n";
