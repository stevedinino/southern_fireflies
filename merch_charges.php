<?php
// Build: 2026-10-18-A
// ============================================================
// Per-invoice charge records: shipping.csv and tax.csv.
//
// Why: merchandise.csv's Shipping/Tax columns are per-line allocations,
// not what the customer was actually charged (Steve, 2026-10-18: real
// charges had to be recovered from sent invoice emails). From now on
// every invoice Send Invoice sends also writes one row to EACH of these
// two files, sharing a ChargeID (like two tables joined on a key):
//
//   shipping.csv: ChargeID, InvoiceDate, Email, OrderIDs, Amount, Basis,
//                 Status, SupersededBy, Source, Notes
//   tax.csv:      ChargeID, InvoiceDate, Email, OrderIDs, ItemsSubtotal,
//                 Discount, TaxableAmount, Rate, Amount, InvoiceTotal,
//                 Status, SupersededBy, Source, Notes
//
// OrderIDs is a semicolon list (one invoice can cover several order
// lines). Source is 'recorded' for rows written here ('email' = recovered
// from an old invoice email, 'unverified' = no email found).
//
// Shipping Basis: a Tool Holder Stand forces a box, so shipping is $10
// whenever one is in the order (Steve's rule, 2026-10-18). $10 without a
// Stand is labelled as the not-yet-a-rule habit; anything else is
// 'other / hand-priced'.
//
// Recording NEVER blocks or undoes the invoice: any failure is
// error_log()ed and swallowed. Both files are gitignored (customer
// emails) and blocked from the web by the *.csv rule in .htaccess.
// ============================================================

require_once __DIR__ . '/csv_safety.php';
require_once __DIR__ . '/id_sequence.php';

function merch_charges_shipping_file(): string
{
    return defined('MERCH_SHIPPING_FILE') ? MERCH_SHIPPING_FILE : __DIR__ . '/shipping.csv';
}

function merch_charges_tax_file(): string
{
    return defined('MERCH_TAX_FILE') ? MERCH_TAX_FILE : __DIR__ . '/tax.csv';
}

function merch_charges_counter_file(): string
{
    return defined('MERCH_CHARGE_COUNTER_FILE') ? MERCH_CHARGE_COUNTER_FILE : __DIR__ . '/charges_chargeid_counter.txt';
}

function merch_charges_shipping_header(): array
{
    return ['ChargeID', 'InvoiceDate', 'Email', 'OrderIDs', 'Amount', 'Basis', 'Status', 'SupersededBy', 'Source', 'Notes'];
}

function merch_charges_tax_header(): array
{
    return ['ChargeID', 'InvoiceDate', 'Email', 'OrderIDs', 'ItemsSubtotal', 'Discount', 'TaxableAmount', 'Rate', 'Amount', 'InvoiceTotal', 'Status', 'SupersededBy', 'Source', 'Notes'];
}

/** Highest numeric part of any ChargeID (C0001 -> 1) in $file, 0 if none. */
function merch_charges_max_id(string $file): int
{
    $h = @fopen($file, 'rb');
    if (!$h) {
        return 0;
    }
    $max = 0;
    $first = true;
    while (($row = fgetcsv($h, 0, ',', '"', '')) !== false) {
        if ($first) {
            $first = false;
            continue;
        }
        if (isset($row[0]) && preg_match('/^C(\d+)$/', trim($row[0]), $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    fclose($h);
    return $max;
}

/** Classifies a shipping amount into the Basis vocabulary used in shipping.csv. */
function merch_charges_shipping_basis(float $amount, bool $isShipping, bool $hasStand, int $totalQty): string
{
    if (!$isShipping) {
        return 'pickup (no shipping)';
    }
    if ($hasStand && abs($amount - 10.0) < 0.005) {
        return 'box $10 (Tool Stand + up to 2 small)';
    }
    if ($hasStand && abs($amount - 12.0) < 0.005) {
        return 'box $12 (Tool Stand + 3-4 small)';
    }
    if (abs($amount - 7.0) < 0.005 && !$hasStand) {
        return 'standard $7';
    }
    if (abs($amount - 6.0) < 0.005 && !$hasStand) {
        return 'standard $6'; // the rate before 2026-10-31; kept so a re-sent old invoice still classifies
    }
    if (abs($amount - 10.0) < 0.005 && !$hasStand && $totalQty >= 3) {
        return 'box $10 (3-4 small items)';
    }
    if ($amount == 0.0) {
        return 'none (pickup/no shipping line)';
    }
    return 'other / hand-priced';
}

/**
 * Appends one row each to shipping.csv and tax.csv for an invoice that
 * was just sent (or, for pickup, generated). Returns the new ChargeID,
 * or '' on any failure (already logged).
 *
 * @param array  $pricing  merch_group_calculate() result (after any manual shipping override)
 * @param string $email    customer email as on the order
 * @param array  $orderIds OrderIDs covered by this invoice
 * @param array  $items    the grouped item rows ('item', 'quantity', ...)
 * @param bool   $isShipping false for a pickup document
 * @param bool   $manualShipping true if Steve typed the shipping amount
 * @param string $invoiceDate Y-m-d actually stamped (blank = today)
 */
function merch_charges_record(array $pricing, string $email, array $orderIds, array $items, bool $isShipping, bool $manualShipping, string $invoiceDate = ''): string
{
    try {
        $date = $invoiceDate !== '' ? $invoiceDate : date('Y-m-d');
        $shipFile = merch_charges_shipping_file();
        $taxFile = merch_charges_tax_file();

        $hasStand = false;
        $qty = 0;
        foreach ($items as $it) {
            $qty += (int) ($it['quantity'] ?? 1);
            if (stripos((string) ($it['item'] ?? ''), 'Tool Holder Stand') !== false) {
                $hasStand = true;
            }
        }

        $shipAmount = $isShipping ? (float) ($pricing['shipping'] ?? 0) : 0.0;
        $basis = merch_charges_shipping_basis($shipAmount, $isShipping, $hasStand, $qty);
        $notes = $manualShipping ? 'shipping typed by hand at send' : '';

        $subtotal = (float) ($pricing['subtotal'] ?? 0);
        $discount = (float) ($pricing['bundleDiscount'] ?? 0) + (float) ($pricing['fullSetDiscount'] ?? 0);
        $taxable = $subtotal - $discount;

        $floor = max(merch_charges_max_id($shipFile), merch_charges_max_id($taxFile));
        $n = merch_next_persistent_id(merch_charges_counter_file(), $floor);
        $chargeId = sprintf('C%04d', $n);

        $ids = merch_csv_safe_cell(implode(';', array_map('strval', $orderIds)));
        $safeEmail = merch_csv_safe_cell(strtolower(trim($email)));

        $shipRow = [$chargeId, $date, $safeEmail, $ids, number_format($shipAmount, 2, '.', ''), $basis, 'linked', '', 'recorded', $notes];
        $taxRow = [
            $chargeId, $date, $safeEmail, $ids,
            number_format($subtotal, 2, '.', ''),
            number_format($discount, 2, '.', ''),
            number_format($taxable, 2, '.', ''),
            '0.07',
            number_format((float) ($pricing['tax'] ?? 0), 2, '.', ''),
            number_format((float) ($pricing['total'] ?? 0), 2, '.', ''),
            'linked', '', 'recorded', '',
        ];

        $okShip = merch_charges_append($shipFile, merch_charges_shipping_header(), $shipRow);
        $okTax = merch_charges_append($taxFile, merch_charges_tax_header(), $taxRow);
        if (!$okShip || !$okTax) {
            error_log("Southern Fireflies: charge {$chargeId} recorded incompletely (shipping " . ($okShip ? 'ok' : 'FAILED') . ', tax ' . ($okTax ? 'ok' : 'FAILED') . ')');
            return '';
        }
        return $chargeId;
    } catch (\Throwable $e) {
        error_log('Southern Fireflies: charge record failed: ' . $e->getMessage());
        return '';
    }
}

/** Append-only write: flock, header if the file is empty. */
function merch_charges_append(string $file, array $header, array $row): bool
{
    $h = @fopen($file, 'ab');
    if (!$h) {
        error_log("Southern Fireflies: could not open '{$file}' for append");
        return false;
    }
    $ok = false;
    if (flock($h, LOCK_EX)) {
        $stat = fstat($h);
        if (($stat['size'] ?? 0) === 0) {
            fputcsv($h, $header, ',', '"', '');
        }
        $ok = fputcsv($h, $row, ',', '"', '') !== false;
        fflush($h);
        flock($h, LOCK_UN);
    }
    fclose($h);
    return $ok;
}
