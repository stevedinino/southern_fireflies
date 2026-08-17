<?php
// Build: 2026-08-01-A
// ============================================================
// Single shared flyer page for every retreat. Replaces the old
// event-august-2026.html / event-august-2027.html / etc files -
// there is no longer a separate HTML file per event. Which retreat
// to show comes from ?slug=... in the URL, matched against the
// events-data.php manifest sitting next to this file.
//
// A brand-new retreat needs: one new entry in events-data.php, plus
// its flyer/thumbnail images uploaded to /events. No new PHP file.
//
// If ?slug= is missing or doesn't match anything in the manifest,
// this bails out to a plain "event not found" message rather than a
// raw PHP error or a blank page - link rot (an old bookmark, a typo
// in a shared link) should fail obviously, not silently.
// ============================================================

$events = require __DIR__ . '/events-data.php';

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$event = $events[$slug] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= $event ? htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') . ' – Southern Fireflies Retreats' : 'Retreat Not Found – Southern Fireflies Retreats' ?></title>
  <link rel="icon" href="../images/favicon.png" type="image/png" />
  <link rel="stylesheet" href="../styles/layout.css" />
</head>
<body>
  <header>
    <div class="nav-wrapper">
      <button class="menu-toggle" aria-label="Toggle menu">&#9776;</button>
      <nav class="main-nav">
        <ul class="nav-links">
          <li><a href="../index.php">Home</a></li>
          <li><a href="../merch.php">Merch</a></li>
          <li><a href="../cancellation.php">Our Policies</a></li>
          <li><a href="../gallery.php">Gallery</a></li>
          <li><a href="../about.php">About</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <div class="content-wrapper">
    <div class="page-container event-page">
<?php if (!$event): ?>
      <p style="text-align:center;">We couldn't find that retreat &mdash; it may have moved or the link may be out of date. <a href="../index.php">See upcoming retreats &rarr;</a></p>
<?php else: ?>
      <div class="event-cta event-cta-top">
<?php if ($event['soldOut']): ?>
        <button type="button" class="btn btn-sold-out" disabled aria-disabled="true">Sold Out</button>
<?php else: ?>
        <a href="../retreat-register.php?event=<?= urlencode($event['registerLabel']) ?>" class="btn">Register for This Retreat</a>
<?php endif; ?>
        <button type="button" class="btn btn-secondary" id="details-open">Details</button>
      </div>

      <img src="../<?= htmlspecialchars($event['flyerImage'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?> flyer, Rock Hill SC, <?= htmlspecialchars($event['dateRange'], ENT_QUOTES, 'UTF-8') ?>" class="flyer" />
<?php endif; ?>
    </div>
  </div>

<?php if ($event): ?>
  <div id="details-modal" class="lightbox" hidden>
    <button id="details-close" class="lightbox-close" type="button" aria-label="Close details">&times;</button>
    <div class="details-modal-content">
      <h2>Retreat Details</h2>
      <p><strong>Cost:</strong> <?= htmlspecialchars($event['costText'], ENT_QUOTES, 'UTF-8') ?></p>
      <p><?= htmlspecialchars($event['scheduleText'], ENT_QUOTES, 'UTF-8') ?></p>

      <h3>Location</h3>
      <p>
        <?= htmlspecialchars($event['hotelName'], ENT_QUOTES, 'UTF-8') ?><br />
        <a href="<?= htmlspecialchars($event['hotelLink'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
          <?= htmlspecialchars($event['hotelAddress'], ENT_QUOTES, 'UTF-8') ?>
        </a>
      </p>
<?php if (!empty($event['bookingLink'])): ?>
      <p>
        <a href="<?= htmlspecialchars($event['bookingLink'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
          <strong>Book Your Room &rarr;</strong>
        </a>
      </p>
<?php endif; ?>
      <p><em><?= htmlspecialchars($event['hotelRateNote'], ENT_QUOTES, 'UTF-8') ?></em></p>
    </div>
  </div>
<?php endif; ?>

  <script>
    const menuToggle = document.querySelector('.menu-toggle');
    const navLinks = document.querySelector('.nav-links');
    menuToggle.addEventListener('click', () => {
      navLinks.classList.toggle('show');
    });

<?php if ($event): ?>
    const detailsModal = document.getElementById('details-modal');
    const detailsOpen = document.getElementById('details-open');
    const detailsClose = document.getElementById('details-close');

    detailsOpen.addEventListener('click', () => {
      detailsModal.hidden = false;
      document.body.classList.add('lightbox-open');
    });
    function closeDetailsModal() {
      detailsModal.hidden = true;
      document.body.classList.remove('lightbox-open');
    }
    detailsClose.addEventListener('click', closeDetailsModal);
    detailsModal.addEventListener('click', (event) => {
      if (event.target === detailsModal) closeDetailsModal();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !detailsModal.hidden) closeDetailsModal();
    });
<?php endif; ?>
  </script>
</body>
</html>
