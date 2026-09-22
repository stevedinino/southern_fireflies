<?php
// Build: 2026-09-20-A
// ============================================================
// Direct tests for merch_update.php's Qty Created delta handler
// (merch_update_apply_qty_created_deltas()) - clamping, the Created
// cascade in both directions, and the Fulfilled guard. Runs against a
// scratch CSV copy, never the real merchandise.csv. Run from anywhere:
//
//     php tests/test_qty_created_deltas.php
//
// This can't just `require` merch_update.php directly - that file runs
// its admin/CSRF/session guards and the rest of its request-handling
// flow the moment it's loaded, which this test deliberately isn't
// set up for (no real HTTP request, no admin session). Instead it
// pulls the one function under test out of the real file at run time
// (so this test can never silently drift from what's actually
// deployed) and evals just that, with merch_backup_csv() stubbed out
// so no real backup file gets written for a throwaway scratch CSV.
// ============================================================

error_reporting(E_ALL);

$source = file_get_contents(dirname(__DIR__) . '/merch_update.php');
if (!preg_match('/^function merch_update_apply_qty_created_deltas.*^}/ms', $source, $m)) {
    fwrite(STDERR, "Could not find merch_update_apply_qty_created_deltas() in merch_update.php - test is out of sync with the source.\n");
    exit(1);
}
function merch_backup_csv(string $csvFile, string $dir): void
{
    // no-op for this test - the real implementation is exercised by
    // whatever already backs up the live merchandise.csv.
}
eval($m[0]);

$failures = [];
function expect(string $label, $got, $want): void
{
    global $failures;
    if ((string) $got !== (string) $want) {
        $failures[] = "[$label] expected '$want', got '$got'";
        echo "FAIL  $label: expected '$want', got '$got'\n";
    } else {
        echo "  ok  $label\n";
    }
}

$scratchCsv = sys_get_temp_dir() . '/qty_created_test_' . uniqid() . '.csv';
function writeScratchCsv(string $path, array $rows): void
{
    $h = fopen($path, 'w');
    foreach ($rows as $row) {
        fputcsv($h, $row, ',', '"', '\\');
    }
    fclose($h);
}
function readScratchCsv(string $path): array
{
    $h = fopen($path, 'r');
    $rows = [];
    while (($row = fgetcsv($h)) !== false) {
        $rows[] = $row;
    }
    fclose($h);
    return $rows;
}

$header = ['OrderID', 'Name', 'Item', 'Quantity', 'Created', 'Fulfilled', 'Qty Created'];
// 556: 4 Tape Gun Holders, none printed yet.
// 692: 1 Circle Cutter Holder, none printed yet (Quantity 1 case).
// 700: 3 Rectangle Cutter Holders, already 2 printed, not yet Created.
// 800: 2 Hearts Cutter Holders, already fully created+fulfilled.
$rows = [
    $header,
    ['556', 'Dale Monnier', 'Tape Gun Holder', '4', '', '', ''],
    ['692', 'Cindy Morgan', 'Circle Cutter Holder', '1', '', '', ''],
    ['700', 'Solo Order', 'Rectangle Cutter Holder', '3', '', '', '2'],
    ['800', 'Shipped Order', 'Hearts Cutter Holder', '2', '2026-09-01', '2026-09-05', '2'],
];

// ---- +1 on a fresh multi-unit row, not yet complete -------------------
writeScratchCsv($scratchCsv, $rows);
ob_start();
$_POST = ['deltas' => json_encode(['556' => 1])];
merch_update_apply_qty_created_deltas($scratchCsv);
$resp = json_decode(ob_get_clean(), true);
expect('+1 of 4: ok', $resp['ok'] ?? null, true);
expect('+1 of 4: qtyCreated', $resp['results']['556']['qtyCreated'] ?? null, 1);
expect('+1 of 4: not yet Created', $resp['results']['556']['createdValue'] ?? null, '');
$after = readScratchCsv($scratchCsv);
expect('+1 of 4: CSV Qty Created column', $after[1][6], '1');
expect('+1 of 4: CSV Created column still blank', $after[1][4], '');

// ---- reaching Quantity stamps Created ---------------------------------
writeScratchCsv($scratchCsv, $rows);
$_POST = ['deltas' => json_encode(['556' => 4])];
ob_start();
merch_update_apply_qty_created_deltas($scratchCsv);
$resp = json_decode(ob_get_clean(), true);
expect('all 4 of 4: qtyCreated clamped to Quantity', $resp['results']['556']['qtyCreated'] ?? null, 4);
expect('all 4 of 4: Created stamped', $resp['results']['556']['createdValue'] ?? '', date('Y-m-d'));
$after = readScratchCsv($scratchCsv);
expect('all 4 of 4: CSV Created column stamped', $after[1][4], date('Y-m-d'));

// ---- over-delta clamps at Quantity, never goes past it -----------------
writeScratchCsv($scratchCsv, $rows);
$_POST = ['deltas' => json_encode(['556' => 99])];
ob_start();
merch_update_apply_qty_created_deltas($scratchCsv);
$resp = json_decode(ob_get_clean(), true);
expect('delta way over: clamped at Quantity (4)', $resp['results']['556']['qtyCreated'] ?? null, 4);

// ---- a Quantity=1 row behaves the same as any multi-unit row ----------
writeScratchCsv($scratchCsv, $rows);
$_POST = ['deltas' => json_encode(['692' => 1])];
ob_start();
merch_update_apply_qty_created_deltas($scratchCsv);
$resp = json_decode(ob_get_clean(), true);
expect('single-unit row: qtyCreated reaches 1', $resp['results']['692']['qtyCreated'] ?? null, 1);
expect('single-unit row: Created stamped', $resp['results']['692']['createdValue'] ?? '', date('Y-m-d'));

// ---- -1 on a partially-printed row (2 of 3), un-checking a mistake -----
writeScratchCsv($scratchCsv, $rows);
$_POST = ['deltas' => json_encode(['700' => -1])];
ob_start();
merch_update_apply_qty_created_deltas($scratchCsv);
$resp = json_decode(ob_get_clean(), true);
expect('undo 1 of 2: qtyCreated drops to 1', $resp['results']['700']['qtyCreated'] ?? null, 1);
expect('undo 1 of 2: never goes below 0', ($resp['ok'] ?? false) ? 'ok' : 'not ok', 'ok');

// ---- dropping below Quantity clears an already-set Created ------------
$rowsAtFull = $rows;
$rowsAtFull[3] = ['700', 'Solo Order', 'Rectangle Cutter Holder', '3', '2026-09-10', '', '3']; // fully created, not yet fulfilled
writeScratchCsv($scratchCsv, $rowsAtFull);
$_POST = ['deltas' => json_encode(['700' => -1])];
ob_start();
merch_update_apply_qty_created_deltas($scratchCsv);
$resp = json_decode(ob_get_clean(), true);
expect('un-complete: qtyCreated drops to 2', $resp['results']['700']['qtyCreated'] ?? null, 2);
expect('un-complete: Created cleared', $resp['results']['700']['createdValue'] ?? 'MISSING', '');
$after = readScratchCsv($scratchCsv);
expect('un-complete: CSV Created column cleared', $after[3][4], '');

// ---- clamping never drops below 0 --------------------------------------
writeScratchCsv($scratchCsv, $rows);
$_POST = ['deltas' => json_encode(['700' => -99])];
ob_start();
merch_update_apply_qty_created_deltas($scratchCsv);
$resp = json_decode(ob_get_clean(), true);
expect('huge negative delta: clamped at 0', $resp['results']['700']['qtyCreated'] ?? null, 0);

// ---- an already-Fulfilled order refuses the decrement entirely --------
writeScratchCsv($scratchCsv, $rows);
$_POST = ['deltas' => json_encode(['800' => -1])];
ob_start();
merch_update_apply_qty_created_deltas($scratchCsv);
$resp = json_decode(ob_get_clean(), true);
expect('fulfilled order: this order refused', $resp['results']['800']['ok'] ?? null, false);
$after = readScratchCsv($scratchCsv);
expect('fulfilled order: CSV Qty Created untouched', $after[4][6], '2');
expect('fulfilled order: CSV Created untouched', $after[4][4], '2026-09-01');

// ---- a batch spanning multiple orders applies each independently ------
writeScratchCsv($scratchCsv, $rows);
$_POST = ['deltas' => json_encode(['556' => 2, '692' => 1])];
ob_start();
merch_update_apply_qty_created_deltas($scratchCsv);
$resp = json_decode(ob_get_clean(), true);
expect('batch: order 556 result', $resp['results']['556']['qtyCreated'] ?? null, 2);
expect('batch: order 692 result', $resp['results']['692']['qtyCreated'] ?? null, 1);
$after = readScratchCsv($scratchCsv);
expect('batch: CSV row for 556', $after[1][6], '2');
expect('batch: CSV row for 692', $after[2][6], '1');

// ---- an unknown order ID reports not-found without touching the file --
writeScratchCsv($scratchCsv, $rows);
$_POST = ['deltas' => json_encode(['999999' => 1])];
ob_start();
merch_update_apply_qty_created_deltas($scratchCsv);
$resp = json_decode(ob_get_clean(), true);
expect('unknown order: reported not found', $resp['results']['999999']['ok'] ?? null, false);

// ---- malformed input is rejected outright ------------------------------
writeScratchCsv($scratchCsv, $rows);
$_POST = ['deltas' => 'not json'];
ob_start();
merch_update_apply_qty_created_deltas($scratchCsv);
$resp = json_decode(ob_get_clean(), true);
expect('malformed deltas JSON: rejected', $resp['ok'] ?? null, false);

$_POST = ['deltas' => json_encode(['556' => 'not-an-int'])];
ob_start();
merch_update_apply_qty_created_deltas($scratchCsv);
$resp = json_decode(ob_get_clean(), true);
expect('non-integer delta: rejected', $resp['ok'] ?? null, false);

@unlink($scratchCsv);

// ---- Verdict ---------------------------------------------------------
if ($failures) {
    echo 'FAIL - ' . count($failures) . " assertion(s) failed.\n";
    exit(1);
}
echo "PASS - all Qty Created delta assertions matched.\n";
exit(0);
