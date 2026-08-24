<?php
// Build: 2026-08-21-B
// ============================================================
// SINGLE SOURCE OF TRUTH for Southern Fireflies merch pricing.
//
// 2026-08-21 (card/catalog redesign): per-ITEM constants became
// per-CLASS definitions (MERCH_CLASSES below). WHICH items exist now
// comes from the /items/ folder scan (merch_items.php, require'd at
// the bottom of this file), where each folder's item.txt declares its
// canonical name and class. merch_items.php then define()s the
// familiar per-item lists (MERCH_PRICES, SHIRT_ITEMS, PRINTED_ITEMS,
// ITEM_WEIGHT_OZ, ...) as projections of catalog x class, under the
// same names this codebase has always read. Adding an item of an
// EXISTING class is now just dropping a folder - this file only needs
// touching when a new KIND of item shows up (new class), or a price/
// weight/rule changes.
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

// ------------------------------------------------------------
// ITEM CLASSES - the kind-level truth. Every orderable item folder
// under /items/ declares one of these classes in its item.txt; the
// class carries every money/logistics attribute for that KIND of item.
//
// Attributes:
//   price       flat number, OR an array keyed by sleeve length
//               ("Short Sleeve"/"Long Sleeve" - must match merch.php's
//               <select name="sleeve"> option values exactly, or price
//               lookup silently falls back to the wrong price)
//   weight_oz   per-unit weight for shippo_export.php, null = no entry
//               in the weight table (shirts/hats - Steve hand-weighs)
//   printed     true = 3D-printed, pays Steve's personal accounts
//               (config.php *_PRINTED constants); false = Janet's side
//   colors      'gildan' | 'filament' | 'none' - which color chart/
//               dropdown the item uses
//   rainbow     offers the Rainbow filament option (+RAINBOW_SURCHARGE)
//   stars_stripes  offers Stars & Stripes (+STARS_STRIPES_SURCHARGE)
//   shipping    'mailer_tier' = small flat printed item, packs into
//               the poly-mailer/box tiers (merch_printed_shipping());
//               'box_base'    = needs its own box, small items ride
//                               along inside it;
//               'flat_rate'   = FLAT_SHIPPING_RATE up to
//                               FLAT_SHIPPING_MAX_QTY (shirts/hats)
//   max_qty_per_shipment  more than this many in one shipment always
//               forces a manual quote (bulky items that need
//               hand-packing), null = no cap. max_qty_note is the
//               strings/ key for the customer-facing note when the
//               cap trips.
//   sizes/sleeves  true = the request form asks for size/sleeve and
//               the server validates them (MERCH_SIZES /
//               MERCH_SLEEVE_LENGTHS below)
//   oversize_surcharge  true = +OVERSIZE_SURCHARGE for
//               OVERSIZE_SURCHARGE_SIZES sizes
//
// History preserved from the per-item era: shirt prices bumped
// 2026-08-16 (supplier increase; was Short 23 / Long 25). Rainbow
// narrowed to Tool Stand + Tape Gun 2026-08-04 (didn't look good on
// the cutter holders). Stars & Stripes added 2026-08-20 on the four
// small items, not Tool Stand (never printed in that color). The
// max_qty_per_shipment caps replace TAPE_GUN_MAX_QTY (2026-08-10)
// AND the hardcoded "2+ Tool Stands -> manual" branch - same
// hand-packing rule, now written once as a class attribute.
// ------------------------------------------------------------
const MERCH_CLASSES = [
    'cutter-holder' => [
        'price' => 18,
        'weight_oz' => 2, // Circle/Oval; Rectangle overrides to 3 below
        'printed' => true,
        'colors' => 'filament',
        'rainbow' => false,
        'stars_stripes' => true,
        'shipping' => 'mailer_tier',
        'max_qty_per_shipment' => null,
        'max_qty_note' => null,
        'sizes' => false,
        'sleeves' => false,
        'oversize_surcharge' => false,
    ],
    'tape-gun-holder' => [
        // Bumped 15 -> 18 on 2026-08-21 (Steve), alongside the new Tape
        // Gun Add-On launch - see the 'tape-gun-addon' class below and
        // MERCH_BUNDLES for the buy-both discount.
        'price' => 18,
        'weight_oz' => 3,
        'printed' => true,
        'colors' => 'filament',
        'rainbow' => true,
        'stars_stripes' => true,
        'shipping' => 'mailer_tier',
        'max_qty_per_shipment' => 1,
        'max_qty_note' => 'shipping/manual-quote-multiple-tapeguns',
        'sizes' => false,
        'sleeves' => false,
        'oversize_surcharge' => false,
    ],
    // First "extension" product (2026-08-21): an accessory to the Tape
    // Gun Holder that can also be bought alone. A small flat printed
    // item like the cutter holders (mailer-tier, no bulky cap), full
    // filament color story including BOTH premium prints. The
    // buy-it-with-the-holder discount is NOT a class concern - that's
    // cross-item pricing, and it lives in MERCH_BUNDLES below.
    'tape-gun-addon' => [
        'price' => 10,
        'weight_oz' => 1.5, // Steve's scale, 2026-08-21. Holder+add-on sums
                            // to 4.5oz vs. his rough 4oz pair reading - his
                            // scale rounds; erring high is postage-safe.
        'printed' => true,
        'colors' => 'filament',
        'rainbow' => true,
        'stars_stripes' => true,
        'shipping' => 'mailer_tier',
        'max_qty_per_shipment' => null,
        'max_qty_note' => null,
        'sizes' => false,
        'sleeves' => false,
        'oversize_surcharge' => false,
    ],
    'tool-stand' => [
        'price' => 12,
        // Weight is read for the Shippo export's per-LINE Item Weight
        // column only - box_base shipments always use scavenged one-off
        // boxes, so Order Weight/dimensions stay hand-measured by Steve
        // in Shippo (see shippo_export.php). The mailer-tier weight
        // math never sums this class because it only sums
        // 'mailer_tier'-class items.
        'weight_oz' => 8,
        'printed' => true,
        'colors' => 'filament',
        'rainbow' => true,
        'stars_stripes' => false,
        'shipping' => 'box_base',
        'max_qty_per_shipment' => 1,
        'max_qty_note' => 'shipping/manual-quote-multiple-toolstands',
        'sizes' => false,
        'sleeves' => false,
        'oversize_surcharge' => false,
    ],
    'shirt' => [
        'price' => ['Short Sleeve' => 25, 'Long Sleeve' => 30],
        'weight_oz' => null,
        'printed' => false,
        'colors' => 'gildan',
        'rainbow' => false,
        'stars_stripes' => false,
        'shipping' => 'flat_rate',
        'max_qty_per_shipment' => null,
        'max_qty_note' => null,
        'sizes' => true,
        'sleeves' => true,
        'oversize_surcharge' => true,
    ],
    'hat' => [
        'price' => 25,
        'weight_oz' => null,
        'printed' => false,
        'colors' => 'gildan',
        'rainbow' => false,
        'stars_stripes' => false,
        'shipping' => 'flat_rate',
        'max_qty_per_shipment' => null,
        'max_qty_note' => null,
        'sizes' => false,
        'sleeves' => false,
        'oversize_surcharge' => false,
    ],
];

// Per-item exceptions to the class defaults, keyed by canonical item
// name; any class attribute may appear here. Deliberately in THIS file
// (not the item folders) so every money/logistics fact stays in
// pricing.php - see the 2026-08-21 redesign discussion. If an item
// ever needs more than two or three overrides, that's the signal it
// deserves its own class instead.
const MERCH_ITEM_OVERRIDES = [
    // Real ounces heavier than Circle/Oval (Steve's scale, 2026-08-10) -
    // the difference is real money in the mailer-tier weight math.
    'Rectangle Cutter Holder' => ['weight_oz' => 3],
];

// ------------------------------------------------------------
// BUNDLES (2026-08-21, first one: the Tape Gun Add-On launch) -
// cross-ITEM pricing, the one thing classes deliberately don't carry.
// Each entry: buy every item in 'items' together and 'discount' comes
// off the combined subtotal, once per complete set (a group with 2
// holders + 1 add-on earns ONE discount; 2 + 2 earns two). Keyed by
// canonical item NAME, not class - merch_items.php logs an error for
// any name here that doesn't match an item folder, same fail-loud
// rule as MERCH_ITEM_OVERRIDES.
//
// Where it applies: merch_group_calculate() ONLY - the same place
// cross-item shipping already lives. A single request is always one
// item, so the discount naturally lands when orders are combined at
// invoice time, which is exactly what the site already tells
// customers ("we'll combine everything into a single total when we
// follow up"). The live estimate shows a nudge instead (see
// merch_pricing_for_js() and strings/pages/merch-bundle-nudge.txt).
// Tax is charged on the discounted subtotal.
// ------------------------------------------------------------
const MERCH_BUNDLES = [
    ['items' => ['Tape Gun Holder', 'Tape Gun Add-On'], 'discount' => 3],
];

/**
 * Total bundle discount for a group, given item-name => combined-qty.
 * Complete sets are counted as the minimum quantity across the
 * bundle's items. Returns 0.0 when nothing qualifies.
 */
function merch_bundle_discount(array $qtyByItem): float
{
    $total = 0.0;
    foreach (MERCH_BUNDLES as $bundle) {
        $sets = null;
        foreach ($bundle['items'] as $bundleItem) {
            $q = (int) ($qtyByItem[$bundleItem] ?? 0);
            $sets = ($sets === null) ? $q : min($sets, $q);
        }
        if ($sets !== null && $sets > 0) {
            $total += $sets * $bundle['discount'];
        }
    }
    return $total;
}

// Canonical size/sleeve option lists - 'sizes'/'sleeves' classes (the
// shirts) are the only items that use either. Added 2026-08-20
// alongside merch_order.php's server-side validation (Finding 2,
// 2026-08-19 code review), so a size/sleeve submitted for a non-shirt
// item, or a value that isn't one of these, gets rejected/cleared
// instead of stored as-is. Must match merch.php's <select name="size">
// / <select name="sleeve"> <option value="..."> strings exactly - same
// rule as GILDAN_COLORS/FILAMENT_COLORS below.
//
// (The old per-item lists that lived here - PRINTED_ITEMS,
// BOX_SHIPPING_ITEMS, RAINBOW_ELIGIBLE_ITEMS,
// STARS_STRIPES_ELIGIBLE_ITEMS, SHIRT_ITEMS - still exist under the
// same names, but are now define()'d by merch_items.php from
// catalog x class. Same for MERCH_PRICES and ITEM_WEIGHT_OZ.)
const MERCH_SIZES = ['S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'];
const MERCH_SLEEVE_LENGTHS = ['Short Sleeve', 'Long Sleeve'];
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
// Color VALUE lists - added 2026-08-18 so ourmerch.php can offer an
// admin an editable color dropdown for an existing order, alongside
// merch.php's customer-facing request form. WHICH items use which
// list is the class 'colors' attribute now (GILDAN_COLOR_ITEMS /
// FILAMENT_COLOR_ITEMS are derived in merch_items.php, and merch.php's
// JS reads the same derived lists out of merch_pricing_for_js() - the
// old hand-mirrored JS copies are gone, so that keep-in-sync hazard
// is closed).
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
    // Rainbow and Stars & Stripes both live only in the filament list
    // (they're print options, not garment colors) - merch_color_options_
    // for_item() below strips whichever one doesn't apply for a given
    // filament item, per RAINBOW_ELIGIBLE_ITEMS/STARS_STRIPES_ELIGIBLE_
    // ITEMS, same restriction the live form enforces.
    'Rainbow (+$2)',
    // Added 2026-08-20 (Steve) - red/white/blue print with a white
    // star-accented locking knob, on the four small box-shipping items
    // (not Tool Holder Stand). See STARS_STRIPES_ELIGIBLE_ITEMS/
    // STARS_STRIPES_SURCHARGE above/below.
    'Stars & Stripes (+$7)',
    'Not applicable / no color choice',
];

/**
 * The list of valid color values for a given item: GILDAN_COLORS,
 * FILAMENT_COLORS (with Rainbow and/or Stars & Stripes removed unless
 * the item is actually eligible for each - RAINBOW_ELIGIBLE_ITEMS /
 * STARS_STRIPES_ELIGIBLE_ITEMS), or an empty array if the item doesn't
 * offer a color choice at all. Used by merch_update.php to validate an
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
        $exclude = [];
        if (!in_array($item, RAINBOW_ELIGIBLE_ITEMS, true)) {
            $exclude[] = 'Rainbow (+$2)';
        }
        if (!in_array($item, STARS_STRIPES_ELIGIBLE_ITEMS, true)) {
            $exclude[] = 'Stars & Stripes (+$7)';
        }
        return array_values(array_diff(FILAMENT_COLORS, $exclude));
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
// (2026-08-21: TOOL_STAND_ITEM / TAPE_GUN_ITEM / MAILER_TIER_ITEMS /
// TAPE_GUN_MAX_QTY retired. Which items are mailer-tier riders vs. the
// box-base item is the class 'shipping' attribute now - see
// MAILER_TIER_ITEMS / BOX_BASE_ITEMS derived in merch_items.php - and
// both bulky-item caps live in MERCH_SHIPMENT_QTY_CAPS via the class
// 'max_qty_per_shipment' attribute. The CIRCLE_OVAL_* tier maxima
// below were renamed MAILER_TIER_* at the same time - the old names
// had been a misnomer since Rectangle joined 2026-08-09 and Tape Gun
// 2026-08-10. NOTE: the {{maxCircleOval}} token inside the
// strings/shipping/*.txt note files deliberately KEEPS its old name -
// renaming it would force the .txt files and this code to deploy in
// lockstep or customers would see a literal {{token}}; not worth it.)
const PRINTED_SHIP_RATE_MAILER = 6;  // 1-2 small items, no box-base item
const PRINTED_SHIP_RATE_BOX = 10;    // a box is involved either way (1 box-base + 0-2 riders, or 3-4 small items alone)
const PRINTED_SHIP_RATE_BOX_EXPANDED = 12; // 1 box-base + 3-4 riders - bigger box needed (new 2026-08-10)
const MAILER_TIER_MAILER_MAX = 2;
const MAILER_TIER_ALONE_MAX = 4;     // above this, even with no box-base item, it's a manual quote
const MAILER_TIER_WITH_BOX_BASE_MAX = 2; // how many can ride along in the box-base item's box at the base $10 rate
const MAILER_TIER_WITH_BOX_BASE_EXPANDED_MAX = 4; // above MAILER_TIER_WITH_BOX_BASE_MAX but up to this many -> PRINTED_SHIP_RATE_BOX_EXPANDED instead of manual

// (2026-08-21: the ITEM_WEIGHT_OZ table is derived in merch_items.php
// now, from each class's 'weight_oz' + MERCH_ITEM_OVERRIDES. It kept
// its historical properties: box_base items' weights are read only for
// the Shippo export's per-LINE Item Weight column, never the
// mailer-tier tare/weight math - that math only ever sums
// 'mailer_tier'-class items, same as it only summed the four small
// items before. The table itself replaced the old count-indexed
// POLY_MAILER_WEIGHT_OZ once mixed combos of different-weight items
// became common - Rectangle and Tape Gun Holder are real ounces
// heavier than Circle/Oval, which is also why Rectangle's weight is a
// per-item override rather than the cutter-holder class default.)
//
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
 * Per-class bulky-item cap check for one shipment/group. Takes a map
 * of item name => combined quantity and returns the customer-facing
 * manual-quote note for the first exceeded cap, or null if no cap is
 * exceeded. Caps come from MERCH_SHIPMENT_QTY_CAPS (the class
 * 'max_qty_per_shipment' attribute) - as of 2026-08-21 that's Tape Gun
 * Holder (max 1) and Tool Holder Stand (max 1), replacing the old
 * TAPE_GUN_MAX_QTY constant and the hardcoded 2+-Tool-Stands branch.
 *
 * Mailer-tier items' caps are checked before box-base items' caps,
 * preserving the pre-class behavior exactly: a shipment with 2 Tape
 * Guns AND 2 Tool Stands reports the Tape Gun note, same as when that
 * check was hardcoded first.
 */
function merch_shipment_cap_note(array $qtyByItem): ?string
{
    foreach (['mailer_tier', 'box_base'] as $role) {
        foreach (MERCH_SHIPMENT_QTY_CAPS as $item => $cap) {
            if ($cap['shipping'] !== $role) {
                continue;
            }
            if (($qtyByItem[$item] ?? 0) > $cap['max']) {
                return $cap['noteKey'] !== null
                    ? merch_load_string($cap['noteKey'])
                    : merch_flat_rate_exceeded_note(); // defensive fallback - every capped class sets a note key
            }
        }
    }
    return null;
}

/**
 * Shipping for a group of printed items, given the combined quantity
 * of box-base items (Tool Holder Stand), all mailer-tier small items
 * combined (Circle/Oval/Rectangle/Tape Gun), and any already-tripped
 * bulky-item cap note from merch_shipment_cap_note() (null = no cap
 * exceeded). Could be a single order line, or several combined into
 * one invoice. Returns ['amount' => float|null, 'note' => string] -
 * amount is null when it needs a manual quote instead of a flat rate,
 * same convention as the rest of this file.
 *
 * Note $boxBaseQty >= 2 never reaches the tier math: the box-base
 * class caps max_qty_per_shipment at 1, so that case arrives here as
 * a non-null $capNote (the multiple-toolstands note), exactly like
 * the old hardcoded branch.
 */
function merch_printed_shipping(int $boxBaseQty, int $mailerTierQty, ?string $capNote): array
{
    // Bulky-item caps checked FIRST, before anything else - same
    // precedence the hardcoded tape-gun/tool-stand checks always had.
    if ($capNote !== null) {
        return ['amount' => null, 'note' => $capNote];
    }

    if ($boxBaseQty === 1) {
        if ($mailerTierQty <= MAILER_TIER_WITH_BOX_BASE_MAX) {
            return ['amount' => (float) PRINTED_SHIP_RATE_BOX, 'note' => ''];
        }
        if ($mailerTierQty <= MAILER_TIER_WITH_BOX_BASE_EXPANDED_MAX) {
            return ['amount' => (float) PRINTED_SHIP_RATE_BOX_EXPANDED, 'note' => ''];
        }
        return ['amount' => null, 'note' => merch_load_string('shipping/manual-quote-toolstand-plus-extra', ['maxCircleOval' => MAILER_TIER_WITH_BOX_BASE_EXPANDED_MAX])];
    }

    // No box-base item in this group.
    if ($mailerTierQty <= MAILER_TIER_MAILER_MAX) {
        return ['amount' => (float) PRINTED_SHIP_RATE_MAILER, 'note' => ''];
    }
    if ($mailerTierQty <= MAILER_TIER_ALONE_MAX) {
        return ['amount' => (float) PRINTED_SHIP_RATE_BOX, 'note' => ''];
    }
    return ['amount' => null, 'note' => merch_load_string('shipping/manual-quote-too-many-circleoval', ['maxCircleOval' => MAILER_TIER_ALONE_MAX])];
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
// The in-process filament swaps needed for a red/white/blue print cost
// more than Rainbow's single-spool swap (Steve, 2026-08-20) - hence the
// higher surcharge. One flat number applied to every STARS_STRIPES_
// ELIGIBLE_ITEMS item, same pattern as RAINBOW_SURCHARGE, even though
// those items don't all share the same base price (Tape Gun Holder is
// $15 vs. $18 for the cutter holders) - matches how Rainbow's single
// $2 already applies across Tool Holder Stand ($12) and Tape Gun
// Holder ($15) despite their different base prices too.
const STARS_STRIPES_SURCHARGE = 7;
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

    if (in_array($item, STARS_STRIPES_ELIGIBLE_ITEMS, true) && $color === 'Stars & Stripes (+$7)') {
        $price += STARS_STRIPES_SURCHARGE;
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
        'starsStripesEligibleItems' => STARS_STRIPES_ELIGIBLE_ITEMS,
        'printedItems' => PRINTED_ITEMS,
        'boxShippingItems' => BOX_SHIPPING_ITEMS,
        'oversizeSurchargeSizes' => OVERSIZE_SURCHARGE_SIZES,
        'oversizeSurcharge' => OVERSIZE_SURCHARGE,
        'rainbowSurcharge' => RAINBOW_SURCHARGE,
        'starsStripesSurcharge' => STARS_STRIPES_SURCHARGE,
        'taxRate' => TAX_RATE,
        'flatShippingRate' => FLAT_SHIPPING_RATE,
        'flatShippingMaxQty' => FLAT_SHIPPING_MAX_QTY,
        'maxQuantity' => MAX_QUANTITY,
        'mailerTierItems' => MAILER_TIER_ITEMS,
        'boxBaseItems' => BOX_BASE_ITEMS,
        // Which color list each item's request form shows - derived
        // from the class 'colors' attribute, same source ourmerch.php's
        // admin color editor uses. Replaces the hand-mirrored
        // GILDAN_COLOR_ITEMS/FILAMENT_COLOR_ITEMS JS arrays that used
        // to live in merch.php's script block.
        'gildanColorItems' => GILDAN_COLOR_ITEMS,
        'filamentColorItems' => FILAMENT_COLOR_ITEMS,
        // Per-item bulky-item caps with their resolved customer-facing
        // notes - replaces toolStandItem/tapeGunItem/tapeGunMaxQty and
        // the multipleTapeGuns/multipleToolStands entries that used to
        // sit in shippingNotes below.
        'shipmentQtyCaps' => array_map(
            function ($cap) {
                return [
                    'max' => $cap['max'],
                    'note' => $cap['noteKey'] !== null ? merch_load_string($cap['noteKey']) : '',
                ];
            },
            MERCH_SHIPMENT_QTY_CAPS
        ),
        // Bundle nudge text per item (2026-08-21): the live estimate is
        // single-item by design, so it can't SHOW the bundle discount -
        // instead any item that's part of a MERCH_BUNDLES entry gets a
        // "order the other one too and save" note, resolved here from
        // strings/pages/merch-bundle-nudge.txt so the wording lives in
        // one editable file. The discount itself is applied server-side
        // in merch_group_calculate() at invoice time.
        'bundleNudges' => (function () {
            $nudges = [];
            foreach (MERCH_BUNDLES as $bundle) {
                foreach ($bundle['items'] as $bundleItem) {
                    $others = array_values(array_diff($bundle['items'], [$bundleItem]));
                    $nudges[$bundleItem] = merch_load_string('pages/merch-bundle-nudge', [
                        'otherItems' => implode(' & ', $others),
                        'discount' => $bundle['discount'],
                    ]);
                }
            }
            return $nudges;
        })(),
        'printedShipRateMailer' => PRINTED_SHIP_RATE_MAILER,
        'printedShipRateBox' => PRINTED_SHIP_RATE_BOX,
        'printedShipRateBoxExpanded' => PRINTED_SHIP_RATE_BOX_EXPANDED,
        'mailerTierMailerMax' => MAILER_TIER_MAILER_MAX,
        'mailerTierAloneMax' => MAILER_TIER_ALONE_MAX,
        'mailerTierWithBoxBaseMax' => MAILER_TIER_WITH_BOX_BASE_MAX,
        'mailerTierWithBoxBaseExpandedMax' => MAILER_TIER_WITH_BOX_BASE_EXPANDED_MAX,
        // Resolved shipping-note TEXT (not just the numbers above) so
        // merch.php's live JS estimate shows the exact same wording as
        // the real email/CSV notes - one source (the /strings/shipping
        // .txt files), read once here, instead of a hand-typed copy
        // living in the JS too. See merch_printed_shipping() and
        // merch_flat_rate_exceeded_note() above for where each of
        // these comes from server-side.
        'shippingNotes' => [
            // (multipleTapeGuns/multipleToolStands moved into
            // shipmentQtyCaps above, 2026-08-21)
            'toolStandPlusExtra' => merch_load_string('shipping/manual-quote-toolstand-plus-extra', ['maxCircleOval' => MAILER_TIER_WITH_BOX_BASE_EXPANDED_MAX]),
            'tooManyCircleOvalAlone' => merch_load_string('shipping/manual-quote-too-many-circleoval', ['maxCircleOval' => MAILER_TIER_ALONE_MAX]),
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
    $boxBaseQty = 0;
    $mailerTierQty = 0;
    $qtyByItem = []; // per-item totals for the bulky-item caps

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

        if (in_array($it['item'], BOX_BASE_ITEMS, true)) {
            $boxBaseQty += $qty;
        } elseif (in_array($it['item'], MAILER_TIER_ITEMS, true)) {
            $mailerTierQty += $qty;
        }
        // Combined per-item totals feed merch_shipment_cap_note() and
        // merch_bundle_discount() - the per-class bulky-item caps and
        // the cross-item bundle discounts both apply to the whole
        // group, regardless of the per-line or tier totals.
        $qtyByItem[$it['item']] = ($qtyByItem[$it['item']] ?? 0) + $qty;
    }

    // Bundle discount before tax (2026-08-21): a complete bundle set in
    // the group (e.g. Tape Gun Holder + Tape Gun Add-On) takes the
    // bundle's discount off the combined subtotal, and tax is charged
    // on what the customer actually pays. 'subtotal' stays the plain
    // sum of the lines - the discount is its own returned field so
    // every renderer (invoice email, pickup document, ourmerch
    // preview) can show it as its own line instead of silently
    // shrinking a number the customer would try to reconcile against
    // the per-item prices.
    $bundleDiscount = merch_bundle_discount($qtyByItem);
    $tax = round(($subtotal - $bundleDiscount) * TAX_RATE, 2);

    $shipping = null;
    $shippingNote = '';
    if ($isShipping) {
        // Box-capacity tiers apply only when the group actually
        // contains a box-base and/or a mailer-tier item - NOT just
        // because $isPrinted is true (a printed item outside
        // BOX_SHIPPING_ITEMS would fall through to the flat-rate
        // branch below instead, same as a shirt/hat, though as of
        // 2026-08-10 every printed item is in BOX_SHIPPING_ITEMS).
        if ($boxBaseQty > 0 || $mailerTierQty > 0) {
            $shipInfo = merch_printed_shipping($boxBaseQty, $mailerTierQty, merch_shipment_cap_note($qtyByItem));
            $shipping = $shipInfo['amount'];
            $shippingNote = $shipInfo['note'];
        } elseif ($totalQuantity <= FLAT_SHIPPING_MAX_QTY) {
            $shipping = FLAT_SHIPPING_RATE;
        } else {
            $shippingNote = merch_flat_rate_exceeded_note();
        }
    }

    $total = $subtotal - $bundleDiscount + $tax + ($shipping ?? 0);

    return [
        'lines' => $lines,
        'subtotal' => $subtotal,
        'bundleDiscount' => $bundleDiscount,
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
            $boxBaseQty = in_array($item, BOX_BASE_ITEMS, true) ? $quantity : 0;
            $mailerTierQty = in_array($item, MAILER_TIER_ITEMS, true) ? $quantity : 0;
            $shipInfo = merch_printed_shipping($boxBaseQty, $mailerTierQty, merch_shipment_cap_note([$item => $quantity]));
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

// ------------------------------------------------------------
// Load the item catalog LAST, once every class/global above exists.
// merch_items.php scans /items/, joins each folder's declared class to
// MERCH_CLASSES + MERCH_ITEM_OVERRIDES, and define()s the derived
// per-item constants (MERCH_PRICES, ITEM_WEIGHT_OZ, SHIRT_ITEMS,
// PRINTED_ITEMS, BOX_SHIPPING_ITEMS, MAILER_TIER_ITEMS,
// BOX_BASE_ITEMS, RAINBOW_ELIGIBLE_ITEMS, STARS_STRIPES_ELIGIBLE_ITEMS,
// GILDAN_COLOR_ITEMS, FILAMENT_COLOR_ITEMS, MERCH_SHIPMENT_QTY_CAPS)
// that everything above and every consumer of this file reads.
// Requiring pricing.php - as every consumer already does - is the ONE
// entry point to all of it; nothing should require merch_items.php
// directly.
// ------------------------------------------------------------
require_once __DIR__ . '/merch_items.php';
