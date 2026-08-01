<?php
/**
 * @var string $transaction_time
 * @var int|string $sale_id
 * @var string $invoice_number
 * @var array $cart
 * @var float $discount
 * @var float $prediscount_subtotal
 * @var float $subtotal
 * @var float $service_charge
 * @var array $taxes
 * @var float $total
 * @var array $payments
 * @var float $amount_change
 * @var array $config
 */

$formatQuantity = static function ($quantity): string {
    $quantityValue = (float) $quantity;

    if (floor($quantityValue) === $quantityValue) {
        return (string) (int) $quantityValue;
    }

    return rtrim(rtrim(to_quantity_decimals((string) $quantity), '0'), ',.');
};

$formatTendered = static function ($amount): string {
    return to_currency((string) abs((float) $amount));
};

$receiptNumber = !empty($invoice_number) ? $invoice_number : '';
$serviceCharge = (float) ($service_charge ?? 0);
$amountTendered = 0.0;

$footerBlocks = array_values(array_filter([
    [
        'text'  => trim((string) ($config['receipt_opening_hours'] ?? '')),
        'class' => '',
    ],
    [
        'text'  => trim((string) ($config['receipt_exchange_policy'] ?? ($config['return_policy'] ?? ''))),
        'class' => '',
    ],
    [
        'text'  => trim((string) ($config['receipt_thank_you'] ?? ($config['receipt_footer'] ?? ($config['payment_message'] ?? '')))),
        'class' => 'k58-footer-thanks',
    ],
], static fn (array $footerBlock): bool => $footerBlock['text'] !== ''));

foreach ($payments as $payment) {
    $paymentAmount = (float) ($payment['payment_amount'] ?? 0);
    if ($paymentAmount > 0 && empty($payment['cash_adjustment'])) {
        $amountTendered += $paymentAmount;
    }
}
?>

<div id="receipt_k58_wrapper" style="font-size: <?= esc($config['receipt_font_size']) ?>px;">
    <div class="k58-header">
        <?php if ($config['receipt_show_company_name']) { ?>
            <div class="k58-company"><?= nl2br(esc($config['company'])) ?></div>
        <?php } ?>

        <?php if (!empty($config['address'])) { ?>
            <div><?= nl2br(esc($config['address'])) ?></div>
        <?php } ?>

        <?php if (!empty($config['phone'])) { ?>
            <div><?= esc($config['phone']) ?></div>
        <?php } ?>

        <?php if (!empty($config['website'])) { ?>
            <div><?= esc($config['website']) ?></div>
        <?php } elseif (!empty($config['email'])) { ?>
            <div><?= esc($config['email']) ?></div>
        <?php } ?>
    </div>

    <div class="k58-separator"></div>

    <div class="k58-title">HÓA ĐƠN BÁN HÀNG</div>

    <div class="k58-lines">
        <div class="k58-line">
            <span>Ngày giờ</span>
            <span><?= esc($transaction_time) ?></span>
        </div>

        <?php if ($receiptNumber !== '') { ?>
            <div class="k58-line">
                <span>Mã hóa đơn</span>
                <span><?= esc($receiptNumber) ?></span>
            </div>
        <?php } ?>

        <?php if (!empty($customer)) { ?>
            <div class="k58-line">
                <span>Khách hàng:</span>
                <span><?= esc($customer) ?></span>
            </div>

            <?php if (!empty($customer_phone_number)) { ?>
                <div class="k58-line">
                    <span>SĐT:</span>
                    <span><?= esc($customer_phone_number) ?></span>
                </div>
            <?php } ?>
        <?php } ?>
    </div>

    <div class="k58-separator"></div>

    <div class="k58-item-header">
        <span>Đơn giá</span>
        <span>SL</span>
        <span>Thành tiền</span>
    </div>

    <div class="k58-items">
        <?php foreach ($cart as $item) {
            if (($item['print_option'] ?? PRINT_YES) != PRINT_YES) {
                continue;
            }

            $itemName = trim(($item['name'] ?? '') . ' ' . ($item['attribute_values'] ?? ''));
            $lineTotalKey = $config['receipt_show_total_discount'] ? 'total' : 'discounted_total';
            $lineTotal = $item[$lineTotalKey] ?? 0;
        ?>
            <div class="k58-item">
                <div class="k58-item-name"><?= esc(ucfirst($itemName)) ?></div>
                <div class="k58-item-calc">
                    <span><?= to_currency((string) ($item['price'] ?? 0)) ?></span>
                    <span><?= esc($formatQuantity($item['quantity'] ?? 0)) ?></span>
                    <span><?= to_currency((string) $lineTotal) ?></span>
                </div>

                <?php if (!empty($config['receipt_show_description']) && !empty($item['description'])) { ?>
                    <div class="k58-item-note"><?= esc($item['description']) ?></div>
                <?php } ?>

                <?php if (!empty($config['receipt_show_serialnumber']) && !empty($item['serialnumber'])) { ?>
                    <div class="k58-item-note"><?= esc($item['serialnumber']) ?></div>
                <?php } ?>

                <?php if (($item['discount'] ?? 0) > 0) { ?>
                    <div class="k58-line k58-discount">
                        <?php if (($item['discount_type'] ?? null) == FIXED) { ?>
                            <span><?= lang('Sales.discount') ?></span>
                            <span><?= to_currency((string) ($item['discount'] ?? 0)) ?></span>
                        <?php } else { ?>
                            <span><?= lang('Sales.discount') ?></span>
                            <span><?= to_decimals((string) ($item['discount'] ?? 0)) ?>%</span>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </div>

    <div class="k58-separator"></div>

    <div class="k58-totals">
        <?php if ($discount > 0) { ?>
            <div class="k58-line">
                <span>Cộng tiền hàng</span>
                <span><?= to_currency((string) $prediscount_subtotal) ?></span>
            </div>
            <?php if ($serviceCharge > 0) { ?>
                <div class="k58-line">
                    <span>Phí dịch vụ</span>
                    <span><?= to_currency((string) $serviceCharge) ?></span>
                </div>
            <?php } ?>
            <div class="k58-line">
                <span>Chiết khấu</span>
                <span><?= to_currency((string) $discount) ?></span>
            </div>
        <?php } else { ?>
            <div class="k58-line">
                <span>Cộng tiền hàng</span>
                <span><?= to_currency((string) $subtotal) ?></span>
            </div>
            <?php if ($serviceCharge > 0) { ?>
                <div class="k58-line">
                    <span>Phí dịch vụ</span>
                    <span><?= to_currency((string) $serviceCharge) ?></span>
                </div>
            <?php } ?>
        <?php } ?>

        <?php if ($config['receipt_show_taxes']) { ?>
            <?php foreach ($taxes as $tax) { ?>
                <?php if ((float) $tax['sale_tax_amount'] <= 0) {
                    continue;
                } ?>
                <div class="k58-line">
                    <span>Thuế/VAT <?= (float) $tax['tax_rate'] ?>% <?= esc($tax['tax_group']) ?></span>
                    <span><?= to_currency_tax((string) $tax['sale_tax_amount']) ?></span>
                </div>
            <?php } ?>
        <?php } ?>

        <div class="k58-line k58-total">
            <span>Tổng cộng</span>
            <span><?= to_currency((string) $total) ?></span>
        </div>
    </div>

    <div class="k58-separator"></div>

    <div class="k58-payments">
        <?php if ($amountTendered > 0) { ?>
            <div class="k58-line">
                <span>Tiền khách đưa</span>
                <span><?= $formatTendered($amountTendered) ?></span>
            </div>
        <?php } ?>

        <div class="k58-line">
            <span><?= $amount_change >= 0 ? 'Tiền thừa' : 'Còn thiếu' ?></span>
            <span><?= $formatTendered($amount_change) ?></span>
        </div>
    </div>

    <?php if ($footerBlocks !== []) { ?>
        <div class="k58-separator"></div>

        <div class="k58-footer">
            <?php foreach ($footerBlocks as $footerBlock) { ?>
                <div class="<?= esc($footerBlock['class'], 'attr') ?>"><?= nl2br(esc($footerBlock['text'])) ?></div>
            <?php } ?>
        </div>
    <?php } ?>
</div>
