<?php

namespace Tests\Models;

use App\Controllers\Secure_Controller;
use App\Database\Migrations\AddFixedEmployeeAccountScopes;
use App\Models\Employee;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use RuntimeException;

require_once APPPATH . 'Database/Migrations/20260724000000_AddFixedEmployeeAccountScopes.php';

class FixedEmployeeAccountsTest extends CIUnitTestCase
{
    private const INITIAL_PASSWORD = 'TestOnlyPassword123!';

    private string|false $previousInitialPassword;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousInitialPassword = getenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');
        putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . self::INITIAL_PASSWORD);

        (new AddFixedEmployeeAccountScopes())->up();
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

        $this->assertSame(3, $this->countFixedAccounts());
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
