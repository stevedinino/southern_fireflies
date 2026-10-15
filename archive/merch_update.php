<?php
// Build: 2026-07-26-H
// Marks a single order's status, called via fetch() from ourmerch.php's
// checkboxes. Same admin session gate as the rest of the admin pages -
// this is not a public endpoint.
//
// Every status is now just ONE column (blank = not done, a date = done)
// - the separate 'x' marker columns (Invoiced, Paid) were removed
// 2026-07-26 since they just duplicated what the date column already
// says. Checking a box stamps today's date into that column;
// unchecking clears it.
//
// Two fields also CASCADE into another column, on Steve's instruction
// (2026-07-26) that you can't be paid before being invoiced, or ship
// something before it's created:
//   - Pymt Date  -> backfills/matches Invoice Date
//   - Fulfilled  -> backfills/matches Created
// "Matches" means: if the target column already has a date, the field
// being checked is set to THAT SAME date (not today) - e.g. if
// something was invoiced 3 days ago and you mark it Paid today, Pymt
// Date becomes that same 3-days-ago date, not today's. If the target
// column is still blank, it gets backfilled with today, and the field
// being checked matches that. Unchecking a field only clears its own
// column - it never un-invoices or un-creates anything.
//
// Invoice Date itself is NOT editable from here - it's only ever set
// by merch_invoice.php when an invoice email actually sends (which can
// affect several rows at once, for a combined invoice).

session_start();
header('Content-Type: application/json');

if (empty($_SESSION['sff_admin_ok'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    exit;
}

$csvFile = __DIR__ . '/merchandise.csv';

$orderId = isset($_POST['orderId']) ? trim($_POST['orderId']) : '';
$field = isset($_POST['field']) ? trim($_POST['field']) : '';
$checked = isset($_POST['checked']) && $_POST['checked'] === '1';

// Only these fields are editable from this endpoint - never allow an
// arbitrary column name in, even though this is session-gated. Value
// is either null (no cascade) or the name of the column to backfill/
// match when this field is checked.
$editableFields = [
    'Created' => null,
    'Fulfilled' => 'Created',
    'Pymt Date' => 'Invoice Date',
];
if (!array_key_exists($field, $editableFields) || $orderId === '' || !ctype_digit($orderId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}
$cascadeField = $editableFields[$field];

$handle = fopen($csvFile, 'c+');
if (!$handle) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not open file.']);
    exit;
}

// Locking matters here for the same reason it matters in merch_order.php:
// without it, an order submitted while this script is mid-read-modify-write
// could get silently dropped when this script writes its stale copy back.
if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not lock file - try again.']);
    exit;
}

$rows = [];
while (($row = fgetcsv($handle)) !== false) {
    $rows[] = $row;
}

if (empty($rows)) {
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'File is empty.']);
    exit;
}

$header = $rows[0];
// Same BOM issue as ourmerch.php - strip it before matching column names.
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}
$fieldIndex = array_search($field, $header, true);
$orderIdIndex = array_search('OrderID', $header, true);
$cascadeIndex = $cascadeField !== null ? array_search($cascadeField, $header, true) : null;

if ($fieldIndex === false || $orderIdIndex === false || ($cascadeField !== null && $cascadeIndex === false)) {
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Expected column not found in CSV header - has the header changed?']);
    exit;
}

$found = false;
$newValue = '';
$cascadeValue = null; // null = no cascade column involved; otherwise its resulting value
foreach ($rows as $i => &$row) {
    if ($i === 0) {
        continue; // header
    }
    if (isset($row[$orderIdIndex]) && $row[$orderIdIndex] === $orderId) {
        if ($checked) {
            if ($cascadeIndex !== false && $cascadeIndex !== null) {
                $existingCascadeDate = trim($row[$cascadeIndex] ?? '');
                if ($existingCascadeDate === '') {
                    // Cascade target not set yet - backfill it with
                    // today, and match this field to the same date.
                    $today = date('Y-m-d');
                    $row[$cascadeIndex] = $today;
                    $newValue = $today;
                } else {
                    // Cascade target already has a date - match it
                    // exactly rather than using today's date.
                    $newValue = $existingCascadeDate;
                }
                $cascadeValue = $row[$cascadeIndex];
            } else {
                $newValue = date('Y-m-d');
            }
        } else {
            // Unchecking only clears this field's own column - never
            // touches the cascade target.
            $newValue = '';
        }
        $row[$fieldIndex] = $newValue;
        $found = true;
        break;
    }
}
unset($row);

if (!$found) {
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Order not found - the page may be out of date, try refreshing.']);
    exit;
}

// Backup before writing - cheap insurance against a bug corrupting live
// order data. These will accumulate over time; safe to delete old ones
// by hand periodically, they're not read by anything. Create backups/
// if it doesn't exist yet - @copy() alone fails silently if the target
// directory is missing, which is why this folder was found empty
// (2026-07-26) despite this line having always been here.
$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}
if (is_dir($backupDir)) {
    @copy($csvFile, $backupDir . '/merchandise_' . date('Ymd_His') . '.csv');
}

rewind($handle);
ftruncate($handle, 0);
foreach ($rows as $row) {
    fputcsv($handle, $row, ",", '"', "\\");
}
fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);

echo json_encode([
    'ok' => true,
    'value' => $newValue,
    'cascadeField' => $cascadeField,
    'cascadeValue' => $cascadeValue,
    'build' => '2026-07-26-H',
]);
