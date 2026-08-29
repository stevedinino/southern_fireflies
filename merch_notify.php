<?php
// Build: 2026-08-29-A
// ============================================================
// Shared customer-notification logic for merch orders. Used by:
//   - merch_order.php  (automatic, one order, right at submission)
//   - merch_invoice.php (manual admin button, possibly several
//     combined orders, mainly for catching up backlog rows)
//   - merch_send_reminders.php (2026-08-29: bulk payment-reminder
//     nudge for already-invoiced-but-unpaid Ship orders - see
//     merch_send_payment_reminder() below and merch_reminder_groups.php)
// so the email copy can never drift between these paths - there is
// exactly one place that builds each kind of email.
//
// Two possible outcomes per notification:
//   1. Shipping resolved to a real number -> send the full itemized
//      "here's your total" invoice email to the customer (CC'd to
//      Steve), and the caller should stamp Invoiced/Invoice Date.
//   2. Shipping needs a manual quote (Steve's box-capacity tiers
//      returned null) -> send a simple "thanks, we'll follow up"
//      email to the customer (also CC'd to Steve), AND a SEPARATE
//      internal-only alert email to Steve with a flagged subject and
//      high importance, so it doesn't get lost. Invoiced is NOT
//      stamped in this case - there's no real total yet.
//
// 2026-08-01: every CUSTOMER-FACING email body/subject below now
// loads from /strings/emails/*.txt via merch_load_string() instead of
// being embedded here, per Steve's request to make customer wording
// easier to edit without touching PHP. The internal-only manual-quote
// alert (merch_send_manual_followup()'s $alertMail, below) is
// deliberately left hardcoded - Steve is the only person who ever
// sees it, so externalizing it wasn't worth the extra file. Line-item
// loops and conditional shipping/last4 lines stay as PHP-built HTML
// fragments (data formatting, not fixed prose) and get passed INTO
// the string templates as tokens - see merch_send_invoice() below.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/strings.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * The simple, always-the-same acknowledgment sent for every new
 * submission now that auto-invoicing is off (2026-07-26): "thanks, 
 * we'll follow up with your total." No pricing shown, no payment
 * info - those only ever appear later in the real invoice email sent
 * via the "Send Invoice" button (merch_invoice.php ->
 * merch_send_notification() -> merch_send_invoice()/
 * merch_send_manual_followup() below, unchanged).
 *
 * BCC'd to Steve (his own request: a quiet notification he doesn't
 * need to act on, not the visible CC used on real invoices) so he
 * knows to check ourmerch.php for new requests to process.
 *
 * Returns ['sent' => bool, 'error' => string].
 */
function merch_send_submission_ack(string $name, string $email, string $itemLabel): array
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    try {
        $mail = merch_mailer();
        $mail->addAddress($email, $name);
        if (defined('NOTIFY_MRFIREFLY_EMAIL') && NOTIFY_MRFIREFLY_EMAIL) {
            $mail->addBCC(NOTIFY_MRFIREFLY_EMAIL);
        }
        $mail->isHTML(true);
        $mail->Subject = merch_load_string('emails/submission-ack.subject', ['itemLabel' => $itemLabel]);
        $mail->Body = merch_load_string('emails/submission-ack.html', ['name' => $safeName]);
        $mail->AltBody = merch_load_string('emails/submission-ack.text', ['name' => $name]);
        $mail->send();
        return ['sent' => true, 'error' => ''];
    } catch (Exception $e) {
        $err = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
        error_log('Southern Fireflies submission-ack email failed: ' . $err);
        return ['sent' => false, 'error' => $err];
    }
}

/**
 * Entry point - decides which of the two emails above to send.
 *
 * $pricing must be the return value of merch_group_calculate()
 * (pricing.php) - works fine for a single order too, just pass a
 * one-item $items array into that function.
 *
 * Returns ['sentInvoice' => bool, 'error' => string]. sentInvoice
 * tells the caller whether to stamp Invoiced/Invoice Date - only
 * true when the real itemized invoice actually went out.
 */
function merch_send_notification(array $pricing, string $name, string $email, bool $isPrinted, bool $isShipping): array
{
    $needsManualQuote = $isShipping && $pricing['shipping'] === null;

    if ($needsManualQuote) {
        return merch_send_manual_followup($pricing, $name, $email);
    }
    return merch_send_invoice($pricing, $name, $email, $isPrinted);
}

/**
 * One PHPMailer instance pre-configured with the site's SMTP settings
 * - every send in this file starts from this so connection settings
 * only live in one place.
 */
function merch_mailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = SMTP_ENCRYPTION === 'tls'
        ? PHPMailer::ENCRYPTION_STARTTLS
        : PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = SMTP_PORT;
    // Without this, a slow/stalled SMTP host blocks whichever request is
    // sending - for merch_order.php, that meant the CUSTOMER'S OWN
    // browser sat on "Submitting..." for minutes (Finding 15, 2026-08-19
    // code review).
    //
    // $mail->Timeout alone does NOT fix this - it only bounds the
    // initial socket connect. The actual "wait for the server to
    // respond" loop inside PHPMailer's SMTP class runs on
    // stream_select() against a SEPARATE property, SMTP::$Timelimit
    // (default 300s), which PHPMailer's public API has no setter for.
    // Confirmed this the hard way: setting only ->Timeout = 10 still
    // hung for 100+ seconds against a host that accepted the TCP
    // connection but never replied. Pre-creating the SMTP instance here
    // via getSMTPInstance() and setting Timelimit directly is the only
    // way to actually bound that wait; smtpConnect() reuses whatever
    // instance already exists instead of creating a fresh one, so this
    // sticks. If you ever touch this, verify by pointing SMTP_HOST at a
    // listener that accepts but never responds and confirming the send
    // actually fails around 10s, not hangs.
    $mail->Timeout = 10;
    $smtp = $mail->getSMTPInstance();
    $smtp->Timelimit = 10;
    $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
    return $mail;
}

/**
 * The real itemized invoice, in Steve's own copy/tone. Handles both a
 * single order line and several combined lines transparently, since
 * $pricing['lines'] is always an array (of 1 or more).
 */
function merch_send_invoice(array $pricing, string $name, string $email, bool $isPrinted): array
{
    $money = fn($n) => '$' . number_format((float)$n, 2);
    $itemLabel = implode(' & ', array_unique(array_map(fn($l) => $l['item'], $pricing['lines'])));

    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    $lineItemsHtml = '';
    $lineItemsText = '';
    foreach ($pricing['lines'] as $line) {
        $qtyLabel = $line['quantity'] > 1 ? " (x{$line['quantity']})" : '';
        $lineItemsHtml .= '<li>' . htmlspecialchars($line['item'], ENT_QUOTES, 'UTF-8') . $qtyLabel . ': ' . $money($line['lineSubtotal']) . '</li>';
        $lineItemsText .= '- ' . $line['item'] . $qtyLabel . ': ' . $money($line['lineSubtotal']) . "\n";
    }

    // Bundle discount line (2026-08-21, Tape Gun Add-On launch) - shown
    // as its own line between the items and the tax, so the customer
    // can reconcile every number: item prices add up, then the discount
    // comes off, then tax is 7% of what's actually owed (see
    // merch_group_calculate()). Empty strings when no bundle applies,
    // so the templates render exactly as before.
    $discountLineHtml = '';
    $discountLineText = '';
    if (!empty($pricing['bundleDiscount'])) {
        $discountLineHtml = '<li>Bundle discount: &minus;' . $money($pricing['bundleDiscount']) . '</li>';
        $discountLineText = '- Bundle discount: -' . $money($pricing['bundleDiscount']) . "\n";
    }

    $venmoHandle = $isPrinted ? VENMO_HANDLE_PRINTED : VENMO_HANDLE_MERCH;
    $paypalEmail = $isPrinted ? PAYPAL_EMAIL_PRINTED : PAYPAL_EMAIL_MERCH;

    $last4Html = '';
    $last4Text = '';
    if ($isPrinted && defined('VENMO_LAST4_PRINTED') && VENMO_LAST4_PRINTED) {
        $safeLast4 = htmlspecialchars(VENMO_LAST4_PRINTED, ENT_QUOTES, 'UTF-8');
        $last4Html = merch_load_string('emails/invoice-last4-note.html', ['last4' => $safeLast4]);
        // merch_load_string() trims trailing whitespace off every loaded
        // file (so a stray blank line at the end of a .txt file never
        // leaks into a rendered string) - so the blank line that used to
        // separate this note from the next paragraph is added back here
        // in code instead of relying on trailing newlines surviving
        // inside the .txt file itself.
        $last4Text = merch_load_string('emails/invoice-last4-note.text', ['last4' => VENMO_LAST4_PRINTED]) . "\n\n";
    }

    // Shipping is guaranteed non-null here (merch_send_notification
    // only routes here when that's true), but still branch on
    // $isShipping-equivalent by checking if shipping key is set at
    // all vs. this being a pickup order (shipping stays null for
    // pickup on purpose - see merch_group_calculate()).
    $shippingLineHtml = '';
    $shippingLineText = '';
    if ($pricing['shipping'] !== null) {
        $shippingLineHtml = '<li>Flat-rate shipping: ' . $money($pricing['shipping']) . '</li>';
        $shippingLineText = '- Flat-rate shipping: ' . $money($pricing['shipping']) . "\n";
    }

    // The printed-items account note is itself customer-facing prose,
    // so it comes from its own string file - only the surrounding
    // punctuation (the em-dash / " - " prefix) stays here since that's
    // formatting, not wording.
    $accountNoteText = $isPrinted ? merch_load_string('emails/invoice-account-note') : '';
    $accountNoteHtml = $accountNoteText !== '' ? " &mdash; {$accountNoteText}" : '';
    $accountNoteFlat = $accountNoteText !== '' ? " - {$accountNoteText}" : '';

    try {
        $mail = merch_mailer();
        $mail->addAddress($email, $name);
        if (defined('NOTIFY_MRFIREFLY_EMAIL') && NOTIFY_MRFIREFLY_EMAIL) {
            $mail->addCC(NOTIFY_MRFIREFLY_EMAIL);
        }
        $mail->isHTML(true);
        $mail->Subject = merch_load_string('emails/invoice-body.subject', ['itemLabel' => $itemLabel]);

        $mail->Body = merch_load_string('emails/invoice-body.html', [
            'name' => $safeName,
            'lineItemsHtml' => $lineItemsHtml,
            'discountLineHtml' => $discountLineHtml,
            'tax' => $money($pricing['tax']),
            'shippingLineHtml' => $shippingLineHtml,
            'total' => $money($pricing['total']),
            'last4Html' => $last4Html,
            'venmoHandle' => htmlspecialchars($venmoHandle, ENT_QUOTES, 'UTF-8'),
            'paypalEmail' => htmlspecialchars($paypalEmail, ENT_QUOTES, 'UTF-8'),
            'accountNote' => $accountNoteHtml,
        ]);

        $mail->AltBody = merch_load_string('emails/invoice-body.text', [
            'name' => $name,
            'lineItemsText' => $lineItemsText,
            'discountLineText' => $discountLineText,
            'tax' => $money($pricing['tax']),
            'shippingLineText' => $shippingLineText,
            'total' => $money($pricing['total']),
            'last4Text' => $last4Text,
            'venmoHandle' => $venmoHandle,
            'paypalEmail' => $paypalEmail,
            'accountNote' => $accountNoteFlat,
        ]);

        $mail->send();
        return ['sentInvoice' => true, 'error' => ''];
    } catch (Exception $e) {
        $err = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
        error_log('Southern Fireflies invoice email failed: ' . $err);
        return ['sentInvoice' => false, 'error' => $err];
    }
}

/**
 * The "we'll follow up" path: a short, clean email to the customer
 * (no internal ops language), PLUS a separate flagged alert to Steve
 * only, since this order needs a manual shipping quote he has to work
 * out himself before a real total exists.
 */
function merch_send_manual_followup(array $pricing, string $name, string $email): array
{
    $itemLabel = implode(' & ', array_unique(array_map(fn($l) => $l['item'], $pricing['lines'])));
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    // ---- Customer-facing email: clean, no internal flags/language ----
    try {
        $mail = merch_mailer();
        $mail->addAddress($email, $name);
        if (defined('NOTIFY_MRFIREFLY_EMAIL') && NOTIFY_MRFIREFLY_EMAIL) {
            $mail->addCC(NOTIFY_MRFIREFLY_EMAIL);
        }
        $mail->isHTML(true);
        $mail->Subject = merch_load_string('emails/manual-followup-customer.subject', ['itemLabel' => $itemLabel]);
        $mail->Body = merch_load_string('emails/manual-followup-customer.html', ['name' => $safeName]);
        $mail->AltBody = merch_load_string('emails/manual-followup-customer.text', ['name' => $name]);
        $mail->send();
    } catch (Exception $e) {
        error_log('Southern Fireflies manual-followup customer email failed: ' . (isset($mail) ? $mail->ErrorInfo : $e->getMessage()));
        // Still try the internal alert below even if this one failed -
        // Steve needs to know about the order either way.
    }

    // ---- Internal-only flagged alert - never seen by the customer ----
    $alertError = '';
    if (defined('NOTIFY_MRFIREFLY_EMAIL') && NOTIFY_MRFIREFLY_EMAIL) {
        try {
            $alertMail = merch_mailer();
            $alertMail->addAddress(NOTIFY_MRFIREFLY_EMAIL);
            $alertMail->isHTML(false);
            $alertMail->Subject = "MANUAL FOLLOWUP: {$itemLabel} for {$name} needs a shipping quote";
            // High importance - sets X-Priority, X-MSMail-Priority, and
            // Importance headers so it stands out in most mail clients.
            $alertMail->Priority = 1;

            $lineText = '';
            foreach ($pricing['lines'] as $l) {
                $lineText .= "- {$l['item']} (x{$l['quantity']})\n";
            }

            $alertMail->Body = "This order needs a manual shipping quote before an invoice can go out:\n\n"
                . $lineText
                . "\nCustomer: {$name} <{$email}>\n\n"
                . ($pricing['shippingNote'] !== '' ? $pricing['shippingNote'] . "\n\n" : '')
                . "Once you know the real shipping cost, you'll need to email {$name} directly with the total - the system doesn't have a way to send a corrected invoice for this order yet.";
            $alertMail->send();
        } catch (Exception $e) {
            $alertError = isset($alertMail) ? $alertMail->ErrorInfo : $e->getMessage();
            error_log('Southern Fireflies manual-followup alert email failed: ' . $alertError);
        }
    }

    return ['sentInvoice' => false, 'error' => $alertError];
}

/**
 * The payment-reminder email (2026-08-29, per Steve: "a bulk reminder
 * email that gently nudges the people who placed requests but never
 * paid"). NOT a repeat of the real invoice - deliberately shows no
 * dollar amount at all. merchandise.csv never stores a combined
 * invoice's final total anywhere (merch_invoice_stamp_invoice_date()
 * only ever writes Invoice Date, never Price/Tax/Shipping), so any
 * total shown here would have to be recomputed from scratch and could
 * drift from what the original invoice actually said if pricing.php's
 * rules changed in between the two emails. Steve's own call
 * (2026-08-29, via AskUserQuestion): no total, just a friendly nudge
 * naming the items, offering to send the real total on request.
 *
 * $itemLines is a list of ready-to-display strings (e.g. "Logo Shirt
 * (x2)") - already built by the caller via
 * merch_reminder_groups.php's merch_reminder_format_item_lines(), so
 * this function has no pricing.php dependency at all and doesn't need
 * one just to send a reminder.
 *
 * Read-only with respect to merchandise.csv - unlike
 * merch_send_invoice()/merch_send_manual_followup(), nothing about
 * this email causes any column to get stamped; merch_send_reminders.php
 * calls this directly with no follow-up write of any kind.
 *
 * Returns ['sent' => bool, 'error' => string].
 */
function merch_send_payment_reminder(array $itemLines, string $name, string $email): array
{
    $itemLabel = implode(' & ', $itemLines);
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    $lineItemsHtml = '';
    $lineItemsText = '';
    foreach ($itemLines as $line) {
        $lineItemsHtml .= '<li>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
        $lineItemsText .= '- ' . $line . "\n";
    }

    try {
        $mail = merch_mailer();
        $mail->addAddress($email, $name);
        // CC'd to Steve, same as the real invoice email - so he sees
        // every reminder that actually went out.
        if (defined('NOTIFY_MRFIREFLY_EMAIL') && NOTIFY_MRFIREFLY_EMAIL) {
            $mail->addCC(NOTIFY_MRFIREFLY_EMAIL);
        }
        $mail->isHTML(true);
        $mail->Subject = merch_load_string('emails/payment-reminder.subject', ['itemLabel' => $itemLabel]);
        $mail->Body = merch_load_string('emails/payment-reminder.html', [
            'name' => $safeName,
            'lineItemsHtml' => $lineItemsHtml,
        ]);
        $mail->AltBody = merch_load_string('emails/payment-reminder.text', [
            'name' => $name,
            'lineItemsText' => $lineItemsText,
        ]);
        $mail->send();
        return ['sent' => true, 'error' => ''];
    } catch (Exception $e) {
        $err = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
        error_log('Southern Fireflies payment-reminder email failed: ' . $err);
        return ['sent' => false, 'error' => $err];
    }
}
