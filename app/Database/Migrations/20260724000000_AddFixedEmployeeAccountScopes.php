<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddFixedEmployeeAccountScopes extends Migration
{
    private const FIXED_ACCOUNTS = [
        [
            'username'      => 'NguyenDuyTai',
            'account_scope' => 'DAY',
            'first_name'    => 'DAY',
            'last_name'     => 'Account',
        ],
        [
            'username'      => 'NguyenDuyTai1',
            'account_scope' => 'NIGHT',
            'first_name'    => 'NIGHT',
            'last_name'     => 'Account',
        ],
        [
            'username'      => 'NguyenDuyTai2',
            'account_scope' => 'AGGREGATE',
            'first_name'    => 'AGGREGATE',
            'last_name'     => 'Account',
        ],
    ];

    public function up(): void
    {
        $initialPassword = $this->getInitialPassword();

        $this->ensureAccountScopeColumn();

        foreach (self::FIXED_ACCOUNTS as $account) {
            $personId = $this->ensureFixedAccount($account, $initialPassword);
            $this->ensureGrants($personId, $account['account_scope']);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback is unsafe because the fixed employee accounts may already own operational data.');
    }

    private function ensureAccountScopeColumn(): void
    {
        $this->db->resetDataCache();

        if ($this->db->fieldExists('account_scope', 'employees')) {
            return;
        }

        $this->forge->addColumn('employees', [
            'account_scope' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => true,
                'after'      => 'language_code',
            ],
        ]);

        $this->db->resetDataCache();
    }

    private function getInitialPassword(): string
    {
        $password = getenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');

        if (!is_string($password) || $password === '' || strlen($password) < 8) {
            throw new RuntimeException('POS_FIXED_ACCOUNT_INITIAL_PASSWORD must be set and at least 8 characters long.');
        }

        return $password;
    }

    private function ensureFixedAccount(array $account, string $initialPassword): int
    {
        $employee = $this->db->table('employees')
            ->where('username', $account['username'])
            ->get()
            ->getRowArray();

        if ($employee !== null) {
            if ($employee['account_scope'] !== $account['account_scope'] || (int) $employee['deleted'] !== 0) {
                throw new RuntimeException('Fixed employee account conflict for username: ' . $account['username']);
            }

            return (int) $employee['person_id'];
        }

        $this->db->transStart();

        $this->db->table('people')->insert([
            'first_name'   => $account['first_name'],
            'last_name'    => $account['last_name'],
            'gender'       => null,
            'phone_number' => '',
            'email'        => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);

        $personId = (int) $this->db->insertID();

        $this->db->table('employees')->insert([
            'username'      => $account['username'],
            'password'      => password_hash($initialPassword, PASSWORD_DEFAULT),
            'person_id'     => $personId,
            'deleted'       => 0,
            'hash_version'  => 2,
            'language'      => 'vietnamese',
            'language_code' => 'vi',
            'account_scope' => $account['account_scope'],
        ]);

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new RuntimeException('Failed to create fixed employee account: ' . $account['username']);
        }

        return $personId;
    }

    private function ensureGrants(int $personId, string $accountScope): void
    {
        $permissionIds = $accountScope === 'AGGREGATE'
            ? ['home']
            : array_column($this->db->table('permissions')->select('permission_id')->get()->getResultArray(), 'permission_id');

        foreach ($permissionIds as $permissionId) {
            if (!$this->permissionExists($permissionId) || $this->grantExists($personId, $permissionId)) {
                continue;
            }

            $this->db->table('grants')->insert([
                'permission_id' => $permissionId,
                'person_id'     => $personId,
                'menu_group'    => $this->getDefaultMenuGroup($permissionId),
            ]);
        }
    }

    private function permissionExists(string $permissionId): bool
    {
        return $this->db->table('permissions')
            ->where('permission_id', $permissionId)
            ->countAllResults() > 0;
    }

    private function grantExists(int $personId, string $permissionId): bool
    {
        return $this->db->table('grants')
            ->where('person_id', $personId)
            ->where('permission_id', $permissionId)
            ->countAllResults() > 0;
    }

    private function getDefaultMenuGroup(string $permissionId): string
    {
        $adminGrant = $this->db->table('grants')
            ->select('menu_group')
            ->where('person_id', 1)
            ->where('permission_id', $permissionId)
            ->get()
            ->getRowArray();

        return $adminGrant['menu_group'] ?? 'home';
    }
}
