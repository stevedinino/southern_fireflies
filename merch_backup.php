<?php
// Build: 2026-08-20-A
// ============================================================
// Shared "back up this CSV before writing it" helper for
// merch_update.php and merch_invoice.php, which each carried their own
// near-identical copy. Two things changed vs. the old copies (Findings
// 12 and 14, 2026-08-19 code review):
//
// 1. No more @-suppressed mkdir()/copy(). A failed backup used to fail
//    completely silently - which is exactly how the backups folder was
//    once found empty despite the code "always being there" (see the
//    comment that used to sit next to this in both files). Failures
//    are now error_log()'d, so they show up in the host's PHP error
//    log instead of just vanishing.
// 2. Old backups are now pruned to the most recent $keepCount after
//    every write, instead of accumulating forever. At even modest
//    admin activity this folder grows without bound otherwise (every
//    checkbox toggle and every invoice send writes a full copy of
//    merchandise.csv) - see the review's own math: roughly 25MB/day at
//    50 edits on a 500KB file, which will eventually hit a cheap
//    shared-host quota.
// ============================================================

/**
 * Copies $csvFile into $backupDir as a Ymd_His-timestamped snapshot,
 * creating $backupDir if needed, then deletes the oldest snapshots
 * beyond $keepCount so this directory doesn't grow forever. Failures
 * at any step are logged (via error_log) rather than silently ignored,
 * but never throw/exit - a failed backup shouldn't block the actual
 * write the caller is about to do.
 */
function merch_backup_csv(string $csvFile, string $backupDir, int $keepCount = 50): void
{
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            error_log("merch_backup_csv: could not create backup directory {$backupDir}");
            return;
        }
    }

    $dest = $backupDir . '/merchandise_' . date('Ymd_His') . '.csv';
    if (!copy($csvFile, $dest)) {
        error_log("merch_backup_csv: could not copy {$csvFile} to {$dest}");
        return;
    }

    // Prune: filenames are Ymd_His-stamped, so a plain string sort is
    // also a chronological sort - oldest first.
    $existing = glob($backupDir . '/merchandise_*.csv');
    if ($existing === false || count($existing) <= $keepCount) {
        return;
    }
    sort($existing);
    $toDelete = array_slice($existing, 0, count($existing) - $keepCount);
    foreach ($toDelete as $oldFile) {
        if (!unlink($oldFile)) {
            error_log("merch_backup_csv: could not prune old backup {$oldFile}");
        }
    }
}
