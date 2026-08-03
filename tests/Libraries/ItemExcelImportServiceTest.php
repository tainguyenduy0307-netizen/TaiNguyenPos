<?php

namespace Tests\Libraries;

use App\Libraries\ItemExcelImportService;
use App\Libraries\SimpleZip;
use App\Models\Item;
use App\Models\Item_barcode;
use App\Models\Item_unit;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class ItemExcelImportServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $seed = '';
    protected $seedOnce = true;
    protected $refresh = true;
    protected $namespace = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        session()->set('person_id', 1);
    }

    public function testPreviewReadsNamedSheetAndDetectsBarcodeConflicts(): void
    {
        $path = $this->createWorkbook([
            ['0001234567890, 0001234567891, 0001234567891', 'Sữa Việt Nam', 'Sua', 'HOP', '', '', '1', '10000', '10000', '0', '-2'],
            ['HH-0017', 'Bánh Unicode', '', 'GOI', '', '', '1', '15000', '15000', '7000', '3'],
            ['0001234567890', 'Sản phẩm xung đột', 'Khac', 'HOP', '', '', '1', '20000', '20000', '12000', '4'],
            ['CASE-001', 'Thùng hợp lệ', 'Case', 'Chai', 'Thùng', 'CASE-001-BOX', '24', '10000', '225000', '8000', '5'],
        ]);

        $service = new ItemExcelImportService();
        $preview = $service->preview($path, 'DAY', false, false);

        $this->assertSame(4, $preview['summary']['total_rows']);
        $this->assertSame('Danh_muc_clean', $preview['summary']['sheet']);
        $this->assertSame(1, $preview['summary']['duplicate_barcodes_in_cell']);
        $this->assertGreaterThan(0, $preview['summary']['barcode_conflicts']);
        $this->assertSame(1, $preview['summary']['zero_cost_items']);
        $this->assertSame(1, $preview['summary']['negative_stock_items']);
        $this->assertSame(1, $preview['summary']['valid_large_units']);
        $this->assertFalse($preview['summary']['can_import']);

        unlink($path);
    }

    public function testImportCreatesOneItemForMultipleBarcodesAndSetsOnlySelectedBusinessUnitStock(): void
    {
        $path = $this->createWorkbook([
            ['0900123456789, ALT-0900, HH-0017', 'Sản phẩm nhiều mã', '', 'HOP', '', '', '1', '25000', '25000', '0', '-7'],
        ]);

        $service = new ItemExcelImportService();
        $preview = $service->preview($path, 'DAY', false, false);
        $this->assertTrue($preview['summary']['can_import']);
        $this->assertSame(1, $preview['summary']['new_items']);
        $this->assertSame(3, $preview['summary']['total_barcodes']);

        $result = $service->import($path, 'DAY', false, false, 1);
        $this->assertSame(1, $result['new_items']);
        $this->assertSame(3, $result['imported_barcodes']);

        $itemModel = model(Item::class);
        $item = $itemModel->get_info_by_id_or_number('ALT-0900', false);
        $this->assertIsObject($item);
        $this->assertSame('0900123456789', $item->item_number);
        $this->assertSame((int) $item->item_id, $itemModel->get_item_id('HH-0017'));

        $barcodeModel = model(Item_barcode::class);
        $this->assertSame((int) $item->item_id, $barcodeModel->getItemId('0900123456789'));
        $this->assertSame((int) $item->item_id, $barcodeModel->getItemId('ALT-0900'));

        $db = db_connect();
        $dayId = $this->getBusinessUnitId('DAY');
        $nightId = $this->getBusinessUnitId('NIGHT');
        $locationId = (int) $db->table('stock_locations')->select('location_id')->where('deleted', 0)->get()->getRow()->location_id;

        $dayQuantity = $db->table('business_unit_item_quantities')
            ->where('business_unit_id', $dayId)
            ->where('item_id', (int) $item->item_id)
            ->where('location_id', $locationId)
            ->get()
            ->getRow()
            ->quantity;
        $nightQuantity = $db->table('business_unit_item_quantities')
            ->where('business_unit_id', $nightId)
            ->where('item_id', (int) $item->item_id)
            ->where('location_id', $locationId)
            ->get()
            ->getRow();

        $this->assertSame('-7.000', $dayQuantity);
        $this->assertNull($nightQuantity);

        $retailUnit = model(Item_unit::class)->getRetailUnit((int) $item->item_id);
        $this->assertNotNull($retailUnit);
        $this->assertSame('HOP', $retailUnit->unit_name);
        $this->assertSame('25000.00', $retailUnit->unit_price);

        unlink($path);
    }

    public function testImportCreatesValidLargeUnitWithExactPriceCostAndZeroStock(): void
    {
        $path = $this->createWorkbook([
            ['RETAIL-LARGE-001, ALT-LARGE-001', 'Hàng có thùng hợp lệ', 'Case', 'Chai', 'Thùng', 'BOX-LARGE-001', '24', '10000', '225000', '8000', '5'],
        ]);

        $service = new ItemExcelImportService();
        $preview = $service->preview($path, 'DAY', false, false);
        $this->assertTrue($preview['summary']['can_import']);
        $this->assertSame(1, $preview['summary']['valid_large_units']);
        $this->assertSame(1, $preview['summary']['large_units_to_import']);
        $this->assertSame(1, $preview['summary']['large_unit_stock_defaults']);

        $result = $service->import($path, 'DAY', false, false, 1);
        $this->assertSame(1, $result['imported_large_units']);

        $item = model(Item::class)->get_info_by_id_or_number('RETAIL-LARGE-001', false);
        $this->assertIsObject($item);

        $itemUnitModel = model(Item_unit::class);
        $largeUnit = $itemUnitModel->getLargeUnit((int) $item->item_id);
        $this->assertNotNull($largeUnit);
        $this->assertSame('Thùng', $largeUnit->unit_name);
        $this->assertSame('BOX-LARGE-001', $largeUnit->barcode);
        $this->assertSame('24.000', $largeUnit->conversion_quantity);
        $this->assertSame('225000.00', $largeUnit->unit_price);
        $this->assertSame('192000.00', $largeUnit->cost_price);

        $this->assertNull(model(Item_barcode::class)->getItemId('BOX-LARGE-001'));
        $this->assertSame('', model(Item::class)->get_info_by_id_or_number('BOX-LARGE-001', false));

        $dayId = $this->getBusinessUnitId('DAY');
        $largeStock = db_connect()->table('business_unit_item_unit_quantities')
            ->where('business_unit_id', $dayId)
            ->where('item_unit_id', (int) $largeUnit->item_unit_id)
            ->get()
            ->getRow()
            ->quantity;
        $this->assertSame('0.000', $largeStock);

        $service->import($path, 'DAY', true, true, 1);
        $this->assertSame(2, db_connect()->table('item_units')->where('item_id', (int) $item->item_id)->countAllResults());

        unlink($path);
    }

    public function testLargeUnitProblemsAreWarningsAndLargeUnitBarcodeIsNotImported(): void
    {
        $path = $this->createWorkbook([
            ['RETAIL-001, ALT-001', 'Hàng có mã thùng trùng alias', 'Case', 'Chai', 'Thùng', 'ALT-001', '24', '10000', '225000', '7000', '8'],
            ['RETAIL-002', 'Hàng thiếu dữ liệu thùng', 'Case', 'Goi', 'Thùng', 'CASE-002', '', '12000', '', '6000', '3'],
        ]);

        $service = new ItemExcelImportService();
        $preview = $service->preview($path, 'DAY', false, false);

        $this->assertTrue($preview['summary']['can_import']);
        $this->assertSame(0, $preview['summary']['errors']);
        $this->assertSame(1, $preview['summary']['large_unit_conflicts']);
        $this->assertSame(1, $preview['summary']['valid_large_units']);
        $this->assertSame(1, $preview['summary']['large_units_without_barcode_due_to_conflict']);
        $this->assertSame(1, $preview['summary']['manual_large_units']);
        $this->assertSame(1, $preview['summary']['incomplete_large_units']);
        $this->assertSame(1, $preview['summary']['ignored_large_unit_rows']);
        $this->assertStringContainsString(
            'Mã đơn vị lớn bị xung đột nên sẽ không được dùng để quét.',
            implode(' ', array_column($preview['summary']['messages'], 'message'))
        );

        $result = $service->import($path, 'DAY', false, false, 1);
        $this->assertSame(2, $result['new_items']);
        $this->assertSame(2, $result['inventory_rows_updated']);
        $this->assertSame(1, $result['ignored_large_unit_rows']);
        $this->assertSame(1, $result['imported_large_units']);

        $itemModel = model(Item::class);
        $barcodeModel = model(Item_barcode::class);
        $firstItem = $itemModel->get_info_by_id_or_number('ALT-001', false);
        $secondItem = $itemModel->get_info_by_id_or_number('RETAIL-002', false);

        $this->assertIsObject($firstItem);
        $this->assertIsObject($secondItem);
        $this->assertSame((int) $firstItem->item_id, $barcodeModel->getItemId('ALT-001'));
        $this->assertNull($barcodeModel->getItemId('CASE-002'));
        $this->assertSame('', $itemModel->get_info_by_id_or_number('CASE-002', false));
        $firstLargeUnit = model(Item_unit::class)->getLargeUnit((int) $firstItem->item_id);
        $this->assertNotNull($firstLargeUnit);
        $this->assertNull($firstLargeUnit->barcode);
        $this->assertSame('225000.00', $firstLargeUnit->unit_price);
        $this->assertNull(model(Item_unit::class)->getLargeUnit((int) $secondItem->item_id));

        unlink($path);
    }

    public function testLargeBarcodeConflictWithRetailAliasCreatesManualLargeUnit(): void
    {
        $path = $this->createWorkbook([
            ['RETAIL-CONFLICT-001', 'Hàng lẻ một', 'Case', 'Chai', '', '', '', '10000', '', '5000', '1'],
            ['RETAIL-CONFLICT-002', 'Hàng thùng đụng lẻ', 'Case', 'Chai', 'Thùng', 'RETAIL-CONFLICT-001', '24', '10000', '225000', '5000', '1'],
        ]);

        $service = new ItemExcelImportService();
        $preview = $service->preview($path, 'DAY', false, false);

        $this->assertTrue($preview['summary']['can_import']);
        $this->assertSame(0, $preview['summary']['errors']);
        $this->assertSame(1, $preview['summary']['large_units_without_barcode_due_to_conflict']);
        $this->assertStringContainsString(
            'Mã đơn vị lớn bị xung đột nên sẽ không được dùng để quét.',
            implode(' ', array_column($preview['summary']['messages'], 'message'))
        );

        $service->import($path, 'DAY', false, false, 1);
        $item = model(Item::class)->get_info_by_id_or_number('RETAIL-CONFLICT-002', false);
        $this->assertIsObject($item);
        $largeUnit = model(Item_unit::class)->getLargeUnit((int) $item->item_id);
        $this->assertNotNull($largeUnit);
        $this->assertNull($largeUnit->barcode);
        $retailConflictOwner = model(Item::class)->get_info_by_id_or_number('RETAIL-CONFLICT-001', false);
        $this->assertSame('Hàng lẻ một', $retailConflictOwner->name);
        $this->assertNull(model(Item_unit::class)->getLargeUnitByBarcode('RETAIL-CONFLICT-001'));

        unlink($path);
    }

    public function testRealWorkbookPreviewDoesNotBlockOnLargeBarcodeConflicts(): void
    {
        $path = ROOTPATH . 'dev-data/import/Danh_muc_san_pham_clean.xlsx';
        $this->assertFileExists($path);

        $service = new ItemExcelImportService();
        $preview = $service->preview($path, 'DAY', false, false);

        $this->assertSame(1708, $preview['summary']['total_rows']);
        $this->assertSame(2184, $preview['summary']['total_barcodes']);
        $this->assertSame(288, $preview['summary']['multi_barcode_items']);
        $this->assertSame(259, $preview['summary']['valid_large_units']);
        $this->assertSame(1449, $preview['summary']['incomplete_large_units']);
        $this->assertSame(0, $preview['summary']['errors']);
        $this->assertSame(0, $preview['summary']['blocking_errors']);
        $this->assertTrue($preview['summary']['can_import']);
        $this->assertSame(21, $preview['summary']['large_unit_conflicts']);
        $this->assertSame(21, $preview['summary']['large_units_without_barcode_due_to_conflict']);
        $this->assertSame(161, $preview['summary']['manual_large_units']);
        $this->assertSame(98, $preview['summary']['large_units_with_valid_barcode']);
        $this->assertGreaterThanOrEqual(
            8,
            count(array_filter(
                $preview['rows'],
                static fn ($row) => in_array(
                    'Mã đơn vị lớn bị xung đột nên sẽ không được dùng để quét. Đơn vị lớn vẫn được tạo và có thể chọn thủ công tại Thu ngân. Chi tiết: cùng mã thùng dùng cho nhiều dòng: 61, 173, 1241, 1422; mã không đáng tin cậy.',
                    $row['warnings'],
                    true
                ) || (bool) $row['large_unit_barcode_conflict']
            ))
        );
    }

    private function getBusinessUnitId(string $code): int
    {
        return (int) db_connect()
            ->table('business_units')
            ->select('id')
            ->where('code', $code)
            ->get()
            ->getRow()
            ->id;
    }

    private function createWorkbook(array $cleanRows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'items_import_');
        $this->assertIsString($path);
        SimpleZip::create($path, [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Tong_quan" sheetId="1" r:id="rId1"/><sheet name="Danh_muc_clean" sheetId="2" r:id="rId2"/></sheets></workbook>',
            'xl/worksheets/sheet1.xml' => $this->sheetXml([['ignored']]),
            'xl/worksheets/sheet2.xml' => $this->sheetXml(array_merge([[
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
            ]], $cleanRows)),
        ]);

        return $path;
    }

    private function sheetXml(array $rows): string
    {
        $xmlRows = '';
        foreach ($rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $xmlRows .= '<row r="' . $rowNumber . '">';
            foreach ($row as $columnIndex => $value) {
                $cellReference = chr(ord('A') + $columnIndex) . $rowNumber;
                $xmlRows .= '<c r="' . $cellReference . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</t></is></c>';
            }
            $xmlRows .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $xmlRows . '</sheetData></worksheet>';
    }
}
