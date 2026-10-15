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

$p23 = null;
foreach (PRINT_PLATES as $i => $p) { if ($p['no'] === '23') { $p23 = $i; } }
expect('plate 23 exists and is made-to-order', [$p23 !== null, !empty(PRINT_PLATES[$p23]['madeToOrder'])], [true, true]);
expect('plate 23 contents', PRINT_PLATES[$p23]['items'], ['Tool Holder Stand' => 2, 'Tape Gun Holder' => 1, 'Tape Gun Add-On' => 1]);
expect('plate numbers are unique', count(array_unique(array_column(PRINT_PLATES, 'no'))), count(PRINT_PLATES));

$olive = '#21 Olive Green';
// 1. One customer needs exactly the plate.
$g = print_plate_group_queue([
    row('Tool Holder Stand', $olive, 2, '1', 'Ann Lee'), row('Tape Gun Holder', $olive, 1, '1', 'Ann Lee'), row('Tape Gun Add-On', $olive, 1, '1', 'Ann Lee'),
]);
expect('one customer, exact contents -> plate 23, full', [labels($g), $g[0]['plateGroups'][0]['plates'][0]['fillFraction']], [['Plate 23 · Tool Stands Tape Gun and Add on'], 1]);

// 2. Two customers whose combined demand equals the plate (Steve's real case).
$g = print_plate_group_queue([
    row('Tool Holder Stand', $olive, 2, '1', 'Ann Lee', 100),
    row('Tape Gun Holder', $olive, 1, '2', 'Bo Chan', 200), row('Tape Gun Add-On', $olive, 1, '2', 'Bo Chan', 200),
]);
expect('two customers together fill plate 23: one plate', labels($g), ['Plate 23 · Tool Stands Tape Gun and Add on']);
$items = $g[0]['plateGroups'][0]['plates'][0]['items'];
expect('  ...it carries both customers\' pieces', [$items['Tool Holder Stand']['qty'], $items['Tape Gun Holder']['qty'], $items['Tape Gun Add-On']['qty']], [2, 1, 1]);

// 3. One customer, 1 Tool Stand + Tape Gun pair: color demand does NOT fill 23 -> ordinary plates only.
$g = print_plate_group_queue([
    row('Tool Holder Stand', $olive, 1, '1', 'Ann Lee'), row('Tape Gun Holder', $olive, 1, '1', 'Ann Lee'), row('Tape Gun Add-On', $olive, 1, '1', 'Ann Lee'),
]);
expect('not enough demand -> plate 23 is not suggested', in_array(true, array_map(fn($l) => strpos($l, 'Plate 23') === 0, labels($g)), true), false);

// 3b. A color that is not opted in never uses plate 23, even when its demand equals the plate
// exactly (this is what CM Blue looked like on 2026-10-14) or exceeds it.
$g = print_plate_group_queue([
    row('Tool Holder Stand', '#15 CM Blue', 2, '1', 'Ann Lee', 100),
    row('Tape Gun Holder', '#15 CM Blue', 1, '2', 'Bo Chan', 200), row('Tape Gun Add-On', '#15 CM Blue', 1, '2', 'Bo Chan', 200),
]);
expect('color not opted in (demand equals plate) -> no plate 23', in_array(true, array_map(fn($l) => strpos($l, 'Plate 23') === 0, labels($g)), true), false);
// A busy opted-in color (demand beyond the plate) can still use it.
$g = print_plate_group_queue([
    row('Tool Holder Stand', '#15 CM Blue', 2, '1', 'Ann Lee', 100), row('Tool Holder Stand', '#15 CM Blue', 1, '3', 'Cy Doe', 300),
    row('Tape Gun Holder', '#15 CM Blue', 1, '2', 'Bo Chan', 200), row('Tape Gun Add-On', '#15 CM Blue', 1, '2', 'Bo Chan', 200),
    row('Tape Gun Holder', '#15 CM Blue', 1, '4', 'Di Poe', 400), row('Tape Gun Add-On', '#15 CM Blue', 1, '4', 'Di Poe', 400),
]);
expect('busy color (demand > plate) -> no plate 23', in_array(true, array_map(fn($l) => strpos($l, 'Plate 23') === 0, labels($g)), true), false);

// 4. Two Tool Stands alone stay on plate 07.
$g = print_plate_group_queue([row('Tool Holder Stand', $olive, 2, '1', 'Ann Lee')]);
expect('two Tool Stands alone -> plate 07', labels($g), ['Plate 07 · Tool Stand']);

// 5. Demand in OTHER colors does not count toward this color.
$g = print_plate_group_queue([
    row('Tool Holder Stand', $olive, 2, '1', 'Ann Lee'),
    row('Tape Gun Holder', '#12 Purple', 1, '2', 'Bo Chan'), row('Tape Gun Add-On', '#12 Purple', 1, '2', 'Bo Chan'),
]);
expect('demand split across colors -> no plate 23', in_array(true, array_map(fn($l) => strpos($l, 'Plate 23') === 0, labels($g)), true), false);

// 6. Ordinary contents never pick 23 by default, and the helper agrees.
expect('default allowed list excludes 23', in_array($p23, print_plate_allowed_indexes(), true), false);
$fills = ['Tool Holder Stand' => 2, 'Tape Gun Holder' => 1, 'Tape Gun Add-On' => 1];
expect('allowed list: 23 only for an opted-in color whose demand fills it',
    [in_array($p23, print_plate_allowed_indexes($fills, $olive), true),
     in_array($p23, print_plate_allowed_indexes(['Tool Holder Stand' => 2, 'Tape Gun Holder' => 1], $olive), true),
     in_array($p23, print_plate_allowed_indexes($fills, '#15 CM Blue'), true),
     in_array($p23, print_plate_allowed_indexes($fills), true)], [true, false, false, false]);
expect('best plate for 1 TGH + 1 Add-On is still plate 03', PRINT_PLATES[print_plate_best_plate(['Tape Gun Holder' => 1, 'Tape Gun Add-On' => 1])]['no'], '03');

echo $fail ? "\nFAIL - $fail assertion(s) failed.\n" : "\nPASS - all plate 23 assertions matched.\n";
exit($fail ? 1 : 0);
