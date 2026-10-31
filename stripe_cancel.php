<?php
// Build: 2026-10-30-A
// Where Stripe sends a customer who backs out of the payment page. Nothing
// was charged. Their request is already saved (merch_order.php wrote it
// before sending them to Stripe), so it sits in Needs Invoicing like any
// other request and gets a normal invoice.
require __DIR__ . '/config.php';
require_once __DIR__ . '/strings.php';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>No Payment Taken</title>
  <link rel="stylesheet" href="styles/base.css" />
  <link rel="stylesheet" href="styles/merch.css" />
</head>
<body>
  <div class="content-wrapper">
    <div class="page-container" style="text-align:center;">
      <?= merch_load_string('pages/stripe-cancelled') ?>
      <p><a href="merch.php">&larr; Back to the merch page</a></p>
    </div>
  </div>
</body>
</html>
