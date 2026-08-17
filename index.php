<?php
// Build: 2026-08-14-B
// 2026-08-01: renamed from index.html to index.php so the Save-the-
// Date grid below could loop over /events/events-data.php instead of
// being hand-typed - adding a retreat is now one manifest entry, not
// a new card here AND a new flyer file. The hero tagline text stays
// exactly as plain hardcoded HTML per Steve's call - only the event
// grid itself became data-driven.
$events = require __DIR__ . '/events/events-data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Southern Fireflies Retreats</title>
  <link rel="icon" href="images/favicon.png" type="image/png" />
  <link rel="stylesheet" href="styles/layout.css" />
</head>
<body>
  <header>
    <div class="nav-wrapper">
      <button class="menu-toggle" aria-label="Toggle menu">&#9776;</button>
      <nav class="main-nav">
        <ul class="nav-links">
          <li><a href="index.php" aria-current="page">Home</a></li>
          <li><a href="merch.php">Merch</a></li>
          <li><a href="cancellation.php">Our Policies</a></li>
          <li><a href="gallery.php">Gallery</a></li>
          <li><a href="about.php">About</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <!-- Sits outside .content-wrapper on purpose - at double size (1120px)
       the banner is wider than the site's usual 1000px container, so it
       needs to be free to grow past that instead of getting clamped to
       it. .home-hero itself now carries its own horizontal padding for
       the gutter that .content-wrapper would otherwise have provided. -->
  <section class="home-hero">
    <img src="images/home-banner.png" alt="Southern Fireflies - Scrapbook &amp; Craft Retreat Co." class="home-logo" />
    <p class="home-tagline">
      A warm, creative getaway for crafters who want to relax, make, and connect.
      Pre-register below to save your spot at an upcoming retreat!
    </p>
  </section>

  <div class="content-wrapper">

    <section class="save-the-dates">
      <h1>Save the Date</h1>
      <p class="gallery-intro">Click a card to see that retreat's full flyer and details.</p>

      <div class="std-grid">
<?php foreach ($events as $slug => $event): ?>

        <a class="std-card" href="events/event.php?slug=<?= urlencode($slug) ?>">
<?php if ($event['soldOut']): ?>
          <span class="std-badge std-badge-sold-out">Sold Out</span>
<?php endif; ?>
          <img src="<?= htmlspecialchars($event['thumbImage'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?> flyer" class="std-thumb" loading="lazy" />
          <span class="std-caption"><?= htmlspecialchars($event['dateRange'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="std-subcaption"><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></span>
        </a>
<?php endforeach; ?>

      </div>
    </section>

  </div>

  <script>
    const menuToggle = document.querySelector('.menu-toggle');
    const navLinks = document.querySelector('.nav-links');
    menuToggle.addEventListener('click', () => {
      navLinks.classList.toggle('show');
    });
  </script>
</body>
</html>
