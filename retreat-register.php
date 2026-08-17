<?php require __DIR__ . '/strings.php'; // Build: 2026-08-01-A ?>
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
          <input type="text" name="name" placeholder="Full Name" required />
          <input type="text" name="address" placeholder="Home Address" required />
          <input type="tel" name="phone" placeholder="Phone Number" required />
          <input type="email" name="email" placeholder="Email Address" required />

          <label for="four_day" class="four-day-label">How many days will you be attending?</label>
          <select name="four_day" id="four_day">
            <option value="3" selected>3 days (Fri&ndash;Sun)</option>
            <option value="4">4 days (Thu&ndash;Sun)</option>
          </select>

          <textarea name="message" placeholder="Special Requests or Notes" rows="3"></textarea>
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
  </script>
</body>
</html>
