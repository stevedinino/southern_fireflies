<?php
// Build: 2026-10-30-A
// ============================================================
// Stripe calls this address after a customer pays (or lets a checkout
// expire). It is public on purpose - Stripe's servers have to reach it -
// so the ONLY thing that makes a request believable is the signature check
// below. No valid signature, nothing happens.
//
// Register in the Stripe dashboard (Developers > Webhooks) as:
//   https://southernfirefliesretreats.com/stripe_webhook.php
// listening for exactly two events:
//   checkout.session.completed
//   checkout.session.expired
// and copy the endpoint's signing secret (whsec_...) into config.php as
// STRIPE_WEBHOOK_SECRET. Test mode and live mode each have their OWN
// endpoint and secret.
//
// Answers 200 once an event is dealt with (including "nothing to do"),
// 400 for a bad signature, 500 if something failed in a way worth retrying
// (Stripe then retries for days). Safe to receive the same event twice.
// ============================================================

require __DIR__ . '/config.php';
require __DIR__ . '/stripe_lib.php';

header('Content-Type: text/plain');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo 'POST only';
    exit;
}
if (!merch_stripe_enabled() || !defined('STRIPE_WEBHOOK_SECRET') || STRIPE_WEBHOOK_SECRET === '') {
    // Switch is off or the secret isn't set. 503 (not 200) so Stripe keeps
    // retrying and nothing paid is silently dropped while this is fixed.
    error_log('Southern Fireflies Stripe webhook: not enabled or STRIPE_WEBHOOK_SECRET missing');
    http_response_code(503);
    echo 'not enabled';
    exit;
}

require_once __DIR__ . '/stripe-php/init.php';

$payload = file_get_contents('php://input');
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig, STRIPE_WEBHOOK_SECRET);
} catch (\Throwable $e) {
    http_response_code(400);
    echo 'bad signature';
    exit;
}

$eventId = (string) $event->id;
$type = (string) $event->type;

if (merch_stripe_event_seen($eventId)) {
    http_response_code(200);
    echo 'already handled';
    exit;
}

try {
    if ($type === 'checkout.session.completed') {
        $session = $event->data->object->toArray();
        $result = merch_stripe_handle_paid($session, merch_stripe_client());
        merch_stripe_log_event($eventId, $type, (string) ($session['id'] ?? ''), $result);
    } elseif ($type === 'checkout.session.expired') {
        $session = $event->data->object->toArray();
        $sid = (string) ($session['id'] ?? '');
        $state = merch_stripe_session_state($sid);
        if ($state !== null && !merch_stripe_state_is_final($state['State'])) {
            merch_stripe_append_session_row($sid, 'expired', $state['Email'], $state['Name'], (int) $state['AmountCents'], '', '', '', '');
        }
        merch_stripe_log_event($eventId, $type, $sid, 'expired');
    } else {
        merch_stripe_log_event($eventId, $type, '', 'ignored');
    }
    http_response_code(200);
    echo 'ok';
} catch (\Throwable $e) {
    error_log('Southern Fireflies Stripe webhook failed: ' . $e->getMessage());
    merch_stripe_log_event($eventId, $type, '', 'error');
    http_response_code(500);
    echo 'error';
}
