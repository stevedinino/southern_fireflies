<?php
// Build: 2026-10-23-A
// ============================================================
// "Currently printing" marks for the Create view's print plates
// (ourmerch.php). Steve (2026-10-23): hopping between tasks, he lost track
// of which plates were on the printer and which were done.
//
// Pressing "Start printing" on a plate appends a 'start' row to
// merch_printing.csv; "Finished..." (after it adds the pieces to inventory)
// or "Not printing" appends a 'clear' row. The LATEST row per plate key
// wins, so the file is an append-only history like merch_status_log /
// merch_notes_handled, and nothing here touches merchandise.csv or
// inventory.csv.
//
// A plate's key is a hash of its color, its plate label and the exact
// order lines on it, so it stays attached to the same plate across page
// loads. Each start row also stores a readable snapshot (color, plate,
// items) so the "Currently printing" strip can still list a plate that
// has since moved or vanished from the live plan - a mark never silently
// disappears.
//
// Columns: Timestamp, Key, Action, Color, Plate, Items, Source, IP
// merch_printing.csv is gitignored and blocked from the web by the *.csv
// rule in .htaccess.
// ============================================================

require_once __DIR__ . '/csv_safety.php';

function merch_printing_file(): string
{
    if (defined('MERCH_PRINTING_FILE')) {
        return MERCH_PRINTING_FILE; // tests point this at a temp file
    }
    return __DIR__ . '/merch_printing.csv';
}

function merch_printing_header(): array
{
    return ['Timestamp', 'Key', 'Action', 'Color', 'Plate', 'Items', 'Source', 'IP'];
}

/** Stable key for a plate: color + plate label + its order lines. */
function merch_printing_key(string $color, string $plateLabel, array $orderQtys): string
{
    ksort($orderQtys);
    $parts = [];
    foreach ($orderQtys as $id => $q) {
        $parts[] = $id . 'x' . $q;
    }
    return substr(sha1($color . '|' . $plateLabel . '|' . implode(',', $parts)), 0, 12);
}

/**
 * Plates marked as printing right now: [Key => ['since' => 'Y-m-d H:i:s',
 * 'color' => ..., 'plate' => ..., 'items' => ...]], oldest first.
 */
function merch_printing_active(): array
{
    $file = merch_printing_file();
    $active = [];
    if (!is_file($file) || ($h = @fopen($file, 'rb')) === false) {
        return $active;
    }
    $first = true;
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        if ($first) {
            $first = false;
            continue;
        }
        $key = (string) ($r[1] ?? '');
        $act = (string) ($r[2] ?? '');
        if ($key === '') {
            continue;
        }
        if ($act === 'start') {
            $active[$key] = [
                'since' => (string) ($r[0] ?? ''),
                'color' => (string) ($r[3] ?? ''),
                'plate' => (string) ($r[4] ?? ''),
                'items' => (string) ($r[5] ?? ''),
            ];
        } elseif ($act === 'clear') {
            unset($active[$key]);
        }
    }
    fclose($h);
    return $active;
}

/** Appends one row. False (and error_log) if it could not be written. */
function merch_printing_append(string $action, string $key, string $color = '', string $plate = '', string $items = '', string $source = 'ourmerch.php'): bool
{
    $file = merch_printing_file();
    $h = @fopen($file, 'ab');
    if (!$h) {
        error_log("Southern Fireflies: could not open printing log '{$file}' for append");
        return false;
    }
    $ok = false;
    if (flock($h, LOCK_EX)) {
        $stat = fstat($h);
        $ok = true;
        if (($stat['size'] ?? 0) === 0) {
            $ok = fputcsv($h, merch_printing_header(), ',', '"', '') !== false;
        }
        if ($ok) {
            $ok = fputcsv($h, [
                date('Y-m-d H:i:s'),
                $key,
                $action === 'start' ? 'start' : 'clear',
                $color,
                $plate,
                $items,
                $source,
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ], ',', '"', '') !== false;
        }
        fflush($h);
        flock($h, LOCK_UN);
    }
    fclose($h);
    return $ok;
}
