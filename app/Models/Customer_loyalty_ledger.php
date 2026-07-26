<?php

namespace App\Models;

use CodeIgniter\Model;
use Config\OSPOS;

class Customer_loyalty_ledger extends Model
{
    private const POINT_AMOUNT = 150000;

    protected $table = 'customer_loyalty_ledger';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'customer_id',
        'sale_id',
        'eligible_amount',
        'active',
        'created_at',
        'updated_at'
    ];

    public function syncSale(int $saleId, ?int $previousCustomerId = null): void
    {
        $sale = $this->getSale($saleId);
        $existingLedger = $this->getLedgerBySaleId($saleId);
        $affectedCustomerIds = [
            $previousCustomerId,
            $existingLedger === null ? null : (int) $existingLedger['customer_id'],
            $sale === null || $sale['customer_id'] === null ? null : (int) $sale['customer_id'],
        ];

        if ($sale !== null && $this->isSaleEligible($sale)) {
            $this->upsertActiveLedger($sale, $this->calculateEligibleAmount($saleId));
        } elseif ($existingLedger !== null) {
            $this->deactivateLedger((int) $existingLedger['id']);
        }

        $this->recalculateCustomers($affectedCustomerIds);
    }

    /**
     * @param array<int|null> $customerIds
     */
    public function recalculateCustomers(array $customerIds): void
    {
        $customerIds = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
        sort($customerIds);

        foreach ($customerIds as $customerId) {
            $this->lockCustomer($customerId);
            $totals = $this->getTotals($customerId);
            $points = (int) floor($totals['eligible_amount'] / self::POINT_AMOUNT);

            $this->db->table('customers')
                ->where('person_id', $customerId)
                ->update(['points' => max(0, $points)]);
        }
    }

    /**
     * @return array{eligible_amount: float, points: int, remainder_amount: float}
     */
    public function getTotals(int $customerId): array
    {
        $eligibleAmount = (float) ($this->db->table('customer_loyalty_ledger')
            ->selectSum('eligible_amount', 'eligible_amount')
            ->where('customer_id', $customerId)
            ->where('active', 1)
            ->get()
            ->getRow()
            ->eligible_amount ?? 0);

        return [
            'eligible_amount'  => $eligibleAmount,
            'points'           => (int) floor($eligibleAmount / self::POINT_AMOUNT),
            'remainder_amount' => fmod($eligibleAmount, self::POINT_AMOUNT),
        ];
    }

    private function isSaleEligible(array $sale): bool
    {
        return $sale['customer_id'] !== null
            && (int) $sale['sale_status'] === COMPLETED
            && in_array((int) $sale['sale_type'], [SALE_TYPE_POS, SALE_TYPE_INVOICE], true);
    }

    private function getSale(int $saleId): ?array
    {
        $sale = $this->db->table('sales')
            ->select('sale_id, customer_id, sale_status, sale_type')
            ->where('sale_id', $saleId)
            ->get()
            ->getRowArray();

        return $sale === null ? null : $sale;
    }

    private function getLedgerBySaleId(int $saleId): ?array
    {
        $ledger = $this->db->table('customer_loyalty_ledger')
            ->where('sale_id', $saleId)
            ->get()
            ->getRowArray();

        return $ledger === null ? null : $ledger;
    }

    private function upsertActiveLedger(array $sale, float $eligibleAmount): void
    {
        $now = date('Y-m-d H:i:s');
        $ledger = $this->getLedgerBySaleId((int) $sale['sale_id']);
        $data = [
            'customer_id'      => (int) $sale['customer_id'],
            'sale_id'          => (int) $sale['sale_id'],
            'eligible_amount'  => number_format($eligibleAmount, 2, '.', ''),
            'active'           => 1,
            'updated_at'       => $now,
        ];

        if ($ledger === null) {
            $data['created_at'] = $now;
            $this->db->table('customer_loyalty_ledger')->insert($data);
            return;
        }

        $this->db->table('customer_loyalty_ledger')
            ->where('id', (int) $ledger['id'])
            ->update($data);
    }

    private function deactivateLedger(int $ledgerId): void
    {
        $this->db->table('customer_loyalty_ledger')
            ->where('id', $ledgerId)
            ->update([
                'active'     => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private function calculateEligibleAmount(int $saleId): float
    {
        $config = config(OSPOS::class)->settings;
        $decimals = totals_decimals();

        $itemTotal = (float) ($this->db->table('sales_items')
            ->select(
                'ROUND(SUM(CASE WHEN discount_type = ' . PERCENT
                . ' THEN quantity_purchased * item_unit_price - ROUND(quantity_purchased * item_unit_price * discount / 100, ' . $decimals . ')'
                . ' ELSE quantity_purchased * (item_unit_price - discount) END), ' . $decimals . ') AS total',
                false
            )
            ->where('sale_id', $saleId)
            ->get()
            ->getRow()
            ->total ?? 0);

        $salesTax = 0.0;
        if (empty($config['tax_included'])) {
            $salesTax = (float) ($this->db->table('sales_items_taxes')
                ->select('SUM(ROUND(item_tax_amount, ' . $decimals . ')) AS tax', false)
                ->where('sale_id', $saleId)
                ->where('tax_type', 1)
                ->get()
                ->getRow()
                ->tax ?? 0);
        }

        $cashAdjustment = (float) ($this->db->table('sales_payments')
            ->selectSum('payment_amount', 'payment_amount')
            ->where('sale_id', $saleId)
            ->where('cash_adjustment', CASH_ADJUSTMENT_TRUE)
            ->get()
            ->getRow()
            ->payment_amount ?? 0);

        return max(0.0, round($itemTotal + $salesTax + $cashAdjustment, $decimals));
    }

    private function lockCustomer(int $customerId): void
    {
        $this->db->query(
            'SELECT person_id FROM `' . $this->db->prefixTable('customers') . '` WHERE person_id = ? FOR UPDATE',
            [$customerId]
        );
    }
}
