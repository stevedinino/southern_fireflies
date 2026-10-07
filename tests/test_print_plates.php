<?php
// Build: 2026-10-07-A
// ============================================================
// Direct tests for print_plates.php's planner (rewritten 2026-10-07 around
// Steve's 22 real Bambu plates - see that file's header). Run from
// anywhere:
//
//     php tests/test_print_plates.php
//
// Calls the planner functions directly (no web server, no CSV needed)
// against small hand-built row sets.
// ============================================================

error_reporting(E_ALL);
define('FILAMENT_COLOR_ITEMS', ['Oval Cutter Holder', 'Circle Cutter Holder', 'Rectangle Cutter Holder', 'Hearts Cutter Holder', 'Tool Holder Stand', 'Tape Gun Holder', 'Tape Gun Add-On', 'Blade Holder']);
require dirname(__DIR__) . '/print_plates.php';

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

const CIRCLE = 'Circle Cutter Holder';
const OVAL = 'Oval Cutter Holder';
const RECT = 'Rectangle Cutter Holder';
const HEARTS = 'Hearts Cutter Holder';
const STAND = 'Tool Holder Stand';
const TGH = 'Tape Gun Holder';
const TGA = 'Tape Gun Add-On';
const BLADE = 'Blade Holder';

/** A needs-creating row for one customer (shipment key = the name). */
function row(string $item, string $color, int $qty, string $orderId, string $name, ?int $ts = null, array $extra = []): array
{
    return array_merge(['item' => $item, 'color' => $color, 'qty' => $qty, 'orderId' => $orderId, 'customerName' => $name, 'orderGroupId' => '', 'shipmentKey' => 'ship:' . strtolower($name) . '|1', 'ts' => $ts], $extra);
}

/** One line per plate, in print order: "09 Circle Oval 100%: Circle x1 [Wendy(1)] + Oval x1 [Wendy(1)]". */
function plateLines(array $groups, ?string $color = null): array
{
    $out = [];
    foreach ($groups as $g) {
        if ($color !== null && $g['color'] !== $color) {
            continue;
        }
        foreach ($g['plateGroups'] as $pg) {
            foreach ($pg['plates'] as $plate) {
                $bits = [];
                foreach ($plate['items'] as $item => $data) {
                    $who = [];
                    foreach ($data['orders'] as $o) {
                        $who[] = $o['customerName'] . '(' . $o['qty'] . ')';
                    }
                    $bits[] = $data['qty'] . 'x ' . $item . ' [' . implode(',', $who) . ']';
                }
                $out[] = $plate['plateNo'] . ' ' . round($plate['fillFraction'] * 100) . '%: ' . implode(' + ', $bits);
            }
        }
    }
    return $out;
}

$BLUE = '#15 CM Blue';
$D = 86400;

// ---- The plate table itself ----------------------------------------
expect('22 plates', count(PRINT_PLATES), 22);
expect('plate numbers 01..22 in order', array_column(PRINT_PLATES, 'no'), array_map(fn($n) => sprintf('%02d', $n), range(1, 22)));
$soloFromPlates = [];
foreach (PRINT_PLATES as $p) {
    if (!empty($p['solo'])) {
        foreach ($p['items'] as $item => $cap) {
            $soloFromPlates[$item] = $cap;
        }
    }
}
$soloConst = PRINT_PLATE_SOLO_CAPACITY;
ksort($soloFromPlates);
ksort($soloConst);
expect('PRINT_PLATE_SOLO_CAPACITY agrees with the single-type plates', $soloConst, $soloFromPlates);
$pairCount = count(array_filter(PRINT_PLATES, fn($p) => empty($p['solo'])));
expect('15 two-type plates', $pairCount, 15);
$allItemsOk = true;
foreach (PRINT_PLATES as $p) {
    foreach ($p['items'] as $item => $cap) {
        if (!in_array($item, FILAMENT_COLOR_ITEMS, true) || $cap < 1) {
            $allItemsOk = false;
        }
    }
}
expect('every plate item is a real filament item with a positive count', $allItemsOk, true);

// ---- Which real plate holds a set of pieces -------------------------
$plateNo = fn(array $c) => ($i = print_plate_best_plate($c)) === null ? 'none' : PRINT_PLATES[$i]['no'];
expect('1 Circle -> 02 Circles', $plateNo([CIRCLE => 1]), '02');
expect('2 Circles -> 02', $plateNo([CIRCLE => 2]), '02');
expect('3 Circles -> none', $plateNo([CIRCLE => 3]), 'none');
expect('Circle + Oval -> 09', $plateNo([CIRCLE => 1, OVAL => 1]), '09');
expect('Hearts + Rectangle -> 08', $plateNo([HEARTS => 1, RECT => 1]), '08');
expect('Oval + Hearts -> 20', $plateNo([OVAL => 1, HEARTS => 1]), '20');
expect('Circle + Hearts -> 21', $plateNo([CIRCLE => 1, HEARTS => 1]), '21');
expect('3 Hearts -> 05', $plateNo([HEARTS => 3]), '05');
expect('lone Tape Gun Holder -> 03 (not a pair plate)', $plateNo([TGH => 1]), '03');
expect('3 Tape Gun Holders + 3 Add-Ons -> 03', $plateNo([TGH => 3, TGA => 3]), '03');
expect('4 Tape Gun Holders -> none', $plateNo([TGH => 4]), 'none');
expect('Circle + Tape Gun + Add-On -> 15', $plateNo([CIRCLE => 1, TGH => 1, TGA => 1]), '15');
expect('Blade Holder + Tape Gun -> 19', $plateNo([BLADE => 1, TGH => 1]), '19');
expect('2 Blade Holders + Rectangle -> 22', $plateNo([BLADE => 2, RECT => 1]), '22');
expect('Blade Holder + Rectangle -> 22', $plateNo([BLADE => 1, RECT => 1]), '22');
expect('Tool Stand -> 07', $plateNo([STAND => 1]), '07');
expect('2 Tool Stands -> none', $plateNo([STAND => 2]), 'none');
expect('Stand + anything -> none', $plateNo([STAND => 1, CIRCLE => 1]), 'none');
expect('Circle + Oval + Hearts -> none (no 3-type plate)', $plateNo([CIRCLE => 1, OVAL => 1, HEARTS => 1]), 'none');

// ---- Cutting one customer into plate-sized chunks ---------------------
expect('Circle + Oval is one chunk', count(print_plate_chunk_needs([CIRCLE => 1, OVAL => 1])), 1);
expect('5 Circles = 3 chunks (2,2,1)', array_map('array_sum', print_plate_chunk_needs([CIRCLE => 5])), [2, 2, 1]);
expect('Stand + Tape Gun + Add-On = 2 chunks', count(print_plate_chunk_needs([STAND => 1, TGH => 1, TGA => 1])), 2);
expect('2 Blade + Rect is one chunk (plate 22)', count(print_plate_chunk_needs([BLADE => 2, RECT => 1])), 1);
expect('chunks never lose a piece', array_sum(array_map('array_sum', print_plate_chunk_needs([CIRCLE => 1, OVAL => 3, HEARTS => 2, TGH => 5, TGA => 1, STAND => 2]))), 14);

// ---- The cases Steve actually hit (2026-09-25) ------------------------
// Shelly: two Rectangles stay together on plate 06; Linda's Hearts are their own.
$rows = [
    row(HEARTS, $BLUE, 1, '745', 'Linda', 5 * $D),
    row(RECT, $BLUE, 1, '737', 'Shelly', 2 * $D),
    row(RECT, $BLUE, 1, '740', 'Shelly', 2 * $D),
];
expect('Shelly: both Rectangles on plate 06, Hearts on 05', plateLines(print_plate_group_queue($rows)), [
    '06 100%: 2x Rectangle Cutter Holder [Shelly(1),Shelly(1)]',
    '05 33%: 1x Hearts Cutter Holder [Linda(1)]',
]);

// Wendy: her Circle + Oval (separate submissions, same shipment key) share plate 09; Georgia's Oval is alone.
$rows = [
    row(OVAL, '#13 Lilac', 1, '530', 'Georgia', 1 * $D),
    row(CIRCLE, '#13 Lilac', 1, '673', 'Wendy', 3 * $D, ['shipmentKey' => 'ship:wendy|2']),
    row(OVAL, '#13 Lilac', 1, '674', 'Wendy', 3 * $D, ['shipmentKey' => 'ship:wendy|2']),
];
expect('Wendy: Circle+Oval together on 09 (Georgia is older, so her plate is first)', plateLines(print_plate_group_queue($rows)), [
    '04 50%: 1x Oval Cutter Holder [Georgia(1)]',
    '09 100%: 1x Circle Cutter Holder [Wendy(1)] + 1x Oval Cutter Holder [Wendy(1)]',
]);

// Jean: Stand, Tape Gun Holder, Add-On -> the stand alone, the tape-gun pair together.
$rows = [
    row(STAND, '#02 Coral', 1, '795', 'Jean', 1 * $D, ['orderGroupId' => '46']),
    row(TGA, '#02 Coral', 1, '796', 'Jean', 1 * $D, ['orderGroupId' => '46']),
    row(TGH, '#02 Coral', 1, '797', 'Jean', 1 * $D, ['orderGroupId' => '46']),
];
$lines = plateLines(print_plate_group_queue($rows));
expect('Jean: two plates', count($lines), 2);
expect('Jean: tape gun + add-on on one plate', in_array('03 33%: 1x Tape Gun Holder [Jean(1)] + 1x Tape Gun Add-On [Jean(1)]', $lines, true), true);

// ---- Two customers' single pieces share a two-type plate -----------------
$rows = [
    row(HEARTS, $BLUE, 1, 'I1', 'Cy', 1 * $D),
    row(RECT, $BLUE, 1, 'I2', 'Di', 2 * $D),
];
expect('single pieces from two customers share plate 08', plateLines(print_plate_group_queue($rows)), [
    '08 100%: 1x Hearts Cutter Holder [Cy(1)] + 1x Rectangle Cutter Holder [Di(1)]',
]);

// ... but a customer is never pulled apart to make a plate fuller: Ann has
// 2 Circles (plate 02 exactly); Bob's lone Oval does NOT borrow one of them.
$rows = [
    row(CIRCLE, $BLUE, 2, 'A1', 'Ann', 1 * $D),
    row(OVAL, $BLUE, 1, 'B1', 'Bob', 2 * $D),
];
expect('no borrowing from another customer\'s plate', plateLines(print_plate_group_queue($rows)), [
    '02 100%: 2x Circle Cutter Holder [Ann(2)]',
    '04 50%: 1x Oval Cutter Holder [Bob(1)]',
]);

// ---- Wait time: longest-waiting customer's plate prints first ----------------
$rows = [
    row(OVAL, $BLUE, 2, 'N1', 'Newer', 10 * $D),
    row(CIRCLE, $BLUE, 2, 'O1', 'Oldest', 1 * $D),
    row(HEARTS, $BLUE, 3, 'M1', 'Middle', 5 * $D),
    row(RECT, $BLUE, 2, 'U1', 'Undated', null),
];
expect('plates in wait order, undated last', array_map(fn($l) => substr($l, 0, 2), plateLines(print_plate_group_queue($rows))), ['02', '05', '04', '06']);

// A customer's wait time counts their shirts/hats and other colors too.
$rows = [
    row(OVAL, $BLUE, 2, 'N1', 'Ann', 8 * $D),
    row(CIRCLE, $BLUE, 2, 'O1', 'Bea', 3 * $D),
    row('Hat', '', 1, 'N2', 'Ann', 1 * $D), // Ann's hat is the oldest thing anyone is waiting on
];
expect('wait time counts the hat', array_map(fn($l) => substr($l, 0, 2), plateLines(print_plate_group_queue($rows))), ['04', '02']);

// Same wait time -> lower OrderID first.
$rows = [
    row(CIRCLE, $BLUE, 2, '20', 'Later', 5 * $D),
    row(OVAL, $BLUE, 2, '10', 'Earlier', 5 * $D),
];
expect('tie on wait time: lower OrderID first', array_map(fn($l) => substr($l, 0, 2), plateLines(print_plate_group_queue($rows))), ['04', '02']);

// ---- Capacity forces a split: 5 Circles from one order -> 2,2,1 -------
$rows = [row(CIRCLE, $BLUE, 5, '900', 'Solo', 1 * $D)];
expect('capacity-forced split: 3 plates', plateLines(print_plate_group_queue($rows)), [
    '02 100%: 2x Circle Cutter Holder [Solo(2)]',
    '02 100%: 2x Circle Cutter Holder [Solo(2)]',
    '02 50%: 1x Circle Cutter Holder [Solo(1)]',
]);
// The leftover of one big order can ride with another customer's piece.
$rows = [row(CIRCLE, $BLUE, 3, '900', 'Big', 1 * $D), row(OVAL, $BLUE, 1, '901', 'Small', 2 * $D)];
expect('big order\'s leftover Circle shares plate 09 with a stranger\'s Oval', plateLines(print_plate_group_queue($rows)), [
    '02 100%: 2x Circle Cutter Holder [Big(2)]',
    '09 100%: 1x Circle Cutter Holder [Big(1)] + 1x Oval Cutter Holder [Small(1)]',
]);

// ---- Colors never mix; excluded colors and non-plate items are skipped ---------
$rows = [
    row(CIRCLE, $BLUE, 1, '1', 'Ann', 1 * $D),
    row(OVAL, '#12 Purple', 1, '2', 'Bob', 2 * $D),
    row(OVAL, 'Stars & Stripes (+$7)', 1, '3', 'Cy', 3 * $D),
    row('Hat', '#15 CM Blue', 1, '4', 'Di', 4 * $D),
];
$groups = print_plate_group_queue($rows);
expect('colors planned separately, in priority order (CM Blue before Purple)', array_column($groups, 'color'), [$BLUE, '#12 Purple']);
expect('Circle and Oval of different colors stay on separate plates', [count(plateLines($groups, $BLUE)), count(plateLines($groups, '#12 Purple'))], [1, 1]);

// ---- Several order lines for one customer: pieces drawn first line first,
// per-order quantities add up to what the plate says -------------------
$rows = [
    row(CIRCLE, $BLUE, 1, 'L1', 'Lee', 1 * $D),
    row(CIRCLE, $BLUE, 1, 'L2', 'Lee', 1 * $D),
    row(OVAL, $BLUE, 1, 'L3', 'Lee', 1 * $D),
];
expect('Lee: 2 Circles + 1 Oval fit on 2 plates', count(plateLines(print_plate_group_queue($rows))), 2);

// ---- Conservation + never-split-when-it-fits, over many random queues ------
mt_srand(20261007);
$items = [CIRCLE, OVAL, RECT, HEARTS, STAND, TGH, TGA, BLADE];
$bad = [];
for ($trial = 0; $trial < 300; $trial++) {
    $rows = [];
    $want = [];
    $custCount = mt_rand(1, 9);
    $id = 0;
    for ($c = 0; $c < $custCount; $c++) {
        $name = 'C' . $c;
        $ts = mt_rand(0, 4) === 0 ? null : mt_rand(1, 40) * $D;
        $lineCount = mt_rand(1, 4);
        for ($l = 0; $l < $lineCount; $l++) {
            $item = $items[mt_rand(0, 7)];
            $color = ['#15 CM Blue', '#12 Purple', '#14 Sky Blue'][mt_rand(0, 2)];
            $qty = mt_rand(1, 4);
            $id++;
            $rows[] = row($item, $color, $qty, (string) $id, $name, $ts);
            $want[$color][$item] = ($want[$color][$item] ?? 0) + $qty;
        }
    }
    $got = [];
    $platesOf = []; // color|customer => set of plate indexes
    $needsOf = [];  // color|customer => item => qty
    foreach ($rows as $r) {
        $needsOf[$r['color'] . '|' . $r['customerName']][$r['item']] = ($needsOf[$r['color'] . '|' . $r['customerName']][$r['item']] ?? 0) + $r['qty'];
    }
    $n = 0;
    $prevTs = null;
    $groups = print_plate_group_queue($rows);
    foreach ($groups as $g) {
        $prev = -1;
        foreach ($g['plateGroups'] as $pg) {
            foreach ($pg['plates'] as $plate) {
                $n++;
                $contents = [];
                foreach ($plate['items'] as $item => $d) {
                    $got[$g['color']][$item] = ($got[$g['color']][$item] ?? 0) + $d['qty'];
                    $contents[$item] = $d['qty'];
                    $sum = array_sum(array_column($d['orders'], 'qty'));
                    if ($sum !== $d['qty']) {
                        $bad[] = "trial $trial: order quantities on a plate don't add up";
                    }
                    foreach ($d['orders'] as $o) {
                        $platesOf[$g['color'] . '|' . $o['customerName']][$n] = true;
                    }
                }
                if ($plate['plateNo'] === '' || print_plate_best_plate($contents) === null) {
                    $bad[] = "trial $trial: a plate no real plate can hold: " . json_encode($contents);
                }
                if ($plate['fillFraction'] <= 0 || $plate['fillFraction'] > 1.0000001) {
                    $bad[] = "trial $trial: bad fill " . $plate['fillFraction'];
                }
                $ts = $plate['oldestTs'];
                $key = $ts ?? PHP_INT_MAX;
                if ($key < $prev) {
                    $bad[] = "trial $trial: plates not in wait order within " . $g['color'];
                }
                $prev = $key;
            }
        }
    }
    ksort($want);
    ksort($got);
    foreach ($want as &$w) { ksort($w); }
    foreach ($got as &$w) { ksort($w); }
    unset($w);
    if (json_encode($want) !== json_encode($got)) {
        $bad[] = "trial $trial: pieces lost or duplicated";
    }
    foreach ($needsOf as $k => $needs) {
        $maxPlates = count(print_plate_chunk_needs($needs));
        if (count($platesOf[$k] ?? []) > $maxPlates) {
            $bad[] = "trial $trial: customer $k on " . count($platesOf[$k]) . " plates but could fit on $maxPlates";
        }
    }
}
expect('300 random queues: nothing lost, every plate real, wait order, no extra splitting', array_slice($bad, 0, 3), []);

// ---- Timestamp parser ---------------------------------------------------------
expect('parse ts: ISO', date('Y-m-d H:i:s', print_plate_parse_ts('2026-10-03 18:26:31')), '2026-10-03 18:26:31');
expect('parse ts: M/D/YYYY', date('Y-m-d', print_plate_parse_ts('7/5/2026')), '2026-07-05');
expect('parse ts: blank/garbage', [print_plate_parse_ts(''), print_plate_parse_ts('soon')], [null, null]);

// ---- Verdict ---------------------------------------------------------
if ($failures) {
    echo 'FAIL - ' . count($failures) . " assertion(s) failed.\n";
    exit(1);
}
echo "PASS - all print-plate planner assertions matched.\n";
exit(0);
