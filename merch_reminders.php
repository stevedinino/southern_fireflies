<?php
// Build: 2026-08-29-A
// Admin-only page: preview-then-confirm list for the payment-reminder
// feature (2026-08-29, per Steve: "a bulk reminder email that gently
// nudges the people who placed requests but never paid"). Lists every
// customer group that's overdue - invoiced at least
// merch_reminder_min_age_days() days ago and still hasn't paid - for
// Ship orders only (Pickup customers pay in person - see
// merch_reminder_groups.php's header comment), with a checkbox per
// group (default checked) and a single "Send Reminders" button that
// sends only to the ones still checked when clicked.
//
// The minimum-age gate was added 2026-08-31 per Steve, after
// order-analytics on the live CSV showed most Ship customers pay
// within a few days of being invoiced - reminding someone who was
// just invoiced isn't a nudge, it's an irritation. See
// merch_reminder_groups.php's merch_reminder_min_age_days().
//
// Deliberately two-step, not a one-click "email everyone" button - per
// Steve's own answer when asked (AskUserQuestion, 2026-08-29): "Preview
// list, then confirm." Nothing is sent by just opening this page.
//
// The actual send goes to merch_send_reminders.php, which re-derives
// every group fresh from a live CSV read at send time (see that file
// and merch_reminder_groups.php) - this page's list is a snapshot at
// load time, same tradeoff packing_slips.php's checkboxes already
// carry, and is explicitly allowed to go stale between preview and
// send (the send endpoint just skips anything no longer eligible
// rather than erroring).

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_shipments.php';
require __DIR__ . '/merch_reminder_groups.php';

// Shared implementation in admin_guard.php as of 2026-08-20 (Finding
// 11, 2026-08-19 code review) - was previously duplicated across 8 files.
merch_require_admin_redirect('ourmerch.php');

$csvFile = __DIR__ . '/merchandise.csv';
$loaded = merch_load_csv($csvFile, 'merchandise.csv');
$col = merch_csv_column_map(
    $loaded['header'],
    merch_reminder_required_columns(),
    ['OrderID', 'Fulfillment', 'Email', 'Invoice Date', 'Pymt Date'],
    'merchandise.csv'
);

$groups = merch_reminder_build_groups($loaded['rows'], $col);
$groupCount = count($groups);
$minAgeDays = merch_reminder_min_age_days();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Payment Reminders &ndash; Southern Fireflies Retreats</title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; color: #222; margin: 24px; max-width: 720px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .generated-note { color: #666; font-size: 13px; margin-bottom: 20px; }

  .action-bar { margin-bottom: 20px; display: flex; align-items: center; gap: 14px; }
  .action-bar button { font-size: 15px; padding: 8px 16px; cursor: pointer; }
  .select-links { font-size: 13px; }
  .select-links a { color: var(--accent, #3a6); cursor: pointer; }
  .select-links a + a { margin-left: 10px; }

  .reminder-list { list-style: none; margin: 0; padding: 0; }

  .reminder-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid #ddd;
  }

  .reminder-check {
    width: 20px;
    height: 20px;
    margin-top: 3px;
    flex: 0 0 auto;
  }

  .reminder-body { flex: 1 1 auto; }

  .reminder-summary { font-size: 15px; line-height: 1.4; }

  .customer-name { font-weight: bold; }
  .customer-email { color: #444; }

  .item-lines {
    margin: 6px 0 0;
    padding-left: 4px;
    font-size: 14px;
    color: #333;
  }
  .item-lines div { margin: 2px 0; }

  .invoice-date-note { color: #888; font-size: 0.85em; margin-top: 4px; }

  .account-badge {
    display: inline-block;
    padding: 1px 6px;
    font-size: 0.7em;
    background: #eef;
    color: #448;
    border-radius: 3px;
    vertical-align: middle;
    margin-left: 6px;
  }

  .send-status { margin-left: 8px; font-size: 0.9em; font-weight: bold; }
  .send-status.status-sent { color: #2a7a2a; }
  .send-status.status-skipped { color: #888; }
  .send-status.status-failed { color: #b00020; }

  .reminder-check:checked ~ .reminder-body { }

  .empty-note { text-align: center; color: #666; margin-top: 60px; }

  .result-summary {
    margin-top: 20px;
    padding: 10px 14px;
    border-radius: 4px;
    background: #f3f8f3;
    border: 1px solid #cde5cd;
    font-size: 14px;
  }
</style>
</head>
<body>
  <h1>Payment Reminders</h1>
  <p class="generated-note">
    Generated <?= date('F j, Y g:ia') ?> &mdash; every Ship customer invoiced <?= $minAgeDays ?>+ days ago who
    still hasn't paid, one row per invoice (printed items and shop items from the same customer are kept
    separate, same as how they were invoiced). <?= $groupCount ?> group<?= $groupCount === 1 ? '' : 's' ?> shown.
    Uncheck anyone you don't want reminded, then click Send.
  </p>

  <?php if ($groupCount === 0): ?>
    <p class="empty-note">Nobody's overdue on a payment right now &mdash; nothing here until a Ship order has been invoiced for at least <?= $minAgeDays ?> days with its Pymt Date still blank.</p>
  <?php else: ?>
    <div class="action-bar">
      <button type="button" id="send-reminders-btn">Send Reminders</button>
      <span class="select-links">
        <a id="select-all-link">Select all</a><a id="select-none-link">Select none</a>
      </span>
    </div>

    <ul class="reminder-list">
      <?php foreach ($groups as $group): ?>
        <?php $itemLines = merch_reminder_format_item_lines($group['items']); ?>
        <li class="reminder-row" data-anchor-order-id="<?= htmlspecialchars($group['anchorOrderId'], ENT_QUOTES) ?>">
          <input type="checkbox" class="reminder-check" checked data-anchor-order-id="<?= htmlspecialchars($group['anchorOrderId'], ENT_QUOTES) ?>" />
          <div class="reminder-body">
            <div class="reminder-summary">
              <span class="customer-name"><?= htmlspecialchars($group['name']) ?></span>
              &mdash;
              <span class="customer-email"><?= htmlspecialchars($group['email']) ?></span>
              <?php if ($group['isPrinted']): ?>
                <span class="account-badge">Printed</span>
              <?php endif; ?>
            </div>
            <div class="item-lines">
              <?php foreach ($itemLines as $line): ?>
                <div>&bull; <?= htmlspecialchars($line) ?></div>
              <?php endforeach; ?>
            </div>
            <?php if ($group['invoiceDate'] !== ''): ?>
              <div class="invoice-date-note">
                Invoiced <?= htmlspecialchars($group['invoiceDate']) ?><?php if ($group['invoiceAgeDays'] !== null): ?> (<?= $group['invoiceAgeDays'] ?> days ago)<?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>

    <div id="result-summary" class="result-summary" hidden></div>
  <?php endif; ?>

  <script>
    // 2026-08-29 (Finding 9): merch_send_reminders.php refuses a request
    // without a matching CSRF token - see csrf.php and ourmerch.php's
    // own MERCH_CSRF_TOKEN for the same wiring there.
    const MERCH_CSRF_TOKEN = <?= json_encode(merch_csrf_token()) ?>;

    const selectAllLink = document.getElementById('select-all-link');
    const selectNoneLink = document.getElementById('select-none-link');
    if (selectAllLink && selectNoneLink) {
      selectAllLink.addEventListener('click', () => {
        document.querySelectorAll('.reminder-check').forEach((cb) => { cb.checked = true; });
      });
      selectNoneLink.addEventListener('click', () => {
        document.querySelectorAll('.reminder-check').forEach((cb) => { cb.checked = false; });
      });
    }

    const sendBtn = document.getElementById('send-reminders-btn');
    if (sendBtn) {
      sendBtn.addEventListener('click', () => {
        const checked = Array.from(document.querySelectorAll('.reminder-check:checked'));
        if (checked.length === 0) {
          alert('Select at least one customer to remind.');
          return;
        }
        const anchorIds = checked.map((cb) => cb.dataset.anchorOrderId);

        // Irreversible (a real email goes out) - matches ourmerch.php's
        // own confirm() before sendInvoice(), not the no-confirm
        // convention used for reversible checkbox toggles elsewhere.
        if (!confirm(`Send a payment reminder to ${anchorIds.length} customer${anchorIds.length === 1 ? '' : 's'}?`)) {
          return;
        }

        sendBtn.disabled = true;
        sendBtn.textContent = 'Sending…';
        document.querySelectorAll('.reminder-check').forEach((cb) => { cb.disabled = true; });

        fetch('merch_send_reminders.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `anchorOrderIds=${encodeURIComponent(anchorIds.join(','))}&csrf_token=${encodeURIComponent(MERCH_CSRF_TOKEN)}`
        })
          .then((r) => r.json())
          .then((data) => {
            sendBtn.textContent = 'Send Reminders';
            if (!data.ok) {
              alert('Could not send reminders: ' + (data.error || 'unknown error'));
              sendBtn.disabled = false;
              document.querySelectorAll('.reminder-check').forEach((cb) => { cb.disabled = false; });
              return;
            }

            const byAnchor = {};
            data.results.forEach((r) => { byAnchor[r.anchorOrderId] = r; });

            document.querySelectorAll('.reminder-row').forEach((row) => {
              const anchorId = row.dataset.anchorOrderId;
              const result = byAnchor[anchorId];
              if (!result) {
                return; // wasn't selected - no result to show
              }

              const status = document.createElement('span');
              if (result.ok) {
                status.className = 'send-status status-sent';
                status.textContent = 'Sent ✓';
              } else if (result.skipped) {
                status.className = 'send-status status-skipped';
                status.textContent = 'Skipped - ' + result.error;
              } else {
                status.className = 'send-status status-failed';
                status.textContent = 'Failed - ' + result.error;
              }
              row.querySelector('.reminder-summary').appendChild(status);
            });

            const summary = document.getElementById('result-summary');
            const failedOrSkipped = data.results.length - data.sentCount;
            summary.hidden = false;
            summary.textContent = `Sent ${data.sentCount} reminder${data.sentCount === 1 ? '' : 's'}.`
              + (failedOrSkipped > 0 ? ` ${failedOrSkipped} skipped or failed - see details above.` : '');

            sendBtn.disabled = false;
          })
          .catch(() => {
            alert('Could not send reminders - check your connection and try again.');
            sendBtn.textContent = 'Send Reminders';
            sendBtn.disabled = false;
            document.querySelectorAll('.reminder-check').forEach((cb) => { cb.disabled = false; });
          });
      });
    }
  </script>
</body>
</html>
