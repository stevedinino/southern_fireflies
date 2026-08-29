<?php
// Build: 2026-08-29-A
// Admin-triggered: given a comma-separated list of anchor OrderIDs
// (one per customer group Steve left checked on merch_reminders.php's
// preview list), sends a gentle "you still owe for this" reminder
// email to each one via merch_notify.php's merch_send_payment_reminder()
// and reports back which sent/skipped/failed. Same admin+CSRF session
// gate as merch_update.php/merch_invoice.php - not a public endpoint.
//
// Read-only with respect to merchandise.csv end to end - no flock/
// write phase at all, unlike merch_invoice.php. A reminder causes no
// state change (see merch_reminder_groups.php's header comment for
// why: no dollar amount, no column ever gets stamped), so there's
// nothing here that needs the lock-then-send-then-relock dance that
// file uses to avoid blocking merch_order.php during a slow SMTP call.
//
// Every group is RE-DERIVED here from a fresh CSV read, keyed only by
// the anchor OrderID the browser said was checked - nothing about a
// group's actual contents (items, name, email) is trusted from the
// client. See merch_reminder_groups.php's merch_reminder_group_for_anchor()
// for the full reasoning: this means a stale preview page (a row got
// paid, cancelled, or edited to Pickup between when the list was drawn
// and when Send was clicked) naturally self-corrects - that anchor is
// just skipped, not sent incorrectly - rather than needing the browser
// to somehow know the CSV changed underneath it.

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
header('Content-Type: application/json');
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_notify.php';
require __DIR__ . '/merch_shipments.php';
require __DIR__ . '/merch_reminder_groups.php';
require_once __DIR__ . '/strings.php';

// Shared implementation in admin_guard.php as of 2026-08-20 (Finding
// 11, 2026-08-19 code review).
merch_require_admin_json();
// 2026-08-29 (Finding 9): same CSRF gate as every other state-changing
// (or, here, email-sending) admin endpoint - see csrf.php.
merch_require_csrf_json();

$anchorIdsRaw = isset($_POST['anchorOrderIds']) ? trim($_POST['anchorOrderIds']) : '';
if ($anchorIdsRaw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No reminders selected.']);
    exit;
}

$anchorIds = array_values(array_filter(array_map('trim', explode(',', $anchorIdsRaw)), fn($id) => $id !== ''));
if (empty($anchorIds)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No reminders selected.']);
    exit;
}
foreach ($anchorIds as $id) {
    if (!ctype_digit($id)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
        exit;
    }
}

$csvFile = __DIR__ . '/merchandise.csv';
// Read-only - see header comment above for why no lock/write phase
// exists here at all, same as merch_paid_receipt.php.
$loaded = merch_load_csv($csvFile, 'merchandise.csv');
$rows = $loaded['rows'];
$col = merch_csv_column_map(
    $loaded['header'],
    merch_reminder_required_columns(),
    ['OrderID', 'Fulfillment', 'Email', 'Invoice Date', 'Pymt Date'],
    'merchandise.csv'
);

$results = [];
$sentCount = 0;
foreach ($anchorIds as $anchorId) {
    $group = merch_reminder_group_for_anchor($rows, $col, $anchorId);
    if ($group === null) {
        $results[] = [
            'anchorOrderId' => $anchorId,
            'ok' => false,
            'skipped' => true,
            'name' => null,
            'error' => 'No longer eligible - it may have been paid, cancelled, or changed to Pickup since this list loaded.',
        ];
        continue;
    }

    $itemLines = merch_reminder_format_item_lines($group['items']);
    $sendResult = merch_send_payment_reminder($itemLines, $group['name'], $group['email']);

    $results[] = [
        'anchorOrderId' => $anchorId,
        'ok' => $sendResult['sent'],
        'skipped' => false,
        'name' => $group['name'],
        'error' => $sendResult['sent'] ? '' : $sendResult['error'],
    ];
    if ($sendResult['sent']) {
        $sentCount++;
    }
}

echo json_encode([
    'ok' => true,
    'sentCount' => $sentCount,
    'results' => $results,
]);
