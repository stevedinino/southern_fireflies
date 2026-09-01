<?php
// Build: 2026-08-29-A
// ============================================================
// Shared eligibility/grouping/formatting logic for the payment-
// reminder feature (merch_reminders.php preview page,
// merch_send_reminders.php send endpoint). New 2026-08-29, per Steve:
// "a bulk reminder email that gently nudges the people who placed
// requests but never paid."
//
// Deliberately its own file rather than reusing merch_invoice.php's
// grouping inline, because the ELIGIBILITY condition here is
// different in a way that matters, not just a filter tweak:
//   merch_invoice.php:  Invoice Date === ''   (not yet invoiced at all)
//   here:                Invoice Date !== ''   (already invoiced)
//                        AND Pymt Date === ''   (but still unpaid)
//                        AND Invoice Date is at least
//                        MERCH_REMINDER_MIN_AGE_DAYS old (see
//                        merch_reminder_min_age_days() below) - added
//                        2026-08-31 per Steve, after order-analytics
//                        review showed most Ship customers pay within a
//                        few days of being invoiced: reminding someone
//                        who was just invoiced yesterday just irritates
//                        people who were always going to pay on their
//                        own timeline. Only genuinely overdue/abandoned
//                        invoices should surface here.
//                        AND Fulfillment === 'Ship' only - Pickup at
//                        Retreat customers pay cash/check in person at
//                        the retreat, so an emailed payment reminder
//                        never makes sense for them (Steve, 2026-08-29,
//                        confirmed via AskUserQuestion: "Ship orders
//                        only").
//
// Also drops merch_invoice.php's blank-email "fall back to matching by
// Name" rule entirely (see merch_reminder_row_eligible() below) - this
// feature has no delivery method at all for a customer with no email
// on file (no printable document, no in-person hand-off), so unlike an
// invoice, there's nothing to gain by force-fitting such a row into a
// name-only group. In practice this rarely matters: merch_invoice.php
// already refuses to invoice a Ship order with no email, so a row
// can't normally reach Invoice Date !== '' with a blank Email in the
// first place - this mainly guards a hand-edited/legacy CSV row.
//
// Both the preview page and the send endpoint call
// merch_reminder_build_groups()/merch_reminder_group_for_anchor() fresh
// against a live CSV read every time - the send endpoint deliberately
// trusts nothing about a group's CONTENTS (items, name, email) from the
// browser, only WHICH anchor OrderIDs were checked. That means a group
// can never be spoofed by tampering with the page, and it can't go
// stale between when the preview was rendered and when Send is
// clicked - if a row was paid, cancelled, or edited to Pickup in the
// meantime, re-deriving from the anchor OrderID naturally drops it (or
// skips the whole group if the anchor itself is no longer eligible).
//
// No dollar amount appears anywhere in this feature (items only) - see
// merch_notify.php's merch_send_payment_reminder() for why: this
// codebase never stores a combined invoice's final total anywhere on
// the CSV (merch_invoice_stamp_invoice_date() only ever writes Invoice
// Date, never Price/Tax/Shipping), so any total shown in a reminder
// would have to be recomputed from scratch and could drift from what
// the original invoice actually said if pricing.php's rules changed in
// between sending the two. Steve's own call (2026-08-29, via
// AskUserQuestion): no total, just a friendly nudge naming the items,
// with the email itself offering to resend the total on request.
// ============================================================

/**
 * Column names merch_reminders.php and merch_send_reminders.php should
 * both pass to merch_csv_column_map() (merch_shipments.php) - kept
 * here, next to the logic that actually uses them, instead of being
 * retyped in both files.
 */
function merch_reminder_required_columns(): array
{
    return ['OrderID', 'Item', 'Quantity', 'Name', 'Fulfillment', 'Email', 'Invoice Date', 'Pymt Date', 'Cancelled'];
}

/**
 * Minimum number of days since Invoice Date before an unpaid Ship
 * order is considered "overdue" enough to nudge by email. Added
 * 2026-08-31 per Steve: order-analytics on the live CSV showed Ship
 * customers pay in a median of 1 day and 75% pay within 4 days, so
 * anything still unpaid inside this window is very likely just a
 * customer who hasn't gotten to it yet, not someone who needs a
 * reminder. One place to tune if that behavior changes.
 */
function merch_reminder_min_age_days(): int
{
    return 14;
}

/**
 * Parses an Invoice Date cell into a whole number of days since then
 * (as of right now), or null if the value is blank or can't be parsed
 * at all. Tries the app's own native format first (Y-m-d, written by
 * merch_invoice_stamp_invoice_date() in merch_invoice.php) and falls
 * back to a loose strtotime() parse for any row saved in a different
 * shape - e.g. from the CSV having been opened/saved in Excel at some
 * point, which is known to reformat dates on this file (M/D/YYYY and
 * M/D/YYYY H:MM have both been observed). A row whose date genuinely
 * can't be parsed is treated as NOT old enough (fails closed) rather
 * than guessed at, since this only gates an email send.
 */
function merch_reminder_invoice_age_days(string $invoiceDate): ?int
{
    $invoiceDate = trim($invoiceDate);
    if ($invoiceDate === '') {
        return null;
    }

    $parsed = DateTime::createFromFormat('Y-m-d', $invoiceDate);
    if ($parsed === false || $parsed->format('Y-m-d') !== $invoiceDate) {
        $timestamp = strtotime($invoiceDate);
        if ($timestamp === false) {
            return null;
        }
        $parsed = (new DateTime())->setTimestamp($timestamp);
    }

    $parsed->setTime(0, 0, 0);
    $today = new DateTime('today');
    $diff = $today->diff($parsed);
    return $diff->invert === 1 ? $diff->days : 0;
}

/**
 * True if $row is a candidate for a payment reminder on its own -
 * Ship, invoiced at least merch_reminder_min_age_days() days ago, not
 * yet paid, has an email on file, and not cancelled. Says nothing
 * about identity/grouping; see merch_reminder_build_groups() and
 * merch_reminder_group_for_anchor() for that.
 */
function merch_reminder_row_eligible(array $row, array $col): bool
{
    $fulfillment = trim($row[$col['Fulfillment']] ?? '');
    $invoiced = trim($row[$col['Invoice Date']] ?? '');
    $paid = trim($row[$col['Pymt Date']] ?? '');
    $email = trim($row[$col['Email']] ?? '');
    $cancelled = $col['Cancelled'] !== false && trim($row[$col['Cancelled']] ?? '') !== '';
    if ($fulfillment !== 'Ship' || $invoiced === '' || $paid !== '' || $email === '' || $cancelled) {
        return false;
    }
    $ageDays = merch_reminder_invoice_age_days($invoiced);
    return $ageDays !== null && $ageDays >= merch_reminder_min_age_days();
}

/**
 * Sums quantity per unique item name across a set of rows, preserving
 * first-seen order - so a customer whose group happens to span more
 * than one OrderID for the same item (e.g. two separate requests for
 * the same shirt) shows as one line ("Logo Shirt (x2)"), not two.
 * Returns a list of ['item' => string, 'quantity' => int].
 */
function merch_reminder_aggregate_items(array $groupRows, array $col): array
{
    $order = [];
    $qtyByItem = [];
    foreach ($groupRows as $row) {
        $item = trim($row[$col['Item']] ?? '');
        $quantity = (int) ($row[$col['Quantity']] ?? 1);
        if (!isset($qtyByItem[$item])) {
            $qtyByItem[$item] = 0;
            $order[] = $item;
        }
        $qtyByItem[$item] += $quantity;
    }
    return array_map(fn($item) => ['item' => $item, 'quantity' => $qtyByItem[$item]], $order);
}

/**
 * Turns merch_reminder_aggregate_items()'s output into plain display
 * strings ("Logo Shirt (x2)", "Trucker Hat") - shared by the preview
 * page's on-screen list and merch_notify.php's actual email body, so
 * what Steve previews is exactly what goes out.
 */
function merch_reminder_format_item_lines(array $items): array
{
    return array_map(function ($entry) {
        $label = $entry['item'];
        if ($entry['quantity'] > 1) {
            $label .= ' (x' . $entry['quantity'] . ')';
        }
        return $label;
    }, $items);
}

/**
 * Groups every eligible row into reminder groups, keyed by email +
 * printed-vs-shop account type (merch_is_printed_item(), pricing.php) -
 * kept separate from merch_invoice.php's identity formula only in that
 * it drops the Name fallback (see this file's header comment for why).
 * Printed and shop items still group separately here for the same
 * reason merch_invoice.php keeps them separate: they're different
 * orders on different timelines as far as Steve's own bookkeeping
 * goes, even for the same customer, so one reminder shouldn't conflate
 * them.
 *
 * Returns a list of:
 *   [
 *     'anchorOrderId' => the lowest OrderID in the group (a stable
 *                         per-group identifier for both the preview
 *                         checkboxes and the send endpoint's
 *                         re-derivation anchor),
 *     'orderIds'   => [...],
 *     'name'       => '...', 'email' => '...',
 *     'isPrinted'  => bool,
 *     'items'      => merch_reminder_aggregate_items() output,
 *     'invoiceDate'=> the earliest Invoice Date in the group (display only),
 *     'invoiceAgeDays' => days since that earliest Invoice Date (display only),
 *   ]
 * Ordered by anchorOrderId ascending, for a stable/readable preview list.
 */
function merch_reminder_build_groups(array $rows, array $col): array
{
    $eligible = [];
    foreach ($rows as $row) {
        if (merch_reminder_row_eligible($row, $col)) {
            $eligible[] = $row;
        }
    }

    $groups = [];
    foreach ($eligible as $row) {
        $email = strtolower(trim($row[$col['Email']] ?? ''));
        $isPrinted = merch_is_printed_item(trim($row[$col['Item']] ?? ''));
        $key = $email . '|' . ($isPrinted ? 'printed' : 'shop');
        $groups[$key][] = $row;
    }

    $result = [];
    foreach ($groups as $groupRows) {
        $numericIds = array_map(fn($r) => (int) trim($r[$col['OrderID']] ?? '0'), $groupRows);
        $anchorIndex = array_search(min($numericIds), $numericIds, true);
        $anchor = $groupRows[$anchorIndex];

        $invoiceDates = array_values(array_filter(array_map(fn($r) => trim($r[$col['Invoice Date']] ?? ''), $groupRows)));
        sort($invoiceDates);
        $earliestInvoiceDate = $invoiceDates[0] ?? '';

        $result[] = [
            'anchorOrderId' => (string) min($numericIds),
            'orderIds' => array_map(fn($r) => trim($r[$col['OrderID']] ?? ''), $groupRows),
            'name' => trim($anchor[$col['Name']] ?? ''),
            'email' => trim($anchor[$col['Email']] ?? ''),
            'isPrinted' => merch_is_printed_item(trim($anchor[$col['Item']] ?? '')),
            'items' => merch_reminder_aggregate_items($groupRows, $col),
            'invoiceDate' => $earliestInvoiceDate,
            'invoiceAgeDays' => $earliestInvoiceDate !== '' ? merch_reminder_invoice_age_days($earliestInvoiceDate) : null,
        ];
    }

    usort($result, fn($a, $b) => (int) $a['anchorOrderId'] <=> (int) $b['anchorOrderId']);

    return $result;
}

/**
 * Re-derives ONE group at send time from a single anchor OrderID the
 * browser said was checked - the send endpoint's only input, and never
 * trusted for anything beyond "which group." Returns null if that
 * OrderID no longer exists, or is no longer eligible itself (paid,
 * cancelled, edited to Pickup, email removed, etc. since the preview
 * was rendered) - the send endpoint treats that as "skip it," not an
 * error, since the page having gone slightly stale between preview and
 * send is an expected race, not a bug.
 */
function merch_reminder_group_for_anchor(array $rows, array $col, string $anchorOrderId): ?array
{
    $anchor = null;
    foreach ($rows as $row) {
        if (trim($row[$col['OrderID']] ?? '') === $anchorOrderId) {
            $anchor = $row;
            break;
        }
    }
    if ($anchor === null || !merch_reminder_row_eligible($anchor, $col)) {
        return null;
    }

    $anchorEmail = strtolower(trim($anchor[$col['Email']] ?? ''));
    $anchorIsPrinted = merch_is_printed_item(trim($anchor[$col['Item']] ?? ''));

    $groupRows = [];
    foreach ($rows as $row) {
        if (!merch_reminder_row_eligible($row, $col)) {
            continue;
        }
        $email = strtolower(trim($row[$col['Email']] ?? ''));
        if ($email === $anchorEmail && merch_is_printed_item(trim($row[$col['Item']] ?? '')) === $anchorIsPrinted) {
            $groupRows[] = $row;
        }
    }

    return [
        'orderIds' => array_map(fn($r) => trim($r[$col['OrderID']] ?? ''), $groupRows),
        'name' => trim($anchor[$col['Name']] ?? ''),
        'email' => trim($anchor[$col['Email']] ?? ''),
        'isPrinted' => $anchorIsPrinted,
        'items' => merch_reminder_aggregate_items($groupRows, $col),
    ];
}
