<?php
// Build: 2026-09-16-A
// 2026-08-01: renamed from index.html to index.php so the Save-the-
// Date grid below could loop over /events/events-data.php instead of
// being hand-typed - adding a retreat is now one manifest entry, not
// a new card here AND a new flyer file. The hero tagline text stays
// exactly as plain hardcoded HTML per Steve's call - only the event
// grid itself became data-driven.
//
// 2026-09-08: switched from requiring events-data.php directly to
// events_upcoming() (events/events_helpers.php) - same underlying
// data, but this excludes archived:true entries (retreats that
// already happened and Steve has marked done) so old, sold-out
// retreats don't linger on the homepage forever. See events-data.php's
// header for the full story.
require __DIR__ . '/events/events_helpers.php';
require __DIR__ . '/strings.php';
$events = events_upcoming();
// 2026-10-22: Past Events cards (below Save the Date) are now the way into
// gallery.php, which no longer has a top-nav slot.
$pastEvents = array_reverse(array_values(array_filter(events_all_by_date(), fn($e) => events_is_past($e))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Southern Fireflies Retreats</title>
  <link rel="icon" href="images/favicon.png" type="image/png" />
  <link rel="stylesheet" href="styles/base.css" />
  <link rel="stylesheet" href="styles/site.css" />
  <link rel="stylesheet" href="styles/home.css" />
</head>
<body class="home-page">
  <header>
    <div class="nav-wrapper">
      <button class="menu-toggle" aria-label="Toggle menu">&#9776;</button>
      <nav class="main-nav">
        <ul class="nav-links">
          <li><a href="index.php" aria-current="page">Events</a></li>
          <li><a href="merch.php">Merch</a></li>
          <li><a href="about.php">About</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <!-- Hero: banner + tagline. Optional flower/jar art on either side shows
       up on its own once images/home-art-left.png / home-art-right.png
       exist (see home.css). -->
  <section class="home-hero">
<?php if (file_exists(__DIR__ . '/images/home-art-left.png')): ?>
    <img src="images/home-art-left.png" alt="" class="home-hero-art home-hero-art-left" />
<?php endif; ?>
    <div class="home-hero-center">
      <img src="images/home-banner.png" alt="Southern Fireflies - Scrapbook &amp; Craft Retreat Co." class="home-logo" />
      <p class="home-tagline">
        A warm, creative getaway for crafters who want to relax, make, and connect.
        Pre-register below to save your spot at an upcoming retreat!
      </p>
      <div class="home-orn" aria-hidden="true">&#9825;</div>
    </div>
<?php if (file_exists(__DIR__ . '/images/home-art-right.png')): ?>
    <img src="images/home-art-right.png" alt="" class="home-hero-art home-hero-art-right" />
<?php endif; ?>
  </section>

  <div class="content-wrapper">

    <section class="save-the-dates">
      <h1>Save the Date</h1>
      <p class="gallery-intro">Weekends made for scrapbook souls. Click a card to see the full flyer and details.</p>

      <div class="std-grid">
<?php foreach ($events as $event): ?>

        <a class="std-card" href="events/event.php?slug=<?= urlencode($event['slug']) ?>">
<?php if ($event['soldOut']): ?>
          <span class="std-badge std-badge-sold-out">Sold Out</span>
<?php endif; ?>
          <?php if (!empty($event['thumbImage'])): ?>
          <img src="<?= htmlspecialchars($event['thumbImage'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?> flyer" class="std-thumb" loading="lazy" />
<?php else: ?>
          <span class="std-thumb std-thumb-empty" aria-hidden="true">&#10022;</span>
<?php endif; ?>
          <span class="std-caption"><?= htmlspecialchars($event['dateRange'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="std-subcaption"><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="std-pill<?= $event['soldOut'] ? ' std-pill-sold' : '' ?>"><?= $event['soldOut'] ? 'Sold out' : 'Open' ?></span>
        </a>
<?php endforeach; ?>

      </div>
    </section>

    <!-- Gift certificate promo banner (2026-09-16, holiday season) - same
         markup/string as merch.php and retreat-register.php. Moved below the
         Save the Date cards 2026-10-22 so the cards start higher on the page. -->
    <div class="giftcert-banner">
      <p><?= merch_load_string('pages/giftcert-banner') ?></p>
      <a href="gift-certificate.php" class="btn">Get a Gift Certificate</a>
    </div>

<?php if ($pastEvents): ?>
    <section class="save-the-dates past-events" id="past-events">
      <h1>Past Events</h1>
      <p class="gallery-intro">Click a card to see photos from that retreat.</p>

      <div class="std-grid">
<?php foreach ($pastEvents as $event): ?>

        <a class="std-card" href="gallery.php">
          <?php if (!empty($event['thumbImage'])): ?>
          <img src="<?= htmlspecialchars($event['thumbImage'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?> flyer" class="std-thumb" loading="lazy" />
<?php else: ?>
          <span class="std-thumb std-thumb-empty" aria-hidden="true">&#10022;</span>
<?php endif; ?>
          <span class="std-caption"><?= htmlspecialchars($event['dateRange'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="std-subcaption"><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></span>
        </a>
<?php endforeach; ?>

      </div>
    </section>
<?php endif; ?>

  </div>

  <script>
    const menuToggle = document.querySelector('.menu-toggle');
    const navLinks = document.querySelector('.nav-links');
    menuToggle.addEventListener('click', () => {
      navLinks.classList.toggle('show');
    });
  </script>

  <?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
