<?php
// Build: 2026-09-17-A
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
// codebase can derive that from geometry, so PRINT_PLATE_RECIPES
// below is a small, hand-maintained list of the plate layouts Steve
// already uses. Update it whenever a real layout changes; no other
// code needs to change when you do.
//
// Requires merch_items.php's FILAMENT_COLOR_ITEMS to already be
// defined - require this file after pricing.php (same order
// merch_items.php's other consumers already use).
// ============================================================

// ---- Plate recipes -------------------------------------------
// Each entry: display label => [item name => qty needed to fill that
// plate]. List order is priority/matching order - put your most-used
// or most-valuable combos first, since a color's queue is matched
// against these in order and ties go to whichever comes first.
//
// Confirmed by Steve 2026-09-17 (replacing the earlier screenshot
// guesses same day) - real plate counts from Bambu Studio.
const PRINT_PLATE_RECIPES = [
    'Circle / Oval combo' => [
        'Circle Cutter Holder' => 1,
        'Oval Cutter Holder' => 1,
    ],
    'Oval Cutter Holder (solo)' => [
        'Oval Cutter Holder' => 2,
    ],
    'Rectangle Cutter Holder (solo)' => [
        'Rectangle Cutter Holder' => 2,
    ],
    'Tape Gun Holder (solo)' => [
        'Tape Gun Holder' => 3,
    ],
    'Tool Holder Stand (solo)' => [
        'Tool Holder Stand' => 2,
    ],
    'Hearts Cutter Holder (solo)' => [
        'Hearts Cutter Holder' => 3,
    ],
    'Circle Cutter Holder (solo)' => [
        'Circle Cutter Holder' => 2,
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
 * excluded color (see above) are silently skipped, since they don't
 * belong in a print-plate batching view.
 *
 * Returns an array of per-color groups, sorted by
 * PRINT_PLATE_COLOR_PRIORITY (unlisted colors last, alphabetically
 * among themselves):
 *   [
 *     'color' => string,
 *     'batches' => [
 *         ['recipe' => label, 'plates' => int, 'items' => [item=>qty consumed]],
 *         ...
 *     ],
 *     'items' => [
 *         item => ['qty' => int, 'orders' => [ the original row, ... ]],
 *         ...
 *     ],
 *   ]
 *
 * 'batches' is a planning summary layered on top - it does NOT
 * remove anything from 'items'. 'items' is still the full, complete
 * list of what's actually in the queue for that color (same shape of
 * information packing_slips.php's existing "By Color" section
 * already shows), so Steve can always see every order line
 * regardless of whether it happened to complete a recipe. This is
 * the read-only "first cut" per Steve (2026-09-17): no attempt is
 * made to attribute which specific order's units went into which
 * batch - that level of bookkeeping isn't needed for a planning view,
 * and keeps this simple to build and to trust.
 */
function print_plate_group_queue(array $rows): array
{
    $byColor = []; // color => item => ['qty'=>int, 'orders'=>[...]]
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
        if (!isset($byColor[$color][$item])) {
            $byColor[$color][$item] = ['qty' => 0, 'orders' => []];
        }
        $byColor[$color][$item]['qty'] += $qty;
        $byColor[$color][$item]['orders'][] = $row;
    }

    $result = [];
    foreach ($byColor as $color => $itemPools) {
        $available = [];
        foreach ($itemPools as $item => $pool) {
            $available[$item] = $pool['qty'];
        }

        $batches = [];
        foreach (PRINT_PLATE_RECIPES as $label => $recipe) {
            $platesPossible = null;
            foreach ($recipe as $item => $needed) {
                $have = $available[$item] ?? 0;
                $canMake = $needed > 0 ? intdiv($have, $needed) : 0;
                $platesPossible = ($platesPossible === null) ? $canMake : min($platesPossible, $canMake);
            }
            if ($platesPossible === null || $platesPossible < 1) {
                continue;
            }
            $consumed = [];
            foreach ($recipe as $item => $needed) {
                $available[$item] -= $needed * $platesPossible;
                $consumed[$item] = $needed * $platesPossible;
            }
            $batches[] = [
                'recipe' => $label,
                'plates' => $platesPossible,
                'items' => $consumed,
            ];
        }

        $result[$color] = [
            'color' => $color,
            'batches' => $batches,
            'items' => $itemPools,
        ];
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
