<?php
// Build: 2026-10-07-B
// ============================================================
// Printed-stock matching + print-next planning logic, behind
// merch_stock_report.php (admin report) and, since 2026-10-07, the live
// inventory editor (merch_stock_edit.php / merch_stock_adjust.php; the old
// CSV upload box and merch_stock_upload.php are gone - a wipe-and-replace is
// now an FTP push of inventory.csv). Pure functions - no CSV/session/HTTP in this
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
 * Qty Created, plus optionally Timestamp (when it was ordered), Size and
 * Sleeve (any may be false - same optional-column tolerance as the rest
 * of the codebase).
 *
 * AGE (2026-10-04, Steve): "how old is an order" means how long since it
 * was PAID, not since it was placed - someone who ordered in July and paid
 * yesterday must not jump ahead of people who paid a week ago. Pymt Date is
 * the real payment day for everything marked paid from 2026-10-04 on. Older
 * rows have a Pymt Date that an old rule copied from their Invoice Date;
 * that can't be corrected, and since invoices went out within ~2 days of
 * ordering they rank about the same as by order date. A row with no payment
 * date (unpaid Pickup) falls back to its order Timestamp. $validItems = FILAMENT_COLOR_ITEMS (printed
 * pieces); anything else (shirts/hats) can't be printed or stocked, so it
 * is carried as an "other" line that blocks its shipment from being
 * ready. $excludedColors = PRINT_PLATE_EXCLUDED_COLORS (Stars & Stripes:
 * can't be stocked or batched, so a shipment needing one is "special").
 *
 * Returns:
 *   'shipments'    list sorted by oldest OrderID; each:
 *       key, printKey, type ('ship'|'pickup'), name, orderIds[],
 *       minOrderId, oldestTs (unix time the oldest row was ordered, or
 *       null), ageTs + ageBasis (how long it has been WAITING: the oldest
 *       row's Pymt Date - the real day it was paid, see below - or, for a
 *       row with no payment date (unpaid Pickup), its order Timestamp;
 *       ageBasis is 'paid' or 'ordered'), lines[ [orderId,item,color,qty,made,rowQty,ts,basis] ] (qty =
 *       pieces still to make; made = pieces of that row already made),
 *       other[ same ], madeLines[ [orderId,item,color,size,sleeve,qty,ts] ]
 *       (rows already fully Created - physically part of the shipment
 *       but nothing left to make or pull), special (bool),
 *       unpaidSiblingLines (int)
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
        $rowTs = merch_stock_parse_ts($get($row, 'Timestamp'));
        // How long this row has been waiting: since it was paid, else (no
        // payment date - unpaid Pickup) since it was ordered.
        $paidTs = merch_stock_parse_ts($get($row, 'Pymt Date'));
        $ageRowTs = $paidTs ?? $rowTs;
        $ageRowBasis = $paidTs !== null ? 'paid' : 'ordered';
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
                'oldestTs' => null,
                'ageTs' => null,
                'ageBasis' => 'ordered',
                'lines' => [],
                'other' => [],
                'madeLines' => [],
                'special' => false,
                'unpaidSiblingLines' => 0,
            ];
        }
        $ships[$key]['orderIds'][] = $orderId;
        if (ctype_digit($orderId)) {
            $ships[$key]['minOrderId'] = min($ships[$key]['minOrderId'], (int) $orderId);
        }
        if ($rowTs !== null) {
            $ships[$key]['oldestTs'] = $ships[$key]['oldestTs'] === null ? $rowTs : min($ships[$key]['oldestTs'], $rowTs);
        }
        if ($ageRowTs !== null && ($ships[$key]['ageTs'] === null || $ageRowTs < $ships[$key]['ageTs'])) {
            $ships[$key]['ageTs'] = $ageRowTs;
            $ships[$key]['ageBasis'] = $ageRowBasis;
        }

        if ($remaining === 0) {
            // Already printed - counts as done, nothing to plan. Kept for
            // the pick-list so Steve gathers the WHOLE order.
            $ships[$key]['madeLines'][] = [
                'orderId' => $orderId, 'item' => $item, 'color' => $color,
                'size' => $get($row, 'Size'), 'sleeve' => $get($row, 'Sleeve'),
                'qty' => $qty, 'ts' => $ageRowTs, 'basis' => $ageRowBasis,
            ];
            continue;
        }
        $line = ['orderId' => $orderId, 'item' => $item, 'color' => $color, 'qty' => $remaining,
                 'made' => $qty - $remaining, 'rowQty' => $qty, 'ts' => $ageRowTs, 'basis' => $ageRowBasis];
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
    usort($out, 'merch_stock_age_cmp');

    return [
        'shipments' => $out,
        'unpaidPieces' => $unpaidPieces,
        'unpaidShipments' => count($unpaidByKey),
        'awaitingShip' => $awaitingShip,
    ];
}

/**
 * Parse the merchandise.csv Timestamp column into a unix time, or null.
 * The column holds two formats: early rows are "7/5/2026" (M/D/YYYY,
 * date only) and everything since is "2026-10-03 18:26:31".
 */
function merch_stock_parse_ts(string $raw): ?int
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
 * Longest-waiting first: earliest age date (paid date, else order date),
 * then lowest OrderID, then key for a stable order. A shipment with no age
 * date at all sorts after dated ones but still by OrderID among themselves.
 */
function merch_stock_age_cmp(array $a, array $b): int
{
    return [$a['ageTs'] ?? PHP_INT_MAX, $a['minOrderId'], $a['key']] <=> [$b['ageTs'] ?? PHP_INT_MAX, $b['minOrderId'], $b['key']];
}

/** "Paid Sep 30 (4 days)" / "Ordered Sep 30 (4 days)"; '' when there's no date. */
function merch_stock_age_label(?int $ts, string $basis, ?int $now = null): string
{
    $t = merch_stock_age_text($ts, $now);
    return $t === '' ? '' : ($basis === 'paid' ? 'Paid ' : 'Ordered ') . $t;
}

/** "Aug 22 (43 days ago)" for the report; '' when there's no timestamp. */
function merch_stock_age_text(?int $ts, ?int $now = null): string
{
    if ($ts === null) {
        return '';
    }
    $now = $now ?? time();
    $days = max(0, (int) floor(($now - $ts) / 86400));
    return date('M j', $ts) . ' (' . ($days === 0 ? 'today' : ($days === 1 ? '1 day' : $days . ' days')) . ')';
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
 * that can't complete yet - oldest order first ($priority 'age', what
 * the report page uses) or closest-to-done first ($priority 'quick', the
 * original rule and this function's default) - so the "still to print"
 * list reflects pieces genuinely missing. (Stock is never moved between
 * item/colors - rule 3.) Shipments waiting on shirts/hats go last.
 *
 * Returns the shipments split into 'ready' / 'partial' / 'blocked' (each
 * line annotated with 'covered' and 'missing'), plus 'stockLeft' (stock no
 * remaining paid demand wants - the "stranded" pieces).
 */
function merch_stock_allocate(array $shipments, array $stock, string $priority = 'quick'): array
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

    // Judged against what's left after pass A.
    $short = function (array $s) use ($rem): int {
        $m = 0;
        foreach (merch_stock_shipment_needs($s) as $item => $byColor) {
            foreach ($byColor as $color => $need) {
                $m += max(0, $need - ($rem[$item][$color] ?? 0));
            }
        }
        return $m;
    };
    if ($priority === 'quick') {
        usort($pending, fn($a, $b) => [$short($a), $a['minOrderId']] <=> [$short($b), $b['minOrderId']]);
    } else {
        usort($pending, 'merch_stock_age_cmp');
    }

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
                    'ts' => $l['ts'] ?? null, // 2026-10-07: wait time, so print_plates.php prints the longest-waiting customer's plate first
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
                'ts' => $l['ts'] ?? null,
            ];
        }
    }
    return $rows;
}

/**
 * Close-out ordering: shipments that could actually ship once printed
 * (not waiting on a shirt/hat, not Stars & Stripes). $priority 'age'
 * ranks strictly oldest order first; 'quick' (the original rule) ranks by
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
function merch_stock_closeout_rank(array $partial, string $priority = 'quick'): array
{
    $eligible = array_values(array_filter($partial, fn($s) => !$s['special'] && empty($s['other']) && $s['missingUnits'] > 0));
    if ($priority === 'age') {
        // 2026-10-04 (Steve): with a big backlog the longest-waiting orders
        // must not sit while cheaper new ones jump ahead - strictly oldest
        // first, where "oldest" = paid longest ago (see merch_stock_age_cmp).
        usort($eligible, 'merch_stock_age_cmp');
        return $eligible;
    }

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

// ============================================================
// Pick-list "Done" support (2026-10-04): the Ready-to-ship list on
// merch_stock_report.php gets a checkbox per part and a Done button;
// merch_stock_ship.php uses these to (a) mark the pulled rows Created and
// (b) take the pieces out of inventory.csv - all or nothing.
// ============================================================

/**
 * Fingerprint of exactly what a Done click commits for one shipment: every
 * stock-covered line (order id, item, color, pieces). The page posts the
 * value it rendered; the server recomputes from the live files and refuses
 * if they differ, so what Steve physically gathered can never silently
 * differ from what gets decremented/marked.
 */
function merch_stock_shipment_signature(array $shipment): string
{
    $parts = [];
    foreach ($shipment['lines'] as $l) {
        $parts[] = $l['orderId'] . '|' . $l['item'] . '|' . $l['color'] . '|' . $l['qty'];
    }
    sort($parts, SORT_STRING);
    return substr(sha1(implode("\n", $parts)), 0, 16);
}

/** Ids of the rows a Done click would mark Created (the lines pulled from stock). */
function merch_stock_shipment_commit_ids(array $shipment): array
{
    $ids = [];
    foreach ($shipment['lines'] as $l) {
        $ids[$l['orderId']] = true;
    }
    $ids = array_map('strval', array_keys($ids));
    sort($ids, SORT_STRING);
    return $ids;
}

/**
 * Take $needs (item => color => qty) out of a raw inventory grid (the
 * fgetcsv rows of inventory.csv, either layout), returning the grid with
 * only those cells changed - labels, unmatched colors (Copper), blank
 * rows and the BOM are all left exactly as uploaded. Zeroed cells go
 * blank, like the rest of the sheet. If the sheet has numeric Total
 * row/column cells, they come down by the same amount.
 *
 * Returns ['ok'=>true,'rows'=>...] or ['ok'=>false,'error'=>...] - and on
 * error NOTHING is changed (all-or-nothing; the caller never gets a
 * half-decremented grid).
 */
function merch_stock_decrement_grid(array $rows, array $needs, array $validItems, array $validColors): array
{
    $itemIdx = merch_stock_item_index($validItems);
    $colorIdx = merch_stock_color_index($validColors);
    $flipped = merch_stock_detect_orientation($rows, $validItems, $validColors) === 'colors-down';

    $hdr = null;
    foreach ($rows as $i => $r) {
        if (is_array($r) && count(array_filter($r, fn($c) => trim((string) $c) !== '')) > 0) {
            $hdr = $i;
            break;
        }
    }
    if ($hdr === null) {
        return ['ok' => false, 'error' => 'The inventory file is empty.'];
    }
    $isTotal = fn($v) => strtolower(trim((string) $v)) === 'total';
    $itemOf = fn($label) => $itemIdx[merch_stock_norm(trim((string) $label))] ?? null;
    $colorOf = fn($label) => merch_stock_resolve_color(trim((string) $label), $colorIdx)[0];

    $width = 0;
    foreach ($rows as $r) {
        $width = max($width, count($r));
    }
    $totalCol = null;
    for ($c = 1; $c < $width; $c++) {
        if ($isTotal($rows[$hdr][$c] ?? '')) {
            $totalCol = $c;
        }
    }
    $totalRow = null;
    foreach ($rows as $i => $r) {
        if ($i > $hdr && $isTotal($r[0] ?? '')) {
            $totalRow = $i;
        }
    }

    $out = $rows;
    $changes = []; // [r, c, take]
    foreach ($needs as $item => $byColor) {
        foreach ($byColor as $color => $need) {
            $left = (int) $need;
            for ($r = $hdr + 1; $r < count($rows) && $left > 0; $r++) {
                $side = trim((string) ($rows[$r][0] ?? ''));
                if ($side === '' || $isTotal($side)) {
                    continue;
                }
                for ($c = 1; $c < $width && $left > 0; $c++) {
                    $top = trim((string) ($rows[$hdr][$c] ?? ''));
                    if ($top === '' || $isTotal($top)) {
                        continue;
                    }
                    $cellItem = $flipped ? $itemOf($top) : $itemOf($side);
                    $cellColor = $flipped ? $colorOf($side) : $colorOf($top);
                    if ($cellItem !== $item || $cellColor !== $color) {
                        continue;
                    }
                    $raw = trim((string) ($out[$r][$c] ?? ''));
                    if ($raw === '' || !is_numeric($raw) || (float) $raw < 0 || (float) $raw != floor((float) $raw)) {
                        continue;
                    }
                    $have = (int) $raw;
                    $take = min($have, $left);
                    if ($take > 0) {
                        while (count($out[$r]) <= $c) {
                            $out[$r][] = '';
                        }
                        $out[$r][$c] = $have - $take === 0 ? '' : (string) ($have - $take);
                        $changes[] = [$r, $c, $take];
                        $left -= $take;
                    }
                }
            }
            if ($left > 0) {
                return ['ok' => false, 'error' => "Not enough {$item} in {$color} on the shelf (the inventory changed - reload the page)."];
            }
        }
    }

    foreach ($changes as [$r, $c, $take]) {
        // The row's Total cell, the column's Total cell, and the grand-total corner.
        foreach ([[$r, $totalCol], [$totalRow, $c], [$totalRow, $totalCol]] as [$tr, $tc]) {
            if ($tr === null || $tc === null) {
                continue;
            }
            $v = trim((string) ($out[$tr][$tc] ?? ''));
            if ($v !== '' && is_numeric($v)) {
                $out[$tr][$tc] = (string) max(0, (int) $v - $take);
            }
        }
    }
    return ['ok' => true, 'rows' => $out];
}

/**
 * The row-level effect of Done: exactly what ticking "Created" does in
 * merch_update.php - Created gets today's date (kept if already set) and
 * Qty Created becomes the full Quantity (the two columns are one fact; see
 * the 2026-09-25 comment there). Never touches Fulfilled. Pads short rows
 * first so a value can't land at the wrong offset.
 */
function merch_stock_apply_created(array &$row, array $col, string $today): void
{
    $created = $col['Created'];
    $qtyCreated = $col['Qty Created'];
    $quantity = $col['Quantity'];
    $max = max($created, $qtyCreated);
    while (count($row) <= $max) {
        $row[] = '';
    }
    if (trim((string) $row[$created]) === '') {
        $row[$created] = $today;
    }
    $row[$qtyCreated] = (string) max(1, (int) trim((string) ($row[$quantity] ?? '1')));
}

/**
 * Back up inventory.csv to backups/inventory_<stamp>.csv (never overwrites an earlier one) and keep the newest
 * $keep (300 since 2026-10-07: the live editor writes on every tap, and a keep of 30 would have covered about half an
 * hour of edits; the real record of what changed is inventory_log.csv - see below).
 */
function merch_stock_backup_inventory(string $target, string $backupDir, int $keep = 300): void
{
    if (!is_file($target) || filesize($target) === 0) {
        return;
    }
    if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
        error_log("merch_stock_backup_inventory: could not create {$backupDir}");
        return;
    }
    $stamp = date('Ymd_His');
    $dest = $backupDir . '/inventory_' . $stamp . '.csv';
    for ($n = 2; file_exists($dest); $n++) {
        $dest = $backupDir . '/inventory_' . $stamp . '_' . $n . '.csv';
    }
    if (!copy($target, $dest)) {
        error_log("merch_stock_backup_inventory: could not copy {$target} to {$dest}");
        return;
    }
    $old = glob($backupDir . '/inventory_*.csv') ?: [];
    if (count($old) > $keep) {
        sort($old);
        foreach (array_slice($old, 0, count($old) - $keep) as $f) {
            if (!unlink($f)) {
                error_log("merch_stock_backup_inventory: could not prune {$f}");
            }
        }
    }
}

// ============================================================
// 2026-10-07 (Steve): LIVE INVENTORY EDITING
//
// Entering stock used to mean editing a sheet in Excel, saving it as CSV and
// uploading it, and re-doing the whole count whenever he doubted what he had
// versus what he'd added. The editor page (merch_stock_edit.php, written
// through merch_stock_adjust.php) changes ONE cell at a time and records
// every change in inventory_log.csv, so "what do I have vs what did I add"
// can be answered from the log instead of from memory.
//
// Everything below the "pure" functions is I/O-free so tests can drive it
// with hand-built rows (tests/test_merch_stock_edit.php). Rules it follows:
//   * Changes arrive as DELTAS (+3, -1), applied to the file as it is right
//     now under lock - never as "set the count to N" - so a page that was
//     loaded earlier can't overwrite a change made since (a Done pick,
//     another tab). The one absolute action is "count" (a physical
//     recount), which logs the difference.
//   * A removal that would take a count below zero is refused.
//   * Every request carries a one-time token; a repeat (double tap, network
//     retry) is answered from the log and changes nothing the second time.
//   * Each log row stores a short hash of inventory.csv as it was left. If
//     the file's hash no longer matches the last row, someone replaced the
//     file outside the page (FTP push, hand edit): an "outside" marker row
//     is logged first, and Undo refuses to run across it.
//   * The log is append-only. Undo is a new row that reverses the latest
//     page change (never a deletion), and is refused if the count has moved.
//   * The grid keeps whatever layout it already has (parts-down or
//     colors-down, Total row/column, unmatched labels like an unlisted
//     color) - only the one cell and its Total cells change. A part row or
//     color column that doesn't exist yet is added (before any Total).
// ============================================================

const MERCH_STOCK_LOG_COLUMNS = ['LogID', 'Timestamp', 'Item', 'Color', 'Change', 'Before', 'After', 'Reason', 'Source', 'Ref', 'Req', 'FileHash'];
const MERCH_STOCK_ADD_REASON = 'Plate finished';
const MERCH_STOCK_COUNT_REASON = 'Count correction';
const MERCH_STOCK_REMOVE_REASONS = ['Broken or defective part', 'Gift', 'Event or show', 'Misprint or discarded', 'Other'];
const MERCH_STOCK_OUTSIDE_REASON = 'Inventory file changed outside this page';

/**
 * The colors a printed piece can be stocked in: the site's color list minus
 * the "Not applicable" placeholder and anything in $excluded (Stars &
 * Stripes - it can't be stocked or batched, see PRINT_PLATE_EXCLUDED_COLORS).
 */
function merch_stock_stockable_colors(array $validColors, array $excluded = []): array
{
    $out = [];
    foreach ($validColors as $c) {
        if (in_array($c, $excluded, true) || stripos($c, 'Not applicable') === 0) {
            continue;
        }
        $out[] = $c;
    }
    return $out;
}

/** Serialize a grid exactly as the endpoints write it (so a hash of this string is a hash of the file). */
function merch_stock_grid_to_csv(array $rows): string
{
    $fh = fopen('php://temp', 'r+');
    foreach ($rows as $r) {
        fputcsv($fh, array_map(fn($v) => $v === null ? '' : (string) $v, $r), ',', '"', '\\');
    }
    rewind($fh);
    $s = (string) stream_get_contents($fh);
    fclose($fh);
    return $s;
}

/** Short fingerprint of the inventory file's bytes, stored on every log row. */
function merch_stock_file_hash(string $contents): string
{
    return substr(sha1($contents), 0, 12);
}

/**
 * Find where (item, color) lives in a raw grid: which rows/columns carry
 * its labels and every cell where the two cross. See merch_stock_adjust_grid().
 * ['empty' => true] when the grid has no content at all.
 */
function merch_stock_grid_locate(array $rows, string $item, string $color, array $validItems, array $validColors): array
{
    $hdr = null;
    foreach ($rows as $i => $r) {
        if (is_array($r) && count(array_filter($r, fn($c) => trim((string) $c) !== '')) > 0) {
            $hdr = $i;
            break;
        }
    }
    if ($hdr === null) {
        return ['empty' => true];
    }
    $itemIdx = merch_stock_item_index($validItems);
    $colorIdx = merch_stock_color_index($validColors);
    $flipped = merch_stock_detect_orientation($rows, $validItems, $validColors) === 'colors-down';
    $isTotal = fn($v) => strtolower(trim((string) $v)) === 'total';
    $itemOf = fn($label) => $itemIdx[merch_stock_norm(trim((string) $label))] ?? null;
    $colorOf = fn($label) => merch_stock_resolve_color(trim((string) $label), $colorIdx)[0];

    $width = 0;
    foreach ($rows as $r) {
        $width = max($width, is_array($r) ? count($r) : 0);
    }
    $totalCol = null;
    for ($c = 1; $c < $width; $c++) {
        if ($isTotal($rows[$hdr][$c] ?? '')) {
            $totalCol = $c;
        }
    }
    $totalRow = null;
    $lastContent = $hdr;
    foreach ($rows as $i => $r) {
        if ($i <= $hdr || !is_array($r)) {
            continue;
        }
        if ($isTotal($r[0] ?? '')) {
            $totalRow = $i;
            continue;
        }
        if (count(array_filter($r, fn($c) => trim((string) $c) !== '')) > 0) {
            $lastContent = $i;
        }
    }

    $sideRows = [];
    for ($r = $hdr + 1; $r < count($rows); $r++) {
        $side = trim((string) ($rows[$r][0] ?? ''));
        if ($side === '' || $isTotal($side)) {
            continue;
        }
        if (($flipped ? $colorOf($side) : $itemOf($side)) === ($flipped ? $color : $item)) {
            $sideRows[] = $r;
        }
    }
    $topCols = [];
    for ($c = 1; $c < $width; $c++) {
        $top = trim((string) ($rows[$hdr][$c] ?? ''));
        if ($top === '' || $isTotal($top)) {
            continue;
        }
        if (($flipped ? $itemOf($top) : $colorOf($top)) === ($flipped ? $item : $color)) {
            $topCols[] = $c;
        }
    }
    $cells = [];
    foreach ($sideRows as $r) {
        foreach ($topCols as $c) {
            $raw = trim((string) ($rows[$r][$c] ?? ''));
            $ok = $raw === '' || (is_numeric($raw) && (float) $raw >= 0 && (float) $raw == floor((float) $raw));
            $cells[] = ['r' => $r, 'c' => $c, 'ok' => $ok, 'have' => ($ok && $raw !== '') ? (int) $raw : 0];
        }
    }
    return [
        'empty' => false, 'hdr' => $hdr, 'flipped' => $flipped, 'width' => $width,
        'totalCol' => $totalCol, 'totalRow' => $totalRow, 'lastContent' => $lastContent,
        'sideRows' => $sideRows, 'topCols' => $topCols, 'cells' => $cells,
    ];
}

/**
 * Add $delta (signed) to the count of one item+color in a raw inventory
 * grid, returning the grid with only that cell (and the Total cells that
 * are numbers) changed. A zeroed cell goes blank like the rest of the
 * sheet. A part row / color column that isn't in the sheet yet is created
 * (a new part row goes right after the last data row, a new color column before the Total column); an empty or missing grid
 * becomes a colors-down grid with every part across the top.
 *
 * Returns ['ok'=>true,'rows'=>..., 'before'=>int, 'after'=>int] or
 * ['ok'=>false,'error'=>...] with nothing changed. A removal larger than
 * what's on hand is refused.
 */
function merch_stock_adjust_grid(array $rows, string $item, string $color, int $delta, array $validItems, array $validColors): array
{
    $loc = merch_stock_grid_locate($rows, $item, $color, $validItems, $validColors);
    if ($loc['empty']) {
        if ($delta <= 0) {
            return $delta === 0
                ? ['ok' => true, 'rows' => $rows, 'before' => 0, 'after' => 0]
                : ['ok' => false, 'error' => "There is no {$item} in {$color} on the shelf to remove."];
        }
        $rows = [array_merge(['Color'], array_values($validItems))];
        $loc = merch_stock_grid_locate($rows, $item, $color, $validItems, $validColors);
    }
    $before = 0;
    foreach ($loc['cells'] as $cell) {
        $before += $cell['have'];
    }
    if ($delta === 0) {
        return ['ok' => true, 'rows' => $rows, 'before' => $before, 'after' => $before];
    }
    if ($before + $delta < 0) {
        return ['ok' => false, 'error' => $before === 0
            ? "There is no {$item} in {$color} on the shelf to remove."
            : "Only {$before} {$item} in {$color} on the shelf - can't remove " . (-$delta) . '.'];
    }
    $newRow = false;
    $newCol = false;
    if ($delta > 0 && empty($loc['cells'])) {
        $flipped = $loc['flipped'];
        $sideLabel = $flipped ? $color : $item;
        $topLabel = $flipped ? $item : $color;
        $width = $loc['width'];
        if (empty($loc['topCols'])) {
            $at = $loc['totalCol'] ?? $width;
            foreach ($rows as $i => $r) {
                $r = is_array($r) ? array_map(fn($v) => $v === null ? '' : $v, $r) : [];
                while (count($r) < $width) {
                    $r[] = '';
                }
                array_splice($r, $at, 0, ['']);
                $rows[$i] = $r;
            }
            $rows[$loc['hdr']][$at] = $topLabel;
            $width++;
            $newCol = true;
        }
        if (empty($loc['sideRows'])) {
            $line = array_fill(0, $width, '');
            $line[0] = $sideLabel;
            array_splice($rows, $loc['lastContent'] + 1, 0, [$line]);
            $newRow = true;
        }
        $loc = merch_stock_grid_locate($rows, $item, $color, $validItems, $validColors);
    }
    $target = null;
    foreach ($loc['cells'] as $cell) {
        if ($cell['ok'] && ($delta > 0 || $cell['have'] > 0)) {
            $target = $cell;
            break;
        }
    }
    if ($delta > 0 && $target === null) {
        return ['ok' => false, 'error' => "The cell for {$item} in {$color} holds something other than a count - fix inventory.csv by hand."];
    }

    $out = $rows;
    $touched = [];
    // A zeroed count cell goes blank like the rest of the sheet; a Total cell shows 0 (same as merch_stock_decrement_grid).
    $apply = function (int $r, int $c, int $newVal, bool $isTotalCell = false) use (&$out): void {
        while (count($out[$r]) <= $c) {
            $out[$r][] = '';
        }
        $out[$r][$c] = ($newVal === 0 && !$isTotalCell) ? '' : (string) $newVal;
    };
    if ($delta > 0) {
        $apply($target['r'], $target['c'], $target['have'] + $delta);
        $touched[] = [$target['r'], $target['c'], $delta];
    } else {
        $left = -$delta;
        foreach ($loc['cells'] as $cell) {
            if ($left <= 0) {
                break;
            }
            if (!$cell['ok'] || $cell['have'] <= 0) {
                continue;
            }
            $take = min($cell['have'], $left);
            $apply($cell['r'], $cell['c'], $cell['have'] - $take);
            $touched[] = [$cell['r'], $cell['c'], -$take];
            $left -= $take;
        }
    }
    // Total row / column / corner: only cells that already hold a number come along.
    // A freshly created line has no Total cell value yet, so it starts at the delta.
    foreach ($touched as [$r, $c, $d]) {
        foreach ([[$r, $loc['totalCol'], $newRow], [$loc['totalRow'], $c, $newCol], [$loc['totalRow'], $loc['totalCol'], false]] as [$tr, $tc, $fresh]) {
            if ($tr === null || $tc === null) {
                continue;
            }
            $v = trim((string) ($out[$tr][$tc] ?? ''));
            if ($v !== '' && is_numeric($v)) {
                $apply($tr, $tc, max(0, (int) $v + $d), true);
            } elseif ($v === '' && $fresh) {
                $apply($tr, $tc, max(0, $d), true);
            }
        }
    }
    return ['ok' => true, 'rows' => $out, 'before' => $before, 'after' => $before + $delta];
}

// ---- Change log (inventory_log.csv) --------------------------

/** Parse fgetcsv'd log rows (header first) into assoc arrays keyed by column name. */
function merch_stock_log_parse(array $rawRows): array
{
    $out = [];
    $header = null;
    foreach ($rawRows as $r) {
        if (!is_array($r) || count(array_filter($r, fn($c) => trim((string) $c) !== '')) === 0) {
            continue;
        }
        if ($header === null) {
            $header = array_map(fn($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $r);
            continue;
        }
        $row = [];
        foreach (MERCH_STOCK_LOG_COLUMNS as $name) {
            $i = array_search($name, $header, true);
            $row[$name] = $i === false ? '' : trim((string) ($r[$i] ?? ''));
        }
        $out[] = $row;
    }
    return $out;
}

/** Newest-first slice of the log for display. */
function merch_stock_log_recent(array $logRows, int $n = 15): array
{
    return array_slice(array_reverse($logRows), 0, $n);
}

/** Give each new entry its LogID (one after the highest existing) and fill every column. */
function merch_stock_log_finish(array $logRows, array $entries): array
{
    $next = 1;
    foreach ($logRows as $r) {
        $next = max($next, (int) ($r['LogID'] ?? 0) + 1);
    }
    $out = [];
    foreach ($entries as $e) {
        $row = [];
        foreach (MERCH_STOCK_LOG_COLUMNS as $name) {
            $row[$name] = (string) ($e[$name] ?? '');
        }
        $row['LogID'] = (string) $next++;
        $out[] = $row;
    }
    return $out;
}

/**
 * If the inventory file isn't what the last log row left behind, the file
 * was replaced or edited outside the page: returns the "outside" marker
 * entry to log first (it records the file as it is now); null otherwise
 * (including an empty log - nothing to compare against yet).
 */
function merch_stock_log_outside_marker(array $logRows, string $invContents, string $now): ?array
{
    if (empty($logRows)) {
        return null;
    }
    $last = $logRows[count($logRows) - 1];
    $hash = merch_stock_file_hash($invContents);
    if (($last['FileHash'] ?? '') === '' || $last['FileHash'] === $hash) {
        return null;
    }
    return ['Timestamp' => $now, 'Reason' => MERCH_STOCK_OUTSIDE_REASON, 'Source' => 'outside', 'FileHash' => $hash];
}

/**
 * The log entries for a Done pick-list pull (merch_stock_ship.php): one row
 * per item+color taken, with before/after from the grids either side of the
 * decrement. Source 'ship' rows can't be undone from the editor (undoing
 * stock alone would leave the order marked Created).
 */
function merch_stock_log_pull_entries(array $needs, array $stockBefore, array $stockAfter, string $label, string $now, string $fileHash): array
{
    $out = [];
    foreach ($needs as $item => $byColor) {
        foreach ($byColor as $color => $q) {
            $out[] = [
                'Timestamp' => $now, 'Item' => $item, 'Color' => $color, 'Change' => (string) -((int) $q),
                'Before' => (string) ($stockBefore[$item][$color] ?? 0), 'After' => (string) ($stockAfter[$item][$color] ?? 0),
                'Reason' => 'Pulled for ' . $label, 'Source' => 'ship', 'FileHash' => $fileHash,
            ];
        }
    }
    return $out;
}

/**
 * One editor request, start to finish, as pure data: validates it, decides
 * what changes, and returns what the endpoint should write. Nothing here
 * touches a file.
 *
 * $req: action ('add'|'remove'|'count'|'undo'), item, color, qty (add/remove:
 * 1-99; count: 0-9999), reason (remove only), token (one per click),
 * undoId (undo only).
 *
 * Returns:
 *   ok/code/error      - on failure nothing is to be written (code = HTTP status)
 *   duplicate          - this token was already applied; answer, don't write
 *   write              - false when nothing changes (duplicate, or a count equal to the current one)
 *   invRows            - the new grid (only when write)
 *   logEntries         - rows to append, outside-marker first if any (only when write)
 *   backupForce        - true when the old file should always be snapshotted first (it was replaced outside the page)
 *   result             - item, color, change, before, after, logId, reason, action
 *   stock              - item => color => qty after the change
 */
function merch_stock_apply_adjustment(array $invRows, string $invContents, array $logRows, array $req, string $now, array $validItems, array $validColors, array $excludedColors = []): array
{
    $fail = fn(int $code, string $msg) => ['ok' => false, 'code' => $code, 'error' => $msg];
    $stockable = merch_stock_stockable_colors($validColors, $excludedColors);

    $action = (string) ($req['action'] ?? '');
    if (!in_array($action, ['add', 'remove', 'count', 'undo'], true)) {
        return $fail(400, 'Invalid request.');
    }
    $token = (string) ($req['token'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token)) {
        return $fail(400, 'Invalid request.');
    }

    // A repeat of a request that already went through: answer from the log.
    foreach (array_slice($logRows, -300) as $row) {
        if (($row['Req'] ?? '') === $token) {
            $stock = merch_stock_parse_grid($invRows, $validItems, $validColors)['stock'];
            return [
                'ok' => true, 'duplicate' => true, 'write' => false, 'stock' => $stock,
                'result' => [
                    'action' => $action, 'item' => $row['Item'], 'color' => $row['Color'], 'change' => (int) $row['Change'],
                    'before' => (int) $row['Before'], 'after' => (int) $row['After'], 'logId' => (int) $row['LogID'], 'reason' => $row['Reason'],
                ],
            ];
        }
    }

    $marker = merch_stock_log_outside_marker($logRows, $invContents, $now);
    $currentStock = merch_stock_parse_grid($invRows, $validItems, $validColors)['stock'];
    $ref = '';
    $reason = '';

    if ($action === 'undo') {
        $undoId = (string) ($req['undoId'] ?? '');
        if (!ctype_digit($undoId) || empty($logRows)) {
            return $fail(400, 'Invalid request.');
        }
        $last = $logRows[count($logRows) - 1];
        if ($marker !== null) {
            return $fail(409, "The inventory file was changed outside this page since that change, so it can't be undone safely.");
        }
        if ($last['LogID'] !== $undoId || $last['Source'] !== 'page' || strpos($last['Reason'], 'Undo') === 0 || (int) $last['Change'] === 0) {
            return $fail(409, 'Only the most recent change can be undone, and that is no longer it - reload the page.');
        }
        $item = $last['Item'];
        $color = $last['Color'];
        $now_have = (int) ($currentStock[$item][$color] ?? 0);
        if ($now_have !== (int) $last['After']) {
            return $fail(409, "That count has changed since (now {$now_have}) - it can't be undone safely.");
        }
        $delta = -((int) $last['Change']);
        $ref = $undoId;
        $reason = 'Undo of #' . $undoId;
    } else {
        $item = (string) ($req['item'] ?? '');
        $color = (string) ($req['color'] ?? '');
        if (!in_array($item, $validItems, true) || !in_array($color, $stockable, true)) {
            return $fail(400, 'Unknown part or color.');
        }
        $qtyRaw = (string) ($req['qty'] ?? '');
        if (!ctype_digit($qtyRaw)) {
            return $fail(400, 'Enter a whole number.');
        }
        $qty = (int) $qtyRaw;
        if ($action === 'count') {
            if ($qty > 9999) {
                return $fail(400, 'That count is too large.');
            }
            $delta = $qty - (int) ($currentStock[$item][$color] ?? 0);
            $reason = MERCH_STOCK_COUNT_REASON;
        } else {
            if ($qty < 1 || $qty > 99) {
                return $fail(400, 'Enter a number from 1 to 99.');
            }
            if ($action === 'add') {
                $delta = $qty;
                $reason = MERCH_STOCK_ADD_REASON;
            } else {
                $delta = -$qty;
                $reason = (string) ($req['reason'] ?? '') === '' ? MERCH_STOCK_REMOVE_REASONS[0] : (string) $req['reason'];
                if (!in_array($reason, MERCH_STOCK_REMOVE_REASONS, true)) {
                    return $fail(400, 'Unknown reason.');
                }
            }
        }
    }

    if ($delta === 0) {
        return [
            'ok' => true, 'duplicate' => false, 'write' => false, 'stock' => $currentStock,
            'result' => ['action' => $action, 'item' => $item, 'color' => $color, 'change' => 0, 'before' => (int) ($currentStock[$item][$color] ?? 0), 'after' => (int) ($currentStock[$item][$color] ?? 0), 'logId' => 0, 'reason' => $reason],
        ];
    }
    $adj = merch_stock_adjust_grid($invRows, $item, $color, $delta, $validItems, $validColors);
    if (!$adj['ok']) {
        return $fail(409, $adj['error']);
    }
    $csv = merch_stock_grid_to_csv($adj['rows']);
    $entries = [];
    if ($marker !== null) {
        $entries[] = $marker;
    }
    $entries[] = [
        'Timestamp' => $now, 'Item' => $item, 'Color' => $color, 'Change' => (string) $delta,
        'Before' => (string) $adj['before'], 'After' => (string) $adj['after'], 'Reason' => $reason,
        'Source' => 'page', 'Ref' => $ref, 'Req' => $token, 'FileHash' => merch_stock_file_hash($csv),
    ];
    $entries = merch_stock_log_finish($logRows, $entries);
    $mine = $entries[count($entries) - 1];
    return [
        'ok' => true, 'duplicate' => false, 'write' => true, 'backupForce' => $marker !== null,
        'invRows' => $adj['rows'], 'invCsv' => $csv, 'logEntries' => $entries,
        'stock' => merch_stock_parse_grid($adj['rows'], $validItems, $validColors)['stock'],
        'result' => ['action' => $action, 'item' => $item, 'color' => $color, 'change' => $delta, 'before' => $adj['before'], 'after' => $adj['after'], 'logId' => (int) $mine['LogID'], 'reason' => $reason],
    ];
}

/**
 * Snapshot inventory.csv before an editor write, but at most once per
 * $minGap seconds (the editor writes on every tap; a snapshot per tap would
 * be a hundred identical files an hour) unless $force. The log, not these
 * snapshots, is the record of individual changes.
 */
function merch_stock_backup_inventory_throttled(string $target, string $backupDir, bool $force = false, int $minGap = 600): void
{
    if (!$force) {
        $existing = glob($backupDir . '/inventory_*.csv') ?: [];
        if (!empty($existing)) {
            sort($existing);
            $newest = (int) @filemtime($existing[count($existing) - 1]);
            if ($newest > 0 && (time() - $newest) < $minGap) {
                return;
            }
        }
    }
    merch_stock_backup_inventory($target, $backupDir);
}

/**
 * What the editor page shows from the log: the newest $n rows (display
 * fields only) and the id of the latest change if it is the kind Undo can
 * reverse (a page change that isn't itself an undo). The server re-checks
 * everything when Undo is actually clicked.
 */
function merch_stock_log_view(array $logRows, int $n = 15): array
{
    $recent = [];
    foreach (merch_stock_log_recent($logRows, $n) as $r) {
        $recent[] = [
            'id' => (int) $r['LogID'], 'time' => $r['Timestamp'], 'item' => $r['Item'], 'color' => $r['Color'],
            'change' => (int) $r['Change'], 'after' => (int) $r['After'], 'reason' => $r['Reason'], 'source' => $r['Source'],
        ];
    }
    $undoId = 0;
    if (!empty($logRows)) {
        $last = $logRows[count($logRows) - 1];
        if ($last['Source'] === 'page' && strpos($last['Reason'], 'Undo') !== 0 && (int) $last['Change'] !== 0) {
            $undoId = (int) $last['LogID'];
        }
    }
    return ['recent' => $recent, 'undoId' => $undoId];
}

/** Read the whole log from an already-locked handle. */
function merch_stock_log_read($lh): array
{
    rewind($lh);
    $raw = [];
    while (($r = fgetcsv($lh, 0, ',', '"', '\\')) !== false) {
        $raw[] = $r;
    }
    return merch_stock_log_parse($raw);
}

/** Append entries (already finished by merch_stock_log_finish) to an already-locked log handle, header first when the file is new. */
function merch_stock_log_append($lh, array $entries): bool
{
    if (fseek($lh, 0, SEEK_END) !== 0) {
        return false;
    }
    if (ftell($lh) === 0 && fputcsv($lh, MERCH_STOCK_LOG_COLUMNS, ',', '"', '\\') === false) {
        return false;
    }
    foreach ($entries as $e) {
        if (fputcsv($lh, array_map(fn($c) => (string) ($e[$c] ?? ''), MERCH_STOCK_LOG_COLUMNS), ',', '"', '\\') === false) {
            return false;
        }
    }
    return fflush($lh);
}
