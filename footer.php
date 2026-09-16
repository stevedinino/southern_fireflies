<?php
// Build: 2026-09-16-A
// Shared site footer (copyright notice). Centralized here instead of
// pasted into every page by hand, same "one place to fix it" principle
// as events/events_helpers.php - Steve doesn't want to hand-edit N
// pages every time the notice text or the year changes.
//
// Usage - right before the closing body tag on any customer-facing page:
//   include __DIR__ . '/footer.php';
// From a file one directory down (events/event.php), use:
//   include __DIR__ . '/../footer.php';
// (__DIR__ inside THIS file always resolves to the repo root, so the
// require below is correct either way - only the include path used to
// reach this file changes with the caller's location.)
//
// The year is today's real year via date('Y') - so it reads 2026 right
// now but becomes 2027 on its own come January, no yearly edit needed.
// The notice text itself lives in strings/pages/footer.html.txt, same
// as the rest of the site's customer-facing copy (see strings.php).
require_once __DIR__ . '/strings.php';
?>
  <footer class="site-footer">
    <p><?= merch_load_string('pages/footer.html', ['year' => date('Y')]) ?></p>
  </footer>
