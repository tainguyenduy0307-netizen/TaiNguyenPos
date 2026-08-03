<?php
/**
 * @var string $transaction_time
 * @var int|string $sale_id
 * @var string $invoice_number
 * @var array $cart
 * @var float $discount
 * @var int|null $order_discount_type
 * @var float $order_discount_value
 * @var float $order_discount_amount
 * @var string $order_discount_code
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

$configValue = static function (array $config, string $key, string $fallback = ''): string {
    return array_key_exists($key, $config) ? trim((string) $config[$key]) : $fallback;
};

$legacyFooterValue = static function (string $value, string $fallback): string {
    $value = trim($value);
    if ($value === '' || mb_strlen($value) < 12) {
        return $fallback;
    }

    return $value;
};

$footerConfigValue = static function (array $config, string $key, string $fallback) use ($configValue): string {
    if (!array_key_exists($key, $config)) {
        return $fallback;
    }

    $value = $configValue($config, $key);
    if ($value !== '' && mb_strlen($value) < 8) {
        return $fallback;
    }

    return $value;
};

$configBool = static function (array $config, string $key, bool $fallback = true): bool {
    if (!array_key_exists($key, $config)) {
        return $fallback;
    }

    if (is_bool($config[$key])) {
        return $config[$key];
    }

    return !in_array(strtolower(trim((string) $config[$key])), ['', '0', 'false', 'no', 'off'], true);
};

$receiptFontSize = max(11.5, (float) ($config['receipt_font_size'] ?? 11.5));
$defaultExchangePolicy = 'Thời gian đổi hàng trong vòng 5 ngày, sản phẩm đổi có giá trị lớn hơn hoặc bằng sản phẩm được đổi!';
$defaultThankYou = 'Cảm ơn quý khách và hẹn gặp lại!';

$k58Config = [
    'company'                => $configValue($config, 'receipt_company', 'Siêu Thị Sữa Gia Phú 93'),
    'address'                => $configValue($config, 'receipt_address', '449 đường Bình Mỹ, Bình Mỹ, Củ Chi, Thành Phố Hồ Chí Minh'),
    'phone'                  => $configValue($config, 'receipt_phone', '0867.807.957'),
    'phone_label'            => $configValue($config, 'receipt_phone_label', 'ĐT / Zalo:'),
    'facebook'               => $configValue($config, 'receipt_facebook', 'Siêu Thị Sữa Gia Phú 93'),
    'website'                => $configValue($config, 'receipt_website', 'sieuthiasuagiaphu93.vn'),
    'email'                  => $configValue($config, 'receipt_email', $configValue($config, 'email', '')),
    'title'                  => $configValue($config, 'receipt_title', 'HÓA ĐƠN BÁN HÀNG'),
    'opening_hours'          => $footerConfigValue($config, 'receipt_opening_hours', 'Thời gian mở cửa từ 7h - 22h30!'),
    'exchange_policy'        => $footerConfigValue($config, 'receipt_exchange_policy', $legacyFooterValue($configValue($config, 'return_policy'), $defaultExchangePolicy)),
    'thank_you'              => $footerConfigValue($config, 'receipt_thank_you', $legacyFooterValue($configValue($config, 'receipt_footer'), $defaultThankYou)),
    'show_company'           => $configBool($config, 'receipt_show_company_name', true),
    'show_address'           => $configBool($config, 'receipt_show_address', true),
    'show_phone'             => $configBool($config, 'receipt_show_phone', true),
    'show_facebook'          => $configBool($config, 'receipt_show_facebook', true),
    'show_website'           => $configBool($config, 'receipt_show_website', true),
    'show_customer_phone'    => $configBool($config, 'receipt_show_customer_phone', true),
    'show_service_fee'       => $configBool($config, 'receipt_show_service_fee', true),
    'show_discount'          => $configBool($config, 'receipt_show_discount', true),
    'show_tax'               => $configBool($config, 'receipt_show_tax', (bool) ($config['receipt_show_taxes'] ?? false)),
    'show_amount_tendered'   => $configBool($config, 'receipt_show_amount_tendered', true),
    'show_change'            => $configBool($config, 'receipt_show_change', true),
    'show_opening_hours'     => $configBool($config, 'receipt_show_opening_hours', true),
    'show_exchange_policy'   => $configBool($config, 'receipt_show_exchange_policy', true),
    'show_thank_you'         => $configBool($config, 'receipt_show_thank_you', true),
];

$transactionTime = trim((string) $transaction_time);
if (($transactionTimestamp = strtotime($transactionTime)) !== false) {
    $transactionTime = date('d/m/Y - H:i', $transactionTimestamp);
}

$receiptNumber = !empty($invoice_number) ? $invoice_number : '';
$serviceCharge = (float) ($service_charge ?? 0);
$amountTendered = 0.0;
$phoneLabel = rtrim($k58Config['phone_label'], ':') . ':';

$footerBlocks = array_values(array_filter([
    [
        'text'  => $k58Config['show_opening_hours'] ? $k58Config['opening_hours'] : '',
        'class' => '',
    ],
    [
        'text'  => $k58Config['show_exchange_policy'] ? $k58Config['exchange_policy'] : '',
        'class' => '',
    ],
    [
        'text'  => $k58Config['show_thank_you'] ? $k58Config['thank_you'] : '',
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

<div id="receipt_k58_wrapper" style="font-size: <?= esc((string) $receiptFontSize) ?>px;">
    <div class="k58-header">
        <?php if ($k58Config['show_company'] && $k58Config['company'] !== '') { ?>
            <div class="k58-company"><?= nl2br(esc($k58Config['company'])) ?></div>
        <?php } ?>

        <?php if ($k58Config['show_address'] && $k58Config['address'] !== '') { ?>
            <div class="k58-store-line"><?= nl2br(esc($k58Config['address'])) ?></div>
        <?php } ?>

        <?php if ($k58Config['show_phone'] && $k58Config['phone'] !== '') { ?>
            <div class="k58-store-line"><?= esc($phoneLabel) ?> <?= esc($k58Config['phone']) ?></div>
        <?php } ?>

        <?php if ($k58Config['show_facebook'] && $k58Config['facebook'] !== '') { ?>
            <div class="k58-store-line">Facebook: <?= esc($k58Config['facebook']) ?></div>
        <?php } ?>

        <?php if ($k58Config['show_website'] && $k58Config['website'] !== '') { ?>
            <div class="k58-store-line"><?= esc($k58Config['website']) ?></div>
        <?php } elseif ($k58Config['show_website'] && $k58Config['email'] !== '') { ?>
            <div class="k58-store-line"><?= esc($k58Config['email']) ?></div>
        <?php } ?>
    </div>

    <div class="k58-separator"></div>

    <div class="k58-title"><?= esc($k58Config['title']) ?></div>

    <div class="k58-lines">
        <div class="k58-datetime"><?= esc($transactionTime) ?></div>

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

            <?php if ($k58Config['show_customer_phone']) { ?>
                <div class="k58-line">
                    <span>SĐT:</span>
                    <span><?= esc($customer_phone_number ?? '') ?></span>
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
            if (!empty($item['unit_name'])) {
                $itemName = trim($itemName . ' - ' . $item['unit_name']);
            }
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
        <?php if ($k58Config['show_service_fee'] && $serviceCharge > 0) { ?>
            <div class="k58-line">
                <span>Phí dịch vụ</span>
                <span><?= to_currency((string) $serviceCharge) ?></span>
            </div>
        <?php } ?>

        <?php if ($k58Config['show_discount'] && ($order_discount_amount ?? 0) > 0) { ?>
            <div class="k58-line">
                <span>
                    Giảm giá<?php if (($order_discount_type ?? null) === PERCENT) { ?> (<?= to_decimals((string) ($order_discount_value ?? 0)) ?>%)<?php } ?>
                </span>
                <span>-<?= to_currency((string) ($order_discount_amount ?? 0)) ?></span>
            </div>
            <?php if (trim((string) ($order_discount_code ?? '')) !== '') { ?>
                <div class="k58-line k58-discount-code">
                    <span>Mã giảm giá</span>
                    <span><?= esc($order_discount_code) ?></span>
                </div>
            <?php } ?>
        <?php } ?>

        <?php if ($k58Config['show_tax']) { ?>
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
        <?php if ($k58Config['show_amount_tendered'] && $amountTendered > 0) { ?>
            <div class="k58-line">
                <span>Tiền khách đưa</span>
                <span><?= $formatTendered($amountTendered) ?></span>
            </div>
        <?php } ?>

        <?php if ($k58Config['show_change'] && $amount_change >= 0) { ?>
            <div class="k58-line">
                <span>Tiền thừa</span>
                <span><?= $formatTendered($amount_change) ?></span>
            </div>
        <?php } ?>
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
