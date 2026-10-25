<?php
// 2026-09-26: the build stamp is now a real variable, printed in the page
// footer (bottom of this page), instead of a comment plus a hand-typed
// footer label that had been stuck at "2026-09-20-B" while this file's own
// stamp moved on - which made a successful deploy look like a failed one.
// Because it's executed code, the footer shows the version PHP is actually
// RUNNING: if the footer is old after a deploy, it's stale OPcache (use
// Clear PHP Cache), not a failed upload. Bump this on every change,
// YYYY-MM-DD-[Letter], and it's the only place to bump.
$merchBuild = '2026-10-25-B';
require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require __DIR__ . '/pricing.php'; // 2026-08-18: for GILDAN_COLOR_ITEMS/FILAMENT_COLOR_ITEMS/merch_color_options_for_item() - powers the editable Color dropdown below
require __DIR__ . '/merch_shipments.php'; // 2026-08-20: for merch_shipment_key() - see Finding 10, 2026-08-19 code review
require __DIR__ . '/merch_stock.php'; // 2026-10-19: merch_stock_reduce_print_rows() - the plate pane counts the shelf
require __DIR__ . '/merch_printing.php'; // 2026-10-23: 'Currently printing' marks on the Create view's plates
require __DIR__ . '/merch_notes.php'; // 2026-10-22: customer order notes - the Notes view, its Reply link and the Handled log
require __DIR__ . '/print_plates.php'; // 2026-09-17: for print_plate_group_queue() - powers the "Sort by Print Plate" toggle below (checkbox, Needs Creating only, as of 2026-09-20)

// 2026-08-20 (Steve): working an order means glancing back and forth
// between a handful of fields, but they're spread across the CSV's 25
// columns (Name is #2, Color is #7, Invoice Date/Pymt Date/Created/
// Fulfilled are #20-23) - wide enough apart that they scroll off
// opposite edges of the screen at once. This reorders the ADMIN
// TABLE'S DISPLAY ONLY - it has no effect on merchandise.csv's own
// column order (still MERCH_CSV_HEADER, pricing.php), which every
// reader/writer keys by name, not position. Any header column not
// listed here (a future hand-added one, say) just falls in after
// these, in whatever order the CSV already has it - see $displayOrder
// below - rather than silently vanishing from the table.
// (2026-08-23: Item, Quantity, and Cancelled joined this list after it
// was first written - the list below is the current, accurate one;
// the "8 fields"/position callouts above describe the original
// 2026-08-20 shape, not a live count worth re-verifying on every edit.)
const MERCH_ADMIN_COLUMN_ORDER = [
    'OrderID', 'Name', 'Item', 'Quantity', 'Color',
    'Invoice Date', 'Pymt Date', 'Created', 'Fulfilled', 'Cancelled',
];

// 2026-10-05 (Steve): the Invoice and Payments views showed every column
// (address, timestamp, IP, email ...) when only a few matter for the job.
// Per-view column lists, applied in the browser by applyView() below: a
// column NOT listed is hidden while that view is active, and the other
// views (All, Create, Ship) still show everything. This only hides table
// cells - nothing is removed from the data or the page. Keep the column
// that carries the view's action button: Invoice Date holds Send Invoice,
// Pymt Date holds Mark Paid. Cancelled stays because it is how a single
// line is dropped before invoicing / an abandoned order is closed out.
// Edit these two lists to change what each view shows; names must match
// the table's column headers exactly.
const MERCH_VIEW_COLUMNS = [
    'needs-invoicing' => ['Name', 'Item', 'Color', 'Price', 'Invoice Date', 'Cancelled'],
    'needs-payment' => ['Name', 'Item', 'Color', 'Price', 'Invoice Date', 'Pymt Date', 'Cancelled'],
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
  <link rel="stylesheet" href="styles/base.css" />
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
    /* 2026-09-05 (Steve: printed the wrong quantity 4 times in a row -
       "I've had 4 separate messages from people who ordered 2x of the
       same item... in my haste to get these printed and shipped I read
       them as a single item order") - a plain "2" in the Quantity
       column looks exactly like every other cell until you're already
       reading that specific column closely, which isn't how this page
       actually gets scanned while working. Two reinforcing fixes below:
       tint the WHOLE row so a multi-quantity order is visible no matter
       which column your eye lands on (works even when just skimming
       Item names), and turn the Quantity cell itself into a loud badge
       for the moments you do look right at it. Both keyed off the same
       $rowQuantity > 1 check server-side - see the row loop below. */
    tr.merch-row-multi {
      background: #fff4e0;
    }
    tr.merch-row-multi:hover {
      background: #ffe9c2;
    }
    .merch-qty-badge {
      display: inline-block;
      min-width: 1.6em;
      padding: 2px 8px;
      border-radius: 999px;
      background: #b34700;
      color: #fff;
      font-weight: bold;
      font-size: 1.05em;
      text-align: center;
    }
    /* Same badge, smaller, riding right in front of the Item name -
       Steve's own description of the failure ("read them as a single
       item order") is about misreading the ITEM line, not the Quantity
       column specifically, so the flag needs to show up there too. */
    .qty-chip { display: inline-block; padding: 1px 7px; border-radius: 999px; background: #b34700; color: #fff; font-weight: bold; font-size: 0.85em; vertical-align: middle; }
    .merch-qty-flag {
      display: inline-block;
      margin-right: 6px;
      padding: 1px 7px;
      border-radius: 999px;
      background: #b34700;
      color: #fff;
      font-weight: bold;
      font-size: 0.85em;
      vertical-align: middle;
    }
    /* "Sort by Print Plate" pane (2026-09-17) - same visual language
       as packing_slips.php's existing "By Color" section (muted
       headings/secondary text, light divider), not reinvented here. */
    .print-plate-color-group {
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 1px solid #eee;
    }
    .print-plate-color-group:last-child {
      border-bottom: none;
    }
    .print-plate-color-heading {
      margin: 0 0 8px 0;
      font-size: 1.05em;
      color: #444;
    }
    .print-plate-group-block {
      margin: 0 0 14px 0;
    }
    .print-plate-group-block:last-child {
      margin-bottom: 0;
    }
    .print-plate-group-heading {
      margin: 0 0 4px 0;
      font-size: 0.9em;
      font-weight: bold;
      color: #555;
    }
    .print-plate-plate-list {
      list-style: none;
      margin: 0;
      padding: 0;
      font-size: 0.95em;
    }
    .print-plate-plate-list li {
      margin: 4px 0;
      padding: 4px 8px;
      background: #eef7ee;
      border-radius: 4px;
    }
    .print-plate-plate-list li.print-plate-partial {
      background: #f8f3e6;
    }
    /* 2026-10-23: Currently printing */
    .print-plate-now {
      background: #fff4e0;
      border: 1px solid #e0a040;
      border-radius: 6px;
      padding: 8px 14px;
      margin-bottom: 14px;
    }
    .print-plate-now ul { margin: 4px 0 0; padding-left: 20px; }
    .print-plate-plate-list li.print-plate-printing {
      background: #fff4e0;
      border-left: 4px solid #e0a040;
      padding-left: 8px;
    }
    .print-plate-badge {
      display: inline-block;
      background: #b36b00;
      color: #fff;
      font-weight: bold;
      font-size: 0.8em;
      padding: 1px 8px;
      border-radius: 3px;
      margin-right: 8px;
    }
    .print-plate-plate-fill {
      color: #667;
      font-size: 0.85em;
      font-weight: normal;
    }
    .print-plate-plate-orders {
      display: block;
      color: #777;
      font-size: 0.88em;
      margin-top: 2px;
    }
    /* 2026-09-20 (Steve, item #5): "A Print button to get a hard copy
       would also be useful." Scoped to just the print-plate pane rather
       than the whole admin page - the JS below adds this body class
       right before calling window.print() (and removes it on
       afterprint), so a plain Ctrl+P on the rest of the page is
       unaffected. The per-plate checkboxes print too, on purpose - a
       paper copy Steve can physically tick off at the printer is a
       reasonable bonus, not just a reference sheet. */
    @media print {
      .no-print {
        display: none !important;
      }
      body.merch-printing-plates > .content-wrapper > *:not(#merch-print-plate-pane) {
        display: none !important;
      }
      body.merch-printing-plates #merch-print-plate-pane {
        display: block !important;
        max-height: none !important;
        overflow: visible !important;
      }
    }
  </style>
</head>
<body>
  <div class="content-wrapper">
    <div class="page-container">
      <h2 style="margin-top:0;">Merchandise Requests</h2>

      <?php
      $csvFile = 'merchandise.csv';
      $rows = [];
      if (file_exists($csvFile) && filesize($csvFile) > 0) {
          if (($handle = fopen($csvFile, 'r')) !== false) {
              while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                  $rows[] = $row;
              }
              fclose($handle);
          }
      }

      // Read column names from the CSV's own header row rather than
      // hardcoding them here - with 25+ columns now (MERCH_CSV_HEADER,
      // pricing.php, plus Steve's hand-added Cancelled), hand-matching a
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
      // 2026-10-22 (Steve): customer order notes used to be invisible here.
      // One entry per order that has a note (merch_notes.php); $openNotes is
      // the not-yet-handled ones keyed by note key, which drives the Notes
      // button count, the Notes view and the marker on the other views.
      $noteEntries = merch_notes_collect($header, $rows);
      $openNotes = [];
      foreach ($noteEntries as $noteEntry) {
          if (!$noteEntry['handled']) {
              $openNotes[$noteEntry['key']] = $noteEntry;
          }
      }
      $notesIndex = array_search('Notes', $header, true);
      $groupIdIndex = array_search('OrderGroupID', $header, true);
      $emailIndex = array_search('Email', $header, true);
      $timestampIndexForNotes = array_search('Timestamp', $header, true);
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
      // 2026-09-20: Qty Created - per-line partial-completion count (see
      // merch_update.php's Qty Created header comment). Missing entirely
      // (a merchandise.csv that hasn't been migrated yet) behaves the
      // same as "nothing printed yet" everywhere it's read below - same
      // optional-column tolerance as every other column here.
      $qtyCreatedIndex = array_search('Qty Created', $header, true);
      // 2026-09-24: OrderGroupID - which lines belong to the same
      // multi-item purchase (see print_plate_group_queue()'s own
      // header comment for why the print-plate queue needs this).
      // Same optional-column tolerance as everywhere else here -
      // missing entirely just means every row is its own group, same
      // as it behaved before this column existed.
      $orderGroupIdIndex = array_search('OrderGroupID', $header, true);
      // 2026-10-07: Timestamp (order placed) - with Pymt Date, how long a
      // customer has been waiting, which orders the plates in the Sort by
      // Print Plate view (see print_plates.php). Optional like the rest.
      $timestampIndex = array_search('Timestamp', $header, true);
      $retreatIndex = array_search('Retreat', $header, true); // 2026-10-19: replacement orders jump the print queue

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
      // 2026-09-17: accumulated below (same loop as the table rows) for
      // the "Sort by Print Plate" pane - one entry per row that meets
      // the same "needs creating" criteria the needs-creating named
      // view uses. See print_plates.php.
      $printPlateRows = [];
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
              // 2026-10-15 (Steve): a shirt/hat never gates a printed
              // shipment (Janet ships those separately). Matches
              // packing_slips.php and shippo_export.php.
              if ($itemIndex !== false && !merch_is_printed_item(trim($data[$itemIndex] ?? ''))) {
                  continue;
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
          // 2026-09-28 (Steve): retired "Active (hide Fulfilled)" - the
          // narrower named views (Needs Invoicing/Payment/Creating/
          // Shipping) already cover the actual workflow steps, and this
          // one rarely got used. The 'active' case is left in applyView()
          // below rather than deleted outright, purely so a stale
          // sessionStorage value from before this change (someone's tab
          // still holding 'active' as merchAdminView) degrades to the
          // same "show everything not fulfilled" behavior it always had,
          // instead of silently landing on a different view.
          // 2026-10-05 (Steve): shortened labels - Invoice / Payments /
          // Create / Ship (the full "Needs ..." name stays as the hover
          // tooltip), "Cancelled" and "P/U @ Retreat" for the two
          // checkboxes. Labels only: data-view values, element ids and all
          // the JS are unchanged. Sort by Print Plate is deliberately not
          // trimmed.
          // 2026-10-19 (Steve): the "All" button is gone. The four view buttons
          // are on/off toggles: pressing one turns off whichever was on (radio
          // style), pressing the lit one turns it off, and "nothing lit" IS the
          // All view (no filter). The old "Active" case and the stale-value
          // fallback for it are gone too (see applyView()).
          echo '<button type="button" class="btn merch-view-btn" data-view="needs-invoicing" aria-pressed="false" style="margin-right:8px; padding:4px 12px; font-size:0.75em;" title="Needs Invoicing">Invoice</button>';
          echo '<button type="button" class="btn merch-view-btn" data-view="needs-payment" aria-pressed="false" style="margin-right:8px; padding:4px 12px; font-size:0.75em;" title="Needs Payment">Payments</button>';
          // 2026-10-25 (Steve): Create now opens Print Plan (ourprint.php), which replaces the old Create view; the old view is still reachable via ?view=needs-creating (Print Plan's "+ Shirts/Hats/P/U" box uses it).
          echo '<a class="btn" href="ourprint.php" style="margin-right:8px; padding:4px 12px; font-size:0.75em; text-decoration:none;" title="Print Plan">Print Plan' . (count(merch_printing_active()) > 0 ? ' <span style="font-size:0.8em;">(&#128424; ' . count(merch_printing_active()) . ' printing)</span>' : '') . '</a>';
          echo '<button type="button" class="btn merch-view-btn" data-view="needs-shipping" aria-pressed="false" style="margin-right:8px; padding:4px 12px; font-size:0.75em;" title="Needs Shipping">Ship</button>';
          // 2026-10-22 (Steve): customer order notes. The count is the notes not yet marked Handled.
          echo '<button type="button" class="btn merch-view-btn" id="merch-notes-btn" data-view="notes" aria-pressed="false" style="margin-right:8px; padding:4px 12px; font-size:0.75em;" title="Customer notes on orders">Notes' . (count($openNotes) > 0 ? ' (<span id="merch-notes-count">' . count($openNotes) . '</span>)' : ' <span id="merch-notes-count"></span>') . '</button>';
          // 2026-09-17 (Steve): "sort the Needs Creating list to give me
          // an optimized list of same color gadgets I can print
          // together." NOT another data-view case below (applyView()
          // shows/hides existing rows; this groups/aggregates them
          // instead), so it's a separate toggle with its own pane -
          // see #merch-print-plate-pane below and print_plates.php.
          // Groups the same Needs Creating queue by color and packs it
          // against Steve's own known plate combos and per-item
          // capacities (print_plates.php's PRINT_PLATE_TEMPLATES /
          // PRINT_PLATE_SOLO_CAPACITY); 2026-09-20 it also carries
          // per-plate completion checkboxes (see the pane markup below).
          //
          // 2026-09-20 (Steve): "unlike any of the other buttons - you
          // can click it from any view... It should really be a checkbox
          // that only appears when Needs Creating is clicked." Rebuilt
          // as a hidden-by-default checkbox+label, same pattern as "Show
          // cancelled"/"Pickup at Retreat only" right below - except
          // this one's own visibility is ALSO gated on the current
          // named view (see the JS applyView() changes further down),
          // since it only makes sense once Needs Creating is selected.
          // Starts hidden via inline style; JS removes/restores that the
          // moment the buttons/view logic wires up, so there's no flash
          // of a checkbox that doesn't apply to the default "All" view.
          echo '<label id="merch-print-plate-label" style="display:none; margin-left:16px; font-size:0.85em; font-weight:normal; white-space:nowrap;"><input type="checkbox" id="merch-print-plate-toggle" /> Sort by Print Plate</label>';
          // 2026-10-05 (Steve): the Ship view now opens on the Packing
          // Checklist (packing_slips.php, shown in a frame below the filter
          // bar) instead of the plain filtered list, same idea as Sort by
          // Print Plate on Create. Uncheck this to get the plain list back;
          // the choice is remembered for the tab, like the plate checkbox.
          // Only visible on the Ship view (JS shows/hides the label).
          // 2026-10-15 (Steve): Create hides pickup orders and shirts/hats so
          // the list is only what he can print for shipping. This brings
          // them back; only shown on Create, remembered for the tab.
          echo '<label id="merch-focus-label" title="Show the plain list (instead of the plate view), including shirts/hats and pickup orders" style="display:none; margin-left:16px; font-size:0.85em; font-weight:normal; white-space:nowrap;"><input type="checkbox" id="merch-focus-out" /> + Shirts/Hats/P/U</label>';
          echo '<label id="merch-pack-label" style="display:none; margin-left:16px; font-size:0.85em; font-weight:normal; white-space:nowrap;"><input type="checkbox" id="merch-pack-toggle" checked /> Packing Checklist</label>';
          // 2026-08-23: independent of the named views above (which
          // never show a cancelled row, full stop - nothing to act on
          // there) - this is a manual override for browsing/auditing,
          // layered on top of whichever view is active. Off by default,
          // which is the actual fix for "the list is growing lengthy
          // and I don't want to scroll past abandoned orders" (Steve,
          // 2026-08-23).
          echo '<label style="margin-left:16px; font-size:0.85em; font-weight:normal; white-space:nowrap;"><input type="checkbox" id="merch-show-cancelled" /> Cancelled</label>';
          // 2026-08-25 (Steve): same independent-of-the-named-views
          // pattern as "Show cancelled" right above - there was no way to
          // isolate just the Pickup at Retreat orders on this page, which
          // matters this week specifically because pickup fulfillment
          // happens all at once at the retreat rather than trickling out
          // like shipments do. Off by default so the page's default view
          // is unchanged for everyone else.
          echo '<label style="margin-left:16px; font-size:0.85em; font-weight:normal; white-space:nowrap;"><input type="checkbox" id="merch-pickup-only" /> P/U @ Retreat</label>';
          // 2026-09-24 (Steve): "I've deployed everything, but the fix
          // isn't showing up live." Root-caused to PHP OPcache on the
          // host serving stale compiled bytecode even after a
          // successfully-deployed file change (see
          // admin_opcache_reset.php's own header comment for the full
          // story). This button is the one-click fix for that specific
          // situation - a plain POST form (not a fetch() call) so it
          // works exactly like clear.php: no JS dependency, full-page
          // navigation to a small confirmation screen. Right-aligned
          // and visually muted (small, gray) since this is a diagnostic
          // tool for Steve, not a day-to-day filter like the buttons/
          // checkboxes above it.
          echo '<form method="POST" action="admin_opcache_reset.php" style="display:inline; float:right;">';
          echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(merch_csrf_token(), ENT_QUOTES, 'UTF-8') . '" />';
          echo '<button type="submit" class="btn" style="padding:4px 10px; font-size:0.8em; background:#888;" title="If a deployed fix isn\'t showing up live, this clears the server\'s PHP cache.">Clear PHP Cache</button>';
          echo '</form>';
          echo '</div>';
          echo '<div class="merch-table-pane" id="merch-table-pane"><table style="width:100%; border-collapse: collapse;">';
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
              // 2026-09-05 (Steve: multiple missed 2x orders) - drives
              // both the whole-row highlight and the Quantity/Item
              // badges below. Same "trim then cast" pattern already
              // used for $rowQuantityForEdit further down; computed
              // once up front for the same reason $rowItem is (needed
              // by more than one column's rendering, regardless of
              // display order).
              $rowQuantity = $quantityIndex !== false ? (int) trim($data[$quantityIndex] ?? '1') : 1;
              $rowIsCreated = $createdIndex !== false && trim($data[$createdIndex] ?? '') !== '';
              $rowIsFulfilled = $fulfilledIndex !== false && trim($data[$fulfilledIndex] ?? '') !== '';
              $rowIsInvoiced = $invoiceDateIndex !== false && trim($data[$invoiceDateIndex] ?? '') !== '';
              $rowIsPaid = $pymtDateIndex !== false && trim($data[$pymtDateIndex] ?? '') !== '';
              $rowIsShipping = $fulfillmentIndex !== false && trim($data[$fulfillmentIndex] ?? '') === 'Ship';
              // 2026-10-15 (Steve): the Create view and the print plan focus
              // on SHIPPING PRINTED pieces. A pickup row or a shirt/hat is
              // "out of focus": hidden there unless the "+ Shirts/Hats/P/U"
              // checkbox (or P/U @ Retreat) is on. See applyView().
              $rowIsFocusOut = !$rowIsShipping || !merch_is_printed_item($rowItem);
              $rowIsCancelled = $cancelledIndex !== false && trim($data[$cancelledIndex] ?? '') !== '';
              // 2026-10-22: does this line belong to an order with an unanswered customer note?
              $rowNoteKey = '';
              $rowNoteText = '';
              if ($notesIndex !== false && trim($data[$notesIndex] ?? '') !== '') {
                  $rowNoteKeyCandidate = merch_notes_key([
                      'OrderGroupID' => $groupIdIndex !== false ? ($data[$groupIdIndex] ?? '') : '',
                      'Email' => $emailIndex !== false ? ($data[$emailIndex] ?? '') : '',
                      'Timestamp' => $timestampIndexForNotes !== false ? ($data[$timestampIndexForNotes] ?? '') : '',
                  ]);
                  if (isset($openNotes[$rowNoteKeyCandidate])) {
                      $rowNoteKey = $rowNoteKeyCandidate;
                      $rowNoteText = trim($data[$notesIndex]);
                  }
              }
              // Same normalized Name+Zip key as the pre-scan above -
              // defaults to "ready" (true) when Name/Zip columns are
              // missing or this row isn't part of any tracked shipment,
              // so it never blocks a row that the pre-scan didn't cover.
              $rowShipmentKey = ($nameIndex !== false && $zipIndex !== false)
                  ? merch_shipment_key($data[$nameIndex] ?? '', $data[$zipIndex] ?? '')
                  : '';
              $rowShipmentReady = $shipmentAllCreated[$rowShipmentKey] ?? true;
              // 2026-09-20: how many of this line are still outstanding,
              // not yet printed - Quantity minus whatever Qty Created
              // already has, clamped at 0 so a fully-caught-up row (or
              // one that somehow over-reports) never goes negative. This
              // is what actually feeds the print-plate queue below now,
              // not the raw order Quantity - a partially-printed 4-unit
              // line with 1 already done only needs 3 more slots on a
              // plate, not 4.
              $rowQtyCreated = $qtyCreatedIndex !== false ? (int) trim($data[$qtyCreatedIndex] ?? '0') : 0;
              // 2026-09-22 (bug fix): a row that's already fully Created
              // is 0 remaining, full stop - regardless of what Qty
              // Created happens to say. Every row written before Qty
              // Created existed (2026-09-20) - including every already-
              // Fulfilled/shipped order in the file - has Qty Created
              // permanently blank/0, since nothing ever backfilled it for
              // history. Without this check, $rowQuantity - $rowQtyCreated
              // stayed equal to the full original Quantity for every one
              // of those rows forever, so old Fulfilled orders (Fulfilled
              // always implies Created - see merch_update.php's "Fulfilled
              // -> backfills/matches Created" cascade) kept reappearing in
              // the print-plate queue as if never printed. Going forward
              // this is a no-op: merch_update.php's Qty Created delta
              // handler stamps Created the moment Qty Created reaches
              // Quantity, so a row tracked entirely through the new
              // mechanism never has Created=true with room left anyway -
              // this only matters for the legacy rows that predate it.
              $rowQtyRemaining = $rowIsCreated ? 0 : max(0, $rowQuantity - $rowQtyCreated);
              // 2026-09-17: same "needs creating" test as the
              // needs-creating case in applyView() below (Ship rows
              // need paid, Pickup rows don't), plus never cancelled -
              // matching every named view's default (Show Cancelled is
              // a manual override for the flat table only, not
              // extended to this separate pane). 2026-09-20: swapped the
              // old "!$rowIsCreated" gate for "$rowQtyRemaining > 0" -
              // the more precise test now that a row can be partially
              // printed without Created being stamped yet at all
              // ($rowQtyRemaining itself now folds the old Created gate
              // back in above, so this condition doesn't need its own
              // separate !$rowIsCreated/!$rowIsFulfilled check).
              // 2026-10-15 (Steve): the plate plan is for paid SHIPPING
              // printed pieces only - pickup orders and shirts/hats are no
              // longer collected here (so they also stop counting toward a
              // customer's wait time, which print_plates.php ranks plates by).
              if ($rowQtyRemaining > 0 && $rowIsShipping && $rowIsPaid && !$rowIsFocusOut && !$rowIsCancelled) {
                  $printPlateRows[] = [
                      'item' => $rowItem,
                      'color' => $colorIndex !== false ? trim($data[$colorIndex] ?? '') : '',
                      'qty' => $rowQtyRemaining,
                      'orderId' => $orderId,
                      'customerName' => $nameIndex !== false ? trim($data[$nameIndex] ?? '') : '',
                      // 2026-09-24: see print_plate_group_queue()'s header
                      // comment - lets the order-completion preference
                      // recognize a multi-item order as one unit instead
                      // of treating each of its lines as its own order.
                      'orderGroupId' => $orderGroupIdIndex !== false ? trim($data[$orderGroupIdIndex] ?? '') : '',
                      // 2026-09-25 (Steve: Shelly Brooks / Wendy Staples /
                      // Jean McFadden split across plates): the same
                      // Name+Zip shipment key the Needs Shipping view uses,
                      // so a customer's separate submissions (blank
                      // OrderGroupID, like Wendy's Circle and Oval) still
                      // count as one order for completion and keep-together
                      // planning. Blank name = no identity signal, so no
                      // key (falls back to OrderGroupID/OrderID). Prefixed
                      // by fulfillment so a Ship order and a Pickup order
                      // from the same person aren't treated as one.
                      'shipmentKey' => ($nameIndex !== false && trim($data[$nameIndex] ?? '') !== '' && $rowShipmentKey !== '')
                          ? (($rowIsShipping ? 'ship:' : 'pickup:') . $rowShipmentKey)
                          : '',
                      // 2026-10-07: how long this line has been waiting -
                      // paid date, else the order's own timestamp (an
                      // unpaid Pickup). print_plates.php prints the plate
                      // holding the longest-waiting customer first.
                      // 2026-10-19: a REPLACEMENT order goes first (see merch_stock.php).
                      'ts' => ($retreatIndex !== false && trim($data[$retreatIndex] ?? '') === MERCH_REPLACEMENT_TAG)
                          ? MERCH_REPLACEMENT_TS
                          : (print_plate_parse_ts($pymtDateIndex !== false ? (string) ($data[$pymtDateIndex] ?? '') : '')
                          ?? print_plate_parse_ts($timestampIndex !== false ? (string) ($data[$timestampIndex] ?? '') : '')),
                  ];
              }
              echo '<tr class="' . ($rowQuantity > 1 ? 'merch-row-multi' : '') . '" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-created="' . ($rowIsCreated ? '1' : '0') . '" data-fulfilled="' . ($rowIsFulfilled ? '1' : '0') . '" data-invoiced="' . ($rowIsInvoiced ? '1' : '0') . '" data-paid="' . ($rowIsPaid ? '1' : '0') . '" data-shipping="' . ($rowIsShipping ? '1' : '0') . '" data-focus-out="' . ($rowIsFocusOut ? '1' : '0') . '" data-shipment-ready="' . ($rowShipmentReady ? '1' : '0') . '" data-cancelled="' . ($rowIsCancelled ? '1' : '0') . '" data-quantity="' . $rowQuantity . '"' . ($rowNoteKey !== '' ? ' data-note-key="' . htmlspecialchars($rowNoteKey, ENT_QUOTES) . '" data-note="' . htmlspecialchars($rowNoteText, ENT_QUOTES) . '"' : '') . '>';
              foreach ($displayOrder as $col) {
                  $i = $columnIndexByName[$col] ?? null;
                  if ($i === null) {
                      continue; // $displayOrder is derived from $header, so this shouldn't happen - skip rather than warn on a missing index
                  }
                  $cell = $data[$i] ?? '';
                  if ($col === 'Created' || $col === 'Fulfilled' || $col === 'Cancelled') {
                      // All three are the same pattern now: one column,
                      // checkbox toggles it, blank-or-date is the whole
                      // status. Fulfilled also cascades into another
                      // column server-side (see merch_update.php) - the
                      // shared JS handler below updates whichever
                      // second cell that touches, found via matching
                      // data-order-id + data-field. (Pymt Date used to
                      // be a fourth field sharing this exact branch -
                      // see the 'Pymt Date' branch below for why it
                      // split off 2026-09-30, and note it still falls
                      // back to this same plain-checkbox shape for a
                      // not-yet-invoiced row.)
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
                      // 2026-10-19 (Steve's workflow decision): printed pieces are marked
                      // Created only by Done on the Inventory page, which also takes them
                      // off the shelf - a hand-ticked box skipped the shelf and let counts
                      // drift. Locked here for SHIP rows (and refused by merch_update.php);
                      // shirts/hats have no shelf, and Pickup orders are not on the
                      // Stock & Print Plan, so those keep a working box.
                      $createdLocked = ($fieldName === 'Created' && $rowIsShipping && in_array($rowItem, FILAMENT_COLOR_ITEMS, true));
                      echo '<input type="checkbox" class="merch-status-toggle" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-field="' . htmlspecialchars($fieldName, ENT_QUOTES) . '" aria-label="' . htmlspecialchars($fieldName . ' - order ' . $orderId, ENT_QUOTES) . '" ' . ($isChecked ? 'checked' : '') . ($createdLocked ? ' disabled title="Set by Done on the Inventory page"' : '') . ' />';
                      echo '<br /><span class="merch-status-date" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-field="' . htmlspecialchars($fieldName, ENT_QUOTES) . '" style="font-size:0.8em; color:#888;">' . htmlspecialchars($displayValue) . '</span>';
                      echo '</td>';
                  } elseif ($col === 'Pymt Date') {
                      // 2026-09-30: split off from the shared
                      // Created/Fulfilled/Cancelled checkbox branch
                      // above. Once an order's actually been invoiced,
                      // marking it Paid is no longer a plain per-row
                      // checkbox - see merch_mark_paid.php's header
                      // comment for why (Steve gets ONE payment
                      // covering a whole combined invoice, and ticking
                      // every row in that invoice separately took as
                      // many clicks as it had lines). This mirrors the
                      // Invoice Date cell just below: a button while
                      // unpaid, plain text + an undo action once paid.
                      //
                      // BEFORE an order's been invoiced there's no
                      // invoice group to mark paid against yet, so this
                      // still falls back to the ORIGINAL plain checkbox
                      // (same markup/classes/JS handler as Created/
                      // Fulfilled/Cancelled above) - preserves the
                      // existing ability to mark something paid ahead
                      // of a real invoice, and its Invoice Date
                      // backfill cascade, exactly as before this
                      // change (see merch_update.php).
                      $pymtDate = trim($cell);
                      if (!$rowIsInvoiced) {
                          $isChecked = $pymtDate !== '';
                          $looksLikeDate = $pymtDate !== '' && strtotime($pymtDate) !== false;
                          $displayValue = $looksLikeDate ? $pymtDate : ($isChecked ? '(marked before this page existed)' : '');
                          echo '<td style="padding:6px; border-bottom:1px solid #eee; text-align:center; white-space:nowrap;">';
                          echo '<input type="checkbox" class="merch-status-toggle" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-field="Pymt Date" aria-label="' . htmlspecialchars('Pymt Date - order ' . $orderId, ENT_QUOTES) . '" ' . ($isChecked ? 'checked' : '') . ' />';
                          echo '<br /><span class="merch-status-date" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-field="Pymt Date" style="font-size:0.8em; color:#888;">' . htmlspecialchars($displayValue) . '</span>';
                          echo '</td>';
                      } else {
                          echo '<td style="padding:6px; border-bottom:1px solid #eee; text-align:center; white-space:nowrap;">';
                          echo '<span class="merch-status-date" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-field="Pymt Date">';
                          if ($pymtDate !== '') {
                              // Same legacy-data fallback as the
                              // not-yet-invoiced branch above (and every
                              // other date-or-blank column) - some early
                              // rows have a non-date "checked" marker
                              // hand-typed before this page tracked real
                              // dates.
                              $pymtLooksLikeDate = strtotime($pymtDate) !== false;
                              echo '<span style="font-size:0.85em; color:#888;">' . htmlspecialchars($pymtLooksLikeDate ? $pymtDate : '(marked before this page existed)') . '</span>';
                              // Undo, same spirit as Un-invoice below -
                              // just clears this ONE row's own Pymt
                              // Date via merch_update.php's existing
                              // checked=0 path (Pymt Date has no
                              // clear-only gating like Invoice Date -
                              // a normal boolean column can always be
                              // unchecked). Never touches any other row
                              // in the group, same "uncheck only clears
                              // its own column" rule as everywhere else.
                              echo ' <button type="button" class="btn merch-unpay-btn" style="padding:2px 6px; font-size:0.7em; background:#888;" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '">Unmark</button>';
                          } elseif ($rowIsCancelled) {
                              // No Mark Paid button on a cancelled,
                              // already-invoiced row - matches the
                              // server-side guard in
                              // merch_mark_paid.php.
                              echo '<span style="font-size:0.85em; color:#bbb;">&mdash;</span>';
                          } else {
                              echo '<button type="button" class="btn merch-markpaid-btn" style="padding:4px 10px; font-size:0.85em;" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '">Mark Paid</button>';
                          }
                          echo '</span>';
                          echo '</td>';
                      }
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
                          } elseif (!$rowIsShipping && !$rowIsCancelled) {
                              // 2026-08-25 (Steve): several pickup customers
                              // pay ahead of the real invoice (see
                              // merch_paid_receipt.php's header comment) -
                              // this lets Steve print a "paid in full"
                              // receipt to hand over with the merchandise at
                              // the retreat. Unlike Send Invoice, this can
                              // be clicked repeatedly (extra copy, lost the
                              // first printout) since it never touches
                              // merchandise.csv - pure read-and-render.
                              // Ship orders don't get this button - they
                              // already got a real emailed invoice, and
                              // there's no in-person hand-off moment for a
                              // shipment.
                              echo ' <button type="button" class="btn merch-paid-receipt-btn" style="padding:2px 6px; font-size:0.7em; background:#2a7a2a;" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '">Paid Receipt</button>';
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
                              // 2026-08-29 (Finding 5, code review): this was
                              // '\xe2\x80\x93' inside a SINGLE-quoted PHP
                              // string, which doesn't interpret \x escapes at
                              // all - it was rendering as the literal 11
                              // characters "\xe2\x80\x93" in the dropdown
                              // instead of an en dash. &ndash; is the same
                              // fix already used elsewhere in this codebase
                              // (e.g. "Fri&ndash;Sun") - an HTML entity,
                              // not a raw byte sequence, so it can't come
                              // apart the same way again regardless of
                              // quoting.
                              echo '<option value="' . htmlspecialchars($currentColor, ENT_QUOTES) . '" selected>' . htmlspecialchars($currentColor) . ' (current &ndash; no longer a valid choice)</option>';
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
                      echo '<span class="merch-item-display">';
                      // 2026-09-05 (Steve: 4 missed 2x orders) - the
                      // Item name is what actually gets read while
                      // deciding what to print, so the quantity flag
                      // needs to sit right in front of it, not just in
                      // its own column off to the side.
                      if ($rowQuantity > 1) {
                          echo '<span class="merch-qty-flag">&times;' . $rowQuantity . '</span>';
                      }
                      echo htmlspecialchars($cell);
                      if (!$rowIsInvoiced) {
                          echo ' <button type="button" class="merch-item-edit-btn" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-item="' . htmlspecialchars($rowItem, ENT_QUOTES) . '" data-qty="' . htmlspecialchars($rowQuantityForEdit, ENT_QUOTES) . '" data-color="' . htmlspecialchars($rowColorForEdit, ENT_QUOTES) . '" data-size="' . htmlspecialchars($rowSizeForEdit, ENT_QUOTES) . '" data-sleeve="' . htmlspecialchars($rowSleeveForEdit, ENT_QUOTES) . '">Edit</button>';
                      }
                      echo '</span>';
                      echo '</td>';
                  } elseif ($col === 'Quantity') {
                      // 2026-09-05 (Steve: 4 missed 2x orders) - a plain
                      // "2" here reads the same as every other cell.
                      // Badge it when it actually matters (>1); a
                      // quantity of 1, which is most rows, stays plain
                      // text so the badge itself doesn't become
                      // wallpaper you learn to ignore.
                      echo '<td style="padding:6px; border-bottom:1px solid #eee; text-align:center;">';
                      if ($rowQuantity > 1) {
                          echo '<span class="merch-qty-badge">&times;' . $rowQuantity . '</span>';
                      } else {
                          echo htmlspecialchars($cell);
                      }
                      echo '</td>';
                  } elseif ($col === 'Name') {
                      // 2026-08-23: Fulfillment already exists as
                      // 'Ship'/'Pickup at retreat' (merch_order.php) -
                      // this just surfaces it inline next to Name, since
                      // Fulfillment itself isn't in
                      // MERCH_ADMIN_COLUMN_ORDER and can scroll
                      // off-screen. Originally added so an unpaid pickup
                      // order could still be spotted while Needs Payment
                      // excluded pickup rows outright; that exclusion is
                      // gone as of 2026-08-30 (see the needs-payment case
                      // below), but the tag stays - useful in every other
                      // view too, not just that one.
                      echo '<td style="padding:6px; border-bottom:1px solid #eee;">' . htmlspecialchars($cell);
                      if ($rowNoteKey !== '') {
                          echo ' <span class="merch-note-marker" data-note-key="' . htmlspecialchars($rowNoteKey, ENT_QUOTES) . '" title="Customer note: ' . htmlspecialchars($rowNoteText, ENT_QUOTES) . '" style="cursor:help;">&#128221;</span>';
                      }
                      if (!$rowIsShipping) {
                          echo ' <span style="display:inline-block; padding:1px 6px; font-size:0.7em; background:#eef; color:#448; border-radius:3px; vertical-align:middle;">Pickup</span>';
                      }
                      echo '</td>';
                  } elseif ($col === 'Qty Created') {
                      // 2026-09-25: a hook for the Created checkbox's
                      // change handler below - merch_update.php now keeps
                      // this column in step with Created (Created checked
                      // = Quantity, unchecked = 0), and the handler
                      // refreshes this cell from the response so it never
                      // shows a stale count until the next page load.
                      echo '<td class="merch-qty-created-cell" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" style="padding:6px; border-bottom:1px solid #eee;">' . htmlspecialchars($cell) . '</td>';
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

      // 2026-09-17: "Sort by Print Plate" pane - hidden by default
      // (toggled by the checkbox in the filter bar above, and only
      // shown at all under the Needs Creating view - see the JS near
      // the bottom of this file), pre-rendered here rather than built
      // client-side since the grouping/matching logic
      // (print_plate_group_queue(), print_plates.php) is PHP, and
      // $printPlateRows was already collected in the row loop above.
      // Was read-only for the first cut (see the design doc,
      // Claude outputs/print-plate-batch-sort-design-20260917.md, for
      // why); 2026-09-20 added per-plate completion checkboxes wired to
      // merch_update.php's new Qty Created delta endpoint - see the
      // plate-rendering loop below and merch_update.php's own header
      // comment for the full mechanism.
      //
      // 2026-09-17, second pass: rewritten for a capacity-based model
      // after the original "batch summary + separate full item list"
      // layout below turned out to double-render the same units and
      // confused Steve. Every unit now appears exactly once, as part
      // of exactly one plate line (full or partial) - no separate
      // leftover list. This display shape has held through the later
      // passes below.
      //
      // 2026-10-07 (Steve): print_plates.php now plans with his 22 real
      // Bambu plates and keeps each customer's pieces together, longest-
      // waiting customer first - see its header comment. $printPlateRows
      // (collected above, unfiltered by item type) is passed through
      // as-is. As of 2026-10-15 it holds paid shipping printed pieces only,
      // so a customer's wait time no longer counts pickup or shirt/hat lines.
      // 2026-10-19 (Steve's workflow decision): a finished plate adds to the
      // SHELF (inventory.csv), and Done takes pieces off it - the old per-plate
      // checkbox marked orders Created without touching inventory, which is
      // how counts drifted. So the plate plan is the Needs Creating queue
      // MINUS what the shelf already holds (oldest order first).
      $plateShelfCovered = 0;
      $plateInv = merch_stock_load_inventory(__DIR__ . '/inventory.csv');
      if ($plateInv['exists']) {
          $plateParsed = merch_stock_parse_grid($plateInv['rows'], FILAMENT_COLOR_ITEMS, FILAMENT_COLORS);
          $plateReduced = merch_stock_reduce_print_rows($printPlateRows, $plateParsed['stock'] ?? []);
          $printPlateRows = $plateReduced['rows'];
          $plateShelfCovered = $plateReduced['covered'];
      }
      $printPlateGroups = print_plate_group_queue($printPlateRows);
      // 2026-10-23 (Steve): plates marked "Start printing" and not yet Finished.
      $printingActive = merch_printing_active();
      ?>
      <!-- 2026-10-22 (Steve): the Notes view - one row per order with a customer note, newest first. -->
      <div id="merch-notes-pane" class="merch-table-pane" style="display:none; padding:16px;">
        <?php if (empty($noteEntries)): ?>
          <p style="text-align:center; color:#666;">No customer notes yet.</p>
        <?php else: ?>
          <p style="color:#666; font-size:0.85em; margin-top:0;">
            One row per order that has a customer note, newest first. <strong>Reply</strong> opens a new message to the customer in your mail program, with their note quoted. Tick <strong>Handled</strong> once you've dealt with it. Fulfilled orders placed before <?= htmlspecialchars(date('n/j', strtotime(MERCH_NOTES_AUTO_HANDLED_BEFORE))) ?> start out handled.
            <label style="margin-left:10px;"><input type="checkbox" id="merch-notes-show-handled" /> Show handled</label>
          </p>
          <table style="width:100%; border-collapse: collapse;" id="merch-notes-table">
            <tr>
              <th style="padding:6px; text-align:left;">Date</th>
              <th style="padding:6px; text-align:left;">Customer</th>
              <th style="padding:6px; text-align:left;">Note</th>
              <th style="padding:6px; text-align:left;">Order</th>
              <th style="padding:6px; text-align:left;">Status</th>
              <th style="padding:6px; text-align:left;">Reply</th>
              <th style="padding:6px; text-align:left;">Handled</th>
            </tr>
            <?php foreach ($noteEntries as $ne): ?>
              <tr class="merch-note-row" data-note-key="<?= htmlspecialchars($ne['key'], ENT_QUOTES) ?>" data-handled="<?= $ne['handled'] ? '1' : '0' ?>" style="<?= $ne['handled'] ? 'display:none; opacity:0.55;' : '' ?>">
                <td style="padding:6px; border-bottom:1px solid #eee; white-space:nowrap; vertical-align:top;"><?= htmlspecialchars($ne['placed']) ?></td>
                <td style="padding:6px; border-bottom:1px solid #eee; vertical-align:top;"><?= htmlspecialchars($ne['name']) ?><br><span style="color:#666; font-size:0.85em;"><?= htmlspecialchars($ne['email']) ?></span></td>
                <td style="padding:6px; border-bottom:1px solid #eee; vertical-align:top; max-width:420px; white-space:pre-wrap;"><?= htmlspecialchars($ne['note']) ?></td>
                <td style="padding:6px; border-bottom:1px solid #eee; vertical-align:top; font-size:0.85em;">#<?= htmlspecialchars(implode(', #', $ne['orderIds'])) ?><br><?= merch_qty_chip_text(htmlspecialchars($ne['items'])) ?></td>
                <td style="padding:6px; border-bottom:1px solid #eee; vertical-align:top;"><?= htmlspecialchars($ne['status']) ?></td>
                <td style="padding:6px; border-bottom:1px solid #eee; vertical-align:top;">
                  <?php if ($ne['email'] !== ''): ?>
                    <a class="btn" style="padding:4px 10px; font-size:0.85em; text-decoration:none;" href="<?= htmlspecialchars(merch_notes_mailto($ne), ENT_QUOTES) ?>">Reply</a>
                  <?php endif; ?>
                </td>
                <td style="padding:6px; border-bottom:1px solid #eee; vertical-align:top; text-align:center;">
                  <input type="checkbox" class="merch-note-handled" data-note-key="<?= htmlspecialchars($ne['key'], ENT_QUOTES) ?>" <?= $ne['handled'] ? 'checked' : '' ?> aria-label="Handled" />
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>
      <div id="merch-print-plate-pane" class="merch-table-pane" style="display:none; padding:16px;">
        <?php /* 2026-10-23: everything marked "Start printing" and not yet Finished, always listed here even if the plan has since shifted. */ ?>
        <div class="print-plate-now no-print-strip" id="merch-printing-now"<?= $printingActive ? '' : ' hidden' ?>>
          <strong>&#128424; Currently printing (<?= count($printingActive) ?>)</strong>
          <ul>
            <?php foreach ($printingActive as $pk => $pa): ?>
              <li><?= htmlspecialchars($pa['color']) ?> &mdash; <?= htmlspecialchars($pa['plate']) ?> &mdash; <?= merch_qty_chip_text(htmlspecialchars($pa['items'])) ?>
                <span style="color:#666;">(since <?= htmlspecialchars(date('D g:i a', strtotime($pa['since']))) ?>)</span></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php if (empty($printPlateGroups)): ?>
          <p style="text-align:center; color:#666;">Nothing here right now &mdash; either the Create view is empty, or everything left is a pickup order, a shirt/hat or a Stars &amp; Stripes order, none of which go through this view.</p>
        <?php else: ?>
          <p style="color:#666; font-size:0.85em; margin-top:0;">
            The same Needs Creating queue, grouped by color (most-ordered colors first), then planned onto your 22 real plates (named and numbered as in Bambu Studio). Within a color the plate holding the customer who has waited longest comes first, and one customer's pieces stay on one plate unless no single plate can hold their order. Every order line appears on exactly one plate below &mdash; nothing is hidden or double-counted. A partial plate is still a candidate for combining by hand with something else in that color. Pieces already on the shelf are counted first<?= $plateShelfCovered > 0 ? ' (' . (int) $plateShelfCovered . ' piece' . ($plateShelfCovered === 1 ? '' : 's') . ' right now)' : '' ?>, so this is only what is still left to print. Press <strong>Finished&hellip;</strong> when a plate comes off the printer &mdash; it adds those pieces to inventory (change a number if the plate came out different). The orders are marked Created later, by <strong>Done</strong> on the Inventory page, when the pieces are pulled for shipping.
            <button type="button" id="merch-print-plate-print-btn" class="btn no-print" style="padding:2px 10px; font-size:0.85em; margin-left:8px;">Print this list</button>
          </p>
          <?php foreach ($printPlateGroups as $group): ?>
            <div class="print-plate-color-group">
              <h3 class="print-plate-color-heading"><?= htmlspecialchars($group['color']) ?></h3>
              <?php /* 2026-10-07: one list per color, plates in PRINT ORDER (longest-waiting customer first); each plate names the real Bambu plate to open. */ ?>
              <ul class="print-plate-plate-list">
                <?php $printSeq = 0; ?>
                <?php foreach ($group['plateGroups'] as $pg): ?>
                  <?php foreach ($pg['plates'] as $plate): ?>
                    <?php
                    $printSeq++;
                    $isFull = $plate['fillFraction'] >= 0.999;
                    $fillPct = (int) round($plate['fillFraction'] * 100);
                    $itemBits = [];
                    $itemBitsHtml = [];
                    $orderBits = [];
                    // 2026-09-20 (Steve, item #4): "Checkboxes to mark
                    // items as 'complete' are needed in the Sort by
                    // Print Plate view." One checkbox per plate,
                    // carrying exactly what this plate consumed from
                    // each order (summed across every item on the
                    // plate, in case a plate pulls from the same
                    // order twice) as its Qty Created delta - see
                    // merch_update_apply_qty_created_deltas() in
                    // merch_update.php. Checking it posts those deltas
                    // and reloads; the plate then simply isn't
                    // regenerated next time (its orders' remaining
                    // quantity dropped, possibly to 0) rather than
                    // sitting there struck through.
                    $plateDeltas = [];
                    $plateKeyQtys = [];
                    foreach ($plate['items'] as $kItem => $kData) {
                        foreach ($kData['orders'] as $ko) {
                            $plateKeyQtys[$kItem . '#' . $ko['orderId']] = ($plateKeyQtys[$kItem . '#' . $ko['orderId']] ?? 0) + (int) $ko['qty'];
                        }
                    }
                    $plateKey = merch_printing_key((string) $group['color'], (string) $pg['group'], $plateKeyQtys);
                    $plateIsPrinting = isset($printingActive[$plateKey]);
                    foreach ($plate['items'] as $itemName => $data) {
                        $itemBits[] = (int) $data['qty'] . '&times; ' . htmlspecialchars($itemName);
                        $itemBitsHtml[] = merch_qty_chip((int) $data['qty']) . htmlspecialchars($itemName);
                        foreach ($data['orders'] as $o) {
                            $orderBits[] = '#' . htmlspecialchars($o['orderId']) . ' ' . htmlspecialchars($o['customerName']) . ((int) $o['qty'] > 1 ? ' <span class="qty-chip">&times;' . (int) $o['qty'] . '</span>' : ' (1)');
                            $plateDeltas[$o['orderId']] = ($plateDeltas[$o['orderId']] ?? 0) + (int) $o['qty'];
                        }
                    }
                    ?>
                    <li class="<?= $isFull ? '' : 'print-plate-partial' ?><?= $plateIsPrinting ? ' print-plate-printing' : '' ?>">
                      <?php
                      $plateDoneItems = [];
                      foreach ($plate['items'] as $itemName => $data) {
                          $plateDoneItems[] = ['item' => (string) $itemName, 'qty' => (int) $data['qty']];
                      }
                      ?>
                      <div style="display:block;">
                      <?php
                      $plateItemsText = strip_tags(implode(', ', $itemBits));
                      $plateItemsText = html_entity_decode($plateItemsText, ENT_QUOTES, 'UTF-8');
                      ?>
                      <?php if ($plateIsPrinting): ?>
                        <span class="print-plate-badge" title="Started <?= htmlspecialchars($printingActive[$plateKey]['since'], ENT_QUOTES) ?>">&#128424; PRINTING since <?= htmlspecialchars(date('D g:i a', strtotime($printingActive[$plateKey]['since']))) ?></span>
                        <button type="button" class="print-plate-stop no-print" data-key="<?= htmlspecialchars($plateKey, ENT_QUOTES) ?>" style="margin-right:8px; padding:1px 8px; font-size:0.85em; cursor:pointer;">Not printing</button>
                      <?php else: ?>
                        <button type="button" class="print-plate-start no-print" data-key="<?= htmlspecialchars($plateKey, ENT_QUOTES) ?>" data-color="<?= htmlspecialchars($group['color'], ENT_QUOTES) ?>" data-plate="<?= htmlspecialchars($pg['group'], ENT_QUOTES) ?>" data-items-text="<?= htmlspecialchars($plateItemsText, ENT_QUOTES) ?>" style="margin-right:8px; padding:1px 8px; font-size:0.85em; border:1px solid #b36b00; background:#fff; color:#b36b00; border-radius:3px; cursor:pointer;">Start printing</button>
                      <?php endif; ?>
                      <button type="button" class="print-plate-finished no-print" data-key="<?= htmlspecialchars($plateKey, ENT_QUOTES) ?>" data-color="<?= htmlspecialchars($group['color'], ENT_QUOTES) ?>" data-plate="<?= htmlspecialchars($pg['group'], ENT_QUOTES) ?>" data-items="<?= htmlspecialchars(json_encode($plateDoneItems), ENT_QUOTES) ?>" style="margin-right:8px; padding:1px 8px; font-size:0.85em; border:1px solid #2a7a2a; background:#fff; color:#2a7a2a; border-radius:3px; cursor:pointer;">Finished&hellip;</button>
                      <strong><?= $printSeq ?>. <?= htmlspecialchars($pg['group']) ?></strong>
                      &mdash; <?= implode(', ', $itemBitsHtml) ?>
                      <span class="print-plate-plate-fill"><?= $isFull ? '(full)' : '(' . $fillPct . '% full)' ?></span>
                      <span class="print-plate-plate-orders"><?= implode(', ', $orderBits) ?></span>
                      </div>
                    </li>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- 2026-10-19: Plate finished -> adds the pieces to inventory through merch_stock_adjust.php
           (same path as the Inventory page's Add; its history and Undo cover it). -->
      <dialog id="merch-plate-dialog" style="max-width:440px; border:1px solid #ccc; border-radius:8px; padding:16px 20px;">
        <p style="margin:0 0 6px; font-weight:bold;" id="merch-pd-title">Plate finished</p>
        <p style="margin:0 0 10px; color:#666; font-size:0.85em;">Adds these pieces to the shelf. Change a number if the plate came out different (a failed piece, a spare). It does not change any order.</p>
        <div id="merch-pd-rows"></div>
        <p id="merch-pd-msg" style="min-height:1.2em; font-size:0.85em; margin:10px 0;"></p>
        <div style="display:flex; gap:8px; justify-content:flex-end;">
          <button type="button" id="merch-pd-cancel" class="btn">Cancel</button>
          <button type="button" id="merch-pd-ok" class="btn">Add to inventory</button>
        </div>
      </dialog>

      <!-- 2026-10-05: Ship view's Packing Checklist. packing_slips.php is the
           whole checklist (same page the Pack List footer link opens), so its
           Fulfilled checkboxes, Print button and "still in progress" sections
           all work here unchanged. The frame loads the first time the Ship
           view is shown, not on every page load. -->
      <div id="merch-pack-pane" style="display:none; padding:0 0 8px;">
        <iframe id="merch-pack-frame" title="Packing Checklist" style="width:100%; height:calc(100vh - 170px); min-height:480px; border:1px solid #ddd; background:#fff;"></iframe>
      </div>

      <div class="button-container" style="text-align:center; margin-top:20px;">
        <p style="margin:0;">
          <!-- 2026-10-05 (Steve): shortened link names, ordered roughly by
               how often each is used (Inventory first), to be adjusted by
               feel. Display text only - every href/target is unchanged. -->
          <!-- 2026-10-02 (Steve): read-only "what can I ship from what I have
               printed, and what should I print next?" report - see
               merch_stock.php / merch_stock_report.php header comments.
               2026-10-05: link text is now "Inventory" (the page itself is
               still titled Stock &amp; Print Plan). -->
          <a href="merch_stock_report.php" target="_blank" style="color: var(--accent);">Inventory &rarr;</a>
          &nbsp;&mdash;&nbsp;
          <!-- 2026-10-07 (Steve): live inventory editor (merch_stock_edit.php) - every
               tap saves and is logged; replaces the old upload-a-CSV box. -->
          <a href="merch_stock_edit.php" target="_blank" style="color: var(--accent);">Edit Stock &rarr;</a>
          &nbsp;&mdash;&nbsp;
          <!-- 2026-10-19 (Steve): no-charge replacement for a packing miss / broken part - replaces the dummy-order-marked-Paid trick (merch_replacement.php). -->
          <a href="merch_replacement.php" target="_blank" style="color: var(--accent);">Replacement &rarr;</a>
          &nbsp;&mdash;&nbsp;
          <!-- 2026-09-20 (Steve, item #6): these used to be one click -
               the Shippo CSV download also popped the pack list open in
               a new tab, whether or not you actually wanted the CSV yet.
               "I find myself clicking the link and then deleting the
               .csv file because I'm just working on printing and
               packing but I'm not ready to purchase labels yet." Split
               into two independent links, same paid/Ship/not-yet-
               Fulfilled batch either way (packing_slips.php and
               shippo_export.php already compute that set the same way -
               see Finding 10, 2026-08-19 code review) - pick whichever
               one you actually need right now. (Both links are for the
               paid, unshipped orders.) -->
          <a href="shippo_export.php" style="color: var(--accent);">Shippo Export &rarr;</a>
          &nbsp;&mdash;&nbsp;
          <a href="packing_slips.php" target="_blank" style="color: var(--accent);">Pack List &rarr;</a>
          &nbsp;&mdash;&nbsp;
          <!-- 2026-08-25: companion to the link above, for the Pickup at
               retreat side - see pickup_slips.php's header comment for why
               it needed its own checklist instead of reusing packing_slips.php. -->
          <a href="pickup_slips.php" target="_blank" style="color: var(--accent);">Retreat Pickup &rarr;</a>
          &nbsp;&mdash;&nbsp;
          <a href="export_emails.php" style="color: var(--accent);">D/L Customer Emails &rarr;</a>
          &nbsp;&mdash;&nbsp;
          <!-- 2026-09-26 (Steve, for Janet): the shirts and hats still to
               be made, as a CSV she can open in Excel - see
               merch_export_shirts_hats.php's header comment for exactly
               which rows/columns it includes. -->
          <a href="merch_export_shirts_hats.php" style="color: var(--accent);">D/L Shirts &amp; Hats &rarr;</a>
          &nbsp;&mdash;&nbsp;
          <!-- 2026-08-29: bulk payment-reminder feature - preview-then-
               confirm list of invoiced-but-unpaid Ship customers (see
               merch_reminders.php's header comment). Opens in its own
               tab, same as the Retreat Pickup link above. -->
          <a href="merch_reminders.php" target="_blank" style="color: var(--accent);">Payment Reminders &rarr;</a>
          &nbsp;&mdash;&nbsp;
          <!-- 2026-10-15: "please be patient" email to paid, still-waiting
               Ship customers (see merch_patience.php's header comment). -->
          <a href="merch_patience.php" target="_blank" style="color: var(--accent);">Patience Email &rarr;</a>
          &nbsp;&mdash;&nbsp;
          <span style="color:#bbb; font-size:0.75em;">Build <?= htmlspecialchars($merchBuild, ENT_QUOTES, 'UTF-8') ?></span>
        </p>
      </div>
    </div>
  </div>

  <!-- 2026-10-03: Mark Paid choice for Ship orders (see the click handler). -->
  <dialog id="merch-markpaid-dialog" style="max-width:420px; border:1px solid #ccc; border-radius:8px; padding:18px 20px;">
    <form method="dialog">
      <p style="margin:0 0 6px; font-weight:bold;">Mark this order paid?</p>
      <p style="margin:0 0 12px; font-size:0.9em; color:#555;">This also marks any other unpaid order from the same invoice. <span id="merch-markpaid-emailnote">You can email the customer a "payment received" note, or mark it quietly.</span></p>
      <!-- 2026-10-18: how it was paid -> payments.csv (see merch_payments.php) -->
      <p style="margin:0 0 8px;"><label>Paid with
        <select id="merch-markpaid-method" style="margin-left:6px;">
          <option>Venmo</option><option>PayPal</option><option>Zelle</option><option>Check</option><option>Cash</option><option>Other</option>
        </select></label></p>
      <p style="margin:0 0 16px;"><label>Amount received <span style="color:#777; font-size:0.85em;">(blank = invoice total)</span>
        <input id="merch-markpaid-amount" type="text" inputmode="decimal" style="width:90px; margin-left:6px;" autocomplete="off"></label></p>
      <div style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
        <button type="submit" value="cancel" class="btn">Cancel</button>
        <button type="submit" value="silent" class="btn">Mark paid silently</button>
        <button type="submit" value="email" id="merch-markpaid-emailbtn" class="btn" autofocus>Mark paid &amp; email receipt</button>
      </div>
    </form>
  </dialog>

  <script>
    // 2026-08-23 (#2): catalog data for the Item-edit form below - see
    // the PHP that builds $merchEditCatalog near the top of this file.
    const MERCH_EDIT_CATALOG = <?= json_encode($merchEditCatalog) ?>;
    const MERCH_VIEW_COLUMNS = <?= json_encode(MERCH_VIEW_COLUMNS) ?>; // 2026-10-05: see the PHP constant of the same name

    // 2026-08-29 (Finding 9): every fetch() below that POSTs to
    // merch_update.php/merch_invoice.php/merch_edit_line.php now
    // appends this - those endpoints reject the request otherwise. See
    // csrf.php for what this actually protects against and why.
    const MERCH_CSRF_TOKEN = <?= json_encode(merch_csrf_token()) ?>;

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

        // 2026-10-15 (Steve): cancelling a line that is already paid or
        // already shipped is rarely intended, and the line then vanishes
        // from the working views (the 2026-10-14 incident, orders
        // 1097-1098). Ask first. Un-cancelling and cancelling an unpaid,
        // unshipped line need no prompt. The state comes from the row's
        // own data-paid / data-fulfilled flags. Cancel acts on this ONE
        // line, so the message says so.
        if (field === 'Cancelled' && checked) {
          const tr = box.closest('tr');
          const isPaid = tr && tr.dataset.paid === '1';
          const isShipped = tr && tr.dataset.fulfilled === '1';
          // 2026-10-22: an invoice that has already gone out to the customer
          // is also worth a pause (cancelling here does NOT tell the customer).
          const isInvoiced = tr && tr.dataset.invoiced === '1';
          if (isPaid || isShipped || isInvoiced) {
            const state = isPaid && isShipped ? 'already paid and shipped'
              : (isShipped ? 'already shipped' : (isPaid ? 'already paid' : 'already invoiced (the customer has the invoice)'));
            const ok = confirm(`Order ${orderId} is ${state}.\n\nCancelling hides this line from the working views (use the Cancelled box to see it again). Other lines of the same customer are not affected.\n\nCancel it anyway?`);
            if (!ok) {
              box.checked = false;
              return;
            }
          }
        }

        box.disabled = true;
        fetch('merch_update.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `orderId=${encodeURIComponent(orderId)}&field=${encodeURIComponent(field)}&checked=${checked ? '1' : '0'}&csrf_token=${encodeURIComponent(MERCH_CSRF_TOKEN)}`
        })
          .then((r) => r.json())
          .then((data) => {
            if (data.ok) {
              updateStatusSpan(orderId, field, data.value);
              if (data.cascadeField) {
                updateStatusSpan(orderId, data.cascadeField, data.cascadeValue);
              }
              // 2026-09-25: Created/Fulfilled now also rewrite Qty Created
              // server-side (see merch_update.php) - mirror that here.
              if (data.qtyCreated !== null && data.qtyCreated !== undefined) {
                const qcCell = document.querySelector(`.merch-qty-created-cell[data-order-id="${CSS.escape(orderId)}"]`);
                if (qcCell) qcCell.textContent = String(data.qtyCreated);
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
          body: `orderId=${encodeURIComponent(orderId)}&field=Color&value=${encodeURIComponent(newValue)}&csrf_token=${encodeURIComponent(MERCH_CSRF_TOKEN)}`
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

        const body = `orderId=${encodeURIComponent(orderId)}&item=${encodeURIComponent(newItem)}&quantity=${encodeURIComponent(newQty)}&color=${encodeURIComponent(newColor)}&size=${encodeURIComponent(newSize)}&sleeve=${encodeURIComponent(newSleeve)}&csrf_token=${encodeURIComponent(MERCH_CSRF_TOKEN)}`;

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
      const body = (manualShipping !== undefined
        ? `orderId=${encodeURIComponent(orderId)}&manualShipping=${encodeURIComponent(manualShipping)}`
        : `orderId=${encodeURIComponent(orderId)}`) + `&csrf_token=${encodeURIComponent(MERCH_CSRF_TOKEN)}`;

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
          <div style="color:#666; margin-bottom:6px;">Subtotal ${money(data.subtotal)}${data.bundleDiscount > 0 ? ` &minus; bundle discount ${money(data.bundleDiscount)}` : ''}${data.fullSetDiscount > 0 ? ` &minus; full-set discount ${money(data.fullSetDiscount)}` : ''} + tax ${money(data.tax)}</div>
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
        // 2026-10-22: a customer note that has not been marked Handled yet.
        const noteWarn = tr && tr.dataset.note
          ? `This order has a customer note:\n\n"${tr.dataset.note}"\n\nIf it changes anything, deal with it before invoicing (Notes view, then Handled).\n\n`
          : '';
        if (!confirm(noteWarn + confirmMsg)) {
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

    // 2026-08-25: "Paid Receipt" button next to an Invoice Date that's
    // both invoiced and paid on a pickup order (see the Invoice Date
    // branch above and merch_paid_receipt.php's header comment). Pure
    // read-and-render - never touches merchandise.csv - so unlike
    // sendInvoice() above there's nothing to reload on success, just the
    // download.
    document.querySelectorAll('.merch-paid-receipt-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const orderId = btn.dataset.orderId;
        btn.disabled = true;
        const originalLabel = btn.textContent;
        btn.textContent = 'Generating…';

        fetch('merch_paid_receipt.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `orderId=${encodeURIComponent(orderId)}`,
        })
          .then((r) => r.json())
          .then((data) => {
            if (data.ok) {
              triggerMarkdownDownload(data.downloadFilename, data.downloadDocument);
            } else {
              alert('Could not generate paid receipt: ' + (data.error || 'unknown error'));
            }
            btn.disabled = false;
            btn.textContent = originalLabel;
          })
          .catch(() => {
            alert('Could not generate paid receipt - check your connection and try again.');
            btn.disabled = false;
            btn.textContent = originalLabel;
          });
      });
    });

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
          body: `orderId=${encodeURIComponent(orderId)}&field=${encodeURIComponent('Invoice Date')}&checked=0&csrf_token=${encodeURIComponent(MERCH_CSRF_TOKEN)}`
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

    // 2026-09-30: "Mark Paid" button - mirrors Send Invoice's grouping
    // (see merch_mark_paid.php's header comment). May stamp OTHER rows
    // too (every other unpaid row from the same invoice - same
    // customer, same Invoice Date as this one), so on success this
    // reloads rather than trying to guess which cells to update in
    // place, same as sendInvoice() above.
    //
    // 2026-10-03 (Steve): Ship rows now get a three-way choice - mark paid
    // AND email a "payment received" note, mark paid silently, or cancel.
    // confirm() can only do two choices, hence the <dialog>. Pickup rows
    // never get the email (separate fulfilment path), so they keep the
    // plain confirm and always post notify=0.
    const markPaidDialog = document.getElementById('merch-markpaid-dialog');

    function askMarkPaidChoice(isShip) {
      // Resolves to 'email', 'silent' or 'cancel'. Pickup rows never get the email.
      const emailBtn = document.getElementById('merch-markpaid-emailbtn');
      const emailNote = document.getElementById('merch-markpaid-emailnote');
      const methodSel = document.getElementById('merch-markpaid-method');
      const amountIn = document.getElementById('merch-markpaid-amount');
      if (emailBtn) emailBtn.style.display = isShip ? '' : 'none';
      if (emailNote) emailNote.style.display = isShip ? '' : 'none';
      amountIn.value = '';
      try { const m = localStorage.getItem('merchLastPayMethod'); if (m) methodSel.value = m; } catch (e) {}
      if (!isShip) { methodSel.value = 'Cash'; }
      return new Promise((resolve) => {
        const finish = (choice) => {
          markPaidDialog.removeEventListener('close', onClose);
          resolve(choice);
        };
        const onClose = () => finish(markPaidDialog.returnValue || 'cancel');
        markPaidDialog.returnValue = 'cancel';
        markPaidDialog.addEventListener('close', onClose);
        markPaidDialog.showModal();
      });
    }

    document.querySelectorAll('.merch-markpaid-btn').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const orderId = btn.dataset.orderId;
        const tr = btn.closest('tr');
        const isShip = !!tr && tr.dataset.shipping === '1';

        let notify = '0';
        let payMethod = 'Other';
        let payAmount = '';
        if (markPaidDialog && typeof markPaidDialog.showModal === 'function') {
          const choice = await askMarkPaidChoice(isShip);
          if (choice === 'cancel') {
            return;
          }
          notify = (isShip && choice === 'email') ? '1' : '0';
          payMethod = document.getElementById('merch-markpaid-method').value;
          payAmount = document.getElementById('merch-markpaid-amount').value.trim().replace(/^\$/, '');
          try { localStorage.setItem('merchLastPayMethod', payMethod); } catch (e) {}
        } else if (!confirm('Mark this order paid (and any other unpaid order from the same invoice)?')) {
          return;
        }

        btn.disabled = true;
        btn.textContent = 'Marking…';

        fetch('merch_mark_paid.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `orderId=${encodeURIComponent(orderId)}&notify=${notify}&method=${encodeURIComponent(payMethod)}&amountReceived=${encodeURIComponent(payAmount)}&csrf_token=${encodeURIComponent(MERCH_CSRF_TOKEN)}`
        })
          .then((r) => r.json())
          .then((data) => {
            if (data.ok) {
              // Payment is saved either way; only tell Steve when a
              // requested email did NOT go out, so he can send it by hand.
              if (data.email && data.email.requested && data.email.status !== 'sent') {
                alert('Marked paid, but the payment-received email was NOT sent: ' + (data.email.reason || 'unknown reason'));
              }
              location.reload();
            } else {
              alert('Could not mark paid: ' + (data.error || 'unknown error'));
              btn.disabled = false;
              btn.textContent = 'Mark Paid';
            }
          })
          .catch(() => {
            alert('Could not mark paid - check your connection and try again.');
            btn.disabled = false;
            btn.textContent = 'Mark Paid';
          });
      });
    });

    // 2026-09-30: "Unmark" - clears just this ONE row's own Pymt Date
    // via merch_update.php's existing checked=0 path (no clear-only
    // gating for this field, unlike Invoice Date - a normal boolean
    // column can always be unchecked). Never touches any other row in
    // the group. Reload on success so the cell swaps back to a Mark
    // Paid button, same as Un-invoice above.
    document.querySelectorAll('.merch-unpay-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const orderId = btn.dataset.orderId;
        if (!confirm("Clear this order's Pymt Date? This only affects this one order, not the rest of its invoice.")) {
          return;
        }

        btn.disabled = true;
        btn.textContent = 'Clearing…';

        fetch('merch_update.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `orderId=${encodeURIComponent(orderId)}&field=${encodeURIComponent('Pymt Date')}&checked=0&csrf_token=${encodeURIComponent(MERCH_CSRF_TOKEN)}`
        })
          .then((r) => r.json())
          .then((data) => {
            if (data.ok) {
              location.reload();
            } else {
              alert('Could not clear: ' + (data.error || 'unknown error'));
              btn.disabled = false;
              btn.textContent = 'Unmark';
            }
          })
          .catch(() => {
            alert('Could not clear - check your connection and try again.');
            btn.disabled = false;
            btn.textContent = 'Unmark';
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
    // 2026-10-24: ourprint.php (Print Plan) links here with ?view=needs-invoicing|needs-payment|
    // needs-shipping|notes|needs-creating (&focusout=1 = plain Create list with shirts/hats/pickup).
    // Applied once, then the query string is dropped so a later refresh behaves as before.
    try {
      const qp = new URLSearchParams(location.search);
      const qv = qp.get('view');
      if (qv && ['needs-invoicing', 'needs-payment', 'needs-creating', 'needs-shipping', 'notes'].indexOf(qv) !== -1) {
        sessionStorage.setItem('merchAdminView', qv);
        sessionStorage.setItem('merchAdminShowFocusOut', (qv === 'needs-creating' && qp.get('focusout') === '1') ? '1' : '0');
        history.replaceState(null, '', location.pathname);
      }
    } catch (e) { /* storage unavailable - opens on the last view */ }
    let currentView = sessionStorage.getItem('merchAdminView') || 'all';
    // 2026-10-19: only the four named views (or 'all' = nothing lit) are valid;
    // a stale 'active' from before that view was retired lands on All.
    if (['needs-invoicing', 'needs-payment', 'needs-creating', 'needs-shipping', 'notes'].indexOf(currentView) === -1) currentView = 'all';

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

    // 2026-08-25: "Pickup at Retreat only" - same independent-of-the-
    // named-views, persisted-per-tab pattern as "Show cancelled" above.
    // Layered on top of whatever named view is active, same as cancelled.
    const pickupOnlyBox = document.getElementById('merch-pickup-only');
    let pickupOnly = sessionStorage.getItem('merchAdminPickupOnly') === '1';
    pickupOnlyBox.checked = pickupOnly;
    pickupOnlyBox.addEventListener('change', () => {
      pickupOnly = pickupOnlyBox.checked;
      sessionStorage.setItem('merchAdminPickupOnly', pickupOnly ? '1' : '0');
      applyView(currentView);
    });

    // 2026-10-15: "+ Shirts/Hats/P/U" - Create hides pickup orders and
    // shirts/hats by default (row data-focus-out="1"); this shows them again.
    const focusBox = document.getElementById('merch-focus-out');
    const focusLabel = document.getElementById('merch-focus-label');
    let showFocusOut = false;
    try { showFocusOut = sessionStorage.getItem('merchAdminShowFocusOut') === '1'; } catch (e) { /* storage unavailable - starts off */ }
    if (focusBox) {
      focusBox.checked = showFocusOut;
      focusBox.addEventListener('change', () => {
        showFocusOut = focusBox.checked;
        try { sessionStorage.setItem('merchAdminShowFocusOut', showFocusOut ? '1' : '0'); } catch (e) { /* ignore */ }
        applyView(currentView);
      });
    }

    // 2026-09-20 (Steve, item #2): "unlike any of the other buttons -
    // you can click it from any view... It should really be a checkbox
    // that only appears when Needs Creating is clicked." Declared here
    // (before applyView() runs for the first time below) since
    // applyView() itself now reaches into these on every view change -
    // toggling the checkbox's own label visible/hidden, and forcing the
    // checkbox off (and the pane closed, table pane restored) the
    // instant you leave Needs Creating, so it can never be left
    // checked-but-invisible on some other view.
    const printPlateLabel = document.getElementById('merch-print-plate-label');
    const printPlateToggle = document.getElementById('merch-print-plate-toggle');
    const printPlatePane = document.getElementById('merch-print-plate-pane');
    const merchTablePane = document.getElementById('merch-table-pane');
    // 2026-10-05: Ship view's Packing Checklist (a frame onto
    // packing_slips.php). On by default for Ship; unchecking it shows the
    // plain list. The choice lives in sessionStorage like the plate box.
    // The one function below now decides which of the three panes (plain
    // table / plate pane / checklist frame) is showing.
    const packLabel = document.getElementById('merch-pack-label');
    const packToggle = document.getElementById('merch-pack-toggle');
    const packPane = document.getElementById('merch-pack-pane');
    const packFrame = document.getElementById('merch-pack-frame');
    try {
      if (packToggle && sessionStorage.getItem('merchAdminPackChecklist') === '0') packToggle.checked = false;
    } catch (e) { /* storage unavailable - checklist just starts on */ }
    function updatePrintPlatePaneVisibility() {
      const shown = !!(printPlateToggle && printPlateToggle.checked);
      const packShown = currentView === 'needs-shipping' && !!(packToggle && packToggle.checked);
      if (printPlatePane) printPlatePane.style.display = shown ? '' : 'none';
      if (packPane) packPane.style.display = packShown ? '' : 'none';
      if (packShown && packFrame && !packFrame.getAttribute('src')) packFrame.setAttribute('src', 'packing_slips.php');
      // 2026-10-22: the Notes view swaps the table for its own pane.
      const notesShown = currentView === 'notes';
      const notesPane = document.getElementById('merch-notes-pane');
      if (notesPane) notesPane.style.display = notesShown ? '' : 'none';
      if (merchTablePane) merchTablePane.style.display = (shown || packShown || notesShown) ? 'none' : '';
    }
    if (packToggle) {
      packToggle.addEventListener('change', () => {
        try {
          sessionStorage.setItem('merchAdminPackChecklist', packToggle.checked ? '1' : '0');
        } catch (e) { /* storage unavailable - just won't be remembered */ }
        if (!packToggle.checked) {
          // Back to the plain list: reload so it reflects anything marked
          // Fulfilled inside the checklist frame (the choice and the Ship
          // view are both remembered, so it comes back on the plain list).
          location.reload();
          return;
        }
        updatePrintPlatePaneVisibility();
      });
    }
    // 2026-09-26 (Steve): "When I'm in the Sort by Print Plate view and I
    // click a checkbox, it reloads the page and takes me out of that view."
    // The named view (merchAdminView) always survived a reload, but this
    // checkbox lived only in the page, so every reload reset it to off.
    // Persisted the same way as the other view controls: sessionStorage,
    // so it survives the same-tab reload after a plate is checked off but
    // doesn't linger into another day/tab. Restored just before the first
    // applyView() below; applyView() still forces it off (and clears this
    // saved value) whenever the view isn't Needs Creating, so it can never
    // come back checked on a view where it doesn't apply.
    function savePrintPlateState() {
      try {
        sessionStorage.setItem('merchAdminPrintPlate', printPlateToggle && printPlateToggle.checked ? '1' : '0');
      } catch (e) { /* storage unavailable - just won't be remembered */ }
    }
    if (printPlateToggle) {
      printPlateToggle.addEventListener('change', () => {
        updatePrintPlatePaneVisibility();
        savePrintPlateState();
      });
    }

    // 2026-10-05 (Steve): per-view column trimming for Invoice and Payments
    // (MERCH_VIEW_COLUMNS, defined next to MERCH_EDIT_CATALOG). Cells are
    // matched to their column by header text, once; every other view shows
    // all columns again.
    let viewColumnNames = null; // column name per cell index, built on first use
    function applyViewColumns(view) {
      const table = merchTablePane ? merchTablePane.querySelector('table') : null;
      if (!table) return;
      if (viewColumnNames === null) {
        viewColumnNames = Array.from(table.rows[0].cells).map((th) => th.textContent.trim());
      }
      const allowed = MERCH_VIEW_COLUMNS[view] || null;
      Array.from(table.rows).forEach((row) => {
        Array.from(row.cells).forEach((cell, i) => {
          const name = viewColumnNames[i];
          cell.style.display = (allowed && name !== undefined && allowed.indexOf(name) === -1) ? 'none' : '';
        });
      });
    }

    // 2026-10-24 (Steve): the footer links sat below the fold on every view. Size whichever pane is
    // showing (and the Packing Checklist frame) to the window height minus the footer, so they stay visible.
    function fitPanes() {
      const foot = document.querySelector('.button-container');
      if (!foot) { return; }
      const reserve = foot.offsetHeight + 20 + 55; // footer + its top margin + wrapper/card padding
      document.querySelectorAll('.merch-table-pane, #merch-pack-frame').forEach((el) => {
        if (el.offsetParent === null) { return; }
        const isFrame = el.id === 'merch-pack-frame';
        const keep = el.style.maxHeight;
        if (!isFrame) { el.style.maxHeight = 'none'; }
        const top = el.getBoundingClientRect().top + (window.scrollY || 0);
        const room = Math.max(280, window.innerHeight - top - reserve - (isFrame ? 10 : 0));
        if (isFrame) { el.style.height = room + 'px'; el.style.minHeight = '0'; } else { el.style.maxHeight = room + 'px'; }
      });
    }
    window.addEventListener('resize', fitPanes);
    window.addEventListener('load', fitPanes);
    function applyView(view) {
      currentView = view;
      setTimeout(fitPanes, 0);
      sessionStorage.setItem('merchAdminView', view);
      viewButtons.forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.view === view);
        btn.setAttribute('aria-pressed', btn.dataset.view === view ? 'true' : 'false');
      });
      // 2026-10-19 (Steve): the plate view is now THE Create view - the
      // "Sort by Print Plate" checkbox is retired (its label stays hidden; the
      // checkbox itself is kept as the internal on/off the pane code below
      // reads). Create shows the plate pane; ticking "+ Shirts/Hats/P/U"
      // swaps to the plain list so those lines (which the plate view
      // ignores) still have somewhere to be seen. Any other view: pane off.
      if (printPlateLabel) {
        printPlateLabel.style.display = 'none';
        if (printPlateToggle) {
          printPlateToggle.checked = (view === 'needs-creating') && !showFocusOut;
          savePrintPlateState();
        }
        updatePrintPlatePaneVisibility();
      }
      // 2026-10-05: the Packing Checklist checkbox only exists on Ship.
      if (packLabel) {
        packLabel.style.display = view === 'needs-shipping' ? '' : 'none';
        updatePrintPlatePaneVisibility();
      }
      if (focusLabel) {
        focusLabel.style.display = view === 'needs-creating' ? '' : 'none';
      }
      applyViewColumns(view);
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
          case 'needs-invoicing':
            show = !invoiced;
            break;
          case 'needs-payment':
            // 2026-08-23 (Steve): pickup orders go invoiced -> created ->
            // PAID (paid last, settled in person), not the normal
            // paid-before-created flow, so an invoiced-but-unpaid pickup
            // order used to be excluded here outright - "expected,
            // handled at the retreat, not a call to action."
            //
            // 2026-08-30 (Steve): that exclusion was absolute, though -
            // combined with "Pickup at Retreat only" (which hides every
            // Ship row below) it meant that checkbox could never show
            // anything under Needs Payment, even though Steve does need
            // to see unpaid pickup orders sometimes (chasing prepayment
            // before a retreat, or following up on ones that didn't get
            // settled in person). Dropping the isShipping requirement
            // here does that: Needs Payment with the box unchecked now
            // shows both unpaid Ship and unpaid Pickup orders together;
            // checking "Pickup at Retreat only" narrows it to just the
            // pickup ones, same as it narrows every other view.
            show = invoiced && !paid;
            break;
          case 'needs-creating':
            // 2026-08-25 (Steve): this used to require `paid` for every
            // row, which made sense for the normal Ship flow (paid then
            // created) but silently hid every not-yet-created Pickup row
            // that simply hadn't been paid yet - per the needs-payment
            // case just above, pickup orders go invoiced -> created ->
            // PAID (settled in person at the retreat), so an unpaid
            // pickup item can still very much need creating. Ship orders
            // keep the paid requirement; pickup orders just need to not
            // be created yet.
            show = !created && (isShipping ? paid : true);
            // 2026-10-15: pickup orders and shirts/hats are out of focus
            // here unless "+ Shirts/Hats/P/U" or "P/U @ Retreat" is on.
            if (show && tr.dataset.focusOut === '1' && !showFocusOut && !pickupOnly) {
              show = false;
            }
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
            // 2026-10-15: shirts/hats (and anything else not a printed
            // part) are not part of this view - Janet ships those.
            if (tr.dataset.focusOut === '1') {
              show = false;
            }
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
        // Layered the same way as the cancelled override above - a
        // Ship row is hidden whenever "Pickup at Retreat only" is on,
        // regardless of which named view (or "All") is active.
        if (pickupOnly && isShipping) {
          show = false;
        }
        tr.style.display = show ? '' : 'none';
      });
    }

    // 2026-10-22 (Steve): Notes view. Handled checkbox -> merch_notes_set.php
    // (appends to merch_notes_handled.csv). Ticking it dims the row, drops it
    // from the Notes (n) count and removes the note marker / invoice warning
    // from that order's lines in the other views. "Show handled" reveals the
    // rows that are hidden once handled.
    (function () {
      const notesTable = document.getElementById('merch-notes-table');
      if (!notesTable) return;
      const showHandledBox = document.getElementById('merch-notes-show-handled');
      const countEl = document.getElementById('merch-notes-count');
      const notesBtn = document.getElementById('merch-notes-btn');
      function refreshNotesRows() {
        const showHandled = !!(showHandledBox && showHandledBox.checked);
        notesTable.querySelectorAll('tr.merch-note-row').forEach((tr) => {
          const handled = tr.dataset.handled === '1';
          tr.style.display = (handled && !showHandled) ? 'none' : '';
          tr.style.opacity = handled ? '0.55' : '';
        });
      }
      function refreshNotesCount() {
        const open = notesTable.querySelectorAll('tr.merch-note-row[data-handled="0"]').length;
        if (countEl && notesBtn) {
          // Keep the "(n)" bracketed only while there is something open.
          notesBtn.innerHTML = open > 0 ? 'Notes (<span id="merch-notes-count">' + open + '</span>)' : 'Notes <span id="merch-notes-count"></span>';
        }
      }
      if (showHandledBox) showHandledBox.addEventListener('change', refreshNotesRows);
      notesTable.querySelectorAll('.merch-note-handled').forEach((box) => {
        box.addEventListener('change', () => {
          const key = box.dataset.noteKey;
          const handled = box.checked;
          box.disabled = true;
          fetch('merch_notes_set.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `noteKey=${encodeURIComponent(key)}&handled=${handled ? '1' : '0'}&csrf_token=${encodeURIComponent(MERCH_CSRF_TOKEN)}`
          })
            .then((r) => r.json())
            .then((data) => {
              if (!data.ok) throw new Error(data.error || 'unknown error');
              const tr = box.closest('tr');
              tr.dataset.handled = handled ? '1' : '0';
              if (handled) {
                // The order's lines in the other views no longer carry a note flag.
                document.querySelectorAll('tr[data-note-key]').forEach((row) => {
                  if (row.dataset.noteKey === key && row !== tr) {
                    row.removeAttribute('data-note-key');
                    row.removeAttribute('data-note');
                  }
                });
                document.querySelectorAll('.merch-note-marker').forEach((m) => {
                  if (m.dataset.noteKey === key) m.remove();
                });
              }
              refreshNotesRows();
              refreshNotesCount();
            })
            .catch((err) => {
              alert('Could not save: ' + err.message);
              box.checked = !handled;
            })
            .finally(() => { box.disabled = false; });
        });
      });
    })();

    viewButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        applyView(btn.dataset.view === currentView ? 'all' : btn.dataset.view);
      });
    });

    // Apply whatever view was restored (or the 'all' default) now that
    // the table and buttons above actually exist to apply it to.
    // 2026-09-26: bring back the Sort by Print Plate checkbox first (only
    // meaningful on Needs Creating - applyView() clears it on any other
    // view), and, right after, the scroll position saved by the plate
    // checkbox handler below so a reload after checking off a plate
    // leaves you where you were instead of back at the top.
    try {
      if (printPlateToggle && currentView === 'needs-creating' && sessionStorage.getItem('merchAdminPrintPlate') === '1') {
        printPlateToggle.checked = true;
      }
    } catch (e) { /* storage unavailable - checkbox just starts off */ }
    applyView(currentView);
    try {
      const savedScroll = JSON.parse(sessionStorage.getItem('merchAdminPlateScroll') || 'null');
      sessionStorage.removeItem('merchAdminPlateScroll');
      if (savedScroll && printPlateToggle && printPlateToggle.checked) {
        if (printPlatePane) printPlatePane.scrollTop = savedScroll.pane || 0;
        window.scrollTo(0, savedScroll.page || 0);
      }
    } catch (e) { /* nothing saved, or storage unavailable */ }

    // 2026-10-19 (Steve's workflow decision): the per-plate "mark complete"
    // checkbox (2026-09-20) is gone - it marked orders Created without
    // touching inventory, so the shelf counts drifted. "Finished..." adds the
    // plate's pieces to inventory (merch_stock_adjust.php, one call per item,
    // each with its own idempotency token); the plate list then already
    // subtracts the shelf, and Done (Inventory page) marks orders Created.
    (function () {
      const dlg = document.getElementById('merch-plate-dialog');
      if (!dlg) { return; }
      const rowsEl = document.getElementById('merch-pd-rows');
      const msg = document.getElementById('merch-pd-msg');
      const okBtn = document.getElementById('merch-pd-ok');
      let cur = null;
      const tok = () => {
        const a = new Uint8Array(12);
        (window.crypto || window.msCrypto).getRandomValues(a);
        return Array.prototype.map.call(a, (b) => ('0' + b.toString(16)).slice(-2)).join('');
      };
      const post = (params) => {
        const body = new URLSearchParams();
        Object.keys(params).forEach((k) => body.append(k, params[k]));
        body.append('csrf_token', MERCH_CSRF_TOKEN);
        return fetch('merch_stock_adjust.php', { method: 'POST', body: body, credentials: 'same-origin' })
          .then((r) => r.json().catch(() => ({ ok: false, error: 'Unexpected response (HTTP ' + r.status + ').' })));
      };
      document.querySelectorAll('.print-plate-finished').forEach((b) => {
        b.addEventListener('click', () => {
          let items;
          try { items = JSON.parse(b.dataset.items || '[]'); } catch (e) { return; }
          cur = { key: b.dataset.key || '', color: b.dataset.color, plate: b.dataset.plate, items: items, tokens: items.map(tok) };
          document.getElementById('merch-pd-title').textContent = 'Plate finished: ' + cur.plate + ' (' + cur.color + ')';
          rowsEl.innerHTML = '';
          items.forEach((it, i) => {
            const d = document.createElement('div'); d.style.margin = '4px 0';
            const lab = document.createElement('label'); lab.textContent = it.item + '  ';
            const inp = document.createElement('input');
            inp.type = 'number'; inp.min = '0'; inp.max = '50'; inp.value = it.qty; inp.id = 'merch-pd-q' + i; inp.style.width = '60px';
            lab.appendChild(inp); d.appendChild(lab); rowsEl.appendChild(d);
          });
          msg.textContent = ''; okBtn.disabled = false;
          dlg.showModal();
        });
      });
      // 2026-10-23: Start printing / Not printing. Appends to merch_printing.csv, then reloads
      // (the view and scroll position are remembered like after Finished).
      const printingPost = (params) => {
        const body = new URLSearchParams();
        Object.keys(params).forEach((k) => body.append(k, params[k]));
        body.append('csrf_token', MERCH_CSRF_TOKEN);
        return fetch('merch_printing_set.php', { method: 'POST', body: body, credentials: 'same-origin' })
          .then((r) => r.json().catch(() => ({ ok: false, error: 'Unexpected response (HTTP ' + r.status + ').' })));
      };
      const reloadKeepingScroll = () => {
        try {
          sessionStorage.setItem('merchAdminPlateScroll', JSON.stringify({ page: window.scrollY, pane: printPlatePane ? printPlatePane.scrollTop : 0 }));
        } catch (e) { /* storage unavailable - reload just starts at the top */ }
        location.reload();
      };
      document.querySelectorAll('.print-plate-start').forEach((b) => {
        b.addEventListener('click', () => {
          b.disabled = true;
          printingPost({ action: 'start', key: b.dataset.key, color: b.dataset.color, plate: b.dataset.plate, items: b.dataset.itemsText })
            .then((d) => { if (d.ok) { reloadKeepingScroll(); } else { alert('Could not save: ' + (d.error || 'unknown error')); b.disabled = false; } })
            .catch(() => { alert('Could not reach the server.'); b.disabled = false; });
        });
      });
      document.querySelectorAll('.print-plate-stop').forEach((b) => {
        b.addEventListener('click', () => {
          if (!confirm('Mark this plate as not printing? (Use Finished\u2026 instead if it came off the printer.)')) return;
          b.disabled = true;
          printingPost({ action: 'clear', key: b.dataset.key })
            .then((d) => { if (d.ok) { reloadKeepingScroll(); } else { alert('Could not save: ' + (d.error || 'unknown error')); b.disabled = false; } })
            .catch(() => { alert('Could not reach the server.'); b.disabled = false; });
        });
      });
      document.getElementById('merch-pd-cancel').addEventListener('click', () => dlg.close());
      okBtn.addEventListener('click', () => {
        if (!cur) { return; }
        okBtn.disabled = true; msg.style.color = '#555'; msg.textContent = 'Saving...';
        const done = [];
        let i = 0;
        const next = () => {
          if (i >= cur.items.length) {
            msg.style.color = '#2a7a2a';
            msg.textContent = 'Added: ' + (done.length ? done.join(', ') : 'nothing (all zero)') + '. Reloading...';
            // 2026-10-23: the plate is off the printer - clear its Currently printing mark (best effort).
            if (cur.key && done.length) { printingPost({ action: 'clear', key: cur.key }).catch(() => {}); }
            try {
              sessionStorage.setItem('merchAdminPlateScroll', JSON.stringify({ page: window.scrollY, pane: printPlatePane ? printPlatePane.scrollTop : 0 }));
            } catch (e) { /* storage unavailable - reload just starts at the top */ }
            setTimeout(() => location.reload(), 800);
            return;
          }
          const it = cur.items[i];
          const q = parseInt(document.getElementById('merch-pd-q' + i).value, 10);
          if (!(q > 0)) { i++; next(); return; }
          post({ action: 'add', item: it.item, color: cur.color, qty: String(q), token: cur.tokens[i] })
            .then((d) => {
              if (d.ok) { done.push(q + '\u00d7 ' + it.item); i++; next(); return; }
              msg.style.color = '#a00';
              msg.textContent = (d.error || 'Could not save.') + (done.length ? ' Already added: ' + done.join(', ') + ' (see Edit Stock to undo).' : ' Nothing was added.');
              okBtn.disabled = false;
            })
            .catch(() => {
              msg.style.color = '#a00';
              msg.textContent = 'Could not reach the server.' + (done.length ? ' Already added: ' + done.join(', ') + '.' : ' Nothing was added.') + ' Check Edit Stock before trying again.';
              okBtn.disabled = false;
            });
        };
        next();
      });
    })();

    // 2026-09-20 (Steve, item #5): "A Print button to get a hard copy
    // would also be useful." Scoped to just this pane via the
    // merch-printing-plates body class + the @media print rules near
    // the top of this file - toggled back off on afterprint so a plain
    // Ctrl+P anywhere else on the page later in the same tab isn't left
    // affected by a stale class.
    const printPlatePrintBtn = document.getElementById('merch-print-plate-print-btn');
    if (printPlatePrintBtn) {
      printPlatePrintBtn.addEventListener('click', () => {
        document.body.classList.add('merch-printing-plates');
        window.print();
      });
      window.addEventListener('afterprint', () => {
        document.body.classList.remove('merch-printing-plates');
      });
    }
  </script>
</body>
</html>
