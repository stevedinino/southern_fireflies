<?php
// Build: 2026-09-16-A
// ============================================================
// Handles gift-certificate.php's submission: validates, mints a code
// (giftcert.php), appends one row to gift_certificates.csv, and
// answers the browser with the printable certificate itself - that
// page IS the primary deliverable (see the 2026-09-16 discussion:
// "what do they get that they can put in a holiday card?"). The
// confirmation email sent afterward is a plain-text-safe BACKUP copy
// of the same details, not a second attempt at the fancy design - the
// script font/decorative SVGs on the printed page aren't something
// email clients can be trusted to render, so the email stays in the
// same plain inline-styled-table style every other email on this site
// already uses.
//
// Same "respond first, email after" ordering as merch_order.php
// (Finding 15, 2026-08-19 code review) - the certificate is already
// safely on disk and shown to the customer before the SMTP call runs,
// so a slow/stalled mail host can't stall the page they actually need.
//
// Redemption is NOT handled here or anywhere yet - Redeemed/
// RedeemedDate are written blank and are meant to be filled in by
// hand (Janet or Steve, checking a presented code against this CSV),
// same manual-ledger pattern as everything else on this site.
//
// 2026-09-16: images/logo_svg/element_*.svg (fireflies, flowers, jar,
// etc.) are NOT standalone icons - each shares one large coordinate
// canvas meant for compositing into the full logo scene, so dropping
// one alone into an <img> at icon size renders as a near-blank box (a
// tiny fragment of the artwork, confirmed via a local screenshot
// before shipping this). The certificate uses a plain CSS rule for
// its divider for now - Steve's exporting a standalone firefly
// flourish to swap in once that's ready.
//
// 2026-09-16 (Steve, aesthetic call): wordmark image is
// home-banner.png (the wide ~2.7:1 banner from the home page), not
// the circular logo mark - fits this card's proportions better. Sized
// by width/max-width, not a fixed height, same reasoning as index.php's
// .home-logo (see that rule in layout.css).
// ============================================================
require __DIR__ . '/config.php';
require __DIR__ . '/strings.php';
require __DIR__ . '/giftcert.php';
require __DIR__ . '/merch_notify.php';

/**
 * Same shape as merch_order.php's merch_render_error_page() - a styled
 * page with a way back, instead of a bare unstyled error fragment.
 */
function giftcert_render_error_page(string $bodyHtml): void
{
    echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Certificate Not Saved</title>
  <link rel="stylesheet" href="styles/layout.css" />
</head>
<body>
  <div class="content-wrapper">
    <div class="page-container" style="text-align:center;">
      ' . $bodyHtml . '
      <p><a href="gift-certificate.php">&larr; Back to gift certificates</a></p>
    </div>
  </div>
</body>
</html>';
}

// ---- Collect + validate ----
$type = isset($_POST['type']) ? trim((string) $_POST['type']) : '';
if ($type !== 'Merch' && $type !== 'Retreat') {
    giftcert_render_error_page(merch_load_string('errors/giftcert-invalid-selection'));
    exit;
}

$amountRaw = isset($_POST['amount']) ? trim((string) $_POST['amount']) : '';
$customRaw = isset($_POST['customAmount']) ? trim((string) $_POST['customAmount']) : '';
$amount = giftcert_validate_amount($amountRaw, $customRaw, $type);
if ($amount === null) {
    giftcert_render_error_page(merch_load_string('errors/giftcert-invalid-amount', [
        'minAmount' => GIFTCERT_MIN_AMOUNT,
        'maxAmount' => GIFTCERT_MAX_AMOUNT,
    ]));
    exit;
}

$event = '';
if ($type === 'Retreat') {
    $event = isset($_POST['event']) ? trim((string) $_POST['event']) : '';
    $validEvents = giftcert_valid_retreat_events();
    if ($event === '' || !in_array($event, $validEvents, true)) {
        giftcert_render_error_page(merch_load_string('errors/giftcert-invalid-selection'));
        exit;
    }
}

$to = giftcert_clamp_name(isset($_POST['to']) ? (string) $_POST['to'] : '');
$from = giftcert_clamp_name(isset($_POST['from']) ? (string) $_POST['from'] : '');
$buyerName = giftcert_clamp_name(isset($_POST['buyerName']) ? (string) $_POST['buyerName'] : '');
$buyerEmail = isset($_POST['buyerEmail']) ? trim((string) $_POST['buyerEmail']) : '';

if ($to === '' || $from === '' || $buyerName === '' || $buyerEmail === '') {
    giftcert_render_error_page(merch_load_string('errors/giftcert-missing-fields'));
    exit;
}

$timestamp = date('Y-m-d H:i:s');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// ---- Append the row with file locking, minting the code inside the lock ----
// Same reasoning as merch_order.php/register.php: reading the current
// max and assigning the next ID have to happen inside one locked
// session, or two near-simultaneous submissions could mint the same
// code.
$csvFile = __DIR__ . '/gift_certificates.csv';
$handle = fopen($csvFile, 'c+');
if (!$handle) {
    giftcert_render_error_page(merch_load_string('errors/giftcert-write-failed'));
    exit;
}

if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    giftcert_render_error_page(merch_load_string('errors/giftcert-lock-busy'));
    exit;
}

$csvMaxForType = 0;
$header = null;
while (($existingRow = fgetcsv($handle)) !== false) {
    if ($header === null) {
        $header = $existingRow;
        // Same Excel-BOM defense as every other CSV reader on this site.
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        continue;
    }
    $codeIdx = array_search('Code', $header, true);
    $typeIdx = array_search('Type', $header, true);
    if ($codeIdx !== false && $typeIdx !== false && ($existingRow[$typeIdx] ?? '') === $type) {
        $num = giftcert_code_number((string) ($existingRow[$codeIdx] ?? ''));
        if ($num !== null) {
            $csvMaxForType = max($csvMaxForType, $num);
        }
    }
}

fseek($handle, 0, SEEK_END);

// Brand-new/empty file: no header was ever read - write the canonical
// one now rather than letting this first certificate become the
// header row (same fallback as every other CSV on this site).
if ($header === null) {
    fputcsv($handle, GIFTCERT_CSV_HEADER, ",", '"', "\\");
    $header = GIFTCERT_CSV_HEADER;
}

$code = giftcert_next_code($type, $csvMaxForType);

$values = [
    'Code' => $code,
    'Type' => $type,
    'Amount' => number_format($amount, 2, '.', ''),
    'Event' => $event,
    'To' => $to,
    'From' => $from,
    'BuyerName' => $buyerName,
    'BuyerEmail' => $buyerEmail,
    'IssuedDate' => $timestamp,
    'Redeemed' => '',      // filled in by hand at redemption time
    'RedeemedDate' => '',  // filled in by hand at redemption time
    'IP' => $ip,
];

$row = [];
$writeOk = true;
foreach ($header as $col) {
    if (!array_key_exists($col, $values)) {
        $writeOk = false;
        error_log('gift_certificate_order.php: unrecognized gift_certificates.csv column "' . $col . '" - certificate NOT written.');
        break;
    }
    $row[] = $values[$col];
}

if ($writeOk) {
    fputcsv($handle, $row, ",", '"', "\\");
    fflush($handle);
}

flock($handle, LOCK_UN);
fclose($handle);

if (!$writeOk) {
    giftcert_render_error_page(merch_load_string('errors/giftcert-write-failed'));
    exit;
}

// ---- Respond to the customer FIRST (the certificate itself), then email ----
$displayAmount = giftcert_format_amount($amount);
$safeTo = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
$safeFrom = htmlspecialchars($from, ENT_QUOTES, 'UTF-8');
$safeEvent = htmlspecialchars($event, ENT_QUOTES, 'UTF-8');
$safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

$validityLine = $type === 'Retreat'
    ? 'Valid toward: <strong>' . $safeEvent . '</strong>'
    : 'No expiration &mdash; valid anytime';

$redeemLine = $type === 'Retreat'
    ? 'Mention this code when registering for the retreat above.'
    : 'Present this code when placing a merch request, or email it to us.';

echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Your Gift Certificate – Southern Fireflies Retreats</title>
  <link rel="icon" href="images/favicon.png" type="image/png" />
  <link rel="stylesheet" href="styles/layout.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
  <style>
    /* 2026-09-16 (Steve): certificate prints at 5x7in on purpose (not
       8.5x11) - small enough to slip into a standard 5x7 greeting card
       without an awkward fold. .cert-card is sized in real inches
       (not px/max-width) for both screen AND print, so the on-screen
       preview at normal browser zoom is a true-to-size proxy for what
       comes out of the printer, not just a scaled-down mockup. */
    @page {
      size: 5in 7in;
      margin: 0.25in;
    }
    body {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 40px 16px 60px;
    }
    .cert-actions {
      max-width: 4.5in;
      width: 100%;
      text-align: center;
      margin-bottom: 20px;
    }
    .cert-actions .btn { display: inline-block; width: auto; padding: 10px 28px; }
    .cert-actions p { font-size: 14px; color: #666; }
    .cert-card {
      width: 4.5in;
      max-width: 100%;
      background: #fffdf9;
      border: 2px solid var(--accent);
      outline: 1px solid rgba(98,129,65,0.35);
      outline-offset: 6px;
      border-radius: 4px;
      padding: 0.3in 0.35in 0.25in;
      box-shadow: var(--elevated-shadow);
      text-align: center;
      box-sizing: border-box;
    }
    .cert-wordmark {
      /* home-banner.png is ~2.7:1 and has a real transparent
         background (no box behind the ribbon artwork) - same reason
         the home page .home-logo rule sizes it by width/max-width
         instead of a fixed height, and leaves box-shadow off. See that
         rule in layout.css for the full story. */
      width: 65%;
      max-width: 2.3in;
      height: auto;
      margin-bottom: 0.05in;
      box-shadow: none;
      border-radius: 0;
    }
    .cert-title {
      font-family: "Playfair Display", Georgia, serif;
      letter-spacing: 3px;
      text-transform: uppercase;
      font-size: 1.25rem;
      color: var(--accent);
      margin: 4px 0 0.12in;
    }
    .cert-amount {
      font-family: "Playfair Display", Georgia, serif;
      font-size: 2.6rem;
      color: #333;
      margin: 0 0 0.15in;
    }
    .cert-divider {
      /* Steve, 2026-09-16: this is the cleaned-up images/divider.png
         (see this file top comment for the transparency fix) used as a
         DIVIDER between the branding/amount block above and the
         personalization/redemption block below - deliberately
         mid-page, not capping the very top, so it reads as a divide in
         the page rather than a banner shouting for attention first.
         2026-09-16 follow-up (Steve): sized/centered to match
         .cert-wordmark and .cert-title instead of bleeding to the
         card edges - at the 5x7 card width the full-bleed version read
         as oversized next to everything else on the card.
         box-shadow/border-radius reset: the generic `img` rule in
         layout.css puts a drop shadow and rounded corners on EVERY
         image site-wide by default - harmless on a photo, but on this
         thin, mostly-transparent strip it rendered as a faint gray
         bar floating above the artwork (only caught by actually
         screenshotting the live page, not visible in an isolated
         image preview). Same fix .cert-wordmark already needed
         above. */
      display: block;
      width: 65%;
      max-width: 2.3in;
      margin: 0 auto 0.15in;
      box-shadow: none;
      border-radius: 0;
    }
    .cert-line {
      font-size: 1rem;
      margin: 0.07in 0;
      color: #444;
    }
    .cert-line .cert-name {
      font-family: "Dancing Script", cursive;
      font-size: 1.7rem;
      color: var(--accent);
      display: block;
      margin-top: 2px;
    }
    .cert-validity {
      margin: 0.15in 0 0.05in;
      font-size: 0.88rem;
      color: #555;
    }
    .cert-code {
      display: inline-block;
      margin: 0.12in 0 0.03in;
      padding: 8px 18px;
      border: 1px dashed var(--accent);
      border-radius: 6px;
      font-family: "Courier New", monospace;
      font-size: 1.1rem;
      letter-spacing: 2px;
      color: #333;
    }
    .cert-redeem-note {
      font-size: 0.8rem;
      color: #777;
      margin-top: 0.1in;
    }
    .cert-footer {
      margin-top: 0.15in;
      padding-top: 0.1in;
      border-top: 1px solid rgba(98,129,65,0.25);
      font-size: 0.7rem;
      color: #999;
    }
    @media print {
      .no-print { display: none !important; }
      body { padding: 0; background: #fff; }
      .cert-card { box-shadow: none; outline: none; border: 2px solid var(--accent); width: 100%; }
    }
  </style>
</head>
<body>
  <div class="cert-actions no-print">
    <button type="button" class="btn" onclick="window.print()">Print This Certificate</button>
    <p>A backup copy is also on its way to your email.<br />
       <a href="gift-certificate.php" style="color: var(--accent);">&larr; Create another certificate</a></p>
  </div>

  <div class="cert-card">
    <img src="images/home-banner.png" alt="Southern Fireflies Retreats" class="cert-wordmark" />
    <div class="cert-title">Gift Certificate</div>

    <div class="cert-amount">$' . $displayAmount . '</div>

    <img src="images/divider-clean.png" alt="" class="cert-divider" />

    <div class="cert-line">For<span class="cert-name">' . $safeTo . '</span></div>
    <div class="cert-line">From<span class="cert-name">' . $safeFrom . '</span></div>

    <div class="cert-validity">' . $validityLine . '</div>

    <div class="cert-code">' . $safeCode . '</div>
    <p class="cert-redeem-note">' . $redeemLine . '</p>

    <div class="cert-footer">Southern Fireflies Retreats &middot; southernfirefliesretreats.com</div>
  </div>
</body>
</html>';

// Best-effort flush before the email attempt - same reasoning as
// merch_order.php's identical block (Finding 15, 2026-08-19 code
// review): the certificate is already on disk and shown above, so a
// stalled SMTP connection should never hold up the customer's page.
ignore_user_abort(true);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (function_exists('ob_end_flush')) {
        @ob_end_flush();
    }
    @flush();
}

// ---- Backup-copy email (best-effort; never blocks the page above) ----
// Voice/signature/BCC match whichever human actually fulfills this
// type - Steve (Mr. Firefly) for Merch, Janet for Retreat - same as
// every other customer email already splits by who's really on the
// other end of it.
try {
    $mail = merch_mailer();
    $mail->addAddress($buyerEmail, $buyerName);

    $tokens = [
        'buyerName' => htmlspecialchars($buyerName, ENT_QUOTES, 'UTF-8'),
        'amount' => $displayAmount,
        'code' => $code,
        'to' => $safeTo,
        'from' => $safeFrom,
        'venmoHandle' => '',
        'paypalEmail' => '',
    ];

    if ($type === 'Retreat') {
        $tokens['event'] = $safeEvent;
        $tokens['venmoHandle'] = htmlspecialchars(VENMO_HANDLE_MERCH, ENT_QUOTES, 'UTF-8');
        $tokens['paypalEmail'] = htmlspecialchars(PAYPAL_EMAIL_MERCH, ENT_QUOTES, 'UTF-8');
        if (defined('NOTIFY_ADMIN_EMAIL') && NOTIFY_ADMIN_EMAIL) {
            $mail->addBCC(NOTIFY_ADMIN_EMAIL);
        }
        $subjectKey = 'emails/giftcert-retreat.subject';
        $htmlKey = 'emails/giftcert-retreat.html';
        $textKey = 'emails/giftcert-retreat.text';
    } else {
        $tokens['venmoHandle'] = htmlspecialchars(VENMO_HANDLE_PRINTED, ENT_QUOTES, 'UTF-8');
        $tokens['paypalEmail'] = htmlspecialchars(PAYPAL_EMAIL_PRINTED, ENT_QUOTES, 'UTF-8');
        if (defined('NOTIFY_MRFIREFLY_EMAIL') && NOTIFY_MRFIREFLY_EMAIL) {
            $mail->addBCC(NOTIFY_MRFIREFLY_EMAIL);
        }
        $subjectKey = 'emails/giftcert-merch.subject';
        $htmlKey = 'emails/giftcert-merch.html';
        $textKey = 'emails/giftcert-merch.text';
    }

    $mail->isHTML(true);
    $mail->Subject = merch_load_string($subjectKey);
    $mail->Body = merch_load_string($htmlKey, $tokens);
    $mail->AltBody = merch_load_string($textKey, $tokens);

    $mail->send();
} catch (\Throwable $e) {
    // The certificate itself is already safely delivered above - a
    // failed backup email is logged, not fatal to the request.
    error_log('gift_certificate_order.php: backup email failed for ' . $code . ' - ' . $e->getMessage());
}
