<?php
// Build: 2026-10-03-A
// ============================================================
// Printed-stock matching + print-next planning logic, behind
// merch_stock_report.php (admin report) and merch_stock_upload.php
// (replaces inventory.csv). Pure functions - no CSV/session/HTTP in this
// file except merch_stock_load_inventory() - so tests/test_merch_stock.php
// can call everything directly with small hand-built row sets, same
// approach as print_plates.php / tests/test_print_plates.php.
//
// Why this exists (Steve, 2026-10-02): Blade Holder volume put him far
// behind, so he now prints ahead in his top colors instead of printing
// per order, and ends up with a pile of finished parts plus a stack of
// open orders and no way to answer "what could I ship from what I have?"
// or "what should I print next?". This is the evolution of the old
// "untracked shelf-stock" idea (see the TODO doc) - stock is now the
// main buffer, not just leftovers.
//
// ---- Business rules baked in (all stated by Steve, 2026-10-02) ------
//   1. NEVER a partial shipment. A shipment is the same Name+Zip group
//      Needs Shipping / packing_slips.php / shippo_export.php already use
//      (merch_shipment_key() in merch_shipments.php), and it is "ready"
//      only when every line in it is in hand. Stock is therefore
//      allocated per whole shipment, not per piece.
//   2. PAID FIRST. Only rows the Needs Creating view would show are
//      considered: Ship rows must be paid, Pickup rows any payment state
//      (same test as ourmerch.php), never cancelled, never fulfilled.
//      Unpaid pieces are counted (so the report can say how many are
//      being ignored) but never allocated stock or planned for printing.
//   3. NO COLOR SUBSTITUTION. Stock only ever matches the exact item +
//      color a line asked for. There is no "close enough" matching, and
//      the inventory sheet's colors are resolved to the site's own color
//      list strictly (see merch_stock_resolve_color()) - an unrecognized
//      color is reported, never guessed.
//
// Everything here is READ-ONLY with respect to merchandise.csv. Pulling
// stock for an order is still done by hand (Qty Created checkboxes /
// editing inventory.csv) - see the TODO doc for the later "decrement on
// pull" idea.
// ============================================================

/**
 * Extra spellings of item names Steve uses in his inventory sheet
 * (normalized: lowercase letters+digits only) => canonical item name.
 * An alias is only honored if its target is in the valid-items list the
 * caller passes in, so renaming an item in /items/ can't leave this
 * pointing at something that no longer exists.
 */
const MERCH_STOCK_ITEM_ALIASES = [
    'circles' => 'Circle Cutter Holder',
    'circle' => 'Circle Cutter Holder',
    'ovals' => 'Oval Cutter Holder',
    'oval' => 'Oval Cutter Holder',
    'hearts' => 'Hearts Cutter Holder',
    'heart' => 'Hearts Cutter Holder',
    'rectangles' => 'Rectangle Cutter Holder',
    'rectangle' => 'Rectangle Cutter Holder',
    'toolstandholder' => 'Tool Holder Stand',
    'toolstand' => 'Tool Holder Stand',
    'toolholder' => 'Tool Holder Stand',
    'tapegunaddon' => 'Tape Gun Add-On',
    'tapegunaddons' => 'Tape Gun Add-On',
    'tapegunholders' => 'Tape Gun Holder',
    'bladeholders' => 'Blade Holder',
];

/** Lowercase letters+digits only - the comparison form for names. */
function merch_stock_norm(string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}

/** normalized name => canonical item name, for every valid item + alias. */
function merch_stock_item_index(array $validItems): array
{
    $idx = [];
    foreach ($validItems as $item) {
        $idx[merch_stock_norm($item)] = $item;
    }
    foreach (MERCH_STOCK_ITEM_ALIASES as $alias => $item) {
        if (in_array($item, $validItems, true)) {
            $idx[$alias] = $item;
        }
    }
    return $idx;
}

/**
 * Splits a site color like '#15 CM Blue' / 'Rainbow (+$2)' into
 * [number|null, name, nameWithoutTrailingParenthetical].
 */
function merch_stock_split_color(string $color): array
{
    $num = null;
    $name = trim($color);
    if (preg_match('/^#\s*0*(\d+)\s*(.*)$/', $name, $m)) {
        $num = (int) $m[1];
        $name = trim($m[2]);
    }
    $base = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $name));
    return [$num, $name, $base];
}

/**
 * Index of the site's color list for strict lookups:
 *   byNorm   - normalized full string => color
 *   byNumber - color number => color
 *   byName   - normalized name (no number, no "(+$2)" tail) => color
 */
function merch_stock_color_index(array $validColors): array
{
    $byNorm = [];
    $byNumber = [];
    $byName = [];
    foreach ($validColors as $color) {
        [$num, $name, $base] = merch_stock_split_color($color);
        $byNorm[merch_stock_norm($color)] = $color;
        if ($num !== null) {
            $byNumber[$num] = $color;
        }
        $byName[merch_stock_norm($base)] = $color;
    }
    return ['byNorm' => $byNorm, 'byNumber' => $byNumber, 'byName' => $byName];
}

/**
 * Resolve a color as typed in the inventory sheet to the site's exact
 * color string, STRICTLY (rule 3: no guessing). Accepts the exact value,
 * an unpadded number ('#9 Magenta'), or a bare name ('Rainbow'). A number
 * whose name disagrees with the site list ('#9 Hot Pink') is refused, not
 * "fixed", since either the number or the name is wrong and only Steve
 * knows which.
 *
 * Returns [color|null, warning|null].
 */
function merch_stock_resolve_color(string $raw, array $idx): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [null, null];
    }
    $n = merch_stock_norm($raw);
    if (isset($idx['byNorm'][$n])) {
        return [$idx['byNorm'][$n], null];
    }
    if (preg_match('/^#\s*0*(\d+)\s*(.*)$/', $raw, $m)) {
        $num = (int) $m[1];
        $rest = trim($m[2]);
        if (!isset($idx['byNumber'][$num])) {
            return [null, "Color '{$raw}' - there is no color #{$num} on the site's color list."];
        }
        $color = $idx['byNumber'][$num];
        [, $name, $base] = merch_stock_split_color($color);
        if ($rest === '' || merch_stock_norm($rest) === merch_stock_norm($name) || merch_stock_norm($rest) === merch_stock_norm($base)) {
            return [$color, null];
        }
        return [null, "Color '{$raw}' - number and name disagree (the site list has '{$color}')."];
    }
    if (isset($idx['byName'][$n])) {
        return [$idx['byName'][$n], null];
    }
    return [null, "Color '{$raw}' is not on the site's color list."];
}

/**
 * Which way is the inventory grid laid out? Looks at the first row and the
 * first column (ignoring the corner cell) and counts how many labels are
 * real part names vs real site colors in each place:
 *   'parts-down'  - parts down the first column, colors across the top
 *   'colors-down' - colors down the first column, parts across the top
 * Ties (including a file that recognizes nothing) are 'parts-down', the
 * original layout, so a non-inventory file fails exactly as before.
 * 2026-10-03 (Steve): 27 colors made 27 columns hard to manage; he wants
 * 27 rows x 8 part columns, so either layout must work.
 */
function merch_stock_detect_orientation(array $rows, array $validItems, array $validColors): string
{
    $itemIdx = merch_stock_item_index($validItems);
    $colorIdx = merch_stock_color_index($validColors);

    $labelKind = function (string $label) use ($itemIdx, $colorIdx): string {
        $label = trim($label);
        if ($label === '' || strtolower($label) === 'total') {
            return '';
        }
        if (isset($itemIdx[merch_stock_norm($label)])) {
            return 'item';
        }
        if (merch_stock_resolve_color($label, $colorIdx)[0] !== null) {
            return 'color';
        }
        return '';
    };

    $rows = array_values(array_filter($rows, fn($r) => is_array($r) && count(array_filter($r, fn($c) => trim((string) $c) !== '')) > 0));
    if (empty($rows)) {
        return 'parts-down';
    }
    $top = ['item' => 0, 'color' => 0];
    foreach (array_slice($rows[0], 1) as $label) {
        $k = $labelKind((string) $label);
        if ($k !== '') {
            $top[$k]++;
        }
    }
    $side = ['item' => 0, 'color' => 0];
    foreach (array_slice($rows, 1) as $r) {
        $k = $labelKind((string) ($r[0] ?? ''));
        if ($k !== '') {
            $side[$k]++;
        }
    }
    $partsDownScore = $side['item'] + $top['color'];
    $colorsDownScore = $top['item'] + $side['color'];
    return $colorsDownScore > $partsDownScore ? 'colors-down' : 'parts-down';
}

/** Flip a ragged row set so rows become columns (short rows padded blank). */
function merch_stock_transpose(array $rows): array
{
    $width = 0;
    foreach ($rows as $r) {
        $width = max($width, is_array($r) ? count($r) : 0);
    }
    $out = [];
    for ($c = 0; $c < $width; $c++) {
        $line = [];
        foreach ($rows as $r) {
            $line[] = is_array($r) ? (string) ($r[$c] ?? '') : '';
        }
        $out[] = $line;
    }
    return $out;
}

/**
 * Parse Steve's inventory sheet exported as CSV: a grid with parts down
 * the first column and colors across the first row, e.g.
 *     Part,#15 CM Blue,#17 Teal,...,Total
 *     Blade Holder,5,1,...,13
 * OR the flipped layout (colors down the first column, parts across the
 * first row) - detected automatically, see merch_stock_detect_orientation().
 * (Excel's own Total row/column, blank columns and blank rows are
 * ignored.) $rows is an already-fgetcsv'd list of rows.
 *
 * Returns:
 *   'stock'     item => color => qty (only entries > 0)
 *   'unmatched' list of ['part'=>, 'color'=>, 'qty'=>, 'reason'=>] for
 *               pieces that could NOT be matched to a real item+color -
 *               reported, never silently dropped or guessed at
 *   'warnings'  other problems (non-numeric cells, unknown part names)
 *   'columnsRecognized' / 'rowsRecognized' - sanity counts, used by the
 *               upload endpoint to refuse a file that isn't an inventory
 *               grid at all. (Counted AFTER any flip: "columns" are always
 *               colors and "rows" always parts.)
 *   'orientation' 'parts-down' | 'colors-down' - which layout the file used
 */
function merch_stock_parse_grid(array $rows, array $validItems, array $validColors): array
{
    $out = ['stock' => [], 'unmatched' => [], 'warnings' => [], 'columnsRecognized' => 0, 'rowsRecognized' => 0, 'orientation' => 'parts-down'];
    $itemIdx = merch_stock_item_index($validItems);
    $colorIdx = merch_stock_color_index($validColors);

    // Normalize to parts-down/colors-across, then everything below is unchanged.
    if (merch_stock_detect_orientation($rows, $validItems, $validColors) === 'colors-down') {
        $out['orientation'] = 'colors-down';
        $rows = merch_stock_transpose($rows);
    }

    // First non-empty row is the header.
    $header = null;
    $dataRows = [];
    foreach ($rows as $row) {
        if (!is_array($row) || count(array_filter($row, fn($c) => trim((string) $c) !== '')) === 0) {
            continue;
        }
        if ($header === null) {
            $header = $row;
            continue;
        }
        $dataRows[] = $row;
    }
    if ($header === null) {
        $out['warnings'][] = 'The inventory file is empty.';
        return $out;
    }
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
    }

    // Resolve each header column to a color (or note it as unmatched).
    $colorByIndex = []; // idx => color|null
    $labelByIndex = [];
    foreach ($header as $i => $label) {
        $label = trim((string) $label);
        if ($i === 0 || $label === '' || strtolower($label) === 'total') {
            continue;
        }
        $labelByIndex[$i] = $label;
        [$color, $warn] = merch_stock_resolve_color($label, $colorIdx);
        $colorByIndex[$i] = $color;
        if ($color !== null) {
            $out['columnsRecognized']++;
        }
    }

    foreach ($dataRows as $row) {
        $part = trim((string) ($row[0] ?? ''));
        if ($part === '' || strtolower($part) === 'total') {
            continue;
        }
        $item = $itemIdx[merch_stock_norm($part)] ?? null;
        $rowHadQty = false;
        foreach ($labelByIndex as $i => $label) {
            $cell = trim((string) ($row[$i] ?? ''));
            if ($cell === '') {
                continue;
            }
            if (!is_numeric($cell) || (float) $cell < 0 || (float) $cell != floor((float) $cell)) {
                $out['warnings'][] = "'{$part}' / '{$label}': '{$cell}' is not a whole number - ignored.";
                continue;
            }
            $qty = (int) $cell;
            if ($qty === 0) {
                continue;
            }
            $rowHadQty = true;
            $color = $colorByIndex[$i] ?? null;
            if ($item === null) {
                $out['unmatched'][] = ['part' => $part, 'color' => $label, 'qty' => $qty, 'reason' => "unknown part '{$part}'"];
                continue;
            }
            if ($color === null) {
                [, $why] = merch_stock_resolve_color($label, $colorIdx);
                $out['unmatched'][] = ['part' => $item, 'color' => $label, 'qty' => $qty, 'reason' => $why ?? 'unrecognized color'];
                continue;
            }
            $out['stock'][$item][$color] = ($out['stock'][$item][$color] ?? 0) + $qty;
        }
        if ($item !== null) {
            $out['rowsRecognized']++;
        } elseif (!$rowHadQty) {
            $out['warnings'][] = "Part '{$part}' is not a known item - ignored (it had no quantities).";
        }
    }
    return $out;
}

/** Total pieces in a stock map (item => color => qty). */
function merch_stock_total(array $stock): int
{
    $t = 0;
    foreach ($stock as $byColor) {
        $t += array_sum($byColor);
    }
    return $t;
}

/**
 * Read inventory.csv under a shared lock (an upload may be mid-write).
 * Missing file is not an error - it just means no stock recorded yet.
 * Returns ['rows' => list-of-rows, 'exists' => bool, 'mtime' => int|null].
 */
function merch_stock_load_inventory(string $path): array
{
    if (!is_file($path)) {
        return ['rows' => [], 'exists' => false, 'mtime' => null];
    }
    $h = fopen($path, 'r');
    if (!$h) {
        return ['rows' => [], 'exists' => false, 'mtime' => null];
    }
    flock($h, LOCK_SH);
    $rows = [];
    while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
        $rows[] = $r;
    }
    flock($h, LOCK_UN);
    fclose($h);
    return ['rows' => $rows, 'exists' => true, 'mtime' => filemtime($path) ?: null];
}

/**
 * Turn merchandise.csv rows into per-shipment open-work records, using
 * exactly the Needs Creating eligibility test from ourmerch.php (rule 2).
 *
 * $col maps column name => index|false for: OrderID, Name, Zip, Item,
 * Quantity, Color, Fulfillment, Pymt Date, Created, Fulfilled, Cancelled,
 * Qty Created (any may be false - same optional-column tolerance as the
 * rest of the codebase). $validItems = FILAMENT_COLOR_ITEMS (printed
 * pieces); anything else (shirts/hats) can't be printed or stocked, so it
 * is carried as an "other" line that blocks its shipment from being
 * ready. $excludedColors = PRINT_PLATE_EXCLUDED_COLORS (Stars & Stripes:
 * can't be stocked or batched, so a shipment needing one is "special").
 *
 * Returns:
 *   'shipments'    list sorted by oldest OrderID; each:
 *       key, printKey, type ('ship'|'pickup'), name, orderIds[],
 *       minOrderId, lines[ [orderId,item,color,qty] ], other[ same ],
 *       special (bool), unpaidSiblingLines (int)
 *   'unpaidPieces' / 'unpaidShipments' - printable pieces on Ship rows
 *       that were skipped for not being paid yet
 *   'awaitingShip' - shipments whose every piece is already Created but
 *       not yet Fulfilled (printed, just not shipped)
 */
function merch_stock_build_shipments(array $rows, array $col, array $validItems, array $excludedColors = []): array
{
    $get = function (array $row, string $name) use ($col): string {
        $i = $col[$name] ?? false;
        return $i === false ? '' : trim((string) ($row[$i] ?? ''));
    };

    $ships = [];
    $unpaidByKey = []; // key => printable pieces not counted
    $unpaidPieces = 0;
    $awaitingKeys = [];

    foreach ($rows as $row) {
        if ($get($row, 'Cancelled') !== '' || $get($row, 'Fulfilled') !== '') {
            continue;
        }
        $isShip = $get($row, 'Fulfillment') === 'Ship';
        $paid = $get($row, 'Pymt Date') !== '';
        $item = $get($row, 'Item');
        $color = $get($row, 'Color');
        $orderId = $get($row, 'OrderID');
        $qty = (int) $get($row, 'Quantity');
        if ($qty < 1) {
            $qty = 1;
        }
        $isCreated = $get($row, 'Created') !== '';
        $qtyCreated = (int) $get($row, 'Qty Created');
        $remaining = $isCreated ? 0 : max(0, $qty - $qtyCreated);

        $name = $get($row, 'Name');
        $zip = $get($row, 'Zip');
        $baseKey = $name !== '' ? merch_shipment_key($name, $zip) : '';
        $key = ($isShip ? 'ship:' : 'pickup:') . ($baseKey !== '' ? $baseKey : '#' . $orderId);

        if ($isShip && !$paid) {
            if ($remaining > 0 && in_array($item, $validItems, true)) {
                $unpaidPieces += $remaining;
                $unpaidByKey[$key] = true;
            }
            continue;
        }

        if (!isset($ships[$key])) {
            $ships[$key] = [
                'key' => $key,
                'printKey' => $baseKey !== '' ? $key : '',
                'type' => $isShip ? 'ship' : 'pickup',
                'name' => $name,
                'orderIds' => [],
                'minOrderId' => PHP_INT_MAX,
                'lines' => [],
                'other' => [],
                'special' => false,
                'unpaidSiblingLines' => 0,
            ];
        }
        $ships[$key]['orderIds'][] = $orderId;
        if (ctype_digit($orderId)) {
            $ships[$key]['minOrderId'] = min($ships[$key]['minOrderId'], (int) $orderId);
        }

        if ($remaining === 0) {
            continue; // already printed - counts as done, nothing to plan
        }
        $line = ['orderId' => $orderId, 'item' => $item, 'color' => $color, 'qty' => $remaining];
        if (in_array($item, $validItems, true)) {
            $ships[$key]['lines'][] = $line;
            if (in_array($color, $excludedColors, true)) {
                $ships[$key]['special'] = true;
            }
        } else {
            $ships[$key]['other'][] = $line;
        }
    }

    $awaitingShip = 0;
    $out = [];
    foreach ($ships as $key => $s) {
        if (empty($s['lines']) && empty($s['other'])) {
            $awaitingShip++;
            continue;
        }
        $s['unpaidSiblingLines'] = isset($unpaidByKey[$key]) ? 1 : 0;
        $out[] = $s;
    }
    usort($out, fn($a, $b) => [$a['minOrderId'], $a['key']] <=> [$b['minOrderId'], $b['key']]);

    return [
        'shipments' => $out,
        'unpaidPieces' => $unpaidPieces,
        'unpaidShipments' => count($unpaidByKey),
        'awaitingShip' => $awaitingShip,
    ];
}

/** item => color => qty needed across a shipment's lines. */
function merch_stock_shipment_needs(array $shipment): array
{
    $needs = [];
    foreach ($shipment['lines'] as $l) {
        $needs[$l['item']][$l['color']] = ($needs[$l['item']][$l['color']] ?? 0) + $l['qty'];
    }
    return $needs;
}

/**
 * Allocate stock to open shipments.
 *
 * Pass A - READY: walk shipments oldest-first; a shipment that is not
 * waiting on a shirt/hat and whose EVERY printed line can be covered from
 * what's still on the shelf takes that stock and goes on the ready list
 * (rule 1: whole shipments only). A shipment that can't be completed is
 * skipped, not allowed to hold up newer ones behind it.
 *
 * Pass B - RESERVE: whatever stock remains is handed to the shipments
 * that can't complete yet, closest-to-done first, so the "still to print"
 * list reflects pieces genuinely missing. (Stock is never moved between
 * item/colors - rule 3.) Shipments waiting on shirts/hats go last.
 *
 * Returns the shipments split into 'ready' / 'partial' / 'blocked' (each
 * line annotated with 'covered' and 'missing'), plus 'stockLeft' (stock no
 * remaining paid demand wants - the "stranded" pieces).
 */
function merch_stock_allocate(array $shipments, array $stock): array
{
    $rem = $stock;
    $ready = [];
    $pending = [];
    $blocked = [];

    foreach ($shipments as $s) {
        if (empty($s['lines'])) {
            $blocked[] = $s; // only shirts/hats outstanding - nothing to stock
            continue;
        }
        $canComplete = empty($s['other']);
        if ($canComplete) {
            foreach (merch_stock_shipment_needs($s) as $item => $byColor) {
                foreach ($byColor as $color => $need) {
                    if (($rem[$item][$color] ?? 0) < $need) {
                        $canComplete = false;
                        break 2;
                    }
                }
            }
        }
        if ($canComplete) {
            foreach ($s['lines'] as $i => $l) {
                $s['lines'][$i]['covered'] = $l['qty'];
                $s['lines'][$i]['missing'] = 0;
                $rem[$l['item']][$l['color']] -= $l['qty'];
            }
            $s['coveredUnits'] = array_sum(array_column($s['lines'], 'qty'));
            $s['missingUnits'] = 0;
            $ready[] = $s;
        } elseif (!empty($s['other'])) {
            $blocked[] = $s;
        } else {
            $pending[] = $s;
        }
    }

    // Closest-to-done first, judged against what's left after pass A.
    $short = function (array $s) use ($rem): int {
        $m = 0;
        foreach (merch_stock_shipment_needs($s) as $item => $byColor) {
            foreach ($byColor as $color => $need) {
                $m += max(0, $need - ($rem[$item][$color] ?? 0));
            }
        }
        return $m;
    };
    usort($pending, fn($a, $b) => [$short($a), $a['minOrderId']] <=> [$short($b), $b['minOrderId']]);

    $reserve = function (array $s) use (&$rem): array {
        $covered = 0;
        $missing = 0;
        foreach ($s['lines'] as $i => $l) {
            $take = min($rem[$l['item']][$l['color']] ?? 0, $l['qty']);
            if ($take > 0) {
                $rem[$l['item']][$l['color']] -= $take;
            }
            $s['lines'][$i]['covered'] = $take;
            $s['lines'][$i]['missing'] = $l['qty'] - $take;
            $covered += $take;
            $missing += $l['qty'] - $take;
        }
        $s['coveredUnits'] = $covered;
        $s['missingUnits'] = $missing;
        return $s;
    };

    $partial = array_map($reserve, $pending);
    $blocked = array_map($reserve, $blocked);

    $left = [];
    foreach ($rem as $item => $byColor) {
        foreach ($byColor as $color => $q) {
            if ($q > 0) {
                $left[$item][$color] = $q;
            }
        }
    }

    return ['ready' => $ready, 'partial' => $partial, 'blocked' => $blocked, 'stockLeft' => $left];
}

/**
 * Rows in the shape print_plate_group_queue() (print_plates.php) takes:
 * one per line that still has pieces MISSING after stock. Passing a
 * shipment's other (shirt/hat) lines too lets that function's "real
 * completion" logic know such a shipment isn't finishing yet.
 */
function merch_stock_print_rows(array $shipments): array
{
    $rows = [];
    foreach ($shipments as $s) {
        foreach ($s['lines'] as $l) {
            if (($l['missing'] ?? $l['qty']) > 0) {
                $rows[] = [
                    'item' => $l['item'],
                    'color' => $l['color'],
                    'qty' => $l['missing'] ?? $l['qty'],
                    'orderId' => $l['orderId'],
                    'customerName' => $s['name'],
                    'orderGroupId' => '',
                    'shipmentKey' => $s['printKey'],
                ];
            }
        }
        foreach ($s['other'] as $l) {
            $rows[] = [
                'item' => $l['item'],
                'color' => $l['color'],
                'qty' => $l['qty'],
                'orderId' => $l['orderId'],
                'customerName' => $s['name'],
                'orderGroupId' => '',
                'shipmentKey' => $s['printKey'],
            ];
        }
    }
    return $rows;
}

/**
 * Close-out ordering: shipments that could actually ship once printed
 * (not waiting on a shirt/hat, not Stars & Stripes) ranked by
 *   1. fewest pieces still missing (cheapest to finish),
 *   2. then pieces that share an item+color with OTHER close-to-done
 *      shipments - those ride the same plate, so finishing them costs
 *      less than their piece count suggests (a plate holds 3 Blade
 *      Holders, so four orders each missing one CM Blue Blade Holder are
 *      cheaper together than four unrelated pieces),
 *   3. then fewest different colors (each color is a filament change),
 *   4. then oldest.
 * Returns every such shipment in ranked order; the caller slices off the
 * first N.
 */
function merch_stock_closeout_rank(array $partial): array
{
    $eligible = array_values(array_filter($partial, fn($s) => !$s['special'] && empty($s['other']) && $s['missingUnits'] > 0));

    $demand = []; // item|color => missing units across all eligible shipments
    foreach ($eligible as $s) {
        foreach ($s['lines'] as $l) {
            if (($l['missing'] ?? 0) > 0) {
                $k = $l['item'] . '|' . $l['color'];
                $demand[$k] = ($demand[$k] ?? 0) + $l['missing'];
            }
        }
    }
    $metrics = function (array $s) use ($demand): array {
        $colors = [];
        $shared = 0;
        foreach ($s['lines'] as $l) {
            if (($l['missing'] ?? 0) > 0) {
                $colors[$l['color']] = true;
                // Units OTHER shipments also need of this same item+color.
                $shared += $demand[$l['item'] . '|' . $l['color']] - $l['missing'];
            }
        }
        return [count($colors), $shared];
    };
    usort($eligible, function ($a, $b) use ($metrics) {
        [$ac, $as] = $metrics($a);
        [$bc, $bs] = $metrics($b);
        return [$a['missingUnits'], -$as, $ac, $a['minOrderId']] <=> [$b['missingUnits'], -$bs, $bc, $b['minOrderId']];
    });
    return $eligible;
}

/**
 * How many shipments would be ready if stock were handed out to the
 * SMALLEST shipments first instead of oldest-first. The report shows this
 * only as a hint when it beats oldest-first (it can: one three-piece
 * order can use up the pieces that two one-piece orders were waiting for)
 * - oldest-first stays the default, this is Steve's call.
 */
function merch_stock_ready_count_smallest_first(array $shipments, array $stock): int
{
    usort($shipments, function ($a, $b) {
        $sa = array_sum(array_column($a['lines'], 'qty'));
        $sb = array_sum(array_column($b['lines'], 'qty'));
        return [$sa, $a['minOrderId']] <=> [$sb, $b['minOrderId']];
    });
    return count(merch_stock_allocate($shipments, $stock)['ready']);
}

/** Per-color unit/plate totals from print_plate_group_queue()'s output. */
function merch_stock_plan_stats(array $groups): array
{
    $byColor = [];
    $units = 0;
    $plates = 0;
    foreach ($groups as $g) {
        $cu = 0;
        $cp = 0;
        foreach ($g['plateGroups'] as $pg) {
            foreach ($pg['plates'] as $plate) {
                $cp++;
                foreach ($plate['items'] as $data) {
                    $cu += $data['qty'];
                }
            }
        }
        $byColor[$g['color']] = ['units' => $cu, 'plates' => $cp];
        $units += $cu;
        $plates += $cp;
    }
    return ['byColor' => $byColor, 'units' => $units, 'plates' => $plates, 'colors' => count($byColor)];
}
