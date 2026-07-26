<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddCustomerLoyaltyLedger extends Migration
{
    public function up(): void
    {
        helper('migration');

        $this->ensureCustomerLoyaltyLedgerTable();
        $this->ensureIndexes();
        $this->ensureForeignKeys();
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback is unsafe because customer loyalty ledger rows may already affect customer points.');
    }

    private function ensureCustomerLoyaltyLedgerTable(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists('customer_loyalty_ledger')) {
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
            'sale_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
            ],
            'eligible_amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'null'       => false,
                'default'    => '0.00',
            ],
            'active' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 1,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('sale_id', false, true, 'customer_loyalty_ledger_sale_uq');
        $this->forge->addKey(['customer_id', 'active'], false, false, 'customer_loyalty_ledger_customer_active');
        $this->forge->createTable('customer_loyalty_ledger', true);
        $this->db->resetDataCache();
    }

    private function ensureIndexes(): void
    {
        if (!indexExists('customer_loyalty_ledger', 'customer_loyalty_ledger_sale_uq')) {
            $this->forge->addKey('sale_id', false, true, 'customer_loyalty_ledger_sale_uq');
            $this->forge->processIndexes('customer_loyalty_ledger');
        }

        if (!indexExists('customer_loyalty_ledger', 'customer_loyalty_ledger_customer_active')) {
            $this->forge->addKey(['customer_id', 'active'], false, false, 'customer_loyalty_ledger_customer_active');
            $this->forge->processIndexes('customer_loyalty_ledger');
        }
    }

    private function ensureForeignKeys(): void
    {
        if (!foreignKeyExists('ospos_customer_loyalty_ledger_customer_fk', 'customer_loyalty_ledger')) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('customer_loyalty_ledger') . '`'
                . ' ADD CONSTRAINT `ospos_customer_loyalty_ledger_customer_fk` FOREIGN KEY (`customer_id`)'
                . ' REFERENCES `' . $this->db->prefixTable('customers') . '` (`person_id`)'
            );
        }

        if (!foreignKeyExists('ospos_customer_loyalty_ledger_sale_fk', 'customer_loyalty_ledger')) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('customer_loyalty_ledger') . '`'
                . ' ADD CONSTRAINT `ospos_customer_loyalty_ledger_sale_fk` FOREIGN KEY (`sale_id`)'
                . ' REFERENCES `' . $this->db->prefixTable('sales') . '` (`sale_id`)'
            );
        }
    }
}
