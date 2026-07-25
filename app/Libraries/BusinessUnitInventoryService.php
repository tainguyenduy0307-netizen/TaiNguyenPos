<?php

namespace App\Libraries;

use App\Models\Business_unit_item_quantity;

class BusinessUnitInventoryService
{
    private BusinessUnitService $businessUnitService;
    private Business_unit_item_quantity $businessUnitItemQuantity;

    public function __construct(
        ?BusinessUnitService $businessUnitService = null,
        ?Business_unit_item_quantity $businessUnitItemQuantity = null
    ) {
        $this->businessUnitService = $businessUnitService ?? new BusinessUnitService();
        $this->businessUnitItemQuantity = $businessUnitItemQuantity ?? model(Business_unit_item_quantity::class);
    }

    public function getCurrentQuantity(int $itemId, int $locationId): ?float
    {
        $businessUnitId = $this->businessUnitService->getCurrentBusinessUnitId();

        if ($businessUnitId === null) {
            return null;
        }

        $this->businessUnitItemQuantity->ensureZeroRow($businessUnitId, $itemId, $locationId);

        return $this->businessUnitItemQuantity->getQuantity($businessUnitId, $itemId, $locationId);
    }

    public function setCurrentQuantity(int $itemId, int $locationId, float $quantity): bool
    {
        $businessUnitId = $this->businessUnitService->requireCurrentBusinessUnitId();
        $this->businessUnitItemQuantity->ensureZeroRow($businessUnitId, $itemId, $locationId);

        return $this->businessUnitItemQuantity->setQuantity($businessUnitId, $itemId, $locationId, $quantity);
    }

    public function changeCurrentQuantity(int $itemId, int $locationId, float $quantityChange): bool
    {
        $businessUnitId = $this->businessUnitService->requireCurrentBusinessUnitId();
        $this->businessUnitItemQuantity->ensureZeroRow($businessUnitId, $itemId, $locationId);

        return $this->businessUnitItemQuantity->changeQuantity($businessUnitId, $itemId, $locationId, $quantityChange);
    }
}
