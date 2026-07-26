<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class GrantAggregateReportAccess extends Migration
{
    private const AGGREGATE_USERNAME = 'NguyenDuyTai2';

    private const ALLOWED_GRANTS = [
        'home',
        'reports',
        'reports_sales',
        'reports_payments',
        'reports_expenses_categories',
    ];

    public function up(): void
    {
        $aggregate = $this->db->table('employees')
            ->select('person_id, account_scope, deleted')
            ->where('username', self::AGGREGATE_USERNAME)
            ->get()
            ->getRowArray();

        if ($aggregate === null) {
            return;
        }

        if ($aggregate['account_scope'] !== 'AGGREGATE' || (int) $aggregate['deleted'] !== 0) {
            throw new RuntimeException('Fixed aggregate employee account conflict for username: ' . self::AGGREGATE_USERNAME);
        }

        $personId = (int) $aggregate['person_id'];

        $this->removeDisallowedGrants($personId);
        $this->ensureAllowedGrants($personId);
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback is unsafe because aggregate report access may already be in use.');
    }

    private function removeDisallowedGrants(int $personId): void
    {
        $this->db->table('grants')
            ->where('person_id', $personId)
            ->whereNotIn('permission_id', self::ALLOWED_GRANTS)
            ->delete();
    }

    private function ensureAllowedGrants(int $personId): void
    {
        foreach (self::ALLOWED_GRANTS as $permissionId) {
            if (!$this->permissionExists($permissionId)) {
                continue;
            }

            $menuGroup = in_array($permissionId, ['home', 'reports'], true) ? 'home' : 'office';

            if ($this->grantExists($personId, $permissionId)) {
                $this->db->table('grants')
                    ->where('person_id', $personId)
                    ->where('permission_id', $permissionId)
                    ->update(['menu_group' => $menuGroup]);

                continue;
            }

            $this->db->table('grants')->insert([
                'permission_id' => $permissionId,
                'person_id'     => $personId,
                'menu_group'    => $menuGroup,
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
}
