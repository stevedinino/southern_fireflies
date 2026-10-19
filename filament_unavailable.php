<?php
// Build: 2026-10-19-A
// ============================================================
// "Filament color unavailable" list (Steve, 2026-10-19): the Stock & Print
// Plan kept recommending Hot Pink and Green while both were on order and
// not arriving for days. This is NOT a filament manager - it is just a
// short list of colors the print plan should treat as "can't print right
// now", so it moves on to what he CAN print and printer time isn't wasted.
//
// File: filament_unavailable.csv (gitignored, blocked from the web by the
// *.csv rule): Color, BackOn, Since, Note. BackOn is an optional date
// (YYYY-MM-DD); the color returns by itself ON that date. Blank = unavailable
// until cleared by hand. Edited from the Stock & Print Plan page through
// merch_filament_unavailable.php.
//
// Only PRINTING is affected: pieces of that color already on the shelf still
// count, and orders can still ship from stock. An order that still needs
// pieces of an unavailable color is "held" (listed separately, waiting on
// that color) instead of being picked for the next prints.
// ============================================================

function merch_unavailable_file(): string
{
    return defined('MERCH_UNAVAILABLE_FILE') ? MERCH_UNAVAILABLE_FILE : __DIR__ . '/filament_unavailable.csv';
}

function merch_unavailable_header(): array
{
    return ['Color', 'BackOn', 'Since', 'Note'];
}

/** All rows in the file, as [['Color'=>..,'BackOn'=>..,'Since'=>..,'Note'=>..], ...]. Missing file = none. */
function merch_unavailable_read(): array
{
    $h = @fopen(merch_unavailable_file(), 'rb');
    if (!$h) {
        return [];
    }
    $rows = [];
    $first = true;
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        if ($first) {
            $first = false;
            continue;
        }
        if (trim((string) ($r[0] ?? '')) === '') {
            continue;
        }
        $rows[] = ['Color' => trim($r[0]), 'BackOn' => trim((string) ($r[1] ?? '')), 'Since' => trim((string) ($r[2] ?? '')), 'Note' => trim((string) ($r[3] ?? ''))];
    }
    fclose($h);
    return $rows;
}

/** Colors unavailable on $today (Y-m-d): no BackOn date, or BackOn still in the future. color => row. */
function merch_unavailable_active_rows(string $today): array
{
    $out = [];
    foreach (merch_unavailable_read() as $r) {
        if ($r['BackOn'] === '' || $r['BackOn'] > $today) {
            $out[$r['Color']] = $r;
        }
    }
    return $out;
}

/**
 * Splits shipments (merch_stock_allocate()'s 'partial') into [printable, held]:
 * held = any line still MISSING pieces in an unavailable color.
 */
function merch_unavailable_split(array $shipments, array $unavailColors): array
{
    if (empty($unavailColors)) {
        return [array_values($shipments), []];
    }
    $printable = [];
    $held = [];
    foreach ($shipments as $s) {
        $isHeld = false;
        foreach ($s['lines'] as $l) {
            if (($l['missing'] ?? $l['qty']) > 0 && in_array($l['color'], $unavailColors, true)) {
                $isHeld = true;
                break;
            }
        }
        if ($isHeld) {
            $held[] = $s;
        } else {
            $printable[] = $s;
        }
    }
    return [$printable, $held];
}

/** Rewrites the file under an exclusive lock from $rows. Returns true on success. */
function merch_unavailable_write_locked($fh, array $rows): bool
{
    $out = fopen('php://memory', 'w+');
    fputcsv($out, merch_unavailable_header(), ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, [$r['Color'], $r['BackOn'], $r['Since'], $r['Note']], ',', '"', '');
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return rewind($fh) && ftruncate($fh, 0) && fwrite($fh, $csv) !== false && fflush($fh);
}

/** Adds (or updates) a color. $backOn '' or Y-m-d. Returns ['ok'=>bool,'error'=>string]. */
function merch_unavailable_set(string $color, string $backOn, string $today): array
{
    if ($backOn !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $backOn) || $backOn <= $today || !checkdate((int) substr($backOn, 5, 2), (int) substr($backOn, 8, 2), (int) substr($backOn, 0, 4)))) {
        return ['ok' => false, 'error' => 'The back-on date must be a real date after today.'];
    }
    $fh = @fopen(merch_unavailable_file(), 'c+');
    if (!$fh || !flock($fh, LOCK_EX)) {
        if ($fh) {
            fclose($fh);
        }
        return ['ok' => false, 'error' => 'Could not open the unavailable-colors file.'];
    }
    rewind($fh);
    $rows = [];
    $first = true;
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($first) { $first = false; continue; }
        if (trim((string) ($r[0] ?? '')) !== '') {
            $rows[] = ['Color' => trim($r[0]), 'BackOn' => trim((string) ($r[1] ?? '')), 'Since' => trim((string) ($r[2] ?? '')), 'Note' => trim((string) ($r[3] ?? ''))];
        }
    }
    $found = false;
    foreach ($rows as &$r) {
        if ($r['Color'] === $color) {
            $r['BackOn'] = $backOn;
            $found = true;
        }
    }
    unset($r);
    if (!$found) {
        $rows[] = ['Color' => $color, 'BackOn' => $backOn, 'Since' => $today, 'Note' => ''];
    }
    $ok = merch_unavailable_write_locked($fh, $rows);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => 'Could not save the file.'];
}

/** Removes a color (it is available again). */
function merch_unavailable_clear(string $color): array
{
    $fh = @fopen(merch_unavailable_file(), 'c+');
    if (!$fh || !flock($fh, LOCK_EX)) {
        if ($fh) {
            fclose($fh);
        }
        return ['ok' => false, 'error' => 'Could not open the unavailable-colors file.'];
    }
    rewind($fh);
    $rows = [];
    $first = true;
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($first) { $first = false; continue; }
        $c = trim((string) ($r[0] ?? ''));
        if ($c !== '' && $c !== $color) {
            $rows[] = ['Color' => $c, 'BackOn' => trim((string) ($r[1] ?? '')), 'Since' => trim((string) ($r[2] ?? '')), 'Note' => trim((string) ($r[3] ?? ''))];
        }
    }
    $ok = merch_unavailable_write_locked($fh, $rows);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => 'Could not save the file.'];
}
