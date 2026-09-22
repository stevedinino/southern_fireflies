<?php
// Build: 2026-09-18-A
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
// codebase can derive that from geometry, so everything below is a
// small, hand-maintained record of what Steve already knows. Update
// it whenever a real layout changes or you discover a new combo; no
// other code needs to change when you do.
//
// ---- Build history (why this looks the way it does) -----------
// 2026-09-17, first pass: PRINT_PLATE_RECIPES - a flat list of named
// item=>qty recipes, matched for exact full multiples only. Two
// problems: (1) the display showed a "batch summary" on top of a
// separate full-totals list, and the two silently overlapped - Steve
// couldn't tell what was already counted. (2) It assumed Tape Gun
// Holder/Add-On had one fixed recipe, but Steve orders those a la
// carte in uneven ratios - no fixed pairing exists.
//
// 2026-09-17, second pass: threw out recipes entirely for a pure
// capacity model (item=>solo-capacity, with only Circle+Oval sharing
// a group) and a generic bin-packer. This fixed the display (every
// unit in exactly one plate line, full or partial) and the Tape Gun
// Holder/Add-On problem (no forced pairing) - but lost real
// information: Steve doesn't just know each item's OWN capacity, he
// also knows specific cross-item combos that fit together (a
// Rectangle + 3 Tape Gun Holders; a Hearts + a Rectangle; an Oval + 4
// Tape Gun Add-Ons) that a pure per-item capacity number can't
// predict - these plates aren't full because the areas add up to some
// formula, they're full because Steve has tried them and they fit. A
// proportional/footprint guess at mixing items (tried and rejected
// same day, before ever reaching Steve) is exactly the kind of guess
// that doesn't hold up: it reproduces solo capacities fine but has no
// way to know a Rectangle and 3 Tape Gun Holders happen to nest
// together, since that isn't computable from two capacity numbers.
//
// 2026-09-18, third pass: merged the two ideas instead of picking
// one. PRINT_PLATE_TEMPLATES is Steve's confirmed-combo list
// (including the "Circle / Oval combo" from the first pass) - tried
// first, in priority order, as exact whole-plate matches only (no
// partial combos - if the queue doesn't have enough of every item in
// a template, it just doesn't fire). Whatever's left over after every
// template has fired as many times as it can - i.e. whatever doesn't
// complete a known combo - falls back to that item's own
// PRINT_PLATE_SOLO_CAPACITY, chunked into full plates plus at most
// one trailing partial, so it still always shows up as its own plate
// line instead of vanishing into a leftover pile.
//
// 2026-09-18, fourth pass: added order-completion preference. Steve:
// "I lean toward completing orders so I can ship - if I have a choice
// of printing 3 things and only 2 fit, I'll print the two that complete
// an order every time. If they're all mixed and won't complete an
// order then it doesn't matter." This only matters when a plate (a
// combo instance or a solo-capacity chunk) has more candidate orders
// wanting that item than it can hold - which of them gets consumed onto
// the EARLIER plate vs. pushed to a later one. The first version of
// this picked, unit by unit, whichever order had fewest needs-creating
// pieces left shop-wide - a proxy for "would this finish the order,"
// recomputed after every single unit taken. See the next entry for
// where that proxy went wrong.
//
// 2026-09-20, fifth pass (current): fixed a real case of the above.
// Steve, looking at a live example - one order needing 2 of the same
// item/color, another needing 1, capacity 2 per plate: "I lean toward
// trying to finish orders if at all possible, but in this case the
// logic doesn't see that given three same-color items it could have
// grouped tools for the same person together." What happened: the old
// per-unit comparison took the smaller order's 1 unit first (fewer
// pieces left shop-wide OVERALL - not because taking that 1 unit
// specifically finished anything the other order's 2 units wouldn't
// have), then re-ran the same comparison for the second unit with the
// first order already gone from contention - fragmenting the 2-unit
// order across two plates even though both arrangements finish exactly
// one order after the first plate. print_plate_consume_units() now
// decides per PLATE-FILL CALL, not per unit: it only prefers an order
// when taking that order's FULL remaining request right now would
// actually zero out its shop-wide remaining count (a real completion,
// not just a smaller current count); among orders that are equally real
// completions (or equally not), it prefers the LARGER request first, so
// a multi-unit order gets consolidated onto one plate instead of being
// fragmented to make room for a smaller one - matching "it could have
// grouped tools for the same person together." An order's own request
// is still split across two plates when capacity genuinely forces it
// (its remaining qty is bigger than what's left on this plate), and the
// original arrival/FIFO order still breaks any remaining tie - matching
// "if it doesn't matter, don't touch the ordering."
//
// Requires merch_items.php's FILAMENT_COLOR_ITEMS to already be
// defined - require this file after pricing.php (same order
// merch_items.php's other consumers already use).
// ============================================================

// ---- Confirmed plate combos ------------------------------------
// Each entry: display label => [item name => qty needed], one whole
// plate's worth. List order is priority order: for each color, these
// are tried top to bottom, and each is matched as many whole times as
// the current queue allows before moving to the next template - so
// put your most space-efficient or most-common combos first. Ties
// over a shared item (e.g. Oval appears in both the Circle/Oval combo
// and the Oval + Tape Gun Add-On combo below) go to whichever
// template is listed first; reorder these two lines if you'd rather
// the Add-On combo get first claim on Oval stock.
//
// Confirmed by Steve (2026-09-17 combo; 2026-09-18 the other three).
const PRINT_PLATE_TEMPLATES = [
    'Circle / Oval combo' => [
        'Circle Cutter Holder' => 1,
        'Oval Cutter Holder' => 1,
    ],
    'Rectangle + Tape Gun Holder combo' => [
        'Rectangle Cutter Holder' => 1,
        'Tape Gun Holder' => 3,
    ],
    'Hearts + Rectangle combo' => [
        'Hearts Cutter Holder' => 1,
        'Rectangle Cutter Holder' => 1,
    ],
    'Oval + Tape Gun Add-On combo' => [
        'Oval Cutter Holder' => 1,
        'Tape Gun Add-On' => 4,
    ],
];

// ---- Solo capacities (fallback for anything a combo didn't use) --
// How many of JUST this item fit alone on one plate. Used for
// whatever's left in the queue after PRINT_PLATE_TEMPLATES above has
// been matched as far as it will go - so every item still needs an
// entry here even if you expect most of it to go through a combo
// instead, since real order mixes won't always cooperate.
//
// Confirmed by Steve: Circle/Oval/Rectangle/Tool Holder Stand cap at
// 2, Hearts caps at 3 (all 2026-09-17); Tape Gun Holder caps at 5,
// Tape Gun Add-On caps at 8 (2026-09-17, "ordered a la carte... odd
// number combos"); the 2026-09-18 message re-confirmed Rectangle,
// Tool Holder Stand, Circle, and Oval all cap at 2 and Hearts at 3.
const PRINT_PLATE_SOLO_CAPACITY = [
    'Circle Cutter Holder' => 2,
    'Oval Cutter Holder' => 2,
    'Rectangle Cutter Holder' => 2,
    'Hearts Cutter Holder' => 3,
    'Tool Holder Stand' => 2,
    'Tape Gun Holder' => 5,
    'Tape Gun Add-On' => 8,
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

/** Total queued quantity remaining across a FIFO order queue. */
function print_plate_available(array $queue): int
{
    $sum = 0;
    foreach ($queue as $o) {
        $sum += $o['qty'];
    }
    return $sum;
}

/**
 * Pull exactly $n units off a per-item order queue (mutates it),
 * returning the per-order breakdown consumed - e.g. taking 3 units
 * that happen to span two orders returns two entries. Caller must
 * ensure $n <= print_plate_available($queue) first.
 *
 * $orderRemaining: orderId => total needs-creating pieces left for
 * that order SHOP-WIDE (every item/color, not just this print-plate
 * view) - mutated here too, decremented by whatever's taken, so a
 * later call (same order, maybe a different item entirely) sees the
 * up-to-date count.
 *
 * Selection order (2026-09-20, per Steve - see the build-history
 * comment above for the case that prompted this): for each unit still
 * needed this call, prefer drawing from whichever queue entry, in
 * order -
 *   1) would have ITS OWN FULL remaining qty zero out that order's
 *      shop-wide remaining count if taken right now (a real
 *      completion - not just "currently has fewer pieces left," which
 *      doesn't actually mean taking it finishes anything);
 *   2) failing a tie there, has the LARGER remaining qty - so a
 *      multi-unit order gets consolidated onto this plate instead of
 *      fragmenting to make room for a smaller one;
 *   3) failing a tie there too, whichever is earliest in the queue
 *      (arrival/FIFO order) - unchanged from every earlier build.
 * Entries missing from $orderRemaining (shouldn't normally happen -
 * it's built from the same rows) are treated as never a real
 * completion, so they fall to rule 2/3 same as any tie.
 */
function print_plate_consume_units(array &$queue, int $n, array &$orderRemaining): array
{
    $consumed = [];
    while ($n > 0 && !empty($queue)) {
        $bestIdx = 0;
        $bestKey = null;
        foreach ($queue as $idx => $entry) {
            $remaining = $orderRemaining[$entry['orderId']] ?? null;
            $wouldComplete = $remaining !== null
                && $entry['qty'] <= $n
                && ($remaining - $entry['qty']) <= 0;
            // Sort key, lowest wins: completions (0) before non-
            // completions (1); within that, larger qty first (negated,
            // so a plain ascending comparison still picks it); $idx
            // last, as an explicit FIFO tie-break (PHP's foreach order
            // already matches queue order, but spelling it out here
            // means this doesn't depend on array comparison stopping
            // at the first differing element by luck).
            $key = [$wouldComplete ? 0 : 1, -$entry['qty'], $idx];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $bestIdx = $idx;
            }
        }
        $orderId = $queue[$bestIdx]['orderId'];
        $customerName = $queue[$bestIdx]['customerName'];
        $take = min($n, $queue[$bestIdx]['qty']);

        $queue[$bestIdx]['qty'] -= $take;
        $n -= $take;
        if (isset($orderRemaining[$orderId])) {
            $orderRemaining[$orderId] -= $take;
        }
        if ($queue[$bestIdx]['qty'] <= 0) {
            array_splice($queue, $bestIdx, 1);
        }

        $lastIdx = count($consumed) - 1;
        if ($lastIdx >= 0 && $consumed[$lastIdx]['orderId'] === $orderId) {
            $consumed[$lastIdx]['qty'] += $take;
        } else {
            $consumed[] = ['orderId' => $orderId, 'customerName' => $customerName, 'qty' => $take];
        }
    }
    return $consumed;
}

/**
 * Build the print-plate grouped view from a list of Needs-Creating
 * queue rows.
 *
 * $rows: array of ['item'=>string, 'color'=>string, 'qty'=>int,
 * 'orderId'=>string, 'customerName'=>string] - one entry per
 * not-yet-created order line, already filtered the same way
 * ourmerch.php's own "needs-creating" view filters rows (paid+Ship,
 * or any-payment-state Pickup; not cancelled). Pass EVERY
 * needs-creating row here, including shirts/hats and any excluded
 * color - they're filtered out below for the plate grouping itself,
 * but they still count toward whether an order is "done" for the
 * order-completion preference (see print_plate_consume_units()).
 *
 * Only items in FILAMENT_COLOR_ITEMS with a PRINT_PLATE_SOLO_CAPACITY
 * entry, in a non-excluded color, actually get grouped into plates.
 *
 * Returns an array of per-color groups, sorted by
 * PRINT_PLATE_COLOR_PRIORITY (unlisted colors last, alphabetically
 * among themselves):
 *   [
 *     'color' => string,
 *     'plateGroups' => [
 *         ['group' => label, 'plates' => [
 *             ['items' => [item => ['qty'=>int, 'orders'=>[
 *                 ['orderId'=>, 'customerName'=>, 'qty'=>int], ...
 *               ]]], 'fillFraction' => float (0..1, 1.0 = full)],
 *             ...
 *         ]],
 *         ...
 *     ],
 *   ]
 *
 * 'group' is either a PRINT_PLATE_TEMPLATES label (every plate under
 * it is a full, confirmed combo - fillFraction always 1.0) or a plain
 * item name (solo fallback plates for whatever didn't complete a
 * combo - full or trailing-partial). Every grouped unit appears in
 * exactly one plate, in exactly one group, never split across two
 * plate lines and never left out of the result entirely. Read-only
 * "first cut" per Steve (2026-09-17): this only plans, it doesn't
 * check anything off.
 */
function print_plate_group_queue(array $rows): array
{
    // orderId => total needs-creating pieces left, shop-wide (every
    // item/color) - built from the FULL $rows before any filtering,
    // so a still-outstanding shirt correctly keeps an order from
    // looking "almost done" here. See print_plate_consume_units().
    $orderRemaining = [];
    foreach ($rows as $row) {
        $qty = (int) ($row['qty'] ?? 1);
        if ($qty < 1) {
            continue;
        }
        $orderId = $row['orderId'] ?? '';
        $orderRemaining[$orderId] = ($orderRemaining[$orderId] ?? 0) + $qty;
    }

    // color => item => queue of ['orderId','customerName','qty']
    // (order here is arrival/FIFO order - the tie-break of last
    // resort once order-completion is accounted for).
    $byColorItem = [];
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
        if (!array_key_exists($item, PRINT_PLATE_SOLO_CAPACITY)) {
            continue; // nothing configured to pack this item against
        }
        if (in_array($color, PRINT_PLATE_EXCLUDED_COLORS, true)) {
            continue;
        }
        $byColorItem[$color][$item][] = [
            'orderId' => $row['orderId'] ?? '',
            'customerName' => $row['customerName'] ?? '',
            'qty' => $qty,
        ];
    }

    $result = [];
    foreach ($byColorItem as $color => $queues) {
        // $orderRemaining is shared across every color's packing (not
        // just this one) since orderIds are unique shop-wide - an
        // order that happens to span two colors still counts a piece
        // finished in one color toward completing it. Colors are
        // processed here in arrival order, not final display-priority
        // order; this only matters for the rare order spanning two
        // colors, and either way every unit still ends up on some
        // plate - it's a tie-break nicety, not a correctness issue.
        $platesByLabel = [];

        // Pass 1: confirmed combos, in priority order, exact whole
        // matches only.
        foreach (PRINT_PLATE_TEMPLATES as $label => $template) {
            while (true) {
                $canMake = true;
                foreach ($template as $item => $needed) {
                    if (print_plate_available($queues[$item] ?? []) < $needed) {
                        $canMake = false;
                        break;
                    }
                }
                if (!$canMake) {
                    break;
                }
                $items = [];
                foreach ($template as $item => $needed) {
                    $items[$item] = [
                        'qty' => $needed,
                        'orders' => print_plate_consume_units($queues[$item], $needed, $orderRemaining),
                    ];
                }
                $platesByLabel[$label][] = ['items' => $items, 'fillFraction' => 1.0];
            }
        }

        // Pass 2: whatever's left per item falls back to its own solo
        // capacity - full plates plus at most one trailing partial.
        foreach (PRINT_PLATE_SOLO_CAPACITY as $item => $cap) {
            $remaining = print_plate_available($queues[$item] ?? []);
            while ($remaining > 0) {
                $take = min($remaining, $cap);
                $platesByLabel[$item][] = [
                    'items' => [$item => [
                        'qty' => $take,
                        'orders' => print_plate_consume_units($queues[$item], $take, $orderRemaining),
                    ]],
                    'fillFraction' => $take / $cap,
                ];
                $remaining -= $take;
            }
        }

        // Assemble in a stable order: combo templates first (their
        // own priority order), then solo fallback items (declaration
        // order), skipping any label that produced nothing.
        $plateGroups = [];
        foreach (PRINT_PLATE_TEMPLATES as $label => $template) {
            if (!empty($platesByLabel[$label])) {
                $plateGroups[] = ['group' => $label, 'plates' => $platesByLabel[$label]];
            }
        }
        foreach (PRINT_PLATE_SOLO_CAPACITY as $item => $cap) {
            if (!empty($platesByLabel[$item])) {
                $plateGroups[] = ['group' => $item, 'plates' => $platesByLabel[$item]];
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
