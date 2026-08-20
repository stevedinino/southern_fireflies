<?php
// Build: 2026-08-20-B
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
//
// 2026-08-18: Color joined as a second kind of editable field, for
// customers who change their mind after ordering. It doesn't fit the
// checked/unchecked date pattern above - it takes a free-text VALUE
// instead - so it's handled as its own branch throughout rather than
// forced into the boolean shape. A submitted color is only ever
// accepted if it's actually in that order's OWN item's color list
// (merch_color_options_for_item() in pricing.php, the same list
// merch.php's request form itself would have offered) - never an
// arbitrary string, even though this endpoint is already session-
// gated. Color never cascades into another column.

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_backup.php';

header('Content-Type: application/json');

// Shared implementation in admin_guard.php as of 2026-08-20 (Finding
// 11, 2026-08-19 code review) - was previously duplicated here and in
// merch_invoice.php.
merch_require_admin_json();

$csvFile = __DIR__ . '/merchandise.csv';

$orderId = isset($_POST['orderId']) ? trim($_POST['orderId']) : '';
$field = isset($_POST['field']) ? trim($_POST['field']) : '';
$checked = isset($_POST['checked']) && $_POST['checked'] === '1';

// Only these fields are editable from this endpoint - never allow an
// arbitrary column name in, even though this is session-gated. Value
// is either null (no cascade) or the name of the column to backfill/
// match when this field is checked.
$booleanFields = [
    'Created' => null,
    'Fulfilled' => 'Created',
    'Pymt Date' => 'Invoice Date',
];
// Color is the only value-based (non-checkbox) field right now - kept
// as its own small allowlist rather than folded into $booleanFields
// above, since it doesn't have a $checked/cascade shape at all.
$valueFields = ['Color'];

$isBooleanField = array_key_exists($field, $booleanFields);
$isValueField = in_array($field, $valueFields, true);

if ((!$isBooleanField && !$isValueField) || $orderId === '' || !ctype_digit($orderId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}
$cascadeField = $isBooleanField ? $booleanFields[$field] : null;
// Only read/trimmed for a value field - $checked above already covers
// the boolean fields, so this stays null (and unused) for those.
$submittedValue = $isValueField ? trim((string) ($_POST['value'] ?? '')) : null;

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
// Only needed for Color - that's the one field whose valid values
// depend on ANOTHER column in the same row (what item was ordered).
$itemIndex = $isValueField ? array_search('Item', $header, true) : null;

if ($fieldIndex === false || $orderIdIndex === false || ($cascadeField !== null && $cascadeIndex === false) || ($isValueField && $itemIndex === false)) {
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
        if ($isValueField) {
            // Color: only accept a value that's actually in the color
            // list THIS row's own item offers - e.g. a Circle Cutter
            // Holder can only be changed to a filament color (and not
            // Rainbow, since it's not RAINBOW_ELIGIBLE_ITEMS), a Logo
            // Shirt only to a Gildan color. Same allowlist principle as
            // $booleanFields above, just resolved per-row instead of
            // being fixed ahead of time.
            $rowItem = trim($row[$itemIndex] ?? '');
            $allowedColors = merch_color_options_for_item($rowItem);
            // An empty value is always accepted for a colorable item -
            // that's "clear the color choice," the same as how it never
            // got picked in the first place. It's deliberately NOT in
            // $allowedColors itself (that list is real chart colors
            // only) - checked here as its own explicit case instead.
            if (empty($allowedColors) || ($submittedValue !== '' && !in_array($submittedValue, $allowedColors, true))) {
                flock($handle, LOCK_UN);
                fclose($handle);
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Not a valid color choice for this item.']);
                exit;
            }
            $newValue = $submittedValue;
        } elseif ($checked) {
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
// order data. Shared implementation in merch_backup.php as of
// 2026-08-20 (Findings 12 and 14, 2026-08-19 code review) - failures
// are now logged instead of silently swallowed, and old backups are
// pruned instead of accumulating forever.
merch_backup_csv($csvFile, __DIR__ . '/backups');

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
    'build' => '2026-08-20-A',
]);
