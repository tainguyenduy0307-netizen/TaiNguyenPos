<?php

namespace Tests\Models;

use App\Controllers\Secure_Controller;
use App\Database\Migrations\AddBusinessUnits;
use App\Database\Migrations\AddFixedEmployeeAccountScopes;
use App\Libraries\BusinessUnitService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use RuntimeException;

require_once APPPATH . 'Database/Migrations/20260724000000_AddFixedEmployeeAccountScopes.php';
require_once APPPATH . 'Database/Migrations/20260725000000_AddBusinessUnits.php';

class BusinessUnitServiceTest extends CIUnitTestCase
{
    private const INITIAL_PASSWORD = 'TestOnlyPassword123!';

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
        $this->removeFixedAccounts();
        putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . self::INITIAL_PASSWORD);

        (new AddFixedEmployeeAccountScopes())->up();
        (new AddBusinessUnits())->up();
    }

    protected function tearDown(): void
    {
        Services::session()->destroy();

        if ($this->previousInitialPassword === false) {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD');
        } else {
            putenv('POS_FIXED_ACCOUNT_INITIAL_PASSWORD=' . $this->previousInitialPassword);
        }

        parent::tearDown();
    }

    public function testBusinessUnitsExistWithOnlyDayAndNight(): void
    {
        $businessUnits = db_connect()
            ->table('business_units')
            ->select('code, name, enabled')
            ->orderBy('code')
            ->get()
            ->getResultArray();

        $this->assertSame([
            ['code' => 'DAY', 'name' => 'DAY', 'enabled' => '1'],
            ['code' => 'NIGHT', 'name' => 'NIGHT', 'enabled' => '1'],
        ], $businessUnits);
    }

    public function testBusinessUnitCodeIsUnique(): void
    {
        $db = db_connect();
        $uniqueCodeIndexes = $db->query(
            'SELECT COUNT(*) AS count FROM information_schema.statistics'
            . ' WHERE table_schema = DATABASE()'
            . ' AND table_name = ?'
            . ' AND column_name = ?'
            . ' AND non_unique = 0',
            [$db->prefixTable('business_units'), 'code']
        )->getRowArray();

        $this->assertGreaterThan(0, (int) $uniqueCodeIndexes['count']);
    }

    public function testFixedAccountsAreMappedToExpectedBusinessUnits(): void
    {
        $this->assertSame('DAY', $this->getBusinessUnitCodeForUsername('NguyenDuyTai'));
        $this->assertSame('NIGHT', $this->getBusinessUnitCodeForUsername('NguyenDuyTai1'));
        $this->assertNull($this->getBusinessUnitCodeForUsername('NguyenDuyTai2'));
    }

    public function testServiceReturnsBusinessUnitForDayAndNight(): void
    {
        $this->loginAsUsername('NguyenDuyTai');
        $dayBusinessUnit = (new BusinessUnitService())->getCurrentBusinessUnit();

        $this->assertNotNull($dayBusinessUnit);
        $this->assertSame('DAY', $dayBusinessUnit->code);

        $this->loginAsUsername('NguyenDuyTai1');
        $nightBusinessUnit = (new BusinessUnitService())->getCurrentBusinessUnit();

        $this->assertNotNull($nightBusinessUnit);
        $this->assertSame('NIGHT', $nightBusinessUnit->code);
    }

    public function testServiceReturnsNullForAggregate(): void
    {
        $this->loginAsUsername('NguyenDuyTai2');

        $this->assertNull((new BusinessUnitService())->getCurrentBusinessUnit());
    }

    public function testRequiredBusinessUnitRejectsAggregate(): void
    {
        $this->loginAsUsername('NguyenDuyTai2');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An operational business unit is required for this action.');

        (new BusinessUnitService())->requireCurrentBusinessUnit();
    }

    public function testBusinessUnitCannotBeSpoofedFromSessionOrRequest(): void
    {
        $this->loginAsUsername('NguyenDuyTai');
        Services::session()->set('business_unit_id', $this->getBusinessUnitId('NIGHT'));
        $_GET['business_unit_id'] = (string) $this->getBusinessUnitId('NIGHT');

        try {
            $businessUnit = (new BusinessUnitService())->requireCurrentBusinessUnit();
        } finally {
            unset($_GET['business_unit_id']);
        }

        $this->assertSame('DAY', $businessUnit->code);
    }

    public function testSecureControllerProvidesBusinessUnitViewData(): void
    {
        $this->loginAsUsername('NguyenDuyTai');

        $controller = new class extends Secure_Controller {
            public function __construct()
            {
                parent::__construct('home');
            }

            public function businessUnitId(): ?int
            {
                return $this->global_view_data['business_unit_id'];
            }
        };

        $this->assertSame($this->getBusinessUnitId('DAY'), $controller->businessUnitId());
    }

    public function testMigrationIsIdempotent(): void
    {
        $before = $this->getBusinessUnitIdsByCode();

        (new AddBusinessUnits())->up();

        $this->assertSame($before, $this->getBusinessUnitIdsByCode());
        $this->assertSame(2, db_connect()->table('business_units')->countAllResults());
    }

    private function getBusinessUnitCodeForUsername(string $username): ?string
    {
        $employee = db_connect()
            ->table('employees')
            ->select('business_units.code')
            ->join('business_units', 'business_units.id = employees.business_unit_id', 'left')
            ->where('employees.username', $username)
            ->get()
            ->getRow();

        return $employee->code ?? null;
    }

    private function getBusinessUnitId(string $code): int
    {
        $businessUnit = db_connect()
            ->table('business_units')
            ->select('id')
            ->where('code', $code)
            ->get()
            ->getRow();

        return (int) $businessUnit->id;
    }

    /**
     * @return array<string, string>
     */
    private function getBusinessUnitIdsByCode(): array
    {
        return array_column(
            db_connect()
                ->table('business_units')
                ->select('id, code')
                ->orderBy('code')
                ->get()
                ->getResultArray(),
            'id',
            'code'
        );
    }

    private function loginAsUsername(string $username): void
    {
        $personId = db_connect()
            ->table('employees')
            ->select('person_id')
            ->where('username', $username)
            ->get()
            ->getRow()
            ->person_id;

        $session = Services::session();
        $session->destroy();
        $session->set('person_id', (int) $personId);
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
