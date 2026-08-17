<?php
session_start();

if (empty($_SESSION['sff_admin_ok'])) {
    header('Location: ourguests.php');
    exit;
}

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
