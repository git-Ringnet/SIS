<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use App\Support\BrandSettings;
use App\Support\CenterStore;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->input('search');
        $phone = $request->input('phone');
        $courseId = $request->input('course_id');
        $status = $request->input('status', 'all');
        $filterAlert = $request->input('alert');
        $attendanceStatus = $request->input('attendance_status');
        $center = $request->input('center');
        $assignedClass = $request->input('assigned_class');
        $teacher = $request->input('teacher');
        $staff = $request->input('staff');
        $tuitionStatus = $request->input('tuition_status');
        $sessionsFilter = $request->input('sessions_filter');
        $sortBy = $request->input('sort_by', 'newest');

        $query = Student::query()
            ->with(['course', 'latestAttendance', 'latestRetentionLog', 'attendances']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('parent_name', 'like', "%{$search}%")
                    ->orWhere('parent_phone', 'like', "%{$search}%");
            });
        }

        if ($phone) {
            $query->where(function ($q) use ($phone) {
                $q->where('parent_phone', 'like', "%{$phone}%")
                    ->orWhere('parent_zalo', 'like', "%{$phone}%");
            });
        }

        if ($courseId && $courseId !== 'all') {
            $query->where('course_id', $courseId);
        }

        if ($status === 'dropped') {
            // "Đã nghỉ" gồm cả học viên được đánh dấu nghỉ từ màn HV Tạm dừng - Nghỉ
            $query->whereIn('status', ['dropped', 'inactive']);
        } elseif ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($center && $center !== 'all') {
            $query->where('center', 'like', "%{$center}%");
        }

        if ($assignedClass && $assignedClass !== 'all') {
            $query->where('assigned_class', 'like', "%{$assignedClass}%");
        }

        if ($teacher && $teacher !== 'all') {
            $query->where('teacher', 'like', "%{$teacher}%");
        }

        if ($staff && $staff !== 'all') {
            $query->where(function ($q) use ($staff) {
                $q->where('teacher', 'like', "%{$staff}%")
                    ->orWhere('notes', 'like', "%{$staff}%");
            });
        }

        if ($tuitionStatus === 'paid') {
            $query->where(function ($q) {
                $q->whereHas('invoices', fn ($iq) => $iq->where('status', 'paid'))
                    ->orWhere('retention_status', 'renewed');
            });
        } elseif ($tuitionStatus === 'unpaid') {
            $query->where(function ($q) {
                $q->whereDoesntHave('invoices', fn ($iq) => $iq->where('status', 'paid'))
                    ->where('retention_status', '!=', 'renewed');
            });
        }

        if ($sessionsFilter === 'expiring') {
            $query->where('remaining_sessions', '<=', 5);
        } elseif ($sessionsFilter === 'plenty') {
            $query->where('remaining_sessions', '>', 5);
        }

        if ($filterAlert === 'expiring') {
            $query->where('status', 'active')->where('remaining_sessions', '<=', 5);
        } elseif ($filterAlert === 'absence') {
            $query->where('status', 'active')->where('consecutive_absences', '>=', 2);
        }

        if ($attendanceStatus && $attendanceStatus !== 'all') {
            if ($attendanceStatus === 'at_risk') {
                $query->where('consecutive_absences', '>=', 2);
            } elseif ($attendanceStatus === 'attention') {
                $query->where('consecutive_absences', 1);
            } elseif ($attendanceStatus === 'excellent') {
                $query->where('consecutive_absences', 0)->where('absences_used', 0);
            } elseif ($attendanceStatus === 'returned') {
                $query->where('consecutive_absences', 0)->where('absences_used', '>', 0);
            } elseif ($attendanceStatus === 'good') {
                $query->where('consecutive_absences', 0);
            }
        }

        if ($sortBy === 'name_asc') {
            $query->orderBy('name', 'asc');
        } elseif ($sortBy === 'name_desc') {
            $query->orderBy('name', 'desc');
        } elseif ($sortBy === 'sessions_asc') {
            $query->orderBy('remaining_sessions', 'asc');
        } elseif ($sortBy === 'sessions_desc') {
            $query->orderBy('remaining_sessions', 'desc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $courses = Course::where('is_active', true)->get();
        $centers = array_values(array_unique(array_merge(
            CenterStore::names(),
            Student::whereNotNull('center')->where('center', '!=', '')->distinct()->pluck('center')->toArray()
        )));

        $teachers = Student::whereNotNull('teacher')->where('teacher', '!=', '')->distinct()->pluck('teacher')->toArray();
        $staffMembers = [];
        if (Storage::exists('staff_data.json')) {
            $staffData = json_decode(Storage::get('staff_data.json'), true);
            if (! empty($staffData['members'])) {
                foreach ($staffData['members'] as $m) {
                    if (! empty($m['name'])) {
                        $teachers[] = $m['name'];
                        $staffMembers[] = $m['name'];
                    }
                }
            }
        }
        $teachers = array_values(array_unique(array_filter($teachers)));
        $staffMembers = array_values(array_unique(array_filter($staffMembers)));
        if (empty($staffMembers)) {
            $staffMembers = $teachers;
        }

        $classes = Student::whereNotNull('assigned_class')->where('assigned_class', '!=', '')->distinct()->pluck('assigned_class')->values();

        if ($this->wantsPagination($request)) {
            $paginator = $query->paginate($this->perPage($request));

            return response()->json([
                'students' => $paginator->items(),
                'pagination' => $this->paginationMeta($paginator),
                'courses' => $courses,
                'centers' => $centers,
                'teachers' => $teachers,
                'staff_members' => $staffMembers,
                'classes' => $classes,
            ]);
        }

        return response()->json([
            'students' => $query->get(),
            'courses' => $courses,
            'centers' => $centers,
            'teachers' => $teachers,
            'staff_members' => $staffMembers,
            'classes' => $classes,
        ]);
    }

    public function show(Student $student): JsonResponse
    {
        $student->load([
            'course',
            'attendances' => fn ($q) => $q->orderBy('attended_at', 'desc'),
            'retentionLogs' => fn ($q) => $q->orderBy('contact_date', 'desc'),
            'invoices' => fn ($q) => $q->orderBy('created_at', 'desc'),
        ]);

        $courses = Course::where('is_active', true)->get();

        return response()->json([
            'student' => $student,
            'courses' => $courses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'dob' => 'nullable|date',
            'gender' => 'required|string|in:Nam,Nữ,Khác',
            'parent_name' => 'required|string|max:255',
            'parent_phone' => 'required|string|max:20',
            'parent_zalo' => 'nullable|string|max:20',
            'parent_email' => 'nullable|email|max:255',
            'course_id' => 'required|exists:courses,id',
            'total_sessions' => 'required|integer|min:1',
            'status' => 'required|string|in:active,paused,dropped',
            'join_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'create_invoice' => 'nullable|boolean',
            'invoice_amount' => 'nullable|numeric|min:0',
            ...$this->scheduleRules(),
        ]);

        $code = Student::nextCode();

        $student = Student::create([
            'code' => $code,
            'name' => $validated['name'],
            'dob' => $validated['dob'] ?? null,
            'gender' => $validated['gender'],
            'parent_name' => $validated['parent_name'],
            'parent_phone' => $validated['parent_phone'],
            'parent_zalo' => $validated['parent_zalo'] ?? $validated['parent_phone'],
            'parent_email' => $validated['parent_email'] ?? null,
            'course_id' => $validated['course_id'],
            'total_sessions' => $validated['total_sessions'],
            'remaining_sessions' => $validated['total_sessions'],
            'status' => $validated['status'],
            'join_date' => $validated['join_date'] ?? Carbon::today(),
            'notes' => $validated['notes'] ?? null,
            'center' => $validated['center'] ?? CenterStore::defaultName(),
            ...$this->scheduleAttributes($validated),
        ]);

        if (! empty($validated['create_invoice'])) {
            $course = Course::find($validated['course_id']);
            $amount = $validated['invoice_amount'] ?? ($course ? $course->price : 0);

            Invoice::create([
                'invoice_code' => Invoice::uniqueCode('HD-'.date('Ym').'-'.str_pad($student->id, 3, '0', STR_PAD_LEFT)),
                'student_id' => $student->id,
                'course_id' => $student->course_id,
                'amount' => $amount,
                'sessions_added' => $student->total_sessions,
                'payment_method' => 'bank_transfer',
                'status' => 'paid',
                'paid_at' => Carbon::now(),
                'note' => 'Học phí nhập học ban đầu',
                'recorded_by' => 'Admin Thu Ngân',
            ]);
        }

        $student->load(['course', 'latestAttendance', 'latestRetentionLog']);

        return response()->json([
            'message' => "Thêm học viên {$student->name} thành công!",
            'student' => $student,
        ], 201);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'dob' => 'nullable|date',
            'gender' => 'required|string|in:Nam,Nữ,Khác',
            'parent_name' => 'required|string|max:255',
            'parent_phone' => 'required|string|max:20',
            'parent_zalo' => 'nullable|string|max:20',
            'parent_email' => 'nullable|email|max:255',
            'course_id' => 'required|exists:courses,id',
            'total_sessions' => 'required|integer|min:1',
            'remaining_sessions' => 'required|integer|min:0',
            'status' => 'required|string|in:active,paused,dropped,inactive,completed',
            // Có thể là nhiều trạng thái ghép bằng dấu phẩy, ví dụ "invoice_sent,following_up"
            'retention_status' => ['required', 'string', function ($attribute, $value, $fail) {
                $allowed = ['normal', 'invoice_sent', 'following_up', 'renewed'];
                if (array_diff(array_map('trim', explode(',', $value)), $allowed)) {
                    $fail('Trạng thái tái ký không hợp lệ.');
                }
            }],
            'notes' => 'nullable|string',
            ...$this->scheduleRules(),
        ]);

        $student->update([...$validated, ...$this->scheduleAttributes($validated)]);
        $student->load(['course', 'latestAttendance', 'latestRetentionLog']);

        return response()->json([
            'message' => "Cập nhật học viên {$student->name} thành công!",
            'student' => $student,
        ]);
    }

    public function destroy(Student $student): JsonResponse
    {
        $name = $student->name;
        $student->delete();

        return response()->json([
            'message' => "Đã xóa học viên {$name}!",
        ]);
    }

    /**
     * Lịch học cố định hằng tuần: các thứ học, giờ học, giáo viên, phòng.
     */
    private function scheduleRules(): array
    {
        return [
            'center' => 'nullable|string|max:255',
            'class_days' => 'nullable|array',
            'class_days.*' => 'integer|between:0,6',
            'class_start_time' => 'nullable|date_format:H:i',
            'class_end_time' => 'nullable|date_format:H:i|after:class_start_time',
            'teacher' => 'nullable|string|max:255',
            'room' => 'nullable|string|max:100',
        ];
    }

    /**
     * Chuẩn hoá lịch học và tạo dòng mô tả schedule_info (ví dụ "T3, T5 • 17:30 - 18:30").
     */
    private function scheduleAttributes(array $validated): array
    {
        $teacherRoom = array_intersect_key($validated, array_flip(['teacher', 'room']));

        if (! array_key_exists('class_days', $validated)) {
            return $teacherRoom;
        }

        $schedule = new Student([
            'class_days' => array_values(array_unique(array_map('intval', $validated['class_days'] ?? []))),
            'class_start_time' => $validated['class_start_time'] ?? null,
            'class_end_time' => $validated['class_end_time'] ?? null,
        ]);

        return [
            ...$teacherRoom,
            'class_days' => $schedule->class_days,
            'class_start_time' => $schedule->class_start_time,
            'class_end_time' => $schedule->class_end_time,
            'schedule_info' => $schedule->scheduleSummary() ?? '1 buổi/tuần',
        ];
    }

    public function recordAttendance(Request $request, Student $student): JsonResponse
    {
        if ($student->remaining_sessions <= 0) {
            return response()->json([
                'error' => "Học viên {$student->name} đã hết buổi học! Vui lòng gia hạn thêm khóa học.",
            ], 422);
        }

        // Mỗi ngày chỉ trừ 1 buổi, tránh bấm 2 lần hoặc điểm danh lại ở màn Điểm danh
        $alreadyPresentToday = Attendance::where('student_id', $student->id)
            ->where('status', 'present')
            ->whereDate('attended_at', Carbon::today())
            ->exists();
        if ($alreadyPresentToday) {
            return response()->json([
                'error' => "Học viên {$student->name} đã được điểm danh có mặt hôm nay.",
            ], 422);
        }

        $attendedCount = $student->total_sessions - $student->remaining_sessions + 1;

        $attendance = Attendance::create([
            'student_id' => $student->id,
            'course_id' => $student->course_id,
            'session_number' => $attendedCount,
            'attended_at' => Carbon::now(),
            'status' => 'present',
            'note' => $request->input('note', "Điểm danh buổi #{$attendedCount}"),
            'marked_by' => 'Giáo viên / Admin',
        ]);

        $student->decrement('remaining_sessions');
        $student->consecutive_absences = 0;
        $student->save();
        $student->load(['course', 'latestAttendance', 'latestRetentionLog']);

        return response()->json([
            'message' => "Điểm danh thành công cho {$student->name}. Còn lại {$student->remaining_sessions} buổi.",
            'student' => $student,
            'attendance' => $attendance,
        ]);
    }

    public function undoAttendance(Student $student): JsonResponse
    {
        $latestAttendance = Attendance::where('student_id', $student->id)
            ->where('status', 'present')
            ->latest('attended_at')
            ->first();

        if ($latestAttendance) {
            $latestAttendance->delete();
            $student->increment('remaining_sessions');
            $student->save();
            $student->load(['course', 'latestAttendance', 'latestRetentionLog']);

            return response()->json([
                'message' => "Đã hoàn tác điểm danh. Số buổi hiện tại: {$student->remaining_sessions}.",
                'student' => $student,
            ]);
        }

        return response()->json(['error' => 'Không tìm thấy lịch sử điểm danh phù hợp.'], 404);
    }

    public function recordAbsence(Request $request, Student $student): JsonResponse
    {
        $isWithLeave = $request->boolean('with_leave', false);

        $attendance = Attendance::create([
            'student_id' => $student->id,
            'course_id' => $student->course_id,
            'session_number' => ($student->total_sessions - $student->remaining_sessions),
            'attended_at' => Carbon::now(),
            'status' => $isWithLeave ? 'absent_with_leave' : 'absent_without_leave',
            'note' => $request->input('note', $isWithLeave ? 'Nghỉ có phép' : 'Nghỉ không phép'),
            'marked_by' => 'Giáo viên / Admin',
        ]);

        $student->increment('consecutive_absences');
        $student->save();
        $student->load(['course', 'latestAttendance', 'latestRetentionLog']);

        return response()->json([
            'message' => "Đã ghi nhận vắng học cho {$student->name} (Vắng liên tiếp: {$student->consecutive_absences} buổi).",
            'student' => $student,
            'attendance' => $attendance,
        ]);
    }

    public function addSupplies(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'delivery_date' => 'required|date',
            'center' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'nullable|numeric|min:0',
        ]);

        $currentSupplies = $student->supplies ?? [];
        foreach ($validated['items'] as $item) {
            $currentSupplies[] = [
                'id' => uniqid('sup_'),
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'price' => $item['price'] ?? 0,
                'delivery_date' => $validated['delivery_date'],
                'center' => $validated['center'] ?? $student->center ?? CenterStore::defaultName(),
                'status' => 'delivered',
                'created_at' => Carbon::now()->toIso8601String(),
            ];
        }

        $student->supplies = $currentSupplies;
        $student->save();

        return response()->json([
            'message' => "Đã thêm học cụ cho học viên {$student->name} thành công!",
            'student' => $student,
            'supplies' => $currentSupplies,
        ]);
    }

    public function updateCourseProgress(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'total_sessions' => 'nullable|integer|min:1',
            'remaining_sessions' => 'nullable|integer|min:0',
            'absences_used' => 'nullable|integer|min:0',
            'allowed_absences' => 'nullable|integer|min:0',
            'assigned_class' => 'nullable|string',
            'schedule_info' => 'nullable|string',
        ]);

        $student->update($validated);

        return response()->json([
            'message' => "Cập nhật tiến độ học viên {$student->name} thành công!",
            'student' => $student,
        ]);
    }

    public function tuitionInvoicePdf(Student $student): Response
    {
        $student->load('course');

        $invoiceCode = 'HD-'.now()->year.'-'.($student->code ?? $student->id).'-'.now()->month;

        $pdf = Pdf::loadView('pdf.tuition-invoice', [
            'student' => $student,
            'invoiceCode' => $invoiceCode,
            'issuedAt' => now()->format('d/m/Y'),
            'amount' => $student->course?->price ?? 0,
            'brand' => BrandSettings::brand(),
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true);

        return $pdf->stream("{$invoiceCode}.pdf");
    }

    /**
     * Tải file Excel mẫu nhập học viên theo định dạng SenyApp.
     */
    public function downloadTemplate(): StreamedResponse
    {
        $columns = [
            'full_name' => ['Họ tên học viên (bắt buộc)', 'VÕ MINH AN'],
            'birthday' => ['Ngày sinh, định dạng DD/MM/YYYY', '15/03/2015'],
            'parent_name' => ['Tên phụ huynh', 'NGUYỄN HOÀNG KIM ANH'],
            'parent_phone' => ['SĐT phụ huynh', '0908168815'],
            'parent_email' => ['Email phụ huynh', 'parent@email.com'],
            'student_phone' => ['SĐT học viên / Zalo', ''],
            'address' => ['Địa chỉ', '123 Lê Lợi'],
            'enrollment_date' => ['Ngày nhập học, định dạng DD/MM/YYYY', '01/01/2026'],
            'notes' => ['Ghi chú', 'Loves painting'],
            'status' => ['Trạng thái: active | paused | stopped', 'active'],
            'center_name' => ['Tên trung tâm', CenterStore::defaultName()],
            'schedules' => ['Lịch học, phân cách bằng dấu ; (vd: Thứ 2 15:00; Thứ 4 17:00)', 'Thứ 2 15:00; Thứ 4 17:00'],
            'courses' => ['Khóa học, phân cách bằng dấu ;', 'Foundation 1; Acrylic'],
        ];

        $spreadsheet = new Spreadsheet;

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Học viên');
        $sheet->fromArray([array_keys($columns), array_column($columns, 1)], null, 'A1', true);

        $lastCol = Coordinate::stringFromColumnIndex(count($columns));
        // Định dạng Text cho toàn bộ cột để Excel không tự đổi ngày / mất số 0 đầu SĐT
        $sheet->getStyle("A1:{$lastCol}1000")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E11D48']],
        ]);
        $sheet->freezePane('A2');
        foreach (range(1, count($columns)) as $i) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        $guide = $spreadsheet->createSheet();
        $guide->setTitle('Hướng dẫn');
        $guide->fromArray([['Cột', 'Mô tả'], ...array_map(
            fn ($key, $col) => [$key, $col[0]],
            array_keys($columns),
            $columns,
        )]);
        $guide->getStyle('A1:B1')->getFont()->setBold(true);
        $guide->getColumnDimension('A')->setAutoSize(true);
        $guide->getColumnDimension('B')->setAutoSize(true);

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'students_template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Nhập danh sách học viên từ file Excel (.xlsx/.xls) hoặc CSV theo đúng chuẩn template.
     */
    public function importCsv(Request $request): JsonResponse
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
        ]);

        $file = $request->file('csv_file');
        if (! $file) {
            return response()->json(['message' => 'Không tìm thấy file tải lên!'], 400);
        }

        try {
            $rows = $this->readImportRows($file);
        } catch (\Throwable) {
            return response()->json(['message' => 'Không thể đọc file! Vui lòng dùng file Excel hoặc CSV theo mẫu.'], 400);
        }

        // Tìm dòng header, bỏ qua các dòng ghi chú bắt đầu bằng #
        $header = null;
        while (($line = array_shift($rows)) !== null) {
            if (empty(array_filter($line))) {
                continue;
            }
            if (isset($line[0]) && str_starts_with(trim($line[0]), '#')) {
                continue;
            }
            $header = array_map(fn ($h) => trim(strtolower((string) $h)), $line);
            break;
        }

        if (! $header) {
            return response()->json(['message' => 'File không có hàng tiêu đề hợp lệ!'], 400);
        }

        $colIndex = array_flip($header);
        $importedCount = 0;

        $defaultCourse = Course::where('is_active', true)->first();
        if (! $defaultCourse) {
            $defaultCourse = Course::firstOrCreate([
                'name' => 'Khóa học tiêu chuẩn',
            ], [
                'code' => 'KH-001',
                'price' => 1500000,
                'default_sessions' => 12,
                'is_active' => true,
            ]);
        }

        foreach ($rows as $row) {
            if (empty(array_filter($row))) {
                continue;
            }

            $fullName = trim($row[$colIndex['full_name'] ?? 0] ?? '');
            if (empty($fullName) || str_starts_with($fullName, '#')) {
                continue;
            }

            $rawDob = trim($row[$colIndex['birthday'] ?? 1] ?? '');
            $dob = $this->parseDate($rawDob);

            $parentName = trim($row[$colIndex['parent_name'] ?? 2] ?? '');
            if (empty($parentName)) {
                $parentName = 'Phụ huynh '.$fullName;
            }

            $parentPhone = trim($row[$colIndex['parent_phone'] ?? 3] ?? '');
            if (empty($parentPhone)) {
                $parentPhone = '0900000000';
            }

            $parentEmail = trim($row[$colIndex['parent_email'] ?? 4] ?? '') ?: null;
            $studentPhone = trim($row[$colIndex['student_phone'] ?? 5] ?? '') ?: null;
            $address = trim($row[$colIndex['address'] ?? 6] ?? '') ?: null;

            $rawEnrollment = trim($row[$colIndex['enrollment_date'] ?? 7] ?? '');
            $joinDate = $this->parseDate($rawEnrollment) ?: Carbon::today()->toDateString();

            $notes = trim($row[$colIndex['notes'] ?? 8] ?? '') ?: null;

            $rawStatus = trim(strtolower($row[$colIndex['status'] ?? 9] ?? ''));
            $status = match ($rawStatus) {
                'active', 'hoạt động', 'đang học' => 'active',
                'paused', 'tạm dừng' => 'paused',
                'stopped', 'dropped', 'đã nghỉ', 'nghỉ học' => 'dropped',
                default => 'active',
            };

            $centerName = trim($row[$colIndex['center_name'] ?? 10] ?? '') ?: CenterStore::defaultName();
            $schedulesStr = trim($row[$colIndex['schedules'] ?? 11] ?? '');
            $coursesStr = trim($row[$colIndex['courses'] ?? 12] ?? '');

            // Course mapping
            $courseId = $defaultCourse->id;
            if (! empty($coursesStr)) {
                $courseNames = preg_split('/[;,]/', $coursesStr);
                $firstCourseName = trim($courseNames[0] ?? '');
                if (! empty($firstCourseName)) {
                    $matchedCourse = Course::where('name', 'like', "%{$firstCourseName}%")->first();
                    if ($matchedCourse) {
                        $courseId = $matchedCourse->id;
                    } else {
                        $newCourse = Course::create([
                            'code' => 'KH-'.strtoupper(substr(md5($firstCourseName), 0, 4)),
                            'name' => $firstCourseName,
                            'price' => 1500000,
                            'default_sessions' => 12,
                            'is_active' => true,
                        ]);
                        $courseId = $newCourse->id;
                    }
                }
            }

            $scheduleData = $this->parseSchedules($schedulesStr);
            $code = Student::nextCode();

            Student::create([
                'code' => $code,
                'name' => $fullName,
                'dob' => $dob,
                'gender' => 'Nam',
                'parent_name' => $parentName,
                'parent_phone' => $parentPhone,
                'parent_zalo' => $studentPhone ?: $parentPhone,
                'parent_email' => $parentEmail,
                'address' => $address,
                'course_id' => $courseId,
                'total_sessions' => 12,
                'remaining_sessions' => 12,
                'status' => $status,
                'join_date' => $joinDate,
                'notes' => $notes,
                'center' => $centerName,
                ...$scheduleData,
            ]);

            $importedCount++;
        }

        return response()->json([
            'message' => "Đã nhập thành công {$importedCount} học viên vào hệ thống!",
            'imported_count' => $importedCount,
        ]);
    }

    /**
     * Đọc toàn bộ dòng của file import thành mảng chuỗi (hỗ trợ .xlsx, .xls, .csv).
     *
     * @return list<list<string>>
     */
    private function readImportRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($file->getRealPath())->getSheet(0);

            return array_map(
                fn ($row) => array_map(fn ($value) => trim((string) $value), $row),
                $sheet->toArray(null, true, false),
            );
        }

        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            throw new \RuntimeException('Cannot open file');
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
     * Parse ngày tháng từ chuỗi CSV (hỗ trợ DD/MM/YYYY, DD-MM-YYYY, YYYY-MM-DD).
     */
    private function parseDate(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = trim($value);

        try {
            // Ô ngày trong Excel bị lưu dạng số serial (vd: 42078)
            if (is_numeric($value) && $value > 0 && $value < 80000) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            }

            if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $m)) {
                return Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->toDateString();
            }

            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse lịch học từ định dạng: "Thứ 2 15:00; Thứ 4 17:00" hoặc tiếng Anh.
     */
    private function parseSchedules(?string $schedulesStr): array
    {
        if (empty($schedulesStr)) {
            return [
                'class_days' => null,
                'class_start_time' => null,
                'class_end_time' => null,
                'schedule_info' => '1 buổi/tuần',
            ];
        }

        $dayMap = [
            'chủ nhật' => 0, 'cn' => 0, 'sunday' => 0, 'sun' => 0,
            'thứ 2' => 1, 'thu 2' => 1, 't2' => 1, 'monday' => 1, 'mon' => 1,
            'thứ 3' => 2, 'thu 3' => 2, 't3' => 2, 'tuesday' => 2, 'tue' => 2,
            'thứ 4' => 3, 'thu 4' => 3, 't4' => 3, 'wednesday' => 3, 'wed' => 3,
            'thứ 5' => 4, 'thu 5' => 4, 't5' => 4, 'thursday' => 4, 'thu' => 4,
            'thứ 6' => 5, 'thu 6' => 5, 't6' => 5, 'friday' => 5, 'fri' => 5,
            'thứ 7' => 6, 'thu 7' => 6, 't7' => 6, 'saturday' => 6, 'sat' => 6,
        ];

        $days = [];
        $startTime = null;

        $parts = preg_split('/[;,]/', $schedulesStr);
        if ($parts) {
            foreach ($parts as $part) {
                $part = trim($part);
                if (empty($part)) {
                    continue;
                }

                $lower = mb_strtolower($part, 'UTF-8');
                foreach ($dayMap as $name => $dayNum) {
                    if (str_contains($lower, $name)) {
                        $days[] = $dayNum;
                        break;
                    }
                }

                if (! $startTime && preg_match('/(\d{1,2}:\d{2})/', $part, $m)) {
                    $startTime = str_pad($m[1], 5, '0', STR_PAD_LEFT);
                }
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        $endTime = null;
        if ($startTime) {
            try {
                $endTime = Carbon::createFromFormat('H:i', $startTime)->addHour()->format('H:i');
            } catch (\Throwable) {
                $endTime = null;
            }
        }

        $schedule = new Student([
            'class_days' => ! empty($days) ? $days : null,
            'class_start_time' => $startTime,
            'class_end_time' => $endTime,
        ]);

        return [
            'class_days' => ! empty($days) ? $days : null,
            'class_start_time' => $startTime,
            'class_end_time' => $endTime,
            'schedule_info' => $schedule->scheduleSummary() ?: $schedulesStr,
        ];
    }
}
