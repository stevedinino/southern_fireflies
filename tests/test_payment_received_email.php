<?php
// Content tests for merch_payment_received_content() using the REAL templates.
// Run from the repo root: php tests/test_payment_received_email.php
// (Loads merch_notify.php, which needs config.php + PHPMailer like production.)
require_once __DIR__ . '/../merch_notify.php';

$fails = 0;
function expect($cond, string $label): void {
    global $fails;
    if ($cond) { echo "PASS  $label\n"; } else { echo "FAIL  $label\n"; $fails++; }
}

$c = merch_payment_received_content([
    ['item' => 'Blade Holder', 'quantity' => 2, 'color' => '#14 Sky Blue'],
    ['item' => 'Hearts', 'quantity' => 1, 'color' => 'Not applicable'],
    ['item' => 'Tape Gun Holder', 'quantity' => 1, 'color' => ''],
], 'Ann <b>');

expect($c['subject'] === 'We got your payment!', 'subject');
expect(strpos($c['text'], 'Hi Ann <b>!') !== false, 'text greeting, name unescaped in plain text');
expect(strpos($c['text'], '- Blade Holder (x2) - #14 Sky Blue') !== false, 'quantity and color shown');
expect(strpos($c['text'], '- Hearts') !== false && strpos($c['text'], 'Not applicable') === false, '"Not applicable" color suppressed');
expect(strpos($c['text'], '- Tape Gun Holder') !== false, 'blank color ok');
expect(strpos($c['text'], 'will email you when they ship. Thank you for your support, we appreciate it!') !== false, 'approved wording');
expect(strpos($c['text'], 'when they\'re ready') === false, 'old wording gone');
expect(strpos($c['text'], '$') === false, 'no dollar amounts');
expect(strpos($c['html'], 'Ann &lt;b&gt;') !== false && strpos($c['html'], 'Ann <b>') === false, 'html escapes the name');
expect(strpos($c['html'], '<li>') !== false, 'html list items');
expect(strpos($c['html'], '{{') === false && strpos($c['text'], '{{') === false, 'no unreplaced tokens');

exit($fails ? 1 : 0);
