<?php
// Build: 2026-08-11-C
// Admin-triggered: given ONE OrderID (the row the button was clicked
// on), finds every OTHER not-yet-invoiced order from the same email
// address that pays to the same account (printed vs. shop items) and
// uses the same fulfillment method, combines them into one invoice
// email using merch_group_calculate(), sends it, then stamps every
// included row's Invoice Date column with today's date so it won't be
// picked up again. Same admin session gate as merch_update.php - not
// a public endpoint.
//
// 2026-08-10: accepts an optional manualShipping POST field. When the
// group's shipping can't be auto-priced (AK/HI/Canada, an order combo
// outside the auto tiers), the first request (no manualShipping) comes
// back with needsManualQuote + the group's own items/subtotal/tax so
// ourmerch.php can show them inline. Steve works out the real shipping
// cost by hand and resubmits with manualShipping set, which replaces
// the (null) auto-calculated shipping and lets the exact same
// combine-price-email-stamp flow finish the job - no more downloading
// merchandise.csv, hand-editing Invoice Date, and re-uploading it.
//
// IMPORTANT: the file lock is released BEFORE the email send, not
// held across it. SMTP is a slow network round trip (worse during any
// throttling like the iPage incident), and merch_order.php needs an
// exclusive lock to write new customer orders - if this script held
// its lock through a slow send, every new order submission would hang
// waiting for it, which is exactly what caused real site outages
// (found 2026-07-26). Fixed by splitting this into three phases:
// read+compute (locked), send email (NOT locked), stamp result
// (locked again, briefly, by OrderID rather than array index in case
// the file changed in between).

session_start();
header('Content-Type: application/json');
require __DIR__ . '/config.php';
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_notify.php';
require_once __DIR__ . '/strings.php';

/**
 * Stamps today's date into Invoice Date for every OrderID in
 * $groupOrderIds. Extracted 2026-08-10 so both the email-invoice path
 * and the pickup-document path (which never sends an email at all)
 * can share the exact same lock-safe, backed-up write instead of two
 * copies of this drifting apart over time. Same three-phase-lock
 * discipline as the rest of this file - only ever called AFTER
 * whatever the "real" action was (send email, or generate the pickup
 * doc) has already succeeded, never before.
 *
 * Returns ['ok' => true, 'invoicedOrderIds' => [...], 'invoicedDate' => '...']
 * or ['ok' => false, 'error' => '...'] - callers still respond to the
 * browser either way (a failed stamp doesn't mean the invoice/document
 * didn't happen, just that the CSV needs a manual look).
 */
function merch_invoice_stamp_invoice_date(string $csvFile, array $groupOrderIds): array
{
    $handle = fopen($csvFile, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) fclose($handle);
        return ['ok' => false, 'error' => 'Could not re-open the file to mark Invoice Date - please check merchandise.csv by hand for OrderID(s): ' . implode(', ', $groupOrderIds)];
    }

    $freshRows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $freshRows[] = $row;
    }

    $freshHeader = $freshRows[0] ?? [];
    if (isset($freshHeader[0])) {
        $freshHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', $freshHeader[0]);
    }
    $freshOrderIdIdx = array_search('OrderID', $freshHeader, true);
    $freshInvoiceDateIdx = array_search('Invoice Date', $freshHeader, true);

    $invoicedDate = date('Y-m-d');
    $invoicedOrderIds = [];
    if ($freshOrderIdIdx !== false && $freshInvoiceDateIdx !== false) {
        foreach ($freshRows as $i => &$row) {
            if ($i === 0) continue;
            if (in_array($row[$freshOrderIdIdx] ?? null, $groupOrderIds, true)) {
                $row[$freshInvoiceDateIdx] = $invoicedDate;
                $invoicedOrderIds[] = $row[$freshOrderIdIdx];
            }
        }
        unset($row);

        // Backup before writing. Create backups/ if it doesn't exist yet
        // instead of silently doing nothing - @copy() alone fails quietly
        // if the target directory is missing, which is why the backups
        // folder was empty (found 2026-07-26).
        $backupDir = __DIR__ . '/backups';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }
        if (is_dir($backupDir)) {
            @copy($csvFile, $backupDir . '/merchandise_' . date('Ymd_His') . '.csv');
        }

        rewind($handle);
        ftruncate($handle, 0);
        foreach ($freshRows as $row) {
            fputcsv($handle, $row, ",", '"', "\\");
        }
        fflush($handle);
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return ['ok' => true, 'invoicedOrderIds' => $invoicedOrderIds, 'invoicedDate' => $invoicedDate];
}

if (empty($_SESSION['sff_admin_ok'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    exit;
}

$csvFile = __DIR__ . '/merchandise.csv';

$orderId = isset($_POST['orderId']) ? trim($_POST['orderId']) : '';
if ($orderId === '' || !ctype_digit($orderId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

// Manual shipping override, used when merch_group_calculate() can't
// auto-price the shipping (AK/HI/Canada, an order combo outside the
// auto tiers, etc). Steve works out the real shipping cost himself and
// types it in from the inline form ourmerch.php shows once a plain
// "Send Invoice" click comes back needing one - see the manualShipping
// handling below, right after pricing is calculated. Optional: when
// absent, behavior is 100% unchanged from before this existed.
$manualShipping = null;
if (isset($_POST['manualShipping']) && trim($_POST['manualShipping']) !== '') {
    $manualShippingRaw = trim($_POST['manualShipping']);
    if (!is_numeric($manualShippingRaw) || (float) $manualShippingRaw < 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Shipping amount must be a non-negative number.']);
        exit;
    }
    $manualShipping = round((float) $manualShippingRaw, 2);
}

// ---- Phase 1: locked just long enough to read + figure out the group ----
$handle = fopen($csvFile, 'c+');
if (!$handle) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not open file.']);
    exit;
}

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
flock($handle, LOCK_UN);
fclose($handle);

if (empty($rows)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'File is empty.']);
    exit;
}

$header = $rows[0];
// Same BOM issue as ourmerch.php/merch_update.php.
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}

$col = [];
foreach (['OrderID', 'Item', 'Quantity', 'Name', 'Fulfillment', 'Email', 'Color', 'Size', 'Sleeve', 'Invoice Date'] as $name) {
    $col[$name] = array_search($name, $header, true);
}
if ($col['OrderID'] === false || $col['Invoice Date'] === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Expected column not found - has the CSV header changed? (Does it have an Invoice Date column?)']);
    exit;
}

// Find the row the button was clicked on.
$anchor = null;
foreach ($rows as $i => $row) {
    if ($i === 0) continue;
    if (($row[$col['OrderID']] ?? '') === $orderId) {
        $anchor = $row;
        break;
    }
}
if ($anchor === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Order not found - the page may be out of date, try refreshing.']);
    exit;
}
if (trim($anchor[$col['Invoice Date']] ?? '') !== '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'This order was already invoiced.']);
    exit;
}

$anchorEmail = strtolower(trim($anchor[$col['Email']] ?? ''));
$anchorName = strtolower(trim($anchor[$col['Name']] ?? ''));
$anchorFulfillment = trim($anchor[$col['Fulfillment']] ?? '');
$anchorIsPrinted = merch_is_printed_item(trim($anchor[$col['Item']] ?? ''));

// Legacy rows submitted before Email was a required field all have a
// blank Email - matching on blank === blank used to silently combine
// EVERY such customer's orders into one another, regardless of who
// they actually were (found 2026-08-11: a 26-line, $359 "invoice"
// combining ~10 different real customers who each just happened to
// have left Email blank). When the anchor's email is blank, fall back
// to matching by Name instead - not perfect (a genuine name typo means
// two orders from the same person won't combine; two different real
// people who happen to share an exact name string would incorrectly
// combine), but it's the only identity signal this legacy data has,
// and it's a real fix for the actual bug: unrelated customers no
// longer get swept together just because both left Email blank. A
// blank name matching another blank name is still never allowed -
// that's fully anonymous data with zero identity signal.
$groupByName = ($anchorEmail === '');

// Gather every not-yet-invoiced row matching: same identity (email,
// or name as a fallback for legacy blank-email rows - see above), same
// fulfillment method, same payment-account type. Track by OrderID
// (not array index) since the file could change between phase 1 and
// phase 3 now that the lock isn't held continuously.
$groupOrderIds = [];
$items = [];
foreach ($rows as $i => $row) {
    if ($i === 0) continue;
    $email = strtolower(trim($row[$col['Email']] ?? ''));
    $name = strtolower(trim($row[$col['Name']] ?? ''));
    $fulfillment = trim($row[$col['Fulfillment']] ?? '');
    $item = trim($row[$col['Item']] ?? '');
    $invoiced = trim($row[$col['Invoice Date']] ?? '');

    $identityMatches = $groupByName
        ? ($name !== '' && $name === $anchorName)
        : ($email === $anchorEmail);

    if ($identityMatches
        && $fulfillment === $anchorFulfillment
        && merch_is_printed_item($item) === $anchorIsPrinted
        && $invoiced === '') {
        $groupOrderIds[] = $row[$col['OrderID']];
        $items[] = [
            'item' => $item,
            'quantity' => (int)($row[$col['Quantity']] ?? 1),
            'size' => trim($row[$col['Size']] ?? ''),
            'sleeve' => trim($row[$col['Sleeve']] ?? ''),
            'color' => trim($row[$col['Color']] ?? ''),
        ];
    }
}

$isShipping = ($anchorFulfillment === 'Ship');
$pricing = merch_group_calculate($items, $isShipping, $anchorIsPrinted);
if ($pricing === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not price one of the items - check the CSV for a typo in an Item value.']);
    exit;
}

// Manual override, if one was given: replaces the auto-calculated
// shipping (which may be null - that's exactly the case this exists
// for) with Steve's real number, and recomputes the total to match.
// Everything downstream - merch_send_notification()'s routing,
// the email content, the Invoice Date stamping below - already
// handles this correctly once $pricing['shipping'] is a real number
// instead of null, with no further special-casing needed.
if ($manualShipping !== null) {
    $pricing['shipping'] = $manualShipping;
    $pricing['shippingNote'] = '';
    $pricing['total'] = $pricing['subtotal'] + $pricing['tax'] + $pricing['shipping'];
}

$name = trim($anchor[$col['Name']] ?? '');
$email = trim($anchor[$col['Email']] ?? '');

// ---- Pickup orders: no email at all, generate a printable document ----
// instead. Added 2026-08-10 - pickup customers pay cash/check in
// person at the retreat, so the normal invoice email (which points
// them at Venmo/PayPal) never made sense for this fulfillment type.
// This never sends anything; it renders strings/shipping/pickup-
// invoice-template.md with the group's real order details and hands
// the result back to the browser as a downloadable file, then stamps
// Invoice Date the same as a real sent invoice would - Janet can print
// it and tape it to the item instead of a hand-scribbled Post-it.
if (!$isShipping) {
    $money = fn($n) => '$' . number_format((float) $n, 2);
    $lineItemsText = '';
    // $items and $pricing['lines'] are built from the same source array
    // in the same order (merch_group_calculate() does a straight 1:1
    // foreach with no skipping/reordering), so it's safe to zip them by
    // index here - $pricing['lines'] doesn't carry color/size/sleeve
    // (used elsewhere too, e.g. the real invoice email, where it was
    // never needed before), but $items always has them.
    foreach ($pricing['lines'] as $i => $line) {
        $qtyLabel = $line['quantity'] > 1 ? " (x{$line['quantity']})" : '';
        $details = [];
        if (!empty($items[$i]['color'])) $details[] = $items[$i]['color'];
        if (!empty($items[$i]['size'])) $details[] = $items[$i]['size'];
        if (!empty($items[$i]['sleeve'])) $details[] = $items[$i]['sleeve'];
        $detailLabel = $details ? ' - ' . implode(', ', $details) : '';
        $lineItemsText .= '- ' . $line['item'] . $qtyLabel . $detailLabel . ': ' . $money($line['lineSubtotal']) . "\n";
    }

    $document = merch_load_string('shipping/pickup-invoice-template', [
        'name' => $name,
        'date' => date('Y-m-d'),
        'orderIds' => implode(', ', $groupOrderIds),
        'lineItemsText' => rtrim($lineItemsText),
        'subtotal' => $money($pricing['subtotal']),
        'tax' => $money($pricing['tax']),
        'total' => $money($pricing['total']),
    ]);

    $stampResult = merch_invoice_stamp_invoice_date($csvFile, $groupOrderIds);
    if (!$stampResult['ok']) {
        // The document itself is fine - it just hasn't been generated
        // from a "committed" state yet. Safe to surface as a real
        // error since, unlike an email, nothing has gone out that
        // can't be retried cleanly.
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $stampResult['error']]);
        exit;
    }

    $safeNameForFilename = preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
    echo json_encode([
        'ok' => true,
        'invoicedOrderIds' => $stampResult['invoicedOrderIds'],
        'total' => $pricing['total'],
        'invoicedDate' => $stampResult['invoicedDate'],
        'downloadDocument' => $document,
        'downloadFilename' => "pickup-invoice-{$safeNameForFilename}-{$stampResult['invoicedDate']}.txt",
    ]);
    exit;
}

// Ship orders with no email on file (same legacy pre-required-field
// data as the grouping fix above) can't be invoiced by any means this
// system has - there's no address to send to. Fails loud and specific
// here rather than letting it reach PHPMailer, which would reject an
// empty address with a much less helpful message. This needs a human
// to actually track the customer down some other way (phone on file,
// if any, or recognizing the name) - not something code can resolve.
if ($email === '') {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'error' => "No email address on file for {$name} (submitted before email was required) and this order is set to Ship - there's no way to send an invoice automatically. You'll need to reach this customer another way before it can be invoiced.",
    ]);
    exit;
}

// ---- Check vs send, split 2026-08-10 ----
// If shipping still can't be resolved at this point (no manual override
// given, and the auto tiers came back null), this is a CHECK, not a
// send - return the preview (items/subtotal/tax) with ZERO side
// effects. No customer email, no internal alert. Repeated clicks while
// Steve works out the shipping cost are free and harmless - previously
// this branch called merch_send_notification(), which fired the "we'll
// follow up" customer email AND the internal alert on every single
// click, including ones that were really just Steve checking what a
// group needed (found 2026-08-10, after clicking Send Invoice on the
// same pending group twice sent the customer two near-identical
// "we're still working on it" emails).
if ($pricing['shipping'] === null && $isShipping) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'needsManualQuote' => true,
        'error' => 'This order needs a manual shipping quote - enter one to send the real invoice.',
        'items' => $items,
        'customerName' => $name,
        'customerEmail' => $email,
        'subtotal' => $pricing['subtotal'],
        'tax' => $pricing['tax'],
    ]);
    exit;
}

// ---- Phase 2: only reached once shipping is a real number (auto-
// resolved, or the manual override above) - this is a genuine SEND,
// deliberately NOT holding the file lock across the slow SMTP call. ----
$notifyResult = merch_send_notification($pricing, $name, $email, $anchorIsPrinted, $isShipping);

if (!$notifyResult['sentInvoice']) {
    // Shipping was resolved by this point (auto or manual override), so
    // reaching here can only mean the actual email send itself failed -
    // the manual-quote case is fully handled above, before this point,
    // and never reaches merch_send_notification() at all anymore.
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Email failed to send: ' . $notifyResult['error']]);
    exit;
}

// ---- Phase 3: mail sent - reopen briefly, re-read, stamp by OrderID ----
$stampResult = merch_invoice_stamp_invoice_date($csvFile, $groupOrderIds);
if (!$stampResult['ok']) {
    // The email genuinely went out at this point - don't tell the
    // customer-facing flow anything failed. Just surface that the
    // CSV stamp itself needs a manual look.
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Invoice email sent, but ' . lcfirst($stampResult['error'])]);
    exit;
}

echo json_encode([
    'ok' => true,
    'invoicedOrderIds' => $stampResult['invoicedOrderIds'],
    'total' => $pricing['total'],
    'invoicedDate' => $stampResult['invoicedDate'],
]);
