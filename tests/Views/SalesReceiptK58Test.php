<?php

namespace Tests\Views;

use App\Libraries\Sale_lib;
use CodeIgniter\Test\CIUnitTestCase;

class SalesReceiptK58Test extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once APPPATH . 'Helpers/locale_helper.php';
    }

    public function testReceiptK58IsAllowedWithoutRemovingExistingTemplates(): void
    {
        $this->assertTrue(Sale_lib::isValidReceiptTemplate('receipt_k58'));
        $this->assertTrue(Sale_lib::isValidReceiptTemplate('receipt_default'));
        $this->assertTrue(Sale_lib::isValidReceiptTemplate('receipt_short'));
    }

    public function testReceiptK58IsAvailableInReceiptConfigOptions(): void
    {
        $configView = file_get_contents(APPPATH . 'Views/configs/receipt_config.php');

        $this->assertStringContainsString("'receipt_k58'", $configView);
        $this->assertStringContainsString("'K58'", $configView);
    }

    public function testReceiptK58RendersCustomerSaleContent(): void
    {
        $html = view('sales/receipt_k58', $this->receiptData([
            'customer'              => 'Nguyen Van A',
            'customer_phone_number' => '0900000000',
            'customer_points'       => 7,
        ]));

        $this->assertStringContainsString('Nguyen Van A', $html);
        $this->assertStringContainsString('0900000000', $html);
        $this->assertStringContainsString('Cua hang Tai Nguyen', $html);
        $this->assertStringContainsString('123 Nguyen Trai', $html);
        $this->assertStringContainsString('028000000', $html);
        $this->assertStringContainsString('tainguyenpos.test', $html);
        $this->assertStringContainsString('HÓA ĐƠN BÁN HÀNG', $html);
        $this->assertStringContainsString('Đơn giá', $html);
        $this->assertStringContainsString('SL', $html);
        $this->assertStringContainsString('Thành tiền', $html);
        $this->assertStringContainsString('SĐT:', $html);
        $this->assertStringContainsString('Điểm:', $html);
        $this->assertStringContainsString('>7<', $html);
        $this->assertStringContainsString('Sữa tươi tiệt trùng Vinamilk loại đặc biệt rất dài', $html);
        $this->assertStringContainsString('15.000', $html);
        $this->assertStringContainsString('>2<', $html);
        $this->assertStringContainsString('30.000', $html);
        $this->assertStringContainsString('50.000', $html);
        $this->assertStringContainsString('20.000', $html);
        $this->assertStringContainsString('Cộng tiền hàng', $html);
        $this->assertStringContainsString('Tổng cộng', $html);
        $this->assertStringContainsString('Tiền khách đưa', $html);
        $this->assertStringContainsString('Tiền thừa', $html);
        $this->assertStringContainsString('Bằng chữ:', $html);
        $this->assertStringContainsString('Cảm ơn quý khách', $html);
        $this->assertStringContainsString('Mo ta san pham', $html);
        $this->assertStringContainsString('SN123456', $html);
        $this->assertLessThan(strpos($html, 'Đơn giá'), strpos($html, 'Điểm:'));
        $this->assertSame(1, substr_count($html, 'INV-1001'));
        $this->assertSame(1, substr_count($html, 'Đơn giá'));
        $this->assertStringNotContainsString('POS 1001', $html);
        $this->assertStringNotContainsString('Nhân viên', $html);
        $this->assertStringNotContainsString('Le B', $html);
        $this->assertStringNotContainsString('(Tiền mặt)', $html);
        $this->assertStringNotContainsString('Tiền mặt', $html);
        $this->assertStringNotContainsString('Phí dịch vụ', $html);
        $this->assertStringNotContainsString('Chiết khấu', $html);
        $this->assertStringNotContainsString('barcode', strtolower($html));
        $this->assertStringNotContainsString('comment', strtolower($html));
        $this->assertStringNotContainsString('Test', $html);
        $this->assertStringNotContainsString('return_policy', $html);
        $this->assertStringNotContainsString('Return Policy', $html);
        $this->assertStringNotContainsString('&minus;', $html);
        $this->assertStringNotContainsString('>-', $html);
        $this->assertStringNotContainsString('>Giá<', $html);
        $this->assertStringNotContainsString('$', $html);
        $this->assertStringNotContainsString('₫', $html);
        $this->assertDoesNotMatchRegularExpression('/>[^<]*\.\d{2}</u', $html);
    }

    public function testReceiptK58RendersWithoutCustomer(): void
    {
        $html = view('sales/receipt_k58', $this->receiptData([
            'invoice_number' => '',
        ]));

        $this->assertStringContainsString('POS 1001', $html);
        $this->assertStringNotContainsString('Điểm:', $html);
        $this->assertStringContainsString('Cảm ơn quý khách', $html);
    }

    public function testReceiptK58RendersServiceChargeAndDiscountOnlyWhenPositive(): void
    {
        $html = view('sales/receipt_k58', $this->receiptData([
            'discount'              => 5000,
            'prediscount_subtotal'  => 35000,
            'service_charge'        => 2000,
            'subtotal'              => 30000,
            'total'                 => 32000,
            'amount_change'         => 18000,
            'config'                => array_merge($this->baseConfig(), [
                'receipt_show_total_discount' => true,
            ]),
        ]));

        $this->assertStringContainsString('Cộng tiền hàng', $html);
        $this->assertStringContainsString('35.000', $html);
        $this->assertStringContainsString('Phí dịch vụ', $html);
        $this->assertStringContainsString('2.000', $html);
        $this->assertStringContainsString('Chiết khấu', $html);
        $this->assertStringContainsString('5.000', $html);
        $this->assertStringContainsString('Tổng cộng', $html);
        $this->assertStringContainsString('32.000', $html);
    }

    public function testReceiptK58CssContainsThermalPrintRules(): void
    {
        $css = file_get_contents(FCPATH . 'css/receipt_k58.css');

        $this->assertStringContainsString('@page', $css);
        $this->assertStringContainsString('size: 58mm auto;', $css);
        $this->assertStringContainsString('margin: 0;', $css);
        $this->assertStringContainsString('width: 100%;', $css);
        $this->assertStringContainsString('display: flex;', $css);
        $this->assertStringContainsString('justify-content: center;', $css);
        $this->assertStringContainsString('align-items: flex-start;', $css);
        $this->assertStringContainsString('width: 52mm;', $css);
        $this->assertStringContainsString('max-width: 52mm;', $css);
        $this->assertStringContainsString('margin: 0 auto;', $css);
        $this->assertStringContainsString('float: none;', $css);
        $this->assertStringContainsString('grid-template-columns: 1fr 10mm 1fr;', $css);
        $this->assertStringContainsString('table-layout: fixed;', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere;', $css);
        $this->assertStringContainsString('word-break: break-word;', $css);
        $this->assertStringContainsString('line-height: 1.22;', $css);
        $this->assertStringContainsString('#receipt_k58_wrapper .k58-item-name', $css);
        $this->assertStringContainsString('#receipt_k58_wrapper .k58-footer', $css);
        $this->assertStringContainsString('.print_hide', $css);
        $this->assertStringContainsString('display: none !important;', $css);
        $this->assertStringNotContainsString('left:', $css);
        $this->assertStringNotContainsString('transform:', $css);
    }

    public function testReceiptK58CssIsLoadedOnlyForK58Template(): void
    {
        $receiptView = file_get_contents(APPPATH . 'Views/sales/receipt.php');

        $this->assertStringContainsString("\$template === 'receipt_k58'", $receiptView);
        $this->assertStringContainsString("base_url('css/receipt_k58.css')", $receiptView);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function receiptData(array $overrides = []): array
    {
        return array_merge([
            'transaction_time'     => '2026-07-26 10:15:30',
            'sale_id'              => 'POS 1001',
            'invoice_number'       => 'INV-1001',
            'employee'             => 'Le B',
            'cart'                 => [
                [
                    'print_option'      => PRINT_YES,
                    'name'              => 'Sữa tươi tiệt trùng Vinamilk loại đặc biệt rất dài',
                    'attribute_values'  => '',
                    'quantity'          => '2.0000',
                    'price'             => '15000.00',
                    'total'             => '30000.00',
                    'discounted_total'  => '30000.00',
                    'description'       => 'Mo ta san pham',
                    'serialnumber'      => 'SN123456',
                    'discount'          => 0,
                    'discount_type'     => FIXED,
                ],
            ],
            'discount'             => 0,
            'prediscount_subtotal' => 30000,
            'subtotal'             => 30000,
            'taxes'                => [],
            'total'                => 30000,
            'payments'             => [
                [
                    'payment_type'   => 'Tiền mặt',
                    'payment_amount' => '50000.00',
                ],
            ],
            'amount_change'        => 20000,
            'customer'             => null,
            'customer_phone_number' => null,
            'customer_points'      => null,
            'service_charge'       => 0,
            'config'               => $this->baseConfig(),
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(): array
    {
        return [
            'receipt_font_size'           => 11,
            'company_logo'                => '',
            'receipt_show_company_name'   => true,
            'company'                     => 'Cua hang Tai Nguyen',
            'address'                     => '123 Nguyen Trai',
            'phone'                       => '028000000',
            'website'                     => 'tainguyenpos.test',
            'email'                       => 'store@example.test',
            'payment_message'             => 'Mo cua 08:00 - 22:00',
            'receipt_show_total_discount' => false,
            'receipt_show_description'    => true,
            'receipt_show_serialnumber'   => true,
            'receipt_show_taxes'          => false,
        ];
    }
}
