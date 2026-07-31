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
 */

use App\Models\Employee;

?>

<?= view('partial/header') ?>
<link rel="stylesheet" href="<?= base_url('css/register.css') ?>">
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
                    <button type="button" class="pos-order-tab active" id="pos_close_order_button">
                        <strong><?= isset($customer) ? esc($customer) : 'Khách lẻ' ?></strong>
                        <small>Đơn hàng 1</small>
                        <span aria-hidden="true">x</span>
                    </button>
                </div>
                <button type="button" class="btn pos-add-tab" disabled title="Multi-order cần task riêng"><span class="glyphicon glyphicon-plus-sign"></span></button>
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
            <div class="cashier-cart-cell discount pos-cart-discount">Giảm giá</div>
            <div class="cashier-cart-cell total pos-cart-total">Thành tiền</div>
            <div class="cashier-cart-cell delete pos-cart-action"></div>
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
                    <?= form_open("$controller_name/editItem/$line", ['class' => 'cashier-cart-row-form', 'id' => $cart_form_id]) ?>
                    <?= form_close() ?>
                    <div class="cashier-cart-row pos-cart-row" data-cart-form="<?= esc($cart_form_id) ?>">
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
                                <div class="cashier-cart-cell code pos-cart-code"><?= esc($item['item_number']) ?></div>
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

                            <div class="cashier-cart-cell discount pos-cart-discount">
                                <?= form_input(['type' => 'hidden', 'name' => 'discount_type', 'class' => 'pos-discount-type-value', 'value' => $item['discount_type'] ? 1 : 0, 'form' => $cart_form_id]) ?>
                                <div class="input-group">
                                    <?= form_input(['name' => 'discount', 'class' => 'form-control input-sm', 'value' => $item['discount_type'] ? to_currency_no_money($item['discount']) : to_decimals($item['discount']), 'tabindex' => ++$tabindex, 'onClick' => 'this.select();', 'form' => $cart_form_id]) ?>
                                    <span class="input-group-btn">
                                        <?= form_checkbox(['id' => "discount_toggle_$line", 'name' => 'discount_toggle', 'value' => 1, 'data-toggle' => "toggle", 'data-size' => 'small', 'data-onstyle' => 'success', 'data-on' => '<b>' . currency_symbol() . '</b>', 'data-off' => '<b>%</b>', 'data-line' => $line, 'checked' => $item['discount_type'] == 1]) ?>
                                    </span>
                                </div>
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

                            <div class="cashier-cart-cell delete pos-cart-action">
                                <?= anchor("$controller_name/deleteItem/$line", '<span class="glyphicon glyphicon-trash"></span>', ['class' => 'pos-delete-line', 'title' => lang('Common.delete')]) ?>
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
                        <?= anchor(
                            "$controller_name/removeCustomer",
                            '<span class="glyphicon glyphicon-remove"></span>',
                            ['class' => 'btn btn-danger btn-sm pos-remove-customer-button', 'id' => 'remove_customer_button', 'title' => lang('Common.remove') . ' ' . lang('Customers.customer')]
                        )
                        ?>
                    </div>
                </div>
            <?php } else { ?>
                <div class="pos-side-section form-group" id="select_customer">
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
                <th><?= lang(ucfirst($controller_name) . '.quantity_of_items', [$item_count]) ?></th>
                <th><?= $total_units ?></th>
            </tr>
            <tr>
                <th>Tổng thành tiền</th>
                <th><?= to_currency($subtotal) ?></th>
            </tr>
            <tr>
                <th><?= isset($customer) ? 'Điểm hiện có' : 'Điểm thưởng' ?></th>
                <th><?= esc($customer_points ?? 0) ?></th>
            </tr>
            <tr>
                <th>Giảm giá</th>
                <th><?= to_currency($discount ?? 0) ?></th>
            </tr>
            <?php foreach ($taxes as $tax_group_index => $tax) { ?>
                <tr class="pos-muted-total">
                    <th><?= (float)$tax['tax_rate'] . '% ' . $tax['tax_group'] ?></th>
                    <th><?= to_currency_tax($tax['sale_tax_amount']) ?></th>
                </tr>
            <?php } ?>
            <tr class="pos-grand-total">
                <th>Tổng cộng</th>
                <th><span id="sale_total"><?= to_currency($total) ?></span></th>
            </tr>
        </table>

        <?php if (count($cart) > 0) { // Only show this part if there are Items already in the register ?>
            <table class="sales_table_100 pos-side-section" id="payment_totals">
                <tr>
                    <th>Đã thanh toán</th>
                    <th><?= to_currency($payments_total) ?></th>
                </tr>
                <tr>
                    <th>Số còn lại phải thanh toán</th>
                    <th><span id="sale_amount_due"><?= to_currency($amount_due) ?></span></th>
                </tr>
            </table>

            <div id="payment_details">
                <div class="pos-payment-title">THANH TOÁN</div>
                <?php if ($payments_cover_total) { // Show Complete sale button instead of Add Payment if there is no amount due left ?>
                    <?= form_open("$controller_name/addPayment", ['id' => 'add_payment_form', 'class' => 'form-horizontal']) ?>
                        <input type="hidden" name="complete_after_payment" value="0">
                        <table class="sales_table_100 pos-payment-entry">
                            <tr>
                                <td>Hình thức</td>
                                <td>
                                    <?= form_dropdown('payment_type', $register_payment_options, $register_selected_payment_type, ['id' => 'payment_types', 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => '100%', 'disabled' => 'disabled']) ?>
                                </td>
                            </tr>
                            <tr>
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
                            <tr>
                                <td>Hình thức</td>
                                <td>
                                    <?= form_dropdown('payment_type', $register_payment_options, $register_selected_payment_type, ['id' => 'payment_types', 'class' => 'selectpicker show-menu-arrow', 'data-style' => 'btn-default btn-sm', 'data-width' => '100%']) ?>
                                </td>
                            </tr>
                            <tr>
                                <td><span id="amount_tendered_label">Tiền khách đưa</span></td>
                                <td>
                                    <?= form_input(['name' => 'amount_tendered', 'id' => 'amount_tendered', 'class' => 'form-control input-sm non-giftcard-input', 'value' => to_currency_no_money($amount_due), 'size' => '5', 'tabindex' => ++$tabindex, 'onClick' => 'this.select();']) ?>
                                    <?= form_input(['name' => 'amount_tendered', 'id' => 'amount_tendered', 'class' => 'form-control input-sm giftcard-input', 'disabled' => true, 'value' => to_currency_no_money($amount_due), 'size' => '5', 'tabindex' => ++$tabindex]) ?>
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
    const registerAmountDue = <?= json_encode((float) $amount_due) ?>;
    const registerHasCartItems = <?= json_encode(count($cart) > 0) ?>;
    const registerHasOpenData = <?= json_encode(count($cart) > 0 || count($payments) > 0) ?>;
    let registerCompletionInProgress = false;
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

        $("#remove_customer_button").click(function() {
            $.post("<?= site_url('sales/removeCustomer'); ?>", redirect);
        });

        $("#change_customer_button").click(function() {
            $.post("<?= site_url('sales/removeCustomer'); ?>", redirect);
        });

        $(".delete_item_button").click(function() {
            const line = $(this).data('line');
            $.post("<?= site_url('sales/deleteItem/'); ?>" + line, redirect);
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

        $('#item').focus();

        $('#item').blur(function() {
            if ($(this).val() == '') {
                $(this).attr('placeholder', 'Tìm kiếm mặt hàng - F1');
            }
        });

        $('#item').autocomplete({
            source: "<?= esc("$controller_name/itemSearch") ?>",
            minChars: 0,
            autoFocus: false,
            delay: 500,
            select: function(a, ui) {
                $(this).val(ui.item.value);
                $('#add_item_form').submit();
                return false;
            }
        });

        $('#item').keypress(function(e) {
            if (e.which == 13) {
                $('#add_item_form').submit();
                return false;
            }
        });

        const clear_fields = function() {
            if ($(this).val().match("<?= lang(ucfirst($controller_name) . '.start_typing_item_name') . '|' . lang(ucfirst($controller_name) . '.start_typing_customer_name') ?>")) {
                $(this).val('');
            }
        };

        $('#item, #customer').click(clear_fields).dblclick(function(event) {
            $(this).autocomplete('search');
        });

        $('#customer').blur(function() {
            if ($(this).val() == '') {
                $(this).attr('placeholder', 'Tên hoặc số điện thoại');
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

        $('#customer').keypress(function(e) {
            if (e.which == 13) {
                $('#select_customer_form').submit();
                return false;
            }
        });

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

        $('#pos_close_order_button').click(function(event) {
            if ($(event.target).closest('span').length === 0) {
                return;
            }

            if (!registerHasOpenData || confirm("<?= lang(ucfirst($controller_name) . '.confirm_cancel_sale') ?>")) {
                $('#buttons_form').attr('action', "<?= site_url("$controller_name/cancel") ?>");
                $('#buttons_form').submit();
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

        $('[name="amount_tendered"]').on('input', updateRegisterChange);
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

        $('[name="price"],[name="quantity"],[name="discount"],[name="description"],[name="serialnumber"],[name="discounted_total"]').change(function() {
            submitCartInput($(this));
        });

        $('[name="discount_toggle"]').change(function() {
            const formId = 'cart_' + $(this).attr('data-line');
            $("input.pos-discount-type-value[form='" + formId + "']").val(($(this).prop('checked')) ? 1 : 0);
            $('#' + formId).submit();
        });
    });

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
            $('[name="amount_tendered"]:enabled').val("<?= to_currency_no_money($cash_amount_due) ?>");
            $(".giftcard-input").attr('disabled', true);
            $(".non-giftcard-input").attr('disabled', false);
            $(".reference-code-input").hide();
        } else {
            $("#sale_total").html("<?= to_currency($non_cash_total) ?>");
            $("#sale_amount_due").html("<?= to_currency($amount_due) ?>");
            $("#amount_tendered_label").html("Tiền khách đưa");
            $('[name="amount_tendered"]:enabled').val("<?= to_currency_no_money($amount_due) ?>");
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
            method: 'post',
            data: $form.serialize(),
            dataType: 'json',
            success: function(response) {
                if (response && response.success && response.receipt_url) {
                    printReceiptInFrame(response.receipt_url)
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
