<?php
// Build: 2026-09-20-A
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
//
// 2026-08-23: Cancelled joined as a third boolean field, same shape as
// Created/Fulfilled/Pymt Date, no cascade, no gating either direction -
// it can be set/cleared at any point in an order's life. This is now
// the one mechanism for both "customer cancelled the whole order" and
// "remove just this one line before invoicing" (Steve, 2026-08-23:
// same net effect as a hard delete of the row, minus the risk of one -
// nothing is destroyed, a mistaken check is just a click to undo). See
// ourmerch.php for how a cancelled row is hidden from view, and
// merch_invoice.php for how it's kept out of a future combined
// invoice.
//
// 2026-08-23: Invoice Date is now a FOURTH kind of field - a one-way
// "clear only" action, distinct from both the booleanFields and
// valueFields shapes above. It still can't be freely toggled on/off
// like Created/Fulfilled/Pymt Date (checking it here would fabricate
// an "invoiced" date without anything having actually been sent - see
// merch_invoice.php, the only legitimate place Invoice Date gets SET).
// But it can now be CLEARED, for Steve's "jumped the gun and hit Send
// Invoice before the customer's last item came in" case: clearing it
// lets a later real Send Invoice click combine this row with the rest
// of that customer's order instead of leaving it stranded with its own
// premature date. Guarded on Pymt Date being blank - see
// $clearOnlyFields below - since a row that's already been paid
// against might not match whatever the corrected total turns out to
// be, and that mismatch needs Steve's own judgment, not a button.
//
// 2026-09-20: Qty Created is a FIFTH kind of field - a per-order DELTA
// against a numeric running count, for print_plates.php's "Sort by
// Print Plate" checkboxes. Steve: "If I have a multi tool order (4 tape
// guns, for instance) and I have printed 1 but still need to create the
// other three, there's no way to tell the site I've done 1 of 4." A
// plate can span several orders at once (see print_plates.php), so
// checking one plate off needs to bump several different orders' Qty
// Created by different amounts in one request - not the single
// orderId/checked shape every other field above uses. $_POST['deltas']
// carries that as JSON: {"<orderId>": <signed int>, ...}. Each delta is
// added to that row's current Qty Created, clamped to [0, Quantity].
// Reaching Quantity stamps today's date into Created, same "blank vs.
// today" pattern as every other done-marker in this file - so a fully
// printed multi-unit row drops out of Needs Creating exactly the same
// way a single-unit row always has, no separate code path needed
// anywhere else. Dropping back below Quantity clears Created again,
// but ONLY if Fulfilled is still blank - once something's shipped,
// un-creating it from here would be a real data-integrity mess, not a
// harmless undo (matching the existing "you can't ship before you
// create" cascade rule elsewhere in this file). A row with
// Quantity <= 1 never reaches this field in practice (ourmerch.php only
// renders the print-plate checkboxes for multi-unit lines), but nothing
// here depends on that - it works the same either way.

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_backup.php';

header('Content-Type: application/json');

// Shared implementation in admin_guard.php as of 2026-08-20 (Finding
// 11, 2026-08-19 code review) - was previously duplicated here and in
// merch_invoice.php.
merch_require_admin_json();
// 2026-08-29 (Finding 9): ourmerch.php's/packing_slips.php's fetch()
// calls to this endpoint all carry MERCH_CSRF_TOKEN now - see
// csrf.php for why SameSite=Lax alone wasn't considered sufficient.
merch_require_csrf_json();

// 2026-08-31 (Steve, packing_slips.php slowness): PHP's default session
// handler holds an exclusive lock on the session FILE for as long as a
// script keeps $_SESSION open - normally released at script end. Two
// requests sharing one admin session (e.g. several fetch() calls fired
// at once from one browser tab) queue behind that lock and run one at a
// time no matter how fast each one actually is, on top of - not instead
// of - the flock() on merchandise.csv below. Nothing past this point
// reads or writes $_SESSION, so it's safe to release it here instead of
// holding it for the rest of this script.
session_write_close();

$csvFile = __DIR__ . '/merchandise.csv';

// 2026-09-20: Qty Created's per-order-delta shape doesn't fit the
// single orderId/checked parsing just below at all - branch it off
// first and exit, before any of that runs. See the file-level comment
// above and merch_update_apply_qty_created_deltas() further down.
if (isset($_POST['field']) && trim($_POST['field']) === 'Qty Created') {
    merch_update_apply_qty_created_deltas($csvFile);
    exit;
}

// 2026-08-31: orderId now accepts a comma-separated list, not just one
// ID - see the batched path near the bottom of this file for why
// (packing_slips.php's shipment checkboxes). A single ID (the only
// shape ourmerch.php ever sends) takes the exact same path/response
// shape as before this change - nothing about that behavior moves.
$orderIdsRaw = isset($_POST['orderId']) ? trim($_POST['orderId']) : '';
$orderIds = array_values(array_filter(
    array_map('trim', explode(',', $orderIdsRaw)),
    fn($id) => $id !== ''
));
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
    'Cancelled' => null,
];
// Color is the only value-based (non-checkbox) field right now - kept
// as its own small allowlist rather than folded into $booleanFields
// above, since it doesn't have a $checked/cascade shape at all.
$valueFields = ['Color'];
// One-way "clear" fields: never settable from here (checked=1 is
// refused below), only clearable (checked=0). Value is the name of a
// guard column that must be BLANK for the clear to proceed.
$clearOnlyFields = [
    'Invoice Date' => 'Pymt Date',
];

$isBooleanField = array_key_exists($field, $booleanFields);
$isValueField = in_array($field, $valueFields, true);
$isClearOnlyField = array_key_exists($field, $clearOnlyFields);

$orderIdsAllDigits = true;
foreach ($orderIds as $oneOrderId) {
    if (!ctype_digit($oneOrderId)) {
        $orderIdsAllDigits = false;
        break;
    }
}
if ((!$isBooleanField && !$isValueField && !$isClearOnlyField) || empty($orderIds) || !$orderIdsAllDigits) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}
// A clear-only field can only ever be unchecked from here - refuse a
// checked=1 outright rather than silently fabricating a value (see the
// file-level comment above for why Invoice Date can't just be set like
// a normal checkbox).
if ($isClearOnlyField && $checked) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'This field can only be cleared, not set, from here.']);
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
// Only needed for a clear-only field - the column that must be blank
// before the clear is allowed to proceed (see $clearOnlyFields above).
$clearGuardIndex = $isClearOnlyField ? array_search($clearOnlyFields[$field], $header, true) : null;

if ($fieldIndex === false || $orderIdIndex === false || ($cascadeField !== null && $cascadeIndex === false) || ($isValueField && $itemIndex === false) || ($isClearOnlyField && $clearGuardIndex === false)) {
    flock($handle, LOCK_UN);
    fclose($handle);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Expected column not found in CSV header - has the header changed?']);
    exit;
}

// 2026-08-31: the per-row update logic (cascade/color/clear-only) is
// unchanged from before this change - just factored out so the
// single-order path and the new batched path below can both use it
// without a second hand-maintained copy. Takes the matched row BY
// REFERENCE and mutates it in place (same as the old inline code did);
// returns what the caller needs to report back or bail out on.
$applyToRow = function (array &$row) use (
    $isClearOnlyField,
    $clearGuardIndex,
    $clearOnlyFields,
    $field,
    $isValueField,
    $itemIndex,
    $submittedValue,
    $checked,
    $cascadeIndex,
    $fieldIndex
): array {
    if ($isClearOnlyField && $clearGuardIndex !== null && trim($row[$clearGuardIndex] ?? '') !== '') {
        return ['ok' => false, 'error' => "Can't un-invoice - this order already shows a " . $clearOnlyFields[$field] . '. Sort out the payment by hand first.'];
    }

    $newValue = '';
    $cascadeValue = null; // null = no cascade column involved; otherwise its resulting value
    if ($isValueField) {
        // Color: only accept a value that's actually in the color list
        // THIS row's own item offers - e.g. a Circle Cutter Holder can
        // only be changed to a filament color (and not Rainbow, since
        // it's not RAINBOW_ELIGIBLE_ITEMS), a Logo Shirt only to a
        // Gildan color. Same allowlist principle as $booleanFields
        // above, just resolved per-row instead of being fixed ahead of
        // time.
        $rowItem = trim($row[$itemIndex] ?? '');
        $allowedColors = merch_color_options_for_item($rowItem);
        // An empty value is always accepted for a colorable item -
        // that's "clear the color choice," the same as how it never got
        // picked in the first place. It's deliberately NOT in
        // $allowedColors itself (that list is real chart colors only) -
        // checked here as its own explicit case instead.
        if (empty($allowedColors) || ($submittedValue !== '' && !in_array($submittedValue, $allowedColors, true))) {
            return ['ok' => false, 'error' => 'Not a valid color choice for this item.'];
        }
        $newValue = $submittedValue;
    } elseif ($checked) {
        if ($cascadeIndex !== false && $cascadeIndex !== null) {
            $existingCascadeDate = trim($row[$cascadeIndex] ?? '');
            if ($existingCascadeDate === '') {
                // Cascade target not set yet - backfill it with today,
                // and match this field to the same date.
                $today = date('Y-m-d');
                $row[$cascadeIndex] = $today;
                $newValue = $today;
            } else {
                // Cascade target already has a date - match it exactly
                // rather than using today's date.
                $newValue = $existingCascadeDate;
            }
            $cascadeValue = $row[$cascadeIndex];
        } else {
            $newValue = date('Y-m-d');
        }
    } else {
        // Unchecking (or clearing, for a clear-only field) only touches
        // this field's own column - never the cascade target, never any
        // other row.
        $newValue = '';
    }
    $row[$fieldIndex] = $newValue;
    return ['ok' => true, 'value' => $newValue, 'cascadeValue' => $cascadeValue];
};

if (count($orderIds) === 1) {
    // Single order - EXACT same behavior and response shape as before
    // this change. ourmerch.php only ever sends one OrderID, so nothing
    // here changes for it.
    $orderId = $orderIds[0];
    $found = false;
    $newValue = '';
    $cascadeValue = null;
    foreach ($rows as $i => &$row) {
        if ($i === 0) {
            continue; // header
        }
        if (isset($row[$orderIdIndex]) && $row[$orderIdIndex] === $orderId) {
            $result = $applyToRow($row);
            if (!$result['ok']) {
                flock($handle, LOCK_UN);
                fclose($handle);
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $result['error']]);
                exit;
            }
            $newValue = $result['value'];
            $cascadeValue = $result['cascadeValue'];
            $found = true;
            break;
        }
    }
    unset($row);

    if (!$found) {
        // 2026-09-14: logged rather than silently returned - this is the
        // one way a "Could not save" error can reach the browser for the
        // Fulfilled field specifically (applyToRow() never itself returns
        // ok:false for a plain boolean field), so if a row that genuinely
        // exists ever turns up "not found" here, this is the evidence
        // that will say why instead of leaving it a guess.
        error_log("merch_update.php: OrderID {$orderId} not found for field '{$field}' (rows read: " . count($rows) . ")");
        flock($handle, LOCK_UN);
        fclose($handle);
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Order not found - the page may be out of date, try refreshing.']);
        exit;
    }

    // Backup before writing - cheap insurance against a bug corrupting
    // live order data. Shared implementation in merch_backup.php as of
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
        'build' => '2026-08-31-A',
    ]);
    exit;
}

// 2026-08-31: batched path - packing_slips.php's "ready to pack"
// shipment checkboxes land here (2+ OrderIDs, one shipment = every item
// bound for one address). This used to be N separate requests, each
// doing its own full open/lock/backup/rewrite of merchandise.csv - on
// an 8-item shipment that's 8 full read-modify-write cycles back to
// back (serialized twice over: once by PHP's own session lock - see the
// session_write_close() note near the top of this file - and again by
// the flock() below). Looking every ID up against the SAME in-memory
// $rows and writing/backing-up ONCE for the whole batch, instead of
// once per ID, is the actual fix for that slowness.
//
// Every ID is attempted independently - one bad or not-found ID doesn't
// stop the others in the same shipment from being applied - and the
// response reports per-ID results so the caller can tell exactly which
// ones (if any) need a manual look, same as the old one-fetch-per-ID
// approach let it do.
$results = [];
$anyChanged = false;
foreach ($orderIds as $oneOrderId) {
    $found = false;
    $result = null;
    foreach ($rows as $i => &$row) {
        if ($i === 0) {
            continue; // header
        }
        if (isset($row[$orderIdIndex]) && $row[$orderIdIndex] === $oneOrderId) {
            $result = $applyToRow($row);
            $found = true;
            break;
        }
    }
    unset($row);

    if (!$found) {
        // 2026-09-14: same reasoning as the single-order path above -
        // applyToRow() never returns ok:false on its own for a plain
        // boolean field like Fulfilled, so "not found" is the only way
        // this batch can report a per-ID failure. Logged so a genuinely
        // unexpected miss (vs. a stale page listing an order that's
        // since been removed/renumbered) leaves real evidence.
        error_log("merch_update.php: OrderID {$oneOrderId} not found for field '{$field}' in batch [" . implode(',', $orderIds) . '] (rows read: ' . count($rows) . ')');
        $results[$oneOrderId] = ['ok' => false, 'error' => 'Order not found - the page may be out of date, try refreshing.'];
        continue;
    }
    if (!$result['ok']) {
        $results[$oneOrderId] = ['ok' => false, 'error' => $result['error']];
        continue;
    }
    $anyChanged = true;
    $results[$oneOrderId] = [
        'ok' => true,
        'value' => $result['value'],
        'cascadeField' => $cascadeField,
        'cascadeValue' => $result['cascadeValue'],
    ];
}

if ($anyChanged) {
    // Same backup-then-rewrite shared implementation as the single-order
    // path above - just called once for the whole batch instead of once
    // per ID.
    merch_backup_csv($csvFile, __DIR__ . '/backups');
    rewind($handle);
    ftruncate($handle, 0);
    foreach ($rows as $row) {
        fputcsv($handle, $row, ",", '"', "\\");
    }
    fflush($handle);
}
flock($handle, LOCK_UN);
fclose($handle);

echo json_encode([
    'ok' => true,
    'results' => $results,
    'build' => '2026-08-31-A',
]);

// 2026-09-20: handles the Qty Created field's per-order-delta shape -
// see the file-level comment above for why this doesn't fit
// $applyToRow()'s single orderId/checked model. Called (and the whole
// request ended) before any of the code above it ever runs, so this
// function does its own complete open/lock/read/write/unlock rather
// than sharing $handle/$rows with the rest of the file.
//
// $_POST['deltas'] is a JSON object, {"<orderId>": <signed int>, ...} -
// print_plates.php's plate breakdown ("this plate used 2 of Dale's, 1
// of Cindy's") turns directly into this shape client-side, so checking
// ONE plate off can touch several orders' Qty Created in a single
// request/backup/rewrite instead of one round-trip per order.
//
// Each order's delta is added to its current Qty Created and clamped to
// [0, Quantity]. Reaching Quantity stamps Created with today's date if
// it was blank (a fully-printed multi-unit row then drops out of Needs
// Creating exactly like any other Created row always has). Dropping
// back below Quantity clears Created again - but only when Fulfilled is
// still blank; an order already marked Fulfilled is refused outright
// (nothing about it is touched) rather than left with Fulfilled set but
// Created cleared, which nothing else in this codebase expects to see.
//
// Every order in the batch is attempted independently, same as the
// existing multi-ID path above - one bad/not-found/refused ID doesn't
// stop the others, and the response reports per-ID results so the
// caller (print_plates.php's checkbox handler) can tell the person
// exactly which one needs a manual look.
function merch_update_apply_qty_created_deltas(string $csvFile): void
{
    $deltasRaw = isset($_POST['deltas']) ? (string) $_POST['deltas'] : '';
    $deltas = json_decode($deltasRaw, true);
    if (!is_array($deltas) || empty($deltas)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
        return;
    }
    // Same allowlist spirit as the rest of this file - only real
    // digit-string order IDs with real integer deltas, never anything
    // looser, even though this endpoint is already session-gated.
    foreach ($deltas as $orderId => $delta) {
        if (!ctype_digit((string) $orderId) || !is_int($delta)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
            return;
        }
    }

    $handle = fopen($csvFile, 'c+');
    if (!$handle) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not open file.']);
        return;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not lock file - try again.']);
        return;
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
        return;
    }

    $header = $rows[0];
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    }
    $orderIdIndex = array_search('OrderID', $header, true);
    $quantityIndex = array_search('Quantity', $header, true);
    $qtyCreatedIndex = array_search('Qty Created', $header, true);
    $createdIndex = array_search('Created', $header, true);
    $fulfilledIndex = array_search('Fulfilled', $header, true);
    if ($orderIdIndex === false || $quantityIndex === false || $qtyCreatedIndex === false || $createdIndex === false || $fulfilledIndex === false) {
        flock($handle, LOCK_UN);
        fclose($handle);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Expected column not found in CSV header - has the header changed?']);
        return;
    }

    $results = [];
    $anyChanged = false;
    foreach ($deltas as $orderId => $delta) {
        $orderId = (string) $orderId;
        $found = false;
        foreach ($rows as $i => &$row) {
            if ($i === 0) {
                continue; // header
            }
            if (!isset($row[$orderIdIndex]) || $row[$orderIdIndex] !== $orderId) {
                continue;
            }
            $found = true;

            $quantity = max(1, (int) trim($row[$quantityIndex] ?? '1'));
            $currentQtyCreated = (int) trim($row[$qtyCreatedIndex] ?? '0');
            $newQtyCreated = max(0, min($quantity, $currentQtyCreated + $delta));
            $createdWasSet = trim($row[$createdIndex] ?? '') !== '';
            $fulfilledIsSet = trim($row[$fulfilledIndex] ?? '') !== '';

            if ($newQtyCreated < $quantity && $createdWasSet && $fulfilledIsSet) {
                // Would need to un-create an already-Fulfilled row -
                // refused outright, nothing about this order touched.
                $results[$orderId] = ['ok' => false, 'error' => 'Already marked Fulfilled - sort this one out by hand.'];
                break;
            }

            $row[$qtyCreatedIndex] = (string) $newQtyCreated;
            $newCreatedValue = $createdWasSet ? trim($row[$createdIndex]) : '';
            if ($newQtyCreated >= $quantity) {
                if (!$createdWasSet) {
                    $newCreatedValue = date('Y-m-d');
                    $row[$createdIndex] = $newCreatedValue;
                }
            } elseif ($createdWasSet) {
                // Dropping back below Quantity un-completes the row -
                // Fulfilled is confirmed blank above, so this is safe.
                $newCreatedValue = '';
                $row[$createdIndex] = '';
            }

            $anyChanged = true;
            $results[$orderId] = [
                'ok' => true,
                'qtyCreated' => $newQtyCreated,
                'quantity' => $quantity,
                'createdValue' => $newCreatedValue,
            ];
            break;
        }
        unset($row);

        if (!$found) {
            error_log("merch_update.php: OrderID {$orderId} not found for field 'Qty Created' in batch [" . implode(',', array_keys($deltas)) . '] (rows read: ' . count($rows) . ')');
            $results[$orderId] = ['ok' => false, 'error' => 'Order not found - the page may be out of date, try refreshing.'];
        }
    }

    if ($anyChanged) {
        merch_backup_csv($csvFile, __DIR__ . '/backups');
        rewind($handle);
        ftruncate($handle, 0);
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        fflush($handle);
    }
    flock($handle, LOCK_UN);
    fclose($handle);

    echo json_encode([
        'ok' => true,
        'results' => $results,
        'build' => '2026-09-20-A',
    ]);
}
