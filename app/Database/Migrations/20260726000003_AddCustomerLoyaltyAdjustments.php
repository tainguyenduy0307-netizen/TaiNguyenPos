<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddCustomerLoyaltyAdjustments extends Migration
{
    public function up(): void
    {
        helper('migration');

        $this->ensureCustomerLoyaltyAdjustmentsTable();
        $this->ensureIndexes();
        $this->ensureForeignKeys();
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback is unsafe because customer loyalty adjustments affect customer points.');
    }

    private function ensureCustomerLoyaltyAdjustmentsTable(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists('customer_loyalty_adjustments')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'auto_increment' => true,
            ],
            'customer_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
            ],
            'points_delta' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
            ],
            'resulting_points' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
            ],
            'employee_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
            ],
            'reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['customer_id', 'created_at'], false, false, 'customer_loyalty_adjustments_customer_created');
        $this->forge->addKey('employee_id', false, false, 'customer_loyalty_adjustments_employee');
        $this->forge->createTable('customer_loyalty_adjustments', true);
        $this->db->resetDataCache();
    }

    private function ensureIndexes(): void
    {
        if (!indexExists('customer_loyalty_adjustments', 'customer_loyalty_adjustments_customer_created')) {
            $this->forge->addKey(['customer_id', 'created_at'], false, false, 'customer_loyalty_adjustments_customer_created');
            $this->forge->processIndexes('customer_loyalty_adjustments');
        }

        if (!indexExists('customer_loyalty_adjustments', 'customer_loyalty_adjustments_employee')) {
            $this->forge->addKey('employee_id', false, false, 'customer_loyalty_adjustments_employee');
            $this->forge->processIndexes('customer_loyalty_adjustments');
        }
    }

    private function ensureForeignKeys(): void
    {
        if (!foreignKeyExists('ospos_customer_loyalty_adjustments_customer_fk', 'customer_loyalty_adjustments')) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('customer_loyalty_adjustments') . '`'
                . ' ADD CONSTRAINT `ospos_customer_loyalty_adjustments_customer_fk` FOREIGN KEY (`customer_id`)'
                . ' REFERENCES `' . $this->db->prefixTable('customers') . '` (`person_id`)'
            );
        }

        if (!foreignKeyExists('ospos_customer_loyalty_adjustments_employee_fk', 'customer_loyalty_adjustments')) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('customer_loyalty_adjustments') . '`'
                . ' ADD CONSTRAINT `ospos_customer_loyalty_adjustments_employee_fk` FOREIGN KEY (`employee_id`)'
                . ' REFERENCES `' . $this->db->prefixTable('employees') . '` (`person_id`)'
            );
        }
    }
}
