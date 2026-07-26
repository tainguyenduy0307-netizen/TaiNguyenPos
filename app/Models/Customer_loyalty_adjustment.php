<?php

namespace App\Models;

use CodeIgniter\Model;

class Customer_loyalty_adjustment extends Model
{
    protected $table = 'customer_loyalty_adjustments';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'customer_id',
        'points_delta',
        'resulting_points',
        'employee_id',
        'reason',
        'created_at',
    ];

    public function getTotalAdjustment(int $customerId): int
    {
        if (!$this->db->tableExists('customer_loyalty_adjustments')) {
            return 0;
        }

        return (int) ($this->db->table('customer_loyalty_adjustments')
            ->selectSum('points_delta', 'points_delta')
            ->where('customer_id', $customerId)
            ->get()
            ->getRow()
            ->points_delta ?? 0);
    }

    public function insertAdjustment(
        int $customerId,
        int $pointsDelta,
        int $resultingPoints,
        int $employeeId,
        ?string $reason = null
    ): bool {
        return $this->db->table('customer_loyalty_adjustments')->insert([
            'customer_id'       => $customerId,
            'points_delta'     => $pointsDelta,
            'resulting_points' => $resultingPoints,
            'employee_id'      => $employeeId,
            'reason'           => $reason === '' ? null : $reason,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
    }
}
