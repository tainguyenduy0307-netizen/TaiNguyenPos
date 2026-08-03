<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddItemUnits extends Migration
{
    public function up(): void
    {
        helper('migration');

        $this->ensureItemUnitsTable();
        $this->ensureUnitQuantitiesTable();
        $this->ensureSalesItemsColumns();
    }

    public function down(): void
    {
        helper('migration');

        if ($this->db->tableExists('sales_items')) {
            if (foreignKeyExists('ospos_sales_items_item_unit_id_fk', 'sales_items')) {
                $this->db->query('ALTER TABLE `' . $this->db->prefixTable('sales_items') . '` DROP FOREIGN KEY `ospos_sales_items_item_unit_id_fk`');
            }

            $dropColumns = [];
            foreach (['item_unit_id', 'unit_type', 'unit_name', 'conversion_quantity'] as $column) {
                if ($this->db->fieldExists($column, 'sales_items')) {
                    $dropColumns[] = $column;
                }
            }
            if ($dropColumns !== []) {
                $this->forge->dropColumn('sales_items', $dropColumns);
            }
        }

        $this->forge->dropTable('business_unit_item_unit_quantities', true);
        $this->forge->dropTable('item_units', true);
        $this->db->resetDataCache();
    }

    private function ensureItemUnitsTable(): void
    {
        $this->db->resetDataCache();
        if ($this->db->tableExists('item_units')) {
            return;
        }

        $this->forge->addField([
            'item_unit_id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'item_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
            ],
            'unit_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
            ],
            'unit_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'barcode' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'conversion_quantity' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,3',
                'null'       => false,
                'default'    => 1,
            ],
            'unit_price' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'null'       => false,
                'default'    => 0,
            ],
            'cost_price' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'null'       => false,
                'default'    => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
        $this->forge->addPrimaryKey('item_unit_id');
        $this->forge->addUniqueKey(['item_id', 'unit_type'], 'item_units_item_unit_type_unique');
        $this->forge->addUniqueKey('barcode', 'item_units_barcode_unique');
        $this->forge->addKey('item_id', false, false, 'item_units_item_id');
        $this->forge->createTable('item_units', true);

        $this->db->query(
            'ALTER TABLE `' . $this->db->prefixTable('item_units') . '`'
            . ' ADD CONSTRAINT `ospos_item_units_item_id_fk` FOREIGN KEY (`item_id`)'
            . ' REFERENCES `' . $this->db->prefixTable('items') . '` (`item_id`)'
        );
        $this->db->resetDataCache();
    }

    private function ensureUnitQuantitiesTable(): void
    {
        $this->db->resetDataCache();
        if ($this->db->tableExists('business_unit_item_unit_quantities')) {
            return;
        }

        $this->forge->addField([
            'business_unit_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
            ],
            'item_unit_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'quantity' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,3',
                'null'       => false,
                'default'    => 0,
            ],
        ]);
        $this->forge->addPrimaryKey(['business_unit_id', 'item_unit_id']);
        $this->forge->addKey(['business_unit_id', 'quantity'], false, false, 'bu_item_unit_quantities_business_unit_quantity');
        $this->forge->createTable('business_unit_item_unit_quantities', true);

        $this->db->query(
            'ALTER TABLE `' . $this->db->prefixTable('business_unit_item_unit_quantities') . '`'
            . ' ADD CONSTRAINT `ospos_bu_item_unit_quantities_business_unit_id_fk` FOREIGN KEY (`business_unit_id`)'
            . ' REFERENCES `' . $this->db->prefixTable('business_units') . '` (`id`)'
        );
        $this->db->query(
            'ALTER TABLE `' . $this->db->prefixTable('business_unit_item_unit_quantities') . '`'
            . ' ADD CONSTRAINT `ospos_bu_item_unit_quantities_item_unit_id_fk` FOREIGN KEY (`item_unit_id`)'
            . ' REFERENCES `' . $this->db->prefixTable('item_units') . '` (`item_unit_id`)'
            . ' ON DELETE CASCADE'
        );
        $this->db->resetDataCache();
    }

    private function ensureSalesItemsColumns(): void
    {
        $this->db->resetDataCache();
        if (!$this->db->fieldExists('item_unit_id', 'sales_items')) {
            $this->forge->addColumn('sales_items', [
                'item_unit_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'null'       => true,
                    'after'      => 'item_id',
                ],
            ]);
            $this->db->resetDataCache();
        }
        if (!$this->db->fieldExists('unit_type', 'sales_items')) {
            $this->forge->addColumn('sales_items', [
                'unit_type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 16,
                    'null'       => false,
                    'default'    => 'retail',
                    'after'      => 'item_unit_id',
                ],
            ]);
        }
        if (!$this->db->fieldExists('unit_name', 'sales_items')) {
            $this->forge->addColumn('sales_items', [
                'unit_name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => true,
                    'after'      => 'unit_type',
                ],
            ]);
        }
        if (!$this->db->fieldExists('conversion_quantity', 'sales_items')) {
            $this->forge->addColumn('sales_items', [
                'conversion_quantity' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '15,3',
                    'null'       => false,
                    'default'    => 1,
                    'after'      => 'unit_name',
                ],
            ]);
        }
        if (!foreignKeyExists('ospos_sales_items_item_unit_id_fk', 'sales_items')) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('sales_items') . '`'
                . ' ADD CONSTRAINT `ospos_sales_items_item_unit_id_fk` FOREIGN KEY (`item_unit_id`)'
                . ' REFERENCES `' . $this->db->prefixTable('item_units') . '` (`item_unit_id`)'
            );
        }
    }
}
