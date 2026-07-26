<?php

namespace Tests\Models;

use App\Controllers\Secure_Controller;
use App\Database\Migrations\AddFixedEmployeeAccountScopes;
use App\Database\Migrations\GrantAggregateReportAccess;
use App\Models\Employee;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use RuntimeException;

require_once APPPATH . 'Database/Migrations/20260724000000_AddFixedEmployeeAccountScopes.php';
require_once APPPATH . 'Database/Migrations/20260726000001_GrantAggregateReportAccess.php';

class FixedEmployeeAccountsTest extends CIUnitTestCase
{
    private const INITIAL_PASSWORD = 'TestOnlyPassword123!';

    private string|false $previousInitialPassword;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousInitialPassword = getenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');
        $this->removeFixedAccounts();
        putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . self::INITIAL_PASSWORD);

        (new AddFixedEmployeeAccountScopes())->up();
        (new GrantAggregateReportAccess())->up();
    }

    protected function tearDown(): void
    {
        if ($this->previousInitialPassword === false) {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');
        } else {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . $this->previousInitialPassword);
        }

        parent::tearDown();
    }

    private const FIXED_ACCOUNTS = [
        'NguyenDuyTai'  => 'DAY',
        'NguyenDuyTai1' => 'NIGHT',
        'NguyenDuyTai2' => 'AGGREGATE',
    ];

    public function testFixedAccountsExistWithExpectedScopes(): void
    {
        foreach (self::FIXED_ACCOUNTS as $username => $scope) {
            $account = $this->getEmployeeByUsername($username);

            $this->assertNotNull($account);
            $this->assertSame($scope, $account->account_scope);
            $this->assertSame('0', (string)$account->deleted);
        }

        $this->assertSame(3, $this->countFixedAccounts());
    }

    public function testInitialPasswordLogsInAndIsNotStoredAsPlaintext(): void
    {
        foreach (array_keys(self::FIXED_ACCOUNTS) as $username) {
            $employee = model(Employee::class);

            $this->assertTrue($employee->login($username, self::INITIAL_PASSWORD));

            $account = $this->getEmployeeByUsername($username);
            $this->assertNotSame(self::INITIAL_PASSWORD, $account->password);
            $this->assertTrue(password_verify(self::INITIAL_PASSWORD, $account->password));
        }
    }

    public function testMigrationIsIdempotentForFixedAccounts(): void
    {
        (new AddFixedEmployeeAccountScopes())->up();
        (new GrantAggregateReportAccess())->up();

        $this->assertSame(3, $this->countFixedAccounts());
        $this->assertSame(1, $this->grantCountForUsername('NguyenDuyTai2', 'reports'));
    }

    public function testFixedUsernameAndAccountScopeCannotBeChangedThroughEmployeeSave(): void
    {
        $day = $this->getEmployeeByUsername('NguyenDuyTai');
        $employee = model(Employee::class);

        $personData = [
            'first_name'   => $day->first_name,
            'last_name'    => $day->last_name,
            'email'        => $day->email,
            'phone_number' => $day->phone_number,
        ];
        $employeeData = [
            'username'      => 'ChangedUsername',
            'account_scope' => 'NIGHT',
            'language_code' => $day->language_code,
            'language'      => $day->language,
        ];
        $grants = $employee->get_employee_grants((int)$day->person_id);

        $this->assertFalse($employee->save_employee($personData, $employeeData, $grants, (int)$day->person_id));

        $unchanged = $this->getEmployeeByUsername('NguyenDuyTai');
        $this->assertSame('DAY', $unchanged->account_scope);
    }

    public function testFixedAccountsCannotBeDeleted(): void
    {
        $day = $this->getEmployeeByUsername('NguyenDuyTai');
        $this->loginAs((int)$this->getEmployeeByUsername('NguyenDuyTai1')->person_id);

        $employee = model(Employee::class);

        $this->assertFalse($employee->delete((int)$day->person_id));
        $this->assertFalse($employee->delete_list([(int)$day->person_id]));
    }

    public function testAccountCannotChangeAnotherAccountPassword(): void
    {
        $day = $this->getEmployeeByUsername('NguyenDuyTai');
        $night = $this->getEmployeeByUsername('NguyenDuyTai1');
        $this->loginAs((int)$day->person_id);

        $employee = model(Employee::class);

        $this->assertFalse($employee->change_password([
            'password'     => password_hash('newpassword123', PASSWORD_DEFAULT),
            'hash_version' => 2,
        ], (int)$night->person_id));
    }

    public function testFixedAccountCanChangeOwnPasswordWithoutChangingUsername(): void
    {
        $day = $this->getEmployeeByUsername('NguyenDuyTai');
        $this->loginAs((int)$day->person_id);

        $employee = model(Employee::class);

        $this->assertTrue($employee->change_password([
            'username'     => 'ChangedUsername',
            'account_scope' => 'NIGHT',
            'password'     => password_hash('newpassword123', PASSWORD_DEFAULT),
            'hash_version' => 2,
        ], (int)$day->person_id));

        $updated = $this->getEmployeeByUsername('NguyenDuyTai');
        $this->assertNotNull($updated);
        $this->assertSame('DAY', $updated->account_scope);
        $this->assertTrue(password_verify('newpassword123', $updated->password));
    }

    public function testSecureControllerLoadsAccountScopeFromLoggedInEmployee(): void
    {
        $day = $this->getEmployeeByUsername('NguyenDuyTai');
        $this->loginAs((int)$day->person_id);

        $controller = new class extends Secure_Controller {
            public function __construct()
            {
                parent::__construct('home');
            }

            public function accountScope(): ?string
            {
                return $this->getAccountScope();
            }
        };

        $this->assertSame('DAY', $controller->accountScope());
    }

    public function testSecureControllerAlwaysProvidesAllowedModulesArray(): void
    {
        $aggregate = $this->getEmployeeByUsername('NguyenDuyTai2');
        db_connect()
            ->table('grants')
            ->where('person_id', (int)$aggregate->person_id)
            ->where('permission_id', 'home')
            ->update(['menu_group' => 'office']);
        $this->loginAs((int)$aggregate->person_id);

        $controller = new class extends Secure_Controller {
            public function __construct()
            {
                parent::__construct('home', null, 'home');
            }

            public function allowedModules(): array
            {
                return $this->global_view_data['allowed_modules'];
            }
        };

        $this->assertSame(['reports'], array_column($controller->allowedModules(), 'module_id'));
    }

    public function testAggregateHomeGrantUsesHomeMenuGroup(): void
    {
        $aggregate = $this->getEmployeeByUsername('NguyenDuyTai2');

        $this->assertSame('home', $this->getGrantMenuGroup((int)$aggregate->person_id, 'home'));
    }

    public function testMigrationRepairsAggregateHomeGrantMenuGroup(): void
    {
        $aggregate = $this->getEmployeeByUsername('NguyenDuyTai2');
        db_connect()
            ->table('grants')
            ->where('person_id', (int)$aggregate->person_id)
            ->where('permission_id', 'home')
            ->update(['menu_group' => 'office']);

        (new AddFixedEmployeeAccountScopes())->up();

        $this->assertSame('home', $this->getGrantMenuGroup((int)$aggregate->person_id, 'home'));
    }

    public function testAggregateHasOnlyReadOnlyReportAccess(): void
    {
        $aggregate = $this->getEmployeeByUsername('NguyenDuyTai2');
        $employee = model(Employee::class);

        foreach (['home', 'reports', 'reports_sales', 'reports_payments', 'reports_expenses_categories'] as $permissionId) {
            $this->assertTrue($employee->has_grant($permissionId, (int)$aggregate->person_id), $permissionId);
        }

        foreach ([
            'sales',
            'receivings',
            'expenses',
            'items',
            'employees',
            'config',
            'cashups',
            'giftcards',
            'customers',
            'reports_inventory',
            'reports_receivings',
            'reports_items',
            'reports_customers',
            'reports_employees',
            'reports_taxes',
            'reports_categories',
        ] as $permissionId) {
            $this->assertFalse($employee->has_grant($permissionId, (int)$aggregate->person_id), $permissionId);
        }
    }

    public function testAggregateReportAccessMigrationIsIdempotentAndRemovesOperationalGrants(): void
    {
        $aggregate = $this->getEmployeeByUsername('NguyenDuyTai2');
        $db = db_connect();
        $db->table('grants')->insert([
            'permission_id' => 'sales',
            'person_id'     => (int)$aggregate->person_id,
            'menu_group'    => 'home',
        ]);

        (new GrantAggregateReportAccess())->up();
        (new GrantAggregateReportAccess())->up();

        $this->assertSame(0, $this->grantCountForUsername('NguyenDuyTai2', 'sales'));
        $this->assertSame(1, $this->grantCountForUsername('NguyenDuyTai2', 'reports'));
        $this->assertSame(1, $this->grantCountForUsername('NguyenDuyTai2', 'reports_sales'));
    }

    public function testMissingInitialPasswordThrowsBeforeAccountCreation(): void
    {
        $this->removeFixedAccounts();
        putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POS_FIXED_ACCOUNT_INITIAL_PASSWORD must be set and at least 8 characters long.');

        try {
            (new AddFixedEmployeeAccountScopes())->up();
        } finally {
            $this->assertSame(0, $this->countFixedAccounts());
        }
    }

    public function testShortInitialPasswordThrowsBeforeAccountCreation(): void
    {
        $this->removeFixedAccounts();
        putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=short');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POS_FIXED_ACCOUNT_INITIAL_PASSWORD must be set and at least 8 characters long.');

        try {
            (new AddFixedEmployeeAccountScopes())->up();
        } finally {
            $this->assertSame(0, $this->countFixedAccounts());
        }
    }

    public function testDownThrowsBecauseRollbackIsUnsafe(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rollback is unsafe because the fixed employee accounts may already own operational data.');

        (new AddFixedEmployeeAccountScopes())->down();
    }

    private function getEmployeeByUsername(string $username): ?object
    {
        return db_connect()
            ->table('employees')
            ->join('people', 'people.person_id = employees.person_id')
            ->where('username', $username)
            ->get()
            ->getRow();
    }

    private function countFixedAccounts(): int
    {
        return db_connect()
            ->table('employees')
            ->whereIn('username', array_keys(self::FIXED_ACCOUNTS))
            ->countAllResults();
    }

    private function getGrantMenuGroup(int $personId, string $permissionId): ?string
    {
        $grant = db_connect()
            ->table('grants')
            ->select('menu_group')
            ->where('person_id', $personId)
            ->where('permission_id', $permissionId)
            ->get()
            ->getRow();

        return $grant->menu_group ?? null;
    }

    private function grantCountForUsername(string $username, string $permissionId): int
    {
        $account = $this->getEmployeeByUsername($username);

        return db_connect()
            ->table('grants')
            ->where('person_id', (int)$account->person_id)
            ->where('permission_id', $permissionId)
            ->countAllResults();
    }

    private function removeFixedAccounts(): void
    {
        $db = db_connect();
        $fixedUsernames = array_keys(self::FIXED_ACCOUNTS);
        $personIds = array_column(
            $db->table('employees')
                ->select('person_id')
                ->whereIn('username', $fixedUsernames)
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

    private function loginAs(int $personId): void
    {
        $session = Services::session();
        $session->destroy();
        $session->set('person_id', $personId);
        $session->set('menu_group', 'home');
    }
}
