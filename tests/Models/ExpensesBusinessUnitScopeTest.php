<?php

namespace Tests\Models;

use App\Database\Migrations\AddBusinessUnits;
use App\Database\Migrations\AddExpensesBusinessUnitScope;
use App\Database\Migrations\AddFixedEmployeeAccountScopes;
use App\Models\Expense;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use RuntimeException;

require_once APPPATH . 'Database/Migrations/20260724000000_AddFixedEmployeeAccountScopes.php';
require_once APPPATH . 'Database/Migrations/20260725000000_AddBusinessUnits.php';
require_once APPPATH . 'Database/Migrations/20260726000000_AddExpensesBusinessUnitScope.php';

class ExpensesBusinessUnitScopeTest extends CIUnitTestCase
{
    private const INITIAL_PASSWORD = 'TestOnlyPassword123!';
    private const TEST_PREFIX = 'EXP_BU_SCOPE_TEST_';
    private const FIXED_ACCOUNTS = [
        'NguyenDuyTai',
        'NguyenDuyTai1',
        'NguyenDuyTai2',
    ];

    private string|false $previousInitialPassword;
    private int $categoryId;
    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousInitialPassword = getenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');

        $this->removeTestExpenses();
        $this->removeTestSupplier();
        $this->removeTestCategory();
        $this->removeFixedAccounts();

        putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . self::INITIAL_PASSWORD);

        (new AddFixedEmployeeAccountScopes())->up();
        (new AddBusinessUnits())->up();
        (new AddExpensesBusinessUnitScope())->up();

        $this->categoryId = $this->createTestCategory();
        $this->supplierId = $this->createTestSupplier();
    }

    protected function tearDown(): void
    {
        $this->removeTestExpenses();
        $this->removeTestSupplier();
        $this->removeTestCategory();
        Services::session()->destroy();

        if ($this->previousInitialPassword === false) {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');
        } else {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . $this->previousInitialPassword);
        }

        parent::tearDown();
    }

    public function testMigrationAddsExpensesBusinessUnitColumnIndexesForeignKeyAndBackfillsKnownEmployees(): void
    {
        $dayExpenseId = $this->createExpense($this->getEmployeeId('NguyenDuyTai'), null, self::TEST_PREFIX . 'BACKFILL_DAY');
        $nightExpenseId = $this->createExpense($this->getEmployeeId('NguyenDuyTai1'), null, self::TEST_PREFIX . 'BACKFILL_NIGHT');
        $aggregateExpenseId = $this->createExpense($this->getEmployeeId('NguyenDuyTai2'), null, self::TEST_PREFIX . 'BACKFILL_NULL');

        (new AddExpensesBusinessUnitScope())->up();

        $this->assertTrue(db_connect()->fieldExists('business_unit_id', 'expenses'));
        $this->assertSame($this->getBusinessUnitId('DAY'), $this->getExpenseBusinessUnitId($dayExpenseId));
        $this->assertSame($this->getBusinessUnitId('NIGHT'), $this->getExpenseBusinessUnitId($nightExpenseId));
        $this->assertNull($this->getExpenseBusinessUnitId($aggregateExpenseId));
        $this->assertSame([
            'date_index'         => 2,
            'deleted_date_index' => 3,
            'foreign_key'        => 1,
        ], $this->getExpensesScopeMetadataCounts());
    }

    public function testMigrationIsIdempotentForIndexesAndForeignKey(): void
    {
        $before = $this->getExpensesScopeMetadataCounts();

        (new AddExpensesBusinessUnitScope())->up();

        $this->assertSame($before, $this->getExpensesScopeMetadataCounts());
    }

    public function testSaveValueAssignsCurrentBusinessUnitForDayAndNight(): void
    {
        $this->loginAsUsername('NguyenDuyTai');
        $dayExpenseId = $this->saveExpenseThroughModel();

        $this->loginAsUsername('NguyenDuyTai1');
        $nightExpenseId = $this->saveExpenseThroughModel();

        $this->assertSame($this->getBusinessUnitId('DAY'), $this->getExpenseBusinessUnitId($dayExpenseId));
        $this->assertSame($this->getBusinessUnitId('NIGHT'), $this->getExpenseBusinessUnitId($nightExpenseId));
        $this->assertSame($this->categoryId, $this->getExpenseCategoryId($dayExpenseId));
        $this->assertSame($this->supplierId, $this->getExpenseSupplierId($nightExpenseId));
    }

    public function testAggregateCannotSaveOperationalExpense(): void
    {
        $this->loginAsUsername('NguyenDuyTai2');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An operational business unit is required for this action.');

        $this->saveExpenseThroughModel();
    }

    public function testInputCannotSpoofBusinessUnitOnCreateOrUpdate(): void
    {
        $this->loginAsUsername('NguyenDuyTai');
        Services::session()->set('business_unit_id', $this->getBusinessUnitId('NIGHT'));
        $_POST['business_unit_id'] = (string) $this->getBusinessUnitId('NIGHT');

        try {
            $expenseId = $this->saveExpenseThroughModel([
                'employee_id'      => $this->getEmployeeId('NguyenDuyTai1'),
                'business_unit_id' => $this->getBusinessUnitId('NIGHT'),
            ]);
            $updated = model(Expense::class)->update($expenseId, [
                'description'      => self::TEST_PREFIX . 'SPOOF_UPDATE',
                'employee_id'      => $this->getEmployeeId('NguyenDuyTai1'),
                'business_unit_id' => $this->getBusinessUnitId('NIGHT'),
            ]);
        } finally {
            unset($_POST['business_unit_id']);
        }

        $this->assertTrue($updated);
        $this->assertSame($this->getBusinessUnitId('DAY'), $this->getExpenseBusinessUnitId($expenseId));
        $this->assertSame(self::TEST_PREFIX . 'SPOOF_UPDATE', $this->getExpenseDescription($expenseId));
    }

    public function testDayAndNightCannotReadEachOtherExpensesOrNullScopedExpense(): void
    {
        $dayExpenseId = $this->createExpense($this->getEmployeeId('NguyenDuyTai'), $this->getBusinessUnitId('DAY'), self::TEST_PREFIX . 'READ_DAY');
        $nightExpenseId = $this->createExpense($this->getEmployeeId('NguyenDuyTai1'), $this->getBusinessUnitId('NIGHT'), self::TEST_PREFIX . 'READ_NIGHT');
        $nullExpenseId = $this->createExpense($this->getEmployeeId('NguyenDuyTai2'), null, self::TEST_PREFIX . 'READ_NULL');

        $expense = model(Expense::class);
        $this->loginAsUsername('NguyenDuyTai');

        $this->assertSame($dayExpenseId, (int) $expense->get_info($dayExpenseId)->expense_id);
        $this->assertSame(NEW_ENTRY, (int) $expense->get_info($nightExpenseId)->expense_id);
        $this->assertSame(NEW_ENTRY, (int) $expense->get_info($nullExpenseId)->expense_id);
        $this->assertTrue($expense->exists($dayExpenseId));
        $this->assertFalse($expense->exists($nightExpenseId));
        $this->assertSame(1, $expense->get_multiple_info([$dayExpenseId, $nightExpenseId, $nullExpenseId])->getNumRows());
        $this->assertSame(0, $expense->get_expense_payment($nightExpenseId)->getNumRows());

        $this->loginAsUsername('NguyenDuyTai1');

        $this->assertSame(NEW_ENTRY, (int) $expense->get_info($dayExpenseId)->expense_id);
        $this->assertSame($nightExpenseId, (int) $expense->get_info($nightExpenseId)->expense_id);
    }

    public function testScopedWriteOperationsCannotAffectAnotherBusinessUnitOrNullScopedExpense(): void
    {
        $dayExpenseId = $this->createExpense($this->getEmployeeId('NguyenDuyTai'), $this->getBusinessUnitId('DAY'), self::TEST_PREFIX . 'WRITE_DAY');
        $nightExpenseId = $this->createExpense($this->getEmployeeId('NguyenDuyTai1'), $this->getBusinessUnitId('NIGHT'), self::TEST_PREFIX . 'WRITE_NIGHT');
        $nullExpenseId = $this->createExpense($this->getEmployeeId('NguyenDuyTai2'), null, self::TEST_PREFIX . 'WRITE_NULL');

        $expense = model(Expense::class);
        $this->loginAsUsername('NguyenDuyTai');

        $crossScopeData = $this->expenseData([
            'description'      => self::TEST_PREFIX . 'CROSS_SAVE',
            'business_unit_id' => $this->getBusinessUnitId('NIGHT'),
        ]);

        $this->assertFalse($expense->save_value($crossScopeData, $nightExpenseId));
        $this->assertFalse($expense->update($nightExpenseId, ['description' => self::TEST_PREFIX . 'CROSS_UPDATE']));
        $this->assertFalse($expense->update($nullExpenseId, ['description' => self::TEST_PREFIX . 'NULL_UPDATE']));

        $this->assertSame(self::TEST_PREFIX . 'WRITE_NIGHT', $this->getExpenseDescription($nightExpenseId));
        $this->assertSame(self::TEST_PREFIX . 'WRITE_NULL', $this->getExpenseDescription($nullExpenseId));
        $this->assertSame($this->getBusinessUnitId('NIGHT'), $this->getExpenseBusinessUnitId($nightExpenseId));
        $this->assertNull($this->getExpenseBusinessUnitId($nullExpenseId));

        $ownScopeData = $this->expenseData(['description' => self::TEST_PREFIX . 'OWN_SAVE']);
        $this->assertTrue($expense->save_value($ownScopeData, $dayExpenseId));
        $this->assertSame(self::TEST_PREFIX . 'OWN_SAVE', $this->getExpenseDescription($dayExpenseId));
    }

    public function testScopedDeleteCannotAffectAnotherBusinessUnit(): void
    {
        $dayExpenseId = $this->createExpense($this->getEmployeeId('NguyenDuyTai'), $this->getBusinessUnitId('DAY'), self::TEST_PREFIX . 'DELETE_DAY');
        $nightExpenseId = $this->createExpense($this->getEmployeeId('NguyenDuyTai1'), $this->getBusinessUnitId('NIGHT'), self::TEST_PREFIX . 'DELETE_NIGHT');

        $expense = model(Expense::class);
        $this->loginAsUsername('NguyenDuyTai');

        $this->assertFalse($expense->delete($nightExpenseId));
        $this->assertSame(0, $this->getExpenseDeleted($nightExpenseId));

        $this->assertFalse($expense->delete_list([$nightExpenseId]));
        $this->assertSame(0, $this->getExpenseDeleted($nightExpenseId));

        $this->assertFalse($expense->delete_list([$dayExpenseId, $nightExpenseId]));
        $this->assertSame(0, $this->getExpenseDeleted($dayExpenseId));
        $this->assertSame(0, $this->getExpenseDeleted($nightExpenseId));

        $this->assertTrue($expense->delete_list([$dayExpenseId]));
        $this->assertSame(1, $this->getExpenseDeleted($dayExpenseId));
        $this->assertSame(0, $this->getExpenseDeleted($nightExpenseId));
    }

    public function testSearchFoundRowsAndPaymentSummaryAreScoped(): void
    {
        $this->createExpense($this->getEmployeeId('NguyenDuyTai'), $this->getBusinessUnitId('DAY'), self::TEST_PREFIX . 'SEARCH_DAY', '10.00', 'Cash');
        $this->createExpense($this->getEmployeeId('NguyenDuyTai1'), $this->getBusinessUnitId('NIGHT'), self::TEST_PREFIX . 'SEARCH_NIGHT', '20.00', 'Cash');
        $this->createExpense($this->getEmployeeId('NguyenDuyTai2'), null, self::TEST_PREFIX . 'SEARCH_NULL', '30.00', 'Cash');

        $expense = model(Expense::class);
        $filters = $this->expenseSearchFilters();

        $this->loginAsUsername('NguyenDuyTai');

        $this->assertSame(1, $expense->search('', $filters)->getNumRows());
        $this->assertSame(1, $expense->get_found_rows('', $filters));

        $summary = $expense->get_payments_summary('', $filters);

        $this->assertCount(1, $summary);
        $this->assertSame('Cash', $summary[0]['payment_type']);
        $this->assertEquals(10.00, (float) $summary[0]['amount']);

        $this->loginAsUsername('NguyenDuyTai1');

        $this->assertSame(1, $expense->search('', $filters)->getNumRows());
        $this->assertSame(1, $expense->get_found_rows('', $filters));
        $this->assertEquals(20.00, (float) $expense->get_payments_summary('', $filters)[0]['amount']);
    }

    private function saveExpenseThroughModel(array $overrides = []): int
    {
        $expenseData = $this->expenseData($overrides);
        $this->assertTrue(model(Expense::class)->save_value($expenseData));

        return (int) $expenseData['expense_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseData(array $overrides = []): array
    {
        return array_merge([
            'date'                => '2035-07-26 10:00:00',
            'supplier_id'         => $this->supplierId,
            'supplier_tax_code'   => self::TEST_PREFIX . 'TAX',
            'amount'              => '10.00',
            'tax_amount'          => '1.00',
            'payment_type'        => 'Cash',
            'expense_category_id' => $this->categoryId,
            'description'         => self::TEST_PREFIX . uniqid('SAVE_', false),
            'employee_id'         => $this->getLoggedInEmployeeId(),
            'deleted'             => 0,
        ], $overrides);
    }

    private function createExpense(int $employeeId, ?int $businessUnitId, string $description, string $amount = '10.00', string $paymentType = 'Cash'): int
    {
        $db = db_connect();
        $db->table('expenses')->insert([
            'date'                => '2035-07-26 10:00:00',
            'amount'              => $amount,
            'payment_type'        => $paymentType,
            'expense_category_id' => $this->categoryId,
            'description'         => $description,
            'employee_id'         => $employeeId,
            'business_unit_id'    => $businessUnitId,
            'deleted'             => 0,
            'supplier_tax_code'   => self::TEST_PREFIX . 'TAX',
            'tax_amount'          => '1.00',
            'supplier_id'         => $this->supplierId,
        ]);

        return (int) $db->insertID();
    }

    private function createTestCategory(): int
    {
        $categoryName = self::TEST_PREFIX . uniqid('CATEGORY_', false);
        db_connect()->table('expense_categories')->insert([
            'category_name'        => $categoryName,
            'category_description' => self::TEST_PREFIX . 'CATEGORY',
            'deleted'              => 0,
        ]);

        return (int) db_connect()->insertID();
    }

    private function createTestSupplier(): int
    {
        $db = db_connect();
        $db->table('people')->insert([
            'first_name'   => self::TEST_PREFIX . 'SUPPLIER',
            'last_name'    => 'Shared',
            'phone_number' => '',
            'email'        => '',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
        ]);
        $supplierId = (int) $db->insertID();

        $db->table('suppliers')->insert([
            'person_id'      => $supplierId,
            'company_name'   => self::TEST_PREFIX . 'SUPPLIER',
            'agency_name'    => '',
            'account_number' => self::TEST_PREFIX . uniqid('ACCOUNT_', false),
            'deleted'        => 0,
            'category'       => COST_SUPPLIER,
        ]);

        return $supplierId;
    }

    /**
     * @return array<string, bool|string>
     */
    private function expenseSearchFilters(): array
    {
        return [
            'start_date'  => '2035-07-26',
            'end_date'    => '2035-07-26',
            'only_cash'   => false,
            'only_due'    => false,
            'only_check'  => false,
            'only_credit' => false,
            'only_debit'  => false,
            'is_deleted'  => false,
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

    private function getExpenseBusinessUnitId(int $expenseId): ?int
    {
        $businessUnitId = db_connect()
            ->table('expenses')
            ->select('business_unit_id')
            ->where('expense_id', $expenseId)
            ->get()
            ->getRow()
            ->business_unit_id;

        return $businessUnitId === null ? null : (int) $businessUnitId;
    }

    private function getExpenseDescription(int $expenseId): string
    {
        return db_connect()
            ->table('expenses')
            ->select('description')
            ->where('expense_id', $expenseId)
            ->get()
            ->getRow()
            ->description;
    }

    private function getExpenseDeleted(int $expenseId): int
    {
        return (int) db_connect()
            ->table('expenses')
            ->select('deleted')
            ->where('expense_id', $expenseId)
            ->get()
            ->getRow()
            ->deleted;
    }

    private function getExpenseCategoryId(int $expenseId): int
    {
        return (int) db_connect()
            ->table('expenses')
            ->select('expense_category_id')
            ->where('expense_id', $expenseId)
            ->get()
            ->getRow()
            ->expense_category_id;
    }

    private function getExpenseSupplierId(int $expenseId): int
    {
        return (int) db_connect()
            ->table('expenses')
            ->select('supplier_id')
            ->where('expense_id', $expenseId)
            ->get()
            ->getRow()
            ->supplier_id;
    }

    /**
     * @return array<string, int>
     */
    private function getExpensesScopeMetadataCounts(): array
    {
        $db = db_connect();

        return [
            'date_index'         => (int) $db->query(
                'SELECT COUNT(*) AS count FROM information_schema.statistics'
                . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$db->prefixTable('expenses'), 'expenses_business_unit_date']
            )->getRow()->count,
            'deleted_date_index' => (int) $db->query(
                'SELECT COUNT(*) AS count FROM information_schema.statistics'
                . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$db->prefixTable('expenses'), 'expenses_business_unit_deleted_date']
            )->getRow()->count,
            'foreign_key'        => (int) $db->query(
                'SELECT COUNT(*) AS count FROM information_schema.table_constraints'
                . ' WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?',
                [$db->prefixTable('expenses'), 'ospos_expenses_business_unit_id_fk']
            )->getRow()->count,
        ];
    }

    private function removeTestExpenses(): void
    {
        $db = db_connect();
        $db->table('expenses')
            ->like('description', self::TEST_PREFIX, 'after')
            ->delete();
    }

    private function removeTestCategory(): void
    {
        db_connect()->table('expense_categories')
            ->like('category_name', self::TEST_PREFIX, 'after')
            ->delete();
    }

    private function removeTestSupplier(): void
    {
        $db = db_connect();
        $personIds = array_column(
            $db->table('people')
                ->select('person_id')
                ->like('first_name', self::TEST_PREFIX . 'SUPPLIER', 'after')
                ->get()
                ->getResultArray(),
            'person_id'
        );

        if ($personIds === []) {
            return;
        }

        $db->table('suppliers')->whereIn('person_id', $personIds)->delete();
        $db->table('people')->whereIn('person_id', $personIds)->delete();
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
