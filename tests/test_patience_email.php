<?php
// Usage: php tests/test_patience_email.php [path/to/merchandise.csv]
// Expected count against the Oct 14 snapshot: 47.
define('MERCH_EMAIL_LOG_FILE', sys_get_temp_dir() . '/patience_test_log_' . getmypid() . '.csv');
require __DIR__ . '/../pricing.php';
require __DIR__ . '/../merch_shipments.php';
require __DIR__ . '/../merch_patience_email.php';

$fail = 0;
function check($label, $ok) { global $fail; if (!$ok) { $fail++; } echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; }

foreach ([
    'Jane Smith' => 'Jane', 'jane smith' => 'Jane', 'JANE SMITH' => 'Jane', 'DeAnna Lee' => 'DeAnna',
    'Cher' => 'there', 'Tom & Amy Lee' => 'there', 'Tom and Amy Lee' => 'there', 'Dr. Who' => 'there',
    'Mrs Jones' => 'there', 'J. Smith' => 'there', 'J Smith' => 'there', 'a@b.com x' => 'there', '' => 'there',
    "Mary-Ann O'Neil" => 'Mary-Ann',
] as $in => $want) {
    check("first name '$in' -> '$want'", merch_patience_first_name((string) $in) === $want);
}

$log = merch_email_log_file();
merch_email_log_append('patience', 'A@x.com', '=cmd', true);
merch_email_log_append('patience', 'b@x.com', 'B', false, 'boom');
$last = merch_email_log_last_sent('patience');
check('log: sent recorded lowercase', isset($last['a@x.com']));
check('log: failure not counted', !isset($last['b@x.com']));
check('guard: recent', merch_patience_recently_sent($last, 'a@x.com') !== null);
check('guard: old', merch_patience_recently_sent($last, 'a@x.com', time() + 8 * 86400) === null);
@unlink($log);

$csv = $argv[1] ?? null;
if ($csv && is_file($csv)) {
    $loaded = merch_load_csv($csv, 'merchandise.csv');
    $col = merch_csv_column_map($loaded['header'], merch_patience_required_columns(), merch_patience_hard_required_columns(), 'merchandise.csv');
    $r = merch_patience_build_recipients($loaded['rows'], $col);
    echo 'recipients: ' . count($r) . "\n";
    check('47 recipients on Oct 14 snapshot', count($r) === 47);
    check('emails unique', count(array_unique(array_column($r, 'email'))) === count($r));
    $c = merch_patience_content($r[0]);
    check('content tokens resolved', strpos($c['html'] . $c['text'], '{{') === false);
    echo $c['subject'] . "\n" . $c['text'] . "\n";
    echo 'unreachable: ' . count(merch_patience_unreachable($loaded['rows'], $col)) . "\n";
}
exit($fail ? 1 : 0);
