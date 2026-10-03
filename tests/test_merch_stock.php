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

echo "\n";
if (!empty($failures)) {
    echo count($failures) . " failure(s):\n" . implode("\n", $failures) . "\n";
    exit(1);
}
echo "All merch_stock tests passed.\n";
