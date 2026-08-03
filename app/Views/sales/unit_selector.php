<?php

$availableUnits = [];
foreach (($item['available_units'] ?? []) as $unit) {
    $unitName = trim((string) ($unit['unit_name'] ?? ''));
    if ($unitName === '') {
        continue;
    }

    $availableUnits[] = [
        'item_unit_id' => (int) ($unit['item_unit_id'] ?? 0),
        'unit_name'    => $unitName,
    ];
}

if ($availableUnits === [] && trim((string) ($item['unit_name'] ?? '')) !== '') {
    $availableUnits[] = [
        'item_unit_id' => (int) ($item['item_unit_id'] ?? 0),
        'unit_name'    => trim((string) $item['unit_name']),
    ];
}
?>
<?php if ($availableUnits !== []) { ?>
    <div class="cashier-unit-selector" role="group" aria-label="Đơn vị bán">
        <?php foreach ($availableUnits as $unit) {
            $isCurrentUnit = (int) ($item['item_unit_id'] ?? 0) === $unit['item_unit_id'];
            $unitLabel = '1 ' . $unit['unit_name'];
        ?>
            <button
                type="button"
                class="cashier-unit-badge <?= $isCurrentUnit ? 'cashier-unit-badge--active' : 'cashier-unit-badge--inactive' ?>"
                data-line="<?= esc((string) $line) ?>"
                data-unit-id="<?= esc((string) $unit['item_unit_id']) ?>"
                aria-pressed="<?= $isCurrentUnit ? 'true' : 'false' ?>"
                title="<?= esc($unitLabel) ?>"
            >[<?= esc($unitLabel) ?>]</button>
        <?php } ?>
    </div>
<?php } ?>
