<?php
// Build: 2026-10-07-B
// Admin-only page: edit the printed-parts inventory live (Steve, 2026-10-07).
//
// Why it exists: keeping inventory.csv current meant editing a sheet in
// Excel, saving it as CSV and uploading it - slow, and any time he doubted
// "what I have vs what I added" he had to recount and redo the whole thing.
// This page changes ONE count at a time, writing straight to inventory.csv,
// and every change is recorded in inventory_log.csv so the history can
// answer that question. The upload box on the Stock & Print Plan page is
// gone; replacing the whole file is an FTP push of inventory.csv.
//
// How it works:
//   - Pick a color (it stays picked), then tap -1 / +1 / +plate on a part.
//     A plate button adds one plate's worth (the count on the item's own
//     single-type plate in print_plates.php; Tape Gun Holder and Add-On
//     are +3 each), the usual "a plate just finished" entry.
//   - Tapping the number itself lets you type the real count after counting
//     the shelf; only the difference is applied and logged.
//   - Each tap is a change (+3, -1) applied by the server to the file as it
//     is at that moment, never "set the count to N" - so a page left open
//     can't overwrite a pick-list pull or another tab. A removal below zero
//     is refused. A repeated tap/retry is recognized and applied once.
//   - Undo reverses the latest change, if the count hasn't moved since.
//
// Nothing here is read by the print-next logic differently: merch_stock_report.php
// still reads the same inventory.csv (and its Done button still takes pulled
// pieces off it and logs them here). Writes go through merch_stock_adjust.php.

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
require __DIR__ . '/pricing.php';
require __DIR__ . '/print_plates.php';
require __DIR__ . '/merch_stock.php';

merch_require_admin_redirect('ourmerch.php');

$csrfToken = merch_csrf_token();
// Every change goes through its own endpoint; release the session lock so
// this page never queues behind another admin request.
session_write_close();

$build = '2026-10-07-A';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// ---- Load (read-only) ------------------------------------------
$inv = merch_stock_load_inventory(__DIR__ . '/inventory.csv');
$parsed = merch_stock_parse_grid($inv['rows'], FILAMENT_COLOR_ITEMS, FILAMENT_COLORS);

$logRows = [];
$logPath = __DIR__ . '/inventory_log.csv';
if (is_file($logPath)) {
    $lh = fopen($logPath, 'r');
    if ($lh) {
        flock($lh, LOCK_SH);
        $raw = [];
        while (($r = fgetcsv($lh, 0, ',', '"', '\\')) !== false) {
            $raw[] = $r;
        }
        flock($lh, LOCK_UN);
        fclose($lh);
        $logRows = merch_stock_log_parse($raw);
    }
}
$view = merch_stock_log_view($logRows, 15);

// Parts: short display names + the plate size for the "+plate" button.
$shortName = function (string $item): string {
    $s = preg_replace('/ Cutter Holder$/', '', $item);
    return $s === 'Tool Holder Stand' ? 'Tool Stand' : $s;
};
$items = [];
foreach (FILAMENT_COLOR_ITEMS as $item) {
    $items[] = ['name' => $item, 'short' => $shortName($item), 'plate' => (int) (PRINT_PLATE_SOLO_CAPACITY[$item] ?? 1)];
}
// Colors: the popular ones first (same order the print plan uses), then the rest of the catalog.
$stockable = merch_stock_stockable_colors(FILAMENT_COLORS, PRINT_PLATE_EXCLUDED_COLORS);
$colors = [];
foreach (PRINT_PLATE_COLOR_PRIORITY as $c) {
    if (in_array($c, $stockable, true)) {
        $colors[] = $c;
    }
}
foreach ($stockable as $c) {
    if (!in_array($c, $colors, true)) {
        $colors[] = $c;
    }
}

$config = [
    'items' => $items,
    'colors' => $colors,
    'stock' => (object) $parsed['stock'],
    'reasons' => MERCH_STOCK_REMOVE_REASONS,
    'recent' => $view['recent'],
    'undoId' => $view['undoId'],
    'csrf' => $csrfToken,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Inventory &ndash; Southern Fireflies Retreats</title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; color: #222; margin: 20px; max-width: 980px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  h2 { font-size: 16px; margin: 24px 0 8px; padding-top: 10px; border-top: 1px solid #ccc; }
  .note { color: #666; font-size: 13px; margin: 2px 0 10px; }
  .summary { display: flex; flex-wrap: wrap; gap: 10px; margin: 12px 0; }
  .stat { border: 1px solid #ddd; border-radius: 4px; padding: 8px 14px; min-width: 150px; }
  .stat b { display: block; font-size: 22px; }
  .stat span { font-size: 12px; color: #666; }
  .warnbox { background: #fff6e0; border: 1px solid #e8cf8a; color: #6b5416; padding: 8px 12px; border-radius: 3px; margin: 8px 0; font-size: 13px; }
  .chips { display: flex; flex-wrap: wrap; gap: 6px; }
  .chip { font-size: 14px; padding: 8px 10px; min-height: 40px; border: 1px solid #aaa; border-radius: 4px; background: #fff; color: #222; cursor: pointer; }
  .chip .n { display: inline-block; margin-left: 6px; padding: 0 6px; border-radius: 9px; background: #eee; font-size: 12px; }
  .chip.zero { color: #888; }
  .chip.on { background: #222; border-color: #222; color: #fff; }
  .chip.on .n { background: #555; color: #fff; }
  .reason { margin: 4px 0 12px; font-size: 14px; }
  .reason select { padding: 6px; font-size: 14px; }
  .tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 10px; }
  .tile { border: 1px solid #ccc; border-radius: 6px; padding: 10px 12px; }
  .tile .name { font-weight: bold; font-size: 15px; }
  .tile .count { display: block; font-size: 34px; font-weight: bold; margin: 4px 0 8px; padding: 0 6px; background: none; border: 1px dashed transparent; border-radius: 4px; cursor: pointer; text-align: left; color: #222; }
  .tile .count:hover { border-color: #aaa; }
  .tile .count.zero { color: #aaa; }
  .tile .btns { display: flex; gap: 6px; }
  .tile .btns button { flex: 1; min-height: 44px; font-size: 16px; font-weight: bold; border: 1px solid #888; border-radius: 4px; background: #fff; cursor: pointer; }
  .tile .btns button.plus { border-color: #2a7a2a; color: #2a7a2a; }
  .tile .btns button.minus { border-color: #b00020; color: #b00020; }
  .tile .btns button:disabled { opacity: .35; cursor: not-allowed; }
  .tile .countform { display: flex; gap: 6px; margin: 4px 0 8px; align-items: center; }
  .tile .countform input { width: 80px; font-size: 22px; padding: 4px 6px; }
  .tile .countform button { min-height: 40px; padding: 0 12px; font-size: 14px; }
  .tile .hint { font-size: 11px; color: #777; margin-bottom: 4px; }
  .toast { position: sticky; bottom: 0; margin-top: 12px; min-height: 22px; }
  .toast .msg { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 10px 14px; border-radius: 4px; font-size: 14px; background: #eef6ee; border: 1px solid #b9d6b9; color: #2a5a2a; }
  .toast .msg.err { background: #fdecea; border-color: #e6b3ad; color: #8a1c14; }
  .toast .msg button { padding: 6px 14px; font-size: 14px; font-weight: bold; border: 1px solid #2a5a2a; background: #fff; border-radius: 3px; cursor: pointer; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; }
  th, td { border-bottom: 1px solid #e3e3e3; padding: 5px 8px; text-align: left; vertical-align: top; }
  th { background: #f6f6f6; }
  td.num, th.num { text-align: center; }
  td.pos { color: #2a7a2a; font-weight: bold; }
  td.neg { color: #b00020; font-weight: bold; }
  tr.outside td { background: #fff6e0; color: #6b5416; font-style: italic; }
  table.overview td.lbl { cursor: pointer; text-decoration: underline; }
  .empty { color: #777; font-style: italic; }
  .scroll { overflow-x: auto; }
  /* Phone at the printers: 29 color chips would push the parts off the screen, so the chip list scrolls in its own box. */
  @media (max-width: 640px) {
    body { margin: 12px; }
    .chips { max-height: 176px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px; padding: 6px; }
    .chip { font-size: 13px; padding: 6px 8px; min-height: 36px; }
    .tiles { grid-template-columns: 1fr 1fr; }
    .tile .btns button { font-size: 15px; }
  }
</style>
</head>
<body>
<h1>Inventory</h1>
<div class="note">
  <a href="ourmerch.php">&larr; Merchandise Requests</a> &middot;
  <a href="merch_stock_report.php">Stock &amp; Print Plan &rarr;</a> &middot;
  Build <?= $h($build) ?> &middot; every tap saves right away and is logged
</div>

<div class="summary">
  <div class="stat"><b id="total">0</b><span>printed pieces on hand</span></div>
</div>

<?php if (!$inv['exists'] || empty($inv['rows'])): ?>
  <div class="warnbox">No inventory recorded yet &mdash; the first piece you add creates <code>inventory.csv</code>.</div>
<?php endif; ?>
<?php foreach ($parsed['unmatched'] as $u): ?>
  <div class="warnbox">Not shown here: <?= (int) $u['qty'] ?> &times; <?= $h($u['part']) ?> in &ldquo;<?= $h($u['color']) ?>&rdquo; &mdash; <?= $h($u['reason']) ?> It is still in <code>inventory.csv</code>; fix the label in the file (FTP) to count it.</div>
<?php endforeach; ?>

<noscript><div class="warnbox">This page needs JavaScript.</div></noscript>

<h2>1. Pick a color</h2>
<div id="chips" class="chips"></div>

<h2 id="tiles-h">2. Parts</h2>
<div class="reason">
  <label>Reason for removals (&minus;1): <select id="reason"></select></label>
</div>
<div id="tiles" class="tiles"></div>
<div id="toast" class="toast" aria-live="polite"></div>

<h2>Recent changes</h2>
<div class="scroll"><table id="recent"></table></div>
<p class="note">Newest first. Plate finished = +; removals show their reason; &ldquo;Count correction&rdquo; is a recount; &ldquo;Pulled for&hellip;&rdquo; rows come from the Done button on Stock &amp; Print Plan. Undo reverses only the latest change, and only while that count hasn't moved.</p>

<h2>Everything on the shelf</h2>
<div class="scroll"><div id="overview"></div></div>
<p class="note">Tap a color name to jump to it. To replace the whole inventory at once, FTP a new <code>inventory.csv</code> over the old one &mdash; the next change here will note in the log that the file was replaced outside this page.</p>

<script>
(function () {
  var CFG = <?= json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var STORE = 'sffStockEditColor_v1';
  var state = { stock: CFG.stock || {}, color: null, busy: false, pending: null, undoId: CFG.undoId || 0, recent: CFG.recent || [], editing: null };

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }
  function qty(item, color) { return ((state.stock[item] || {})[color]) | 0; }
  function colorTotal(color) { var t = 0; CFG.items.forEach(function (i) { t += qty(i.name, color); }); return t; }
  function grandTotal() { var t = 0; CFG.colors.forEach(function (c) { t += colorTotal(c); }); return t; }
  function short(name) { var f = CFG.items.filter(function (i) { return i.name === name; })[0]; return f ? f.short : name; }

  try { var saved = localStorage.getItem(STORE); if (saved && CFG.colors.indexOf(saved) >= 0) { state.color = saved; } } catch (e) {}
  if (!state.color) { state.color = CFG.colors[0]; }

  var reasonSel = document.getElementById('reason');
  CFG.reasons.forEach(function (r) { var o = el('option', '', r); o.value = r; reasonSel.appendChild(o); });

  function newToken() {
    var s = '';
    try {
      var a = new Uint8Array(12);
      (window.crypto || window.msCrypto).getRandomValues(a);
      for (var i = 0; i < a.length; i++) { s += (a[i] % 36).toString(36); }
    } catch (e) {
      for (var j = 0; j < 12; j++) { s += Math.floor(Math.random() * 36).toString(36); }
    }
    return 'e' + Date.now().toString(36) + s;
  }

  // ---- rendering ---------------------------------------------
  function renderChips() {
    var box = document.getElementById('chips');
    box.textContent = '';
    CFG.colors.forEach(function (c) {
      var n = colorTotal(c);
      var b = el('button', 'chip' + (c === state.color ? ' on' : '') + (n === 0 ? ' zero' : ''), c);
      b.type = 'button';
      b.appendChild(el('span', 'n', String(n)));
      b.addEventListener('click', function () { selectColor(c); });
      box.appendChild(b);
    });
  }
  function renderTiles() {
    document.getElementById('tiles-h').textContent = '2. Parts in ' + state.color;
    var box = document.getElementById('tiles');
    box.textContent = '';
    CFG.items.forEach(function (it) {
      var n = qty(it.name, state.color);
      var tile = el('div', 'tile');
      tile.appendChild(el('div', 'name', it.short));
      if (state.editing === it.name) {
        tile.appendChild(el('div', 'hint', 'Type the number you actually counted'));
        var f = el('div', 'countform');
        var inp = el('input');
        inp.type = 'number'; inp.min = '0'; inp.max = '9999'; inp.step = '1'; inp.value = String(n);
        inp.setAttribute('inputmode', 'numeric');
        var save = el('button', '', 'Save'); save.type = 'button';
        var cancel = el('button', '', 'Cancel'); cancel.type = 'button';
        function doSave() { var v = String(inp.value).trim(); if (/^\d+$/.test(v)) { state.editing = null; send('count', it.name, state.color, v); } else { toast('Enter a whole number, 0 or more.', true); } }
        save.addEventListener('click', doSave);
        cancel.addEventListener('click', function () { state.editing = null; renderTiles(); });
        inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { doSave(); } else if (e.key === 'Escape') { state.editing = null; renderTiles(); } });
        f.appendChild(inp); f.appendChild(save); f.appendChild(cancel);
        tile.appendChild(f);
        box.appendChild(tile);
        setTimeout(function () { inp.focus(); inp.select(); }, 0);
        return;
      }
      var cnt = el('button', 'count' + (n === 0 ? ' zero' : ''), String(n));
      cnt.type = 'button';
      cnt.title = 'Tap to enter an exact count';
      cnt.addEventListener('click', function () { if (!state.busy) { state.editing = it.name; renderTiles(); } });
      tile.appendChild(cnt);
      var btns = el('div', 'btns');
      var minus = el('button', 'minus', '−1'); minus.type = 'button'; minus.disabled = n < 1;
      minus.addEventListener('click', function () { send('remove', it.name, state.color, '1', reasonSel.value); });
      var plus = el('button', 'plus', '+1'); plus.type = 'button';
      plus.addEventListener('click', function () { send('add', it.name, state.color, '1'); });
      btns.appendChild(minus); btns.appendChild(plus);
      if (it.plate > 1) {
        var pl = el('button', 'plus', '+' + it.plate); pl.type = 'button';
        pl.title = 'One plate: ' + it.plate;
        pl.addEventListener('click', function () { send('add', it.name, state.color, String(it.plate)); });
        btns.appendChild(pl);
      }
      tile.appendChild(btns);
      box.appendChild(tile);
    });
  }
  function fmtTime(t) { return String(t || '').replace(/:\d\d$/, ''); }
  function renderRecent() {
    var t = document.getElementById('recent');
    t.textContent = '';
    if (!state.recent.length) {
      var tr0 = el('tr'); var td0 = el('td', 'empty', 'No changes recorded yet.'); td0.colSpan = 6; tr0.appendChild(td0); t.appendChild(tr0);
      return;
    }
    var head = el('tr');
    ['When', 'Part', 'Color', 'Change', 'Now', 'Reason'].forEach(function (h, i) { head.appendChild(el('th', (i === 3 || i === 4) ? 'num' : '', h)); });
    t.appendChild(head);
    state.recent.forEach(function (r) {
      var tr = el('tr', r.source === 'outside' ? 'outside' : '');
      tr.appendChild(el('td', '', fmtTime(r.time)));
      if (r.source === 'outside') {
        var td = el('td', '', r.reason); td.colSpan = 5; tr.appendChild(td);
      } else {
        tr.appendChild(el('td', '', short(r.item)));
        tr.appendChild(el('td', '', r.color));
        tr.appendChild(el('td', 'num ' + (r.change > 0 ? 'pos' : 'neg'), (r.change > 0 ? '+' : '−') + Math.abs(r.change)));
        tr.appendChild(el('td', 'num', String(r.after)));
        tr.appendChild(el('td', '', r.reason));
      }
      t.appendChild(tr);
    });
  }
  function renderOverview() {
    var box = document.getElementById('overview');
    box.textContent = '';
    var rows = CFG.colors.filter(function (c) { return colorTotal(c) > 0; });
    if (!rows.length) { box.appendChild(el('div', 'empty', 'Nothing on the shelf yet.')); return; }
    var t = el('table', 'overview');
    var head = el('tr');
    head.appendChild(el('th', '', 'Color'));
    CFG.items.forEach(function (i) { head.appendChild(el('th', 'num', i.short)); });
    head.appendChild(el('th', 'num', 'Total'));
    t.appendChild(head);
    rows.forEach(function (c) {
      var tr = el('tr');
      var lbl = el('td', 'lbl', c);
      lbl.addEventListener('click', function () { selectColor(c); window.scrollTo(0, 0); });
      tr.appendChild(lbl);
      CFG.items.forEach(function (i) { var n = qty(i.name, c); tr.appendChild(el('td', 'num', n ? String(n) : '')); });
      tr.appendChild(el('td', 'num', String(colorTotal(c))));
      t.appendChild(tr);
    });
    var foot = el('tr'); foot.appendChild(el('th', '', 'Total'));
    CFG.items.forEach(function (i) { var s = 0; CFG.colors.forEach(function (c) { s += qty(i.name, c); }); foot.appendChild(el('th', 'num', String(s))); });
    foot.appendChild(el('th', 'num', String(grandTotal())));
    t.appendChild(foot);
    box.appendChild(t);
  }
  function renderAll() {
    document.getElementById('total').textContent = String(grandTotal());
    renderChips(); renderTiles(); renderRecent(); renderOverview();
  }
  function selectColor(c) {
    state.color = c; state.editing = null;
    try { localStorage.setItem(STORE, c); } catch (e) {}
    renderChips(); renderTiles();
  }

  // ---- toast -------------------------------------------------
  function toast(msg, isErr, undoId) {
    var box = document.getElementById('toast');
    box.textContent = '';
    if (!msg) { return; }
    var m = el('div', 'msg' + (isErr ? ' err' : ''));
    m.appendChild(el('span', '', msg));
    if (undoId) {
      var u = el('button', '', 'Undo'); u.type = 'button';
      u.addEventListener('click', function () { send('undo', '', '', '', '', String(undoId)); });
      m.appendChild(u);
    }
    box.appendChild(m);
  }
  function describe(d) {
    var r = d.result;
    var who = short(r.item) + ', ' + r.color;
    if (r.action === 'undo') { return 'Undone — ' + who + ' back to ' + r.after + '.'; }
    if (r.change === 0) { return who + ' is already ' + r.after + ' — nothing changed.'; }
    if (r.action === 'count') { return 'Counted ' + who + ': ' + r.before + ' → ' + r.after + ' (' + (r.change > 0 ? '+' : '−') + Math.abs(r.change) + ').'; }
    return (r.change > 0 ? 'Added ' : 'Removed ') + Math.abs(r.change) + ' × ' + who + ' — now ' + r.after + '.';
  }

  // ---- talking to the server ---------------------------------
  function send(action, item, color, q, reason, undoId) {
    if (state.busy) { return; }
    state.busy = true;
    document.body.style.cursor = 'progress';
    var key = [action, item, color, q, reason || '', undoId || ''].join('|');
    // A retry of the SAME request after a network failure reuses its token, so if the first try did go
    // through the server recognizes it and doesn't apply it twice.
    var token = (state.pending && state.pending.key === key) ? state.pending.token : newToken();
    state.pending = { key: key, token: token };
    var body = new URLSearchParams();
    body.append('csrf_token', CFG.csrf);
    body.append('action', action);
    body.append('token', token);
    if (action === 'undo') { body.append('undoId', undoId); }
    else {
      body.append('item', item); body.append('color', color); body.append('qty', q);
      if (action === 'remove') { body.append('reason', reason || ''); }
    }
    fetch('merch_stock_adjust.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected response (HTTP ' + r.status + ').' }; }); })
      .then(function (d) {
        state.pending = null;
        state.busy = false;
        document.body.style.cursor = '';
        if (d.ok) {
          state.stock = d.stock || {};
          state.recent = d.recent || [];
          state.undoId = d.undoId || 0;
          renderAll();
          var canUndo = d.undoId && d.result && d.result.logId === d.undoId && d.result.action !== 'undo';
          toast(describe(d) + (d.logWarning ? ' (The history row could not be saved.)' : ''), false, canUndo ? d.undoId : 0);
        } else {
          toast((d.error || 'Could not save.') + ' Nothing was changed.', true);
        }
      })
      .catch(function () {
        state.busy = false;
        document.body.style.cursor = '';
        toast('Could not reach the server. Tap again to retry — if the first try did go through, it will not be counted twice.', true);
      });
  }

  renderAll();
})();
</script>
</body>
</html>
