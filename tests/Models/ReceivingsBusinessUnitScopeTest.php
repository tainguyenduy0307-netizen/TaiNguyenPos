<?php

namespace Tests\Models;

use App\Database\Migrations\AddBusinessUnits;
use App\Database\Migrations\AddFixedEmployeeAccountScopes;
use App\Database\Migrations\AddReceivingsBusinessUnitScope;
use App\Libraries\Receiving_lib;
use App\Models\Receiving;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use RuntimeException;

require_once APPPATH . 'Database/Migrations/20260724000000_AddFixedEmployeeAccountScopes.php';
require_once APPPATH . 'Database/Migrations/20260725000000_AddBusinessUnits.php';
require_once APPPATH . 'Database/Migrations/20260725000002_AddReceivingsBusinessUnitScope.php';

class ReceivingsBusinessUnitScopeTest extends CIUnitTestCase
{
    private const INITIAL_PASSWORD = 'TestOnlyPassword123!';
    private const TEST_PREFIX = 'RECV_BU_SCOPE_TEST_';
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

        $this->removeTestReceivings();
        $this->removeTestItems();
        $this->removeFixedAccounts();

        putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . self::INITIAL_PASSWORD);

        (new AddFixedEmployeeAccountScopes())->up();
        (new AddBusinessUnits())->up();
        (new AddReceivingsBusinessUnitScope())->up();

        $this->itemId = $this->createTestItem();
    }

    protected function tearDown(): void
    {
        $this->removeTestReceivings();
        $this->removeTestItems();
        Services::session()->destroy();

        if ($this->previousInitialPassword === false) {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');
        } else {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . $this->previousInitialPassword);
        }

        parent::tearDown();
    }

    public function testMigrationAddsReceivingsBusinessUnitColumnIndexesForeignKeyAndBackfillsKnownEmployees(): void
    {
        $dayReceivingId = $this->createReceiving($this->getEmployeeId('NguyenDuyTai'), null, self::TEST_PREFIX . 'BACKFILL_DAY');
        $nightReceivingId = $this->createReceiving($this->getEmployeeId('NguyenDuyTai1'), null, self::TEST_PREFIX . 'BACKFILL_NIGHT');
        $aggregateReceivingId = $this->createReceiving($this->getEmployeeId('NguyenDuyTai2'), null, self::TEST_PREFIX . 'BACKFILL_NULL');

        (new AddReceivingsBusinessUnitScope())->up();

        $this->assertTrue(db_connect()->fieldExists('business_unit_id', 'receivings'));
        $this->assertSame($this->getBusinessUnitId('DAY'), $this->getReceivingBusinessUnitId($dayReceivingId));
        $this->assertSame($this->getBusinessUnitId('NIGHT'), $this->getReceivingBusinessUnitId($nightReceivingId));
        $this->assertNull($this->getReceivingBusinessUnitId($aggregateReceivingId));
        $this->assertSame([
            'receiving_time_index' => 2,
            'reference_index'      => 2,
            'foreign_key'          => 1,
        ], $this->getReceivingsScopeMetadataCounts());
    }

    public function testMigrationIsIdempotentForIndexesAndForeignKey(): void
    {
        $before = $this->getReceivingsScopeMetadataCounts();

        (new AddReceivingsBusinessUnitScope())->up();

        $this->assertSame($before, $this->getReceivingsScopeMetadataCounts());
    }

    public function testSaveValueAssignsCurrentBusinessUnitForDayAndNight(): void
    {
        $this->loginAsUsername('NguyenDuyTai');
        $dayReceivingId = $this->saveReceivingThroughModel();

        $this->loginAsUsername('NguyenDuyTai1');
        $nightReceivingId = $this->saveReceivingThroughModel();

        $this->assertSame($this->getBusinessUnitId('DAY'), $this->getReceivingBusinessUnitId($dayReceivingId));
        $this->assertSame($this->getBusinessUnitId('NIGHT'), $this->getReceivingBusinessUnitId($nightReceivingId));
    }

    public function testAggregateCannotSaveOperationalReceiving(): void
    {
        $this->loginAsUsername('NguyenDuyTai2');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An operational business unit is required for this action.');

        $this->saveReceivingThroughModel();
    }

    public function testInputCannotSpoofBusinessUnitOnCreateOrUpdate(): void
    {
        $this->loginAsUsername('NguyenDuyTai');
        Services::session()->set('business_unit_id', $this->getBusinessUnitId('NIGHT'));
        $_POST['business_unit_id'] = (string) $this->getBusinessUnitId('NIGHT');

        try {
            $receivingId = $this->saveReceivingThroughModel(['business_unit_id' => $this->getBusinessUnitId('NIGHT')]);
            $updated = model(Receiving::class)->update($receivingId, [
                'comment'          => self::TEST_PREFIX . 'SPOOF_UPDATE',
                'employee_id'      => $this->getEmployeeId('NguyenDuyTai1'),
                'business_unit_id' => $this->getBusinessUnitId('NIGHT'),
            ]);
            (new AddReceivingsBusinessUnitScope())->up();
        } finally {
            unset($_POST['business_unit_id']);
        }

        $this->assertTrue($updated);
        $this->assertSame($this->getBusinessUnitId('DAY'), $this->getReceivingBusinessUnitId($receivingId));
        $this->assertSame(self::TEST_PREFIX . 'SPOOF_UPDATE', $this->getReceivingComment($receivingId));
    }

    public function testDayAndNightCannotReadEachOtherReceivingsOrNullScopedReceiving(): void
    {
        $dayReceivingId = $this->createReceiving($this->getEmployeeId('NguyenDuyTai'), $this->getBusinessUnitId('DAY'), self::TEST_PREFIX . 'READ_DAY');
        $nightReceivingId = $this->createReceiving($this->getEmployeeId('NguyenDuyTai1'), $this->getBusinessUnitId('NIGHT'), self::TEST_PREFIX . 'READ_NIGHT');
        $nullReceivingId = $this->createReceiving($this->getEmployeeId('NguyenDuyTai2'), null, self::TEST_PREFIX . 'READ_NULL');

        $receiving = model(Receiving::class);
        $this->loginAsUsername('NguyenDuyTai');

        $this->assertSame(1, $receiving->get_info($dayReceivingId)->getNumRows());
        $this->assertSame(0, $receiving->get_info($nightReceivingId)->getNumRows());
        $this->assertSame(0, $receiving->get_info($nullReceivingId)->getNumRows());
        $this->assertSame(0, $receiving->get_receiving_items($nightReceivingId)->getNumRows());
        $this->assertTrue($receiving->is_valid_receipt('RECV ' . $dayReceivingId));
        $this->assertFalse($receiving->is_valid_receipt('RECV ' . $nightReceivingId));
        $this->assertSame(0, $receiving->get_receiving_by_reference(self::TEST_PREFIX . 'READ_NIGHT')->getNumRows());

        $this->loginAsUsername('NguyenDuyTai1');

        $this->assertSame(0, $receiving->get_info($dayReceivingId)->getNumRows());
        $this->assertSame(1, $receiving->get_info($nightReceivingId)->getNumRows());
    }

    public function testCopyAndReturnEntireReceivingAreScoped(): void
    {
        $dayReceivingId = $this->createReceiving($this->getEmployeeId('NguyenDuyTai'), $this->getBusinessUnitId('DAY'), self::TEST_PREFIX . 'COPY_DAY');
        $nightReceivingId = $this->createReceiving($this->getEmployeeId('NguyenDuyTai1'), $this->getBusinessUnitId('NIGHT'), self::TEST_PREFIX . 'COPY_NIGHT');

        $this->loginAsUsername('NguyenDuyTai');
        $receivingLib = new Receiving_lib();

        $receivingLib->copy_entire_receiving($nightReceivingId);
        $this->assertSame([], $receivingLib->get_cart());

        $receivingLib->return_entire_receiving(self::TEST_PREFIX . 'COPY_NIGHT');
        $this->assertSame([], $receivingLib->get_cart());

        $receivingLib->copy_entire_receiving($dayReceivingId);
        $this->assertCount(1, $receivingLib->get_cart());

        $receivingLib->clear_all();
        $receivingLib->return_entire_receiving('RECV ' . $dayReceivingId);
        $cart = $receivingLib->get_cart();
        $this->assertCount(1, $cart);
        $this->assertSame(-1, (int) reset($cart)['quantity']);
    }

    public function testScopedWriteOperationsCannotAffectAnotherBusinessUnit(): void
    {
        $dayReceivingId = $this->createReceiving($this->getEmployeeId('NguyenDuyTai'), $this->getBusinessUnitId('DAY'), self::TEST_PREFIX . 'WRITE_DAY');
        $nightReceivingId = $this->createReceiving($this->getEmployeeId('NguyenDuyTai1'), $this->getBusinessUnitId('NIGHT'), self::TEST_PREFIX . 'WRITE_NIGHT');
        $receiving = model(Receiving::class);

        $this->loginAsUsername('NguyenDuyTai');
        $quantityBefore = $this->getItemQuantity();
        $deleteLedgerBefore = $this->getInventoryLedgerCount('Deleting receiving ' . $nightReceivingId);

        $this->assertFalse($receiving->update($nightReceivingId, ['comment' => self::TEST_PREFIX . 'CROSS_UPDATE']));
        $this->assertFalse($receiving->delete_list([$nightReceivingId], $this->getEmployeeId('NguyenDuyTai'), true));

        $this->assertSame(self::TEST_PREFIX . 'WRITE_NIGHT', $this->getReceivingComment($nightReceivingId));
        $this->assertSame(1, $this->getReceivingItemCount($nightReceivingId));
        $this->assertSame($quantityBefore, $this->getItemQuantity());
        $this->assertSame($deleteLedgerBefore, $this->getInventoryLedgerCount('Deleting receiving ' . $nightReceivingId));

        $this->assertTrue($receiving->update($dayReceivingId, ['comment' => self::TEST_PREFIX . 'OWN_UPDATE']));
        $this->assertSame(self::TEST_PREFIX . 'OWN_UPDATE', $this->getReceivingComment($dayReceivingId));
    }

    public function testOwnScopeReceivingStillUpdatesInventoryAndQuantity(): void
    {
        $this->loginAsUsername('NguyenDuyTai');

        $quantityBefore = $this->getItemQuantity();
        $receivingId = $this->saveReceivingThroughModel();

        $this->assertSame($quantityBefore + 1.0, $this->getItemQuantity());
        $this->assertSame(1, $this->getInventoryLedgerCount('RECV ' . $receivingId));
    }

    private function saveReceivingThroughModel(array $extraItemData = []): int
    {
        $items = [
            0 => array_merge([
                'item_id'            => $this->itemId,
                'line'               => 0,
                'description'        => self::TEST_PREFIX . 'ITEM',
                'serialnumber'       => '',
                'quantity'           => '1',
                'receiving_quantity' => '1',
                'discount'           => '0',
                'discount_type'      => PERCENT,
                'price'              => '2.00',
                'item_location'      => 1,
            ], $extraItemData),
        ];

        return model(Receiving::class)->save_value(
            $items,
            NEW_ENTRY,
            $this->getLoggedInEmployeeId(),
            self::TEST_PREFIX . 'SAVE_VALUE',
            self::TEST_PREFIX . uniqid('REF_', false),
            'Cash',
            1
        );
    }

    private function createReceiving(int $employeeId, ?int $businessUnitId, string $reference): int
    {
        $db = db_connect();
        $db->table('receivings')->insert([
            'receiving_time'   => '2030-07-25 10:00:00',
            'supplier_id'      => null,
            'employee_id'      => $employeeId,
            'business_unit_id' => $businessUnitId,
            'comment'          => $reference,
            'payment_type'     => 'Cash',
            'reference'        => $reference,
        ]);
        $receivingId = (int) $db->insertID();

        $db->table('receivings_items')->insert([
            'receiving_id'       => $receivingId,
            'item_id'            => $this->itemId,
            'line'               => 0,
            'description'        => self::TEST_PREFIX . 'ITEM',
            'serialnumber'       => '',
            'quantity_purchased' => '1.000',
            'receiving_quantity' => '1.000',
            'item_cost_price'    => '1.00',
            'item_unit_price'    => '2.00',
            'discount'           => '0.00',
            'discount_type'      => PERCENT,
            'item_location'      => 1,
        ]);

        return $receivingId;
    }

    private function createTestItem(): int
    {
        $itemNumber = self::TEST_PREFIX . uniqid('ITEM_', false);
        $db = db_connect();

        $db->table('items')->insert([
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

        $itemId = (int) $db->insertID();
        $db->table('item_quantities')->insert([
            'item_id'     => $itemId,
            'location_id' => 1,
            'quantity'    => '0.000',
        ]);

        return $itemId;
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

    private function getReceivingBusinessUnitId(int $receivingId): ?int
    {
        $businessUnitId = db_connect()
            ->table('receivings')
            ->select('business_unit_id')
            ->where('receiving_id', $receivingId)
            ->get()
            ->getRow()
            ->business_unit_id;

        return $businessUnitId === null ? null : (int) $businessUnitId;
    }

    private function getReceivingComment(int $receivingId): string
    {
        return db_connect()
            ->table('receivings')
            ->select('comment')
            ->where('receiving_id', $receivingId)
            ->get()
            ->getRow()
            ->comment;
    }

    private function getReceivingItemCount(int $receivingId): int
    {
        return db_connect()
            ->table('receivings_items')
            ->where('receiving_id', $receivingId)
            ->countAllResults();
    }

    private function getItemQuantity(): float
    {
        return (float) db_connect()
            ->table('item_quantities')
            ->select('quantity')
            ->where('item_id', $this->itemId)
            ->where('location_id', 1)
            ->get()
            ->getRow()
            ->quantity;
    }

    private function getInventoryLedgerCount(string $comment): int
    {
        return db_connect()
            ->table('inventory')
            ->where('trans_comment', $comment)
            ->countAllResults();
    }

    /**
     * @return array<string, int>
     */
    private function getReceivingsScopeMetadataCounts(): array
    {
        $db = db_connect();

        return [
            'receiving_time_index' => (int) $db->query(
                'SELECT COUNT(*) AS count FROM information_schema.statistics'
                . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$db->prefixTable('receivings'), 'receivings_business_unit_receiving_time']
            )->getRow()->count,
            'reference_index'      => (int) $db->query(
                'SELECT COUNT(*) AS count FROM information_schema.statistics'
                . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$db->prefixTable('receivings'), 'receivings_business_unit_reference']
            )->getRow()->count,
            'foreign_key'          => (int) $db->query(
                'SELECT COUNT(*) AS count FROM information_schema.table_constraints'
                . ' WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?',
                [$db->prefixTable('receivings'), 'ospos_receivings_business_unit_id_fk']
            )->getRow()->count,
        ];
    }

    private function removeTestReceivings(): void
    {
        $db = db_connect();

        if (!$db->fieldExists('business_unit_id', 'receivings')) {
            return;
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

        if ($receivingIds === []) {
            return;
        }

        $comments = [];
        foreach ($receivingIds as $receivingId) {
            $comments[] = 'RECV ' . $receivingId;
            $comments[] = 'Deleting receiving ' . $receivingId;
        }

        $db->table('inventory')->whereIn('trans_comment', $comments)->delete();
        $db->table('attribute_links')->whereIn('receiving_id', $receivingIds)->delete();
        $db->table('receivings_items')->whereIn('receiving_id', $receivingIds)->delete();
        $db->table('receivings')->whereIn('receiving_id', $receivingIds)->delete();
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

        if ($itemIds === []) {
            return;
        }

        $db->table('inventory')->whereIn('trans_items', $itemIds)->delete();
        $db->table('item_quantities')->whereIn('item_id', $itemIds)->delete();
        $db->table('attribute_links')->whereIn('item_id', $itemIds)->delete();
        $db->table('items')->whereIn('item_id', $itemIds)->delete();
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
