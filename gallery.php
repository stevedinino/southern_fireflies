<?php require __DIR__ . '/events/events_helpers.php'; // Build: 2026-09-08-B ?>
<!DOCTYPE html>
<!-- Build: 2026-09-08-B -->
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Gallery – Southern Fireflies Retreats</title>
  <link rel="icon" href="images/favicon.png" type="image/png" />
  <link rel="stylesheet" href="styles/layout.css" />
</head>
<body class="gallery-page">
  <header>
    <div class="nav-wrapper">
      <button class="menu-toggle" aria-label="Toggle menu">☰</button>
      <nav class="main-nav">
        <ul class="nav-links">
          <li><a href="index.php">Home</a></li>
          <li><a href="merch.php">Merch</a></li>
          <li><a href="cancellation.php">Our Policies</a></li>
          <li><a href="gallery.php" aria-current="page">Gallery</a></li>
          <li><a href="about.php">About</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <div class="content-wrapper">
    <div class="page-container">
      <h1>Gallery</h1>
      <p class="gallery-intro">Click any photo to view it full size.</p>

      <?php
      // ============================================================
      // Reads straight from events/events_helpers.php - the SAME
      // catalog index.php and merch.php's pickup dropdown use (see
      // events-data.php's header for the full 2026-09-08 unification
      // story). Unlike those two, the gallery shows EVERY event,
      // archived or not - archived only means "don't offer
      // registration for this," it says nothing about whether the
      // photos are worth showing. Past/Upcoming here is purely
      // events_is_past() (today vs. the event's endDate), so a
      // retreat moves itself into "Past Retreats" automatically the
      // day after it ends - no folder rename, no flag to flip.
      //
      // Each card's cover image, in priority order:
      //   1. the first real photo in events/<slug>/photos/, if any
      //   2. the event's flyer, as a plain (non-clickable, not part
      //      of the swipeable gallery) stand-in - it's a promo
      //      graphic, not a retreat photo, so it never appears in the
      //      lightbox slideshow itself, same idea as a video's poster
      //      frame being attached rather than listed
      //   3. a plain "coming soon" placeholder
      // ============================================================
      $allEvents = events_all_by_date();
      $pastEvents = array_reverse(array_values(array_filter($allEvents, fn($e) => events_is_past($e))));
      $upcomingEvents = array_values(array_filter($allEvents, fn($e) => !events_is_past($e)));
      ?>

      <h2 class="gallery-section-heading">Past Retreats</h2>
      <?php if (!$pastEvents): ?>
        <p class="gallery-status">No past retreat photos yet — check back soon!</p>
      <?php else: ?>
        <div class="gallery-events-grid">
          <?php foreach ($pastEvents as $event): ?>
            <?php include __DIR__ . '/gallery_event_card.php'; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <h2 class="gallery-section-heading">Upcoming Retreats</h2>
      <?php if (!$upcomingEvents): ?>
        <p class="gallery-status">Nothing on the books yet — check back soon!</p>
      <?php else: ?>
        <div class="gallery-events-grid">
          <?php foreach ($upcomingEvents as $event): ?>
            <?php include __DIR__ . '/gallery_event_card.php'; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- Shared photo/video viewer - same component pattern as merch.php's
       #photo-viewer-modal (a card image or "See all ..." link opens it
       with that event folder's full media list; prev/next + counter
       only appear once there's more than one slide). Each page keeps
       its own copy of this markup/script since gallery.php and
       merch.php are otherwise independent pages, but the CSS classes
       (.lightbox, .lightbox-image, .lightbox-nav, ...) are shared. -->
  <div id="photo-viewer-modal" class="lightbox" hidden>
    <button id="photo-viewer-close" class="lightbox-close" type="button" aria-label="Close photo viewer">&times;</button>
    <button id="photo-viewer-prev" class="lightbox-nav lightbox-nav-prev" type="button" aria-label="Previous photo" hidden>&#10094;</button>
    <img id="photo-viewer-image" src="" alt="" class="lightbox-image" />
    <video id="photo-viewer-video" class="lightbox-image" controls playsinline preload="metadata" hidden></video>
    <button id="photo-viewer-next" class="lightbox-nav lightbox-nav-next" type="button" aria-label="Next photo" hidden>&#10095;</button>
    <div id="photo-viewer-counter" class="photo-viewer-counter" hidden></div>
    <div id="photo-viewer-caption" class="photo-viewer-caption" hidden></div>
  </div>

  <script>
    const menuToggle = document.querySelector('.menu-toggle');
    const navLinks = document.querySelector('.nav-links');
    menuToggle.addEventListener('click', () => {
      navLinks.classList.toggle('show');
    });

    // Photo/video viewer - every real gallery photo always carries its
    // whole event folder's media list in data-gallery (even a folder
    // with just one photo), so there's exactly one open path:
    // openPhotoGallery(). Prev/next/counter only show once there's
    // more than one slide. Flyer stand-in covers and placeholder boxes
    // have no data-gallery attribute at all, so they're not clickable.
    const photoViewerModal = document.getElementById('photo-viewer-modal');
    const photoViewerImage = document.getElementById('photo-viewer-image');
    const photoViewerVideo = document.getElementById('photo-viewer-video');
    const photoViewerClose = document.getElementById('photo-viewer-close');
    const photoViewerPrev = document.getElementById('photo-viewer-prev');
    const photoViewerNext = document.getElementById('photo-viewer-next');
    const photoViewerCounter = document.getElementById('photo-viewer-counter');
    const photoViewerCaption = document.getElementById('photo-viewer-caption');

    let gallerySlides = [];
    let galleryIndex = 0;

    function stopViewerVideo() {
      if (!photoViewerVideo.hidden) {
        photoViewerVideo.pause();
      }
    }

    function renderGallerySlide() {
      const slide = gallerySlides[galleryIndex];
      stopViewerVideo();
      const isVideo = slide.type === 'video';
      photoViewerImage.hidden = isVideo;
      photoViewerVideo.hidden = !isVideo;
      if (isVideo) {
        photoViewerImage.src = '';
        if (photoViewerVideo.src !== slide.src) {
          photoViewerVideo.src = slide.src;
        }
        if (slide.poster) photoViewerVideo.poster = slide.poster;
        photoViewerVideo.setAttribute('aria-label', slide.alt || '');
      } else {
        photoViewerVideo.removeAttribute('src');
        photoViewerImage.src = slide.src;
        photoViewerImage.alt = slide.alt || '';
      }
      photoViewerCounter.textContent = `${galleryIndex + 1} / ${gallerySlides.length}`;
      photoViewerCaption.textContent = slide.alt || '';
      photoViewerCaption.hidden = !slide.alt;
    }

    // slides: array of {type?, src, poster?, alt}. startIndex: which one to open on.
    function openPhotoGallery(slides, startIndex) {
      gallerySlides = slides;
      galleryIndex = startIndex || 0;
      renderGallerySlide();
      const hasMultiple = gallerySlides.length > 1;
      photoViewerPrev.hidden = !hasMultiple;
      photoViewerNext.hidden = !hasMultiple;
      photoViewerCounter.hidden = !hasMultiple;
      photoViewerModal.hidden = false;
      document.body.classList.add('lightbox-open');
    }

    function showPrevPhoto() {
      if (!gallerySlides.length) return;
      galleryIndex = (galleryIndex - 1 + gallerySlides.length) % gallerySlides.length;
      renderGallerySlide();
    }

    function showNextPhoto() {
      if (!gallerySlides.length) return;
      galleryIndex = (galleryIndex + 1) % gallerySlides.length;
      renderGallerySlide();
    }

    function closePhotoViewer() {
      stopViewerVideo();
      photoViewerModal.hidden = true;
      photoViewerImage.src = '';
      photoViewerVideo.removeAttribute('src');
      photoViewerVideo.hidden = true;
      photoViewerImage.hidden = false;
      photoViewerCaption.hidden = true;
      gallerySlides = [];
      document.body.classList.remove('lightbox-open');
    }

    document.querySelectorAll('.gallery-event-photo[data-gallery]').forEach((img) => {
      img.addEventListener('click', () => {
        openPhotoGallery(JSON.parse(img.dataset.gallery), 0);
      });
    });

    document.querySelectorAll('.gallery-event-link').forEach((btn) => {
      btn.addEventListener('click', () => {
        openPhotoGallery(JSON.parse(btn.dataset.gallery), 0);
      });
    });

    photoViewerPrev.addEventListener('click', showPrevPhoto);
    photoViewerNext.addEventListener('click', showNextPhoto);
    photoViewerClose.addEventListener('click', closePhotoViewer);
    photoViewerModal.addEventListener('click', (event) => {
      if (event.target === photoViewerModal) closePhotoViewer();
    });
    document.addEventListener('keydown', (event) => {
      if (photoViewerModal.hidden) return;
      if (event.key === 'Escape') closePhotoViewer();
      if (event.key === 'ArrowLeft') showPrevPhoto();
      if (event.key === 'ArrowRight') showNextPhoto();
    });
  </script>
</body>
</html>
