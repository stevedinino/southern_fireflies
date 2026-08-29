<?php
// Build: 2026-08-29-A
// ============================================================
// Closes the CSRF-token half of code review Finding 9 (2026-08-19):
// admin_guard.php's own header comment already flagged that SameSite=
// Lax on the session cookie "is not a substitute for real CSRF tokens
// on the state-changing endpoints, just a floor under them" - this is
// that follow-up. Wired into the four endpoints Finding 9 named:
// clear.php, merch_update.php, merch_invoice.php, merch_edit_line.php.
//
// One token per session, generated the first time it's asked for and
// reused for the rest of that login - not a fresh one per request/per
// form, which would make the multi-fetch admin table (dozens of
// checkboxes/buttons on one page load) a headache to keep in sync for
// no real security benefit here.
//
// merch_paid_receipt.php deliberately does NOT get a CSRF check - it's
// read-only (never writes to merchandise.csv, per its own header
// comment), and CSRF only matters for actions that change state.
// ============================================================

/**
 * Returns this session's CSRF token, generating one on first use.
 * Embed this in every form/JS blob that will POST to a protected
 * endpoint - admin_guard.php's require_once means session_start() has
 * already run by the time any caller reaches this.
 */
function merch_csrf_token(): string
{
    if (empty($_SESSION['sff_csrf_token'])) {
        $_SESSION['sff_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['sff_csrf_token'];
}

/**
 * For JSON/API endpoints (merch_update.php, merch_invoice.php,
 * merch_edit_line.php): a 403 JSON error and exit if $_POST['csrf_token']
 * doesn't match this session's token. Does nothing (returns) if it
 * matches. Call this AFTER merch_require_admin_json() - there's no
 * reason to reveal anything about CSRF state to a request that isn't
 * even authenticated yet.
 */
function merch_require_csrf_json(): void
{
    $submitted = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $expected = $_SESSION['sff_csrf_token'] ?? '';
    if ($expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Your session looks out of date - refresh the page and try again.']);
        exit;
    }
}

/**
 * Same check as merch_require_csrf_json(), for a page that redirects
 * on failure instead of returning JSON (clear.php's shape). Call this
 * AFTER merch_require_admin_redirect().
 */
function merch_require_csrf_redirect(string $redirectTo): void
{
    $submitted = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $expected = $_SESSION['sff_csrf_token'] ?? '';
    if ($expected === '' || !hash_equals($expected, $submitted)) {
        header('Location: ' . $redirectTo);
        exit;
    }
}
