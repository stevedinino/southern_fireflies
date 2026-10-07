<?php
// Build: 2026-10-07-A
// ============================================================
// Print-plate planning for ourmerch.php's "Sort by Print Plate" view and
// merch_stock_report.php's "what to print next" plan.
//
// 2026-10-07 REWRITE (Steve): the plan now picks from his 22 REAL Bambu
// Studio plates (PRINT_PLATES below, numbered exactly as in his project)
// instead of fractional footprint estimates, so every suggestion names a
// plate he can open directly. The old planner (template matching, solo
// capacities, "mixable" footprint groups, order-completion preference and
// the 2026-09-25 "reserve a plate for one customer" re-plan loop) is gone:
// Steve found it split plates in odd ways and split one customer's order
// across two or three plates. What remains is deliberately small - it
// only cares about two things:
//   1. COLOR   - a plate is only ever one color (switching filament costs
//                real time), so each color is planned on its own.
//   2. WAIT    - customers who have waited longest (paid longest ago) get
//                their pieces onto the earliest plates.
//
// How a color is planned
//   - Customers are taken oldest-waiting first. A "customer" is the same
//     key the Needs Shipping view uses: Name+Zip shipment key, else the
//     OrderGroupID, else the single OrderID.
//   - Each customer's pieces in that color are kept TOGETHER: they are cut
//     into the fewest plate-sized chunks (a greedy cover of the real
//     plates - e.g. 1 Circle + 1 Oval is exactly plate 09). A customer is
//     only ever on more than one plate when no single plate can hold what
//     they ordered.
//   - Each chunk goes onto an already-started plate if some real plate
//     can still hold the combined pieces (best resulting fill wins, ties
//     go to the earliest plate); otherwise it starts a new plate. This is
//     how two different customers' single pieces share a two-type plate -
//     but nobody's pieces are ever pulled apart to make a plate fuller.
//   - Plates come out in print order: the plate holding the longest-
//     waiting customer first.
//   - A plate that doesn't fill a whole real plate is shown as partial
//     (fill = pieces on it / pieces on the smallest real plate that holds
//     them) - same as before, it can still be combined by hand.
//
// Keep updating PRINT_PLATES when a plate changes - nothing else needs to.
// Counts below are read from Steve's 2026-10-07 screenshot plus his
// answers (plate 03 = 3 Tape Gun Holders + 3 Add-Ons; plate 22 = 2 Blade
// Holders + 1 Rectangle; Tool Stand = 1 per plate, as the screenshot shows
// one stand filling the plate - the old planner had 2).
//
// Requires merch_items.php's FILAMENT_COLOR_ITEMS to already be defined -
// require this file after pricing.php (same order merch_items.php's other
// consumers already use).
// ============================================================

// ---- The 22 real plates ----------------------------------------
// 'no' is the plate number in Steve's Bambu project, 'name' its label
// there (names cut off in the screenshot are completed here), 'items'
// is item => how many fit on that plate; 'solo' marks the single-type
// plates (01-07 - plate 03 counts: it is Tape Gun Holders plus their
// Add-Ons). List order matters only for tie-breaks (earlier wins), so
// keep them in plate-number order.
// A plate may be printed with fewer pieces than listed (that is a
// "partial plate"); it can never hold an item that isn't listed.
const PRINT_PLATES = [
    ['no' => '01', 'solo' => true, 'name' => 'Blade Holder', 'items' => ['Blade Holder' => 3]],
    ['no' => '02', 'solo' => true, 'name' => 'Circles', 'items' => ['Circle Cutter Holder' => 2]],
    ['no' => '03', 'solo' => true, 'name' => 'Tape Gun Holder', 'items' => ['Tape Gun Holder' => 3, 'Tape Gun Add-On' => 3]],
    ['no' => '04', 'solo' => true, 'name' => 'Ovals', 'items' => ['Oval Cutter Holder' => 2]],
    ['no' => '05', 'solo' => true, 'name' => 'Hearts', 'items' => ['Hearts Cutter Holder' => 3]],
    ['no' => '06', 'solo' => true, 'name' => 'Rectangles', 'items' => ['Rectangle Cutter Holder' => 2]],
    ['no' => '07', 'solo' => true, 'name' => 'Tool Stand', 'items' => ['Tool Holder Stand' => 2]],
    ['no' => '08', 'name' => 'Hearts Rectangles', 'items' => ['Hearts Cutter Holder' => 1, 'Rectangle Cutter Holder' => 1]],
    ['no' => '09', 'name' => 'Circle Oval', 'items' => ['Circle Cutter Holder' => 1, 'Oval Cutter Holder' => 1]],
    ['no' => '10', 'name' => 'Circle Blade Holder', 'items' => ['Circle Cutter Holder' => 1, 'Blade Holder' => 1]],
    ['no' => '11', 'name' => 'Oval Blade Holder', 'items' => ['Oval Cutter Holder' => 1, 'Blade Holder' => 1]],
    ['no' => '12', 'name' => 'Hearts Blade Holder', 'items' => ['Hearts Cutter Holder' => 1, 'Blade Holder' => 1]],
    ['no' => '13', 'name' => 'Ovals Rectangles', 'items' => ['Oval Cutter Holder' => 1, 'Rectangle Cutter Holder' => 1]],
    ['no' => '14', 'name' => 'Circles Rectangles', 'items' => ['Circle Cutter Holder' => 1, 'Rectangle Cutter Holder' => 1]],
    ['no' => '15', 'name' => 'Circle Tape Gun and Add-On', 'items' => ['Circle Cutter Holder' => 1, 'Tape Gun Holder' => 1, 'Tape Gun Add-On' => 1]],
    ['no' => '16', 'name' => 'Hearts Tape Gun and Add-On', 'items' => ['Hearts Cutter Holder' => 1, 'Tape Gun Holder' => 1, 'Tape Gun Add-On' => 1]],
    ['no' => '17', 'name' => 'Ovals Tape Gun and Add-On', 'items' => ['Oval Cutter Holder' => 1, 'Tape Gun Holder' => 1, 'Tape Gun Add-On' => 1]],
    ['no' => '18', 'name' => 'Rectangles Tape Gun and Add-On', 'items' => ['Rectangle Cutter Holder' => 1, 'Tape Gun Holder' => 1, 'Tape Gun Add-On' => 1]],
    ['no' => '19', 'name' => 'Blade Holder Tape Gun and Add-On', 'items' => ['Blade Holder' => 1, 'Tape Gun Holder' => 1, 'Tape Gun Add-On' => 1]],
    ['no' => '20', 'name' => 'Ovals Hearts', 'items' => ['Oval Cutter Holder' => 1, 'Hearts Cutter Holder' => 1]],
    ['no' => '21', 'name' => 'Circles Hearts', 'items' => ['Circle Cutter Holder' => 1, 'Hearts Cutter Holder' => 1]],
    ['no' => '22', 'name' => 'Rectangles Blade Holder', 'items' => ['Rectangle Cutter Holder' => 1, 'Blade Holder' => 2]],
];

// ---- Per-item "one plate of just this" count --------------------
// How many of ONLY this item the item's own single-type plate holds
// (plates 01-07). Used by merch_stock_edit.php's "+plate" button and as
// the list of items the planner handles at all (an item missing here is
// left out of the plan). tests/test_print_plates.php checks this agrees
// with PRINT_PLATES, so change both together.
const PRINT_PLATE_SOLO_CAPACITY = [
    'Circle Cutter Holder' => 2,
    'Oval Cutter Holder' => 2,
    'Rectangle Cutter Holder' => 2,
    'Hearts Cutter Holder' => 3,
    'Tool Holder Stand' => 2,
    'Tape Gun Holder' => 3,
    'Tape Gun Add-On' => 3,
    'Blade Holder' => 3,
];

// ---- Color display order -----------------------------------------
// Most-popular-first, so the biggest backlog clears first (Steve,
// 2026-09-17). Derived from the "Popular Items & Colors" tab of
// SFR_Merch_Analytics.xlsx (all-time Total Quantity, active orders
// only, as of the 2026-09-17 update). A color not listed here sorts
// last, alphabetically - nothing has to be added for a brand-new color
// to work. Deliberately excludes Stars & Stripes (see below).
const PRINT_PLATE_COLOR_PRIORITY = [
    '#15 CM Blue', '#12 Purple', '#14 Sky Blue', '#17 Teal', '#09 Magenta',
    '#10 Light Pink', '#08 Hot Pink', '#06 Yellow', '#04 Orange', '#13 Lilac',
    '#16 Navy Blue', '#01 Red', '#02 Coral', '#25 White', '#20 Light Green',
    '#03 Maroon', '#22 Black', '#11 Plum', '#05 Silk Orange', '#23 Gray',
    '#19 Green', '#21 Olive Green', '#18 Silk Green', '#24 Ice',
    'Rainbow (+$2)', '#26 Tan', '#07 Gold', '#27 Brown','#28 Copper',
];

// Colors that never enter the print-plate grouping. Stars & Stripes isn't
// really one filament color - it's a red/white/blue print needing its own
// plate setup (Steve, 2026-09-17) - so rows in it simply don't appear
// here; they stay visible in the plain flat Needs Creating table.
const PRINT_PLATE_EXCLUDED_COLORS = [
    'Stars & Stripes (+$7)',
];

/** Total pieces on a plate recipe or a contents map (item => qty). */
function print_plate_pieces(array $items): int
{
    return (int) array_sum($items);
}

/**
 * Can some real plate hold exactly these contents (item => qty), in the
 * sense that every item is on the plate and its count is within the
 * plate's count? Returns the index into PRINT_PLATES of the best such
 * plate, or null. "Best" = a single-type plate if one fits (so a lone
 * Tape Gun Holder is plate 03, not plate 15), then the plate with the
 * fewest piece slots, then the earlier plate number.
 */
function print_plate_best_plate(array $contents): ?int
{
    $contents = array_filter($contents, fn($q) => $q > 0);
    if (empty($contents)) {
        return null;
    }
    $best = null;
    $bestKey = null;
    foreach (PRINT_PLATES as $idx => $plate) {
        $ok = true;
        foreach ($contents as $item => $qty) {
            if (($plate['items'][$item] ?? 0) < $qty) {
                $ok = false;
                break;
            }
        }
        if (!$ok) {
            continue;
        }
        $key = [!empty($plate['solo']) ? 0 : 1, print_plate_pieces($plate['items']), $idx];
        if ($bestKey === null || $key < $bestKey) {
            $bestKey = $key;
            $best = $idx;
        }
    }
    return $best;
}

/**
 * Cut one customer's needs (item => qty) into the fewest chunks that
 * each fit one real plate. Greedy: repeatedly take the plate that covers
 * the most of what's still needed (ties: the plate with fewer piece slots
 * - least wasted - then the earlier plate number). Returns a list of
 * item => qty maps. An item no plate can hold is returned as its own
 * one-piece chunks so nothing is ever dropped.
 */
function print_plate_chunk_needs(array $needs): array
{
    $needs = array_filter($needs, fn($q) => $q > 0);
    $chunks = [];
    while (!empty($needs)) {
        $bestIdx = null;
        $bestKey = null;
        foreach (PRINT_PLATES as $idx => $plate) {
            $cover = 0;
            foreach ($plate['items'] as $item => $cap) {
                $cover += min($needs[$item] ?? 0, $cap);
            }
            if ($cover === 0) {
                continue;
            }
            $key = [-$cover, print_plate_pieces($plate['items']), $idx];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $bestIdx = $idx;
            }
        }
        $chunk = [];
        if ($bestIdx === null) {
            // No plate holds any of these items (config out of step) -
            // still show them rather than drop them.
            $item = array_key_first($needs);
            $chunk[$item] = 1;
        } else {
            foreach (PRINT_PLATES[$bestIdx]['items'] as $item => $cap) {
                $take = min($needs[$item] ?? 0, $cap);
                if ($take > 0) {
                    $chunk[$item] = $take;
                }
            }
        }
        foreach ($chunk as $item => $take) {
            $needs[$item] -= $take;
            if ($needs[$item] <= 0) {
                unset($needs[$item]);
            }
        }
        $chunks[] = $chunk;
    }
    return $chunks;
}

/** Parse the merchandise.csv Timestamp / Pymt Date text ("2026-10-03 18:26:31" or "7/5/2026") to a unix time, or null. */
function print_plate_parse_ts(string $raw): ?int
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'n/j/Y H:i:s', 'n/j/Y H:i', 'n/j/Y'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $raw);
        $errs = DateTime::getLastErrors();
        if ($d !== false && (!$errs || ($errs['warning_count'] === 0 && $errs['error_count'] === 0))) {
            return $d->getTimestamp();
        }
    }
    return null;
}

/**
 * Plan every plate for the Needs-Creating rows.
 *
 * $rows: array of ['item', 'color', 'qty', 'orderId', 'customerName',
 * 'orderGroupId' (opt), 'shipmentKey' (opt), 'ts' (opt, int unix time
 * the customer has been waiting since - paid date, else order date)].
 * Pass EVERY needs-creating row, shirts/hats and excluded colors
 * included: they never get a plate, but a customer's wait time is the
 * oldest 'ts' across all their rows.
 *
 * Returns per-color groups, sorted by PRINT_PLATE_COLOR_PRIORITY
 * (unlisted colors last, alphabetically):
 *   [
 *     'color' => string,
 *     'plateGroups' => [   // one entry per plate, in PRINT ORDER
 *         ['group' => 'Plate 09 · Circle Oval', 'plates' => [
 *             ['items' => [item => ['qty'=>int, 'orders'=>[
 *                 ['orderId'=>, 'customerName'=>, 'qty'=>int, 'completionKey'=>string], ...]]],
 *              'fillFraction' => float (0..1, 1.0 = every slot of that plate used),
 *              'plateNo' => '09', 'oldestTs' => ?int],
 *         ]],
 *         ...
 *     ],
 *   ]
 * ('plates' always holds exactly one plate; the two-level shape is kept
 * so existing consumers keep working.) Every grouped unit appears on
 * exactly one plate.
 */
function print_plate_group_queue(array $rows): array
{
    $completionKeyFor = function (array $row): string {
        $shipmentKey = $row['shipmentKey'] ?? '';
        if ($shipmentKey !== '') {
            return 's:' . $shipmentKey;
        }
        $groupId = $row['orderGroupId'] ?? '';
        return $groupId !== '' ? ('g:' . $groupId) : ('o:' . ($row['orderId'] ?? ''));
    };

    // Per customer key: how long they've been waiting (oldest 'ts' over
    // ALL their rows) and their lowest numeric OrderID (tie-break).
    $keyTs = [];
    $keyMinOrder = [];
    foreach ($rows as $row) {
        if ((int) ($row['qty'] ?? 1) < 1) {
            continue;
        }
        $key = $completionKeyFor($row);
        $ts = isset($row['ts']) && $row['ts'] !== null && $row['ts'] !== '' ? (int) $row['ts'] : null;
        if ($ts !== null && (!isset($keyTs[$key]) || $ts < $keyTs[$key])) {
            $keyTs[$key] = $ts;
        }
        $oid = (string) ($row['orderId'] ?? '');
        if (ctype_digit($oid)) {
            $keyMinOrder[$key] = min($keyMinOrder[$key] ?? PHP_INT_MAX, (int) $oid);
        }
    }

    // color => key => ['lines' => item => list of order lines].
    $byColor = [];
    foreach ($rows as $row) {
        $item = $row['item'] ?? '';
        $color = $row['color'] ?? '';
        $qty = (int) ($row['qty'] ?? 1);
        if ($qty < 1 || !in_array($item, FILAMENT_COLOR_ITEMS, true)
            || !array_key_exists($item, PRINT_PLATE_SOLO_CAPACITY)
            || in_array($color, PRINT_PLATE_EXCLUDED_COLORS, true)) {
            continue;
        }
        $key = $completionKeyFor($row);
        $byColor[$color][$key]['lines'][$item][] = [
            'orderId' => $row['orderId'] ?? '',
            'customerName' => $row['customerName'] ?? '',
            'qty' => $qty,
            'completionKey' => $key,
        ];
    }

    $result = [];
    foreach ($byColor as $color => $customers) {
        // Oldest-waiting customer first (undated customers after dated
        // ones, then lowest OrderID, then key - same ordering the Stock &
        // Print Plan report uses for its own age ranking).
        $keys = array_keys($customers);
        usort($keys, function ($a, $b) use ($keyTs, $keyMinOrder) {
            return [$keyTs[$a] ?? PHP_INT_MAX, $keyMinOrder[$a] ?? PHP_INT_MAX, $a]
                <=> [$keyTs[$b] ?? PHP_INT_MAX, $keyMinOrder[$b] ?? PHP_INT_MAX, $b];
        });

        $plates = []; // each: ['contents'=>item=>qty, 'items'=>item=>['qty','orders'], 'ts', 'minOrder', 'seq']
        foreach ($keys as $key) {
            $lines = $customers[$key]['lines'];
            $needs = [];
            foreach ($lines as $item => $list) {
                $needs[$item] = array_sum(array_column($list, 'qty'));
            }
            foreach (print_plate_chunk_needs($needs) as $chunk) {
                // Draw this chunk's pieces from the customer's order
                // lines, first line first.
                $chunkItems = [];
                foreach ($chunk as $item => $n) {
                    $orders = [];
                    while ($n > 0 && !empty($lines[$item])) {
                        $take = min($n, $lines[$item][0]['qty']);
                        $orders[] = ['orderId' => $lines[$item][0]['orderId'], 'customerName' => $lines[$item][0]['customerName'], 'qty' => $take, 'completionKey' => $key];
                        $lines[$item][0]['qty'] -= $take;
                        $n -= $take;
                        if ($lines[$item][0]['qty'] <= 0) {
                            array_shift($lines[$item]);
                        }
                    }
                    $chunkItems[$item] = ['qty' => array_sum(array_column($orders, 'qty')), 'orders' => $orders];
                }

                // Best already-started plate that can still hold it.
                $bestPlate = null;
                $bestKey = null;
                foreach ($plates as $pi => $plate) {
                    $merged = $plate['contents'];
                    foreach ($chunkItems as $item => $d) {
                        $merged[$item] = ($merged[$item] ?? 0) + $d['qty'];
                    }
                    $idx = print_plate_best_plate($merged);
                    if ($idx === null) {
                        continue;
                    }
                    $fill = print_plate_pieces($merged) / print_plate_pieces(PRINT_PLATES[$idx]['items']);
                    $k = [-$fill, $pi];
                    if ($bestKey === null || $k < $bestKey) {
                        $bestKey = $k;
                        $bestPlate = $pi;
                    }
                }
                if ($bestPlate === null) {
                    $plates[] = ['contents' => [], 'items' => [], 'ts' => $keyTs[$key] ?? null, 'minOrder' => $keyMinOrder[$key] ?? PHP_INT_MAX, 'seq' => count($plates)];
                    $bestPlate = count($plates) - 1;
                }
                foreach ($chunkItems as $item => $d) {
                    $plates[$bestPlate]['contents'][$item] = ($plates[$bestPlate]['contents'][$item] ?? 0) + $d['qty'];
                    $plates[$bestPlate]['items'][$item]['qty'] = ($plates[$bestPlate]['items'][$item]['qty'] ?? 0) + $d['qty'];
                    foreach ($d['orders'] as $o) {
                        $list = $plates[$bestPlate]['items'][$item]['orders'] ?? [];
                        $last = count($list) - 1;
                        if ($last >= 0 && $list[$last]['orderId'] === $o['orderId']) {
                            $list[$last]['qty'] += $o['qty'];
                        } else {
                            $list[] = $o;
                        }
                        $plates[$bestPlate]['items'][$item]['orders'] = $list;
                    }
                }
                // Customers are visited oldest first, so a plate's first
                // member is its oldest; keep the min anyway.
                if (($keyTs[$key] ?? null) !== null && ($plates[$bestPlate]['ts'] === null || $keyTs[$key] < $plates[$bestPlate]['ts'])) {
                    $plates[$bestPlate]['ts'] = $keyTs[$key];
                }
                $plates[$bestPlate]['minOrder'] = min($plates[$bestPlate]['minOrder'], $keyMinOrder[$key] ?? PHP_INT_MAX);
            }
        }

        usort($plates, fn($a, $b) => [$a['ts'] ?? PHP_INT_MAX, $a['minOrder'], $a['seq']] <=> [$b['ts'] ?? PHP_INT_MAX, $b['minOrder'], $b['seq']]);

        $plateGroups = [];
        foreach ($plates as $plate) {
            $idx = print_plate_best_plate($plate['contents']);
            if ($idx === null) { // only the "no plate holds this item" fallback
                $label = array_key_first($plate['items']);
                $fill = 1.0;
                $no = '';
                $order = array_keys($plate['items']);
            } else {
                $def = PRINT_PLATES[$idx];
                $label = 'Plate ' . $def['no'] . ' · ' . $def['name'];
                $fill = print_plate_pieces($plate['contents']) / print_plate_pieces($def['items']);
                $no = $def['no'];
                $order = array_keys($def['items']);
            }
            $ordered = [];
            foreach ($order as $item) {
                if (isset($plate['items'][$item])) {
                    $ordered[$item] = $plate['items'][$item];
                }
            }
            $plateGroups[] = ['group' => $label, 'plates' => [[
                'items' => $ordered,
                'fillFraction' => min(1.0, $fill),
                'plateNo' => $no,
                'oldestTs' => $plate['ts'],
            ]]];
        }
        $result[$color] = ['color' => $color, 'plateGroups' => $plateGroups];
    }

    uksort($result, function ($a, $b) {
        $pa = array_search($a, PRINT_PLATE_COLOR_PRIORITY, true);
        $pb = array_search($b, PRINT_PLATE_COLOR_PRIORITY, true);
        $pa = ($pa === false) ? PHP_INT_MAX : $pa;
        $pb = ($pb === false) ? PHP_INT_MAX : $pb;
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        return strcasecmp($a, $b);
    });

    return array_values($result);
}
