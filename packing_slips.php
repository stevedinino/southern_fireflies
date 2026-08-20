<?php
// Build: 2026-08-20-B
// Admin-only page: a single working checklist for packing a batch of
// mailers, opened alongside the Shippo export (same click, from
// ourmerch.php's footer link - see the onclick there).
//
// This is NOT a per-customer packing slip and NOT a customer-facing
// document - it's one continuous list Steve reads top to bottom while
// standing at a table packing boxes, with a checkbox to tick off each
// shipment as it's packed. (An earlier draft of this file did one
// shipment per printed page, like a slip to drop in each box - Steve
// clarified 2026-08-14 that's not what he wants; this replaces that.)
//
// Deliberately reuses the EXACT SAME eligibility filter and the EXACT
// SAME customer-grouping logic as shippo_export.php (Pymt Date set +
// Fulfillment = Ship + Fulfilled blank; grouped by normalized Name +
// Zip), including the same "lowest OrderID in the group" Order Number,
// so this checklist and the Shippo CSV always describe the identical
// batch of shipments and can be cross-referenced by Order #. If
// shippo_export.php's filter or grouping rule ever changes, mirror the
// change here too.
//
// 2026-08-15: added shippo_export.php's new whole-shipment Created gate
// here too, per that same instruction - a shipment only appears once
// every item bound for the same address is Created, not just some of
// them (mirrors ourmerch.php's "Needs Shipping" view and
// shippo_export.php).
//
// 2026-08-15: added a second "Still In Progress" section below the main
// checklist, listing shipments that got held back by the gate above
// (same customer/address, but not every item Created yet), with a
// per-item printed/not-printed marker - per Steve, this is for cross-
// checking a mailer he's already got partially packed against what's
// actually still outstanding on it. This section is Shippo-export-only:
// there's nothing to export for an incomplete shipment, so it only
// exists here, not in shippo_export.php.
//
// Renders as a normal page (not a forced download) so it can sit open in
// a tab while packing, and prints on request (Cmd/Ctrl+P) without forcing
// a page break between shipments - it's meant to read as one list, not
// one page per customer.

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_shipments.php';

// Shared implementation in admin_guard.php as of 2026-08-20 (Finding
// 11, 2026-08-19 code review) - was previously duplicated across 8 files.
merch_require_admin_redirect('ourmerch.php');

$csvFile = __DIR__ . '/merchandise.csv';
// Shared with shippo_export.php as of 2026-08-20 (Finding 10, same
// review) - see merch_shipments.php for why.
$loaded = merch_load_csv($csvFile, 'merchandise.csv');
$rows = $loaded['rows'];
$col = merch_csv_column_map($loaded['header'], [
    'OrderID', 'Item', 'Quantity', 'Name', 'Fulfillment', 'Address', 'City',
    'State', 'Zip', 'Email', 'Phone', 'Color', 'Timestamp', 'Price', 'Fulfilled',
    'Invoice Date', 'Pymt Date', 'Created',
], ['OrderID', 'Pymt Date', 'Fulfillment', 'Fulfilled', 'Created'], 'merchandise.csv');

// ---- Same safety filter as shippo_export.php ----
$eligible = [];
foreach ($rows as $row) {
    $paid = $col['Pymt Date'] !== false ? trim($row[$col['Pymt Date']] ?? '') : '';
    $fulfillment = trim($row[$col['Fulfillment']] ?? '');
    $fulfilled = trim($row[$col['Fulfilled']] ?? '');

    if ($paid !== '' && $fulfillment === 'Ship' && $fulfilled === '') {
        $eligible[] = $row;
    }
}

// ---- Same grouping as shippo_export.php: name + zip, normalized ----
$allGroups = merch_group_shipments($eligible, $col);

// ---- Same whole-shipment Created gate as shippo_export.php ----
// A shipment only belongs on the main checklist once every item bound
// for that address is Created - one un-printed item holds the whole
// shipment back, not just its own row (2026-08-15, per Steve). Groups
// that DON'T pass this land in the "Still In Progress" section below
// instead of just being dropped.
$split = merch_split_groups_by_created($allGroups, $col);
$groups = $split['complete'];
$inProgressGroups = $split['incomplete'];

// Same "lowest OrderID in the group" numbering as shippo_export.php's
// Order Number column, so a shipment can be matched between the
// checklist and the Shippo CSV at a glance. Sorted ascending so this
// list's order matches the order shipments appear in that CSV. Used for
// both the ready list and the in-progress list, so the two sections
// number shipments the exact same way.
$buildShipments = function ($groups) use ($col) {
    $shipments = [];
    foreach ($groups as $groupRows) {
        $orderIds = array_map(fn($r) => (int)($r[$col['OrderID']] ?? 0), $groupRows);
        $shipments[] = [
            'orderNumber' => min($orderIds),
            'rows' => $groupRows,
        ];
    }
    usort($shipments, fn($a, $b) => $a['orderNumber'] <=> $b['orderNumber']);
    return $shipments;
};

$shipments = $buildShipments($groups);
$inProgressShipments = $buildShipments($inProgressGroups);

$shipmentCount = count($shipments);
$inProgressCount = count($inProgressShipments);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Packing Checklist &ndash; Southern Fireflies Retreats</title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; color: #222; margin: 24px; max-width: 720px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .generated-note { color: #666; font-size: 13px; margin-bottom: 20px; }
  .print-bar { margin-bottom: 20px; }
  .print-bar button { font-size: 15px; padding: 8px 16px; cursor: pointer; }

  .checklist { list-style: none; margin: 0; padding: 0; }

  .shipment-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid #ddd;
    page-break-inside: avoid;
  }

  .shipment-check {
    width: 20px;
    height: 20px;
    margin-top: 3px;
    flex: 0 0 auto;
  }

  .shipment-body { flex: 1 1 auto; }

  .shipment-summary {
    font-size: 15px;
    line-height: 1.4;
  }

  .shipment-order-number {
    font-weight: bold;
    color: #555;
    margin-right: 6px;
  }

  .customer-name { font-weight: bold; }

  .customer-address { color: #444; }

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

  /* Once a row is checked off, gray it out so the eye jumps straight to
     what's left - the point of a checklist is scanning for what's NOT
     done yet. Works on-screen immediately; also holds when printed,
     since printed checkboxes preserve their checked state. */
  .shipment-check:checked ~ .shipment-body {
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
  <h1>Packing Checklist</h1>
  <p class="generated-note">
    Generated <?= date('F j, Y g:ia') ?> &mdash; same batch as the Shippo export
    (paid, Ship, not yet Fulfilled, and every item Created).
    <?= $shipmentCount ?> shipment<?= $shipmentCount === 1 ? '' : 's' ?> ready to pack<?php if ($inProgressCount > 0): ?>, <?= $inProgressCount ?> still in progress below<?php endif; ?>.
    Check each one off as you pack it.
  </p>

  <?php if ($shipmentCount === 0): ?>
    <p class="empty-note">
      No shipments are fully ready to pack yet &mdash; nothing shows here until every item bound for the same address is paid, Ship, not yet Fulfilled, and Created.
      <?php if ($inProgressCount > 0): ?>
        <?= $inProgressCount ?> partially-complete shipment<?= $inProgressCount === 1 ? '' : 's' ?> below.
      <?php endif; ?>
    </p>
  <?php else: ?>
    <ul class="checklist">
      <?php foreach ($shipments as $shipment): ?>
        <?php
          $first = $shipment['rows'][0];
          $name = trim($first[$col['Name']] ?? '');
          $address = trim($first[$col['Address']] ?? '');
          $city = trim($first[$col['City']] ?? '');
          $state = trim($first[$col['State']] ?? '');
          $zip = trim($first[$col['Zip']] ?? '');
        ?>
        <li class="shipment-row">
          <input type="checkbox" class="shipment-check" />
          <div class="shipment-body">
            <div class="shipment-summary">
              <span class="shipment-order-number">#<?= (int)$shipment['orderNumber'] ?></span>
              <span class="customer-name"><?= htmlspecialchars($name) ?></span>
              &mdash;
              <span class="customer-address">
                <?= htmlspecialchars($address) ?>, <?= htmlspecialchars($city) ?>, <?= htmlspecialchars($state) ?> <?= htmlspecialchars($zip) ?>
              </span>
            </div>
            <div class="item-lines">
              <?php foreach ($shipment['rows'] as $row): ?>
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
      <h2>Still In Progress</h2>
      <p class="inprogress-note">
        Same customer/address as above, but at least one item isn't Created yet, so these
        aren't on the checklist above. Listed here so you can check a mailer you've
        already started against what's actually still outstanding on it.
      </p>
      <ul class="checklist">
        <?php foreach ($inProgressShipments as $shipment): ?>
          <?php
            $first = $shipment['rows'][0];
            $name = trim($first[$col['Name']] ?? '');
            $address = trim($first[$col['Address']] ?? '');
            $city = trim($first[$col['City']] ?? '');
            $state = trim($first[$col['State']] ?? '');
            $zip = trim($first[$col['Zip']] ?? '');
          ?>
          <li class="shipment-row">
            <div class="shipment-body">
              <div class="shipment-summary">
                <span class="shipment-order-number">#<?= (int)$shipment['orderNumber'] ?></span>
                <span class="customer-name"><?= htmlspecialchars($name) ?></span>
                &mdash;
                <span class="customer-address">
                  <?= htmlspecialchars($address) ?>, <?= htmlspecialchars($city) ?>, <?= htmlspecialchars($state) ?> <?= htmlspecialchars($zip) ?>
                </span>
              </div>
              <div class="item-lines">
                <?php foreach ($shipment['rows'] as $row): ?>
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
                      <span class="item-status-done">&check; printed</span>
                    <?php else: ?>
                      <span class="item-status-pending">not yet printed</span>
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
</body>
</html>
