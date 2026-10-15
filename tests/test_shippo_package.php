<?php
// Build: 2026-10-05-A
// ============================================================
// Direct tests for merch_shipment_package() (pricing.php) - which package a
// shipment goes in and what it weighs there, the logic behind the Shippo
// export's Order Weight and package dimensions (2026-10-05). Run from
// anywhere:
//
//     php tests/test_shippo_package.php
//
// Uses the real catalog in /items and the real weights/tiers in
// pricing.php, so a changed weight or tier shows up here as a failure
// (update the expected numbers on purpose when that happens). No web
// server, no CSV.
// ============================================================

error_reporting(E_ALL);
require dirname(__DIR__) . '/pricing.php';

$failures = [];
function expect(string $label, $got, $want): void
{
    global $failures;
    if ((string) $got !== (string) $want) {
        $failures[] = "[$label] expected '$want', got '$got'";
        echo "FAIL  $label: expected '$want', got '$got'\n";
    } else {
        echo "  ok  $label\n";
    }
}
function pkg(array $q): string
{
    $p = merch_shipment_package($q);
    return $p['kind'] . '|' . ($p['weight_oz'] ?? '-') . '|' . $p['length'] . 'x' . $p['width'] . 'x' . $p['height'];
}

// ---- Steve's scale, 2026-10-05 (per-item weights) ----
foreach ([
    'Circle Cutter Holder' => 1.8, 'Oval Cutter Holder' => 2.0, 'Rectangle Cutter Holder' => 2.3,
    'Hearts Cutter Holder' => 1.8, 'Blade Holder' => 2.3, 'Tape Gun Holder' => 2.3,
    'Tape Gun Add-On' => 1.1, 'Tool Holder Stand' => 8.1,
] as $item => $oz) {
    expect("weight on file: $item", ITEM_WEIGHT_OZ[$item] ?? 'missing', $oz);
}
expect('empty mailer 1 oz', MAILER_TARE_OZ, 1);
expect('mailer holds 5', MAILER_PACK_MAX, 5);
expect('box max items = full set', SHIPPING_BOX_MAX_ITEMS, 8);
expect('box max weight = full set', SHIPPING_BOX_MAX_ITEMS_OZ, 21.7);
expect('empty box 4 oz', SHIPPING_BOX_TARE_OZ, 4);

// ---- Poly mailer: no Tool Stand, up to 5 small items (9 x 11) ----
expect('1 Blade -> mailer, 2.3+1', pkg(['Blade Holder' => 1]), 'mailer|3.3|11x9x1');
expect('Circle + Oval -> mailer', pkg(['Circle Cutter Holder' => 1, 'Oval Cutter Holder' => 1]), 'mailer|4.8|11x9x1');
expect('2 Circles -> mailer', pkg(['Circle Cutter Holder' => 2]), 'mailer|4.6|11x9x1');
expect('Tape Gun + Add-On -> mailer', pkg(['Tape Gun Holder' => 1, 'Tape Gun Add-On' => 1]), 'mailer|4.4|11x9x1');
expect('3 small -> mailer (not the invoice box tier)', pkg(['Circle Cutter Holder' => 1, 'Oval Cutter Holder' => 1, 'Hearts Cutter Holder' => 1]), 'mailer|6.6|11x9x1');
expect('4 small -> mailer', pkg(['Rectangle Cutter Holder' => 1, 'Circle Cutter Holder' => 1, 'Oval Cutter Holder' => 1, 'Hearts Cutter Holder' => 1]), 'mailer|8.9|11x9x1');
expect('Blade + all 4 holders (5) -> mailer', pkg(['Blade Holder' => 1, 'Rectangle Cutter Holder' => 1, 'Circle Cutter Holder' => 1, 'Oval Cutter Holder' => 1, 'Hearts Cutter Holder' => 1]), 'mailer|11.2|11x9x1');
expect('5 on one line -> mailer', pkg(['Tape Gun Add-On' => 5]), 'mailer|6.5|11x9x1');
expect('2 Tape Guns + Blade -> mailer (bulky cap is an invoicing rule)', pkg(['Tape Gun Holder' => 2, 'Blade Holder' => 1]), 'mailer|7.9|11x9x1');

// ---- Standard box: a Tool Stand, or more than 5 small items (10 x 7 x 5) ----
expect('6 small -> box', pkg(['Circle Cutter Holder' => 6]), 'box|14.8|10x7x5');
expect('Tool Stand alone -> box', pkg(['Tool Holder Stand' => 1]), 'box|12.1|10x7x5');
expect('Tool Stand + Blade -> box', pkg(['Tool Holder Stand' => 1, 'Blade Holder' => 1]), 'box|14.4|10x7x5');
expect('Tool Stand + 2 -> box', pkg(['Tool Holder Stand' => 1, 'Circle Cutter Holder' => 1, 'Oval Cutter Holder' => 1]), 'box|15.9|10x7x5');
expect('Tool Stand + 4 -> box', pkg(['Tool Holder Stand' => 1, 'Circle Cutter Holder' => 4]), 'box|19.3|10x7x5');
expect('real #816: 8 items incl. 2 tape guns -> box', pkg(['Rectangle Cutter Holder' => 1, 'Tape Gun Holder' => 2, 'Tape Gun Add-On' => 2, 'Circle Cutter Holder' => 1, 'Oval Cutter Holder' => 1, 'Hearts Cutter Holder' => 1]), 'box|18.7|10x7x5');
expect('real #893: 7 small + Tool Stand -> box', pkg(['Blade Holder' => 1, 'Rectangle Cutter Holder' => 1, 'Tape Gun Holder' => 1, 'Tape Gun Add-On' => 1, 'Circle Cutter Holder' => 1, 'Oval Cutter Holder' => 1, 'Hearts Cutter Holder' => 1, 'Tool Holder Stand' => 1]), 'box|25.7|10x7x5');
expect('exactly the box max (8 items) -> box', pkg(['Circle Cutter Holder' => SHIPPING_BOX_MAX_ITEMS]), 'box|18.4|10x7x5');
expect('the full set (8 items, 21.7 oz) -> box', pkg(['Circle Cutter Holder' => 1, 'Oval Cutter Holder' => 1, 'Rectangle Cutter Holder' => 1, 'Hearts Cutter Holder' => 1, 'Blade Holder' => 1, 'Tape Gun Holder' => 1, 'Tape Gun Add-On' => 1, 'Tool Holder Stand' => 1]), 'box|25.7|10x7x5');

// ---- Hand-sized (all blank): never a partial set of fields ----
$blank = 'manual|-|xx';
expect('over the box item limit (9) -> manual', pkg(['Circle Cutter Holder' => SHIPPING_BOX_MAX_ITEMS + 1]), $blank);
expect('full set + one more -> manual', pkg(['Circle Cutter Holder' => 2, 'Oval Cutter Holder' => 1, 'Rectangle Cutter Holder' => 1, 'Hearts Cutter Holder' => 1, 'Blade Holder' => 1, 'Tape Gun Holder' => 1, 'Tape Gun Add-On' => 1, 'Tool Holder Stand' => 1]), $blank);
expect('under 8 items but over the weight (Stand + 6 Rectangles, 21.9 oz) -> manual', pkg(['Tool Holder Stand' => 1, 'Rectangle Cutter Holder' => 6]), $blank);
expect('2 Tool Stands -> manual', pkg(['Tool Holder Stand' => 2]), $blank);
expect('shirt in shipment -> manual (no weight on file)', pkg(['Circle Cutter Holder' => 1, 'Logo Shirt' => 1]), $blank);
expect('unknown item -> manual', pkg(['Totally New Thing' => 1]), $blank);
expect('empty -> manual', pkg([]), $blank);
expect('zero quantities ignored', pkg(['Blade Holder' => 1, 'Oval Cutter Holder' => 0]), 'mailer|3.3|11x9x1');

// Every non-manual answer carries weight AND all three dimensions.
foreach ([
    ['Blade Holder' => 1], ['Circle Cutter Holder' => 6], ['Tool Holder Stand' => 1],
] as $i => $q) {
    $p = merch_shipment_package($q);
    expect("complete field set #$i", ($p['weight_oz'] !== null && $p['width'] !== '' && $p['height'] !== '' && $p['length'] !== '') ? 'yes' : 'no', 'yes');
}

echo "\n" . (empty($failures) ? "All checks passed.\n" : count($failures) . " FAILED:\n  " . implode("\n  ", $failures) . "\n");
exit(empty($failures) ? 0 : 1);
