<?php
// Build: 2026-10-07-A
// ============================================================
// Direct tests for the live inventory editor's logic in merch_stock.php:
// one-cell grid edits (both layouts, Total cells, new rows/columns),
// the change log, idempotent tokens, outside-edit markers and Undo.
// Run from anywhere:
//
//     php tests/test_merch_stock_edit.php
//
// No web server, no real files - hand-built grids and log rows.
// ============================================================

error_reporting(E_ALL);
const FILAMENT_COLOR_ITEMS = ['Blade Holder', 'Circle Cutter Holder', 'Oval Cutter Holder', 'Rectangle Cutter Holder', 'Hearts Cutter Holder', 'Tool Holder Stand', 'Tape Gun Holder', 'Tape Gun Add-On'];
const TEST_COLORS = ['#01 Red', '#03 Maroon', '#09 Magenta', '#12 Purple', '#13 Lilac', '#14 Sky Blue', '#15 CM Blue', '#17 Teal', '#26 Tan', 'Rainbow (+$2)', 'Stars & Stripes (+$7)', 'Not applicable / no color choice'];
const TEST_EXCLUDED = ['Stars & Stripes (+$7)'];
require dirname(__DIR__) . '/merch_shipments.php';
require dirname(__DIR__) . '/print_plates.php';
require dirname(__DIR__) . '/merch_stock.php';

$failures = [];
function expect(string $label, $got, $want): void
{
    global $failures;
    // true/false compare as 1/'' (what (string) gives), also inside arrays.
    $norm = function ($v) use (&$norm) {
        return is_array($v) ? array_map($norm, $v) : (is_bool($v) ? ($v ? 1 : '') : $v);
    };
    $g = is_array($got) ? json_encode($norm($got)) : (string) $got;
    $w = is_array($want) ? json_encode($norm($want)) : (string) $want;
    if ($g !== $w) {
        $failures[] = "[$label] expected '$w', got '$g'";
        echo "FAIL  $label: expected '$w', got '$g'\n";
    } else {
        echo "  ok  $label\n";
    }
}
$I = FILAMENT_COLOR_ITEMS;
$C = TEST_COLORS;

// ---- Stockable colors -------------------------------------------
expect('stockable: drops Stars & Stripes and "Not applicable"', merch_stock_stockable_colors($C, TEST_EXCLUDED), ['#01 Red', '#03 Maroon', '#09 Magenta', '#12 Purple', '#13 Lilac', '#14 Sky Blue', '#15 CM Blue', '#17 Teal', '#26 Tan', 'Rainbow (+$2)']);

// ---- Grid edits: parts down, with Total row/column ---------------
$partsDown = [
    ["\xEF\xBB\xBFPart", '#15 CM Blue', '#17 Teal', 'Copper', 'Total'],
    ['Blade Holder', '5', '1', '2', '8'],
    ['Circles', '1', '', '', '1'],
    ['', '', '', '', ''],
    ['Total', '6', '1', '2', '9'],
];
$r = merch_stock_adjust_grid($partsDown, 'Blade Holder', '#15 CM Blue', 3, $I, $C);
expect('add: ok', $r['ok'], 1);
expect('add: before/after', [$r['before'], $r['after']], [5, 8]);
expect('add: cell', $r['rows'][1][1], '8');
expect('add: row total', $r['rows'][1][4], '11');
expect('add: column total', $r['rows'][4][1], '9');
expect('add: grand total', $r['rows'][4][4], '12');
expect('add: unlisted color column (Copper) untouched', [$r['rows'][1][3], $r['rows'][4][3]], ['2', '2']);
expect('add: BOM and labels untouched', [$r['rows'][0][0], $r['rows'][2][0]], ["\xEF\xBB\xBFPart", 'Circles']);

$r = merch_stock_adjust_grid($partsDown, 'Blade Holder', '#17 Teal', -1, $I, $C);
expect('remove to zero: cell goes blank', $r['rows'][1][2], '');
expect('remove to zero: totals come down', [$r['rows'][1][4], $r['rows'][4][2], $r['rows'][4][4]], ['7', '0', '8']);

$r = merch_stock_adjust_grid($partsDown, 'Blade Holder', '#15 CM Blue', -6, $I, $C);
expect('remove more than on hand: refused', $r['ok'], '');
expect('remove more than on hand: says how many are there', strpos($r['error'], 'Only 5') !== false, 1);
$r = merch_stock_adjust_grid($partsDown, 'Oval Cutter Holder', '#15 CM Blue', -1, $I, $C);
expect('remove a part that has no row: refused, not created', $r['ok'], '');

// New color column goes before Total; new part row goes before the Total row.
$r = merch_stock_adjust_grid($partsDown, 'Blade Holder', '#09 Magenta', 4, $I, $C);
expect('new color: header gets it before Total', $r['rows'][0], ["\xEF\xBB\xBFPart", '#15 CM Blue', '#17 Teal', 'Copper', '#09 Magenta', 'Total']);
expect('new color: value in the right row', $r['rows'][1], ['Blade Holder', '5', '1', '2', '4', '12']);
expect('new color: its column total starts at the amount', $r['rows'][4][4], '4');
expect('new color: other rows padded, not shifted', $r['rows'][2], ['Circles', '1', '', '', '', '1']);
$r = merch_stock_adjust_grid($partsDown, 'Oval Cutter Holder', '#15 CM Blue', 2, $I, $C);
expect('new part: row sits right after the last data row', [$r['rows'][3][0], $r['rows'][3][1], $r['rows'][3][4]], ['Oval Cutter Holder', '2', '2']);
expect('new part: Total row still last and updated', [$r['rows'][count($r['rows']) - 1][0], $r['rows'][count($r['rows']) - 1][1]], ['Total', '8']);
$p = merch_stock_parse_grid($r['rows'], $I, $C);
expect('new part: parses back to the right count', $p['stock']['Oval Cutter Holder']['#15 CM Blue'] ?? null, 2);
expect('new part: nothing else disturbed', $p['stock']['Blade Holder']['#15 CM Blue'] ?? null, 5);

// ---- Grid edits: colors down ------------------------------------
$colorsDown = [
    ['Color', 'Blade Holder', 'Circles', 'Total'],
    ['#15 CM Blue', '5', '1', '6'],
    ['#17 Teal', '1', '', '1'],
    ['Total', '6', '1', '7'],
];
$r = merch_stock_adjust_grid($colorsDown, 'Circle Cutter Holder', '#17 Teal', 2, $I, $C);
expect('flipped: cell found via alias header', $r['rows'][2], ['#17 Teal', '1', '2', '3']);
expect('flipped: totals', [$r['rows'][3][2], $r['rows'][3][3]], ['3', '9']);
$r = merch_stock_adjust_grid($colorsDown, 'Blade Holder', '#12 Purple', 2, $I, $C);
expect('flipped: new color becomes a row above Total', [$r['rows'][3][0], $r['rows'][3][1], $r['rows'][3][3]], ['#12 Purple', '2', '2']);
expect('flipped: Total row still last', $r['rows'][4][0], 'Total');
$r = merch_stock_adjust_grid($colorsDown, 'Tool Holder Stand', '#15 CM Blue', 1, $I, $C);
expect('flipped: new part becomes a column before Total', $r['rows'][0], ['Color', 'Blade Holder', 'Circles', 'Tool Holder Stand', 'Total']);
expect('flipped: new part value', $r['rows'][1], ['#15 CM Blue', '5', '1', '1', '7']);

// ---- Grid edits: no file / empty file ---------------------------
$r = merch_stock_adjust_grid([], 'Hearts Cutter Holder', '#15 CM Blue', 3, $I, $C);
expect('empty grid: add creates a colors-down grid', [$r['ok'], $r['rows'][0][0], $r['rows'][1][0]], [1, 'Color', '#15 CM Blue']);
expect('empty grid: parses back', merch_stock_parse_grid($r['rows'], $I, $C)['stock']['Hearts Cutter Holder']['#15 CM Blue'] ?? null, 3);
expect('empty grid: remove refused', merch_stock_adjust_grid([], 'Hearts Cutter Holder', '#15 CM Blue', -1, $I, $C)['ok'], '');
expect('no-op delta changes nothing', merch_stock_adjust_grid($partsDown, 'Blade Holder', '#15 CM Blue', 0, $I, $C)['rows'], $partsDown);

// ---- Duplicate rows (a part listed twice) -----------------------
$dup = [['Part', '#15 CM Blue', 'Total'], ['Blade Holder', '2', '2'], ['Blade Holder', '3', '3'], ['Total', '5', '5']];
$r = merch_stock_adjust_grid($dup, 'Blade Holder', '#15 CM Blue', -4, $I, $C);
expect('duplicate rows: removal spreads across both', [$r['rows'][1][1], $r['rows'][2][1], $r['rows'][3][1]], ['', '1', '1']);

// ---- A cell holding text is never overwritten -------------------
$junk = [['Part', '#15 CM Blue'], ['Blade Holder', 'ask Janet']];
expect('text cell: add refused', merch_stock_adjust_grid($junk, 'Blade Holder', '#15 CM Blue', 1, $I, $C)['ok'], '');

// ---- Log + requests ---------------------------------------------
$now = '2026-10-07 14:00:00';
$csv0 = merch_stock_grid_to_csv($partsDown);
$req = fn(array $over = []) => array_merge(['action' => 'add', 'item' => 'Blade Holder', 'color' => '#15 CM Blue', 'qty' => '3', 'token' => 'tok-aaaaaaaa'], $over);

$a = merch_stock_apply_adjustment($partsDown, $csv0, [], $req(), $now, $I, $C, TEST_EXCLUDED);
expect('add: ok and writes', [$a['ok'], $a['write']], [1, 1]);
expect('add: result', [$a['result']['change'], $a['result']['before'], $a['result']['after'], $a['result']['logId']], [3, 5, 8, 1]);
expect('add: one log entry, reason, source', [count($a['logEntries']), $a['logEntries'][0]['Reason'], $a['logEntries'][0]['Source']], [1, 'Plate finished', 'page']);
expect('add: log hash is the hash of the file as written', $a['logEntries'][0]['FileHash'], merch_stock_file_hash($a['invCsv']));
expect('add: stock map updated', $a['stock']['Blade Holder']['#15 CM Blue'], 8);
$log1 = $a['logEntries'];
$csv1 = $a['invCsv'];
$rows1 = $a['invRows'];

// Same token again: answered from the log, nothing written.
$d = merch_stock_apply_adjustment($rows1, $csv1, $log1, $req(), $now, $I, $C, TEST_EXCLUDED);
expect('duplicate token: ok, flagged, no write', [$d['ok'], $d['duplicate'], $d['write']], [1, 1, '']);
expect('duplicate token: same answer', [$d['result']['change'], $d['result']['after'], $d['result']['logId']], [3, 8, 1]);

// Removal with a reason; default reason; bad reason; below zero.
$b = merch_stock_apply_adjustment($rows1, $csv1, $log1, $req(['action' => 'remove', 'qty' => '1', 'reason' => 'Gift', 'token' => 'tok-bbbbbbbb']), $now, $I, $C, TEST_EXCLUDED);
expect('remove: ok, change -1, reason kept', [$b['ok'], $b['result']['change'], $b['result']['after'], $b['logEntries'][0]['Reason']], [1, -1, 7, 'Gift']);
expect('remove: ids continue', $b['logEntries'][0]['LogID'], '2');
$b2 = merch_stock_apply_adjustment($rows1, $csv1, $log1, $req(['action' => 'remove', 'qty' => '1', 'token' => 'tok-cccccccc']), $now, $I, $C, TEST_EXCLUDED);
expect('remove: default reason', $b2['logEntries'][0]['Reason'], MERCH_STOCK_REMOVE_REASONS[0]);
$b3 = merch_stock_apply_adjustment($rows1, $csv1, $log1, $req(['action' => 'remove', 'qty' => '1', 'reason' => 'Because', 'token' => 'tok-dddddddd']), $now, $I, $C, TEST_EXCLUDED);
expect('remove: unknown reason refused', [$b3['ok'], $b3['code']], ['', 400]);
$b4 = merch_stock_apply_adjustment($rows1, $csv1, $log1, $req(['action' => 'remove', 'qty' => '9', 'token' => 'tok-eeeeeeee']), $now, $I, $C, TEST_EXCLUDED);
expect('remove below zero: refused 409, nothing to write', [$b4['ok'], $b4['code'], isset($b4['write']) ? 1 : 0], ['', 409, 0]);

// Validation.
foreach ([
    'unknown action' => ['action' => 'steal'],
    'short token' => ['token' => 'abc'],
    'bad item' => ['item' => 'Mystery'],
    'Stars & Stripes not stockable' => ['color' => 'Stars & Stripes (+$7)'],
    'Not applicable not stockable' => ['color' => 'Not applicable / no color choice'],
    'zero qty' => ['qty' => '0'],
    'negative qty' => ['qty' => '-2'],
    'decimal qty' => ['qty' => '1.5'],
    'huge qty' => ['qty' => '100'],
] as $label => $over) {
    $v = merch_stock_apply_adjustment($rows1, $csv1, $log1, $req($over + ['token' => $over['token'] ?? 'tok-vvvvvvvv']), $now, $I, $C, TEST_EXCLUDED);
    expect("validation: $label", $v['ok'], '');
}

// Count: logs the difference; equal count writes nothing.
$c = merch_stock_apply_adjustment($rows1, $csv1, $log1, $req(['action' => 'count', 'qty' => '6', 'token' => 'tok-ffffffff']), $now, $I, $C, TEST_EXCLUDED);
expect('count: delta, reason', [$c['result']['change'], $c['result']['before'], $c['result']['after'], $c['logEntries'][0]['Reason']], [-2, 8, 6, 'Count correction']);
$c0 = merch_stock_apply_adjustment($rows1, $csv1, $log1, $req(['action' => 'count', 'qty' => '8', 'token' => 'tok-gggggggg']), $now, $I, $C, TEST_EXCLUDED);
expect('count equal to current: ok, nothing written', [$c0['ok'], $c0['write']], [1, '']);
$cz = merch_stock_apply_adjustment($rows1, $csv1, $log1, $req(['action' => 'count', 'qty' => '0', 'token' => 'tok-hhhhhhhh']), $now, $I, $C, TEST_EXCLUDED);
expect('count to zero: cell goes blank', [$cz['ok'], $cz['result']['after'], $cz['stock']['Blade Holder']['#15 CM Blue'] ?? 0], [1, 0, 0]);

// Undo: only the latest page change, only while the count hasn't moved.
$u = merch_stock_apply_adjustment($rows1, $csv1, $log1, ['action' => 'undo', 'undoId' => '1', 'token' => 'tok-uuuuuuuu'], $now, $I, $C, TEST_EXCLUDED);
expect('undo latest: ok, reverses', [$u['ok'], $u['result']['change'], $u['result']['after'], $u['logEntries'][0]['Reason'], $u['logEntries'][0]['Ref']], [1, -3, 5, 'Undo of #1', '1']);
$logAfterUndo = array_merge($log1, $u['logEntries']);
$u2 = merch_stock_apply_adjustment($u['invRows'], $u['invCsv'], $logAfterUndo, ['action' => 'undo', 'undoId' => '2', 'token' => 'tok-uuuuuuu2'], $now, $I, $C, TEST_EXCLUDED);
expect('undo of an undo: refused', [$u2['ok'], $u2['code']], ['', 409]);
$u3 = merch_stock_apply_adjustment($u['invRows'], $u['invCsv'], $logAfterUndo, ['action' => 'undo', 'undoId' => '1', 'token' => 'tok-uuuuuuu3'], $now, $I, $C, TEST_EXCLUDED);
expect('undo of an older change: refused', [$u3['ok'], $u3['code']], ['', 409]);
// Count moved since (file edited so the cell no longer matches the last row's After) AND hash differs -> outside marker blocks it.
$moved = merch_stock_adjust_grid($rows1, 'Blade Holder', '#15 CM Blue', 1, $I, $C)['rows'];
$u4 = merch_stock_apply_adjustment($moved, merch_stock_grid_to_csv($moved), $log1, ['action' => 'undo', 'undoId' => '1', 'token' => 'tok-uuuuuuu4'], $now, $I, $C, TEST_EXCLUDED);
expect('undo across an outside edit: refused', [$u4['ok'], $u4['code']], ['', 409]);
// A pick-list pull can't be undone from here.
$shipLog = merch_stock_log_finish($log1, merch_stock_log_pull_entries(['Blade Holder' => ['#15 CM Blue' => 1]], ['Blade Holder' => ['#15 CM Blue' => 8]], ['Blade Holder' => ['#15 CM Blue' => 7]], 'order #5 (Mo)', $now, 'x'));
expect('pull entries: shape', [$shipLog[0]['Change'], $shipLog[0]['Before'], $shipLog[0]['After'], $shipLog[0]['Source'], $shipLog[0]['Reason']], ['-1', '8', '7', 'ship', 'Pulled for order #5 (Mo)']);
$shipLog[0]['FileHash'] = merch_stock_file_hash($csv1);
$u5 = merch_stock_apply_adjustment($rows1, $csv1, array_merge($log1, $shipLog), ['action' => 'undo', 'undoId' => (string) $shipLog[0]['LogID'], 'token' => 'tok-uuuuuuu5'], $now, $I, $C, TEST_EXCLUDED);
expect('undo of a pick-list pull: refused', [$u5['ok'], $u5['code']], ['', 409]);

// Outside edit: the file no longer matches the last row's hash -> marker is logged first and the file is snapshotted.
$outsideRows = [['Color', 'Blade Holder'], ['#15 CM Blue', '40']];
$o = merch_stock_apply_adjustment($outsideRows, merch_stock_grid_to_csv($outsideRows), $log1, $req(['token' => 'tok-oooooooo', 'qty' => '1']), $now, $I, $C, TEST_EXCLUDED);
expect('outside edit: marker first, then the change', [count($o['logEntries']), $o['logEntries'][0]['Source'], $o['logEntries'][0]['Reason'], $o['logEntries'][1]['Source']], [2, 'outside', MERCH_STOCK_OUTSIDE_REASON, 'page']);
expect('outside edit: ids run on', [$o['logEntries'][0]['LogID'], $o['logEntries'][1]['LogID']], ['2', '3']);
expect('outside edit: change applies to the file as it is now', [$o['result']['before'], $o['result']['after']], [40, 41]);
expect('outside edit: snapshot forced', $o['backupForce'], 1);
expect('no marker when the hash matches', merch_stock_log_outside_marker($log1, $csv1, $now), null);
expect('no marker on an empty log', merch_stock_log_outside_marker([], $csv1, $now), null);

// Log parsing/recent.
$raw = [MERCH_STOCK_LOG_COLUMNS, ['1', $now, 'Blade Holder', '#15 CM Blue', '3', '5', '8', 'Plate finished', 'page', '', 'tok', 'abc'], ['', '', '']];
$parsed = merch_stock_log_parse($raw);
expect('log parse: one row, keyed', [count($parsed), $parsed[0]['Item'], $parsed[0]['Change']], [1, 'Blade Holder', '3']);
expect('log recent: newest first', array_map(fn($r) => $r['LogID'], merch_stock_log_recent([['LogID' => '1'], ['LogID' => '2'], ['LogID' => '3']], 2)), ['3', '2']);

echo "\n";
if (!empty($failures)) {
    echo count($failures) . " failure(s):\n" . implode("\n", $failures) . "\n";
    exit(1);
}
echo "All merch_stock_edit tests passed.\n";
