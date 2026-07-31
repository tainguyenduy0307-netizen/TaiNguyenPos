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

        $this->assertStringContainsString('HÓA ĐƠN BÁN HÀNG', $html);
        $this->assertStringNotContainsString('Mã hóa đơn', $html);
        $this->assertStringNotContainsString('POS 1001', $html);
        $this->assertStringNotContainsString('Điểm:', $html);
        $this->assertStringContainsString('Cảm ơn quý khách', $html);
    }

    public function testReceiptK58DoesNotRenderTemporarySaleLabels(): void
    {
        $html = view('sales/receipt_k58', $this->receiptData([
            'invoice_number' => '',
        ]));

        $this->assertStringContainsString('HÓA ĐƠN BÁN HÀNG', $html);
        $this->assertStringNotContainsString('PHIẾU TẠM TÍNH', $html);
        $this->assertStringNotContainsString('CHƯA THANH TOÁN', $html);
        $this->assertStringNotContainsString('TẠM TÍNH', $html);
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

    public function testReceiptK58WrapperDoesNotRenderReceiptControlButtons(): void
    {
        $receiptView = file_get_contents(APPPATH . 'Views/sales/receipt.php');

        $this->assertStringContainsString('if (!$isK58Template)', $receiptView);
        $this->assertStringContainsString('id="control_buttons"', $receiptView);
        $this->assertStringContainsString("\$embeddedPrint = service('request')->getGet('embedded_print') === '1';", $receiptView);
        $this->assertStringContainsString("'auto_print'              => !\$embeddedPrint && (\$isK58Template || \$print_after_sale)", $receiptView);
        $this->assertStringContainsString("'use_browser_print'       => \$isK58Template", $receiptView);
    }

    public function testReceiptPrintPartialCanForceBrowserPrintForK58(): void
    {
        $printPartial = file_get_contents(APPPATH . 'Views/partial/print_receipt.php');

        $this->assertStringContainsString('$use_browser_print = $use_browser_print ?? false;', $printPartial);
        $this->assertStringContainsString('window.print();', $printPartial);
        $this->assertStringContainsString('return;', $printPartial);
        $this->assertStringContainsString('if ($auto_print)', $printPartial);
        $this->assertStringContainsString('window.frameElement !== null', $printPartial);
    }

    public function testRegisterUsesDelegatedCashierPrintHandlers(): void
    {
        $registerView = file_get_contents(APPPATH . 'Views/sales/register.php');

        $this->assertStringContainsString('id="cashier-preview-print"', $registerView);
        $this->assertStringContainsString('id="cashier-complete-sale"', $registerView);
        $this->assertStringContainsString('id="cashier-print-frame"', $registerView);
        $this->assertStringContainsString('id="cashier-home-button"', $registerView);
        $this->assertStringContainsString("site_url('home')", $registerView);
        $this->assertStringContainsString(".off('click.cashierPreviewPrint', '#cashier-preview-print')", $registerView);
        $this->assertStringContainsString(".off('click.cashierCompleteSale', '#cashier-complete-sale')", $registerView);
        $this->assertStringContainsString('function printReceiptInFrame(receiptUrl)', $registerView);
        $this->assertStringContainsString('embedded_print=1&_cashier_print=', $registerView);
        $this->assertStringContainsString("frameWindow.addEventListener('afterprint', finish, { once: true });", $registerView);
        $this->assertStringContainsString("frameWindow.removeEventListener('afterprint', finish);", $registerView);
        $this->assertStringContainsString("window.addEventListener('focus', finishFromWindowFocus);", $registerView);
        $this->assertStringContainsString('fallbackTimer = setTimeout(finish, 60000);', $registerView);
        $this->assertStringNotContainsString("frameWindow.print();\n                    resolve();", $registerView);
        $this->assertStringContainsString('previewReceipt', $registerView);
        $this->assertStringContainsString('completeSaleForCashier', $registerView);
        $this->assertStringNotContainsString("window.open('about:blank'", $registerView);
        $this->assertStringNotContainsString('completeSaleInPopup', $registerView);
        $this->assertStringNotContainsString('Vui lòng cho phép cửa sổ bật lên để in hóa đơn.', $registerView);
    }

    public function testRegisterPrintIframeAndHomeButtonAreScopedInCss(): void
    {
        $css = file_get_contents(FCPATH . 'css/register.css');

        $this->assertStringContainsString('body.sales-register-screen #cashier-print-frame', $css);
        $this->assertStringContainsString('pointer-events: none;', $css);
        $this->assertStringContainsString('body.sales-register-screen #cashier-home-button', $css);
    }

    public function testSalesControllerProvidesK58PreviewAndAjaxReceiptUrl(): void
    {
        $salesController = file_get_contents(APPPATH . 'Controllers/Sales.php');

        $this->assertStringContainsString('public function getPreviewReceipt()', $salesController);
        $this->assertStringContainsString("\$data['receipt_template_view'] = 'receipt_k58';", $salesController);
        $this->assertStringContainsString("'invoice_number'          => ''", $salesController);
        $this->assertStringContainsString("'success'     => true", $salesController);
        $this->assertStringContainsString("'receipt_url' => site_url(\"sales/receipt/", $salesController);
        $this->assertStringContainsString("sale_id_num", $salesController);
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
