<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

class BusinessUnitInvoiceSequenceService
{
    private BaseConnection $db;
    private BusinessUnitService $businessUnitService;

    public function __construct(?BaseConnection $db = null, ?BusinessUnitService $businessUnitService = null)
    {
        $this->db = $db ?? Database::connect();
        $this->businessUnitService = $businessUnitService ?? new BusinessUnitService($this->db);
    }

    public function acquireNextForCurrentBusinessUnit(): string
    {
        $this->db->transStart();

        try {
            $invoiceNumber = $this->acquireNextForCurrentBusinessUnitInTransaction();
            $this->db->transComplete();
        } catch (Throwable $e) {
            $this->rollbackOpenTransactions();
            throw $e;
        }

        if (!$this->db->transStatus()) {
            throw new RuntimeException('Failed to allocate invoice number.');
        }

        return $invoiceNumber;
    }

    public function acquireNextForCurrentBusinessUnitInTransaction(): string
    {
        return $this->acquireNextForBusinessUnitId($this->businessUnitService->requireCurrentBusinessUnitId());
    }

    private function acquireNextForBusinessUnitId(int $businessUnitId): string
    {
        if (!$this->db->tableExists('business_unit_invoice_sequences')) {
            throw new RuntimeException('Business unit invoice sequence table is missing.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->query(
            'INSERT IGNORE INTO `' . $this->db->prefixTable('business_unit_invoice_sequences') . '`'
            . ' (`business_unit_id`, `last_invoice_number`, `updated_at`) VALUES (?, 0, ?)',
            [$businessUnitId, $now]
        );

        $row = $this->db->query(
            'SELECT last_invoice_number'
            . ' FROM `' . $this->db->prefixTable('business_unit_invoice_sequences') . '`'
            . ' WHERE business_unit_id = ?'
            . ' FOR UPDATE',
            [$businessUnitId]
        )->getRowArray();

        if ($row === null) {
            throw new RuntimeException('Failed to lock invoice sequence row.');
        }

        $nextInvoiceNumber = (int) $row['last_invoice_number'] + 1;

        $this->db->table('business_unit_invoice_sequences')
            ->where('business_unit_id', $businessUnitId)
            ->update([
                'last_invoice_number' => $nextInvoiceNumber,
                'updated_at'          => $now,
            ]);

        return str_pad((string) $nextInvoiceNumber, 4, '0', STR_PAD_LEFT);
    }

    private function rollbackOpenTransactions(): void
    {
        while ($this->db->transDepth > 0) {
            $this->db->transRollback();
        }
    }
}
