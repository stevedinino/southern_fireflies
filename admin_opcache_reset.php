<?php
// Build: 2026-09-24-A
require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session

// 2026-09-24 (Steve): diagnosing why a deployed fix (the print-plate
// queue fix in ourmerch.php) wasn't showing up live even though
// GitHub confirmed the SFTP deploy succeeded AND Steve confirmed via
// direct FTP that the file on the server had the new code. That combo
// - correct file on disk, old behavior still running - points at PHP
// OPcache: the host keeps a compiled copy of each file's bytecode and,
// depending on how opcache.validate_timestamps is configured, may not
// notice a file changed underneath it until something forces a reset.
// That's a layer entirely separate from the SFTP upload, so "the
// deploy succeeded" and "the server is running the new code" can
// genuinely disagree.
//
// This is the manual escape hatch: a one-click, admin-only trigger for
// opcache_reset() so a stuck deploy can be un-stuck without shell or
// hosting-control-panel access. Same admin-gated + POST + CSRF pattern
// as clear.php (Finding 9, 2026-08-19 code review), since - like
// clear.php - this changes live server state and shouldn't be
// reachable via a bare GET/link.
merch_require_admin_redirect('ourmerch.php');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ourmerch.php');
    exit;
}
merch_require_csrf_redirect('ourmerch.php');

$opcacheAvailable = function_exists('opcache_reset');
$opcacheEnabled = $opcacheAvailable && ini_get('opcache.enable') !== '0';
$result = $opcacheEnabled ? opcache_reset() : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>PHP Cache Reset</title>
  <link rel="stylesheet" href="styles/layout.css" />
</head>
<body>
  <div class="content-wrapper">
    <div class="form-container">
      <h2>PHP Cache Reset</h2>
      <?php if (!$opcacheAvailable): ?>
        <p>OPcache isn't available on this server (the <code>opcache_reset()</code> function doesn't exist) - so stale compiled code isn't what's going on here. Something else is holding the old behavior.</p>
      <?php elseif (!$opcacheEnabled): ?>
        <p>OPcache is installed but disabled (<code>opcache.enable=0</code>) - so stale compiled code isn't what's going on here. Something else is holding the old behavior.</p>
      <?php elseif ($result): ?>
        <p>Done &mdash; OPcache was cleared. Reload the page you were checking; it should now be running the latest deployed code.</p>
      <?php else: ?>
        <p>opcache_reset() ran but reported failure. Some hosts restrict which scripts are allowed to call it (<code>opcache.restrict_api</code>) - that setting would need to change in the host's PHP config, or the host's own control panel may offer a "clear cache" / "restart PHP" option instead.</p>
      <?php endif; ?>
      <p><a href="ourmerch.php">&larr; Back to Merchandise Requests</a></p>
    </div>
  </div>
</body>
</html>
<?php
exit;
