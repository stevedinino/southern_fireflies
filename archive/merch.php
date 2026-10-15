<?php require __DIR__ . '/pricing.php'; require __DIR__ . '/config.php'; // Build: 2026-07-26-D ?>
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
          <li><a href="index.html">Home</a></li>
          <li><a href="merch.php" aria-current="page">Merch</a></li>
          <li><a href="cancellation.html">Our Policies</a></li>
          <li><a href="gallery.html">Gallery</a></li>
          <li><a href="about.html">About</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <div class="demand-banner">
    <p>
      <b>How this works:</b> This isn't a shopping cart &mdash; it's a request form for handmade items.
      Submit a request for each item you'd like, and we'll follow up by email with your exact total
      (tax and shipping included) and payment instructions. Please don't send any payment until you get
      that email, since totals vary by combination. Everything's handmade to order, so please allow a
      couple of weeks, and we'll email you again the moment it ships.
    </p>
  </div>

  <div class="content-wrapper">
    <div class="page-container merch-page-container">
      <h1>Mr. Firefly's Merchandise and Scrapbooking Gadgets</h1>
      <p style="text-align:center;">T-shirts, hats, and handy tools we design ourselves &mdash; available by request.</p>
      <p style="text-align:center;"> Want one? Click "Request This Item" below, or ask about availability at any retreat.</p>

      <div class="merch-color-note">
        <p>For shirts, please include your <strong>size</strong>, <strong>color number</strong>, and (for the Logo Shirt) <strong>short or long sleeve</strong> in the notes field of your request.</p>
        <button type="button" id="color-chart-open" class="merch-color-chart-trigger">
          <img src="images/color-chart-thumb.jpg" alt="Gildan 64000 color chart - click to view full size" />
          <span>Tap to view color chart</span>
        </button>
      </div>

      <div class="merch-grid">

        <div class="merch-card">
          <img src="images/logo-shirt.jpg" alt="Logo Shirt - short sleeve front, long sleeve front, and long sleeve back with Southern Fireflies logo" class="merch-photo merch-photo-wide" />
          <h2>Logo Shirt</h2>
          <p class="merch-desc">Soft tee with our Southern Fireflies logo on the front (long sleeve also gets a larger version across the back). Available short or long sleeve. Shown in Sage &mdash; available in other Gildan 64000 colors, see chart.</p>
          <p class="merch-price"><?= merch_price_display('Logo Shirt') ?></p>
          <button type="button" class="btn full-width merch-request-btn" data-item="Logo Shirt">Request This Item</button>
        </div>

        <div class="merch-card">
          <img src="images/compass-shirt.jpg" alt="Finding Your Way Shirt - navy long sleeve with small front logo and compass design on back" class="merch-photo merch-photo-wide" />
          <h2>Finding Your Way Shirt</h2>
          <p class="merch-desc">Soft tee with a small logo on the front and a "Finding Your Way Home" compass design across the back &mdash; stories, creativity, friendship, and memories. Shown in Navy &mdash; available in other Gildan 64000 colors, see chart.</p>
          <p class="merch-price"><?= merch_price_display('Finding Your Way Shirt') ?></p>
          <button type="button" class="btn full-width merch-request-btn" data-item="Finding Your Way Shirt">Request This Item</button>
        </div>

        <div class="merch-card">
          <img src="images/hat.png" alt="Logo Hat - gray adjustable strap hat with Southern Fireflies logo" class="merch-photo" />
          <h2>Logo Hat</h2>
          <p class="merch-desc">Adjustable strap, one size fits all, with our Southern Fireflies logo. Shown in gray &mdash; other colors available, just ask.</p>
          <p class="merch-price"><?= merch_price_display('Logo Hat') ?></p>
          <button type="button" class="btn full-width merch-request-btn" data-item="Logo Hat">Request This Item</button>
        </div>

        <div class="merch-card">
          <img src="images/tool-holder.png" alt="Tool Holder Stand - organizer for scrapbooking tools, shown in pink and blue" class="merch-photo" />
          <h2>Tool Holder Stand</h2>
          <p class="merch-desc">3D-printed stand to keep your trimmers, rulers, and other tools organized and within reach. Available in a variety of colors &mdash; tell us a color number from the shirt chart and we'll match filament to it. Rainbow filament also available for an extra $2.</p>
          <p class="merch-price"><?= merch_price_display('Tool Holder Stand') ?></p>
          <button type="button" class="btn full-width merch-request-btn" data-item="Tool Holder Stand">Request This Item</button>
        </div>

        <div class="merch-card">
          <div class="merch-video-wrapper">
            <video class="merch-video" controls playsinline preload="metadata" poster="images/circle-cutter-poster.jpg">
              <source src="images/circle-cutter-demo.mp4" type="video/mp4" />
              Your browser doesn't support embedded video.
              <a href="images/circle-cutter-demo.mp4">Download the video</a> instead.
            </video>
          </div>
          <h2>Circle Cutter Holder</h2>
          <p class="merch-desc">Keeps your Creative Memories circle cutting templates stacked together. The rotating disk on top locks them in place so all three sizes stay put. Available in a variety of colors &mdash; tell us a color number from the shirt chart and we'll match filament to it. Rainbow filament also available for an extra $2.</p>
          <p class="merch-price"><?= merch_price_display('Circle Cutter Holder') ?></p>
          <button type="button" class="btn full-width merch-request-btn" data-item="Circle Cutter Holder">Request This Item</button>
        </div>

        <div class="merch-card">
          <img src="images/oval-cutter-holder.png" alt="Oval Cutter Holder - organizer for Creative Memories oval cutting templates" class="merch-photo" />
          <h2>Oval Cutter Holder</h2>
          <p class="merch-desc">Keeps your Creative Memories oval cutting templates stacked together. The rotating disk on top locks them in place so all three sizes stay put. Available in a variety of colors &mdash; tell us a color number from the shirt chart and we'll match filament to it. Rainbow filament also available for an extra $2.</p>
          <p class="merch-price"><?= merch_price_display('Oval Cutter Holder') ?></p>
          <button type="button" class="btn full-width merch-request-btn" data-item="Oval Cutter Holder">Request This Item</button>
        </div>

        <div class="merch-card">
          <img src="images/merch-placeholder.jpg" alt="More items still baking - photos coming soon" class="merch-photo" />
          <h2>More Coming Soon</h2>
          <p class="merch-desc">3D-printed scrapbooking gadgets are on their way &mdash; photos still baking.</p>
          <p class="merch-price">&nbsp;</p>
        </div>

      </div>

    </div>
  </div>

  <!-- Photo viewer - shared by the color chart and product photos, informational only, not tied to ordering -->
  <div id="photo-viewer-modal" class="lightbox" hidden>
    <button id="photo-viewer-close" class="lightbox-close" type="button" aria-label="Close photo viewer">&times;</button>
    <img id="photo-viewer-image" src="" alt="" class="lightbox-image" />
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
              <option value="AK">Alaska</option>
              <option value="AZ">Arizona</option>
              <option value="AR">Arkansas</option>
              <option value="CA">California</option>
              <option value="CO">Colorado</option>
              <option value="CT">Connecticut</option>
              <option value="DE">Delaware</option>
              <option value="DC">District of Columbia</option>
              <option value="FL">Florida</option>
              <option value="GA">Georgia</option>
              <option value="HI">Hawaii</option>
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
          <label for="merch-color" class="four-day-label">Color choice</label>
          <select name="color" id="merch-color">
              <option value="">Select a color&hellip;</option>
              <optgroup label="Whites & Grays">
                <option value="#1 White">#1 &ndash; White</option>
                <option value="#2 Ice Gray">#2 &ndash; Ice Gray</option>
                <option value="#3 Sport Gray">#3 &ndash; Sport Gray</option>
                <option value="#6 Graphite Heather">#6 &ndash; Graphite Heather</option>
                <option value="#7 Dark Heather">#7 &ndash; Dark Heather</option>
                <option value="#8 Charcoal">#8 &ndash; Charcoal</option>
              </optgroup>
              <optgroup label="Naturals">
                <option value="#4 Natural">#4 &ndash; Natural</option>
                <option value="#5 Sand">#5 &ndash; Sand</option>
              </optgroup>
              <optgroup label="Yellows & Gold">
                <option value="#9 Cornsilk">#9 &ndash; Cornsilk</option>
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
              <option value="Rainbow (+$2)" id="color-option-rainbow" hidden>Rainbow (+$2)</option>
              <option value="Not applicable / no color choice">Not applicable</option>
          </select>
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
    const colorChartOpen = document.getElementById('color-chart-open');

    function openPhotoViewer(src, alt) {
      photoViewerImage.src = src;
      photoViewerImage.alt = alt || '';
      photoViewerModal.hidden = false;
      document.body.classList.add('lightbox-open');
    }

    function closePhotoViewer() {
      photoViewerModal.hidden = true;
      photoViewerImage.src = '';
      document.body.classList.remove('lightbox-open');
    }

    colorChartOpen.addEventListener('click', () => {
      openPhotoViewer('images/color-chart.jpg', 'Gildan 64000 color chart with numbered swatches');
    });

    // Every product photo on the page opens the same zoom viewer when clicked
    document.querySelectorAll('.merch-photo').forEach((img) => {
      img.classList.add('zoomable');
      img.addEventListener('click', () => openPhotoViewer(img.src, img.alt));
    });

    photoViewerClose.addEventListener('click', closePhotoViewer);
    photoViewerModal.addEventListener('click', (event) => {
      if (event.target === photoViewerModal) closePhotoViewer();
    });

    // Item request modal
    const merchModal = document.getElementById('merch-modal');
    const merchModalItem = document.getElementById('merch-modal-item');
    const merchItemField = document.getElementById('merch-item-field');
    const merchModalClose = document.getElementById('merch-modal-close');
    const colorFieldWrapper = document.getElementById('color-field-wrapper');
    const merchColorSelect = document.getElementById('merch-color');
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

    // Items that come in a choice of colors - show the color dropdown only for these
    const COLORABLE_ITEMS = ['Logo Shirt', 'Finding Your Way Shirt', 'Logo Hat', 'Tool Holder Stand', 'Circle Cutter Holder', 'Oval Cutter Holder'];
    // Rainbow filament is only offered on the 3D-printed items, not the fabric shirts/hat
    const RAINBOW_ELIGIBLE_ITEMS = ['Tool Holder Stand', 'Circle Cutter Holder', 'Oval Cutter Holder'];
    // Shirts need a size and a sleeve-length choice; nothing else does
    const SIZE_AND_SLEEVE_ITEMS = ['Logo Shirt', 'Finding Your Way Shirt'];

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

      const subtotal = unitPrice * quantity;
      const tax = Math.round(subtotal * cfg.taxRate * 100) / 100;

      let shipping = null;
      let shippingNote = '';
      if (isShipping) {
        if (cfg.rainbowEligibleItems.includes(item)) {
          // Printed items use box-capacity tiers, not the flat qty<=2
          // rule below - mirrors merch_printed_shipping() in pricing.php.
          // A single order line only ever has ONE item type, so this
          // only ever has to look at whichever count is non-zero.
          const isToolStand = item === cfg.toolStandItem;
          const toolStandQty = isToolStand ? quantity : 0;
          const circleOvalQty = cfg.circleOvalItems.includes(item) ? quantity : 0;

          if (toolStandQty >= 2) {
            shippingNote = 'More than one Tool Holder Stand needs its own shipping box and cost - we\'ll follow up separately with a shipping quote.';
          } else if (toolStandQty === 1) {
            if (circleOvalQty <= cfg.circleOvalWithToolStandMax) {
              shipping = cfg.printedShipRateBox;
            } else {
              shippingNote = `A Tool Holder Stand plus more than ${cfg.circleOvalWithToolStandMax} Circle/Oval Cutter Holders needs its own shipping quote - we'll follow up separately.`;
            }
          } else if (circleOvalQty <= cfg.circleOvalMailerMax) {
            shipping = cfg.printedShipRateMailer;
          } else if (circleOvalQty <= cfg.circleOvalAloneMax) {
            shipping = cfg.printedShipRateBox;
          } else {
            shippingNote = `Orders of more than ${cfg.circleOvalAloneMax} Circle/Oval Cutter Holders need their own shipping quote - we'll follow up separately.`;
          }
        } else if (quantity <= cfg.flatShippingMaxQty) {
          shipping = cfg.flatShippingRate;
        } else {
          shippingNote = `Orders over ${cfg.flatShippingMaxQty} items don't qualify for flat-rate shipping - we'll follow up separately with a shipping cost instead of charging the flat rate here.`;
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
      const color = merchColorSelect.value;
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

    [merchQuantity, merchSizeSelect, merchSleeveSelect, merchColorSelect].forEach((el) => {
      el.addEventListener('change', updateEstimate);
      el.addEventListener('input', updateEstimate);
    });

    function openMerchModal(itemName) {
      merchItemField.value = itemName;
      merchModalItem.textContent = itemName;

      const needsColor = COLORABLE_ITEMS.includes(itemName);
      colorFieldWrapper.hidden = !needsColor;
      merchColorSelect.required = needsColor;

      const rainbowOption = document.getElementById('color-option-rainbow');
      rainbowOption.hidden = !RAINBOW_ELIGIBLE_ITEMS.includes(itemName);

      if (!needsColor || merchColorSelect.value === 'Rainbow (+$2)' && rainbowOption.hidden) {
        merchColorSelect.value = '';
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
