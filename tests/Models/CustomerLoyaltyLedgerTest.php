<?php

namespace Tests\Models;

use App\Database\Migrations\AddBusinessUnits;
use App\Database\Migrations\AddCustomerLoyaltyLedger;
use App\Database\Migrations\AddFixedEmployeeAccountScopes;
use App\Database\Migrations\AddSalesBusinessUnitScope;
use App\Models\Customer;
use App\Models\Customer_loyalty_ledger;
use App\Models\Sale;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use RuntimeException;

require_once APPPATH . 'Database/Migrations/20260724000000_AddFixedEmployeeAccountScopes.php';
require_once APPPATH . 'Database/Migrations/20260725000000_AddBusinessUnits.php';
require_once APPPATH . 'Database/Migrations/20260725000001_AddSalesBusinessUnitScope.php';
require_once APPPATH . 'Database/Migrations/20260726000002_AddCustomerLoyaltyLedger.php';

class CustomerLoyaltyLedgerTest extends CIUnitTestCase
{
    private const INITIAL_PASSWORD = 'TestOnlyPassword123!';
    private const TEST_PREFIX = 'CUSTOMER_LOYALTY_TEST_';
    private const FIXED_ACCOUNTS = [
        'NguyenDuyTai',
        'NguyenDuyTai1',
        'NguyenDuyTai2',
    ];

    private string|false $previousInitialPassword;
    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousInitialPassword = getenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');
        $this->removeTestData();
        $this->removeFixedAccounts();

        putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . self::INITIAL_PASSWORD);

        (new AddFixedEmployeeAccountScopes())->up();
        (new AddBusinessUnits())->up();
        (new AddSalesBusinessUnitScope())->up();
        (new AddCustomerLoyaltyLedger())->up();

        $this->itemId = $this->createTestItem();
    }

    protected function tearDown(): void
    {
        $this->dropCustomerStatsTempTable();
        $this->removeTestData();
        $this->removeFixedAccounts();
        Services::session()->destroy();

        if ($this->previousInitialPassword === false) {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');
        } else {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . $this->previousInitialPassword);
        }

        parent::tearDown();
    }

    public function testCumulativeEligibleAmountEarnsPointsAcrossInvoices(): void
    {
        $customerId = $this->createCustomer();
        $this->loginAsUsername('NguyenDuyTai');

        $this->saveSale($customerId, '100000.00');
        $this->assertLoyaltyTotals($customerId, 100000.00, 0, 100000.00);

        $this->saveSale($customerId, '100000.00');
        $this->assertLoyaltyTotals($customerId, 200000.00, 1, 50000.00);

        $this->saveSale($customerId, '250000.00');
        $this->assertLoyaltyTotals($customerId, 450000.00, 3, 0.00);
    }

    public function testDayAndNightShareCustomerLoyaltyBalance(): void
    {
        $customerId = $this->createCustomer();

        $this->loginAsUsername('NguyenDuyTai');
        $this->saveSale($customerId, '100000.00');

        $this->loginAsUsername('NguyenDuyTai1');
        $this->saveSale($customerId, '100000.00');

        $this->assertLoyaltyTotals($customerId, 200000.00, 1, 50000.00);
    }

    public function testSaveRetryForSameSaleDoesNotDuplicateLedger(): void
    {
        $customerId = $this->createCustomer();
        $this->loginAsUsername('NguyenDuyTai');

        $saleId = $this->saveSale($customerId, '100000.00');
        $this->saveSale($customerId, '100000.00', COMPLETED, SALE_TYPE_POS, $saleId);

        $this->assertSame(1, $this->ledgerCountForSale($saleId));
        $this->assertLoyaltyTotals($customerId, 100000.00, 0, 100000.00);
    }

    public function testOnlyCompletedPosAndInvoiceSalesWithCustomerAreEligible(): void
    {
        $customerId = $this->createCustomer();
        $this->loginAsUsername('NguyenDuyTai');

        $this->saveSale($customerId, '150000.00', SUSPENDED, SALE_TYPE_POS);
        $this->saveSale($customerId, '150000.00', SUSPENDED, SALE_TYPE_QUOTE);
        $this->saveSale($customerId, '150000.00', SUSPENDED, SALE_TYPE_WORK_ORDER);
        $this->saveSale(NEW_ENTRY, '150000.00', COMPLETED, SALE_TYPE_POS);
        $this->assertLoyaltyTotals($customerId, 0.00, 0, 0.00);

        $this->saveSale($customerId, '149999.00', COMPLETED, SALE_TYPE_INVOICE);
        $this->assertLoyaltyTotals($customerId, 149999.00, 0, 149999.00);

        $this->saveSale($customerId, '1.00', COMPLETED, SALE_TYPE_POS);
        $this->assertLoyaltyTotals($customerId, 150000.00, 1, 0.00);
    }

    public function testEditSaleRecalculatesDeltaAndCustomerChanges(): void
    {
        $firstCustomerId = $this->createCustomer();
        $secondCustomerId = $this->createCustomer();
        $this->loginAsUsername('NguyenDuyTai');

        $saleId = $this->saveSale($firstCustomerId, '100000.00');
        $this->saveSale($firstCustomerId, '200000.00', COMPLETED, SALE_TYPE_POS, $saleId);
        $this->assertLoyaltyTotals($firstCustomerId, 200000.00, 1, 50000.00);

        $this->saveSale($secondCustomerId, '200000.00', COMPLETED, SALE_TYPE_POS, $saleId);
        $this->assertLoyaltyTotals($firstCustomerId, 0.00, 0, 0.00);
        $this->assertLoyaltyTotals($secondCustomerId, 200000.00, 1, 50000.00);
    }

    public function testCancelSaleReversesLedgerWithoutNegativePoints(): void
    {
        $customerId = $this->createCustomer();
        $this->loginAsUsername('NguyenDuyTai');

        $firstSaleId = $this->saveSale($customerId, '100000.00');
        $this->saveSale($customerId, '250000.00');
        $this->assertLoyaltyTotals($customerId, 350000.00, 2, 50000.00);

        $this->assertTrue(model(Sale::class)->delete($firstSaleId, false, false, $this->getLoggedInEmployeeId()));
        $this->assertLoyaltyTotals($customerId, 250000.00, 1, 100000.00);

        $this->assertTrue(model(Sale::class)->delete($firstSaleId, false, false, $this->getLoggedInEmployeeId()));
        $this->assertLoyaltyTotals($customerId, 250000.00, 1, 100000.00);
    }

    public function testRewardPaymentsAreRejectedByBackend(): void
    {
        $customerId = $this->createCustomer();
        $this->loginAsUsername('NguyenDuyTai');

        $this->assertArrayNotHasKey(lang('Sales.rewards'), model(Sale::class)->get_payment_options());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reward points payments are disabled.');

        $this->saveSale($customerId, '150000.00', COMPLETED, SALE_TYPE_POS, NEW_ENTRY, [
            lang('Sales.rewards') => [
                'payment_type'    => lang('Sales.rewards'),
                'payment_amount'  => '150000.00',
                'cash_refund'     => '0.00',
                'cash_adjustment' => CASH_ADJUSTMENT_FALSE,
                'reference_code'  => null,
            ],
        ]);
    }

    public function testCustomerStatsRemainGlobalAcrossDayAndNight(): void
    {
        $customerId = $this->createCustomer();

        $this->loginAsUsername('NguyenDuyTai');
        $this->saveSale($customerId, '100000.00');

        $this->loginAsUsername('NguyenDuyTai1');
        $this->saveSale($customerId, '100000.00');

        $stats = model(Customer::class)->get_stats($customerId);

        $this->assertNotNull($stats);
        $this->assertEqualsWithDelta(200000.00, (float) $stats->total, 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $stats->quantity, 0.01);
    }

    private function saveSale(
        int $customerId,
        string $amount,
        int $saleStatus = COMPLETED,
        int $saleType = SALE_TYPE_POS,
        int $saleId = NEW_ENTRY,
        ?array $payments = null
    ): int {
        $status = (string) $saleStatus;
        $items = [
            [
                'item_id'       => $this->itemId,
                'line'          => 0,
                'description'   => self::TEST_PREFIX . 'ITEM',
                'serialnumber'  => '',
                'quantity'      => '1',
                'discount'      => '0',
                'discount_type' => PERCENT,
                'cost_price'    => '1.00',
                'price'         => $amount,
                'item_location' => 1,
                'print_option'  => PRINT_ALL,
            ],
        ];
        $payments ??= [
            lang('Sales.cash') => [
                'payment_type'    => lang('Sales.cash'),
                'payment_amount'  => $amount,
                'cash_refund'     => '0.00',
                'cash_adjustment' => CASH_ADJUSTMENT_FALSE,
                'reference_code'  => null,
            ],
        ];
        $taxes = [[], []];

        $saleId = model(Sale::class)->save_value(
            $saleId,
            $status,
            $items,
            $customerId,
            $this->getLoggedInEmployeeId(),
            self::TEST_PREFIX . bin2hex(random_bytes(8)),
            self::TEST_PREFIX . bin2hex(random_bytes(8)),
            null,
            null,
            $saleType,
            $payments,
            null,
            $taxes
        );

        $this->assertGreaterThan(0, $saleId);

        return $saleId;
    }

    private function assertLoyaltyTotals(int $customerId, float $eligibleAmount, int $points, float $remainderAmount): void
    {
        $totals = model(Customer_loyalty_ledger::class)->getTotals($customerId);

        $this->assertEqualsWithDelta($eligibleAmount, $totals['eligible_amount'], 0.01);
        $this->assertSame($points, $totals['points']);
        $this->assertEqualsWithDelta($remainderAmount, $totals['remainder_amount'], 0.01);
        $this->assertSame($points, $this->getCustomerPoints($customerId));
    }

    private function createCustomer(): int
    {
        $db = db_connect();
        $name = self::TEST_PREFIX . uniqid('CUSTOMER_', false);
        $db->table('people')->insert([
            'first_name'   => $name,
            'last_name'    => 'Customer',
            'gender'       => null,
            'phone_number' => '',
            'email'        => $name . '@example.test',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);
        $personId = (int) $db->insertID();

        $db->table('customers')->insert([
            'person_id'    => $personId,
            'company_name' => null,
            'account_number' => null,
            'taxable'      => 0,
            'discount'     => '0.00',
            'discount_type'=> PERCENT,
            'package_id'   => null,
            'points'       => 0,
            'date'         => date('Y-m-d H:i:s'),
            'employee_id'  => $this->getEmployeeId('NguyenDuyTai'),
            'consent'      => 1,
            'deleted'      => 0,
        ]);

        return $personId;
    }

    private function createTestItem(): int
    {
        $itemNumber = self::TEST_PREFIX . uniqid('ITEM_', false);
        db_connect()->table('items')->insert([
            'name'                  => $itemNumber,
            'category'              => self::TEST_PREFIX . 'CATEGORY',
            'supplier_id'           => null,
            'item_number'           => $itemNumber,
            'description'           => self::TEST_PREFIX . 'ITEM',
            'cost_price'            => '1.00',
            'unit_price'            => '1.00',
            'reorder_level'         => '0.000',
            'receiving_quantity'    => '1.000',
            'allow_alt_description' => 0,
            'is_serialized'         => 0,
            'deleted'               => 0,
            'stock_type'            => HAS_NO_STOCK,
            'item_type'             => ITEM,
            'tax_category_id'       => null,
            'qty_per_pack'          => '1.000',
            'pack_name'             => 'Each',
            'low_sell_item_id'      => 0,
            'hsn_code'              => '',
        ]);

        return (int) db_connect()->insertID();
    }

    private function ledgerCountForSale(int $saleId): int
    {
        return db_connect()->table('customer_loyalty_ledger')
            ->where('sale_id', $saleId)
            ->countAllResults();
    }

    private function getCustomerPoints(int $customerId): int
    {
        return (int) db_connect()->table('customers')
            ->select('points')
            ->where('person_id', $customerId)
            ->get()
            ->getRow()
            ->points;
    }

    private function loginAsUsername(string $username): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', $this->getEmployeeId($username));
    }

    private function getLoggedInEmployeeId(): int
    {
        return (int) Services::session()->get('person_id');
    }

    private function getEmployeeId(string $username): int
    {
        return (int) db_connect()
            ->table('employees')
            ->select('person_id')
            ->where('username', $username)
            ->get()
            ->getRow()
            ->person_id;
    }

    private function removeTestData(): void
    {
        $db = db_connect();
        $saleIds = array_column(
            $db->table('sales')
                ->select('sale_id')
                ->like('comment', self::TEST_PREFIX, 'after')
                ->get()
                ->getResultArray(),
            'sale_id'
        );

        if ($saleIds !== []) {
            if ($db->tableExists('customer_loyalty_ledger')) {
                $db->table('customer_loyalty_ledger')->whereIn('sale_id', $saleIds)->delete();
            }
            $db->table('sales_payments')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales_items_taxes')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales_taxes')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales_items')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales')->whereIn('sale_id', $saleIds)->delete();
        }

        $itemIds = array_column(
            $db->table('items')
                ->select('item_id')
                ->like('item_number', self::TEST_PREFIX, 'after')
                ->get()
                ->getResultArray(),
            'item_id'
        );

        if ($itemIds !== []) {
            $db->table('inventory')->whereIn('trans_items', $itemIds)->delete();
            $db->table('item_quantities')->whereIn('item_id', $itemIds)->delete();
            $db->table('items')->whereIn('item_id', $itemIds)->delete();
        }

        $personIds = array_column(
            $db->table('people')
                ->select('person_id')
                ->like('first_name', self::TEST_PREFIX, 'after')
                ->get()
                ->getResultArray(),
            'person_id'
        );

        if ($personIds !== []) {
            if ($db->tableExists('customer_loyalty_ledger')) {
                $db->table('customer_loyalty_ledger')->whereIn('customer_id', $personIds)->delete();
            }
            $db->table('customers')->whereIn('person_id', $personIds)->delete();
            $db->table('people')->whereIn('person_id', $personIds)->delete();
        }
    }

    private function removeFixedAccounts(): void
    {
        $db = db_connect();
        $personIds = array_column(
            $db->table('employees')
                ->select('person_id')
                ->whereIn('username', self::FIXED_ACCOUNTS)
                ->get()
                ->getResultArray(),
            'person_id'
        );

        if ($personIds === []) {
            return;
        }

        $db->table('grants')->whereIn('person_id', $personIds)->delete();
        $db->table('employees')->whereIn('person_id', $personIds)->delete();
        $db->table('people')->whereIn('person_id', $personIds)->delete();
    }

    private function dropCustomerStatsTempTable(): void
    {
        db_connect()->query('DROP TEMPORARY TABLE IF EXISTS ' . db_connect()->prefixTable('sales_items_temp'));
    }
}
