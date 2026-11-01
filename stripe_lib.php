<?php
// Build: 2026-10-30-A
// ============================================================
// Stripe checkout for the merch shop (2026-10-30, Steve).
//
// THE IDEA
//   A customer submits their list on merch.php exactly as before.
//   merch_order.php writes the rows to merchandise.csv exactly as before.
//   THEN, if every part of the order can be priced automatically, this file
//   creates a Stripe Checkout page for the exact total and the customer is
//   sent there to pay. When Stripe says the payment went through
//   (stripe_webhook.php), the rows are stamped Invoice Date + Pymt Date, the
//   charge and payment records are written, and the usual "payment received"
//   email goes out. Nothing is stamped before payment, so an abandoned
//   checkout is simply a normal request still waiting in Needs Invoicing.
//
// THE OFF SWITCH
//   config.php: define('STRIPE_CHECKOUT_ENABLED', true);   <- on
//               define('STRIPE_CHECKOUT_ENABLED', false);  <- off (or delete it)
//   Off means merch_order.php behaves exactly as it did before Stripe.
//   The switch is only "on" for the real boolean true - the text 'true'
//   does NOT turn it on, on purpose (a typo fails safe).
//
// KEYS (config.php only - never in the repo, never in chat)
//   STRIPE_SECRET_KEY       sk_test_... while testing, sk_live_... for real
//   STRIPE_WEBHOOK_SECRET   whsec_... from the webhook endpoint in Stripe
//   SITE_BASE_URL           optional, default https://southernfirefliesretreats.com
//
// DATA FILES (both gitignored, both blocked from the web by the *.csv rule
// in .htaccess). Append-only, latest row for a SessionID wins:
//   stripe_sessions.csv  SessionID, Time, State, Email, Name, AmountCents,
//                        PaymentIntent, ChargeIDs, Groups, Notes
//     State: created | paid | review | expired | email-failed
//     Groups: JSON - per invoice group the OrderIDs, items and the exact
//             pricing the customer was shown (so the records written after
//             payment match what they paid even if prices change meanwhile)
//   stripe_events.csv    EventID, Time, Type, SessionID, Result
//   stripe_webhook.lock  empty file; only used so two webhooks never run at once
//
// MONEY RULES
//   - Cents are whole numbers everywhere here. The Stripe total must equal
//     the site's own total to the cent or checkout is NOT offered (the
//     customer falls back to the normal request-and-invoice flow).
//   - Discounts go in as one coupon; sales tax and each shipping charge are
//     their own line items. Stripe Tax and Stripe shipping options stay off.
//   - The real Stripe fee is read from the balance transaction after
//     payment; if that lookup fails the fee is estimated (2.9% + 30c) and
//     the payments.csv note says so.
// ============================================================

require_once __DIR__ . '/sff_timezone.php'; // 2026-10-31: Eastern time, so a payment made after 8 PM gets TODAY's Invoice/Pymt Date, not tomorrow's (the webhook is its own entry point)
require_once __DIR__ . '/csv_safety.php';
require_once __DIR__ . '/merch_charges.php';  // merch_charges_append(), merch_charges_record()
require_once __DIR__ . '/merch_payments.php'; // merch_payments_record()

function merch_stripe_enabled(): bool
{
    return defined('STRIPE_CHECKOUT_ENABLED') && STRIPE_CHECKOUT_ENABLED === true
        && defined('STRIPE_SECRET_KEY') && is_string(STRIPE_SECRET_KEY) && STRIPE_SECRET_KEY !== ''
        && is_file(__DIR__ . '/stripe-php/init.php');
}

function merch_stripe_client(): \Stripe\StripeClient
{
    require_once __DIR__ . '/stripe-php/init.php';
    // Stripe's default is to wait up to 80 seconds. A customer staring at a
    // blank page that long is worse than falling back to the normal
    // request-and-invoice flow, so give up much sooner (the order itself
    // is already saved by then).
    $curl = \Stripe\HttpClient\CurlClient::instance();
    $curl->setTimeout(15);
    $curl->setConnectTimeout(8);
    return new \Stripe\StripeClient(STRIPE_SECRET_KEY);
}

function merch_stripe_site_url(): string
{
    $base = defined('SITE_BASE_URL') ? (string) SITE_BASE_URL : 'https://southernfirefliesretreats.com';
    return rtrim($base, '/');
}

function merch_stripe_sessions_file(): string
{
    return defined('MERCH_STRIPE_SESSIONS_FILE') ? MERCH_STRIPE_SESSIONS_FILE : __DIR__ . '/stripe_sessions.csv';
}

function merch_stripe_events_file(): string
{
    return defined('MERCH_STRIPE_EVENTS_FILE') ? MERCH_STRIPE_EVENTS_FILE : __DIR__ . '/stripe_events.csv';
}

function merch_stripe_lock_file(): string
{
    return defined('MERCH_STRIPE_LOCK_FILE') ? MERCH_STRIPE_LOCK_FILE : __DIR__ . '/stripe_webhook.lock';
}

function merch_stripe_sessions_header(): array
{
    return ['SessionID', 'Time', 'State', 'Email', 'Name', 'AmountCents', 'PaymentIntent', 'ChargeIDs', 'Groups', 'Notes'];
}

function merch_stripe_events_header(): array
{
    return ['EventID', 'Time', 'Type', 'SessionID', 'Result'];
}

/** Whole cents from a dollar amount. */
function merch_stripe_cents(float $dollars): int
{
    return (int) round($dollars * 100);
}

/**
 * Latest row for a session as an associative array, plus the original
 * 'created' row's Groups JSON (later rows don't repeat it). Null when the
 * session is unknown. Read-only, no lock needed for a single pass.
 */
function merch_stripe_session_state(string $sessionId): ?array
{
    $h = @fopen(merch_stripe_sessions_file(), 'rb');
    if (!$h) {
        return null;
    }
    $header = merch_stripe_sessions_header();
    $latest = null;
    $created = null;
    $first = true;
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        if ($first) {
            $first = false;
            continue;
        }
        if (($r[0] ?? '') !== $sessionId) {
            continue;
        }
        $row = [];
        foreach ($header as $i => $name) {
            $row[$name] = $r[$i] ?? '';
        }
        $latest = $row;
        if ($row['State'] === 'created') {
            $created = $row;
        }
    }
    fclose($h);
    if ($latest === null) {
        return null;
    }
    $latest['CreatedRow'] = $created;
    return $latest;
}

function merch_stripe_append_session_row(string $sessionId, string $state, string $email, string $name, int $amountCents, string $paymentIntent, string $chargeIds, string $groupsJson, string $notes): bool
{
    $row = [
        $sessionId,
        date('Y-m-d H:i:s'),
        $state,
        merch_csv_safe_cell($email),
        merch_csv_safe_cell($name),
        (string) $amountCents,
        $paymentIntent,
        $chargeIds,
        $groupsJson,
        merch_csv_safe_cell($notes),
    ];
    return merch_charges_append(merch_stripe_sessions_file(), merch_stripe_sessions_header(), $row);
}

function merch_stripe_log_event(string $eventId, string $type, string $sessionId, string $result): void
{
    merch_charges_append(
        merch_stripe_events_file(),
        merch_stripe_events_header(),
        [$eventId, date('Y-m-d H:i:s'), $type, $sessionId, merch_csv_safe_cell($result)]
    );
}

/** True when this exact Stripe event id was already handled. */
function merch_stripe_event_seen(string $eventId): bool
{
    $h = @fopen(merch_stripe_events_file(), 'rb');
    if (!$h) {
        return false;
    }
    $seen = false;
    $first = true;
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        if ($first) {
            $first = false;
            continue;
        }
        if (($r[0] ?? '') === $eventId && ($r[4] ?? '') !== 'error') {
            $seen = true;
            break;
        }
    }
    fclose($h);
    return $seen;
}

// ------------------------------------------------------------
// Building the checkout
// ------------------------------------------------------------

/** "Item - Color, Size, Sleeve" - the same wording the invoice email uses. */
function merch_stripe_line_label(array $line, array $detail): string
{
    $bits = [];
    foreach (['color', 'size', 'sleeve'] as $k) {
        $v = trim((string) ($detail[$k] ?? ''));
        if ($v !== '' && stripos($v, 'Not applicable') !== 0) {
            $bits[] = $v;
        }
    }
    return (string) $line['item'] . ($bits ? ' - ' . implode(', ', $bits) : '');
}

/**
 * Turns the submitted lines into priced invoice groups (3D-printed items,
 * and shirts/hats - the same two groups merch_list_price.php shows and
 * merch_invoice.php invoices). Returns null if the order can't be paid at
 * checkout: any group that can't be priced, or whose shipping needs a
 * manual quote.
 *
 * @param array $lines each: item, quantity, color, size, sleeve, orderId
 * @return array|null list of groups: key, orderIds, items, pricing
 */
function merch_stripe_build_groups(array $lines): ?array
{
    $defs = ['printed' => [], 'apparel' => []];
    foreach ($lines as $l) {
        $defs[merch_is_printed_item((string) $l['item']) ? 'printed' : 'apparel'][] = $l;
    }
    $groups = [];
    foreach ($defs as $key => $groupLines) {
        if (!$groupLines) {
            continue;
        }
        $items = [];
        foreach ($groupLines as $l) {
            $items[] = [
                'item' => (string) $l['item'],
                'quantity' => (int) $l['quantity'],
                'size' => (string) ($l['size'] ?? ''),
                'sleeve' => (string) ($l['sleeve'] ?? ''),
                'color' => (string) ($l['color'] ?? ''),
            ];
        }
        $pricing = merch_group_calculate($items, true, $key === 'printed');
        if ($pricing === null || $pricing['shipping'] === null) {
            return null; // not priceable here - the normal invoice flow handles it
        }
        $groups[] = [
            'key' => $key,
            'orderIds' => array_map(fn($l) => (string) $l['orderId'], $groupLines),
            'items' => $items,
            'pricing' => [
                'subtotal' => (float) $pricing['subtotal'],
                'bundleDiscount' => (float) $pricing['bundleDiscount'],
                'fullSetDiscount' => (float) $pricing['fullSetDiscount'],
                'tax' => (float) $pricing['tax'],
                'shipping' => (float) $pricing['shipping'],
                'total' => (float) $pricing['total'],
                'lines' => array_map(fn($pl) => [
                    'item' => (string) $pl['item'],
                    'quantity' => (int) $pl['quantity'],
                    'unitPrice' => (float) $pl['unitPrice'],
                ], $pricing['lines']),
            ],
        ];
    }
    return $groups ?: null;
}

/**
 * The Stripe Checkout line items, discount cents and total cents for a set
 * of groups. If the itemised lines don't add up to the site's own total to
 * the cent (they always should - this is the safety net), falls back to one
 * line per group carrying that group's exact total.
 *
 * @return array{lineItems: array, discountCents: int, totalCents: int}
 */
function merch_stripe_checkout_lines(array $groups): array
{
    $label = ['printed' => '3D-printed items', 'apparel' => 'Shirts & hats'];
    $lineItems = [];
    $discountCents = 0;
    $taxCents = 0;
    $itemsCents = 0;
    $shipLines = [];
    $expectedCents = 0;
    foreach ($groups as $g) {
        $p = $g['pricing'];
        $expectedCents += merch_stripe_cents($p['total']);
        foreach ($p['lines'] as $i => $pl) {
            $unit = merch_stripe_cents($pl['unitPrice']);
            $itemsCents += $unit * $pl['quantity'];
            $lineItems[] = [
                'quantity' => $pl['quantity'],
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => $unit,
                    'product_data' => ['name' => merch_stripe_line_label($pl, $g['items'][$i] ?? [])],
                ],
            ];
        }
        $discountCents += merch_stripe_cents($p['bundleDiscount']) + merch_stripe_cents($p['fullSetDiscount']);
        $taxCents += merch_stripe_cents($p['tax']);
        $shipLines[] = [$label[$g['key']] ?? 'Order', merch_stripe_cents($p['shipping'])];
    }
    if ($taxCents > 0) {
        $lineItems[] = [
            'quantity' => 1,
            'price_data' => ['currency' => 'usd', 'unit_amount' => $taxCents, 'product_data' => ['name' => 'Sales tax (7%)']],
        ];
    }
    $shipTotal = 0;
    foreach ($shipLines as [$what, $cents]) {
        $shipTotal += $cents;
        if ($cents > 0) {
            $lineItems[] = [
                'quantity' => 1,
                'price_data' => ['currency' => 'usd', 'unit_amount' => $cents, 'product_data' => ['name' => 'Shipping - ' . $what]],
            ];
        }
    }
    $totalCents = $itemsCents - $discountCents + $taxCents + $shipTotal;

    if ($totalCents !== $expectedCents || $totalCents < 50) {
        // Safety net: one exact line per group, no coupon.
        $lineItems = [];
        $discountCents = 0;
        $totalCents = 0;
        foreach ($groups as $g) {
            $cents = merch_stripe_cents($g['pricing']['total']);
            $totalCents += $cents;
            $lineItems[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => $cents,
                    'product_data' => ['name' => 'Mr. Firefly order - ' . ($label[$g['key']] ?? 'items') . ' (incl. tax and shipping)'],
                ],
            ];
        }
    }
    return ['lineItems' => $lineItems, 'discountCents' => $discountCents, 'totalCents' => $totalCents];
}

/**
 * Called by merch_order.php right after the rows are safely on disk.
 * Returns the Stripe-hosted checkout URL, or null when checkout isn't
 * available for this order or anything at all goes wrong (the caller then
 * carries on with the normal request-and-invoice flow, so a Stripe problem
 * never loses or blocks an order).
 *
 * @param array $lines each: item, quantity, color, size, sleeve, orderId
 */
function merch_stripe_checkout_url(string $name, string $email, array $lines): ?string
{
    try {
        if (!merch_stripe_enabled()) {
            return null;
        }
        $groups = merch_stripe_build_groups($lines);
        if ($groups === null) {
            return null;
        }
        $built = merch_stripe_checkout_lines($groups);
        $client = merch_stripe_client();

        $params = [
            'mode' => 'payment',
            'line_items' => $built['lineItems'],
            'customer_email' => $email,
            'success_url' => merch_stripe_site_url() . '/stripe_success.php?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => merch_stripe_site_url() . '/stripe_cancel.php',
            'expires_at' => time() + 23 * 3600, // Stripe allows 30 min to 24 h
            'payment_intent_data' => [
                'description' => 'Mr. Firefly merch order',
                'metadata' => ['sfr_orders' => substr(implode(';', array_map(fn($g) => $g['key'] . ':' . implode(',', $g['orderIds']), $groups)), 0, 480)],
            ],
            'metadata' => ['sfr_orders' => substr(implode(';', array_map(fn($g) => $g['key'] . ':' . implode(',', $g['orderIds']), $groups)), 0, 480)],
        ];
        if ($built['discountCents'] > 0) {
            $coupon = $client->coupons->create([
                'amount_off' => $built['discountCents'],
                'currency' => 'usd',
                'duration' => 'once',
                'max_redemptions' => 1,
                'name' => 'Bundle / set discount',
            ]);
            $params['discounts'] = [['coupon' => $coupon->id]];
        }

        $session = $client->checkout->sessions->create($params);
        if (empty($session->id) || empty($session->url)) {
            error_log('Southern Fireflies Stripe: session created without id/url');
            return null;
        }
        if ((int) $session->amount_total !== $built['totalCents']) {
            // Stripe's total isn't ours - never send a customer to a page
            // that would charge a different amount than the invoice would.
            error_log("Southern Fireflies Stripe: total mismatch ours {$built['totalCents']} vs Stripe {$session->amount_total} - not offering checkout");
            try {
                $client->checkout->sessions->expire($session->id);
            } catch (\Throwable $e) {
                // best effort
            }
            return null;
        }

        merch_stripe_append_session_row(
            $session->id,
            'created',
            $email,
            $name,
            $built['totalCents'],
            '',
            '',
            json_encode($groups, JSON_UNESCAPED_SLASHES),
            ''
        );
        return (string) $session->url;
    } catch (\Throwable $e) {
        error_log('Southern Fireflies Stripe: checkout not started: ' . $e->getMessage());
        return null;
    }
}

/** Send the browser to Stripe. 303 so a refresh never re-POSTs the order. */
function merch_stripe_redirect(string $url): void
{
    if (!headers_sent()) {
        header('Location: ' . $url, true, 303);
    } else {
        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta http-equiv="refresh" content="0;url=' . $safe . '"></head><body><p><a href="' . $safe . '">Continue to payment</a></p></body></html>';
    }
}

// ------------------------------------------------------------
// After payment (called from stripe_webhook.php)
// ------------------------------------------------------------

/**
 * A session in any of these states has been dealt with - a repeat webhook
 * for it must do nothing. ('email-failed' is written AFTER 'paid', and
 * 'review' means Steve was alerted and will sort it out by hand.)
 */
function merch_stripe_state_is_final(string $state): bool
{
    return in_array($state, ['paid', 'email-failed', 'review'], true);
}

/** Best-effort internal alert to Steve; never throws. */
function merch_stripe_alert_admin(string $subject, string $body): void
{
    try {
        require_once __DIR__ . '/merch_notify.php';
        if (!defined('NOTIFY_ADMIN_EMAIL') || NOTIFY_ADMIN_EMAIL === '') {
            return;
        }
        $mail = merch_mailer();
        $mail->addAddress(NOTIFY_ADMIN_EMAIL);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->Priority = 1;
        $mail->send();
    } catch (\Throwable $e) {
        error_log('Southern Fireflies Stripe: admin alert failed: ' . $e->getMessage());
    }
}

/**
 * Checks the rows for these OrderIDs are still in the state they were in
 * when the customer went to pay (present, not cancelled, not invoiced, not
 * paid) and, only if so, stamps Invoice Date and Pymt Date on all of them in
 * one locked, backed-up write - same discipline as merch_invoice.php's
 * stamp. Returns ['ok' => true] or ['ok' => false, 'problem' => '...'] with
 * NOTHING written in the second case.
 */
function merch_stripe_stamp_paid_rows(string $csvFile, array $orderIds, string $date): array
{
    require_once __DIR__ . '/merch_backup.php';
    require_once __DIR__ . '/merch_status_log.php';

    $handle = fopen($csvFile, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) {
            fclose($handle);
        }
        return ['ok' => false, 'problem' => 'Could not open or lock merchandise.csv.'];
    }
    $rows = [];
    while (($r = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $rows[] = $r;
    }
    $header = $rows[0] ?? [];
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    }
    $idx = [];
    foreach (['OrderID', 'Invoice Date', 'Pymt Date', 'Cancelled'] as $c) {
        $idx[$c] = array_search($c, $header, true);
    }
    if ($idx['OrderID'] === false || $idx['Invoice Date'] === false || $idx['Pymt Date'] === false) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return ['ok' => false, 'problem' => 'merchandise.csv is missing an expected column (OrderID / Invoice Date / Pymt Date).'];
    }

    $want = array_map('strval', $orderIds);
    $found = [];
    foreach ($rows as $i => $r) {
        if ($i === 0) {
            continue;
        }
        $id = (string) ($r[$idx['OrderID']] ?? '');
        if (!in_array($id, $want, true)) {
            continue;
        }
        $found[$id] = $i;
    }
    foreach ($want as $id) {
        if (!isset($found[$id])) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return ['ok' => false, 'problem' => "OrderID {$id} is no longer in merchandise.csv."];
        }
        $r = $rows[$found[$id]];
        if ($idx['Cancelled'] !== false && trim((string) ($r[$idx['Cancelled']] ?? '')) !== '') {
            flock($handle, LOCK_UN);
            fclose($handle);
            return ['ok' => false, 'problem' => "OrderID {$id} was cancelled after the customer went to pay."];
        }
        if (trim((string) ($r[$idx['Invoice Date']] ?? '')) !== '' || trim((string) ($r[$idx['Pymt Date']] ?? '')) !== '') {
            flock($handle, LOCK_UN);
            fclose($handle);
            return ['ok' => false, 'problem' => "OrderID {$id} was already invoiced or marked paid by hand."];
        }
    }

    $statusBefore = merch_status_snapshot($header, $rows);
    foreach ($found as $id => $i) {
        $rows[$i][$idx['Invoice Date']] = $date;
        $rows[$i][$idx['Pymt Date']] = $date;
    }
    merch_backup_csv($csvFile, __DIR__ . '/backups');
    rewind($handle);
    ftruncate($handle, 0);
    foreach ($rows as $r) {
        fputcsv($handle, $r, ',', '"', '\\');
    }
    fflush($handle);
    merch_status_log_changes($statusBefore, $header, $rows, 'Stripe');
    flock($handle, LOCK_UN);
    fclose($handle);
    return ['ok' => true];
}

/**
 * Why the last merch_stripe_fee_cents() call came back empty (2026-10-31): the
 * reason goes into the payments.csv note, so a failed fee lookup explains
 * itself without anyone digging through server logs. Pass a string to set it.
 */
function merch_stripe_fee_failure_reason(?string $set = null): string
{
    static $why = '';
    if ($set !== null) {
        $why = trim(preg_replace('/\s+/', ' ', $set));
        if (strlen($why) > 200) {
            $why = substr($why, 0, 200) . '...';
        }
    }
    return $why;
}

/** Actual Stripe fee in whole cents for a PaymentIntent, or null if unavailable. */
function merch_stripe_fee_cents(\Stripe\StripeClient $client, string $paymentIntentId): ?int
{
    merch_stripe_fee_failure_reason('');
    try {
        $pi = $client->paymentIntents->retrieve($paymentIntentId, ['expand' => ['latest_charge.balance_transaction']]);
        $charge = $pi->latest_charge ?? null;
        $bt = is_object($charge) ? ($charge->balance_transaction ?? null) : null;
        if (is_object($bt) && isset($bt->fee) && is_numeric($bt->fee)) {
            return (int) $bt->fee;
        }
        if ($charge === null) {
            merch_stripe_fee_failure_reason('the payment has no charge yet');
        } elseif (!is_object($charge)) {
            merch_stripe_fee_failure_reason('the charge came back as an id only, not expanded');
        } elseif ($bt === null) {
            merch_stripe_fee_failure_reason('the charge has no balance transaction yet (status ' . (string) ($charge->status ?? '?') . ')');
        } else {
            merch_stripe_fee_failure_reason('the balance transaction had no usable fee');
        }
    } catch (\Throwable $e) {
        merch_stripe_fee_failure_reason(get_class($e) . ': ' . $e->getMessage());
        error_log('Southern Fireflies Stripe: fee lookup failed: ' . $e->getMessage());
    }
    return null;
}

/** Splits $totalCents across weights (whole cents, last share takes the remainder). */
function merch_stripe_split_cents(int $totalCents, array $weights): array
{
    $sum = array_sum($weights);
    $out = [];
    $given = 0;
    $n = count($weights);
    $i = 0;
    foreach ($weights as $k => $w) {
        $i++;
        if ($i === $n) {
            $out[$k] = $totalCents - $given;
        } else {
            $share = $sum > 0 ? (int) round($totalCents * $w / $sum) : 0;
            $out[$k] = $share;
            $given += $share;
        }
    }
    return $out;
}

/**
 * Handles a paid checkout.session.completed. Safe to call twice for the same
 * session (the second call finds the 'paid' row and does nothing).
 *
 * @param object|array $session the Stripe session (id, amount_total, currency, payment_status, payment_intent)
 * @return string result word for stripe_events.csv: paid | duplicate | unknown | review | unpaid
 */
function merch_stripe_handle_paid(array $session, ?\Stripe\StripeClient $client = null): string
{
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '') {
        return 'unknown';
    }
    if (($session['payment_status'] ?? '') !== 'paid') {
        return 'unpaid';
    }

    $pre = merch_stripe_session_state($sessionId);
    if ($pre === null || $pre['CreatedRow'] === null) {
        merch_stripe_alert_admin(
            'Stripe payment for an unknown checkout',
            "Stripe reports a paid checkout ({$sessionId}) that this site has no record of creating. Look at it in the Stripe dashboard.\n"
        );
        return 'unknown';
    }
    if (merch_stripe_state_is_final($pre['State'])) {
        return 'duplicate';
    }

    $created = $pre['CreatedRow'];
    $groups = json_decode($created['Groups'], true);
    $paymentIntent = (string) ($session['payment_intent'] ?? '');
    $amountPaid = (int) ($session['amount_total'] ?? 0);
    $email = $created['Email'];
    $name = $created['Name'];

    // Fee first, before taking any lock (it's a network call).
    $feeCents = null;
    $feeWhy = '';
    if ($client === null) {
        $feeWhy = 'no Stripe client';
    } elseif ($paymentIntent === '') {
        $feeWhy = 'the session had no payment_intent';
    } else {
        $feeCents = merch_stripe_fee_cents($client, $paymentIntent);
        $feeWhy = merch_stripe_fee_failure_reason();
    }

    // One webhook at a time per site: a separate lock file, because the
    // helpers below append to stripe_sessions.csv themselves (and take their
    // own short lock on it).
    $lockHandle = fopen(merch_stripe_lock_file(), 'c+');
    if (!$lockHandle || !flock($lockHandle, LOCK_EX)) {
        throw new \RuntimeException('could not take the Stripe webhook lock'); // webhook returns 500, Stripe retries
    }
    try {
        $again = merch_stripe_session_state($sessionId);
        if ($again !== null && merch_stripe_state_is_final($again['State'])) {
            return 'duplicate';
        }
        if (!is_array($groups) || !$groups || $amountPaid !== (int) $created['AmountCents'] || strtolower((string) ($session['currency'] ?? 'usd')) !== 'usd') {
            $why = "Stripe collected {$amountPaid} cents; the checkout was created for {$created['AmountCents']} cents.";
            merch_stripe_append_session_row($sessionId, 'review', $email, $name, $amountPaid, $paymentIntent, '', '', $why);
            merch_stripe_alert_admin('Stripe payment needs review: amount mismatch', "Checkout {$sessionId} ({$email})\n{$why}\nNothing was recorded in merchandise.csv. Check it in the Stripe dashboard.\n");
            return 'review';
        }

        $today = date('Y-m-d');
        $allOrderIds = [];
        foreach ($groups as $g) {
            foreach ($g['orderIds'] as $id) {
                $allOrderIds[] = (string) $id;
            }
        }
        $stamp = merch_stripe_stamp_paid_rows(__DIR__ . '/merchandise.csv', $allOrderIds, $today);
        if (!$stamp['ok']) {
            merch_stripe_append_session_row($sessionId, 'review', $email, $name, $amountPaid, $paymentIntent, '', '', $stamp['problem']);
            merch_stripe_alert_admin(
                'Stripe payment needs review: ' . $email,
                "The customer PAID ($" . number_format($amountPaid / 100, 2) . ") but the order rows could not be marked paid.\n"
                . "Reason: {$stamp['problem']}\nOrderIDs: " . implode(', ', $allOrderIds) . "\nStripe payment: {$paymentIntent}\n"
                . "Nothing was changed in merchandise.csv. Mark the orders paid by hand, or refund in the Stripe dashboard.\n"
            );
            return 'review';
        }

        // Rows are marked. From here on, failures are logged but never undo the payment.
        $weights = [];
        foreach ($groups as $k => $g) {
            $weights[$k] = merch_stripe_cents((float) $g['pricing']['total']);
        }
        $feeNote = '';
        if ($feeCents === null) {
            $feeCents = (int) round($amountPaid * 0.029) + 30;
            $feeNote = 'Stripe fee ESTIMATED 2.9% + 30c (real fee lookup failed' . ($feeWhy !== '' ? ': ' . $feeWhy : '') . ')';
        }
        $feeShares = merch_stripe_split_cents($feeCents, $weights);

        $chargeIds = [];
        foreach ($groups as $k => $g) {
            $orderIds = array_map('strval', $g['orderIds']);
            $pricing = $g['pricing'];
            $chargeId = merch_charges_record($pricing, $email, $orderIds, $g['items'], true, false, $today);
            $chargeIds[] = $chargeId;
            merch_payments_record(
                $orderIds,
                'Stripe',
                round($weights[$k] / 100, 2),
                $today,
                round($feeShares[$k] / 100, 2),
                'stripe',
                trim("stripe {$paymentIntent} {$feeNote}")
            );
        }
        merch_stripe_append_session_row($sessionId, 'paid', $email, $name, $amountPaid, $paymentIntent, implode(';', $chargeIds), '', $feeNote);
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    // The usual "payment received" email - after the lock is released (SMTP can be slow).
    try {
        require_once __DIR__ . '/merch_notify.php';
        $emailItems = [];
        foreach ($groups as $g) {
            foreach ($g['items'] as $it) {
                $emailItems[] = ['item' => $it['item'], 'quantity' => $it['quantity'], 'color' => $it['color']];
            }
        }
        $sent = merch_send_payment_received($emailItems, $name !== '' ? $name : 'there', $email);
        if (!$sent['sent']) {
            merch_stripe_append_session_row($sessionId, 'email-failed', $email, $name, $amountPaid, $paymentIntent, '', '', (string) $sent['error']);
            merch_stripe_alert_admin('Stripe order paid, receipt email failed: ' . $email, "The order was recorded as paid, but the payment-received email did not send: {$sent['error']}\n");
        }
    } catch (\Throwable $e) {
        error_log('Southern Fireflies Stripe: receipt email failed: ' . $e->getMessage());
    }
    return 'paid';
}
