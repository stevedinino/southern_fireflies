<?php
// Build: 2026-08-01-A
// 2026-08-01: customer-facing copy (the confirmation email body/subject
// and the thank-you page text below) now loads from /strings/ via
// merch_load_string() - see strings.php for how the loader works.
require __DIR__ . '/config.php';
require __DIR__ . '/strings.php';
require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$csvFile = 'registrations.csv';

// Collect form data
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$address = isset($_POST['address']) ? trim($_POST['address']) : '';
$phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$message = isset($_POST['message']) ? trim($_POST['message']) : '';
$event = isset($_POST['event']) ? trim($_POST['event']) : '';
$fourDay = (isset($_POST['four_day']) && $_POST['four_day'] === '4')
    ? '4 days (Thu-Sun)'
    : '3 days (Fri-Sun)';
$timestamp = date('Y-m-d H:i:s');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Validate required fields (event is required so every registration is unambiguous)
if ($name && $address && $phone && $email && $event) {

    $row = [$name, $address, $phone, $email, $event, $fourDay, $message, $timestamp, $ip];
    $file = fopen($csvFile, 'a');
    if ($file) {
        fputcsv($file, $row, ",", '"', "\\");
        fclose($file);

        // ---- Send confirmation email (best-effort; never blocks the thank-you page) ----
        $mailSent = false;
        $mailError = '';
        try {
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
            $mail->addAddress($email, $name);
            if (defined('NOTIFY_ADMIN_EMAIL') && NOTIFY_ADMIN_EMAIL) {
                $mail->addBCC(NOTIFY_ADMIN_EMAIL);
            }

            $mail->isHTML(true);
            $mail->Subject = merch_load_string('emails/registration-confirmation.subject');

            $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            $safeMessage = $message !== '' ? nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) : '';
            $safeEvent = $event !== '' ? htmlspecialchars($event, ENT_QUOTES, 'UTF-8') : '';
            $safeFourDay = htmlspecialchars($fourDay, ENT_QUOTES, 'UTF-8');

            // The event line and the notes row are each conditional on
            // whether that field was filled in - built here as HTML/text
            // fragments (data formatting, not fixed prose) and passed
            // into the string templates as tokens, same pattern as the
            // merch invoice email in merch_notify.php.
            $eventLineHtml = $safeEvent !== '' ? "<p style='font-size:1.05em;'>You're registered for: <strong>{$safeEvent}</strong></p>" : '';
            $eventLineText = $event !== '' ? "You're registered for: {$event}\n\n" : '';
            $notesRowHtml = $safeMessage !== '' ? "<tr><td style='padding:6px 0; font-weight:bold; vertical-align:top;'>Notes:</td><td style='padding:6px 0;'>{$safeMessage}</td></tr>" : '';
            $notesLineText = $message !== '' ? "Notes: {$message}\n" : '';

            $mail->Body = merch_load_string('emails/registration-confirmation.html', [
                'name' => $safeName,
                'eventLineHtml' => $eventLineHtml,
                'phone' => htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'),
                'email' => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
                'fourDay' => $safeFourDay,
                'notesRowHtml' => $notesRowHtml,
                'venmoHandle' => htmlspecialchars(VENMO_HANDLE_MERCH, ENT_QUOTES, 'UTF-8'),
                'paypalEmail' => htmlspecialchars(PAYPAL_EMAIL_MERCH, ENT_QUOTES, 'UTF-8'),
            ]);
            $mail->AltBody = merch_load_string('emails/registration-confirmation.text', [
                'name' => $name,
                'eventLineText' => $eventLineText,
                'phone' => $phone,
                'email' => $email,
                'fourDay' => $fourDay,
                'notesLineText' => $notesLineText,
                'venmoHandle' => VENMO_HANDLE_MERCH,
                'paypalEmail' => PAYPAL_EMAIL_MERCH,
            ]);

            $mail->send();
            $mailSent = true;
        } catch (Exception $e) {
            // Registration still succeeds even if email fails - just log it.
            $mailError = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
            error_log('Southern Fireflies registration email failed: ' . $mailError);
        }

        echo '<!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="UTF-8">
          <meta http-equiv="refresh" content="3;url=index.php">
          <title>Registration Successful</title>
          <link rel="stylesheet" href="styles/layout.css" />
        </head>
        <body>
          <div class="content-wrapper">
            <div class="page-container" style="text-align:center;">
              ' . merch_load_string('pages/registration-thankyou-heading') . '' .
              ($mailSent
                ? merch_load_string('pages/registration-thankyou-page-sent', ['email' => htmlspecialchars($email, ENT_QUOTES, 'UTF-8')])
                : merch_load_string('pages/registration-thankyou-page-mailfailed')
              ) . '
              ' . merch_load_string('pages/registration-thankyou-redirect') . '
            </div>
          </div>
        </body>
        </html>';
    } else {
        echo merch_load_string('errors/registration-write-failed');
    }
} else {
    echo merch_load_string('errors/registration-missing-fields');
}
