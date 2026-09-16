<?php
// Build: 2026-09-16-A
// ============================================================
// Shared config/helpers for the gift-certificate feature (Steve,
// 2026-09-16 - holiday season idea from Janet: sell certificates
// buyers can print and put in a card, redeemed by hand at pickup/
// registration, same "no checkout math" philosophy as the rest of
// this site - Venmo/PayPal still do all the actual money-collecting).
//
// Two cert TYPES, because the site's payment accounts are really a
// 3-way split (see config.php), not a clean merch/retreat divide:
//   - 'Merch'   pays to Steve's printed-items account (VENMO/PAYPAL
//               _PRINTED). Steve's own call (2026-09-16): even though
//               shirts/hats normally route to Janet, a Merch cert
//               always pays HIM - simplest single answer for v1. If
//               one's ever redeemed against a shirt/hat, that's a
//               manual transfer to Janet outside the site, same as
//               any other cash-handling here.
//   - 'Retreat' pays to Janet's account (VENMO/PAYPAL_MERCH - yes,
//               that constant name is misleading; see config.php's
//               own comment, it's really "registrations + shirts/
//               hats", i.e. Janet's money). Tied to one specific
//               UPCOMING event at purchase time, which is also what
//               gives a Retreat cert its natural expiration - a Merch
//               cert has none.
//
// Redemption is entirely manual (Janet or Steve checking the code
// against gift_certificates.csv by hand and marking it Redeemed) -
// there is no balance-tracking or partial-redemption logic here, on
// purpose. This file only mints codes and validates request-form
// input; gift_certificate_order.php does the actual CSV write.
// ============================================================

require_once __DIR__ . '/id_sequence.php';
require_once __DIR__ . '/events/events_helpers.php';

// Preset amount choices shown on the request form alongside a "custom
// amount" field - Steve's call (2026-09-16): a short tier list keeps
// the printed certificate's amount line simple and keeps Venmo/PayPal
// requests easy to eyeball against gift_certificates.csv, without
// hard-blocking someone who wants an odd amount.
const GIFTCERT_TIERS = [25, 50, 100, 150];

// Bounds for the "custom amount" field - not a real pricing rule, just
// a sanity ceiling/floor against typos or abuse (a $0 or $50,000
// certificate is almost certainly a mistake, not a real request).
const GIFTCERT_MIN_AMOUNT = 5;
const GIFTCERT_MAX_AMOUNT = 500;

// Column order for gift_certificates.csv - keyed-by-name row building
// (same convention as merchandise.csv/registrations.csv, see those
// files' headers) so a hand-added column later can't silently shift
// every value after it.
const GIFTCERT_CSV_HEADER = [
    'Code', 'Type', 'Amount', 'Event', 'To', 'From',
    'BuyerName', 'BuyerEmail', 'IssuedDate', 'Redeemed', 'RedeemedDate', 'IP',
];

// Reasonable print-safe cap on the free-text name fields (To/From/
// BuyerName) - same idea as merch_order.php's NOTES_MAX_LENGTH clamp,
// just for fields that end up laid out on a fixed-size printed card
// rather than in an email body.
const GIFTCERT_NAME_MAX_LENGTH = 60;

/**
 * "Merch" or "Retreat" -> its single-letter code prefix. Centralized
 * here so the form, the handler, and (eventually) an admin view never
 * risk disagreeing about which letter goes with which type.
 */
function giftcert_prefix_for_type(string $type): string
{
    return $type === 'Retreat' ? 'R' : 'M';
}

/**
 * Mints the next code for one type (e.g. "M-000042"), via the same
 * persistent-counter pattern as OrderID/RegID (id_sequence.php) - a
 * separate counter file per type so Merch and Retreat sequences never
 * collide or interleave. $csvCurrentMax is the highest number already
 * seen in gift_certificates.csv for this type (scanned by the caller
 * from inside its own locked read - see gift_certificate_order.php),
 * used only to bootstrap/floor the counter, same as every other ID
 * sequence on this site.
 */
function giftcert_next_code(string $type, int $csvCurrentMax): string
{
    $prefix = giftcert_prefix_for_type($type);
    $counterFile = __DIR__ . '/giftcert_' . strtolower($type) . '_counter.txt';
    $next = merch_next_persistent_id($counterFile, $csvCurrentMax);
    return sprintf('%s-%06d', $prefix, $next);
}

/**
 * Pulls the numeric part out of a code ("M-000042" -> 42), for
 * scanning gift_certificates.csv's own current max per type. Returns
 * null for anything that doesn't match the expected shape (a hand-
 * edited row, say) rather than guessing.
 */
function giftcert_code_number(string $code): ?int
{
    return preg_match('/^[A-Z]-(\d+)$/', $code, $m) ? (int) $m[1] : null;
}

/**
 * Every valid "Retreat" cert event label, same "dateRange – title"
 * format merch_order.php already validates pickup retreats against -
 * one source of truth (events/events-data.php via events_helpers.php)
 * instead of a second hand-typed list that could drift. Deliberately
 * upcoming-only (events_upcoming() minus anything already past): a
 * certificate good for a retreat that's already happened, or that
 * Steve archived, isn't a real gift.
 */
function giftcert_valid_retreat_events(): array
{
    $events = array_filter(events_upcoming(), fn($e) => !events_is_past($e));
    return array_values(array_map(
        fn($e) => trim($e['dateRange']) . ' – ' . trim($e['title']),
        $events
    ));
}

/**
 * Validates a submitted amount against the tier list or the custom
 * bounds. Returns the validated float, or null if it's neither a
 * recognized tier nor a legal custom amount.
 */
function giftcert_validate_amount(string $amountRaw, string $customRaw): ?float
{
    if ($amountRaw === 'custom') {
        $custom = filter_var(trim($customRaw), FILTER_VALIDATE_FLOAT);
        if ($custom === false) {
            return null;
        }
        if ($custom < GIFTCERT_MIN_AMOUNT || $custom > GIFTCERT_MAX_AMOUNT) {
            return null;
        }
        // Round to whole cents - a customer-typed float like 49.999
        // shouldn't end up on a printed certificate with more than two
        // decimal places.
        return round($custom, 2);
    }

    $tier = filter_var($amountRaw, FILTER_VALIDATE_INT);
    if ($tier !== false && in_array($tier, GIFTCERT_TIERS, true)) {
        return (float) $tier;
    }

    return null;
}

/**
 * Formats a dollar amount for display - whole-dollar tiers show as
 * "50" (no trailing ".00"), a custom amount with real cents shows as
 * "49.50". Callers add their own leading "$". Used on both the
 * printed certificate and its backup email so the two never disagree
 * about formatting.
 */
function giftcert_format_amount(float $amount): string
{
    return ((float) $amount === floor($amount))
        ? number_format($amount, 0)
        : number_format($amount, 2);
}

/**
 * Trims and caps a free-text name field to GIFTCERT_NAME_MAX_LENGTH,
 * same clamp-not-reject approach as NOTES_MAX_LENGTH elsewhere on this
 * site - a client-side maxlength alone can be bypassed by anyone
 * posting directly to gift_certificate_order.php.
 */
function giftcert_clamp_name(string $value): string
{
    $value = trim($value);
    return mb_strlen($value) > GIFTCERT_NAME_MAX_LENGTH
        ? mb_substr($value, 0, GIFTCERT_NAME_MAX_LENGTH)
        : $value;
}
