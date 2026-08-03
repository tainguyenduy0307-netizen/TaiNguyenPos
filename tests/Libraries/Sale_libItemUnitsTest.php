<?php

namespace Tests\Libraries;

use App\Libraries\Sale_lib;
use App\Models\Business_unit_item_unit_quantity;
use App\Models\Item;
use App\Models\Item_barcode;
use App\Models\Item_unit;
use App\Models\Sale;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;

final class Sale_libItemUnitsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

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

        $this->employeeId = $this->getEmployeeIdForScope('DAY');
        session()->set('person_id', $this->employeeId);
        session()->remove('sales_cart');
        config(OSPOS::class)->settings['multi_pack_enabled'] = false;
        config(OSPOS::class)->settings['dateformat'] = 'm/d/Y';
        config(OSPOS::class)->settings['timeformat'] = 'H:i:s';
    }

    protected function tearDown(): void
    {
        $this->removeUnitTestData();
        session()->destroy();

        parent::tearDown();
    }

    public function testRetailAliasAndLargeBarcodeCreateSeparateMergingCartLines(): void
    {
        [$itemId, $retailUnitId, $largeUnitId] = $this->createItemWithUnits();
        $saleLib = new Sale_lib();
        $discount = '0';

        $retailAlias = 'UNIT-RET-A';
        $this->assertTrue($saleLib->add_item($retailAlias, 1, '1', $discount));
        $retailAlias = 'UNIT-RET-A';
        $this->assertTrue($saleLib->add_item($retailAlias, 1, '2', $discount));
        $largeBarcode = 'UNIT-BOX-A';
        $this->assertTrue($saleLib->add_item($largeBarcode, 1, '1', $discount));
        $largeBarcode = 'UNIT-BOX-A';
        $this->assertTrue($saleLib->add_item($largeBarcode, 1, '1', $discount));

        $cart = $saleLib->get_cart();
        $this->assertCount(2, $cart);
        $retailLine = $this->findCartLine($cart, $retailUnitId);
        $largeLine = $this->findCartLine($cart, $largeUnitId);

        $this->assertSame($itemId, (int) $retailLine['item_id']);
        $this->assertSame('3', (string) $retailLine['quantity']);
        $this->assertSame('10000.00', number_format((float) $retailLine['price'], 2, '.', ''));
        $this->assertSame(Item_unit::TYPE_RETAIL, $retailLine['unit_type']);

        $this->assertSame($itemId, (int) $largeLine['item_id']);
        $this->assertSame('2', (string) $largeLine['quantity']);
        $this->assertSame('225000.00', number_format((float) $largeLine['price'], 2, '.', ''));
        $this->assertSame('192000.00', number_format((float) $largeLine['cost_price'], 2, '.', ''));
        $this->assertSame(Item_unit::TYPE_LARGE, $largeLine['unit_type']);
    }

    public function testSwitchingUnitMergesIntoExistingTargetLine(): void
    {
        [, $retailUnitId, $largeUnitId] = $this->createItemWithUnits('SWITCH');
        $saleLib = new Sale_lib();
        $discount = '0';

        $retailAlias = 'SWITCH-RET-A';
        $largeBarcode = 'SWITCH-BOX-A';
        $this->assertTrue($saleLib->add_item($retailAlias, 1, '1', $discount));
        $this->assertTrue($saleLib->add_item($largeBarcode, 1, '2', $discount));

        $retailLineNumber = (int) $this->findCartLine($saleLib->get_cart(), $retailUnitId)['line'];
        $this->assertTrue($saleLib->switch_item_unit($retailLineNumber, $largeUnitId));

        $cart = $saleLib->get_cart();
        $this->assertCount(1, $cart);
        $largeLine = $this->findCartLine($cart, $largeUnitId);
        $this->assertSame('3', (string) $largeLine['quantity']);
        $this->assertSame('225000.00', number_format((float) $largeLine['price'], 2, '.', ''));
    }

    public function testSwitchingLargeUnitBackToRetailMergesIntoExistingTargetLine(): void
    {
        [, $retailUnitId, $largeUnitId] = $this->createItemWithUnits('SWBACK');
        $saleLib = new Sale_lib();
        $discount = '0';

        $retailAlias = 'SWBACK-RET-A';
        $largeBarcode = 'SWBACK-BOX-A';
        $this->assertTrue($saleLib->add_item($retailAlias, 1, '2', $discount));
        $this->assertTrue($saleLib->add_item($largeBarcode, 1, '1', $discount));

        $largeLineNumber = (int) $this->findCartLine($saleLib->get_cart(), $largeUnitId)['line'];
        $this->assertTrue($saleLib->switch_item_unit($largeLineNumber, $retailUnitId));

        $cart = $saleLib->get_cart();
        $this->assertCount(1, $cart);
        $retailLine = $this->findCartLine($cart, $retailUnitId);
        $this->assertSame('3', (string) $retailLine['quantity']);
        $this->assertSame('10000.00', number_format((float) $retailLine['price'], 2, '.', ''));
        $this->assertSame('8000.00', number_format((float) $retailLine['cost_price'], 2, '.', ''));
        $this->assertSame(1.0, (float) $retailLine['conversion_quantity']);
    }

    public function testSwitchingUnitRejectsInvalidLineMissingUnitAndWrongItemUnit(): void
    {
        [, $retailUnitId] = $this->createItemWithUnits('SWVALID');
        [, $otherRetailUnitId] = $this->createItemWithUnits('SWOTHER');
        $saleLib = new Sale_lib();
        $discount = '0';

        $retailAlias = 'SWVALID-RET-A';
        $this->assertTrue($saleLib->add_item($retailAlias, 1, '1', $discount));
        $lineNumber = (int) $this->findCartLine($saleLib->get_cart(), $retailUnitId)['line'];

        $this->assertFalse($saleLib->switch_item_unit(999999, $retailUnitId));
        $this->assertFalse($saleLib->switch_item_unit($lineNumber, 999999));
        $this->assertFalse($saleLib->switch_item_unit($lineNumber, $otherRetailUnitId));

        $cartLine = $this->findCartLine($saleLib->get_cart(), $retailUnitId);
        $this->assertSame('10000.00', number_format((float) $cartLine['price'], 2, '.', ''));
        $this->assertSame(Item_unit::TYPE_RETAIL, $cartLine['unit_type']);
    }

    public function testLargeUnitWithoutBarcodeCanBeSoldByBadgeAndPersistsSnapshot(): void
    {
        [$itemId, $retailUnitId, $largeUnitId] = $this->createItemWithUnits('NOBAR', '');
        $saleLib = new Sale_lib();
        $discount = '0';
        $retailAlias = 'NOBAR-RET-A';

        $this->assertTrue($saleLib->add_item($retailAlias, 1, '1', $discount));
        $retailLineNumber = (int) $this->findCartLine($saleLib->get_cart(), $retailUnitId)['line'];
        $this->assertTrue($saleLib->switch_item_unit($retailLineNumber, $largeUnitId));

        $cart = $saleLib->get_cart();
        $largeLine = $this->findCartLine($cart, $largeUnitId);
        $this->assertSame($itemId, (int) $largeLine['item_id']);
        $this->assertSame('225000.00', number_format((float) $largeLine['price'], 2, '.', ''));
        $this->assertSame('Thùng', $largeLine['unit_name']);
        $this->assertNull(model(Item_unit::class)->getLargeUnitByBarcode('NOBAR-BOX-A'));

        $dayId = (int) db_connect()->table('business_units')->select('id')->where('code', 'DAY')->get()->getRow()->id;
        $beforeQuantity = model(Business_unit_item_unit_quantity::class)->getQuantity($dayId, $largeUnitId);
        $items = $saleLib->get_cart();
        $taxes = [[], []];
        $saleStatus = (string) COMPLETED;
        $saleId = model(Sale::class)->save_value(
            NEW_ENTRY,
            $saleStatus,
            $items,
            NEW_ENTRY,
            $this->employeeId,
            'UNIT SNAPSHOT',
            'UNIT-' . uniqid('', false),
            null,
            null,
            SALE_TYPE_POS,
            [],
            null,
            $taxes
        );

        $this->assertGreaterThan(0, $saleId);
        $salesItem = db_connect()->table('sales_items')
            ->where('sale_id', $saleId)
            ->where('item_id', $itemId)
            ->get()
            ->getRow();
        $this->assertNotNull($salesItem);
        $this->assertSame($largeUnitId, (int) $salesItem->item_unit_id);
        $this->assertSame(Item_unit::TYPE_LARGE, $salesItem->unit_type);
        $this->assertSame('Thùng', $salesItem->unit_name);
        $this->assertSame('24.000', $salesItem->conversion_quantity);
        $this->assertSame($beforeQuantity - 1, model(Business_unit_item_unit_quantity::class)->getQuantity($dayId, $largeUnitId));
    }

    public function testScanningConflictBarcodeUsesRetailOwnerNotManualLargeUnit(): void
    {
        [$retailOwnerItemId] = $this->createItemWithUnits('OWNER');
        [, , $manualLargeUnitId] = $this->createItemWithUnits('MANUAL', '');
        $saleLib = new Sale_lib();
        $discount = '0';
        $conflictBarcode = 'OWNER-RET-A';

        $this->assertTrue($saleLib->add_item($conflictBarcode, 1, '1', $discount));

        $cart = $saleLib->get_cart();
        $this->assertCount(1, $cart);
        $line = reset($cart);
        $this->assertSame($retailOwnerItemId, (int) $line['item_id']);
        $this->assertNotSame($manualLargeUnitId, (int) ($line['item_unit_id'] ?? 0));
        $this->assertSame(Item_unit::TYPE_RETAIL, $line['unit_type']);
    }

    public function testRetailAliasWinsWhenSameCodeExistsAsLargeBarcode(): void
    {
        [$retailOwnerItemId, $retailOwnerUnitId] = $this->createItemWithUnits('WINNER');
        [, , $conflictingLargeUnitId] = $this->createItemWithUnits('LOSER', 'WINNER-RET-A');
        $saleLib = new Sale_lib();
        $discount = '0';
        $conflictBarcode = 'WINNER-RET-A';

        $this->assertTrue($saleLib->add_item($conflictBarcode, 1, '1', $discount));

        $cart = $saleLib->get_cart();
        $this->assertCount(1, $cart);
        $line = reset($cart);

        $this->assertSame($retailOwnerItemId, (int) $line['item_id']);
        $this->assertSame($retailOwnerUnitId, (int) $line['item_unit_id']);
        $this->assertNotSame($conflictingLargeUnitId, (int) ($line['item_unit_id'] ?? 0));
        $this->assertSame(Item_unit::TYPE_RETAIL, $line['unit_type']);
    }

    public function testBarcodeAutocompleteSuggestionsIncludeRetailAliasAndLargeUnitDetailsWithoutAliasDuplicates(): void
    {
        [$itemId] = $this->createItemWithUnits('SUGGEST');

        $primarySuggestions = model(Item::class)->get_search_suggestions('SUGGEST-RET', ['search_custom' => false, 'is_deleted' => false], true);
        $aliasSuggestions = model(Item::class)->get_search_suggestions('SUGGEST-RET-A', ['search_custom' => false, 'is_deleted' => false], true);
        $largeSuggestions = model(Item::class)->get_search_suggestions('SUGGEST-BOX-A', ['search_custom' => false, 'is_deleted' => false], true);

        $this->assertCount(1, array_filter($primarySuggestions, static fn (array $suggestion): bool => (int) $suggestion['value'] === $itemId));
        $this->assertCount(1, $aliasSuggestions);
        $this->assertSame($itemId, (int) $aliasSuggestions[0]['value']);
        $this->assertStringContainsString('SUGGEST Item', $aliasSuggestions[0]['label']);
        $this->assertStringContainsString('SUGGEST-RET-A', $aliasSuggestions[0]['label']);
        $this->assertStringContainsString('Chai', $aliasSuggestions[0]['label']);
        $this->assertSame('SUGGEST Item', $aliasSuggestions[0]['name']);
        $this->assertSame('SUGGEST-RET-A', $aliasSuggestions[0]['barcode']);
        $this->assertSame(10000.0, (float) $aliasSuggestions[0]['price']);
        $this->assertSame(to_currency_no_money(10000), $aliasSuggestions[0]['formatted_price']);
        $this->assertSame('Chai', $aliasSuggestions[0]['unit_name']);
        $this->assertSame($itemId, (int) $aliasSuggestions[0]['item_id']);
        $this->assertNull($aliasSuggestions[0]['item_unit_id']);

        $this->assertCount(1, $largeSuggestions);
        $this->assertSame('SUGGEST-BOX-A', $largeSuggestions[0]['value']);
        $this->assertStringContainsString('SUGGEST Item', $largeSuggestions[0]['label']);
        $this->assertStringContainsString('SUGGEST-BOX-A', $largeSuggestions[0]['label']);
        $this->assertStringContainsString('Thùng', $largeSuggestions[0]['label']);
        $this->assertSame('SUGGEST Item', $largeSuggestions[0]['name']);
        $this->assertSame('SUGGEST-BOX-A', $largeSuggestions[0]['barcode']);
        $this->assertSame(225000.0, (float) $largeSuggestions[0]['price']);
        $this->assertSame(to_currency_no_money(225000), $largeSuggestions[0]['formatted_price']);
        $this->assertSame('Thùng', $largeSuggestions[0]['unit_name']);
        $this->assertSame($itemId, (int) $largeSuggestions[0]['item_id']);
        $this->assertNotEmpty($largeSuggestions[0]['item_unit_id']);
    }

    private function createItemWithUnits(string $prefix = 'UNIT', ?string $largeBarcode = null): array
    {
        $itemData = [
            'name' => $prefix . ' Item',
            'category' => 'Unit',
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
            'pack_name' => 'Chai',
            'hsn_code' => '',
        ];

        $itemModel = model(Item::class);
        $this->assertTrue($itemModel->save_value($itemData));
        $itemId = (int) $itemData['item_id'];
        $this->assertTrue(model(Item_barcode::class)->saveAliases($itemId, [$prefix . '-RET', $prefix . '-RET-A']));

        $itemUnitModel = model(Item_unit::class);
        $retailUnitId = $itemUnitModel->upsertRetailUnit($itemId, 'Chai', 10000, 8000);
        $largeUnitId = $itemUnitModel->upsertLargeUnit($itemId, 'Thùng', $largeBarcode ?? $prefix . '-BOX-A', 24, 225000, 192000);

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

    private function removeUnitTestData(): void
    {
        $db = db_connect();
        $itemIds = array_map(
            'intval',
            array_column(
                $db->table('items')
                    ->select('item_id')
                    ->groupStart()
                    ->like('name', 'UNIT Item')
                    ->orLike('name', 'SWITCH Item')
                    ->orLike('name', 'SWBACK Item')
                    ->orLike('name', 'SWVALID Item')
                    ->orLike('name', 'SWOTHER Item')
                    ->orLike('name', 'NOBAR Item')
                    ->orLike('name', 'OWNER Item')
                    ->orLike('name', 'MANUAL Item')
                    ->orLike('name', 'WINNER Item')
                    ->orLike('name', 'LOSER Item')
                    ->orLike('name', 'SUGGEST Item')
                    ->groupEnd()
                    ->get()
                    ->getResultArray(),
                'item_id'
            )
        );

        $saleIds = array_map(
            'intval',
            array_column(
                $db->table('sales')
                    ->select('sale_id')
                    ->where('comment', 'UNIT SNAPSHOT')
                    ->get()
                    ->getResultArray(),
                'sale_id'
            )
        );

        if ($saleIds !== []) {
            $db->table('sales_payments')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales_items_taxes')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales_items')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales_taxes')->whereIn('sale_id', $saleIds)->delete();
            $db->table('sales')->whereIn('sale_id', $saleIds)->delete();
        }

        if ($itemIds !== []) {
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
