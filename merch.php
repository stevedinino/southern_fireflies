<?php require __DIR__ . '/pricing.php'; require __DIR__ . '/config.php'; require_once __DIR__ . '/strings.php'; // Build: 2026-08-20-C ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Merchandise – Southern Fireflies Retreats</title>
  <link rel="icon" href="images/favicon.png" type="image/png" />
  <link rel="stylesheet" href="styles/layout.css" />
</head>
<body class="merch-page">
  <header>
    <div class="nav-wrapper">
      <button class="menu-toggle" aria-label="Toggle menu">&#9776;</button>
      <nav class="main-nav">
        <ul class="nav-links">
          <li><a href="index.php">Home</a></li>
          <li><a href="merch.php" aria-current="page">Merch</a></li>
          <li><a href="cancellation.php">Our Policies</a></li>
          <li><a href="gallery.php">Gallery</a></li>
          <li><a href="about.php">About</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <!-- Page banner - sits above the announcement block, before any merch
       content. Styling lives in layout.css (.merch-banner-wrapper /
       .merch-banner-img) alongside the rest of the merch page rules. -->
  <div class="merch-banner-wrapper">
    <img
      src="images/mr_firefly_banner.jpg"
      alt="Mr. Firefly's 3D Printed Gadgets"
      class="merch-banner-img"
    />
  </div>

  <!-- Wrapped in its own .content-wrapper so this gets the exact same
       max-width + centering + side-gutter behavior as every other boxed
       block on the page, instead of running full-bleed edge to edge. -->
  <div class="content-wrapper">
    <div class="demand-banner">
      <p><?= merch_load_string('pages/merch-banner') ?></p>
    </div>
  </div>

  <div class="content-wrapper">
    <div class="page-container merch-page-container">
      <?= merch_load_string('pages/merch-intro') ?>

      <!-- Both color notes share one box, stacked at the top, with their
           chart triggers side by side underneath. The Gildan garment
           colors and the 3D-print filament colors are still two entirely
           separate lists - each keeps its own chart image, its own note
           string, and its own <select> in the request modal (see
           GILDAN_COLOR_ITEMS/FILAMENT_COLOR_ITEMS below) - only the
           layout is merged. -->
      <div class="merch-color-note">
        <p><?= merch_load_string('pages/merch-color-note') ?></p>
        <p><?= merch_load_string('pages/merch-filament-color-note') ?></p>

        <div class="merch-color-chart-row">
          <button type="button" id="gildan-color-chart-open" class="merch-color-chart-trigger">
            <img src="images/color-chart-thumb.jpg" alt="Gildan 64000 color chart - click to view full size" />
            <span>Tap to view shirt &amp; hat color chart</span>
          </button>

          <button type="button" id="filament-color-chart-open" class="merch-color-chart-trigger">
            <img src="images/filament-color-chart-thumb.jpg" alt="3D print filament color chart - click to view full size" />
            <span>Tap to view filament color chart</span>
          </button>
        </div>
      </div>

      <div class="merch-grid">

        <?php
        // Stars & Stripes color-sample photo (Steve, 2026-08-20) - one
        // photo today, uploaded under this item's own filename rather
        // than reused across the four eligible items' cards, specifically
        // so a future individual photo (Steve: "I need to take more")
        // only ever means replacing/growing THIS array, never touching
        // shared code. Same data-gallery mechanism as $tapeGunGallery
        // below - just starting from one entry instead of several.
        $rectangleStarsStripesGallery = [
            ['src' => 'images/rectangle-cutter-holder-stars-stripes.jpg', 'alt' => 'Rectangle Cutter Holder - Stars & Stripes red, white, and blue print'],
        ];
        $rectangleStarsStripesGalleryJson = htmlspecialchars(json_encode($rectangleStarsStripesGallery), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="merch-card">
          <div class="merch-video-wrapper">
            <video class="merch-video" controls playsinline preload="metadata" poster="images/rectangle-cutter-poster.jpg">
              <source src="images/rectangle-cutter-demo.mp4" type="video/mp4" />
              Your browser doesn't support embedded video.
              <a href="images/rectangle-cutter-demo.mp4">Download the video</a> instead.
            </video>
          </div>
          <h2>Rectangle Cutter Holder</h2>
          <p class="merch-desc"><?= merch_load_string('items/rectangle-cutter-holder') ?></p>
          <p class="merch-price"><?= merch_price_display('Rectangle Cutter Holder') ?></p>
          <button type="button" class="merch-color-sample-link" data-gallery="<?= $rectangleStarsStripesGalleryJson ?>">Stars &amp; Stripes example (+$7) &rarr;</button>
          <button type="button" class="btn full-width merch-request-btn" data-item="Rectangle Cutter Holder">Request This Item</button>
        </div>

        <?php
        // Tape Gun Holder has several product photos instead of one, so its
        // card image opens as a scrollable gallery (see openPhotoGallery()
        // in the script below) rather than a single fixed zoom image. The
        // data-gallery attribute carries the full photo list as JSON, built
        // here with json_encode()+htmlspecialchars() rather than typed out
        // by hand in the HTML, so a stray quote in a filename or alt text
        // can never break the attribute.
        $tapeGunGallery = [
            ['src' => 'images/tape-gun-holder-1.jpg', 'alt' => 'Tape Gun Holder - front view with tape gun in place'],
            ['src' => 'images/tape-gun-holder-2.jpg', 'alt' => 'Tape Gun Holder - angled view showing the cap slot'],
            ['src' => 'images/tape-gun-holder-3.jpg', 'alt' => 'Tape Gun Holder - tape gun resting in the stand from above'],
            ['src' => 'images/tape-gun-holder-4.jpg', 'alt' => 'Tape Gun Holder - side view with tape gun and cap'],
            ['src' => 'images/tape-gun-holder-5.jpg', 'alt' => 'Tape Gun Holder - empty stand showing the cap slot detail'],
            // Stars & Stripes color sample (Steve, 2026-08-20) - own file,
            // just appended to this item's existing gallery array, same
            // as every other photo above.
            ['src' => 'images/tape-gun-holder-stars-stripes.jpg', 'alt' => 'Tape Gun Holder - Stars & Stripes red, white, and blue print'],
        ];
        $tapeGunGalleryJson = htmlspecialchars(json_encode($tapeGunGallery), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="merch-card">
          <img src="images/tape-gun-holder-1.jpg" alt="Tape Gun Holder - canoe-shaped stand for a Creative Memories tape gun" class="merch-photo" data-gallery="<?= $tapeGunGalleryJson ?>" />
          <h2>Tape Gun Holder</h2>
          <p class="merch-desc"><?= merch_load_string('items/tape-gun-holder') ?></p>
          <p class="merch-price"><?= merch_price_display('Tape Gun Holder') ?></p>
          <button type="button" class="btn full-width merch-request-btn" data-item="Tape Gun Holder">Request This Item</button>
        </div>

        <?php
        $circleStarsStripesGallery = [
            ['src' => 'images/circle-cutter-holder-stars-stripes.jpg', 'alt' => 'Circle Cutter Holder - Stars & Stripes red, white, and blue print'],
        ];
        $circleStarsStripesGalleryJson = htmlspecialchars(json_encode($circleStarsStripesGallery), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="merch-card">
          <div class="merch-video-wrapper">
            <video class="merch-video" controls playsinline preload="metadata" poster="images/circle-cutter-poster.jpg">
              <source src="images/circle-cutter-demo.mp4" type="video/mp4" />
              Your browser doesn't support embedded video.
              <a href="images/circle-cutter-demo.mp4">Download the video</a> instead.
            </video>
          </div>
          <h2>Circle Cutter Holder</h2>
          <p class="merch-desc"><?= merch_load_string('items/circle-cutter-holder') ?></p>
          <p class="merch-price"><?= merch_price_display('Circle Cutter Holder') ?></p>
          <button type="button" class="merch-color-sample-link" data-gallery="<?= $circleStarsStripesGalleryJson ?>">Stars &amp; Stripes example (+$7) &rarr;</button>
          <button type="button" class="btn full-width merch-request-btn" data-item="Circle Cutter Holder">Request This Item</button>
        </div>

        <?php
        // Was a single fixed photo until 2026-08-20 - now a 2-photo
        // gallery (same mechanism as $tapeGunGallery/$circleStarsStripes
        // Gallery above) so the Stars & Stripes sample can be browsed to
        // from the same main photo instead of a separate small thumbnail
        // like the video-based cards get. The displayed card image stays
        // the original photo; startIndex 0 in the click handler below
        // means clicking it still opens on the original first, same as
        // before this change.
        $ovalGallery = [
            ['src' => 'images/oval-cutter-holder.png', 'alt' => 'Oval Cutter Holder - organizer for Creative Memories oval cutting templates'],
            ['src' => 'images/oval-cutter-holder-stars-stripes.jpg', 'alt' => 'Oval Cutter Holder - Stars & Stripes red, white, and blue print'],
        ];
        $ovalGalleryJson = htmlspecialchars(json_encode($ovalGallery), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="merch-card">
          <img src="images/oval-cutter-holder.png" alt="Oval Cutter Holder - organizer for Creative Memories oval cutting templates" class="merch-photo" data-gallery="<?= $ovalGalleryJson ?>" />
          <h2>Oval Cutter Holder</h2>
          <p class="merch-desc"><?= merch_load_string('items/oval-cutter-holder') ?></p>
          <p class="merch-price"><?= merch_price_display('Oval Cutter Holder') ?></p>
          <button type="button" class="btn full-width merch-request-btn" data-item="Oval Cutter Holder">Request This Item</button>
        </div>

        <div class="merch-card">
          <img src="images/tool-holder.png" alt="Tool Holder Stand - organizer for scrapbooking tools, shown in pink and blue" class="merch-photo" />
          <h2>Tool Holder Stand</h2>
          <p class="merch-desc"><?= merch_load_string('items/tool-holder-stand') ?></p>
          <p class="merch-price"><?= merch_price_display('Tool Holder Stand') ?></p>
          <button type="button" class="btn full-width merch-request-btn" data-item="Tool Holder Stand">Request This Item</button>
        </div>

        <div class="merch-card">
          <img src="images/logo-shirt.jpg" alt="Logo Shirt - short sleeve front, long sleeve front, and long sleeve back with Southern Fireflies logo" class="merch-photo merch-photo-wide" />
          <h2>Logo Shirt</h2>
          <p class="merch-desc"><?= merch_load_string('items/logo-shirt') ?></p>
          <p class="merch-price"><?= merch_price_display('Logo Shirt') ?></p>
          <button type="button" class="btn full-width merch-request-btn" data-item="Logo Shirt">Request This Item</button>
        </div>

        <div class="merch-card">
          <img src="images/compass-shirt.jpg" alt="Finding Your Way Shirt - navy long sleeve with small front logo and compass design on back" class="merch-photo merch-photo-wide" />
          <h2>Finding Your Way Shirt</h2>
          <p class="merch-desc"><?= merch_load_string('items/finding-your-way-shirt') ?></p>
          <p class="merch-price"><?= merch_price_display('Finding Your Way Shirt') ?></p>
          <button type="button" class="btn full-width merch-request-btn" data-item="Finding Your Way Shirt">Request This Item</button>
        </div>

        <div class="merch-card">
          <img src="images/mr-firefly-shirt.jpg" alt="Mr. Firefly Shirt - heather tan shirt with 3D Printed Gadgets workshop scene on the back and small front pocket logo" class="merch-photo merch-photo-wide" />
          <h2>Mr. Firefly Shirt</h2>
          <p class="merch-desc"><?= merch_load_string('items/mr-firefly-shirt') ?></p>
          <p class="merch-price"><?= merch_price_display('Mr. Firefly Shirt') ?></p>
          <button type="button" class="btn full-width merch-request-btn" data-item="Mr. Firefly Shirt">Request This Item</button>
        </div>

        <div class="merch-card">
          <img src="images/hat.png" alt="Logo Hat - gray adjustable strap hat with Southern Fireflies logo" class="merch-photo" />
          <h2>Logo Hat</h2>
          <p class="merch-desc"><?= merch_load_string('items/logo-hat') ?></p>
          <p class="merch-price"><?= merch_price_display('Logo Hat') ?></p>
          <button type="button" class="btn full-width merch-request-btn" data-item="Logo Hat">Request This Item</button>
        </div>

        <div class="merch-card">
          <img src="images/merch-placeholder.jpg" alt="More items still baking - photos coming soon" class="merch-photo" />
          <h2>More Coming Soon</h2>
          <p class="merch-desc"><?= merch_load_string('items/more-coming-soon') ?></p>
          <p class="merch-price">&nbsp;</p>
        </div>

      </div>

    </div>
  </div>

  <!-- Photo viewer - shared by the color chart and product photos, informational only, not tied to ordering.
       Prev/next/counter are hidden by default and only shown when opened as a
       gallery (an image with a data-gallery attribute) - a plain single photo
       or the color chart still opens exactly as before, no arrows shown. -->
  <div id="photo-viewer-modal" class="lightbox" hidden>
    <button id="photo-viewer-close" class="lightbox-close" type="button" aria-label="Close photo viewer">&times;</button>
    <button id="photo-viewer-prev" class="lightbox-nav lightbox-nav-prev" type="button" aria-label="Previous photo" hidden>&#10094;</button>
    <img id="photo-viewer-image" src="" alt="" class="lightbox-image" />
    <button id="photo-viewer-next" class="lightbox-nav lightbox-nav-next" type="button" aria-label="Next photo" hidden>&#10095;</button>
    <div id="photo-viewer-counter" class="photo-viewer-counter" hidden></div>
  </div>

  <!-- Request modal: captures full shipping info since most requests now ship rather than get picked up at a retreat -->
  <div id="merch-modal" class="lightbox merch-modal" hidden>
    <button id="merch-modal-close" class="lightbox-close" type="button" aria-label="Close request form">&times;</button>
    <div class="merch-modal-content">
      <h2>Request: <span id="merch-modal-item"></span></h2>

      <form action="merch_order.php" method="POST" id="merch-form">
        <input type="hidden" name="item" id="merch-item-field" value="" />

        <input type="text" name="name" placeholder="Full Name" required />

        <label for="merch-fulfillment" class="four-day-label">How would you like to receive this?</label>
        <select name="fulfillment" id="merch-fulfillment" required>
          <option value="Ship" selected>Ship to me</option>
          <option value="Pickup at retreat">I'll pick it up at a retreat</option>
        </select>

        <div id="shipping-fields">
          <input type="text" name="address" id="merch-address" placeholder="Street Address" required />
          <div class="address-row">
            <input type="text" name="city" id="merch-city" placeholder="City" required />
            <select name="state" id="merch-state" required>
              <option value="" selected disabled>Select a state&hellip;</option>
              <option value="AL">Alabama</option>
              <option value="AZ">Arizona</option>
              <option value="AR">Arkansas</option>
              <option value="CA">California</option>
              <option value="CO">Colorado</option>
              <option value="CT">Connecticut</option>
              <option value="DE">Delaware</option>
              <option value="DC">District of Columbia</option>
              <option value="FL">Florida</option>
              <option value="GA">Georgia</option>
              <option value="ID">Idaho</option>
              <option value="IL">Illinois</option>
              <option value="IN">Indiana</option>
              <option value="IA">Iowa</option>
              <option value="KS">Kansas</option>
              <option value="KY">Kentucky</option>
              <option value="LA">Louisiana</option>
              <option value="ME">Maine</option>
              <option value="MD">Maryland</option>
              <option value="MA">Massachusetts</option>
              <option value="MI">Michigan</option>
              <option value="MN">Minnesota</option>
              <option value="MS">Mississippi</option>
              <option value="MO">Missouri</option>
              <option value="MT">Montana</option>
              <option value="NE">Nebraska</option>
              <option value="NV">Nevada</option>
              <option value="NH">New Hampshire</option>
              <option value="NJ">New Jersey</option>
              <option value="NM">New Mexico</option>
              <option value="NY">New York</option>
              <option value="NC">North Carolina</option>
              <option value="ND">North Dakota</option>
              <option value="OH">Ohio</option>
              <option value="OK">Oklahoma</option>
              <option value="OR">Oregon</option>
              <option value="PA">Pennsylvania</option>
              <option value="RI">Rhode Island</option>
              <option value="SC">South Carolina</option>
              <option value="SD">South Dakota</option>
              <option value="TN">Tennessee</option>
              <option value="TX">Texas</option>
              <option value="UT">Utah</option>
              <option value="VT">Vermont</option>
              <option value="VA">Virginia</option>
              <option value="WA">Washington</option>
              <option value="WV">West Virginia</option>
              <option value="WI">Wisconsin</option>
              <option value="WY">Wyoming</option>
            </select>
            <input type="text" name="zip" id="merch-zip" placeholder="ZIP" required />
          </div>
        </div>

        <input type="email" name="email" placeholder="Email Address" required />
        <input type="tel" name="phone" placeholder="Phone Number (optional)" />

        <div id="size-field-wrapper" hidden>
          <label for="merch-size" class="four-day-label">Size</label>
          <select name="size" id="merch-size">
            <option value="">Select a size&hellip;</option>
            <option value="S">S</option>
            <option value="M">M</option>
            <option value="L">L</option>
            <option value="XL">XL</option>
            <option value="2XL">2XL</option>
            <option value="3XL">3XL (+$3)</option>
            <option value="4XL">4XL (+$3)</option>
            <option value="5XL">5XL (+$3)</option>
          </select>
        </div>

        <div id="sleeve-field-wrapper" hidden>
          <label for="merch-sleeve" class="four-day-label">Sleeve Length</label>
          <select name="sleeve" id="merch-sleeve">
            <option value="">Select sleeve length&hellip;</option>
            <option value="Short Sleeve">Short Sleeve</option>
            <option value="Long Sleeve">Long Sleeve</option>
          </select>
        </div>

        <div id="color-field-wrapper" hidden>
          <label for="merch-color-gildan" class="four-day-label" id="color-field-label">Color choice</label>

          <!-- Gildan garment colors - shirts and the hat. The wrapper div
               (not the <select> itself) is what gets hidden in JS - a bare
               [hidden] select loses out to the ".merch-modal-content select
               { display: block }" rule below on specificity, so it has to be
               a div like the size/sleeve fields use. disabled/required still
               go on the <select> directly so only one of the two selects
               (see GILDAN_COLOR_ITEMS/FILAMENT_COLOR_ITEMS below) is ever
               part of the form submission - both share name="color". -->
          <div id="gildan-color-wrapper">
          <select name="color" id="merch-color-gildan">
              <option value="">Select a color&hellip;</option>
              <optgroup label="Whites & Grays">
                <option value="#01 White">#01 &ndash; White</option>
                <option value="#02 Ice Gray">#02 &ndash; Ice Gray</option>
                <option value="#03 Sport Gray">#03 &ndash; Sport Gray</option>
                <option value="#06 Graphite Heather">#06 &ndash; Graphite Heather</option>
                <option value="#07 Dark Heather">#07 &ndash; Dark Heather</option>
                <option value="#08 Charcoal">#08 &ndash; Charcoal</option>
              </optgroup>
              <optgroup label="Naturals">
                <option value="#04 Natural">#04 &ndash; Natural</option>
                <option value="#05 Sand">#05 &ndash; Sand</option>
              </optgroup>
              <optgroup label="Yellows & Gold">
                <option value="#09 Cornsilk">#09 &ndash; Cornsilk</option>
                <option value="#10 Daisy">#10 &ndash; Daisy</option>
                <option value="#11 Gold">#11 &ndash; Gold</option>
              </optgroup>
              <optgroup label="Oranges">
                <option value="#12 Heather Orange">#12 &ndash; Heather Orange</option>
                <option value="#13 Orange">#13 &ndash; Orange</option>
              </optgroup>
              <optgroup label="Pinks">
                <option value="#14 Light Pink">#14 &ndash; Light Pink</option>
                <option value="#15 Azalea">#15 &ndash; Azalea</option>
                <option value="#16 Coral Silk">#16 &ndash; Coral Silk</option>
                <option value="#17 Heather Coral Silk">#17 &ndash; Heather Coral Silk</option>
                <option value="#18 Heather Heliconia">#18 &ndash; Heather Heliconia</option>
                <option value="#19 Heliconia">#19 &ndash; Heliconia</option>
                <option value="#20 Antique Heliconia">#20 &ndash; Antique Heliconia</option>
              </optgroup>
              <optgroup label="Reds & Maroons">
                <option value="#21 Heather Bronze">#21 &ndash; Heather Bronze</option>
                <option value="#22 Berry">#22 &ndash; Berry</option>
                <option value="#23 Heather Maroon">#23 &ndash; Heather Maroon</option>
                <option value="#24 Heather Red">#24 &ndash; Heather Red</option>
                <option value="#25 Antique Cherry Red">#25 &ndash; Antique Cherry Red</option>
                <option value="#26 Cherry Red">#26 &ndash; Cherry Red</option>
                <option value="#27 Heather Cardinal">#27 &ndash; Heather Cardinal</option>
                <option value="#28 Red">#28 &ndash; Red</option>
                <option value="#29 Maroon">#29 &ndash; Maroon</option>
                <option value="#30 Cardinal">#30 &ndash; Cardinal</option>
              </optgroup>
              <optgroup label="Greens">
                <option value="#31 Pistachio">#31 &ndash; Pistachio</option>
                <option value="#32 Mint Green">#32 &ndash; Mint Green</option>
                <option value="#33 Lime">#33 &ndash; Lime</option>
                <option value="#34 Heather Military Green">#34 &ndash; Heather Military Green</option>
                <option value="#35 Heather Seafoam">#35 &ndash; Heather Seafoam</option>
                <option value="#36 Sage">#36 &ndash; Sage</option>
                <option value="#37 Heather Irish Green">#37 &ndash; Heather Irish Green</option>
                <option value="#38 Kiwi">#38 &ndash; Kiwi</option>
                <option value="#39 Electric Green">#39 &ndash; Electric Green</option>
                <option value="#40 Olive">#40 &ndash; Olive</option>
                <option value="#41 Military Green">#41 &ndash; Military Green</option>
                <option value="#42 Kelly Green">#42 &ndash; Kelly Green</option>
                <option value="#43 Jade Dome">#43 &ndash; Jade Dome</option>
                <option value="#44 Heather Forest Green">#44 &ndash; Heather Forest Green</option>
                <option value="#45 Irish Green">#45 &ndash; Irish Green</option>
                <option value="#46 Forest">#46 &ndash; Forest</option>
              </optgroup>
              <optgroup label="Blues">
                <option value="#47 Light Blue">#47 &ndash; Light Blue</option>
                <option value="#48 Iris">#48 &ndash; Iris</option>
                <option value="#49 Sky">#49 &ndash; Sky</option>
                <option value="#50 Carolina Blue">#50 &ndash; Carolina Blue</option>
                <option value="#51 Antique Sapphire">#51 &ndash; Antique Sapphire</option>
                <option value="#52 Heather Indigo">#52 &ndash; Heather Indigo</option>
                <option value="#53 Sapphire">#53 &ndash; Sapphire</option>
                <option value="#54 Indigo">#54 &ndash; Indigo</option>
                <option value="#55 Heather Galapagos Blue">#55 &ndash; Heather Galapagos Blue</option>
                <option value="#56 Metro Blue">#56 &ndash; Metro Blue</option>
                <option value="#57 Heather Sapphire">#57 &ndash; Heather Sapphire</option>
                <option value="#58 Tropical Blue">#58 &ndash; Tropical Blue</option>
                <option value="#59 Heather Royal">#59 &ndash; Heather Royal</option>
                <option value="#60 Royal">#60 &ndash; Royal</option>
                <option value="#61 Heather Navy">#61 &ndash; Heather Navy</option>
                <option value="#62 Navy">#62 &ndash; Navy</option>
              </optgroup>
              <optgroup label="Purples">
                <option value="#63 Heather Berry">#63 &ndash; Heather Berry</option>
                <option value="#64 Heather Radiant Orchid">#64 &ndash; Heather Radiant Orchid</option>
                <option value="#65 Heather Purple">#65 &ndash; Heather Purple</option>
                <option value="#66 Purple">#66 &ndash; Purple</option>
                <option value="#67 Blackberry">#67 &ndash; Blackberry</option>
                <option value="#68 Paragon">#68 &ndash; Paragon</option>
              </optgroup>
              <optgroup label="Darks">
                <option value="#69 Dark Chocolate">#69 &ndash; Dark Chocolate</option>
                <option value="#70 Black">#70 &ndash; Black</option>
              </optgroup>
              <option value="Not applicable / no color choice">Not applicable</option>
          </select>
          </div>

          <!-- 3D-print filament colors - the cutter holders, tape gun holder,
               and tool holder stand. Numbering/grouping here matches
               filament-color-chart.jpg exactly (#1 Red through #27 Brown) -
               keep the two in sync if the chart is ever renumbered. Rainbow
               lives here (not in the Gildan list above) since it's a filament
               print option, not a garment color; RAINBOW_ELIGIBLE_ITEMS in
               pricing.php controls which items actually see it. -->
          <div id="filament-color-wrapper" hidden>
          <select name="color" id="merch-color-filament" disabled>
              <option value="">Select a color&hellip;</option>
              <optgroup label="Warm Colors">
                <option value="#01 Red">#01 &ndash; Red</option>
                <option value="#02 Coral">#02 &ndash; Coral</option>
                <option value="#03 Maroon">#03 &ndash; Maroon</option>
                <option value="#04 Orange">#04 &ndash; Orange</option>
                <option value="#05 Silk Orange">#05 &ndash; Silk Orange</option>
                <option value="#06 Yellow">#06 &ndash; Yellow</option>
                <option value="#07 Gold">#07 &ndash; Gold</option>
                <option value="#08 Hot Pink">#08 &ndash; Hot Pink</option>
                <option value="#09 Magenta">#09 &ndash; Magenta</option>
              </optgroup>
              <optgroup label="Cool Colors">
                <option value="#10 Light Pink">#10 &ndash; Light Pink</option>
                <option value="#11 Plum">#11 &ndash; Plum</option>
                <option value="#12 Purple">#12 &ndash; Purple</option>
                <option value="#13 Lilac">#13 &ndash; Lilac</option>
                <option value="#14 Sky Blue">#14 &ndash; Sky Blue</option>
                <option value="#15 CM Blue">#15 &ndash; CM Blue</option>
                <option value="#16 Navy Blue">#16 &ndash; Navy Blue</option>
                <option value="#17 Teal">#17 &ndash; Teal</option>
                <option value="#18 Silk Green">#18 &ndash; Silk Green</option>
              </optgroup>
              <optgroup label="Greens & Neutrals">
                <option value="#19 Green">#19 &ndash; Green</option>
                <option value="#20 Light Green">#20 &ndash; Light Green</option>
                <option value="#21 Olive Green">#21 &ndash; Olive Green</option>
                <option value="#22 Black">#22 &ndash; Black</option>
                <option value="#23 Gray">#23 &ndash; Gray</option>
                <option value="#24 Ice">#24 &ndash; Ice</option>
                <option value="#25 White">#25 &ndash; White</option>
                <option value="#26 Tan">#26 &ndash; Tan</option>
                <option value="#27 Brown">#27 &ndash; Brown</option>
              </optgroup>
              <option value="Rainbow (+$2)" id="color-option-rainbow" hidden>Rainbow (+$2)</option>
              <option value="Stars &amp; Stripes (+$7)" id="color-option-stars-stripes" hidden>Stars &amp; Stripes (+$7)</option>
              <option value="Not applicable / no color choice">Not applicable</option>
          </select>
          </div>
        </div>

        <label for="merch-quantity" class="four-day-label">Quantity</label>
        <input type="number" name="quantity" id="merch-quantity" min="1" max="<?= MAX_QUANTITY ?>" value="1" required />

        <textarea name="message" placeholder="Notes - anything else we should know?" rows="3" maxlength="<?= NOTES_MAX_LENGTH ?>"></textarea>

        <div id="merch-estimate" class="merch-estimate"></div>

        <button type="submit" class="btn full-width">Submit Request</button>
      </form>
    </div>
  </div>

  <script>
    // Same pricing data pricing.php uses server-side, handed to JS so the
    // estimate below updates live without a round trip to the server.
    // Note: this shares the *numbers*, not the calculation code itself -
    // calculateEstimate() below mirrors merch_calculate()'s arithmetic by
    // hand. If you ever change the surcharge *rules* (not just a price),
    // update both places.
    const MERCH_PRICING = <?php echo json_encode(merch_pricing_for_js()); ?>;

    const menuToggle = document.querySelector('.menu-toggle');
    const navLinks = document.querySelector('.nav-links');
    menuToggle.addEventListener('click', () => {
      navLinks.classList.toggle('show');
    });

    // Photo viewer - shared by color chart button + every product photo (informational, no request tied to it)
    const photoViewerModal = document.getElementById('photo-viewer-modal');
    const photoViewerImage = document.getElementById('photo-viewer-image');
    const photoViewerClose = document.getElementById('photo-viewer-close');
    const photoViewerPrev = document.getElementById('photo-viewer-prev');
    const photoViewerNext = document.getElementById('photo-viewer-next');
    const photoViewerCounter = document.getElementById('photo-viewer-counter');
    const gildanColorChartOpen = document.getElementById('gildan-color-chart-open');
    const filamentColorChartOpen = document.getElementById('filament-color-chart-open');

    // Gallery state - empty array means "single image, no nav arrows."
    // A single openPhotoViewer(src, alt) call (no gallery array) behaves
    // exactly as it always has; passing a gallery array is what turns on
    // the prev/next/counter UI. Nothing about existing single-photo
    // cards changes unless they're given a data-gallery attribute.
    let galleryImages = [];
    let galleryIndex = 0;

    function renderGalleryImage() {
      const src = galleryImages[galleryIndex];
      photoViewerImage.src = src.src;
      photoViewerImage.alt = src.alt || '';
      photoViewerCounter.textContent = `${galleryIndex + 1} / ${galleryImages.length}`;
    }

    function openPhotoViewer(src, alt) {
      galleryImages = [];
      photoViewerImage.src = src;
      photoViewerImage.alt = alt || '';
      photoViewerPrev.hidden = true;
      photoViewerNext.hidden = true;
      photoViewerCounter.hidden = true;
      photoViewerModal.hidden = false;
      document.body.classList.add('lightbox-open');
    }

    // images: array of {src, alt}. startIndex: which one to open on.
    function openPhotoGallery(images, startIndex) {
      galleryImages = images;
      galleryIndex = startIndex || 0;
      renderGalleryImage();
      const hasMultiple = galleryImages.length > 1;
      photoViewerPrev.hidden = !hasMultiple;
      photoViewerNext.hidden = !hasMultiple;
      photoViewerCounter.hidden = !hasMultiple;
      photoViewerModal.hidden = false;
      document.body.classList.add('lightbox-open');
    }

    function showPrevPhoto() {
      if (!galleryImages.length) return;
      galleryIndex = (galleryIndex - 1 + galleryImages.length) % galleryImages.length;
      renderGalleryImage();
    }

    function showNextPhoto() {
      if (!galleryImages.length) return;
      galleryIndex = (galleryIndex + 1) % galleryImages.length;
      renderGalleryImage();
    }

    function closePhotoViewer() {
      photoViewerModal.hidden = true;
      photoViewerImage.src = '';
      galleryImages = [];
      document.body.classList.remove('lightbox-open');
    }

    gildanColorChartOpen.addEventListener('click', () => {
      openPhotoViewer('images/color-chart.jpg', 'Gildan 64000 color chart with numbered swatches');
    });

    filamentColorChartOpen.addEventListener('click', () => {
      openPhotoViewer('images/filament-color-chart.jpg', 'Filament color chart with numbered, named swatches');
    });

    // Every product photo on the page opens the zoom viewer when clicked.
    // A photo with a data-gallery attribute (JSON array of {src, alt})
    // opens as a scrollable gallery instead of a single fixed image -
    // used for items with several product shots, like the Tape Gun Holder.
    document.querySelectorAll('.merch-photo').forEach((img) => {
      img.classList.add('zoomable');
      img.addEventListener('click', () => {
        if (img.dataset.gallery) {
          const images = JSON.parse(img.dataset.gallery);
          openPhotoGallery(images, 0);
        } else {
          openPhotoViewer(img.src, img.alt);
        }
      });
    });

    // Text-link version of the same gallery trigger (2026-08-20, Stars &
    // Stripes on the video-only cutter-holder cards) - same
    // openPhotoGallery() call as above, just off a <button data-gallery>
    // instead of an <img>, since there's no photo to show as the card's
    // own thumbnail on those cards.
    document.querySelectorAll('.merch-color-sample-link').forEach((btn) => {
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
      if (event.key === 'ArrowLeft') showPrevPhoto();
      if (event.key === 'ArrowRight') showNextPhoto();
    });

    // Item request modal
    const merchModal = document.getElementById('merch-modal');
    const merchModalItem = document.getElementById('merch-modal-item');
    const merchItemField = document.getElementById('merch-item-field');
    const merchModalClose = document.getElementById('merch-modal-close');
    const colorFieldWrapper = document.getElementById('color-field-wrapper');
    const colorFieldLabel = document.getElementById('color-field-label');
    const gildanColorWrapper = document.getElementById('gildan-color-wrapper');
    const merchColorGildan = document.getElementById('merch-color-gildan');
    const filamentColorWrapper = document.getElementById('filament-color-wrapper');
    const merchColorFilament = document.getElementById('merch-color-filament');
    const sizeFieldWrapper = document.getElementById('size-field-wrapper');
    const merchSizeSelect = document.getElementById('merch-size');
    const sleeveFieldWrapper = document.getElementById('sleeve-field-wrapper');
    const merchSleeveSelect = document.getElementById('merch-sleeve');
    const shippingFields = document.getElementById('shipping-fields');
    const merchAddress = document.getElementById('merch-address');
    const merchCity = document.getElementById('merch-city');
    const merchState = document.getElementById('merch-state');
    const merchZip = document.getElementById('merch-zip');
    const merchFulfillment = document.getElementById('merch-fulfillment');
    const merchQuantity = document.getElementById('merch-quantity');
    const merchEstimate = document.getElementById('merch-estimate');

    // Items that come in a choice of colors - two different color lists,
    // since garment colors (Gildan) and 3D-print filament colors are
    // completely different palettes with their own charts. A given item is
    // in exactly one of these two lists, never both.
    const GILDAN_COLOR_ITEMS = ['Logo Shirt', 'Finding Your Way Shirt', 'Mr. Firefly Shirt', 'Logo Hat'];
    const FILAMENT_COLOR_ITEMS = ['Tool Holder Stand', 'Circle Cutter Holder', 'Oval Cutter Holder', 'Rectangle Cutter Holder', 'Tape Gun Holder'];
    // Shirts need a size and a sleeve-length choice; nothing else does
    const SIZE_AND_SLEEVE_ITEMS = ['Logo Shirt', 'Finding Your Way Shirt', 'Mr. Firefly Shirt'];

    function updateShippingFieldsRequired() {
      const shipping = merchFulfillment.value === 'Ship';
      shippingFields.hidden = !shipping;
      [merchAddress, merchCity, merchState, merchZip].forEach((el) => {
        el.required = shipping;
      });
    }

    merchFulfillment.addEventListener('change', () => {
      updateShippingFieldsRequired();
      updateEstimate();
    });

    // Mirrors merch_calculate() in pricing.php - same rules, same numbers
    // (via MERCH_PRICING above), just running in the browser for a live
    // preview instead of at submit time.
    function calculateEstimate(item, quantity, size, sleeve, color, isShipping) {
      const cfg = MERCH_PRICING;
      const base = cfg.prices[item];
      if (base === undefined) return null;

      let unitPrice = (typeof base === 'object') ? (base[sleeve] ?? base['Short Sleeve']) : base;

      if (cfg.shirtItems.includes(item) && cfg.oversizeSurchargeSizes.includes(size)) {
        unitPrice += cfg.oversizeSurcharge;
      }
      if (cfg.rainbowEligibleItems.includes(item) && color === 'Rainbow (+$2)') {
        unitPrice += cfg.rainbowSurcharge;
      }
      if (cfg.starsStripesEligibleItems.includes(item) && color === 'Stars & Stripes (+$7)') {
        unitPrice += cfg.starsStripesSurcharge;
      }

      const subtotal = unitPrice * quantity;
      const tax = Math.round(subtotal * cfg.taxRate * 100) / 100;

      let shipping = null;
      let shippingNote = '';
      if (isShipping) {
        if (cfg.boxShippingItems.includes(item)) {
          // Printed items use box-capacity tiers, not the flat qty<=2
          // rule below - mirrors merch_printed_shipping() in pricing.php.
          // A single order line only ever has ONE item type, so this
          // only ever has to look at whichever count is non-zero.
          const isToolStand = item === cfg.toolStandItem;
          const toolStandQty = isToolStand ? quantity : 0;
          const mailerTierQty = cfg.mailerTierItems.includes(item) ? quantity : 0;
          const tapeGunQty = item === cfg.tapeGunItem ? quantity : 0;

          if (tapeGunQty > cfg.tapeGunMaxQty) {
            // Bulky-item rule checked first, same as server-side -
            // more than one Tape Gun Holder always needs hand-packing.
            shippingNote = cfg.shippingNotes.multipleTapeGuns;
          } else if (toolStandQty >= 2) {
            shippingNote = cfg.shippingNotes.multipleToolStands;
          } else if (toolStandQty === 1) {
            if (mailerTierQty <= cfg.circleOvalWithToolStandMax) {
              shipping = cfg.printedShipRateBox;
            } else if (mailerTierQty <= cfg.circleOvalWithToolStandExpandedMax) {
              shipping = cfg.printedShipRateBoxExpanded;
            } else {
              shippingNote = cfg.shippingNotes.toolStandPlusExtra;
            }
          } else if (mailerTierQty <= cfg.circleOvalMailerMax) {
            shipping = cfg.printedShipRateMailer;
          } else if (mailerTierQty <= cfg.circleOvalAloneMax) {
            shipping = cfg.printedShipRateBox;
          } else {
            shippingNote = cfg.shippingNotes.tooManyCircleOvalAlone;
          }
        } else if (quantity <= cfg.flatShippingMaxQty) {
          shipping = cfg.flatShippingRate;
        } else {
          shippingNote = cfg.shippingNotes.overFlatRateQty;
        }
      }

      const total = subtotal + tax + (shipping ?? 0);
      return { unitPrice, subtotal, tax, shipping, total, shippingNote };
    }

    function formatMoney(n) {
      return '$' + n.toFixed(2);
    }

    function updateEstimate() {
      const item = merchItemField.value;
      const quantity = Math.min(MERCH_PRICING.maxQuantity, Math.max(1, parseInt(merchQuantity.value, 10) || 1));
      const size = merchSizeSelect.value;
      const sleeve = merchSleeveSelect.value;
      // Only one of the two color selects is ever enabled at a time - read
      // whichever one is actually active (see openMerchModal() below).
      const color = FILAMENT_COLOR_ITEMS.includes(item) ? merchColorFilament.value : merchColorGildan.value;
      const isShipping = merchFulfillment.value === 'Ship';

      const est = calculateEstimate(item, quantity, size, sleeve, color, isShipping);
      if (!est) {
        merchEstimate.innerHTML = '';
        return;
      }

      let html = `<div>Subtotal: ${formatMoney(est.subtotal)} (${quantity} &times; ${formatMoney(est.unitPrice)})</div>`;
      html += `<div>Tax (7%): ${formatMoney(est.tax)}</div>`;
      if (est.shipping !== null) {
        html += `<div>Shipping: ${formatMoney(est.shipping)}</div>`;
        html += `<div class="merch-estimate-total">Estimated Total: ${formatMoney(est.total)}</div>`;
      } else if (isShipping) {
        html += `<div class="merch-estimate-note">${est.shippingNote}</div>`;
        html += `<div class="merch-estimate-total">Estimated Total (excl. shipping): ${formatMoney(est.total)}</div>`;
      } else {
        html += `<div class="merch-estimate-total">Estimated Total: ${formatMoney(est.total)}</div>`;
      }
      html += `<div class="merch-estimate-note" style="margin-top:6px;">Estimated total for this item only &mdash; if you're planning more than one request, we'll combine everything into a single total and shipping cost when we follow up.</div>`;
      merchEstimate.innerHTML = html;
    }

    [merchQuantity, merchSizeSelect, merchSleeveSelect, merchColorGildan, merchColorFilament].forEach((el) => {
      el.addEventListener('change', updateEstimate);
      el.addEventListener('input', updateEstimate);
    });

    function openMerchModal(itemName) {
      merchItemField.value = itemName;
      merchModalItem.textContent = itemName;

      const needsGildanColor = GILDAN_COLOR_ITEMS.includes(itemName);
      const needsFilamentColor = FILAMENT_COLOR_ITEMS.includes(itemName);
      const needsColor = needsGildanColor || needsFilamentColor;
      colorFieldWrapper.hidden = !needsColor;
      colorFieldLabel.setAttribute('for', needsFilamentColor ? 'merch-color-filament' : 'merch-color-gildan');

      // Only the select that matches this item stays enabled - the other
      // one's wrapper div is hidden (hiding the <select> itself doesn't work -
      // ".merch-modal-content select { display: block }" outranks a bare
      // [hidden] select on specificity) and the select is disabled so it's
      // left out of the form submission entirely (both share name="color").
      gildanColorWrapper.hidden = !needsGildanColor;
      merchColorGildan.disabled = !needsGildanColor;
      merchColorGildan.required = needsGildanColor;
      filamentColorWrapper.hidden = !needsFilamentColor;
      merchColorFilament.disabled = !needsFilamentColor;
      merchColorFilament.required = needsFilamentColor;

      const rainbowOption = document.getElementById('color-option-rainbow');
      rainbowOption.hidden = !MERCH_PRICING.rainbowEligibleItems.includes(itemName);
      const starsStripesOption = document.getElementById('color-option-stars-stripes');
      starsStripesOption.hidden = !MERCH_PRICING.starsStripesEligibleItems.includes(itemName);

      if (!needsGildanColor) {
        merchColorGildan.value = '';
      }
      if (
        !needsFilamentColor
        || (merchColorFilament.value === 'Rainbow (+$2)' && rainbowOption.hidden)
        || (merchColorFilament.value === 'Stars & Stripes (+$7)' && starsStripesOption.hidden)
      ) {
        merchColorFilament.value = '';
      }

      const needsSizeAndSleeve = SIZE_AND_SLEEVE_ITEMS.includes(itemName);
      sizeFieldWrapper.hidden = !needsSizeAndSleeve;
      merchSizeSelect.required = needsSizeAndSleeve;
      sleeveFieldWrapper.hidden = !needsSizeAndSleeve;
      merchSleeveSelect.required = needsSizeAndSleeve;
      if (!needsSizeAndSleeve) {
        merchSizeSelect.value = '';
        merchSleeveSelect.value = '';
      }

      // Default to "Ship to me" every time the modal opens for a new item
      merchFulfillment.value = 'Ship';
      updateShippingFieldsRequired();

      // Reset quantity to 1 so the estimate doesn't carry over from a
      // previous item's request in the same page visit
      merchQuantity.value = 1;
      updateEstimate();

      merchModal.hidden = false;
      document.body.classList.add('lightbox-open');
    }

    function closeMerchModal() {
      merchModal.hidden = true;
      document.body.classList.remove('lightbox-open');
    }

    document.querySelectorAll('.merch-request-btn').forEach((btn) => {
      btn.addEventListener('click', () => openMerchModal(btn.dataset.item));
    });

    merchModalClose.addEventListener('click', closeMerchModal);
    merchModal.addEventListener('click', (event) => {
      if (event.target === merchModal) closeMerchModal();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        if (!merchModal.hidden) closeMerchModal();
        if (!photoViewerModal.hidden) closePhotoViewer();
      }
    });

    // Disable the submit button the instant the form is submitted, so a
    // double-click (or an impatient double-tap on mobile) can't create
    // two order rows for one request. The form still submits normally -
    // this only blocks a second click during the brief window before the
    // page navigates away.
    const merchForm = document.getElementById('merch-form');
    const merchSubmitBtn = merchForm.querySelector('button[type="submit"]');
    merchForm.addEventListener('submit', () => {
      merchSubmitBtn.disabled = true;
      merchSubmitBtn.textContent = 'Submitting...';
    });
  </script>
</body>
</html>
