<?php
// Build: 2026-08-29-A
// 2026-08-29 (code review Finding 3): RegID assignment below now goes
// through id_sequence.php's persistent counter instead of a bare
// max(existing rows)+1, matching the same fix just applied to
// merch_order.php's OrderID - see that file's header comment for why a
// hand-deleted row could otherwise cause two registrations to share an
// ID.
//
// 2026-08-01: customer-facing copy (the confirmation email body/subject
// and the thank-you page text below) now loads from /strings/ via
// merch_load_string() - see strings.php for how the loader works.
//
// 2026-08-28 (Steve): laying groundwork for the eventual ourguests.php/
// registration-workflow redesign - added a RegID column (first column,
// incrementing integers) to the live registrations.csv by hand, same
// as Cancelled was hand-added to merchandise.csv earlier. Two changes
// here to support it:
//   1. RegID is now assigned under an exclusive lock instead of this
//      file's old blind, lockless append ('a' mode, no read) - fine
//      when there was no ID to compute, not once assigning one
//      requires seeing existing data first. (2026-08-29: what happens
//      inside that lock changed again - see the note at the top of this
//      file - but the lock itself, and the reason for it, are unchanged
//      from this original 2026-08-28 fix.)
//   2. The row is now built by column NAME, keyed off the file's own
//      header, instead of a hardcoded 9-field positional array. A
//      positional writer here is exactly the defect Finding 1
//      (2026-08-19 code review) already found and fixed in
//      merchandise.csv - this closes the same hole in registrations.csv
//      before it bites the same way (a hand-added column silently
//      shifting every value after it).
require __DIR__ . '/config.php';
require __DIR__ . '/strings.php';
require __DIR__ . '/id_sequence.php';
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

    // ---- Append the row with file locking, and assign RegID inside
    // the lock (2026-08-28) - same pattern as merch_order.php's
    // OrderID. 'c+' lets us read current contents (to work out the
    // next RegID) and then write, all within the same locked session.
    $writeOk = false;
    $handle = fopen($csvFile, 'c+');
    if ($handle && flock($handle, LOCK_EX)) {
        $existingRows = [];
        while (($existingRow = fgetcsv($handle)) !== false) {
            $existingRows[] = $existingRow;
        }

        $header = array_shift($existingRows);
        if ($header !== null && isset($header[0])) {
            // Same Excel-BOM defense as merchandise.csv's readers
            // (Excel sometimes glues a UTF-8 BOM onto the first cell,
            // which would otherwise break the exact-match lookup for
            // "RegID" below).
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }

        // Brand-new/empty file: no header was ever read - write the
        // canonical one now rather than letting this first registration
        // become the header row (same fallback as merch_order.php).
        // Done BEFORE the RegID lookup below so a from-scratch file
        // still gets RegID 1 for its first row, instead of $header's
        // pre-fallback null value making regIdIndex look absent.
        if ($header === null) {
            $header = ['RegID', 'Name', 'Address', 'Phone', 'Email', 'Event', 'Duration', 'Notes', 'Date', 'IP'];
            fputcsv($handle, $header, ",", '"', "\\");
        }

        $regIdIndex = array_search('RegID', $header, true);
        $nextRegId = null;
        if ($regIdIndex !== false) {
            $csvMaxRegId = 0;
            foreach ($existingRows as $existingRow) {
                if (isset($existingRow[$regIdIndex]) && is_numeric($existingRow[$regIdIndex])) {
                    $csvMaxRegId = max($csvMaxRegId, (int)$existingRow[$regIdIndex]);
                }
            }
            // 2026-08-29 (Finding 3, same fix as merch_order.php's
            // OrderID): a persistent counter, not the CSV's own current
            // max, is the real source of truth - see id_sequence.php.
            $nextRegId = merch_next_persistent_id(__DIR__ . '/registrations_regid_counter.txt', $csvMaxRegId);
        }

        fseek($handle, 0, SEEK_END);

        $values = [
            'RegID' => $nextRegId,
            'Name' => $name,
            'Address' => $address,
            'Phone' => $phone,
            'Email' => $email,
            'Event' => $event,
            'Duration' => $fourDay,
            'Notes' => $message,
            'Date' => $timestamp,
            'IP' => $ip,
        ];

        // Build the row keyed by column NAME, driven by the file's own
        // header, instead of a hardcoded position list - see the header
        // comment above (same Finding 1 fix already applied to
        // merchandise.csv). A column in the live file that isn't in
        // $values fails loudly here rather than silently misaligning
        // every value after it.
        $row = [];
        $writeOk = true;
        foreach ($header as $col) {
            if (!array_key_exists($col, $values)) {
                $writeOk = false;
                error_log('register.php: unrecognized registrations.csv column "' . $col . '" - registration NOT written. Add it to $values in register.php.');
                break;
            }
            $row[] = $values[$col];
        }

        if ($writeOk) {
            fputcsv($handle, $row, ",", '"', "\\");
            fflush($handle);
        }

        flock($handle, LOCK_UN);
    }
    if ($handle) {
        fclose($handle);
    }

    if ($writeOk) {

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
