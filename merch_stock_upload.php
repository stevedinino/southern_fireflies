<?php
// Build: 2026-10-02-A
// ============================================================
// Admin-only endpoint behind merch_stock_report.php's "Replace inventory"
// box. Steve keeps his printed-parts inventory in an Excel sheet (parts
// down the side, colors across the top); he saves it as CSV and uploads
// it here, replacing inventory.csv wholesale. inventory.csv is live
// server state like merchandise.csv (gitignored, blocked from the web by
// .htaccess's *.csv rule) - a deploy must never overwrite it.
//
// The uploaded file is stored AS UPLOADED (after validation), not
// re-written from the parsed result: if a color in his sheet isn't on the
// site's list yet (e.g. "Copper"), the report keeps flagging it every
// time it loads instead of the piece quietly disappearing from the file.
//
// Same write pattern as every other write endpoint here: admin + CSRF,
// release the session lock, open 'c+', flock(LOCK_EX), back up the old
// file right before writing, truncate and rewrite, unlock. Backups go to
// backups/inventory_YmdHis.csv (own prefix/prune count - the shared
// merch_backup_csv() is hard-wired to merchandise_*.csv).
// ============================================================

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
header('Content-Type: application/json');
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_stock.php';

merch_require_admin_json();
merch_require_csrf_json();
session_write_close();

function merch_stock_upload_fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (!isset($_FILES['inventory']) || !is_array($_FILES['inventory']) || ($_FILES['inventory']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    merch_stock_upload_fail(400, 'No file received - choose your inventory CSV and try again.');
}
$tmp = $_FILES['inventory']['tmp_name'];
if (!is_uploaded_file($tmp)) {
    merch_stock_upload_fail(400, 'Invalid upload.');
}
$size = (int) ($_FILES['inventory']['size'] ?? 0);
// A real inventory grid is a few hundred bytes; this is just a sanity cap.
if ($size < 1 || $size > 200 * 1024) {
    merch_stock_upload_fail(400, 'That file is empty or far too large to be the inventory sheet.');
}
$contents = file_get_contents($tmp);
if ($contents === false || $contents === '') {
    merch_stock_upload_fail(400, 'Could not read the uploaded file.');
}
// Excel can save CSV as UTF-16 ("Unicode Text") - not a CSV we can parse.
if (strpos($contents, "\0") !== false) {
    merch_stock_upload_fail(400, "That doesn't look like a plain CSV (try File > Save As > \"CSV (Comma delimited)\", not \"Unicode Text\").");
}

// Parse the upload with the same function the report uses, so "accepted
// here" and "displays correctly there" can never disagree.
$rows = [];
$fh = fopen('php://temp', 'r+');
fwrite($fh, $contents);
rewind($fh);
while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
    $rows[] = $r;
}
fclose($fh);
$parsed = merch_stock_parse_grid($rows, FILAMENT_COLOR_ITEMS, FILAMENT_COLORS);
if ($parsed['columnsRecognized'] < 1 || $parsed['rowsRecognized'] < 1) {
    merch_stock_upload_fail(400, "That doesn't look like the inventory grid - no recognizable part rows and color columns were found, so the existing inventory was left untouched.");
}

$target = __DIR__ . '/inventory.csv';
$handle = fopen($target, 'c+');
if (!$handle) {
    merch_stock_upload_fail(500, 'Could not open inventory.csv.');
}
if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    merch_stock_upload_fail(500, 'Could not lock inventory.csv - try again.');
}

// Backup the existing file (if it has anything) right before overwriting.
if (filesize($target) > 0) {
    $backupDir = __DIR__ . '/backups';
    if (is_dir($backupDir) || mkdir($backupDir, 0755, true) || is_dir($backupDir)) {
        $dest = $backupDir . '/inventory_' . date('Ymd_His') . '.csv';
        if (!copy($target, $dest)) {
            error_log("merch_stock_upload: could not copy {$target} to {$dest}");
        } else {
            $old = glob($backupDir . '/inventory_*.csv') ?: [];
            if (count($old) > 30) {
                sort($old);
                foreach (array_slice($old, 0, count($old) - 30) as $f) {
                    if (!unlink($f)) {
                        error_log("merch_stock_upload: could not prune old backup {$f}");
                    }
                }
            }
        }
    } else {
        error_log("merch_stock_upload: could not create backup directory {$backupDir}");
    }
}

rewind($handle);
ftruncate($handle, 0);
fwrite($handle, $contents);
fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);

echo json_encode([
    'ok' => true,
    'pieces' => merch_stock_total($parsed['stock']),
    'unmatched' => $parsed['unmatched'],
    'warnings' => $parsed['warnings'],
    'build' => '2026-10-02-A',
]);
