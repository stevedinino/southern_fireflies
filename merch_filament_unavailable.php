<?php
// Build: 2026-10-19-A
// Admin-only endpoint behind the "Colors not available to print" box on
// merch_stock_report.php (see filament_unavailable.php for the why).
// POST: action=set (color, backOn optional Y-m-d) | action=clear (color).
// Same admin + CSRF + session-release pattern as the other endpoints.

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
header('Content-Type: application/json');
require __DIR__ . '/pricing.php';
require __DIR__ . '/print_plates.php';
require __DIR__ . '/merch_stock.php';
require __DIR__ . '/filament_unavailable.php';

merch_require_admin_json();
merch_require_csrf_json();
session_write_close();

$action = trim((string) ($_POST['action'] ?? ''));
$color = trim((string) ($_POST['color'] ?? ''));
$backOn = trim((string) ($_POST['backOn'] ?? ''));

$ok = ['ok' => false, 'error' => 'Invalid request.'];
$valid = merch_stock_stockable_colors(FILAMENT_COLORS, PRINT_PLATE_EXCLUDED_COLORS);
if (in_array($color, $valid, true)) {
    if ($action === 'set') {
        $ok = merch_unavailable_set($color, $backOn, date('Y-m-d'));
    } elseif ($action === 'clear') {
        $ok = merch_unavailable_clear($color);
    }
}
if (!$ok['ok']) {
    http_response_code(400);
}
echo json_encode($ok);
