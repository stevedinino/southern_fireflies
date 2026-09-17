<?php
// Build: 2026-09-17-B
// ============================================================
// Print-plate batching config + matching logic for ourmerch.php's
// "Sort by Print Plate" toggle (Needs Creating view, read-only first
// cut, per Steve 2026-09-17).
//
// See "Claude outputs/print-plate-batch-sort-design-20260917.md" for
// the full design writeup. Short version: Steve prints one filament
// color at a time (switching colors costs real time), and already
// knows - from arranging plates by eye in Bambu Studio - which items,
// solo or mixed, fill a 256x256mm plate well. Nothing in this
// codebase can derive that from geometry, so PRINT_PLATE_GROUPS below
// is a small, hand-maintained list of what Steve knows. Update it
// whenever a real layout changes; no other code needs to change when
// you do.
//
// 2026-09-17, second pass: replaced the original named-recipe list
// (a plain item=>qty map per recipe) with this capacity-based model,
// after the first version confused the display (a "batch" summary
// and a separate full-totals list that silently overlapped - Steve
// couldn't tell the two apart) and turned out not to fit Tape Gun
// Holder/Add-On, which Steve orders a la carte in uneven ratios
// rather than a fixed pairing. Every item now just gets ONE number -
// its solo capacity, i.e. how many of just that item fit alone on a
// plate - and print_plate_pack_group() below turns a color's queue
// into a flat list of plates (full or trailing-partial), the same
// way Steve would fill them by hand: keep adding units until the
// plate's full, start a new one, and whatever's left when the queue
// runs out is still its own (partial) plate, not a hidden leftover
// pile. This also directly fixes the display confusion: every unit
// in the queue appears in exactly one plate line, never twice.
//
// The one exception is Circle Cutter Holder + Oval Cutter Holder,
// which Steve confirmed DOES mix on one plate (his one example of "a
// genuinely mixed" plate) - so those two share a group below, and the
// same capacity-based packer naturally produces a solo-circle plate,
// a solo-oval plate, or a mixed one, whichever the queue supports.
// Tape Gun Holder and Tape Gun Add-On, by contrast, are each their
// own single-item group - no automatic mixing between them, since
// Steve's own numbers (5 solo holders, 8 solo add-ons, "odd number
// combos" since they're ordered a la carte) don't reduce to a simple
// fixed ratio, and a proportional/footprint guess at how to mix them
// didn't reproduce how Steve actually arranges a plate by eye (see
// the design doc's git history / this file's build history for the
// abandoned attempt). Combining a leftover Holder or Add-On with
// something else on one physical plate stays Steve's manual call,
// same as it always was for any two items with no confirmed group.
//
// Requires merch_items.php's FILAMENT_COLOR_ITEMS to already be
// defined - require this file after pricing.php (same order
// merch_items.php's other consumers already use).
// ============================================================

// ---- Plate groups -------------------------------------------
// Each entry: group label => [item name => solo capacity], where
// "solo capacity" is how many of JUST that item fit alone on one
// plate. Items listed together in the SAME group are allowed to
// share one plate (in any mix that fits); items in different groups
// never mix automatically. A single-item group is just "this item's
// solo capacity" - most items are that simple.
//
// Confirmed by Steve 2026-09-17.
const PRINT_PLATE_GROUPS = [
    'Circle / Oval Cutter Holder' => [
        'Circle Cutter Holder' => 2,
        'Oval Cutter Holder' => 2,
    ],
    'Rectangle Cutter Holder' => [
        'Rectangle Cutter Holder' => 2,
    ],
    'Hearts Cutter Holder' => [
        'Hearts Cutter Holder' => 3,
    ],
    'Tool Holder Stand' => [
        'Tool Holder Stand' => 2,
    ],
    'Tape Gun Holder' => [
        'Tape Gun Holder' => 5,
    ],
    'Tape Gun Add-On' => [
        'Tape Gun Add-On' => 8,
    ],
];

// ---- Color display/priority order -----------------------------
// Most-popular-first, so the biggest backlog clears first (Steve,
// 2026-09-17). Derived from the "Popular Items & Colors" tab of
// SFR_Merch_Analytics.xlsx (all-time Total Quantity, active orders
// only, as of the 2026-09-17 update). A color with zero sales so far
// falls back to FILAMENT_COLORS' own catalog order, appended at the
// end by print_plate_group_queue() below - nothing has to be added
// here for a brand-new color to work, it just sorts last until it
// has some sales history.
//
// Deliberately excludes Stars & Stripes: per Steve (2026-09-17) it
// isn't really one filament color, it's a red/white/blue print
// needing its own plate setup, so it's out of this batching exercise
// entirely - see PRINT_PLATE_EXCLUDED_COLORS below. Low volume (a
// handful of orders total) makes handling those by hand, via the
// plain flat Needs Creating list, just fine.
const PRINT_PLATE_COLOR_PRIORITY = [
    '#15 CM Blue', '#12 Purple', '#14 Sky Blue', '#17 Teal', '#09 Magenta',
    '#10 Light Pink', '#08 Hot Pink', '#06 Yellow', '#04 Orange', '#13 Lilac',
    '#16 Navy Blue', '#01 Red', '#02 Coral', '#25 White', '#20 Light Green',
    '#03 Maroon', '#22 Black', '#11 Plum', '#05 Silk Orange', '#23 Gray',
    '#19 Green', '#21 Olive Green', '#18 Silk Green', '#24 Ice',
    'Rainbow (+$2)', '#26 Tan', '#07 Gold', '#27 Brown',
];

// Colors that never enter the print-plate grouping - see the comment
// above. Rows in one of these colors simply don't appear in the
// grouped view; they're still visible as always in the plain flat
// Needs Creating table.
const PRINT_PLATE_EXCLUDED_COLORS = [
    'Stars & Stripes (+$7)',
];

/** Least common multiple of two positive integers. */
function print_plate_lcm(int $a, int $b): int
{
    return intdiv($a * $b, print_plate_gcd($a, $b));
}

/** Greatest common divisor (Euclid's algorithm). */
function print_plate_gcd(int $a, int $b): int
{
    while ($b !== 0) {
        [$a, $b] = [$b, $a % $b];
    }
    return $a === 0 ? 1 : abs($a);
}

/**
 * Pack one group's queued units into plates.
 *
 * $capacities: item name => solo capacity (positive int).
 * $queues: item name => list of ['orderId'=>, 'customerName'=>,
 * 'qty'=>int] (FIFO - order the rows were queued in; this function
 * consumes from the front of each item's list as it fills plates, so
 * pass a fresh copy if the caller still needs the original).
 *
 * Returns a list of plates in fill order:
 *   [ ['items' => [item => ['qty'=>int, 'orders'=>[
 *         ['orderId'=>, 'customerName'=>, 'qty'=>int], ...
 *       ]]], 'fillFraction' => float (0..1, 1.0 = full) ], ... ]
 *
 * Every unit passed in ends up in exactly one plate's 'items' - full
 * plates first, with at most one trailing partial plate per group
 * (whatever didn't divide evenly). Greedy best-fit-decreasing: on
 * each plate, repeatedly adds the largest unit that still fits in
 * the remaining budget, so a multi-item group (like Circle/Oval)
 * fills plates as completely as the queue allows before starting a
 * new one - this is a planning aid, not a promise of the exact
 * physical arrangement Steve will actually lay out in Bambu Studio.
 */
function print_plate_pack_group(array $capacities, array $queues): array
{
    $lcm = 1;
    foreach ($capacities as $cap) {
        $lcm = print_plate_lcm($lcm, max(1, (int) $cap));
    }
    $unit = [];
    foreach ($capacities as $item => $cap) {
        $unit[$item] = intdiv($lcm, max(1, (int) $cap));
    }

    $availableQty = function (array $queue): int {
        $sum = 0;
        foreach ($queue as $o) {
            $sum += $o['qty'];
        }
        return $sum;
    };

    $plates = [];
    while (true) {
        $remainingTotal = 0;
        foreach ($queues as $q) {
            $remainingTotal += $availableQty($q);
        }
        if ($remainingTotal <= 0) {
            break;
        }

        $budget = $lcm;
        $plateItems = [];
        while (true) {
            $chosen = null;
            $chosenUnit = -1;
            foreach ($capacities as $item => $cap) {
                if ($availableQty($queues[$item] ?? []) <= 0) {
                    continue;
                }
                if ($unit[$item] <= $budget && $unit[$item] > $chosenUnit) {
                    $chosen = $item;
                    $chosenUnit = $unit[$item];
                }
            }
            if ($chosen === null) {
                break; // nothing left fits in the remaining budget
            }
            // Take exactly one unit of $chosen from the front of its queue.
            $front = &$queues[$chosen][0];
            $front['qty'] -= 1;
            if (!isset($plateItems[$chosen])) {
                $plateItems[$chosen] = ['qty' => 0, 'orders' => []];
            }
            $plateItems[$chosen]['qty'] += 1;
            $lastIdx = count($plateItems[$chosen]['orders']) - 1;
            if ($lastIdx >= 0 && $plateItems[$chosen]['orders'][$lastIdx]['orderId'] === $front['orderId']) {
                $plateItems[$chosen]['orders'][$lastIdx]['qty'] += 1;
            } else {
                $plateItems[$chosen]['orders'][] = [
                    'orderId' => $front['orderId'],
                    'customerName' => $front['customerName'],
                    'qty' => 1,
                ];
            }
            if ($front['qty'] <= 0) {
                array_shift($queues[$chosen]);
            }
            unset($front);
            $budget -= $chosenUnit;
        }
        if (empty($plateItems)) {
            break; // safety net - shouldn't happen given remainingTotal > 0
        }
        $plates[] = [
            'items' => $plateItems,
            'fillFraction' => ($lcm - $budget) / $lcm,
        ];
    }

    return $plates;
}

/**
 * Build the print-plate grouped view from a list of Needs-Creating
 * queue rows.
 *
 * $rows: array of ['item'=>string, 'color'=>string, 'qty'=>int,
 * 'orderId'=>string, 'customerName'=>string] - one entry per
 * not-yet-created order line, already filtered the same way
 * ourmerch.php's own "needs-creating" view filters rows (paid+Ship,
 * or any-payment-state Pickup; not cancelled). Only items in
 * FILAMENT_COLOR_ITEMS are considered here - shirts/hats and any
 * excluded color (see PRINT_PLATE_EXCLUDED_COLORS) are silently
 * skipped, since they don't belong in a print-plate batching view.
 * An item not listed in PRINT_PLATE_GROUPS is also skipped (nothing
 * to pack it against) - add it to a group above to include it.
 *
 * Returns an array of per-color groups, sorted by
 * PRINT_PLATE_COLOR_PRIORITY (unlisted colors last, alphabetically
 * among themselves):
 *   [
 *     'color' => string,
 *     'plateGroups' => [
 *         ['group' => label, 'plates' => [ see print_plate_pack_group() ]],
 *         ...
 *     ],
 *   ]
 *
 * Groups with nothing queued for that color are omitted. Read-only
 * "first cut" per Steve (2026-09-17): this only plans, it doesn't
 * check anything off.
 */
function print_plate_group_queue(array $rows): array
{
    // color => group label => item => list of ['orderId','customerName','qty']
    $byColorGroup = [];
    // item => which group it belongs to, for a fast lookup below.
    $itemToGroup = [];
    foreach (PRINT_PLATE_GROUPS as $groupLabel => $capacities) {
        foreach (array_keys($capacities) as $item) {
            $itemToGroup[$item] = $groupLabel;
        }
    }

    foreach ($rows as $row) {
        $item = $row['item'] ?? '';
        $color = $row['color'] ?? '';
        $qty = (int) ($row['qty'] ?? 1);
        if ($qty < 1) {
            continue;
        }
        if (!in_array($item, FILAMENT_COLOR_ITEMS, true)) {
            continue;
        }
        if (in_array($color, PRINT_PLATE_EXCLUDED_COLORS, true)) {
            continue;
        }
        $groupLabel = $itemToGroup[$item] ?? null;
        if ($groupLabel === null) {
            continue; // item isn't in any configured group - nothing to pack it against
        }
        $byColorGroup[$color][$groupLabel][$item][] = [
            'orderId' => $row['orderId'] ?? '',
            'customerName' => $row['customerName'] ?? '',
            'qty' => $qty,
        ];
    }

    $result = [];
    foreach ($byColorGroup as $color => $groups) {
        $plateGroups = [];
        foreach (PRINT_PLATE_GROUPS as $groupLabel => $capacities) {
            if (empty($groups[$groupLabel])) {
                continue;
            }
            $queues = [];
            foreach (array_keys($capacities) as $item) {
                $queues[$item] = $groups[$groupLabel][$item] ?? [];
            }
            $plates = print_plate_pack_group($capacities, $queues);
            if (!empty($plates)) {
                $plateGroups[] = ['group' => $groupLabel, 'plates' => $plates];
            }
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
