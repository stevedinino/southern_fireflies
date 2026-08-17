<?php
// Build: 2026-08-15-A
session_start();
require __DIR__ . '/config.php';

// Log out
if (isset($_POST['logout'])) {
    unset($_SESSION['sff_admin_ok']);
    header('Location: ourmerch.php');
    exit;
}

// Simple password gate (same login as ourguests.php - one admin, two lists).
$loginError = '';
if (!isset($_SESSION['sff_admin_ok'])) {
    if (isset($_POST['admin_password'])) {
        if (hash_equals(ADMIN_PASSWORD, $_POST['admin_password'])) {
            $_SESSION['sff_admin_ok'] = true;
        } else {
            $loginError = 'Incorrect password.';
        }
    }
}

if (!isset($_SESSION['sff_admin_ok'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8" />
      <title>Admin Login – Southern Fireflies Retreats</title>
      <link rel="stylesheet" href="styles/layout.css" />
    </head>
    <body>
      <div class="content-wrapper">
        <div class="form-container">
          <h2>Admin Login</h2>
          <?php if ($loginError !== ''): ?>
            <p style="color:#b00020; text-align:center;"><?= htmlspecialchars($loginError) ?></p>
          <?php endif; ?>
          <form method="POST">
            <input type="password" name="admin_password" placeholder="Password" required />
            <button type="submit" class="btn full-width">Log In</button>
          </form>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}
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
              if (!$rPaid || !$rShipping || $rFulfilled) {
                  continue; // not part of "what needs to ship" yet - doesn't gate the group
              }
              $normalizedName = strtolower(trim(preg_replace('/\s+/', ' ', $data[$nameIndex] ?? '')));
              $zip = strtolower(trim($data[$zipIndex] ?? ''));
              $groupKey = $normalizedName . '|' . $zip;
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
          echo '</div>';
          echo '<div class="merch-table-pane"><table style="width:100%; border-collapse: collapse;">';
          echo '<tr>';
          foreach ($header as $col) {
              echo '<th style="padding:6px; text-align:left; white-space:nowrap;">' . htmlspecialchars($col) . '</th>';
          }
          echo '</tr>';

          foreach ($rows as $data) {
              $orderId = $orderIdIndex !== false ? ($data[$orderIdIndex] ?? '') : '';
              $rowIsCreated = $createdIndex !== false && trim($data[$createdIndex] ?? '') !== '';
              $rowIsFulfilled = $fulfilledIndex !== false && trim($data[$fulfilledIndex] ?? '') !== '';
              $rowIsInvoiced = $invoiceDateIndex !== false && trim($data[$invoiceDateIndex] ?? '') !== '';
              $rowIsPaid = $pymtDateIndex !== false && trim($data[$pymtDateIndex] ?? '') !== '';
              $rowIsShipping = $fulfillmentIndex !== false && trim($data[$fulfillmentIndex] ?? '') === 'Ship';
              // Same normalized Name+Zip key as the pre-scan above -
              // defaults to "ready" (true) when Name/Zip columns are
              // missing or this row isn't part of any tracked shipment,
              // so it never blocks a row that the pre-scan didn't cover.
              $rowNormalizedName = $nameIndex !== false ? strtolower(trim(preg_replace('/\s+/', ' ', $data[$nameIndex] ?? ''))) : '';
              $rowZip = $zipIndex !== false ? strtolower(trim($data[$zipIndex] ?? '')) : '';
              $rowShipmentReady = $shipmentAllCreated[$rowNormalizedName . '|' . $rowZip] ?? true;
              echo '<tr data-created="' . ($rowIsCreated ? '1' : '0') . '" data-fulfilled="' . ($rowIsFulfilled ? '1' : '0') . '" data-invoiced="' . ($rowIsInvoiced ? '1' : '0') . '" data-paid="' . ($rowIsPaid ? '1' : '0') . '" data-shipping="' . ($rowIsShipping ? '1' : '0') . '" data-shipment-ready="' . ($rowShipmentReady ? '1' : '0') . '">';
              foreach ($data as $i => $cell) {
                  if ($i === $createdIndex || $i === $fulfilledIndex || $i === $pymtDateIndex) {
                      // All three are the same pattern now: one column,
                      // checkbox toggles it, blank-or-date is the whole
                      // status. Fulfilled and Pymt Date also cascade
                      // into another column server-side (see
                      // merch_update.php) - the shared JS handler below
                      // updates whichever second cell that touches,
                      // found via matching data-order-id + data-field.
                      $fieldName = ($i === $createdIndex) ? 'Created' : (($i === $fulfilledIndex) ? 'Fulfilled' : 'Pymt Date');
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
                      echo '<input type="checkbox" class="merch-status-toggle" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-field="' . htmlspecialchars($fieldName, ENT_QUOTES) . '" ' . ($isChecked ? 'checked' : '') . ' />';
                      echo '<br /><span class="merch-status-date" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '" data-field="' . htmlspecialchars($fieldName, ENT_QUOTES) . '" style="font-size:0.8em; color:#888;">' . htmlspecialchars($displayValue) . '</span>';
                      echo '</td>';
                  } elseif ($i === $invoiceDateIndex) {
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
                      } else {
                          echo '<button type="button" class="btn merch-invoice-btn" style="padding:4px 10px; font-size:0.85em;" data-order-id="' . htmlspecialchars($orderId, ENT_QUOTES) . '">Send Invoice</button>';
                      }
                      echo '</span>';
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
          <span style="color:#bbb; font-size:0.75em;">Build 2026-08-14-A</span>
        </p>
      </div>
    </div>
  </div>

  <script>
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
          <div style="color:#666; margin-bottom:6px;">Subtotal ${money(data.subtotal)} + tax ${money(data.tax)}</div>
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

        let show = true;
        switch (view) {
          case 'active':
            show = !fulfilled;
            break;
          case 'needs-invoicing':
            show = !invoiced;
            break;
          case 'needs-payment':
            show = invoiced && !paid;
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
