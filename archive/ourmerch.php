<?php
// Build: 2026-07-27-B
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
              echo '<tr data-created="' . ($rowIsCreated ? '1' : '0') . '" data-fulfilled="' . ($rowIsFulfilled ? '1' : '0') . '" data-invoiced="' . ($rowIsInvoiced ? '1' : '0') . '" data-paid="' . ($rowIsPaid ? '1' : '0') . '">';
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
          <a href="shippo_export.php" style="color: var(--accent);">Download Shippo Export (paid, unshipped orders) &rarr;</a>
          &nbsp;&mdash;&nbsp;
          <span style="color:#bbb; font-size:0.75em;">Build 2026-07-27-B</span>
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
    document.querySelectorAll('.merch-invoice-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (!confirm('Send an invoice email for this order (and any other un-invoiced orders from the same customer)?')) {
          return;
        }
        const orderId = btn.dataset.orderId;
        btn.disabled = true;
        btn.textContent = 'Sending…';

        fetch('merch_invoice.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `orderId=${encodeURIComponent(orderId)}`
        })
          .then((r) => r.json())
          .then((data) => {
            if (data.ok) {
              location.reload();
            } else if (data.needsManualQuote) {
              // Not a failure - the customer got a "we'll follow up" note
              // and Steve got a flagged internal alert instead. Nothing
              // on this row changed (no Invoiced stamp), so no reload.
              alert(data.error);
              btn.disabled = false;
              btn.textContent = 'Send Invoice';
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
    let currentView = 'all';

    function applyView(view) {
      currentView = view;
      viewButtons.forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.view === view);
      });
      document.querySelectorAll('table tr[data-fulfilled]').forEach((tr) => {
        const created = tr.dataset.created === '1';
        const fulfilled = tr.dataset.fulfilled === '1';
        const invoiced = tr.dataset.invoiced === '1';
        const paid = tr.dataset.paid === '1';

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
            show = created && !fulfilled;
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
  </script>
</body>
</html>
