<?php

namespace App\Models\Reports;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Model;

/**
 *
 *
 * @property response response
 *
 */
abstract class Report extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function applyBusinessUnitScope(array $inputs, BaseBuilder $builder, string $column): void
    {
        if (!array_key_exists('business_unit_ids', $inputs)) {
            return;
        }

        $businessUnitIds = $this->getBusinessUnitIds($inputs);

        if ($businessUnitIds === []) {
            $builder->where('1 = 0', null, false);
            return;
        }

        $builder->whereIn($column, $businessUnitIds);
    }

    protected function getBusinessUnitScopeWhere(array $inputs, string $column): string
    {
        if (!array_key_exists('business_unit_ids', $inputs)) {
            return '1 = 1';
        }

        $businessUnitIds = $this->getBusinessUnitIds($inputs);

        if ($businessUnitIds === []) {
            return '1 = 0';
        }

        return $column . ' IN (' . implode(',', array_map([$this->db, 'escape'], $businessUnitIds)) . ')';
    }

    private function getBusinessUnitIds(array $inputs): array
    {
        if (!isset($inputs['business_unit_ids']) || !is_array($inputs['business_unit_ids'])) {
            return [];
        }

        $businessUnitIds = array_map('intval', $inputs['business_unit_ids']);
        $businessUnitIds = array_filter($businessUnitIds, static fn (int $businessUnitId): bool => $businessUnitId > 0);

        return array_values(array_unique($businessUnitIds));
    }

    /**
     * Returns the column names used for the report
     */
    public abstract function getDataColumns(): array;

    /**
     * Returns all the data to be populated into the report
     */
    public abstract function getData(array $inputs): array;

    /**
     * Returns key=>value pairing of summary data for the report
     */
    public abstract function getSummaryData(array $inputs): array;
}
