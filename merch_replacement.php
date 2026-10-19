<?php
// Build: 2026-10-19-A
// ============================================================
// Admin-only page: enter a REPLACEMENT (no-charge) order for printed
// pieces - a packing miss, a broken or defective part, or anything else
// you are re-sending at your own cost (Steve, 2026-10-19).
//
// Why it exists: replacements used to be a dummy order silently marked
// Paid, which looked like a real paid order in the data (and would have
// muddied the books). A replacement is a normal merchandise.csv row so it
// flows through the same path as every other order - the Stock & Print
// Plan prints it, "Finished..." puts it on the shelf, Done pulls it and
// marks it Created - but it is tagged so it can always be told apart and
// totalled up as a cost:
//   Retreat      = REPLACEMENT
//   Notes        = REPLACEMENT - <reason> (replaces #<source order>)[: note]
//   Price/Tax/Shipping = 0
//   Invoice Date = Pymt Date = today (so it is never invoiced, never shows
//                  as awaiting payment, and no payment is logged)
// The customer is copied from the order being replaced (name, email,
// phone, address); replacements always ship.
//
// POST action=create : orderId (the order being replaced), reason, note,
//                      lines = JSON [{item,color,qty}, ...] (max 8)
// Same admin + CSRF + flock + backup discipline as the other writers.
// ============================================================

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/id_sequence.php';
require_once __DIR__ . '/merch_backup.php';
require_once __DIR__ . '/csv_safety.php';

const MERCH_REPLACEMENT_REASONS = ['Packing miss', 'Broken or defective', 'Other'];

/**
 * Pure validation/row-building so tests can drive it. $rows = the CSV
 * (header first). Returns ['ok'=>true,'source'=>row,'lines'=>[...]] or
 * ['ok'=>false,'error'=>...].
 */
function merch_replacement_plan(array $rows, string $orderId, string $reason, array $lines): array
{
    if (!ctype_digit($orderId)) {
        return ['ok' => false, 'error' => 'Enter the order number being replaced.'];
    }
    if (!in_array($reason, MERCH_REPLACEMENT_REASONS, true)) {
        return ['ok' => false, 'error' => 'Pick a reason.'];
    }
    $header = $rows[0] ?? [];
    $ix = array_flip($header);
    foreach (['OrderID', 'Name', 'Email', 'Address', 'City', 'State', 'Zip', 'Item'] as $need) {
        if (!isset($ix[$need])) {
            return ['ok' => false, 'error' => 'Expected column ' . $need . ' not found in the CSV.'];
        }
    }
    $source = null;
    foreach (array_slice($rows, 1) as $r) {
        if (trim($r[$ix['OrderID']] ?? '') === $orderId) {
            $source = $r;
            break;
        }
    }
    if ($source === null) {
        return ['ok' => false, 'error' => 'Order #' . $orderId . ' was not found.'];
    }
    if (trim($source[$ix['Address']] ?? '') === '' || trim($source[$ix['Zip']] ?? '') === '') {
        return ['ok' => false, 'error' => 'Order #' . $orderId . ' has no shipping address on file, so a replacement cannot be shipped from it.'];
    }
    $clean = [];
    foreach ($lines as $l) {
        $item = trim((string) ($l['item'] ?? ''));
        $color = trim((string) ($l['color'] ?? ''));
        $qty = (int) ($l['qty'] ?? 0);
        if ($item === '' && $color === '') {
            continue; // blank line
        }
        if (!in_array($item, FILAMENT_COLOR_ITEMS, true)) {
            return ['ok' => false, 'error' => 'Replacements are for printed pieces only; "' . $item . '" is not one.'];
        }
        if (!in_array($color, merch_color_options_for_item($item), true)) {
            return ['ok' => false, 'error' => 'Pick a valid color for ' . $item . '.'];
        }
        if ($qty < 1 || $qty > 20) {
            return ['ok' => false, 'error' => 'Quantity for ' . $item . ' must be 1 to 20.'];
        }
        $clean[] = ['item' => $item, 'color' => $color, 'qty' => $qty];
    }
    if (empty($clean)) {
        return ['ok' => false, 'error' => 'Add at least one piece.'];
    }
    if (count($clean) > 8) {
        return ['ok' => false, 'error' => 'At most 8 lines at a time.'];
    }
    return ['ok' => true, 'source' => $source, 'ix' => $ix, 'lines' => $clean];
}

/** Build one CSV row (in the file's own header order) for a planned line. */
function merch_replacement_row(array $header, array $plan, array $line, string $orderId, string $reason, string $note, int $newOrderId, int $groupId, string $today, string $timestamp): array
{
    $src = $plan['source'];
    $ix = $plan['ix'];
    $get = fn(string $c) => isset($ix[$c]) ? trim((string) ($src[$ix[$c]] ?? '')) : '';
    $notes = 'REPLACEMENT - ' . $reason . ' (replaces #' . $orderId . ')' . ($note !== '' ? ': ' . $note : '');
    $values = [
        'OrderID' => $newOrderId,
        'Name' => $get('Name'),
        'Email' => $get('Email'),
        'Phone' => $get('Phone'),
        'Item' => $line['item'],
        'Quantity' => $line['qty'],
        'Color' => $line['color'],
        'Original Color' => $line['color'],
        'Size' => '',
        'Sleeve' => '',
        'Notes' => merch_csv_safe_cell($notes),
        'Fulfillment' => 'Ship',
        'Address' => $get('Address'),
        'City' => $get('City'),
        'State' => $get('State'),
        'Zip' => $get('Zip'),
        'Price' => '0',
        'Tax' => '0',
        'Shipping' => '0',
        'Invoice Date' => $today,
        'Pymt Date' => $today,
        'Created' => '',
        'Fulfilled' => '',
        'Timestamp' => $timestamp,
        'IP' => '',
        'Retreat' => 'REPLACEMENT',
        'Cancelled' => '',
        'OrderGroupID' => $groupId,
        'Qty Created' => '',
    ];
    $row = [];
    foreach ($header as $col) {
        $row[] = array_key_exists($col, $values) ? $values[$col] : '';
    }
    return $row;
}

// tests/test_workflow_20261019.php defines this to load only the functions above.
if (defined('MERCH_REPLACEMENT_LIB_ONLY')) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json');
    merch_require_admin_json();
    merch_require_csrf_json();
    session_write_close();

    $fail = function (int $code, string $msg): void {
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    };
    $orderId = trim((string) ($_POST['orderId'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));
    $note = mb_substr(preg_replace('/\s+/', ' ', $note), 0, 200);
    $lines = json_decode((string) ($_POST['lines'] ?? '[]'), true);
    if (!is_array($lines)) {
        $fail(400, 'Invalid request.');
    }

    $csvFile = __DIR__ . '/merchandise.csv';
    $handle = fopen($csvFile, 'c+');
    if (!$handle) {
        $fail(500, 'Could not open merchandise.csv.');
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        $fail(500, 'Could not lock merchandise.csv - try again.');
    }
    $rows = [];
    while (($r = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $rows[] = $r;
    }
    if (empty($rows)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        $fail(500, 'merchandise.csv is empty.');
    }
    if (isset($rows[0][0])) {
        $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);
    }
    $plan = merch_replacement_plan($rows, $orderId, $reason, $lines);
    if (!$plan['ok']) {
        flock($handle, LOCK_UN);
        fclose($handle);
        $fail(400, $plan['error']);
    }
    $header = $rows[0];
    $max = 0;
    foreach (array_slice($rows, 1) as $r) {
        if (isset($r[0]) && is_numeric($r[0])) {
            $max = max($max, (int) $r[0]);
        }
    }
    merch_backup_csv($csvFile, __DIR__ . '/backups');
    $groupId = merch_next_persistent_id(__DIR__ . '/merchandise_ordergroupid_counter.txt', 0);
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    fseek($handle, 0, SEEK_END);
    $made = [];
    foreach ($plan['lines'] as $line) {
        $newId = merch_next_persistent_id(__DIR__ . '/merchandise_orderid_counter.txt', $max);
        $row = merch_replacement_row($header, $plan, $line, $orderId, $reason, $note, $newId, $groupId, $today, $now);
        fputcsv($handle, $row, ',', '"', '\\');
        $made[] = ['orderId' => $newId, 'item' => $line['item'], 'color' => $line['color'], 'qty' => $line['qty']];
    }
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    echo json_encode(['ok' => true, 'name' => trim((string) ($plan['source'][$plan['ix']['Name']] ?? '')), 'made' => $made]);
    exit;
}

// ---- Page --------------------------------------------------------
merch_require_admin_redirect('ourmerch.php');
$csrfToken = merch_csrf_token();
session_write_close();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$colorMap = [];
foreach (FILAMENT_COLOR_ITEMS as $it) {
    $colorMap[$it] = merch_color_options_for_item($it);
}
$presetOrder = preg_match('/^\d+$/', (string) ($_GET['orderId'] ?? '')) ? (string) $_GET['orderId'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Replacement order</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 640px; margin: 24px auto; padding: 0 16px; color: #222; }
    label { display: block; margin: 12px 0 4px; font-weight: bold; }
    input, select { padding: 6px; font-size: 15px; }
    .line { display: flex; gap: 8px; margin: 6px 0; flex-wrap: wrap; }
    .line select { max-width: 220px; }
    .line input[type=number] { width: 64px; }
    .note { color: #555; font-size: 14px; }
    button { padding: 7px 14px; font-size: 15px; cursor: pointer; }
    #msg { margin: 14px 0; min-height: 1.4em; }
  </style>
</head>
<body>
  <p><a href="ourmerch.php">&larr; Orders</a></p>
  <h1>Replacement order (no charge)</h1>
  <p class="note">For a packing miss, a broken part, or anything you are re-sending at your own cost. It copies the customer and address from the order being replaced, is never invoiced, and shows up on the Stock &amp; Print Plan like any other order. Once it is printed, press Finished, then Done, as usual.</p>

  <label for="orderId">Order being replaced (order #)</label>
  <input type="number" id="orderId" min="1" value="<?= $h($presetOrder) ?>" />

  <label for="reason">Reason</label>
  <select id="reason">
    <option value="">Choose&hellip;</option>
    <?php foreach (MERCH_REPLACEMENT_REASONS as $r): ?><option><?= $h($r) ?></option><?php endforeach; ?>
  </select>

  <label>Pieces to re-send</label>
  <div id="lines"></div>
  <button type="button" id="addLine">+ another piece</button>

  <label for="note">Note (optional)</label>
  <input type="text" id="note" maxlength="200" style="width:100%" />

  <p id="msg"></p>
  <button type="button" id="go">Create replacement</button>

<script>
(function () {
  var ITEMS = <?= json_encode(array_values(FILAMENT_COLOR_ITEMS)) ?>;
  var COLORS = <?= json_encode($colorMap) ?>;
  var CSRF = <?= json_encode($csrfToken) ?>;
  var linesEl = document.getElementById('lines');
  var msg = document.getElementById('msg');
  function addLine() {
    var d = document.createElement('div'); d.className = 'line';
    var it = document.createElement('select');
    it.innerHTML = '<option value="">Item&hellip;</option>' + ITEMS.map(function (i) { return '<option>' + i.replace(/&/g, '&amp;') + '</option>'; }).join('');
    var co = document.createElement('select'); co.innerHTML = '<option value="">Color&hellip;</option>';
    var q = document.createElement('input'); q.type = 'number'; q.min = '1'; q.max = '20'; q.value = '1';
    it.addEventListener('change', function () {
      co.innerHTML = '<option value="">Color&hellip;</option>' + (COLORS[it.value] || []).map(function (c) { return '<option>' + c.replace(/&/g, '&amp;') + '</option>'; }).join('');
    });
    d.appendChild(it); d.appendChild(co); d.appendChild(q); linesEl.appendChild(d);
  }
  addLine();
  document.getElementById('addLine').addEventListener('click', addLine);
  document.getElementById('go').addEventListener('click', function () {
    var lines = [];
    Array.prototype.forEach.call(linesEl.children, function (d) {
      var s = d.querySelectorAll('select'), q = d.querySelector('input');
      lines.push({ item: s[0].value, color: s[1].value, qty: parseInt(q.value, 10) || 0 });
    });
    var body = new URLSearchParams();
    body.append('orderId', document.getElementById('orderId').value);
    body.append('reason', document.getElementById('reason').value);
    body.append('note', document.getElementById('note').value);
    body.append('lines', JSON.stringify(lines));
    body.append('csrf_token', CSRF);
    msg.style.color = '#555'; msg.textContent = 'Saving...';
    var btn = this; btn.disabled = true;
    fetch('merch_replacement.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected response.' }; }); })
      .then(function (d) {
        if (d.ok) {
          msg.style.color = '#2a7a2a';
          msg.textContent = 'Created for ' + d.name + ': ' + d.made.map(function (m) { return '#' + m.orderId + ' ' + m.qty + '× ' + m.item; }).join(', ') + '. It is on the Stock & Print Plan now.';
          linesEl.innerHTML = ''; addLine();
        } else { msg.style.color = '#a00'; msg.textContent = d.error || 'Could not save.'; }
        btn.disabled = false;
      })
      .catch(function () { msg.style.color = '#a00'; msg.textContent = 'Could not reach the server.'; btn.disabled = false; });
  });
})();
</script>
</body>
</html>
