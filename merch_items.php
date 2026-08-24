<?php
// Build: 2026-08-21-B
// ============================================================
// Folder-driven merch item catalog (card redesign, 2026-08-21).
//
// Every item folder under /items/ defines one merch card:
//
//   /items/10-rectangle-cutter-holder/
//       item.txt         REQUIRED - "name:" and "class:" lines (below)
//       description.txt  card description (HTML fragment, same trust
//                        model as the old strings/items/*.txt files -
//                        Steve-authored, echoed as-is)
//       captions.txt     optional - "filename: alt text" per line;
//                        doubles as the lightbox caption
//       01-*.jpg/.png/.mp4 ...  media, ordered by filename. A video's
//                        poster image is the same basename +
//                        "-poster" (01-demo.mp4 -> 01-demo-poster.jpg)
//                        and is attached to the video, not listed as
//                        its own slide.
//
// item.txt is plain "key: value" lines. Required keys:
//   name:  the item's CANONICAL name - the join key to MERCH_CLASSES
//          pricing (via the class), merchandise.csv rows, and the
//          request form's item field. Must be unique across folders.
//   class: a key of MERCH_CLASSES (pricing.php), or the literal word
//          "none" for a display-only card (no price, no Request
//          button) - that's how 99-more-coming-soon works.
//
// The folder ORDER on the page is plain string order of the folder
// names - hence the 10/20/.../99 numeric prefixes. Inserting an item
// between two others is renaming a folder, no code.
//
// This scan is part of the MONEY PATH, not just presentation:
// merch_order.php validates submitted item names against this catalog,
// and pricing constants (MERCH_PRICES, ITEM_WEIGHT_OZ, the eligibility
// lists) are all derived from catalog + class here. So the rules are
// fail-LOUD, never guess:
//   - item.txt missing/unreadable, name missing, class missing  -> folder
//     skipped, error_log()
//   - class not in MERCH_CLASSES (and not "none")               -> folder
//     skipped, error_log()  (a typo'd class must never fall back
//     to some default price)
//   - duplicate name across folders                             -> later
//     folder skipped, error_log()
//   - MERCH_ITEM_OVERRIDES key matching no folder               -> error_log()
//     (stale override - it silently applies to nothing)
// A skipped folder fails SAFE: its item simply doesn't exist, so it
// can't be ordered.
//
// This file is require'd by pricing.php (bottom of the file, after
// MERCH_CLASSES / MERCH_ITEM_OVERRIDES are declared) and immediately
// define()s the derived per-item constants the rest of the codebase
// reads (MERCH_PRICES, SHIRT_ITEMS, PRINTED_ITEMS, ...). Nothing else
// should require this file directly - requiring pricing.php, as every
// consumer already does, is the one entry point, so there is exactly
// one scan and one catalog per request.
// ============================================================

/**
 * The full item catalog, in page order. Each entry:
 *   'name'        canonical item name
 *   'folder'      folder basename under /items/ (e.g. "20-tape-gun-holder")
 *   'class'       class key, or null for a display-only card
 *   'attrs'       merged class attributes + per-item overrides, or null
 *                 for display-only items
 *   'description' HTML fragment for the card body
 *   'media'       ordered list of ['type' => 'image'|'video',
 *                 'src' => web path, 'alt' => string, 'poster' => web
 *                 path|null (videos only)]
 */
function merch_catalog(): array
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }

    $catalog = [];
    $seenNames = [];
    $itemsDir = __DIR__ . '/items';

    $folders = is_dir($itemsDir) ? scandir($itemsDir) : [];
    foreach ($folders as $folder) {
        if ($folder === '.' || $folder === '..' || !is_dir($itemsDir . '/' . $folder)) {
            continue;
        }
        $dir = $itemsDir . '/' . $folder;

        $meta = merch_items_parse_meta($dir . '/item.txt');
        if ($meta === null) {
            error_log("Southern Fireflies: items/{$folder}/item.txt missing or unreadable - folder skipped");
            continue;
        }
        $name = $meta['name'] ?? '';
        $class = $meta['class'] ?? '';
        if ($name === '') {
            error_log("Southern Fireflies: items/{$folder}/item.txt has no 'name:' line - folder skipped");
            continue;
        }
        if ($class === '') {
            error_log("Southern Fireflies: items/{$folder}/item.txt has no 'class:' line - folder skipped (use 'class: none' for a display-only card)");
            continue;
        }
        if ($class !== 'none' && !isset(MERCH_CLASSES[$class])) {
            error_log("Southern Fireflies: items/{$folder}/item.txt names unknown class '{$class}' - folder skipped");
            continue;
        }
        if (isset($seenNames[$name])) {
            error_log("Southern Fireflies: items/{$folder} duplicates item name '{$name}' (already declared by items/{$seenNames[$name]}) - folder skipped");
            continue;
        }
        $seenNames[$name] = $folder;

        $attrs = null;
        if ($class !== 'none') {
            $attrs = MERCH_CLASSES[$class];
            if (isset(MERCH_ITEM_OVERRIDES[$name])) {
                $attrs = array_merge($attrs, MERCH_ITEM_OVERRIDES[$name]);
            }
        }

        $descriptionPath = $dir . '/description.txt';
        if (file_exists($descriptionPath)) {
            $description = rtrim(file_get_contents($descriptionPath), "\r\n");
        } else {
            // Same fail-loud convention as strings.php: an obviously
            // wrong card beats a silently blank one.
            error_log("Southern Fireflies: items/{$folder}/description.txt not found");
            $description = "[Missing description: items/{$folder}/description.txt not found on server]";
        }

        $catalog[] = [
            'name' => $name,
            'folder' => $folder,
            'class' => $class === 'none' ? null : $class,
            'attrs' => $attrs,
            'description' => $description,
            'media' => merch_items_scan_media($dir, "items/{$folder}", $name),
        ];
    }

    foreach (array_keys(MERCH_ITEM_OVERRIDES) as $overrideName) {
        if (!isset($seenNames[$overrideName])) {
            error_log("Southern Fireflies: MERCH_ITEM_OVERRIDES entry '{$overrideName}' matches no item folder - stale override?");
        }
    }
    // Same staleness rule for bundle rules (2026-08-21): a MERCH_BUNDLES
    // item name with no folder means the discount can never apply -
    // usually a typo or a renamed/retired item. Log it, don't guess.
    foreach (MERCH_BUNDLES as $bundle) {
        foreach ($bundle['items'] as $bundleItem) {
            if (!isset($seenNames[$bundleItem])) {
                error_log("Southern Fireflies: MERCH_BUNDLES references '{$bundleItem}' which matches no item folder - stale bundle rule?");
            }
        }
    }

    return $catalog;
}

/**
 * One catalog entry by canonical item name, or null. Display-only
 * (class "none") items ARE returned here - check ['attrs'] !== null if
 * you need an orderable item.
 */
function merch_catalog_item(string $name): ?array
{
    foreach (merch_catalog() as $entry) {
        if ($entry['name'] === $name) {
            return $entry;
        }
    }
    return null;
}

/**
 * Merged class attributes (class defaults + per-item overrides) for an
 * orderable item, or null if the name is unknown or display-only.
 * This is the single lookup everything money-related goes through.
 */
function merch_item_attrs(string $name): ?array
{
    $entry = merch_catalog_item($name);
    return $entry !== null ? $entry['attrs'] : null;
}

/** Parse an item.txt of "key: value" lines. null if missing/unreadable. */
function merch_items_parse_meta(string $path): ?array
{
    if (!file_exists($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $meta = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $meta[trim(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
    }
    return $meta;
}

/**
 * Ordered media list for one item folder. Filename order IS the
 * display order (01-, 02-, ... prefixes); the first entry is the
 * card's face. "<basename>-poster.<ext>" images are a video's poster
 * frame, not their own slide. Anything that isn't a known media
 * extension (item.txt, captions.txt, description.txt, .DS_Store,
 * Thumbs.db, ...) is ignored.
 */
function merch_items_scan_media(string $dir, string $webBase, string $itemName): array
{
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $videoExts = ['mp4'];

    $files = scandir($dir);
    sort($files, SORT_STRING);

    $captions = merch_items_parse_captions($dir . '/captions.txt');

    // First pass: collect posters so they can be attached, not listed.
    $posters = [];
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $stem = pathinfo($file, PATHINFO_FILENAME);
        // (substr comparison, not str_ends_with - that's PHP 8-only and
        // the host's PHP version isn't something to gamble on)
        if (in_array($ext, $imageExts, true) && substr($stem, -7) === '-poster') {
            $posters[substr($stem, 0, -7)] = $file;
        }
    }

    $media = [];
    $photoNumber = 0;
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $stem = pathinfo($file, PATHINFO_FILENAME);

        if (in_array($ext, $videoExts, true)) {
            $poster = $posters[$stem] ?? null;
            $media[] = [
                'type' => 'video',
                'src' => "{$webBase}/{$file}",
                'poster' => $poster !== null ? "{$webBase}/{$poster}" : null,
                'alt' => $captions[$file] ?? "{$itemName} - demo video",
            ];
        } elseif (in_array($ext, $imageExts, true) && substr($stem, -7) !== '-poster') {
            $photoNumber++;
            $media[] = [
                'type' => 'image',
                'src' => "{$webBase}/{$file}",
                'poster' => null,
                // Fallback alt keeps every image described even when
                // captions.txt doesn't mention it (a11y - don't ship
                // filename-as-alt-text).
                'alt' => $captions[$file] ?? "{$itemName} - photo {$photoNumber}",
            ];
        }
    }

    return $media;
}

/** Parse captions.txt ("filename: alt text" per line) into a map. */
function merch_items_parse_captions(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $captions = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) file_get_contents($path)) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $captions[trim(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
    }
    return $captions;
}

// ------------------------------------------------------------
// Derived per-item constants - computed from catalog x class, then
// define()'d under the SAME NAMES the codebase has always read
// (merch_order.php, shippo_export.php, ourmerch.php, the pricing.php
// functions, merch_pricing_for_js()). The class definitions are the
// source of truth; these are projections of them. None of these
// include display-only (class "none") items.
// ------------------------------------------------------------
(function () {
    $prices = [];
    $weights = [];
    $shirtItems = [];
    $printedItems = [];
    $boxShippingItems = [];
    $mailerTierItems = [];
    $boxBaseItems = [];
    $rainbowItems = [];
    $starsStripesItems = [];
    $gildanColorItems = [];
    $filamentColorItems = [];
    $qtyCaps = [];

    foreach (merch_catalog() as $entry) {
        if ($entry['attrs'] === null) {
            continue; // display-only card, not an orderable item
        }
        $name = $entry['name'];
        $a = $entry['attrs'];

        $prices[$name] = $a['price'];
        if (($a['weight_oz'] ?? null) !== null) {
            $weights[$name] = $a['weight_oz'];
        }
        if (!empty($a['sizes'])) {
            $shirtItems[] = $name;
        }
        if (!empty($a['printed'])) {
            $printedItems[] = $name;
        }
        if (($a['shipping'] ?? '') === 'mailer_tier') {
            $mailerTierItems[] = $name;
            $boxShippingItems[] = $name;
        } elseif (($a['shipping'] ?? '') === 'box_base') {
            $boxBaseItems[] = $name;
            $boxShippingItems[] = $name;
        }
        if (!empty($a['rainbow'])) {
            $rainbowItems[] = $name;
        }
        if (!empty($a['stars_stripes'])) {
            $starsStripesItems[] = $name;
        }
        if (($a['colors'] ?? '') === 'gildan') {
            $gildanColorItems[] = $name;
        } elseif (($a['colors'] ?? '') === 'filament') {
            $filamentColorItems[] = $name;
        }
        if (($a['max_qty_per_shipment'] ?? null) !== null) {
            $qtyCaps[$name] = [
                'max' => $a['max_qty_per_shipment'],
                'noteKey' => $a['max_qty_note'] ?? null,
                'shipping' => $a['shipping'] ?? '',
            ];
        }
    }

    define('MERCH_PRICES', $prices);
    define('ITEM_WEIGHT_OZ', $weights);
    define('SHIRT_ITEMS', $shirtItems);
    define('PRINTED_ITEMS', $printedItems);
    define('BOX_SHIPPING_ITEMS', $boxShippingItems);
    define('MAILER_TIER_ITEMS', $mailerTierItems);
    define('BOX_BASE_ITEMS', $boxBaseItems);
    define('RAINBOW_ELIGIBLE_ITEMS', $rainbowItems);
    define('STARS_STRIPES_ELIGIBLE_ITEMS', $starsStripesItems);
    define('GILDAN_COLOR_ITEMS', $gildanColorItems);
    define('FILAMENT_COLOR_ITEMS', $filamentColorItems);
    // item => ['max' => int, 'noteKey' => strings/ key, 'shipping' => role].
    // Replaces the old TAPE_GUN_MAX_QTY constant AND the hardcoded
    // "2+ Tool Stands -> manual quote" branch - both were the same
    // rule ("more than N of this bulky item needs hand-packing")
    // written twice. merch_shipment_cap_note() (pricing.php) reads it.
    define('MERCH_SHIPMENT_QTY_CAPS', $qtyCaps);
})();
