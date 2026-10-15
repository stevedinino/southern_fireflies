<?php
// Build: 2026-10-15-A
// Admin-triggered: given a comma-separated list of customer email
// addresses (the ones Steve left checked on merch_patience.php), sends
// the "please be patient" email to each and reports back which sent,
// were skipped, or failed. Same admin + CSRF gate as
// merch_send_reminders.php - not a public endpoint.
//
// Differences from the payment reminders, all on purpose:
//   - NO CC and NO BCC to Steve (Steve, 2026-10-15). The send log
//     (merch_email_log.php) is his record of what went out.
//   - UTF-8 is set explicitly. PHPMailer defaults to ISO-8859-1, which
//     mangles smart quotes and emoji in the template wording.
//   - Every attempt is logged immediately, success or failure, so a
//     crash or timeout mid-run can never lead to a silent double send.
//   - A repeat guard: anyone with a successful send logged within
//     merch_patience_resend_guard_days() is skipped unless the request
//     carries allowRepeat=1 (the preview page's second confirm).
//   - The browser sends small batches (about 5 addresses per request)
//     and this file paces the sends, so a long list never runs into the
//     web host's request time limit and Brevo's daily cap is respected.
//
// Read-only with respect to merchandise.csv. Each recipient is
// re-derived from a fresh CSV read; only WHICH addresses were checked is
// trusted from the browser, so a stale preview self-corrects (someone
// fulfilled or cancelled since the list loaded is skipped, not emailed).

require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session
header('Content-Type: application/json');
require __DIR__ . '/pricing.php';
require __DIR__ . '/merch_notify.php';
require __DIR__ . '/merch_shipments.php';
require __DIR__ . '/merch_patience_email.php';
require_once __DIR__ . '/strings.php';

merch_require_admin_json();
merch_require_csrf_json();

// A batch must finish even if the browser tab closes mid-run, otherwise
// half the list could be sent with no record in the browser.
ignore_user_abort(true);
@set_time_limit(120);

const MERCH_PATIENCE_MAX_PER_REQUEST = 10;
const MERCH_PATIENCE_PAUSE_MICROSECONDS = 400000; // 0.4s between sends

$emailsRaw = isset($_POST['emails']) ? trim($_POST['emails']) : '';
$allowRepeat = isset($_POST['allowRepeat']) && $_POST['allowRepeat'] === '1';

$emails = array_values(array_unique(array_filter(
    array_map(fn($e) => strtolower(trim($e)), explode(',', $emailsRaw)),
    fn($e) => $e !== ''
)));
if (empty($emails)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No recipients selected.']);
    exit;
}
if (count($emails) > MERCH_PATIENCE_MAX_PER_REQUEST) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Too many recipients in one request.']);
    exit;
}
foreach ($emails as $e) {
    if (filter_var($e, FILTER_VALIDATE_EMAIL) === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
        exit;
    }
}

$loaded = merch_load_csv(__DIR__ . '/merchandise.csv', 'merchandise.csv');
$rows = $loaded['rows'];
$col = merch_csv_column_map(
    $loaded['header'],
    merch_patience_required_columns(),
    merch_patience_hard_required_columns(),
    'merchandise.csv'
);

$kind = merch_patience_kind();
$lastSent = merch_email_log_last_sent($kind);

$results = [];
$sentCount = 0;
$first = true;
foreach ($emails as $email) {
    $recipient = merch_patience_recipient_for_email($rows, $col, $email);
    if ($recipient === null) {
        $results[] = ['email' => $email, 'ok' => false, 'skipped' => true, 'name' => null,
            'error' => 'No longer eligible - it may have been fulfilled, cancelled, or changed since this list loaded.'];
        continue;
    }

    $recent = merch_patience_recently_sent($lastSent, $email);
    if ($recent !== null && !$allowRepeat) {
        $results[] = ['email' => $email, 'ok' => false, 'skipped' => true, 'name' => $recipient['name'],
            'error' => 'Already sent ' . $recent . ' - not repeated.'];
        continue;
    }

    if (!$first) {
        usleep(MERCH_PATIENCE_PAUSE_MICROSECONDS);
    }
    $first = false;

    $content = merch_patience_content($recipient);
    $sent = false;
    $error = '';
    try {
        $mail = merch_mailer();
        // Deliberately NO addCC / addBCC - see header comment.
        $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $mail->Encoding = \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;
        $mail->addAddress($recipient['email'], $recipient['name']);
        $mail->isHTML(true);
        $mail->Subject = $content['subject'];
        $mail->Body = $content['html'];
        $mail->AltBody = $content['text'];
        $mail->send();
        $sent = true;
    } catch (\Throwable $e) {
        $error = isset($mail) && $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
    }

    merch_email_log_append($kind, $recipient['email'], $recipient['name'], $sent, $sent ? '' : $error);
    if ($sent) {
        $sentCount++;
        $lastSent[$email] = date('Y-m-d H:i:s');
    }
    $results[] = ['email' => $email, 'ok' => $sent, 'skipped' => false, 'name' => $recipient['name'],
        'error' => $sent ? '' : $error];
}

echo json_encode(['ok' => true, 'sentCount' => $sentCount, 'results' => $results]);
