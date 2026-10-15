<?php
// Build: 2026-07-21-A
require __DIR__ . '/config.php';
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
            $mail->Subject = "You're registered! - Southern Fireflies Retreat";

            $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            $safeMessage = $message !== '' ? nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) : '';
            $safeEvent = $event !== '' ? htmlspecialchars($event, ENT_QUOTES, 'UTF-8') : '';
            $safeFourDay = htmlspecialchars($fourDay, ENT_QUOTES, 'UTF-8');

            $mail->Body = "
                <div style='font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; color:#333; max-width:500px; margin:0 auto;'>
                  <h2 style='color:#628141;'>Thank you for registering, {$safeName}!</h2>
                  " . ($safeEvent !== '' ? "<p style='font-size:1.05em;'>You're registered for: <strong>{$safeEvent}</strong></p>" : "") . "
                  <p>We've received your pre-registration for Southern Fireflies Retreat. Here's a copy of what you submitted:</p>
                  <table style='width:100%; border-collapse: collapse;'>
                    <tr><td style='padding:6px 0; font-weight:bold;'>Name:</td><td style='padding:6px 0;'>{$safeName}</td></tr>
                    <tr><td style='padding:6px 0; font-weight:bold;'>Phone:</td><td style='padding:6px 0;'>" . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . "</td></tr>
                    <tr><td style='padding:6px 0; font-weight:bold;'>Email:</td><td style='padding:6px 0;'>" . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</td></tr>
                    <tr><td style='padding:6px 0; font-weight:bold;'>Length of stay:</td><td style='padding:6px 0;'>{$safeFourDay}</td></tr>
                    " . ($safeMessage !== '' ? "<tr><td style='padding:6px 0; font-weight:bold; vertical-align:top;'>Notes:</td><td style='padding:6px 0;'>{$safeMessage}</td></tr>" : "") . "
                  </table>
                  <p style='margin-top:20px;'>Don't forget your \$50 deposit via Venmo (<strong>@" . htmlspecialchars(VENMO_HANDLE_MERCH, ENT_QUOTES, 'UTF-8') . "</strong>) or PayPal (<strong>" . htmlspecialchars(PAYPAL_EMAIL_MERCH, ENT_QUOTES, 'UTF-8') . "</strong>) to hold your spot &mdash; see our
                  <a href='https://southernfirefliesretreats.com/cancellation.html' style='color:#628141;'>policies page</a> for deposit and payment details.</p>
                  <p>We can't wait to craft with you!</p>
                  <p style='color:#888; font-size:13px; margin-top:30px;'>Southern Fireflies Retreat</p>
                </div>
            ";
            $mail->AltBody = "Thank you for registering, {$name}!\n\n"
                . ($event !== '' ? "You're registered for: {$event}\n\n" : '')
                . "Name: {$name}\nPhone: {$phone}\nEmail: {$email}\nLength of stay: {$fourDay}\n"
                . ($message !== '' ? "Notes: {$message}\n" : '')
                . "\nDon't forget your \$50 deposit via Venmo (@" . VENMO_HANDLE_MERCH . ") or PayPal (" . PAYPAL_EMAIL_MERCH . ") to hold your spot.\n"
                . "See southernfirefliesretreats.com/cancellation.html for details.\n\nWe can't wait to craft with you!";

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
          <meta http-equiv="refresh" content="3;url=index.html">
          <title>Registration Successful</title>
          <link rel="stylesheet" href="styles/layout.css" />
        </head>
        <body>
          <div class="content-wrapper">
            <div class="page-container" style="text-align:center;">
              <h2>Thank you for registering!</h2>' .
              ($mailSent
                ? '<p>A confirmation email is on its way to ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '.</p>'
                : '<p>Your registration was saved. (We had trouble sending the confirmation email, but your spot is secured &mdash; Janet also has a record of your submission.)</p>'
              ) . '
              <p>You\'ll be redirected to the home page shortly.</p>
            </div>
          </div>
        </body>
        </html>';
    } else {
        echo "<h2>Error: Unable to write to file.</h2>";
    }
} else {
    echo "<h2>Error: Please go back and select a retreat, and fill in all fields except 'Special Request'.</h2>";
}
