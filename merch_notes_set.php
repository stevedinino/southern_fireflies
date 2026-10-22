<?php
// Build: 2026-10-22-A
// Marks one customer note Handled / not handled, called via fetch() from
// ourmerch.php's Notes view. Admin session + CSRF gated like merch_update.php.
// Only appends to merch_notes_handled.csv (see merch_notes.php); never
// touches merchandise.csv.

require __DIR__ . '/admin_guard.php';
require __DIR__ . '/merch_notes.php';

header('Content-Type: application/json');
merch_require_admin_json();
merch_require_csrf_json();
session_write_close();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only.']);
    exit;
}

$key = trim((string) ($_POST['noteKey'] ?? ''));
$handled = ($_POST['handled'] ?? '') === '1';
if ($key === '' || strlen($key) > 300) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing note.']);
    exit;
}

if (!merch_notes_handled_append($key, $handled)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save (log file not writable).']);
    exit;
}
echo json_encode(['ok' => true, 'handled' => $handled]);
