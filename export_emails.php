<?php
// Build: 2026-08-05-A
// Admin-only download: reads merchandise.csv AND archive/merchandise-
// archive.csv, pulls Email + Name from every row in both files, and
// writes out a deduped CSV ready to import into Brevo (or anywhere
// else). Same admin session gate as shippo_export.php - not a public
// endpoint, and deliberately lives in ourmerch.php's admin area, not
// on the public merch.php page.
//
// Every row counts, per Steve (2026-08-05) - no Paid/Created/Fulfilled
// filtering. If abandoned test rows become a real problem later this
// can be revisited, but at current volume it's not worth the added
// complexity.
//
// Dedup is case-insensitive and whitespace-trimmed by email - the
// same person using two different email addresses across orders will
// still come through as two separate rows, same known limitation as
// shippo_export.php's name+zip grouping.
//
// The archive file is OPTIONAL - if it doesn't exist yet (e.g. before
// Steve's first archiving pass), this just exports from merchandise.csv
// alone rather than failing. merchandise.csv itself is still required,
// same as every other admin tool here.

session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['sff_admin_ok'])) {
    header('Location: ourmerch.php');
    exit;
}

/**
 * Reads one CSV file and returns [email => name] pairs for every row
 * with a non-blank Email column. Returns an empty array (not an
 * error) if the file doesn't exist - callers decide whether that's
 * fatal for that particular file.
 */
function export_emails_read_file(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $handle = fopen($path, 'r');
    if (!$handle) {
        return [];
    }

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    if (empty($rows)) {
        return [];
    }

    $header = $rows[0];
    // Same BOM issue as every other admin file that reads this CSV.
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    }

    $emailIndex = array_search('Email', $header, true);
    $nameIndex = array_search('Name', $header, true);
    if ($emailIndex === false) {
        return [];
    }

    $pairs = [];
    foreach ($rows as $i => $row) {
        if ($i === 0) continue; // header
        $email = trim($row[$emailIndex] ?? '');
        if ($email === '') {
            continue;
        }
        $name = $nameIndex !== false ? trim($row[$nameIndex] ?? '') : '';
        $pairs[] = [$email, $name];
    }

    return $pairs;
}

$mainFile = __DIR__ . '/merchandise.csv';
if (!file_exists($mainFile)) {
    die('merchandise.csv not found.');
}

$allPairs = array_merge(
    export_emails_read_file($mainFile),
    export_emails_read_file(__DIR__ . '/archive/merchandise-archive.csv')
);

if (empty($allPairs)) {
    die('No email addresses found across merchandise.csv or the archive file.');
}

// Dedup by lowercased, trimmed email - first name seen for that email wins.
$deduped = [];
foreach ($allPairs as [$email, $name]) {
    $key = strtolower($email);
    if (!isset($deduped[$key])) {
        $deduped[$key] = ['email' => $email, 'name' => $name];
    }
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="customer_emails_' . date('Y-m-d_His') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Email', 'Name']);
foreach ($deduped as $row) {
    fputcsv($out, [$row['email'], $row['name']]);
}
fclose($out);
