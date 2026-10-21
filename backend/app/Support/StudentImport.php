<?php

namespace App\Support;

use App\Models\Course;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * File Excel mẫu và nhập danh sách học viên hàng loạt (màn Học viên → Mẫu Excel / Import Excel).
 */
class StudentImport
{
    private const DATA_SHEET = 'Học viên';

    private const GUIDE_SHEET = 'Hướng dẫn';

    private const STATUS_LABELS = ['Đang học', 'Tạm dừng', 'Đã nghỉ'];

    /**
     * Các cột của file mẫu: key nội bộ => [tiêu đề cột, bắt buộc, cách điền, ví dụ, độ rộng].
     * Tiêu đề cũ bằng tiếng Anh (full_name, birthday...) vẫn được nhận khi import.
     */
    private static function columns(): array
    {
        return [
            'full_name' => ['Họ tên học viên', true, 'Họ và tên đầy đủ của học viên.', 'Võ Minh An', 24],
            'parent_phone' => ['SĐT phụ huynh', true, 'Số điện thoại liên hệ chính, giữ số 0 ở đầu. Dùng để nhắn Zalo / gọi điện.', '0908168815', 16],
            'parent_name' => ['Tên phụ huynh', false, 'Để trống sẽ ghi là "Phụ huynh <tên học viên>".', 'Nguyễn Hoàng Kim Anh', 24],
            'gender' => ['Giới tính', false, 'Chọn trong danh sách: Nam, Nữ, Khác. Để trống = Nam.', 'Nam', 10],
            'birthday' => ['Ngày sinh', false, 'Dạng DD/MM/YYYY. Dùng cho màn Sinh nhật.', '15/03/2015', 13],
            'parent_email' => ['Email phụ huynh', false, 'Email liên hệ (nếu có).', 'phuhuynh@gmail.com', 24],
            'student_phone' => ['SĐT Zalo', false, 'Số Zalo nhận tin nhắn nếu khác SĐT phụ huynh. Để trống = dùng SĐT phụ huynh.', '', 14],
            'address' => ['Địa chỉ', false, 'Địa chỉ nhà.', '123 Lê Lợi, Hóc Môn', 26],
            'center_name' => ['Cơ sở', false, 'Chọn trong danh sách cơ sở ở màn Trung tâm. Để trống = cơ sở chính.', CenterStore::defaultName(), 24],
            'courses' => ['Khóa học', false, 'Tên khóa học đang có ở màn Khóa học (chọn trong danh sách). Khóa chưa có sẽ được tạo mới với học phí 0đ, cần vào màn Khóa học cập nhật giá. Để trống = khóa đang mở đầu tiên.', 'Guitar cơ bản', 22],
            'total_sessions' => ['Số buổi', false, 'Tổng số buổi của gói đã đăng ký (số nguyên). Để trống = số buổi mặc định của khóa học.', '12', 9],
            'enrollment_date' => ['Ngày nhập học', false, 'Dạng DD/MM/YYYY. Để trống = ngày import.', '01/10/2026', 14],
            'status' => ['Trạng thái', false, 'Chọn: Đang học, Tạm dừng, Đã nghỉ. Để trống = Đang học.', 'Đang học', 12],
            'schedules' => ['Lịch học', false, 'Mỗi ca học gồm thứ + giờ, các ca cách nhau bằng dấu ; . Thứ viết là Thứ 2 … Thứ 7, CN (hoặc T2 … T7). Giờ dạng HH:MM-HH:MM, mỗi ca có thể giờ khác nhau, một ngày có thể nhiều ca. Để trống = chưa xếp lịch, học viên sẽ không hiện ở màn Điểm danh.', 'Thứ 2 17:30-18:30; CN 09:00-10:00', 30],
            'teacher' => ['Giáo viên', false, 'Tên giáo viên dạy các ca ở cột Lịch học, phải trùng tên tài khoản giáo viên ở màn Nhân sự thì giáo viên mới thấy ca dạy để check-in. Ca khác giáo viên thì sửa lịch trong thẻ học viên sau khi import.', '', 18],
            'room' => ['Phòng học', false, 'Tên phòng của cơ sở (màn Trung tâm → Phòng học).', '', 14],
            'notes' => ['Ghi chú', false, 'Ghi chú thêm về học viên.', 'Thích vẽ màu nước', 26],
        ];
    }

    // ------------------------------------------------------------------ File mẫu

    public static function template(): StreamedResponse
    {
        $columns = self::columns();
        $spreadsheet = new Spreadsheet;

        // Sheet 1: hướng dẫn (mở file là thấy ngay)
        $guide = $spreadsheet->getActiveSheet();
        $guide->setTitle(self::GUIDE_SHEET);

        // Sheet 2: nơi điền dữ liệu
        $data = $spreadsheet->createSheet();
        $data->setTitle(self::DATA_SHEET);
        self::buildDataSheet($data, $columns);
        self::buildGuideSheet($guide, $columns);

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'mau_nhap_hoc_vien.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private static function buildDataSheet(Worksheet $sheet, array $columns): void
    {
        $keys = array_keys($columns);
        $lastCol = Coordinate::stringFromColumnIndex(count($keys));
        $lastRow = 1000;

        foreach ($keys as $i => $key) {
            [$title, $required, $help, , $width] = $columns[$key];
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $required ? "{$title} *" : $title);
            $sheet->getColumnDimension($col)->setWidth($width);
            // Rê chuột vào tiêu đề sẽ thấy cách điền
            $sheet->getComment("{$col}1")->getText()->createTextRun(($required ? 'BẮT BUỘC. ' : 'Không bắt buộc. ').$help);
            $sheet->getComment("{$col}1")->setWidth('260pt')->setHeight('90pt');
            $sheet->getStyle("{$col}1")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => $required ? 'FFFFFF' : '1E293B']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $required ? 'E11D48' : 'E2E8F0']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
        }
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->freezePane('A2');

        // Định dạng Text để Excel không tự đổi ngày / bỏ số 0 đầu SĐT
        $sheet->getStyle("A2:{$lastCol}{$lastRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $colOf = fn ($key) => Coordinate::stringFromColumnIndex(array_search($key, $keys, true) + 1);

        // Danh sách chọn: Giới tính, Trạng thái, Cơ sở (bắt buộc đúng); Khóa học, Giáo viên, Phòng (gợi ý, vẫn gõ khác được)
        self::dropdown($sheet, $colOf('gender'), $lastRow, ['Nam', 'Nữ', 'Khác'], true);
        self::dropdown($sheet, $colOf('status'), $lastRow, self::STATUS_LABELS, true);
        self::dropdown($sheet, $colOf('center_name'), $lastRow, CenterStore::names(), true);
        self::dropdown($sheet, $colOf('courses'), $lastRow, Course::where('is_active', true)->orderBy('name')->pluck('name')->all(), false);
        self::dropdown($sheet, $colOf('teacher'), $lastRow, TeacherName::all(), false);
        self::dropdown($sheet, $colOf('room'), $lastRow, CenterStore::roomNames(), false);
    }

    /**
     * Danh sách chọn trong ô Excel. $strict = false: chỉ gợi ý, vẫn cho gõ giá trị khác.
     */
    private static function dropdown(Worksheet $sheet, string $col, int $lastRow, array $options, bool $strict): void
    {
        $options = array_values(array_filter(array_map(fn ($o) => str_replace(['"', ','], ['', ' '], (string) $o), $options)));
        $formula = '"'.implode(',', $options).'"';
        // Excel giới hạn danh sách gõ trực tiếp 255 ký tự
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
            ->setError($strict ? 'Vui lòng chọn một giá trị trong danh sách.' : 'Giá trị này chưa có trong hệ thống, vẫn có thể nhập.')
            ->setFormula1($formula);
        $validation->setSqref("{$col}2:{$col}{$lastRow}");
    }

    private static function buildGuideSheet(Worksheet $sheet, array $columns): void
    {
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(14);
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

        $put(['HƯỚNG DẪN NHẬP DANH SÁCH HỌC VIÊN'], ['font' => ['bold' => true, 'size' => 15]]);
        $row++;
        $section('Các bước');
        foreach ([
            '1. Chuyển sang sheet "'.self::DATA_SHEET.'" (tab ở góc dưới), mỗi học viên điền một dòng, bắt đầu từ dòng 2. Không sửa / xoá dòng tiêu đề.',
            '2. Cột tiêu đề màu ĐỎ có dấu * là BẮT BUỘC. Cột màu xám không bắt buộc, có thể để trống.',
            '3. Rê chuột vào ô tiêu đề để xem cách điền. Các cột Giới tính, Trạng thái, Cơ sở, Khóa học, Giáo viên, Phòng có danh sách để chọn.',
            '4. Lưu file, vào màn Học viên → Import Excel → chọn file → Bắt đầu Import.',
            '5. Dòng nào thiếu thông tin bắt buộc sẽ bị bỏ qua, hệ thống báo lại số dòng và lý do để sửa rồi import lại các dòng đó.',
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

        $section('Giá trị đang có trong hệ thống');
        $lists = [
            'Cơ sở' => CenterStore::names(),
            'Khóa học' => Course::where('is_active', true)->orderBy('name')->pluck('name')->all(),
            'Giáo viên' => TeacherName::all(),
            'Phòng học' => CenterStore::roomNames(),
            'Trạng thái' => self::STATUS_LABELS,
        ];
        foreach ($lists as $label => $values) {
            $put([$label, '', $values ? implode(', ', $values) : '(chưa có, tạo ở màn tương ứng trước khi import)']);
            $sheet->getStyle('A'.($row - 1))->getFont()->setBold(true);
            $sheet->getStyle('C'.($row - 1))->getAlignment()->setWrapText(true);
        }
        $row++;

        $section('Ví dụ một dòng hoàn chỉnh (không điền vào sheet này)');
        $put(array_map(fn ($c) => $c[0], array_values($columns)), ['font' => ['bold' => true]]);
        $put(array_map(fn ($c) => $c[3], array_values($columns)));

        $sheet->getStyle('A1:D'.$row)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    }

    // ------------------------------------------------------------------ Import

    /**
     * @return array{imported_count: int, skipped: list<array{row: int, reason: string}>, warnings: list<string>}
     */
    public static function import(UploadedFile $file): array
    {
        $rows = self::readRows($file);

        // Tìm dòng tiêu đề (bỏ qua dòng trống / dòng ghi chú bắt đầu bằng #)
        $header = null;
        $rowNumber = 0;
        while (($line = array_shift($rows)) !== null) {
            $rowNumber++;
            if (empty(array_filter($line)) || str_starts_with(trim((string) ($line[0] ?? '')), '#')) {
                continue;
            }
            $header = $line;
            break;
        }
        if (! $header) {
            throw new \RuntimeException('File không có hàng tiêu đề hợp lệ! Hãy dùng đúng file mẫu.');
        }

        $colIndex = self::mapHeader($header);
        if (! isset($colIndex['full_name'])) {
            throw new \RuntimeException('Không tìm thấy cột "Họ tên học viên". Hãy dùng đúng file mẫu, không sửa dòng tiêu đề.');
        }

        $get = fn (array $row, string $key) => isset($colIndex[$key]) ? trim((string) ($row[$colIndex[$key]] ?? '')) : '';
        $centers = CenterStore::names();
        $defaultCourse = Course::where('is_active', true)->orderBy('id')->first();

        $imported = 0;
        $skipped = [];
        $warnings = [];

        foreach ($rows as $row) {
            $rowNumber++;
            if (empty(array_filter($row, fn ($v) => trim((string) $v) !== ''))) {
                continue;
            }

            $name = $get($row, 'full_name');
            if (str_starts_with($name, '#')) {
                continue;
            }
            $phone = preg_replace('/[^\d+]/', '', $get($row, 'parent_phone'));
            $missing = array_filter(['Họ tên học viên' => $name, 'SĐT phụ huynh' => $phone], fn ($v) => $v === '');
            if ($missing) {
                $skipped[] = ['row' => $rowNumber, 'reason' => 'Thiếu '.implode(', ', array_keys($missing))];

                continue;
            }

            // Cơ sở
            $center = $get($row, 'center_name') ?: CenterStore::defaultName();
            if (! in_array($center, $centers, true)) {
                $warnings[] = "Dòng {$rowNumber}: cơ sở \"{$center}\" không có trong hệ thống, đã gán vào ".CenterStore::defaultName().'.';
                $center = CenterStore::defaultName();
            }

            // Khóa học: khóa đầu tiên trong ô; chưa có thì tạo mới (học phí 0đ)
            $courseName = trim(preg_split('/[;,]/', $get($row, 'courses'))[0] ?? '');
            $course = $defaultCourse;
            if ($courseName !== '') {
                $course = Course::whereRaw('LOWER(name) = ?', [mb_strtolower($courseName)])->first()
                    ?? Course::where('name', 'like', "%{$courseName}%")->first();
                if (! $course) {
                    $course = Course::create([
                        'code' => 'KH-'.strtoupper(substr(md5($courseName), 0, 6)),
                        'name' => $courseName,
                        'price' => 0,
                        'default_sessions' => 12,
                        'is_active' => true,
                    ]);
                    $warnings[] = "Đã tạo khóa học mới \"{$courseName}\" với học phí 0đ, hãy vào màn Khóa học cập nhật học phí.";
                }
            }
            if (! $course) {
                $skipped[] = ['row' => $rowNumber, 'reason' => 'Chưa có khóa học nào trong hệ thống, hãy điền cột Khóa học hoặc tạo khóa học trước'];

                continue;
            }

            $sessions = (int) preg_replace('/\D/', '', $get($row, 'total_sessions')) ?: ($course->default_sessions ?: 12);

            $dobRaw = $get($row, 'birthday');
            $dob = self::parseDate($dobRaw);
            if ($dobRaw !== '' && ! $dob) {
                $warnings[] = "Dòng {$rowNumber}: ngày sinh \"{$dobRaw}\" sai định dạng DD/MM/YYYY, đã bỏ trống.";
            }
            $joinRaw = $get($row, 'enrollment_date');
            $joinDate = self::parseDate($joinRaw);
            if ($joinRaw !== '' && ! $joinDate) {
                $warnings[] = "Dòng {$rowNumber}: ngày nhập học \"{$joinRaw}\" sai định dạng DD/MM/YYYY, đã dùng ngày hôm nay.";
            }

            $scheduleRaw = $get($row, 'schedules');
            $schedule = self::parseSchedule($scheduleRaw, $get($row, 'teacher') ?: null, $get($row, 'room') ?: null);
            if ($scheduleRaw !== '' && empty($schedule['class_days'])) {
                $warnings[] = "Dòng {$rowNumber}: không đọc được lịch học \"{$scheduleRaw}\", hãy xếp lịch lại trong thẻ học viên.";
            }

            Student::create([
                'code' => Student::nextCode(),
                'name' => $name,
                'dob' => $dob,
                'gender' => self::gender($get($row, 'gender')),
                'parent_name' => $get($row, 'parent_name') ?: "Phụ huynh {$name}",
                'parent_phone' => $phone,
                'parent_zalo' => preg_replace('/[^\d+]/', '', $get($row, 'student_phone')) ?: $phone,
                'parent_email' => $get($row, 'parent_email') ?: null,
                'address' => $get($row, 'address') ?: null,
                'course_id' => $course->id,
                'total_sessions' => $sessions,
                'remaining_sessions' => $sessions,
                'allowed_absences' => $course->allowed_absences ?? 3,
                'status' => self::status($get($row, 'status')),
                'join_date' => $joinDate ?: Carbon::today()->toDateString(),
                'notes' => $get($row, 'notes') ?: null,
                'center' => $center,
                'teacher' => $get($row, 'teacher') ?: null,
                'room' => $get($row, 'room') ?: null,
                ...$schedule,
            ]);
            $imported++;
        }

        return ['imported_count' => $imported, 'skipped' => $skipped, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Vị trí từng cột theo tiêu đề (nhận cả tiêu đề tiếng Việt của file mẫu mới và tiêu đề tiếng Anh cũ).
     */
    private static function mapHeader(array $header): array
    {
        $normalize = fn ($v) => mb_strtolower(trim(str_replace('*', '', (string) $v)));
        $aliases = [];
        foreach (self::columns() as $key => [$title]) {
            $aliases[$normalize($title)] = $key;
            $aliases[$key] = $key;
        }
        $aliases += ['sđt học viên / zalo' => 'student_phone', 'trung tâm' => 'center_name', 'lịch' => 'schedules'];

        $map = [];
        foreach ($header as $i => $title) {
            if (isset($aliases[$normalize($title)])) {
                $map[$aliases[$normalize($title)]] ??= $i;
            }
        }

        return $map;
    }

    private static function gender(string $value): string
    {
        return match (mb_strtolower($value)) {
            'nữ', 'nu', 'female', 'f' => 'Nữ',
            'khác', 'khac', 'other' => 'Khác',
            default => 'Nam',
        };
    }

    private static function status(string $value): string
    {
        return match (mb_strtolower($value)) {
            'paused', 'tạm dừng', 'bảo lưu' => 'paused',
            'stopped', 'dropped', 'đã nghỉ', 'nghỉ học' => 'dropped',
            default => 'active',
        };
    }

    /**
     * Đọc toàn bộ dòng của file thành mảng chuỗi (.xlsx, .xls, .csv). File Excel ưu tiên sheet "Học viên".
     *
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

        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            throw new \RuntimeException('Không thể đọc file!');
        }
        // Bỏ qua UTF-8 BOM nếu có
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            $rows[] = array_map(fn ($v) => (string) $v, $line);
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Ngày dạng DD/MM/YYYY, DD-MM-YYYY, YYYY-MM-DD, hoặc số serial của Excel.
     */
    public static function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            if (is_numeric($value) && $value > 0 && $value < 80000) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            }
            if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $value, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Lịch học dạng "Thứ 2 09:00-10:00; CN 15:30-16:30" → danh sách ca, mỗi buổi giờ riêng.
     * Buổi không ghi giờ dùng giờ của buổi đứng trước ("T2, T4 17:30-18:30" = cả T2 và T4 lúc 17:30).
     * Nhận Thứ 2 … Thứ 7, T2 … T7, CN, Chủ nhật, tên tiếng Anh. Không ghi giờ kết thúc = học 60 phút.
     */
    public static function parseSchedule(?string $value, ?string $teacher = null, ?string $room = null): array
    {
        if (trim((string) $value) === '') {
            return [];
        }

        $english = ['sunday' => 0, 'sun' => 0, 'monday' => 1, 'mon' => 1, 'tuesday' => 2, 'tue' => 2, 'wednesday' => 3, 'wed' => 3,
            'thursday' => 4, 'fri' => 5, 'friday' => 5, 'saturday' => 6, 'sat' => 6];
        $parts = [];
        foreach (preg_split('/[;,\n]/', $value) as $part) {
            $part = mb_strtolower(trim($part));
            if ($part === '') {
                continue;
            }

            $day = null;
            if (preg_match('/\b(?:thứ|thu|t)\s*([2-7])\b/u', $part, $m)) {
                $day = (int) $m[1] - 1;
            } elseif (preg_match('/\bcn\b|chủ\s*nhật|chu\s*nhat/u', $part)) {
                $day = 0;
            } else {
                foreach ($english as $word => $num) {
                    if (preg_match('/\b'.$word.'\b/', $part)) {
                        $day = $num;
                        break;
                    }
                }
            }

            $start = $end = null;
            if (preg_match('/(\d{1,2})[:hg](\d{2})(?:\s*[-–]\s*(\d{1,2})[:hg](\d{2}))?/u', $part, $t)) {
                $start = sprintf('%02d:%02d', $t[1], $t[2]);
                $end = isset($t[3]) && $t[3] !== '' ? sprintf('%02d:%02d', $t[3], $t[4]) : null;
            }
            $parts[] = ['day' => $day, 'start' => $start, 'end' => $end];
        }

        // Buổi thiếu giờ lấy giờ của buổi kế tiếp có giờ ("T2, T4 17:30"), rồi buổi đứng trước
        $lastTime = null;
        foreach (array_reverse(array_keys($parts)) as $i) {
            if ($parts[$i]['start']) {
                $lastTime = [$parts[$i]['start'], $parts[$i]['end']];
            } elseif ($lastTime) {
                [$parts[$i]['start'], $parts[$i]['end']] = $lastTime;
            }
        }

        $slots = [];
        foreach ($parts as $p) {
            if ($p['day'] === null || ! $p['start']) {
                continue;
            }
            $end = $p['end'] ?: Carbon::createFromFormat('H:i', $p['start'])->addHour()->format('H:i');
            $slots[] = ['day' => $p['day'], 'start' => $p['start'], 'end' => $end, 'teacher' => $teacher, 'room' => $room];
        }

        $slots = StudentSchedule::normalize($slots);
        if ($slots === []) {
            return [];
        }

        return [...StudentSchedule::legacyColumns($slots), 'schedules' => $slots];
    }
}
