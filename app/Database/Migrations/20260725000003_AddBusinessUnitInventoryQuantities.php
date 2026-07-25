<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddBusinessUnitInventoryQuantities extends Migration
{
    public function up(): void
    {
        helper('migration');

        $this->ensureBusinessUnitItemQuantitiesTable();
        $this->ensureBusinessUnitItemQuantitiesIndex();
        $this->ensureBusinessUnitItemQuantitiesForeignKeys();
        $this->ensureZeroQuantityRows();
        $this->ensureInventoryBusinessUnitColumn();
        $this->ensureInventoryIndexes();
        $this->ensureInventoryForeignKey();
        $this->backfillInventoryBusinessUnits();
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback is unsafe because scoped inventory quantities and ledger entries may already reference business units.');
    }

    private function ensureBusinessUnitItemQuantitiesTable(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists('business_unit_item_quantities')) {
            return;
        }

        $this->forge->addField([
            'business_unit_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
            ],
            'item_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
            ],
            'location_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
            ],
            'quantity' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,3',
                'null'       => false,
                'default'    => 0,
            ],
        ]);
        $this->forge->addPrimaryKey(['business_unit_id', 'item_id', 'location_id']);
        $this->forge->createTable('business_unit_item_quantities', true);
        $this->db->resetDataCache();
    }

    private function ensureBusinessUnitItemQuantitiesIndex(): void
    {
        if (!indexExists('business_unit_item_quantities', 'business_unit_item_quantities_location_quantity')) {
            $this->forge->addKey(
                ['business_unit_id', 'location_id', 'quantity'],
                false,
                false,
                'business_unit_item_quantities_location_quantity'
            );
            $this->forge->processIndexes('business_unit_item_quantities');
        }
    }

    private function ensureBusinessUnitItemQuantitiesForeignKeys(): void
    {
        if (!foreignKeyExists('ospos_bu_item_quantities_business_unit_id_fk', 'business_unit_item_quantities')) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('business_unit_item_quantities') . '`'
                . ' ADD CONSTRAINT `ospos_bu_item_quantities_business_unit_id_fk` FOREIGN KEY (`business_unit_id`)'
                . ' REFERENCES `' . $this->db->prefixTable('business_units') . '` (`id`)'
            );
        }

        if (!foreignKeyExists('ospos_bu_item_quantities_item_id_fk', 'business_unit_item_quantities')) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('business_unit_item_quantities') . '`'
                . ' ADD CONSTRAINT `ospos_bu_item_quantities_item_id_fk` FOREIGN KEY (`item_id`)'
                . ' REFERENCES `' . $this->db->prefixTable('items') . '` (`item_id`)'
            );
        }

        if (!foreignKeyExists('ospos_bu_item_quantities_location_id_fk', 'business_unit_item_quantities')) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('business_unit_item_quantities') . '`'
                . ' ADD CONSTRAINT `ospos_bu_item_quantities_location_id_fk` FOREIGN KEY (`location_id`)'
                . ' REFERENCES `' . $this->db->prefixTable('stock_locations') . '` (`location_id`)'
            );
        }
    }

    private function ensureZeroQuantityRows(): void
    {
        $this->db->query(
            'INSERT IGNORE INTO `' . $this->db->prefixTable('business_unit_item_quantities') . '`'
            . ' (`business_unit_id`, `item_id`, `location_id`, `quantity`)'
            . ' SELECT business_units.id, items.item_id, stock_locations.location_id, 0'
            . ' FROM `' . $this->db->prefixTable('business_units') . '` AS business_units'
            . ' CROSS JOIN `' . $this->db->prefixTable('items') . '` AS items'
            . ' CROSS JOIN `' . $this->db->prefixTable('stock_locations') . '` AS stock_locations'
            . " WHERE business_units.code IN ('DAY', 'NIGHT')"
            . ' AND business_units.enabled = 1'
            . ' AND stock_locations.deleted = 0'
        );
    }

    private function ensureInventoryBusinessUnitColumn(): void
    {
        $this->db->resetDataCache();

        if (!$this->db->fieldExists('business_unit_id', 'inventory')) {
            $this->forge->addColumn('inventory', [
                'business_unit_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                    'after'      => 'trans_user',
                ],
            ]);
            $this->db->resetDataCache();
        }
    }

    private function ensureInventoryIndexes(): void
    {
        if (!indexExists('inventory', 'inventory_business_unit_item_date')) {
            $this->forge->addKey(['business_unit_id', 'trans_items', 'trans_date'], false, false, 'inventory_business_unit_item_date');
            $this->forge->processIndexes('inventory');
        }

        if (!indexExists('inventory', 'inventory_business_unit_location_date')) {
            $this->forge->addKey(['business_unit_id', 'trans_location', 'trans_date'], false, false, 'inventory_business_unit_location_date');
            $this->forge->processIndexes('inventory');
        }
    }

    private function ensureInventoryForeignKey(): void
    {
        if (!foreignKeyExists('ospos_inventory_business_unit_id_fk', 'inventory')) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('inventory') . '`'
                . ' ADD CONSTRAINT `ospos_inventory_business_unit_id_fk` FOREIGN KEY (`business_unit_id`)'
                . ' REFERENCES `' . $this->db->prefixTable('business_units') . '` (`id`)'
            );
        }
    }

    private function backfillInventoryBusinessUnits(): void
    {
        $this->backfillInventoryFromSalesComments('^POS [0-9]+$', 5);
        $this->backfillInventoryFromReceivingsComments('^RECV [0-9]+$', 6);
        $this->backfillInventoryFromSalesComments('^Deleting sale [0-9]+$', 15);
        $this->backfillInventoryFromReceivingsComments('^Deleting receiving [0-9]+$', 20);
    }

    private function backfillInventoryFromSalesComments(string $commentPattern, int $idStart): void
    {
        $this->db->query(
            'UPDATE `' . $this->db->prefixTable('inventory') . '` AS inventory'
            . ' INNER JOIN `' . $this->db->prefixTable('sales') . '` AS sales'
            . ' ON sales.sale_id = CAST(SUBSTRING(inventory.trans_comment, ' . $idStart . ') AS UNSIGNED)'
            . ' AND sales.business_unit_id IS NOT NULL'
            . ' SET inventory.business_unit_id = sales.business_unit_id'
            . ' WHERE inventory.trans_comment REGEXP ' . $this->db->escape($commentPattern)
            . ' AND (inventory.business_unit_id IS NULL OR inventory.business_unit_id <> sales.business_unit_id)'
        );
    }

    private function backfillInventoryFromReceivingsComments(string $commentPattern, int $idStart): void
    {
        $this->db->query(
            'UPDATE `' . $this->db->prefixTable('inventory') . '` AS inventory'
            . ' INNER JOIN `' . $this->db->prefixTable('receivings') . '` AS receivings'
            . ' ON receivings.receiving_id = CAST(SUBSTRING(inventory.trans_comment, ' . $idStart . ') AS UNSIGNED)'
            . ' AND receivings.business_unit_id IS NOT NULL'
            . ' SET inventory.business_unit_id = receivings.business_unit_id'
            . ' WHERE inventory.trans_comment REGEXP ' . $this->db->escape($commentPattern)
            . ' AND (inventory.business_unit_id IS NULL OR inventory.business_unit_id <> receivings.business_unit_id)'
        );
    }
}
