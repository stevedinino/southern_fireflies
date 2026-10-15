<?php
// Build: 2026-07-26-D
// ============================================================
// Shared customer-notification logic for merch orders. Used by BOTH:
//   - merch_order.php  (automatic, one order, right at submission)
//   - merch_invoice.php (manual admin button, possibly several
//     combined orders, mainly for catching up backlog rows)
// so the email copy can never drift between the two paths - there is
// exactly one place that builds this email.
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
// ============================================================

require_once __DIR__ . '/config.php';
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
 * merch_send_manual_followup() above, unchanged).
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
        $mail->Subject = "Thanks for your {$itemLabel} request!";
        $mail->Body = "
            <div style='font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; color:#333; max-width:500px; margin:0 auto;'>
              <p>Hi {$safeName}!</p>
              <p>Thanks so much for your request - we've got it on file and will follow up separately by email with your exact total (including tax and shipping) and payment instructions.</p>
              <p>Thanks for your patience!</p>
              <p>Mr. Firefly (aka Steve)<br/>Southern Fireflies Retreats</p>
            </div>
        ";
        $mail->AltBody = "Hi {$name}!\n\n"
            . "Thanks so much for your request - we've got it on file and will follow up separately by email with your exact total (including tax and shipping) and payment instructions.\n\n"
            . "Thanks for your patience!\n\nMr. Firefly (aka Steve)\nSouthern Fireflies Retreats";
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

    $venmoHandle = $isPrinted ? VENMO_HANDLE_PRINTED : VENMO_HANDLE_MERCH;
    $paypalEmail = $isPrinted ? PAYPAL_EMAIL_PRINTED : PAYPAL_EMAIL_MERCH;

    $last4Html = '';
    $last4Text = '';
    if ($isPrinted && defined('VENMO_LAST4_PRINTED') && VENMO_LAST4_PRINTED) {
        $safeLast4 = htmlspecialchars(VENMO_LAST4_PRINTED, ENT_QUOTES, 'UTF-8');
        $last4Html = "<p>If you're prompted for the last four digits of my phone number by Venmo (first time paying someone new) it's {$safeLast4}.</p>";
        $last4Text = "If you're prompted for the last four digits of my phone number by Venmo (first time paying someone new) it's " . VENMO_LAST4_PRINTED . ".\n\n";
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

    $accountNote = $isPrinted
        ? 'these are paid separately from shirts and hats, so please use this account rather than the retreat one.'
        : '';

    try {
        $mail = merch_mailer();
        $mail->addAddress($email, $name);
        if (defined('NOTIFY_MRFIREFLY_EMAIL') && NOTIFY_MRFIREFLY_EMAIL) {
            $mail->addCC(NOTIFY_MRFIREFLY_EMAIL);
        }
        $mail->isHTML(true);
        $mail->Subject = "Your {$itemLabel} - here's your total!";

        $mail->Body = "
            <div style='font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; color:#333; max-width:500px; margin:0 auto;'>
              <p>Hi {$safeName}!</p>
              <p>The response to Mr. Firefly's merchandise has been overwhelming, and we're so grateful you're one of the folks who jumped in early!</p>
              <p>A quick update since you placed your order: we've gotten our pricing squared away, so instead of leaving you guessing, here's exactly what you owe:</p>
              <ul>
                {$lineItemsHtml}
                <li>Tax (7%): " . $money($pricing['tax']) . "</li>
                {$shippingLineHtml}
                <li><strong>Total: " . $money($pricing['total']) . "</strong></li>
              </ul>
              {$last4Html}
              <p>You can send that whenever it's convenient via Venmo (<strong>@" . htmlspecialchars($venmoHandle, ENT_QUOTES, 'UTF-8') . "</strong>) or PayPal (<strong>" . htmlspecialchars($paypalEmail, ENT_QUOTES, 'UTF-8') . "</strong>)" . ($accountNote !== '' ? " &mdash; {$accountNote}" : '') . "</p>
              <p>As I said on the website: these are handmade to order, so please allow a couple of weeks as we (ok, I) work through the queue. That said &ndash; I'll ship as soon as I match a payment to a request, so it's first-pay, first-ship. We'll follow up by email as soon as it ships. Thanks so much for your patience and your enthusiasm &mdash; rest assured, more good things are coming soon!</p>
              <p>Mr. Firefly (aka Steve)<br/>Southern Fireflies Retreats</p>
            </div>
        ";

        $mail->AltBody = "Hi {$name}!\n\n"
            . "The response to Mr. Firefly's merchandise has been overwhelming, and we're so grateful you're one of the folks who jumped in early!\n\n"
            . "A quick update since you placed your order: we've gotten our pricing squared away, so instead of leaving you guessing, here's exactly what you owe:\n\n"
            . $lineItemsText
            . '- Tax (7%): ' . $money($pricing['tax']) . "\n"
            . $shippingLineText
            . 'Total: ' . $money($pricing['total']) . "\n\n"
            . $last4Text
            . "You can send that whenever it's convenient via Venmo (@{$venmoHandle}) or PayPal ({$paypalEmail})" . ($accountNote !== '' ? " - {$accountNote}" : '') . "\n\n"
            . "As I said on the website: these are handmade to order, so please allow a couple of weeks as we (ok, I) work through the queue. That said - I'll ship as soon as I match a payment to a request, so it's first-pay, first-ship. We'll follow up by email as soon as it ships. Thanks so much for your patience and your enthusiasm - rest assured, more good things are coming soon!\n\n"
            . "Mr. Firefly (aka Steve)\nSouthern Fireflies Retreats";

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
        $mail->Subject = "Thanks for your {$itemLabel} request!";
        $mail->Body = "
            <div style='font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; color:#333; max-width:500px; margin:0 auto;'>
              <p>Hi {$safeName}!</p>
              <p>Thanks so much for your request - we've got it on file. Because of what you ordered, we need to work out an accurate shipping cost by hand rather than using our usual flat rate, so we'll follow up separately with your total before asking you to pay anything.</p>
              <p>Thanks for your patience!</p>
              <p>Mr. Firefly (aka Steve)<br/>Southern Fireflies Retreats</p>
            </div>
        ";
        $mail->AltBody = "Hi {$name}!\n\n"
            . "Thanks so much for your request - we've got it on file. Because of what you ordered, we need to work out an accurate shipping cost by hand rather than using our usual flat rate, so we'll follow up separately with your total before asking you to pay anything.\n\n"
            . "Thanks for your patience!\n\nMr. Firefly (aka Steve)\nSouthern Fireflies Retreats";
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
