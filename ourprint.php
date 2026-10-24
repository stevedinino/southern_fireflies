<?php
// Build: 2026-10-24-A
// Admin-only "Print Plan": the merged replacement for the Create view of
// Merchandise Requests (ourmerch.php) and the Stock & Print Plan page
// (merch_stock_report.php). Steve's decisions, 2026-10-23/24:
//   - Keep: the whole "Ready to ship from stock" section; the plate lines in
//     the Stock & Print Plan's arrow format ("2 Circle -> Alice, Bob"), with
//     Merchandise Requests' tinted lines (full / partial / printing); the
//     Currently printing banner and Start printing / Finished buttons; Colors
//     not available; "Waiting on an unavailable color" (down below); the
//     "+ Shirts/Hats/P/U" toggle; Close-out vs Batch (default: everything by
//     color, oldest paid first) - but NOT at the top of the page.
//   - Gone: the "Target N orders" control and the quickest-vs-oldest
//     comparison line. Summary tiles, Stock on hand and the "Not counted"
//     warning moved to the bottom (Stock on hand and "Not counted" are meant to
//     end up on the Edit Stock page).
//   - Toggles and buttons keep the page where it was (scroll position).
//   - The Invoice / Payments / Ship / Notes bar sits at the top; those views
//     still live in ourmerch.php (?view=...).
//
// This page reads merchandise.csv and inventory.csv and changes nothing by
// itself. Writes go only through the existing endpoints: merch_stock_ship.php
// (Done on Ready to ship), merch_stock_adjust.php (Finished...),
// merch_printing_set.php (Start/Not printing) and merch_filament_unavailable.php.
// The planning rules are exactly the Stock & Print Plan's (merch_stock.php):
// whole shipments only, paid first, exact color only, longest-paid first.

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_shipments.php';
require __DIR__ . '/print_plates.php';
require __DIR__ . '/filament_unavailable.php';
require __DIR__ . '/merch_stock.php';
require __DIR__ . '/merch_printing.php';

merch_require_admin_redirect('ourprint.php');

$csrfToken = merch_csrf_token();
session_write_close(); // read-only page: never queue behind another admin request

// 'batch' (everything still missing, by color) is the default; 'closeout' is the next-N-orders view.
$mode = (($_GET['mode'] ?? 'batch') === 'closeout') ? 'closeout' : 'batch';
$closeoutN = max(1, min(60, (int) ($_GET['n'] ?? 10)));
$priority = (($_GET['prio'] ?? 'age') === 'quick') ? 'quick' : 'age';
$qs = fn(array $over) => htmlspecialchars(http_build_query(array_merge(['mode' => $mode, 'prio' => $priority], $over)), ENT_QUOTES, 'UTF-8');
$build = '2026-10-24-A';

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// ---- Load ------------------------------------------------------
$inv = merch_stock_load_inventory(__DIR__ . '/inventory.csv');
$parsed = merch_stock_parse_grid($inv['rows'], FILAMENT_COLOR_ITEMS, FILAMENT_COLORS);
$stock = $parsed['stock'];

$loaded = merch_load_csv(__DIR__ . '/merchandise.csv', 'merchandise.csv');
$col = merch_csv_column_map(
    $loaded['header'],
    ['OrderID', 'Name', 'Zip', 'Item', 'Quantity', 'Color', 'Size', 'Sleeve', 'Fulfillment', 'Pymt Date', 'Created', 'Fulfilled', 'Cancelled', 'Qty Created', 'Timestamp', 'Retreat'],
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
$unavailRows = merch_unavailable_active_rows(date('Y-m-d'));
$unavailColors = array_keys($unavailRows);
[$partialPrintable, $partialHeld] = merch_unavailable_split($partial, $unavailColors);
$smallestFirstReady = merch_stock_ready_count_smallest_first($shipments, $stock);
$printingActive = merch_printing_active();

$totalPiecesWaiting = 0;
foreach ($shipments as $s) {
    foreach ($s['lines'] as $l) {
        $totalPiecesWaiting += $l['qty'];
    }
}

// ---- Plan the printing ----------------------------------------
$rank = merch_stock_closeout_rank($partialPrintable, $priority);
$heldRank = merch_stock_closeout_rank($partialHeld, $priority);
$heldRows = [];
if ($mode === 'closeout') {
    $chosen = array_slice($rank, 0, $closeoutN);
    $planRows = merch_stock_print_rows($chosen);
} else {
    $chosen = [];
    $planRows = merch_stock_print_rows(array_merge($partial, $blocked));
    $heldRows = array_values(array_filter($planRows, fn($r) => in_array($r['color'], $unavailColors, true)));
    $planRows = array_values(array_filter($planRows, fn($r) => !in_array($r['color'], $unavailColors, true)));
}
$planGroups = print_plate_group_queue($planRows);
$planStats = merch_stock_plan_stats($planGroups);
$orderAge = [];
foreach ($shipments as $s) {
    foreach (['lines', 'other', 'madeLines'] as $k) {
        foreach ($s[$k] as $l) {
            $orderAge[$l['orderId']] = [$l['ts'] ?? null, $l['basis'] ?? 'ordered'];
        }
    }
}
$oldestByColor = [];
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
        usort($planGroups, fn($a, $b) => [$oldestByColor[$a['color']] ?? [PHP_INT_MAX, PHP_INT_MAX], -($planStats['byColor'][$a['color']]['units'] ?? 0)]
            <=> [$oldestByColor[$b['color']] ?? [PHP_INT_MAX, PHP_INT_MAX], -($planStats['byColor'][$b['color']]['units'] ?? 0)]);
    } else {
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
$linesText = function (array $s, string $key) use ($h): string {
    $bits = [];
    foreach ($s['lines'] as $l) {
        if (($l[$key] ?? 0) > 0) {
            $bits[] = merch_qty_chip((int) $l[$key]) . $h($l['item']) . ' <span class="color">' . $h($l['color']) . '</span>';
        }
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
    $txt = merch_qty_chip((int) $l['qty']) . $h($l['item']) . ' <span class="color">' . $h($l['color']) . '</span>';
    if (($l['made'] ?? 0) > 0) {
        $txt .= ' <span class="badge">+' . (int) $l['made'] . ' already made</span>';
    }
    return $txt;
};
$madeLabel = function (array $l) use ($h): string {
    $bits = array_filter([$l['color'] ?? '', $l['size'] ?? '', $l['sleeve'] ?? ''], fn($v) => $v !== '' && stripos($v, 'not applicable') === false);
    return merch_qty_chip((int) $l['qty']) . $h($l['item']) . ($bits ? ' <span class="color">' . $h(implode(', ', $bits)) . '</span>' : '')
        . ' <span class="badge">already made &mdash; in your set-aside pile</span>';
};
$mtimeText = $inv['mtime'] ? date('M j, g:i a', $inv['mtime']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Print Plan &ndash; Southern Fireflies Retreats</title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; color: #222; margin: 24px; max-width: 1100px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  h2 { font-size: 17px; margin: 0; }
  h3 { font-size: 15px; margin: 18px 0 4px; }
  details.sec { border-top: 1px solid #ccc; margin-top: 22px; padding-top: 10px; }
  details.sec > summary { cursor: pointer; font-size: 17px; font-weight: bold; list-style: revert; }
  .qty-chip { display: inline-block; padding: 0 7px; border-radius: 999px; background: #b34700; color: #fff; font-weight: bold; font-size: 0.9em; }
  .note { color: #666; font-size: 13px; margin: 2px 0 10px; }
  .stat { border: 1px solid #ddd; border-radius: 4px; padding: 8px 14px; min-width: 150px; }
  .summary { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
  .stat b { display: block; font-size: 22px; }
  .stat span { font-size: 12px; color: #666; }
  table { border-collapse: collapse; width: 100%; font-size: 14px; }
  th, td { border-bottom: 1px solid #e3e3e3; padding: 6px 8px; text-align: left; vertical-align: top; }
  th { background: #f6f6f6; font-weight: bold; }
  .color { color: #444; background: #f1f1f1; border-radius: 3px; padding: 0 5px; white-space: nowrap; }
  .badge { font-size: 11px; background: #eef; color: #448; border-radius: 3px; padding: 1px 6px; margin-left: 4px; }
  .badge.warn { background: #fff6e0; color: #6b5416; }
  .age { white-space: nowrap; color: #555; font-size: 13px; }
  .empty { color: #777; font-style: italic; }
  /* top bar: the same five views as Merchandise Requests */
  .viewbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 10px 0 14px; }
  .viewbar a.vb { padding: 4px 12px; border: 1px solid #aaa; border-radius: 3px; text-decoration: none; color: #222; font-size: 14px; background: #fff; }
  .viewbar a.vb.on { background: #333; color: #fff; border-color: #333; }
  .viewbar label { margin-left: 12px; font-size: 13px; white-space: nowrap; }
  /* Currently printing banner */
  .now { background: #fff4e0; border: 1px solid #e0a040; border-radius: 6px; padding: 8px 14px; margin: 0 0 14px; }
  .now ul { margin: 4px 0 0; padding-left: 20px; }
  /* plan options (not at the top of the page) */
  .toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 6px 0 10px; font-size: 13px; }
  .toolbar a.tab { padding: 3px 10px; border: 1px solid #aaa; border-radius: 3px; text-decoration: none; color: #222; }
  .toolbar a.tab.on { background: #222; color: #fff; border-color: #222; }
  /* plate plan */
  .color-block { margin: 16px 0; padding-bottom: 10px; border-bottom: 1px solid #eee; }
  .color-block > .chead { font-size: 15px; }
  .plate-list { list-style: none; margin: 6px 0 0; padding: 0; font-size: 14px; }
  .plate-list li { margin: 4px 0; padding: 5px 8px; background: #eef7ee; border-radius: 4px; }
  .plate-list li.partial { background: #f8f3e6; }
  .plate-list li.printing { background: #fff4e0; border-left: 4px solid #e0a040; padding-left: 8px; }
  .plate-label { font-weight: bold; }
  .plate-fill { color: #667; font-size: 12px; margin-left: 4px; }
  .pbadge { display: inline-block; background: #b36b00; color: #fff; font-weight: bold; font-size: 11px; padding: 1px 8px; border-radius: 3px; margin-right: 8px; }
  .pbtn { margin-right: 8px; padding: 1px 8px; font-size: 12px; border: 1px solid; background: #fff; border-radius: 3px; cursor: pointer; }
  .pbtn.start { border-color: #b36b00; color: #b36b00; }
  .pbtn.stop { border-color: #888; color: #444; }
  .pbtn.fin { border-color: #2a7a2a; color: #2a7a2a; }
  /* unavailable colors */
  .unavail { background: #fdecec; border: 1px solid #e5b4b4; color: #7a2323; padding: 8px 12px; border-radius: 3px; margin: 8px 0; font-size: 13px; }
  .unavail .u-item { display: inline-block; margin: 2px 12px 2px 0; }
  .unavail button, .unavail select, .unavail input { font-size: 13px; }
  dialog#plate-dialog { max-width: 440px; border: 1px solid #ccc; border-radius: 8px; padding: 16px 20px; }
  dialog#plate-dialog input[type=number] { width: 60px; padding: 3px; }
  .warnbox { background: #fff6e0; border: 1px solid #e8cf8a; color: #6b5416; padding: 8px 12px; border-radius: 3px; margin: 8px 0; font-size: 13px; }
  .hint { background: #eef6ee; border: 1px solid #b9d6b9; color: #2a5a2a; padding: 8px 12px; border-radius: 3px; margin: 8px 0; font-size: 13px; }
  .grid td, .grid th { text-align: center; padding: 4px 6px; }
  .grid td:first-child, .grid th:first-child { text-align: left; }
  .grid td.stranded { background: #fff6e0; font-weight: bold; }
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
</style>
</head>
<body>
<h1>Print Plan</h1>
<div class="viewbar">
  <a class="vb" href="ourmerch.php?view=needs-invoicing">Invoice</a>
  <a class="vb" href="ourmerch.php?view=needs-payment">Payments</a>
  <a class="vb on" href="ourprint.php">Create<?= count($printingActive) > 0 ? ' (&#128424; ' . count($printingActive) . ' printing)' : '' ?></a>
  <a class="vb" href="ourmerch.php?view=needs-shipping">Ship</a>
  <a class="vb" href="ourmerch.php?view=notes">Notes</a>
  <label title="Pickup orders, shirts and hats aren't part of the print plan - this opens the plain list that includes them"><input type="checkbox" id="focus-out" /> + Shirts/Hats/P/U</label>
</div>

<?php if (!$inv['exists']): ?>
  <div class="warnbox">No inventory recorded yet. Add what you have on the <a href="merch_stock_edit.php">Edit Stock page</a>. Until then the plan below assumes you have nothing on the shelf.</div>
<?php endif; ?>

<?php /* Everything marked "Start printing" and not yet Finished - always listed, even if the plan has since shifted. */ ?>
<?php if ($printingActive): ?>
<div class="now">
  <strong>&#128424; Currently printing (<?= count($printingActive) ?>)</strong>
  <ul>
    <?php foreach ($printingActive as $pa): ?>
      <li><?= $h($pa['color']) ?> &mdash; <?= $h($pa['plate']) ?> &mdash; <?= merch_qty_chip_text($h($pa['items'])) ?>
        <span style="color:#666;">(since <?= $h(date('D g:i a', strtotime($pa['since']))) ?>)</span></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<details class="sec" open>
<summary>Ready to ship from stock (<?= (int) count($ready) ?>)</summary>
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
</details>

<details class="sec" open>
<summary>What to print next</summary>
<div class="unavail" id="unavail-box">
  <strong>Colors not available to print right now</strong> (filament on order). The plan skips them, and orders still needing them wait below. Shelf stock of these colors still counts.
  <div style="margin-top:6px;">
    <?php if (empty($unavailRows)): ?>
      <span class="age">None &mdash; every color is treated as available.</span>
    <?php else: ?>
      <?php foreach ($unavailRows as $uc => $ur): ?>
        <span class="u-item"><strong><?= $h($uc) ?></strong><?= $ur['BackOn'] !== '' ? ' (back ' . $h($ur['BackOn']) . ')' : '' ?>
          <button type="button" class="u-clear" data-color="<?= $h($uc) ?>">Available again</button></span>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <div style="margin-top:6px;">
    Add:
    <select id="u-color">
      <?php foreach (merch_stock_stockable_colors(FILAMENT_COLORS, PRINT_PLATE_EXCLUDED_COLORS) as $uc): ?>
        <?php if (!isset($unavailRows[$uc])): ?><option><?= $h($uc) ?></option><?php endif; ?>
      <?php endforeach; ?>
    </select>
    back on <input type="date" id="u-date" /> <span class="age">(optional &mdash; blank until you clear it)</span>
    <button type="button" id="u-add">Mark unavailable</button>
    <span id="u-msg" style="margin-left:8px;"></span>
  </div>
</div>

<?php if ($mode === 'closeout'): ?>
  <p class="note">
    <?php if ($priority === 'age'): ?>
      The <?= (int) count($chosen) ?> <strong>longest-paid</strong> orders that could ship once printed (nothing waiting on Stars &amp; Stripes). Printing exactly the plates below ships all <?= (int) count($chosen) ?>:
    <?php else: ?>
      The <?= (int) count($chosen) ?> paid orders closest to done (fewest pieces still missing; ties go to pieces that share a plate with other near-done orders, then fewest colors, then oldest). Printing exactly the plates below ships all <?= (int) count($chosen) ?>:
    <?php endif; ?>
    <strong><?= (int) $planStats['units'] ?> pieces on <?= (int) $planStats['plates'] ?> plates across <?= (int) $planStats['colors'] ?> color(s)</strong>.</p>
  <?php if (!empty($chosen)): ?>
    <table>
      <tr><th>#</th><th>Customer</th><th>Order(s)</th><th>Waiting since</th><th>Already on shelf</th><th>Still missing</th></tr>
      <?php foreach ($chosen as $i => $s): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= $h($s['name'] !== '' ? $s['name'] : '(no name)') ?></td>
          <td><?= $shortIds($s['orderIds']) ?></td>
          <td class="age"><?= $h($ageOf($s)) ?></td>
          <td><?= $s['coveredUnits'] > 0 ? $linesText($s, 'covered') : '<span class="age">&mdash;</span>' ?></td>
          <td><?= $linesText($s, 'missing') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
<?php else: ?>
  <p class="note">Every paid piece still missing after stock<?= $priority === 'age' ? ', colors ordered by the longest-paid order they hold' : ', biggest color first' ?>: <strong><?= (int) $planStats['units'] ?> pieces on <?= (int) $planStats['plates'] ?> plates across <?= (int) $planStats['colors'] ?> colors</strong> (Stars &amp; Stripes and shirts/hats aren't in the plate plan<?= $specialCount > 0 ? '; ' . (int) $specialCount . ' order(s) need Stars &amp; Stripes by hand' : '' ?>).
  Press <strong>Start printing</strong> when a plate goes on the printer and <strong>Finished&hellip;</strong> when it comes off &mdash; Finished adds the pieces to the shelf (count what actually came off the bed).</p>
<?php endif; ?>

<?php if (empty($planGroups)): ?>
  <p class="empty">Nothing to print for this view.</p>
<?php else: ?>
  <?php foreach ($planGroups as $g): ?>
    <?php $cs = $planStats['byColor'][$g['color']] ?? ['units' => 0, 'plates' => 0]; $seq = 0; ?>
    <div class="color-block">
      <div class="chead"><strong><?= $h($g['color']) ?></strong> &mdash; <?= (int) $cs['units'] ?> piece(s), <?= (int) $cs['plates'] ?> plate(s)<?php $ow = $oldestTextForColor($g['color']); ?><?= $ow !== '' ? ' <span class="age">&middot; ' . $ow . '</span>' : '' ?></div>
      <ul class="plate-list">
        <?php foreach ($g['plateGroups'] as $pg): ?>
          <?php foreach ($pg['plates'] as $plate): ?>
            <?php
            $seq++;
            $bits = [];
            $doneItems = [];
            $textBits = [];
            $keyQtys = [];
            foreach ($plate['items'] as $item => $data) {
                $who = [];
                foreach ($data['orders'] as $o) {
                    $who[] = '<span title="#' . $h($o['orderId']) . '">' . $h($o['customerName']) . '</span>' . ($o['qty'] > 1 ? ' <span class="qty-chip">&times;' . (int) $o['qty'] . '</span>' : '');
                    $keyQtys[$item . '#' . $o['orderId']] = ($keyQtys[$item . '#' . $o['orderId']] ?? 0) + (int) $o['qty'];
                }
                $bits[] = merch_qty_chip((int) $data['qty']) . $h($item) . ' &rarr; ' . implode(', ', $who);
                $doneItems[] = ['item' => (string) $item, 'qty' => (int) $data['qty']];
                $textBits[] = (int) $data['qty'] . '× ' . $item;
            }
            // Same key recipe as ourmerch.php: color | plate label | order:qty pairs, so a mark made on either page matches.
            $orderQtys = [];
            foreach ($plate['items'] as $item => $data) {
                foreach ($data['orders'] as $o) {
                    $orderQtys[$item . '#' . $o['orderId']] = ($orderQtys[$item . '#' . $o['orderId']] ?? 0) + (int) $o['qty'];
                }
            }
            $plateKey = merch_printing_key((string) $g['color'], (string) $pg['group'], $orderQtys);
            $isPrinting = isset($printingActive[$plateKey]);
            $isFull = $plate['fillFraction'] >= 0.999;
            ?>
            <li class="<?= $isFull ? '' : 'partial' ?><?= $isPrinting ? ' printing' : '' ?>">
              <?php if ($isPrinting): ?>
                <span class="pbadge">&#128424; PRINTING since <?= $h(date('D g:i a', strtotime($printingActive[$plateKey]['since']))) ?></span>
                <button type="button" class="pbtn stop plate-stop" data-key="<?= $h($plateKey) ?>">Not printing</button>
              <?php else: ?>
                <button type="button" class="pbtn start plate-start" data-key="<?= $h($plateKey) ?>" data-color="<?= $h($g['color']) ?>" data-plate="<?= $h($pg['group']) ?>" data-items-text="<?= $h(implode(', ', $textBits)) ?>">Start printing</button>
              <?php endif; ?>
              <button type="button" class="pbtn fin plate-done" data-key="<?= $h($plateKey) ?>" data-color="<?= $h($g['color']) ?>" data-plate="<?= $h($pg['group']) ?>" data-items="<?= $h(json_encode($doneItems)) ?>">Finished&hellip;</button>
              <span class="plate-label"><?= $seq ?>. <?= $h($pg['group']) ?></span>
              <span class="plate-fill"><?= $isFull ? '(full)' : '(' . (int) round($plate['fillFraction'] * 100) . '% full)' ?></span>
              &mdash; <?= implode(' &nbsp;+&nbsp; ', $bits) ?>
            </li>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php
$heldByColorUnits = [];
foreach ($heldRows as $hr) {
    $heldByColorUnits[$hr['color']] = ($heldByColorUnits[$hr['color']] ?? 0) + (int) $hr['qty'];
}
?>

<div class="toolbar">
  <span>Plan view:</span>
  <a class="tab keep <?= $mode === 'batch' ? 'on' : '' ?>" href="?<?= $qs(['mode' => 'batch']) ?>">Everything, by color</a>
  <a class="tab keep <?= $mode === 'closeout' ? 'on' : '' ?>" href="?<?= $qs(['mode' => 'closeout']) ?>">Close-out: finish the next 10 orders</a>
  <span style="margin-left:8px;">Priority:</span>
  <a class="tab keep <?= $priority === 'age' ? 'on' : '' ?>" href="?<?= $qs(['prio' => 'age']) ?>">Oldest paid first</a>
  <a class="tab keep <?= $priority === 'quick' ? 'on' : '' ?>" href="?<?= $qs(['prio' => 'quick']) ?>">Quickest wins</a>
</div>
</details>

<?php if (!empty($heldRank) || !empty($heldByColorUnits)): ?>
<details class="sec" open>
<summary>Waiting on an unavailable color</summary>
  <?php if (!empty($heldRank)): ?>
    <table>
      <tr><th>Customer</th><th>Order(s)</th><th>Waiting since</th><th>Already on shelf</th><th>Still missing</th></tr>
      <?php foreach ($heldRank as $s): ?>
        <tr>
          <td><?= $h($s['name'] !== '' ? $s['name'] : '(no name)') ?></td>
          <td><?= $shortIds($s['orderIds']) ?></td>
          <td class="age"><?= $h($ageOf($s)) ?></td>
          <td><?= $s['coveredUnits'] > 0 ? $linesText($s, 'covered') : '<span class="age">&mdash;</span>' ?></td>
          <td><?= $linesText($s, 'missing') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?>
    <p class="note"><?php foreach ($heldByColorUnits as $hc => $hu): ?><strong><?= $h($hc) ?></strong>: <?= (int) $hu ?> piece(s) not planned. <?php endforeach; ?></p>
  <?php endif; ?>
</details>
<?php endif; ?>

<details class="sec" open>
<summary>Totals</summary>
<div class="summary">
  <div class="stat"><b><?= (int) count($ready) ?></b><span>orders you could ship right now from stock</span></div>
  <div class="stat"><b><?= (int) count($shipments) ?></b><span>paid orders still waiting on prints (<?= (int) $totalPiecesWaiting ?> pieces)</span></div>
  <div class="stat"><b><?= (int) merch_stock_total($stock) ?></b><span>printed pieces on hand<?= $inv['exists'] ? '' : ' (no inventory file yet)' ?></span></div>
  <div class="stat"><b><?= (int) $built['unpaidPieces'] ?></b><span>unpaid pieces ignored (<?= (int) $built['unpaidShipments'] ?> orders)</span></div>
</div>
<?php foreach ($parsed['unmatched'] as $u): ?>
  <div class="warnbox">Not counted: <?= (int) $u['qty'] ?> &times; <?= $h($u['part']) ?> in &ldquo;<?= $h($u['color']) ?>&rdquo; &mdash; <?= $h($u['reason']) ?> Fix the label in <code>inventory.csv</code> (or add the color to the site's list).</div>
<?php endforeach; ?>
<?php foreach ($parsed['warnings'] as $w): ?>
  <div class="warnbox"><?= $h($w) ?></div>
<?php endforeach; ?>
</details>

<?php
$gridColors = [];
foreach ($stock as $byColor) {
    foreach ($byColor as $color => $q) {
        $gridColors[$color] = true;
    }
}
$gridColors = array_keys($gridColors);
usort($gridColors, function ($a, $b) {
    return array_search($a, FILAMENT_COLORS, true) <=> array_search($b, FILAMENT_COLORS, true);
});
?>
<details class="sec">
<summary>Stock on hand (overview)</summary>
<?php if (empty($stock)): ?>
  <p class="empty">No stock recorded.</p>
<?php else: ?>
  <p class="note">Highlighted pieces match <em>no</em> paid order that still needs printing &mdash; candidates for an event table, not for filling orders.
    <?php if ($mtimeText !== ''): ?>Inventory file last updated <?= $h($mtimeText) ?>.<?php endif; ?> <a href="merch_stock_edit.php">Edit stock &rarr;</a></p>
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
</details>

<p class="note" style="margin-top:24px;">
  <a href="ourmerch.php">&larr; Merchandise Requests</a> &middot;
  <a href="merch_stock_edit.php">Edit Stock &rarr;</a> &middot;
  Build <?= $h($build) ?> &middot; paid shipping orders only (no pickup, shirts or hats), whole shipments only, exact color only, longest-paid first
</p>

<!-- Plate finished: adds the pieces to the shelf (inventory.csv) through merch_stock_adjust.php, the same
     path as the Edit Stock page's Add. Orders are NOT touched: the planner then covers waiting orders from
     the shelf, and Done under Ready to ship marks them Created and takes the pieces off the shelf. -->
<dialog id="plate-dialog">
  <p style="margin:0 0 6px; font-weight:bold;" id="pd-title">Plate finished</p>
  <p class="note" style="margin:0 0 10px;">Adds these pieces to the shelf. Change a number if the plate came out different (a failed piece, a spare). It does not change any order.</p>
  <div id="pd-rows"></div>
  <p id="pd-msg" style="min-height:1.2em; font-size:13px; margin:10px 0;"></p>
  <div style="display:flex; gap:8px; justify-content:flex-end;">
    <button type="button" id="pd-cancel">Cancel</button>
    <button type="button" id="pd-ok" class="done-btn">Add to shelf</button>
  </div>
</dialog>

<script>
(function () {
  var csrf = <?= json_encode($csrfToken) ?>;
  var SCROLL = 'sffPrintPlanScroll_v1';

  // ---- Keep the page where it was across any reload / toggle ----
  function rememberScroll() { try { sessionStorage.setItem(SCROLL, String(window.scrollY || 0)); } catch (e) {} }
  function reloadKeep() { rememberScroll(); location.reload(); }
  Array.prototype.forEach.call(document.querySelectorAll('a.keep'), function (a) { a.addEventListener('click', rememberScroll); });
  try {
    var y = parseInt(sessionStorage.getItem(SCROLL) || '', 10);
    sessionStorage.removeItem(SCROLL);
    if (y > 0) {
      window.scrollTo(0, y);
      window.addEventListener('load', function () { window.scrollTo(0, y); });
    }
  } catch (e) {}

  // ---- "+ Shirts/Hats/P/U": those orders are not in the plate plan, so this opens the plain list that has them ----
  var fo = document.getElementById('focus-out');
  if (fo) { fo.addEventListener('change', function () { if (fo.checked) { location.href = 'ourmerch.php?view=needs-creating&focusout=1'; } }); }

  function post(url, params) {
    var body = new URLSearchParams();
    Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
    body.append('csrf_token', csrf);
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected response (HTTP ' + r.status + ').' }; }); });
  }
  function token() {
    var a = new Uint8Array(12); (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  // ---- Ready to ship: tick every part, then Done. Ticks are remembered in this browser; NOTHING is saved until Done. ----
  var STORE = 'sffStockPickChecks_v1';
  var saved = {};
  try { saved = JSON.parse(localStorage.getItem(STORE) || '{}') || {}; } catch (e) { saved = {}; }
  function persist() { try { localStorage.setItem(STORE, JSON.stringify(saved)); } catch (e) {} }
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
      post('merch_stock_ship.php', { orderIds: tr.getAttribute('data-ids'), sig: tr.getAttribute('data-sig') })
        .then(function (d) {
          if (d.ok) {
            Array.prototype.forEach.call(boxes, function (b) { delete saved[b.getAttribute('data-k')]; });
            persist();
            msg.className = 'done-msg ok';
            msg.textContent = 'Done - marked Created and taken off the shelf. Reloading...';
            setTimeout(reloadKeep, 800);
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
  Object.keys(saved).forEach(function (k) { if (!valid[k]) { delete saved[k]; } });
  persist();

  // ---- Colors not available to print ----
  var um = document.getElementById('u-msg');
  function uDone(d) { if (d.ok) { reloadKeep(); } else { um.style.color = '#a00'; um.textContent = d.error || 'Could not save.'; } }
  var add = document.getElementById('u-add');
  if (add) {
    add.addEventListener('click', function () {
      var c = document.getElementById('u-color');
      if (!c || !c.value) { return; }
      um.style.color = '#555'; um.textContent = 'Saving...';
      post('merch_filament_unavailable.php', { action: 'set', color: c.value, backOn: document.getElementById('u-date').value }).then(uDone)
        .catch(function () { um.style.color = '#a00'; um.textContent = 'Could not reach the server.'; });
    });
  }
  Array.prototype.forEach.call(document.querySelectorAll('.u-clear'), function (b) {
    b.addEventListener('click', function () {
      post('merch_filament_unavailable.php', { action: 'clear', color: b.getAttribute('data-color') }).then(uDone)
        .catch(function () { um.style.color = '#a00'; um.textContent = 'Could not reach the server.'; });
    });
  });

  // ---- Start printing / Not printing ----
  Array.prototype.forEach.call(document.querySelectorAll('.plate-start'), function (b) {
    b.addEventListener('click', function () {
      b.disabled = true;
      post('merch_printing_set.php', { action: 'start', key: b.getAttribute('data-key'), color: b.getAttribute('data-color'), plate: b.getAttribute('data-plate'), items: b.getAttribute('data-items-text') })
        .then(function (d) { if (d.ok) { reloadKeep(); } else { alert('Could not save: ' + (d.error || 'unknown error')); b.disabled = false; } })
        .catch(function () { alert('Could not reach the server.'); b.disabled = false; });
    });
  });
  Array.prototype.forEach.call(document.querySelectorAll('.plate-stop'), function (b) {
    b.addEventListener('click', function () {
      if (!confirm('Mark this plate as not printing? (Use Finished… instead if it came off the printer.)')) { return; }
      b.disabled = true;
      post('merch_printing_set.php', { action: 'clear', key: b.getAttribute('data-key') })
        .then(function (d) { if (d.ok) { reloadKeep(); } else { alert('Could not save: ' + (d.error || 'unknown error')); b.disabled = false; } })
        .catch(function () { alert('Could not reach the server.'); b.disabled = false; });
    });
  });

  // ---- Plate finished ----
  var dlg = document.getElementById('plate-dialog');
  var rowsEl = document.getElementById('pd-rows');
  var pmsg = document.getElementById('pd-msg');
  var okBtn = document.getElementById('pd-ok');
  var cur = null;
  Array.prototype.forEach.call(document.querySelectorAll('.plate-done'), function (b) {
    b.addEventListener('click', function () {
      var items; try { items = JSON.parse(b.getAttribute('data-items')); } catch (e) { return; }
      cur = { key: b.getAttribute('data-key') || '', color: b.getAttribute('data-color'), plate: b.getAttribute('data-plate'), items: items, tokens: items.map(token) };
      document.getElementById('pd-title').textContent = 'Plate finished: ' + cur.plate + ' (' + cur.color + ')';
      rowsEl.innerHTML = '';
      items.forEach(function (it, i) {
        var d = document.createElement('div'); d.style.margin = '4px 0';
        var lab = document.createElement('label'); lab.textContent = it.item + '  ';
        var inp = document.createElement('input'); inp.type = 'number'; inp.min = '0'; inp.max = '50'; inp.value = it.qty; inp.id = 'pd-q' + i;
        lab.appendChild(inp); d.appendChild(lab); rowsEl.appendChild(d);
      });
      pmsg.textContent = ''; okBtn.disabled = false;
      dlg.showModal();
    });
  });
  document.getElementById('pd-cancel').addEventListener('click', function () { dlg.close(); });
  okBtn.addEventListener('click', function () {
    if (!cur) { return; }
    okBtn.disabled = true; pmsg.style.color = '#555'; pmsg.textContent = 'Saving...';
    var done = [];
    var i = 0;
    function next() {
      if (i >= cur.items.length) {
        pmsg.style.color = '#2a7a2a'; pmsg.textContent = 'Added: ' + (done.length ? done.join(', ') : 'nothing (all zero)') + '. Reloading...';
        // The plate is off the printer - clear its Currently printing mark (best effort), then reload.
        var finish = function () { setTimeout(reloadKeep, 800); };
        if (cur.key && done.length) { post('merch_printing_set.php', { action: 'clear', key: cur.key }).then(finish, finish); } else { finish(); }
        return;
      }
      var it = cur.items[i];
      var q = parseInt(document.getElementById('pd-q' + i).value, 10);
      if (!(q > 0)) { i++; next(); return; }
      post('merch_stock_adjust.php', { action: 'add', item: it.item, color: cur.color, qty: String(q), token: cur.tokens[i] })
        .then(function (d) {
          if (d.ok) { done.push(q + '× ' + it.item); i++; next(); }
          else {
            pmsg.style.color = '#a00';
            pmsg.textContent = (d.error || 'Could not save.') + (done.length ? ' Already added: ' + done.join(', ') + ' (see Edit Stock to undo).' : ' Nothing was added.');
            okBtn.disabled = false;
          }
        })
        .catch(function () {
          pmsg.style.color = '#a00';
          pmsg.textContent = 'Could not reach the server.' + (done.length ? ' Already added: ' + done.join(', ') + '.' : ' Nothing was added.') + ' Check Edit Stock before trying again.';
          okBtn.disabled = false;
        });
    }
    next();
  });
})();
</script>
</body>
</html>
