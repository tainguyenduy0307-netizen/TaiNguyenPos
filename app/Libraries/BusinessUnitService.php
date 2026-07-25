<?php

namespace App\Libraries;

use App\Models\Employee;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class BusinessUnitService
{
    private BaseConnection $db;
    private Employee $employee;

    public function __construct(?BaseConnection $db = null, ?Employee $employee = null)
    {
        $this->db = $db ?? Database::connect();
        $this->employee = $employee ?? model(Employee::class);
    }

    public function getCurrentBusinessUnit(): ?object
    {
        $employee = $this->employee->get_logged_in_employee_info();

        if (!is_object($employee) || !isset($employee->person_id)) {
            return null;
        }

        return $this->getBusinessUnitForEmployee((int) $employee->person_id);
    }

    public function getCurrentBusinessUnitId(): ?int
    {
        $businessUnit = $this->getCurrentBusinessUnit();

        return $businessUnit === null ? null : (int) $businessUnit->id;
    }

    public function requireCurrentBusinessUnit(): object
    {
        $businessUnit = $this->getCurrentBusinessUnit();

        if ($businessUnit === null) {
            throw new RuntimeException('An operational business unit is required for this action.');
        }

        return $businessUnit;
    }

    public function requireCurrentBusinessUnitId(): int
    {
        return (int) $this->requireCurrentBusinessUnit()->id;
    }

    public function getBusinessUnitForEmployee(int $personId): ?object
    {
        if (!$this->schemaIsReady()) {
            return null;
        }

        $employee = $this->db->table('employees')
            ->select('employees.account_scope, business_units.id, business_units.code, business_units.name, business_units.enabled')
            ->join(
                'business_units',
                'business_units.id = employees.business_unit_id'
                    . ' AND business_units.code = employees.account_scope'
                    . ' AND business_units.enabled = 1',
                'left'
            )
            ->where('employees.person_id', $personId)
            ->where('employees.deleted', 0)
            ->get()
            ->getRow();

        if (
            $employee === null
            || !in_array($employee->account_scope, [Employee::ACCOUNT_SCOPE_DAY, Employee::ACCOUNT_SCOPE_NIGHT], true)
            || $employee->id === null
        ) {
            return null;
        }

        return (object) [
            'id'      => (int) $employee->id,
            'code'    => $employee->code,
            'name'    => $employee->name,
            'enabled' => (int) $employee->enabled,
        ];
    }

    private function schemaIsReady(): bool
    {
        return $this->db->tableExists('business_units')
            && $this->db->fieldExists('business_unit_id', 'employees');
    }
}
