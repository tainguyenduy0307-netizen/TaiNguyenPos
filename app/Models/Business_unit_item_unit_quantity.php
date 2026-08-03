<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

class Business_unit_item_unit_quantity extends Model
{
    protected $table = 'business_unit_item_unit_quantities';
    protected $primaryKey = 'business_unit_id';
    protected $useAutoIncrement = false;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'business_unit_id',
        'item_unit_id',
        'quantity',
    ];

    public function getQuantity(int $businessUnitId, int $itemUnitId): float
    {
        if (!$this->db->tableExists('business_unit_item_unit_quantities') || $itemUnitId <= 0) {
            return 0.0;
        }

        $row = $this->baseBuilder($businessUnitId, $itemUnitId)
            ->select('quantity')
            ->get()
            ->getRow();

        return $row === null ? 0.0 : (float) $row->quantity;
    }

    public function setQuantity(int $businessUnitId, int $itemUnitId, float $quantity): bool
    {
        if (!$this->ensureZeroRow($businessUnitId, $itemUnitId)) {
            return false;
        }

        return $this->baseBuilder($businessUnitId, $itemUnitId)
            ->update(['quantity' => $quantity]);
    }

    public function changeQuantity(int $businessUnitId, int $itemUnitId, float $quantityChange): bool
    {
        $quantity = $this->getQuantity($businessUnitId, $itemUnitId);

        return $this->setQuantity($businessUnitId, $itemUnitId, $quantity + $quantityChange);
    }

    public function ensureZeroRow(int $businessUnitId, int $itemUnitId): bool
    {
        if (!$this->db->tableExists('business_unit_item_unit_quantities') || $itemUnitId <= 0) {
            return false;
        }

        $this->db->query(
            'INSERT IGNORE INTO `' . $this->db->prefixTable('business_unit_item_unit_quantities') . '`'
            . ' (`business_unit_id`, `item_unit_id`, `quantity`)'
            . ' VALUES (?, ?, 0)',
            [$businessUnitId, $itemUnitId]
        );

        return $this->baseBuilder($businessUnitId, $itemUnitId)->countAllResults() === 1;
    }

    private function baseBuilder(int $businessUnitId, int $itemUnitId): BaseBuilder
    {
        return $this->db->table('business_unit_item_unit_quantities')
            ->where('business_unit_id', $businessUnitId)
            ->where('item_unit_id', $itemUnitId);
    }
}
