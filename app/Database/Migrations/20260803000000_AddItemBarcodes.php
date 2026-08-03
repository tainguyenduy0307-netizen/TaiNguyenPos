<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddItemBarcodes extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists('item_barcodes')) {
            return;
        }

        $this->forge->addField([
            'barcode_id' => [
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
            'barcode' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
        $this->forge->addPrimaryKey('barcode_id');
        $this->forge->addUniqueKey('barcode', 'item_barcodes_barcode_unique');
        $this->forge->addKey('item_id', false, false, 'item_barcodes_item_id');
        $this->forge->createTable('item_barcodes', true);

        $this->db->query(
            'ALTER TABLE `' . $this->db->prefixTable('item_barcodes') . '`'
            . ' ADD CONSTRAINT `ospos_item_barcodes_item_id_fk` FOREIGN KEY (`item_id`)'
            . ' REFERENCES `' . $this->db->prefixTable('items') . '` (`item_id`)'
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('item_barcodes', true);
        $this->db->resetDataCache();
    }
}
