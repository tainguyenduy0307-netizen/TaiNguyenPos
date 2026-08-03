<?php

namespace App\Libraries;

use App\Models\Business_unit_item_quantity;
use App\Models\Business_unit_item_unit_quantity;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Item_barcode;
use App\Models\Item_unit;
use App\Models\Stock_location;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use SimpleXMLElement;

class ItemExcelImportService
{
    private const SHEET_NAME = 'Danh_muc_clean';
    private const DEFAULT_CATEGORY = 'Uncategorized';
    private const MAX_MESSAGES = 100;
    private const HEADERS = [
        'Mã hàng hóa',
        'Tên hàng hóa',
        'Tên nhóm',
        'ĐVT',
        'ĐVT Lớn',
        'Mã ĐVT Lớn',
        'Giá trị quy đổi',
        'Giá bán',
        'Giá bán ĐVT Lớn',
        'Giá vốn',
        'Tồn kho',
    ];

    private BaseConnection $db;
    private Item $item;
    private Item_barcode $itemBarcode;
    private Item_unit $itemUnit;
    private Business_unit_item_quantity $businessUnitItemQuantity;
    private Business_unit_item_unit_quantity $businessUnitItemUnitQuantity;
    private Inventory $inventory;
    private Stock_location $stockLocation;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->item = model(Item::class);
        $this->itemBarcode = model(Item_barcode::class);
        $this->itemUnit = model(Item_unit::class);
        $this->businessUnitItemQuantity = model(Business_unit_item_quantity::class);
        $this->businessUnitItemUnitQuantity = model(Business_unit_item_unit_quantity::class);
        $this->inventory = model(Inventory::class);
        $this->stockLocation = model(Stock_location::class);
    }

    public function preview(string $path, string $businessUnitCode, bool $updateExisting, bool $replaceStock): array
    {
        $businessUnit = $this->getBusinessUnit($businessUnitCode);
        $workbook = $this->readWorkbook($path);
        $rows = $this->normalizeRows($workbook['rows']);
        $barcodeRows = $this->buildBarcodeRows($rows);
        $largeUnitRows = $this->buildLargeUnitRows($rows);
        $dbBarcodeItems = $this->getDatabaseBarcodeItems(array_keys($barcodeRows));
        $dbLargeUnitItems = $this->itemUnit->getItemIdsForLargeBarcodes(array_keys($largeUnitRows));

        $summary = $this->emptySummary($workbook['file'], $businessUnit->code);
        $summary['sheet'] = self::SHEET_NAME;
        $summary['total_rows'] = count($rows);
        $summary['replace_stock'] = $replaceStock;
        $summary['update_existing'] = $updateExisting;

        $validatedRows = [];
        foreach ($rows as $row) {
            $validated = $this->validateRow($row, $barcodeRows, $largeUnitRows, $dbBarcodeItems, $dbLargeUnitItems, $updateExisting);
            $validatedRows[] = $validated;
            $this->applyRowToSummary($summary, $validated);
        }

        $summary['can_import'] = $summary['errors'] === 0;

        return [
            'summary' => $summary,
            'rows'    => $validatedRows,
        ];
    }

    public function import(
        string $path,
        string $businessUnitCode,
        bool $updateExisting,
        bool $replaceStock,
        int $employeeId
    ): array {
        $preview = $this->preview($path, $businessUnitCode, $updateExisting, $replaceStock);

        if (!$preview['summary']['can_import']) {
            throw new RuntimeException('Import blocked because preview still has validation errors.');
        }

        $businessUnit = $this->getBusinessUnit($businessUnitCode);
        $locationId = $this->stockLocation->get_default_location_id('items');
        $result = $preview['summary'];
        $result['imported_barcodes'] = 0;
        $result['inventory_rows_updated'] = 0;
        $result['imported_retail_units'] = 0;
        $result['imported_large_units'] = 0;
        $result['large_unit_stock_defaults'] = 0;

        $this->db->transBegin();
        try {
            foreach ($preview['rows'] as $row) {
                if ($row['status'] === 'skipped') {
                    continue;
                }

                $itemId = $row['existing_item_id'];
                $aliases = $row['barcodes'];
                $itemData = [
                    'name'               => $row['name'],
                    'category'           => $row['category'],
                    'item_number'        => $row['main_barcode'],
                    'description'        => '',
                    'cost_price'         => $row['cost_price'],
                    'unit_price'         => $row['unit_price'],
                    'reorder_level'      => 0,
                    'receiving_quantity' => 1,
                    'allow_alt_description' => 0,
                    'is_serialized'      => 0,
                    'deleted'            => 0,
                    'stock_type'         => HAS_STOCK,
                    'item_type'          => ITEM,
                    'qty_per_pack'       => 1,
                    'pack_name'          => $row['unit'] !== '' ? $row['unit'] : lang('Items.default_pack_name'),
                    'hsn_code'           => '',
                ];

                if ($itemId !== null && $updateExisting) {
                    $oldItem = $this->item->get_info($itemId);
                    if (!empty($oldItem->item_number)) {
                        $aliases[] = (string) $oldItem->item_number;
                    }
                    if (!$this->item->save_value($itemData, $itemId)) {
                        throw new RuntimeException('Failed to update item on Excel row ' . $row['excel_row']);
                    }
                } else {
                    if (!$this->item->save_value($itemData)) {
                        throw new RuntimeException('Failed to create item on Excel row ' . $row['excel_row']);
                    }
                    $itemId = (int) $itemData['item_id'];
                }

                if (!$this->itemBarcode->saveAliases($itemId, array_values(array_unique($aliases)))) {
                    throw new RuntimeException('Failed to save barcode aliases on Excel row ' . $row['excel_row']);
                }
                $result['imported_barcodes'] += count(array_unique($aliases));

                $retailUnitId = $this->itemUnit->upsertRetailUnit($itemId, $row['unit'], $row['unit_price'], $row['cost_price']);
                if ($retailUnitId <= 0) {
                    throw new RuntimeException('Failed to save retail unit on Excel row ' . $row['excel_row']);
                }
                $result['imported_retail_units']++;

                if ($replaceStock || $row['existing_item_id'] === null) {
                    $previousQuantity = $this->businessUnitItemQuantity->getQuantity((int) $businessUnit->id, $itemId, $locationId);
                    $this->businessUnitItemQuantity->setQuantity((int) $businessUnit->id, $itemId, $locationId, $row['stock']);
                    $this->businessUnitItemUnitQuantity->setQuantity((int) $businessUnit->id, $retailUnitId, $row['stock']);
                    $delta = $row['stock'] - $previousQuantity;
                    $this->inventory->insert([
                        'trans_date'      => date('Y-m-d H:i:s'),
                        'trans_items'     => $itemId,
                        'trans_user'      => $employeeId,
                        'trans_location'  => $locationId,
                        'trans_comment'   => 'Excel item import',
                        'trans_inventory' => $delta,
                        'business_unit_id'=> (int) $businessUnit->id,
                    ], false);
                    $result['inventory_rows_updated']++;
                }

                if ($row['large_unit_status'] === 'valid') {
                    $largeUnitId = $this->itemUnit->upsertLargeUnit(
                        $itemId,
                        $row['large_unit_name'],
                        $row['large_unit_barcode_to_save'],
                        $row['large_unit_conversion'],
                        $row['large_unit_price'],
                        $row['large_unit_cost_price']
                    );
                    if ($largeUnitId <= 0) {
                        throw new RuntimeException('Failed to save large unit on Excel row ' . $row['excel_row']);
                    }
                    $this->businessUnitItemUnitQuantity->ensureZeroRow((int) $businessUnit->id, $largeUnitId);
                    $result['imported_large_units']++;
                    $result['large_unit_stock_defaults']++;
                }
            }

            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Database transaction failed.');
            }

            $this->db->transCommit();
        } catch (RuntimeException $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        return $result;
    }

    private function emptySummary(string $file, string $businessUnitCode): array
    {
        return [
            'file' => $file,
            'sheet' => '',
            'total_rows' => 0,
            'valid_rows' => 0,
            'new_items' => 0,
            'existing_items' => 0,
            'updated_items' => 0,
            'skipped_items' => 0,
            'errors' => 0,
            'warnings' => 0,
            'total_barcodes' => 0,
            'multi_barcode_items' => 0,
            'duplicate_barcodes_in_cell' => 0,
            'barcode_conflicts' => 0,
            'large_unit_conflicts' => 0,
            'existing_barcodes_same_item' => 0,
            'existing_barcodes_other_item' => 0,
            'zero_cost_items' => 0,
            'negative_stock_items' => 0,
            'valid_large_units' => 0,
            'incomplete_large_units' => 0,
            'large_units_with_valid_barcode' => 0,
            'large_units_without_barcode_due_to_conflict' => 0,
            'manual_large_units' => 0,
            'large_units_to_import' => 0,
            'large_unit_stock_defaults' => 0,
            'large_unit_cost_calculations' => 0,
            'large_unit_zero_cost_warnings' => 0,
            'ignored_large_unit_rows' => 0,
            'business_unit' => $businessUnitCode,
            'stock_is_retail' => true,
            'large_stock_defaults_to_zero' => true,
            'replace_stock' => false,
            'update_existing' => false,
            'blocking_errors' => 0,
            'can_import' => false,
            'messages' => [],
        ];
    }

    private function applyRowToSummary(array &$summary, array $row): void
    {
        $summary['total_barcodes'] += count($row['barcodes']);
        if (count($row['barcodes']) > 1) {
            $summary['multi_barcode_items']++;
        }
        if ($row['duplicate_count'] > 0) {
            $summary['duplicate_barcodes_in_cell'] += $row['duplicate_count'];
        }
        if ($row['cost_price'] === 0.0) {
            $summary['zero_cost_items']++;
        }
        if ($row['stock'] < 0) {
            $summary['negative_stock_items']++;
        }
        if ($row['large_unit_status'] === 'valid') {
            $summary['valid_large_units']++;
            $summary['large_units_to_import']++;
            $summary['large_unit_stock_defaults']++;
            $summary['large_unit_cost_calculations']++;
            if ($row['large_unit_barcode_to_save'] !== '') {
                $summary['large_units_with_valid_barcode']++;
            } else {
                $summary['manual_large_units']++;
                if ($row['large_unit_barcode_conflict']) {
                    $summary['large_units_without_barcode_due_to_conflict']++;
                }
            }
            if ($row['large_unit_cost_price'] === 0.0) {
                $summary['large_unit_zero_cost_warnings']++;
            }
        } elseif ($row['large_unit_status'] === 'incomplete') {
            $summary['incomplete_large_units']++;
            $summary['ignored_large_unit_rows']++;
        } elseif ($row['large_unit_status'] === 'conflict') {
            $summary['incomplete_large_units']++;
            $summary['ignored_large_unit_rows']++;
        }
        if ($row['existing_item_id'] !== null) {
            $summary['existing_items']++;
        }
        if ($row['status'] === 'valid') {
            $summary['valid_rows']++;
            if ($row['existing_item_id'] === null) {
                $summary['new_items']++;
            } elseif ($summary['update_existing']) {
                $summary['updated_items']++;
            }
        } elseif ($row['status'] === 'skipped') {
            $summary['skipped_items']++;
        }

        foreach ($row['errors'] as $message) {
            $summary['errors']++;
            $summary['blocking_errors']++;
            if (str_contains($message, 'xung đột')) {
                $summary['barcode_conflicts']++;
            }
            if (str_contains($message, 'đang thuộc item khác')) {
                $summary['existing_barcodes_other_item']++;
            }
            $this->appendMessage($summary, $row, 'error', $message);
        }

        foreach ($row['warnings'] as $message) {
            $summary['warnings']++;
            if (str_contains($message, 'đã tồn tại đúng item')) {
                $summary['existing_barcodes_same_item']++;
            }
            if (str_contains($message, 'Mã ĐVT Lớn trùng barcode alias lẻ') || str_contains($message, 'Mã đơn vị lớn bị xung đột')) {
                $summary['large_unit_conflicts']++;
            }
            $this->appendMessage($summary, $row, 'warning', $message);
        }
    }

    private function appendMessage(array &$summary, array $row, string $level, string $message): void
    {
        if (count($summary['messages']) >= self::MAX_MESSAGES) {
            if ($level === 'error') {
                for ($index = count($summary['messages']) - 1; $index >= 0; $index--) {
                    if ($summary['messages'][$index]['level'] === 'warning') {
                        unset($summary['messages'][$index]);
                        $summary['messages'] = array_values($summary['messages']);
                        break;
                    }
                }
            }
            if (count($summary['messages']) >= self::MAX_MESSAGES) {
                return;
            }
        }

        if (count($summary['messages']) >= self::MAX_MESSAGES) {
            return;
        }

        $summary['messages'][] = [
            'level' => $level,
            'excel_row' => $row['excel_row'],
            'name' => $row['name'],
            'main_barcode' => $row['main_barcode'],
            'barcodes' => $row['barcodes'],
            'large_unit_barcode' => $row['large_unit_barcode'],
            'status' => $row['status'],
            'message' => $message,
        ];
    }

    private function validateRow(
        array $row,
        array $barcodeRows,
        array $largeUnitRows,
        array $dbBarcodeItems,
        array $dbLargeUnitItems,
        bool $updateExisting
    ): array {
        $errors = [];
        $warnings = [];
        [$barcodes, $duplicateCount] = $this->splitBarcodes($row['Mã hàng hóa']);
        $mainBarcode = $barcodes[0] ?? '';

        if ($duplicateCount > 0) {
            $warnings[] = 'Barcode lặp trong cùng ô đã được loại.';
        }
        if ($mainBarcode === '') {
            $errors[] = 'Mã hàng hóa bắt buộc.';
        }
        foreach ($barcodes as $barcode) {
            if (preg_match('/^\d+(?:\.\d+)?E[+-]?\d+$/i', $barcode) === 1) {
                $errors[] = 'Barcode đang ở dạng scientific notation: ' . $barcode;
            }
            if (isset($barcodeRows[$barcode]) && count($barcodeRows[$barcode]) > 1) {
                $errors[] = 'Barcode xung đột giữa các dòng Excel: ' . $barcode . ' ở dòng ' . implode(', ', $barcodeRows[$barcode]);
            }
        }

        $name = trim((string) $row['Tên hàng hóa']);
        if ($name === '') {
            $errors[] = 'Tên hàng hóa bắt buộc.';
        }

        $unitPrice = $this->parseNumber($row['Giá bán']);
        if ($unitPrice === null || $unitPrice <= 0) {
            $errors[] = 'Giá bán phải lớn hơn 0.';
            $unitPrice = 0.0;
        }

        $costPrice = $this->parseNumber($row['Giá vốn']);
        if ($costPrice === null) {
            $costPrice = 0.0;
        } elseif ($costPrice < 0) {
            $errors[] = 'Giá vốn không được âm.';
        } elseif ($costPrice === 0.0) {
            $warnings[] = 'Giá vốn bằng 0.';
        }

        $stock = $this->parseNumber($row['Tồn kho']);
        if ($stock === null) {
            $errors[] = 'Tồn kho phải là số.';
            $stock = 0.0;
        } elseif ($stock < 0) {
            $warnings[] = 'Tồn kho âm.';
        }

        $existingItemIds = [];
        foreach ($barcodes as $barcode) {
            if (isset($dbBarcodeItems[$barcode])) {
                $existingItemIds[$dbBarcodeItems[$barcode]] = true;
            }
        }
        $existingItemIds = array_keys($existingItemIds);
        $existingItemId = count($existingItemIds) === 1 ? (int) $existingItemIds[0] : null;

        if (count($existingItemIds) > 1) {
            $errors[] = 'Nhiều barcode trong một dòng đang trỏ tới nhiều item_id khác nhau: ' . implode(', ', $existingItemIds);
        }

        foreach ($barcodes as $barcode) {
            if ($existingItemId !== null && ($dbBarcodeItems[$barcode] ?? null) === $existingItemId) {
                $warnings[] = 'Barcode đã tồn tại đúng item: ' . $barcode;
            } elseif ($existingItemId !== null && isset($dbBarcodeItems[$barcode]) && $dbBarcodeItems[$barcode] !== $existingItemId) {
                $errors[] = 'Barcode đang thuộc item khác: ' . $barcode;
            }
        }

        $largeUnit = $this->largeUnitStatus($row, $barcodes, $costPrice);
        if ($largeUnit['status'] === 'incomplete') {
            $warnings[] = $largeUnit['message'] . ' Dữ liệu đơn vị lớn chưa được nhập do chưa đủ điều kiện.';
        } elseif ($largeUnit['status'] === 'valid') {
            $largeBarcode = $largeUnit['barcode'];
            $largeBarcodeConflicts = [];
            if ($largeBarcode === '') {
                $largeUnit['barcode_to_save'] = '';
                $warnings[] = 'Đơn vị lớn không có Mã ĐVT Lớn nên sẽ không được dùng để quét. Đơn vị lớn vẫn được tạo và có thể chọn thủ công tại Thu ngân.';
            } elseif ($this->isUnreliableLargeUnitBarcode($largeBarcode)) {
                $largeBarcodeConflicts[] = 'mã không đáng tin cậy';
            }
            if ($largeBarcode !== '' && isset($largeUnitRows[$largeBarcode]) && count($largeUnitRows[$largeBarcode]) > 1) {
                $largeBarcodeConflicts[] = 'cùng mã thùng dùng cho nhiều dòng: ' . implode(', ', $largeUnitRows[$largeBarcode]);
            }
            if ($largeBarcode !== '' && isset($barcodeRows[$largeBarcode])) {
                $largeBarcodeConflicts[] = 'trùng barcode alias lẻ';
            }
            if ($largeBarcode !== '' && isset($dbBarcodeItems[$largeBarcode])) {
                $largeBarcodeConflicts[] = 'trùng barcode lẻ trong database';
            }
            if ($largeBarcode !== '' && isset($dbLargeUnitItems[$largeBarcode]) && ($existingItemId === null || $dbLargeUnitItems[$largeBarcode]['item_id'] !== $existingItemId)) {
                $largeBarcodeConflicts[] = 'trùng mã thùng của sản phẩm khác';
            }
            if ($largeBarcodeConflicts !== []) {
                $largeUnit['barcode_conflict'] = true;
                $largeUnit['barcode_to_save'] = '';
                $warnings[] = 'Mã đơn vị lớn bị xung đột nên sẽ không được dùng để quét. Đơn vị lớn vẫn được tạo và có thể chọn thủ công tại Thu ngân. Chi tiết: ' . implode('; ', array_values(array_unique($largeBarcodeConflicts))) . '.';
            }
            if ($largeUnit['cost_price'] === 0.0) {
                $warnings[] = 'Giá vốn đơn vị lớn bằng 0 do Giá vốn lẻ bằng 0.';
            }
        } elseif ($largeUnit['status'] === 'conflict') {
            $warnings[] = 'Mã ĐVT Lớn trùng barcode alias lẻ trong cùng dòng. Dữ liệu đơn vị lớn chưa được nhập do chưa đủ điều kiện.';
        }

        $status = 'valid';
        if (!empty($errors)) {
            $status = 'error';
        } elseif ($existingItemId !== null && !$updateExisting) {
            $status = 'skipped';
            $warnings[] = 'Sản phẩm đã tồn tại và tùy chọn cập nhật đang tắt.';
        }

        return [
            'excel_row' => $row['_excel_row'],
            'status' => $status,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'barcodes' => $barcodes,
            'duplicate_count' => $duplicateCount,
            'main_barcode' => $mainBarcode,
            'name' => $name,
            'category' => trim((string) $row['Tên nhóm']) !== '' ? trim((string) $row['Tên nhóm']) : self::DEFAULT_CATEGORY,
            'unit' => trim((string) $row['ĐVT']),
            'unit_price' => (float) $unitPrice,
            'cost_price' => (float) $costPrice,
            'stock' => (float) $stock,
            'existing_item_id' => $existingItemId,
            'large_unit_status' => $largeUnit['status'],
            'large_unit_name' => $largeUnit['name'],
            'large_unit_barcode' => $largeUnit['barcode'],
            'large_unit_barcode_to_save' => $largeUnit['barcode_to_save'],
            'large_unit_barcode_conflict' => $largeUnit['barcode_conflict'],
            'large_unit_conversion' => $largeUnit['conversion'],
            'large_unit_price' => $largeUnit['price'],
            'large_unit_cost_price' => $largeUnit['cost_price'],
        ];
    }

    private function splitBarcodes(string $value): array
    {
        $parts = array_filter(array_map('trim', explode(',', $value)), static fn ($barcode) => $barcode !== '');
        $unique = [];
        foreach ($parts as $part) {
            $unique[$part] = $part;
        }

        return [array_values($unique), count($parts) - count($unique)];
    }

    private function largeUnitStatus(array $row, array $retailBarcodes, float $retailCostPrice): array
    {
        $largeUnitName = trim((string) $row['ĐVT Lớn']);
        $largeUnitBarcode = trim((string) $row['Mã ĐVT Lớn']);
        $conversion = $this->parseNumber($row['Giá trị quy đổi']);
        $largeUnitPrice = $this->parseNumber($row['Giá bán ĐVT Lớn']);

        if ($largeUnitName === '' && $largeUnitBarcode === '' && trim((string) $row['Giá trị quy đổi']) === '' && trim((string) $row['Giá bán ĐVT Lớn']) === '') {
            return $this->largeUnitResult('none', $largeUnitName, $largeUnitBarcode, 0, 0.0, 0.0);
        }
        if (
            $largeUnitName !== ''
            && $conversion !== null
            && (int) $conversion == $conversion
            && $conversion > 1
            && $largeUnitPrice !== null
            && $largeUnitPrice > 0
        ) {
            $conversionQuantity = (int) $conversion;
            return $this->largeUnitResult(
                'valid',
                $largeUnitName,
                $largeUnitBarcode,
                $conversionQuantity,
                (float) $largeUnitPrice,
                $retailCostPrice * $conversionQuantity
            );
        }

        $missing = [];
        if ($largeUnitName === '') {
            $missing[] = 'thiếu ĐVT Lớn';
        }
        if ($largeUnitBarcode === '' && ($largeUnitName === '' || $conversion === null || (int) $conversion != $conversion || $conversion <= 1 || $largeUnitPrice === null || $largeUnitPrice <= 0)) {
            $missing[] = 'thiếu Mã ĐVT Lớn';
        }
        if ($conversion === null) {
            $missing[] = 'thiếu quy đổi';
        } elseif ((int) $conversion != $conversion || $conversion <= 1) {
            $missing[] = 'quy đổi không hợp lệ';
        }
        if ($largeUnitPrice === null) {
            $missing[] = 'thiếu giá bán đơn vị lớn';
        } elseif ($largeUnitPrice <= 0) {
            $missing[] = 'giá bán đơn vị lớn không hợp lệ';
        }

        return $this->largeUnitResult('incomplete', $largeUnitName, $largeUnitBarcode, 0, 0.0, 0.0, 'Cấu hình đơn vị lớn chưa đầy đủ: ' . implode(', ', $missing) . '.');
    }

    private function largeUnitResult(string $status, string $name, string $barcode, int $conversion, float $price, float $costPrice, string $message = ''): array
    {
        return [
            'status' => $status,
            'name' => $name,
            'barcode' => $barcode,
            'barcode_to_save' => $barcode,
            'barcode_conflict' => false,
            'conversion' => $conversion,
            'price' => $price,
            'cost_price' => $costPrice,
            'message' => $message,
        ];
    }

    private function isUnreliableLargeUnitBarcode(string $barcode): bool
    {
        return strlen($barcode) < 4;
    }

    private function parseNumber(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = str_replace(' ', '', $value);
        if (preg_match('/^-?\d{1,3}([.,]\d{3})+$/', $value) === 1) {
            $value = str_replace(['.', ','], '', $value);
        } elseif (str_contains($value, ',') && !str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function buildBarcodeRows(array $rows): array
    {
        $barcodeRows = [];
        foreach ($rows as $row) {
            [$barcodes] = $this->splitBarcodes($row['Mã hàng hóa']);
            foreach ($barcodes as $barcode) {
                $barcodeRows[$barcode][] = $row['_excel_row'];
            }
        }

        return $barcodeRows;
    }

    private function buildLargeUnitRows(array $rows): array
    {
        $largeUnitRows = [];
        foreach ($rows as $row) {
            $barcode = trim((string) $row['Mã ĐVT Lớn']);
            if ($barcode !== '') {
                $largeUnitRows[$barcode][] = $row['_excel_row'];
            }
        }

        return $largeUnitRows;
    }

    private function getDatabaseBarcodeItems(array $barcodes): array
    {
        if (empty($barcodes)) {
            return [];
        }

        $result = $this->itemBarcode->getItemIdsForBarcodes($barcodes);
        $rows = $this->db->table('items')
            ->select('item_number, item_id')
            ->where('deleted', 0)
            ->whereIn('item_number', $barcodes)
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $result[$row['item_number']] = (int) $row['item_id'];
        }

        return $result;
    }

    private function normalizeRows(array $rawRows): array
    {
        $rows = [];
        foreach ($rawRows as $rowIndex => $values) {
            $values += array_fill(0, count(self::HEADERS), '');
            $values = array_slice($values, 0, count(self::HEADERS));
            if (!array_filter($values, static fn ($value) => trim((string) $value) !== '')) {
                continue;
            }

            $row = ['_excel_row' => $rowIndex + 2];
            foreach (self::HEADERS as $index => $header) {
                $row[$header] = trim((string) $values[$index]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function getBusinessUnit(string $businessUnitCode): object
    {
        if (!in_array($businessUnitCode, ['DAY', 'NIGHT'], true)) {
            throw new RuntimeException('Only DAY or NIGHT can receive imported stock.');
        }

        $businessUnit = $this->db->table('business_units')
            ->where('code', $businessUnitCode)
            ->where('enabled', 1)
            ->get()
            ->getRow();

        if ($businessUnit === null) {
            throw new RuntimeException('Business unit is not available: ' . $businessUnitCode);
        }

        return $businessUnit;
    }

    private function readWorkbook(string $path): array
    {
        $entries = SimpleZip::read($path);
        $sharedStrings = $this->readSharedStrings($entries);
        $sheets = $this->readSheets($entries);
        if (!isset($sheets[self::SHEET_NAME])) {
            throw new RuntimeException('Workbook is missing sheet ' . self::SHEET_NAME . '.');
        }

        $sheetXml = $entries[$sheets[self::SHEET_NAME]] ?? false;
        if ($sheetXml === false || $sheetXml === null) {
            throw new RuntimeException('Cannot read sheet ' . self::SHEET_NAME . '.');
        }

        $sheet = new SimpleXMLElement($sheetXml);
        $sheet->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheetRows = $sheet->xpath('//main:sheetData/main:row') ?: [];
        if ($sheetRows === []) {
            throw new RuntimeException('Sheet ' . self::SHEET_NAME . ' has no rows.');
        }

        $headers = array_map('trim', $this->readCells($sheetRows[0], $sharedStrings));
        $headers += array_fill(0, count(self::HEADERS), '');
        $headers = array_slice($headers, 0, count(self::HEADERS));
        if ($headers !== self::HEADERS) {
            throw new RuntimeException('Sheet ' . self::SHEET_NAME . ' does not have the required 11 headers.');
        }

        $rows = [];
        foreach (array_slice($sheetRows, 1) as $row) {
            $rows[] = $this->readCells($row, $sharedStrings);
        }

        return [
            'file' => basename($path),
            'rows' => $rows,
        ];
    }

    private function readSharedStrings(array $entries): array
    {
        $xml = $entries['xl/sharedStrings.xml'] ?? null;
        if ($xml === null) {
            return [];
        }

        $shared = [];
        $root = new SimpleXMLElement($xml);
        $root->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($root->xpath('//main:si') ?: [] as $si) {
            $text = '';
            foreach ($si->xpath('.//main:t') ?: [] as $node) {
                $text .= (string) $node;
            }
            $shared[] = $text;
        }

        return $shared;
    }

    private function readSheets(array $entries): array
    {
        $workbookXml = $entries['xl/workbook.xml'] ?? null;
        $relsXml = $entries['xl/_rels/workbook.xml.rels'] ?? null;
        if ($workbookXml === null || $relsXml === null) {
            throw new RuntimeException('Invalid .xlsx workbook structure.');
        }

        $rels = [];
        $relsRoot = new SimpleXMLElement($relsXml);
        foreach ($relsRoot->Relationship as $relationship) {
            $attrs = $relationship->attributes();
            $rels[(string) $attrs['Id']] = $this->resolveWorkbookTarget((string) $attrs['Target']);
        }

        $workbook = new SimpleXMLElement($workbookXml);
        $workbook->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('rel', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $sheets = [];
        foreach ($workbook->xpath('//main:sheets/main:sheet') ?: [] as $sheet) {
            $attrs = $sheet->attributes();
            $relAttrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid = (string) $relAttrs['id'];
            $sheets[(string) $attrs['name']] = $rels[$rid] ?? '';
        }

        return $sheets;
    }

    private function resolveWorkbookTarget(string $target): string
    {
        $target = ltrim($target, '/');
        if (str_starts_with($target, 'xl/')) {
            return $target;
        }

        return 'xl/' . $target;
    }

    private function readCells(SimpleXMLElement $row, array $sharedStrings): array
    {
        $values = [];
        $row->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($row->xpath('main:c') ?: [] as $cell) {
            $attrs = $cell->attributes();
            $column = $this->columnIndex((string) $attrs['r']);
            while (count($values) < $column - 1) {
                $values[] = '';
            }
            $values[] = $this->cellValue($cell, $sharedStrings);
        }

        return $values;
    }

    private function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $attrs = $cell->attributes();
        $type = (string) ($attrs['t'] ?? '');
        $cell->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        if ($type === 's') {
            $value = $cell->xpath('main:v');
            $index = isset($value[0]) ? (int) $value[0] : -1;
            return $sharedStrings[$index] ?? '';
        }
        if ($type === 'inlineStr') {
            $text = '';
            foreach ($cell->xpath('.//main:t') ?: [] as $node) {
                $text .= (string) $node;
            }
            return $text;
        }

        $value = $cell->xpath('main:v');
        return isset($value[0]) ? (string) $value[0] : '';
    }

    private function columnIndex(string $cellReference): int
    {
        preg_match('/^[A-Z]+/i', $cellReference, $matches);
        $letters = strtoupper($matches[0] ?? 'A');
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        return $index;
    }
}
