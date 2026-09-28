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
//                        AND the item is a PRINTED item (2026-09-28,
//                        per Steve) - shirts/hats are Janet's account,
//                        not Steve's, and she handles her own unpaid
//                        follow-up separately; this feature only ever
//                        nudges for the printed (3D-printed tools)
//                        side. See merch_reminder_row_eligible() below.
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
// A dollar total was added 2026-09-28 (merch_reminder_group_pricing()
// below) by re-running merch_group_calculate() over exactly the rows
// that share one Invoice Date (the same set merch_invoice.php combined
// into one real invoice originally - see that function's own comment
// for why Invoice Date is the safe grouping unit for this). It still
// isn't shown unconditionally, though: this codebase never stores a
// combined invoice's final total anywhere on the CSV
// (merch_invoice_stamp_invoice_date() only ever writes Invoice Date,
// never Price/Tax/Shipping), and a manual shipping quote Steve typed in
// at invoice time is never written back either - so whenever the
// recompute can't resolve a real shipping number (needs manual quote),
// the total is left out and the email falls back to its original
// "reply and I'll send it over" wording rather than risk showing a
// number that doesn't match what was actually invoiced. See
// merch_reminder_group_pricing()'s and merch_send_payment_reminder()'s
// own comments for the full reasoning.
// ============================================================

/**
 * Column names merch_reminders.php and merch_send_reminders.php should
 * both pass to merch_csv_column_map() (merch_shipments.php) - kept
 * here, next to the logic that actually uses them, instead of being
 * retyped in both files.
 */
function merch_reminder_required_columns(): array
{
    // 2026-09-28: Size/Sleeve/Color added so a group's rows can be
    // re-priced through merch_group_calculate() (same shape
    // merch_invoice.php feeds it) - see merch_reminder_group_pricing()
    // below. None of the three are in the $required subset either file
    // passes to merch_csv_column_map(), so a CSV missing one (it
    // shouldn't - every live row has always had these) degrades the
    // same optional way Item/Quantity/Name/Cancelled already do here,
    // rather than hard-failing the whole reminder page over it.
    return ['OrderID', 'Item', 'Quantity', 'Name', 'Fulfillment', 'Email', 'Invoice Date', 'Pymt Date', 'Cancelled', 'Size', 'Sleeve', 'Color', 'Timestamp'];
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
 * Parses an Invoice Date cell into a DateTime (midnight, no time
 * component), or null if blank or unparseable. Tries the app's own
 * native format first (Y-m-d, written by
 * merch_invoice_stamp_invoice_date() in merch_invoice.php) and falls
 * back to a loose strtotime() parse for any row saved in a different
 * shape - e.g. from the CSV having been opened/saved in Excel at some
 * point, which is known to reformat dates on this file (M/D/YYYY and
 * M/D/YYYY H:MM have both been observed).
 *
 * Extracted 2026-09-28 from what used to be
 * merch_reminder_invoice_age_days()'s own inline parsing, so
 * merch_reminder_normalize_date() (below - used for grouping) can
 * share the exact same tolerant parse instead of a second copy of it
 * slowly drifting out of sync.
 */
function merch_reminder_parse_date(string $raw): ?DateTime
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $parsed = DateTime::createFromFormat('Y-m-d', $raw);
    if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }
        $parsed = (new DateTime())->setTimestamp($timestamp);
    }

    $parsed->setTime(0, 0, 0);
    return $parsed;
}

/**
 * Whole number of days between an Invoice Date cell and right now, or
 * null if the value is blank or can't be parsed at all. A row whose
 * date genuinely can't be parsed is treated as NOT old enough (fails
 * closed) rather than guessed at, since this only gates an email send.
 */
function merch_reminder_invoice_age_days(string $invoiceDate): ?int
{
    $parsed = merch_reminder_parse_date($invoiceDate);
    if ($parsed === null) {
        return null;
    }
    $today = new DateTime('today');
    $diff = $today->diff($parsed);
    return $diff->invert === 1 ? $diff->days : 0;
}

/**
 * Same parse as merch_reminder_invoice_age_days(), but returns a
 * canonical Y-m-d string instead of an age - used as part of the
 * reminder-grouping key (2026-09-28) so two Invoice Date cells that
 * are the same calendar day but different SAVED formats (7/26/2026 vs
 * 2026-07-26 - both appear in the live file, see the format comment
 * above) still land in the same group instead of splitting into two.
 * Returns null on the same blank/unparseable cases
 * merch_reminder_parse_date() does.
 */
function merch_reminder_normalize_date(string $raw): ?string
{
    $parsed = merch_reminder_parse_date($raw);
    return $parsed !== null ? $parsed->format('Y-m-d') : null;
}

/**
 * Tolerant parse of a Timestamp cell (order-submission time, NOT
 * Invoice Date) into a DateTime, or null if blank/unparseable. Every
 * row merch_order.php writes today uses 'Y-m-d H:i:s', but older rows
 * predate that and were saved as 'n/j/Y' or 'n/j/Y G:i' (no leading
 * zeros - PHP's date() never pads them, and Excel doesn't either when
 * it's touched the file) - tried in order, falling back to strtotime()
 * for anything else. Used only by
 * merch_reminder_find_possibly_abandoned() below, where a timestamp
 * that can't be parsed just means that row can't be compared (treated
 * as null, not "now"), never a hard failure.
 */
function merch_reminder_parse_timestamp(string $raw): ?DateTime
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    foreach (['Y-m-d H:i:s', 'n/j/Y G:i', 'n/j/Y'] as $format) {
        $parsed = DateTime::createFromFormat($format, $raw);
        if ($parsed !== false) {
            return $parsed;
        }
    }
    $ts = strtotime($raw);
    return $ts !== false ? (new DateTime())->setTimestamp($ts) : null;
}

/**
 * True if $row is a candidate for a payment reminder on its own -
 * Ship, a PRINTED item, invoiced at least merch_reminder_min_age_days()
 * days ago, not yet paid, has an email on file, and not cancelled.
 * Says nothing about identity/grouping; see merch_reminder_build_groups()
 * and merch_reminder_group_for_anchor() for that.
 *
 * 2026-09-28 (Steve): "we can leave out any shirt or hat orders from
 * this function. Janet handles those" - shirts/hats pay to Janet's
 * account (merch_is_printed_item() false), and she chases her own
 * unpaid customers separately; at the time this was added Steve had
 * already hand-sent her the handful of outstanding shirt/hat lines
 * (four, by his count) himself. This is the single eligibility gate
 * both merch_reminder_build_groups() (preview) and
 * merch_reminder_group_for_anchor() (send-time re-derivation) call, so
 * excluding shop items here is enough to keep them out of the whole
 * feature - nothing downstream needs its own copy of this check.
 */
function merch_reminder_row_eligible(array $row, array $col): bool
{
    $fulfillment = trim($row[$col['Fulfillment']] ?? '');
    $invoiced = trim($row[$col['Invoice Date']] ?? '');
    $paid = trim($row[$col['Pymt Date']] ?? '');
    $email = trim($row[$col['Email']] ?? '');
    $cancelled = $col['Cancelled'] !== false && trim($row[$col['Cancelled']] ?? '') !== '';
    $isPrinted = merch_is_printed_item(trim($row[$col['Item']] ?? ''));
    if ($fulfillment !== 'Ship' || $invoiced === '' || $paid !== '' || $email === '' || $cancelled || !$isPrinted) {
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
 * Turns a group's raw CSV rows into merch_group_calculate()'s expected
 * $items shape - one entry per ROW, unaggregated (unlike
 * merch_reminder_aggregate_items() above, which merges same-item
 * quantities purely for display). Pricing can't use the aggregated
 * form: two rows for the same item can still carry different
 * size/sleeve/color, which changes the unit price, so each row has to
 * reach merch_group_calculate() as its own line - exactly how
 * merch_invoice.php builds $items for the same function (see that
 * file's grouping loop).
 */
function merch_reminder_items_for_pricing(array $groupRows, array $col): array
{
    return array_map(fn($row) => [
        'item' => trim($row[$col['Item']] ?? ''),
        'quantity' => (int) ($row[$col['Quantity']] ?? 1),
        'size' => trim($row[$col['Size']] ?? ''),
        'sleeve' => trim($row[$col['Sleeve']] ?? ''),
        'color' => trim($row[$col['Color']] ?? ''),
    ], $groupRows);
}

/**
 * Re-prices a reminder group exactly the way merch_invoice.php priced
 * it the first time - same merch_group_calculate() call, same
 * $isShipping=true (reminders are Ship-only, see this file's header
 * comment), same $isPrinted the group was already split on. 2026-09-28
 * (Steve): "if we recalculate the invoice to put in the nudge, we need
 * to try to constrain that invoice total to just the lines from that
 * order" - that constraint is what merch_reminder_build_groups() /
 * merch_reminder_group_for_anchor() enforce by grouping on Invoice
 * Date now, not this function; this function just prices whatever
 * rows it's handed.
 *
 * Returns null if any row's Item isn't recognized (same fail-closed
 * behavior as merch_group_calculate() itself - shouldn't happen for a
 * row that was successfully invoiced once already, but a hand-edited
 * CSV could produce it). Returns a normal pricing array otherwise,
 * though 'shipping' (and therefore 'total') inside it can still be
 * null - that's the "needs a manual shipping quote" case
 * merch_invoice.php itself would have hit, and since a manual override
 * is only ever applied in memory for that one send (never written back
 * to the CSV - see merch_invoice.php's $manualShipping handling),
 * there's no way to recover what Steve actually typed in. Callers
 * (merch_notify.php's merch_send_payment_reminder(),
 * merch_reminders.php's preview) both treat shipping===null as "don't
 * show a total for this one" rather than guessing at it - see the
 * comment on $showTotal in merch_send_payment_reminder().
 */
function merch_reminder_group_pricing(array $groupRows, array $col, bool $isPrinted): ?array
{
    return merch_group_calculate(merch_reminder_items_for_pricing($groupRows, $col), true, $isPrinted);
}

/**
 * Looks for a signal that a reminder group might already be moot: the
 * same email address with a LATER row (any fulfillment, any item) that
 * actually got paid. 2026-09-28 (Steve, via two real scenarios he'd
 * seen): a customer's original request/invoice email landed in spam,
 * so they re-submitted thinking it never went through, and the SECOND
 * order is the one that got paid - or a customer got invoiced,
 * realized they'd mis-ordered, and just quietly placed a new order
 * instead of dealing with the wrong one. Either way, the OLD invoiced
 * row sits here forever looking unpaid, when the customer likely
 * considers the matter closed.
 *
 * Deliberately not a hard filter: this function only reports what it
 * finds (Steve's call, 2026-09-28, via AskUserQuestion: flag it on the
 * preview page and default that row's checkbox OFF, never auto-send
 * and never silently hide it) - a later paid order is a strong hint,
 * not proof; the old row could just as easily be a second, genuinely
 * separate thing the customer still owes for.
 *
 * $afterTimestamp is the group's own latest row Timestamp (parsed) -
 * pass null if it couldn't be parsed at all, which makes this return
 * empty rather than risk comparing against nothing meaningful.
 * Deliberately scans the FULL $rows array, not just eligible ones - a
 * paid, cancelled, or Pickup row all count as "this customer moved on"
 * signals just as much as a paid Ship row would.
 *
 * Returns a list of ['item'=>, 'quantity'=>, 'paidDate'=>] for display.
 */
function merch_reminder_find_possibly_abandoned(array $rows, array $col, string $email, ?DateTime $afterTimestamp): array
{
    if ($afterTimestamp === null) {
        return [];
    }

    $found = [];
    foreach ($rows as $row) {
        $rowEmail = strtolower(trim($row[$col['Email']] ?? ''));
        $paid = trim($row[$col['Pymt Date']] ?? '');
        if ($rowEmail !== $email || $paid === '') {
            continue;
        }
        $ts = merch_reminder_parse_timestamp(trim($row[$col['Timestamp']] ?? ''));
        if ($ts === null || $ts <= $afterTimestamp) {
            continue;
        }
        $found[] = [
            'item' => trim($row[$col['Item']] ?? ''),
            'quantity' => (int) ($row[$col['Quantity']] ?? 1),
            'paidDate' => $paid,
        ];
    }
    return $found;
}

/**
 * Latest (parsed) Timestamp among a group's rows, or null if none of
 * them parse - shared by merch_reminder_build_groups() below to feed
 * merch_reminder_find_possibly_abandoned()'s $afterTimestamp.
 */
function merch_reminder_latest_timestamp(array $groupRows, array $col): ?DateTime
{
    $latest = null;
    foreach ($groupRows as $row) {
        $ts = merch_reminder_parse_timestamp(trim($row[$col['Timestamp']] ?? ''));
        if ($ts !== null && ($latest === null || $ts > $latest)) {
            $latest = $ts;
        }
    }
    return $latest;
}

/**
 * Groups every eligible row into reminder groups, keyed by email +
 * printed-vs-shop account type (merch_is_printed_item(), pricing.php)
 * + Invoice Date (2026-09-28, normalized via
 * merch_reminder_normalize_date() - see that function's comment) -
 * kept separate from merch_invoice.php's identity formula only in that
 * it drops the Name fallback (see this file's header comment for why).
 * Printed and shop items still group separately here for the same
 * reason merch_invoice.php keeps them separate: they're different
 * orders on different timelines as far as Steve's own bookkeeping
 * goes, even for the same customer, so one reminder shouldn't conflate
 * them.
 *
 * The Invoice Date component of the key is new as of 2026-09-28, added
 * specifically so a real dollar total could be shown safely (see
 * merch_reminder_group_pricing()): rows sharing one Invoice Date are
 * exactly the set merch_invoice.php combined into ONE real invoice
 * when Send Invoice was clicked (that button always stamps every row
 * it combines with the same date), so re-pricing exactly that set
 * reproduces the real total instead of risking a different bundle
 * discount or shipping tier from blending two unrelated invoices
 * together. The accepted edge case: two genuinely separate Send
 * Invoice clicks for the same customer+type landing on the exact same
 * calendar day would still merge here - rare enough (Steve invoices in
 * daily batches, not per-customer-per-day) that it's not worth the
 * extra machinery a stricter split would need.
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
 *     'pricing'    => merch_reminder_group_pricing() output - null if
 *                      an item couldn't be priced; 'shipping'/'total'
 *                      inside it can also independently be null (needs
 *                      a manual quote) - see that function's comment,
 *     'possiblyAbandoned' => merch_reminder_find_possibly_abandoned() output,
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
        // Falls back to the raw trimmed string only if normalization
        // somehow fails - it never should here, since
        // merch_reminder_row_eligible() already required this exact
        // cell to produce a non-null merch_reminder_invoice_age_days()
        // result, which means merch_reminder_parse_date() already
        // parsed it successfully.
        $invoiceKey = merch_reminder_normalize_date(trim($row[$col['Invoice Date']] ?? '')) ?? trim($row[$col['Invoice Date']] ?? '');
        $key = $email . '|' . ($isPrinted ? 'printed' : 'shop') . '|' . $invoiceKey;
        $groups[$key][] = $row;
    }

    $result = [];
    foreach ($groups as $groupRows) {
        $numericIds = array_map(fn($r) => (int) trim($r[$col['OrderID']] ?? '0'), $groupRows);
        $anchorIndex = array_search(min($numericIds), $numericIds, true);
        $anchor = $groupRows[$anchorIndex];
        $isPrinted = merch_is_printed_item(trim($anchor[$col['Item']] ?? ''));
        $email = strtolower(trim($anchor[$col['Email']] ?? ''));

        $invoiceDates = array_values(array_filter(array_map(fn($r) => trim($r[$col['Invoice Date']] ?? ''), $groupRows)));
        sort($invoiceDates);
        $earliestInvoiceDate = $invoiceDates[0] ?? '';

        $result[] = [
            'anchorOrderId' => (string) min($numericIds),
            'orderIds' => array_map(fn($r) => trim($r[$col['OrderID']] ?? ''), $groupRows),
            'name' => trim($anchor[$col['Name']] ?? ''),
            'email' => trim($anchor[$col['Email']] ?? ''),
            'isPrinted' => $isPrinted,
            'items' => merch_reminder_aggregate_items($groupRows, $col),
            'invoiceDate' => $earliestInvoiceDate,
            'invoiceAgeDays' => $earliestInvoiceDate !== '' ? merch_reminder_invoice_age_days($earliestInvoiceDate) : null,
            'pricing' => merch_reminder_group_pricing($groupRows, $col, $isPrinted),
            'possiblyAbandoned' => merch_reminder_find_possibly_abandoned($rows, $col, $email, merch_reminder_latest_timestamp($groupRows, $col)),
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
 *
 * 2026-09-28: now also scopes the match to the anchor's own
 * (normalized) Invoice Date, same as merch_reminder_build_groups() -
 * see that function's comment for why. Keeps the re-derived group at
 * send time identical in shape to whatever the preview page showed for
 * this anchor, rather than the two drifting apart.
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
    $anchorInvoiceKey = merch_reminder_normalize_date(trim($anchor[$col['Invoice Date']] ?? '')) ?? trim($anchor[$col['Invoice Date']] ?? '');

    $groupRows = [];
    foreach ($rows as $row) {
        if (!merch_reminder_row_eligible($row, $col)) {
            continue;
        }
        $email = strtolower(trim($row[$col['Email']] ?? ''));
        $invoiceKey = merch_reminder_normalize_date(trim($row[$col['Invoice Date']] ?? '')) ?? trim($row[$col['Invoice Date']] ?? '');
        if ($email === $anchorEmail
            && merch_is_printed_item(trim($row[$col['Item']] ?? '')) === $anchorIsPrinted
            && $invoiceKey === $anchorInvoiceKey) {
            $groupRows[] = $row;
        }
    }

    return [
        'orderIds' => array_map(fn($r) => trim($r[$col['OrderID']] ?? ''), $groupRows),
        'name' => trim($anchor[$col['Name']] ?? ''),
        'email' => trim($anchor[$col['Email']] ?? ''),
        'isPrinted' => $anchorIsPrinted,
        'items' => merch_reminder_aggregate_items($groupRows, $col),
        'pricing' => merch_reminder_group_pricing($groupRows, $col, $anchorIsPrinted),
    ];
}
