<?php
// Build: 2026-09-05-A
//
// 2026-08-28 (Steve): the "ready to pack" checkboxes below now write
// back to Fulfilled on merchandise.csv when checked, instead of being a
// print-only/no-persistence UI aid. Reuses merch_update.php exactly as
// ourmerch.php's own Fulfilled checkbox already does - no new backend
// endpoint - since one checkbox here can represent SEVERAL CSV rows (a
// shipment is every item bound for one address, which can be more than
// one OrderID), the JS below just fires one merch_update.php call per
// OrderID in the shipment instead of one call per checkbox. See the
// data-order-ids attribute on each .shipment-check below and the
// setFulfilledForOrder() function near the bottom of this file.
//
// This is safe to add without any new gating: by the time a shipment
// reaches the READY list at all, every item on it is already paid,
// Ship, not yet Fulfilled, and Created (see the eligibility filter and
// whole-shipment Created gate below) - so checking Fulfilled here can
// never surprise-backfill Created (merch_update.php's cascade), since
// Created is already guaranteed set for every row involved.
//
// One real tradeoff worth knowing: the OrderIDs baked into a checkbox
// are fixed at page load. If a new item for the same customer/address
// arrives after this page is opened but before it's refreshed, checking
// the box marks only the items this page already knew about - the new
// one is left un-Fulfilled and needs a refresh + another pass (or a
// manual fix on ourmerch.php) to catch. Same snapshot tradeoff this
// whole shipment-grouping concept already carries; not new here.
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
// Fulfillment = Ship + Fulfilled blank + not Cancelled; grouped by
// normalized Name + Zip), including the same "lowest OrderID in the
// group" Order Number, so this checklist and the Shippo CSV always
// describe the identical batch of shipments and can be cross-referenced
// by Order #. If shippo_export.php's filter or grouping rule ever
// changes, mirror the change here too.
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
// 2026-08-23: a cancelled row is now excluded from the eligibility
// filter below, same as shippo_export.php - it should never show up on
// a packing checklist (ready OR in-progress), however it got into a
// Paid+Ship+not-yet-Fulfilled state.
//
// 2026-08-25: added a "By Color" regrouping of the Still In Progress
// section's not-yet-created items (Steve: he was printing this page and
// hand-highlighting same-color items so he could batch them and cut
// down on filament swaps). Pulls from the exact same $inProgressShipments
// data the customer-grouped list above already has - not a new query,
// just a second view of the same not-yet-Created rows, grouped by Color
// instead of by shipment. See merch_split_groups_by_created()'s
// $inProgressShipments population above and $colorGroups below.
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
    'Invoice Date', 'Pymt Date', 'Created', 'Cancelled',
], ['OrderID', 'Pymt Date', 'Fulfillment', 'Fulfilled', 'Created'], 'merchandise.csv');

// ---- Same safety filter as shippo_export.php ----
$eligible = [];
foreach ($rows as $row) {
    $paid = $col['Pymt Date'] !== false ? trim($row[$col['Pymt Date']] ?? '') : '';
    $fulfillment = trim($row[$col['Fulfillment']] ?? '');
    $fulfilled = trim($row[$col['Fulfilled']] ?? '');
    // Cancelled is optional (Steve may not have added the column to the
    // live CSV yet) - missing entirely means "nothing's cancelled,"
    // same convention as every other optional lookup here.
    $cancelled = $col['Cancelled'] !== false && trim($row[$col['Cancelled']] ?? '') !== '';

    if ($paid !== '' && $fulfillment === 'Ship' && $fulfilled === '' && !$cancelled) {
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

// ---- By-color regrouping of the Still In Progress section (2026-08-25,
// per Steve) ----
// The customer-grouped list above answers "what's left on THIS
// shipment"; this answers "what color should I load/print next," so
// items needing the same color can be batched together instead of
// swapping back and forth. Only pulls items that AREN'T Created yet -
// an already-printed item (the "printed" rows mixed into an otherwise
// incomplete shipment) has nothing left to batch, so it's left out here
// even though its shipment still shows up in the section above.
$colorGroups = [];
foreach ($inProgressShipments as $shipment) {
    $shipmentCustomerName = trim($shipment['rows'][0][$col['Name']] ?? '');
    foreach ($shipment['rows'] as $row) {
        if (trim($row[$col['Created']] ?? '') !== '') {
            continue; // already printed - nothing to batch
        }
        $color = trim($row[$col['Color']] ?? '');
        $colorKey = $color !== '' ? $color : '(No color specified)';
        $colorGroups[$colorKey][] = [
            'item' => trim($row[$col['Item']] ?? ''),
            'quantity' => (int)($row[$col['Quantity']] ?? 1),
            'customerName' => $shipmentCustomerName,
            'orderNumber' => $shipment['orderNumber'],
        ];
    }
}
// Alphabetical by color (case-insensitive), with the no-color bucket
// always last regardless of where it'd otherwise sort.
uksort($colorGroups, function ($a, $b) {
    if ($a === '(No color specified)') return 1;
    if ($b === '(No color specified)') return -1;
    return strcasecmp($a, $b);
});

// 2026-09-05 (Steve: printed the wrong quantity 4 times - "in my haste
// to get these printed and shipped I read them as a single item
// order") - one small helper instead of repeating the same badge-vs-
// plain-text check in all three item-line spots below (main
// checklist, Still In Progress, By Color). Only escapes/renders the
// "x2" part - callers still print the item name/color themselves,
// same as before this existed.
function merch_qty_badge_html(int $quantity): string
{
    if ($quantity > 1) {
        return '<span class="item-qty-multi">&times;' . $quantity . '</span>';
    }
    return '&times;' . $quantity;
}
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

  /* 2026-09-05 (Steve: printed the wrong quantity 4 times - "in my
     haste to get these printed and shipped I read them as a single
     item order") - matches the same badge added to ourmerch.php's
     Quantity/Item columns, so a multi-quantity line looks the same
     whichever of the two pages Steve's actually looking at when he
     misses it. A quantity of 1 (most lines) stays as plain "&times;1"
     text - see the qty > 1 checks below - so the badge doesn't turn
     into wallpaper. */
  .item-qty-multi {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 999px;
    background: #b34700;
    color: #fff;
    font-weight: bold;
  }

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
          <input type="checkbox" class="shipment-check" data-order-ids="<?= htmlspecialchars(implode(',', array_map(fn($r) => trim($r[$col['OrderID']] ?? ''), $shipment['rows'])), ENT_QUOTES) ?>" />
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
                  <?= merch_qty_badge_html($quantity) ?>
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
                    <?= merch_qty_badge_html($quantity) ?>
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

  <?php if (!empty($colorGroups)): ?>
    <div class="color-section">
      <h2>Still In Progress &mdash; By Color</h2>
      <p class="inprogress-note">
        Same not-yet-created items from the section above, regrouped by color
        so same-color items can be printed together &mdash; work straight down
        one group at a time instead of highlighting colors by hand.
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
                &bull; <?= htmlspecialchars($colorItem['item']) ?> <?= merch_qty_badge_html($colorItem['quantity']) ?>
                <span class="color-item-customer">&mdash; #<?= (int)$colorItem['orderNumber'] ?> <?= htmlspecialchars($colorItem['customerName']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <script>
    // 2026-08-29 (Finding 9): merch_update.php now refuses a request
    // without a matching CSRF token - see csrf.php and ourmerch.php's
    // own MERCH_CSRF_TOKEN for the same wiring there.
    const MERCH_CSRF_TOKEN = <?= json_encode(merch_csrf_token()) ?>;

    // 2026-08-28 (Steve): make the "ready to pack" checkboxes actually
    // write back to Fulfilled on merchandise.csv - see the header
    // comment at the top of this file for why this is safe (every row
    // on this list is already paid/Ship/Created by the time it's here).
    //
    // Reuses merch_update.php exactly as ourmerch.php's own Fulfilled
    // checkbox does. The one wrinkle: a single checkbox here can stand
    // for MULTIPLE OrderIDs (a shipment = every item bound for one
    // address), where ourmerch.php's checkbox is always exactly one row.
    //
    // 2026-08-31 (Steve, "several minutes per order" on a big shipment):
    // this used to fire one fetch() per OrderID via Promise.all - on a
    // large shipment that meant several full read-modify-write cycles
    // against merchandise.csv, each also serialized behind PHP's own
    // session lock (see merch_update.php's session_write_close() note),
    // so a multi-item shipment could queue up several full CSV rewrites
    // back to back. merch_update.php now accepts a comma-separated list
    // of OrderIDs and applies/backs-up/writes them all in ONE pass, so
    // this sends ONE request per checkbox regardless of shipment size,
    // and reads per-OrderID results back out of it.
    //
    // Symmetric toggle, same as ourmerch.php: checking sets Fulfilled
    // for every OrderID in the shipment, unchecking clears it for all of
    // them again. No confirm() dialog - matches the rest of this
    // codebase's reversible-checkbox behavior.
    function setFulfilledForOrders(orderIds, checked) {
      return fetch('merch_update.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `orderId=${encodeURIComponent(orderIds.join(','))}&field=Fulfilled&checked=${checked ? '1' : '0'}&csrf_token=${encodeURIComponent(MERCH_CSRF_TOKEN)}`
      })
        .then((r) => r.json())
        .then((data) => {
          if (!data.ok) {
            // Whole request was refused (bad CSRF token, not logged in,
            // etc., not a per-order problem) - every OrderID in this
            // shipment counts as failed with the same shared reason.
            return orderIds.map((orderId) => ({ orderId, ok: false, error: data.error }));
          }
          const results = data.results || {};
          return orderIds.map((orderId) => ({
            orderId,
            ok: !!(results[orderId] && results[orderId].ok),
            error: results[orderId] ? results[orderId].error : 'No result returned for this order.',
          }));
        });
    }

    document.querySelectorAll('.shipment-check[data-order-ids]').forEach((box) => {
      const orderIds = box.dataset.orderIds.split(',').map((id) => id.trim()).filter((id) => id !== '');
      box.addEventListener('change', () => {
        const checked = box.checked;
        const previousChecked = !checked;

        if (orderIds.length === 0) return;

        box.disabled = true;
        setFulfilledForOrders(orderIds, checked)
          .then((results) => {
            box.disabled = false;
            const failed = results.filter((r) => !r.ok);
            if (failed.length > 0) {
              // Don't leave it half-applied: revert the checkbox and
              // say plainly this shipment needs a manual look (some
              // OrderIDs in it may have saved before one of them
              // failed - same as any partial-batch failure would).
              box.checked = previousChecked;
              alert(
                'Could not save Fulfilled for order' + (failed.length === 1 ? '' : 's') + ' '
                + failed.map((r) => '#' + r.orderId).join(', ')
                + ' - please check this shipment on ourmerch.php.'
              );
            }
          });
      });
    });
  </script>
</body>
</html>
