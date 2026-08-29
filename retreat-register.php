<?php require __DIR__ . '/strings.php'; // Build: 2026-08-29-B ?>
<!DOCTYPE html>
<!--
  Renamed from register.html to retreat-register.php on 2026-08-01 so
  this page's copy could load from /strings/pages/ like the rest of
  the site's customer-facing text. NOTE: this could NOT be named
  register.php - that filename is already the form-handler script
  (register.php, unchanged, still receives this page's POST). The
  three event flyer pages that link here (event-august-2027.html,
  event-february-2027.html, event-september-2026.html) were updated
  to point at retreat-register.php?event=... accordingly.
-->
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Register for Southern Fireflies Retreats</title>
  <link rel="icon" href="images/favicon.png" type="image/png" />
  <link rel="stylesheet" href="styles/layout.css" />
</head>
<body class="register-page">
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
      <h2>Retreat Registration</h2>

      <p id="event-display" class="event-display" hidden>
        Registering for: <strong id="event-display-name"></strong>
      </p>

      <div id="no-event-notice" class="no-event-notice" hidden>
        <?= merch_load_string('pages/register-no-event-notice') ?>
        <a href="index.php" class="btn full-width">See Upcoming Retreats</a>
      </div>

      <div id="registration-fields">
        <div class="qr-code">
          <img src="images/venmo.png" alt="@SouthernFirefliesRetreats" />
          <a href="https://venmo.com/SouthernFirefliesRetreats">
            Scan QR code or click here to pay via Venmo
          </a>
          <p class="merch-price-note">PayPal also accepted: janetdinino@gmail.com</p>
        </div>

        <form action="register.php" method="POST">
          <input type="hidden" name="event" id="event-field" value="" />
          <!-- 2026-08-29 (Finding 19, a11y): real <label>s, visually
               hidden (styles/layout.css's .visually-hidden), for every
               field that used to rely on its placeholder alone - same
               fix as merch.php's Request form; see that file's comment
               on the first one for why a placeholder isn't a label. -->
          <label for="register-name" class="visually-hidden">Full Name</label>
          <input type="text" name="name" id="register-name" placeholder="Full Name" required />
          <label for="register-address" class="visually-hidden">Home Address</label>
          <input type="text" name="address" id="register-address" placeholder="Home Address" required />
          <label for="register-phone" class="visually-hidden">Phone Number</label>
          <input type="tel" name="phone" id="register-phone" placeholder="Phone Number" required />
          <label for="register-email" class="visually-hidden">Email Address</label>
          <input type="email" name="email" id="register-email" placeholder="Email Address" required />

          <label for="four_day" class="four-day-label">How many days will you be attending?</label>
          <select name="four_day" id="four_day">
            <option value="3" selected>3 days (Fri&ndash;Sun)</option>
            <option value="4">4 days (Thu&ndash;Sun)</option>
          </select>

          <label for="register-message" class="visually-hidden">Special Requests or Notes</label>
          <textarea name="message" id="register-message" placeholder="Special Requests or Notes" rows="3"></textarea>
          <button type="submit" class="btn full-width">Submit Registration</button>
        </form>
      </div>
    </div>
  </div>

  <script>
    const menuToggle = document.querySelector('.menu-toggle');
    const navLinks = document.querySelector('.nav-links');
    menuToggle.addEventListener('click', () => {
      navLinks.classList.toggle('show');
    });

    // Carry the event name over from the flyer page's "Register" link (?event=...)
    const params = new URLSearchParams(window.location.search);
    const eventName = params.get('event');
    if (eventName) {
      document.getElementById('event-field').value = eventName;
      document.getElementById('event-display-name').textContent = eventName;
      document.getElementById('event-display').hidden = false;
    } else {
      // No event context - don't allow an ambiguous registration.
      document.getElementById('registration-fields').hidden = true;
      document.getElementById('no-event-notice').hidden = false;
    }

    // 2026-08-29 (code review Finding 18 follow-up): merch.php's Request
    // form already disables its submit button the instant it's clicked,
    // so a slow connection can't be mistaken for "nothing happened" and
    // invite a second click (which, there, would create a duplicate
    // order row). This registration form had the identical shape of
    // form-plus-submit-button with none of that feedback - same fix
    // here. The form still submits normally; this only affects the
    // button while the browser is en route to register.php.
    const registerForm = document.querySelector('#registration-fields form');
    const registerSubmitBtn = registerForm.querySelector('button[type="submit"]');
    registerForm.addEventListener('submit', () => {
      registerSubmitBtn.disabled = true;
      registerSubmitBtn.textContent = 'Submitting...';
    });
  </script>
</body>
</html>
