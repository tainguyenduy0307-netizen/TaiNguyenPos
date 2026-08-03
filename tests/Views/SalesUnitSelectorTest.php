<?php

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;

final class SalesUnitSelectorTest extends CIUnitTestCase
{
    public function testRetailAndLargeUnitLabelsUseUnitNames(): void
    {
        $html = $this->renderSelector('LỐC', 'THÙNG', 12);

        $this->assertStringContainsString('[1 LỐC]', $html);
        $this->assertStringContainsString('[1 THÙNG]', $html);
        $this->assertStringNotContainsString('12 LỐC', $html);
    }

    public function testSelectorRendersDifferentActualUnitNames(): void
    {
        $this->assertStringContainsString('[1 HỘP]', $this->renderSelector('HỘP', 'THÙNG'));
        $this->assertStringContainsString('[1 THÙNG]', $this->renderSelector('HỘP', 'THÙNG'));
        $this->assertStringContainsString('[1 CHAI]', $this->renderSelector('CHAI', 'LỐC'));
        $this->assertStringContainsString('[1 LỐC]', $this->renderSelector('CHAI', 'LỐC'));
        $this->assertStringContainsString('[1 GÓI]', $this->renderSelector('GÓI', 'HỘP'));
        $this->assertStringContainsString('[1 HỘP]', $this->renderSelector('GÓI', 'HỘP'));
    }

    public function testRetailOnlyItemRendersOneActiveBadge(): void
    {
        $html = view('sales/unit_selector', [
            'line' => 5,
            'item' => [
                'item_unit_id' => 10,
                'unit_name' => 'HỘP',
                'available_units' => [
                    ['item_unit_id' => 10, 'unit_name' => 'HỘP', 'conversion_quantity' => 1],
                ],
            ],
        ]);

        $this->assertSame(1, substr_count($html, '<button'));
        $this->assertStringContainsString('[1 HỘP]', $html);
        $this->assertStringContainsString('cashier-unit-badge--active', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);
    }

    public function testLargeUnitWithoutBarcodeStillRendersInactiveBadge(): void
    {
        $html = view('sales/unit_selector', [
            'line' => 7,
            'item' => [
                'item_unit_id' => 20,
                'unit_name' => 'LỐC',
                'available_units' => [
                    ['item_unit_id' => 20, 'unit_name' => 'LỐC', 'conversion_quantity' => 1, 'barcode' => null],
                    ['item_unit_id' => 21, 'unit_name' => 'THÙNG', 'conversion_quantity' => 12, 'barcode' => null],
                ],
            ],
        ]);

        $this->assertStringContainsString('[1 LỐC]', $html);
        $this->assertStringContainsString('[1 THÙNG]', $html);
        $this->assertStringContainsString('cashier-unit-badge--active', $html);
        $this->assertStringContainsString('cashier-unit-badge--inactive', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
    }

    public function testUnitLabelLogicDoesNotHardcodeConcreteUnitNames(): void
    {
        $source = file_get_contents(APPPATH . 'Views/sales/unit_selector.php');

        foreach (['Lẻ', 'Lốc', 'Thùng', 'Hộp', 'Chai', 'Gói', 'Lon', 'Bịch'] as $unitName) {
            $this->assertStringNotContainsString($unitName, $source);
        }
    }

    private function renderSelector(string $retailUnitName, string $largeUnitName, int $conversion = 1): string
    {
        return view('sales/unit_selector', [
            'line' => 3,
            'item' => [
                'item_unit_id' => 1,
                'unit_name' => $retailUnitName,
                'available_units' => [
                    ['item_unit_id' => 1, 'unit_name' => $retailUnitName, 'conversion_quantity' => 1],
                    ['item_unit_id' => 2, 'unit_name' => $largeUnitName, 'conversion_quantity' => $conversion],
                ],
            ],
        ]);
    }
}
