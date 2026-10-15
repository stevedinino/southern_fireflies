<?php
// Build: 2026-07-25-H
// ============================================================
// SINGLE SOURCE OF TRUTH for Southern Fireflies merch pricing.
//
// merch_order.php includes this file to calculate order totals.
// merch.html currently displays these same numbers as plain text -
// until that page is converted to read from here directly (a
// bigger change - see the note Claude left in chat), any price
// change made here must ALSO be made by hand in merch.html, or
// the two will disagree. That risk doesn't go away with this
// file alone; this file just gives you one clear place to make
// the change instead of hunting through calculation logic too.
// ============================================================

// Base price per item. Shirts price by sleeve length, so those two
// items use an array keyed by sleeve instead of a flat number.
const MERCH_PRICES = [
    // Keys here must match the <select name="sleeve"> option values in
    // merch.php exactly ("Short Sleeve" / "Long Sleeve") - not a shortened
    // label - or price lookup silently falls back to the wrong price.
    'Logo Shirt' => ['Short Sleeve' => 23, 'Long Sleeve' => 25],
    'Finding Your Way Shirt' => ['Short Sleeve' => 23, 'Long Sleeve' => 25],
    'Logo Hat' => 25,
    'Tool Holder Stand' => 12,
    'Circle Cutter Holder' => 18,
    'Oval Cutter Holder' => 18,
];

const SHIRT_ITEMS = ['Logo Shirt', 'Finding Your Way Shirt'];
const RAINBOW_ELIGIBLE_ITEMS = ['Tool Holder Stand', 'Circle Cutter Holder', 'Oval Cutter Holder'];
const OVERSIZE_SURCHARGE_SIZES = ['3XL', '4XL', '5XL'];

// ------------------------------------------------------------
// Printed-item shipping tiers (Steve's items only - shirts/hats
// still use the simple FLAT_SHIPPING_RATE/FLAT_SHIPPING_MAX_QTY
// rule below, since that's Janet's side and hasn't been revisited).
//
// This is a real packaging constraint, not an arbitrary item-count
// rule: Circle/Oval Cutter Holders are thin enough to ship in a
// small poly mailer; a Tool Holder Stand is too fragile for that and
// always needs a box. Once a box is being sent anyway, a couple of
// Circle/Oval holders ride along inside it for free. Tiers, per
// Steve (2026-07-24), based on what's actually shipped successfully
// plus a couple of not-yet-tested guesses he made to cover his cost
// until real data comes in:
//   - 0 Tool Stands, 1-2 Circle/Oval  -> small mailer,  $6
//   - 0 Tool Stands, 3-4 Circle/Oval  -> bigger mailer, $10 (guess)
//   - 0 Tool Stands, 5+ Circle/Oval   -> manual quote (untested)
//   - 1 Tool Stand,  0-2 Circle/Oval  -> one box,       $10
//   - 1 Tool Stand,  3+ Circle/Oval   -> manual quote (exceeds the box)
//   - 2+ Tool Stands, any Circle/Oval -> manual quote, always
// ------------------------------------------------------------
const TOOL_STAND_ITEM = 'Tool Holder Stand';
const CIRCLE_OVAL_ITEMS = ['Circle Cutter Holder', 'Oval Cutter Holder'];
const PRINTED_SHIP_RATE_MAILER = 6;  // 1-2 Circle/Oval, no Tool Stand
const PRINTED_SHIP_RATE_BOX = 10;    // a box is involved either way (1 Tool Stand, or 3-4 Circle/Oval alone)
const CIRCLE_OVAL_MAILER_MAX = 2;
const CIRCLE_OVAL_ALONE_MAX = 4;     // above this, even with no Tool Stand, it's a manual quote
const CIRCLE_OVAL_WITH_TOOL_STAND_MAX = 2; // how many can ride along in the Tool Stand's box for free

// ------------------------------------------------------------
// Poly mailer weights, by Steve (2026-07-25), for shippo_export.php.
// Only applies when there's NO Tool Stand in the shipment - Tool
// Stand shipments always use scavenged one-off boxes, so those are
// always hand-weighed/measured by Steve directly in Shippo.
// ------------------------------------------------------------
const POLY_MAILER_WEIGHT_OZ = [
    1 => 3,
    2 => 5,
    3 => 7,
    4 => 9,
];
const POLY_MAILER_WIDTH_IN = 8.5;
const POLY_MAILER_LENGTH_IN = 11;

/**
 * Shipping for a group of printed items, given the combined quantity
 * of Tool Stands and Circle/Oval holders in that group (could be a
 * single order line, or several combined into one invoice).
 * Returns ['amount' => float|null, 'note' => string] - amount is null
 * when it needs a manual quote instead of a flat rate, same
 * convention as the rest of this file.
 */
function merch_printed_shipping(int $toolStandQty, int $circleOvalQty): array
{
    if ($toolStandQty >= 2) {
        return ['amount' => null, 'note' => 'More than one Tool Holder Stand needs its own shipping box and cost - we\'ll follow up separately with a shipping quote.'];
    }

    if ($toolStandQty === 1) {
        if ($circleOvalQty <= CIRCLE_OVAL_WITH_TOOL_STAND_MAX) {
            return ['amount' => (float) PRINTED_SHIP_RATE_BOX, 'note' => ''];
        }
        return ['amount' => null, 'note' => 'A Tool Holder Stand plus more than ' . CIRCLE_OVAL_WITH_TOOL_STAND_MAX . ' Circle/Oval Cutter Holders needs its own shipping quote - we\'ll follow up separately.'];
    }

    // No Tool Stand in this group.
    if ($circleOvalQty <= CIRCLE_OVAL_MAILER_MAX) {
        return ['amount' => (float) PRINTED_SHIP_RATE_MAILER, 'note' => ''];
    }
    if ($circleOvalQty <= CIRCLE_OVAL_ALONE_MAX) {
        return ['amount' => (float) PRINTED_SHIP_RATE_BOX, 'note' => ''];
    }
    return ['amount' => null, 'note' => 'Orders of more than ' . CIRCLE_OVAL_ALONE_MAX . ' Circle/Oval Cutter Holders need their own shipping quote - we\'ll follow up separately.'];
}

const OVERSIZE_SURCHARGE = 3;
const RAINBOW_SURCHARGE = 2;
const TAX_RATE = 0.07; // South Carolina - not accounting for other-state nexus rules
const FLAT_SHIPPING_RATE = 6;
const FLAT_SHIPPING_MAX_QTY = 2; // orders above this need a manual shipping quote instead

// Sanity caps - not pricing rules exactly, but they live here so
// merch.php (the form) and merch_order.php (the handler) both enforce
// the same numbers instead of drifting apart.
const MAX_QUANTITY = 25; // no legitimate single request needs more than this
const NOTES_MAX_LENGTH = 500; // characters

/**
 * Per-unit price for one item, given its size/sleeve/color choices.
 * Returns null if the item name isn't recognized (guards against
 * tampered POST data - shouldn't happen from the live form).
 */
function merch_unit_price(string $item, string $size, string $sleeve, string $color): ?float
{
    if (!isset(MERCH_PRICES[$item])) {
        return null;
    }

    $base = MERCH_PRICES[$item];
    if (in_array($item, SHIRT_ITEMS, true)) {
        $price = $base[$sleeve] ?? $base['Short Sleeve']; // default to short sleeve if somehow missing
    } else {
        $price = $base;
    }

    if (in_array($item, SHIRT_ITEMS, true) && in_array($size, OVERSIZE_SURCHARGE_SIZES, true)) {
        $price += OVERSIZE_SURCHARGE;
    }

    if (in_array($item, RAINBOW_ELIGIBLE_ITEMS, true) && $color === 'Rainbow (+$2)') {
        $price += RAINBOW_SURCHARGE;
    }

    return $price;
}

/**
 * True if an item is one of the 3D-printed items (paid to Stephen's
 * personal Venmo/PayPal, per config.php's *_PRINTED constants), false
 * if it's a shirt/hat (paid to Janet's accounts). Reuses
 * RAINBOW_ELIGIBLE_ITEMS since that list already identifies exactly
 * the printed items - kept as one function so merch_order.php never
 * has to hardcode this split itself.
 */
function merch_is_printed_item(string $item): bool
{
    return in_array($item, RAINBOW_ELIGIBLE_ITEMS, true);
}

/**
 * HTML price line for a merch card - used by merch.php so the displayed
 * price text is generated from the same numbers as the order calculation,
 * instead of being typed separately as static text.
 */
function merch_price_display(string $item): string
{
    if (!isset(MERCH_PRICES[$item])) {
        return '';
    }
    $base = MERCH_PRICES[$item];
    if (in_array($item, SHIRT_ITEMS, true)) {
        return '$' . $base['Short Sleeve'] . ' short sleeve / $' . $base['Long Sleeve'] . ' long sleeve '
            . '<span class="merch-price-note">(+$' . OVERSIZE_SURCHARGE . ' for size 3XL and up), plus tax and shipping</span>';
    }
    return '$' . $base . ' <span class="merch-price-note">plus tax and shipping</span>';
}

/**
 * Bundles the pricing constants into a plain array for json_encode(), so
 * the same numbers can drive a live JavaScript total estimate in the
 * browser. Note: this hands the *data* to JS, not the calculation logic
 * itself - merch_calculate()'s arithmetic is mirrored in JS separately
 * (calculateEstimate() in merch.php) so the page can update live without
 * a round trip to the server. If you ever change the surcharge *rules*
 * (not just the numbers), both places need updating.
 */
function merch_pricing_for_js(): array
{
    return [
        'prices' => MERCH_PRICES,
        'shirtItems' => SHIRT_ITEMS,
        'rainbowEligibleItems' => RAINBOW_ELIGIBLE_ITEMS,
        'oversizeSurchargeSizes' => OVERSIZE_SURCHARGE_SIZES,
        'oversizeSurcharge' => OVERSIZE_SURCHARGE,
        'rainbowSurcharge' => RAINBOW_SURCHARGE,
        'taxRate' => TAX_RATE,
        'flatShippingRate' => FLAT_SHIPPING_RATE,
        'flatShippingMaxQty' => FLAT_SHIPPING_MAX_QTY,
        'maxQuantity' => MAX_QUANTITY,
        'toolStandItem' => TOOL_STAND_ITEM,
        'circleOvalItems' => CIRCLE_OVAL_ITEMS,
        'printedShipRateMailer' => PRINTED_SHIP_RATE_MAILER,
        'printedShipRateBox' => PRINTED_SHIP_RATE_BOX,
        'circleOvalMailerMax' => CIRCLE_OVAL_MAILER_MAX,
        'circleOvalAloneMax' => CIRCLE_OVAL_ALONE_MAX,
        'circleOvalWithToolStandMax' => CIRCLE_OVAL_WITH_TOOL_STAND_MAX,
    ];
}

/**
 * Combined total across several line items for the same customer -
 * used by merch_invoice.php to invoice everything someone ordered
 * (that hasn't been invoiced yet) in one email instead of one per
 * item request.
 *
 * $items is an array of ['item'=>, 'quantity'=>, 'size'=>, 'sleeve'=>, 'color'=>].
 * All items passed in must share one fulfillment method (Ship or
 * Pickup) - that's a property of the whole group, not each line, so
 * it's a separate $isShipping argument rather than a per-item field.
 *
 * Tax and shipping are calculated on the COMBINED subtotal/quantity,
 * not per line - so this reuses FLAT_SHIPPING_MAX_QTY against the
 * total item count across the group. That means 3 different items at
 * qty 1 each falls to a manual shipping quote just like one item at
 * qty 3 would, on the assumption a bigger combined order needs its
 * own shipping look, not automatic flat-rate stacking.
 *
 * Returns null if any item in the group isn't recognized.
 */
function merch_group_calculate(array $items, bool $isShipping, bool $isPrinted): ?array
{
    $lines = [];
    $subtotal = 0.0;
    $totalQuantity = 0;
    $toolStandQty = 0;
    $circleOvalQty = 0;

    foreach ($items as $it) {
        $unitPrice = merch_unit_price($it['item'], $it['size'] ?? '', $it['sleeve'] ?? '', $it['color'] ?? '');
        if ($unitPrice === null) {
            return null;
        }
        $qty = (int)$it['quantity'];
        $lineSubtotal = $unitPrice * $qty;

        $lines[] = [
            'item' => $it['item'],
            'quantity' => $qty,
            'unitPrice' => $unitPrice,
            'lineSubtotal' => $lineSubtotal,
        ];

        $subtotal += $lineSubtotal;
        $totalQuantity += $qty;

        if ($it['item'] === TOOL_STAND_ITEM) {
            $toolStandQty += $qty;
        } elseif (in_array($it['item'], CIRCLE_OVAL_ITEMS, true)) {
            $circleOvalQty += $qty;
        }
    }

    $tax = round($subtotal * TAX_RATE, 2);

    $shipping = null;
    $shippingNote = '';
    if ($isShipping) {
        if ($isPrinted) {
            $shipInfo = merch_printed_shipping($toolStandQty, $circleOvalQty);
            $shipping = $shipInfo['amount'];
            $shippingNote = $shipInfo['note'];
        } elseif ($totalQuantity <= FLAT_SHIPPING_MAX_QTY) {
            $shipping = FLAT_SHIPPING_RATE;
        } else {
            $shippingNote = 'Orders over ' . FLAT_SHIPPING_MAX_QTY . ' items don\'t qualify for flat-rate shipping - we\'ll follow up separately with a shipping cost.';
        }
    }

    $total = $subtotal + $tax + ($shipping ?? 0);

    return [
        'lines' => $lines,
        'subtotal' => $subtotal,
        'tax' => $tax,
        'shipping' => $shipping,
        'shippingNote' => $shippingNote,
        'total' => $total,
        'totalQuantity' => $totalQuantity,
    ];
}

/**
 * Full order total. Returns null if the item isn't recognized.
 *
 * 'shipping' is null (NOT 0) when the order exceeds the flat-rate
 * quantity cap - that's the signal to leave Shipping blank in the
 * CSV and tell the customer we'll follow up with a shipping quote
 * instead of charging the flat rate. Check for null, not falsy,
 * when reading this elsewhere.
 */
function merch_calculate(string $item, int $quantity, string $size, string $sleeve, string $color, bool $isShipping): ?array
{
    $unitPrice = merch_unit_price($item, $size, $sleeve, $color);
    if ($unitPrice === null) {
        return null;
    }

    $subtotal = $unitPrice * $quantity;
    $tax = round($subtotal * TAX_RATE, 2);

    $shipping = null;
    $shippingNote = '';
    if ($isShipping) {
        if (merch_is_printed_item($item)) {
            $toolStandQty = ($item === TOOL_STAND_ITEM) ? $quantity : 0;
            $circleOvalQty = in_array($item, CIRCLE_OVAL_ITEMS, true) ? $quantity : 0;
            $shipInfo = merch_printed_shipping($toolStandQty, $circleOvalQty);
            $shipping = $shipInfo['amount'];
            $shippingNote = $shipInfo['note'];
        } elseif ($quantity <= FLAT_SHIPPING_MAX_QTY) {
            $shipping = FLAT_SHIPPING_RATE;
        } else {
            $shippingNote = 'Orders over ' . FLAT_SHIPPING_MAX_QTY . ' items don\'t qualify for flat-rate shipping - we\'ll follow up separately with a shipping cost.';
        }
    }

    $total = $subtotal + $tax + ($shipping ?? 0);

    return [
        'unitPrice' => $unitPrice,
        'subtotal' => $subtotal,
        'tax' => $tax,
        'shipping' => $shipping,
        'total' => $total,
        'shippingNote' => $shippingNote,
    ];
}
