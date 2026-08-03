<?php

namespace Tests\Views;

use CodeIgniter\Test\CIUnitTestCase;

final class SalesRegisterLayoutTest extends CIUnitTestCase
{
    private string $registerView;
    private string $registerCss;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerView = file_get_contents(APPPATH . 'Views/sales/register.php');
        $this->registerCss = file_get_contents(ROOTPATH . 'public/css/register.css');
    }

    public function testUnitSelectorIsRenderedUnderBarcodeColumnOnly(): void
    {
        $this->assertStringContainsString("base_url('css/register.css?v=' . \$register_css_version)", $this->registerView);
        $this->assertStringContainsString('<div class="pos-item-code"><?= esc($item[\'item_number\']) ?></div>', $this->registerView);
        $this->assertMatchesRegularExpression(
            '/<div class="cashier-cart-cell code pos-cart-code">\s*<div class="pos-cart-code-content">\s*<div class="pos-item-code">.*?view\(\'sales\/unit_selector\'/s',
            $this->registerView
        );

        $nameCellStart = strpos($this->registerView, '<div class="cashier-cart-cell name pos-cart-name">', strpos($this->registerView, '<?php } else { ?>'));
        $nameCellEnd = strpos($this->registerView, '<div class="cashier-cart-cell unit-price pos-cart-price">', $nameCellStart);
        $nameCellMarkup = substr($this->registerView, $nameCellStart, $nameCellEnd - $nameCellStart);

        $this->assertStringNotContainsString("view('sales/unit_selector'", $nameCellMarkup);
    }

    public function testCartGridKeepsBarcodeColumnReadableAndSharedByHeaderAndRows(): void
    {
        $this->assertStringContainsString('--cashier-cart-columns: 15% 35% 15% 12% 18% 5%;', $this->registerCss);
        $this->assertStringContainsString('grid-template-columns: var(--cashier-cart-columns);', $this->registerCss);
        $this->assertMatchesRegularExpression('/\.cashier-cart-cell\.code\s*\{[^}]*overflow:\s*visible;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/\.cashier-cart-cell\.pos-cart-code\s*\{[^}]*justify-content:\s*stretch;[^}]*overflow:\s*visible;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/\.pos-cart-code-content\s*\{[^}]*grid-template-rows:\s*auto auto;[^}]*padding-left:\s*20px;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/\.pos-item-code\s*\{[^}]*overflow:\s*visible;[^}]*text-overflow:\s*clip;/s', $this->registerCss);
        $this->assertStringNotContainsString('margin-left: -', $this->registerCss);
    }

    public function testCartTableUsesSixColumnsWithoutLineDiscount(): void
    {
        $expectedHeader = '<div class="cashier-cart-cell code pos-cart-code">Mã</div>'
            . "\n            " . '<div class="cashier-cart-cell name pos-cart-name">Tên hàng hóa</div>'
            . "\n            " . '<div class="cashier-cart-cell unit-price pos-cart-price">Đơn giá</div>'
            . "\n            " . '<div class="cashier-cart-cell quantity pos-cart-quantity">SL</div>'
            . "\n            " . '<div class="cashier-cart-cell total pos-cart-total">Thành tiền</div>'
            . "\n            " . '<div class="cashier-cart-cell pos-cart-delete">Xóa</div>';

        $this->assertStringContainsString($expectedHeader, $this->registerView);
        $this->assertStringNotContainsString('Giảm giá</div>', $this->registerView);
        $this->assertStringNotContainsString('pos-cart-discount', $this->registerView);
        $this->assertStringNotContainsString('cashier-cart-cell discount', $this->registerView);
        $this->assertStringNotContainsString('discount_toggle', $this->registerView);
        $this->assertStringNotContainsString("'name' => 'discount'", $this->registerView);
        $this->assertStringNotContainsString('pos-cart-discount', $this->registerCss);

        $this->assertSame(
            1,
            preg_match(
                '/<div class="cashier-cart-header pos-cart-row pos-cart-header">(?<header>.*?)<\/div>\s*<div id="cart_contents"/s',
                $this->registerView,
                $headerMatches
            )
        );
        $headerMarkup = $headerMatches['header'];
        $this->assertSame(6, substr_count($headerMarkup, '<div class="cashier-cart-cell '));

        $tempBranchStart = strpos($this->registerView, "<?php if (\$item['item_type'] == ITEM_TEMP) { ?>");
        $regularBranchStart = strpos($this->registerView, '<?php } else { ?>', $tempBranchStart);
        $branchEnd = strpos($this->registerView, '<?php } ?>', $regularBranchStart);
        $commonCellsEnd = strpos($this->registerView, '</div>', strpos($this->registerView, '<div class="cashier-cart-cell pos-cart-delete">', $branchEnd));
        $this->assertIsInt($tempBranchStart);
        $this->assertIsInt($regularBranchStart);
        $this->assertIsInt($branchEnd);
        $this->assertIsInt($commonCellsEnd);

        $tempBranchMarkup = substr($this->registerView, $tempBranchStart, $regularBranchStart - $tempBranchStart);
        $regularBranchMarkup = substr($this->registerView, $regularBranchStart, $branchEnd - $regularBranchStart);
        $commonCellsMarkup = substr($this->registerView, $branchEnd, $commonCellsEnd - $branchEnd);

        $this->assertSame(2, substr_count($tempBranchMarkup, '<div class="cashier-cart-cell '));
        $this->assertSame(2, substr_count($regularBranchMarkup, '<div class="cashier-cart-cell '));
        $this->assertSame(4, substr_count($commonCellsMarkup, '<div class="cashier-cart-cell '));
        $this->assertLessThan(
            strpos($commonCellsMarkup, '<div class="cashier-cart-cell pos-cart-delete">'),
            strpos($commonCellsMarkup, '<div class="cashier-cart-cell total pos-cart-total">')
        );
    }

    public function testUnitPriceHeaderKeepsVietnameseTextAndVisibleLayout(): void
    {
        $this->assertStringContainsString('<div class="cashier-cart-cell unit-price pos-cart-price">Đơn giá</div>', $this->registerView);
        $this->assertMatchesRegularExpression('/\.pos-cart-header\s*\{[^}]*letter-spacing:\s*0;[^}]*line-height:\s*16px;[^}]*overflow:\s*visible;/s', $this->registerCss);
        $this->assertStringNotContainsString('DON GIA', $this->registerView);
    }

    public function testBarcodeAutocompleteSearchesImmediatelyAndUsesVisibleDropdown(): void
    {
        $this->assertStringContainsString('source: function(request, response)', $this->registerView);
        $this->assertStringContainsString('site_url("$controller_name/itemSearch")', $this->registerView);
        $this->assertStringContainsString('minLength: 1', $this->registerView);
        $this->assertStringContainsString("appendTo: '#add_item_form'", $this->registerView);
        $this->assertStringContainsString("$('#item').on('input'", $this->registerView);
        $this->assertStringContainsString("autocomplete('search', searchValue)", $this->registerView);
        $this->assertStringContainsString('itemAutocomplete._renderItem = function(ul, item)', $this->registerView);
        $this->assertStringContainsString("class: 'cashier-search-suggestion__name'", $this->registerView);
        $this->assertStringContainsString("text: item.name || item.label || ''", $this->registerView);
        $this->assertStringContainsString("class: 'cashier-search-suggestion__meta'", $this->registerView);
        $this->assertLessThan(
            strpos($this->registerView, "class: 'cashier-search-suggestion__meta'"),
            strpos($this->registerView, "class: 'cashier-search-suggestion__name'")
        );
        $this->assertMatchesRegularExpression('/#add_item_form \\.ui-autocomplete\s*\{[^}]*z-index:\s*2300;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/#add_item_form \\.ui-autocomplete\s*\{[^}]*max-height:\s*490px;[^}]*overflow-y:\s*auto;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/\.cashier-search-suggestion\s*\{[^}]*min-height:\s*48px;[^}]*padding:\s*8px 10px;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/\.cashier-search-suggestion__name\s*\{[^}]*font-size:\s*14px;[^}]*font-weight:\s*700;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/\.cashier-search-suggestion__meta\s*\{[^}]*font-size:\s*11px;[^}]*line-height:\s*14px;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/#add_item_form \\.ui-state-active \\.cashier-search-suggestion__price,[^}]*#add_item_form \\.ui-state-focus \\.cashier-search-suggestion__price\s*\{[^}]*color:\s*#fff;/s', $this->registerCss);
    }

    public function testCartDeleteColumnHasVisibleTrashButton(): void
    {
        $this->assertStringContainsString('<div class="cashier-cart-cell pos-cart-delete">Xóa</div>', $this->registerView);
        $this->assertStringContainsString('<div class="cashier-cart-cell pos-cart-delete">
                                <button', $this->registerView);
        $this->assertStringNotContainsString('cashier-cart-cell delete pos-cart-delete', $this->registerView);
        $this->assertStringContainsString('type="button"', $this->registerView);
        $this->assertStringContainsString('class="cashier-delete-line"', $this->registerView);
        $this->assertStringContainsString('title="Xóa sản phẩm khỏi đơn hàng"', $this->registerView);
        $this->assertStringContainsString('aria-label="Xóa sản phẩm khỏi đơn hàng"', $this->registerView);
        $this->assertStringContainsString('glyphicon glyphicon-trash', $this->registerView);
        $this->assertStringContainsString("$(document).on('click', '.cashier-delete-line'", $this->registerView);
        $this->assertStringContainsString("type: 'post'", $this->registerView);
        $this->assertStringContainsString("site_url('sales/deleteItem/')", $this->registerView);
        $this->assertMatchesRegularExpression('/\.cashier-cart-cell\.pos-cart-delete\s*\{[^}]*justify-content:\s*center;/s', $this->registerCss);
        $this->assertStringNotContainsString('anchor(
                                    "$controller_name/deleteItem/$line"', $this->registerView);
        $this->assertStringNotContainsString('class="pos-delete-line"', $this->registerView);
        $this->assertMatchesRegularExpression('/\.cashier-cart-cell\.unit-price\s*\{[^}]*grid-column:\s*3;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/\.cashier-cart-cell\.quantity\s*\{[^}]*grid-column:\s*4;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/\.cashier-cart-cell\.total\s*\{[^}]*grid-column:\s*5;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/\.cashier-cart-cell\.pos-cart-delete\s*\{[^}]*grid-column:\s*6;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/\.cashier-delete-line\s*\{[^}]*width:\s*30px;[^}]*margin:\s*0;[^}]*background:\s*#fff1f1;[^}]*color:\s*#c62828;/s', $this->registerCss);
        $deleteCellCssStart = strpos($this->registerCss, '.cashier-cart-cell.pos-cart-delete');
        $this->assertIsInt($deleteCellCssStart);
        $deleteCellCssEnd = strpos($this->registerCss, '}', $deleteCellCssStart);
        $this->assertIsInt($deleteCellCssEnd);
        $deleteCellCss = substr($this->registerCss, $deleteCellCssStart, $deleteCellCssEnd - $deleteCellCssStart);
        $this->assertStringNotContainsString('position:', $deleteCellCss);
        $this->assertStringNotContainsString('left:', $deleteCellCss);
        $this->assertStringNotContainsString('order:', $deleteCellCss);
        $this->assertStringNotContainsString('margin-left:', $deleteCellCss);
    }

    public function testRemoveCustomerUsesExplicitPostAjaxRouteAndNotGetLink(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $salesController = file_get_contents(APPPATH . 'Controllers/Sales.php');

        $this->assertStringContainsString("\$routes->post('sales/removeCustomer', 'Sales::postRemoveCustomer', ['filter' => 'csrf']);", $routes);
        $this->assertStringContainsString('public function postRemoveCustomer()', $salesController);
        $this->assertStringContainsString('id="remove_customer_button"', $this->registerView);
        $this->assertStringContainsString('type="button"', $this->registerView);
        $this->assertStringContainsString("site_url('sales/removeCustomer')", $this->registerView);
        $this->assertStringContainsString("type: 'post'", $this->registerView);
        $this->assertStringContainsString("dataType: 'json'", $this->registerView);
        $this->assertStringContainsString('Khách hàng: Khách lẻ', $this->registerView);
        $this->assertStringContainsString('Khách lẻ', $salesController);
        $this->assertStringNotContainsString('"$controller_name/removeCustomer"', $this->registerView);
        $this->assertStringNotContainsString('$.post("<?= site_url(\'sales/removeCustomer\'); ?>", redirect)', $this->registerView);
    }

    public function testSaleLevelDiscountEditorUsesPostAjaxRoutesBeforeGrandTotal(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $salesController = file_get_contents(APPPATH . 'Controllers/Sales.php');

        $this->assertStringContainsString("\$routes->post('sales/applyDiscount', 'Sales::postApplyDiscount', ['filter' => 'csrf']);", $routes);
        $this->assertStringContainsString("\$routes->post('sales/removeDiscount', 'Sales::postRemoveDiscount', ['filter' => 'csrf']);", $routes);
        $this->assertStringContainsString('public function postApplyDiscount()', $salesController);
        $this->assertStringContainsString('public function postRemoveDiscount()', $salesController);
        $this->assertStringContainsString("'id' => 'sale_discount_value'", $this->registerView);
        $this->assertStringContainsString("'id' => 'sale_discount_type'", $this->registerView);
        $this->assertStringContainsString("'id' => 'sale_discount_code'", $this->registerView);
        $this->assertStringContainsString('id="apply_sale_discount"', $this->registerView);
        $this->assertStringContainsString('id="remove_sale_discount"', $this->registerView);
        $this->assertStringContainsString("site_url('sales/applyDiscount')", $this->registerView);
        $this->assertStringContainsString("site_url('sales/removeDiscount')", $this->registerView);
        $this->assertStringContainsString("payload[<?= json_encode(csrf_token()) ?>]", $this->registerView);
        $this->assertStringContainsString("applyCartTotals(response.totals, true)", $this->registerView);

        $discountPosition = strpos($this->registerView, '<tr class="pos-sale-discount-row">');
        $grandTotalPosition = strpos($this->registerView, '<tr class="pos-grand-total cashier-grand-total-row">');
        $this->assertIsInt($discountPosition);
        $this->assertIsInt($grandTotalPosition);
        $this->assertLessThan($grandTotalPosition, $discountPosition);

        $this->assertMatchesRegularExpression('/#sale_totals \\.pos-sale-discount-row > th\s*\{[^}]*min-height:\s*78px;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/\\.pos-sale-discount-editor\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\) 78px;/s', $this->registerCss);
    }

    public function testPaymentPanelShowsGrandTotalBeforeTenderedAndNoPaidTotalBlock(): void
    {
        $this->assertStringContainsString('<tr class="pos-grand-total cashier-grand-total-row">', $this->registerView);
        $this->assertStringContainsString('<th class="cashier-grand-total-label">TỔNG CỘNG</th>', $this->registerView);
        $this->assertStringContainsString('<th class="cashier-grand-total-value"><span id="sale_total">', $this->registerView);
        $this->assertStringNotContainsString('id="payment_totals"', $this->registerView);
        $this->assertStringNotContainsString('Đã thanh toán', $this->registerView);
        $this->assertStringNotContainsString('Số còn lại phải thanh toán', $this->registerView);
        $this->assertStringNotContainsString('id="sale_payments_total"', $this->registerView);
        $this->assertStringNotContainsString('pos-payment-title', $this->registerView);
        $this->assertStringContainsString('<tr class="pos-amount-tendered-row">', $this->registerView);
        $grandTotalPosition = strpos($this->registerView, '<tr class="pos-grand-total cashier-grand-total-row">');
        $tenderedPosition = strpos($this->registerView, '<tr class="pos-amount-tendered-row">');
        $quickCashPosition = strpos($this->registerView, '<tr class="pos-quick-cash-row">');
        $paymentTypePosition = strpos($this->registerView, '<td>Hình thức</td>');
        $changePosition = strpos($this->registerView, '<tr class="pos-change-row">');

        $this->assertIsInt($grandTotalPosition);
        $this->assertIsInt($tenderedPosition);
        $this->assertIsInt($quickCashPosition);
        $this->assertIsInt($paymentTypePosition);
        $this->assertIsInt($changePosition);
        $this->assertLessThan($tenderedPosition, $grandTotalPosition);
        $this->assertLessThan($quickCashPosition, $tenderedPosition);
        $this->assertLessThan($paymentTypePosition, $quickCashPosition);
        $this->assertLessThan($changePosition, $paymentTypePosition);
        $this->assertMatchesRegularExpression('/#sale_totals \.cashier-grand-total-row > th\s*\{[^}]*color:\s*#d32f2f !important;[^}]*font-size:\s*18px;[^}]*text-transform:\s*uppercase;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/#sale_totals \.cashier-grand-total-value #sale_total\s*\{[^}]*color:\s*#d32f2f !important;[^}]*font-weight:\s*900 !important;/s', $this->registerCss);
        $this->assertMatchesRegularExpression('/#sale_totals \.cashier-grand-total-value #sale_total\s*\{[^}]*font-size:\s*22px;[^}]*line-height:\s*24px;/s', $this->registerCss);
        $this->assertStringContainsString('class="btn btn-default btn-xs pos-cash-shortcut"', $this->registerView);
        $this->assertStringContainsString("form_dropdown('payment_type'", $this->registerView);
        $this->assertStringNotContainsString('#sale_payments_total', $this->registerView);
    }
}
