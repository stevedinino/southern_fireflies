<?php
// Build: 2026-08-29-A
// 2026-08-29 (code review Finding 3): OrderID assignment below now goes
// through id_sequence.php's persistent counter instead of a bare
// max(existing rows)+1 - see that file's header comment for why a
// hand-deleted row could otherwise cause two orders to share an ID.
require __DIR__ . '/config.php';
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_notify.php';
require_once __DIR__ . '/strings.php';
require_once __DIR__ . '/id_sequence.php';

/**
 * Wraps an error string in the same page shell the success response
 * below uses (doctype/stylesheet/content-wrapper/page-container), plus
 * a link back to the order form. Every error here used to be echoed as
 * a bare, unstyled fragment with no way back except the browser's Back
 * button (Finding 16, 2026-08-19 code review) - a customer who tripped
 * a server-side reject (JS off, a stale autofilled page, a quantity
 * over MAX_QUANTITY) landed on plain black-on-white text with nothing
 * else on the page.
 */
function merch_render_error_page(string $bodyHtml): void
{
    echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Request Not Saved</title>
  <link rel="stylesheet" href="styles/layout.css" />
</head>
<body>
  <div class="content-wrapper">
    <div class="page-container" style="text-align:center;">
      ' . $bodyHtml . '
      <p><a href="merch.php">&larr; Back to the merch page</a></p>
    </div>
  </div>
</body>
</html>';
}

$csvFile = __DIR__ . '/merchandise.csv';

// Collect form data
$item = isset($_POST['item']) ? trim($_POST['item']) : '';
$quantityRaw = isset($_POST['quantity']) ? trim($_POST['quantity']) : '1';
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$fulfillment = isset($_POST['fulfillment']) ? trim($_POST['fulfillment']) : '';
$retreat = isset($_POST['retreat']) ? trim($_POST['retreat']) : '';
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
    merch_render_error_page(merch_load_string('errors/order-quantity-too-high', ['maxQty' => MAX_QUANTITY]));
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

// 2026-08-25 (Steve): "I don't really know who will be expecting their
// items, and who can be deferred to another event" - Fulfillment alone
// only ever said Ship vs Pickup at retreat, with no way to tell WHICH
// retreat once more than one has pending pickup orders at the same
// time. Validated against the SAME manifest index.php's Save-the-Date
// grid reads (events/events-data.php) - one list, not a second
// hand-typed copy that could drift out of sync with it. A normal
// browser can only submit one of these labels (a real <select>), but
// nothing stops a direct/tampered POST, so this closes that the same
// way Finding 2 (2026-08-19 code review) already closed it for
// Color/Size/Sleeve.
if ($isShipping) {
    // Ship orders don't have a retreat - ignore anything submitted here
    // (e.g. left over from switching Fulfillment back to Ship after
    // picking one) rather than trusting it, same as this file already
    // does for Size/Sleeve on a non-shirt item below.
    $retreat = '';
} elseif ($retreat !== '') {
    $pickupEvents = require __DIR__ . '/events/events-data.php';
    $validRetreatLabels = array_map(
        fn($event) => trim($event['dateRange']) . ' – ' . trim($event['title']),
        $pickupEvents
    );
    if (!in_array($retreat, $validRetreatLabels, true)) {
        merch_render_error_page(merch_load_string('errors/order-invalid-selection'));
        exit;
    }
}

// Required fields: item, name, email always; full address only if
// shipping, which retreat only if picking up (2026-08-25).
$requiredOk = $item && $name && $email;
if ($isShipping) {
    $requiredOk = $requiredOk && $address && $city && $state && $zip;
} else {
    $requiredOk = $requiredOk && $retreat;
}

if (!$requiredOk) {
    merch_render_error_page(merch_load_string('errors/order-missing-fields'));
    exit;
}

// ---- Validate color/size/sleeve against the selected item ----
// The live form's JS only ever shows the color/size/sleeve controls
// that actually apply to the selected item (and, for color, only the
// Gildan or filament list that item uses - see GILDAN_COLOR_ITEMS/
// FILAMENT_COLOR_ITEMS in pricing.php), so a normal browser can't
// produce a mismatched combination. But nothing stopped a direct POST
// (or a tampered request) from sending one anyway - merch_update.php's
// admin color editor already validates this exact thing, but this
// script never did (Finding 2, 2026-08-19 code review). Concretely,
// this closes: a Rainbow color on an item that isn't Rainbow-eligible
// (merch_color_options_for_item() already excludes it from the allowed
// list for those items, so the general check below covers it too), a
// size on Logo Hat (no size choice at all), and a color from the wrong
// palette (Gildan on a filament item or vice versa).
$allowedColors = merch_color_options_for_item($item);
if (empty($allowedColors)) {
    // Item doesn't offer a color choice at all - ignore whatever was
    // submitted rather than trusting it.
    $color = '';
} elseif ($color !== '' && !in_array($color, $allowedColors, true)) {
    merch_render_error_page(merch_load_string('errors/order-invalid-selection'));
    exit;
}

if (in_array($item, SHIRT_ITEMS, true)) {
    if ($size !== '' && !in_array($size, MERCH_SIZES, true)) {
        merch_render_error_page(merch_load_string('errors/order-invalid-selection'));
        exit;
    }
    if ($sleeve !== '' && !in_array($sleeve, MERCH_SLEEVE_LENGTHS, true)) {
        merch_render_error_page(merch_load_string('errors/order-invalid-selection'));
        exit;
    }
} else {
    // Only shirts get a size/sleeve choice on the live form - ignore
    // anything submitted for every other item rather than trusting it.
    $size = '';
    $sleeve = '';
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
    merch_render_error_page(merch_load_string('errors/order-item-not-recognized'));
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
    merch_render_error_page(merch_load_string('errors/order-write-failed'));
    exit;
}

if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    merch_render_error_page(merch_load_string('errors/order-lock-busy'));
    exit;
}

$csvMaxOrderId = 0;
$header = null;
while (($existingRow = fgetcsv($handle)) !== false) {
    if ($header === null) {
        $header = $existingRow;
        // Excel sometimes saves this file with a UTF-8 BOM glued onto
        // the first cell, which would otherwise break the exact-match
        // lookups below (see the identical strip in ourmerch.php).
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        continue; // this row is the header, not data
    }
    if (isset($existingRow[0]) && is_numeric($existingRow[0])) {
        $csvMaxOrderId = max($csvMaxOrderId, (int)$existingRow[0]);
    }
}

// 2026-08-29 (Finding 3): the counter file, not $csvMaxOrderId alone,
// is the real source of truth here - see id_sequence.php. $csvMaxOrderId
// only matters the very first time this runs (bootstrapping the counter
// file) or if the counter file is ever missing/behind.
$nextOrderId = merch_next_persistent_id(__DIR__ . '/merchandise_orderid_counter.txt', $csvMaxOrderId);

fseek($handle, 0, SEEK_END);

// Brand-new/empty file: no header was ever read. Write the canonical
// one now rather than letting this first order become the header row.
if ($header === null) {
    fputcsv($handle, MERCH_CSV_HEADER, ",", '"', "\\");
    $header = MERCH_CSV_HEADER;
}

// Build the row keyed by column NAME, driven by the file's own header,
// instead of a hardcoded position list. A positional writer here is
// exactly what caused Finding 1 in the 2026-08-19 code review: a stale
// comment described a 24-column header while the live file had grown
// to 25 (an "Original Color" column was added later between Color and
// Size), so every column from Size onward silently shifted left in
// every order submitted since. Keying by name means the file's header
// is the only place column order can ever live - adding a column here
// can't misalign existing ones again.
//
// Original Color starts equal to Color for a brand-new order - nothing
// has been reconciled/converted yet at submission time. That
// reconciliation is a manual step Steve does later from ourmerch.php's
// color editor; Original Color is what preserves the customer's actual
// original request through that process.
$values = [
    'OrderID' => $nextOrderId,
    'Name' => $name,
    'Email' => $email,
    'Phone' => $phone,
    'Item' => $item,
    'Quantity' => $quantity,
    'Color' => $color,
    'Original Color' => $color,
    'Size' => $size,
    'Sleeve' => $sleeve,
    'Notes' => $message,
    'Fulfillment' => $fulfillment,
    // 2026-08-25: blank for Ship orders, one of events-data.php's
    // labels for Pickup - see the validation above. Harmless no-op
    // until Steve adds a "Retreat" header column to merchandise.csv
    // (same one-column-at-a-time pattern as Cancelled): the write loop
    // below only pulls values for columns the file's own header
    // actually has, so this key is simply unused until then, not an
    // error.
    'Retreat' => $retreat,
    'Address' => $address,
    'City' => $city,
    'State' => $state,
    'Zip' => $zip,
    'Price' => $pricing['subtotal'],
    'Tax' => $pricing['tax'],
    'Shipping' => $pricing['shipping'] ?? '',
    'Invoice Date' => '', // set later via the "Send Invoice" button
    'Pymt Date' => '',    // set later from the admin page
    'Created' => '',      // set later from the admin page
    'Fulfilled' => '',    // set later from the admin page
    'Cancelled' => '',    // set later from the admin page (2026-08-23)
    'Timestamp' => $timestamp,
    'IP' => $ip,
];

$row = [];
foreach ($header as $col) {
    if (!array_key_exists($col, $values)) {
        // A column exists in the live file that this script doesn't
        // know how to fill (e.g. hand-added and not yet wired up here).
        // Fail loudly and write nothing, rather than silently omitting
        // or misaligning a column - that's the exact failure mode this
        // rewrite exists to prevent.
        flock($handle, LOCK_UN);
        fclose($handle);
        error_log('merch_order.php: unrecognized merchandise.csv column "' . $col . '" - order NOT written. Add it to $values in merch_order.php.');
        merch_render_error_page(merch_load_string('errors/order-write-failed'));
        exit;
    }
    $row[] = $values[$col];
}

fputcsv($handle, $row, ",", '"', "\\");
fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);

// ---- Respond to the customer FIRST, then send the notification ----
// Previously the ack email was sent before this page was echoed, so a
// slow/stalled SMTP connection stalled the customer's confirmation page
// too - up to PHPMailer's default 300s timeout (Finding 15, 2026-08-19
// code review; reproduced as an 80+ second hang against a non-responding
// SMTP host). The order row is already safely on disk at this point
// (written above), so there's nothing lost by answering the browser
// before the email attempt runs - only the "thanks" email is delayed,
// never the order itself.
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

// Best-effort: actually flush the response to the browser now instead
// of just handing it to PHP's output buffer, so the customer's page
// finishes loading immediately regardless of what happens below.
// fastcgi_finish_request() is the reliable way to do this (PHP-FPM
// only); ob_end_flush()+flush() is a weaker fallback for a plain
// mod_php/CGI host that may or may not fully take effect depending on
// the host's own buffering/compression - harmless either way, and not
// what actually prevents the hang (the Timeout settings in
// merch_mailer(), merch_notify.php, are what do that).
ignore_user_abort(true);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (function_exists('ob_end_flush')) {
        @ob_end_flush();
    }
    @flush();
}

// Auto-invoicing at submission time is off - it couldn't anticipate a
// customer's other pending requests, so it could never combine
// shipping the way the manual "Send Invoice" button on ourmerch.php
// does. Steve now checks that page and invoices in a daily batch
// instead, which gets the combining benefit back. This email is just
// "thanks, we'll follow up" - no pricing or payment info here; that
// only ever appears in the real invoice, sent later via the button.
$ackResult = merch_send_submission_ack($name, $email, $item);
