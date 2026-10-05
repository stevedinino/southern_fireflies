<?php
// Build: 2026-10-04-C
// Admin-only report: "what can I ship from what I've already printed, and
// what should I print next?" (Steve, 2026-10-02 - see merch_stock.php's
// header comment for the rules it follows: whole shipments only, paid
// first, exact color only).
//
// Reads merchandise.csv and inventory.csv (his printed-parts sheet, saved
// as CSV and uploaded here - see merch_stock_upload.php). Viewing the page
// changes nothing. It writes only through two endpoints: the upload form,
// and (2026-10-04) the pick-list's Done button on the Ready list, which
// goes to merch_stock_ship.php to mark that order's pulled pieces Created
// and take them off the inventory sheet - only after every part has been
// ticked, and only when clicked.
//
// 2026-10-04 (Steve): shirt/hat orders are Janet's, so the old "waiting on a
// shirt/hat" list is gone from this page. Such orders still can't be "ready"
// (never ship a partial order) and still hold shelf stock; only the list is hidden.
//
// 2026-10-04 (Steve): "how old" means how long since the order was PAID
// (Pymt Date, now the real payment day), not since it was placed; an
// unpaid Pickup order falls back to its order date. See merch_stock.php.
//
// 2026-10-04 (Steve): oldest orders first. With a big backlog he doesn't
// want old orders sitting while newer, cheaper ones jump the queue, so the
// default priority is "age" (oldest order first, everywhere); the original
// "quickest wins" ordering is one click away, with the cost difference
// shown so the trade is visible.
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
// 'age' = longest-waiting (paid longest ago) first, the default; 'quick' = fewest pieces missing first.
$priority = (($_GET['prio'] ?? 'age') === 'quick') ? 'quick' : 'age';
$altPriority = $priority === 'age' ? 'quick' : 'age';
$qs = fn(array $over) => htmlspecialchars(http_build_query(array_merge(['mode' => $mode, 'n' => $closeoutN, 'prio' => $priority], $over)), ENT_QUOTES, 'UTF-8');
$build = '2026-10-04-C';

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// ---- Load ------------------------------------------------------
$inventoryPath = __DIR__ . '/inventory.csv';
$inv = merch_stock_load_inventory($inventoryPath);
$parsed = merch_stock_parse_grid($inv['rows'], FILAMENT_COLOR_ITEMS, FILAMENT_COLORS);
$stock = $parsed['stock'];

$loaded = merch_load_csv(__DIR__ . '/merchandise.csv', 'merchandise.csv');
$col = merch_csv_column_map(
    $loaded['header'],
    ['OrderID', 'Name', 'Zip', 'Item', 'Quantity', 'Color', 'Size', 'Sleeve', 'Fulfillment', 'Pymt Date', 'Created', 'Fulfilled', 'Cancelled', 'Qty Created', 'Timestamp'],
    ['OrderID', 'Name', 'Item', 'Fulfillment', 'Pymt Date', 'Created'],
    'merchandise.csv'
);
$built = merch_stock_build_shipments($loaded['rows'], $col, FILAMENT_COLOR_ITEMS, PRINT_PLATE_EXCLUDED_COLORS);
$shipments = $built['shipments'];

$alloc = merch_stock_allocate($shipments, $stock, $priority);
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
$rank = merch_stock_closeout_rank($partial, $priority);
$altStats = null;
if ($mode === 'closeout') {
    $chosen = array_slice($rank, 0, $closeoutN);
    $planRows = merch_stock_print_rows($chosen);
    // What the OTHER ordering would cost for the same number of orders, so
    // the price of oldest-first (or the saving, if he's on quickest-wins)
    // is visible instead of a guess.
    $altAlloc = merch_stock_allocate($shipments, $stock, $altPriority);
    $altChosen = array_slice(merch_stock_closeout_rank($altAlloc['partial'], $altPriority), 0, $closeoutN);
    if (count($altChosen) > 0) {
        $altStats = merch_stock_plan_stats(print_plate_group_queue(merch_stock_print_rows($altChosen)));
    }
} else {
    $chosen = [];
    $planRows = merch_stock_print_rows(array_merge($partial, $blocked));
}
$planGroups = print_plate_group_queue($planRows);
$planStats = merch_stock_plan_stats($planGroups);
// When each order was placed, by OrderID (for the age columns), and the
// oldest order waiting on each color (for the batch ordering).
$orderAge = []; // orderId => [age timestamp, 'paid'|'ordered']
foreach ($shipments as $s) {
    foreach (['lines', 'other', 'madeLines'] as $k) {
        foreach ($s[$k] as $l) {
            $orderAge[$l['orderId']] = [$l['ts'] ?? null, $l['basis'] ?? 'ordered'];
        }
    }
}
$oldestByColor = []; // color => [age timestamp, orderId] of the longest-waiting order needing it
foreach ($planRows as $r) {
    if (ctype_digit((string) $r['orderId'])) {
        $c = $r['color'];
        $cand = [$orderAge[(string) $r['orderId']][0] ?? PHP_INT_MAX, (int) $r['orderId']];
        if (!isset($oldestByColor[$c]) || $cand < $oldestByColor[$c]) {
            $oldestByColor[$c] = $cand;
        }
    }
}
if ($mode === 'batch') {
    if ($priority === 'age') {
        // Run the color holding the longest-waiting order first; volume
        // breaks ties.
        usort($planGroups, fn($a, $b) => [$oldestByColor[$a['color']] ?? [PHP_INT_MAX, PHP_INT_MAX], -($planStats['byColor'][$a['color']]['units'] ?? 0)]
            <=> [$oldestByColor[$b['color']] ?? [PHP_INT_MAX, PHP_INT_MAX], -($planStats['byColor'][$b['color']]['units'] ?? 0)]);
    } else {
        // Biggest color backlog first (the plan comes back in the plate
        // view's popularity order; for a batch run, sheer volume matters more).
        usort($planGroups, fn($a, $b) => ($planStats['byColor'][$b['color']]['units'] ?? 0) <=> ($planStats['byColor'][$a['color']]['units'] ?? 0));
    }
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
$ageOf = fn(array $s) => merch_stock_age_label($s['ageTs'] ?? null, $s['ageBasis'] ?? 'ordered');
$oldestTextForColor = function (string $color) use ($oldestByColor, $orderAge, $h): string {
    if (!isset($oldestByColor[$color])) {
        return '';
    }
    $id = $oldestByColor[$color][1];
    $age = merch_stock_age_label($orderAge[(string) $id][0] ?? null, $orderAge[(string) $id][1] ?? 'ordered');
    return 'longest waiting: #' . (int) $id . ($age !== '' ? ' &middot; ' . $h($age) : '');
};
$lineLabel = function (array $l) use ($h): string {
    $txt = (int) $l['qty'] . '&times; ' . $h($l['item']) . ' <span class="color">' . $h($l['color']) . '</span>';
    if (($l['made'] ?? 0) > 0) {
        $txt .= ' <span class="badge">+' . (int) $l['made'] . ' already made</span>';
    }
    return $txt;
};
$madeLabel = function (array $l) use ($h): string {
    $bits = array_filter([$l['color'] ?? '', $l['size'] ?? '', $l['sleeve'] ?? ''], fn($v) => $v !== '' && stripos($v, 'not applicable') === false);
    return (int) $l['qty'] . '&times; ' . $h($l['item']) . ($bits ? ' <span class="color">' . $h(implode(', ', $bits)) . '</span>' : '')
        . ' <span class="badge">already made &mdash; in your set-aside pile</span>';
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
  .pick td { vertical-align: top; }
  .checklist { list-style: none; margin: 0 0 8px; padding: 0; }
  .checklist li { margin: 4px 0; }
  .checklist label { cursor: pointer; display: inline-flex; gap: 8px; align-items: flex-start; }
  .checklist input { width: 18px; height: 18px; margin-top: 1px; flex: none; }
  .done-btn { padding: 6px 18px; font-size: 14px; font-weight: bold; border: 1px solid #2a7a2a; background: #2a7a2a; color: #fff; border-radius: 3px; cursor: pointer; }
  .done-btn:disabled { background: #e6e6e6; border-color: #ccc; color: #999; cursor: not-allowed; }
  tr.pick.all-checked { background: #f1f8f1; }
  .done-msg { font-size: 13px; margin-left: 8px; }
  .done-msg.ok { color: #2a7a2a; }
  .done-msg.err { color: #b00020; }
  .age { white-space: nowrap; color: #555; font-size: 13px; }
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
  Build <?= $h($build) ?> &middot; paid orders only, whole shipments only, exact color only, longest-paid first
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
<p class="note">Orders whose <em>every</em> piece is already on your shelf, longest-paid first. Tick each part as you gather it &mdash; <strong>Done</strong> unlocks once every part is ticked, and nothing is changed until you click it. Done marks the parts pulled from stock as <em>Created</em> in Merchandise Requests (it doesn't mark anything shipped) and takes them off the inventory sheet, together or not at all. Parts tagged &ldquo;already made&rdquo; were Created earlier; they're listed so the whole order goes in one box.</p>
<?php if ($smallestFirstReady > count($ready)): ?>
  <div class="hint">Handing the same stock to the <em>smallest</em> orders first would ship <?= (int) $smallestFirstReady ?> orders instead of <?= (int) count($ready) ?>. This list stays longest-paid-first unless you decide otherwise.</div>
<?php endif; ?>
<?php if (empty($ready)): ?>
  <p class="empty">No paid order can be completed entirely from what's on the shelf right now.</p>
<?php else: ?>
  <table>
    <tr><th>Customer</th><th>Order(s)</th><th>Waiting since</th><th>Gather these, then click Done</th></tr>
    <?php foreach ($ready as $s): ?>
      <?php $pickIds = merch_stock_shipment_commit_ids($s); $pickSig = merch_stock_shipment_signature($s); ?>
      <tr class="pick" data-ids="<?= $h(implode(',', $pickIds)) ?>" data-sig="<?= $h($pickSig) ?>">
        <td><?= $h($s['name'] !== '' ? $s['name'] : '(no name)') ?><?= $s['type'] === 'pickup' ? '<span class="badge">Pickup</span>' : '' ?><?= !empty($s['unpaidSiblingLines']) ? '<span class="badge warn">also has unpaid items (not included)</span>' : '' ?></td>
        <td><?= $shortIds($s['orderIds']) ?></td>
        <td class="age"><?= $h($ageOf($s)) ?></td>
        <td>
          <ul class="checklist">
            <?php foreach ($s['lines'] as $l): ?>
              <li><label><input type="checkbox" class="pk" data-k="<?= $h($pickSig . '/' . $l['orderId'] . ':' . $l['item'] . ':' . $l['color']) ?>" /> <span><?= $lineLabel($l) ?></span></label></li>
            <?php endforeach; ?>
            <?php foreach ($s['madeLines'] as $l): ?>
              <li><label><input type="checkbox" class="pk" data-k="<?= $h($pickSig . '/made:' . $l['orderId']) ?>" /> <span><?= $madeLabel($l) ?></span></label></li>
            <?php endforeach; ?>
          </ul>
          <button type="button" class="done-btn" disabled>Done</button><span class="done-msg"></span>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<h2>2. What to print next</h2>
<div class="toolbar">
  <a class="tab <?= $mode === 'closeout' ? 'on' : '' ?>" href="?<?= $qs(['mode' => 'closeout']) ?>">Close-out: finish the next orders</a>
  <a class="tab <?= $mode === 'batch' ? 'on' : '' ?>" href="?<?= $qs(['mode' => 'batch']) ?>">Batch: everything, by color</a>
  <span style="margin-left:8px;">Priority:</span>
  <a class="tab <?= $priority === 'age' ? 'on' : '' ?>" href="?<?= $qs(['prio' => 'age']) ?>">Oldest paid first</a>
  <a class="tab <?= $priority === 'quick' ? 'on' : '' ?>" href="?<?= $qs(['prio' => 'quick']) ?>">Quickest wins</a>
  <?php if ($mode === 'closeout'): ?>
    <form method="get" style="margin:0;">
      <input type="hidden" name="mode" value="closeout" />
      <input type="hidden" name="prio" value="<?= $h($priority) ?>" />
      Target <input type="number" name="n" min="1" max="60" value="<?= (int) $closeoutN ?>" /> orders
      <button type="submit">Update</button>
    </form>
  <?php endif; ?>
</div>

<?php if ($mode === 'closeout'): ?>
  <p class="note">
    <?php if ($priority === 'age'): ?>
      The <?= (int) count($chosen) ?> <strong>longest-paid</strong> orders that could ship once printed (nothing waiting on a shirt/hat or Stars &amp; Stripes). Printing exactly the plates below ships all <?= (int) count($chosen) ?>:
    <?php else: ?>
      The <?= (int) count($chosen) ?> paid orders closest to done (fewest pieces still missing; ties go to pieces that share a plate with other near-done orders, then fewest colors, then oldest). Printing exactly the plates below ships all <?= (int) count($chosen) ?>:
    <?php endif; ?>
    <strong><?= (int) $planStats['units'] ?> pieces on <?= (int) $planStats['plates'] ?> plates across <?= (int) $planStats['colors'] ?> color(s)</strong>.</p>
  <?php if ($altStats !== null && ($altStats['units'] !== $planStats['units'] || $altStats['plates'] !== $planStats['plates'])): ?>
    <div class="hint">
      <?php if ($priority === 'age'): ?>
        For comparison, finishing the <?= (int) count($chosen) ?> <em>quickest</em> orders instead would take <?= (int) $altStats['units'] ?> pieces on <?= (int) $altStats['plates'] ?> plates across <?= (int) $altStats['colors'] ?> color(s) &mdash; but would leave older orders waiting. <a href="?<?= $qs(['prio' => 'quick']) ?>">Switch to quickest wins</a>
      <?php else: ?>
        For comparison, finishing the <?= (int) count($chosen) ?> <em>longest-paid</em> orders instead would take <?= (int) $altStats['units'] ?> pieces on <?= (int) $altStats['plates'] ?> plates across <?= (int) $altStats['colors'] ?> color(s). <a href="?<?= $qs(['prio' => 'age']) ?>">Switch to oldest paid first</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($chosen)): ?>
    <table>
      <tr><th>#</th><th>Customer</th><th>Order(s)</th><th>Waiting since</th><th>Still missing</th></tr>
      <?php foreach ($chosen as $i => $s): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= $h($s['name'] !== '' ? $s['name'] : '(no name)') ?><?= $s['coveredUnits'] > 0 ? '<span class="badge">' . (int) $s['coveredUnits'] . ' already on shelf</span>' : '' ?></td>
          <td><?= $shortIds($s['orderIds']) ?></td>
          <td class="age"><?= $h($ageOf($s)) ?></td>
          <td><?= $missingText($s) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
<?php else: ?>
  <p class="note">Every paid piece still missing after stock<?= $priority === 'age' ? ', colors ordered by the longest-paid order they hold' : ', biggest color first' ?>: <strong><?= (int) $planStats['units'] ?> pieces on <?= (int) $planStats['plates'] ?> plates across <?= (int) $planStats['colors'] ?> colors</strong> (Stars &amp; Stripes and shirts/hats aren't in the plate plan<?= $specialCount > 0 ? '; ' . (int) $specialCount . ' order(s) need Stars &amp; Stripes by hand' : '' ?>).</p>
<?php endif; ?>

<?php if (empty($planGroups)): ?>
  <p class="empty">Nothing to print for this view.</p>
<?php else: ?>
  <h3>Plate plan</h3>
  <?php foreach ($planGroups as $g): ?>
    <?php $cs = $planStats['byColor'][$g['color']] ?? ['units' => 0, 'plates' => 0]; ?>
    <div class="color-block">
      <strong><?= $h($g['color']) ?></strong> &mdash; <?= (int) $cs['units'] ?> piece(s), <?= (int) $cs['plates'] ?> plate(s)<?php $ow = $oldestTextForColor($g['color']); ?><?= $ow !== '' ? ' <span class="age">&middot; ' . $ow . '</span>' : '' ?>
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
// Pick-list: tick every part, then Done. Ticks are remembered in this
// browser (so a refresh or a walk to the shelf doesn't lose them) but
// NOTHING is saved to the site until Done is clicked.
(function () {
  var STORE = 'sffStockPickChecks_v1';
  var saved = {};
  try { saved = JSON.parse(localStorage.getItem(STORE) || '{}') || {}; } catch (e) { saved = {}; }
  function persist() { try { localStorage.setItem(STORE, JSON.stringify(saved)); } catch (e) {} }
  var csrf = <?= json_encode($csrfToken) ?>;
  var valid = {};
  Array.prototype.forEach.call(document.querySelectorAll('tr.pick'), function (tr) {
    var boxes = tr.querySelectorAll('input.pk');
    var btn = tr.querySelector('.done-btn');
    var msg = tr.querySelector('.done-msg');
    function refresh() {
      var all = boxes.length > 0;
      Array.prototype.forEach.call(boxes, function (b) { if (!b.checked) { all = false; } });
      btn.disabled = !all;
      tr.classList.toggle('all-checked', all);
    }
    Array.prototype.forEach.call(boxes, function (b) {
      var k = b.getAttribute('data-k');
      valid[k] = true;
      if (saved[k]) { b.checked = true; }
      b.addEventListener('change', function () {
        if (b.checked) { saved[k] = 1; } else { delete saved[k]; }
        persist();
        refresh();
      });
    });
    refresh();
    btn.addEventListener('click', function () {
      if (btn.disabled) { return; }
      btn.disabled = true;
      Array.prototype.forEach.call(boxes, function (b) { b.disabled = true; });
      msg.className = 'done-msg';
      msg.textContent = 'Saving...';
      var body = new URLSearchParams();
      body.append('orderIds', tr.getAttribute('data-ids'));
      body.append('sig', tr.getAttribute('data-sig'));
      body.append('csrf_token', csrf);
      fetch('merch_stock_ship.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected response (HTTP ' + r.status + ').' }; }); })
        .then(function (d) {
          if (d.ok) {
            Array.prototype.forEach.call(boxes, function (b) { delete saved[b.getAttribute('data-k')]; });
            persist();
            msg.className = 'done-msg ok';
            msg.textContent = 'Done - marked Created and taken off the shelf. Reloading...';
            setTimeout(function () { location.reload(); }, 800);
          } else {
            msg.className = 'done-msg err';
            msg.textContent = (d.error || 'Could not save.') + ' Nothing was changed.';
            Array.prototype.forEach.call(boxes, function (b) { b.disabled = false; });
            refresh();
          }
        })
        .catch(function () {
          msg.className = 'done-msg err';
          msg.textContent = 'Could not reach the server. Nothing was changed - check Merchandise Requests before trying again.';
          Array.prototype.forEach.call(boxes, function (b) { b.disabled = false; });
          refresh();
        });
    });
  });
  // Forget ticks for orders that are no longer on the list.
  Object.keys(saved).forEach(function (k) { if (!valid[k]) { delete saved[k]; } });
  persist();
})();

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
