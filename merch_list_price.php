<?php
// Build: 2026-09-14-A
// ============================================================
// Live COMBINED pricing for merch.php's list UI (2026-09-14 multi-item
// list - see merch_order.php's file header comment for the overall
// design). merch.php already mirrors PER-ITEM pricing in JS
// (calculateEstimate(), unchanged) for the "what would one more of
// this item cost" preview inside the add-to-list modal - that's simple
// enough (one item, one unit price + surcharges) to safely hand-mirror
// client-side, same as it always has been.
//
// The COMBINED total across every line in the list is a different
// story: bundle discounts (merch_bundle_discount()), box-capacity
// shipping tiers (merch_printed_shipping()), and the per-class bulky-
// item caps (merch_shipment_cap_note()) all depend on the WHOLE list
// at once, not one line at a time. That math already lives in exactly
// one place - merch_group_calculate(), pricing.php, the same function
// merch_order.php uses per-line and the admin "Send Invoice" button
// uses to combine backlog orders - so this endpoint calls it directly
// with the customer's real in-progress list instead of re-deriving the
// same tiering/discount rules a second time in JS, which is exactly
// the kind of hand-mirrored duplicate this codebase has deliberately
// moved away from elsewhere (see MERCH_PRICING's own comment in
// merch.php).
//
// Read-only: never writes anything, so no CSRF token and no
// merch_order.php-style file locking - same public, unauthenticated,
// no-side-effect profile as the page's old single-item live estimate,
// just computed server-side now that it has to combine several lines.
// ============================================================

require __DIR__ . '/config.php';
require __DIR__ . '/pricing.php';

header('Content-Type: application/json');

function merch_list_price_error(string $message): void
{
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

$listRaw = isset($_POST['list']) ? (string) $_POST['list'] : '';
$listItems = json_decode($listRaw, true);
$fulfillment = isset($_POST['fulfillment']) ? trim((string) $_POST['fulfillment']) : 'Ship';
$isShipping = ($fulfillment !== 'Pickup at retreat');

if (!is_array($listItems) || count($listItems) === 0) {
    merch_list_price_error('List is empty.');
}

if (count($listItems) > LIST_MAX_LINES) {
    merch_list_price_error('Too many items.');
}

// Same per-line validation merch_order.php runs at real submission
// time (item recognized, color legal for that item, size/sleeve only
// for shirts) - this is only an estimate endpoint, but it should never
// hand back a price for a combination the real order would reject.
$items = [];
foreach ($listItems as $rawLine) {
    if (!is_array($rawLine)) {
        merch_list_price_error('Invalid list line.');
    }

    $item = isset($rawLine['item']) ? trim((string) $rawLine['item']) : '';
    $quantityRaw = isset($rawLine['quantity']) ? trim((string) $rawLine['quantity']) : '1';
    $color = isset($rawLine['color']) ? trim((string) $rawLine['color']) : '';
    $size = isset($rawLine['size']) ? trim((string) $rawLine['size']) : '';
    $sleeve = isset($rawLine['sleeve']) ? trim((string) $rawLine['sleeve']) : '';

    if ($item === '') {
        merch_list_price_error('Item not recognized.');
    }

    $quantity = filter_var($quantityRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($quantity === false) {
        $quantity = 1;
    }
    if ($quantity > MAX_QUANTITY) {
        merch_list_price_error('Quantity too high.');
    }

    $allowedColors = merch_color_options_for_item($item);
    if (empty($allowedColors)) {
        $color = '';
    } elseif ($color !== '' && !in_array($color, $allowedColors, true)) {
        merch_list_price_error('Invalid color for this item.');
    }

    if (in_array($item, SHIRT_ITEMS, true)) {
        if ($size !== '' && !in_array($size, MERCH_SIZES, true)) {
            merch_list_price_error('Invalid size for this item.');
        }
        if ($sleeve !== '' && !in_array($sleeve, MERCH_SLEEVE_LENGTHS, true)) {
            merch_list_price_error('Invalid sleeve for this item.');
        }
    } else {
        $size = '';
        $sleeve = '';
    }

    $items[] = ['item' => $item, 'quantity' => $quantity, 'size' => $size, 'sleeve' => $sleeve, 'color' => $color];
}

// $isPrinted only ever affects payment-account routing elsewhere
// (which Venmo/PayPal to show) - never the shipping-tier decision
// inside merch_group_calculate() itself, per that function's own doc
// comment - and this endpoint never shows payment info, so the value
// passed here is inert. false is fine.
$pricing = merch_group_calculate($items, $isShipping, false);
if ($pricing === null) {
    merch_list_price_error('Could not price one of the items.');
}

echo json_encode(['ok' => true] + $pricing);
