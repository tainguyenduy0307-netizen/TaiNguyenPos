<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddBusinessUnitInvoiceSequences extends Migration
{
    private const BUSINESS_UNIT_CODES = [
        'DAY',
        'NIGHT',
    ];

    public function up(): void
    {
        helper('migration');

        $this->ensureSequenceTable();
        $this->ensureSequenceForeignKey();
        $this->ensureNoDuplicateScopedInvoices();
        $this->replaceSalesInvoiceNumberIndex();
        $this->seedSequences();
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback is unsafe because invoice numbers may already have been allocated.');
    }

    private function ensureSequenceTable(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists('business_unit_invoice_sequences')) {
            return;
        }

        $this->forge->addField([
            'business_unit_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
            ],
            'last_invoice_number' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => false,
                'default'    => 0,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('business_unit_id');
        $this->forge->createTable('business_unit_invoice_sequences', true);
        $this->db->resetDataCache();
    }

    private function ensureSequenceForeignKey(): void
    {
        if (!foreignKeyExists('ospos_bu_invoice_sequences_business_unit_id_fk', 'business_unit_invoice_sequences')) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('business_unit_invoice_sequences') . '`'
                . ' ADD CONSTRAINT `ospos_bu_invoice_sequences_business_unit_id_fk` FOREIGN KEY (`business_unit_id`)'
                . ' REFERENCES `' . $this->db->prefixTable('business_units') . '` (`id`)'
            );
        }
    }

    private function ensureNoDuplicateScopedInvoices(): void
    {
        $duplicates = $this->db->query(
            'SELECT business_unit_id, invoice_number, COUNT(*) AS count'
            . ' FROM `' . $this->db->prefixTable('sales') . '`'
            . ' WHERE business_unit_id IS NOT NULL'
            . ' AND invoice_number IS NOT NULL'
            . ' GROUP BY business_unit_id, invoice_number'
            . ' HAVING COUNT(*) > 1'
            . ' LIMIT 1'
        )->getRowArray();

        if ($duplicates !== null) {
            throw new RuntimeException(
                'Duplicate invoice number exists in the same business unit: '
                . $duplicates['invoice_number']
                . ' for business_unit_id '
                . $duplicates['business_unit_id']
            );
        }
    }

    private function replaceSalesInvoiceNumberIndex(): void
    {
        if (indexExists('sales', 'invoice_number')) {
            $this->forge->dropKey('sales', 'invoice_number', false);
        }

        if (!indexExists('sales', 'sales_business_unit_invoice_number')) {
            $this->forge->addKey(['business_unit_id', 'invoice_number'], false, true, 'sales_business_unit_invoice_number');
            $this->forge->processIndexes('sales');
        }
    }

    private function seedSequences(): void
    {
        foreach ($this->getEnabledOperationalBusinessUnits() as $businessUnit) {
            $businessUnitId = (int) $businessUnit['id'];
            $maxInvoiceNumber = $this->getMaxNumericInvoiceNumber($businessUnitId);
            $currentSequence = $this->getCurrentSequence($businessUnitId);
            $lastInvoiceNumber = max($currentSequence ?? 0, $maxInvoiceNumber);

            if ($currentSequence === null) {
                $this->db->table('business_unit_invoice_sequences')->insert([
                    'business_unit_id'      => $businessUnitId,
                    'last_invoice_number'   => $lastInvoiceNumber,
                    'updated_at'            => date('Y-m-d H:i:s'),
                ]);
                continue;
            }

            if ($lastInvoiceNumber > $currentSequence) {
                $this->db->table('business_unit_invoice_sequences')
                    ->where('business_unit_id', $businessUnitId)
                    ->update([
                        'last_invoice_number' => $lastInvoiceNumber,
                        'updated_at'          => date('Y-m-d H:i:s'),
                    ]);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getEnabledOperationalBusinessUnits(): array
    {
        return $this->db->table('business_units')
            ->select('id, code')
            ->whereIn('code', self::BUSINESS_UNIT_CODES)
            ->where('enabled', 1)
            ->get()
            ->getResultArray();
    }

    private function getMaxNumericInvoiceNumber(int $businessUnitId): int
    {
        $row = $this->db->query(
            'SELECT MAX(CAST(invoice_number AS UNSIGNED)) AS max_invoice_number'
            . ' FROM `' . $this->db->prefixTable('sales') . '`'
            . ' WHERE business_unit_id = ?'
            . ' AND invoice_number REGEXP ?',
            [$businessUnitId, '^[0-9]+$']
        )->getRowArray();

        return $row['max_invoice_number'] === null ? 0 : (int) $row['max_invoice_number'];
    }

    private function getCurrentSequence(int $businessUnitId): ?int
    {
        $row = $this->db->table('business_unit_invoice_sequences')
            ->select('last_invoice_number')
            ->where('business_unit_id', $businessUnitId)
            ->get()
            ->getRowArray();

        return $row === null ? null : (int) $row['last_invoice_number'];
    }
}
