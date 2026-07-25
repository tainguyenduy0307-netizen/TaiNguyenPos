<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddReceivingsBusinessUnitScope extends Migration
{
    public function up(): void
    {
        helper('migration');

        $this->ensureReceivingsBusinessUnitColumn();
        $this->ensureIndexes();
        $this->ensureForeignKey();
        $this->backfillReceivingsBusinessUnits();
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback is unsafe because scoped receivings may already reference business units.');
    }

    private function ensureReceivingsBusinessUnitColumn(): void
    {
        $this->db->resetDataCache();

        if (!$this->db->fieldExists('business_unit_id', 'receivings')) {
            $this->forge->addColumn('receivings', [
                'business_unit_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                    'after'      => 'employee_id',
                ],
            ]);

            $this->db->resetDataCache();
        }
    }

    private function ensureIndexes(): void
    {
        if (!indexExists('receivings', 'receivings_business_unit_receiving_time')) {
            $this->forge->addKey(['business_unit_id', 'receiving_time'], false, false, 'receivings_business_unit_receiving_time');
            $this->forge->processIndexes('receivings');
        }

        if (!indexExists('receivings', 'receivings_business_unit_reference')) {
            $this->forge->addKey(['business_unit_id', 'reference'], false, false, 'receivings_business_unit_reference');
            $this->forge->processIndexes('receivings');
        }
    }

    private function ensureForeignKey(): void
    {
        if (!foreignKeyExists('ospos_receivings_business_unit_id_fk', 'receivings')) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('receivings') . '`'
                . ' ADD CONSTRAINT `ospos_receivings_business_unit_id_fk` FOREIGN KEY (`business_unit_id`)'
                . ' REFERENCES `' . $this->db->prefixTable('business_units') . '` (`id`)'
            );
        }
    }

    private function backfillReceivingsBusinessUnits(): void
    {
        $this->db->query(
            'UPDATE `' . $this->db->prefixTable('receivings') . '` AS receivings'
            . ' INNER JOIN `' . $this->db->prefixTable('employees') . '` AS employees'
            . ' ON employees.person_id = receivings.employee_id'
            . ' INNER JOIN `' . $this->db->prefixTable('business_units') . '` AS business_units'
            . ' ON business_units.id = employees.business_unit_id'
            . ' AND business_units.code = employees.account_scope'
            . ' AND business_units.enabled = 1'
            . ' SET receivings.business_unit_id = business_units.id'
            . " WHERE employees.deleted = 0"
            . " AND employees.account_scope IN ('DAY', 'NIGHT')"
            . ' AND receivings.business_unit_id IS NULL'
        );
    }
}
