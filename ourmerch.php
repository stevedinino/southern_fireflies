<?php
// Build: 2026-08-23-C
require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require __DIR__ . '/pricing.php'; // 2026-08-18: for GILDAN_COLOR_ITEMS/FILAMENT_COLOR_ITEMS/merch_color_options_for_item() - powers the editable Color dropdown below
require __DIR__ . '/merch_shipments.php'; // 2026-08-20: for merch_shipment_key() - see Finding 10, 2026-08-19 code review

// 2026-08-20 (Steve): working an order means glancing back and forth
// between these 8 fields, but they're spread across the CSV's 25
// columns (Name is #2, Color is #7, Invoice Date/Pymt Date/Created/
// Fulfilled are #20-23) - wide enough apart that they scroll off
// opposite edges of the screen at once. This reorders the ADMIN
// TABLE'S DISPLAY ONLY - it has no effect on merchandise.csv's own
// column order (still MERCH_CSV_HEADER, pricing.php), which every
// reader/writer keys by name, not position. Any header column not
// listed here (a future hand-added one, say) just falls in after
// these, in whatever order the CSV already has it - see $displayOrder
// below - rather than silently vanishing from the table.
const MERCH_ADMIN_COLUMN_ORDER = [
    'OrderID', 'Name', 'Item', 'Quantity', 'Color',
    'Invoice Date', 'Pymt Date', 'Created', 'Fulfilled', 'Cancelled',
];

// Simple password gate (same login as ourguests.php - one admin, two
// lists). Shared implementation in admin_guard.php as of 2026-08-20
// (Finding 11, 2026-08-19 code review) - was previously duplicated
// byte-for-byte here and in ourguests.php.
merch_admin_login_gate('Admin Login – Southern Fireflies Retreats', 'ourmerch.php');

// 2026-08-23 (#2): catalog data for the Item-edit form's JS (see the
// Item column below, and the script block near the bottom) - built
// once here, not per-row, and embedded as JSON so the client can
// rebuild the Color/Size/Sleeve controls to match whatever Item gets
// picked, mirroring the "only show what applies to this item" rule
// merch_order.php's own validation already follows. MERCH_PRICES' key
// order already reflects the /items/NN-slug/ folder order (see
// merch_items.php), so this dropdown lists items in the same order
// customers see them on merch.php.
$merchEditItemMeta = [];
foreach (array_keys(MERCH_PRICES) as $merchEditCatalogItem) {
    $merchEditItemMeta[$merchEditCatalogItem] = [
        'colors' => merch_color_options_for_item($merchEditCatalogItem),
        'isShirt' => in_array($merchEditCatalogItem, SHIRT_ITEMS, true),
    ];
}
$merchEditCatalog = [
    'items' => array_keys(MERCH_PRICES),
    'itemMeta' => $merchEditItemMeta,
    'sizes' => MERCH_SIZES,
    'sleeves' => MERCH_SLEEVE_LENGTHS,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Merch Requests</title>
  <link rel="icon" href="images/favicon.png" type="image/png" />
  <link rel="stylesheet" href="styles/layout.css" />
  <style>
    /* This admin page doesn't share the public site's nav/branding, so
       widening it here doesn't affect any other page - layout.css's
       .content-wrapper (max-width: 1000px) is meant for readable
       blog-style pages, not a wide data table. Without this override,
       the table scrolls inside that fixed 1000px box regardless of
       zoom level, which is exactly the "frame" problem. */
    .content-wrapper {
      max-width: 98vw;
    }
    /* Table lives in its own scrollable pane (below) with a real,
       intentional height + overflow on BOTH axes - not the earlier
       attempt of letting the whole page scroll, which left the table's
       overflow spilling past the white background onto the tan page
       behind it. With a deliberate scroll container, the sticky header
       below sticks correctly relative to THIS pane instead of relying
       on page scroll. */
    .merch-table-pane {
      max-height: 75vh;
      overflow: auto;
      background: #fff;
      border-radius: 8px;
    }
    table th {
      position: sticky;
      top: 0;
      background: #fff;
      z-index: 2;
      box-shadow: 0 1px 0 #ddd;
    }
    .merch-view-btn.active {
      background: #333;
    }
    /* Click affordance for the editable Color cell - same idea as a
       plain-text "click to edit" field, so it reads as interactive next
       to the checkbox/button cells beside it. */
    .merch-color-display {
      /* A real <button> as of 2026-08-20 (Finding 17, 2026-08-19 code
         review) so it's keyboard-focusable and Enter/Space-activatable
         - a bare <span> with only a click handler couldn't be reached
         or opened at all without a mouse. Reset the button chrome back
         to how the plain-text span used to look. */
      background: none;
      border: none;
      padding: 0;
      margin: 0;
      font: inherit;
      color: inherit;
      cursor: pointer;
      text-decoration: underline dotted;
      text-underline-offset: 2px;
    }
    .merch-color-edit {
      font-size: 0.85em;
    }
  </style>
</head>
<body>
  <div class="content-wrapper">
    <div class="page-container">
      <h2>Merchandise Requests</h2>

      <?php
      $csvFile = 'merchandise.csv';
      $rows = [];
      if (file_exists($csvFile) && filesize($csvFile) > 0) {
          if (($handle = fopen($csvFile, 'r')) !== false) {
              while (($row = fgetcsv($handle)) !== false) {
                  $rows[] = $row;
              }
              fclose($handle);
          }
      }

      // Read column names from the CSV's own header row rather than
      // hardcoding them here - with 22 columns now, hand-matching a
      // hardcoded list to the file is exactly the kind of thing that
      // silently drifts out of sync after the next manual column add.
      $header = !empty($rows) ? array_shift($rows) : [];
      // Excel sometimes saves CSVs with a UTF-8 BOM glued onto the very
      // first cell, which silently breaks an exact-match lookup for
      // "OrderID" (the file looks fine to the eye, but the string isn't
      // literally "OrderID" anymore). Strip it before matching.
      if (isset($header[0])) {
          $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
      }
      $orderIdIndex = array_search('OrderID', $header, true);
      $createdIndex = array_search('Created', $header, true);
      $fulfilledIndex = array_search('Fulfilled', $header, true);
      $invoiceDateIndex = array_search('Invoice Date', $header, true);
      $pymtDateIndex = array_search('Pymt Date', $header, true);
      $fulfillmentIndex = array_search('Fulfillment', $header, true);
      $nameIndex = array_search('Name', $header, true);
      $zipIndex = array_search('Zip', $header, true);
      // 2026-08-18: for the editable Color dropdown below - itemIndex is
      // needed alongside colorIndex since which color LIST a row gets
      // (Gildan vs. filament, or none) depends on that row's own Item.
      $colorIndex = array_search('Color', $header, true);
      $itemIndex = array_search('Item', $header, true);
      // 2026-08-23 (#2): for the Item-edit form's current-value display
      // - same optional-column tolerance as everywhere else here.
      $quantityIndex = array_search('Quantity', $header, true);
      $sizeIndex = array_search('Size', $header, true);
      $sleeveIndex = array_search('Sleeve', $header, true);
      // 2026-08-23: Cancelled - see the checkbox branch below (same
      // blank-or-date pattern as Created/Fulfilled/Pymt Date). Missing
      // entirely (Steve hasn't added the column to the live CSV yet) is
      // the same as "nothing is cancelled" everywhere this is checked -
      // the feature is inert until the column exists, never an error.
      $cancelledIndex = array_search('Cancelled', $header, true);

      // Table render order: MERCH_ADMIN_COLUMN_ORDER's columns first (in
      // that order), then every other column the CSV actually has, in
      // its existing order - so nothing defined above ever disappears,
      // it just isn't front-and-center. $columnIndexByName lets both
      // render loops below look up "which position is this column at in
      // $data" by name instead of assuming display order matches file
      // order (Finding 1, 2026-08-19 code review, is exactly why this
      // codebase keys CSV columns by name rather than position).
      $displayOrder = array_values(array_unique(array_merge(
          array_intersect(MERCH_ADMIN_COLUMN_ORDER, $header),
          $header
      )));
      $columnIndexByName = array_flip($header);

      // ---- Pre-scan: group rows into "shipments" the same way
      // shippo_export.php and packing_slips.php already do - normalized
      // Name + Zip, NOT Invoice Date. (shippo_export.php tried Invoice
      // Date grouping first and rejected it on 2026-07-26: two orders
      // invoiced on different days but going to the same person/address
      // need to combine into one shipment, not split apart.) Needs
      // Shipping should only show a row once EVERY other paid/Ship/
      // unfulfilled row bound for the same address is also Created -
      // Steve ships the whole box together, not whichever parts happen
      // to be done first (2026-08-15, per Steve).
      $shipmentAllCreated = []; // normalized "name|zip" -> bool
      if ($nameIndex !== false && $zipIndex !== false) {
          foreach ($rows as $data) {
              $rPaid = $pymtDateIndex !== false && trim($data[$pymtDateIndex] ?? '') !== '';
              $rShipping = $fulfillmentIndex !== false && trim($data[$fulfillmentIndex] ?? '') === 'Ship';
              $rFulfilled = $fulfilledIndex !== false && trim($data[$fulfilledIndex] ?? '') !== '';
              // 2026-08-23: a cancelled row (even one that was somehow
              // paid before being cancelled) should never hold back a
              // shipment group waiting on its own Created status - it's
              // never going to be created or ship.
              $rCancelled = $cancelledIndex !== false && trim($data[$cancelledIndex] ?? '') !== '';
              if (!$rPaid || !$rShipping || $rFulfilled || $rCancelled) {
                  continue; // not part of "what needs to ship" yet - doesn't gate the group
              }
              // Shared with shippo_export.php/packing_slips.php as of
              // 2026-08-20 (Finding 10, 2026-08-19 code review) - same
              // grouping-key formula, so this page's "shipment ready"
              // indicator can never silently disagree with what those
              // two tools consider the same shipment.
              $groupKey = merch_shipment_key($data[$nameIndex] ?? '', $data[$zipIndex] ?? '');
              $rCreated = $createdIndex !== false && trim($data[$createdIndex] ?? '') !== '';
              if (!isset($shipmentAllCreated[$groupKey])) {
                  $shipmentAllCreated[$groupKey] = true;
              }
              if (!$rCreated) {
                  $shipmentAllCreated[$groupKey] = false;
              }
          }
      }

      if (!empty($rows)) {
          echo '<div class="merch-filter-bar" style="margin-bottom:10px;">';
          echo '<button type="button" class="btn merch-view-btn" data-view="all" style="margin-right:8px; padding:4px 12px; font-size:0.85em;">All</button>';
          echo '<button type="button" class="btn merch-view-btn" data-view="active" style="margin-right:8px; padding:4px 12px; font-size:0.85em;">Active (hide Fulfilled)</button>';
          echo '<button type="button" class="btn merch-view-btn" data-view="needs-invoicing" style="margin-right:8px; padding:4px 12px; font-size:0.85em;">Needs Invoicing</button>';
          echo '<button type="button" class="btn merch-view-btn" data-view="needs-payment" style="margin-right:8px; padding:4px 12px; font-size:0.85em;">Needs Payment</button>';
          echo '<button type="button" class="btn merch-view-btn" data-view="needs-creating" style="margin-right:8px; padding:4px 12px; font-size:0.85em;">Needs Creating</button>';
          echo '<button type="button" class="btn merch-view-btn" data-view="needs-shipping" style="padding:4px 12px; font-size:0.85em;">Needs Shipping</button>';
          // 2026-08-23: independent of the named views above (which
          // never show a cancelled row, full stop - nothing to act on
          // there) - this is a manual override for browsing/auditing,
          // layered on top of whichever view is active. Off by default,
          // which is the actual fix for "the list is growing lengthy
          // and I don't want to scroll past abandoned orders" (Steve,
          // 2026-08-23).
          echo '<label style="margin-left:16px; font-size:0.85em; font-weight:normal; white-space:nowrap;"><input type="checkbox" id="merch-show-cancelled" /> Show cancelled</label>';
          echo '</div>';
          echo '<div class="merch-table-pane"><table style="width:100%; border-collapse: collapse;">';
          echo '<tr>';
          foreach ($displayOrder as $col) {
              echo '<th style="padding:6px; text-align:left; white-space:nowrap;">' . htmlspecialchars($col) . '</th>';
          }
          echo '</tr>';

          foreach ($rows as $data) {
              $orderId = $orderIdIndex !== false ? ($data[$orderIdIndex] ?? '') : '';
              // 2026-08-18: computed up front (not inside the per-cell
              // loop below) since the Color cell needs to know this
              // row's Item regardless of which column comes first in
              // the CSV's header order.
              $rowItem = $itemIndex !== false ? trim($data[$itemIndex] ?? '') : '';
              $rowIsCreated = $createdIndex !== false && trim($data[$createdIndex] ?? '') !== '';
              $rowIsFulfilled = $fulfilledIndex !== false && trim($data[$fulfilledIndex] ?? '') !== '';
              $rowIsInvoiced = $invoiceDateIndex !== false && trim($data[$invoiceDateIndex] ?? '') !== '';
              $rowIsPaid = $pymtDateIndex !== false && trim($data[$pymtDateIndex] ?? '') !== '';
              $rowIsShipping = $fulfillmentIndex !== false && trim($data[$fulfillmentIndex] ?? '') === 'Ship';
              $rowIsCancelled = $cancelledIndex !== false && trim($data[$cancelledIndex] ?? '') !== '';
              // Same normalized Name+Zip key as the pre-scan above -
              // defaults to "ready" (true) when Name/Zip columns are
              // missing or this row isn't part of any tracked shipment,
              // so it never blocks a row that the pre-scan didn't cover.
              $rowShipmentKey = ($nameIndex !== false && $zipIndex !== false)
                  ? merch_shipment_key($data[$nameIndex] ?? '', $data[$zipIndex] ?? '')
                  : '';
              $rowShipmentReady = $shipmentAllCreated[$rowShipmentKey] ?? true;
              echo '<tr data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-created="' . ($rowIsCreated ? '1' : '0') . '" data-fulfilled="' . ($rowIsFulfilled ? '1' : '0') . '" data-invoiced="' . ($rowIsInvoiced ? '1' : '0') . '" data-paid="' . ($rowIsPaid ? '1' : '0') . '" data-shipping="' . ($rowIsShipping ? '1' : '0') . '" data-shipment-ready="' . ($rowShipmentReady ? '1' : '0') . '" data-cancelled="' . ($rowIsCancelled ? '1' : '0') . '">';
              foreach ($displayOrder as $col) {
                  $i = $columnIndexByName[$col] ?? null;
                  if ($i === null) {
                      continue; // $displayOrder is derived from $header, so this shouldn't happen - skip rather than warn on a missing index
                  }
                  $cell = $data[$i] ?? '';
                  if ($col === 'Created' || $col === 'Fulfilled' || $col === 'Pymt Date' || $col === 'Cancelled') {
                      // All four are the same pattern now: one column,
                      // checkbox toggles it, blank-or-date is the whole
                      // status. Fulfilled and Pymt Date also cascade
                      // into another column server-side (see
                      // merch_update.php) - the shared JS handler below
                      // updates whichever second cell that touches,
                      // found via matching data-order-id + data-field.
                      // Cancelled (2026-08-23) has no cascade - see
                      // merch_update.php - and no gating on this end
                      // either: it can be checked/unchecked at any
                      // point in an order's life, invoiced or not, paid
                      // or not. Checking it hides the row from every
                      // view below except with "Show cancelled" on, and
                      // (see merch_invoice.php) keeps it out of a future
                      // combined invoice - this is now the one mechanism
                      // for both "customer cancelled the whole order"
                      // and "remove just this one line before
                      // invoicing" (Steve, 2026-08-23: same net effect
                      // as a hard delete, minus the risk of one - just
                      // uncheck it to bring a row back).
                      $fieldName = $col;
                      $isChecked = trim($cell) !== '';
                      // Accept ANY format PHP can parse as a real date,
                      // not just strict YYYY-MM-DD - some early rows
                      // have hand-typed dates like "7/24/2026" from
                      // before this system tracked dates automatically,
                      // and those are legitimate dates, not the kind of
                      // ambiguous old paper-list marker this fallback
                      // text was actually meant for. Only genuinely
                      // non-date content (or a bare checked-but-blank
                      // legacy marker) falls back to the placeholder.
                      $trimmedCell = trim($cell);
                      $looksLikeDate = $trimmedCell !== '' && strtotime($trimmedCell) !== false;
                      $displayValue = $looksLikeDate ? $trimmedCell : ($isChecked ? '(marked before this page existed)' : '');
                      echo '<td style="padding:6px; border-bottom:1px solid #eee; text-align:center; white-space:nowrap;">';
                      // aria-label added 2026-08-20 (Finding 17,
                      // 2026-08-19 code review) - with no <label> and
                      // no visible text next to the checkbox itself, a
                      // screen reader previously announced just
                      // "checkbox, checked" with no idea which order or
                      // field it belonged to. The values are already
                      // available here, so no new markup/data needed.
                      echo '<input type="checkbox" class="merch-status-toggle" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-field="' . htmlspecialchars($fieldName, ENT_QUOTES) . '" aria-label="' . htmlspecialchars($fieldName . ' - order ' . $orderId, ENT_QUOTES) . '" ' . ($isChecked ? 'checked' : '') . ' />';
                      echo '<br /><span class="merch-status-date" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-field="' . htmlspecialchars($fieldName, ENT_QUOTES) . '" style="font-size:0.8em; color:#888;">' . htmlspecialchars($displayValue) . '</span>';
                      echo '</td>';
                  } elseif ($col === 'Invoice Date') {
                      // Not a checkbox - clicking this sends a real
                      // email (and may stamp several OTHER rows too, if
                      // this customer has other un-invoiced orders), so
                      // it's a button until a date exists. Wrapped in a
                      // span the Pymt Date cascade can swap to a plain
                      // date if Paid gets checked first and backfills
                      // this column before you ever click the button.
                      $invoicedDate = trim($cell);
                      echo '<td style="padding:6px; border-bottom:1px solid #eee; text-align:center; white-space:nowrap;">';
                      echo '<span class="merch-status-date" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-field="Invoice Date">';
                      if ($invoicedDate !== '') {
                          echo '<span style="font-size:0.85em; color:#888;">' . htmlspecialchars($invoicedDate) . '</span>';
                          // 2026-08-23 (Steve): "jumped the gun and hit
                          // Send Invoice before the customer's last item
                          // came in" - this clears Invoice Date (via
                          // merch_update.php's new one-way clear-only
                          // handling) so a later Send Invoice click
                          // picks up every un-invoiced row for the
                          // customer, this one included, instead of
                          // leaving it stranded on its own. Only offered
                          // once Pymt Date is blank - if it's already
                          // been paid against, the premature invoice's
                          // total may not match what the corrected one
                          // will be, and that mismatch needs Steve's own
                          // judgment, not a button. This does NOT
                          // un-send whatever already went out - see the
                          // confirm text below.
                          if (!$rowIsPaid) {
                              echo ' <button type="button" class="btn merch-uninvoice-btn" style="padding:2px 6px; font-size:0.7em; background:#888;" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '">Un-invoice</button>';
                          }
                      } elseif ($rowIsCancelled) {
                          // No Send Invoice button on a cancelled,
                          // never-invoiced row - matches the server-side
                          // guard in merch_invoice.php that refuses to
                          // treat a cancelled row as an invoice anchor.
                          echo '<span style="font-size:0.85em; color:#bbb;">&mdash;</span>';
                      } else {
                          echo '<button type="button" class="btn merch-invoice-btn" style="padding:4px 10px; font-size:0.85em;" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '">Send Invoice</button>';
                      }
                      echo '</span>';
                      echo '</td>';
                  } elseif ($col === 'Color') {
                      // 2026-08-18: editable color, for customers who
                      // change their mind after ordering. Click the
                      // displayed value to reveal a <select> populated
                      // from THIS row's own item (merch_color_options_for_
                      // item() in pricing.php - the same list merch.php's
                      // request form itself offers); shared JS handler
                      // below posts the change to merch_update.php.
                      // Items with no color choice at all (colorOptions
                      // empty) fall back to plain text, same as before
                      // this feature existed.
                      $currentColor = trim($cell);
                      $colorOptions = $itemIndex !== false ? merch_color_options_for_item($rowItem) : [];
                      echo '<td style="padding:6px; border-bottom:1px solid #eee; white-space:nowrap;">';
                      if (!empty($colorOptions)) {
                          echo '<button type="button" class="merch-color-display" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" aria-label="Color: ' . htmlspecialchars($currentColor !== '' ? $currentColor : 'none', ENT_QUOTES) . ' - click to edit">' . htmlspecialchars($currentColor !== '' ? $currentColor : '(none)') . '</button>';
                          echo '<select class="merch-color-edit" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-original-value="' . htmlspecialchars($currentColor, ENT_QUOTES) . '" hidden>';
                          // Explicit blank prompt option, same as
                          // merch.php's own request-form dropdown - matters
                          // here because a blank stored Color has to map
                          // to a REAL selected option (this one), not just
                          // "none of the below happen to match," or the
                          // browser would default to showing the first
                          // real color as if it had been chosen.
                          $blankSelected = ($currentColor === '') ? ' selected' : '';
                          echo '<option value=""' . $blankSelected . '>Select a color&hellip;</option>';
                          // If the stored value isn't (or is no longer) a
                          // valid option for this item - an older order,
                          // or the color list changed since it was
                          // placed - add it as an extra option so simply
                          // opening the dropdown never silently discards
                          // it; it stays put until the admin actually
                          // picks something else.
                          if ($currentColor !== '' && !in_array($currentColor, $colorOptions, true)) {
                              echo '<option value="' . htmlspecialchars($currentColor, ENT_QUOTES) . '" selected>' . htmlspecialchars($currentColor) . ' (current \xe2\x80\x93 no longer a valid choice)</option>';
                          }
                          foreach ($colorOptions as $opt) {
                              $selected = ($opt === $currentColor) ? ' selected' : '';
                              echo '<option value="' . htmlspecialchars($opt, ENT_QUOTES) . '"' . $selected . '>' . htmlspecialchars($opt) . '</option>';
                          }
                          echo '</select>';
                          // Same two chart images merch.php's own photo
                          // viewer uses - so the admin isn't picking
                          // "CM Blue" vs. "Sky Blue" from memory alone.
                          $chartImage = in_array($rowItem, FILAMENT_COLOR_ITEMS, true) ? 'images/filament-color-chart.jpg' : 'images/color-chart.jpg';
                          echo ' <a href="' . htmlspecialchars($chartImage, ENT_QUOTES) . '" target="_blank" rel="noopener" style="font-size:0.75em;">chart</a>';
                      } else {
                          echo htmlspecialchars($currentColor);
                      }
                      echo '</td>';
                  } elseif ($col === 'Item') {
                      // 2026-08-23 (#2, Steve: "I ordered two of x,
                      // meant x and y") - lets a not-yet-invoiced
                      // line's Item/Quantity/Color/Size/Sleeve be
                      // corrected in place via merch_edit_line.php,
                      // which reuses merch_order.php's OWN validation
                      // and pricing (merch_group_calculate(), one
                      // line) instead of Steve hand-typing a price
                      // into the raw CSV. Only offered pre-invoice,
                      // same reasoning as the Cancel/Send-Invoice guard
                      // elsewhere on this page: an already-sent invoice
                      // shouldn't silently stop matching what's in the
                      // file. The current values ride along on the
                      // button's own data-* attributes so the JS below
                      // doesn't have to scrape sibling cells (which
                      // aren't guaranteed adjacent - see
                      // MERCH_ADMIN_COLUMN_ORDER above).
                      $rowQuantityForEdit = $quantityIndex !== false ? trim($data[$quantityIndex] ?? '') : '1';
                      $rowColorForEdit = $colorIndex !== false ? trim($data[$colorIndex] ?? '') : '';
                      $rowSizeForEdit = $sizeIndex !== false ? trim($data[$sizeIndex] ?? '') : '';
                      $rowSleeveForEdit = $sleeveIndex !== false ? trim($data[$sleeveIndex] ?? '') : '';
                      echo '<td style="padding:6px; border-bottom:1px solid #eee; white-space:nowrap;" class="merch-item-cell">';
                      echo '<span class="merch-item-display">' . htmlspecialchars($cell);
                      if (!$rowIsInvoiced) {
                          echo ' <button type="button" class="merch-item-edit-btn" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-item="' . htmlspecialchars($rowItem, ENT_QUOTES) . '" data-qty="' . htmlspecialchars($rowQuantityForEdit, ENT_QUOTES) . '" data-color="' . htmlspecialchars($rowColorForEdit, ENT_QUOTES) . '" data-size="' . htmlspecialchars($rowSizeForEdit, ENT_QUOTES) . '" data-sleeve="' . htmlspecialchars($rowSleeveForEdit, ENT_QUOTES) . '">Edit</button>';
                      }
                      echo '</span>';
                      echo '</td>';
                  } elseif ($col === 'Name') {
                      // 2026-08-23: Fulfillment already exists as
                      // 'Ship'/'Pickup at retreat' (merch_order.php) -
                      // this just surfaces it inline next to Name, since
                      // Fulfillment itself isn't in
                      // MERCH_ADMIN_COLUMN_ORDER and can scroll
                      // off-screen. Needs Payment's filter below now
                      // also hides these by default - Steve already
                      // knows they're outstanding and settles them in
                      // person at the retreat, so this tag is just "so
                      // I can still spot them" when scanning other
                      // views, not a call to action.
                      echo '<td style="padding:6px; border-bottom:1px solid #eee;">' . htmlspecialchars($cell);
                      if (!$rowIsShipping) {
                          echo ' <span style="display:inline-block; padding:1px 6px; font-size:0.7em; background:#eef; color:#448; border-radius:3px; vertical-align:middle;">Pickup</span>';
                      }
                      echo '</td>';
                  } else {
                      echo '<td style="padding:6px; border-bottom:1px solid #eee;">' . htmlspecialchars($cell) . '</td>';
                  }
              }
              echo '</tr>';
          }
          echo '</table></div>';
      } else {
          echo '<p style="text-align:center;">No merch requests yet.</p>';
      }
      ?>

      <div class="button-container" style="text-align:center; margin-top:20px;">
        <p style="margin:0;">
          <!-- Same click does two things: the normal navigation downloads
               the Shippo CSV (Content-Disposition: attachment, so the tab
               never actually navigates away), and the onclick pops the
               packing checklist - covering the identical paid/Ship/not-
               yet-Fulfilled batch - open in a new tab alongside it. -->
          <a href="shippo_export.php" onclick="window.open('packing_slips.php', '_blank'); return true;" style="color: var(--accent);">Download Shippo Export (paid, unshipped orders) &rarr;</a>
          &nbsp;&mdash;&nbsp;
          <a href="export_emails.php" style="color: var(--accent);">Download Customer Emails &rarr;</a>
          &nbsp;&mdash;&nbsp;
          <span style="color:#bbb; font-size:0.75em;">Build 2026-08-23-C</span>
        </p>
      </div>
    </div>
  </div>

  <script>
    // 2026-08-23 (#2): catalog data for the Item-edit form below - see
    // the PHP that builds $merchEditCatalog near the top of this file.
    const MERCH_EDIT_CATALOG = <?= json_encode($merchEditCatalog) ?>;

    // Created, Fulfilled, and Pymt Date all use the exact same pattern
    // now: one checkbox, one date column. Fulfilled and Pymt Date also
    // cascade into another column server-side (merch_update.php) - when
    // that happens, this same handler finds and updates the SECOND
    // cell too, matched by data-order-id + data-field rather than DOM
    // position, so it works the same regardless of which columns are
    // next to each other in the table.
    function updateStatusSpan(orderId, fieldName, value) {
      const span = document.querySelector(`.merch-status-date[data-order-id="${CSS.escape(orderId)}"][data-field="${CSS.escape(fieldName)}"]`);
      if (!span) return;
      if (fieldName === 'Invoice Date') {
        // This span might currently contain the "Send Invoice" button
        // instead of plain text (if nobody's clicked it yet) - replace
        // its contents outright rather than assuming it's already text.
        span.textContent = value;
      } else {
        span.textContent = value;
        const checkbox = document.querySelector(`.merch-status-toggle[data-order-id="${CSS.escape(orderId)}"][data-field="${CSS.escape(fieldName)}"]`);
        if (checkbox) checkbox.checked = value !== '';
      }
    }

    document.querySelectorAll('.merch-status-toggle').forEach((box) => {
      box.addEventListener('change', () => {
        const orderId = box.dataset.orderId;
        const field = box.dataset.field;
        const checked = box.checked;
        const previousChecked = !checked;

        box.disabled = true;
        fetch('merch_update.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `orderId=${encodeURIComponent(orderId)}&field=${encodeURIComponent(field)}&checked=${checked ? '1' : '0'}`
        })
          .then((r) => r.json())
          .then((data) => {
            if (data.ok) {
              updateStatusSpan(orderId, field, data.value);
              if (data.cascadeField) {
                updateStatusSpan(orderId, data.cascadeField, data.cascadeValue);
              }
            } else {
              alert('Could not save: ' + (data.error || 'unknown error'));
              box.checked = previousChecked;
            }
          })
          .catch(() => {
            alert('Could not save - check your connection and try again.');
            box.checked = previousChecked;
          })
          .finally(() => {
            box.disabled = false;
          });
      });
    });

    // Editable Color cell - click the displayed value to reveal the
    // <select> (rendered but hidden server-side, populated with only
    // that row's own valid colors - see the Color branch in the PHP
    // above). Picking a new value posts it to merch_update.php the same
    // way the status checkboxes do, then swaps back to plain text.
    document.querySelectorAll('.merch-color-display').forEach((span) => {
      span.addEventListener('click', () => {
        const select = span.nextElementSibling;
        if (!select || !select.classList.contains('merch-color-edit')) return;
        span.hidden = true;
        select.hidden = false;
        select.focus();
      });
    });

    document.querySelectorAll('.merch-color-edit').forEach((select) => {
      const span = select.previousElementSibling;

      // Clicking away without picking a different color just closes the
      // dropdown back to plain text - no request needed. Guarded on
      // select.disabled so this doesn't fire mid-save and hide the
      // dropdown before the fetch below has a chance to update it.
      select.addEventListener('blur', () => {
        if (!select.disabled && select.value === select.dataset.originalValue) {
          select.hidden = true;
          span.hidden = false;
        }
      });

      select.addEventListener('change', () => {
        const orderId = select.dataset.orderId;
        const newValue = select.value;
        const previousValue = select.dataset.originalValue;

        select.disabled = true;
        fetch('merch_update.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `orderId=${encodeURIComponent(orderId)}&field=Color&value=${encodeURIComponent(newValue)}`
        })
          .then((r) => r.json())
          .then((data) => {
            if (data.ok) {
              span.textContent = data.value !== '' ? data.value : '(none)';
              select.dataset.originalValue = data.value;
            } else {
              alert('Could not save: ' + (data.error || 'unknown error'));
              select.value = previousValue;
            }
          })
          .catch(() => {
            alert('Could not save - check your connection and try again.');
            select.value = previousValue;
          })
          .finally(() => {
            select.disabled = false;
            select.hidden = true;
            span.hidden = false;
          });
      });
    });

    // 2026-08-23 (#2): "Edit" button next to a not-yet-invoiced Item
    // (see the Item branch in the PHP above) - lets Item/Quantity/
    // Color/Size/Sleeve be corrected in place via merch_edit_line.php,
    // which reuses merch_order.php's own validation and pricing
    // (merch_group_calculate()) so a corrected line is priced exactly
    // the way a brand-new one would be, never a hand-typed number.
    // Reload on success since Price/Tax/Shipping - separate cells,
    // possibly off in a column that's scrolled out of view - change
    // too.
    function buildSelect(className, options, currentValue, placeholderLabel, includeBlank) {
      const select = document.createElement('select');
      select.className = className;
      if (includeBlank) {
        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = placeholderLabel;
        if (currentValue === '') blank.selected = true;
        select.appendChild(blank);
      }
      options.forEach((opt) => {
        const o = document.createElement('option');
        o.value = opt;
        o.textContent = opt;
        if (opt === currentValue) o.selected = true;
        select.appendChild(o);
      });
      return select;
    }

    // Rebuilds the Color/Size/Sleeve controls to match whatever Item is
    // currently selected - called once when the form first opens (with
    // the row's real current values) and again every time the Item
    // dropdown changes (with blanks, since an old selection may not
    // even apply to the new item - a filament color on a Gildan shirt,
    // say - so this forces a deliberate re-pick rather than silently
    // carrying over something invalid).
    function refreshItemEditExtras(form, item, currentColor, currentSize, currentSleeve) {
      const meta = MERCH_EDIT_CATALOG.itemMeta[item] || { colors: [], isShirt: false };
      const colorWrap = form.querySelector('.merch-edit-color-wrap');
      const sizeWrap = form.querySelector('.merch-edit-size-wrap');
      const sleeveWrap = form.querySelector('.merch-edit-sleeve-wrap');
      colorWrap.innerHTML = '';
      sizeWrap.innerHTML = '';
      sleeveWrap.innerHTML = '';

      if (meta.colors.length > 0) {
        const label = document.createElement('label');
        label.appendChild(document.createTextNode('Color'));
        label.appendChild(document.createElement('br'));
        label.appendChild(buildSelect('merch-edit-color', meta.colors, currentColor, 'Select a color…', true));
        colorWrap.appendChild(label);
      }
      if (meta.isShirt) {
        const sizeLabel = document.createElement('label');
        sizeLabel.appendChild(document.createTextNode('Size'));
        sizeLabel.appendChild(document.createElement('br'));
        sizeLabel.appendChild(buildSelect('merch-edit-size', MERCH_EDIT_CATALOG.sizes, currentSize, 'Select a size…', true));
        sizeWrap.appendChild(sizeLabel);

        const sleeveLabel = document.createElement('label');
        sleeveLabel.appendChild(document.createTextNode('Sleeve'));
        sleeveLabel.appendChild(document.createElement('br'));
        sleeveLabel.appendChild(buildSelect('merch-edit-sleeve', MERCH_EDIT_CATALOG.sleeves, currentSleeve, 'Select a sleeve length…', true));
        sleeveWrap.appendChild(sleeveLabel);
      }
    }

    function restoreItemDisplay(cell, orderId, current) {
      cell.innerHTML = '';
      const span = document.createElement('span');
      span.className = 'merch-item-display';
      span.appendChild(document.createTextNode(current.item + ' '));
      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'merch-item-edit-btn';
      editBtn.textContent = 'Edit';
      editBtn.dataset.orderId = orderId;
      editBtn.dataset.item = current.item;
      editBtn.dataset.qty = current.quantity;
      editBtn.dataset.color = current.color;
      editBtn.dataset.size = current.size;
      editBtn.dataset.sleeve = current.sleeve;
      span.appendChild(editBtn);
      cell.appendChild(span);
      wireItemEditButton(editBtn);
    }

    function renderItemEditForm(cell, orderId, current) {
      const container = document.createElement('div');
      container.className = 'merch-item-edit-form';
      container.style.cssText = 'text-align:left; font-size:0.85em; border:1px solid #ddd; border-radius:6px; padding:8px; max-width:220px;';

      const itemLabel = document.createElement('label');
      itemLabel.appendChild(document.createTextNode('Item'));
      itemLabel.appendChild(document.createElement('br'));
      const itemSelect = buildSelect('merch-edit-item', MERCH_EDIT_CATALOG.items, current.item, '', false);
      itemLabel.appendChild(itemSelect);
      container.appendChild(itemLabel);
      container.appendChild(document.createElement('br'));

      const qtyLabel = document.createElement('label');
      qtyLabel.appendChild(document.createTextNode('Qty'));
      qtyLabel.appendChild(document.createElement('br'));
      const qtyInput = document.createElement('input');
      qtyInput.type = 'number';
      qtyInput.min = '1';
      qtyInput.step = '1';
      qtyInput.className = 'merch-edit-qty';
      qtyInput.value = current.quantity || '1';
      qtyInput.style.width = '60px';
      qtyLabel.appendChild(qtyInput);
      container.appendChild(qtyLabel);

      const colorWrap = document.createElement('div');
      colorWrap.className = 'merch-edit-color-wrap';
      colorWrap.style.marginTop = '6px';
      const sizeWrap = document.createElement('div');
      sizeWrap.className = 'merch-edit-size-wrap';
      sizeWrap.style.marginTop = '6px';
      const sleeveWrap = document.createElement('div');
      sleeveWrap.className = 'merch-edit-sleeve-wrap';
      sleeveWrap.style.marginTop = '6px';
      container.appendChild(colorWrap);
      container.appendChild(sizeWrap);
      container.appendChild(sleeveWrap);

      refreshItemEditExtras(container, current.item, current.color, current.size, current.sleeve);

      itemSelect.addEventListener('change', () => {
        refreshItemEditExtras(container, itemSelect.value, '', '', '');
      });

      const actions = document.createElement('div');
      actions.style.marginTop = '8px';
      const saveBtn = document.createElement('button');
      saveBtn.type = 'button';
      saveBtn.className = 'btn merch-edit-save';
      saveBtn.textContent = 'Save';
      saveBtn.style.cssText = 'padding:4px 8px; font-size:0.9em; margin-right:6px;';
      const cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'btn merch-edit-cancel';
      cancelBtn.textContent = 'Cancel';
      cancelBtn.style.cssText = 'padding:4px 8px; font-size:0.9em; background:#888;';
      actions.appendChild(saveBtn);
      actions.appendChild(cancelBtn);
      container.appendChild(actions);

      cancelBtn.addEventListener('click', () => {
        restoreItemDisplay(cell, orderId, current);
      });

      saveBtn.addEventListener('click', () => {
        const newItem = itemSelect.value;
        const newQty = qtyInput.value.trim();
        const colorSelect = container.querySelector('.merch-edit-color');
        const sizeSelect = container.querySelector('.merch-edit-size');
        const sleeveSelect = container.querySelector('.merch-edit-sleeve');
        const newColor = colorSelect ? colorSelect.value : '';
        const newSize = sizeSelect ? sizeSelect.value : '';
        const newSleeve = sleeveSelect ? sleeveSelect.value : '';

        if (!newQty || isNaN(newQty) || Number(newQty) < 1) {
          alert('Enter a quantity of 1 or more.');
          return;
        }
        if (!confirm(`Save this line as "${newItem}" x${newQty}? This recalculates Price/Tax/Shipping for this line.`)) {
          return;
        }

        saveBtn.disabled = true;
        cancelBtn.disabled = true;
        saveBtn.textContent = 'Saving…';

        const body = `orderId=${encodeURIComponent(orderId)}&item=${encodeURIComponent(newItem)}&quantity=${encodeURIComponent(newQty)}&color=${encodeURIComponent(newColor)}&size=${encodeURIComponent(newSize)}&sleeve=${encodeURIComponent(newSleeve)}`;

        fetch('merch_edit_line.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body,
        })
          .then((r) => r.json())
          .then((data) => {
            if (data.ok) {
              location.reload();
            } else {
              alert('Could not save: ' + (data.error || 'unknown error'));
              saveBtn.disabled = false;
              cancelBtn.disabled = false;
              saveBtn.textContent = 'Save';
            }
          })
          .catch(() => {
            alert('Could not save - check your connection and try again.');
            saveBtn.disabled = false;
            cancelBtn.disabled = false;
            saveBtn.textContent = 'Save';
          });
      });

      cell.innerHTML = '';
      cell.appendChild(container);
    }

    function wireItemEditButton(btn) {
      btn.addEventListener('click', () => {
        const cell = btn.closest('.merch-item-cell');
        if (!cell) return;
        const current = {
          item: btn.dataset.item,
          quantity: btn.dataset.qty,
          color: btn.dataset.color,
          size: btn.dataset.size,
          sleeve: btn.dataset.sleeve,
        };
        renderItemEditForm(cell, btn.dataset.orderId, current);
      });
    }

    document.querySelectorAll('.merch-item-edit-btn').forEach(wireItemEditButton);

    // Sending an invoice may stamp OTHER rows too (any other un-invoiced
    // order from the same customer, same payment type, same fulfillment
    // method, gets combined into the one email) - so on success we
    // reload the page rather than trying to guess which buttons/cells
    // to update in place.
    //
    // Pickup orders never email anything - see merch_invoice.php's
    // 2026-08-10 pickup-document branch. Instead the response carries
    // downloadDocument (the rendered markdown) and downloadFilename;
    // this triggers a normal browser file download via a throwaway
    // Blob + <a download>, then reloads the same as the email path.
    function triggerMarkdownDownload(filename, content) {
      const blob = new Blob([content], { type: 'text/markdown' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
    }

    function sendInvoice(btn, orderId, manualShipping) {
      const body = manualShipping !== undefined
        ? `orderId=${encodeURIComponent(orderId)}&manualShipping=${encodeURIComponent(manualShipping)}`
        : `orderId=${encodeURIComponent(orderId)}`;

      return fetch('merch_invoice.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
      }).then((r) => r.json());
    }

    function renderManualShippingForm(span, orderId, data) {
      const money = (n) => '$' + Number(n).toFixed(2);
      const itemsList = data.items.map((it) => {
        const bits = [it.item, `x${it.quantity}`];
        if (it.color) bits.push(it.color);
        if (it.size) bits.push(it.size);
        if (it.sleeve) bits.push(it.sleeve);
        return bits.join(' - ');
      }).join('<br>');

      span.innerHTML = `
        <div style="text-align:left; font-size:0.85em; border:1px solid #ddd; border-radius:6px; padding:8px; max-width:260px;">
          <div style="margin-bottom:6px;">${itemsList}</div>
          <div style="color:#666; margin-bottom:6px;">Subtotal ${money(data.subtotal)}${data.bundleDiscount > 0 ? ` &minus; bundle discount ${money(data.bundleDiscount)}` : ''} + tax ${money(data.tax)}</div>
          <input type="number" class="merch-manual-shipping-input" placeholder="Shipping $" min="0" step="0.01" style="width:90px; padding:4px;" />
          <button type="button" class="btn merch-manual-shipping-send" style="padding:4px 8px; font-size:0.9em;">Send</button>
          <button type="button" class="btn merch-manual-shipping-cancel" style="padding:4px 8px; font-size:0.9em; background:#888;">Cancel</button>
        </div>
      `;

      const input = span.querySelector('.merch-manual-shipping-input');
      const sendBtn = span.querySelector('.merch-manual-shipping-send');
      const cancelBtn = span.querySelector('.merch-manual-shipping-cancel');

      cancelBtn.addEventListener('click', () => {
        span.innerHTML = `<button type="button" class="btn merch-invoice-btn" style="padding:4px 10px; font-size:0.85em;" data-order-id="${orderId}">Send Invoice</button>`;
        wireInvoiceButton(span.querySelector('.merch-invoice-btn'));
      });

      sendBtn.addEventListener('click', () => {
        const shipping = input.value.trim();
        if (shipping === '' || isNaN(shipping) || Number(shipping) < 0) {
          alert('Enter a shipping amount of 0 or more.');
          return;
        }
        if (!confirm(`Send this invoice with $${Number(shipping).toFixed(2)} shipping?`)) {
          return;
        }
        sendBtn.disabled = true;
        cancelBtn.disabled = true;
        sendBtn.textContent = 'Sending…';

        sendInvoice(sendBtn, orderId, shipping)
          .then((data) => {
            if (data.ok) {
              location.reload();
            } else {
              alert('Could not send invoice: ' + (data.error || 'unknown error'));
              sendBtn.disabled = false;
              cancelBtn.disabled = false;
              sendBtn.textContent = 'Send';
            }
          })
          .catch(() => {
            alert('Could not send invoice - check your connection and try again.');
            sendBtn.disabled = false;
            cancelBtn.disabled = false;
            sendBtn.textContent = 'Send';
          });
      });
    }

    function wireInvoiceButton(btn) {
      btn.addEventListener('click', () => {
        const orderId = btn.dataset.orderId;
        const span = btn.closest('.merch-status-date');
        const tr = btn.closest('tr');
        const isPickup = tr && tr.dataset.shipping === '0';

        const confirmMsg = isPickup
          ? 'Generate a printable pickup invoice for this order (and any other un-invoiced pickup orders from the same customer)? Nothing is emailed.'
          : 'Send an invoice email for this order (and any other un-invoiced orders from the same customer)?';
        if (!confirm(confirmMsg)) {
          return;
        }

        btn.disabled = true;
        btn.textContent = 'Sending…';

        sendInvoice(btn, orderId)
          .then((data) => {
            if (data.ok) {
              if (data.downloadDocument) {
                triggerMarkdownDownload(data.downloadFilename, data.downloadDocument);
              }
              location.reload();
            } else if (data.needsManualQuote) {
              // Not a failure - this is a pure preview (no email sent,
              // see merch_invoice.php's check-vs-send split). Nothing
              // on this row changed (no Invoiced stamp), so no reload -
              // show the manual-shipping form right here instead.
              renderManualShippingForm(span, orderId, data);
            } else {
              alert('Could not send invoice: ' + (data.error || 'unknown error'));
              btn.disabled = false;
              btn.textContent = 'Send Invoice';
            }
          })
          .catch(() => {
            alert('Could not send invoice - check your connection and try again.');
            btn.disabled = false;
            btn.textContent = 'Send Invoice';
          });
      });
    }

    document.querySelectorAll('.merch-invoice-btn').forEach(wireInvoiceButton);

    // 2026-08-23: "Un-invoice" button next to an Invoice Date that
    // isn't paid yet (see the Invoice Date branch above) - clears the
    // column via merch_update.php's new one-way clear-only handling for
    // this field, so a later Send Invoice click can combine this row
    // with the rest of the customer's items instead of leaving it
    // stranded with its own premature date. Reload on success so the
    // cell swaps back to a normal Send Invoice button.
    document.querySelectorAll('.merch-uninvoice-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const orderId = btn.dataset.orderId;
        if (!confirm("Clear this order's Invoice Date so a future Send Invoice combines it with the rest of this customer's items? This does NOT un-send anything already emailed or handed to the customer.")) {
          return;
        }

        btn.disabled = true;
        btn.textContent = 'Un-invoicing…';

        fetch('merch_update.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `orderId=${encodeURIComponent(orderId)}&field=${encodeURIComponent('Invoice Date')}&checked=0`
        })
          .then((r) => r.json())
          .then((data) => {
            if (data.ok) {
              location.reload();
            } else {
              alert('Could not un-invoice: ' + (data.error || 'unknown error'));
              btn.disabled = false;
              btn.textContent = 'Un-invoice';
            }
          })
          .catch(() => {
            alert('Could not un-invoice - check your connection and try again.');
            btn.disabled = false;
            btn.textContent = 'Un-invoice';
          });
      });
    });

    // Named "view" buttons instead of independent Hide checkboxes -
    // each one answers a specific "what do I need to act on" question
    // rather than just hiding one column's completed rows. Only one
    // view is active at a time; clicking the active one again (or
    // clicking All) resets to showing everything. Uses the same
    // data-created/fulfilled/invoiced/paid attributes already on each
    // row - no new server-side computation needed.
    // Requested 2026-07-27 to replace the earlier checkbox filters,
    // which could only express "hide things that are done," not
    // "show me things done with X but not yet done with Y."
    const viewButtons = document.querySelectorAll('.merch-view-btn');
    // Restored from sessionStorage (not localStorage) on purpose - this
    // should survive the location.reload() after sending an invoice
    // (same tab, same work session) but not linger for weeks if Steve
    // comes back another day and would otherwise be confused why the
    // list looks pre-filtered. Falls back to 'all' the first time, or
    // in any other browser/tab.
    let currentView = sessionStorage.getItem('merchAdminView') || 'all';

    // 2026-08-23: "Show cancelled" - independent of the named views
    // (see the filter-bar comment in the PHP above). Persisted the same
    // way currentView is: survives a same-tab reload, resets to off
    // (the useful default) in a new tab/day.
    const showCancelledBox = document.getElementById('merch-show-cancelled');
    let showCancelled = sessionStorage.getItem('merchAdminShowCancelled') === '1';
    showCancelledBox.checked = showCancelled;
    showCancelledBox.addEventListener('change', () => {
      showCancelled = showCancelledBox.checked;
      sessionStorage.setItem('merchAdminShowCancelled', showCancelled ? '1' : '0');
      applyView(currentView);
    });

    function applyView(view) {
      currentView = view;
      sessionStorage.setItem('merchAdminView', view);
      viewButtons.forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.view === view);
      });
      document.querySelectorAll('table tr[data-fulfilled]').forEach((tr) => {
        const created = tr.dataset.created === '1';
        const fulfilled = tr.dataset.fulfilled === '1';
        const invoiced = tr.dataset.invoiced === '1';
        const paid = tr.dataset.paid === '1';
        const isShipping = tr.dataset.shipping === '1';
        const shipmentReady = tr.dataset.shipmentReady === '1';
        const cancelled = tr.dataset.cancelled === '1';

        let show = true;
        switch (view) {
          case 'active':
            show = !fulfilled;
            break;
          case 'needs-invoicing':
            show = !invoiced;
            break;
          case 'needs-payment':
            // 2026-08-23 (Steve): pickup orders go invoiced -> created ->
            // PAID (paid last, settled in person), not the normal
            // paid-before-created flow - an invoiced-but-unpaid pickup
            // order isn't something to act on here, it's expected and
            // gets handled at the retreat. Excluded from this view;
            // still tagged inline (see the Name cell above) and still
            // visible in All/Active.
            show = invoiced && !paid && isShipping;
            break;
          case 'needs-creating':
            show = paid && !created;
            break;
          case 'needs-shipping':
            // Matches shippo_export.php's own "safe to buy a label"
            // definition: Paid + Ship + not yet Fulfilled. PLUS Created,
            // per Steve (2026-08-02): a real ready-to-ship order needs
            // payment AND the physical part in hand, not just payment.
            // PLUS shipmentReady, per Steve (2026-08-15): Created is
            // checked for the whole shipment (same normalized Name+Zip
            // grouping as shippo_export.php/packing_slips.php), not just
            // this one row - a customer's order ships together, so one
            // un-printed item holds back every other item bound for the
            // same address, not just itself.
            show = paid && created && !fulfilled && isShipping && shipmentReady;
            break;
          case 'all':
          default:
            show = true;
        }
        // Layered on top of whatever the view above decided - a
        // cancelled row is excluded everywhere (named views included)
        // unless the override is on, in which case cancelled status
        // doesn't affect visibility at all.
        if (cancelled && !showCancelled) {
          show = false;
        }
        tr.style.display = show ? '' : 'none';
      });
    }

    viewButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        applyView(btn.dataset.view === currentView ? 'all' : btn.dataset.view);
      });
    });

    // Apply whatever view was restored (or the 'all' default) now that
    // the table and buttons above actually exist to apply it to.
    applyView(currentView);
  </script>
</body>
</html>
