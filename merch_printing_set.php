<?php
// Build: 2026-10-23-A
// Marks a print plate as printing / not printing, called via fetch() from
// ourmerch.php's Create view. Admin session + CSRF gated like
// merch_update.php. Only appends to merch_printing.csv (merch_printing.php).

require __DIR__ . '/admin_guard.php';
require __DIR__ . '/merch_printing.php';

header('Content-Type: application/json');
merch_require_admin_json();
merch_require_csrf_json();
session_write_close();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only.']);
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$key = trim((string) ($_POST['key'] ?? ''));
if (!in_array($action, ['start', 'clear'], true) || !preg_match('/^[0-9a-f]{12}$/', $key)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad request.']);
    exit;
}

$clip = fn($v) => mb_substr(trim((string) $v), 0, 300);
$ok = merch_printing_append($action, $key, $clip($_POST['color'] ?? ''), $clip($_POST['plate'] ?? ''), $clip($_POST['items'] ?? ''), 'ourmerch.php');
if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save (log file not writable).']);
    exit;
}
echo json_encode(['ok' => true, 'action' => $action]);
