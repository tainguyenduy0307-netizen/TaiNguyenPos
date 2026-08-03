<?php

namespace Tests\Models;

use App\Models\Business_unit_item_unit_quantity;
use App\Models\Item;
use App\Models\Item_barcode;
use App\Models\Item_unit;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

final class ItemsListingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $seed = '';
    protected $seedOnce = true;
    protected $refresh = true;
    protected $namespace = null;

    private const PREFIX = 'ITEM_LISTING_TEST_';

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->removeListingTestData();
        session()->set('person_id', $this->getEmployeeIdForScope('DAY'));
    }

    protected function tearDown(): void
    {
        $this->removeListingTestData();
        session()->destroy();

        parent::tearDown();
    }

    public function testActiveItemWithoutLegacyQuantityStillAppearsWithRetailUnitQuantity(): void
    {
        $itemId = $this->createItem('NO_LEGACY', 'NOLEG-001', 0, [
            'day_retail' => 7,
            'night_retail' => 3,
        ]);

        $rows = $this->searchRows('NO_LEGACY');

        $this->assertCount(1, $rows);
        $this->assertSame($itemId, (int) $rows[0]->item_id);
        $this->assertSame(7.0, (float) $rows[0]->quantity);
        $this->assertNull($rows[0]->large_quantity);
    }

    public function testRetailAndLargeUnitItemAppearsOnceWithCurrentBusinessUnitQuantities(): void
    {
        $itemId = $this->createItem('TWO_UNITS', 'TWOUNIT-001', 0, [
            'retail_unit_name' => 'LỐC',
            'large_unit_name' => 'THÙNG',
            'day_retail' => -5,
            'day_large' => 2,
            'night_retail' => 11,
            'night_large' => 4,
        ], ['TWOUNIT-ALIAS-A', 'TWOUNIT-ALIAS-B']);

        $dayRows = $this->searchRows('TWO_UNITS');
        $this->assertCount(1, $dayRows);
        $this->assertSame($itemId, (int) $dayRows[0]->item_id);
        $this->assertSame(-5.0, (float) $dayRows[0]->quantity);
        $this->assertSame(2.0, (float) $dayRows[0]->large_quantity);
        $this->assertSame('LỐC', $dayRows[0]->retail_unit_name);
        $this->assertSame('THÙNG', $dayRows[0]->large_unit_name);

        session()->set('person_id', $this->getEmployeeIdForScope('NIGHT'));
        $nightRows = $this->searchRows('TWO_UNITS');
        $this->assertCount(1, $nightRows);
        $this->assertSame(11.0, (float) $nightRows[0]->quantity);
        $this->assertSame(4.0, (float) $nightRows[0]->large_quantity);
    }

    public function testMultipleBarcodeAliasesSearchAsOneItemRow(): void
    {
        $itemId = $this->createItem('ALIASED', 'ALIASED-001', 0, [
            'day_retail' => 0,
        ], ['ALIAS-DUP-001', 'ALIAS-DUP-002']);

        $nameRows = $this->searchRows('ALIASED');
        $aliasRows = $this->searchRows('ALIAS-DUP-002');

        $this->assertCount(1, $nameRows);
        $this->assertCount(1, $aliasRows);
        $this->assertSame($itemId, (int) $aliasRows[0]->item_id);
        $this->assertSame(0.0, (float) $aliasRows[0]->quantity);
    }

    public function testItemsSearchEndpointReturnsRowsWithoutLegacyQuantities(): void
    {
        $itemId = $this->createItem('ENDPOINT', 'ENDPOINT-001', 0, [
            'day_retail' => 6,
        ], ['ENDPOINT-ALIAS']);

        $response = $this
            ->withSession(['person_id' => $this->getEmployeeIdForScope('DAY'), 'menu_group' => 'office'])
            ->get(
                'items/search?search=ENDPOINT-ALIAS&limit=10&offset=0&sort=items.name&order=asc'
                . '&stock_location=1&start_date=2010-01-01&end_date=' . date('Y-m-d')
            );

        $response->assertOK();
        $payload = json_decode($response->getJSON(), true);

        $this->assertSame(1, (int) $payload['total']);
        $this->assertCount(1, $payload['rows']);
        $this->assertSame($itemId, (int) $payload['rows'][0]['items.item_id']);
        $this->assertStringContainsString('6', $payload['rows'][0]['quantity']);
    }

    public function testDeletedItemIsExcludedAndPaginationCountUsesDistinctItems(): void
    {
        $this->createItem('PAGE_A', 'PAGE-001');
        $this->createItem('PAGE_B', 'PAGE-002');
        $this->createItem('PAGE_C', 'PAGE-003');
        $this->createItem('PAGE_DELETED', 'PAGE-004', 1);

        $itemModel = model(Item::class);
        $rows = $itemModel->search(self::PREFIX . 'PAGE', $this->filters(), 2, 0, 'items.name', 'asc')->getResult();
        $totalRows = $itemModel->get_found_rows(self::PREFIX . 'PAGE', $this->filters());

        $this->assertCount(2, $rows);
        $this->assertSame(3, (int) $totalRows);
        foreach ($rows as $row) {
            $this->assertSame(0, (int) $row->deleted);
        }
    }

    private function createItem(string $nameSuffix, string $itemNumber, int $deleted = 0, array $unitData = [], array $aliases = []): int
    {
        $itemData = [
            'name' => self::PREFIX . $nameSuffix,
            'category' => 'Listing',
            'item_number' => $itemNumber,
            'description' => '',
            'cost_price' => 1000,
            'unit_price' => 2000,
            'reorder_level' => 0,
            'receiving_quantity' => 1,
            'allow_alt_description' => 0,
            'is_serialized' => 0,
            'deleted' => $deleted,
            'stock_type' => HAS_STOCK,
            'item_type' => ITEM,
            'qty_per_pack' => 1,
            'pack_name' => $unitData['retail_unit_name'] ?? 'LỐC',
            'hsn_code' => '',
        ];

        $this->assertTrue(model(Item::class)->save_value($itemData));
        $itemId = (int) $itemData['item_id'];

        model(Item_barcode::class)->saveAliases($itemId, array_merge([$itemNumber], $aliases));

        $itemUnitModel = model(Item_unit::class);
        $retailUnitId = $itemUnitModel->upsertRetailUnit(
            $itemId,
            $unitData['retail_unit_name'] ?? 'LỐC',
            2000,
            1000
        );
        $largeUnitId = null;
        if (array_key_exists('large_unit_name', $unitData)) {
            $largeUnitId = $itemUnitModel->upsertLargeUnit(
                $itemId,
                $unitData['large_unit_name'],
                '',
                12,
                22000,
                12000
            );
        }

        $quantityModel = model(Business_unit_item_unit_quantity::class);
        $dayId = $this->getBusinessUnitId('DAY');
        $nightId = $this->getBusinessUnitId('NIGHT');
        $quantityModel->setQuantity($dayId, $retailUnitId, (float) ($unitData['day_retail'] ?? 0));
        $quantityModel->setQuantity($nightId, $retailUnitId, (float) ($unitData['night_retail'] ?? 0));
        if ($largeUnitId !== null) {
            $quantityModel->setQuantity($dayId, $largeUnitId, (float) ($unitData['day_large'] ?? 0));
            $quantityModel->setQuantity($nightId, $largeUnitId, (float) ($unitData['night_large'] ?? 0));
        }

        return $itemId;
    }

    /**
     * @return list<object>
     */
    private function searchRows(string $search): array
    {
        return model(Item::class)->search($search, $this->filters(), 0, 0, 'items.name', 'asc')->getResult();
    }

    private function filters(): array
    {
        return [
            'start_date' => '2010-01-01',
            'end_date' => date('Y-m-d'),
            'stock_location_id' => 1,
            'empty_upc' => false,
            'low_inventory' => false,
            'is_serialized' => false,
            'no_description' => false,
            'search_custom' => false,
            'is_deleted' => false,
            'temporary' => false,
            'definition_ids' => [],
        ];
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

    private function removeListingTestData(): void
    {
        $db = db_connect();
        $itemIds = array_map(
            'intval',
            array_column(
                $db->table('items')
                    ->select('item_id')
                    ->like('name', self::PREFIX, 'after')
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

        if ($unitIds !== []) {
            $db->table('business_unit_item_unit_quantities')->whereIn('item_unit_id', $unitIds)->delete();
        }
        $db->table('item_units')->whereIn('item_id', $itemIds)->delete();
        $db->table('item_barcodes')->whereIn('item_id', $itemIds)->delete();
        $db->table('item_quantities')->whereIn('item_id', $itemIds)->delete();
        $db->table('inventory')->whereIn('trans_items', $itemIds)->delete();
        $db->table('items')->whereIn('item_id', $itemIds)->delete();
    }
}
