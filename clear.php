<?php
// Build: 2026-08-29-A
require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session

// Shared implementation in admin_guard.php as of 2026-08-20 (Finding
// 11, 2026-08-19 code review) - was previously duplicated across 8 files.
merch_require_admin_redirect('ourguests.php');

// 2026-08-29 (Finding 9): this endpoint used to run on ANY request
// method, including a bare GET - meaning a plain link or an
// auto-redirecting page, not even a form or JS, was enough to trigger
// it in an authenticated admin's browser (SameSite=Lax cookies still
// ride along on a top-level GET navigation, just not on cross-site
// subresource loads like <img>). Restricting to POST closes that on
// its own; requiring a valid CSRF token on top is the same defense-in-
// depth already applied to merch_update.php/merch_invoice.php/
// merch_edit_line.php.
//
// Note: ourguests.php's own Clear button/form was removed in an
// earlier change (2026-08-28) at Steve's request, and nothing else in
// this app currently submits a token here - so as of this fix, this
// endpoint has no live caller and can't be reached through the UI at
// all. Left working (not deleted) since the underlying capability may
// still be wanted; it just needs a form wired back up with
// merch_csrf_token() in it, the same way ourmerch.php's fetch() calls
// carry theirs, if it's ever needed again.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ourguests.php');
    exit;
}
merch_require_csrf_redirect('ourguests.php');

$csvFile = 'registrations.csv';

// Keep the header row (first line) intact; only clear the data rows below it.
$header = '';
if (file_exists($csvFile)) {
    $handle = fopen($csvFile, 'r');
    if ($handle) {
        $firstLine = fgets($handle);
        if ($firstLine !== false) {
            $header = rtrim($firstLine, "\r\n") . "\n";
        }
        fclose($handle);
    }
}
file_put_contents($csvFile, $header);

header('Location: ourguests.php');
exit;
