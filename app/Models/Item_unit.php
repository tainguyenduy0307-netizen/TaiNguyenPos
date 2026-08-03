<?php

namespace App\Models;

use CodeIgniter\Model;

class Item_unit extends Model
{
    public const TYPE_RETAIL = 'retail';
    public const TYPE_LARGE = 'large';

    protected $table = 'item_units';
    protected $primaryKey = 'item_unit_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'item_id',
        'unit_type',
        'unit_name',
        'barcode',
        'conversion_quantity',
        'unit_price',
        'cost_price',
        'created_at',
        'updated_at',
    ];

    public function getRetailUnit(int $itemId): ?object
    {
        return $this->getUnit($itemId, self::TYPE_RETAIL);
    }

    public function getLargeUnit(int $itemId): ?object
    {
        return $this->getUnit($itemId, self::TYPE_LARGE);
    }

    public function getUnit(int $itemId, string $unitType): ?object
    {
        if (!$this->db->tableExists('item_units')) {
            return null;
        }

        return $this->db->table('item_units')
            ->where('item_id', $itemId)
            ->where('unit_type', $unitType)
            ->get()
            ->getRow();
    }

    public function getUnitsForItem(int $itemId): array
    {
        if (!$this->db->tableExists('item_units')) {
            return [];
        }

        return $this->db->table('item_units')
            ->where('item_id', $itemId)
            ->orderBy('unit_type', 'DESC')
            ->get()
            ->getResultArray();
    }

    public function getUnitById(int $itemUnitId): ?object
    {
        if (!$this->db->tableExists('item_units')) {
            return null;
        }

        return $this->db->table('item_units')
            ->where('item_unit_id', $itemUnitId)
            ->get()
            ->getRow();
    }

    public function getLargeUnitByBarcode(string $barcode): ?object
    {
        if (!$this->db->tableExists('item_units')) {
            return null;
        }

        return $this->db->table('item_units')
            ->where('barcode', $barcode)
            ->where('unit_type', self::TYPE_LARGE)
            ->get()
            ->getRow();
    }

    public function getItemIdsForLargeBarcodes(array $barcodes): array
    {
        if (!$this->db->tableExists('item_units') || empty($barcodes)) {
            return [];
        }

        $rows = $this->db->table('item_units')
            ->select('barcode, item_id, item_unit_id')
            ->where('unit_type', self::TYPE_LARGE)
            ->whereIn('barcode', $barcodes)
            ->get()
            ->getResultArray();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['barcode']] = [
                'item_id' => (int) $row['item_id'],
                'item_unit_id' => (int) $row['item_unit_id'],
            ];
        }

        return $result;
    }

    public function upsertRetailUnit(int $itemId, string $unitName, float $unitPrice, float $costPrice): int
    {
        return $this->upsertUnit($itemId, self::TYPE_RETAIL, $unitName, null, 1.0, $unitPrice, $costPrice);
    }

    public function upsertLargeUnit(int $itemId, string $unitName, string $barcode, int $conversionQuantity, float $unitPrice, float $costPrice): int
    {
        return $this->upsertUnit($itemId, self::TYPE_LARGE, $unitName, $barcode, (float) $conversionQuantity, $unitPrice, $costPrice);
    }

    private function upsertUnit(int $itemId, string $unitType, string $unitName, ?string $barcode, float $conversionQuantity, float $unitPrice, float $costPrice): int
    {
        if (!$this->db->tableExists('item_units')) {
            return 0;
        }

        $existing = $this->getUnit($itemId, $unitType);
        $now = date('Y-m-d H:i:s');
        $data = [
            'item_id' => $itemId,
            'unit_type' => $unitType,
            'unit_name' => $unitName !== '' ? $unitName : ($unitType === self::TYPE_RETAIL ? 'Lẻ' : 'Thùng'),
            'barcode' => $barcode !== '' ? $barcode : null,
            'conversion_quantity' => $conversionQuantity,
            'unit_price' => $unitPrice,
            'cost_price' => $costPrice,
            'updated_at' => $now,
        ];

        if ($existing !== null) {
            $this->update((int) $existing->item_unit_id, $data);
            return (int) $existing->item_unit_id;
        }

        $data['created_at'] = $now;
        $this->insert($data, false);

        return (int) $this->db->insertID();
    }
}
