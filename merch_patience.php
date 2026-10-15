<?php
// Build: 2026-10-15-A
// Admin-only page: preview-then-confirm list for the "please be
// patient" email (2026-10-15, per Steve). Lists every customer who has
// PAID and is still waiting on a printed Ship item, one row per
// customer, each with a checkbox (default checked), the greeting the
// email will use, their items, and the exact email as they would read
// it. One "Send" button emails everyone still checked.
//
// Anyone who already got this email within the resend-guard window
// (merch_patience_resend_guard_days()) is flagged and UNCHECKED, and
// sending to them takes a second, explicit confirm.
//
// Nothing is sent by opening this page. Sending goes to
// merch_send_patience.php in small batches; that endpoint re-derives
// every recipient from a fresh CSV read, so a stale list self-corrects.
// No copy of any email goes to Steve; see merch_email_log.csv instead.

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_shipments.php';
require __DIR__ . '/merch_patience_email.php';

merch_require_admin_redirect('ourmerch.php');

$loaded = merch_load_csv(__DIR__ . '/merchandise.csv', 'merchandise.csv');
$col = merch_csv_column_map(
    $loaded['header'],
    merch_patience_required_columns(),
    merch_patience_hard_required_columns(),
    'merchandise.csv'
);

$recipients = merch_patience_build_recipients($loaded['rows'], $col);
$unreachable = merch_patience_unreachable($loaded['rows'], $col);
$lastSent = merch_email_log_last_sent(merch_patience_kind());
$count = count($recipients);
$guardDays = merch_patience_resend_guard_days();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$recentCount = 0;
foreach ($recipients as $i => $r) {
    $recipients[$i]['recent'] = merch_patience_recently_sent($lastSent, $r['email']);
    if ($recipients[$i]['recent'] !== null) {
        $recentCount++;
    }
}
$sampleHtml = $count > 0 ? merch_patience_content($recipients[0])['html'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Patience Email &ndash; Southern Fireflies Retreats</title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; color: #222; margin: 24px; max-width: 780px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .generated-note { color: #666; font-size: 13px; margin-bottom: 16px; }
  .action-bar { margin-bottom: 16px; display: flex; align-items: center; gap: 14px; }
  .action-bar button { font-size: 15px; padding: 8px 16px; cursor: pointer; }
  .select-links { font-size: 13px; }
  .select-links a { color: var(--accent, #3a6); cursor: pointer; }
  .select-links a + a { margin-left: 10px; }
  .notice { margin: 0 0 16px; padding: 8px 12px; font-size: 13px; border-radius: 3px;
            background: #fff6e0; border: 1px solid #e8cf8a; color: #6b5416; }
  .row-list { list-style: none; margin: 0; padding: 0; }
  .row { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-bottom: 1px solid #ddd; }
  .row-check { width: 20px; height: 20px; margin-top: 3px; flex: 0 0 auto; }
  .row-body { flex: 1 1 auto; }
  .customer-name { font-weight: bold; }
  .customer-email { color: #444; }
  .greeting { color: #666; font-size: 13px; }
  .item-lines { margin: 6px 0 0; padding-left: 4px; font-size: 14px; color: #333; }
  .item-lines div { margin: 2px 0; }
  .recent-note { margin-top: 6px; font-size: 0.85em; color: #6b5416; }
  details.preview { margin-top: 6px; font-size: 13px; }
  details.preview summary { cursor: pointer; color: var(--accent, #3a6); }
  details.preview pre { white-space: pre-wrap; background: #f7f7f7; border: 1px solid #e2e2e2; padding: 8px 10px; }
  .send-status { margin-left: 8px; font-size: 0.9em; font-weight: bold; }
  .status-sent { color: #2a7a2a; }
  .status-skipped { color: #888; }
  .status-failed { color: #b00020; }
  .empty-note { text-align: center; color: #666; margin-top: 60px; }
  .result-summary { margin-top: 20px; padding: 10px 14px; border-radius: 4px; background: #f3f8f3;
                    border: 1px solid #cde5cd; font-size: 14px; }
  .sample-frame { width: 100%; height: 420px; border: 1px solid #ccc; margin: 0 0 20px; }
</style>
</head>
<body>
  <h1>Patience Email</h1>
  <p class="generated-note">
    Generated <?= date('F j, Y g:ia') ?> &mdash; every customer who has paid and is still waiting on a PRINTED
    item shipping to them (pickup orders, shirts and hats are not included). One email per customer.
    <?= $count ?> customer<?= $count === 1 ? '' : 's' ?> shown. Nobody is copied on these emails; each send is
    recorded in merch_email_log.csv.
  </p>

  <?php if (!empty($unreachable)): ?>
    <div class="notice">
      &#9888; Paid and waiting, but NO usable email address on the order (not emailed):
      <?php foreach ($unreachable as $u): ?>
        <?= $h($u['name'] !== '' ? $u['name'] : '(no name)') ?> [order <?= $h(implode(', ', $u['orderIds'])) ?>];
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($count === 0): ?>
    <p class="empty-note">Nobody is waiting on a paid printed Ship order right now.</p>
  <?php else: ?>
    <?php if ($recentCount > 0): ?>
      <div class="notice">
        <?= $recentCount ?> customer<?= $recentCount === 1 ? ' was' : 's were' ?> already sent this email within
        the last <?= $guardDays ?> days. They are unchecked below. Checking them anyway will ask you to confirm a repeat.
      </div>
    <?php endif; ?>
    <p class="generated-note">Sample of the email (first person on the list):</p>
    <iframe class="sample-frame" sandbox srcdoc="<?= $h($sampleHtml) ?>"></iframe>

    <div class="action-bar">
      <button type="button" id="send-btn">Send Patience Email</button>
      <span class="select-links"><a id="select-all-link">Select all</a><a id="select-none-link">Select none</a></span>
      <span id="progress" class="generated-note" style="margin:0"></span>
    </div>

    <ul class="row-list">
      <?php foreach ($recipients as $r): ?>
        <?php $content = merch_patience_content($r); ?>
        <li class="row" data-email="<?= $h($r['email']) ?>">
          <input type="checkbox" class="row-check" <?= $r['recent'] === null ? 'checked' : '' ?>
                 data-email="<?= $h($r['email']) ?>" data-recent="<?= $r['recent'] === null ? '0' : '1' ?>" />
          <div class="row-body">
            <div class="row-summary">
              <span class="customer-name"><?= $h($r['name']) ?></span> &mdash;
              <span class="customer-email"><?= $h($r['email']) ?></span>
              <span class="greeting">&nbsp;(greeting: &ldquo;Hi <?= $h($r['firstName']) ?>,&rdquo;)</span>
            </div>
            <div class="item-lines">
              <?php foreach (merch_patience_format_item_lines($r['lines']) as $line): ?>
                <div>&bull; <?= $h($line) ?></div>
              <?php endforeach; ?>
            </div>
            <?php if ($r['oldestPaid'] !== ''): ?>
              <div class="greeting">Paid <?= $h($r['oldestPaid']) ?></div>
            <?php endif; ?>
            <?php if ($r['recent'] !== null): ?>
              <div class="recent-note">&#9888; Already sent <?= $h($r['recent']) ?> &mdash; unchecked.</div>
            <?php endif; ?>
            <details class="preview">
              <summary>Preview this email</summary>
              <div><strong>Subject:</strong> <?= $h($content['subject']) ?></div>
              <pre><?= $h($content['text']) ?></pre>
            </details>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>

    <div id="result-summary" class="result-summary" hidden></div>
  <?php endif; ?>

  <script>
    const MERCH_CSRF_TOKEN = <?= json_encode(merch_csrf_token()) ?>;
    const BATCH_SIZE = 5;

    const checks = () => Array.from(document.querySelectorAll('.row-check'));
    const allLink = document.getElementById('select-all-link');
    const noneLink = document.getElementById('select-none-link');
    if (allLink && noneLink) {
      allLink.addEventListener('click', () => checks().forEach((cb) => { cb.checked = true; }));
      noneLink.addEventListener('click', () => checks().forEach((cb) => { cb.checked = false; }));
    }

    const sendBtn = document.getElementById('send-btn');
    if (sendBtn) {
      sendBtn.addEventListener('click', async () => {
        const chosen = checks().filter((cb) => cb.checked);
        if (chosen.length === 0) { alert('Select at least one customer.'); return; }
        const repeats = chosen.filter((cb) => cb.dataset.recent === '1');
        if (!confirm(`Send the patience email to ${chosen.length} customer${chosen.length === 1 ? '' : 's'}? This cannot be undone.`)) return;
        let allowRepeat = '0';
        if (repeats.length > 0) {
          if (!confirm(`${repeats.length} of these already received this email within the last <?= $guardDays ?> days. Send to them AGAIN?`)) return;
          allowRepeat = '1';
        }

        sendBtn.disabled = true;
        checks().forEach((cb) => { cb.disabled = true; });
        const emails = chosen.map((cb) => cb.dataset.email);
        const progress = document.getElementById('progress');
        let sent = 0, notSent = 0, aborted = false;

        for (let i = 0; i < emails.length; i += BATCH_SIZE) {
          const batch = emails.slice(i, i + BATCH_SIZE);
          progress.textContent = `Sending ${Math.min(i + BATCH_SIZE, emails.length)} of ${emails.length}…`;
          try {
            const resp = await fetch('merch_send_patience.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: `emails=${encodeURIComponent(batch.join(','))}&allowRepeat=${allowRepeat}&csrf_token=${encodeURIComponent(MERCH_CSRF_TOKEN)}`
            });
            const data = await resp.json();
            if (!data.ok) { alert('Stopped: ' + (data.error || 'unknown error')); aborted = true; break; }
            data.results.forEach((res) => {
              const row = document.querySelector(`.row[data-email="${CSS.escape(res.email)}"]`);
              if (!row) return;
              const s = document.createElement('span');
              if (res.ok) { s.className = 'send-status status-sent'; s.textContent = 'Sent ✓'; sent++; }
              else if (res.skipped) { s.className = 'send-status status-skipped'; s.textContent = 'Skipped - ' + res.error; notSent++; }
              else { s.className = 'send-status status-failed'; s.textContent = 'Failed - ' + res.error; notSent++; }
              row.querySelector('.row-summary').appendChild(s);
            });
          } catch (e) {
            alert('Lost contact with the server. Check merch_email_log.csv before sending again - some of this batch may have gone out.');
            aborted = true;
            break;
          }
        }

        progress.textContent = '';
        const summary = document.getElementById('result-summary');
        summary.hidden = false;
        summary.textContent = `Sent ${sent}.` + (notSent > 0 ? ` ${notSent} skipped or failed - see details above.` : '')
          + (aborted ? ' The run stopped early; the rest were not sent.' : '');
        sendBtn.disabled = false;
        checks().forEach((cb) => { cb.disabled = false; cb.checked = false; });
      });
    }
  </script>
</body>
</html>
