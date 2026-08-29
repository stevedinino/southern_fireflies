<?php
// Build: 2026-08-20-A
// ============================================================
// Shared CSV-load, column-mapping, and shipment-grouping logic for
// shippo_export.php and packing_slips.php (plus, just the grouping-key
// formula, ourmerch.php's per-row "is this shipment ready" indicator).
// Extracted 2026-08-20 (Finding 10, 2026-08-19 code review) - this
// exact "read CSV -> build $col name->index map -> normalize name/zip
// -> group into shipments -> gate on whole-shipment Created" pipeline
// was copy-pasted near-identically across shippo_export.php and
// packing_slips.php, including a byte-identical column list (18 names
// at the time; both callers' lists have grown since, most recently
// with Cancelled on 2026-08-23, but still identical to each other),
// with only a comment ("mirror it here") standing between the two ever
// silently drifting apart - change the grouping rule in one and miss
// the other, and the packing checklist quietly describes a different
// batch than the Shippo CSV, which is exactly the thing they exist to
// let Steve cross-reference.
//
// Deliberately does NOT include the "number and sort shipments" step -
// packing_slips.php sorts its list by Order Number; shippo_export.php
// doesn't (it just writes shipments in the order they were grouped),
// and unifying that would be a real behavior change neither file asked
// for. Everything in this file is else-equal between the two callers.
//
// 2026-08-29: merch_load_csv()/merch_csv_column_map() (only - not the
// shipment-grouping functions below, which are specific to the
// name+zip shipping concept) are now ALSO required by
// merch_reminders.php/merch_send_reminders.php, purely as general-
// purpose "read a CSV, map its header" utilities - unrelated to
// shipments, but not worth a second copy of either function just to
// avoid requiring a file with "shipments" in its name.
// ============================================================

/**
 * Reads a CSV file into memory, dies with a clear message on any of
 * the ways this can go wrong (missing file, unreadable, empty) instead
 * of continuing with partial/garbage data - the exact three die()
 * messages shippo_export.php and packing_slips.php each used to carry
 * their own copy of. $label is used in the messages, e.g. "merchandise.csv".
 *
 * Returns ['header' => [...], 'rows' => [...]] - header is already
 * BOM-stripped at index 0; rows does NOT include the header row.
 */
function merch_load_csv(string $csvFile, string $label = 'the file'): array
{
    if (!file_exists($csvFile)) {
        die("{$label} not found.");
    }
    $handle = fopen($csvFile, 'r');
    if (!$handle) {
        die("Could not open {$label}.");
    }
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    if (empty($rows)) {
        die("{$label} is empty.");
    }

    $header = array_shift($rows);
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    }
    return ['header' => $header, 'rows' => $rows];
}

/**
 * Builds a column-name -> index map via array_search - the same
 * "$col[$name] = array_search($name, $header, true)" loop every reader
 * in this codebase already wrote out by hand. If $required names are
 * given, dies with a clear message naming the first one missing,
 * rather than continuing and hitting an undefined-index warning deep
 * inside the actual export/render logic.
 */
function merch_csv_column_map(array $header, array $columnNames, array $required = [], string $label = 'the file'): array
{
    $col = [];
    foreach ($columnNames as $name) {
        $col[$name] = array_search($name, $header, true);
    }
    foreach ($required as $name) {
        if (($col[$name] ?? false) === false) {
            die("Expected column '{$name}' not found in {$label} header - has it changed?");
        }
    }
    return $col;
}

/**
 * The one normalization formula "same customer, for shipment-grouping
 * purposes" depends on everywhere it's used (shippo_export.php,
 * packing_slips.php, ourmerch.php's per-row "is this shipment ready"
 * indicator): lowercased, whitespace-collapsed name + lowercased,
 * trimmed zip. Change this formula here once and all three follow,
 * instead of by hand in three places. See shippo_export.php for the
 * reasoning behind name+zip over email/address (typos, autofill
 * glitches) and its known tradeoff (two different customers sharing
 * both a name and zip would incorrectly merge).
 */
function merch_shipment_key(string $name, string $zip): string
{
    $normalizedName = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    $normalizedZip = strtolower(trim($zip));
    return $normalizedName . '|' . $normalizedZip;
}

/**
 * Groups already-filtered rows into shipments keyed by
 * merch_shipment_key(). $col must include 'Name' and 'Zip'.
 */
function merch_group_shipments(array $rows, array $col): array
{
    $groups = [];
    foreach ($rows as $row) {
        $key = merch_shipment_key($row[$col['Name']] ?? '', $row[$col['Zip']] ?? '');
        $groups[$key][] = $row;
    }
    return $groups;
}

/**
 * Splits shipment groups into complete/incomplete based on whether
 * EVERY row in the group has a non-blank Created value - a shipment
 * ships as one box, so it only counts as ready once every item bound
 * for that address is Created; one un-printed item holds back the
 * whole group, not just its own row (2026-08-15, per Steve). $col must
 * include 'Created'.
 *
 * Returns ['complete' => [...], 'incomplete' => [...]], each still
 * keyed the same way $groups was.
 */
function merch_split_groups_by_created(array $groups, array $col): array
{
    $complete = [];
    $incomplete = [];
    foreach ($groups as $key => $groupRows) {
        $allCreated = true;
        foreach ($groupRows as $row) {
            if (trim($row[$col['Created']] ?? '') === '') {
                $allCreated = false;
                break;
            }
        }
        if ($allCreated) {
            $complete[$key] = $groupRows;
        } else {
            $incomplete[$key] = $groupRows;
        }
    }
    return ['complete' => $complete, 'incomplete' => $incomplete];
}
