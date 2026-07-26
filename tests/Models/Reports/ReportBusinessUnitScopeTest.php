<?php

namespace Tests\Models\Reports;

use App\Controllers\Reports;
use App\Database\Migrations\AddBusinessUnits;
use App\Database\Migrations\AddExpensesBusinessUnitScope;
use App\Database\Migrations\AddFixedEmployeeAccountScopes;
use App\Database\Migrations\AddSalesBusinessUnitScope;
use App\Database\Migrations\GrantAggregateReportAccess;
use App\Models\Reports\Detailed_sales;
use App\Models\Reports\Summary_expenses_categories;
use App\Models\Reports\Summary_payments;
use App\Models\Reports\Summary_sales;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use ReflectionClass;
use ReflectionMethod;

require_once APPPATH . 'Database/Migrations/20260724000000_AddFixedEmployeeAccountScopes.php';
require_once APPPATH . 'Database/Migrations/20260725000000_AddBusinessUnits.php';
require_once APPPATH . 'Database/Migrations/20260725000001_AddSalesBusinessUnitScope.php';
require_once APPPATH . 'Database/Migrations/20260726000000_AddExpensesBusinessUnitScope.php';
require_once APPPATH . 'Database/Migrations/20260726000001_GrantAggregateReportAccess.php';

class ReportBusinessUnitScopeTest extends CIUnitTestCase
{
    private const INITIAL_PASSWORD = 'TestOnlyPassword123!';
    private const TEST_PREFIX = 'REPORT_BU_SCOPE_TEST_';
    private const FIXED_ACCOUNTS = [
        'NguyenDuyTai',
        'NguyenDuyTai1',
        'NguyenDuyTai2',
    ];

    private string|false $previousInitialPassword;
    private int $itemId;
    private int $categoryId;
    private int $dayBusinessUnitId;
    private int $nightBusinessUnitId;
    private int $daySaleId;
    private int $nightSaleId;
    private int $nullSaleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousInitialPassword = getenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');

        $this->removeTestData();
        $this->removeFixedAccounts();

        putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . self::INITIAL_PASSWORD);

        (new AddFixedEmployeeAccountScopes())->up();
        (new GrantAggregateReportAccess())->up();
        (new AddBusinessUnits())->up();
        (new AddSalesBusinessUnitScope())->up();
        (new AddExpensesBusinessUnitScope())->up();

        $this->dayBusinessUnitId = $this->getBusinessUnitId('DAY');
        $this->nightBusinessUnitId = $this->getBusinessUnitId('NIGHT');
        $this->itemId = $this->createTestItem();
        $this->categoryId = $this->createTestExpenseCategory();

        $this->seedReportData();
    }

    protected function tearDown(): void
    {
        $this->dropReportTempTables();
        $this->removeTestData();
        Services::session()->destroy();

        if ($this->previousInitialPassword === false) {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');
        } else {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . $this->previousInitialPassword);
        }

        parent::tearDown();
    }

    public function testSummarySalesIsScopedForDayNightAggregateAndExcludesNull(): void
    {
        $day = $this->getSummarySalesReport($this->reportInputs([$this->dayBusinessUnitId]));
        $night = $this->getSummarySalesReport($this->reportInputs([$this->nightBusinessUnitId]));
        $aggregate = $this->getSummarySalesReport($this->reportInputs([$this->dayBusinessUnitId, $this->nightBusinessUnitId]));

        $this->assertSame(1, (int) $day['data'][0]['sales']);
        $this->assertEqualsWithDelta(20.00, (float) $day['summary']['subtotal'], 0.01);
        $this->assertEqualsWithDelta(6.00, (float) $day['summary']['cost'], 0.01);
        $this->assertEqualsWithDelta(14.00, (float) $day['summary']['profit'], 0.01);

        $this->assertSame(1, (int) $night['data'][0]['sales']);
        $this->assertEqualsWithDelta(21.00, (float) $night['summary']['subtotal'], 0.01);
        $this->assertEqualsWithDelta(6.00, (float) $night['summary']['cost'], 0.01);
        $this->assertEqualsWithDelta(15.00, (float) $night['summary']['profit'], 0.01);

        $this->assertSame(2, (int) $aggregate['data'][0]['sales']);
        $this->assertEqualsWithDelta(41.00, (float) $aggregate['summary']['subtotal'], 0.01);
        $this->assertEqualsWithDelta(12.00, (float) $aggregate['summary']['cost'], 0.01);
        $this->assertEqualsWithDelta(29.00, (float) $aggregate['summary']['profit'], 0.01);
    }

    public function testDetailedSalesListAndDirectSaleIdAreScoped(): void
    {
        $dayData = $this->getDetailedSalesReport($this->reportInputs([$this->dayBusinessUnitId]));
        $nightData = $this->getDetailedSalesReport($this->reportInputs([$this->nightBusinessUnitId]));
        $aggregateData = $this->getDetailedSalesReport($this->reportInputs([$this->dayBusinessUnitId, $this->nightBusinessUnitId]));

        $this->assertSame([$this->daySaleId], $this->saleIdsFromDetailedData($dayData));
        $this->assertSame([$this->nightSaleId], $this->saleIdsFromDetailedData($nightData));
        $this->assertSame([$this->daySaleId, $this->nightSaleId], $this->saleIdsFromDetailedData($aggregateData));

        $this->dropReportTempTables();
        $dayInputs = ['sale_id' => $this->nightSaleId, 'business_unit_ids' => [$this->dayBusinessUnitId]];
        $detailedSales = model(Detailed_sales::class);
        $detailedSales->create($dayInputs);
        $this->assertNull($detailedSales->getDataBySaleId($this->nightSaleId, $dayInputs));

        $this->dropReportTempTables();
        $aggregateInputs = ['sale_id' => $this->nullSaleId, 'business_unit_ids' => [$this->dayBusinessUnitId, $this->nightBusinessUnitId]];
        $detailedSales->create($aggregateInputs);
        $this->assertNull($detailedSales->getDataBySaleId($this->nullSaleId, $aggregateInputs));
    }

    public function testSummaryPaymentsIsScopedForDayNightAggregateAndExcludesNull(): void
    {
        $this->assertEqualsWithDelta(20.00, $this->paymentTotalFor($this->reportInputs([$this->dayBusinessUnitId])), 0.01);
        $this->assertEqualsWithDelta(21.00, $this->paymentTotalFor($this->reportInputs([$this->nightBusinessUnitId])), 0.01);
        $this->assertEqualsWithDelta(41.00, $this->paymentTotalFor($this->reportInputs([$this->dayBusinessUnitId, $this->nightBusinessUnitId])), 0.01);
    }

    public function testSummaryExpenseCategoriesIsScopedForDayNightAggregateAndExcludesNull(): void
    {
        $day = $this->getExpenseCategorySummary($this->expenseReportInputs([$this->dayBusinessUnitId]));
        $night = $this->getExpenseCategorySummary($this->expenseReportInputs([$this->nightBusinessUnitId]));
        $aggregate = $this->getExpenseCategorySummary($this->expenseReportInputs([$this->dayBusinessUnitId, $this->nightBusinessUnitId]));

        $this->assertSame(1, (int) $day['count']);
        $this->assertEqualsWithDelta(5.00, (float) $day['total_amount'], 0.01);
        $this->assertEqualsWithDelta(0.50, (float) $day['total_tax_amount'], 0.01);

        $this->assertSame(1, (int) $night['count']);
        $this->assertEqualsWithDelta(8.00, (float) $night['total_amount'], 0.01);
        $this->assertEqualsWithDelta(0.80, (float) $night['total_tax_amount'], 0.01);

        $this->assertSame(2, (int) $aggregate['count']);
        $this->assertEqualsWithDelta(13.00, (float) $aggregate['total_amount'], 0.01);
        $this->assertEqualsWithDelta(1.30, (float) $aggregate['total_tax_amount'], 0.01);
    }

    public function testControllerReportScopeIgnoresRequestSpoofing(): void
    {
        $this->loginAsUsername('NguyenDuyTai');
        $_GET['business_unit_ids'] = [(string) $this->nightBusinessUnitId];
        $_POST['business_unit_ids'] = [(string) $this->nightBusinessUnitId];

        try {
            $controller = $this->newReportsControllerWithAccountScope('DAY');
            $method = new ReflectionMethod($controller, 'withReportBusinessUnitScope');
            $method->setAccessible(true);
            $inputs = $method->invoke($controller, ['business_unit_ids' => [$this->nightBusinessUnitId]]);
        } finally {
            unset($_GET['business_unit_ids'], $_POST['business_unit_ids']);
        }

        $this->assertSame([$this->dayBusinessUnitId], $inputs['business_unit_ids']);
    }

    public function testAggregateCanOpenReportsController(): void
    {
        $this->loginAsUsername('NguyenDuyTai2');

        $controller = new Reports();

        $this->assertInstanceOf(Reports::class, $controller);
    }

    private function newReportsControllerWithAccountScope(string $accountScope): Reports
    {
        $reflection = new ReflectionClass(Reports::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $parentReflection = $reflection->getParentClass();
        $accountScopeProperty = $parentReflection->getProperty('accountScope');
        $accountScopeProperty->setAccessible(true);
        $accountScopeProperty->setValue($controller, $accountScope);

        return $controller;
    }

    private function getSummarySalesReport(array $inputs): array
    {
        $this->dropReportTempTables();
        $summarySales = model(Summary_sales::class);

        return [
            'data'    => $summarySales->getData($inputs),
            'summary' => $summarySales->getSummaryData($inputs),
        ];
    }

    private function getDetailedSalesReport(array $inputs): array
    {
        $this->dropReportTempTables();
        $detailedSales = model(Detailed_sales::class);
        $detailedSales->create($inputs);

        return $detailedSales->getData($inputs);
    }

    private function getExpenseCategorySummary(array $inputs): array
    {
        $summaryExpenses = model(Summary_expenses_categories::class);
        $data = $summaryExpenses->getData($inputs);
        $summary = $summaryExpenses->getSummaryData($inputs);

        $this->assertCount(1, $data);

        return $data[0] + $summary;
    }

    private function paymentTotalFor(array $inputs): float
    {
        $this->dropReportTempTables();
        $payments = model(Summary_payments::class)->getData($inputs);

        $total = 0.0;
        foreach ($payments as $payment) {
            if ($payment['trans_group'] === lang('Reports.trans_payments')) {
                $total += (float) $payment['trans_amount'];
            }
        }

        return $total;
    }

    private function saleIdsFromDetailedData(array $data): array
    {
        $saleIds = array_map('intval', array_column($data['summary'], 'sale_id'));
        sort($saleIds);

        return $saleIds;
    }

    private function reportInputs(array $businessUnitIds): array
    {
        return [
            'start_date'        => '2036-07-26',
            'end_date'          => '2036-07-26',
            'sale_type'         => 'complete',
            'location_id'       => 'all',
            'definition_ids'    => [],
            'business_unit_ids' => $businessUnitIds,
        ];
    }

    private function expenseReportInputs(array $businessUnitIds): array
    {
        return [
            'start_date'        => '2036-07-26',
            'end_date'          => '2036-07-26',
            'sale_type'         => 'complete',
            'business_unit_ids' => $businessUnitIds,
        ];
    }

    private function seedReportData(): void
    {
        $this->daySaleId = $this->createSale($this->getEmployeeId('NguyenDuyTai'), $this->dayBusinessUnitId, '2.000', '3.00', '10.00', '20.00');
        $this->nightSaleId = $this->createSale($this->getEmployeeId('NguyenDuyTai1'), $this->nightBusinessUnitId, '3.000', '2.00', '7.00', '21.00');
        $this->nullSaleId = $this->createSale($this->getEmployeeId('NguyenDuyTai2'), null, '1.000', '50.00', '100.00', '100.00');

        $this->createExpense($this->getEmployeeId('NguyenDuyTai'), $this->dayBusinessUnitId, '5.00', '0.50');
        $this->createExpense($this->getEmployeeId('NguyenDuyTai1'), $this->nightBusinessUnitId, '8.00', '0.80');
        $this->createExpense($this->getEmployeeId('NguyenDuyTai2'), null, '11.00', '1.10');
    }

    private function createSale(int $employeeId, ?int $businessUnitId, string $quantity, string $costPrice, string $unitPrice, string $paymentAmount): int
    {
        $db = db_connect();
        $invoiceNumber = self::TEST_PREFIX . 'SALE_' . bin2hex(random_bytes(8));

        $db->table('sales')->insert([
            'sale_time'        => '2036-07-26 10:00:00',
            'customer_id'      => null,
            'employee_id'      => $employeeId,
            'business_unit_id' => $businessUnitId,
            'comment'          => $invoiceNumber,
            'invoice_number'   => $invoiceNumber,
            'sale_status'      => COMPLETED,
            'sale_type'        => SALE_TYPE_POS,
        ]);
        $saleId = (int) $db->insertID();

        $db->table('sales_items')->insert([
            'sale_id'            => $saleId,
            'item_id'            => $this->itemId,
            'line'               => 0,
            'description'        => self::TEST_PREFIX . 'ITEM',
            'serialnumber'       => '',
            'quantity_purchased' => $quantity,
            'item_cost_price'    => $costPrice,
            'item_unit_price'    => $unitPrice,
            'discount'           => '0.00',
            'discount_type'      => PERCENT,
            'item_location'      => 1,
            'print_option'       => PRINT_ALL,
        ]);

        $db->table('sales_payments')->insert([
            'sale_id'         => $saleId,
            'payment_type'    => 'Cash',
            'payment_amount'  => $paymentAmount,
            'cash_refund'     => '0.00',
            'cash_adjustment' => CASH_ADJUSTMENT_FALSE,
            'employee_id'     => $employeeId,
            'reference_code'  => '',
        ]);

        return $saleId;
    }

    private function createExpense(int $employeeId, ?int $businessUnitId, string $amount, string $taxAmount): void
    {
        db_connect()->table('expenses')->insert([
            'date'                => '2036-07-26 10:00:00',
            'amount'              => $amount,
            'payment_type'        => 'Cash',
            'expense_category_id' => $this->categoryId,
            'description'         => self::TEST_PREFIX . uniqid('EXPENSE_', false),
            'employee_id'         => $employeeId,
            'business_unit_id'    => $businessUnitId,
            'deleted'             => 0,
            'supplier_tax_code'   => self::TEST_PREFIX . 'TAX',
            'tax_amount'          => $taxAmount,
            'supplier_id'         => null,
        ]);
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
            'unit_price'            => '2.00',
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

    private function createTestExpenseCategory(): int
    {
        db_connect()->table('expense_categories')->insert([
            'category_name'        => self::TEST_PREFIX . uniqid('CATEGORY_', false),
            'category_description' => self::TEST_PREFIX . 'CATEGORY',
            'deleted'              => 0,
        ]);

        return (int) db_connect()->insertID();
    }

    private function loginAsUsername(string $username): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', $this->getEmployeeId($username));
        $session->set('menu_group', 'home');
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

    private function removeTestData(): void
    {
        $db = db_connect();
        $saleIds = array_column(
            $db->table('sales')
                ->select('sale_id')
                ->groupStart()
                    ->like('comment', self::TEST_PREFIX, 'after')
                    ->orLike('invoice_number', self::TEST_PREFIX, 'after')
                ->groupEnd()
                ->get()
                ->getResultArray(),
            'sale_id'
        );

        if ($saleIds !== []) {
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

        if ($itemIds !== [] && $db->tableExists('business_unit_item_quantities')) {
            $db->table('business_unit_item_quantities')->whereIn('item_id', $itemIds)->delete();
        }

        if ($itemIds !== []) {
            $db->table('inventory')->whereIn('trans_items', $itemIds)->delete();
            $db->table('item_quantities')->whereIn('item_id', $itemIds)->delete();
        }

        $db->table('items')->like('item_number', self::TEST_PREFIX, 'after')->delete();
        $db->table('expenses')->like('description', self::TEST_PREFIX, 'after')->delete();
        $db->table('expense_categories')->like('category_name', self::TEST_PREFIX, 'after')->delete();
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

    private function dropReportTempTables(): void
    {
        $db = db_connect();
        $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable('sales_items_taxes_temp'));
        $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable('sales_payments_temp'));
        $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable('sales_items_temp'));
        $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable('sumpay_taxes_temp'));
        $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable('sumpay_items_temp'));
        $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable('sumpay_payments_temp'));
    }
}
