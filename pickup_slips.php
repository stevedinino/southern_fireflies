<?php
// Build: 2026-08-25-A
// Admin-only page: the pickup-fulfillment companion to packing_slips.php.
//
// packing_slips.php is Steve's working checklist for physically packing
// SHIPPED orders, opened alongside the Shippo export. There was no
// equivalent for "Pickup at retreat" orders - the only per-order
// document those get is the plain-text pickup invoice merch_invoice.php
// generates when Send Invoice is clicked on a pickup order (see that
// file's "Pickup orders: no email at all, generate a printable
// document" branch). That text file is a receipt for the customer; it's
// not something Steve can work from to see what still needs to be
// created/pulled together before the retreat. This is that missing
// document - Steve's own prep checklist for pickup orders, structured
// the same way as packing_slips.php so the two feel like a matched
// pair.
//
// Key difference from packing_slips.php's eligibility filter: this does
// NOT require Pymt Date to be set. Per ourmerch.php's "needs-payment"
// view comment (2026-08-23), pickup orders go invoiced -> created ->
// PAID (settled in person at the retreat), the reverse of the normal
// paid-before-created Ship flow - so gating on payment here would hide
// exactly the orders Steve most needs to prep in advance. Eligibility is
// just: Fulfillment = Pickup at retreat, not yet Fulfilled (i.e. not
// already handed over), and not Cancelled.
//
// Grouping reuses merch_group_shipments()'s Name+Zip key (merch_shipments.php,
// shared with packing_slips.php/shippo_export.php) even though pickup
// orders never collect an address - Zip is required only when
// Fulfillment is Ship (see merch.php's shipping-fields toggle), so it's
// simply blank for every pickup row. A blank Zip normalizes the same way
// for every pickup customer, so grouping still comes down to Name alone
// here - same known tradeoff as everywhere else this key is used (two
// customers sharing an exact name would incorrectly combine).
//
// "Ready for Pickup" / "Still Being Prepared" mirrors packing_slips.php's
// "ready to pack" / "Still In Progress" split via
// merch_split_groups_by_created() - an order only counts as ready once
// every item bound for that customer is Created, same whole-shipment
// gate as the Ship side (2026-08-15, per Steve, extended here to pickup
// for the same reason: don't call it done until every piece exists).
//
// Also carries the same By Color regrouping added to packing_slips.php
// on 2026-08-25 for its Still In Progress section - the same filament-
// batching motivation applies equally to pickup orders' not-yet-created
// items, and it's the same underlying data shape, so it's included here
// from the start rather than bolted on later.
//
// Adds one thing packing_slips.php doesn't need: a "Payment due" tag per
// order, since pickup customers often haven't paid yet by the time
// their items are prepped - useful to know at hand-off time. Ship orders
// don't need this tag because packing_slips.php's filter already
// requires Pymt Date to be set.
//
// Renders as a normal page (not a forced download), same as
// packing_slips.php, so it can sit open in a tab while prepping for the
// retreat and prints on request without forcing a page break mid-list.

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_shipments.php';

// Shared implementation in admin_guard.php as of 2026-08-20 (Finding
// 11, 2026-08-19 code review) - was previously duplicated across 8 files.
merch_require_admin_redirect('ourmerch.php');

$csvFile = __DIR__ . '/merchandise.csv';
// Shared with packing_slips.php/shippo_export.php as of 2026-08-20
// (Finding 10, same review) - see merch_shipments.php for why.
$loaded = merch_load_csv($csvFile, 'merchandise.csv');
$rows = $loaded['rows'];
$col = merch_csv_column_map($loaded['header'], [
    'OrderID', 'Item', 'Quantity', 'Name', 'Fulfillment', 'Zip', 'Color',
    'Fulfilled', 'Invoice Date', 'Pymt Date', 'Created', 'Cancelled',
], ['OrderID', 'Fulfillment', 'Fulfilled', 'Created', 'Pymt Date'], 'merchandise.csv');

// ---- Eligibility: Pickup at retreat, not yet Fulfilled, not Cancelled ----
// Deliberately no Pymt Date requirement - see header comment.
$eligible = [];
foreach ($rows as $row) {
    $fulfillment = trim($row[$col['Fulfillment']] ?? '');
    $fulfilled = trim($row[$col['Fulfilled']] ?? '');
    // Cancelled is optional (older CSVs may not have the column yet) -
    // missing entirely means "nothing's cancelled," same convention as
    // every other optional lookup in this codebase.
    $cancelled = $col['Cancelled'] !== false && trim($row[$col['Cancelled']] ?? '') !== '';

    if ($fulfillment === 'Pickup at retreat' && $fulfilled === '' && !$cancelled) {
        $eligible[] = $row;
    }
}

// ---- Grouping: same Name+Zip key as packing_slips.php/shippo_export.php ----
$allGroups = merch_group_shipments($eligible, $col);

// ---- Whole-order Created gate, same as packing_slips.php ----
$split = merch_split_groups_by_created($allGroups, $col);
$groups = $split['complete'];
$inProgressGroups = $split['incomplete'];

// Same "lowest OrderID in the group" numbering as packing_slips.php, so
// an order can be referred to the same way across both documents if it
// ever needs cross-referencing.
$buildOrders = function ($groups) use ($col) {
    $orders = [];
    foreach ($groups as $groupRows) {
        $orderIds = array_map(fn($r) => (int)($r[$col['OrderID']] ?? 0), $groupRows);
        $paid = false;
        foreach ($groupRows as $r) {
            if (trim($r[$col['Pymt Date']] ?? '') !== '') {
                $paid = true;
                break;
            }
        }
        $orders[] = [
            'orderNumber' => min($orderIds),
            'rows' => $groupRows,
            'paid' => $paid,
        ];
    }
    usort($orders, fn($a, $b) => $a['orderNumber'] <=> $b['orderNumber']);
    return $orders;
};

$readyOrders = $buildOrders($groups);
$inProgressOrders = $buildOrders($inProgressGroups);

$readyCount = count($readyOrders);
$inProgressCount = count($inProgressOrders);

// ---- By-color regrouping of the Still Being Prepared items, same
// feature as packing_slips.php (2026-08-25) ----
$colorGroups = [];
foreach ($inProgressOrders as $order) {
    $orderCustomerName = trim($order['rows'][0][$col['Name']] ?? '');
    foreach ($order['rows'] as $row) {
        if (trim($row[$col['Created']] ?? '') !== '') {
            continue; // already created - nothing to batch
        }
        $color = trim($row[$col['Color']] ?? '');
        $colorKey = $color !== '' ? $color : '(No color specified)';
        $colorGroups[$colorKey][] = [
            'item' => trim($row[$col['Item']] ?? ''),
            'quantity' => (int)($row[$col['Quantity']] ?? 1),
            'customerName' => $orderCustomerName,
            'orderNumber' => $order['orderNumber'],
        ];
    }
}
uksort($colorGroups, function ($a, $b) {
    if ($a === '(No color specified)') return 1;
    if ($b === '(No color specified)') return -1;
    return strcasecmp($a, $b);
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Pickup Checklist &ndash; Southern Fireflies Retreats</title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; color: #222; margin: 24px; max-width: 720px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .generated-note { color: #666; font-size: 13px; margin-bottom: 20px; }
  .print-bar { margin-bottom: 20px; }
  .print-bar button { font-size: 15px; padding: 8px 16px; cursor: pointer; }

  .checklist { list-style: none; margin: 0; padding: 0; }

  .order-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid #ddd;
    page-break-inside: avoid;
  }

  .order-check {
    width: 20px;
    height: 20px;
    margin-top: 3px;
    flex: 0 0 auto;
  }

  .order-body { flex: 1 1 auto; }

  .order-summary {
    font-size: 15px;
    line-height: 1.4;
  }

  .order-number {
    font-weight: bold;
    color: #555;
    margin-right: 6px;
  }

  .customer-name { font-weight: bold; }

  .payment-due-tag {
    display: inline-block;
    padding: 1px 6px;
    font-size: 0.7em;
    background: #fff3cd;
    color: #8a6500;
    border-radius: 3px;
    vertical-align: middle;
    margin-left: 6px;
  }

  .item-lines {
    margin: 6px 0 0;
    padding-left: 4px;
    font-size: 14px;
    color: #333;
  }

  .item-lines div { margin: 2px 0; }

  .item-color { color: #555; }

  .empty-note { text-align: center; color: #666; margin-top: 60px; }

  .inprogress-section { margin-top: 40px; }

  .inprogress-section h2 {
    font-size: 16px;
    margin: 0 0 4px;
    color: #555;
    border-top: 2px solid #ddd;
    padding-top: 20px;
  }

  .inprogress-note { color: #666; font-size: 13px; margin-bottom: 16px; }

  .item-status-done { color: #2a7a2a; font-size: 0.85em; margin-left: 4px; }

  .item-status-pending { color: #b00020; font-size: 0.85em; font-weight: bold; margin-left: 4px; }

  .color-section { margin-top: 40px; }

  .color-section h2 {
    font-size: 16px;
    margin: 0 0 4px;
    color: #555;
    border-top: 2px solid #ddd;
    padding-top: 20px;
  }

  .color-group {
    margin: 16px 0;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    page-break-inside: avoid;
  }

  .color-group h3 {
    font-size: 15px;
    margin: 0 0 6px;
  }

  .color-group-count { font-weight: normal; color: #666; font-size: 0.85em; }

  .color-items { list-style: none; margin: 0; padding: 0; font-size: 14px; }

  .color-items li { margin: 3px 0; }

  .color-item-customer { color: #666; font-size: 0.9em; }

  /* Once a row is checked off, gray it out so the eye jumps straight to
     what's left - same convention as packing_slips.php. */
  .order-check:checked ~ .order-body {
    opacity: 0.45;
    text-decoration: line-through;
  }

  @media print {
    .print-bar { display: none; }
    body { max-width: none; }
  }
</style>
</head>
<body>
  <div class="print-bar">
    <button type="button" onclick="window.print()">Print</button>
  </div>
  <h1>Pickup Checklist</h1>
  <p class="generated-note">
    Generated <?= date('F j, Y g:ia') ?> &mdash; every Pickup at Retreat order that
    isn&rsquo;t yet marked Fulfilled or Cancelled, regardless of payment or
    invoice status.
    <?= $readyCount ?> order<?= $readyCount === 1 ? '' : 's' ?> ready to hand over<?php if ($inProgressCount > 0): ?>, <?= $inProgressCount ?> still being prepared below<?php endif; ?>.
    Check each one off as you set it aside for pickup.
  </p>

  <?php if ($readyCount === 0): ?>
    <p class="empty-note">
      No orders are fully ready yet &mdash; nothing shows here until every item
      a customer ordered is Created.
      <?php if ($inProgressCount > 0): ?>
        <?= $inProgressCount ?> partially-complete order<?= $inProgressCount === 1 ? '' : 's' ?> below.
      <?php endif; ?>
    </p>
  <?php else: ?>
    <ul class="checklist">
      <?php foreach ($readyOrders as $order): ?>
        <?php $name = trim($order['rows'][0][$col['Name']] ?? ''); ?>
        <li class="order-row">
          <input type="checkbox" class="order-check" />
          <div class="order-body">
            <div class="order-summary">
              <span class="order-number">#<?= (int)$order['orderNumber'] ?></span>
              <span class="customer-name"><?= htmlspecialchars($name) ?></span>
              <?php if (!$order['paid']): ?>
                <span class="payment-due-tag">Payment due at pickup</span>
              <?php endif; ?>
            </div>
            <div class="item-lines">
              <?php foreach ($order['rows'] as $row): ?>
                <?php
                  $item = trim($row[$col['Item']] ?? '');
                  $color = trim($row[$col['Color']] ?? '');
                  $quantity = (int)($row[$col['Quantity']] ?? 1);
                ?>
                <div>
                  &bull; <?= htmlspecialchars($item) ?>
                  <?php if ($color !== ''): ?>
                    &mdash; <span class="item-color"><?= htmlspecialchars($color) ?></span>
                  <?php endif; ?>
                  &times;<?= $quantity ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($inProgressCount > 0): ?>
    <div class="inprogress-section">
      <h2>Still Being Prepared</h2>
      <p class="inprogress-note">
        At least one item on these orders isn&rsquo;t Created yet, so they
        aren&rsquo;t on the checklist above. Listed here so you can see what&rsquo;s
        still outstanding before the retreat.
      </p>
      <ul class="checklist">
        <?php foreach ($inProgressOrders as $order): ?>
          <?php $name = trim($order['rows'][0][$col['Name']] ?? ''); ?>
          <li class="order-row">
            <div class="order-body">
              <div class="order-summary">
                <span class="order-number">#<?= (int)$order['orderNumber'] ?></span>
                <span class="customer-name"><?= htmlspecialchars($name) ?></span>
                <?php if (!$order['paid']): ?>
                  <span class="payment-due-tag">Payment due at pickup</span>
                <?php endif; ?>
              </div>
              <div class="item-lines">
                <?php foreach ($order['rows'] as $row): ?>
                  <?php
                    $item = trim($row[$col['Item']] ?? '');
                    $color = trim($row[$col['Color']] ?? '');
                    $quantity = (int)($row[$col['Quantity']] ?? 1);
                    $itemCreated = trim($row[$col['Created']] ?? '') !== '';
                  ?>
                  <div>
                    &bull; <?= htmlspecialchars($item) ?>
                    <?php if ($color !== ''): ?>
                      &mdash; <span class="item-color"><?= htmlspecialchars($color) ?></span>
                    <?php endif; ?>
                    &times;<?= $quantity ?>
                    <?php if ($itemCreated): ?>
                      <span class="item-status-done">&check; created</span>
                    <?php else: ?>
                      <span class="item-status-pending">not yet created</span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (!empty($colorGroups)): ?>
    <div class="color-section">
      <h2>Still Being Prepared &mdash; By Color</h2>
      <p class="inprogress-note">
        Same not-yet-created items from the section above, regrouped by color
        so same-color items can be printed together.
      </p>
      <?php foreach ($colorGroups as $colorName => $colorItems): ?>
        <?php $colorTotalQty = array_sum(array_column($colorItems, 'quantity')); ?>
        <div class="color-group">
          <h3>
            <?= htmlspecialchars($colorName) ?>
            <span class="color-group-count">(<?= $colorTotalQty ?> item<?= $colorTotalQty === 1 ? '' : 's' ?> total)</span>
          </h3>
          <ul class="color-items">
            <?php foreach ($colorItems as $colorItem): ?>
              <li>
                &bull; <?= htmlspecialchars($colorItem['item']) ?> &times;<?= $colorItem['quantity'] ?>
                <span class="color-item-customer">&mdash; #<?= (int)$colorItem['orderNumber'] ?> <?= htmlspecialchars($colorItem['customerName']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</body>
</html>
