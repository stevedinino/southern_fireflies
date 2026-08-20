<?php
// Build: 2026-08-20-A
require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session

// Shared implementation in admin_guard.php as of 2026-08-20 (Finding
// 11, 2026-08-19 code review) - was previously duplicated across 8 files.
merch_require_admin_redirect('ourguests.php');

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
