<?php
// Build: 2026-09-08-A
// ============================================================
// Shared accessor + media-scan layer over events-data.php - see that
// file's header for the full picture of the 2026-09-08 gallery/events
// unification. Every page that needs "which retreats exist / are
// upcoming / archived" goes through the functions here instead of
// requiring events-data.php directly and re-deriving its own date
// math - that's exactly the kind of drift (index.php's grid and
// merch.php's pickup dropdown quietly disagreeing about what's
// "still upcoming") this file exists to prevent.
//
//   events_catalog()        every entry, keyed by slug (+ 'slug'
//                           merged into each entry for convenience).
//   events_upcoming()       non-archived entries, soonest endDate
//                           first - index.php's Save-the-Date grid
//                           and merch.php's pickup dropdown loop over
//                           this.
//   events_all_by_date()    every entry regardless of archived,
//                           oldest endDate first - gallery.php loops
//                           over this instead, since the gallery
//                           shows historical retreats on purpose.
//   events_by_slug($slug)   one entry, or null - event.php uses this.
//                           Returns null for an archived slug on
//                           purpose (see below), same as an unknown
//                           one.
//   events_is_past($event)  true once today is after the event's
//                           endDate. Bucketing only - has nothing to
//                           do with archived.
//   events_scan_photos(...) ordered media list from an event's
//                           events/<slug>/photos/ folder.
//
// archived is a MANUAL, date-independent switch (events-data.php's
// header has the full reasoning) that hides an entry from the
// registration-facing pages - index.php, merch.php's pickup
// dropdown, and event.php's flyer/registration page - but never from
// gallery.php, which always shows every event. event.php treats an
// archived slug as "not found" rather than rendering a registration
// page full of nulls (archived entries are allowed to skip every
// registration-only field - see Chasin' Fireflies in events-data.php
// for the example, a retreat that predates this system entirely).
// ============================================================

function events_catalog(): array
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }
    $raw = require __DIR__ . '/events-data.php';
    $catalog = [];
    foreach ($raw as $slug => $entry) {
        $entry['slug'] = $slug;
        $catalog[$slug] = $entry;
    }
    return $catalog;
}

/** Non-archived events, soonest endDate first. */
function events_upcoming(): array
{
    $events = array_values(array_filter(events_catalog(), fn($e) => empty($e['archived'])));
    usort($events, fn($a, $b) => $a['endDate'] <=> $b['endDate']);
    return $events;
}

/** Every event regardless of archived, oldest endDate first. */
function events_all_by_date(): array
{
    $events = array_values(events_catalog());
    usort($events, fn($a, $b) => $a['endDate'] <=> $b['endDate']);
    return $events;
}

/** One event by slug. null if unknown OR archived - event.php treats both as "not found". */
function events_by_slug(string $slug): ?array
{
    $event = events_catalog()[$slug] ?? null;
    if ($event === null || !empty($event['archived'])) {
        return null;
    }
    return $event;
}

/** True once today is after this event's last day. Bucketing only - unrelated to 'archived'. */
function events_is_past(array $event): bool
{
    return $event['endDate'] < date('Y-m-d');
}

/**
 * Ordered media list for one event's events/<slug>/photos/ folder -
 * same convention as merch_items.php's merch_items_scan_media():
 * filename order is display order (01-, 02-, ... prefixes
 * recommended so it's obvious at a glance), a video's
 * "<basename>-poster.<ext>" image attaches to it instead of listing
 * separately, and anything that isn't a known media extension
 * (captions.txt, .DS_Store, Thumbs.db, ...) is ignored. Empty array
 * if the event has no photos/ subfolder yet - that's the normal
 * state for an upcoming retreat and gallery.php falls back to the
 * flyer, then a plain placeholder, in that case (see gallery.php).
 */
function events_scan_photos(string $slug, string $eventName): array
{
    $dir = __DIR__ . "/{$slug}/photos";
    if (!is_dir($dir)) {
        return [];
    }
    $webBase = "events/{$slug}/photos";

    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $videoExts = ['mp4'];

    $files = scandir($dir);
    sort($files, SORT_STRING);

    $captions = events_parse_captions($dir . '/captions.txt');

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
                'alt' => $captions[$file] ?? "{$eventName} - video",
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
                'alt' => $captions[$file] ?? "{$eventName} - photo {$photoNumber}",
            ];
        }
    }

    return $media;
}

/** Parse captions.txt ("filename: alt text" per line) into a map. */
function events_parse_captions(string $path): array
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
