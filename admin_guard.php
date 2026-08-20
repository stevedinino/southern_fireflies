<?php
// Build: 2026-08-20-A
// ============================================================
// Shared "is this admin authenticated" plumbing for every admin-only
// page/endpoint. Centralizing this after Finding 11 (2026-08-19 code
// review) found the session check itself - and, on ourmerch.php/
// ourguests.php, the entire login form markup - duplicated byte-for-
// byte across 8 files, with three different reactions to "not logged
// in" (redirect, JSON 403, or render-a-form). This file doesn't force
// all three into one shape (that would change behavior these pages
// actually want to keep different) - it gives each shape ONE
// implementation instead of several drifting copies, so changing the
// session key, adding rate-limiting, etc. means editing here once.
//
// Also folds in the session-hardening half of Finding 9 (same review):
// the cookie flags below and session_regenerate_id() in
// merch_admin_login_gate() didn't exist anywhere before. CSRF
// protection and login throttling (the rest of Finding 9) are a
// bigger, separate change - not done here yet.
//
// Every admin file should require this INSTEAD OF calling
// session_start() itself - the cookie params below only take effect
// if set before session_start() runs, so don't call it again first.
// ============================================================

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    // HttpOnly: JS can't read the session cookie (nothing here needs
    // it to). SameSite=Lax: blocks the cookie being sent on a
    // cross-site POST, which is most of what CSRF protection would
    // otherwise need to do - it's not a substitute for real CSRF
    // tokens on the state-changing endpoints, just a floor under them.
    // Secure is conditional: this codebase is also run locally over
    // plain HTTP for testing (see the various *test/ harnesses), where
    // a hardcoded Secure flag would silently stop the cookie from ever
    // being sent/accepted at all. In production, Finding 7's HTTPS
    // enforcement means every real request is HTTPS, so this ends up
    // Secure there regardless.
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * For pure API/download endpoints (merch_update.php, merch_invoice.php):
 * a 403 JSON error and exit if there's no authenticated admin session.
 * Does nothing (returns) if already authenticated.
 */
function merch_require_admin_json(): void
{
    if (empty($_SESSION['sff_admin_ok'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
        exit;
    }
}

/**
 * For admin-only pages with no login form of their own
 * (shippo_export.php, packing_slips.php, export_emails.php, clear.php):
 * bounce to $redirectTo if there's no authenticated admin session.
 * Does nothing (returns) if already authenticated.
 */
function merch_require_admin_redirect(string $redirectTo): void
{
    if (empty($_SESSION['sff_admin_ok'])) {
        header('Location: ' . $redirectTo);
        exit;
    }
}

/**
 * For the two pages that ARE the login form (ourmerch.php,
 * ourguests.php): handles logout, handles a submitted password, and -
 * if still not authenticated - renders the login page and exits.
 * Returns (does not exit) once there's an authenticated admin session,
 * so the caller's own page can go on to render its real content.
 *
 * $pageTitle and $selfUrl are the two things that actually differed
 * between the previous copies (the <title>, and where logout should
 * redirect back to).
 */
function merch_admin_login_gate(string $pageTitle, string $selfUrl): void
{
    if (isset($_POST['logout'])) {
        unset($_SESSION['sff_admin_ok']);
        header('Location: ' . $selfUrl);
        exit;
    }

    $loginError = '';
    if (!isset($_SESSION['sff_admin_ok'])) {
        if (isset($_POST['admin_password'])) {
            if (hash_equals(ADMIN_PASSWORD, $_POST['admin_password'])) {
                $_SESSION['sff_admin_ok'] = true;
                // Session fixation protection: rotate the session ID on
                // every successful login (deletes the old session file
                // too) so a pre-login session ID an attacker planted
                // can't ride along into the authenticated session.
                session_regenerate_id(true);
            } else {
                $loginError = 'Incorrect password.';
            }
        }
    }

    if (!isset($_SESSION['sff_admin_ok'])) {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="UTF-8" />
          <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
          <link rel="stylesheet" href="styles/layout.css" />
        </head>
        <body>
          <div class="content-wrapper">
            <div class="form-container">
              <h2>Admin Login</h2>
              <?php if ($loginError !== ''): ?>
                <p style="color:#b00020; text-align:center;"><?= htmlspecialchars($loginError) ?></p>
              <?php endif; ?>
              <form method="POST">
                <input type="password" name="admin_password" placeholder="Password" required />
                <button type="submit" class="btn full-width">Log In</button>
              </form>
            </div>
          </div>
        </body>
        </html>
        <?php
        exit;
    }
}
