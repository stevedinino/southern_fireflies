<?php
// Build: 2026-10-30-A
// ============================================================
// How each invoice was PAID: payments.csv (the third table beside
// shipping.csv and tax.csv, joined on ChargeID).
//
//   ChargeID, PaidDate, Method, AmountReceived, FeeRate, Fee, Net, OrderIDs, Source, Notes
//
// Written by Mark Paid (merch_mark_paid.php) once the CSV update has
// succeeded. Append-only, flock + header-if-empty, never blocks or undoes
// the payment: failures are error_log()ed and swallowed.
//
// Method comes from the dropdown in the Mark Paid dialog. Fee is ESTIMATED
// from merch_payment_fee_rates() below (change the rates there - e.g. when
// a Venmo business profile exists). AmountReceived defaults to the
// invoice total (tax.csv InvoiceTotal) and can be typed over when someone
// rounds up or short-pays. If an order is un-marked and marked paid again,
// a second row is appended; the LATEST row for a ChargeID governs.
// Rows for payments before 2026-10-18 do not exist yet (backfill later,
// Source 'backfill').
// ============================================================

require_once __DIR__ . '/csv_safety.php';

function merch_payments_file(): string
{
    return defined('MERCH_PAYMENTS_FILE') ? MERCH_PAYMENTS_FILE : __DIR__ . '/payments.csv';
}

function merch_payments_header(): array
{
    return ['ChargeID', 'PaidDate', 'Method', 'AmountReceived', 'FeeRate', 'Fee', 'Net', 'OrderIDs', 'Source', 'Notes'];
}

/** Estimated fee rate per method. PayPal measured 2026-10-18 (about 2.99%, no fixed fee). */
function merch_payment_fee_rates(): array
{
    // 'Stripe' is listed so the method is recognised; its fee is never taken
    // from this table - the Stripe webhook passes the real fee in (see
    // $feeOverride below).
    return ['Venmo' => 0.0, 'PayPal' => 0.0299, 'Zelle' => 0.0, 'Check' => 0.0, 'Cash' => 0.0, 'Stripe' => 0.0, 'Other' => 0.0];
}

/** The ChargeID of the latest linked tax.csv row covering any of $orderIds, and its invoice total. */
function merch_payments_find_charge(array $orderIds): array
{
    $file = defined('MERCH_TAX_FILE') ? MERCH_TAX_FILE : __DIR__ . '/tax.csv';
    $h = @fopen($file, 'rb');
    if (!$h) {
        return ['', null];
    }
    $want = array_map('strval', $orderIds);
    $best = ['', null];
    $first = true;
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        if ($first) { $first = false; continue; }
        // columns: 0 ChargeID, 3 OrderIDs, 9 InvoiceTotal, 10 Status
        if (($r[10] ?? '') !== 'linked') {
            continue;
        }
        if (array_intersect(explode(';', (string) ($r[3] ?? '')), $want)) {
            if ($best[0] === '' || strcmp($r[0], $best[0]) > 0) {
                $best = [(string) $r[0], is_numeric($r[9] ?? '') ? (float) $r[9] : null];
            }
        }
    }
    fclose($h);
    return $best;
}

/**
 * Returns true if a row was written.
 *
 * 2026-10-30 (Stripe): optional $feeOverride is the ACTUAL fee in dollars
 * (Stripe reports it; the rate table above is only an estimate for methods
 * where nobody tells us). FeeRate is then stored as fee / amount received.
 * $source and $extraNote let the Stripe webhook label its rows
 * ('stripe', 'stripe pi_...'); the Mark Paid dialog passes neither.
 */
function merch_payments_record(array $orderIds, string $method, ?float $amountTyped, string $paidDate, ?float $feeOverride = null, string $source = 'recorded', string $extraNote = ''): bool
{
    try {
        $rates = merch_payment_fee_rates();
        $notes = '';
        if (!isset($rates[$method])) {
            $method = 'Other';
            $notes = 'method not recognised';
        }
        [$chargeId, $invoiceTotal] = merch_payments_find_charge($orderIds);
        if ($amountTyped !== null) {
            $received = $amountTyped;
            if ($invoiceTotal !== null && abs($amountTyped - $invoiceTotal) >= 0.005) {
                $notes = trim($notes . ' amount differs from invoice total ' . number_format($invoiceTotal, 2, '.', ''));
            }
        } else {
            $received = $invoiceTotal;
        }
        if ($chargeId === '') {
            $notes = trim($notes . ' no linked charge record found');
        }
        if ($extraNote !== '') {
            $notes = trim($notes . ' ' . $extraNote);
        }
        if ($feeOverride !== null && $received !== null && $received > 0) {
            $rate = round($feeOverride / $received, 4);
            $fee = round($feeOverride, 2);
        } else {
            $rate = $rates[$method];
            $fee = $received !== null ? round($received * $rate, 2) : null;
        }
        $net = $received !== null ? round($received - $fee, 2) : null;
        $f = fn($v) => $v === null ? '' : number_format($v, 2, '.', '');
        $row = [$chargeId, $paidDate, $method, $f($received), rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.') ?: '0', $f($fee), $f($net),
                merch_csv_safe_cell(implode(';', array_map('strval', $orderIds))), $source, $notes];

        $file = merch_payments_file();
        $h = @fopen($file, 'ab');
        if (!$h) {
            error_log("Southern Fireflies: could not open '{$file}' for append");
            return false;
        }
        $ok = false;
        if (flock($h, LOCK_EX)) {
            $stat = fstat($h);
            if (($stat['size'] ?? 0) === 0) {
                fputcsv($h, merch_payments_header(), ',', '"', '');
            }
            $ok = fputcsv($h, $row, ',', '"', '') !== false;
            fflush($h);
            flock($h, LOCK_UN);
        }
        fclose($h);
        return $ok;
    } catch (\Throwable $e) {
        error_log('Southern Fireflies: payment record failed: ' . $e->getMessage());
        return false;
    }
}
