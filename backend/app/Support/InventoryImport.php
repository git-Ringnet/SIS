<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel của màn Học cụ: tải danh sách kho, file mẫu có hướng dẫn và nhập hàng loạt.
 * File tải về có cùng cột với file mẫu nên sửa xong có thể nhập lại (trùng mã học cụ = cập nhật).
 */
class InventoryImport
{
    private const PATH = 'inventory_data.json';

    private const DATA_SHEET = 'Học cụ';

    private const GUIDE_SHEET = 'Hướng dẫn';

    public const CATEGORIES = ['Giáo trình', 'Phụ kiện', 'Nhạc cụ', 'Đồng phục & Quà', 'Khác'];

    /**
     * key nội bộ => [tiêu đề cột, bắt buộc, cách điền, ví dụ, độ rộng, là cột tiền/số]
     */
    private static function columns(): array
    {
        return [
            'code' => ['Mã học cụ', false, 'Để trống thì hệ thống tự tạo mã. Nếu mã đã có trong kho thì dòng này sẽ CẬP NHẬT học cụ đó (giá, tồn kho...) thay vì thêm mới.', 'HC-ALFRED-01', 16, false],
            'name' => ['Tên học cụ', true, 'Tên vật phẩm / học cụ.', 'Giáo trình Piano Alfred Tập 1', 32, false],
            'category' => ['Phân loại', false, 'Chọn: '.implode(', ', self::CATEGORIES).'. Để trống = Khác.', 'Giáo trình', 16, false],
            'unit' => ['Đơn vị tính', false, 'Cuốn, Cái, Bộ, Cây... Để trống = Cái.', 'Cuốn', 12, false],
            'center' => ['Trung tâm', false, 'Chọn trong danh sách trung tâm. Để trống = trung tâm chính.', CenterStore::defaultName(), 26, false],
            'cost_price' => ['Giá nhập (VNĐ)', false, 'Chỉ ghi số, không ghi chữ đ. Ví dụ 60000 hoặc 60.000.', '60000', 15, true],
            'sell_price' => ['Giá bán (VNĐ)', false, 'Giá bán cho học viên, dùng để tính doanh thu học cụ.', '120000', 15, true],
            'quantity' => ['Số lượng tồn', false, 'Số lượng đang có trong kho (số nguyên). Để trống = 0.', '20', 13, true],
            'min_threshold' => ['Cảnh báo khi còn ≤', false, 'Tồn kho từ mức này trở xuống sẽ báo sắp hết. Để trống = 3.', '5', 16, true],
            'note' => ['Ghi chú', false, 'Nhà cung cấp, tình trạng...', 'Sách bìa cứng', 26, false],
        ];
    }

    public static function all(): array
    {
        $data = Storage::exists(self::PATH) ? json_decode(Storage::get(self::PATH), true) : [];

        return is_array($data) ? $data : [];
    }

    private static function download(Spreadsheet $spreadsheet, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ------------------------------------------------------------------ Tải danh sách kho

    public static function export(string $center = 'all'): StreamedResponse
    {
        $items = array_values(array_filter(
            self::all(),
            fn ($it) => $center === 'all' || $center === '' || ($it['center'] ?? '') === $center,
        ));

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::DATA_SHEET);
        $columns = self::columns();
        self::header($sheet, $columns, false);

        $keys = array_keys($columns);
        foreach ($items as $r => $it) {
            $values = array_map(fn ($key) => match ($key) {
                'cost_price', 'sell_price' => (float) ($it[$key] ?? 0),
                'quantity' => (int) ($it[$key] ?? 0),
                'min_threshold' => (int) ($it[$key] ?? 3),
                default => (string) ($it[$key] ?? ''),
            }, $keys);
            $sheet->fromArray($values, null, 'A'.($r + 2), true);
        }

        // Dòng tổng giá trị tồn kho
        $lastRow = count($items) + 1;
        if ($items) {
            $totalRow = $lastRow + 2;
            $sheet->setCellValue("A{$totalRow}", 'Tổng giá trị tồn (giá nhập × tồn)');
            $sheet->setCellValue("F{$totalRow}", array_sum(array_map(fn ($it) => ($it['cost_price'] ?? 0) * ($it['quantity'] ?? 0), $items)));
            $sheet->getStyle("A{$totalRow}:F{$totalRow}")->getFont()->setBold(true);
            $sheet->getStyle("F{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        }
        $sheet->getStyle('F2:G'.max(2, $lastRow))->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setAutoFilter('A1:'.Coordinate::stringFromColumnIndex(count($keys)).max(1, $lastRow));

        return self::download($spreadsheet, 'kho_hoc_cu_'.date('Ymd_His').'.xlsx');
    }

    // ------------------------------------------------------------------ File mẫu

    public static function template(): StreamedResponse
    {
        $columns = self::columns();
        $spreadsheet = new Spreadsheet;

        $guide = $spreadsheet->getActiveSheet();
        $guide->setTitle(self::GUIDE_SHEET);
        $data = $spreadsheet->createSheet();
        $data->setTitle(self::DATA_SHEET);
        self::header($data, $columns, true);
        self::buildGuideSheet($guide, $columns);
        $spreadsheet->setActiveSheetIndex(0);

        return self::download($spreadsheet, 'mau_nhap_hoc_cu.xlsx');
    }

    /**
     * Dòng tiêu đề (cột bắt buộc màu đỏ, rê chuột xem cách điền) + danh sách chọn cho file mẫu
     */
    private static function header(Worksheet $sheet, array $columns, bool $withInputHelp): void
    {
        $keys = array_keys($columns);
        $lastRow = 1000;

        foreach ($keys as $i => $key) {
            [$title, $required, $help, , $width, $numeric] = $columns[$key];
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $required ? "{$title} *" : $title);
            $sheet->getColumnDimension($col)->setWidth($width);
            $sheet->getComment("{$col}1")->getText()->createTextRun(($required ? 'BẮT BUỘC. ' : 'Không bắt buộc. ').$help);
            $sheet->getComment("{$col}1")->setWidth('240pt')->setHeight('80pt');
            $sheet->getStyle("{$col}1")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => $required ? 'FFFFFF' : '1E293B']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $required ? 'E11D48' : 'E2E8F0']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            if ($withInputHelp) {
                // Cột chữ để dạng Text (giữ nguyên mã), cột số hiện dấu phân cách hàng nghìn khi gõ 60000
                $sheet->getStyle("{$col}2:{$col}{$lastRow}")->getNumberFormat()
                    ->setFormatCode($numeric ? '#,##0' : NumberFormat::FORMAT_TEXT);
            }
        }
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->freezePane('A2');

        if ($withInputHelp) {
            $colOf = fn ($key) => Coordinate::stringFromColumnIndex(array_search($key, $keys, true) + 1);
            self::dropdown($sheet, $colOf('category'), $lastRow, self::CATEGORIES, false);
            self::dropdown($sheet, $colOf('center'), $lastRow, CenterStore::names(), true);
        }
    }

    private static function dropdown(Worksheet $sheet, string $col, int $lastRow, array $options, bool $strict): void
    {
        $options = array_values(array_filter(array_map(fn ($o) => str_replace(['"', ','], ['', ' '], (string) $o), $options)));
        $formula = '"'.implode(',', $options).'"';
        if ($options === [] || mb_strlen($formula) > 255) {
            return;
        }

        $validation = $sheet->getCell("{$col}2")->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle($strict ? DataValidation::STYLE_STOP : DataValidation::STYLE_INFORMATION)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Giá trị không có trong danh sách')
            ->setError($strict ? 'Vui lòng chọn một giá trị trong danh sách.' : 'Giá trị này chưa có trong danh sách, vẫn có thể nhập.')
            ->setFormula1($formula);
        $validation->setSqref("{$col}2:{$col}{$lastRow}");
    }

    private static function buildGuideSheet(Worksheet $sheet, array $columns): void
    {
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(80);
        $sheet->getColumnDimension('D')->setWidth(30);

        $row = 1;
        $put = function (array $values, array $style = []) use ($sheet, &$row) {
            $sheet->fromArray($values, null, "A{$row}", true);
            if ($style) {
                $sheet->getStyle("A{$row}:D{$row}")->applyFromArray($style);
            }
            $row++;
        };
        $section = fn (string $title) => $put([$title], ['font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'E11D48']]]);

        $put(['HƯỚNG DẪN NHẬP DANH SÁCH HỌC CỤ'], ['font' => ['bold' => true, 'size' => 15]]);
        $row++;
        $section('Các bước');
        foreach ([
            '1. Chuyển sang sheet "'.self::DATA_SHEET.'" (tab ở góc dưới), mỗi học cụ điền một dòng, bắt đầu từ dòng 2. Không sửa / xoá dòng tiêu đề.',
            '2. Cột tiêu đề màu ĐỎ có dấu * là BẮT BUỘC. Cột màu xám có thể để trống.',
            '3. Rê chuột vào ô tiêu đề để xem cách điền. Cột Phân loại và Trung tâm có danh sách để chọn.',
            '4. Muốn sửa hàng loạt: bấm "Tải Excel" ở màn Học cụ, sửa trong file rồi nhập lại. Dòng có Mã học cụ trùng sẽ được cập nhật, không tạo trùng.',
            '5. Lưu file, vào màn Học cụ → Nhập Excel → chọn file → Bắt đầu nhập. Dòng thiếu tên học cụ sẽ bị bỏ qua và được báo lại.',
        ] as $line) {
            $put([$line]);
            $sheet->mergeCells('A'.($row - 1).':D'.($row - 1));
        }
        $row++;

        $section('Mô tả từng cột');
        $put(['Cột', 'Bắt buộc', 'Cách điền', 'Ví dụ'], [
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
        ]);
        foreach ($columns as [$title, $required, $help, $example]) {
            $put([$title, $required ? 'BẮT BUỘC' : 'Không', $help, $example]);
            $sheet->getStyle('C'.($row - 1))->getAlignment()->setWrapText(true);
            if ($required) {
                $sheet->getStyle('A'.($row - 1).':B'.($row - 1))->getFont()->setBold(true)->getColor()->setRGB('E11D48');
            }
        }
        $row++;

        $section('Trung tâm đang có trong hệ thống');
        $put([implode(', ', CenterStore::names())]);
        $row++;

        $section('Ví dụ một dòng hoàn chỉnh (không điền vào sheet này)');
        $put(array_map(fn ($c) => $c[0], array_values($columns)), ['font' => ['bold' => true]]);
        $put(array_map(fn ($c) => $c[3], array_values($columns)));

        $sheet->getStyle('A1:D'.$row)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    }

    // ------------------------------------------------------------------ Nhập

    /**
     * @return array{imported_count: int, updated_count: int, skipped: list<array{row: int, reason: string}>, warnings: list<string>}
     */
    public static function import(UploadedFile $file): array
    {
        $rows = self::readRows($file);

        $header = null;
        $rowNumber = 0;
        while (($line = array_shift($rows)) !== null) {
            $rowNumber++;
            if (! empty(array_filter($line, fn ($v) => $v !== ''))) {
                $header = $line;
                break;
            }
        }
        $colIndex = $header ? self::mapHeader($header) : [];
        if (! isset($colIndex['name'])) {
            throw new \RuntimeException('Không tìm thấy cột "Tên học cụ". Hãy dùng đúng file mẫu, không sửa dòng tiêu đề.');
        }

        $get = fn (array $row, string $key) => isset($colIndex[$key]) ? trim((string) ($row[$colIndex[$key]] ?? '')) : '';
        // Ô số của Excel đọc ra "60000"; gõ tay kiểu "60.000", "60,000 đ" thì bỏ dấu phân cách
        $number = fn (string $v) => match (true) {
            $v === '' => null,
            (bool) preg_match('/^\d{1,3}([.,]\d{3})+$/', $v) => (float) preg_replace('/\D/', '', $v), // 60.000 / 1,200,000
            is_numeric($v) => (float) $v,
            default => (float) preg_replace('/\D/', '', $v),
        };

        $centers = CenterStore::names();
        $items = self::all();
        $maxId = $items ? max(array_column($items, 'id')) : 0;
        $imported = 0;
        $updated = 0;
        $skipped = [];
        $warnings = [];

        foreach ($rows as $row) {
            $rowNumber++;
            if (empty(array_filter($row, fn ($v) => trim((string) $v) !== ''))) {
                continue;
            }

            $name = $get($row, 'name');
            if ($name === '') {
                $skipped[] = ['row' => $rowNumber, 'reason' => 'Thiếu Tên học cụ'];

                continue;
            }

            $center = $get($row, 'center') ?: CenterStore::defaultName();
            if (! in_array($center, $centers, true)) {
                $warnings[] = "Dòng {$rowNumber}: trung tâm \"{$center}\" không có trong hệ thống, đã gán vào ".CenterStore::defaultName().'.';
                $center = CenterStore::defaultName();
            }

            $code = $get($row, 'code');
            $values = [
                'name' => $name,
                'category' => $get($row, 'category') ?: 'Khác',
                'unit' => $get($row, 'unit') ?: 'Cái',
                'center' => $center,
                'cost_price' => $number($get($row, 'cost_price')) ?? 0,
                'sell_price' => $number($get($row, 'sell_price')) ?? 0,
                'quantity' => (int) ($number($get($row, 'quantity')) ?? 0),
                'min_threshold' => (int) ($number($get($row, 'min_threshold')) ?? 3),
                'note' => $get($row, 'note'),
            ];

            // Trùng mã học cụ → cập nhật học cụ đó
            $existing = $code === '' ? false : array_search(mb_strtolower($code), array_map(fn ($it) => mb_strtolower($it['code'] ?? ''), $items), true);
            if ($existing !== false) {
                $items[$existing] = [...$items[$existing], ...$values];
                $updated++;

                continue;
            }

            $items[] = [
                'id' => ++$maxId,
                'code' => $code !== '' ? $code : 'HC-'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
                ...$values,
            ];
            $imported++;
        }

        Storage::put(self::PATH, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return ['imported_count' => $imported, 'updated_count' => $updated, 'skipped' => $skipped, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Vị trí từng cột theo tiêu đề (nhận cả tiêu đề của file CSV cũ)
     */
    private static function mapHeader(array $header): array
    {
        $normalize = fn ($v) => mb_strtolower(trim(preg_replace('/\s*\(.*?\)\s*|\*/u', ' ', (string) $v)));
        $aliases = [];
        foreach (self::columns() as $key => [$title]) {
            $aliases[$normalize($title)] = $key;
        }
        $aliases += [
            'tên vật phẩm' => 'name',
            'cơ sở / trung tâm' => 'center',
            'cơ sở' => 'center',
            'giá nhập' => 'cost_price',
            'giá bán' => 'sell_price',
            'mức cảnh báo tối thiểu' => 'min_threshold',
            'cảnh báo tối thiểu' => 'min_threshold',
        ];

        $map = [];
        foreach ($header as $i => $title) {
            $key = $aliases[$normalize($title)] ?? null;
            if ($key) {
                $map[$key] ??= $i;
            }
        }

        return $map;
    }

    /**
     * @return list<list<string>>
     */
    private static function readRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $book = $reader->load($file->getRealPath());
            $sheet = $book->getSheetByName(self::DATA_SHEET) ?? $book->getSheet(0);

            return array_map(
                fn ($row) => array_map(fn ($value) => trim((string) $value), $row),
                $sheet->toArray(null, true, false),
            );
        }

        // Vẫn nhận file CSV cũ
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            throw new \RuntimeException('Không thể đọc file!');
        }
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            $rows[] = array_map(fn ($v) => trim((string) $v), $line);
        }
        fclose($handle);

        return $rows;
    }
}
