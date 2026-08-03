<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSaleLevelDiscount extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();

        if (!$this->db->fieldExists('sale_discount_type', 'sales')) {
            $this->forge->addColumn('sales', [
                'sale_discount_type' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'null'       => true,
                    'after'      => 'sale_type',
                ],
            ]);
        }

        if (!$this->db->fieldExists('sale_discount_value', 'sales')) {
            $this->forge->addColumn('sales', [
                'sale_discount_value' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '15,2',
                    'null'       => false,
                    'default'    => 0,
                    'after'      => 'sale_discount_type',
                ],
            ]);
        }

        if (!$this->db->fieldExists('sale_discount_amount', 'sales')) {
            $this->forge->addColumn('sales', [
                'sale_discount_amount' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '15,2',
                    'null'       => false,
                    'default'    => 0,
                    'after'      => 'sale_discount_value',
                ],
            ]);
        }

        if (!$this->db->fieldExists('sale_discount_code', 'sales')) {
            $this->forge->addColumn('sales', [
                'sale_discount_code' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => true,
                    'after'      => 'sale_discount_amount',
                ],
            ]);
        }

        $this->db->resetDataCache();
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        $dropColumns = [];
        foreach (['sale_discount_code', 'sale_discount_amount', 'sale_discount_value', 'sale_discount_type'] as $column) {
            if ($this->db->fieldExists($column, 'sales')) {
                $dropColumns[] = $column;
            }
        }

        if ($dropColumns !== []) {
            $this->forge->dropColumn('sales', $dropColumns);
        }

        $this->db->resetDataCache();
    }
}
