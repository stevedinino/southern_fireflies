<?php
// Build: 2026-10-30-A
// Where Stripe sends a customer after paying. Shows a warm thank-you and
// says "paid" only once the webhook has actually recorded the payment (it
// usually has by the time this loads; if not, the page re-checks a few
// times). It never asks Stripe anything and never trusts the URL for money
// facts - the session id is only used to look up our own record.
require __DIR__ . '/config.php';
require_once __DIR__ . '/strings.php';
require_once __DIR__ . '/stripe_lib.php';

$sid = isset($_GET['session_id']) ? (string) $_GET['session_id'] : '';
if (!preg_match('/^cs_[A-Za-z0-9_]{10,200}$/', $sid)) {
    $sid = '';
}
$tries = isset($_GET['n']) ? max(0, min(6, (int) $_GET['n'])) : 0;

$state = $sid !== '' ? merch_stripe_session_state($sid) : null;
$recorded = $state !== null && in_array($state['State'], ['paid', 'email-failed'], true);
$review = $state !== null && $state['State'] === 'review';
$refresh = (!$recorded && !$review && $sid !== '' && $tries < 6);
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($refresh): ?>
  <meta http-equiv="refresh" content="4;url=stripe_success.php?session_id=<?= htmlspecialchars($sid, ENT_QUOTES, 'UTF-8') ?>&amp;n=<?= $tries + 1 ?>">
<?php endif; ?>
  <title>Thank You</title>
  <link rel="stylesheet" href="styles/base.css" />
  <link rel="stylesheet" href="styles/merch.css" />
</head>
<body>
  <div class="content-wrapper">
    <div class="page-container" style="text-align:center;">
      <?php if ($recorded): ?>
        <?= merch_load_string('pages/stripe-success-paid') ?>
      <?php elseif ($review): ?>
        <?= merch_load_string('pages/stripe-success-review') ?>
      <?php else: ?>
        <?= merch_load_string('pages/stripe-success-waiting') ?>
      <?php endif; ?>
      <p><a href="merch.php">&larr; Back to the merch page</a></p>
    </div>
  </div>
</body>
</html>
