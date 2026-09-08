<?php
// Build: 2026-09-08-A
// One event card - included twice by gallery.php (Past section,
// Upcoming section) with $event already set to the entry being
// rendered. Pulled into its own file just so that loop body isn't
// duplicated verbatim in two places in gallery.php.
$media = events_scan_photos($event['slug'], $event['title']);
$first = $media[0] ?? null;
$galleryJson = htmlspecialchars(json_encode($media), ENT_QUOTES, 'UTF-8');

$photoCount = 0;
$videoCount = 0;
foreach ($media as $m) {
    if ($m['type'] === 'video') { $videoCount++; } else { $photoCount++; }
}
if ($videoCount > 0 && $photoCount > 0) {
    $galleryLabel = 'See all photos &amp; video (' . count($media) . ')';
} elseif ($photoCount > 1) {
    $galleryLabel = 'See all ' . $photoCount . ' photos';
} else {
    $galleryLabel = 'See gallery (' . count($media) . ')';
}

$placeholderText = events_is_past($event) ? 'Photos coming soon' : 'Photos coming after this retreat!';
?>
<div class="gallery-event-card">
  <div class="gallery-event-media<?= ($first !== null && $first['type'] === 'video') ? ' gallery-event-video-wrapper' : '' ?>">
    <?php if ($first !== null && $first['type'] === 'video'): ?>
      <video class="gallery-event-video" controls playsinline preload="metadata"<?= $first['poster'] !== null ? ' poster="' . htmlspecialchars($first['poster'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
        <source src="<?= htmlspecialchars($first['src'], ENT_QUOTES, 'UTF-8') ?>" type="video/mp4" />
        Your browser doesn't support embedded video.
        <a href="<?= htmlspecialchars($first['src'], ENT_QUOTES, 'UTF-8') ?>">Download the video</a> instead.
      </video>
    <?php elseif ($first !== null): ?>
      <img src="<?= htmlspecialchars($first['src'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($first['alt'], ENT_QUOTES, 'UTF-8') ?>" class="gallery-event-photo zoomable" data-gallery="<?= $galleryJson ?>" />
    <?php elseif (!empty($event['flyerImage'])): ?>
      <!-- No real photos yet - the flyer stands in as the cover only.
           No data-gallery attribute on purpose: a promo graphic isn't
           a retreat photo, so it's never a swipeable lightbox slide,
           and it quietly stops being shown the moment real photos get
           dropped into events/<slug>/photos/. -->
      <img src="<?= htmlspecialchars($event['flyerImage'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?> flyer" class="gallery-event-photo gallery-event-flyer" />
    <?php else: ?>
      <div class="gallery-event-placeholder"><span><?= htmlspecialchars($placeholderText, ENT_QUOTES, 'UTF-8') ?></span></div>
    <?php endif; ?>
  </div>
  <h3><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></h3>
  <p class="gallery-event-dates"><?= htmlspecialchars($event['dateRange'], ENT_QUOTES, 'UTF-8') ?></p>
  <?php if (count($media) > 1): ?>
    <button type="button" class="gallery-event-link" data-gallery="<?= $galleryJson ?>"><?= $galleryLabel ?> &rarr;</button>
  <?php endif; ?>
</div>
