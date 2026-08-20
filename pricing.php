<?php
// Build: 2026-08-20-A
// ============================================================
// SINGLE SOURCE OF TRUTH for Southern Fireflies merch pricing.
//
// merch_order.php includes this file to calculate order totals.
// merch.php reads these same numbers via merch_price_display() and
// merch_pricing_for_js(), so there's exactly one place prices live.
// (merch.html, an old static duplicate of this page with hand-typed
// prices, was confirmed dead and removed from the server 2026-08-01.)
//
// 2026-08-01: the four manual-shipping-quote NOTE STRINGS below now
// load from /strings/shipping/*.txt via merch_load_string() instead
// of being hardcoded here AND separately hand-copied into merch.php's
// JS estimate. merch_pricing_for_js() bakes the resolved text into
// the JSON blob it hands to the browser, so the live JS estimate and
// the server-side email/CSV notes are now guaranteed to say the exact
// same thing - editing one of those four .txt files is now the only
// place this ever needs to change.
// ============================================================

require_once __DIR__ . '/strings.php';

// Base price per item. Shirts price by sleeve length, so those two
// items use an array keyed by sleeve instead of a flat number.
const MERCH_PRICES = [
    // Keys here must match the <select name="sleeve"> option values in
    // merch.php exactly ("Short Sleeve" / "Long Sleeve") - not a shortened
    // label - or price lookup silently falls back to the wrong price.
    // Bumped 2026-08-16 (Steve: supplier price increase) - was
    // Short 23 / Long 25 for both shirts before this.
    'Logo Shirt' => ['Short Sleeve' => 25, 'Long Sleeve' => 30],
    'Finding Your Way Shirt' => ['Short Sleeve' => 25, 'Long Sleeve' => 30],
    // New third shirt design added 2026-08-16 (Janet's "Mr. Firefly's 3D
    // Printed Gadgets" artwork) - same price as the other two shirts,
    // full Gildan color lineup like them too (see SHIRT_ITEMS/
    // COLORABLE_ITEMS below and in merch.php).
    'Mr. Firefly Shirt' => ['Short Sleeve' => 25, 'Long Sleeve' => 30],
    'Logo Hat' => 25,
    'Tool Holder Stand' => 12,
    'Circle Cutter Holder' => 18,
    'Oval Cutter Holder' => 18,
    'Rectangle Cutter Holder' => 18,
    'Tape Gun Holder' => 15,
];

const SHIRT_ITEMS = ['Logo Shirt', 'Finding Your Way Shirt', 'Mr. Firefly Shirt'];
// Canonical size/sleeve option lists - the shirts are the only items
// that use either (see SHIRT_ITEMS just above). Added 2026-08-20
// alongside merch_order.php's new server-side validation (Finding 2,
// 2026-08-19 code review), so a size/sleeve submitted for a non-shirt
// item, or a value that isn't one of these, gets rejected/cleared
// instead of stored as-is. Must match merch.php's <select name="size">
// / <select name="sleeve"> <option value="..."> strings exactly - same
// rule as GILDAN_COLORS/FILAMENT_COLORS below.
const MERCH_SIZES = ['S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'];
const MERCH_SLEEVE_LENGTHS = ['Short Sleeve', 'Long Sleeve'];
// The complete set of 3D-printed items - this is the single source of
// truth for "does this item pay to Steve's personal account." Do NOT
// use this list for shipping-tier or color-option decisions - those
// are BOX_SHIPPING_ITEMS and RAINBOW_ELIGIBLE_ITEMS below.
const PRINTED_ITEMS = ['Tool Holder Stand', 'Circle Cutter Holder', 'Oval Cutter Holder', 'Rectangle Cutter Holder', 'Tape Gun Holder'];
// Which printed items pack together into the box-capacity shipping
// tiers below (merch_printed_shipping()). Tape Gun Holder joined this
// list 2026-08-10, closing the "known gap" flagged 2026-08-04 - Steve
// now has real combo pricing for it (a Tool Stand + up to 3 riders
// including one Tape Gun Holder ships together), so it no longer needs
// the separate flat-shirt-rate fallback. The one thing that stays
// special about it is TAPE_GUN_MAX_QTY (below) - more than one in a
// shipment always forces a manual quote regardless of anything else.
const BOX_SHIPPING_ITEMS = ['Tool Holder Stand', 'Circle Cutter Holder', 'Oval Cutter Holder', 'Rectangle Cutter Holder', 'Tape Gun Holder'];
// Which of the printed items actually offer Rainbow filament as a
// color choice. Narrowed 2026-08-04 (Steve: it didn't look good on
// the Circle/Oval cards) to just the Tool Holder Stand and, as of the
// same date, the new Tape Gun Holder - this list is ONLY about the
// color option/surcharge; never use it for payment routing or
// shipping-tier detection, use PRINTED_ITEMS / BOX_SHIPPING_ITEMS /
// merch_is_printed_item() for those instead.
const RAINBOW_ELIGIBLE_ITEMS = ['Tool Holder Stand', 'Tape Gun Holder'];
const OVERSIZE_SURCHARGE_SIZES = ['3XL', '4XL', '5XL'];

// Canonical column order for merchandise.csv - the single source of
// truth for the header row. merch_order.php uses this to write a
// header into a brand-new/empty file and to build every new row keyed
// by column name rather than position (see code review 2026-08-19,
// Finding 1 - a positional writer here had silently shifted every
// column from Size onward in every web order, because a stale comment
// listed the pre-"Original Color" 24-column header while the live file
// had grown to 25). Every reader keys off the file's OWN header row at
// read time and doesn't need this constant - but if you ever add,
// rename, or reorder a column, update it here too so a from-scratch
// file starts correct, and add the matching key to merch_order.php's
// $values array or new orders will fail loudly (by design) instead of
// silently misaligning again.
const MERCH_CSV_HEADER = [
    'OrderID', 'Name', 'Email', 'Phone', 'Item', 'Quantity', 'Color',
    'Original Color', 'Size', 'Sleeve', 'Notes', 'Fulfillment', 'Address',
    'City', 'State', 'Zip', 'Price', 'Tax', 'Shipping', 'Invoice Date',
    'Pymt Date', 'Created', 'Fulfilled', 'Timestamp', 'IP',
];

// ------------------------------------------------------------
// Color option lists - added 2026-08-18 so ourmerch.php can offer an
// admin an editable color dropdown for an existing order, alongside
// merch.php's customer-facing request form. Which item gets which
// list mirrors the identically-named GILDAN_COLOR_ITEMS/
// FILAMENT_COLOR_ITEMS JS arrays in merch.php exactly - keep both in
// sync if an item ever moves between the two.
//
// The VALUE lists (GILDAN_COLORS/FILAMENT_COLORS below) are NOT read
// by merch.php - its <select> markup is still hand-written HTML,
// since the option text there needs &ndash; entities and optgroup
// labels the admin editor doesn't. But every value here must match
// one of merch.php's <option value="..."> strings EXACTLY, or an
// order placed from the live form won't validate when an admin later
// tries to edit its color. If you ever add, rename, or remove a color
// in merch.php, mirror the change here too.
//
// Numbers are zero-padded to two digits ('#01' not '#1') so a plain
// string sort of the Color column lands in the same order the color
// charts display them in. If you ever add a color, pad its number the
// same way (both lists top out under 100, so two digits always cover
// it). Historical merchandise.csv rows written before this convention
// may still hold unpadded values ('#1 Red') - those are treated as
// legacy data, not errors, but won't string-sort correctly until
// corrected by hand.
// ------------------------------------------------------------
const GILDAN_COLOR_ITEMS = ['Logo Shirt', 'Finding Your Way Shirt', 'Mr. Firefly Shirt', 'Logo Hat'];
const FILAMENT_COLOR_ITEMS = ['Tool Holder Stand', 'Circle Cutter Holder', 'Oval Cutter Holder', 'Rectangle Cutter Holder', 'Tape Gun Holder'];

const GILDAN_COLORS = [
    '#01 White', '#02 Ice Gray', '#03 Sport Gray', '#06 Graphite Heather', '#07 Dark Heather', '#08 Charcoal',
    '#04 Natural', '#05 Sand',
    '#09 Cornsilk', '#10 Daisy', '#11 Gold',
    '#12 Heather Orange', '#13 Orange',
    '#14 Light Pink', '#15 Azalea', '#16 Coral Silk', '#17 Heather Coral Silk', '#18 Heather Heliconia', '#19 Heliconia', '#20 Antique Heliconia',
    '#21 Heather Bronze', '#22 Berry', '#23 Heather Maroon', '#24 Heather Red', '#25 Antique Cherry Red', '#26 Cherry Red', '#27 Heather Cardinal', '#28 Red', '#29 Maroon', '#30 Cardinal',
    '#31 Pistachio', '#32 Mint Green', '#33 Lime', '#34 Heather Military Green', '#35 Heather Seafoam', '#36 Sage', '#37 Heather Irish Green', '#38 Kiwi', '#39 Electric Green', '#40 Olive', '#41 Military Green', '#42 Kelly Green', '#43 Jade Dome', '#44 Heather Forest Green', '#45 Irish Green', '#46 Forest',
    '#47 Light Blue', '#48 Iris', '#49 Sky', '#50 Carolina Blue', '#51 Antique Sapphire', '#52 Heather Indigo', '#53 Sapphire', '#54 Indigo', '#55 Heather Galapagos Blue', '#56 Metro Blue', '#57 Heather Sapphire', '#58 Tropical Blue', '#59 Heather Royal', '#60 Royal', '#61 Heather Navy', '#62 Navy',
    '#63 Heather Berry', '#64 Heather Radiant Orchid', '#65 Heather Purple', '#66 Purple', '#67 Blackberry', '#68 Paragon',
    '#69 Dark Chocolate', '#70 Black',
    'Not applicable / no color choice',
];

const FILAMENT_COLORS = [
    '#01 Red', '#02 Coral', '#03 Maroon', '#04 Orange', '#05 Silk Orange', '#06 Yellow', '#07 Gold', '#08 Hot Pink', '#09 Magenta',
    '#10 Light Pink', '#11 Plum', '#12 Purple', '#13 Lilac', '#14 Sky Blue', '#15 CM Blue', '#16 Navy Blue', '#17 Teal', '#18 Silk Green',
    '#19 Green', '#20 Light Green', '#21 Olive Green', '#22 Black', '#23 Gray', '#24 Ice', '#25 White', '#26 Tan', '#27 Brown',
    // Rainbow lives only in the filament list (it's a print option, not
    // a garment color) - merch_color_options_for_item() below strips it
    // back out again for a filament item that isn't in
    // RAINBOW_ELIGIBLE_ITEMS, same restriction the live form enforces.
    'Rainbow (+$2)',
    'Not applicable / no color choice',
];

/**
 * The list of valid color values for a given item: GILDAN_COLORS,
 * FILAMENT_COLORS (with Rainbow removed unless the item is actually
 * RAINBOW_ELIGIBLE_ITEMS), or an empty array if the item doesn't offer
 * a color choice at all. Used by merch_update.php to validate an
 * admin's color edit, and by ourmerch.php to build that edit dropdown -
 * so an order's color can only ever be changed to a value that item's
 * own request-form dropdown would have allowed in the first place.
 */
function merch_color_options_for_item(string $item): array
{
    if (in_array($item, GILDAN_COLOR_ITEMS, true)) {
        return GILDAN_COLORS;
    }
    if (in_array($item, FILAMENT_COLOR_ITEMS, true)) {
        if (in_array($item, RAINBOW_ELIGIBLE_ITEMS, true)) {
            return FILAMENT_COLORS;
        }
        return array_values(array_diff(FILAMENT_COLORS, ['Rainbow (+$2)']));
    }
    return [];
}

// ------------------------------------------------------------
// Printed-item shipping tiers (Steve's items only - shirts/hats
// still use the simple FLAT_SHIPPING_RATE/FLAT_SHIPPING_MAX_QTY
// rule below, since that's Janet's side and hasn't been revisited).
//
// This is a real packaging constraint, not an arbitrary item-count
// rule: Circle/Oval/Rectangle/Tape Gun holders are thin enough to
// ship in a small poly mailer; a Tool Holder Stand is too fragile for
// that and always needs a box. Once a box is being sent anyway, more
// small items ride along inside it for free/cheap. Tiers, per Steve
// (2026-07-24, expanded 2026-08-10 with real weights once order
// volume gave him enough data to calibrate):
//   - 0 Tool Stands, 1-2 small items  -> small mailer,  $6
//   - 0 Tool Stands, 3-4 small items  -> bigger mailer, $10
//   - 0 Tool Stands, 5+ small items   -> manual quote
//   - 1 Tool Stand,  0-2 small items  -> one box,       $10
//   - 1 Tool Stand,  3-4 small items  -> bigger box,    $12 (new 2026-08-10)
//   - 1 Tool Stand,  5+ small items   -> manual quote (exceeds the box)
//   - 2+ Tool Stands, any small items -> manual quote, always
//   - 2+ Tape Gun Holders, ANY combo  -> manual quote, always (bulky
//     item, same "multiples need hand-packing" rule as Tool Stand -
//     checked before anything else below, regardless of what else is
//     in the shipment or which tier it would otherwise land in)
// ------------------------------------------------------------
const TOOL_STAND_ITEM = 'Tool Holder Stand';
const TAPE_GUN_ITEM = 'Tape Gun Holder';
// Originally just Circle/Oval Cutter Holders - Rectangle joined
// 2026-08-09, Tape Gun Holder joined 2026-08-10 once Steve had real
// combo weights to calibrate against. Anything in this list is a
// small, flat printed item that fits the poly-mailer/box tiers below;
// it has nothing to do with color options or payment routing - see
// PRINTED_ITEMS and RAINBOW_ELIGIBLE_ITEMS for those.
const MAILER_TIER_ITEMS = ['Circle Cutter Holder', 'Oval Cutter Holder', 'Rectangle Cutter Holder', 'Tape Gun Holder'];
// More than one Tape Gun Holder in a shipment always forces a manual
// quote, regardless of what else is in the group or how few total
// items that'd otherwise be - it's bulkier than the other small items
// and multiples need to be hand-packed (Steve, 2026-08-10), same
// principle as 2+ Tool Stands always being manual.
const TAPE_GUN_MAX_QTY = 1;
const PRINTED_SHIP_RATE_MAILER = 6;  // 1-2 small items, no Tool Stand
const PRINTED_SHIP_RATE_BOX = 10;    // a box is involved either way (1 Tool Stand + 0-2 riders, or 3-4 small items alone)
const PRINTED_SHIP_RATE_BOX_EXPANDED = 12; // 1 Tool Stand + 3-4 riders - bigger box needed (new 2026-08-10)
const CIRCLE_OVAL_MAILER_MAX = 2;
const CIRCLE_OVAL_ALONE_MAX = 4;     // above this, even with no Tool Stand, it's a manual quote
const CIRCLE_OVAL_WITH_TOOL_STAND_MAX = 2; // how many can ride along in the Tool Stand's box at the base $10 rate
const CIRCLE_OVAL_WITH_TOOL_STAND_EXPANDED_MAX = 4; // above CIRCLE_OVAL_WITH_TOOL_STAND_MAX but up to this many -> PRINTED_SHIP_RATE_BOX_EXPANDED instead of manual

// ------------------------------------------------------------
// Real per-unit weights (oz), by Steve (2026-08-10; Tool Holder Stand
// added 2026-08-19), for shippo_export.php.
//
// The automatic mailer-tier Order Weight/Package dimensions fill-in
// (merch_printed_shipping()-adjacent logic in shippo_export.php) only
// sums the four small items below - Tool Holder Stand shipments always
// use scavenged one-off boxes of varying size, so Order Weight/
// dimensions stay hand-measured by Steve directly in Shippo regardless
// of having a weight here. Tool Holder Stand's entry is read-only for
// the export's per-LINE Item Weight column instead (added 2026-08-19,
// so Steve can see each item's own weight - Tool Stand included - when
// hand-grouping items into a box); it does NOT feed the mailer-tier
// tare/weight math the other four items do, since that logic branches
// on TOOL_STAND_ITEM before it ever looks at this table.
//
// Replaced the old count-indexed POLY_MAILER_WEIGHT_OZ table (which
// assumed every item weighed the same ~2oz) once mixed combos of
// different-weight items became common enough that a per-count lookup
// was no longer accurate - Rectangle and Tape Gun Holder are real
// ounces heavier than Circle/Oval.
// ------------------------------------------------------------
const ITEM_WEIGHT_OZ = [
    'Circle Cutter Holder' => 2,
    'Oval Cutter Holder' => 2,
    'Rectangle Cutter Holder' => 3,
    'Tape Gun Holder' => 3,
    'Tool Holder Stand' => 8,
];
// Packaging/padding weight added on top of the items themselves.
// Validated against several real historical single-item-type
// shipments (1-4 Circle/Oval at an assumed uniform 2oz/unit implied a
// consistent ~1oz tare); kept at that same value for the new mixed
// combos rather than re-derived from Steve's one rough "~10.5oz
// together" estimate for the 4-item mixed case, since that was an
// approximation, not a scale reading.
const MAILER_TARE_OZ = 1;
const POLY_MAILER_WIDTH_IN = 8.5;
const POLY_MAILER_LENGTH_IN = 11;
// Added 2026-08-15: Package Height used to be left blank for these
// same mailer shipments on the theory that "poly mailers are flat, no
// real depth to guess." In practice Shippo's bulk importer treats a
// missing Height the same as a missing Width or Length - it refused
// to create a parcel for ANY of these rows and reported "package
// dimensions incomplete" on every one, even though Weight/Width/
// Length were all correctly filled in (confirmed 2026-08-15 by
// cross-referencing Steve's Shippo import-error order numbers against
// merchandise.csv - 7 of the 10 failures were mailer-tier shipments
// like this one, not the genuinely bulky Tool-Stand/manual-quote
// kind). A stuffed poly mailer does have some real thickness, so this
// is a nominal estimate, not a guess at zero - adjust this one
// constant if it doesn't match what you're actually taping shut.
const POLY_MAILER_HEIGHT_IN = 1;

/**
 * Shipping for a group of printed items, given the combined quantity
 * of Tool Stands, all mailer-tier small items combined (Circle/Oval/
 * Rectangle/Tape Gun), and Tape Gun Holders specifically (a subset of
 * the mailer-tier count, checked separately for the bulky-item cap).
 * Could be a single order line, or several combined into one invoice.
 * Returns ['amount' => float|null, 'note' => string] - amount is null
 * when it needs a manual quote instead of a flat rate, same
 * convention as the rest of this file.
 */
function merch_printed_shipping(int $toolStandQty, int $mailerTierQty, int $tapeGunQty): array
{
    // Bulky-item rule checked FIRST, before anything else - more than
    // one Tape Gun Holder always forces a manual quote regardless of
    // what else is (or isn't) in the shipment.
    if ($tapeGunQty > TAPE_GUN_MAX_QTY) {
        return ['amount' => null, 'note' => merch_load_string('shipping/manual-quote-multiple-tapeguns')];
    }

    if ($toolStandQty >= 2) {
        return ['amount' => null, 'note' => merch_load_string('shipping/manual-quote-multiple-toolstands')];
    }

    if ($toolStandQty === 1) {
        if ($mailerTierQty <= CIRCLE_OVAL_WITH_TOOL_STAND_MAX) {
            return ['amount' => (float) PRINTED_SHIP_RATE_BOX, 'note' => ''];
        }
        if ($mailerTierQty <= CIRCLE_OVAL_WITH_TOOL_STAND_EXPANDED_MAX) {
            return ['amount' => (float) PRINTED_SHIP_RATE_BOX_EXPANDED, 'note' => ''];
        }
        return ['amount' => null, 'note' => merch_load_string('shipping/manual-quote-toolstand-plus-extra', ['maxCircleOval' => CIRCLE_OVAL_WITH_TOOL_STAND_EXPANDED_MAX])];
    }

    // No Tool Stand in this group.
    if ($mailerTierQty <= CIRCLE_OVAL_MAILER_MAX) {
        return ['amount' => (float) PRINTED_SHIP_RATE_MAILER, 'note' => ''];
    }
    if ($mailerTierQty <= CIRCLE_OVAL_ALONE_MAX) {
        return ['amount' => (float) PRINTED_SHIP_RATE_BOX, 'note' => ''];
    }
    return ['amount' => null, 'note' => merch_load_string('shipping/manual-quote-too-many-circleoval', ['maxCircleOval' => CIRCLE_OVAL_ALONE_MAX])];
}

/**
 * The "orders over N items don't qualify for flat-rate shipping" note -
 * used by both merch_calculate() (single line) and
 * merch_group_calculate() (combined lines), so it's one function
 * rather than the same merch_load_string() call typed out twice.
 */
function merch_flat_rate_exceeded_note(): string
{
    return merch_load_string('shipping/manual-quote-over-flatrate-qty', ['maxQty' => FLAT_SHIPPING_MAX_QTY]);
}

const OVERSIZE_SURCHARGE = 3;
const RAINBOW_SURCHARGE = 2;
const TAX_RATE = 0.07; // South Carolina - not accounting for other-state nexus rules
const FLAT_SHIPPING_RATE = 6;
const FLAT_SHIPPING_MAX_QTY = 2; // orders above this need a manual shipping quote instead

// Sanity caps - not pricing rules exactly, but they live here so
// merch.php (the form) and merch_order.php (the handler) both enforce
// the same numbers instead of drifting apart.
const MAX_QUANTITY = 25; // no legitimate single request needs more than this
const NOTES_MAX_LENGTH = 500; // characters

/**
 * Per-unit price for one item, given its size/sleeve/color choices.
 * Returns null if the item name isn't recognized (guards against
 * tampered POST data - shouldn't happen from the live form).
 */
function merch_unit_price(string $item, string $size, string $sleeve, string $color): ?float
{
    if (!isset(MERCH_PRICES[$item])) {
        return null;
    }

    $base = MERCH_PRICES[$item];
    if (in_array($item, SHIRT_ITEMS, true)) {
        $price = $base[$sleeve] ?? $base['Short Sleeve']; // default to short sleeve if somehow missing
    } else {
        $price = $base;
    }

    if (in_array($item, SHIRT_ITEMS, true) && in_array($size, OVERSIZE_SURCHARGE_SIZES, true)) {
        $price += OVERSIZE_SURCHARGE;
    }

    if (in_array($item, RAINBOW_ELIGIBLE_ITEMS, true) && $color === 'Rainbow (+$2)') {
        $price += RAINBOW_SURCHARGE;
    }

    return $price;
}

/**
 * True if an item is one of the 3D-printed items (paid to Stephen's
 * personal Venmo/PayPal, per config.php's *_PRINTED constants), false
 * if it's a shirt/hat (paid to Janet's accounts). Also used to pick
 * box-capacity shipping tiers vs. the flat shirt/hat rate. Uses
 * PRINTED_ITEMS, NOT RAINBOW_ELIGIBLE_ITEMS - those two lists used to
 * be identical by coincidence and shared this function, but they
 * diverged 2026-08-04 when Rainbow was removed from two of the three
 * printed items. Payment routing and shipping tiers must stay based
 * on "is this item printed," not "does this item offer Rainbow."
 */
function merch_is_printed_item(string $item): bool
{
    return in_array($item, PRINTED_ITEMS, true);
}

/**
 * HTML price line for a merch card - used by merch.php so the displayed
 * price text is generated from the same numbers as the order calculation,
 * instead of being typed separately as static text.
 */
function merch_price_display(string $item): string
{
    if (!isset(MERCH_PRICES[$item])) {
        return '';
    }
    $base = MERCH_PRICES[$item];
    if (in_array($item, SHIRT_ITEMS, true)) {
        return '$' . $base['Short Sleeve'] . ' short sleeve / $' . $base['Long Sleeve'] . ' long sleeve '
            . '<span class="merch-price-note">(+$' . OVERSIZE_SURCHARGE . ' for size 3XL and up), plus tax and shipping</span>';
    }
    return '$' . $base . ' <span class="merch-price-note">plus tax and shipping</span>';
}

/**
 * Bundles the pricing constants into a plain array for json_encode(), so
 * the same numbers can drive a live JavaScript total estimate in the
 * browser. Note: this hands the *data* to JS, not the calculation logic
 * itself - merch_calculate()'s arithmetic is mirrored in JS separately
 * (calculateEstimate() in merch.php) so the page can update live without
 * a round trip to the server. If you ever change the surcharge *rules*
 * (not just the numbers), both places need updating.
 */
function merch_pricing_for_js(): array
{
    return [
        'prices' => MERCH_PRICES,
        'shirtItems' => SHIRT_ITEMS,
        'rainbowEligibleItems' => RAINBOW_ELIGIBLE_ITEMS,
        'printedItems' => PRINTED_ITEMS,
        'boxShippingItems' => BOX_SHIPPING_ITEMS,
        'oversizeSurchargeSizes' => OVERSIZE_SURCHARGE_SIZES,
        'oversizeSurcharge' => OVERSIZE_SURCHARGE,
        'rainbowSurcharge' => RAINBOW_SURCHARGE,
        'taxRate' => TAX_RATE,
        'flatShippingRate' => FLAT_SHIPPING_RATE,
        'flatShippingMaxQty' => FLAT_SHIPPING_MAX_QTY,
        'maxQuantity' => MAX_QUANTITY,
        'toolStandItem' => TOOL_STAND_ITEM,
        'tapeGunItem' => TAPE_GUN_ITEM,
        'tapeGunMaxQty' => TAPE_GUN_MAX_QTY,
        'mailerTierItems' => MAILER_TIER_ITEMS,
        'printedShipRateMailer' => PRINTED_SHIP_RATE_MAILER,
        'printedShipRateBox' => PRINTED_SHIP_RATE_BOX,
        'printedShipRateBoxExpanded' => PRINTED_SHIP_RATE_BOX_EXPANDED,
        'circleOvalMailerMax' => CIRCLE_OVAL_MAILER_MAX,
        'circleOvalAloneMax' => CIRCLE_OVAL_ALONE_MAX,
        'circleOvalWithToolStandMax' => CIRCLE_OVAL_WITH_TOOL_STAND_MAX,
        'circleOvalWithToolStandExpandedMax' => CIRCLE_OVAL_WITH_TOOL_STAND_EXPANDED_MAX,
        // Resolved shipping-note TEXT (not just the numbers above) so
        // merch.php's live JS estimate shows the exact same wording as
        // the real email/CSV notes - one source (the /strings/shipping
        // .txt files), read once here, instead of a hand-typed copy
        // living in the JS too. See merch_printed_shipping() and
        // merch_flat_rate_exceeded_note() above for where each of
        // these comes from server-side.
        'shippingNotes' => [
            'multipleTapeGuns' => merch_load_string('shipping/manual-quote-multiple-tapeguns'),
            'multipleToolStands' => merch_load_string('shipping/manual-quote-multiple-toolstands'),
            'toolStandPlusExtra' => merch_load_string('shipping/manual-quote-toolstand-plus-extra', ['maxCircleOval' => CIRCLE_OVAL_WITH_TOOL_STAND_EXPANDED_MAX]),
            'tooManyCircleOvalAlone' => merch_load_string('shipping/manual-quote-too-many-circleoval', ['maxCircleOval' => CIRCLE_OVAL_ALONE_MAX]),
            'overFlatRateQty' => merch_flat_rate_exceeded_note(),
        ],
    ];
}

/**
 * Combined total across several line items for the same customer -
 * used by merch_invoice.php to invoice everything someone ordered
 * (that hasn't been invoiced yet) in one email instead of one per
 * item request.
 *
 * $items is an array of ['item'=>, 'quantity'=>, 'size'=>, 'sleeve'=>, 'color'=>].
 * All items passed in must share one fulfillment method (Ship or
 * Pickup) - that's a property of the whole group, not each line, so
 * it's a separate $isShipping argument rather than a per-item field.
 *
 * Tax and shipping are calculated on the COMBINED subtotal/quantity,
 * not per line - so this reuses FLAT_SHIPPING_MAX_QTY against the
 * total item count across the group. That means 3 different items at
 * qty 1 each falls to a manual shipping quote just like one item at
 * qty 3 would, on the assumption a bigger combined order needs its
 * own shipping look, not automatic flat-rate stacking.
 *
 * Returns null if any item in the group isn't recognized.
 *
 * $isPrinted is still required in the signature (callers already have
 * it for payment routing/account selection) but is NOT used inside
 * this function for the shipping-tier decision - that keys off
 * whether the group actually contains a BOX_SHIPPING_ITEMS item.
 * Kept as a parameter for call-site clarity even though this
 * function's body doesn't reference it.
 */
function merch_group_calculate(array $items, bool $isShipping, bool $isPrinted): ?array
{
    $lines = [];
    $subtotal = 0.0;
    $totalQuantity = 0;
    $toolStandQty = 0;
    $mailerTierQty = 0;
    $tapeGunQty = 0;

    foreach ($items as $it) {
        $unitPrice = merch_unit_price($it['item'], $it['size'] ?? '', $it['sleeve'] ?? '', $it['color'] ?? '');
        if ($unitPrice === null) {
            return null;
        }
        $qty = (int)$it['quantity'];
        $lineSubtotal = $unitPrice * $qty;

        $lines[] = [
            'item' => $it['item'],
            'quantity' => $qty,
            'unitPrice' => $unitPrice,
            'lineSubtotal' => $lineSubtotal,
        ];

        $subtotal += $lineSubtotal;
        $totalQuantity += $qty;

        if ($it['item'] === TOOL_STAND_ITEM) {
            $toolStandQty += $qty;
        } elseif (in_array($it['item'], MAILER_TIER_ITEMS, true)) {
            $mailerTierQty += $qty;
            // Tape Gun Holder is a subset of the mailer-tier count above -
            // tracked separately too, since it has its own bulky-item cap
            // (TAPE_GUN_MAX_QTY) checked inside merch_printed_shipping()
            // regardless of the overall mailer-tier total.
            if ($it['item'] === TAPE_GUN_ITEM) {
                $tapeGunQty += $qty;
            }
        }
    }

    $tax = round($subtotal * TAX_RATE, 2);

    $shipping = null;
    $shippingNote = '';
    if ($isShipping) {
        // Box-capacity tiers apply only when the group actually
        // contains a Tool Stand and/or a mailer-tier item - NOT just
        // because $isPrinted is true (a printed item outside
        // BOX_SHIPPING_ITEMS would fall through to the flat-rate
        // branch below instead, same as a shirt/hat, though as of
        // 2026-08-10 every printed item is in BOX_SHIPPING_ITEMS).
        if ($toolStandQty > 0 || $mailerTierQty > 0) {
            $shipInfo = merch_printed_shipping($toolStandQty, $mailerTierQty, $tapeGunQty);
            $shipping = $shipInfo['amount'];
            $shippingNote = $shipInfo['note'];
        } elseif ($totalQuantity <= FLAT_SHIPPING_MAX_QTY) {
            $shipping = FLAT_SHIPPING_RATE;
        } else {
            $shippingNote = merch_flat_rate_exceeded_note();
        }
    }

    $total = $subtotal + $tax + ($shipping ?? 0);

    return [
        'lines' => $lines,
        'subtotal' => $subtotal,
        'tax' => $tax,
        'shipping' => $shipping,
        'shippingNote' => $shippingNote,
        'total' => $total,
        'totalQuantity' => $totalQuantity,
    ];
}

/**
 * Full order total. Returns null if the item isn't recognized.
 *
 * 'shipping' is null (NOT 0) when the order exceeds the flat-rate
 * quantity cap - that's the signal to leave Shipping blank in the
 * CSV and tell the customer we'll follow up with a shipping quote
 * instead of charging the flat rate. Check for null, not falsy,
 * when reading this elsewhere.
 */
function merch_calculate(string $item, int $quantity, string $size, string $sleeve, string $color, bool $isShipping): ?array
{
    $unitPrice = merch_unit_price($item, $size, $sleeve, $color);
    if ($unitPrice === null) {
        return null;
    }

    $subtotal = $unitPrice * $quantity;
    $tax = round($subtotal * TAX_RATE, 2);

    $shipping = null;
    $shippingNote = '';
    if ($isShipping) {
        // Box-capacity tiers only for the items that actually pack into
        // that combo (see BOX_SHIPPING_ITEMS comment in the constants
        // above) - as of 2026-08-10 that's every printed item.
        if (in_array($item, BOX_SHIPPING_ITEMS, true)) {
            $toolStandQty = ($item === TOOL_STAND_ITEM) ? $quantity : 0;
            $mailerTierQty = in_array($item, MAILER_TIER_ITEMS, true) ? $quantity : 0;
            $tapeGunQty = ($item === TAPE_GUN_ITEM) ? $quantity : 0;
            $shipInfo = merch_printed_shipping($toolStandQty, $mailerTierQty, $tapeGunQty);
            $shipping = $shipInfo['amount'];
            $shippingNote = $shipInfo['note'];
        } elseif ($quantity <= FLAT_SHIPPING_MAX_QTY) {
            $shipping = FLAT_SHIPPING_RATE;
        } else {
            $shippingNote = merch_flat_rate_exceeded_note();
        }
    }

    $total = $subtotal + $tax + ($shipping ?? 0);

    return [
        'unitPrice' => $unitPrice,
        'subtotal' => $subtotal,
        'tax' => $tax,
        'shipping' => $shipping,
        'total' => $total,
        'shippingNote' => $shippingNote,
    ];
}
