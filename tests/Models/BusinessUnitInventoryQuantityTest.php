<?php

namespace Tests\Models;

use App\Database\Migrations\AddBusinessUnitInventoryQuantities;
use App\Database\Migrations\AddBusinessUnitInvoiceSequences;
use App\Database\Migrations\AddBusinessUnits;
use App\Database\Migrations\AddFixedEmployeeAccountScopes;
use App\Database\Migrations\AddReceivingsBusinessUnitScope;
use App\Database\Migrations\AddSalesBusinessUnitScope;
use App\Libraries\BusinessUnitInventoryService;
use App\Libraries\Receiving_lib;
use App\Libraries\Sale_lib;
use App\Models\Business_unit_item_quantity;
use App\Models\Receiving;
use App\Models\Sale;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use RuntimeException;

require_once APPPATH . 'Database/Migrations/20260724000000_AddFixedEmployeeAccountScopes.php';
require_once APPPATH . 'Database/Migrations/20260725000000_AddBusinessUnits.php';
require_once APPPATH . 'Database/Migrations/20260725000001_AddSalesBusinessUnitScope.php';
require_once APPPATH . 'Database/Migrations/20260725000002_AddReceivingsBusinessUnitScope.php';
require_once APPPATH . 'Database/Migrations/20260725000003_AddBusinessUnitInventoryQuantities.php';
require_once APPPATH . 'Database/Migrations/20260726000004_AddBusinessUnitInvoiceSequences.php';

class BusinessUnitInventoryQuantityTest extends CIUnitTestCase
{
    private const INITIAL_PASSWORD = 'TestOnlyPassword123!';
    private const TEST_PREFIX = 'BU_INV_SCOPE_TEST_';
    private const FIXED_ACCOUNTS = [
        'NguyenDuyTai',
        'NguyenDuyTai1',
        'NguyenDuyTai2',
    ];

    private string|false $previousInitialPassword;

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
        (new AddReceivingsBusinessUnitScope())->up();
        (new AddBusinessUnitInvoiceSequences())->up();
    }

    protected function tearDown(): void
    {
        $this->removeTestData();
        Services::session()->destroy();

        if ($this->previousInitialPassword === false) {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');
        } else {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . $this->previousInitialPassword);
        }

        parent::tearDown();
    }

    public function testMigrationCreatesScopedQuantitySchemaAndZeroRowsWithoutCopyingLegacyQuantity(): void
    {
        $itemId = $this->createTestItemWithLegacyQuantity(37.0);

        (new AddBusinessUnitInventoryQuantities())->up();

        $this->assertTrue(db_connect()->tableExists('business_unit_item_quantities'));
        $this->assertSame(['business_unit_id', 'item_id', 'location_id'], $this->getIndexColumns('business_unit_item_quantities', 'PRIMARY'));
        $this->assertSame(
            ['business_unit_id', 'location_id', 'quantity'],
            $this->getIndexColumns('business_unit_item_quantities', 'business_unit_item_quantities_location_quantity')
        );
        $this->assertSame(3, $this->getForeignKeyCount('business_unit_item_quantities'));

        $model = model(Business_unit_item_quantity::class);
        $this->assertSame(0.0, $model->getQuantity($this->getBusinessUnitId('DAY'), $itemId, 1));
        $this->assertSame(0.0, $model->getQuantity($this->getBusinessUnitId('NIGHT'), $itemId, 1));
        $this->assertSame(37.0, $this->getLegacyQuantity($itemId, 1));
    }

    public function testMigrationIsIdempotentAndDoesNotResetExistingScopedQuantity(): void
    {
        $itemId = $this->createTestItemWithLegacyQuantity(21.0);

        (new AddBusinessUnitInventoryQuantities())->up();

        $model = model(Business_unit_item_quantity::class);
        $dayBusinessUnitId = $this->getBusinessUnitId('DAY');
        $this->assertTrue($model->setQuantity($dayBusinessUnitId, $itemId, 1, 12.5));
        $rowCountBefore = $this->getScopedQuantityRowCount($itemId);

        (new AddBusinessUnitInventoryQuantities())->up();

        $this->assertSame($rowCountBefore, $this->getScopedQuantityRowCount($itemId));
        $this->assertSame(12.5, $model->getQuantity($dayBusinessUnitId, $itemId, 1));
        $this->assertSame(21.0, $this->getLegacyQuantity($itemId, 1));
    }

    public function testMigrationAddsInventoryBusinessUnitAndBackfillsOnlyClearLedgerReferences(): void
    {
        $itemId = $this->createTestItemWithLegacyQuantity(0.0);
        $daySaleId = $this->createSale($this->getEmployeeId('NguyenDuyTai'), $this->getBusinessUnitId('DAY'));
        $nightReceivingId = $this->createReceiving($this->getEmployeeId('NguyenDuyTai1'), $this->getBusinessUnitId('NIGHT'));

        $this->insertInventoryLedger($itemId, 'POS ' . $daySaleId, $this->getEmployeeId('NguyenDuyTai'), -2.0);
        $this->insertInventoryLedger($itemId, 'RECV ' . $nightReceivingId, $this->getEmployeeId('NguyenDuyTai1'), 3.0);
        $this->insertInventoryLedger($itemId, self::TEST_PREFIX . 'MANUAL_ADJUSTMENT', $this->getEmployeeId('NguyenDuyTai'), 4.0);

        (new AddBusinessUnitInventoryQuantities())->up();

        $this->assertTrue(db_connect()->fieldExists('business_unit_id', 'inventory'));
        $this->assertSame(['business_unit_id', 'trans_items', 'trans_date'], $this->getIndexColumns('inventory', 'inventory_business_unit_item_date'));
        $this->assertSame(['business_unit_id', 'trans_location', 'trans_date'], $this->getIndexColumns('inventory', 'inventory_business_unit_location_date'));
        $this->assertSame(1, $this->getNamedForeignKeyCount('inventory', 'ospos_inventory_business_unit_id_fk'));

        $this->assertSame($this->getBusinessUnitId('DAY'), $this->getInventoryLedgerBusinessUnitId('POS ' . $daySaleId));
        $this->assertSame($this->getBusinessUnitId('NIGHT'), $this->getInventoryLedgerBusinessUnitId('RECV ' . $nightReceivingId));
        $this->assertNull($this->getInventoryLedgerBusinessUnitId(self::TEST_PREFIX . 'MANUAL_ADJUSTMENT'));
    }

    public function testModelKeepsBusinessUnitQuantitiesIndependentAndAllowsNegativeQuantity(): void
    {
        $itemId = $this->createTestItemWithLegacyQuantity(0.0);

        (new AddBusinessUnitInventoryQuantities())->up();

        $model = model(Business_unit_item_quantity::class);
        $dayBusinessUnitId = $this->getBusinessUnitId('DAY');
        $nightBusinessUnitId = $this->getBusinessUnitId('NIGHT');

        $this->assertTrue($model->setQuantity($dayBusinessUnitId, $itemId, 1, 10.0));
        $this->assertTrue($model->setQuantity($nightBusinessUnitId, $itemId, 1, 3.0));
        $this->assertTrue($model->changeQuantity($dayBusinessUnitId, $itemId, 1, -15.0));

        $this->assertSame(-5.0, $model->getQuantity($dayBusinessUnitId, $itemId, 1));
        $this->assertSame(3.0, $model->getQuantity($nightBusinessUnitId, $itemId, 1));
    }

    public function testServiceUsesCurrentEmployeeBusinessUnitAndRejectsAggregateWrites(): void
    {
        $itemId = $this->createTestItemWithLegacyQuantity(0.0);

        (new AddBusinessUnitInventoryQuantities())->up();

        $model = model(Business_unit_item_quantity::class);
        $dayBusinessUnitId = $this->getBusinessUnitId('DAY');
        $nightBusinessUnitId = $this->getBusinessUnitId('NIGHT');

        $this->loginAsUsername('NguyenDuyTai');
        Services::session()->set('business_unit_id', $nightBusinessUnitId);
        $_POST['business_unit_id'] = (string) $nightBusinessUnitId;

        try {
            $this->assertTrue((new BusinessUnitInventoryService())->changeCurrentQuantity($itemId, 1, 4.0));
        } finally {
            unset($_POST['business_unit_id']);
        }

        $this->assertSame(4.0, $model->getQuantity($dayBusinessUnitId, $itemId, 1));
        $this->assertSame(0.0, $model->getQuantity($nightBusinessUnitId, $itemId, 1));

        $this->loginAsUsername('NguyenDuyTai1');
        $this->assertTrue((new BusinessUnitInventoryService())->changeCurrentQuantity($itemId, 1, 7.0));
        $this->assertSame(4.0, $model->getQuantity($dayBusinessUnitId, $itemId, 1));
        $this->assertSame(7.0, $model->getQuantity($nightBusinessUnitId, $itemId, 1));

        $this->loginAsUsername('NguyenDuyTai2');
        $this->assertNull((new BusinessUnitInventoryService())->getCurrentQuantity($itemId, 1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An operational business unit is required for this action.');

        (new BusinessUnitInventoryService())->changeCurrentQuantity($itemId, 1, 1.0);
    }

    public function testSalesWriterReaderAndDeleteUseScopedInventoryOnly(): void
    {
        $itemId = $this->createTestItemWithLegacyQuantity(50.0);

        (new AddBusinessUnitInventoryQuantities())->up();

        $model = model(Business_unit_item_quantity::class);
        $dayBusinessUnitId = $this->getBusinessUnitId('DAY');
        $nightBusinessUnitId = $this->getBusinessUnitId('NIGHT');
        $model->setQuantity($dayBusinessUnitId, $itemId, 1, 10.0);
        $model->setQuantity($nightBusinessUnitId, $itemId, 1, 3.0);

        $this->loginAsUsername('NguyenDuyTai');
        Services::session()->set('business_unit_id', $nightBusinessUnitId);
        $_POST['business_unit_id'] = (string) $nightBusinessUnitId;

        try {
            $saleId = $this->saveSaleThroughModel($itemId, '2', 'NguyenDuyTai');
        } finally {
            unset($_POST['business_unit_id']);
        }

        $this->assertSame(8.0, $model->getQuantity($dayBusinessUnitId, $itemId, 1));
        $this->assertSame(3.0, $model->getQuantity($nightBusinessUnitId, $itemId, 1));
        $this->assertSame(50.0, $this->getLegacyQuantity($itemId, 1));
        $this->assertSame($dayBusinessUnitId, $this->getInventoryLedgerBusinessUnitId('POS ' . $saleId));

        $saleItem = (string) $itemId;
        $discount = '0.0';
        $saleLib = new Sale_lib();
        $this->assertTrue($saleLib->add_item($saleItem, 1, '1', $discount));
        $cart = $saleLib->get_cart();
        $this->assertSame(8.0, (float) reset($cart)['in_stock']);

        $this->assertTrue(model(Sale::class)->delete($saleId, false, true, $this->getEmployeeId('NguyenDuyTai')));
        $this->assertSame(10.0, $model->getQuantity($dayBusinessUnitId, $itemId, 1));
        $this->assertSame(3.0, $model->getQuantity($nightBusinessUnitId, $itemId, 1));
        $this->assertSame(50.0, $this->getLegacyQuantity($itemId, 1));
        $this->assertSame($dayBusinessUnitId, $this->getInventoryLedgerBusinessUnitId('Deleting sale ' . $saleId));

        $this->loginAsUsername('NguyenDuyTai1');
        $nightSaleId = $this->saveSaleThroughModel($itemId, '1', 'NguyenDuyTai1');

        $this->assertSame(10.0, $model->getQuantity($dayBusinessUnitId, $itemId, 1));
        $this->assertSame(2.0, $model->getQuantity($nightBusinessUnitId, $itemId, 1));
        $this->assertSame(50.0, $this->getLegacyQuantity($itemId, 1));
        $this->assertSame($nightBusinessUnitId, $this->getInventoryLedgerBusinessUnitId('POS ' . $nightSaleId));

        $this->assertTrue(model(Sale::class)->delete($nightSaleId, false, true, $this->getEmployeeId('NguyenDuyTai1')));
        $this->assertSame(10.0, $model->getQuantity($dayBusinessUnitId, $itemId, 1));
        $this->assertSame(3.0, $model->getQuantity($nightBusinessUnitId, $itemId, 1));
        $this->assertSame($nightBusinessUnitId, $this->getInventoryLedgerBusinessUnitId('Deleting sale ' . $nightSaleId));
    }

    public function testSaleOutOfStockUsesScopedQuantityWithoutLegacyFallback(): void
    {
        $itemId = $this->createTestItemWithLegacyQuantity(50.0);

        (new AddBusinessUnitInventoryQuantities())->up();

        model(Business_unit_item_quantity::class)->setQuantity($this->getBusinessUnitId('DAY'), $itemId, 1, 0.0);
        $this->loginAsUsername('NguyenDuyTai');

        $saleItem = (string) $itemId;
        $discount = '0.0';
        $saleLib = new Sale_lib();

        $this->assertTrue($saleLib->add_item($saleItem, 1, '1', $discount));
        $this->assertSame(lang('Sales.quantity_less_than_zero'), $saleLib->out_of_stock($itemId, 1));
    }

    public function testReceivingsWriterReaderDeleteAndRequisitionUseScopedInventoryOnly(): void
    {
        $itemId = $this->createTestItemWithLegacyQuantity(50.0);
        $secondLocationId = $this->createTestLocation();

        (new AddBusinessUnitInventoryQuantities())->up();

        $model = model(Business_unit_item_quantity::class);
        $dayBusinessUnitId = $this->getBusinessUnitId('DAY');
        $nightBusinessUnitId = $this->getBusinessUnitId('NIGHT');
        $model->setQuantity($dayBusinessUnitId, $itemId, 1, 10.0);
        $model->setQuantity($dayBusinessUnitId, $itemId, $secondLocationId, 1.0);
        $model->setQuantity($nightBusinessUnitId, $itemId, 1, 3.0);

        $this->loginAsUsername('NguyenDuyTai');
        $receivingId = $this->saveReceivingThroughModel($itemId, '4', '1', 1);

        $this->assertSame(14.0, $model->getQuantity($dayBusinessUnitId, $itemId, 1));
        $this->assertSame(3.0, $model->getQuantity($nightBusinessUnitId, $itemId, 1));
        $this->assertSame(50.0, $this->getLegacyQuantity($itemId, 1));
        $this->assertSame($dayBusinessUnitId, $this->getInventoryLedgerBusinessUnitId('RECV ' . $receivingId));

        $receivingLib = new Receiving_lib();
        $this->assertTrue($receivingLib->add_item((string) $itemId, 1, 1));
        $cart = $receivingLib->get_cart();
        $this->assertSame(14.0, (float) reset($cart)['in_stock']);

        $this->assertTrue(model(Receiving::class)->delete_value($receivingId, $this->getEmployeeId('NguyenDuyTai')));
        $this->assertSame(10.0, $model->getQuantity($dayBusinessUnitId, $itemId, 1));
        $this->assertSame(3.0, $model->getQuantity($nightBusinessUnitId, $itemId, 1));
        $this->assertSame(50.0, $this->getLegacyQuantity($itemId, 1));
        $this->assertSame($dayBusinessUnitId, $this->getInventoryLedgerBusinessUnitId('Deleting receiving ' . $receivingId));

        $this->loginAsUsername('NguyenDuyTai1');
        $nightReceivingId = $this->saveReceivingThroughModel($itemId, '2', '1', 1, 'NguyenDuyTai1');

        $this->assertSame(10.0, $model->getQuantity($dayBusinessUnitId, $itemId, 1));
        $this->assertSame(5.0, $model->getQuantity($nightBusinessUnitId, $itemId, 1));
        $this->assertSame(50.0, $this->getLegacyQuantity($itemId, 1));
        $this->assertSame($nightBusinessUnitId, $this->getInventoryLedgerBusinessUnitId('RECV ' . $nightReceivingId));

        $nightReceivingLib = new Receiving_lib();
        $nightReceivingLib->empty_cart();
        $this->assertTrue($nightReceivingLib->add_item((string) $itemId, 1, 1));
        $cart = $nightReceivingLib->get_cart();
        $this->assertSame(5.0, (float) reset($cart)['in_stock']);

        $this->assertTrue(model(Receiving::class)->delete_value($nightReceivingId, $this->getEmployeeId('NguyenDuyTai1')));
        $this->assertSame(10.0, $model->getQuantity($dayBusinessUnitId, $itemId, 1));
        $this->assertSame(3.0, $model->getQuantity($nightBusinessUnitId, $itemId, 1));
        $this->assertSame($nightBusinessUnitId, $this->getInventoryLedgerBusinessUnitId('Deleting receiving ' . $nightReceivingId));

        $this->loginAsUsername('NguyenDuyTai');
        $requisitionId = model(Receiving::class)->save_value(
            [
                0 => $this->receivingItemData($itemId, '2', '1', $secondLocationId, 0),
                1 => $this->receivingItemData($itemId, '-2', '1', 1, 1),
            ],
            NEW_ENTRY,
            $this->getEmployeeId('NguyenDuyTai'),
            self::TEST_PREFIX . 'REQUISITION',
            self::TEST_PREFIX . uniqid('REQ_', false),
            'Cash'
        );

        $this->assertSame(8.0, $model->getQuantity($dayBusinessUnitId, $itemId, 1));
        $this->assertSame(3.0, $model->getQuantity($dayBusinessUnitId, $itemId, $secondLocationId));
        $this->assertSame(3.0, $model->getQuantity($nightBusinessUnitId, $itemId, 1));
        $this->assertSame($dayBusinessUnitId, $this->getInventoryLedgerBusinessUnitId('RECV ' . $requisitionId));
    }

    private function createTestItemWithLegacyQuantity(float $quantity): int
    {
        $itemNumber = self::TEST_PREFIX . uniqid('ITEM_', false);
        $db = db_connect();

        $db->table('items')->insert([
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
            'stock_type'            => HAS_STOCK,
            'item_type'             => ITEM,
            'tax_category_id'       => null,
            'qty_per_pack'          => '1.000',
            'pack_name'             => 'Each',
            'low_sell_item_id'      => 0,
            'hsn_code'              => '',
        ]);
        $itemId = (int) $db->insertID();

        $db->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => 1,
            'quantity'    => $quantity,
        ]);

        return $itemId;
    }

    private function createSale(int $employeeId, int $businessUnitId): int
    {
        $db = db_connect();

        $db->table('sales')->insert([
            'sale_time'        => '2030-07-25 10:00:00',
            'customer_id'      => null,
            'employee_id'      => $employeeId,
            'business_unit_id' => $businessUnitId,
            'comment'          => self::TEST_PREFIX . 'SALE',
            'sale_status'      => COMPLETED,
            'sale_type'        => SALE_TYPE_POS,
        ]);

        return (int) $db->insertID();
    }

    private function createReceiving(int $employeeId, int $businessUnitId): int
    {
        $db = db_connect();

        $db->table('receivings')->insert([
            'receiving_time'   => '2030-07-25 10:00:00',
            'supplier_id'      => null,
            'employee_id'      => $employeeId,
            'business_unit_id' => $businessUnitId,
            'comment'          => self::TEST_PREFIX . 'RECEIVING',
            'payment_type'     => 'Cash',
            'reference'        => self::TEST_PREFIX . uniqid('REF_', false),
        ]);

        return (int) $db->insertID();
    }

    private function insertInventoryLedger(int $itemId, string $comment, int $employeeId, float $quantity): void
    {
        db_connect()->table('inventory')->insert([
            'trans_items'     => $itemId,
            'trans_user'      => $employeeId,
            'trans_comment'   => $comment,
            'trans_location'  => 1,
            'trans_inventory' => $quantity,
        ]);
    }

    private function saveSaleThroughModel(int $itemId, string $quantity, string $username): int
    {
        $saleStatus = (string) COMPLETED;
        $items = [
            0 => [
                'item_id'      => $itemId,
                'line'         => 0,
                'description'  => self::TEST_PREFIX . 'SALE_ITEM',
                'serialnumber' => '',
                'quantity'     => $quantity,
                'discount'     => '0',
                'discount_type'=> PERCENT,
                'cost_price'   => '1.00',
                'price'        => '2.00',
                'item_location'=> 1,
                'print_option' => PRINT_ALL,
            ],
        ];
        $payments = [];
        $taxes = [[], []];

        return model(Sale::class)->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $this->getEmployeeId($username),
            self::TEST_PREFIX . 'SALE_FLOW',
            self::TEST_PREFIX . uniqid('INV_', false),
            null,
            null,
            SALE_TYPE_POS,
            $payments,
            null,
            $taxes
        );
    }

    private function saveReceivingThroughModel(
        int $itemId,
        string $quantity,
        string $receivingQuantity,
        int $locationId,
        string $username = 'NguyenDuyTai'
    ): int
    {
        return model(Receiving::class)->save_value(
            [0 => $this->receivingItemData($itemId, $quantity, $receivingQuantity, $locationId, 0)],
            NEW_ENTRY,
            $this->getEmployeeId($username),
            self::TEST_PREFIX . 'RECEIVING_FLOW',
            self::TEST_PREFIX . uniqid('REF_', false),
            'Cash'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function receivingItemData(int $itemId, string $quantity, string $receivingQuantity, int $locationId, int $line): array
    {
        return [
            'item_id'            => $itemId,
            'line'               => $line,
            'description'        => self::TEST_PREFIX . 'RECEIVING_ITEM',
            'serialnumber'       => '',
            'quantity'           => $quantity,
            'receiving_quantity' => $receivingQuantity,
            'discount'           => '0',
            'discount_type'      => PERCENT,
            'price'              => '2.00',
            'item_location'      => $locationId,
        ];
    }

    private function createTestLocation(): int
    {
        db_connect()->table('stock_locations')->insert([
            'location_name' => self::TEST_PREFIX . uniqid('LOCATION_', false),
            'deleted'       => 0,
        ]);

        return (int) db_connect()->insertID();
    }

    private function getLegacyQuantity(int $itemId, int $locationId): float
    {
        return (float) db_connect()
            ->table('item_quantities')
            ->select('quantity')
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->get()
            ->getRow()
            ->quantity;
    }

    private function getScopedQuantityRowCount(int $itemId): int
    {
        return db_connect()
            ->table('business_unit_item_quantities')
            ->where('item_id', $itemId)
            ->countAllResults();
    }

    /**
     * @return list<string>
     */
    private function getIndexColumns(string $table, string $index): array
    {
        return array_column(
            db_connect()->query(
                'SELECT column_name FROM information_schema.statistics'
                . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
                . ' ORDER BY seq_in_index',
                [db_connect()->prefixTable($table), $index]
            )->getResultArray(),
            'column_name'
        );
    }

    private function getForeignKeyCount(string $table): int
    {
        return (int) db_connect()->query(
            'SELECT COUNT(*) AS count FROM information_schema.table_constraints'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND constraint_type = ?',
            [db_connect()->prefixTable($table), 'FOREIGN KEY']
        )->getRow()->count;
    }

    private function getNamedForeignKeyCount(string $table, string $constraintName): int
    {
        return (int) db_connect()->query(
            'SELECT COUNT(*) AS count FROM information_schema.table_constraints'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?',
            [db_connect()->prefixTable($table), $constraintName]
        )->getRow()->count;
    }

    private function getInventoryLedgerBusinessUnitId(string $comment): ?int
    {
        $businessUnitId = db_connect()
            ->table('inventory')
            ->select('business_unit_id')
            ->where('trans_comment', $comment)
            ->get()
            ->getRow()
            ->business_unit_id;

        return $businessUnitId === null ? null : (int) $businessUnitId;
    }

    private function loginAsUsername(string $username): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', $this->getEmployeeId($username));
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

        $itemIds = array_column(
            $db->table('items')
                ->select('item_id')
                ->like('item_number', self::TEST_PREFIX, 'after')
                ->get()
                ->getResultArray(),
            'item_id'
        );

        if ($db->tableExists('business_unit_item_quantities') && $itemIds !== []) {
            $db->table('business_unit_item_quantities')->whereIn('item_id', $itemIds)->delete();
        }

        $db->table('inventory')->like('trans_comment', self::TEST_PREFIX, 'after')->delete();

        if ($itemIds !== []) {
            $db->table('inventory')->whereIn('trans_items', $itemIds)->delete();
            $db->table('item_quantities')->whereIn('item_id', $itemIds)->delete();
            $db->table('attribute_links')->whereIn('item_id', $itemIds)->delete();
        }

        $saleIds = array_column(
            $db->table('sales')
                ->select('sale_id')
                ->like('comment', self::TEST_PREFIX, 'after')
                ->get()
                ->getResultArray(),
            'sale_id'
        );

        if ($saleIds !== []) {
            $db->table('inventory')->whereIn('trans_comment', array_map(static fn ($saleId) => 'Deleting sale ' . $saleId, $saleIds))->delete();
            $db->table('inventory')->whereIn('trans_comment', array_map(static fn ($saleId) => 'POS ' . $saleId, $saleIds))->delete();
            $db->table('sales_payments')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales_items_taxes')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales_items')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales_taxes')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales')->whereIn('sale_id', $saleIds)->delete();
        }

        $receivingIds = array_column(
            $db->table('receivings')
                ->select('receiving_id')
                ->like('comment', self::TEST_PREFIX, 'after')
                ->orLike('reference', self::TEST_PREFIX, 'after')
                ->get()
                ->getResultArray(),
            'receiving_id'
        );

        if ($receivingIds !== []) {
            $db->table('inventory')->whereIn('trans_comment', array_map(static fn ($receivingId) => 'Deleting receiving ' . $receivingId, $receivingIds))->delete();
            $db->table('inventory')->whereIn('trans_comment', array_map(static fn ($receivingId) => 'RECV ' . $receivingId, $receivingIds))->delete();
            $db->table('receivings_items')->whereIn('receiving_id', $receivingIds)->delete();
            $db->table('receivings')->whereIn('receiving_id', $receivingIds)->delete();
        }

        if ($itemIds !== []) {
            $db->table('items')->whereIn('item_id', $itemIds)->delete();
        }

        $locationIds = array_column(
            $db->table('stock_locations')
                ->select('location_id')
                ->like('location_name', self::TEST_PREFIX, 'after')
                ->get()
                ->getResultArray(),
            'location_id'
        );

        if ($locationIds !== [] && $db->tableExists('business_unit_item_quantities')) {
            $db->table('business_unit_item_quantities')->whereIn('location_id', $locationIds)->delete();
        }

        if ($locationIds !== []) {
            $db->table('inventory')->whereIn('trans_location', $locationIds)->delete();
            $db->table('stock_locations')->whereIn('location_id', $locationIds)->delete();
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
}
