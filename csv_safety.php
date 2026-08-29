<?php
// Build: 2026-08-29-A
// ============================================================
// Closes code review Finding 8 (Medium, CSV/spreadsheet formula
// injection): customer-supplied fields (Name, Address, Email, Phone...)
// go into export_emails.php's and shippo_export.php's downloadable
// CSVs by way of fputcsv with nothing done to them first. A customer
// who puts a leading =, +, -, or @ in one of those fields - by accident
// (a "+1" phone prefix typed into the wrong box) or on purpose -
// produces a cell that Excel/Google Sheets treats as a FORMULA the
// moment that export is opened, not as the literal text that was
// actually submitted. A crafted one (e.g. starting with =HYPERLINK(...)
// or @SUM(...)) can exfiltrate data from the sheet or just misbehave.
//
// The commonly-cited fix for this (including in an earlier draft of
// this file) is to PREFIX the cell with a leading apostrophe, which is
// what Excel's own UI treats as "force this to display as plain text."
// That's the right fix for a CSV that only ever gets opened by a human
// in a spreadsheet. It is NOT the right fix here: both of this export's
// destinations are automated importers, not just a human's eyeballs -
// shippo_export.php's own header comment says its output goes straight
// into "Shippo's bulk importer" to buy shipping labels, and
// export_emails.php's says its output is "ready to import into Brevo."
// Neither of those importers is Excel - they'd almost certainly read a
// literal leading apostrophe as part of the actual address or email
// field (Shippo printing a label with `'123 Main St` as the street
// address, Brevo importing `'customer@example.com` as a broken email)
// rather than as a formatting hint. Steve does also review this export
// by eye before uploading it (per shippo_export.php's own comment),
// but the SAME file he reviews is the one that gets uploaded - so
// "safe to open in Excel" and "safe to feed to the real importer" have
// to be the same property, not a tradeoff.
//
// Fix actually used here: instead of adding a character, STRIP the
// leading trigger character(s) so the cell can never begin with one.
// A formula requires that specific character to be first - remove it
// and there's nothing left for Excel/Sheets to interpret as a formula,
// and (unlike the apostrophe-prefix approach) nothing new is added
// that a non-Excel importer could take as literal data. The tradeoff
// is the opposite kind of edge case: genuine data that happens to
// start with one of these characters (a name typo, an address that
// legitimately starts with a hyphen) loses that one leading character
// in the EXPORT only - never in merchandise.csv/registrations.csv
// themselves, which this deliberately does not touch (see below) - a
// far smaller and rarer cost than a mis-addressed shipping label or a
// silently-dropped customer from an email import.
//
// This ONLY touches the two files that hand data to an automated
// importer - it deliberately does NOT touch merchandise.csv/
// registrations.csv themselves (written by register.php,
// merch_order.php, merch_update.php, merch_edit_line.php,
// merch_invoice.php), since those are read back through this app's own
// pages (which already escape everything with htmlspecialchars when
// rendering as HTML - no formula-injection risk there, since nothing
// ever opens those files directly in a spreadsheet) and stripping a
// customer's actually-submitted leading character out of the source-
// of-truth data, rather than just the export, would be a real data
// change for no benefit.
// ============================================================

/**
 * Returns $value unchanged, UNLESS it's a string starting with a
 * character Excel/Sheets treats as "this cell is a formula" (=, +, -,
 * @) or a raw tab/carriage-return (which can smuggle a formula-looking
 * value past a naive check on the first visible character) - in which
 * case that leading character is stripped so the cell can't be
 * interpreted as a formula by anything that opens or imports it.
 *
 * Deliberately takes/returns a single scalar rather than a whole row,
 * so a caller can choose exactly which columns are actually free text
 * (Name, Address, Email...) and leave numeric/computed columns
 * (Quantity, Price, Order Weight...) untouched - running this over a
 * legitimate negative number, for instance, would silently turn -12.5
 * into 12.5, which is worse than the problem this exists to fix.
 *
 * @param mixed $value
 * @return mixed The original value, or a copy with a leading
 *   formula-trigger character removed.
 */
function merch_csv_safe_cell($value)
{
    if (!is_string($value) || $value === '') {
        return $value;
    }
    return preg_replace('/^[=+\-@\t\r]+/', '', $value);
}
