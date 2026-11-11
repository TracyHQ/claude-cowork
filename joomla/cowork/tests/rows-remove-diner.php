<?php
// Exact released Diner menu rows: removing the extras must preserve every kept slot and the masked lock.
// Uses only in-memory contract lock rows; no CMS database or customer site is connected.
$dinerId = 'ja-diner/j6/1.0.8';
$dinerDir = __DIR__ . '/../lib/contracts/' . $dinerId;
$dinerMapBytes = (string) file_get_contents($dinerDir . '/content-map.json');
$dinerMap = json_decode($dinerMapBytes, true);
$dinerLock = json_decode((string) file_get_contents($dinerDir . '/presentation-lock.json'), true);
$dinerReview = json_decode((string) file_get_contents(__DIR__ . '/../lib/hideable-slots.json'), true)['contracts'][$dinerId];
check('Diner reviewed rows are tied to the exact released content map', $dinerReview['contentMapSha256'], hash('sha256', $dinerMapBytes));
$dinerStore = new TestContractStore();
$dinerContract = new QuickstartContract(new ContractTestWriter(new FakeApplyLog(), $dinerStore), $dinerStore, sys_get_temp_dir(), $dinerDir);
$dinerBad = []; $dinerRemoved = 0;
foreach ([100 => 2, 101 => 2, 102 => 3] as $dinerModule => $dinerKeep) {
    $entity = 'module-' . $dinerModule;
    $row = $dinerLock['entities'][$entity];
    $slots = array_values(array_filter($dinerMap['slots'], static fn($slot) => $slot['entity'] === $entity));
    $before = []; foreach ($slots as $slot) $before[$slot['key']] = $dinerContract->slotValue($row, $slot);
    $table = $dinerReview['rows'][$entity][0];
    $ops = [];
    for ($i = $table['count'] - 1; $i >= $dinerKeep; $i--) {
        $ops[] = ['op' => 'index', 'column' => $table['column'], 'nestedJson' => $table['nestedJson'], 'parent' => $table['parent'], 'arrays' => $table['arrays'], 'index' => $i];
        $dinerRemoved++;
    }
    $live = $row;
    $mask = array_fill_keys(array_keys($before), MultilingualProfile::CONTENT_SLOT);
    $baseline = $dinerContract->patch($row, $slots, $mask);
    foreach ($ops as $op) {
        $live[$op['column']] = SlotRemoval::apply($live[$op['column']], $op);
        $baseline[$op['column']] = SlotRemoval::apply($baseline[$op['column']], $op);
    }
    $outer = json_decode($live['params'], true);
    $body = json_decode($outer['jatools-config'], true);
    $parent = SlotRemoval::at($body, $table['parent']);
    foreach ($table['arrays'] as $array)
        if (count($parent[$array]) !== $dinerKeep) $dinerBad[] = $entity . ': parallel row count';
    $left = [];
    foreach ($slots as $slot) {
        $moved = SlotRemoval::slot($slot, $ops);
        if ($moved === null) continue;
        $left[] = $moved;
        if ($dinerContract->slotValue($live, $moved) !== $before[$slot['key']]) $dinerBad[] = $slot['key'] . ': kept value changed';
    }
    $again = $dinerContract->patch($live, $left, array_intersect_key($mask, array_flip(array_column($left, 'key'))));
    $decoded = static fn($value) => array_map(static fn($v) => is_string($v) && is_array(json_decode($v, true)) ? json_decode($v, true) : $v, $value);
    if ($decoded($again) != $decoded($baseline)) $dinerBad[] = $entity . ': masked drift';
}
check('Diner menus shrink 10/10/10 to 2/2/3 without changing kept slots or masked presentation', $dinerBad, []);
check('Diner shrinking removes exactly the 23 excess rows', $dinerRemoved, 23);
unset($dinerContract, $dinerStore, $dinerMap, $dinerLock, $dinerReview);
