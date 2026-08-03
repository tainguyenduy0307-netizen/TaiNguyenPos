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
        $this->assertStringContainsString('font-size: 11.5px;', $html);
        $this->assertStringContainsString('0900000000', $html);
        $this->assertStringContainsString('Siêu Thị Sữa Gia Phú 93', $html);
        $this->assertStringContainsString('449 đường Bình Mỹ, Bình Mỹ, Củ Chi, Thành Phố Hồ Chí Minh', $html);
        $this->assertStringContainsString('ĐT / Zalo: 0867.807.957', $html);
        $this->assertStringContainsString('Facebook: Siêu Thị Sữa Gia Phú 93', $html);
        $this->assertStringContainsString('sieuthiasuagiaphu93.vn', $html);
        $this->assertStringContainsString('HÓA ĐƠN BÁN HÀNG', $html);
        $this->assertStringContainsString('26/07/2026 - 10:15', $html);
        $this->assertStringNotContainsString('Ngày giờ', $html);
        $this->assertStringContainsString('Đơn giá', $html);
        $this->assertStringContainsString('SL', $html);
        $this->assertStringContainsString('Thành tiền', $html);
        $this->assertStringContainsString('SĐT:', $html);
        $this->assertStringNotContainsString('Điểm:', $html);
        $this->assertStringNotContainsString('Điểm hiện có', $html);
        $this->assertStringNotContainsString('Điểm thưởng', $html);
        $this->assertStringNotContainsString('customer_points', $html);
        $this->assertStringNotContainsString('>7<', $html);
        $this->assertStringContainsString('Sữa tươi tiệt trùng Vinamilk loại đặc biệt rất dài', $html);
        $this->assertStringContainsString('Sữa tươi tiệt trùng Vinamilk loại đặc biệt rất dài - LON', $html);
        $this->assertStringContainsString('15.000', $html);
        $this->assertStringContainsString('>2<', $html);
        $this->assertStringContainsString('30.000', $html);
        $this->assertStringContainsString('50.000', $html);
        $this->assertStringContainsString('20.000', $html);
        $this->assertStringNotContainsString('Cộng tiền hàng', $html);
        $this->assertStringContainsString('Tổng cộng', $html);
        $this->assertStringContainsString('Tiền khách đưa', $html);
        $this->assertStringContainsString('Tiền thừa', $html);
        $this->assertStringNotContainsString('Bằng chữ:', $html);
        $this->assertStringNotContainsString('Bằng chữ', $html);
        $this->assertStringContainsString('Thời gian mở cửa từ 7h - 22h30!', $html);
        $this->assertStringContainsString('Thời gian đổi hàng trong vòng 5 ngày', $html);
        $this->assertStringContainsString('Cảm ơn quý khách và hẹn gặp lại!', $html);
        $this->assertLessThan(strpos($html, 'Facebook: Siêu Thị Sữa Gia Phú 93'), strpos($html, 'ĐT / Zalo: 0867.807.957'));
        $this->assertLessThan(strpos($html, 'sieuthiasuagiaphu93.vn'), strpos($html, 'Facebook: Siêu Thị Sữa Gia Phú 93'));
        $this->assertLessThan(strpos($html, 'Thời gian đổi hàng trong vòng 5 ngày'), strpos($html, 'Thời gian mở cửa từ 7h - 22h30!'));
        $this->assertLessThan(strpos($html, 'Cảm ơn quý khách và hẹn gặp lại!'), strpos($html, 'Thời gian đổi hàng trong vòng 5 ngày'));
        $this->assertStringContainsString('Mo ta san pham', $html);
        $this->assertStringContainsString('SN123456', $html);
        $this->assertLessThan(strpos($html, 'Đơn giá'), strpos($html, 'SĐT:'));
        $this->assertSame(1, substr_count($html, 'INV-1001'));
        $this->assertSame(1, substr_count($html, 'Đơn giá'));
        $this->assertStringNotContainsString('POS 1001', $html);
        $this->assertStringNotContainsString('Nhân viên', $html);
        $this->assertStringNotContainsString('Le B', $html);
        $this->assertStringNotContainsString('(Tiền mặt)', $html);
        $this->assertStringNotContainsString('Tiền mặt', $html);
        $this->assertStringNotContainsString('Phí dịch vụ', $html);
        $this->assertStringNotContainsString('Chiết khấu', $html);
        $this->assertStringNotContainsString('Còn thiếu', $html);
        $this->assertStringNotContainsString('barcode', strtolower($html));
        $this->assertStringNotContainsString('comment', strtolower($html));
        $this->assertStringNotContainsString('Test', $html);
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
        $this->assertStringNotContainsString('Bằng chữ', $html);
        $this->assertStringContainsString('Cảm ơn quý khách và hẹn gặp lại!', $html);
        $this->assertStringNotContainsString('Cộng tiền hàng', $html);
        $this->assertStringNotContainsString('Còn thiếu', $html);
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
            'discount'              => 0,
            'order_discount_type'   => PERCENT,
            'order_discount_value'  => 10,
            'order_discount_amount' => 3000,
            'order_discount_code'   => 'SUMMER10',
            'prediscount_subtotal'  => 35000,
            'service_charge'        => 2000,
            'subtotal'              => 30000,
            'total'                 => 32000,
            'amount_change'         => 18000,
            'config'                => array_merge($this->baseConfig(), [
                'receipt_show_total_discount' => true,
            ]),
        ]));

        $this->assertStringContainsString('Phí dịch vụ', $html);
        $this->assertStringContainsString('2.000', $html);
        $this->assertMatchesRegularExpression('/Giảm giá \\(10[,.]00%\\)/', $html);
        $this->assertStringContainsString('-3.000', $html);
        $this->assertStringContainsString('Mã giảm giá', $html);
        $this->assertStringContainsString('SUMMER10', $html);
        $this->assertStringContainsString('Tổng cộng', $html);
        $this->assertStringContainsString('32.000', $html);
        $this->assertStringNotContainsString('Cộng tiền hàng', $html);
    }

    public function testReceiptK58RendersTaxOnlyWhenPositive(): void
    {
        $html = view('sales/receipt_k58', $this->receiptData([
            'taxes' => [
                [
                    'tax_rate'        => 10,
                    'tax_group'       => 'VAT',
                    'sale_tax_amount' => 3000,
                ],
                [
                    'tax_rate'        => 0,
                    'tax_group'       => 'ZERO',
                    'sale_tax_amount' => 0,
                ],
            ],
            'config' => array_merge($this->baseConfig(), [
                'receipt_show_tax' => true,
            ]),
        ]));

        $this->assertStringContainsString('Thuế/VAT 10% VAT', $html);
        $this->assertStringContainsString('3.000', $html);
        $this->assertStringNotContainsString('ZERO', $html);
    }

    public function testReceiptK58RendersRetailAndLargeUnitSnapshotsAsSeparateProductLines(): void
    {
        $html = view('sales/receipt_k58', $this->receiptData([
            'cart' => [
                [
                    'print_option'     => PRINT_YES,
                    'name'             => 'MILO A2 ÍT ĐƯỜNG 180ML',
                    'attribute_values' => '',
                    'quantity'         => '1.0000',
                    'price'            => '363000.00',
                    'total'            => '363000.00',
                    'discounted_total' => '363000.00',
                    'discount'         => 0,
                    'discount_type'    => FIXED,
                    'unit_name'        => 'LỐC',
                ],
                [
                    'print_option'     => PRINT_YES,
                    'name'             => 'MILO A2 ÍT ĐƯỜNG 180ML',
                    'attribute_values' => '',
                    'quantity'         => '2.0000',
                    'price'            => '1800000.00',
                    'total'            => '3600000.00',
                    'discounted_total' => '3600000.00',
                    'discount'         => 0,
                    'discount_type'    => FIXED,
                    'unit_name'        => 'THÙNG',
                ],
            ],
            'total' => 3963000,
        ]));

        $this->assertSame(1, substr_count($html, 'MILO A2 ÍT ĐƯỜNG 180ML - LỐC'));
        $this->assertSame(1, substr_count($html, 'MILO A2 ÍT ĐƯỜNG 180ML - THÙNG'));
        $this->assertStringContainsString('363.000', $html);
        $this->assertStringContainsString('1.800.000', $html);
        $this->assertStringContainsString('3.600.000', $html);
    }

    public function testReceiptK58UsesEmailWhenWebsiteIsEmpty(): void
    {
        $html = view('sales/receipt_k58', $this->receiptData([
            'config' => array_merge($this->baseConfig(), [
                'receipt_website' => '',
                'receipt_email'   => 'store@example.test',
            ]),
        ]));

        $this->assertStringContainsString('store@example.test', $html);
        $this->assertStringNotContainsString('sieuthiasuagiaphu93.vn', $html);
    }

    public function testReceiptK58DoesNotRenderEmptyWebsiteEmailOrFooterLines(): void
    {
        $html = view('sales/receipt_k58', $this->receiptData([
            'config' => array_merge($this->baseConfig(), [
                'website'                 => '',
                'email'                   => '',
                'receipt_opening_hours'   => '',
                'receipt_exchange_policy' => '',
                'return_policy'           => '',
                'receipt_thank_you'       => '',
                'receipt_footer'          => '',
                'payment_message'         => '',
                'receipt_website'         => '',
                'receipt_email'           => '',
            ]),
        ]));

        $this->assertStringNotContainsString('store@example.test', $html);
        $this->assertStringNotContainsString('tainguyenpos.test', $html);
        $this->assertStringNotContainsString('k58-footer', $html);
        $this->assertStringNotContainsString('<div></div>', $html);
    }

    public function testReceiptK58CanHideFacebookAndSkipsEmptyFacebookLine(): void
    {
        $hiddenHtml = view('sales/receipt_k58', $this->receiptData([
            'config' => array_merge($this->baseConfig(), [
                'receipt_show_facebook' => false,
            ]),
        ]));
        $emptyHtml = view('sales/receipt_k58', $this->receiptData([
            'config' => array_merge($this->baseConfig(), [
                'receipt_facebook' => '',
            ]),
        ]));

        $this->assertStringNotContainsString('Facebook:', $hiddenHtml);
        $this->assertStringNotContainsString('Facebook:', $emptyHtml);
        $this->assertStringNotContainsString('<div class="k58-store-line"></div>', $emptyHtml);
    }

    public function testReceiptK58DoesNotFallbackThankYouToPaymentMessage(): void
    {
        $html = view('sales/receipt_k58', $this->receiptData([
            'config' => array_merge($this->baseConfig(), [
                'receipt_thank_you' => '',
                'receipt_footer'    => '',
                'payment_message'   => 'Bựa',
            ]),
        ]));

        $this->assertStringNotContainsString('Bựa', $html);
        $this->assertStringNotContainsString('k58-footer-thanks', $html);
    }

    public function testReceiptK58UsesSafeFooterFallbacksInsteadOfLegacyGarbage(): void
    {
        $config = $this->baseConfig();
        unset($config['receipt_opening_hours'], $config['receipt_exchange_policy'], $config['receipt_thank_you']);
        $config['return_policy'] = 'Bựa';
        $config['receipt_footer'] = 'Bựa';
        $config['payment_message'] = 'Bựa';

        $html = view('sales/receipt_k58', $this->receiptData([
            'config' => $config,
        ]));

        $this->assertStringContainsString('Thời gian mở cửa từ 7h - 22h30!', $html);
        $this->assertStringContainsString('Thời gian đổi hàng trong vòng 5 ngày, sản phẩm đổi có giá trị lớn hơn hoặc bằng sản phẩm được đổi!', $html);
        $this->assertStringContainsString('Cảm ơn quý khách và hẹn gặp lại!', $html);
        $this->assertStringNotContainsString('Bựa', $html);
    }

    public function testReceiptK58DoesNotRenderShortGarbageFromDirectFooterOptions(): void
    {
        $html = view('sales/receipt_k58', $this->receiptData([
            'config' => array_merge($this->baseConfig(), [
                'receipt_opening_hours'   => 'Bựa',
                'receipt_exchange_policy' => 'Bựa',
                'receipt_thank_you'       => 'Bựa',
            ]),
        ]));

        $this->assertStringContainsString('Thời gian mở cửa từ 7h - 22h30!', $html);
        $this->assertStringContainsString('Thời gian đổi hàng trong vòng 5 ngày, sản phẩm đổi có giá trị lớn hơn hoặc bằng sản phẩm được đổi!', $html);
        $this->assertStringContainsString('Cảm ơn quý khách và hẹn gặp lại!', $html);
        $this->assertStringNotContainsString('Bựa', $html);
    }

    public function testReceiptK58OptionsCanHideHeaderCustomerPaymentAndFooterBlocks(): void
    {
        $html = view('sales/receipt_k58', $this->receiptData([
            'customer'              => 'Nguyen Van A',
            'customer_phone_number' => '0900000000',
            'config'                => array_merge($this->baseConfig(), [
                'receipt_show_address'         => false,
                'receipt_show_phone'           => false,
                'receipt_show_website'         => false,
                'receipt_show_customer_phone'  => false,
                'receipt_show_amount_tendered' => false,
                'receipt_show_change'          => false,
                'receipt_show_opening_hours'   => false,
                'receipt_show_exchange_policy' => false,
                'receipt_show_thank_you'       => false,
            ]),
        ]));

        $this->assertStringContainsString('Siêu Thị Sữa Gia Phú 93', $html);
        $this->assertStringContainsString('Nguyen Van A', $html);
        $this->assertStringNotContainsString('449 đường Bình Mỹ', $html);
        $this->assertStringNotContainsString('0865.545.119', $html);
        $this->assertStringNotContainsString('sieuthiasuagiaphu93.vn', $html);
        $this->assertStringNotContainsString('SĐT:', $html);
        $this->assertStringNotContainsString('0900000000', $html);
        $this->assertStringNotContainsString('Tiền khách đưa', $html);
        $this->assertStringNotContainsString('Tiền thừa', $html);
        $this->assertStringNotContainsString('k58-footer', $html);
    }

    public function testReceiptK58SkipsNegativePaymentsAndDoesNotRenderAmountDue(): void
    {
        $html = view('sales/receipt_k58', $this->receiptData([
            'payments' => [
                [
                    'payment_type'   => 'Điều chỉnh',
                    'payment_amount' => '-1000.00',
                ],
            ],
            'amount_change' => -30000,
        ]));

        $this->assertStringNotContainsString('Tiền khách đưa', $html);
        $this->assertStringNotContainsString('Còn thiếu', $html);
        $this->assertStringNotContainsString('>-', $html);
    }

    public function testReceiptK58PreviewAndReceiptUseSameHeaderFooterTemplate(): void
    {
        $previewHtml = view('sales/receipt_k58', $this->receiptData([
            'invoice_number' => '',
        ]));
        $receiptHtml = view('sales/receipt_k58', $this->receiptData());

        foreach ([
            'Siêu Thị Sữa Gia Phú 93',
            '449 đường Bình Mỹ',
            '0867.807.957',
            'Facebook: Siêu Thị Sữa Gia Phú 93',
            'HÓA ĐƠN BÁN HÀNG',
            'Thời gian mở cửa từ 7h - 22h30!',
            'Thời gian đổi hàng trong vòng 5 ngày',
            'Cảm ơn quý khách và hẹn gặp lại!',
        ] as $sharedText) {
            $this->assertStringContainsString($sharedText, $previewHtml);
            $this->assertStringContainsString($sharedText, $receiptHtml);
        }

        $this->assertStringNotContainsString('Mã hóa đơn', $previewHtml);
        $this->assertStringContainsString('Mã hóa đơn', $receiptHtml);
    }

    public function testReceiptK58CssContainsThermalPrintRules(): void
    {
        $css = file_get_contents(FCPATH . 'css/receipt_k58.css');

        $this->assertStringContainsString('@page', $css);
        $this->assertStringContainsString('size: 58mm auto;', $css);
        $this->assertStringContainsString('margin: 0;', $css);
        $this->assertStringContainsString('display: flex;', $css);
        $this->assertStringContainsString('justify-content: center;', $css);
        $this->assertStringContainsString('align-items: flex-start;', $css);
        $this->assertStringContainsString('width: 100%;', $css);
        $this->assertStringContainsString('max-width: none !important;', $css);
        $this->assertStringContainsString('width: 57mm;', $css);
        $this->assertStringContainsString('max-width: 57mm;', $css);
        $this->assertStringContainsString('margin: 0 auto;', $css);
        $this->assertStringContainsString('padding: 1.5mm 0.6mm 5mm;', $css);
        $this->assertStringContainsString('float: none;', $css);
        $this->assertStringContainsString('grid-template-columns: 35% 15% 50%;', $css);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 55%) minmax(0, 45%);', $css);
        $this->assertStringContainsString('table-layout: fixed;', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere;', $css);
        $this->assertStringContainsString('word-break: break-word;', $css);
        $this->assertStringContainsString('line-height: 1.28;', $css);
        $this->assertStringContainsString('font-size: 14.5px;', $css);
        $this->assertStringContainsString('#receipt_k58_wrapper .k58-item-name', $css);
        $this->assertStringContainsString('font-size: 10.5px;', $css);
        $this->assertStringContainsString('#receipt_k58_wrapper .k58-total', $css);
        $this->assertStringContainsString('font-size: 11px;', $css);
        $this->assertStringContainsString('#receipt_k58_wrapper .k58-footer', $css);
        $this->assertStringContainsString('line-height: 1.35;', $css);
        $this->assertStringContainsString('margin-bottom: 5px;', $css);
        $this->assertStringContainsString('#receipt_k58_wrapper .k58-store-line', $css);
        $this->assertStringContainsString('#receipt_k58_wrapper .k58-datetime', $css);
        $this->assertStringContainsString('#receipt_k58_wrapper .k58-totals', $css);
        $this->assertStringContainsString('#receipt_k58_wrapper .k58-items', $css);
        $this->assertStringContainsString('#receipt_k58_wrapper .k58-total span:first-child', $css);
        $this->assertStringContainsString('white-space: nowrap;', $css);
        $this->assertStringContainsString('#receipt_k58_wrapper .k58-item:last-child', $css);
        $this->assertStringContainsString('border-bottom: 1px solid #000;', $css);
        $this->assertStringContainsString('border-bottom: 0;', $css);
        $this->assertStringContainsString('#receipt_k58_wrapper .k58-footer', $css);
        $this->assertStringContainsString('#receipt_k58_wrapper .k58-footer-thanks', $css);
        $this->assertStringContainsString('.print_hide', $css);
        $this->assertStringContainsString('display: none !important;', $css);
        $this->assertStringNotContainsString('k58-amount-words', $css);
        $this->assertStringNotContainsString('left:', $css);
        $this->assertStringNotContainsString('margin-left', $css);
        $this->assertStringNotContainsString('scale(', $css);
        $this->assertDoesNotMatchRegularExpression('/html,\\s*body\\s*\\{[^}]*width:\\s*58mm;/s', $css);
        $this->assertDoesNotMatchRegularExpression('/html,\\s*body\\s*\\{[^}]*max-width:\\s*58mm;/s', $css);
        $this->assertDoesNotMatchRegularExpression('/\\.wrapper,\\s*\\.container\\s*\\{[^}]*width:\\s*58mm/s', $css);
        $this->assertDoesNotMatchRegularExpression('/(^|[\\s{;])transform\\s*:/', $css);
    }

    public function testReceiptK58CssIsLoadedOnlyForK58Template(): void
    {
        $receiptView = file_get_contents(APPPATH . 'Views/sales/receipt.php');

        $this->assertStringContainsString("\$template === 'receipt_k58'", $receiptView);
        $this->assertStringContainsString("\$receiptK58CssVersion = is_file(FCPATH . 'css/receipt_k58.css') ? filemtime(FCPATH . 'css/receipt_k58.css') : time();", $receiptView);
        $this->assertStringContainsString("base_url('css/receipt_k58.css?v=' . \$receiptK58CssVersion)", $receiptView);
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
        $this->assertStringContainsString('function getValidReceiptUrl(response)', $registerView);
        $this->assertStringContainsString('response.success !== true', $registerView);
        $this->assertStringContainsString("receiptUrl.indexOf('/sales/receipt/') === -1", $registerView);
        $this->assertStringContainsString('/\\/sales\\/receipt\\/(\\d+)$/', $registerView);
        $this->assertStringContainsString('printReceiptInFrame(receiptUrl)', $registerView);
        $this->assertStringContainsString('embedded_print=1&_cashier_print=', $registerView);
        $this->assertStringContainsString("frameWindow.addEventListener('afterprint', finish, { once: true });", $registerView);
        $this->assertStringContainsString("frameWindow.removeEventListener('afterprint', finish);", $registerView);
        $this->assertStringContainsString("window.addEventListener('focus', finishFromWindowFocus);", $registerView);
        $this->assertStringContainsString('fallbackTimer = setTimeout(finish, 60000);', $registerView);
        $this->assertStringNotContainsString("frameWindow.print();\n                    resolve();", $registerView);
        $this->assertStringContainsString('previewReceipt', $registerView);
        $this->assertStringContainsString('completeSaleForCashier', $registerView);
        $this->assertStringNotContainsString("window.open('about:blank'", $registerView);
        $this->assertStringNotContainsString('printReceiptInFrame(window.location.href)', $registerView);
        $this->assertStringNotContainsString('printReceiptInFrame(form.action)', $registerView);
        $this->assertStringNotContainsString('responseURL', $registerView);
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
        $this->assertStringContainsString("'sale_id'     => \$data['sale_id_num']", $salesController);
        $this->assertStringContainsString("'receipt_url' => site_url(\"sales/receipt/", $salesController);
        $this->assertStringContainsString("sale_id_num", $salesController);
    }

    public function testReceiptK58ConfigFormAndControllerExposeNewOptions(): void
    {
        $configView = file_get_contents(APPPATH . 'Views/configs/receipt_config.php');
        $configController = file_get_contents(APPPATH . 'Controllers/Config.php');

        foreach ([
            'receipt_company',
            'receipt_address',
            'receipt_phone',
            'receipt_phone_label',
            'receipt_facebook',
            'receipt_website',
            'receipt_email',
            'receipt_title',
            'receipt_opening_hours',
            'receipt_exchange_policy',
            'receipt_thank_you',
            'receipt_show_address',
            'receipt_show_phone',
            'receipt_show_facebook',
            'receipt_show_website',
            'receipt_show_customer_phone',
            'receipt_show_service_fee',
            'receipt_show_discount',
            'receipt_show_tax',
            'receipt_show_amount_tendered',
            'receipt_show_change',
            'receipt_show_opening_hours',
            'receipt_show_exchange_policy',
            'receipt_show_thank_you',
        ] as $receiptConfigKey) {
            $this->assertStringContainsString($receiptConfigKey, $configView);
            $this->assertStringContainsString($receiptConfigKey, $configController);
        }

        $this->assertStringContainsString("'rows'  => 4", $configView);
        $this->assertStringContainsString('Facebook', $configView);
        $this->assertStringContainsString('Hiển thị Facebook', $configView);
        $this->assertStringContainsString("'0867.807.957'", $configView);
        $this->assertStringContainsString("'ĐT / Zalo:'", $configView);
        $this->assertStringContainsString("'Siêu Thị Sữa Gia Phú 93'", $configView);
        $this->assertStringContainsString("'receipt_facebook'              => \$this->request->getPost('receipt_facebook')", $configController);
        $this->assertStringContainsString("'receipt_show_facebook'         => \$this->request->getPost('receipt_show_facebook') != null", $configController);
        $this->assertStringNotContainsString("\$receiptConfigValue('payment_message'", $configView);
    }

    public function testSalesReceiptRouteIsDefinedForCashierAjaxPrinting(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');

        $this->assertStringContainsString("\$routes->get('sales/receipt/(:num)', 'Sales::getReceipt/\$1');", $routes);
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
                    'unit_name'         => 'LON',
                ],
            ],
            'discount'             => 0,
            'order_discount_type'  => null,
            'order_discount_value' => 0,
            'order_discount_amount'=> 0,
            'order_discount_code'  => '',
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
            'payment_message'             => '',
            'return_policy'               => '',
            'receipt_opening_hours'       => 'Thời gian mở cửa từ 7h - 22h30!',
            'receipt_exchange_policy'     => 'Thời gian đổi hàng trong vòng 5 ngày, sản phẩm đổi có giá trị lớn hơn hoặc bằng sản phẩm được đổi!',
            'receipt_thank_you'           => 'Cảm ơn quý khách và hẹn gặp lại!',
            'receipt_show_total_discount' => false,
            'receipt_show_description'    => true,
            'receipt_show_serialnumber'   => true,
            'receipt_show_taxes'          => false,
        ];
    }
}
