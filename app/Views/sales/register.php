<?php
/**
 * @var string $controller_name
 * @var array $modes
 * @var array $mode
 * @var array $empty_tables
 * @var array $selected_table
 * @var array $stock_locations
 * @var array $stock_location
 * @var array $cart
 * @var bool $items_module_allowed
 * @var bool $change_price
 * @var int $customer_id
 * @var int $customer_discount_type
 * @var float $customer_discount
 * @var float $customer_total
 * @var string $customer_required
 * @var float|int $item_count
 * @var float|int $total_units
 * @var float $subtotal
 * @var array $taxes
 * @var float $total
 * @var float $payments_total
 * @var float $amount_due
 * @var bool $payments_cover_total
 * @var array $payment_options
 * @var array $selected_payment_type
 * @var bool $pos_mode
 * @var array $payments
 * @var string $mode_label
 * @var string $comment
 * @var array $quick_items
 * @var int $quick_current_page
 * @var int $quick_total_pages
 * @var bool $print_after_sale
 * @var bool $email_receipt
 * @var bool $price_work_orders
 * @var string $invoice_number
 * @var int $cash_mode
 * @var float $non_cash_total
 * @var float $cash_amount_due
 * @var array $config
 * @var array $cashier_order_tabs
 * @var string $cashier_active_order_id
 * @var array $cashier_active_order
 */

use App\Models\Employee;

$register_css_version = is_file(FCPATH . 'css/register.css') ? filemtime(FCPATH . 'css/register.css') : time();

?>

<?= view('partial/header') ?>
<link rel="stylesheet" href="<?= base_url('css/register.css?v=' . $register_css_version) ?>">
<script>
    document.body.classList.add('sales-register-screen');
</script>

<?php
if (isset($error)) {
    echo '<div class="alert alert-dismissible alert-danger">' . esc($error) . '</div>';
}

if (isset($success)) {
    echo '<div class="alert alert-dismissible alert-success">' . esc($success) . '</div>';
}

helper('url');

$hidden_payment_options = array_filter(
    [lang('Sales.giftcard'), lang('Sales.rewards')],
    static fn ($payment_option): bool => $payment_option !== null && $payment_option !== ''
);
$register_payment_options = array_diff_key($payment_options, array_flip($hidden_payment_options));
$register_selected_payment_type = in_array($selected_payment_type, array_keys($register_payment_options), true)
    ? $selected_payment_type
    : (array_key_exists(lang('Sales.cash'), $register_payment_options)
        ? lang('Sales.cash')
        : (array_key_first($register_payment_options) ?: $selected_payment_type));
$active_order_amount_tendered = $cashier_active_order['amount_tendered'] ?? null;
?>

<div id="register_wrapper">

    <!-- Top register controls -->
    <?= form_open("$controller_name/changeMode", ['id' => 'mode_form', 'class' => 'form-horizontal panel panel-default pos-hidden-register-control']) ?>
        <div class="panel-body form-group">
            <ul>
                <li class="pull-left first_li">
                    <label class="control-label"><?= lang(ucfirst($controller_name) . '.mode') ?></label>
                </li>
                <li class="pull-left">
                    <?= form_dropdown('mode', $modes, $mode, ['onchange' => "$('#mode_form').submit();", 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => 'fit']) ?>
                </li>
                <?php if ($config['dinner_table_enable']) { ?>
                    <li class="pull-left first_li">
                        <label class="control-label"><?= lang(ucfirst($controller_name) . '.table') ?></label>
                    </li>
                    <li class="pull-left">
                        <?= form_dropdown('dinner_table', $empty_tables, $selected_table, ['onchange' => "$('#mode_form').submit();", 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => 'fit']) ?>
                    </li>
                <?php } ?>
                <?php if (count($stock_locations) > 1) { ?>
                    <li class="pull-left">
                        <label class="control-label"><?= lang(ucfirst($controller_name) . '.stock_location') ?></label>
                    </li>
                    <li class="pull-left">
                        <?= form_dropdown('stock_location', $stock_locations, $stock_location, ['onchange' => "$('#mode_form').submit();", 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => 'fit']) ?>
                    </li>
                <?php } ?>

                <li class="pull-right">
                    <button class="btn btn-default btn-sm modal-dlg" id="show_suspended_sales_button" data-href="<?= esc("$controller_name/suspended") ?>"
                        title="<?= lang(ucfirst($controller_name) . '.suspended_sales') ?>">
                        <span class="glyphicon glyphicon-align-justify">&nbsp;</span><?= lang(ucfirst($controller_name) . '.suspended_sales') ?>
                    </button>
                </li>

                <?php
                $employee = model(Employee::class);
                if ($employee->has_grant('reports_sales', session('person_id'))) {
                ?>
                    <li class="pull-right">
                        <?= anchor(
                            "$controller_name/manage",
                            '<span class="glyphicon glyphicon-list-alt">&nbsp;</span>' . lang(ucfirst($controller_name) . '.takings'),
                            array('class' => 'btn btn-primary btn-sm', 'id' => 'sales_takings_button', 'title' => lang(ucfirst($controller_name) . '.takings'))
                        ) ?>
                    </li>
                <?php } ?>
            </ul>
        </div>
    <?= form_close() ?>

    <?php $tabindex = 0; ?>

    <?= form_open("$controller_name/add", ['id' => 'add_item_form', 'class' => 'form-horizontal panel panel-default pos-product-search']) ?>
        <div class="panel-body form-group">
            <div class="pos-search-row">
                <button type="button" class="btn pos-search-menu" title="Tìm kiếm"><span class="glyphicon glyphicon-search"></span></button>
                <?= form_input(['name' => 'item', 'id' => 'item', 'class' => 'form-control input-sm pos-search-input', 'tabindex' => ++$tabindex, 'placeholder' => 'Tìm kiếm mặt hàng - F1']) ?>
                <span class="ui-helper-hidden-accessible" role="status"></span>
                <div class="pos-order-tabs" role="tablist">
                    <?php foreach ($cashier_order_tabs as $cashier_order_tab) { ?>
                        <button
                            type="button"
                            class="pos-order-tab<?= $cashier_order_tab['active'] ? ' active' : '' ?>"
                            data-order-id="<?= esc($cashier_order_tab['id']) ?>"
                            data-has-open-data="<?= $cashier_order_tab['has_open_data'] ? '1' : '0' ?>"
                            aria-selected="<?= $cashier_order_tab['active'] ? 'true' : 'false' ?>">
                            <strong><?= esc($cashier_order_tab['label']) ?></strong>
                            <small><?= esc($cashier_order_tab['subtitle']) ?></small>
                            <span class="pos-order-close" aria-hidden="true">x</span>
                        </button>
                    <?php } ?>
                </div>
                <button
                    type="button"
                    class="btn pos-add-tab"
                    id="pos_add_order_button"
                    title="Thêm đơn hàng">
                    <span class="glyphicon glyphicon-plus-sign"></span>
                </button>
                <button id="new_item_button" class="btn btn-default btn-sm modal-dlg pos-hidden-register-control" data-btn-new="<?= lang('Common.new') ?>" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= "items/view" ?>" title="<?= lang(ucfirst($controller_name) . ".new_item") ?>">
                    <span class="glyphicon glyphicon-tag"></span>
                </button>
                <a href="<?= site_url('home') ?>" class="btn" id="cashier-home-button" title="Trang chủ">
                    <span class="glyphicon glyphicon-home"></span>
                </a>
            </div>
        </div>
    <?= form_close() ?>

    <div class="pos-left-control-bar">
        <div><span class="glyphicon glyphicon-shopping-cart"></span> Kênh bán hàng</div>
        <div><span class="glyphicon glyphicon-refresh"></span> Đổi/Trả hàng</div>
        <div>Giá niêm yết <span class="caret"></span></div>
        <div>Admin <span class="caret"></span></div>
    </div>


    <!-- Sale Items List -->

    <div class="pos-cart-panel">
    <div class="cashier-cart-list pos-cart-table" id="register">
        <div class="cashier-cart-header pos-cart-row pos-cart-header">
            <div class="cashier-cart-cell code pos-cart-code">Mã</div>
            <div class="cashier-cart-cell name pos-cart-name">Tên hàng hóa</div>
            <div class="cashier-cart-cell unit-price pos-cart-price">Đơn giá</div>
            <div class="cashier-cart-cell quantity pos-cart-quantity">SL</div>
            <div class="cashier-cart-cell total pos-cart-total">Thành tiền</div>
            <div class="cashier-cart-cell pos-cart-delete">Xóa</div>
        </div>

        <div id="cart_contents" class="cashier-cart-body">
            <?php if (count($cart) == 0) { ?>
                <div class="pos-cart-empty-row">
                    <div class="cashier-cart-cell pos-cart-empty-cell">
                        <div class="alert alert-dismissible alert-info pos-empty-cart">Chưa có sản phẩm trong hóa đơn</div>
                    </div>
                </div>
            <?php
            } else {
                foreach (array_reverse($cart, true) as $line => $item) {
                    $cart_form_id = "cart_$line";
            ?>
                    <?= form_open("$controller_name/editItem/$line", ['class' => 'cashier-cart-row-form', 'id' => $cart_form_id, 'data-line' => $line]) ?>
                    <?= form_close() ?>
                    <div class="cashier-cart-row pos-cart-row" data-line="<?= esc($line) ?>" data-cart-form="<?= esc($cart_form_id) ?>">
                            <?php if ($item['item_type'] == ITEM_TEMP) { ?>
                                <div class="cashier-cart-cell code pos-cart-code">
                                    <?= form_input(['name' => 'item_number', 'id' => "item_number_$line", 'class' => 'form-control input-sm pos-item-number-input', 'value' => $item['item_number'], 'tabindex' => ++$tabindex, 'form' => $cart_form_id]) ?>
                                </div>
                                <div class="cashier-cart-cell name pos-cart-name">
                                    <?= form_input(['name' => 'name', 'id' => "name_$line", 'class' => 'form-control input-sm pos-item-name-input', 'value' => $item['name'], 'tabindex' => ++$tabindex, 'form' => $cart_form_id]) ?>
                                    <?= form_input(['type' => 'hidden', 'name' => 'description', 'value' => $item['description'], 'form' => $cart_form_id]) ?>
                                    <?= form_input(['type' => 'hidden', 'name' => 'serialnumber', 'value' => $item['serialnumber'] ?? '', 'form' => $cart_form_id]) ?>
                                </div>
                            <?php } else { ?>
                                <div class="cashier-cart-cell code pos-cart-code">
                                    <div class="pos-cart-code-content">
                                        <div class="pos-item-code"><?= esc($item['item_number']) ?></div>
                                        <?= view('sales/unit_selector', ['item' => $item, 'line' => $line]) ?>
                                    </div>
                                </div>
                                <div class="cashier-cart-cell name pos-cart-name">
                                    <div class="pos-item-title"><?= esc($item['name']) . ' ' . esc(implode(' ', [$item['attribute_values'], $item['attribute_dtvalues']])) ?></div>
                                    <?= form_input(['type' => 'hidden', 'name' => 'description', 'value' => $item['description'], 'form' => $cart_form_id]) ?>
                                    <?= form_input(['type' => 'hidden', 'name' => 'serialnumber', 'value' => $item['serialnumber'] ?? '', 'form' => $cart_form_id]) ?>
                                </div>
                            <?php } ?>

                            <div class="cashier-cart-cell unit-price pos-cart-price">
                                <?php
                                if ($items_module_allowed && $change_price) {
                                    echo form_input(['name' => 'price', 'class' => 'form-control input-sm', 'value' => to_currency_no_money($item['price']), 'tabindex' => ++$tabindex, 'onClick' => 'this.select();', 'form' => $cart_form_id]);
                                } else {
                                    echo to_currency($item['price']);
                                    echo form_input(['type' => 'hidden', 'name' => 'price', 'value' => to_currency_no_money($item['price']), 'form' => $cart_form_id]);
                                }
                                ?>
                            </div>

                            <div class="cashier-cart-cell quantity pos-cart-quantity">
                                <?php
                                echo form_input(['type' => 'hidden', 'name' => 'location', 'value' => (string)$item['item_location'], 'form' => $cart_form_id]);
                                echo form_input(['type' => 'hidden', 'name' => 'item_id', 'value' => $item['item_id'], 'form' => $cart_form_id]);
                                if (!$item['is_serialized']) {
                                    echo '<div class="pos-quantity-control">';
                                    echo '<button type="button" class="btn btn-default btn-xs pos-qty-step" data-step="-1" title="Giảm số lượng"><span class="glyphicon glyphicon-minus"></span></button>';
                                }
                                if ($item['is_serialized']) {
                                    echo '<span class="pos-serialized-qty">' . to_quantity_decimals($item['quantity']) . '</span>';
                                    echo form_input(['type' => 'hidden', 'name' => 'quantity', 'value' => $item['quantity'], 'form' => $cart_form_id]);
                                } else {
                                    echo form_input(['name' => 'quantity', 'class' => 'form-control input-sm pos-quantity-input', 'value' => to_quantity_decimals($item['quantity']), 'tabindex' => ++$tabindex, 'onClick' => 'this.select();', 'form' => $cart_form_id]);
                                }
                                if (!$item['is_serialized']) {
                                    echo '<button type="button" class="btn btn-default btn-xs pos-qty-step" data-step="1" title="Tăng số lượng"><span class="glyphicon glyphicon-plus"></span></button>';
                                    echo '</div>';
                                }
                                ?>
                            </div>

                            <div class="cashier-cart-cell total pos-cart-total">
                                <?php
                                if ($item['item_type'] == ITEM_AMOUNT_ENTRY) {    // TODO: === ?
                                    echo form_input(['name' => 'discounted_total', 'class' => 'form-control input-sm', 'value' => to_currency_no_money($item['discounted_total']), 'tabindex' => ++$tabindex, 'onClick' => 'this.select();', 'form' => $cart_form_id]);
                                } else {
                                    echo to_currency($item['discounted_total']);
                                }
                                ?>
                            </div>

                            <div class="cashier-cart-cell pos-cart-delete">
                                <button
                                    type="button"
                                    class="cashier-delete-line"
                                    data-line="<?= esc($line) ?>"
                                    title="Xóa sản phẩm khỏi đơn hàng"
                                    aria-label="Xóa sản phẩm khỏi đơn hàng">
                                    <span class="glyphicon glyphicon-trash" aria-hidden="true"></span>
                                    <span class="sr-only">Xóa sản phẩm khỏi đơn hàng</span>
                                </button>
                            </div>
                    </div>
            <?php
                }
            }
            ?>
        </div>
    </div>
    </div>
    <div class="pos-cart-footer">
        <span>Tự động tạo mã (Đơn hàng)</span>
        <span><span class="glyphicon glyphicon-edit"></span> Ghi chú</span>
        <span><?= date('d/m/Y H:i') ?></span>
        <span class="pos-cart-pager">
            <?php if (($quick_current_page ?? 1) > 1) { ?>
                <?= anchor("$controller_name?quick_page=" . (($quick_current_page ?? 1) - 1), '<span class="glyphicon glyphicon-chevron-left"></span>', ['class' => 'pos-quick-page-link']) ?>
            <?php } else { ?>
                <span class="glyphicon glyphicon-chevron-left pos-quick-page-disabled"></span>
            <?php } ?>
            <?= esc($quick_current_page ?? 1) ?> / <?= esc($quick_total_pages ?? 1) ?>
            <?php if (($quick_current_page ?? 1) < ($quick_total_pages ?? 1)) { ?>
                <?= anchor("$controller_name?quick_page=" . (($quick_current_page ?? 1) + 1), '<span class="glyphicon glyphicon-chevron-right"></span>', ['class' => 'pos-quick-page-link']) ?>
            <?php } else { ?>
                <span class="glyphicon glyphicon-chevron-right pos-quick-page-disabled"></span>
            <?php } ?>
        </span>
    </div>
    <div class="pos-quick-grid">
        <?php foreach (($quick_items ?? []) as $quick_item) { ?>
            <button type="button" class="pos-quick-item" data-item-id="<?= esc($quick_item->item_id) ?>">
                <span class="pos-quick-price"><?= to_currency($quick_item->unit_price ?? 0) ?></span>
                <span class="pos-quick-name"><?= esc($quick_item->name ?? '') ?></span>
            </button>
        <?php } ?>
    </div>
</div>

<!-- Overall Sale -->

<div id="overall_sale" class="panel panel-default">
    <div class="panel-body">
        <?= form_open("$controller_name/selectCustomer", ['id' => 'select_customer_form', 'class' => 'form-horizontal']) ?>
            <?php if (isset($customer)) { ?>
                <?= form_hidden('customer', (string) $customer_id) ?>
                <div class="pos-side-section pos-customer-card">
                    <div class="pos-section-title">Khách hàng</div>
                    <div class="pos-customer-name">
                        <?= anchor(
                            "customers/viewCashier/$customer_id",
                            esc($customer),
                            [
                                'class'            => 'cashier-customer-modal modal-dlg-cashier-customer',
                                'data-customer-id' => (int) $customer_id,
                                'data-href'        => "customers/viewCashier/$customer_id",
                                'title'            => lang('Customers.update')
                            ]
                        ) ?>
                    </div>
                    <div class="pos-customer-meta">
                        <?php if (!empty($customer_phone_number)) { ?>
                            <span><?= esc($customer_phone_number) ?></span>
                        <?php } else { ?>
                            <span>Chưa có số điện thoại</span>
                        <?php } ?>
                        <span>Điểm: <?= esc($customer_points ?? 0) ?></span>
                    </div>
                    <div class="pos-customer-actions">
                        <button type="button" class="btn btn-default btn-sm" id="change_customer_button">Đổi khách</button>
                        <button type="button" class="btn btn-danger btn-sm pos-remove-customer-button" id="remove_customer_button" title="<?= esc(lang('Common.remove') . ' ' . lang('Customers.customer'), 'attr') ?>">
                            <span class="glyphicon glyphicon-remove"></span>
                        </button>
                    </div>
                </div>
            <?php } else { ?>
                <div class="pos-side-section form-group" id="select_customer">
                    <div class="pos-section-title">Khách hàng: Khách lẻ</div>
                    <div class="pos-customer-search-row">
                        <?= form_input(['name' => 'customer', 'id' => 'customer', 'class' => 'form-control input-sm', 'value' => '', 'placeholder' => 'Tìm khách hàng']) ?>
                        <button type="button" class="btn btn-sm cashier-customer-modal pos-new-customer-button" data-href="<?= "customers/viewCashier" ?>" data-mode="create" title="<?= lang(ucfirst($controller_name) . ".new_customer") ?>">
                            <span class="glyphicon glyphicon-plus"></span>
                        </button>
                    </div>
                    <button class="btn btn-default btn-sm modal-dlg pos-hidden-register-control" id="show_keyboard_help" data-href="<?= esc("$controller_name/salesKeyboardHelp") ?>" title="<?= lang(ucfirst($controller_name) . '.key_title') ?>">
                        <span class="glyphicon glyphicon-share-alt">&nbsp;</span><?= lang(ucfirst($controller_name) . '.key_help') ?>
                    </button>
                </div>
            <?php } ?>
        <?= form_close() ?>

        <table class="sales_table_100 pos-side-section" id="sale_totals">
            <tr>
                <th>Trạng thái</th>
                <th>
                    <select class="form-control input-sm pos-status-select" disabled>
                        <option>Hoàn thành</option>
                    </select>
                </th>
            </tr>
            <tr class="pos-muted-total">
                <th id="sale_item_count_label"><?= lang(ucfirst($controller_name) . '.quantity_of_items', [$item_count]) ?></th>
                <th id="sale_total_units"><?= $total_units ?></th>
            </tr>
            <tr>
                <th>Tổng thành tiền</th>
                <th id="sale_subtotal"><?= to_currency($subtotal) ?></th>
            </tr>
            <tr>
                <th><?= isset($customer) ? 'Điểm hiện có' : 'Điểm thưởng' ?></th>
                <th><?= esc($customer_points ?? 0) ?></th>
            </tr>
            <tr class="pos-sale-discount-row">
                <th>
                    <label for="sale_discount_value" id="sale_discount_label"><?= esc($order_discount_label ?? 'Giảm giá') ?></label>
                    <div class="pos-sale-discount-editor">
                        <?= form_input([
                            'name' => 'sale_discount_value',
                            'id' => 'sale_discount_value',
                            'class' => 'form-control input-sm',
                            'value' => isset($order_discount_value) && (float) $order_discount_value > 0 ? to_currency_no_money($order_discount_value) : '',
                            'placeholder' => '0',
                        ]) ?>
                        <?= form_dropdown(
                            'sale_discount_type',
                            [
                                'percent' => '%',
                                'fixed' => 'Số tiền',
                            ],
                            ($order_discount_type ?? PERCENT) === FIXED ? 'fixed' : 'percent',
                            ['id' => 'sale_discount_type', 'class' => 'form-control input-sm']
                        ) ?>
                    </div>
                    <?= form_input([
                        'name' => 'sale_discount_code',
                        'id' => 'sale_discount_code',
                        'class' => 'form-control input-sm pos-sale-discount-code',
                        'value' => $order_discount_code ?? '',
                        'maxlength' => 64,
                        'placeholder' => 'Mã voucher',
                    ]) ?>
                    <div id="sale_discount_error" class="text-danger pos-sale-discount-message"></div>
                </th>
                <th>
                    <div id="sale_discount_amount" class="pos-sale-discount-amount">
                        <?= (float) ($order_discount_amount ?? 0) > 0 ? '-' . to_currency($order_discount_amount) : 'Chưa áp dụng' ?>
                    </div>
                    <div class="pos-sale-discount-actions">
                        <button type="button" id="apply_sale_discount" class="btn btn-xs btn-primary">Áp dụng</button>
                        <button type="button" id="remove_sale_discount" class="btn btn-xs btn-default">Hủy</button>
                    </div>
                </th>
            </tr>
            <?php foreach ($taxes as $tax_group_index => $tax) { ?>
                <tr class="pos-muted-total">
                    <th><?= (float)$tax['tax_rate'] . '% ' . $tax['tax_group'] ?></th>
                    <th><?= to_currency_tax($tax['sale_tax_amount']) ?></th>
                </tr>
            <?php } ?>
            <tr class="pos-grand-total cashier-grand-total-row">
                <th class="cashier-grand-total-label">TỔNG CỘNG</th>
                <th class="cashier-grand-total-value"><span id="sale_total"><?= to_currency($total) ?></span></th>
            </tr>
        </table>

        <?php if (count($cart) > 0) { // Only show this part if there are Items already in the register ?>
            <div id="payment_details">
                <span id="sale_amount_due" class="sr-only"><?= to_currency($amount_due) ?></span>
                <?php if ($payments_cover_total) { // Show Complete sale button instead of Add Payment if there is no amount due left ?>
                    <?= form_open("$controller_name/addPayment", ['id' => 'add_payment_form', 'class' => 'form-horizontal']) ?>
                        <input type="hidden" name="complete_after_payment" value="0">
                        <table class="sales_table_100 pos-payment-entry">
                            <tr class="pos-amount-tendered-row">
                                <td><span id="amount_tendered_label">Tiền khách đưa</span></td>
                                <td>
                                    <?= form_input([
                                        'name' => 'amount_tendered',
                                        'id' => 'amount_tendered',
                                        'class' => 'form-control input-sm disabled',
                                        'disabled' => 'disabled',
                                        'value' => '0',
                                        'size' => '5',
                                        'tabindex' => ++$tabindex,
                                        'onClick' => 'this.select();'
                                    ]) ?>
                                </td>
                            </tr>
                            <tr class="pos-quick-cash-row">
                                <td></td>
                                <td>
                                    <div class="pos-quick-cash">
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="due">Đủ tiền</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="1000">1K</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="2000">2K</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="5000">5K</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="10000">10K</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="20000">20K</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="50000">50.000</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="100000">100.000</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="200000">200.000</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="500000">500.000</button>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>Hình thức</td>
                                <td>
                                    <?= form_dropdown('payment_type', $register_payment_options, $register_selected_payment_type, ['id' => 'payment_types', 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => '100%', 'disabled' => 'disabled']) ?>
                                </td>
                            </tr>
                            <tr class="pos-change-row">
                                <td>Tiền thừa</td>
                                <td><span id="pos_change_due"><?= to_currency(abs(min(0, $amount_due))) ?></span></td>
                            </tr>
                            <tr class="pos-payment-warning-row">
                                <td colspan="2"><div id="pos_payment_warning" class="alert alert-warning">Tiền khách đưa nhỏ hơn tổng thanh toán</div></td>
                            </tr>
                            <tr class="reference-code-input reference-code-input-hidden">
                                <td style="padding-top: 8px;"><span id='reference_code_label'><?= lang('Sales.reference_code') ?></span></td>
                                <td style="padding-top: 8px;">
                                    <?php echo form_input(array(
                                        'name'	=> 'reference_code',
                                        'id'	=> 'reference_code',
                                        'class'	=> 'form-control input-sm non-giftcard-input',
                                        'disabled' => true,
                                        'value'	=> '',
                                        'size' => 5,
                                        'tabindex'	=> ++$tabindex,
                                        'onClick'	=> 'this.select();'));
                                    ?>
                                </td>
                            </tr>
                        </table>
                    <?= form_close() ?>

                    <?php
                    // Only show this part if in sale or return mode
                    if ($pos_mode) {
                        $due_payment = false;

                        if (count($payments) > 0) {
                            foreach ($payments as $payment_id => $payment) {
                                if ($payment['payment_type'] == lang(ucfirst($controller_name) . '.due')) {
                                    $due_payment = true;
                                }
                            }
                        }

                        if (!$due_payment || ($due_payment && isset($customer))) {    // TODO: $due_payment is not needed because the first clause insures that it will always be true if it gets to this point.  Can be shortened to if (!$due_payment || isset($customer))
                    ?>
                            <div class="btn btn-sm btn-success btn-block pos-complete-button" id="cashier-complete-sale" tabindex="<?= ++$tabindex ?>">
                                <span class="glyphicon glyphicon-ok">&nbsp;</span>Hoàn tất thanh toán
                            </div>
                    <?php
                        }
                    }
                    ?>
                <?php } else { ?>
                    <?= form_open("$controller_name/addPayment", ['id' => 'add_payment_form', 'class' => 'form-horizontal']) ?>
                        <input type="hidden" name="complete_after_payment" value="0">
                        <table class="sales_table_100 pos-payment-entry">
                            <tr class="pos-amount-tendered-row">
                                <td><span id="amount_tendered_label">Tiền khách đưa</span></td>
                                <td>
                                    <?= form_input(['name' => 'amount_tendered', 'id' => 'amount_tendered', 'class' => 'form-control input-sm non-giftcard-input', 'value' => $active_order_amount_tendered ?? to_currency_no_money($amount_due), 'size' => '5', 'tabindex' => ++$tabindex, 'onClick' => 'this.select();']) ?>
                                    <?= form_input(['name' => 'amount_tendered', 'id' => 'amount_tendered', 'class' => 'form-control input-sm giftcard-input', 'disabled' => true, 'value' => $active_order_amount_tendered ?? to_currency_no_money($amount_due), 'size' => '5', 'tabindex' => ++$tabindex]) ?>
                                </td>
                            </tr>
                            <tr class="pos-quick-cash-row">
                                <td></td>
                                <td>
                                    <div class="pos-quick-cash">
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="due">Đủ tiền</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="1000">1K</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="2000">2K</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="5000">5K</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="10000">10K</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="20000">20K</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="50000">50.000</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="100000">100.000</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="200000">200.000</button>
                                        <button type="button" class="btn btn-default btn-xs pos-cash-shortcut" data-amount="500000">500.000</button>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>Hình thức</td>
                                <td>
                                    <?= form_dropdown('payment_type', $register_payment_options, $register_selected_payment_type, ['id' => 'payment_types', 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => '100%']) ?>
                                </td>
                            </tr>
                            <tr class="pos-change-row">
                                <td>Tiền thừa</td>
                                <td><span id="pos_change_due">0</span></td>
                            </tr>
                            <tr class="pos-payment-warning-row">
                                <td colspan="2"><div id="pos_payment_warning" class="alert alert-warning">Tiền khách đưa nhỏ hơn tổng thanh toán</div></td>
                            </tr>
                            <tr class="reference-code-input reference-code-input-hidden">
                                <td style="padding-top: 8px;"><span id='reference_code_label'><?= lang('Sales.reference_code') ?></span></td>
                                <td style="padding-top: 8px;">
                                    <?php echo form_input(array(
                                        'name'     => 'reference_code',
                                        'id'       => 'reference_code',
                                        'class'    => 'form-control input-sm non-giftcard-input',
                                        'disabled' => true,
                                        'value'    => '',
                                        'size'     => 5,
                                        'tabindex' => ++$tabindex,
                                        'onClick'  => 'this.select();'));
                                    ?>
                                </td>
                            </tr>
                        </table>
                    <?= form_close() ?>

                    <div class="btn btn-sm btn-success btn-block pos-complete-button" id="cashier-complete-sale" tabindex="<?= ++$tabindex ?>">
                        <span class="glyphicon glyphicon-ok">&nbsp;</span>Hoàn tất thanh toán
                    </div>
                <?php } ?>

                <?php if (count($payments) > 0) { // Only show this part if there is at least one payment entered. ?>
                    <table class="sales_table_100 pos-payment-list" id="register">
                        <thead>
                            <tr>
                                <th style="width: 10%;"></th>
                                <th style="width: 60%;"><?= lang(ucfirst($controller_name) . '.payment_type') ?></th>
                                <th style="width: 20%;"><?= lang(ucfirst($controller_name) . '.payment_amount') ?></th>
                            </tr>
                        </thead>

                        <tbody id="payment_contents">
                            <?php foreach ($payments as $payment_id => $payment) { ?>
                                <tr>
                                    <td><?= anchor("$controller_name/deletePayment/". esc(base64url_encode($payment_id), 'url'), '<span class="glyphicon glyphicon-trash"></span>') ?></td>
                                    <td><?= $payment['payment_type'] ?></td>
                                    <td style="text-align: right;"><?= to_currency($payment['payment_amount']) ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } ?>
            </div>

            <?= form_open("$controller_name/cancel", ['id' => 'buttons_form']) ?>
            <div class="form-group" id="buttons_sale">
                <div class="btn btn-sm btn-default pull-left pos-hidden-register-control" id="suspend_sale_button"><span class="glyphicon glyphicon-align-justify">&nbsp;</span><?= lang(ucfirst($controller_name) . '.suspend_sale') ?></div>
                <?php if (!$pos_mode && isset($customer)) { // Only show this part if the payment covers the total ?>
                    <div class="btn btn-sm btn-success pos-hidden-register-control" id="finish_invoice_quote_button"><span class="glyphicon glyphicon-ok">&nbsp;</span><?= esc($mode_label) ?></div>
                <?php } ?>

                <button type="button" class="btn pos-print-button" id="cashier-preview-print"><span class="glyphicon glyphicon-print"></span></button>
                <div class="btn btn-sm btn-default pos-cancel-button" id="cancel_sale_button"><span class="glyphicon glyphicon-remove">&nbsp;</span><?= lang(ucfirst($controller_name) . '.cancel_sale') ?></div>
            </div>
            <?= form_close() ?>

            <?php if ($payments_cover_total || !$pos_mode) { // Only show this part if the payment cover the total ?>
                <div class="container-fluid pos-sale-options">
                    <div class="no-gutter row">
                        <div class="form-group form-group-sm">
                            <div class="col-xs-12">
                                <?= form_label(lang('Common.comments'), 'comments', ['class' => 'control-label', 'id' => 'comment_label', 'for' => 'comment']) ?>
                                <?= form_textarea(['name' => 'comment', 'id' => 'comment', 'class' => 'form-control input-sm', 'value' => $comment, 'rows' => '2']) ?>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="form-group form-group-sm">
                            <div class="col-xs-6">
                                <label for="sales_print_after_sale" class="control-label checkbox">
                                    <?= form_checkbox(['name' => 'sales_print_after_sale', 'id' => 'sales_print_after_sale', 'value' => 1, 'checked' => $print_after_sale]) ?>
                                    <?= lang(ucfirst($controller_name) . '.print_after_sale') ?>
                                </label>
                            </div>

                            <?php if (!empty($customer_email)) { ?>
                                <div class="col-xs-6 pos-hidden-register-control">
                                    <label for="email_receipt" class="control-label checkbox">
                                        <?= form_checkbox(['name' => 'email_receipt', 'id' => 'email_receipt', 'value' => 1, 'checked' => $email_receipt]) ?>
                                        <?= lang(ucfirst($controller_name) . '.email_receipt') ?>
                                    </label>
                                </div>
                            <?php } ?>
                            <?php if ($mode == 'sale_work_order') { ?>
                                <div class="col-xs-6 pos-hidden-register-control">
                                    <label for="price_work_orders" class="control-label checkbox">
                                        <?= form_checkbox(['name' => 'price_work_orders', 'id' => 'price_work_orders', 'value' => 1, 'checked' => $price_work_orders]) ?>
                                        <?= lang(ucfirst($controller_name) . '.include_prices') ?>
                                    </label>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                    <?php if (($mode == 'sale_invoice') && $config['invoice_enable']) { ?>
                        <div class="row pos-hidden-register-control">
                            <div class="form-group form-group-sm">
                                <div class="col-xs-6">
                                    <label for="sales_invoice_number" class="control-label checkbox">
                                        <?= lang(ucfirst($controller_name) . '.invoice_enable') ?>
                                    </label>
                                </div>

                                <div class="col-xs-6">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-addon input-sm">#</span>
                                        <?= form_input(['name' => 'sales_invoice_number', 'id' => 'sales_invoice_number', 'class' => 'form-control input-sm', 'value' => $invoice_number]) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
        <?php
            }
        }
        ?>
    </div>
</div>

<iframe
    id="cashier-print-frame"
    name="cashier-print-frame"
    title="In hóa đơn"
    aria-hidden="true"></iframe>

<script type="text/javascript">
    const keyboardShortcuts = <?= json_encode($keyboardShortcuts ?? []) ?>;
    const paymentsCoverTotal = <?= json_encode((bool) $payments_cover_total) ?>;
    const referenceCodePaymentTypes = <?= json_encode($reference_code_payment_types) ?>;
    let registerAmountDue = <?= json_encode((float) $amount_due) ?>;
    const registerHasCartItems = <?= json_encode(count($cart) > 0) ?>;
    const cashierActiveOrderId = <?= json_encode($cashier_active_order_id) ?>;
    const registerInitialTenderedAmount = <?= json_encode($active_order_amount_tendered) ?>;
    let registerCompletionInProgress = false;
    let saveTenderedTimer = null;
    const shortcutCodes = {
        items: keyboardShortcuts?.items?.code ?? null,
        customers: keyboardShortcuts?.customers?.code ?? null,
        suspend: keyboardShortcuts?.suspend?.code ?? null,
        suspended: keyboardShortcuts?.suspended?.code ?? null,
        amount: keyboardShortcuts?.amount?.code ?? null,
        payment: keyboardShortcuts?.payment?.code ?? null,
        complete: keyboardShortcuts?.complete?.code ?? null,
        finish: keyboardShortcuts?.finish?.code ?? null,
        help: keyboardShortcuts?.help?.code ?? null,
        cancel: keyboardShortcuts?.cancel?.code ?? null
    };

    $(document).ready(function() {
        const redirect = function() {
            window.location.href = "<?= site_url('sales'); ?>";
        };

        function renderCustomerSearch() {
            return '<div class="pos-side-section form-group" id="select_customer">'
                + '<div class="pos-section-title">Khách hàng: Khách lẻ</div>'
                + '<div class="pos-customer-search-row">'
                + '<input type="text" name="customer" id="customer" class="form-control input-sm" value="" placeholder="Tìm khách hàng">'
                + '<button type="button" class="btn btn-sm cashier-customer-modal pos-new-customer-button" data-href="<?= esc('customers/viewCashier', 'js') ?>" data-mode="create" title="<?= esc(lang(ucfirst($controller_name) . '.new_customer'), 'js') ?>">'
                + '<span class="glyphicon glyphicon-plus"></span>'
                + '</button>'
                + '</div>'
                + '<button class="btn btn-default btn-sm modal-dlg pos-hidden-register-control" id="show_keyboard_help" data-href="<?= esc("$controller_name/salesKeyboardHelp", 'js') ?>" title="<?= esc(lang(ucfirst($controller_name) . '.key_title'), 'js') ?>">'
                + '<span class="glyphicon glyphicon-share-alt"></span>'
                + '</button>'
                + '</div>';
        }

        function bindCustomerSearch() {
            $('#customer')
                .off('blur.cashierCustomer')
                .on('blur.cashierCustomer', function() {
                    if ($(this).val() == '') {
                        $(this).attr('placeholder', 'Tên hoặc số điện thoại');
                    }
                })
                .off('keypress.cashierCustomer')
                .on('keypress.cashierCustomer', function(e) {
                    if (e.which == 13) {
                        $('#select_customer_form').submit();
                        return false;
                    }
                });

            $('#customer').autocomplete({
                source: "<?= site_url('customers/suggest') ?>",
                appendTo: '#select_customer',
                minChars: 0,
                delay: 10,
                select: function(a, ui) {
                    $(this).val(ui.item.value);
                    $('#select_customer_form').submit();
                    return false;
                }
            });
        }

        function removeCustomerFromActiveOrder(focusCustomerSearch) {
            const $buttons = $('#remove_customer_button, #change_customer_button');
            $buttons.prop('disabled', true);

            $.ajax({
                url: "<?= site_url('sales/removeCustomer'); ?>",
                type: 'post',
                dataType: 'json',
                success: function(response) {
                    if (!response || !response.success) {
                        showRegisterPrintError((response && response.message) || 'Không thể gỡ khách hàng khỏi đơn hiện tại.');
                        $buttons.prop('disabled', false);
                        focusItemSearch(false);
                        return;
                    }

                    $('#select_customer_form input[name="customer"][type="hidden"]').remove();
                    $('.pos-customer-card').replaceWith(renderCustomerSearch());
                    bindCustomerSearch();

                    if (focusCustomerSearch) {
                        $('#customer').focus().select();
                    } else {
                        focusItemSearch(false);
                    }
                },
                error: function() {
                    showRegisterPrintError('Không thể gỡ khách hàng khỏi đơn hiện tại.');
                    $buttons.prop('disabled', false);
                    focusItemSearch(false);
                }
            });
        }

        $(document).on('click', '#remove_customer_button', function() {
            removeCustomerFromActiveOrder(false);
        });

        $(document).on('click', '#change_customer_button', function() {
            removeCustomerFromActiveOrder(true);
        });

        function renderEmptyCartRow() {
            return '<div class="pos-cart-empty-row">'
                + '<div class="cashier-cart-cell pos-cart-empty-cell">'
                + '<div class="alert alert-dismissible alert-info pos-empty-cart">Chưa có sản phẩm trong hóa đơn</div>'
                + '</div>'
                + '</div>';
        }

        function applyCartTotals(totals, preserveTenderedAmount) {
            if (!totals) {
                return;
            }

            preserveTenderedAmount = preserveTenderedAmount === true;
            registerAmountDue = parseLocaleMoney(totals.amount_due_raw);
            $('#sale_item_count_label').text(totals.item_count_label);
            $('#sale_total_units').text(totals.total_units);
            $('#sale_subtotal').text(totals.subtotal);
            $('#sale_discount_label').text(totals.order_discount_label || 'Giảm giá');
            $('#sale_discount_amount').text(totals.order_discount_applied ? '-' + totals.order_discount_amount : 'Chưa áp dụng');
            $('#sale_total').text(totals.total);
            $('#sale_amount_due').text(totals.amount_due);
            $('#pos_change_due').text(totals.change_due);
            if (!preserveTenderedAmount) {
                $('[name="amount_tendered"]:enabled').val(totals.amount_due_raw);
                $('.pos-payment-list').remove();
            }

            if (!totals.payments_cover_total) {
                $('#payment_types').prop('disabled', false).selectpicker('refresh');
                $('.non-giftcard-input[name="amount_tendered"]').prop('disabled', false).removeClass('disabled');
                if (!preserveTenderedAmount) {
                    $('.non-giftcard-input[name="amount_tendered"]').val(totals.amount_due_raw);
                }
                $('#cashier-complete-sale').removeClass('disabled');
            }

            updateRegisterChange();
        }

        function applySaleDiscount(removeDiscount) {
            $('#sale_discount_error').text('');

            const payload = removeDiscount ? {} : {
                discount_type: $('#sale_discount_type').val(),
                discount_value: $('#sale_discount_value').val(),
                discount_code: $('#sale_discount_code').val()
            };
            payload[<?= json_encode(csrf_token()) ?>] = <?= json_encode(csrf_hash()) ?>;
            payload.amount_tendered = getActiveTenderedAmountValue();

            $.ajax({
                url: removeDiscount ? "<?= site_url('sales/removeDiscount'); ?>" : "<?= site_url('sales/applyDiscount'); ?>",
                type: 'post',
                data: payload,
                dataType: 'json',
                success: function(response) {
                    if (!response || !response.success) {
                        const message = (response && response.message) || 'Giá trị giảm giá không hợp lệ.';
                        $('#sale_discount_error').text(message);
                        showRegisterPrintError(message);
                        return;
                    }

                    if (removeDiscount) {
                        $('#sale_discount_value').val('');
                        $('#sale_discount_code').val('');
                    }

                    applyCartTotals(response.totals, true);
                    focusItemSearch(false);
                },
                error: function(xhr) {
                    const response = xhr.responseJSON || {};
                    const message = response.message || 'Giá trị giảm giá không hợp lệ.';
                    $('#sale_discount_error').text(message);
                    showRegisterPrintError(message);
                }
            });
        }

        $(document).on('click', '#apply_sale_discount', function() {
            applySaleDiscount(false);
        });

        $(document).on('click', '#remove_sale_discount', function() {
            applySaleDiscount(true);
        });

        $(document).on('click', '.cashier-delete-line', function(event) {
            event.preventDefault();

            const $button = $(this);
            const line = $button.data('line');

            $button.prop('disabled', true);

            $.ajax({
                url: "<?= site_url('sales/deleteItem/'); ?>" + line,
                type: 'post',
                dataType: 'json',
                success: function(response) {
                    if (!response || !response.success) {
                        showRegisterPrintError((response && response.message) || 'Không thể xóa sản phẩm khỏi đơn hàng.');
                        $button.prop('disabled', false);
                        focusItemSearch(false);
                        return;
                    }

                    const $row = $('.cashier-cart-row[data-line="' + response.line + '"]');
                    const cartForm = $row.data('cart-form');
                    if (cartForm) {
                        $('#' + cartForm).remove();
                    }
                    $row.remove();

                    if (response.cart_empty) {
                        $('#cart_contents').html(renderEmptyCartRow());
                        $('#payment_details').remove();
                    }

                    applyCartTotals(response.totals);
                    focusItemSearch(false);
                },
                error: function(xhr) {
                    const response = xhr.responseJSON || {};
                    showRegisterPrintError(response.message || 'Không thể xóa sản phẩm khỏi đơn hàng.');
                    $button.prop('disabled', false);
                    focusItemSearch(false);
                }
            });
        });

        $(".delete_payment_button").click(function() {
            const item_id = $(this).data('payment-id');
            $.post("<?= site_url('sales/deletePayment/'); ?>" + item_id, redirect);
        });

        $("input[name='item_number']").change(function() {
            const item_id = getCartItemId($(this));
            const item_number = $(this).val();
            $.ajax({
                url: "<?= site_url('sales/changeItemNumber') ?>",
                method: 'post',
                data: {
                    'item_id': item_id,
                    'item_number': item_number,
                },
                dataType: 'json'
            });
        });

        $("input[name='name']").change(function() {
            const item_id = getCartItemId($(this));
            const item_name = $(this).val();
            $.ajax({
                url: "<?= site_url('sales/changeItemName') ?>",
                method: 'post',
                data: {
                    'item_id': item_id,
                    'item_name': item_name,
                },
                dataType: 'json'
            });
        });

        $("input[name='item_description']").change(function() {
            const item_id = getCartItemId($(this));
            const item_description = $(this).val();
            $.ajax({
                url: "<?= site_url('sales/changeItemDescription') ?>",
                method: 'post',
                data: {
                    'item_id': item_id,
                    'item_description': item_description,
                },
                dataType: 'json'
            });
        });

        function focusItemSearch(selectText) {
            const $itemInput = $('#item');
            $itemInput.focus();
            if (selectText) {
                $itemInput.select();
            }
        }

        focusItemSearch(false);

        $('#item').blur(function() {
            if ($(this).val() == '') {
                $(this).attr('placeholder', 'Tìm kiếm mặt hàng - F1');
            }
        });

        const itemAutocomplete = $('#item').autocomplete({
            source: function(request, response) {
                $.getJSON("<?= site_url("$controller_name/itemSearch") ?>", {
                    term: $.trim(request.term)
                }, response);
            },
            minLength: 1,
            minChars: 1,
            autoFocus: false,
            delay: 0,
            appendTo: '#add_item_form',
            select: function(a, ui) {
                $(this).val(ui.item.value);
                $('#add_item_form').submit();
                return false;
            }
        }).data('ui-autocomplete');

        if (itemAutocomplete) {
            itemAutocomplete._renderItem = function(ul, item) {
                const $suggestion = $('<div>', {
                    class: 'cashier-search-suggestion'
                });

                $('<div>', {
                    class: 'cashier-search-suggestion__name',
                    text: item.name || item.label || ''
                }).appendTo($suggestion);

                const $meta = $('<div>', {
                    class: 'cashier-search-suggestion__meta'
                }).appendTo($suggestion);

                const barcode = item.barcode || '';
                const formattedPrice = item.formatted_price || '';

                $('<span>', {
                    class: 'cashier-search-suggestion__barcode',
                    text: barcode
                }).appendTo($meta);

                if (barcode !== '' && formattedPrice !== '') {
                    $('<span>', {
                        class: 'cashier-search-suggestion__separator',
                        text: '·'
                    }).appendTo($meta);
                }

                $('<span>', {
                    class: 'cashier-search-suggestion__price',
                    text: formattedPrice
                }).appendTo($meta);

                return $('<li>').append($suggestion).appendTo(ul);
            };
        }

        $('#item').on('input', function() {
            const searchValue = $.trim($(this).val());
            if (searchValue.length > 0) {
                $(this).autocomplete('search', searchValue);
            }
        });

        function submitScannedItem() {
            const $itemInput = $('#item');
            const itemValue = $.trim($itemInput.val());

            if (itemValue === '') {
                focusItemSearch(false);
                return;
            }

            $itemInput.val(itemValue);

            $.ajax({
                url: "<?= site_url("$controller_name/add") ?>",
                type: 'post',
                dataType: 'json',
                data: $('#add_item_form').serialize(),
                success: function(response) {
                    if (response && response.success) {
                        $itemInput.val('');
                        window.location.href = "<?= site_url($controller_name) ?>";
                        return;
                    }

                    showRegisterPrintError((response && response.message) || 'Mã hàng hóa này chưa tồn tại trên hệ thống.');
                    focusItemSearch(true);
                },
                error: function() {
                    showRegisterPrintError('Mã hàng hóa này chưa tồn tại trên hệ thống.');
                    focusItemSearch(true);
                }
            });
        }

        $('#add_item_form').submit(function(event) {
            event.preventDefault();
            submitScannedItem();
            return false;
        });

        $('#item').keypress(function(e) {
            if (e.which == 13) {
                submitScannedItem();
                return false;
            }
        });

        const clear_fields = function() {
            if ($(this).val().match("<?= lang(ucfirst($controller_name) . '.start_typing_item_name') . '|' . lang(ucfirst($controller_name) . '.start_typing_customer_name') ?>")) {
                $(this).val('');
            }
        };

        $('#item').click(clear_fields).dblclick(function(event) {
            $(this).autocomplete('search');
        });
        $(document).on('click', '#customer', clear_fields);
        $(document).on('dblclick', '#customer', function() {
            $(this).autocomplete('search');
        });

        bindCustomerSearch();

        $('.giftcard-input').autocomplete({
            source: "<?= site_url('giftcards/suggest') ?>",
            minChars: 0,
            delay: 10,
            select: function(a, ui) {
                $(this).val(ui.item.value);
                $('#add_payment_form').submit();
                return false;
            }
        });

        $('#comment').keyup(function() {
            $.post("<?= esc(site_url("$controller_name/setComment")) ?>", {
                comment: $('#comment').val()
            });
        });

        <?php if ($config['invoice_enable']) { ?>
            $('#sales_invoice_number').keyup(function() {
                $.post("<?= esc(site_url("$controller_name/setInvoiceNumber")) ?>", {
                    sales_invoice_number: $('#sales_invoice_number').val()
                });
            });

        <?php } ?>

        $('#sales_print_after_sale').change(function() {
            $.post("<?= esc(site_url("$controller_name/setPrintAfterSale")) ?>", {
                sales_print_after_sale: $(this).is(':checked')
            });
        });

        $('#price_work_orders').change(function() {
            $.post("<?= esc(site_url("$controller_name/setPriceWorkOrders")) ?>", {
                price_work_orders: $(this).is(':checked')
            });
        });

        $('#email_receipt').change(function() {
            $.post("<?= esc(site_url("$controller_name/setEmailReceipt")) ?>", {
                email_receipt: $(this).is(':checked')
            });
        });

        $(document)
            .off('click.cashierCompleteSale', '#cashier-complete-sale')
            .on('click.cashierCompleteSale', '#cashier-complete-sale', function(event) {
                event.preventDefault();

                if (!registerHasCartItems) {
                    showRegisterPrintError('Chưa có sản phẩm trong hóa đơn.');
                    return;
                }

                const $paymentForm = $('#add_payment_form');

                if ($paymentForm.length && !paymentsCoverTotal) {
                    if (!validateTenderedAmount()) {
                        return;
                    }

                    $paymentForm.find('input[name="complete_after_payment"]').val('1');
                    normalizeTenderedAmount();
                    completeSaleForCashier($paymentForm, "<?= site_url("$controller_name/addPayment") ?>");
                    return;
                }

                if (!paymentsCoverTotal) {
                    showRegisterPrintError('Tiền khách đưa nhỏ hơn tổng thanh toán.');
                    return;
                }

                completeSaleForCashier($('#buttons_form'), "<?= site_url("$controller_name/complete") ?>");
            });

        $('#finish_invoice_quote_button').click(function() {
            $('#buttons_form').attr('action', "<?= "$controller_name/complete" ?>");
            $('#buttons_form').submit();
        });

        $('#suspend_sale_button').click(function() {
            $('#buttons_form').attr('action', "<?= site_url("$controller_name/suspend") ?>");
            $('#buttons_form').submit();
        });

        $('#cancel_sale_button').click(function() {
            if (confirm("<?= lang(ucfirst($controller_name) . '.confirm_cancel_sale') ?>")) {
                $('#buttons_form').attr('action', "<?= site_url("$controller_name/cancel") ?>");
                $('#buttons_form').submit();
            }
        });

        $('#pos_add_order_button').click(function() {
            postCashierOrder("<?= site_url('sales/orders/new') ?>");
        });

        $('.pos-order-tab').click(function(event) {
            const $tab = $(this);
            const orderId = $tab.data('order-id');

            if ($(event.target).closest('.pos-order-close').length > 0) {
                closeCashierOrder($tab, orderId);
                return;
            }

            if (orderId && orderId !== cashierActiveOrderId) {
                postCashierOrder("<?= site_url('sales/orders/switch') ?>/" + encodeURIComponent(orderId));
            }
        });

        $('.pos-quick-item').click(function() {
            $('#item').val($(this).data('item-id'));
            $('#add_item_form').submit();
        });

        $(document)
            .off('click.cashierPreviewPrint', '#cashier-preview-print')
            .on('click.cashierPreviewPrint', '#cashier-preview-print', function(event) {
                event.preventDefault();

                if (!registerHasCartItems) {
                    showRegisterPrintError('Chưa có sản phẩm trong hóa đơn.');
                    return;
                }

                printReceiptInFrame("<?= site_url("$controller_name/previewReceipt") ?>")
                    .catch(function(error) {
                        showRegisterPrintError(error.message || 'Không thể in hóa đơn.');
                    });
            });

        $('.pos-qty-step').click(function() {
            const $quantityInput = $(this).siblings('input[name="quantity"]');
            const currentQuantity = parseLocaleMoney($quantityInput.val());
            const nextQuantity = Math.max(1, currentQuantity + Number($(this).data('step')));
            $quantityInput.val(formatRegisterQuantity(nextQuantity));
            submitCartInput($quantityInput);
        });

        $('.pos-cash-shortcut').click(function() {
            const amount = $(this).data('amount') == 'due' ? registerAmountDue : Number($(this).data('amount'));
            $('[name="amount_tendered"]:enabled').val(formatRegisterNumber(amount));
            updateRegisterChange();
        });

        $('[name="amount_tendered"]').on('input', function() {
            updateRegisterChange();
            queueSaveTenderedAmount();
        });
        updateRegisterChange();

        $('#add_payment_form').submit(function() {
            normalizeTenderedAmount();
        });

        $('#payment_types').change(check_payment_type).ready(check_payment_type);

        $('#cart_contents input').keypress(function(event) {
            if (event.which == 13) {
                submitCartInput($(this));
            }
        });

        $('[name="amount_tendered"]').keypress(function(event) {
            if (event.which == 13) {
                $('#add_payment_form').submit();
            }
        });

        $('#cashier-complete-sale').keypress(function(event) {
            if (event.which == 13) {
                $('#cashier-complete-sale').click();
            }
        });

        $(document)
            .off('click.cashierCustomerModal', '.cashier-customer-modal')
            .on('click.cashierCustomerModal', '.cashier-customer-modal', function(event) {
                event.preventDefault();
                openCashierCustomerModal($(this));
            });

        dialog_support.init('a.modal-dlg, button.modal-dlg');

        table_support.handle_submit = function(resource, response, stay_open) {
            $.notify({
                message: response.message
            }, {
                type: response.success ? 'success' : 'danger'
            })

            if (response.success) {
                if (resource.match(/customers$/)) {
                    const $customerInput = $('#customer');
                    if ($customerInput.length) {
                        $customerInput.val(response.id);
                    } else {
                        $('#select_customer_form input[name="customer"]').remove();
                        $('<input>', {
                            type: 'hidden',
                            name: 'customer',
                            value: response.id
                        }).appendTo('#select_customer_form');
                    }
                    $('#select_customer_form').submit();
                } else {
                    const $stock_location = $("select[name='stock_location']").val();
                    $('#item_location').val($stock_location);
                    $('#item').val(response.id);
                    if (stay_open) {
                        $('#add_item_form').ajaxSubmit();
                    } else {
                        $('#add_item_form').submit();
                    }
                }
            }
        }

        $('[name="price"],[name="quantity"],[name="description"],[name="serialnumber"],[name="discounted_total"]').change(function() {
            submitCartInput($(this));
        });

        $(document).on('click', '.cashier-unit-badge', function() {
            const $button = $(this);
            if ($button.attr('aria-pressed') === 'true') {
                return;
            }

            $.ajax({
                url: "<?= site_url('sales/switchItemUnit') ?>/" + encodeURIComponent($button.data('line')),
                type: 'post',
                dataType: 'json',
                data: {
                    item_unit_id: $button.data('unit-id')
                },
                success: function(response) {
                    if (response && response.success) {
                        window.location.href = "<?= site_url('sales') ?>";
                        return;
                    }

                    showRegisterPrintError(response && response.message ? response.message : "<?= lang('Sales.unable_to_add_item') ?>");
                },
                error: function() {
                    showRegisterPrintError("<?= lang('Sales.unable_to_add_item') ?>");
                }
            });
        });
    });

    function getActiveTenderedAmountValue() {
        return $('[name="amount_tendered"]:enabled').val() || '';
    }

    function postCashierOrder(url) {
        $.ajax({
            url: url,
            type: 'post',
            data: {
                amount_tendered: getActiveTenderedAmountValue()
            },
            dataType: 'json',
            success: function(response) {
                if (response && response.success) {
                    window.location.href = "<?= site_url('sales') ?>";
                    return;
                }

                showRegisterPrintError(response && response.message ? response.message : 'Không thể đổi đơn hàng.');
            },
            error: function() {
                showRegisterPrintError('Không thể đổi đơn hàng.');
            }
        });
    }

    function closeCashierOrder($tab, orderId) {
        const hasOpenData = $tab.data('has-open-data') == '1';

        if (hasOpenData && !confirm("<?= lang(ucfirst($controller_name) . '.confirm_cancel_sale') ?>")) {
            return;
        }

        postCashierOrder("<?= site_url('sales/orders/close') ?>/" + encodeURIComponent(orderId));
    }

    function queueSaveTenderedAmount() {
        if (saveTenderedTimer) {
            clearTimeout(saveTenderedTimer);
        }

        saveTenderedTimer = setTimeout(function() {
            $.ajax({
                url: "<?= site_url('sales/orders/amount-tendered') ?>",
                type: 'post',
                data: {
                    amount_tendered: getActiveTenderedAmountValue()
                }
            });
        }, 250);
    }

    function getCartItemId($input) {
        const formId = $input.attr('form') || $input.closest('.cashier-cart-row').data('cart-form');

        if (formId) {
            return $("input[name='item_id'][form='" + formId + "']").val() || $('#' + formId).find("input[name='item_id']").val();
        }

        return $input.closest('.cashier-cart-row').find("input[name='item_id']").val();
    }

    function openCashierCustomerModal($trigger) {
        const url = $trigger.data('href') || $trigger.attr('href');
        const customerId = $trigger.data('customer-id');
        const node = $('<div></div>');

        if (!url) {
            showRegisterPrintError('Không thể mở thông tin khách hàng.');
            return;
        }

        BootstrapDialog.show({
            title: $trigger.attr('title') || 'Khách hàng',
            cssClass: 'modal-dlg-cashier-customer',
            message: function() {
                $.get(url, function(data) {
                    node.html(data);
                }).fail(function(xhr) {
                    const message = xhr.responseText || 'Không thể tải thông tin khách hàng.';
                    node.html($('<div class="alert alert-danger"></div>').text(message.replace(/<[^>]*>/g, '')));
                });

                return node;
            },
            buttons: []
        });

        if (customerId) {
            node.attr('data-customer-id', customerId);
        }
    }

    function check_payment_type() {
        const cash_mode = <?= json_encode($cash_mode) ?>;
        const paymentType = $("#payment_types").val();
        const isGiftCard = paymentType == "<?= lang(ucfirst($controller_name) . '.giftcard') ?>";
        const isCash = paymentType == "<?= lang(ucfirst($controller_name) . '.cash') ?>";
        const needsReferenceCode = referenceCodePaymentTypes.indexOf(paymentType) !== -1;

        if (isGiftCard) {
            $("#sale_total").html("<?= to_currency($total) ?>");
            $("#sale_amount_due").html("<?= to_currency($amount_due) ?>");
            $("#amount_tendered_label").html("<?= lang(ucfirst($controller_name) . '.giftcard_number') ?>");
            $('[name="amount_tendered"]:enabled').val('').focus();
            $(".giftcard-input").attr('disabled', false);
            $(".non-giftcard-input").attr('disabled', true);
            $(".giftcard-input:enabled").val('').focus();
            $(".reference-code-input").hide();
        } else if (isCash && cash_mode == '1') {
            $("#sale_total").html("<?= to_currency($non_cash_total) ?>");
            $("#sale_amount_due").html("<?= to_currency($cash_amount_due) ?>");
            $("#amount_tendered_label").html("Tiền khách đưa");
            $('[name="amount_tendered"]:enabled').val(registerInitialTenderedAmount ?? "<?= to_currency_no_money($cash_amount_due) ?>");
            $(".giftcard-input").attr('disabled', true);
            $(".non-giftcard-input").attr('disabled', false);
            $(".reference-code-input").hide();
        } else {
            $("#sale_total").html("<?= to_currency($non_cash_total) ?>");
            $("#sale_amount_due").html("<?= to_currency($amount_due) ?>");
            $("#amount_tendered_label").html("Tiền khách đưa");
            $('[name="amount_tendered"]:enabled').val(registerInitialTenderedAmount ?? "<?= to_currency_no_money($amount_due) ?>");
            $(".giftcard-input").attr('disabled', true);
            $(".non-giftcard-input").attr('disabled', false);
            if (needsReferenceCode) {
                $("#reference_code_label").html("<?= lang(ucfirst($controller_name) . '.reference_code') ?>");
                $(".reference-code-input").show();
                $(".reference-code-input input").attr('disabled', false);
            } else {
                $(".reference-code-input").hide();
                $(".reference-code-input input").attr('disabled', true);
            }
        }
        updateRegisterChange();
    }

    function submitCartInput($input) {
        const $form = $input.closest('form');

        if ($form.length) {
            $form.submit();
            return;
        }

        const formId = $input.attr('form') || $input.closest('.cashier-cart-row').data('cart-form');

        if (formId && $('#' + formId).length) {
            $('#' + formId).submit();
        }
    }

    function showRegisterPrintError(message) {
        if ($.notify) {
            $.notify({
                message: message
            }, {
                type: 'danger'
            });
            return;
        }

        alert(message);
    }

    function addPrintFrameParams(receiptUrl) {
        const separator = receiptUrl.indexOf('?') === -1 ? '?' : '&';
        return receiptUrl + separator + 'embedded_print=1&_cashier_print=' + Date.now();
    }

    function printReceiptInFrame(receiptUrl) {
        const frame = document.getElementById('cashier-print-frame');

        if (!frame) {
            return Promise.reject(new Error('Không tìm thấy vùng in hóa đơn.'));
        }

        return new Promise(function(resolve, reject) {
            let printed = false;

            frame.onload = function() {
                if (printed) {
                    return;
                }

                printed = true;
                frame.onload = null;
                let cleanupPrint = function() {};

                try {
                    const frameWindow = frame.contentWindow;

                    if (!frameWindow) {
                        throw new Error('Không thể tải hóa đơn để in.');
                    }

                    let finished = false;
                    let fallbackTimer = null;
                    let printStartedAt = 0;
                    cleanupPrint = function() {
                        frameWindow.removeEventListener('afterprint', finish);
                        window.removeEventListener('focus', finishFromWindowFocus);

                        if (fallbackTimer) {
                            clearTimeout(fallbackTimer);
                            fallbackTimer = null;
                        }
                    };
                    const finish = function() {
                        if (finished) {
                            return;
                        }

                        finished = true;
                        cleanupPrint();
                        resolve();
                    };
                    const finishFromWindowFocus = function() {
                        if (printStartedAt === 0 || Date.now() - printStartedAt < 1000) {
                            return;
                        }

                        finish();
                    };

                    frameWindow.addEventListener('afterprint', finish, { once: true });
                    window.addEventListener('focus', finishFromWindowFocus);
                    fallbackTimer = setTimeout(finish, 60000);
                    printStartedAt = Date.now();
                    frameWindow.focus();
                    frameWindow.print();
                } catch (error) {
                    frame.onload = null;
                    cleanupPrint();
                    reject(error);
                }
            };

            frame.src = addPrintFrameParams(receiptUrl);
        });
    }

    function completeSaleForCashier($form, action) {
        if (registerCompletionInProgress) {
            return;
        }

        registerCompletionInProgress = true;
        $('#cashier-complete-sale').addClass('disabled').prop('disabled', true);

        $.ajax({
            url: action,
            type: 'post',
            data: $form.serialize(),
            dataType: 'json',
            success: function(response) {
                const receiptUrl = getValidReceiptUrl(response);

                if (receiptUrl) {
                    printReceiptInFrame(receiptUrl)
                        .then(function() {
                            window.location.href = "<?= site_url('sales') ?>";
                        })
                        .catch(function(error) {
                            registerCompletionInProgress = false;
                            $('#cashier-complete-sale').removeClass('disabled').prop('disabled', false);
                            showRegisterPrintError(error.message || 'Không thể in hóa đơn.');
                        });
                    return;
                }

                registerCompletionInProgress = false;
                $('#cashier-complete-sale').removeClass('disabled').prop('disabled', false);
                showRegisterPrintError(response && response.message ? response.message : 'Không thể hoàn tất thanh toán.');
            },
            error: function() {
                registerCompletionInProgress = false;
                $('#cashier-complete-sale').removeClass('disabled').prop('disabled', false);
                showRegisterPrintError('Không thể hoàn tất thanh toán.');
            }
        });
    }

    function getValidReceiptUrl(response) {
        if (!response || response.success !== true) {
            return null;
        }

        const saleId = Number(response.sale_id);
        const receiptUrl = typeof response.receipt_url === 'string' ? response.receipt_url.trim() : '';

        if (!Number.isInteger(saleId) || saleId <= 0 || receiptUrl === '' || receiptUrl.indexOf('/sales/receipt/') === -1) {
            return null;
        }

        try {
            const parsedUrl = new URL(receiptUrl, window.location.origin);
            const matches = parsedUrl.pathname.match(/\/sales\/receipt\/(\d+)$/);

            if (!matches || Number(matches[1]) !== saleId) {
                return null;
            }
        } catch (error) {
            return null;
        }

        return receiptUrl;
    }

    function parseLocaleMoney(value) {
        let normalized = String(value || '').trim();

        if (normalized == '') {
            return 0;
        }

        normalized = normalized.replace(/[^\d,.-]/g, '');
        const lastComma = normalized.lastIndexOf(',');
        const lastDot = normalized.lastIndexOf('.');

        if (lastComma > -1 && lastDot > -1) {
            if (lastComma > lastDot) {
                normalized = normalized.replace(/\./g, '').replace(',', '.');
            } else {
                normalized = normalized.replace(/,/g, '');
            }
        } else if (lastComma > -1) {
            normalized = normalized.replace(/\./g, '').replace(',', '.');
        } else if ((normalized.match(/\./g) || []).length > 1) {
            normalized = normalized.replace(/\./g, '');
        } else if (/^\d{1,3}\.\d{3}$/.test(normalized)) {
            normalized = normalized.replace(/\./g, '');
        }

        const amount = Number(normalized);
        return Number.isFinite(amount) ? amount : 0;
    }

    function formatRegisterNumber(value) {
        return new Intl.NumberFormat('vi-VN', {
            maximumFractionDigits: 0
        }).format(Number(value) || 0);
    }

    function updateRegisterChange() {
        const tenderedAmount = parseLocaleMoney($('[name="amount_tendered"]:enabled').val());
        const changeDue = Math.max(0, tenderedAmount - registerAmountDue);
        $('#pos_change_due').text(formatRegisterNumber(changeDue));
        $('#pos_payment_warning').toggle(tenderedAmount < registerAmountDue);
    }

    function validateTenderedAmount() {
        updateRegisterChange();
        return parseLocaleMoney($('[name="amount_tendered"]:enabled').val()) >= registerAmountDue;
    }

    function normalizeTenderedAmount() {
        const $amountInput = $('[name="amount_tendered"]:enabled');
        $amountInput.val(String(parseLocaleMoney($amountInput.val())));
    }

    function formatRegisterQuantity(value) {
        return new Intl.NumberFormat('vi-VN', {
            maximumFractionDigits: 3
        }).format(Number(value) || 0);
    }

    // Add Keyboard Shortcuts/Hotkeys to Sale Register
    document.body.onkeyup = function(event) {
        if ($(event.target).closest('.modal').length || $('.modal.in').length) {
            return;
        }
        if (event.altKey) {
            switch (event.keyCode) {
                case shortcutCodes.items:
                    $("#item").focus();
                    $("#item").select();
                    break;
                case shortcutCodes.customers:
                    $("#customer").focus();
                    $("#customer").select();
                    break;
                case shortcutCodes.suspend:
                    $("#suspend_sale_button").click();
                    break;
                case shortcutCodes.suspended:
                    $("#show_suspended_sales_button").click();
                    break;
                case shortcutCodes.amount:
                    $("#amount_tendered").focus();
                    $("#amount_tendered").select();
                    break;
                case shortcutCodes.payment:
                    $("#cashier-complete-sale").click();
                    break;
                case shortcutCodes.complete:
                    $("#cashier-complete-sale").click();
                    break;
                case shortcutCodes.finish:
                    $("#finish_invoice_quote_button").click();
                    break;
                case shortcutCodes.help:
                    $("#show_keyboard_help").click();
                    break;
            }
        }

        switch (event.keyCode) {
            case shortcutCodes.cancel:
                $("#cancel_sale_button").click();
                break;
        }
    }
</script>

<?= view('partial/footer') ?>
