<?php
// Build: 2026-08-21-B
// ============================================================
// Golden-file test for the merch order pipeline. Run from anywhere:
//
//     php tests/test_order_golden.php
//
// Spins up `php -S` against a scratch copy of the order-path files
// (plus a dummy config.php and an empty merchandise.csv), POSTs a
// series of realistic orders at it exactly the way merch.php's form
// would, then compares what actually landed in the CSV - matched BY
// COLUMN NAME off the file's own header, the same way merch_order.php
// writes it - against hard-coded golden expectations for every
// money-bearing column (Item/Quantity/Color/Size/Sleeve/Price/Tax/
// Shipping) plus a rejected-order case that must write NO row at all.
//
// History: the original version of this test (2026-08-20, Finding 20,
// commit 4bc9195) was verified to fail against the pre-Finding-1
// positional CSV writer and pass after it. That file never made it
// into the GitHub repo (the tests/ folder was missed in an upload
// batch) - this is its 2026-08-21 rebuild, extended for the class/
// catalog redesign: the scratch sandbox now includes /items/ (the
// folder scan is part of the money path - merch_order.php validates
// item names against it), and the golden cases cover one item of
// every class, both surcharges, an oversize shirt, and an unknown
// item name that must be rejected.
//
// The ack/notify emails point at a dummy SMTP host (127.0.0.1:2599,
// nothing listening) - connection refused is instant, and since
// Finding 15 the confirmation page is echoed before the ack send
// anyway, so a mail failure can't fail these assertions.
// ============================================================

error_reporting(E_ALL);

$repo = dirname(__DIR__);
$scratch = sys_get_temp_dir() . '/sfr_golden_' . getmypid();
$port = 8199;
$base = "http://127.0.0.1:{$port}";

// ---- Build the scratch sandbox --------------------------------------
@exec('rm -rf ' . escapeshellarg($scratch));
mkdir($scratch, 0777, true);
$copies = ['pricing.php', 'merch_items.php', 'strings.php', 'merch_order.php', 'merch_notify.php', 'merch_backup.php', 'PHPMailer', 'strings', 'items', 'styles'];
foreach ($copies as $c) {
    exec(sprintf('cp -r %s %s', escapeshellarg("$repo/$c"), escapeshellarg("$scratch/$c")));
}
file_put_contents("$scratch/config.php", <<<'CFG'
<?php
// Test-only dummy config written by test_order_golden.php - never deploy.
const ADMIN_PASSWORD = 'golden-test';
const MAIL_FROM_ADDRESS = 'test@example.com';
const MAIL_FROM_NAME = 'Golden Test';
const NOTIFY_MRFIREFLY_EMAIL = 'notify@example.com';
const PAYPAL_EMAIL_MERCH = 'merchpp@example.com';
const PAYPAL_EMAIL_PRINTED = 'printedpp@example.com';
const SMTP_ENCRYPTION = 'tls';
const SMTP_HOST = '127.0.0.1';
const SMTP_PORT = 2599;
const SMTP_PASSWORD = 'x';
const SMTP_USERNAME = 'x';
const VENMO_HANDLE_MERCH = '@merch-test';
const VENMO_HANDLE_PRINTED = '@printed-test';
const VENMO_LAST = '1234';
CFG);
// Start from an EMPTY file (no header) - also re-proves the Finding 4
// behavior that merch_order.php writes MERCH_CSV_HEADER onto a
// brand-new file before the first row.
file_put_contents("$scratch/merchandise.csv", '');

// ---- Start the server -----------------------------------------------
$server = proc_open(
    ['php', '-S', "127.0.0.1:{$port}", '-t', $scratch],
    [1 => ['file', "$scratch/server.log", 'w'], 2 => ['file', "$scratch/server.log", 'a']],
    $pipes,
    $scratch
);
register_shutdown_function(function () use ($server) {
    if (is_resource($server)) {
        proc_terminate($server);
    }
});
usleep(400000);

function post_order(string $base, array $fields): string
{
    $ch = curl_init("$base/merch_order.php");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
}

$shipTo = [
    'name' => 'Golden Tester', 'email' => 'golden@example.com', 'phone' => '555-0000',
    'fulfillment' => 'Ship', 'address' => '1 Test Ln', 'city' => 'Testville',
    'state' => 'SC', 'zip' => '29001', 'message' => '',
];

// ---- The golden cases -----------------------------------------------
// Each: POST fields (merged over $shipTo) + expected CSV column values.
// Expected Price is the LINE subtotal (unit x qty) as merch_order.php
// stores it; Tax = 7% of that, Shipping per the tier rules.
$cases = [
    'cutter holder, Stars & Stripes, qty 1' => [
        'post' => ['item' => 'Rectangle Cutter Holder', 'quantity' => 1, 'color' => 'Stars & Stripes (+$7)'],
        'expect' => ['Item' => 'Rectangle Cutter Holder', 'Quantity' => '1', 'Color' => 'Stars & Stripes (+$7)',
                     'Size' => '', 'Sleeve' => '', 'Price' => '25', 'Tax' => '1.75', 'Shipping' => '6'],
    ],
    'cutter holders qty 3 -> bigger mailer' => [
        'post' => ['item' => 'Circle Cutter Holder', 'quantity' => 3, 'color' => '#01 Red'],
        'expect' => ['Item' => 'Circle Cutter Holder', 'Quantity' => '3', 'Color' => '#01 Red',
                     'Price' => '54', 'Tax' => '3.78', 'Shipping' => '10'],
    ],
    'tape gun holder qty 2 -> manual quote (cap)' => [
        // Price reflects the 2026-08-21 bump to $18 base (+$2 Rainbow).
        'post' => ['item' => 'Tape Gun Holder', 'quantity' => 2, 'color' => 'Rainbow (+$2)'],
        'expect' => ['Item' => 'Tape Gun Holder', 'Quantity' => '2', 'Color' => 'Rainbow (+$2)',
                     'Price' => '40', 'Tax' => '2.8', 'Shipping' => ''],
    ],
    'tape gun add-on alone, qty 1 (no bundle partner -> full price)' => [
        // The -$3 bundle discount only ever applies at invoice time via
        // merch_group_calculate() when BOTH items are in the group - a
        // single-item order line stores plain per-line pricing, so
        // there's deliberately nothing bundle-related to assert here
        // (see tests/test_bundle_discount.php for the group math).
        'post' => ['item' => 'Tape Gun Add-On', 'quantity' => 1, 'color' => 'Stars & Stripes (+$7)'],
        'expect' => ['Item' => 'Tape Gun Add-On', 'Quantity' => '1', 'Color' => 'Stars & Stripes (+$7)',
                     'Size' => '', 'Sleeve' => '', 'Price' => '17', 'Tax' => '1.19', 'Shipping' => '6'],
    ],
    'tool stand, Rainbow, qty 1 -> box rate' => [
        'post' => ['item' => 'Tool Holder Stand', 'quantity' => 1, 'color' => 'Rainbow (+$2)'],
        'expect' => ['Item' => 'Tool Holder Stand', 'Quantity' => '1', 'Color' => 'Rainbow (+$2)',
                     'Price' => '14', 'Tax' => '0.98', 'Shipping' => '10'],
    ],
    'oversize long-sleeve shirt qty 1' => [
        'post' => ['item' => 'Mr. Firefly Shirt', 'quantity' => 1, 'color' => '#62 Navy',
                   'size' => '4XL', 'sleeve' => 'Long Sleeve'],
        'expect' => ['Item' => 'Mr. Firefly Shirt', 'Quantity' => '1', 'Color' => '#62 Navy',
                     'Size' => '4XL', 'Sleeve' => 'Long Sleeve', 'Price' => '33', 'Tax' => '2.31', 'Shipping' => '6'],
    ],
    'hat qty 3 -> over flat-rate cap, shipping blank' => [
        'post' => ['item' => 'Logo Hat', 'quantity' => 3, 'color' => '#03 Sport Gray'],
        'expect' => ['Item' => 'Logo Hat', 'Quantity' => '3', 'Color' => '#03 Sport Gray',
                     'Price' => '75', 'Tax' => '5.25', 'Shipping' => ''],
    ],
];

// Rejection cases: must NOT add a row.
$rejections = [
    'unknown item name' => ['item' => 'Square Cutter Holder', 'quantity' => 1, 'color' => '#01 Red'],
    'display-only catalog entry' => ['item' => 'More Coming Soon', 'quantity' => 1],
    'Stars & Stripes on an ineligible item' => ['item' => 'Tool Holder Stand', 'quantity' => 1, 'color' => 'Stars & Stripes (+$7)'],
    'Gildan color on a filament item' => ['item' => 'Oval Cutter Holder', 'quantity' => 1, 'color' => '#70 Black '],
];

$failures = [];
$expectedRows = 0;

foreach ($cases as $label => $case) {
    $body = post_order($base, array_merge($shipTo, $case['post']));
    $expectedRows++;
    if (stripos($body, 'Request Not Saved') !== false || $body === '') {
        $failures[] = "[$label] order was rejected (or empty response) but should have been accepted";
        continue;
    }

    // Read the CSV back keyed by its own header, same as the real readers.
    $rows = array_map(static fn($l) => str_getcsv($l, ',', '"', '\\'), file("$scratch/merchandise.csv", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $header = array_shift($rows);
    if (count($rows) !== $expectedRows) {
        $failures[] = "[$label] expected {$expectedRows} CSV rows, found " . count($rows);
        continue;
    }
    $row = array_combine($header, end($rows));
    foreach ($case['expect'] as $colName => $want) {
        $got = $row[$colName] ?? '<missing column>';
        if ((string) $got !== (string) $want) {
            $failures[] = "[$label] column '{$colName}': expected '{$want}', got '{$got}'";
        }
    }
}

foreach ($rejections as $label => $post) {
    $body = post_order($base, array_merge($shipTo, $post));
    $rows = array_map(static fn($l) => str_getcsv($l, ',', '"', '\\'), file("$scratch/merchandise.csv", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    array_shift($rows);
    if (count($rows) !== $expectedRows) {
        $failures[] = "[reject: $label] a CSV row was written for an order that should have been rejected";
        $expectedRows = count($rows); // resync so later checks aren't all noise
    }
    if (stripos($body, 'Request Not Saved') === false) {
        $failures[] = "[reject: $label] response was not the error page";
    }
}

// ---- Verdict --------------------------------------------------------
proc_terminate($server);
if ($failures) {
    echo "FAIL - " . count($failures) . " assertion(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    echo "Scratch dir kept for inspection: $scratch\n";
    exit(1);
}
@exec('rm -rf ' . escapeshellarg($scratch));
echo "PASS - " . count($cases) . " golden orders + " . count($rejections) . " rejections all matched.\n";
exit(0);
