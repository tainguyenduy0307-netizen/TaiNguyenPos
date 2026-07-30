<?php

namespace Tests\Models;

use App\Database\Migrations\AddBusinessUnits;
use App\Database\Migrations\AddBusinessUnitInvoiceSequences;
use App\Database\Migrations\AddFixedEmployeeAccountScopes;
use App\Database\Migrations\AddSalesBusinessUnitScope;
use App\Libraries\BusinessUnitInvoiceSequenceService;
use App\Models\Sale;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use RuntimeException;

require_once APPPATH . 'Database/Migrations/20260724000000_AddFixedEmployeeAccountScopes.php';
require_once APPPATH . 'Database/Migrations/20260725000000_AddBusinessUnits.php';
require_once APPPATH . 'Database/Migrations/20260725000001_AddSalesBusinessUnitScope.php';
require_once APPPATH . 'Database/Migrations/20260726000004_AddBusinessUnitInvoiceSequences.php';

class BusinessUnitInvoiceSequenceTest extends CIUnitTestCase
{
    private const INITIAL_PASSWORD = 'TestOnlyPassword123!';
    private const TEST_PREFIX = 'BU_INVOICE_SEQUENCE_TEST_';
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
        (new AddBusinessUnitInvoiceSequences())->up();

        $this->removeSalesWithInvoiceNumbers(['0001', '0002', '10000']);
        $this->resetOperationalSequences();
        $this->itemId = $this->createTestItem();
    }

    protected function tearDown(): void
    {
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

    public function testMigrationCreatesSequenceTableAndScopedSalesUniqueIndex(): void
    {
        $db = db_connect();
        $before = $this->getSequenceMetadataCounts();

        (new AddBusinessUnitInvoiceSequences())->up();

        $this->assertTrue($db->tableExists('business_unit_invoice_sequences'));
        $this->assertSame($before, $this->getSequenceMetadataCounts());
        $this->assertSame(0, $this->indexCount('sales', 'invoice_number'));
        $this->assertSame(1, $this->indexCount('sales', 'sales_business_unit_invoice_number'));
        $this->assertSame(
            ['DAY', 'NIGHT'],
            array_column(
                $db->table('business_unit_invoice_sequences')
                    ->select('business_units.code')
                    ->join('business_units', 'business_units.id = business_unit_invoice_sequences.business_unit_id')
                    ->orderBy('business_units.code')
                    ->get()
                    ->getResultArray(),
                'code'
            )
        );
    }

    public function testMigrationSeedsMaxNumericInvoicesAndDoesNotLowerExistingSequences(): void
    {
        $dayBusinessUnitId = $this->getBusinessUnitId('DAY');
        $nightBusinessUnitId = $this->getBusinessUnitId('NIGHT');
        $dayNumericInvoice = (string) random_int(200000, 299999);
        $nightNumericInvoice = (string) random_int(300000, 399999);

        $this->createDirectSale($dayBusinessUnitId, $dayNumericInvoice);
        $this->createDirectSale($dayBusinessUnitId, 'INV-' . $dayNumericInvoice);
        $this->createDirectSale($nightBusinessUnitId, $nightNumericInvoice);
        $this->setSequence($dayBusinessUnitId, 0);
        $this->setSequence($nightBusinessUnitId, (int) $nightNumericInvoice + 5);

        (new AddBusinessUnitInvoiceSequences())->up();

        $this->assertSame((int) $dayNumericInvoice, $this->getSequenceValue($dayBusinessUnitId));
        $this->assertSame((int) $nightNumericInvoice + 5, $this->getSequenceValue($nightBusinessUnitId));
    }

    public function testCompletedDayAndNightSalesReceiveIndependentNumbersAndIgnoreSpoofedInvoiceNumber(): void
    {
        $this->loginAsUsername('NguyenDuyTai');
        $dayFirstSaleId = $this->saveSaleThroughModel(COMPLETED, SALE_TYPE_POS, NEW_ENTRY, '9999');
        $daySecondSaleId = $this->saveSaleThroughModel();

        $this->loginAsUsername('NguyenDuyTai1');
        $nightFirstSaleId = $this->saveSaleThroughModel(COMPLETED, SALE_TYPE_POS, NEW_ENTRY, '9999');
        $nightSecondSaleId = $this->saveSaleThroughModel();

        $this->assertSame('0001', $this->getSaleInvoiceNumber($dayFirstSaleId));
        $this->assertSame('0002', $this->getSaleInvoiceNumber($daySecondSaleId));
        $this->assertSame('0001', $this->getSaleInvoiceNumber($nightFirstSaleId));
        $this->assertSame('0002', $this->getSaleInvoiceNumber($nightSecondSaleId));
    }

    public function testCompletedSaleKeepsInvoiceNumberWhenEditedAndDeleteDoesNotReuseIt(): void
    {
        $this->loginAsUsername('NguyenDuyTai');
        $saleId = $this->saveSaleThroughModel();

        $this->saveSaleThroughModel(COMPLETED, SALE_TYPE_POS, $saleId, '9999');
        $this->assertSame('0001', $this->getSaleInvoiceNumber($saleId));

        $this->assertTrue(model(Sale::class)->delete($saleId, false, false, $this->getLoggedInEmployeeId()));

        $newSaleId = $this->saveSaleThroughModel();
        $this->assertSame('0002', $this->getSaleInvoiceNumber($newSaleId));
    }

    public function testSuspendedSalesDoNotReceiveInvoiceUntilCompleted(): void
    {
        $this->loginAsUsername('NguyenDuyTai');

        $suspendedSaleId = $this->saveSaleThroughModel(SUSPENDED, SALE_TYPE_POS, NEW_ENTRY, '9999');
        $quoteSaleId = $this->saveSaleThroughModel(SUSPENDED, SALE_TYPE_QUOTE, NEW_ENTRY, '9999');
        $workOrderSaleId = $this->saveSaleThroughModel(SUSPENDED, SALE_TYPE_WORK_ORDER, NEW_ENTRY, '9999');

        $this->assertNull($this->getSaleInvoiceNumber($suspendedSaleId));
        $this->assertNull($this->getSaleInvoiceNumber($quoteSaleId));
        $this->assertNull($this->getSaleInvoiceNumber($workOrderSaleId));

        $this->saveSaleThroughModel(COMPLETED, SALE_TYPE_POS, $suspendedSaleId, '9999');
        $this->assertSame('0001', $this->getSaleInvoiceNumber($suspendedSaleId));
    }

    public function testSequenceBeyond9999IsNotResetOrTruncated(): void
    {
        $this->loginAsUsername('NguyenDuyTai');
        $this->setSequence($this->getBusinessUnitId('DAY'), 9999);

        $saleId = $this->saveSaleThroughModel();

        $this->assertSame('10000', $this->getSaleInvoiceNumber($saleId));
    }

    public function testDuplicateInvoiceIsBlockedWithinUnitButAllowedAcrossDayAndNight(): void
    {
        $dayBusinessUnitId = $this->getBusinessUnitId('DAY');
        $nightBusinessUnitId = $this->getBusinessUnitId('NIGHT');

        $this->createDirectSale($dayBusinessUnitId, self::TEST_PREFIX . 'DUPLICATE');
        $this->createDirectSale($nightBusinessUnitId, self::TEST_PREFIX . 'DUPLICATE');

        $this->expectException(DatabaseException::class);
        $this->createDirectSale($dayBusinessUnitId, self::TEST_PREFIX . 'DUPLICATE');
    }

    public function testAggregateAndNullScopeDoNotAllocateInvoiceNumbers(): void
    {
        $nullScopeSaleId = $this->createDirectSale(null, null);
        $this->assertNull($this->getSaleInvoiceNumber($nullScopeSaleId));

        $this->loginAsUsername('NguyenDuyTai2');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An operational business unit is required for this action.');

        (new BusinessUnitInvoiceSequenceService())->acquireNextForCurrentBusinessUnit();
    }

    public function testAllocationUsesDatabaseRowLock(): void
    {
        $serviceSource = file_get_contents(APPPATH . 'Libraries/BusinessUnitInvoiceSequenceService.php');

        $this->assertStringContainsString('FOR UPDATE', $serviceSource);
        $this->assertStringContainsString('business_unit_id = ?', $serviceSource);
    }

    private function saveSaleThroughModel(
        int $saleStatus = COMPLETED,
        int $saleType = SALE_TYPE_POS,
        int $saleId = NEW_ENTRY,
        ?string $spoofedInvoiceNumber = null
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
                'price'         => '2.00',
                'item_location' => 1,
                'print_option'  => PRINT_ALL,
            ],
        ];
        $payments = [];
        $taxes = [[], []];

        $saleId = model(Sale::class)->save_value(
            $saleId,
            $status,
            $items,
            NEW_ENTRY,
            $this->getLoggedInEmployeeId(),
            self::TEST_PREFIX . bin2hex(random_bytes(8)),
            $spoofedInvoiceNumber,
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

    private function createDirectSale(?int $businessUnitId, ?string $invoiceNumber): int
    {
        db_connect()->table('sales')->insert([
            'sale_time'        => '2030-07-26 10:00:00',
            'customer_id'      => null,
            'employee_id'      => 1,
            'business_unit_id' => $businessUnitId,
            'comment'          => self::TEST_PREFIX . ($invoiceNumber ?? 'NULL_SCOPE'),
            'invoice_number'   => $invoiceNumber,
            'sale_status'      => COMPLETED,
            'sale_type'        => SALE_TYPE_POS,
        ]);

        return (int) db_connect()->insertID();
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

    private function getSaleInvoiceNumber(int $saleId): ?string
    {
        $invoiceNumber = db_connect()
            ->table('sales')
            ->select('invoice_number')
            ->where('sale_id', $saleId)
            ->get()
            ->getRow()
            ->invoice_number;

        return $invoiceNumber === null ? null : (string) $invoiceNumber;
    }

    private function getSequenceValue(int $businessUnitId): int
    {
        return (int) db_connect()
            ->table('business_unit_invoice_sequences')
            ->select('last_invoice_number')
            ->where('business_unit_id', $businessUnitId)
            ->get()
            ->getRow()
            ->last_invoice_number;
    }

    private function setSequence(int $businessUnitId, int $lastInvoiceNumber): void
    {
        db_connect()->table('business_unit_invoice_sequences')
            ->where('business_unit_id', $businessUnitId)
            ->update([
                'last_invoice_number' => $lastInvoiceNumber,
                'updated_at'          => date('Y-m-d H:i:s'),
            ]);
    }

    private function resetOperationalSequences(): void
    {
        foreach (['DAY', 'NIGHT'] as $code) {
            $this->setSequence($this->getBusinessUnitId($code), 0);
        }
    }

    /**
     * @return array<string, int>
     */
    private function getSequenceMetadataCounts(): array
    {
        return [
            'primary_key' => $this->constraintCount('business_unit_invoice_sequences', 'PRIMARY', 'PRIMARY KEY'),
            'foreign_key' => $this->constraintCount(
                'business_unit_invoice_sequences',
                'ospos_bu_invoice_sequences_business_unit_id_fk',
                'FOREIGN KEY'
            ),
            'composite_unique' => $this->indexCount('sales', 'sales_business_unit_invoice_number'),
        ];
    }

    private function constraintCount(string $table, string $constraintName, string $constraintType): int
    {
        $db = db_connect();

        return (int) $db->query(
            'SELECT COUNT(*) AS count FROM information_schema.table_constraints'
            . ' WHERE table_schema = DATABASE()'
            . ' AND table_name = ?'
            . ' AND constraint_name = ?'
            . ' AND constraint_type = ?',
            [$db->prefixTable($table), $constraintName, $constraintType]
        )->getRow()->count;
    }

    private function indexCount(string $table, string $indexName): int
    {
        $db = db_connect();

        return (int) $db->query(
            'SELECT COUNT(DISTINCT index_name) AS count FROM information_schema.statistics'
            . ' WHERE table_schema = DATABASE()'
            . ' AND table_name = ?'
            . ' AND index_name = ?',
            [$db->prefixTable($table), $indexName]
        )->getRow()->count;
    }

    private function removeSalesWithInvoiceNumbers(array $invoiceNumbers): void
    {
        $saleIds = array_column(
            db_connect()->table('sales')
                ->select('sale_id')
                ->whereIn('invoice_number', $invoiceNumbers)
                ->whereIn('business_unit_id', [$this->getBusinessUnitId('DAY'), $this->getBusinessUnitId('NIGHT')])
                ->get()
                ->getResultArray(),
            'sale_id'
        );

        $this->removeSalesByIds(array_map('intval', $saleIds));
    }

    private function removeTestSales(): void
    {
        $saleIds = array_column(
            db_connect()->table('sales')
                ->select('sale_id')
                ->like('comment', self::TEST_PREFIX, 'after')
                ->get()
                ->getResultArray(),
            'sale_id'
        );

        $this->removeSalesByIds(array_map('intval', $saleIds));
    }

    private function removeSalesByIds(array $saleIds): void
    {
        if ($saleIds === []) {
            return;
        }

        $db = db_connect();
        $db->table('sales_payments')->whereIn('sale_id', $saleIds)->delete();
        $db->table('sales_items_taxes')->whereIn('sale_id', $saleIds)->delete();
        $db->table('sales_taxes')->whereIn('sale_id', $saleIds)->delete();
        $db->table('sales_items')->whereIn('sale_id', $saleIds)->delete();
        if ($db->tableExists('customer_loyalty_ledger')) {
            $db->table('customer_loyalty_ledger')->whereIn('sale_id', $saleIds)->delete();
        }
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

        if ($itemIds !== []) {
            $db->table('inventory')->whereIn('trans_items', $itemIds)->delete();
            $db->table('item_quantities')->whereIn('item_id', $itemIds)->delete();
            $db->table('items')->whereIn('item_id', $itemIds)->delete();
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
