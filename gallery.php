<?php
// Build: 2026-08-01-A
// 2026-08-01: renamed from gallery.html to gallery.php so the image
// list below could be read live from /gallery via glob() instead of
// a hand-typed JS array. Drop a new photo in /gallery, it shows up on
// next page load; delete one, it disappears. No file to edit here.
//
// Sorted by filename - your camera/phone numbers files sequentially
// (IMG_0124, IMG_0636, ...) so alphabetical order is chronological
// order in practice. If you ever add photos from a different device
// with a different naming pattern, they may not sort where you'd
// expect - just flag it and we can switch this to sort by file
// modified date instead, which is a one-line change.
$galleryFiles = glob(__DIR__ . '/gallery/*.{jpg,jpeg,png,JPG,JPEG,PNG}', GLOB_BRACE);
sort($galleryFiles);
$galleryImages = array_map(fn($path) => 'gallery/' . basename($path), $galleryFiles);
?>
<!DOCTYPE html>
<!-- Build: 2026-08-01-A -->
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
      <p class="gallery-intro">Click any image to view it full size.</p>

      <div id="gallery-status" class="gallery-status">Loading gallery...</div>
      <div id="gallery-grid" class="gallery-grid" aria-live="polite"></div>
    </div>
  </div>

  <div id="lightbox" class="lightbox" hidden>
    <button
      id="lightbox-close"
      class="lightbox-close"
      type="button"
      aria-label="Close image view"
    >
      ×
    </button>
    <img id="lightbox-image" class="lightbox-image" alt="" />
  </div>

  <script>
    const menuToggle = document.querySelector('.menu-toggle');
    const navLinks = document.querySelector('.nav-links');

    menuToggle.addEventListener('click', () => {
      navLinks.classList.toggle('show');
    });

    const galleryGrid = document.getElementById('gallery-grid');
    const galleryStatus = document.getElementById('gallery-status');
    const lightbox = document.getElementById('lightbox');
    const lightboxImage = document.getElementById('lightbox-image');
    const lightboxClose = document.getElementById('lightbox-close');

    /* Populated server-side from /gallery via glob() in gallery.php -
       nothing to edit here anymore. See the PHP block at the top of
       this file if you ever need to change sort order or file types. */
    const imageFiles = <?php echo json_encode($galleryImages); ?>;

    function openLightbox(src, altText) {
      lightboxImage.src = src;
      lightboxImage.alt = altText || 'Gallery image';
      lightbox.hidden = false;
      document.body.classList.add('lightbox-open');
    }

    function closeLightbox() {
      lightbox.hidden = true;
      lightboxImage.src = '';
      lightboxImage.alt = '';
      document.body.classList.remove('lightbox-open');
    }

    lightboxClose.addEventListener('click', closeLightbox);

    lightbox.addEventListener('click', (event) => {
      if (event.target === lightbox) {
        closeLightbox();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !lightbox.hidden) {
        closeLightbox();
      }
    });

    function loadGallery() {
      if (!imageFiles.length) {
        galleryStatus.textContent = 'No images were found.';
        return;
      }

      galleryStatus.hidden = true;

      imageFiles.forEach((src, index) => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'gallery-item';
        item.setAttribute('aria-label', `Open image ${index + 1}`);

        const image = document.createElement('img');
        image.src = src;
        image.alt = `Gallery image ${index + 1}`;
        image.className = 'gallery-thumb';
        image.loading = 'lazy';

        item.appendChild(image);
        item.addEventListener('click', () => openLightbox(src, image.alt));
        galleryGrid.appendChild(item);
      });
    }

    loadGallery();
  </script>
</body>
</html>
