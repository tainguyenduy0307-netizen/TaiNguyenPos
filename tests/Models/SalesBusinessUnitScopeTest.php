<?php

namespace Tests\Models;

use App\Database\Migrations\AddBusinessUnits;
use App\Database\Migrations\AddBusinessUnitInventoryQuantities;
use App\Database\Migrations\AddFixedEmployeeAccountScopes;
use App\Database\Migrations\AddReceivingsBusinessUnitScope;
use App\Database\Migrations\AddSalesBusinessUnitScope;
use App\Libraries\Sale_lib;
use App\Models\Sale;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use RuntimeException;

require_once APPPATH . 'Database/Migrations/20260724000000_AddFixedEmployeeAccountScopes.php';
require_once APPPATH . 'Database/Migrations/20260725000000_AddBusinessUnits.php';
require_once APPPATH . 'Database/Migrations/20260725000001_AddSalesBusinessUnitScope.php';
require_once APPPATH . 'Database/Migrations/20260725000002_AddReceivingsBusinessUnitScope.php';
require_once APPPATH . 'Database/Migrations/20260725000003_AddBusinessUnitInventoryQuantities.php';

class SalesBusinessUnitScopeTest extends CIUnitTestCase
{
    private const INITIAL_PASSWORD = 'TestOnlyPassword123!';
    private const TEST_PREFIX = 'BU_SCOPE_TEST_';
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

        $this->removeTestSales();
        $this->removeFixedAccounts();

        putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . self::INITIAL_PASSWORD);

        (new AddFixedEmployeeAccountScopes())->up();
        (new AddBusinessUnits())->up();
        (new AddSalesBusinessUnitScope())->up();
        (new AddReceivingsBusinessUnitScope())->up();
        (new AddBusinessUnitInventoryQuantities())->up();

        $this->dropSaleTempTables();
        $this->itemId = $this->createTestItem();
    }

    protected function tearDown(): void
    {
        $this->dropSaleTempTables();
        $this->removeTestSales();
        $this->removeTestItems();
        Services::session()->destroy();

        if ($this->previousInitialPassword === false) {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');
        } else {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . $this->previousInitialPassword);
        }

        parent::tearDown();
    }

    public function testMigrationAddsSalesBusinessUnitColumnAndBackfillsKnownEmployees(): void
    {
        $daySaleId = $this->createSale($this->getEmployeeId('NguyenDuyTai'), null, self::TEST_PREFIX . 'BACKFILL_DAY');
        $nightSaleId = $this->createSale($this->getEmployeeId('NguyenDuyTai1'), null, self::TEST_PREFIX . 'BACKFILL_NIGHT');
        $ambiguousSaleId = $this->createSale(1, null, self::TEST_PREFIX . 'BACKFILL_NULL');

        (new AddSalesBusinessUnitScope())->up();

        $this->assertTrue(db_connect()->fieldExists('business_unit_id', 'sales'));
        $this->assertSame($this->getBusinessUnitId('DAY'), $this->getSaleBusinessUnitId($daySaleId));
        $this->assertSame($this->getBusinessUnitId('NIGHT'), $this->getSaleBusinessUnitId($nightSaleId));
        $this->assertNull($this->getSaleBusinessUnitId($ambiguousSaleId));
    }

    public function testMigrationIsIdempotentForIndexesAndForeignKey(): void
    {
        $before = $this->getSalesScopeMetadataCounts();

        (new AddSalesBusinessUnitScope())->up();

        $this->assertSame($before, $this->getSalesScopeMetadataCounts());
    }

    public function testSaveValueAssignsCurrentBusinessUnitForDayAndNight(): void
    {
        $this->loginAsUsername('NguyenDuyTai');
        $daySaleId = $this->saveSaleThroughModel();

        $this->loginAsUsername('NguyenDuyTai1');
        $nightSaleId = $this->saveSaleThroughModel();

        $this->assertSame($this->getBusinessUnitId('DAY'), $this->getSaleBusinessUnitId($daySaleId));
        $this->assertSame($this->getBusinessUnitId('NIGHT'), $this->getSaleBusinessUnitId($nightSaleId));
    }

    public function testAggregateCannotSaveOperationalSale(): void
    {
        $this->loginAsUsername('NguyenDuyTai2');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An operational business unit is required for this action.');

        $this->saveSaleThroughModel();
    }

    public function testInputCannotSpoofBusinessUnitOnCreateOrUpdate(): void
    {
        $this->loginAsUsername('NguyenDuyTai');
        Services::session()->set('business_unit_id', $this->getBusinessUnitId('NIGHT'));
        $_POST['business_unit_id'] = (string) $this->getBusinessUnitId('NIGHT');

        try {
            $saleId = $this->saveSaleThroughModel(['business_unit_id' => $this->getBusinessUnitId('NIGHT')]);
            $updated = model(Sale::class)->update($saleId, [
                'comment'          => self::TEST_PREFIX . 'SPOOF_UPDATE',
                'business_unit_id' => $this->getBusinessUnitId('NIGHT'),
            ]);
        } finally {
            unset($_POST['business_unit_id']);
        }

        $this->assertTrue($updated);
        $this->assertSame($this->getBusinessUnitId('DAY'), $this->getSaleBusinessUnitId($saleId));
    }

    public function testDayAndNightCannotReadEachOtherSalesOrNullScopedSales(): void
    {
        $daySaleId = $this->createSale($this->getEmployeeId('NguyenDuyTai'), $this->getBusinessUnitId('DAY'), self::TEST_PREFIX . 'READ_DAY');
        $nightSaleId = $this->createSale($this->getEmployeeId('NguyenDuyTai1'), $this->getBusinessUnitId('NIGHT'), self::TEST_PREFIX . 'READ_NIGHT');
        $nullSaleId = $this->createSale(1, null, self::TEST_PREFIX . 'READ_NULL');

        $sale = model(Sale::class);
        $this->loginAsUsername('NguyenDuyTai');

        $this->assertSame(1, $sale->get_info($daySaleId)->getNumRows());
        $this->assertSame(0, $sale->get_info($nightSaleId)->getNumRows());
        $this->assertSame(0, $sale->get_info($nullSaleId)->getNumRows());
        $this->assertSame(0, $sale->get_sale_items_ordered($nightSaleId)->getNumRows());
        $this->assertSame(0, $sale->get_sale_payments($nightSaleId)->getNumRows());
        $this->assertSame([], $sale->get_sales_taxes($nightSaleId));

        $dayReceipt = 'POS ' . $daySaleId;
        $nightReceipt = 'POS ' . $nightSaleId;
        $this->assertTrue($sale->isValidReceipt($dayReceipt));
        $this->assertFalse($sale->isValidReceipt($nightReceipt));
        $this->assertSame(0, $sale->get_sale_by_invoice_number(self::TEST_PREFIX . 'READ_NIGHT')->getNumRows());

        $this->loginAsUsername('NguyenDuyTai1');

        $this->assertSame(0, $sale->get_info($daySaleId)->getNumRows());
        $this->assertSame(1, $sale->get_info($nightSaleId)->getNumRows());
    }

    public function testSuspendedSalesAndCartCopyAreScoped(): void
    {
        $daySaleId = $this->createSale($this->getEmployeeId('NguyenDuyTai'), $this->getBusinessUnitId('DAY'), self::TEST_PREFIX . 'SUSP_DAY', SUSPENDED);
        $nightSaleId = $this->createSale($this->getEmployeeId('NguyenDuyTai1'), $this->getBusinessUnitId('NIGHT'), self::TEST_PREFIX . 'SUSP_NIGHT', SUSPENDED);

        $this->loginAsUsername('NguyenDuyTai');
        $sale = model(Sale::class);

        $suspendedSaleIds = array_map('intval', array_column($sale->get_all_suspended(NEW_ENTRY), 'sale_id'));

        $this->assertContains($daySaleId, $suspendedSaleIds);
        $this->assertNotContains($nightSaleId, $suspendedSaleIds);

        Services::session()->set('sale_id', NEW_ENTRY);
        $saleLib = new Sale_lib();
        $saleLib->copy_entire_sale($nightSaleId);
        $this->assertSame(NEW_ENTRY, $saleLib->get_sale_id());

        $saleLib->copy_entire_sale($daySaleId);
        $this->assertSame($daySaleId, $saleLib->get_sale_id());
    }

    public function testScopedWriteOperationsCannotAffectAnotherBusinessUnit(): void
    {
        $daySaleId = $this->createSale($this->getEmployeeId('NguyenDuyTai'), $this->getBusinessUnitId('DAY'), self::TEST_PREFIX . 'WRITE_DAY');
        $nightSaleId = $this->createSale($this->getEmployeeId('NguyenDuyTai1'), $this->getBusinessUnitId('NIGHT'), self::TEST_PREFIX . 'WRITE_NIGHT');
        $sale = model(Sale::class);

        $this->loginAsUsername('NguyenDuyTai');

        $this->assertFalse($sale->update($nightSaleId, ['comment' => self::TEST_PREFIX . 'CROSS_UPDATE']));
        $this->assertFalse($sale->delete_list([$nightSaleId], $this->getEmployeeId('NguyenDuyTai'), false));
        $this->assertFalse($sale->restore_list([$nightSaleId], $this->getEmployeeId('NguyenDuyTai'), false));
        $this->assertFalse($sale->update_sale_status($nightSaleId, CANCELED));
        $this->assertFalse($sale->delete_suspended_sale($nightSaleId));

        $this->assertSame(self::TEST_PREFIX . 'WRITE_NIGHT', $this->getSaleComment($nightSaleId));
        $this->assertSame(COMPLETED, $this->getSaleStatus($nightSaleId));
        $this->assertSame($this->getBusinessUnitId('NIGHT'), $this->getSaleBusinessUnitId($nightSaleId));

        $this->assertTrue($sale->update($daySaleId, ['comment' => self::TEST_PREFIX . 'OWN_UPDATE']));
        $this->assertSame(self::TEST_PREFIX . 'OWN_UPDATE', $this->getSaleComment($daySaleId));
    }

    public function testSearchFoundRowsAndPaymentSummaryAreScoped(): void
    {
        $this->createSale($this->getEmployeeId('NguyenDuyTai'), $this->getBusinessUnitId('DAY'), self::TEST_PREFIX . 'SEARCH_DAY', COMPLETED, '10.00');
        $this->createSale($this->getEmployeeId('NguyenDuyTai1'), $this->getBusinessUnitId('NIGHT'), self::TEST_PREFIX . 'SEARCH_NIGHT', COMPLETED, '20.00');

        $this->loginAsUsername('NguyenDuyTai');
        $sale = model(Sale::class);
        $filters = $this->salesSearchFilters();

        $this->assertSame(1, $sale->search(null, $filters)->getNumRows());
        $this->assertSame(1, $sale->get_found_rows(null, $filters));

        $summary = $sale->get_payments_summary(null, $filters);

        $this->assertCount(1, $summary);
        $this->assertSame('Cash', $summary[0]['payment_type']);
        $this->assertEquals(10.00, (float) $summary[0]['payment_amount']);
    }

    private function saveSaleThroughModel(array $extraItemData = []): int
    {
        $saleStatus = (string) COMPLETED;
        $items = [
            0 => array_merge([
                'item_id'      => $this->itemId,
                'line'         => 0,
                'description'  => self::TEST_PREFIX . 'ITEM',
                'serialnumber' => '',
                'quantity'     => '1',
                'discount'     => '0',
                'discount_type'=> PERCENT,
                'cost_price'   => '1.00',
                'price'        => '2.00',
                'item_location'=> 1,
                'print_option' => PRINT_ALL,
            ], $extraItemData),
        ];
        $payments = [];
        $taxes = [[], []];

        return model(Sale::class)->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $this->getLoggedInEmployeeId(),
            self::TEST_PREFIX . 'SAVE_VALUE',
            self::TEST_PREFIX . uniqid('INV_', false),
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $taxes
        );
    }

    private function createSale(int $employeeId, ?int $businessUnitId, string $invoiceNumber, int $status = COMPLETED, string $paymentAmount = '0.00'): int
    {
        $db = db_connect();
        $db->table('sales')->insert([
            'sale_time'        => '2030-07-25 10:00:00',
            'customer_id'      => null,
            'employee_id'      => $employeeId,
            'business_unit_id' => $businessUnitId,
            'comment'          => $invoiceNumber,
            'invoice_number'   => $invoiceNumber,
            'sale_status'      => $status,
            'sale_type'        => SALE_TYPE_POS,
        ]);
        $saleId = (int) $db->insertID();

        $db->table('sales_items')->insert([
            'sale_id'            => $saleId,
            'item_id'            => $this->itemId,
            'line'               => 0,
            'description'        => self::TEST_PREFIX . 'ITEM',
            'serialnumber'       => '',
            'quantity_purchased' => '1.000',
            'item_cost_price'    => '1.00',
            'item_unit_price'    => '2.00',
            'discount'           => '0.00',
            'discount_type'      => PERCENT,
            'item_location'      => 1,
            'print_option'       => PRINT_ALL,
        ]);

        if ($paymentAmount !== '0.00') {
            $db->table('sales_payments')->insert([
                'sale_id'         => $saleId,
                'payment_type'    => 'Cash',
                'payment_amount'  => $paymentAmount,
                'cash_refund'     => '0.00',
                'cash_adjustment' => CASH_ADJUSTMENT_FALSE,
                'employee_id'     => $employeeId,
                'reference_code'  => '',
            ]);
        }

        return $saleId;
    }

    private function createTestItem(): int
    {
        $itemNumber = self::TEST_PREFIX . uniqid('ITEM_', false);
        db_connect()->table('items')->insert([
            'name'                 => $itemNumber,
            'category'             => self::TEST_PREFIX . 'CATEGORY',
            'supplier_id'          => null,
            'item_number'          => $itemNumber,
            'description'          => self::TEST_PREFIX . 'ITEM',
            'cost_price'           => '1.00',
            'unit_price'           => '2.00',
            'reorder_level'        => '0.000',
            'receiving_quantity'   => '1.000',
            'allow_alt_description'=> 0,
            'is_serialized'        => 0,
            'deleted'              => 0,
            'stock_type'           => HAS_NO_STOCK,
            'item_type'            => ITEM,
            'tax_category_id'      => null,
            'qty_per_pack'         => '1.000',
            'pack_name'            => 'Each',
            'low_sell_item_id'     => 0,
            'hsn_code'             => '',
        ]);

        return (int) db_connect()->insertID();
    }

    private function salesSearchFilters(): array
    {
        return [
            'sale_type'         => 'all',
            'location_id'       => 'all',
            'start_date'        => '2030-07-25',
            'end_date'          => '2030-07-25',
            'only_cash'         => false,
            'only_due'          => false,
            'only_check'        => false,
            'selected_customer' => false,
            'only_creditcard'   => false,
            'only_debit'        => false,
            'only_bank_transfer'=> false,
            'only_wallet'       => false,
            'only_invoices'     => false,
            'is_valid_receipt'  => false,
        ];
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

    private function getBusinessUnitId(string $code): int
    {
        return (int) db_connect()
            ->table('business_units')
            ->select('id')
            ->where('code', $code)
            ->get()
            ->getRow()
            ->id;
    }

    private function getSaleBusinessUnitId(int $saleId): ?int
    {
        $businessUnitId = db_connect()
            ->table('sales')
            ->select('business_unit_id')
            ->where('sale_id', $saleId)
            ->get()
            ->getRow()
            ->business_unit_id;

        return $businessUnitId === null ? null : (int) $businessUnitId;
    }

    private function getSaleComment(int $saleId): string
    {
        return db_connect()
            ->table('sales')
            ->select('comment')
            ->where('sale_id', $saleId)
            ->get()
            ->getRow()
            ->comment;
    }

    private function getSaleStatus(int $saleId): int
    {
        return (int) db_connect()
            ->table('sales')
            ->select('sale_status')
            ->where('sale_id', $saleId)
            ->get()
            ->getRow()
            ->sale_status;
    }

    /**
     * @return array<string, int>
     */
    private function getSalesScopeMetadataCounts(): array
    {
        $db = db_connect();

        return [
            'sale_time_index' => (int) $db->query(
                'SELECT COUNT(*) AS count FROM information_schema.statistics'
                . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$db->prefixTable('sales'), 'sales_business_unit_sale_time']
            )->getRow()->count,
            'status_index'    => (int) $db->query(
                'SELECT COUNT(*) AS count FROM information_schema.statistics'
                . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$db->prefixTable('sales'), 'sales_business_unit_status']
            )->getRow()->count,
            'foreign_key'     => (int) $db->query(
                'SELECT COUNT(*) AS count FROM information_schema.table_constraints'
                . ' WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?',
                [$db->prefixTable('sales'), 'ospos_sales_business_unit_id_fk']
            )->getRow()->count,
        ];
    }

    private function removeTestSales(): void
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

        if ($saleIds === []) {
            return;
        }

        $db->table('sales_payments')->whereIn('sale_id', $saleIds)->delete();
        $db->table('sales_items_taxes')->whereIn('sale_id', $saleIds)->delete();
        $db->table('sales_taxes')->whereIn('sale_id', $saleIds)->delete();
        $db->table('sales_items')->whereIn('sale_id', $saleIds)->delete();
        $db->table('sales')->whereIn('sale_id', $saleIds)->delete();
    }

    private function removeTestItems(): void
    {
        $db = db_connect();
        $itemIds = array_column(
            $db->table('items')
                ->select('item_id')
                ->like('item_number', self::TEST_PREFIX, 'after')
                ->get()
                ->getResultArray(),
            'item_id'
        );

        if ($itemIds !== [] && $db->tableExists('business_unit_item_quantities')) {
            $db->table('business_unit_item_quantities')->whereIn('item_id', $itemIds)->delete();
        }

        if ($itemIds !== []) {
            $db->table('inventory')->whereIn('trans_items', $itemIds)->delete();
            $db->table('item_quantities')->whereIn('item_id', $itemIds)->delete();
        }

        $db->table('items')
            ->like('item_number', self::TEST_PREFIX, 'after')
            ->delete();
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

    private function dropSaleTempTables(): void
    {
        $db = db_connect();
        $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable('sales_items_taxes_temp'));
        $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable('sales_payments_temp'));
        $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable('sales_items_temp'));
    }
}
