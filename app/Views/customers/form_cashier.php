<?php
/**
 * @var string $controller_name
 * @var object $person_info
 * @var int $customer_id
 * @var string $cashier_mode
 * @var bool $can_edit_points
 * @var array $loyalty_totals
 * @var float $due_balance
 * @var object|null $stats
 */

$cashierCustomerId = (int) ($customer_id ?? $person_info->person_id ?? NEW_ENTRY);
$cashierMode = $cashier_mode ?? ($cashierCustomerId === NEW_ENTRY ? 'create' : 'update');
$customerName = trim((string) $person_info->first_name . ' ' . (string) $person_info->last_name);
$customerDebt = $due_balance ?? 0.0;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open("$controller_name/save/$cashierCustomerId", ['id' => 'customer_form', 'class' => 'form-horizontal cashier-customer-form', 'data-mode' => $cashierMode]) ?>
    <?= form_hidden('cashier_form', '1') ?>
    <?= form_hidden('customer_id', (string) $cashierCustomerId) ?>
    <?= form_hidden('person_id', (string) $cashierCustomerId) ?>

    <div class="cashier-customer-grid">
        <div class="cashier-field">
            <?= form_label('Mã khách hàng', 'account_number') ?>
            <?= form_input(['name' => 'account_number', 'id' => 'account_number', 'class' => 'form-control input-sm', 'value' => $person_info->account_number]) ?>
        </div>
        <div class="cashier-field">
            <?= form_label('Dư nợ', 'customer_debt') ?>
            <?= form_input(['id' => 'customer_debt', 'class' => 'form-control input-sm', 'value' => to_currency_no_money($customerDebt), 'disabled' => 'disabled']) ?>
        </div>
        <div class="cashier-field">
            <?= form_label('Điểm thưởng', 'requested_points') ?>
            <?php
            $pointsInput = [
                'name'    => 'requested_points',
                'id'      => 'requested_points',
                'class'   => 'form-control input-sm',
                'value'   => (string) $loyalty_totals['points'],
                'min'     => '0',
                'pattern' => '[0-9]*',
            ];

            if (!$can_edit_points) {
                $pointsInput['disabled'] = '';
            }
            ?>
            <?= form_input($pointsInput) ?>
        </div>
        <div class="cashier-field cashier-span-2">
            <?= form_label('Tên', 'first_name', ['class' => 'required']) ?>
            <?= form_input(['name' => 'first_name', 'id' => 'first_name', 'class' => 'form-control input-sm', 'value' => $customerName]) ?>
        </div>
        <div class="cashier-field">
            <?= form_label('Điện thoại 1', 'phone_number') ?>
            <?= form_input(['name' => 'phone_number', 'id' => 'phone_number', 'class' => 'form-control input-sm', 'value' => $person_info->phone_number]) ?>
        </div>
        <div class="cashier-field cashier-span-2">
            <?= form_label('Địa chỉ 1', 'address_1') ?>
            <?= form_input(['name' => 'address_1', 'id' => 'address_1', 'class' => 'form-control input-sm', 'value' => $person_info->address_1]) ?>
        </div>
        <div class="cashier-field">
            <?= form_label('Công ty', 'customer_company_name') ?>
            <?= form_input(['name' => 'company_name', 'id' => 'customer_company_name', 'class' => 'form-control input-sm', 'value' => $person_info->company_name]) ?>
        </div>
        <div class="cashier-field">
            <?= form_label('Bộ phận', 'department') ?>
            <?= form_input(['id' => 'department', 'class' => 'form-control input-sm', 'value' => '', 'disabled' => 'disabled']) ?>
        </div>
        <div class="cashier-field cashier-span-2">
            <?= form_label('Ghi chú', 'comments') ?>
            <?= form_textarea(['name' => 'comments', 'id' => 'comments', 'class' => 'form-control input-sm', 'value' => $person_info->comments, 'rows' => '2']) ?>
        </div>
        <div class="cashier-field cashier-image-field">
            <?= form_label('Hình ảnh', 'customer_image') ?>
            <div id="customer_image" class="cashier-image-placeholder"><span class="glyphicon glyphicon-user"></span></div>
        </div>
    </div>

    <div class="cashier-customer-footer">
        <button type="button" class="btn btn-warning btn-sm" id="cashier_customer_close"><span class="glyphicon glyphicon-triangle-left"></span> Thoát</button>
        <button type="submit" class="btn btn-primary btn-sm"><span class="glyphicon glyphicon-floppy-disk"></span> Lưu</button>
    </div>
<?= form_close() ?>

<style>
    .cashier-customer-dialog .alert,
    .cashier-customer-dialog .error_message_box {
        width: auto;
        max-width: 520px;
        min-height: 0;
        height: auto;
        margin: 8px 0;
        padding: 8px 12px;
        flex: 0 0 auto;
    }

    .cashier-customer-dialog .error_message_box {
        list-style-position: inside;
    }

    .cashier-customer-dialog {
        width: 560px;
        max-width: 620px;
    }

    .cashier-customer-form {
        max-height: 640px;
    }

    .cashier-customer-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px 14px;
    }

    .cashier-field label {
        display: block;
        margin-bottom: 4px;
        font-size: 12px;
        font-weight: 600;
    }

    .cashier-field .form-control {
        height: 30px;
    }

    .cashier-field textarea.form-control {
        height: 64px;
        resize: vertical;
    }

    .cashier-span-2 {
        grid-column: span 2;
    }

    .cashier-image-placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 64px;
        height: 64px;
        border: 1px solid #9aa9b4;
        background: #f3f5f6;
        color: #66727c;
        font-size: 34px;
    }

    .cashier-customer-footer {
        display: flex;
        justify-content: space-between;
        margin-top: 18px;
    }

    .cashier-customer-footer .btn {
        min-width: 68px;
        height: 32px;
    }
</style>

<script type="text/javascript">
    $(document).ready(function() {
        let isSubmitting = false;

        $('#customer_form').closest('.modal-dialog').addClass('cashier-customer-dialog');

        $('#cashier_customer_close').click(function() {
            BootstrapDialog.closeAll();
        });

        $('#customer_form').validate($.extend({
            submitHandler: function(form) {
                if (isSubmitting) {
                    return;
                }

                isSubmitting = true;
                const finishSubmit = function(response) {
                    BootstrapDialog.closeAll();
                    dialog_support.hide();
                    table_support.handle_submit("<?= $controller_name ?>", response);
                };
                const displayError = function(message) {
                    isSubmitting = false;
                    $('#error_message_box')
                        .empty()
                        .addClass('alert alert-danger alert-dismissible')
                        .append($('<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>'))
                        .append($('<li>').text(message));
                };

                $(form).ajaxSubmit({
                    success: function(response) {
                        if (!response.success) {
                            const errors = response.errors || {};
                            const detail = Object.keys(errors).map(function(field) {
                                return errors[field];
                            }).filter(Boolean).join(' ');
                            displayError(detail || response.message || "<?= lang('Common.correct_errors') ?>");
                            return;
                        }

                        if (!response.success || !$('#requested_points').is(':enabled')) {
                            finishSubmit(response);
                            return;
                        }

                        $.post(
                            "<?= site_url("$controller_name/savePoints") ?>/" + response.id,
                            {
                                requested_points: $('#requested_points').val()
                            },
                            function(pointsResponse) {
                                if (pointsResponse.success) {
                                    finishSubmit(response);
                                    return;
                                }

                                displayError(pointsResponse.message || "<?= lang('Common.correct_errors') ?>");
                            },
                            'json'
                        ).fail(function(xhr) {
                            isSubmitting = false;
                            const responseJson = xhr.responseJSON || {};
                            displayError(responseJson.message || "<?= lang('Common.correct_errors') ?>");
                        });
                    },
                    dataType: 'json',
                    error: function(xhr) {
                        isSubmitting = false;
                        const responseJson = xhr.responseJSON || {};
                        displayError(responseJson.message || "<?= lang('Common.correct_errors') ?>");
                    }
                });
            },
            errorLabelContainer: '#error_message_box',
            rules: {
                first_name: 'required',
                account_number: {
                    remote: {
                        url: "<?= "$controller_name/checkAccountNumber" ?>",
                        type: 'POST',
                        data: {
                            'person_id': "<?= $person_info->person_id ?>"
                        }
                    }
                },
                requested_points: {
                    digits: true,
                    min: 0
                }
            },
            messages: {
                first_name: "<?= lang('Common.first_name_required') ?>",
                account_number: "<?= lang('Customers.account_number_duplicate') ?>",
                requested_points: "<?= lang('Common.correct_errors') ?>"
            }
        }, form_support.error));
    });
</script>
