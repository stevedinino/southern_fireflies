<?php
// Build: 2026-09-16-A
// ============================================================
// Gift certificate request form - see giftcert.php for the full
// design rationale (two types/two payees, tiers+custom amount,
// manual redemption). This page only collects the request; the
// printable certificate itself is rendered by gift_certificate_order.php
// after submission.
//
// Not linked from the site nav/banners yet on purpose (2026-09-16,
// Steve) - backend first so Janet can review the certificate design
// before this goes live; the banner announcements and the merch page
// card come in a later pass.
// ============================================================
require __DIR__ . '/strings.php';
require __DIR__ . '/giftcert.php';
require __DIR__ . '/config.php';

$retreatEvents = giftcert_valid_retreat_events();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Gift Certificates – Southern Fireflies Retreats</title>
  <link rel="icon" href="images/favicon.png" type="image/png" />
  <link rel="stylesheet" href="styles/layout.css" />
  <style>
    /* Small page-specific additions - kept here rather than growing
       layout.css for a handful of rules only this page uses. */
    .giftcert-type-fieldset,
    .giftcert-amount-fieldset {
      border: 1px solid #ddd;
      border-radius: 8px;
      padding: 12px 14px 14px;
      margin: 0 0 14px;
    }
    .giftcert-type-fieldset legend,
    .giftcert-amount-fieldset legend {
      padding: 0 6px;
      font-weight: bold;
      color: var(--accent);
      font-size: 0.95rem;
    }
    .giftcert-radio-row {
      display: flex;
      align-items: center;
      gap: 8px;
      margin: 6px 0;
    }
    .giftcert-radio-row label {
      margin: 0;
      font-weight: normal;
    }
    .giftcert-radio-row input[type="radio"] {
      width: auto;
      margin: 0;
    }
    .giftcert-custom-amount-wrapper {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-left: 26px;
    }
    .giftcert-custom-amount-wrapper input[type="number"] {
      width: 110px;
      margin: 0;
    }
    .giftcert-payment-block {
      background: var(--bg);
      border-radius: 8px;
      padding: 14px;
      margin-bottom: 18px;
      text-align: center;
    }
    .giftcert-payment-block p {
      margin: 6px 0;
      font-size: 14px;
    }
  </style>
</head>
<body class="giftcert-page">
  <header>
    <div class="nav-wrapper">
      <button class="menu-toggle" aria-label="Toggle menu">&#9776;</button>
      <nav class="main-nav">
        <ul class="nav-links">
          <li><a href="index.php">Home</a></li>
          <li><a href="merch.php">Merch</a></li>
          <li><a href="cancellation.php">Our Policies</a></li>
          <li><a href="gallery.php">Gallery</a></li>
          <li><a href="about.php">About</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <div class="content-wrapper">
    <div class="form-container">
      <h2>Gift Certificates</h2>

      <p><?= merch_load_string('pages/giftcert-intro') ?></p>

      <form action="gift_certificate_order.php" method="POST" id="giftcert-form">

        <fieldset class="giftcert-type-fieldset">
          <legend>Certificate type</legend>
          <div class="giftcert-radio-row">
            <input type="radio" name="type" id="type-merch" value="Merch" checked />
            <label for="type-merch">Merch &mdash; never expires</label>
          </div>
          <div class="giftcert-radio-row">
            <input type="radio" name="type" id="type-retreat" value="Retreat"<?= empty($retreatEvents) ? ' disabled' : '' ?> />
            <label for="type-retreat">Retreat &mdash; good toward a specific upcoming retreat</label>
          </div>
<?php if (empty($retreatEvents)): ?>
          <p style="font-size:13px; color:#888; margin: 4px 0 0;">No upcoming retreats are open for this right now.</p>
<?php endif; ?>
        </fieldset>

        <div id="event-field-wrapper" hidden>
          <label for="giftcert-event" class="four-day-label">Which retreat?</label>
          <select name="event" id="giftcert-event">
            <option value="">Select a retreat&hellip;</option>
<?php foreach ($retreatEvents as $eventLabel): ?>
            <option value="<?= htmlspecialchars($eventLabel, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($eventLabel, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
          </select>
        </div>

        <fieldset class="giftcert-amount-fieldset">
          <legend>Amount</legend>
<?php foreach (GIFTCERT_TIERS as $tier): ?>
          <div class="giftcert-radio-row">
            <input type="radio" name="amount" id="amount-<?= $tier ?>" value="<?= $tier ?>"<?= $tier === GIFTCERT_TIERS[0] ? ' checked' : '' ?> />
            <label for="amount-<?= $tier ?>">$<?= $tier ?></label>
          </div>
<?php endforeach; ?>
          <div class="giftcert-radio-row">
            <input type="radio" name="amount" id="amount-custom" value="custom" />
            <label for="amount-custom">Custom amount:</label>
            <div class="giftcert-custom-amount-wrapper">
              $<input type="number" name="customAmount" id="giftcert-custom-amount" min="<?= GIFTCERT_MIN_AMOUNT ?>" max="<?= GIFTCERT_MAX_AMOUNT ?>" step="1" disabled aria-label="Custom amount in dollars" />
            </div>
          </div>
        </fieldset>

        <label for="giftcert-to" class="visually-hidden">Recipient's Name (To)</label>
        <input type="text" name="to" id="giftcert-to" placeholder="To (recipient's name)" required maxlength="60" />

        <label for="giftcert-from" class="visually-hidden">Your Name As It Should Appear (From)</label>
        <input type="text" name="from" id="giftcert-from" placeholder="From (your name, as it'll appear on the certificate)" required maxlength="60" />

        <label for="giftcert-buyer-name" class="visually-hidden">Your Name</label>
        <input type="text" name="buyerName" id="giftcert-buyer-name" placeholder="Your Name (for our records)" required maxlength="60" />

        <label for="giftcert-buyer-email" class="visually-hidden">Your Email Address</label>
        <input type="email" name="buyerEmail" id="giftcert-buyer-email" placeholder="Your Email Address" required />

        <div id="payment-merch" class="giftcert-payment-block">
          <p><strong>Pay via Venmo:</strong> <a href="<?= htmlspecialchars(VENMO_LINK_PRINTED, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">@<?= htmlspecialchars(VENMO_HANDLE_PRINTED, ENT_QUOTES, 'UTF-8') ?></a></p>
          <p><strong>Or PayPal:</strong> <?= htmlspecialchars(PAYPAL_EMAIL_PRINTED, ENT_QUOTES, 'UTF-8') ?></p>
<?php if (defined('VENMO_LAST4_PRINTED') && VENMO_LAST4_PRINTED): ?>
          <p style="font-size:12px; color:#777;">First time paying this Venmo account? It may ask you to confirm the last 4 digits of the recipient's phone: <?= htmlspecialchars(VENMO_LAST4_PRINTED, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
        </div>

        <div id="payment-retreat" class="giftcert-payment-block" hidden>
          <div class="qr-code" style="margin-bottom:0;">
            <img src="images/venmo.png" alt="@<?= htmlspecialchars(VENMO_HANDLE_MERCH, ENT_QUOTES, 'UTF-8') ?>" />
            <a href="<?= htmlspecialchars(VENMO_LINK_MERCH, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Scan QR code or click here to pay via Venmo</a>
            <p class="merch-price-note">PayPal also accepted: <?= htmlspecialchars(PAYPAL_EMAIL_MERCH, ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        </div>

        <p style="font-size:13px; color:#777; text-align:center; margin: -8px 0 14px;">Please send the amount above once you submit &mdash; we'll match it up by name/email.</p>

        <button type="submit" class="btn full-width" id="giftcert-submit">Get My Certificate</button>
      </form>
    </div>
  </div>

  <script>
    const menuToggle = document.querySelector('.menu-toggle');
    const navLinks = document.querySelector('.nav-links');
    menuToggle.addEventListener('click', () => {
      navLinks.classList.toggle('show');
    });

    // Type toggle: swap which payment block and event field show, same
    // show/hide-by-radio pattern as merch.php's fulfillment field and
    // retreat-register.php's event-display block.
    const typeRadios = document.querySelectorAll('input[name="type"]');
    const eventFieldWrapper = document.getElementById('event-field-wrapper');
    const giftcertEventSelect = document.getElementById('giftcert-event');
    const paymentMerch = document.getElementById('payment-merch');
    const paymentRetreat = document.getElementById('payment-retreat');

    function updateTypeVisibility() {
      const isRetreat = document.getElementById('type-retreat').checked;
      eventFieldWrapper.hidden = !isRetreat;
      giftcertEventSelect.required = isRetreat;
      paymentMerch.hidden = isRetreat;
      paymentRetreat.hidden = !isRetreat;
    }
    typeRadios.forEach((radio) => radio.addEventListener('change', updateTypeVisibility));
    updateTypeVisibility();

    // Custom-amount toggle: the number input only accepts a value (and
    // only counts toward "custom" is selected) when its own radio is
    // checked - disabled inputs aren't submitted at all, so picking a
    // tier can't accidentally also send a stale custom amount.
    const amountRadios = document.querySelectorAll('input[name="amount"]');
    const customAmountInput = document.getElementById('giftcert-custom-amount');
    const customAmountRadio = document.getElementById('amount-custom');

    function updateCustomAmountState() {
      customAmountInput.disabled = !customAmountRadio.checked;
      if (customAmountRadio.checked) {
        customAmountInput.focus();
      }
    }
    amountRadios.forEach((radio) => radio.addEventListener('change', updateCustomAmountState));
    // Typing directly into the amount box implies choosing "custom",
    // even if the radio itself wasn't clicked first.
    customAmountInput.addEventListener('focus', () => {
      customAmountRadio.checked = true;
      updateCustomAmountState();
    });

    // Same disable-on-submit feedback as retreat-register.php's form -
    // a slow connection shouldn't look like nothing happened.
    const giftcertForm = document.getElementById('giftcert-form');
    const giftcertSubmitBtn = document.getElementById('giftcert-submit');
    giftcertForm.addEventListener('submit', () => {
      giftcertSubmitBtn.disabled = true;
      giftcertSubmitBtn.textContent = 'Submitting...';
    });
  </script>

  <?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
