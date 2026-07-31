<?php

namespace Tests\Models;

use App\Controllers\Sales as SalesController;
use App\Controllers\Customers as CustomersController;
use App\Database\Migrations\AddBusinessUnits;
use App\Database\Migrations\AddCustomerLoyaltyAdjustments;
use App\Database\Migrations\AddCustomerLoyaltyLedger;
use App\Database\Migrations\AddFixedEmployeeAccountScopes;
use App\Database\Migrations\AddSalesBusinessUnitScope;
use App\Database\Migrations\AddBusinessUnitInvoiceSequences;
use App\Models\Customer;
use App\Models\Customer_loyalty_ledger;
use App\Models\Sale;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

require_once APPPATH . 'Database/Migrations/20260724000000_AddFixedEmployeeAccountScopes.php';
require_once APPPATH . 'Database/Migrations/20260725000000_AddBusinessUnits.php';
require_once APPPATH . 'Database/Migrations/20260725000001_AddSalesBusinessUnitScope.php';
require_once APPPATH . 'Database/Migrations/20260726000002_AddCustomerLoyaltyLedger.php';
require_once APPPATH . 'Database/Migrations/20260726000003_AddCustomerLoyaltyAdjustments.php';
require_once APPPATH . 'Database/Migrations/20260726000004_AddBusinessUnitInvoiceSequences.php';

class CustomerLoyaltyLedgerTest extends CIUnitTestCase
{
    use FeatureTestTrait;

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
        (new AddBusinessUnitInvoiceSequences())->up();
        (new AddCustomerLoyaltyLedger())->up();
        (new AddCustomerLoyaltyAdjustments())->up();

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

    public function testManualAdjustmentAddsToAutomaticPointsAndSurvivesSaleSync(): void
    {
        $customerId = $this->createCustomer();
        $this->loginAsUsername('NguyenDuyTai');

        $this->saveSale($customerId, '300000.00');
        $this->assertLoyaltyTotals($customerId, 300000.00, 2, 0.00);

        $this->assertTrue(model(Customer_loyalty_ledger::class)->setManualPointsTarget(
            $customerId,
            5,
            $this->getLoggedInEmployeeId(),
            self::TEST_PREFIX . 'manual target'
        ));
        $this->assertLoyaltyTotals($customerId, 300000.00, 2, 0.00, 3, 5);
        $this->assertSame(1, $this->adjustmentCountForCustomer($customerId));

        $this->assertTrue(model(Customer_loyalty_ledger::class)->setManualPointsTarget(
            $customerId,
            5,
            $this->getLoggedInEmployeeId()
        ));
        $this->assertSame(1, $this->adjustmentCountForCustomer($customerId));

        $this->saveSale($customerId, '150000.00');
        $this->assertLoyaltyTotals($customerId, 450000.00, 3, 0.00, 3, 6);

        $this->assertTrue(model(Customer_loyalty_ledger::class)->setManualPointsTarget(
            $customerId,
            4,
            $this->getLoggedInEmployeeId()
        ));
        $this->assertLoyaltyTotals($customerId, 450000.00, 3, 0.00, 1, 4);
        $this->assertSame(2, $this->adjustmentCountForCustomer($customerId));
    }

    public function testManualAdjustmentCannotMakeCustomerPointsNegative(): void
    {
        $customerId = $this->createCustomer();
        $this->loginAsUsername('NguyenDuyTai');

        $saleId = $this->saveSale($customerId, '150000.00');
        $this->assertLoyaltyTotals($customerId, 150000.00, 1, 0.00);

        $this->assertTrue(model(Customer_loyalty_ledger::class)->setManualPointsTarget(
            $customerId,
            0,
            $this->getLoggedInEmployeeId()
        ));
        $this->assertLoyaltyTotals($customerId, 150000.00, 1, 0.00, -1, 0);

        $this->assertTrue(model(Sale::class)->delete($saleId, false, false, $this->getLoggedInEmployeeId()));
        $this->assertLoyaltyTotals($customerId, 0.00, 0, 0.00, -1, 0);
    }

    public function testDayCanSaveManualPointsThroughEndpointAndSpoofedFieldsAreIgnored(): void
    {
        $customerId = $this->createCustomer();
        $dayEmployeeId = $this->getEmployeeId('NguyenDuyTai');

        $response = $this
            ->withSession(['person_id' => $dayEmployeeId, 'menu_group' => 'office'])
            ->post('/customers/savePoints/' . $customerId, [
                'requested_points' => '7',
                'points_delta'     => '999',
                'employee_id'      => $this->getEmployeeId('NguyenDuyTai1'),
                'business_unit_id' => 999999,
            ]);

        $response->assertOK();
        $payload = json_decode($response->getJSON(), true);

        $this->assertTrue($payload['success']);
        $this->assertLoyaltyTotals($customerId, 0.00, 0, 0.00, 7, 7);

        $adjustment = db_connect()->table('customer_loyalty_adjustments')
            ->where('customer_id', $customerId)
            ->get()
            ->getRow();

        $this->assertSame(7, (int) $adjustment->points_delta);
        $this->assertSame(7, (int) $adjustment->resulting_points);
        $this->assertSame($dayEmployeeId, (int) $adjustment->employee_id);
    }

    public function testDayCanCreateCashierCustomerWithOnlyNameAndPhone(): void
    {
        $dayEmployeeId = $this->getEmployeeId('NguyenDuyTai');
        $name = self::TEST_PREFIX . 'nguyen';

        $response = $this
            ->withSession(['person_id' => $dayEmployeeId, 'menu_group' => 'office'])
            ->post('/customers/save/-1', [
                'cashier_form' => '1',
                'first_name'   => $name,
                'phone_number' => '0900000000',
            ]);

        $response->assertOK();
        $payload = json_decode($response->getJSON(), true);

        $this->assertTrue($payload['success'], json_encode($payload));
        $this->assertArrayHasKey('customer_id', $payload);
        $this->assertSame($payload['id'], $payload['customer_id']);

        $customer = model(Customer::class)->get_info((int) $payload['customer_id']);
        $this->assertSame($payload['customer_name'], $customer->first_name);
        $this->assertSame('', $customer->last_name);
        $this->assertSame('0900000000', $customer->phone_number);
        $this->assertSame('', $customer->email);
        $this->assertSame('', $customer->address_2);
        $this->assertSame(0, (int) $customer->points);
        $this->assertSame(0, (int) $customer->deleted);
        $this->assertSame($dayEmployeeId, (int) $customer->employee_id);
    }

    public function testCashierCreatePopupRendersBlankCreateForm(): void
    {
        $response = $this
            ->withSession(['person_id' => $this->getEmployeeId('NguyenDuyTai'), 'menu_group' => 'office'])
            ->get('/customers/viewCashier');

        $response->assertOK();
        $html = $response->getBody();

        $this->assertStringContainsString('action="' . site_url('customers/save/-1') . '"', $html);
        $this->assertStringContainsString('data-mode="create"', $html);
        $this->assertStringContainsString('name="customer_id" value="-1"', $html);
        $this->assertStringContainsString('name="person_id" value="-1"', $html);
        $this->assertStringContainsString('id="first_name"', $html);
        $this->assertStringContainsString('value="0"', $html);
    }

    public function testCashierUpdatePopupLoadsRequestedCustomerData(): void
    {
        $customerId = $this->createCustomer();

        db_connect()->table('people')
            ->where('person_id', $customerId)
            ->update([
                'first_name'   => self::TEST_PREFIX . 'Update Name',
                'phone_number' => '0987654321',
                'address_1'    => 'Update Address 1',
                'comments'     => 'Update Note',
            ]);
        db_connect()->table('customers')
            ->where('person_id', $customerId)
            ->update([
                'account_number' => 'KH-UPDATE',
                'company_name'   => 'Update Company',
            ]);
        $this->assertTrue(model(Customer_loyalty_ledger::class)->setManualPointsTarget(
            $customerId,
            6,
            $this->getEmployeeId('NguyenDuyTai')
        ));

        $response = $this
            ->withSession(['person_id' => $this->getEmployeeId('NguyenDuyTai'), 'menu_group' => 'office'])
            ->get('/customers/viewCashier/' . $customerId);

        $response->assertOK();
        $html = $response->getBody();

        $this->assertStringContainsString('action="' . site_url('customers/save/' . $customerId) . '"', $html);
        $this->assertStringContainsString('data-mode="update"', $html);
        $this->assertStringContainsString('name="customer_id" value="' . $customerId . '"', $html);
        $this->assertStringContainsString('name="person_id" value="' . $customerId . '"', $html);
        $this->assertStringContainsString('value="KH-UPDATE"', $html);
        $this->assertStringContainsString('value="' . self::TEST_PREFIX . 'Update Name Customer"', $html);
        $this->assertStringContainsString('value="0987654321"', $html);
        $this->assertStringContainsString('value="Update Address 1"', $html);
        $this->assertStringContainsString('value="Update Company"', $html);
        $this->assertStringContainsString('Update Note', $html);
        $this->assertStringContainsString('value="6"', $html);
    }

    public function testCashierUpdatePopupDoesNotLoadAnotherCustomer(): void
    {
        $firstCustomerId = $this->createCustomer();
        $secondCustomerId = $this->createCustomer();

        db_connect()->table('people')
            ->where('person_id', $firstCustomerId)
            ->update(['first_name' => self::TEST_PREFIX . 'First Customer']);
        db_connect()->table('people')
            ->where('person_id', $secondCustomerId)
            ->update(['first_name' => self::TEST_PREFIX . 'Second Customer']);

        $response = $this
            ->withSession(['person_id' => $this->getEmployeeId('NguyenDuyTai1'), 'menu_group' => 'office'])
            ->get('/customers/viewCashier/' . $secondCustomerId);

        $response->assertOK();
        $html = $response->getBody();

        $this->assertStringContainsString('value="' . self::TEST_PREFIX . 'Second Customer Customer"', $html);
        $this->assertStringNotContainsString(self::TEST_PREFIX . 'First Customer', $html);
    }

    public function testCashierUpdatePopupRejectsMissingCustomerAndAggregate(): void
    {
        $missingResponse = $this
            ->withSession(['person_id' => $this->getEmployeeId('NguyenDuyTai'), 'menu_group' => 'office'])
            ->get('/customers/viewCashier/99999999');
        $missingResponse->assertStatus(404);

        $customerId = $this->createCustomer();
        $aggregateResponse = $this
            ->withSession(['person_id' => $this->getEmployeeId('NguyenDuyTai2'), 'menu_group' => 'office'])
            ->get('/customers/viewCashier/' . $customerId);
        $aggregateResponse->assertStatus(403);
    }

    public function testNightCanCreateCashierCustomerAndRequestedPointsAreNotSavedDirectly(): void
    {
        $nightEmployeeId = $this->getEmployeeId('NguyenDuyTai1');
        $name = self::TEST_PREFIX . 'night';

        $response = $this
            ->withSession(['person_id' => $nightEmployeeId, 'menu_group' => 'office'])
            ->post('/customers/save/-1', [
                'cashier_form'     => '1',
                'first_name'       => $name,
                'phone_number'     => '0911111111',
                'requested_points' => '9',
            ]);

        $response->assertOK();
        $payload = json_decode($response->getJSON(), true);

        $this->assertTrue($payload['success'], json_encode($payload));
        $this->assertSame(0, $this->getCustomerPoints((int) $payload['customer_id']));
        $this->assertSame(0, $this->adjustmentCountForCustomer((int) $payload['customer_id']));
    }

    public function testCashierUpdatePreservesHiddenCustomerFields(): void
    {
        $customerId = $this->createCustomer();
        $dayEmployeeId = $this->getEmployeeId('NguyenDuyTai');
        db_connect()->table('people')
            ->where('person_id', $customerId)
            ->update([
                'last_name' => 'HiddenLast',
                'gender'    => 1,
                'email'     => self::TEST_PREFIX . 'hidden@example.test',
                'address_2' => 'Hidden Address 2',
                'city'      => 'Hidden City',
                'state'     => 'Hidden State',
                'zip'       => 'Hidden Zip',
                'country'   => 'Hidden Country',
            ]);
        db_connect()->table('customers')
            ->where('person_id', $customerId)
            ->update([
                'tax_id'        => 'HIDDEN-TAX',
                'discount'      => '3.00',
                'discount_type' => FIXED,
                'points'        => 5,
            ]);

        $response = $this
            ->withSession(['person_id' => $dayEmployeeId, 'menu_group' => 'office'])
            ->post('/customers/save/' . $customerId, [
                'cashier_form' => '1',
                'first_name'   => self::TEST_PREFIX . 'updated',
                'phone_number' => '0922222222',
                'address_1'    => 'Visible Address',
                'company_name' => 'Visible Company',
                'comments'     => 'Visible Note',
            ]);

        $response->assertOK();
        $payload = json_decode($response->getJSON(), true);
        $this->assertTrue($payload['success'], json_encode($payload));

        $customer = model(Customer::class)->get_info($customerId);
        $this->assertSame(self::TEST_PREFIX . 'updated', $customer->first_name);
        $this->assertSame('0922222222', $customer->phone_number);
        $this->assertSame('Visible Address', $customer->address_1);
        $this->assertSame('Visible Company', $customer->company_name);
        $this->assertSame('Visible Note', $customer->comments);
        $this->assertSame('HiddenLast', $customer->last_name);
        $this->assertSame('1', (string) $customer->gender);
        $this->assertSame(self::TEST_PREFIX . 'hidden@example.test', $customer->email);
        $this->assertSame('Hidden Address 2', $customer->address_2);
        $this->assertSame('Hidden City', $customer->city);
        $this->assertSame('Hidden State', $customer->state);
        $this->assertSame('Hidden Zip', $customer->zip);
        $this->assertSame('Hidden Country', $customer->country);
        $this->assertSame('HIDDEN-TAX', $customer->tax_id);
        $this->assertSame('3.00', (string) $customer->discount);
        $this->assertSame(FIXED, (int) $customer->discount_type);
        $this->assertSame(5, (int) $customer->points);
    }

    public function testCashierCustomerValidationFailureReturnsFieldError(): void
    {
        $response = $this
            ->withSession(['person_id' => $this->getEmployeeId('NguyenDuyTai'), 'menu_group' => 'office'])
            ->post('/customers/save/-1', [
                'cashier_form' => '1',
                'first_name'   => '',
                'phone_number' => '0933333333',
            ]);

        $response->assertOK();
        $payload = json_decode($response->getJSON(), true);

        $this->assertFalse($payload['success']);
        $this->assertArrayHasKey('errors', $payload);
        $this->assertArrayHasKey('first_name', $payload['errors']);
    }

    public function testAggregateCannotCreateCashierCustomer(): void
    {
        $response = $this
            ->withSession(['person_id' => $this->getEmployeeId('NguyenDuyTai2'), 'menu_group' => 'office'])
            ->post('/customers/save/-1', [
                'cashier_form' => '1',
                'first_name'   => self::TEST_PREFIX . 'aggregate',
            ]);

        $response->assertStatus(403);
        $payload = json_decode($response->getJSON(), true);
        $this->assertFalse($payload['success']);
        $this->assertArrayHasKey('errors', $payload);
    }

    public function testAggregateCannotSaveManualPointsThroughEndpoint(): void
    {
        $customerId = $this->createCustomer();
        $this->loginAsUsername('NguyenDuyTai');

        $controller = new CustomersController();
        $controller->initController(Services::request(), Services::response(), Services::logger());
        $accountScope = new ReflectionProperty($controller, 'accountScope');
        $accountScope->setAccessible(true);
        $accountScope->setValue($controller, 'AGGREGATE');
        $response = $controller->postSavePoints($customerId);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $this->adjustmentCountForCustomer($customerId));
        $this->assertSame(0, $this->getCustomerPoints($customerId));
    }

    public function testSalesCustomerDataIncludesSharedPointsForPosDisplay(): void
    {
        $customerId = $this->createCustomer();
        $this->loginAsUsername('NguyenDuyTai');

        $this->saveSale($customerId, '150000.00');
        $this->assertTrue(model(Customer_loyalty_ledger::class)->setManualPointsTarget(
            $customerId,
            4,
            $this->getLoggedInEmployeeId()
        ));

        $controller = new SalesController();
        $data = [];
        $method = new ReflectionMethod($controller, '_load_customer_data');
        $method->setAccessible(true);
        $method->invokeArgs($controller, [$customerId, &$data, true]);

        $this->assertSame(4, $data['customer_points']);
        $this->assertArrayHasKey('customer_phone_number', $data);
        $this->assertEqualsWithDelta(150000.00, (float) $data['customer_total'], 0.01);
    }

    public function testCustomerStatsCanBeScopedByBusinessUnit(): void
    {
        $customerId = $this->createCustomer();

        $this->loginAsUsername('NguyenDuyTai');
        $this->saveSale($customerId, '100000.00');
        $nullScopeSaleId = $this->saveSale($customerId, '300000.00');
        db_connect()->table('sales')
            ->where('sale_id', $nullScopeSaleId)
            ->update(['business_unit_id' => null]);

        $this->loginAsUsername('NguyenDuyTai1');
        $this->saveSale($customerId, '200000.00');

        $dayBusinessUnitId = $this->getBusinessUnitId('DAY');
        $nightBusinessUnitId = $this->getBusinessUnitId('NIGHT');

        $this->assertEqualsWithDelta(600000.00, $this->getStatsTotal($customerId), 0.01);
        $this->assertEqualsWithDelta(100000.00, $this->getStatsTotal($customerId, [$dayBusinessUnitId]), 0.01);
        $this->assertEqualsWithDelta(200000.00, $this->getStatsTotal($customerId, [$nightBusinessUnitId]), 0.01);
        $this->assertEqualsWithDelta(300000.00, $this->getStatsTotal($customerId, [$dayBusinessUnitId, $nightBusinessUnitId]), 0.01);
        $this->assertSame(0.0, $this->getStatsTotal($customerId, []));
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

    private function assertLoyaltyTotals(
        int $customerId,
        float $eligibleAmount,
        int $automaticPoints,
        float $remainderAmount,
        int $manualAdjustment = 0,
        ?int $finalPoints = null
    ): void {
        $finalPoints ??= max(0, $automaticPoints + $manualAdjustment);
        $totals = model(Customer_loyalty_ledger::class)->getTotals($customerId);

        $this->assertEqualsWithDelta($eligibleAmount, $totals['eligible_amount'], 0.01);
        $this->assertSame($automaticPoints, $totals['automatic_points']);
        $this->assertSame($manualAdjustment, $totals['manual_adjustment']);
        $this->assertSame($finalPoints, $totals['points']);
        $this->assertEqualsWithDelta($remainderAmount, $totals['remainder_amount'], 0.01);
        $this->assertSame($finalPoints, $this->getCustomerPoints($customerId));
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

    private function adjustmentCountForCustomer(int $customerId): int
    {
        return db_connect()->table('customer_loyalty_adjustments')
            ->where('customer_id', $customerId)
            ->countAllResults();
    }

    /**
     * @param array<int>|null $businessUnitIds
     */
    private function getStatsTotal(int $customerId, ?array $businessUnitIds = null): float
    {
        $stats = model(Customer::class)->get_stats($customerId, $businessUnitIds);

        return $stats === null ? 0.0 : (float) $stats->total;
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
            if ($db->tableExists('business_unit_item_quantities')) {
                $db->table('business_unit_item_quantities')->whereIn('item_id', $itemIds)->delete();
            }
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
            if ($db->tableExists('customer_loyalty_adjustments')) {
                $db->table('customer_loyalty_adjustments')->whereIn('customer_id', $personIds)->delete();
            }
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
