<?php
// Build: 2026-10-07-A
// ============================================================
// Admin-only endpoint behind merch_stock_edit.php (the live inventory
// editor, Steve 2026-10-07). One request changes ONE item+color count in
// inventory.csv - add, remove, a physical recount, or Undo of the latest
// change - and appends what happened to inventory_log.csv.
//
// All the decisions (validation, below-zero refusal, duplicate tokens,
// outside-edit markers, undo rules) live in merch_stock_apply_adjustment()
// so tests/test_merch_stock_edit.php can drive them directly; this file is
// only the locking and the writing.
//
// Same write pattern as the other endpoints: admin + CSRF, release the
// session lock, 'c+' + flock(LOCK_EX) on inventory.csv (then the log - the
// lock order merch_stock_ship.php uses too, so the two can't deadlock),
// snapshot the old file (at most every 10 minutes - the log is the record
// of individual changes), rewrite, unlock. If the inventory write fails the
// old contents are put back and nothing is logged. If only the LOG append
// fails the count change stands (it already happened) and the reply says
// the history row is missing.
// ============================================================

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
header('Content-Type: application/json');
require __DIR__ . '/pricing.php';
require __DIR__ . '/print_plates.php';
require __DIR__ . '/merch_stock.php';

merch_require_admin_json();
merch_require_csrf_json();
session_write_close();

function merch_stock_adjust_fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$invFile = __DIR__ . '/inventory.csv';
$logFile = __DIR__ . '/inventory_log.csv';
$backupDir = __DIR__ . '/backups';

$ih = fopen($invFile, 'c+');
if (!$ih) {
    merch_stock_adjust_fail(500, 'Could not open inventory.csv.');
}
if (!flock($ih, LOCK_EX)) {
    fclose($ih);
    merch_stock_adjust_fail(500, 'Could not lock inventory.csv - try again.');
}
$lh = fopen($logFile, 'c+');
if (!$lh || !flock($lh, LOCK_EX)) {
    if ($lh) {
        fclose($lh);
    }
    flock($ih, LOCK_UN);
    fclose($ih);
    merch_stock_adjust_fail(500, 'Could not open the inventory log - try again.');
}
$bail = function (int $code, string $msg) use ($ih, $lh): void {
    flock($lh, LOCK_UN);
    fclose($lh);
    flock($ih, LOCK_UN);
    fclose($ih);
    merch_stock_adjust_fail($code, $msg);
};

// ---- Read both ---------------------------------------------------
$origInv = (string) stream_get_contents($ih);
rewind($ih);
$invRows = [];
while (($r = fgetcsv($ih, 0, ',', '"', '\\')) !== false) {
    $invRows[] = $r;
}
$logRows = merch_stock_log_read($lh);

// ---- Decide ------------------------------------------------------
$req = [
    'action' => $_POST['action'] ?? '',
    'item' => $_POST['item'] ?? '',
    'color' => $_POST['color'] ?? '',
    'qty' => $_POST['qty'] ?? '',
    'reason' => $_POST['reason'] ?? '',
    'token' => $_POST['token'] ?? '',
    'undoId' => $_POST['undoId'] ?? '',
];
foreach ($req as $k => $v) {
    if (!is_string($v)) {
        $bail(400, 'Invalid request.');
    }
    $req[$k] = trim($v);
}
$res = merch_stock_apply_adjustment($invRows, $origInv, $logRows, $req, date('Y-m-d H:i:s'), FILAMENT_COLOR_ITEMS, FILAMENT_COLORS, PRINT_PLATE_EXCLUDED_COLORS);
if (!$res['ok']) {
    $bail($res['code'], $res['error']);
}

$logWarning = false;
if ($res['write']) {
    merch_stock_backup_inventory_throttled($invFile, $backupDir, !empty($res['backupForce']));
    $okInv = rewind($ih) && ftruncate($ih, 0) && fwrite($ih, $res['invCsv']) !== false && fflush($ih);
    if (!$okInv) {
        rewind($ih);
        ftruncate($ih, 0);
        fwrite($ih, $origInv);
        fflush($ih);
        error_log('merch_stock_adjust: could not write inventory.csv - restored');
        $bail(500, 'Could not save inventory.csv - nothing was changed.');
    }
    // Append the history rows (header first when the log is new).
    $okLog = merch_stock_log_append($lh, $res['logEntries']);
    if (!$okLog) {
        $logWarning = true;
        error_log('merch_stock_adjust: inventory changed but inventory_log.csv could not be written');
    } else {
        $logRows = array_merge($logRows, $res['logEntries']);
    }
}

flock($lh, LOCK_UN);
fclose($lh);
flock($ih, LOCK_UN);
fclose($ih);

$view = merch_stock_log_view($logRows, 15);
echo json_encode([
    'ok' => true,
    'duplicate' => !empty($res['duplicate']),
    'result' => $res['result'],
    'stock' => (object) $res['stock'],
    'total' => merch_stock_total($res['stock']),
    'recent' => $view['recent'],
    'undoId' => $logWarning ? 0 : $view['undoId'],
    'logWarning' => $logWarning,
    'build' => '2026-10-07-A',
]);
