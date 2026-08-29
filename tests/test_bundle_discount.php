<?php
// Build: 2026-08-21-A
// ============================================================
// Direct tests for the bundle-discount pricing (2026-08-21, Tape Gun
// Add-On launch) plus the invoice-template rendering of the discount
// line. Run from anywhere:
//
//     php tests/test_bundle_discount.php
//
// Calls merch_group_calculate()/merch_calculate() in-process (no web
// server needed - the folder scan runs against the real /items/ tree)
// and asserts every money field for the combos that matter: the
// gun+add-on pair, partial sets, cap interactions, premium colors,
// pickup, and the not-bundled neighbors that must NOT get a discount.
// ============================================================

error_reporting(E_ALL);
require dirname(__DIR__) . '/pricing.php';

$failures = [];
function expect(string $label, $got, $want): void
{
    global $failures;
    if ((string) $got !== (string) $want) {
        $failures[] = "[$label] expected '$want', got '$got'";
        echo "FAIL  $label: expected '$want', got '$got'\n";
    } else {
        echo "  ok  $label\n";
    }
}

function group(array $items, bool $ship = true): array
{
    $lines = [];
    foreach ($items as [$name, $qty]) {
        $lines[] = ['item' => $name, 'quantity' => $qty, 'size' => '', 'sleeve' => '', 'color' => ''];
    }
    return merch_group_calculate($lines, $ship, true);
}

// ---- The headline case: gun + add-on = $25 pair ----------------------
$r = group([['Tape Gun Holder', 1], ['Tape Gun Add-On', 1]]);
expect('pair: subtotal', $r['subtotal'], 28);
expect('pair: discount', $r['bundleDiscount'], 3);
expect('pair: tax on discounted $25', $r['tax'], 1.75);
expect('pair: 2 mailer items ship at mailer rate', $r['shipping'], 6);
expect('pair: total', $r['total'], 32.75);

// ---- Discount counts complete SETS (min of the quantities) -----------
$r = group([['Tape Gun Holder', 1], ['Tape Gun Add-On', 2]]);
expect('1 gun + 2 add-ons: one set only', $r['bundleDiscount'], 3);
expect('1 gun + 2 add-ons: subtotal', $r['subtotal'], 38);
expect('1 gun + 2 add-ons: tax on 35', $r['tax'], 2.45);
expect('1 gun + 2 add-ons: 3 mailer items -> bigger mailer', $r['shipping'], 10);
expect('1 gun + 2 add-ons: total', $r['total'], 47.45);

// ---- Discount and the bulky-item cap are independent ------------------
$r = group([['Tape Gun Holder', 2], ['Tape Gun Add-On', 2]]);
expect('2 guns + 2 add-ons: two sets discount', $r['bundleDiscount'], 6);
expect('2 guns + 2 add-ons: cap still forces manual quote', $r['shipping'], '');
expect('2 guns + 2 add-ons: cap note is the tape-gun note', $r['shippingNote'], merch_load_string('shipping/manual-quote-multiple-tapeguns'));
expect('2 guns + 2 add-ons: total excl. shipping', $r['total'], 56 - 6 + round(50 * TAX_RATE, 2));

// ---- No free-riding: neighbors that are NOT bundled ------------------
$r = group([['Tape Gun Add-On', 1]]);
expect('add-on alone: no discount', $r['bundleDiscount'], 0);
expect('add-on alone: total', $r['total'], 10 + 0.70 + 6);
$r = group([['Tape Gun Add-On', 1], ['Circle Cutter Holder', 1]]);
expect('add-on + cutter holder: no discount', $r['bundleDiscount'], 0);
$r = group([['Tape Gun Holder', 1], ['Tool Holder Stand', 1]]);
expect('gun + tool stand: no discount', $r['bundleDiscount'], 0);

// ---- The gun's new price stands alone --------------------------------
$r = group([['Tape Gun Holder', 1]]);
expect('gun alone: new $18 price', $r['subtotal'], 18);
expect('gun alone: total', $r['total'], 18 + 1.26 + 6);

// ---- Premium color + discount stack ----------------------------------
$lines = [
    ['item' => 'Tape Gun Holder', 'quantity' => 1, 'size' => '', 'sleeve' => '', 'color' => 'Stars & Stripes (+$7)'],
    ['item' => 'Tape Gun Add-On', 'quantity' => 1, 'size' => '', 'sleeve' => '', 'color' => 'Rainbow (+$2)'],
];
$r = merch_group_calculate($lines, true, true);
expect('S&S gun + Rainbow add-on: subtotal', $r['subtotal'], 25 + 12);
expect('S&S gun + Rainbow add-on: discount still 3', $r['bundleDiscount'], 3);
expect('S&S gun + Rainbow add-on: tax on 34', $r['tax'], round(34 * TAX_RATE, 2));

// ---- Pickup: discount applies, shipping stays null -------------------
$r = group([['Tape Gun Holder', 1], ['Tape Gun Add-On', 1]], false);
expect('pickup pair: discount', $r['bundleDiscount'], 3);
expect('pickup pair: shipping null', $r['shipping'], '');
expect('pickup pair: total', $r['total'], 25 + 1.75);

// ---- Add-on class facts (derived constants) --------------------------
expect('add-on price', MERCH_PRICES['Tape Gun Add-On'], 10);
expect('add-on weight 1.5oz', ITEM_WEIGHT_OZ['Tape Gun Add-On'], 1.5);
expect('add-on is mailer-tier', in_array('Tape Gun Add-On', MAILER_TIER_ITEMS, true) ? 'yes' : 'no', 'yes');
expect('add-on Rainbow-eligible', in_array('Tape Gun Add-On', RAINBOW_ELIGIBLE_ITEMS, true) ? 'yes' : 'no', 'yes');
expect('add-on Stars&Stripes-eligible', in_array('Tape Gun Add-On', STARS_STRIPES_ELIGIBLE_ITEMS, true) ? 'yes' : 'no', 'yes');
expect('add-on has no shipment cap', isset(MERCH_SHIPMENT_QTY_CAPS['Tape Gun Add-On']) ? 'capped' : 'uncapped', 'uncapped');

// ---- Template rendering: the discount line and no leftover tokens ----
$rendered = merch_load_string('emails/invoice-body.text', [
    'name' => 'Test', 'lineItemsText' => "- Tape Gun Holder: \$18.00\n- Tape Gun Add-On: \$10.00\n",
    'discountLineText' => "- Bundle discount: -\$3.00\n",
    'tax' => '$1.75', 'shippingLineText' => "- Flat-rate shipping: \$6.00\n",
    'total' => '$32.75', 'last4Text' => '', 'venmoHandle' => 'x', 'paypalEmail' => 'x', 'accountNote' => '',
]);
expect('invoice text: discount line present', strpos($rendered, 'Bundle discount: -$3.00') !== false ? 'yes' : 'no', 'yes');
expect('invoice text: no unreplaced tokens', preg_match('/\{\{\w+\}\}/', $rendered) ? 'leftover' : 'clean', 'clean');
$renderedNoDiscount = merch_load_string('emails/invoice-body.text', [
    'name' => 'Test', 'lineItemsText' => "- Logo Hat: \$25.00\n", 'discountLineText' => '',
    'tax' => '$1.75', 'shippingLineText' => '', 'total' => '$26.75',
    'last4Text' => '', 'venmoHandle' => 'x', 'paypalEmail' => 'x', 'accountNote' => '',
]);
expect('invoice text: clean when no discount', preg_match('/\{\{\w+\}\}|Bundle discount/', $renderedNoDiscount) ? 'dirty' : 'clean', 'clean');
$pickup = merch_load_string('shipping/pickup-invoice-template', [
    'name' => 'T', 'date' => 'd', 'orderIds' => '1', 'lineItemsText' => '- x',
    'subtotal' => '$28.00', 'discountLineText' => "Bundle discount: -\$3.00\n", 'tax' => '$1.75', 'total' => '$26.75',
]);
expect('pickup doc: discount line present', strpos($pickup, "Bundle discount: -\$3.00\nTax") !== false ? 'yes' : 'no', 'yes');

// ---- Verdict ---------------------------------------------------------
if ($failures) {
    echo 'FAIL - ' . count($failures) . " assertion(s) failed.\n";
    exit(1);
}
echo "PASS - all bundle-discount assertions matched.\n";
exit(0);
