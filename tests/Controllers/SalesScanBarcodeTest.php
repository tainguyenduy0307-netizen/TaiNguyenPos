<?php

namespace Tests\Controllers;

use App\Libraries\Sale_lib;
use App\Models\Business_unit_item_unit_quantity;
use App\Models\Item;
use App\Models\Item_barcode;
use App\Models\Item_unit;
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
            'sales_mode',
            'sales_location',
            'cashier_orders',
            'cashier_active_order_id',
            'cashier_next_order_number',
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
