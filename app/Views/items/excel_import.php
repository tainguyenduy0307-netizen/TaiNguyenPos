<?php
/**
 * @var array|null $preview
 * @var array|null $import_result
 * @var string|null $error
 * @var string $business_unit
 * @var bool $update_existing
 * @var bool $replace_stock
 * @var string|null $preview_token
 */
?>

<?= view('partial/header') ?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-10 col-md-offset-1">
            <div class="page-header">
                <h3>Nhập danh mục Excel</h3>
            </div>

            <?php if (!empty($error)) { ?>
                <div class="alert alert-danger"><?= esc($error) ?></div>
            <?php } ?>

            <?php if ($import_result !== null) { ?>
                <div class="alert alert-success">Import hoàn tất.</div>
                <table class="table table-bordered table-striped">
                    <tbody>
                    <tr><th>Tổng dòng</th><td><?= esc($import_result['total_rows']) ?></td></tr>
                    <tr><th>Sản phẩm mới</th><td><?= esc($import_result['new_items']) ?></td></tr>
                    <tr><th>Sản phẩm cập nhật</th><td><?= esc($import_result['updated_items']) ?></td></tr>
                    <tr><th>Sản phẩm bỏ qua</th><td><?= esc($import_result['skipped_items']) ?></td></tr>
                    <tr><th>Sản phẩm đã nhập</th><td><?= esc($import_result['new_items'] + $import_result['updated_items']) ?></td></tr>
                    <tr><th>Barcode đã nhập</th><td><?= esc($import_result['imported_barcodes']) ?></td></tr>
                    <tr><th>Đơn vị lẻ đã nhập</th><td><?= esc($import_result['imported_retail_units'] ?? 0) ?></td></tr>
                    <tr><th>Đơn vị lớn đã nhập</th><td><?= esc($import_result['imported_large_units'] ?? 0) ?></td></tr>
                    <tr><th>Tồn lẻ đã nhập cho <?= esc($import_result['business_unit']) ?></th><td><?= esc($import_result['inventory_rows_updated']) ?></td></tr>
                    <tr><th>Tồn đơn vị lớn đã khởi tạo</th><td><?= esc($import_result['large_unit_stock_defaults'] ?? 0) ?></td></tr>
                    <tr><th>Dữ liệu đơn vị lớn bị bỏ qua</th><td><?= esc($import_result['ignored_large_unit_rows']) ?></td></tr>
                    <tr><th>Dòng đơn vị lớn cần xử lý sau</th><td><?= esc($import_result['ignored_large_unit_rows']) ?></td></tr>
                    <tr><th>Tồn đơn vị lớn</th><td>Mặc định 0; file Excel không có cột tồn đơn vị lớn.</td></tr>
                    </tbody>
                </table>
                <a class="btn btn-default" href="<?= site_url('items') ?>">Quay lại danh sách hàng hóa</a>
            <?php } ?>

            <?php if ($import_result === null) { ?>
                <?= form_open_multipart('items/previewExcelImport', ['class' => 'form-horizontal']) ?>
                    <div class="form-group">
                        <label class="control-label col-sm-3" for="file_path">File .xlsx</label>
                        <div class="col-sm-7">
                            <input type="file" id="file_path" name="file_path" class="form-control" accept=".xlsx" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="control-label col-sm-3" for="business_unit">Đơn vị nhận tồn</label>
                        <div class="col-sm-4">
                            <?= form_dropdown('business_unit', ['DAY' => 'DAY', 'NIGHT' => 'NIGHT'], $business_unit, ['id' => 'business_unit', 'class' => 'form-control']) ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="col-sm-7 col-sm-offset-3">
                            <div class="checkbox">
                                <label>
                                    <?= form_checkbox('update_existing', '1', $update_existing) ?>
                                    Cập nhật sản phẩm đã tồn tại
                                </label>
                            </div>
                            <div class="checkbox">
                                <label>
                                    <?= form_checkbox('replace_stock', '1', $replace_stock) ?>
                                    Thay thế tồn kho bằng dữ liệu Excel
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="col-sm-7 col-sm-offset-3">
                            <button class="btn btn-primary" type="submit">Kiểm tra dữ liệu</button>
                            <a class="btn btn-default" href="<?= site_url('items/generateExcelTemplate') ?>">Tải file mẫu</a>
                            <a class="btn btn-default" href="<?= site_url('items') ?>">Quay lại danh sách hàng hóa</a>
                        </div>
                    </div>
                <?= form_close() ?>
            <?php } ?>

            <?php if ($preview !== null) {
                $summary = $preview['summary'];
                ?>
                <hr>
                <h4>Preview</h4>
                <table class="table table-bordered table-striped">
                    <tbody>
                    <tr><th>Tên file</th><td><?= esc($summary['file']) ?></td></tr>
                    <tr><th>Sheet</th><td><?= esc($summary['sheet']) ?></td></tr>
                    <tr><th>Tổng dòng</th><td><?= esc($summary['total_rows']) ?></td></tr>
                    <tr><th>Dòng hợp lệ</th><td><?= esc($summary['valid_rows']) ?></td></tr>
                    <tr><th>Sản phẩm mới</th><td><?= esc($summary['new_items']) ?></td></tr>
                    <tr><th>Sản phẩm hiện có</th><td><?= esc($summary['existing_items']) ?></td></tr>
                    <tr><th>Sản phẩm sẽ cập nhật</th><td><?= esc($summary['updated_items']) ?></td></tr>
                    <tr><th>Sản phẩm sẽ bỏ qua</th><td><?= esc($summary['skipped_items']) ?></td></tr>
                    <tr><th>Lỗi</th><td><?= esc($summary['errors']) ?></td></tr>
                    <tr><th>Lỗi chặn import thực sự</th><td><?= esc($summary['blocking_errors'] ?? $summary['errors']) ?></td></tr>
                    <tr><th>Cảnh báo</th><td><?= esc($summary['warnings']) ?></td></tr>
                    <tr><th>Tổng barcode</th><td><?= esc($summary['total_barcodes']) ?></td></tr>
                    <tr><th>Sản phẩm nhiều barcode</th><td><?= esc($summary['multi_barcode_items']) ?></td></tr>
                    <tr><th>Barcode lặp trong ô đã loại</th><td><?= esc($summary['duplicate_barcodes_in_cell']) ?></td></tr>
                    <tr><th>Barcode xung đột giữa sản phẩm</th><td><?= esc($summary['barcode_conflicts']) ?></td></tr>
                    <tr><th>Mã ĐVT Lớn trùng barcode alias lẻ</th><td><?= esc($summary['large_unit_conflicts']) ?></td></tr>
                    <tr><th>Barcode đã tồn tại đúng item</th><td><?= esc($summary['existing_barcodes_same_item']) ?></td></tr>
                    <tr><th>Barcode đang thuộc item khác</th><td><?= esc($summary['existing_barcodes_other_item']) ?></td></tr>
                    <tr><th>Sản phẩm giá vốn 0</th><td><?= esc($summary['zero_cost_items']) ?></td></tr>
                    <tr><th>Tồn âm</th><td><?= esc($summary['negative_stock_items']) ?></td></tr>
                    <tr><th>Đơn vị lớn hợp lệ</th><td><?= esc($summary['valid_large_units']) ?></td></tr>
                    <tr><th>Đơn vị lớn có barcode hợp lệ</th><td><?= esc($summary['large_units_with_valid_barcode'] ?? 0) ?></td></tr>
                    <tr><th>Đơn vị lớn không có barcode do conflict</th><td><?= esc($summary['large_units_without_barcode_due_to_conflict'] ?? 0) ?></td></tr>
                    <tr><th>Đơn vị lớn chọn thủ công</th><td><?= esc($summary['manual_large_units'] ?? 0) ?></td></tr>
                    <tr><th>Đơn vị lớn sẽ nhập</th><td><?= esc($summary['large_units_to_import'] ?? 0) ?></td></tr>
                    <tr><th>Đơn vị lớn thiếu dữ liệu</th><td><?= esc($summary['incomplete_large_units']) ?></td></tr>
                    <tr><th>Dữ liệu đơn vị lớn bị bỏ qua</th><td><?= esc($summary['ignored_large_unit_rows']) ?></td></tr>
                    <tr><th>Tồn đơn vị lớn sau import</th><td><?= esc($summary['large_unit_stock_defaults'] ?? 0) ?> dòng mặc định 0</td></tr>
                    <tr><th>Giá vốn đơn vị lớn</th><td><?= esc($summary['large_unit_cost_calculations'] ?? 0) ?> dòng tính từ Giá vốn lẻ x Quy đổi</td></tr>
                    <tr><th>Business unit nhận tồn</th><td><?= esc($summary['business_unit']) ?></td></tr>
                    <tr><th>Tồn Excel</th><td>Tồn lẻ; tồn đơn vị lớn mặc định 0.</td></tr>
                    </tbody>
                </table>

                <?php if ($summary['messages'] !== []) { ?>
                    <?php
                    $error_messages = array_values(array_filter($summary['messages'], static fn ($message) => $message['level'] === 'error'));
                    $warning_messages = array_values(array_filter($summary['messages'], static fn ($message) => $message['level'] === 'warning'));
                    ?>
                    <?php if ($error_messages !== []) { ?>
                    <h4>Lỗi chặn import</h4>
                    <table class="table table-condensed table-bordered">
                        <thead>
                        <tr>
                            <th>Dòng</th>
                            <th>Tên sản phẩm</th>
                            <th>Mã chính</th>
                            <th>Barcode alias</th>
                            <th>Mã ĐVT Lớn</th>
                            <th>Trạng thái</th>
                            <th>Nội dung</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($error_messages as $message) { ?>
                            <tr class="danger">
                                <td><?= esc($message['excel_row']) ?></td>
                                <td><?= esc($message['name']) ?></td>
                                <td><?= esc($message['main_barcode']) ?></td>
                                <td><?= esc(implode(', ', $message['barcodes'])) ?></td>
                                <td><?= esc($message['large_unit_barcode']) ?></td>
                                <td><?= esc($message['status']) ?></td>
                                <td><?= esc($message['message']) ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                    <?php } ?>

                    <?php if ($warning_messages !== []) { ?>
                    <h4>Cảnh báo không chặn import</h4>
                    <table class="table table-condensed table-bordered">
                        <thead>
                        <tr>
                            <th>Dòng</th>
                            <th>Tên sản phẩm</th>
                            <th>Mã chính</th>
                            <th>Barcode alias</th>
                            <th>Mã ĐVT Lớn</th>
                            <th>Trạng thái</th>
                            <th>Nội dung</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($warning_messages as $message) { ?>
                            <tr class="warning">
                                <td><?= esc($message['excel_row']) ?></td>
                                <td><?= esc($message['name']) ?></td>
                                <td><?= esc($message['main_barcode']) ?></td>
                                <td><?= esc(implode(', ', $message['barcodes'])) ?></td>
                                <td><?= esc($message['large_unit_barcode']) ?></td>
                                <td><?= esc($message['status']) ?></td>
                                <td><?= esc($message['message']) ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                    <?php } ?>
                <?php } ?>

                <?php if ($summary['can_import'] && $preview_token !== null) { ?>
                    <?= form_open('items/importExcelFile') ?>
                        <?= form_hidden('preview_token', $preview_token) ?>
                        <button class="btn btn-success" type="submit">Thực hiện nhập</button>
                        <a class="btn btn-default" href="<?= site_url('items') ?>">Quay lại danh sách hàng hóa</a>
                    <?= form_close() ?>
                <?php } else { ?>
                    <div class="alert alert-warning">Chưa thể import khi preview còn lỗi.</div>
                <?php } ?>
            <?php } ?>
        </div>
    </div>
</div>

<?= view('partial/footer') ?>
