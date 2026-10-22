<?php require __DIR__ . '/pricing.php'; require __DIR__ . '/config.php'; require_once __DIR__ . '/strings.php'; require __DIR__ . '/events/events_helpers.php'; $merchPickupEvents = events_upcoming(); // Build: 2026-10-21-A ?>
<?php
require_once __DIR__ . '/sff_timezone.php';
require_once __DIR__ . '/filament_unavailable.php';

// ============================================================
// 2026-10-21 (shop redesign, Phase 1): the merch page is now a single
// browse-and-build page instead of cards that open pop-up forms.
//   - Every card carries its own Color / Size / Sleeve / Quantity
//     controls and an "Add to list" button.
//   - "Your list" lives in a sticky sidebar (desktop) or a bottom bar
//     that opens the same panel (phone) - no list modal, no item modal.
//   - "Request this list" reveals the contact / shipping step inside
//     that same panel; Submit stays disabled until it is complete.
//   - Totals are shown per invoice group (3D-printed items / shirts &
//     hats), priced server-side by merch_list_price.php.
//   - The order itself is unchanged: the same `list` JSON + contact
//     fields POST to merch_order.php.
// Card photos are one still image per item (merch_items.php:
// merch_items_card_face()); photos and videos open in the existing
// viewer. Shop styles are in styles/shop.css.
// ============================================================

// Pickup-retreat choices: the SAME list index.php's Save-the-Date grid
// reads (events_upcoming(), events/events_helpers.php) - one source,
// not a second copy of retreat names/dates to keep in sync by hand.
// (Not filtered by soldOut; filtered to non-archived events, so nobody
// is offered pickup at an event that's long over.)

// Filament colors that can't be printed right now (filament_unavailable.csv,
// maintained from the Stock & Print Plan page). Keyed by the color's NAME
// without its "#NN " number, lower-cased, so it matches however the CSV
// spells it ("Hot Pink" or "#08 Hot Pink"). Value = the short note shown
// next to the greyed-out choice.
$shopUnavailableColors = [];
foreach (merch_unavailable_active_rows(date('Y-m-d')) as $unavailColor => $unavailRow) {
    $unavailKey = strtolower(trim(preg_replace('/^#\d+\s*/', '', (string) $unavailColor)));
    $backOn = $unavailRow['BackOn'] ?? '';
    $shopUnavailableColors[$unavailKey] = ($backOn !== '' && strtotime($backOn) !== false)
        ? 'back ' . date('M j', strtotime($backOn))
        : 'unavailable right now';
}

/** Short price line for a card, e.g. "$12" or "$22 short / $26 long sleeve". */
function shop_card_price(string $item): string
{
    if (!isset(MERCH_PRICES[$item])) {
        return '';
    }
    $base = MERCH_PRICES[$item];
    if (is_array($base)) {
        return '$' . $base['Short Sleeve'] . ' short &middot; $' . $base['Long Sleeve'] . ' long';
    }
    return '$' . $base;
}

// Sort the catalog into the page's sections. Printed vs. shirts & hats
// is the same split the invoices use (merch_is_printed_item()); display-
// only cards (Gift Certificates) go in a short closing section.
$shopSections = [
    'printed' => ['title' => '3D-printed gadgets', 'items' => []],
    'apparel' => ['title' => 'Shirts &amp; hats', 'items' => []],
    'other' => ['title' => '', 'items' => []],
];
foreach (merch_catalog() as $shopEntry) {
    if ($shopEntry['attrs'] === null) {
        // Display-only card (Gift Certificates): sits in the 3D-printed grid,
        // filling the last slot, rather than in a section of its own.
        $shopSections['printed']['items'][] = $shopEntry;
    } elseif (merch_is_printed_item($shopEntry['name'])) {
        $shopSections['printed']['items'][] = $shopEntry;
    } else {
        $shopSections['apparel']['items'][] = $shopEntry;
    }
}

function shop_render_card(array $merchItem): void
{
    $itemName = $merchItem['name'];
    $media = $merchItem['media'];
    $card = $merchItem['card'];
    $orderable = $merchItem['attrs'] !== null;

    // Gallery the photo opens. A folder with only a 00-card image has no
    // gallery media, so fall back to a one-slide gallery of the card face.
    $gallery = $media;
    if (!$gallery && $card !== null) {
        $gallery = [['type' => 'image', 'src' => $card['src'], 'poster' => null, 'alt' => $card['alt']]];
    }
    $galleryJson = htmlspecialchars(json_encode($gallery), ENT_QUOTES, 'UTF-8');
    $videoCount = 0;
    foreach ($media as $m) {
        if ($m['type'] === 'video') { $videoCount++; }
    }
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    ?>
        <article class="shop-card" data-item="<?= $esc($itemName) ?>"<?= $card !== null ? ' data-thumb="' . $esc($card['src']) . '"' : '' ?><?= $orderable ? '' : ' data-display-only="1"' ?>>
          <div class="shop-card-media">
            <?php if ($card !== null): ?>
            <button type="button" class="shop-card-photo" data-gallery="<?= $galleryJson ?>" aria-label="See larger photos of <?= $esc($itemName) ?>">
              <img src="<?= $esc($card['src']) ?>" alt="<?= $esc($card['alt']) ?>" loading="lazy" />
            </button>
            <?php else: ?>
            <div class="shop-card-photo shop-card-photo-empty" aria-hidden="true"><span>Photo coming soon</span></div>
            <?php endif; ?>
            <?php if ($videoCount > 0): ?><span class="shop-card-badge" aria-hidden="true">&#9654; Video</span><?php endif; ?>
            <button type="button" class="shop-details-link" aria-label="Details about <?= $esc($itemName) ?>">Details</button>
          </div>

          <div class="shop-card-body">
            <div class="shop-card-titlerow">
              <h3 class="shop-card-name"><?= $esc($itemName) ?></h3>
              <?php if ($orderable): ?><span class="shop-card-price"><?= shop_card_price($itemName) ?></span><?php endif; ?>
            </div>
            <div class="shop-card-desc" hidden><?= $merchItem['description'] ?></div>

            <?php if ($orderable): ?>
            <div class="shop-card-controls">
              <div class="shop-fields"></div>
              <div class="shop-actionrow">
                <input type="number" class="shop-qty-input" min="1" max="<?= (int) MAX_QUANTITY ?>" value="1" inputmode="numeric" aria-label="Quantity of <?= $esc($itemName) ?>" />
                <button type="button" class="shop-add-btn" aria-label="Add <?= $esc($itemName) ?> to your list">Add <span aria-hidden="true">&rarr;</span></button>
              </div>
              <p class="shop-card-msg" role="status" aria-live="polite"></p>
            </div>
            <?php elseif ($merchItem['link'] !== null): ?>
            <div class="shop-card-controls">
              <a href="<?= $esc($merchItem['link']) ?>" class="shop-link-cta"><?= $esc($merchItem['linkText']) ?> <span aria-hidden="true">&rarr;</span></a>
            </div>
            <?php endif; ?>
          </div>
        </article>
    <?php
}

$shopFullSetRule = MERCH_FULL_SET_DISCOUNTS[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Merchandise – Southern Fireflies Retreats</title>
  <link rel="icon" href="images/favicon.png" type="image/png" />
  <link rel="stylesheet" href="styles/base.css" />
  <link rel="stylesheet" href="styles/merch.css" />
  <link rel="stylesheet" href="styles/shop.css" />
</head>
<body class="merch-page">
  <header>
    <div class="nav-wrapper">
      <button class="menu-toggle" aria-label="Toggle menu">&#9776;</button>
      <nav class="main-nav">
        <ul class="nav-links">
          <li><a href="index.php">Events</a></li>
          <li><a href="merch.php" aria-current="page">Merch</a></li>
          <li><a href="about.php">About</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <div class="content-wrapper shop-wrap">
    <?= merch_load_string('pages/merch-intro') ?>
    <div class="shop-layout">

      <main class="shop-main" id="shop-main">
        <!-- Hero: logo over the left of the vase photo (images/shop-hero.jpg
             blends into the page cream on its left edge). -->
        <div class="shop-hero">
          <img class="shop-hero-photo" src="images/shop-hero.jpg" alt="" />
          <img class="shop-hero-logo" src="images/mr-firefly-logo.png" alt="Mr. Firefly's 3D Printed Gadgets" />
        </div>
<?php foreach ($shopSections as $sectionKey => $section): ?>
<?php if (!$section['items']) { continue; } ?>
        <section class="shop-section"<?= $sectionKey === 'printed' ? ' aria-label="3D-printed gadgets"' : ' aria-labelledby="shop-sec-' . $sectionKey . '"' ?>>
          <?php if ($sectionKey === 'printed'): ?>
          <?php $shopAnnouncement = trim(merch_load_string('pages/merch-announcement')); ?>
          <?php if ($shopAnnouncement !== ''): ?>
          <p class="shop-announce" id="shop-sec-<?= $sectionKey ?>"><?= $shopAnnouncement ?></p>
          <?php endif; ?>
          <?php elseif ($section['title'] !== ''): ?>
          <h2 class="shop-section-title" id="shop-sec-<?= $sectionKey ?>"><?= $section['title'] ?></h2>
          <?php endif; ?>
          <div class="shop-grid">
<?php foreach ($section['items'] as $shopItem) { shop_render_card($shopItem); } ?>
          </div>
        </section>
<?php endforeach; ?>
      </main>

      <!-- Your list: sticky sidebar on desktop; on phones it is a panel
           that slides up from the bottom bar below. The checkout form is
           inside it, hidden until "Request this list" is pressed. -->
      <aside class="shop-list" id="shop-list" aria-label="Your list">
        <div class="shop-list-head">
          <h2>Your list</h2>
          <button type="button" class="shop-info-link shop-howlink" data-info="how">How this works</button>
          <button type="button" class="shop-list-close" id="shop-list-close" aria-label="Close your list">&times;</button>
        </div>

        <p class="shop-list-charts">Color charts:
          <button type="button" class="shop-chart-link" data-chart="filament">Printed items</button> &middot;
          <button type="button" class="shop-chart-link" data-chart="gildan">Shirts &amp; hats</button>
        </p>

        <p class="shop-list-empty" id="list-empty">Nothing here yet. Choose a color and press <strong>Add</strong> on any item.</p>
        <div id="list-groups"></div>
        <div class="shop-list-hint" id="list-set-hint"><span class="shop-spark" aria-hidden="true">&#10022;</span><div id="list-set-text"></div></div>

        <button type="button" class="shop-primary-btn" id="request-btn" disabled>Request this list <span aria-hidden="true">&rarr;</span></button>
        <p class="shop-list-note" id="list-invoice-note" hidden></p>

        <form action="merch_order.php" method="POST" id="merch-checkout-form" class="shop-checkout" novalidate hidden>
          <input type="hidden" name="list" id="list-payload-field" value="" />
          <h3 id="checkout-heading" tabindex="-1">Where should we send it?</h3>

          <!-- Honeypot (merch_order.php): invisible to people, tempting to
               form-filling bots. Anything typed here is silently dropped. -->
          <div class="shop-hp" aria-hidden="true">
            <label for="merch-website">Leave this field empty</label>
            <input type="text" name="website" id="merch-website" value="" tabindex="-1" autocomplete="off" />
          </div>

          <label for="merch-name">Your name</label>
          <input type="text" name="name" id="merch-name" autocomplete="name" required />

          <label for="merch-email">Email address</label>
          <input type="email" name="email" id="merch-email" autocomplete="email" inputmode="email" required />

          <label for="merch-phone">Phone <span class="shop-optional">(optional)</span></label>
          <input type="tel" name="phone" id="merch-phone" autocomplete="tel" />

          <label for="merch-fulfillment">How would you like to receive this?</label>
          <select name="fulfillment" id="merch-fulfillment" required>
            <option value="Ship" selected>Ship to me</option>
            <option value="Pickup at retreat">I'll pick it up at a retreat</option>
          </select>

          <div id="shipping-fields">
            <label for="merch-address">Street address</label>
            <input type="text" name="address" id="merch-address" autocomplete="street-address" required />
            <div class="shop-address-row">
              <div>
                <label for="merch-city">City</label>
                <input type="text" name="city" id="merch-city" autocomplete="address-level2" required />
              </div>
              <div>
                <label for="merch-state">State</label>
                <select name="state" id="merch-state" autocomplete="address-level1" required>
                  <option value="" selected disabled>Select&hellip;</option>
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
              </div>
              <div>
                <label for="merch-zip">ZIP code</label>
                <input type="text" name="zip" id="merch-zip" autocomplete="postal-code" inputmode="numeric" maxlength="10" required />
              </div>
            </div>
          </div>

          <p class="shop-list-note" id="ship-region-note">Alaska, Hawaii or Canada? Please email us before sending, so we can quote shipping first.</p>

          <div id="retreat-fields" hidden>
            <label for="merch-retreat">Which retreat will you pick this up at?</label>
            <select name="retreat" id="merch-retreat">
              <option value="" selected disabled>Select a retreat&hellip;</option>
<?php foreach ($merchPickupEvents as $merchPickupEvent): ?>
<?php $merchPickupEventLabel = trim($merchPickupEvent['dateRange']) . ' – ' . trim($merchPickupEvent['title']); ?>
              <option value="<?= htmlspecialchars($merchPickupEventLabel, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($merchPickupEventLabel, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
            </select>
          </div>

          <label for="merch-message">Notes <span class="shop-optional">(anything else we should know?)</span></label>
          <textarea name="message" id="merch-message" rows="3" maxlength="<?= NOTES_MAX_LENGTH ?>"></textarea>

          <!-- 2026-09-25 (Steve): confirmation and invoice emails were
               landing in customers' spam/junk folders. The sender address
               comes from config.php's MAIL_FROM_ADDRESS so it can never
               drift from what the emails are actually sent from. -->
          <p class="shop-list-note" id="list-spam-note">We'll email you a confirmation of this request, and your invoice later on. Those emails sometimes land in spam or junk folders, so if you don't see them, please check there<?= defined('MAIL_FROM_ADDRESS') ? ' for messages from <strong>' . htmlspecialchars(MAIL_FROM_ADDRESS, ENT_QUOTES, 'UTF-8') . '</strong>' : '' ?>.</p>

          <p class="shop-submit-hint" id="submit-hint" role="status" aria-live="polite"></p>
          <button type="submit" class="shop-primary-btn" id="list-submit-btn" disabled>Submit my request</button>
          <button type="button" class="shop-link-btn" id="checkout-back-btn">&larr; Back to my list</button>
        </form>
      </aside>
    </div>
  </div>

  <!-- Phone: bottom bar that opens the list panel. Hidden on wide
       screens (the sidebar is always visible there) and while the list
       is empty. -->
  <div class="shop-bar" id="shop-bar" hidden>
    <div class="shop-bar-summary">
      <span id="shop-bar-count">0 items</span>
      <span id="shop-bar-total" class="shop-bar-total"></span>
    </div>
    <button type="button" class="shop-primary-btn shop-bar-btn" id="shop-bar-open">View my list</button>
  </div>
  <div class="shop-scrim" id="shop-scrim" hidden></div>

  <!-- Option lists for the per-card Color choice, cloned in by the script
       below. Numbering/grouping matches the color chart images exactly
       (keep them in sync if a chart is ever renumbered). Rainbow and
       Stars & Stripes are added in script for the items eligible for them
       (RAINBOW_ELIGIBLE_ITEMS / STARS_STRIPES_ELIGIBLE_ITEMS, pricing.php). -->
  <template id="tpl-color-gildan">
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
  </template>
  <template id="tpl-color-filament">
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
                <!-- 2026-10-05 (Steve): Copper, #28. Chart image gained a fourth row for it. -->
                <option value="#28 Copper">#28 &ndash; Copper</option>
              </optgroup>
  </template>

  <!-- Details: description (and a way into the photos) for one item. -->
  <div id="details-modal" class="lightbox" role="dialog" aria-modal="true" aria-labelledby="details-title" hidden>
    <div class="shop-details">
      <button type="button" class="shop-details-close" id="details-close" aria-label="Close details">&times;</button>
      <h2 id="details-title"></h2>
      <p class="shop-details-price" id="details-price"></p>
      <div class="shop-details-body" id="details-body"></div>
      <button type="button" class="btn btn-secondary full-width" id="details-photos" hidden>See photos &amp; video</button>
      <button type="button" class="btn full-width" id="details-done">Close</button>
    </div>
  </div>

  <!-- Small info dialogs opened from the list panel ("How this works",
       "Shipping"). Text comes from strings/pages/merch-how-it-works and
       merch-shipping-note; both are rendered here hidden and copied in. -->
  <template id="tpl-info-how"><?= merch_load_string('pages/merch-how-it-works') ?></template>
  <template id="tpl-info-shipping"><?= merch_load_string('pages/merch-shipping-note') ?></template>
  <div id="info-modal" class="lightbox" role="dialog" aria-modal="true" aria-labelledby="info-title" hidden>
    <div class="shop-details">
      <button type="button" class="shop-details-close" id="info-close" aria-label="Close">&times;</button>
      <h2 id="info-title"></h2>
      <div class="shop-details-body" id="info-body"></div>
      <button type="button" class="btn full-width" id="info-done">Close</button>
    </div>
  </div>

  <!-- Photo viewer - shared by the color charts and product photos,
       informational only. Prev/next/counter show only when opened as a
       gallery; a gallery slide can be a photo or a video; the caption
       line shows each slide's caption (the item folder's captions.txt). -->
  <div id="photo-viewer-modal" class="lightbox" role="dialog" aria-modal="true" aria-label="Photo viewer" hidden>
    <button id="photo-viewer-close" class="lightbox-close" type="button" aria-label="Close photo viewer">&times;</button>
    <button id="photo-viewer-prev" class="lightbox-nav lightbox-nav-prev" type="button" aria-label="Previous photo" hidden>&#10094;</button>
    <img id="photo-viewer-image" src="" alt="" class="lightbox-image" />
    <video id="photo-viewer-video" class="lightbox-image" controls playsinline preload="metadata" hidden></video>
    <button id="photo-viewer-next" class="lightbox-nav lightbox-nav-next" type="button" aria-label="Next photo" hidden>&#10095;</button>
    <div id="photo-viewer-counter" class="photo-viewer-counter" hidden></div>
    <div id="photo-viewer-caption" class="photo-viewer-caption" hidden></div>
  </div>

  <script>
    // Pricing data pricing.php uses server-side, handed to JS for the
    // per-line price shown in the list (calculateItemSubtotal() below -
    // unit price + surcharges only, hand-mirrored from merch_unit_price();
    // if you ever change a surcharge *rule*, not just a price, update both
    // places) and for the item/color lists. The COMBINED totals (tax,
    // shipping tiers, bundle and full-set discounts) are NOT mirrored
    // here - they're priced server-side by merch_list_price.php.
    const MERCH_PRICING = <?php echo json_encode(merch_pricing_for_js()); ?>;
    // The "buy the whole set" rule, for the nudge in the list (the discount
    // itself is applied server-side).
    const FULL_SET_RULE = <?php echo json_encode($shopFullSetRule); ?>;
    // Filament colors not printable right now: lower-cased name -> short note.
    // Item pairs with a flat "buy both" discount (e.g. Tape Gun Holder + Add-On).
    const BUNDLES = <?php echo json_encode(MERCH_BUNDLES); ?>;
    const UNAVAILABLE_COLORS = <?php echo json_encode((object) $shopUnavailableColors); ?>;

    const menuToggle = document.querySelector('.menu-toggle');
    const navLinks = document.querySelector('.nav-links');
    menuToggle.addEventListener('click', () => {
      navLinks.classList.toggle('show');
    });

    // ============================================================
    // Small helpers
    // ============================================================
    function formatMoney(n) {
      return '$' + Number(n).toFixed(2);
    }

    function escapeHtml(str) {
      return String(str).replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
      }[ch]));
    }

    function pluralItems(n) {
      return `${n} item${n === 1 ? '' : 's'}`;
    }

    // Tab trap for every dialog-like layer (details, photo viewer, and the
    // list panel when it is a phone sheet). Recomputed on every Tab press
    // since which controls are visible changes.
    function focusableWithin(el) {
      const focusable = el.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      );
      return Array.from(focusable).filter((node) => node.offsetParent !== null);
    }

    function trapTabWithin(el, event) {
      if (event.key !== 'Tab') return;
      const visible = focusableWithin(el);
      if (visible.length === 0) return;
      const first = visible[0];
      const last = visible[visible.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    // ============================================================
    // Photo viewer - color charts + every card photo (informational).
    // A gallery slide can be a video ({type:'video', src, poster, alt});
    // the <img> and <video> swap places per slide and a playing video is
    // paused whenever the slide changes or the viewer closes.
    // ============================================================
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
    let photoViewerReturnFocus = null;

    function stopViewerVideo() {
      if (!photoViewerVideo.hidden) photoViewerVideo.pause();
    }

    function renderGallerySlide() {
      const slide = gallerySlides[galleryIndex];
      stopViewerVideo();
      const isVideo = slide.type === 'video';
      photoViewerImage.hidden = isVideo;
      photoViewerVideo.hidden = !isVideo;
      if (isVideo) {
        photoViewerImage.src = '';
        if (photoViewerVideo.getAttribute('src') !== slide.src) photoViewerVideo.src = slide.src;
        if (slide.poster) photoViewerVideo.poster = slide.poster; else photoViewerVideo.removeAttribute('poster');
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

    function openPhotoViewer(src, alt) {
      photoViewerReturnFocus = document.activeElement;
      gallerySlides = [];
      stopViewerVideo();
      photoViewerVideo.hidden = true;
      photoViewerVideo.removeAttribute('src');
      photoViewerImage.hidden = false;
      photoViewerImage.src = src;
      photoViewerImage.alt = alt || '';
      photoViewerPrev.hidden = true;
      photoViewerNext.hidden = true;
      photoViewerCounter.hidden = true;
      photoViewerCaption.hidden = true;
      photoViewerModal.hidden = false;
      document.body.classList.add('lightbox-open');
      photoViewerClose.focus();
    }

    // slides: array of {type?, src, poster?, alt}
    function openPhotoGallery(slides, startIndex) {
      if (!slides || slides.length === 0) return;
      photoViewerReturnFocus = document.activeElement;
      gallerySlides = slides;
      galleryIndex = startIndex || 0;
      renderGallerySlide();
      const hasMultiple = gallerySlides.length > 1;
      photoViewerPrev.hidden = !hasMultiple;
      photoViewerNext.hidden = !hasMultiple;
      photoViewerCounter.hidden = !hasMultiple;
      photoViewerModal.hidden = false;
      document.body.classList.add('lightbox-open');
      photoViewerClose.focus();
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
      // Stay "locked" if a details dialog or the phone list sheet is
      // still open underneath.
      syncBodyLock();
      if (photoViewerReturnFocus && document.contains(photoViewerReturnFocus)) {
        photoViewerReturnFocus.focus();
      }
      photoViewerReturnFocus = null;
    }

    // The two color charts open from a small "chart" link beside each
    // card's Color choice (see makeField() below).
    document.querySelectorAll('.shop-chart-link[data-chart]').forEach((btn) => {
      btn.addEventListener('click', () => openPhotoViewer(...COLOR_CHARTS[btn.dataset.chart]));
    });
    const COLOR_CHARTS = {
      gildan: ['images/color-chart.jpg', 'Gildan 64000 color chart with numbered swatches'],
      filament: ['images/filament-color-chart.jpg', 'Filament color chart with numbered, named swatches'],
    };

    // Clicking a card photo opens its gallery (photos, then videos).
    document.querySelectorAll('.shop-card-photo[data-gallery]').forEach((btn) => {
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

    // ============================================================
    // Info dialogs ("How this works", "Shipping")
    // ============================================================
    const infoModal = document.getElementById('info-modal');
    let infoReturnFocus = null;
    const INFO_TITLES = { how: 'How this works', shipping: 'Shipping' };

    function openInfo(kind, opener) {
      infoReturnFocus = opener;
      document.getElementById('info-title').textContent = INFO_TITLES[kind] || '';
      const tpl = document.getElementById('tpl-info-' + kind);
      document.getElementById('info-body').innerHTML = tpl ? tpl.innerHTML : '';
      infoModal.hidden = false;
      document.body.classList.add('lightbox-open');
      document.getElementById('info-close').focus();
    }

    function closeInfo() {
      infoModal.hidden = true;
      syncBodyLock();
      if (infoReturnFocus && document.contains(infoReturnFocus)) infoReturnFocus.focus();
      infoReturnFocus = null;
    }

    document.getElementById('shop-list').addEventListener('click', (event) => {
      const btn = event.target.closest('.shop-info-link[data-info]');
      if (btn) openInfo(btn.dataset.info, btn);
    });
    document.getElementById('info-close').addEventListener('click', closeInfo);
    document.getElementById('info-done').addEventListener('click', closeInfo);
    infoModal.addEventListener('click', (event) => { if (event.target === infoModal) closeInfo(); });

    // ============================================================
    // Details dialog
    // ============================================================
    const detailsModal = document.getElementById('details-modal');
    const detailsTitle = document.getElementById('details-title');
    const detailsPrice = document.getElementById('details-price');
    const detailsBody = document.getElementById('details-body');
    const detailsPhotos = document.getElementById('details-photos');
    let detailsReturnFocus = null;
    let detailsGallery = null;

    function openDetails(card, opener) {
      detailsReturnFocus = opener;
      detailsTitle.textContent = card.dataset.item;
      const priceEl = card.querySelector('.shop-card-price');
      detailsPrice.innerHTML = priceEl ? priceEl.innerHTML + ' <span class="shop-price-note">plus tax and shipping</span>' : '';
      detailsBody.innerHTML = card.querySelector('.shop-card-desc').innerHTML;
      const nudgeText = MERCH_PRICING.bundleNudges[card.dataset.item];
      if (nudgeText) detailsBody.innerHTML += `<p class="shop-hint-extra"><strong>${nudgeText}</strong></p>`;
      const photoBtn = card.querySelector('.shop-card-photo[data-gallery]');
      const gallery = photoBtn ? JSON.parse(photoBtn.dataset.gallery) : [];
      detailsGallery = gallery.length ? gallery : null;
      detailsPhotos.hidden = !detailsGallery;
      if (detailsGallery) {
        const hasVideo = detailsGallery.some((s) => s.type === 'video');
        detailsPhotos.textContent = hasVideo ? 'See photos & video' : (detailsGallery.length > 1 ? 'See all photos' : 'See larger photo');
      }
      detailsModal.hidden = false;
      document.body.classList.add('lightbox-open');
      document.getElementById('details-close').focus();
    }

    function closeDetails() {
      detailsModal.hidden = true;
      syncBodyLock();
      if (detailsReturnFocus && document.contains(detailsReturnFocus)) detailsReturnFocus.focus();
      detailsReturnFocus = null;
    }

    document.querySelectorAll('.shop-details-link').forEach((btn) => {
      btn.addEventListener('click', () => openDetails(btn.closest('.shop-card'), btn));
    });
    document.getElementById('details-close').addEventListener('click', closeDetails);
    document.getElementById('details-done').addEventListener('click', closeDetails);
    detailsModal.addEventListener('click', (event) => {
      if (event.target === detailsModal) closeDetails();
    });
    detailsPhotos.addEventListener('click', () => {
      if (!detailsGallery) return;
      const gallery = detailsGallery;
      // Hand focus-return over to the viewer, which returns it to the
      // Details button once the visitor is done.
      closeDetails();
      openPhotoGallery(gallery, 0);
    });

    // ============================================================
    // Card controls: Color / Size / Sleeve built per card
    // ============================================================
    const SIZE_AND_SLEEVE_ITEMS = MERCH_PRICING.shirtItems;
    const GILDAN_COLOR_ITEMS = MERCH_PRICING.gildanColorItems;
    const FILAMENT_COLOR_ITEMS = MERCH_PRICING.filamentColorItems;
    const PRINTED_ITEMS = MERCH_PRICING.printedItems;
    const MAX_QTY = MERCH_PRICING.maxQuantity;
    const tplGildan = document.getElementById('tpl-color-gildan').innerHTML;
    const tplFilament = document.getElementById('tpl-color-filament').innerHTML;
    let controlSeq = 0;

    const SIZE_OPTIONS = [
      ['S', 'S'], ['M', 'M'], ['L', 'L'], ['XL', 'XL'], ['2XL', '2XL'],
      ['3XL', '3XL (+$3)'], ['4XL', '4XL (+$3)'], ['5XL', '5XL (+$3)'],
    ];
    const SLEEVE_OPTIONS = ['Short Sleeve', 'Long Sleeve'];

    // "#08 Hot Pink" -> "hot pink" (matches how the unavailable list is keyed)
    function colorKey(value) {
      return String(value).replace(/^#\d+\s*/, '').trim().toLowerCase();
    }

    function makeField(card, labelText, name, selectEl, chartKey) {
      const id = `shop-${name}-${++controlSeq}`;
      const wrap = document.createElement('div');
      wrap.className = 'shop-field' + (name === 'color' ? ' shop-field-color' : '');
      const label = document.createElement('label');
      label.htmlFor = id;
      label.textContent = labelText;
      label.className = 'visually-hidden'; // the select's first option reads as its label
      selectEl.id = id;
      selectEl.className = 'shop-select';
      selectEl.dataset.field = name;
      wrap.append(label, selectEl);
      if (chartKey) {
        // The color choice sits on the same row as quantity and Add.
        card.querySelector('.shop-actionrow').prepend(wrap);
        return selectEl;
      }
      card.querySelector('.shop-fields').appendChild(wrap);
      return selectEl;
    }

    function buildColorSelect(item, isFilament) {
      const select = document.createElement('select');
      select.innerHTML = isFilament ? tplFilament : tplGildan;
      select.querySelector('option[value=""]').textContent = 'Color…';
      if (isFilament) {
        // Rainbow / Stars & Stripes only on the items that offer them.
        const extras = [];
        if (MERCH_PRICING.rainbowEligibleItems.includes(item)) extras.push('Rainbow (+$2)');
        if (MERCH_PRICING.starsStripesEligibleItems.includes(item)) {
          extras.push('Stars & Stripes (+$7)');
        }
        extras.forEach((value) => {
          const opt = document.createElement('option');
          opt.value = value;
          opt.textContent = value;
          select.appendChild(opt);
        });
        // Colors that can't be printed right now: greyed out and unpickable,
        // with when they're back so nobody wonders why.
        select.querySelectorAll('option').forEach((opt) => {
          const note = UNAVAILABLE_COLORS[colorKey(opt.value)];
          if (note && opt.value !== '') {
            opt.disabled = true;
            opt.textContent = `${opt.textContent} — ${note}`;
          }
        });
      }
      return select;
    }

    document.querySelectorAll('.shop-card:not([data-display-only])').forEach((card) => {
      const item = card.dataset.item;
      const isGildan = GILDAN_COLOR_ITEMS.includes(item);
      const isFilament = FILAMENT_COLOR_ITEMS.includes(item);
      if (isGildan || isFilament) {
        makeField(card, 'Color', 'color', buildColorSelect(item, isFilament), isFilament ? 'filament' : 'gildan');
      }
      if (SIZE_AND_SLEEVE_ITEMS.includes(item)) {
        const sleeve = document.createElement('select');
        sleeve.innerHTML = '<option value="">Sleeve length…</option>' + SLEEVE_OPTIONS.map((s) => `<option value="${s}">${s}</option>`).join('');
        makeField(card, 'Sleeve length', 'sleeve', sleeve);
        const size = document.createElement('select');
        size.innerHTML = '<option value="">Size…</option>' + SIZE_OPTIONS.map(([v, l]) => `<option value="${v}">${l}</option>`).join('');
        makeField(card, 'Size', 'size', size);
      }
      const fields = card.querySelector('.shop-fields');
      if (!fields.children.length) fields.remove();

      const qtyInput = card.querySelector('.shop-qty-input');
      qtyInput.addEventListener('change', () => {
        qtyInput.value = Math.min(MAX_QTY, Math.max(1, parseInt(qtyInput.value, 10) || 1));
      });

      // Clear a "please choose" flag as soon as the choice is made.
      card.querySelectorAll('.shop-select').forEach((sel) => {
        sel.addEventListener('change', () => {
          sel.removeAttribute('aria-invalid');
          const msg = card.querySelector('.shop-card-msg');
          if (!card.querySelector('.shop-select[aria-invalid]')) {
            msg.textContent = '';
            msg.classList.remove('is-error');
          }
        });
      });

      card.querySelector('.shop-add-btn').addEventListener('click', () => addFromCard(card));
    });

    // ============================================================
    // Per-line price (unit price + surcharges only, mirrors
    // merch_unit_price())
    // ============================================================
    function calculateItemSubtotal(item, quantity, size, sleeve, color) {
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
        unitPrice += cfg.starsStripesSurcharges?.[item] ?? cfg.starsStripesSurcharge;
      }
      return { unitPrice, subtotal: unitPrice * quantity };
    }

    // ============================================================
    // List state. Persisted to localStorage purely as a convenience so
    // an accidental reload doesn't lose a half-built list - every
    // read/write is wrapped in try/catch and the list still works for the
    // page visit if storage is blocked.
    // ============================================================
    const LIST_STORAGE_KEY = 'sfrMerchList_v1';

    function loadStoredList() {
      try {
        const raw = window.localStorage.getItem(LIST_STORAGE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) return [];
        // Drop anything that no longer matches the catalog (an item that
        // was renamed or retired since the visitor's last visit).
        return parsed.filter((l) => l && typeof l.item === 'string' && MERCH_PRICING.prices[l.item] !== undefined)
          .map((l) => ({
            id: l.id || makeListLineId(),
            item: l.item,
            quantity: Math.min(MAX_QTY, Math.max(1, parseInt(l.quantity, 10) || 1)),
            size: l.size || '',
            sleeve: l.sleeve || '',
            color: l.color || '',
          }));
      } catch (err) {
        return [];
      }
    }

    function saveListToStorage() {
      try {
        window.localStorage.setItem(LIST_STORAGE_KEY, JSON.stringify(list));
      } catch (err) {
        // Storage unavailable or full - not worth surfacing.
      }
    }

    function makeListLineId() {
      return 'l' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
    }

    let list = [];

    function colorLabel(color) {
      return color.replace(/ \(\+\$\d+\)$/, '') + (/\(\+\$\d+\)$/.test(color) ? ' ' + color.match(/\(\+\$\d+\)$/)[0] : '');
    }

    // Tapping a line's color swaps it for the same drop-down the card
    // uses, so a wrong pick is one tap to fix instead of delete-and-re-add.
    // If the new color matches another line exactly, the two are merged.
    function editLineColor(line, detailEl) {
      const isFilament = FILAMENT_COLOR_ITEMS.includes(line.item);
      const select = buildColorSelect(line.item, isFilament);
      select.className = 'shop-select shop-line-colorselect';
      select.setAttribute('aria-label', `Color for ${line.item}`);
      select.value = line.color;
      detailEl.innerHTML = '';
      detailEl.appendChild(select);
      select.focus();
      let done = false;
      select.addEventListener('change', () => {
        if (done || !select.value) return;
        done = true;
        line.color = select.value;
        const twin = list.find((l) => l !== line && l.item === line.item && l.color === line.color && l.size === line.size && l.sleeve === line.sleeve);
        if (twin) {
          twin.quantity = Math.min(MAX_QTY, twin.quantity + line.quantity);
          list = list.filter((l) => l !== line);
        }
        renderList();
      });
      select.addEventListener('blur', () => { if (!done) { done = true; renderList(); } });
    }

    function lineDetailLabel(line) {
      const details = [];
      if (line.color) details.push(line.color.replace(/ \(\+\$\d+\)$/, '') + (/\(\+\$\d+\)$/.test(line.color) ? ' ' + line.color.match(/\(\+\$\d+\)$/)[0] : ''));
      if (line.sleeve) details.push(line.sleeve);
      if (line.size) details.push('Size ' + line.size);
      return details.join(' · ');
    }

    function lineSubtotal(line) {
      const est = calculateItemSubtotal(line.item, line.quantity, line.size, line.sleeve, line.color);
      return est ? est.subtotal : 0;
    }

    function addFromCard(card) {
      const item = card.dataset.item;
      const msg = card.querySelector('.shop-card-msg');
      const colorSel = card.querySelector('[data-field="color"]');
      const sizeSel = card.querySelector('[data-field="size"]');
      const sleeveSel = card.querySelector('[data-field="sleeve"]');
      const qtyInput = card.querySelector('.shop-qty-input');

      // Everything that is needed must be chosen. Flag the first missing
      // one in plain words and send focus to it.
      const needs = [
        [colorSel, 'a color'], [sleeveSel, 'a sleeve length'], [sizeSel, 'a size'],
      ].filter(([sel]) => sel && !sel.value);
      card.querySelectorAll('.shop-select').forEach((sel) => sel.removeAttribute('aria-invalid'));
      if (needs.length) {
        needs.forEach(([sel]) => sel.setAttribute('aria-invalid', 'true'));
        const words = needs.map(([, w]) => w);
        msg.textContent = 'Please choose ' + (words.length > 1 ? words.slice(0, -1).join(', ') + ' and ' + words[words.length - 1] : words[0]) + '.';
        msg.classList.add('is-error');
        needs[0][0].focus();
        return;
      }

      const quantity = Math.min(MAX_QTY, Math.max(1, parseInt(qtyInput.value, 10) || 1));
      const color = colorSel ? colorSel.value : '';
      const size = sizeSel ? sizeSel.value : '';
      const sleeve = sleeveSel ? sleeveSel.value : '';

      const existing = list.find((l) => l.item === item && l.color === color && l.size === size && l.sleeve === sleeve);
      if (existing) {
        existing.quantity = Math.min(MAX_QTY, existing.quantity + quantity);
      } else {
        if (list.length >= MERCH_PRICING.listMaxLines) {
          msg.textContent = `One request can hold up to ${MERCH_PRICING.listMaxLines} different lines - please send this one first, or remove something.`;
          msg.classList.add('is-error');
          return;
        }
        list.push({ id: makeListLineId(), item, quantity, size, sleeve, color });
      }

      msg.classList.remove('is-error');
      msg.textContent = '';
      qtyInput.value = 1;
      const btn = card.querySelector('.shop-add-btn');
      btn.innerHTML = 'Added <span aria-hidden="true">\u2713</span>';
      btn.classList.add('is-added');
      clearTimeout(btn._resetTimer);
      btn._resetTimer = setTimeout(() => {
        btn.innerHTML = 'Add <span aria-hidden="true">\u2192</span>';
        btn.classList.remove('is-added');
      }, 1600);

      renderList();
    }

    // ============================================================
    // Rendering the list panel (grouped like the invoices: 3D-printed
    // items vs. shirts & hats), then the bottom bar.
    // ============================================================
    const listGroupsEl = document.getElementById('list-groups');
    const listEmptyEl = document.getElementById('list-empty');
    const listSetHint = document.getElementById('list-set-hint');
    const listInvoiceNote = document.getElementById('list-invoice-note');
    const requestBtn = document.getElementById('request-btn');
    const shopBar = document.getElementById('shop-bar');
    const shopBarCount = document.getElementById('shop-bar-count');
    const shopBarTotal = document.getElementById('shop-bar-total');

    const GROUPS = [
      { key: 'printed', label: '3D-printed items' },
      { key: 'apparel', label: 'Shirts & hats' },
    ];
    // key -> last pricing result for that group from the server
    let pricingByGroup = {};

    // item name -> card photo, for the little thumbnails in the list
    const THUMBS = {};
    document.querySelectorAll('.shop-card[data-thumb]').forEach((c) => { THUMBS[c.dataset.item] = c.dataset.thumb; });

    function groupKeyFor(item) {
      return PRINTED_ITEMS.includes(item) ? 'printed' : 'apparel';
    }

    function removeLine(id) {
      list = list.filter((l) => l.id !== id);
      renderList();
    }

    function stepLine(line, delta) {
      const next = line.quantity + delta;
      if (next < 1) { removeLine(line.id); return; }
      if (next > MAX_QTY) return;
      line.quantity = next;
      renderList();
    }

    function renderList() {
      saveListToStorage();

      const itemCount = list.reduce((sum, l) => sum + l.quantity, 0);
      const roughSubtotal = list.reduce((sum, l) => sum + lineSubtotal(l), 0);
      const empty = list.length === 0;

      listEmptyEl.hidden = !empty;
      requestBtn.disabled = empty;
      shopBar.hidden = empty;
      shopBarCount.textContent = pluralItems(itemCount);
      shopBarTotal.textContent = formatMoney(roughSubtotal);

      listGroupsEl.innerHTML = '';
      GROUPS.forEach((group) => {
        const lines = list.filter((l) => groupKeyFor(l.item) === group.key);
        if (!lines.length) return;

        const section = document.createElement('section');
        section.className = 'shop-list-group';
        section.dataset.group = group.key;
        const h = document.createElement('h3');
        h.textContent = group.label;
        section.appendChild(h);

        lines.forEach((line) => {
          const row = document.createElement('div');
          row.className = 'shop-line';
          const detail = lineDetailLabel(line);

          const thumb = document.createElement('div');
          thumb.className = 'shop-line-thumb';
          if (THUMBS[line.item]) {
            const img = document.createElement('img');
            img.src = THUMBS[line.item];
            img.alt = '';
            thumb.appendChild(img);
          }

          const info = document.createElement('div');
          info.className = 'shop-line-info';
          info.innerHTML = `<div class="shop-line-name">${escapeHtml(line.item)}</div>`;
          const detailEl = document.createElement('div');
          detailEl.className = 'shop-line-detail';
          if (line.color) {
            const colorBtn = document.createElement('button');
            colorBtn.type = 'button';
            colorBtn.className = 'shop-info-link shop-line-colorlink';
            colorBtn.textContent = colorLabel(line.color);
            colorBtn.setAttribute('aria-label', `Color ${colorLabel(line.color)}. Change color for ${line.item}`);
            colorBtn.addEventListener('click', () => editLineColor(line, detailEl));
            detailEl.appendChild(colorBtn);
          }
          const rest = [];
          if (line.sleeve) rest.push(line.sleeve);
          if (line.size) rest.push('Size ' + line.size);
          if (rest.length) detailEl.appendChild(document.createTextNode((line.color ? ' \u00b7 ' : '') + rest.join(' \u00b7 ')));
          if (detailEl.childNodes.length) info.appendChild(detailEl);

          const controls = document.createElement('div');
          controls.className = 'shop-line-controls';
          const qty = document.createElement('input');
          qty.type = 'number';
          qty.className = 'shop-qty-input shop-line-qtyinput';
          qty.min = '1';
          qty.max = String(MAX_QTY);
          qty.value = String(line.quantity);
          qty.setAttribute('inputmode', 'numeric');
          qty.setAttribute('aria-label', `Quantity of ${line.item}`);
          qty.addEventListener('change', () => {
            const n = parseInt(qty.value, 10);
            line.quantity = Math.min(MAX_QTY, Math.max(1, Number.isFinite(n) ? n : line.quantity));
            renderList();
          });
          controls.appendChild(qty);
          info.appendChild(controls);

          const side = document.createElement('div');
          side.className = 'shop-line-side';
          const remove = document.createElement('button');
          remove.type = 'button';
          remove.className = 'shop-line-remove';
          remove.innerHTML = '&times;';
          remove.setAttribute('aria-label', `Remove ${line.item} from list`);
          remove.addEventListener('click', () => removeLine(line.id));
          const price = document.createElement('div');
          price.className = 'shop-line-price';
          price.textContent = formatMoney(lineSubtotal(line));
          side.append(remove, price);

          row.append(thumb, info, side);
          section.appendChild(row);
        });

        const totals = document.createElement('div');
        totals.className = 'shop-totals';
        totals.dataset.group = group.key;
        section.appendChild(totals);
        listGroupsEl.appendChild(section);
      });

      renderSetHint();
      renderInvoiceNote();
      renderAllTotals();
      refreshListPricing();
      // The checkout step's Submit button depends on the list being non-empty.
      updateCheckoutState();
    }

    // Pair-deal nudge box (the whole-set discount no longer gets a hint).
    function renderSetHint() {
      const textEl = document.getElementById('list-set-text');
      // The whole-set discount is applied silently (see the totals); this
      // box now only carries the Tape Gun pair nudge.
      const have = new Set(list.map((l) => l.item));
      let html = '';
      // Pair deals (Tape Gun Holder + Add-On): once one is in the list
      // without the other, say so.
      const nudged = new Set();
      BUNDLES.forEach((bundle) => {
        const present = bundle.items.filter((i) => have.has(i));
        if (present.length > 0 && present.length < bundle.items.length) {
          present.forEach((i) => {
            const note = MERCH_PRICING.bundleNudges[i];
            if (note && !nudged.has(note)) { nudged.add(note); html += `<span class="shop-hint-extra">${note}</span>`; }
          });
        }
      });
      textEl.innerHTML = html;
      listSetHint.hidden = html === '';
    }

    function renderInvoiceNote() {
      if (list.length === 0 || !checkoutForm.hidden) { listInvoiceNote.hidden = true; return; }
      const groups = GROUPS.filter((g) => list.some((l) => groupKeyFor(l.item) === g.key));
      listInvoiceNote.hidden = false;
      listInvoiceNote.textContent = groups.length > 1
        ? 'Nothing is charged yet. You’ll get one invoice for the 3D-printed items and a separate one for the shirts & hats, since they’re made and shipped on different schedules.'
        : 'Nothing is charged yet. We’ll email your invoice with the final total and payment instructions.';
    }

    function renderAllTotals() {
      document.querySelectorAll('.shop-totals').forEach((el) => renderTotals(el));
    }

    function renderTotals(el) {
      const key = el.dataset.group;
      const pricing = pricingByGroup[key];
      const isShipping = merchFulfillment.value === 'Ship';
      const lines = list.filter((l) => groupKeyFor(l.item) === key);
      const roughSubtotal = lines.reduce((sum, l) => sum + lineSubtotal(l), 0);

      if (!pricing) {
        el.classList.add('is-stale');
        el.innerHTML = `<div class="shop-totals-row"><span>Subtotal</span><span>${formatMoney(roughSubtotal)}</span></div>`
          + '<div class="shop-totals-note">Working out tax and shipping&hellip;</div>';
        return;
      }
      el.classList.toggle('is-stale', !!pricing.stale);

      let html = `<div class="shop-totals-row"><span>Subtotal</span><span>${formatMoney(pricing.subtotal)}</span></div>`;
      if (pricing.bundleDiscount) {
        html += `<div class="shop-totals-row shop-totals-discount"><span>Bundle discount</span><span>&minus;${formatMoney(pricing.bundleDiscount)}</span></div>`;
      }
      if (pricing.fullSetDiscount) {
        html += `<div class="shop-totals-row shop-totals-discount"><span>Whole-set discount</span><span>&minus;${formatMoney(pricing.fullSetDiscount)}</span></div>`;
      }
      html += `<div class="shop-totals-row"><span>Tax (7%)</span><span>${formatMoney(pricing.tax)}</span></div>`;
      if (!isShipping) {
        html += '<div class="shop-totals-row"><span>Shipping</span><span>Pickup — none</span></div>';
        html += `<div class="shop-totals-row shop-totals-total"><span>Estimated total</span><span>${formatMoney(pricing.total)}</span></div>`;
      } else if (pricing.shipping !== null) {
        html += `<div class="shop-totals-row"><span><button type="button" class="shop-info-link" data-info="shipping">Shipping</button></span><span>${formatMoney(pricing.shipping)}</span></div>`;
        html += `<div class="shop-totals-row shop-totals-total"><span>Estimated total</span><span>${formatMoney(pricing.total)}</span></div>`;
      } else {
        html += '<div class="shop-totals-row"><span><button type="button" class="shop-info-link" data-info="shipping">Shipping</button></span><span>Quote needed</span></div>';
        html += `<div class="shop-totals-note">${pricing.shippingNote}</div>`;
        html += `<div class="shop-totals-row shop-totals-total"><span>Estimated total <small>(before shipping)</small></span><span>${formatMoney(pricing.total)}</span></div>`;
      }
      el.innerHTML = html;
    }

    // Combined pricing per invoice group - bundle discounts and the shared
    // box-capacity shipping tier depend on the WHOLE group at once, so it
    // is priced server-side by merch_list_price.php (same
    // merch_group_calculate() the admin invoice uses) rather than
    // re-derived in JS. Debounced so a burst of clicks makes one request;
    // the sequence number drops any response that a newer request has
    // already superseded.
    let pricingTimer = null;
    let pricingSeq = 0;

    function refreshListPricing() {
      if (pricingTimer) { clearTimeout(pricingTimer); pricingTimer = null; }
      if (list.length === 0) { pricingByGroup = {}; return; }
      // Keep showing the last numbers, dimmed, until fresh ones arrive.
      Object.values(pricingByGroup).forEach((p) => { p.stale = true; });
      renderAllTotals();
      pricingTimer = setTimeout(fetchListPricing, 200);
    }

    function fetchListPricing() {
      const requestId = ++pricingSeq;
      const body = new URLSearchParams();
      body.set('list', JSON.stringify(list.map((l) => ({
        item: l.item, quantity: l.quantity, size: l.size, sleeve: l.sleeve, color: l.color,
      }))));
      body.set('fulfillment', merchFulfillment.value);

      fetch('merch_list_price.php', { method: 'POST', body })
        .then((res) => res.json())
        .then((data) => {
          if (requestId !== pricingSeq) return;
          if (!data.ok) { pricingFallback(); return; }
          pricingByGroup = {};
          data.groups.forEach((g) => { pricingByGroup[g.key] = g; });
          renderAllTotals();
        })
        .catch(() => {
          if (requestId !== pricingSeq) return;
          pricingFallback();
        });
    }

    function pricingFallback() {
      pricingByGroup = {};
      document.querySelectorAll('.shop-totals').forEach((el) => {
        el.classList.remove('is-stale');
        el.innerHTML = '<div class="shop-totals-note">We couldn’t work out tax and shipping just now — you can still send your request, and we’ll confirm the total by email.</div>';
      });
    }

    // ============================================================
    // Request step: contact / shipping, revealed inside the list panel
    // ============================================================
    const checkoutForm = document.getElementById('merch-checkout-form');
    const listPayloadField = document.getElementById('list-payload-field');
    const submitBtn = document.getElementById('list-submit-btn');
    const submitHint = document.getElementById('submit-hint');
    const merchFulfillment = document.getElementById('merch-fulfillment');
    const shippingFields = document.getElementById('shipping-fields');
    const retreatFields = document.getElementById('retreat-fields');
    const merchRetreat = document.getElementById('merch-retreat');
    const f = {
      name: document.getElementById('merch-name'),
      email: document.getElementById('merch-email'),
      address: document.getElementById('merch-address'),
      city: document.getElementById('merch-city'),
      state: document.getElementById('merch-state'),
      zip: document.getElementById('merch-zip'),
    };
    const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    const ZIP_RE = /^\d{5}(-\d{4})?$/;

    function showCheckout() {
      checkoutForm.hidden = false;
      requestBtn.hidden = true;
      listInvoiceNote.hidden = true;
      updateCheckoutState();
      document.getElementById('checkout-heading').scrollIntoView({ block: 'start', behavior: 'smooth' });
      f.name.focus({ preventScroll: true });
    }

    function hideCheckout() {
      checkoutForm.hidden = true;
      requestBtn.hidden = false;
      renderInvoiceNote();
      requestBtn.focus();
    }

    requestBtn.addEventListener('click', showCheckout);
    document.getElementById('checkout-back-btn').addEventListener('click', hideCheckout);

    function updateShippingFieldsRequired() {
      const shipping = merchFulfillment.value === 'Ship';
      shippingFields.hidden = !shipping;
      [f.address, f.city, f.state, f.zip].forEach((el) => { el.required = shipping; });
      retreatFields.hidden = shipping;
      document.getElementById('ship-region-note').hidden = !shipping;
      merchRetreat.required = !shipping;
      // Switched back to Ship after picking a retreat - clear the stale
      // choice (merch_order.php blanks it for Ship orders too).
      if (shipping) merchRetreat.value = '';
    }

    // The Submit button stays off until everything needed is filled in and
    // looks right; the line above it says, in plain words, what is missing.
    function missingFields() {
      const missing = [];
      if (list.length === 0) missing.push('at least one item');
      if (!f.name.value.trim()) missing.push('your name');
      if (!EMAIL_RE.test(f.email.value.trim())) missing.push('a valid email address');
      if (merchFulfillment.value === 'Ship') {
        if (!f.address.value.trim()) missing.push('your street address');
        if (!f.city.value.trim()) missing.push('your city');
        if (!f.state.value) missing.push('your state');
        if (!ZIP_RE.test(f.zip.value.trim())) missing.push('a ZIP code');
      } else if (!merchRetreat.value) {
        missing.push('which retreat');
      }
      return missing;
    }

    function updateCheckoutState() {
      const missing = missingFields();
      submitBtn.disabled = missing.length > 0;
      submitHint.textContent = missing.length ? 'Still needed: ' + missing.join(', ') + '.' : '';
    }

    checkoutForm.addEventListener('input', updateCheckoutState);
    checkoutForm.addEventListener('change', updateCheckoutState);

    merchFulfillment.addEventListener('change', () => {
      updateShippingFieldsRequired();
      updateCheckoutState();
      // Ship vs. Pickup changes whether each group has a shipping line.
      refreshListPricing();
    });

    // Disable Submit the instant the form is sent, so a double-tap can't
    // create two orders. The form still submits normally.
    checkoutForm.addEventListener('submit', (event) => {
      if (missingFields().length > 0) {
        event.preventDefault();
        updateCheckoutState();
        return;
      }
      listPayloadField.value = JSON.stringify(list.map((l) => ({
        item: l.item, quantity: l.quantity, size: l.size, sleeve: l.sleeve, color: l.color,
      })));
      submitBtn.disabled = true;
      submitBtn.textContent = 'Sending…';
      try {
        window.localStorage.removeItem(LIST_STORAGE_KEY);
      } catch (err) {
        // Storage unavailable - nothing to clear.
      }
    });

    // ============================================================
    // Phone: the list panel is a sheet opened from the bottom bar
    // ============================================================
    const shopList = document.getElementById('shop-list');
    const shopScrim = document.getElementById('shop-scrim');
    const shopBarOpen = document.getElementById('shop-bar-open');
    const shopListClose = document.getElementById('shop-list-close');
    const phoneMQ = window.matchMedia('(max-width: 899px)');
    let sheetReturnFocus = null;

    function sheetIsOpen() {
      return shopList.classList.contains('is-open');
    }

    function syncBodyLock() {
      const anyOpen = !detailsModal.hidden || !infoModal.hidden || !photoViewerModal.hidden || sheetIsOpen();
      document.body.classList.toggle('lightbox-open', anyOpen);
    }

    function openSheet() {
      sheetReturnFocus = document.activeElement;
      shopList.classList.add('is-open');
      shopScrim.hidden = false;
      shopList.setAttribute('role', 'dialog');
      shopList.setAttribute('aria-modal', 'true');
      syncBodyLock();
      shopListClose.focus();
    }

    function closeSheet() {
      shopList.classList.remove('is-open');
      shopScrim.hidden = true;
      shopList.removeAttribute('role');
      shopList.removeAttribute('aria-modal');
      syncBodyLock();
      if (sheetReturnFocus && document.contains(sheetReturnFocus)) sheetReturnFocus.focus();
      sheetReturnFocus = null;
    }

    shopBarOpen.addEventListener('click', openSheet);
    shopListClose.addEventListener('click', closeSheet);
    shopScrim.addEventListener('click', closeSheet);
    // Rotating a tablet / resizing to desktop width: no sheet to leave open.
    phoneMQ.addEventListener('change', () => { if (!phoneMQ.matches && sheetIsOpen()) closeSheet(); });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        if (!photoViewerModal.hidden) { closePhotoViewer(); return; }
        if (!infoModal.hidden) { closeInfo(); return; }
        if (!detailsModal.hidden) { closeDetails(); return; }
        if (sheetIsOpen()) { closeSheet(); return; }
      }
      if (!photoViewerModal.hidden) {
        if (event.key === 'ArrowLeft') showPrevPhoto();
        if (event.key === 'ArrowRight') showNextPhoto();
        return;
      }
      if (!infoModal.hidden) { trapTabWithin(infoModal, event); return; }
      if (!detailsModal.hidden) { trapTabWithin(detailsModal, event); return; }
      if (sheetIsOpen()) trapTabWithin(shopList, event);
    });

    // ============================================================
    // Start-up: restore a saved list, then draw everything
    // ============================================================
    list = loadStoredList();
    updateShippingFieldsRequired();
    renderList();
  </script>

  <?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
