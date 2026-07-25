<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

class Business_unit_item_quantity extends Model
{
    protected $table = 'business_unit_item_quantities';
    protected $primaryKey = 'business_unit_id';
    protected $useAutoIncrement = false;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'business_unit_id',
        'item_id',
        'location_id',
        'quantity',
    ];

    public function exists(int $businessUnitId, int $itemId, int $locationId): bool
    {
        return $this->baseBuilder($businessUnitId, $itemId, $locationId)
            ->countAllResults() === 1;
    }

    public function getQuantity(int $businessUnitId, int $itemId, int $locationId): float
    {
        $row = $this->baseBuilder($businessUnitId, $itemId, $locationId)
            ->select('quantity')
            ->get()
            ->getRow();

        return $row === null ? 0.0 : (float) $row->quantity;
    }

    public function setQuantity(int $businessUnitId, int $itemId, int $locationId, float $quantity): bool
    {
        $this->ensureZeroRow($businessUnitId, $itemId, $locationId);

        return $this->baseBuilder($businessUnitId, $itemId, $locationId)
            ->update(['quantity' => $quantity]);
    }

    public function changeQuantity(int $businessUnitId, int $itemId, int $locationId, float $quantityChange): bool
    {
        $this->ensureZeroRow($businessUnitId, $itemId, $locationId);
        $quantity = $this->getQuantity($businessUnitId, $itemId, $locationId);

        return $this->setQuantity($businessUnitId, $itemId, $locationId, $quantity + $quantityChange);
    }

    public function ensureZeroRow(int $businessUnitId, int $itemId, int $locationId): bool
    {
        $this->db->query(
            'INSERT IGNORE INTO `' . $this->db->prefixTable('business_unit_item_quantities') . '`'
            . ' (`business_unit_id`, `item_id`, `location_id`, `quantity`)'
            . ' VALUES (?, ?, ?, 0)',
            [$businessUnitId, $itemId, $locationId]
        );

        return $this->exists($businessUnitId, $itemId, $locationId);
    }

    public function resetForItem(int $businessUnitId, int $itemId): bool
    {
        return $this->db->table('business_unit_item_quantities')
            ->where('business_unit_id', $businessUnitId)
            ->where('item_id', $itemId)
            ->update(['quantity' => 0]);
    }

    private function baseBuilder(int $businessUnitId, int $itemId, int $locationId): BaseBuilder
    {
        return $this->db->table('business_unit_item_quantities')
            ->where('business_unit_id', $businessUnitId)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId);
    }
}
