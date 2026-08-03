<?php

namespace App\Models;

use CodeIgniter\Model;

class Item_barcode extends Model
{
    protected $table = 'item_barcodes';
    protected $primaryKey = 'barcode_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'item_id',
        'barcode',
        'created_at',
    ];

    public function getItemId(string $barcode): ?int
    {
        if (!$this->db->tableExists('item_barcodes')) {
            return null;
        }

        $row = $this->db->table('item_barcodes')
            ->select('item_id')
            ->where('barcode', $barcode)
            ->get()
            ->getRow();

        return $row === null ? null : (int) $row->item_id;
    }

    public function barcodeExistsForOtherItem(string $barcode, int $itemId): bool
    {
        if (!$this->db->tableExists('item_barcodes')) {
            return false;
        }

        return $this->db->table('item_barcodes')
            ->where('barcode', $barcode)
            ->where('item_id !=', $itemId)
            ->countAllResults() > 0;
    }

    public function getItemIdsForBarcodes(array $barcodes): array
    {
        if (!$this->db->tableExists('item_barcodes') || empty($barcodes)) {
            return [];
        }

        $rows = $this->db->table('item_barcodes')
            ->select('barcode, item_id')
            ->whereIn('barcode', $barcodes)
            ->get()
            ->getResultArray();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['barcode']] = (int) $row['item_id'];
        }

        return $result;
    }

    public function saveAliases(int $itemId, array $barcodes): bool
    {
        if (!$this->db->tableExists('item_barcodes')) {
            return false;
        }

        $success = true;
        foreach ($barcodes as $barcode) {
            if ($this->getItemId($barcode) === $itemId) {
                continue;
            }

            $success = $success && (bool) $this->insert([
                'item_id'     => $itemId,
                'barcode'     => $barcode,
                'created_at'  => date('Y-m-d H:i:s'),
            ], false);
        }

        return $success;
    }
}
