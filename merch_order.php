<?php
// Build: 2026-08-01-A
require __DIR__ . '/config.php';
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_notify.php';
require_once __DIR__ . '/strings.php';

$csvFile = __DIR__ . '/merchandise.csv';

// Collect form data
$item = isset($_POST['item']) ? trim($_POST['item']) : '';
$quantityRaw = isset($_POST['quantity']) ? trim($_POST['quantity']) : '1';
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$fulfillment = isset($_POST['fulfillment']) ? trim($_POST['fulfillment']) : '';
$address = isset($_POST['address']) ? trim($_POST['address']) : '';
$city = isset($_POST['city']) ? trim($_POST['city']) : '';
$state = isset($_POST['state']) ? trim($_POST['state']) : '';
$zip = isset($_POST['zip']) ? trim($_POST['zip']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
$color = isset($_POST['color']) ? trim($_POST['color']) : '';
$size = isset($_POST['size']) ? trim($_POST['size']) : '';
$sleeve = isset($_POST['sleeve']) ? trim($_POST['sleeve']) : '';
$message = isset($_POST['message']) ? trim($_POST['message']) : '';
$timestamp = date('Y-m-d H:i:s');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Quantity must be a positive integer - fall back to 1 if anything unexpected comes through
$quantity = filter_var($quantityRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($quantity === false) {
    $quantity = 1;
}

// Reject rather than silently clamp - if someone (or a tampered request)
// sends an absurd quantity, better to tell them than to quietly change
// their order to something they didn't ask for.
if ($quantity > MAX_QUANTITY) {
    echo merch_load_string('errors/order-quantity-too-high', ['maxQty' => MAX_QUANTITY]);
    exit;
}

// Cap notes length server-side regardless of what the client enforces -
// a client-side maxlength alone can be bypassed by anyone posting
// directly to this script.
if (mb_strlen($message) > NOTES_MAX_LENGTH) {
    $message = mb_substr($message, 0, NOTES_MAX_LENGTH);
}

// Only "Ship" and "Pickup at retreat" are valid fulfillment values - default to Ship if anything unexpected comes through
if ($fulfillment !== 'Ship' && $fulfillment !== 'Pickup at retreat') {
    $fulfillment = 'Ship';
}
$isShipping = ($fulfillment === 'Ship');

// Required fields: item, name, email always; full address only if shipping
$requiredOk = $item && $name && $email;
if ($isShipping) {
    $requiredOk = $requiredOk && $address && $city && $state && $zip;
}

if (!$requiredOk) {
    echo merch_load_string('errors/order-missing-fields');
    exit;
}

// Single order line, but run through merch_group_calculate() (same
// function the admin "Send Invoice" button uses for combined backlog
// orders) so there's exactly one place that does this math, not two
// slowly drifting copies of it.
$isPrinted = merch_is_printed_item($item);
$pricing = merch_group_calculate(
    [['item' => $item, 'quantity' => $quantity, 'size' => $size, 'sleeve' => $sleeve, 'color' => $color]],
    $isShipping,
    $isPrinted
);
if ($pricing === null) {
    echo merch_load_string('errors/order-item-not-recognized');
    exit;
}

// ---- Append the row with file locking, and assign OrderID inside the lock ----
// Locking matters here: without it, a near-simultaneous write from the admin
// edit page (marking an order Created/Fulfilled/Paid) could read the file and
// then overwrite it with a stale copy that doesn't include this brand-new order.
// Opening in 'c+' lets us read current contents (to work out the next OrderID)
// and then write, all within the same locked session.
$handle = fopen($csvFile, 'c+');
if (!$handle) {
    echo merch_load_string('errors/order-write-failed');
    exit;
}

if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    echo merch_load_string('errors/order-lock-busy');
    exit;
}

$nextOrderId = 1;
$isFirstLine = true;
while (($existingRow = fgetcsv($handle)) !== false) {
    if ($isFirstLine) {
        $isFirstLine = false;
        continue; // skip header row
    }
    if (isset($existingRow[0]) && is_numeric($existingRow[0])) {
        $nextOrderId = max($nextOrderId, (int)$existingRow[0] + 1);
    }
}

fseek($handle, 0, SEEK_END);
// Column order must match the CSV header exactly:
// OrderID,Name,Email,Phone,Item,Quantity,Color,Size,Sleeve,Notes,Fulfillment,Address,City,State,Zip,Price,Tax,Shipping,Invoice Date,Pymt Date,Created,Fulfilled,Timestamp,IP
$row = [
    $nextOrderId, $name, $email, $phone,
    $item, $quantity, $color, $size, $sleeve, $message,
    $fulfillment, $address, $city, $state, $zip,
    $pricing['subtotal'], $pricing['tax'], $pricing['shipping'] ?? '',
    '', // Invoice Date - set later via the "Send Invoice" button
    '', // Pymt Date - set later from the admin page
    '', // Created - set later from the admin page
    '', // Fulfilled - set later from the admin page
    $timestamp, $ip,
];
fputcsv($handle, $row, ",", '"', "\\");
fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);

// ---- Notify the customer: always the same simple ack now (2026-07-26) ----
// Auto-invoicing at submission time is off - it couldn't anticipate a
// customer's other pending requests, so it could never combine
// shipping the way the manual "Send Invoice" button on ourmerch.php
// does. Steve now checks that page and invoices in a daily batch
// instead, which gets the combining benefit back. This email is just
// "thanks, we'll follow up" - no pricing or payment info here; that
// only ever appears in the real invoice, sent later via the button.
$ackResult = merch_send_submission_ack($name, $email, $item);

echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="refresh" content="3;url=merch.php">
  <title>Request Received</title>
  <link rel="stylesheet" href="styles/layout.css" />
</head>
<body>
  <div class="content-wrapper">
    <div class="page-container" style="text-align:center;">
      ' . merch_load_string('errors/order-received') . '
    </div>
  </div>
</body>
</html>';
