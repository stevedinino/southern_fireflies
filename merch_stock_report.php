<?php
// Build: 2026-10-03-A
// Admin-only, READ-ONLY report: "what can I ship from what I've already
// printed, and what should I print next?" (Steve, 2026-10-02 - see
// merch_stock.php's header comment for the rules it follows: whole
// shipments only, paid first, exact color only).
//
// Reads merchandise.csv and inventory.csv (his printed-parts sheet, saved
// as CSV and uploaded here - see merch_stock_upload.php). Writes nothing
// except through that upload form. Nothing is decremented or marked
// Created by viewing this page; pulling stock for an order is still done
// by hand, same as before.
//
// Two views of the same numbers:
//   - Close-out: the N cheapest-to-finish paid orders (fewest pieces
//     missing), with the plates that would finish exactly those.
//   - Batch: everything still missing, grouped by color (biggest color
//     first) - for when he'd rather just run filament down.
// Plate planning reuses print_plates.php (confirmed combos, solo
// capacities, keep-one-customer-together), fed only the pieces that are
// STILL missing after stock is applied.

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_shipments.php';
require __DIR__ . '/print_plates.php';
require __DIR__ . '/merch_stock.php';

merch_require_admin_redirect('ourmerch.php');

$csrfToken = merch_csrf_token();
// Read-only page past this point (the upload goes to its own endpoint) -
// release the session lock so this page never queues behind, or in front
// of, another admin request.
session_write_close();

$mode = (($_GET['mode'] ?? 'closeout') === 'batch') ? 'batch' : 'closeout';
$closeoutN = max(1, min(60, (int) ($_GET['n'] ?? 10)));

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// ---- Load ------------------------------------------------------
$inventoryPath = __DIR__ . '/inventory.csv';
$inv = merch_stock_load_inventory($inventoryPath);
$parsed = merch_stock_parse_grid($inv['rows'], FILAMENT_COLOR_ITEMS, FILAMENT_COLORS);
$stock = $parsed['stock'];

$loaded = merch_load_csv(__DIR__ . '/merchandise.csv', 'merchandise.csv');
$col = merch_csv_column_map(
    $loaded['header'],
    ['OrderID', 'Name', 'Zip', 'Item', 'Quantity', 'Color', 'Fulfillment', 'Pymt Date', 'Created', 'Fulfilled', 'Cancelled', 'Qty Created'],
    ['OrderID', 'Name', 'Item', 'Fulfillment', 'Pymt Date', 'Created'],
    'merchandise.csv'
);
$built = merch_stock_build_shipments($loaded['rows'], $col, FILAMENT_COLOR_ITEMS, PRINT_PLATE_EXCLUDED_COLORS);
$shipments = $built['shipments'];

$alloc = merch_stock_allocate($shipments, $stock);
$ready = $alloc['ready'];
$partial = $alloc['partial'];
$blocked = $alloc['blocked'];
$stockLeft = $alloc['stockLeft'];
$smallestFirstReady = merch_stock_ready_count_smallest_first($shipments, $stock);

$totalPiecesWaiting = 0;
foreach ($shipments as $s) {
    foreach ($s['lines'] as $l) {
        $totalPiecesWaiting += $l['qty'];
    }
}
$readyPieces = 0;
foreach ($ready as $s) {
    $readyPieces += $s['coveredUnits'];
}

// ---- Plan the printing ----------------------------------------
$rank = merch_stock_closeout_rank($partial);
if ($mode === 'closeout') {
    $chosen = array_slice($rank, 0, $closeoutN);
    $planRows = merch_stock_print_rows($chosen);
} else {
    $chosen = [];
    $planRows = merch_stock_print_rows(array_merge($partial, $blocked));
}
$planGroups = print_plate_group_queue($planRows);
$planStats = merch_stock_plan_stats($planGroups);
if ($mode === 'batch') {
    // Biggest color backlog first (the plan comes back in the plate
    // view's popularity order; for a batch run, sheer volume matters more).
    usort($planGroups, fn($a, $b) => ($planStats['byColor'][$b['color']]['units'] ?? 0) <=> ($planStats['byColor'][$a['color']]['units'] ?? 0));
}
$specialCount = count(array_filter(array_merge($partial, $blocked), fn($s) => $s['special']));

$shortIds = function (array $ids) use ($h): string {
    $ids = array_values(array_unique($ids));
    if (count($ids) <= 4) {
        return '#' . $h(implode(', #', $ids));
    }
    return '#' . $h(implode(', #', array_slice($ids, 0, 3))) . ' +' . (count($ids) - 3) . ' more';
};
$missingText = function (array $s) use ($h): string {
    $bits = [];
    foreach ($s['lines'] as $l) {
        if (($l['missing'] ?? 0) > 0) {
            $bits[] = $l['missing'] . '&times; ' . $h($l['item']) . ' <span class="color">' . $h($l['color']) . '</span>';
        }
    }
    return implode('; ', $bits);
};
$pullText = function (array $s) use ($h): string {
    $bits = [];
    foreach ($s['lines'] as $l) {
        $bits[] = $l['qty'] . '&times; ' . $h($l['item']) . ' <span class="color">' . $h($l['color']) . '</span>';
    }
    return implode('; ', $bits);
};
$mtimeText = $inv['mtime'] ? date('M j, g:i a', $inv['mtime']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Stock &amp; Print Plan &ndash; Southern Fireflies Retreats</title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; color: #222; margin: 24px; max-width: 980px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  h2 { font-size: 17px; margin: 28px 0 6px; padding-top: 10px; border-top: 1px solid #ccc; }
  h3 { font-size: 15px; margin: 18px 0 4px; }
  .note { color: #666; font-size: 13px; margin: 2px 0 10px; }
  .summary { display: flex; flex-wrap: wrap; gap: 10px; margin: 14px 0; }
  .stat { border: 1px solid #ddd; border-radius: 4px; padding: 8px 14px; min-width: 150px; }
  .stat b { display: block; font-size: 22px; }
  .stat span { font-size: 12px; color: #666; }
  table { border-collapse: collapse; width: 100%; font-size: 14px; }
  th, td { border-bottom: 1px solid #e3e3e3; padding: 6px 8px; text-align: left; vertical-align: top; }
  th { background: #f6f6f6; font-weight: bold; }
  .color { color: #444; background: #f1f1f1; border-radius: 3px; padding: 0 5px; white-space: nowrap; }
  .badge { font-size: 11px; background: #eef; color: #448; border-radius: 3px; padding: 1px 6px; margin-left: 4px; }
  .badge.warn { background: #fff6e0; color: #6b5416; }
  .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 8px 0 12px; }
  .toolbar a.tab { padding: 5px 12px; border: 1px solid #aaa; border-radius: 3px; text-decoration: none; color: #222; font-size: 14px; }
  .toolbar a.tab.on { background: #222; color: #fff; border-color: #222; }
  .toolbar input[type=number] { width: 60px; padding: 4px; }
  .color-block { margin: 14px 0; }
  .plate-list { margin: 4px 0 0 18px; padding: 0; font-size: 14px; }
  .plate-list li { margin: 3px 0; }
  .plate-label { font-weight: bold; }
  .plate-partial { color: #8a6d00; font-size: 12px; margin-left: 4px; }
  .warnbox { background: #fff6e0; border: 1px solid #e8cf8a; color: #6b5416; padding: 8px 12px; border-radius: 3px; margin: 8px 0; font-size: 13px; }
  .hint { background: #eef6ee; border: 1px solid #b9d6b9; color: #2a5a2a; padding: 8px 12px; border-radius: 3px; margin: 8px 0; font-size: 13px; }
  .grid td, .grid th { text-align: center; padding: 4px 6px; }
  .grid td:first-child, .grid th:first-child { text-align: left; }
  .grid td.stranded { background: #fff6e0; font-weight: bold; }
  .empty { color: #777; font-style: italic; }
  .upload { margin-top: 10px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .upload-msg { font-size: 13px; }
  .upload-msg.ok { color: #2a7a2a; }
  .upload-msg.err { color: #b00020; }
</style>
</head>
<body>
<h1>Stock &amp; Print Plan</h1>
<div class="note">
  <a href="ourmerch.php">&larr; Merchandise Requests</a> &middot;
  Build 2026-10-02-A &middot; read-only &middot; paid orders only, whole shipments only, exact color only
</div>

<div class="summary">
  <div class="stat"><b><?= (int) count($ready) ?></b><span>orders you could ship right now from stock</span></div>
  <div class="stat"><b><?= (int) count($shipments) ?></b><span>paid orders still waiting on prints (<?= (int) $totalPiecesWaiting ?> pieces)</span></div>
  <div class="stat"><b><?= (int) merch_stock_total($stock) ?></b><span>printed pieces on hand<?= $inv['exists'] ? '' : ' (no inventory file yet)' ?></span></div>
  <div class="stat"><b><?= (int) $built['unpaidPieces'] ?></b><span>unpaid pieces ignored (<?= (int) $built['unpaidShipments'] ?> orders)</span></div>
</div>

<?php if (!$inv['exists']): ?>
  <div class="warnbox">No inventory file yet. In Excel use File &rarr; Save As &rarr; CSV on your printed-inventory sheet, then upload it at the bottom of this page. Until then the plan below assumes you have nothing on the shelf.</div>
<?php endif; ?>
<?php foreach ($parsed['unmatched'] as $u): ?>
  <div class="warnbox">Not counted: <?= (int) $u['qty'] ?> &times; <?= $h($u['part']) ?> in &ldquo;<?= $h($u['color']) ?>&rdquo; &mdash; <?= $h($u['reason']) ?> Fix the name in the sheet (or add the color to the site's list) and re-upload.</div>
<?php endforeach; ?>
<?php foreach ($parsed['warnings'] as $w): ?>
  <div class="warnbox"><?= $h($w) ?></div>
<?php endforeach; ?>

<h2>1. Ready to ship from stock</h2>
<p class="note">Orders whose <em>every</em> piece is already on your shelf, oldest first. Nothing here is reserved or removed &mdash; pull them, check off Created in Merchandise Requests, and update the inventory sheet.</p>
<?php if ($smallestFirstReady > count($ready)): ?>
  <div class="hint">Handing the same stock to the <em>smallest</em> orders first would ship <?= (int) $smallestFirstReady ?> orders instead of <?= (int) count($ready) ?>. This list stays oldest-first unless you decide otherwise.</div>
<?php endif; ?>
<?php if (empty($ready)): ?>
  <p class="empty">No paid order can be completed entirely from what's on the shelf right now.</p>
<?php else: ?>
  <table>
    <tr><th>Customer</th><th>Order(s)</th><th>Pull from stock</th></tr>
    <?php foreach ($ready as $s): ?>
      <tr>
        <td><?= $h($s['name'] !== '' ? $s['name'] : '(no name)') ?><?= $s['type'] === 'pickup' ? '<span class="badge">Pickup</span>' : '' ?></td>
        <td><?= $shortIds($s['orderIds']) ?></td>
        <td><?= $pullText($s) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
<?php if (!empty($blocked)): ?>
  <p class="note" style="margin-top:12px;"><span class="badge warn">Waiting on a shirt/hat</span>
    <?= (int) count($blocked) ?> paid order(s) also include a shirt or hat that isn't Created yet, so they can't ship regardless of stock:
    <?= implode(', ', array_map(fn($s) => $h($s['name']) . ' ' . $shortIds($s['orderIds']), $blocked)) ?>.
  </p>
<?php endif; ?>

<h2>2. What to print next</h2>
<div class="toolbar">
  <a class="tab <?= $mode === 'closeout' ? 'on' : '' ?>" href="?mode=closeout&amp;n=<?= (int) $closeoutN ?>">Close-out: finish the closest orders</a>
  <a class="tab <?= $mode === 'batch' ? 'on' : '' ?>" href="?mode=batch">Batch: everything, by color</a>
  <?php if ($mode === 'closeout'): ?>
    <form method="get" style="margin:0;">
      <input type="hidden" name="mode" value="closeout" />
      Target <input type="number" name="n" min="1" max="60" value="<?= (int) $closeoutN ?>" /> orders
      <button type="submit">Update</button>
    </form>
  <?php endif; ?>
</div>

<?php if ($mode === 'closeout'): ?>
  <p class="note">The <?= (int) count($chosen) ?> paid orders closest to done (fewest pieces still missing; ties go to pieces that share a plate with other near-done orders, then fewest colors, then oldest). Printing exactly the plates below ships all <?= (int) count($chosen) ?>:
    <strong><?= (int) $planStats['units'] ?> pieces on <?= (int) $planStats['plates'] ?> plates across <?= (int) $planStats['colors'] ?> color(s)</strong>.</p>
  <?php if (!empty($chosen)): ?>
    <table>
      <tr><th>#</th><th>Customer</th><th>Order(s)</th><th>Still missing</th></tr>
      <?php foreach ($chosen as $i => $s): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= $h($s['name'] !== '' ? $s['name'] : '(no name)') ?><?= $s['coveredUnits'] > 0 ? '<span class="badge">' . (int) $s['coveredUnits'] . ' already on shelf</span>' : '' ?></td>
          <td><?= $shortIds($s['orderIds']) ?></td>
          <td><?= $missingText($s) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
<?php else: ?>
  <p class="note">Every paid piece still missing after stock, biggest color first: <strong><?= (int) $planStats['units'] ?> pieces on <?= (int) $planStats['plates'] ?> plates across <?= (int) $planStats['colors'] ?> colors</strong> (Stars &amp; Stripes and shirts/hats aren't in the plate plan<?= $specialCount > 0 ? '; ' . (int) $specialCount . ' order(s) need Stars &amp; Stripes by hand' : '' ?>).</p>
<?php endif; ?>

<?php if (empty($planGroups)): ?>
  <p class="empty">Nothing to print for this view.</p>
<?php else: ?>
  <h3>Plate plan</h3>
  <?php foreach ($planGroups as $g): ?>
    <?php $cs = $planStats['byColor'][$g['color']] ?? ['units' => 0, 'plates' => 0]; ?>
    <div class="color-block">
      <strong><?= $h($g['color']) ?></strong> &mdash; <?= (int) $cs['units'] ?> piece(s), <?= (int) $cs['plates'] ?> plate(s)
      <ul class="plate-list">
        <?php foreach ($g['plateGroups'] as $pg): ?>
          <?php foreach ($pg['plates'] as $plate): ?>
            <li>
              <span class="plate-label"><?= $h($pg['group']) ?></span>
              <?php if ($plate['fillFraction'] < 0.999): ?><span class="plate-partial">(partial plate)</span><?php endif; ?>
              &mdash;
              <?php
              $bits = [];
              foreach ($plate['items'] as $item => $data) {
                  $who = [];
                  foreach ($data['orders'] as $o) {
                      $who[] = $h($o['customerName']) . ($o['qty'] > 1 ? ' (' . (int) $o['qty'] . ')' : '');
                  }
                  $bits[] = (int) $data['qty'] . '&times; ' . $h($item) . ' &rarr; ' . implode(', ', $who);
              }
              echo implode(' &nbsp;+&nbsp; ', $bits);
              ?>
            </li>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<h2>3. Stock on hand</h2>
<?php
$gridColors = [];
foreach ($stock as $byColor) {
    foreach ($byColor as $color => $q) {
        $gridColors[$color] = true;
    }
}
$gridColors = array_keys($gridColors);
usort($gridColors, function ($a, $b) {
    $ia = array_search($a, FILAMENT_COLORS, true);
    $ib = array_search($b, FILAMENT_COLORS, true);
    return $ia <=> $ib;
});
?>
<?php if (empty($stock)): ?>
  <p class="empty">No stock recorded.</p>
<?php else: ?>
  <p class="note">Highlighted pieces match <em>no</em> paid order that still needs printing (e.g. the Tan pieces) &mdash; candidates for an event table, not for filling orders.
    <?php if ($mtimeText !== ''): ?>Inventory file last updated <?= $h($mtimeText) ?>.<?php endif; ?></p>
  <table class="grid">
    <tr><th>Part</th><?php foreach ($gridColors as $c): ?><th><?= $h($c) ?></th><?php endforeach; ?><th>Total</th></tr>
    <?php foreach (FILAMENT_COLOR_ITEMS as $item): ?>
      <?php if (empty($stock[$item])): continue; endif; ?>
      <tr>
        <td><?= $h($item) ?></td>
        <?php foreach ($gridColors as $c): ?>
          <?php $q = $stock[$item][$c] ?? 0; ?>
          <td class="<?= ($stockLeft[$item][$c] ?? 0) > 0 ? 'stranded' : '' ?>"><?= $q > 0 ? (int) $q : '' ?></td>
        <?php endforeach; ?>
        <td><?= (int) array_sum($stock[$item]) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<div class="upload">
  <form id="stock-upload-form" style="margin:0;">
    <label>Replace inventory (CSV saved from your sheet): <input type="file" name="inventory" accept=".csv,text/csv" required /></label>
    <button type="submit">Upload</button>
  </form>
  <span id="stock-upload-msg" class="upload-msg"></span>
</div>
<p class="note">Layout: parts down the first column with site colors across the top (e.g. <code>#15 CM Blue</code>) <em>or</em> colors down the first column with parts across the top &mdash; either way works, and it's detected automatically. Counts go in the cells. Excel's Total row/column and blank cells are ignored. Colors must match the site's list &mdash; anything that doesn't is reported above instead of guessed.</p>

<script>
(function () {
  var form = document.getElementById('stock-upload-form');
  var msg = document.getElementById('stock-upload-msg');
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(form);
    fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
    msg.className = 'upload-msg';
    msg.textContent = 'Uploading...';
    fetch('merch_stock_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected response (HTTP ' + r.status + ').' }; }); })
      .then(function (d) {
        if (d.ok) {
          msg.className = 'upload-msg ok';
          msg.textContent = 'Saved - ' + d.pieces + ' pieces recognized. Reloading...';
          setTimeout(function () { location.reload(); }, 600);
        } else {
          msg.className = 'upload-msg err';
          msg.textContent = d.error || 'Upload failed.';
        }
      })
      .catch(function () {
        msg.className = 'upload-msg err';
        msg.textContent = 'Could not reach the server.';
      });
  });
})();
</script>
</body>
</html>
