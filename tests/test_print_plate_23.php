<?php
// Build: 2026-10-15-A
// Plate 23 (2 Tool Stands + 1 Tape Gun Holder + 1 Add-On, made-to-order).
//     php tests/test_print_plate_23.php
error_reporting(E_ALL);
define('FILAMENT_COLOR_ITEMS', ['Oval Cutter Holder', 'Circle Cutter Holder', 'Rectangle Cutter Holder', 'Hearts Cutter Holder', 'Tool Holder Stand', 'Tape Gun Holder', 'Tape Gun Add-On', 'Blade Holder']);
require dirname(__DIR__) . '/print_plates.php';

$fail = 0;
function expect(string $label, $got, $want): void
{
    global $fail;
    $g = is_array($got) ? json_encode($got) : (string) $got;
    $w = is_array($want) ? json_encode($want) : (string) $want;
    if ($g !== $w) { $fail++; echo "FAIL  $label: expected $w, got $g\n"; } else { echo "  ok  $label\n"; }
}
function row(string $item, string $color, int $qty, string $id, string $name, int $ts = 1000): array
{
    return ['item' => $item, 'color' => $color, 'qty' => $qty, 'orderId' => $id, 'customerName' => $name, 'ts' => $ts];
}
/** Plate labels in print order for the whole plan. */
function labels(array $groups): array
{
    $o = [];
    foreach ($groups as $g) { foreach ($g['plateGroups'] as $pg) { $o[] = $pg['group']; } }
    return $o;
}

$olive = '#21 Olive Green';
$p23 = null;
foreach (PRINT_PLATES as $i => $p) { if ($p['no'] === '23') { $p23 = $i; } }
expect('plate 23 exists', $p23 !== null, true);
expect('plate 23 contents', PRINT_PLATES[$p23]['items'], ['Tool Holder Stand' => 2, 'Tape Gun Holder' => 1, 'Tape Gun Add-On' => 1]);
expect('plate numbers are unique', count(array_unique(array_column(PRINT_PLATES, 'no'))), count(PRINT_PLATES));
$is23 = fn(array $l) => in_array(true, array_map(fn($x) => strpos($x, 'Plate 23') === 0, $l), true);

// Plate 23 is an ordinary plate: any color, whenever the parts call for it.
foreach (['#21 Olive Green', '#15 CM Blue', '#12 Purple'] as $color) {
    $g = print_plate_group_queue([
        row('Tool Holder Stand', $color, 2, '1', 'Ann Lee'), row('Tape Gun Holder', $color, 1, '1', 'Ann Lee'), row('Tape Gun Add-On', $color, 1, '1', 'Ann Lee'),
    ]);
    expect("one customer, exact contents, $color -> one full plate 23", [labels($g), $g[0]['plateGroups'][0]['plates'][0]['fillFraction']], [['Plate 23 · Tool Stands Tape Gun and Add on'], 1]);
}

// Two customers whose pieces together make the plate (Steve's real case).
$g = print_plate_group_queue([
    row('Tool Holder Stand', $olive, 2, '1', 'Ann Lee', 100),
    row('Tape Gun Holder', $olive, 1, '2', 'Bo Chan', 200), row('Tape Gun Add-On', $olive, 1, '2', 'Bo Chan', 200),
]);
expect('two customers together -> one plate 23', labels($g), ['Plate 23 · Tool Stands Tape Gun and Add on']);

// Single-type needs keep their own plates.
expect('two Tool Stands alone -> plate 07', labels(print_plate_group_queue([row('Tool Holder Stand', $olive, 2, '1', 'Ann Lee')])), ['Plate 07 · Tool Stand']);
expect('best plate for 1 TGH + 1 Add-On is still plate 03', PRINT_PLATES[print_plate_best_plate(['Tape Gun Holder' => 1, 'Tape Gun Add-On' => 1])]['no'], '03');
expect('best plate for 2 Tool Stands is plate 07', PRINT_PLATES[print_plate_best_plate(['Tool Holder Stand' => 2])]['no'], '07');

echo $fail ? "\nFAIL - $fail assertion(s) failed.\n" : "\nPASS - all plate 23 assertions matched.\n";
exit($fail ? 1 : 0);
