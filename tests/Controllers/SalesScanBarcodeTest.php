<?php

namespace Tests\Controllers;

use App\Libraries\Sale_lib;
use App\Models\Business_unit_item_unit_quantity;
use App\Models\Item;
use App\Models\Item_barcode;
use App\Models\Item_unit;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\OSPOS;

final class SalesScanBarcodeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $seed = '';
    protected $seedOnce = true;
    protected $refresh = true;
    protected $namespace = null;
    private int $employeeId;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->removeScanTestData();
        $this->employeeId = $this->getEmployeeIdForScope('DAY');
        session()->set('person_id', $this->employeeId);
        session()->set('menu_group', 'office');
        session()->remove('sales_cart');
        session()->remove('cashier_orders');
        session()->remove('cashier_active_order_id');
        config(OSPOS::class)->settings = array_merge(config(OSPOS::class)->settings, [
            'multi_pack_enabled' => false,
            'dateformat' => 'm/d/Y',
            'timeformat' => 'H:i:s',
            'default_sales_discount' => '0.0',
            'default_sales_discount_type' => PERCENT,
            'barcode_formats' => '[]',
            'allow_duplicate_barcodes' => false,
            'smtp_pass' => '',
            'protocol' => 'mail',
            'mailpath' => '/usr/sbin/sendmail',
            'smtp_host' => '',
            'smtp_user' => '',
            'smtp_port' => 25,
            'smtp_timeout' => 5,
            'smtp_crypto' => '',
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeScanTestData();
        session()->destroy();

        parent::tearDown();
    }

    public function testAjaxScanAddsExactItemNumberAliasAndLargeBarcodeWithSeparateUnitLines(): void
    {
        [, $retailUnitId, $largeUnitId] = $this->createItemWithUnits('SCAN');

        $this->postScan('SCAN-RET');
        $this->postScan('SCAN-RET-A');
        $this->postScan('SCAN-RET-B');
        $this->postScan('SCAN-BOX-A');
        $this->postScan('SCAN-BOX-A');

        $cart = session()->get('sales_cart');
        $this->assertCount(2, $cart);

        $retailLine = $this->findCartLine($cart, $retailUnitId);
        $largeLine = $this->findCartLine($cart, $largeUnitId);

        $this->assertSame(3.0, (float) $retailLine['quantity']);
        $this->assertSame(Item_unit::TYPE_RETAIL, $retailLine['unit_type']);
        $this->assertSame(2.0, (float) $largeLine['quantity']);
        $this->assertSame(Item_unit::TYPE_LARGE, $largeLine['unit_type']);
        $this->assertSame('225000.00', number_format((float) $largeLine['price'], 2, '.', ''));
    }

    public function testAjaxScanUnknownBarcodeReturnsVietnameseMessageWithoutChangingCart(): void
    {
        [$itemId] = $this->createItemWithUnits('UNKNOWN');
        $this->postScan('UNKNOWN-RET');
        $cartBefore = session()->get('sales_cart');

        $response = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('sales/add', ['item' => '9999999999999']);

        $response->assertOK();
        $payload = json_decode($response->getJSON(), true);

        $this->assertFalse($payload['success']);
        $this->assertSame('Mã hàng hóa này chưa tồn tại trên hệ thống.', $payload['message']);
        $this->assertSame($cartBefore, session()->get('sales_cart'));
        $this->assertSame($itemId, (int) reset($cartBefore)['item_id']);
    }

    public function testAutocompleteEndpointReturnsBarcodeAliasAndLargeUnitSuggestionsBeforeEnter(): void
    {
        $this->createItemWithUnits('AUTO');

        $aliasResponse = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get('sales/itemSearch?term=AUTO-RET-A');
        $aliasResponse->assertOK();
        $aliasSuggestions = json_decode($aliasResponse->getJSON(), true);

        $this->assertCount(1, $aliasSuggestions);
        $this->assertStringContainsString('SCAN_TEST_AUTO Item', $aliasSuggestions[0]['label']);
        $this->assertStringContainsString('AUTO-RET-A', $aliasSuggestions[0]['label']);
        $this->assertStringContainsString('Lon', $aliasSuggestions[0]['label']);
        $this->assertSame('SCAN_TEST_AUTO Item', $aliasSuggestions[0]['name']);
        $this->assertSame('AUTO-RET-A', $aliasSuggestions[0]['barcode']);
        $this->assertSame(10000.0, (float) $aliasSuggestions[0]['price']);
        $this->assertSame(to_currency_no_money(10000), $aliasSuggestions[0]['formatted_price']);
        $this->assertSame('Lon', $aliasSuggestions[0]['unit_name']);
        $this->assertArrayHasKey('item_id', $aliasSuggestions[0]);

        $largeResponse = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get('sales/itemSearch?term=AUTO-BOX-A');
        $largeResponse->assertOK();
        $largeSuggestions = json_decode($largeResponse->getJSON(), true);

        $this->assertCount(1, $largeSuggestions);
        $this->assertSame('AUTO-BOX-A', $largeSuggestions[0]['value']);
        $this->assertStringContainsString('Thùng', $largeSuggestions[0]['label']);
        $this->assertSame('AUTO-BOX-A', $largeSuggestions[0]['barcode']);
        $this->assertSame(225000.0, (float) $largeSuggestions[0]['price']);
        $this->assertSame(to_currency_no_money(225000), $largeSuggestions[0]['formatted_price']);
        $this->assertSame('Thùng', $largeSuggestions[0]['unit_name']);
        $this->assertArrayHasKey('item_unit_id', $largeSuggestions[0]);

        $missingResponse = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get('sales/itemSearch?term=NO-SUCH-SCAN-BARCODE-XYZ');
        $missingResponse->assertOK();
        $this->assertSame([], json_decode($missingResponse->getJSON(), true));
    }

    public function testAjaxScanSavesOnlyActiveCashierOrder(): void
    {
        [, $retailUnitId] = $this->createItemWithUnits('ORDER1');
        [$orderTwoItemId, $orderTwoRetailUnitId] = $this->createItemWithUnits('ORDER2');

        $this->postScan('ORDER1-RET');

        $newOrderResponse = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('sales/orders/new');
        $newOrderResponse->assertOK();

        $this->postScan('ORDER2-RET');

        $orders = session()->get('cashier_orders');
        $this->assertArrayHasKey('order_1', $orders);
        $this->assertArrayHasKey('order_2', $orders);

        $orderOneLine = $this->findCartLine($orders['order_1']['cart'], $retailUnitId);
        $orderTwoLine = $this->findCartLine($orders['order_2']['cart'], $orderTwoRetailUnitId);

        $this->assertSame(1.0, (float) $orderOneLine['quantity']);
        $this->assertSame($orderTwoItemId, (int) $orderTwoLine['item_id']);
        $this->assertSame(1.0, (float) $orderTwoLine['quantity']);
    }

    public function testPostDeleteRetailLineKeepsLargeLineAndRecalculatesTotals(): void
    {
        [$itemId, $retailUnitId, $largeUnitId] = $this->createItemWithUnits('DELETE');
        $this->postScan('DELETE-RET');
        $this->postScan('DELETE-BOX-A');
        (new Sale_lib())->addPayment(lang('Sales.cash'), '10000');

        $cart = session()->get('sales_cart');
        $this->assertCount(2, $cart);
        $retailLine = $this->findCartLine($cart, $retailUnitId);
        $largeLine = $this->findCartLine($cart, $largeUnitId);

        $response = $this->postDeleteLine((int) $retailLine['line']);
        $payload = json_decode($response->getJSON(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame((int) $retailLine['line'], $payload['line']);
        $this->assertFalse($payload['cart_empty']);
        $this->assertSame(to_currency(225000), $payload['totals']['subtotal']);
        $this->assertSame(to_currency(225000), $payload['totals']['total']);
        $this->assertSame(to_currency(0), $payload['totals']['payments_total']);
        $this->assertSame(to_currency(225000), $payload['totals']['amount_due']);
        $this->assertSame([], session()->get('sales_payments'));

        $cart = session()->get('sales_cart');
        $this->assertCount(1, $cart);
        $remainingLine = $this->findCartLine($cart, $largeUnitId);
        $this->assertSame($largeLine['line'], $remainingLine['line']);
        $this->assertSame(Item_unit::TYPE_LARGE, $remainingLine['unit_type']);

        $orders = session()->get('cashier_orders');
        $snapshotLine = $this->findCartLine($orders['order_1']['cart'], $largeUnitId);
        $this->assertSame($largeLine['line'], $snapshotLine['line']);
        $this->assertCount(1, $orders['order_1']['cart']);

        $item = db_connect()->table('items')->where('item_id', $itemId)->get()->getRow();
        $this->assertNotNull($item);
        $this->assertSame(0, (int) $item->deleted);
        $this->assertSame(3, db_connect()->table('item_barcodes')->where('item_id', $itemId)->countAllResults());
        $this->assertSame(2, db_connect()->table('item_units')->where('item_id', $itemId)->countAllResults());
    }

    public function testPostDeleteLargeLineKeepsRetailLineAndOtherOrderUnchanged(): void
    {
        [, $retailUnitId, $largeUnitId] = $this->createItemWithUnits('DLARGE');
        [, $otherRetailUnitId] = $this->createItemWithUnits('DOTHER');

        $this->postScan('DOTHER-RET');
        $newOrderResponse = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('sales/orders/new');
        $newOrderResponse->assertOK();

        $this->postScan('DLARGE-RET');
        $this->postScan('DLARGE-BOX-A');

        $cart = session()->get('sales_cart');
        $this->assertCount(2, $cart);
        $largeLine = $this->findCartLine($cart, $largeUnitId);

        $response = $this->postDeleteLine((int) $largeLine['line']);
        $payload = json_decode($response->getJSON(), true);

        $this->assertTrue($payload['success']);
        $this->assertFalse($payload['cart_empty']);

        $cart = session()->get('sales_cart');
        $this->assertCount(1, $cart);
        $remainingLine = $this->findCartLine($cart, $retailUnitId);
        $this->assertSame(Item_unit::TYPE_RETAIL, $remainingLine['unit_type']);
        $this->assertSame(to_currency(10000), $payload['totals']['amount_due']);

        $orders = session()->get('cashier_orders');
        $this->assertArrayHasKey('order_1', $orders);
        $this->assertArrayHasKey('order_2', $orders);
        $this->assertCount(1, $orders['order_1']['cart']);
        $this->assertSame($otherRetailUnitId, (int) reset($orders['order_1']['cart'])['item_unit_id']);
        $this->assertCount(1, $orders['order_2']['cart']);
        $this->assertSame($retailUnitId, (int) reset($orders['order_2']['cart'])['item_unit_id']);
    }

    public function testPostDeleteLastLineReturnsEmptyCartAndMissingLineDoesNotMutate(): void
    {
        [$itemId, $retailUnitId] = $this->createItemWithUnits('DLAST');
        $this->postScan('DLAST-RET');

        $cart = session()->get('sales_cart');
        $line = (int) $this->findCartLine($cart, $retailUnitId)['line'];

        $response = $this->postDeleteLine($line);
        $payload = json_decode($response->getJSON(), true);

        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['cart_empty']);
        $this->assertSame([], session()->get('sales_cart'));
        $this->assertSame(to_currency(0), $payload['totals']['total']);
        $this->assertSame(to_currency(0), $payload['totals']['amount_due']);

        $this->postScan('DLAST-RET');
        $cartBefore = session()->get('sales_cart');

        $missingResponse = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('sales/deleteItem/999999');
        $missingResponse->assertStatus(404);
        $missingPayload = json_decode($missingResponse->getJSON(), true);

        $this->assertFalse($missingPayload['success']);
        $this->assertSame('Dòng sản phẩm không tồn tại trong đơn hàng.', $missingPayload['message']);
        $this->assertSame($cartBefore, session()->get('sales_cart'));

        $item = db_connect()->table('items')->where('item_id', $itemId)->get()->getRow();
        $this->assertNotNull($item);
        $this->assertSame(0, (int) $item->deleted);
    }

    public function testPostDeleteRemovesTempTypedCartLineWithoutDeletingItemDatabaseRow(): void
    {
        [$itemId, $retailUnitId] = $this->createItemWithUnits('DTEMP');
        $this->postScan('DTEMP-RET');

        $cart = session()->get('sales_cart');
        $line = (int) $this->findCartLine($cart, $retailUnitId)['line'];
        $cart[$line]['item_type'] = ITEM_TEMP;
        session()->set('sales_cart', $cart);

        $response = $this->postDeleteLine($line);
        $payload = json_decode($response->getJSON(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame([], session()->get('sales_cart'));

        $item = db_connect()->table('items')->where('item_id', $itemId)->get()->getRow();
        $this->assertNotNull($item);
        $this->assertSame(0, (int) $item->deleted);
    }

    public function testPostRemoveCustomerClearsOnlyActiveOrderCustomerAndKeepsCartPaymentAndOtherOrder(): void
    {
        [, $orderOneRetailUnitId] = $this->createItemWithUnits('RCUST1');
        [, $orderTwoRetailUnitId] = $this->createItemWithUnits('RCUST2');
        $orderOneCustomerId = $this->createCustomer('A', 7);
        $orderTwoCustomerId = $this->createCustomer('B', 5);
        $saleLib = new Sale_lib();

        $this->postScan('RCUST1-RET');
        $saleLib->set_customer($orderOneCustomerId);
        $saleLib->addPayment(lang('Sales.cash'), '10000');
        $saleLib->saveActiveCashierOrder('10.000');

        $newOrderResponse = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('sales/orders/new');
        $newOrderResponse->assertOK();

        $this->postScan('RCUST2-RET');
        $saleLib->set_customer($orderTwoCustomerId);
        $saleLib->addPayment(lang('Sales.cash'), '20000');
        $saleLib->saveActiveCashierOrder('20.000');

        $switchResponse = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('sales/orders/switch/order_1');
        $switchResponse->assertOK();

        $cartBefore = session()->get('sales_cart');
        $paymentsBefore = session()->get('sales_payments');
        $orderTwoBefore = session()->get('cashier_orders')['order_2'];

        try {
            $this->withSession($this->requestSession())
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->get('sales/removeCustomer');
            $this->fail('GET sales/removeCustomer should not be routed.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($orderOneCustomerId, $saleLib->get_customer());
        $this->assertSame($cartBefore, session()->get('sales_cart'));
        $this->assertSame($paymentsBefore, session()->get('sales_payments'));

        $response = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('sales/removeCustomer', [
                csrf_token() => csrf_hash(),
            ]);
        $response->assertOK();
        $payload = json_decode($response->getJSON(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame('', $payload['message']);
        $this->assertSame('Khách lẻ', $payload['customer_name']);
        $this->assertSame(NEW_ENTRY, $saleLib->get_customer());
        $this->assertSame($cartBefore, session()->get('sales_cart'));
        $this->assertSame($paymentsBefore, session()->get('sales_payments'));

        $orders = session()->get('cashier_orders');
        $this->assertSame(NEW_ENTRY, $orders['order_1']['customer_id']);
        $this->assertSame($cartBefore, $orders['order_1']['cart']);
        $this->assertSame($paymentsBefore, $orders['order_1']['payments']);
        $this->assertSame($orderTwoCustomerId, $orders['order_2']['customer_id']);
        $this->assertSame($orderTwoBefore['cart'], $orders['order_2']['cart']);
        $this->assertSame($orderTwoBefore['payments'], $orders['order_2']['payments']);
        $this->assertSame($orderOneRetailUnitId, (int) reset($orders['order_1']['cart'])['item_unit_id']);
        $this->assertSame($orderTwoRetailUnitId, (int) reset($orders['order_2']['cart'])['item_unit_id']);

        $orderOneCustomer = db_connect()->table('customers')->where('person_id', $orderOneCustomerId)->get()->getRow();
        $orderTwoCustomer = db_connect()->table('customers')->where('person_id', $orderTwoCustomerId)->get()->getRow();
        $this->assertNotNull($orderOneCustomer);
        $this->assertNotNull($orderTwoCustomer);
        $this->assertSame(0, (int) $orderOneCustomer->deleted);
        $this->assertSame(7, (int) $orderOneCustomer->points);
        $this->assertSame(0, (int) $orderTwoCustomer->deleted);
        $this->assertSame(5, (int) $orderTwoCustomer->points);
    }

    public function testSaleLevelPercentDiscountRecalculatesTotalsAndPreservesTenderedInput(): void
    {
        $this->createItemWithUnits('DISC10');
        $this->postScan('DISC10-RET');
        (new Sale_lib())->addPayment('cash', '2000');
        $paymentsBefore = session()->get('sales_payments');

        $response = $this->postApplyDiscount('percent', '10', 'SUMMER10', '20.000');
        $payload = json_decode($response->getJSON(), true);

        $this->assertTrue($payload['success']);
        $this->assertMatchesRegularExpression('/Giảm giá \\(10[,.]00%\\)/', $payload['totals']['order_discount_label']);
        $this->assertSame(to_currency(1000), $payload['totals']['order_discount_amount']);
        $this->assertSame(to_currency(9000), $payload['totals']['total']);
        $this->assertSame(to_currency(7000), $payload['totals']['amount_due']);
        $this->assertSame('7.000', $payload['totals']['amount_due_raw']);
        $this->assertSame('SUMMER10', session()->get('sales_discount_code'));
        $this->assertSame($paymentsBefore, session()->get('sales_payments'));

        $orders = session()->get('cashier_orders');
        $this->assertSame(PERCENT, (int) $orders['order_1']['sale_discount_type']);
        $this->assertSame('10', (string) $orders['order_1']['sale_discount_value']);
        $this->assertSame('20.000', $orders['order_1']['amount_tendered']);
        $this->assertSame($paymentsBefore, $orders['order_1']['payments']);
    }

    public function testSaleLevelFixedDiscountRecalculatesTotalsAndCanBeRemoved(): void
    {
        $this->createItemWithUnits('DISCFIX');
        $this->postScan('DISCFIX-BOX-A');
        (new Sale_lib())->addPayment('cash', '100000');
        $paymentsBefore = session()->get('sales_payments');

        $response = $this->postApplyDiscount('fixed', '150.000', 'VOUCHER150');
        $payload = json_decode($response->getJSON(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame('Giảm giá', $payload['totals']['order_discount_label']);
        $this->assertSame(to_currency(150000), $payload['totals']['order_discount_amount']);
        $this->assertSame(to_currency(75000), $payload['totals']['total']);
        $this->assertSame($paymentsBefore, session()->get('sales_payments'));

        $removeResponse = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('sales/removeDiscount', [
                csrf_token() => csrf_hash(),
                'amount_tendered' => '100.000',
            ]);
        $removeResponse->assertOK();
        $removePayload = json_decode($removeResponse->getJSON(), true);

        $this->assertTrue($removePayload['success']);
        $this->assertSame(to_currency(0), $removePayload['totals']['order_discount_amount']);
        $this->assertFalse($removePayload['totals']['order_discount_applied']);
        $this->assertSame(to_currency(225000), $removePayload['totals']['total']);
        $this->assertNull(session()->get('sales_discount_type'));
        $this->assertSame($paymentsBefore, session()->get('sales_payments'));
    }

    public function testSaleLevelDiscountValidationRejectsInvalidValues(): void
    {
        $this->createItemWithUnits('DISCVAL');
        $this->postScan('DISCVAL-RET');

        $percentResponse = $this->postApplyDiscount('percent', '101');
        $percentPayload = json_decode($percentResponse->getJSON(), true);
        $this->assertFalse($percentPayload['success']);
        $this->assertSame('Phần trăm giảm giá phải từ 0 đến 100.', $percentPayload['message']);

        $fixedResponse = $this->postApplyDiscount('fixed', '10.001');
        $fixedPayload = json_decode($fixedResponse->getJSON(), true);
        $this->assertFalse($fixedPayload['success']);
        $this->assertSame('Số tiền giảm không được lớn hơn tổng đơn hàng.', $fixedPayload['message']);

        $negativeResponse = $this->postApplyDiscount('fixed', '-1');
        $negativePayload = json_decode($negativeResponse->getJSON(), true);
        $this->assertFalse($negativePayload['success']);
        $this->assertSame('Giá trị giảm giá không hợp lệ.', $negativePayload['message']);
    }

    public function testSaleLevelDiscountIsIndependentPerCashierOrder(): void
    {
        $this->createItemWithUnits('DORDER1');
        $this->createItemWithUnits('DORDER2');

        $this->postScan('DORDER1-RET');
        $this->postApplyDiscount('percent', '10');

        $newOrderResponse = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('sales/orders/new');
        $newOrderResponse->assertOK();

        $this->postScan('DORDER2-RET');
        $this->postApplyDiscount('fixed', '5.000');

        $orders = session()->get('cashier_orders');
        $this->assertSame(PERCENT, (int) $orders['order_1']['sale_discount_type']);
        $this->assertSame('10', (string) $orders['order_1']['sale_discount_value']);
        $this->assertSame(FIXED, (int) $orders['order_2']['sale_discount_type']);
        $this->assertSame('5000', (string) $orders['order_2']['sale_discount_value']);

        $switchResponse = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('sales/orders/switch/order_1');
        $switchResponse->assertOK();

        $this->assertSame(PERCENT, (new Sale_lib())->get_order_discount_type());
        $this->assertSame('10', (new Sale_lib())->get_order_discount_value());
    }

    public function testEditingCartLineWithoutLineDiscountInputPreservesExistingDiscount(): void
    {
        [, $retailUnitId] = $this->createItemWithUnits('EDITNODISC');
        $this->postScan('EDITNODISC-RET');

        $cart = session()->get('sales_cart');
        $line = (int) $this->findCartLine($cart, $retailUnitId)['line'];
        $cart[$line]['discount'] = '10';
        $cart[$line]['discount_type'] = PERCENT;
        session()->set('sales_cart', $cart);

        $response = $this->withSession($this->requestSession())
            ->post('sales/editItem/' . $line, [
                'description' => '',
                'serialnumber' => '',
                'price' => '10000',
                'quantity' => '2',
                'location' => '1',
                'item_id' => (string) $cart[$line]['item_id'],
            ]);

        $response->assertOK();

        $updatedLine = $this->findCartLine(session()->get('sales_cart'), $retailUnitId);
        $this->assertSame('10', (string) $updatedLine['discount']);
        $this->assertSame(PERCENT, (int) $updatedLine['discount_type']);
        $this->assertSame('2', (string) $updatedLine['quantity']);
        $this->assertSame('18000.00', number_format((float) $updatedLine['discounted_total'], 2, '.', ''));
    }

    private function postScan(string $barcode): void
    {
        $response = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('sales/add', ['item' => $barcode]);

        $response->assertOK();
        $payload = json_decode($response->getJSON(), true);

        $this->assertTrue($payload['success'], $barcode . ' should be accepted.');
    }

    private function postDeleteLine(int $line): object
    {
        $response = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('sales/deleteItem/' . $line);

        $response->assertOK();

        return $response;
    }

    private function postApplyDiscount(string $type, string $value, string $code = '', string $amountTendered = ''): object
    {
        $response = $this->withSession($this->requestSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('sales/applyDiscount', [
                csrf_token() => csrf_hash(),
                'discount_type' => $type,
                'discount_value' => $value,
                'discount_code' => $code,
                'amount_tendered' => $amountTendered,
            ]);

        $response->assertOK();

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestSession(): array
    {
        $session = [
            'person_id' => $this->employeeId,
            'menu_group' => 'office',
        ];

        foreach ([
            'sales_cart',
            'sales_customer',
            'sales_payments',
            'sales_mode',
            'sales_location',
            'cashier_orders',
            'cashier_active_order_id',
            'cashier_next_order_number',
            'sales_discount_type',
            'sales_discount_value',
            'sales_discount_amount',
            'sales_discount_code',
        ] as $key) {
            $value = session()->get($key);
            if ($value !== null) {
                $session[$key] = $value;
            }
        }

        return $session;
    }

    private function createItemWithUnits(string $prefix): array
    {
        $itemData = [
            'name' => 'SCAN_TEST_' . $prefix . ' Item',
            'category' => 'Scan',
            'item_number' => $prefix . '-RET',
            'description' => '',
            'cost_price' => 8000,
            'unit_price' => 10000,
            'reorder_level' => 0,
            'receiving_quantity' => 1,
            'allow_alt_description' => 0,
            'is_serialized' => 0,
            'deleted' => 0,
            'stock_type' => HAS_STOCK,
            'item_type' => ITEM,
            'qty_per_pack' => 1,
            'pack_name' => 'Lon',
            'hsn_code' => '',
        ];

        $this->assertTrue(model(Item::class)->save_value($itemData));
        $itemId = (int) $itemData['item_id'];
        $this->assertTrue(model(Item_barcode::class)->saveAliases($itemId, [$prefix . '-RET', $prefix . '-RET-A', $prefix . '-RET-B']));

        $itemUnitModel = model(Item_unit::class);
        $retailUnitId = $itemUnitModel->upsertRetailUnit($itemId, 'Lon', 10000, 8000);
        $largeUnitId = $itemUnitModel->upsertLargeUnit($itemId, 'Thùng', $prefix . '-BOX-A', 24, 225000, 192000);

        $dayId = (int) db_connect()->table('business_units')->select('id')->where('code', 'DAY')->get()->getRow()->id;
        $quantityModel = model(Business_unit_item_unit_quantity::class);
        $quantityModel->setQuantity($dayId, $retailUnitId, 10);
        $quantityModel->setQuantity($dayId, $largeUnitId, 4);

        return [$itemId, $retailUnitId, $largeUnitId];
    }

    private function createCustomer(string $suffix, int $points): int
    {
        $db = db_connect();
        $firstName = 'SCAN_TEST_CUSTOMER_' . $suffix;

        $db->table('people')->insert([
            'first_name'   => $firstName,
            'last_name'    => 'Customer',
            'gender'       => null,
            'phone_number' => '09000000' . $suffix,
            'email'        => strtolower($firstName) . '@example.test',
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
            'person_id'       => $personId,
            'company_name'    => null,
            'account_number'  => null,
            'taxable'         => 0,
            'discount'        => '0.00',
            'discount_type'   => PERCENT,
            'package_id'      => null,
            'points'          => $points,
            'date'            => date('Y-m-d H:i:s'),
            'employee_id'     => $this->employeeId,
            'consent'         => 1,
            'deleted'         => 0,
        ]);

        return $personId;
    }

    private function getEmployeeIdForScope(string $scope): int
    {
        return (int) db_connect()
            ->table('employees')
            ->select('person_id')
            ->where('account_scope', $scope)
            ->where('deleted', 0)
            ->get()
            ->getRow()
            ->person_id;
    }

    private function removeScanTestData(): void
    {
        $db = db_connect();
        $this->removeScanTestCustomers($db);

        $itemIds = array_map(
            'intval',
            array_column(
                $db->table('items')
                    ->select('item_id')
                    ->like('name', 'SCAN_TEST_')
                    ->get()
                    ->getResultArray(),
                'item_id'
            )
        );

        if ($itemIds === []) {
            return;
        }

        $saleIds = array_map(
            'intval',
            array_column(
                $db->table('sales_items')
                    ->select('sale_id')
                    ->whereIn('item_id', $itemIds)
                    ->get()
                    ->getResultArray(),
                'sale_id'
            )
        );

        if ($saleIds !== []) {
            $db->table('sales_items_taxes')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales_payments')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales_items')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales_taxes')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales')->whereIn('sale_id', $saleIds)->delete();
        }

        $unitIds = array_map(
            'intval',
            array_column(
                $db->table('item_units')
                    ->select('item_unit_id')
                    ->whereIn('item_id', $itemIds)
                    ->get()
                    ->getResultArray(),
                'item_unit_id'
            )
        );

        $db->table('inventory')->whereIn('trans_items', $itemIds)->delete();
        if ($unitIds !== []) {
            $db->table('business_unit_item_unit_quantities')->whereIn('item_unit_id', $unitIds)->delete();
        }
        $db->table('item_units')->whereIn('item_id', $itemIds)->delete();
        $db->table('item_barcodes')->whereIn('item_id', $itemIds)->delete();
        $db->table('business_unit_item_quantities')->whereIn('item_id', $itemIds)->delete();
        $db->table('items')->whereIn('item_id', $itemIds)->delete();
    }

    private function removeScanTestCustomers($db): void
    {
        $personIds = array_map(
            'intval',
            array_column(
                $db->table('people')
                    ->select('person_id')
                    ->like('first_name', 'SCAN_TEST_CUSTOMER_')
                    ->get()
                    ->getResultArray(),
                'person_id'
            )
        );

        if ($personIds === []) {
            return;
        }

        if ($db->tableExists('customer_loyalty_adjustments')) {
            $db->table('customer_loyalty_adjustments')->whereIn('customer_id', $personIds)->delete();
        }
        if ($db->tableExists('customer_loyalty_ledger')) {
            $db->table('customer_loyalty_ledger')->whereIn('customer_id', $personIds)->delete();
        }

        $db->table('customers')->whereIn('person_id', $personIds)->delete();
        $db->table('people')->whereIn('person_id', $personIds)->delete();
    }

    private function findCartLine(array $cart, int $itemUnitId): array
    {
        foreach ($cart as $line) {
            if ((int) ($line['item_unit_id'] ?? 0) === $itemUnitId) {
                return $line;
            }
        }

        $this->fail('Cart line with unit ' . $itemUnitId . ' was not found.');
    }
}
