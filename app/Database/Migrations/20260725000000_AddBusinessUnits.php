<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddBusinessUnits extends Migration
{
    private const BUSINESS_UNITS = [
        'DAY'   => 'DAY',
        'NIGHT' => 'NIGHT',
    ];

    private const FIXED_ACCOUNT_BUSINESS_UNITS = [
        'NguyenDuyTai'  => 'DAY',
        'NguyenDuyTai1' => 'NIGHT',
        'NguyenDuyTai2' => null,
    ];

    public function up(): void
    {
        helper('migration');

        $this->ensureBusinessUnitsTable();
        $this->ensureEmployeesBusinessUnitColumn();
        $this->ensureBusinessUnits();
        $this->ensureFixedAccountMappings();
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback is unsafe because business unit assignments may be referenced by operational data.');
    }

    private function ensureBusinessUnitsTable(): void
    {
        $this->db->resetDataCache();

        if (!$this->db->tableExists('business_units')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'auto_increment' => true,
                ],
                'code' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 16,
                    'null'       => false,
                ],
                'name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => false,
                ],
                'enabled' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'null'       => false,
                    'default'    => 1,
                ],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey('code', false, true, 'code');
            $this->forge->createTable('business_units', true);
            $this->db->resetDataCache();
        }

        if (!indexExists('business_units', 'code')) {
            $this->forge->addKey('code', false, true, 'code');
            $this->forge->processIndexes('business_units');
        }
    }

    private function ensureEmployeesBusinessUnitColumn(): void
    {
        $this->db->resetDataCache();

        if (!$this->db->fieldExists('business_unit_id', 'employees')) {
            $this->forge->addColumn('employees', [
                'business_unit_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                    'after'      => 'account_scope',
                ],
            ]);
            $this->db->resetDataCache();
        }

        if (!indexExists('employees', 'business_unit_id')) {
            $this->forge->addKey('business_unit_id', false, false, 'business_unit_id');
            $this->forge->processIndexes('employees');
        }

        if (!foreignKeyExists('ospos_employees_business_unit_id_fk', 'employees')) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('employees') . '`'
                . ' ADD CONSTRAINT `ospos_employees_business_unit_id_fk` FOREIGN KEY (`business_unit_id`)'
                . ' REFERENCES `' . $this->db->prefixTable('business_units') . '` (`id`)'
            );
        }
    }

    private function ensureBusinessUnits(): void
    {
        foreach (self::BUSINESS_UNITS as $code => $name) {
            $businessUnit = $this->db->table('business_units')
                ->where('code', $code)
                ->get()
                ->getRowArray();

            if ($businessUnit === null) {
                $this->db->table('business_units')->insert([
                    'code'    => $code,
                    'name'    => $name,
                    'enabled' => 1,
                ]);
                continue;
            }

            $this->db->table('business_units')
                ->where('id', (int) $businessUnit['id'])
                ->update([
                    'name'    => $name,
                    'enabled' => 1,
                ]);
        }
    }

    private function ensureFixedAccountMappings(): void
    {
        $businessUnitIds = $this->getBusinessUnitIds();

        foreach (self::FIXED_ACCOUNT_BUSINESS_UNITS as $username => $businessUnitCode) {
            $businessUnitId = $businessUnitCode === null ? null : $businessUnitIds[$businessUnitCode];

            $this->db->table('employees')
                ->where('username', $username)
                ->update(['business_unit_id' => $businessUnitId]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function getBusinessUnitIds(): array
    {
        $businessUnits = $this->db->table('business_units')
            ->select('id, code')
            ->whereIn('code', array_keys(self::BUSINESS_UNITS))
            ->get()
            ->getResultArray();

        return array_column($businessUnits, 'id', 'code');
    }
}
