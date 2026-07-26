<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddExpensesBusinessUnitScope extends Migration
{
    public function up(): void
    {
        helper('migration');

        $this->ensureExpensesBusinessUnitColumn();
        $this->ensureIndexes();
        $this->ensureForeignKey();
        $this->backfillExpensesBusinessUnits();
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback is unsafe because scoped expenses may already reference business units.');
    }

    private function ensureExpensesBusinessUnitColumn(): void
    {
        $this->db->resetDataCache();

        if (!$this->db->fieldExists('business_unit_id', 'expenses')) {
            $this->forge->addColumn('expenses', [
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
        if (!indexExists('expenses', 'expenses_business_unit_date')) {
            $this->forge->addKey(['business_unit_id', 'date'], false, false, 'expenses_business_unit_date');
            $this->forge->processIndexes('expenses');
        }

        if (!indexExists('expenses', 'expenses_business_unit_deleted_date')) {
            $this->forge->addKey(['business_unit_id', 'deleted', 'date'], false, false, 'expenses_business_unit_deleted_date');
            $this->forge->processIndexes('expenses');
        }
    }

    private function ensureForeignKey(): void
    {
        if (!foreignKeyExists('ospos_expenses_business_unit_id_fk', 'expenses')) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('expenses') . '`'
                . ' ADD CONSTRAINT `ospos_expenses_business_unit_id_fk` FOREIGN KEY (`business_unit_id`)'
                . ' REFERENCES `' . $this->db->prefixTable('business_units') . '` (`id`)'
            );
        }
    }

    private function backfillExpensesBusinessUnits(): void
    {
        $this->db->query(
            'UPDATE `' . $this->db->prefixTable('expenses') . '` AS expenses'
            . ' INNER JOIN `' . $this->db->prefixTable('employees') . '` AS employees'
            . ' ON employees.person_id = expenses.employee_id'
            . ' INNER JOIN `' . $this->db->prefixTable('business_units') . '` AS business_units'
            . ' ON business_units.id = employees.business_unit_id'
            . ' AND business_units.code = employees.account_scope'
            . ' AND business_units.enabled = 1'
            . ' SET expenses.business_unit_id = business_units.id'
            . " WHERE employees.deleted = 0"
            . " AND employees.account_scope IN ('DAY', 'NIGHT')"
            . ' AND expenses.business_unit_id IS NULL'
        );
    }
}
