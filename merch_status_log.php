<?php
// Build: 2026-10-15-D
// ============================================================
// Append-only change log for order STATUS fields in merchandise.csv
// (Steve, 2026-10-14 incident: orders 1097-1098, paid and already shipped,
// vanished from the working views because Cancelled was set by an
// accidental click, and nothing recorded who/when/how).
//
// One row per changed field per order line:
//   Timestamp, OrderID, Name, Item, Field, Old, New, Source, IP
// Tracked fields: Created, Fulfilled, Cancelled, Pymt Date, Invoice Date
// (merch_status_log_fields()). Un-cancel and un-check are logged like any
// other change (New is blank). A group action (Mark Paid on a combined
// invoice, Send Invoice, a packing-list shipment) logs one row per line.
//
// HOW IT WORKS - deliberately not a call at every place a field is set:
// every endpoint that changes these fields already reads the whole CSV
// into $rows, edits it in memory, and rewrites it under an exclusive lock.
// So each endpoint takes a snapshot right after reading
// (merch_status_snapshot) and, once its write succeeded, calls
// merch_status_log_changes() with the finished rows. The helper compares
// before and after and writes whatever differs. That means a field can't
// be changed without being logged by one of these endpoints, and a
// no-op (checking an already-checked box) logs nothing. New rows
// (merch_order.php appends them) are not "changes" and are not logged.
//
// Logging NEVER blocks or undoes the change it describes: any failure is
// error_log()ed and swallowed.
//
// merch_status_log.csv is gitignored (customer names) and blocked from the
// web by the *.csv rule in .htaccess, like every other CSV here. It only
// ever grows by a few rows per action.
// ============================================================

require_once __DIR__ . '/csv_safety.php';

function merch_status_log_file(): string
{
    if (defined('MERCH_STATUS_LOG_FILE')) {
        return MERCH_STATUS_LOG_FILE; // tests point this at a temp file
    }
    return __DIR__ . '/merch_status_log.csv';
}

function merch_status_log_header(): array
{
    return ['Timestamp', 'OrderID', 'Name', 'Item', 'Field', 'Old', 'New', 'Source', 'IP'];
}

/** The columns whose changes are recorded. */
function merch_status_log_fields(): array
{
    return ['Created', 'Fulfilled', 'Cancelled', 'Pymt Date', 'Invoice Date'];
}

/**
 * Snapshot of the tracked fields as they are NOW:
 * [OrderID => ['name' => ..., 'item' => ..., 'fields' => ['Cancelled' => '', ...]]].
 * $header/$rows are the raw CSV rows ($rows[0] is the header); a UTF-8 BOM
 * on the first header cell is tolerated.
 */
function merch_status_snapshot(array $header, array $rows): array
{
    $header = array_values($header);
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
    }
    $idIdx = array_search('OrderID', $header, true);
    if ($idIdx === false) {
        return [];
    }
    $nameIdx = array_search('Name', $header, true);
    $itemIdx = array_search('Item', $header, true);
    $fieldIdx = [];
    foreach (merch_status_log_fields() as $f) {
        $i = array_search($f, $header, true);
        if ($i !== false) {
            $fieldIdx[$f] = $i;
        }
    }

    $snap = [];
    foreach ($rows as $n => $row) {
        if ($n === 0) {
            continue; // header
        }
        $id = trim((string) ($row[$idIdx] ?? ''));
        if ($id === '') {
            continue;
        }
        $fields = [];
        foreach ($fieldIdx as $f => $i) {
            $fields[$f] = trim((string) ($row[$i] ?? ''));
        }
        $snap[$id] = [
            'name' => $nameIdx !== false ? trim((string) ($row[$nameIdx] ?? '')) : '',
            'item' => $itemIdx !== false ? trim((string) ($row[$itemIdx] ?? '')) : '',
            'fields' => $fields,
        ];
    }
    return $snap;
}

/**
 * Compares $before (merch_status_snapshot() taken before the endpoint's
 * edits) with the finished $rows and appends one log row per changed
 * field. $source says what caused it, e.g. 'merch_update.php' (the
 * Merchandise Requests checkboxes and the packing list), 'Mark Paid',
 * 'Send Invoice'. Returns the number of rows written (0 on nothing
 * changed or on any failure).
 */
function merch_status_log_changes(array $before, array $header, array $rows, string $source): int
{
    try {
        $after = merch_status_snapshot($header, $rows);
        $now = date('Y-m-d H:i:s');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $out = [];
        foreach ($after as $id => $a) {
            if (!isset($before[$id])) {
                continue; // a new row is not a status change
            }
            $b = $before[$id];
            foreach ($a['fields'] as $field => $newValue) {
                $oldValue = $b['fields'][$field] ?? '';
                if ($oldValue === $newValue) {
                    continue;
                }
                $out[] = [
                    $now,
                    (string) $id,
                    merch_csv_safe_cell($a['name']),
                    merch_csv_safe_cell($a['item']),
                    $field,
                    $oldValue,
                    $newValue,
                    $source,
                    $ip,
                ];
            }
        }
        if (empty($out)) {
            return 0;
        }

        $file = merch_status_log_file();
        $handle = @fopen($file, 'ab');
        if (!$handle) {
            error_log("Southern Fireflies: could not open status log '{$file}' for append");
            return 0;
        }
        $written = 0;
        if (flock($handle, LOCK_EX)) {
            $stat = fstat($handle);
            if (($stat['size'] ?? 0) === 0) {
                fputcsv($handle, merch_status_log_header(), ',', '"', '');
            }
            foreach ($out as $line) {
                if (fputcsv($handle, $line, ',', '"', '') !== false) {
                    $written++;
                }
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        if ($written !== count($out)) {
            error_log("Southern Fireflies: status log wrote {$written} of " . count($out) . " rows to '{$file}'");
        }
        return $written;
    } catch (\Throwable $e) {
        error_log('Southern Fireflies: status log failed: ' . $e->getMessage());
        return 0;
    }
}
