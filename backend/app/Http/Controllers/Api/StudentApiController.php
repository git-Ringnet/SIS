<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Invoice;
use App\Models\Student;
use App\Support\ActivityLogger;
use App\Support\AttendanceMarker;
use App\Support\BrandSettings;
use App\Support\CenterStore;
use App\Support\PagePermissions;
use App\Support\ScheduleConflicts;
use App\Support\StudentImport;
use App\Support\StudentSchedule;
use App\Support\SupplySales;
use App\Support\Tuition;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
            ->with(['course', 'latestAttendance', 'latestRetentionLog', 'attendances', 'latestPaidInvoice', 'pendingInvoice', 'invoices']);

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
            if ($assignedClass === 'unassigned') {
                $query->where(function ($q) {
                    $q->whereNull('assigned_class')->orWhere('assigned_class', '');
                });
            } else {
                $query->where('assigned_class', 'like', "%{$assignedClass}%");
            }
        }

        if ($teacher && $teacher !== 'all') {
            if ($teacher === 'unassigned') {
                $query->where(function ($q) {
                    $q->whereNull('teacher')->orWhere('teacher', '');
                });
            } else {
                $query->where('teacher', 'like', "%{$teacher}%");
            }
        }

        if ($staff && $staff !== 'all') {
            if ($staff === 'unassigned') {
                $query->where(function ($q) {
                    $q->whereNull('teacher')->orWhere('teacher', '');
                });
            } else {
                $query->where(function ($q) use ($staff) {
                    $q->where('teacher', 'like', "%{$staff}%")
                        ->orWhere('notes', 'like', "%{$staff}%");
                });
            }
        }

        // Đã / chưa thanh toán theo công nợ học phí + học cụ (cùng cách tính với Hóa đơn và Cổng phụ huynh)
        if ($tuitionStatus === 'paid' || $tuitionStatus === 'unpaid') {
            $owing = Tuition::studentIdsWithDebt();
            $tuitionStatus === 'paid' ? $query->whereNotIn('id', $owing) : $query->whereIn('id', $owing);
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

        $teachers = Student::whereNotNull('teacher')->where('teacher', '!=', '')->distinct()->pluck('teacher')
            ->flatMap(fn ($t) => array_map('trim', explode(',', $t)))->filter()->unique()->values()->toArray();
        $staffMembers = [];
        if (Storage::exists('staff_data.json')) {
            $staffData = json_decode(Storage::get('staff_data.json'), true);
            if (! empty($staffData['members'])) {
                foreach ($staffData['members'] as $m) {
                    if (! empty($m['name'])) {
                        // Chỉ thành viên vai trò Giáo viên mới vào danh sách giáo viên
                        if (str_contains($m['role'] ?? '', 'Giáo viên')) {
                            $teachers[] = $m['name'];
                        }
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

        $classes = [];
        $courseClasses = CourseClass::with('course')->orderBy('name')->get();

        foreach ($courseClasses as $cc) {
            $displayName = $cc->course ? "{$cc->name} — {$cc->course->name}" : $cc->name;
            $classes[] = [
                'id' => $cc->name,
                'name' => $displayName,
            ];
        }

        $studentClasses = Student::whereNotNull('assigned_class')
            ->where('assigned_class', '!=', '')
            ->distinct()
            ->pluck('assigned_class');

        foreach ($studentClasses as $sc) {
            if (! collect($classes)->contains('id', $sc)) {
                $classes[] = [
                    'id' => $sc,
                    'name' => $sc,
                ];
            }
        }

        if ($this->wantsPagination($request)) {
            $paginator = $query->paginate($this->perPage($request));
            // Ngày bắt đầu / kết thúc từng khóa (cùng cách tính với Cổng phụ huynh) cho thẻ khóa học
            foreach ($paginator->items() as $st) {
                $st->setAttribute('course_dates', collect($st->registered_courses_list)
                    ->mapWithKeys(fn ($item) => [$item['id'] => $st->courseItemDates($item)]));
                $st->setAttribute('course_warnings', StudentSchedule::continuationWarnings($st));
                $this->attachTuition($st);
            }

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

        $students = $query->get();
        foreach ($students as $st) {
            $st->setAttribute('course_dates', collect($st->registered_courses_list)
                ->mapWithKeys(fn ($item) => [$item['id'] => $st->courseItemDates($item)]));
            $st->setAttribute('course_warnings', StudentSchedule::continuationWarnings($st));
            $this->attachTuition($st);
        }

        return response()->json([
            'students' => $students,
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

        // Công nợ học phí / học cụ theo khóa (nút Học phí); trang chi tiết vẫn trả danh sách phiếu thu
        $summary = Tuition::summary($student, $student->invoices);
        $student->setAttribute('tuition', ['debt' => $summary['debt'], 'paid' => $summary['paid'], 'fee' => $summary['fee'],
            'courses' => $summary['courses'], 'supplies' => $summary['supplies']]);

        // Kèm lớp học của từng khóa để gán lớp ở trang chi tiết học viên
        $courses = Course::with('classes')->where('is_active', true)->get();

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
            'address' => 'nullable|string|max:255',
            'course_id' => 'required|exists:courses,id',
            'total_sessions' => 'required|integer|min:1',
            'status' => 'required|string|in:active,paused,dropped',
            'join_date' => 'nullable|date',
            'center' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'create_invoice' => 'nullable|boolean',
            'invoice_amount' => 'nullable|numeric|min:0',
            // Phụ huynh đã đóng học phí lúc đăng ký chưa; chưa đóng thì khóa là khoản còn nợ (không lập phiếu)
            'invoice_paid' => 'nullable|boolean',
            ...$this->scheduleRules(),
        ]);

        $code = Student::nextCode();
        $course = Course::find($validated['course_id']);

        $student = Student::create([
            'code' => $code,
            'name' => $validated['name'],
            'dob' => $validated['dob'] ?? null,
            'gender' => $validated['gender'],
            'parent_name' => $validated['parent_name'],
            'parent_phone' => $validated['parent_phone'],
            'parent_zalo' => $validated['parent_zalo'] ?? $validated['parent_phone'],
            'parent_email' => $validated['parent_email'] ?? null,
            'address' => $validated['address'] ?? null,
            'course_id' => $validated['course_id'],
            'total_sessions' => $validated['total_sessions'],
            'remaining_sessions' => $validated['total_sessions'],
            // Số buổi được nghỉ có phép theo khóa (1 tháng = 1 buổi, 3 tháng = 3 buổi...)
            'allowed_absences' => $course?->allowed_absences ?? 3,
            'status' => $validated['status'],
            'join_date' => $validated['join_date'] ?? Carbon::today(),
            'notes' => $validated['notes'] ?? null,
            'center' => $validated['center'] ?? CenterStore::defaultName(),
            ...$this->scheduleAttributes($validated),
        ]);

        // Học phí khóa đầu tiên là khoản phải thu (giá khóa). Phụ huynh đã đóng lúc đăng ký thì lập hóa đơn thanh toán;
        // chưa đóng thì không lập phiếu nào, khóa hiện "còn nợ" ở trang Học viên / Hóa đơn / Cổng phụ huynh.
        // Người không có quyền trang Hóa đơn (giáo vụ) không lập được hóa đơn: khóa hiện "còn nợ"
        if (! empty($validated['create_invoice']) && ! empty($validated['invoice_paid']) && self::canRecordPayment($request)) {
            $item = $student->registered_courses_list[0] ?? null;
            $price = Tuition::coursePrice($student->course_id);
            $amount = min((float) ($validated['invoice_amount'] ?? $price), $price ?: (float) ($validated['invoice_amount'] ?? 0));
            if ($item && $amount > 0) {
                Invoice::create([
                    'invoice_code' => Invoice::nextInvoiceCodeForCenter($student->center),
                    'student_id' => $student->id,
                    'course_id' => $student->course_id,
                    'course_item_id' => (int) $item['id'],
                    'center' => $student->center,
                    'amount' => $amount,
                    'lines' => [[
                        'type' => 'course', 'name' => $item['course_name'] ?? 'Khóa học', 'amount' => $amount, 'debt_before' => $price ?: $amount,
                        'course_item_id' => (int) $item['id'], 'course_id' => $student->course_id, 'fee' => $price ?: $amount,
                        'discount' => 0, 'total_sessions' => (int) ($item['total_sessions'] ?? 0),
                    ]],
                    'sessions_added' => 0,
                    'payment_method' => 'bank_transfer',
                    'status' => 'paid',
                    'paid_at' => Carbon::now(),
                    'note' => 'Học phí nhập học ban đầu',
                    'recorded_by' => $request->user()?->name ?? 'Admin',
                ]);
            }
        }

        $student->load(['course', 'latestAttendance', 'latestRetentionLog']);

        return response()->json([
            'message' => "Thêm học viên {$student->name} thành công!",
            'student' => $student,
            'warnings' => ScheduleConflicts::warningsFor($student),
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
            'address' => 'nullable|string|max:255',
            'join_date' => 'nullable|date',
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
            'center' => 'nullable|string|max:255',
            ...$this->scheduleRules(),
        ]);

        $slotsBefore = StudentSchedule::slots($student);
        $student->update([...$validated, ...$this->scheduleAttributes($validated)]);
        // Sửa thứ của một ca đã có điểm danh: buổi đã điểm danh vẫn thuộc ca của đúng thứ ngày đó
        if (StudentSchedule::slots($student) != $slotsBefore) {
            StudentSchedule::relinkAttendanceSlots($student);
        }

        // Đồng bộ lại registered_courses khi lịch học thay đổi:
        // cập nhật số buổi/tuần và mô tả lịch cho các khóa không chọn ca riêng (schedule_ids rỗng).
        // Lịch không đổi hoặc bị xóa hết thì giữ nguyên số buổi/tuần đã nhập tay.
        $slots = StudentSchedule::slots($student);
        $slotCount = count($slots);
        $courses = $student->registered_courses;
        if ($slotCount > 0 && $slots != $slotsBefore && ! empty($courses) && is_array($courses)) {
            $changed = false;
            foreach ($courses as $key => $cItem) {
                if (empty($cItem['schedule_ids'])) {
                    $courses[$key]['schedule_info'] = "{$slotCount} buổi/tuần";
                    $courses[$key]['sessions_per_week'] = (string) $slotCount;
                    $changed = true;
                }
            }
            if ($changed) {
                $student->registered_courses = $courses;
                $student->save();
            }
        }

        $student->load(['course', 'latestAttendance', 'latestRetentionLog']);
        $student->setAttribute('course_dates', collect($student->registered_courses_list)
            ->mapWithKeys(fn ($item) => [$item['id'] => $student->courseItemDates($item)]));

        return response()->json([
            'message' => "Cập nhật học viên {$student->name} thành công!",
            'student' => $student,
            'warnings' => ScheduleConflicts::warningsFor($student),
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
     * Lịch học cố định hằng tuần: danh sách ca (thứ + giờ + giáo viên + phòng), một ngày có thể nhiều ca.
     * Vẫn nhận dạng cũ (class_days + một khung giờ) để tương thích.
     */
    private function scheduleRules(): array
    {
        return [
            'center' => 'nullable|string|max:255',
            'schedules' => 'nullable|array|max:21',
            'schedules.*.id' => 'nullable|string|max:40',
            'schedules.*.day' => 'required|integer|between:0,6',
            'schedules.*.start' => 'required|date_format:H:i',
            'schedules.*.end' => 'nullable|date_format:H:i',
            'schedules.*.teacher' => 'nullable|string|max:255',
            'schedules.*.room' => 'nullable|string|max:100',
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
        if (array_key_exists('schedules', $validated)) {
            $slots = StudentSchedule::normalize($validated['schedules'] ?? []);
        } elseif (array_key_exists('class_days', $validated)) {
            $slots = StudentSchedule::fromLegacy(
                array_values(array_unique(array_map('intval', $validated['class_days'] ?? []))),
                $validated['class_start_time'] ?? null,
                $validated['class_end_time'] ?? null,
                $validated['teacher'] ?? null,
                $validated['room'] ?? null,
            );
        } else {
            return array_intersect_key($validated, array_flip(['teacher', 'room']));
        }

        foreach ($slots as $slot) {
            if ($slot['end'] && $slot['end'] <= $slot['start']) {
                throw ValidationException::withMessages([
                    'schedules' => "Ca {$slot['start']}: giờ kết thúc phải sau giờ bắt đầu.",
                ]);
            }
        }

        $legacy = StudentSchedule::legacyColumns($slots);

        return [
            ...$legacy,
            'schedules' => $slots ?: null,
            'schedule_info' => $legacy['schedule_info'] ?? '1 buổi/tuần',
        ];
    }

    public function recordAttendance(Request $request, Student $student): JsonResponse
    {
        if (! PagePermissions::canMarkAttendance($request->user())) {
            return response()->json(['message' => 'Giáo viên không điểm danh được, giáo vụ / quản lý sẽ điểm danh.'], 403);
        }

        if ($student->remaining_sessions <= 0) {
            return response()->json([
                'error' => "Học viên {$student->name} đã hết buổi học! Vui lòng gia hạn thêm khóa học.",
            ], 422);
        }

        // Điểm danh ca kế tiếp chưa điểm danh của hôm nay (một ngày có thể nhiều ca); hết ca thì báo đã điểm danh
        [$slot, $allDone] = StudentSchedule::nextUnmarkedSlotToday($student);
        if ($allDone) {
            return response()->json([
                'error' => "Học viên {$student->name} đã được điểm danh đủ các ca hôm nay.",
            ], 422);
        }

        // Cùng quy tắc trừ buổi với trang Điểm danh
        $result = AttendanceMarker::mark($student, Carbon::today()->toDateString(), 'present', $request->input('note'), $slot['id'] ?? null,
            ($request->user()?->name ?: 'Giáo viên / Admin').' (Điểm danh nhanh)');
        $student->load(['course', 'latestAttendance', 'latestRetentionLog']);

        return response()->json([
            'message' => "Điểm danh thành công cho {$student->name}. Còn lại {$student->remaining_sessions} buổi.",
            'student' => $student,
            'attendance' => $result['attendance'],
        ]);
    }

    public function undoAttendance(Request $request, Student $student): JsonResponse
    {
        if (! PagePermissions::canMarkAttendance($request->user())) {
            return response()->json(['message' => 'Giáo viên không điểm danh được, giáo vụ / quản lý sẽ điểm danh.'], 403);
        }

        $latestAttendance = Attendance::where('student_id', $student->id)
            ->where('status', 'present')
            ->latest('attended_at')
            ->first();

        if ($latestAttendance) {
            AttendanceMarker::mark($student, $latestAttendance->attended_at->toDateString(), 'not_checked', null, $latestAttendance->slot_id);
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
        if (! PagePermissions::canMarkAttendance($request->user())) {
            return response()->json(['message' => 'Giáo viên không điểm danh được, giáo vụ / quản lý sẽ điểm danh.'], 403);
        }

        $isWithLeave = $request->boolean('with_leave', false);

        // Ghi vắng cho ca kế tiếp chưa điểm danh hôm nay, cùng quy tắc trừ buổi với trang Điểm danh
        // (có phép trong số buổi được nghỉ: không trừ buổi; không phép / hết lượt nghỉ: trừ 1 buổi)
        [$slot, $allDone] = StudentSchedule::nextUnmarkedSlotToday($student);
        if ($allDone && $slot === null && StudentSchedule::slotsOn($student, Carbon::today()->dayOfWeek) !== []) {
            return response()->json([
                'error' => "Học viên {$student->name} đã được điểm danh đủ các ca hôm nay.",
            ], 422);
        }

        $result = AttendanceMarker::mark(
            $student,
            Carbon::today()->toDateString(),
            $isWithLeave ? 'absent_with_leave' : 'absent_without_leave',
            $request->input('note') ?: ($isWithLeave ? 'Nghỉ có phép' : 'Vắng không phép'),
            $slot['id'] ?? null,
            ($request->user()?->name ?: 'Giáo viên / Admin').' (Điểm danh nhanh)',
        );
        $student->load(['course', 'latestAttendance', 'latestRetentionLog']);

        return response()->json([
            'message' => $result['message']." (Vắng liên tiếp: {$student->consecutive_absences} buổi).",
            'student' => $student,
            'attendance' => $result['attendance'],
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
            'items.*.inventory_id' => 'nullable|integer',
            'payment_method' => 'nullable|string|max:50',
        ]);

        $center = $validated['center'] ?? $student->center ?? CenterStore::defaultName();

        // Không cho giao quá số lượng còn trong kho của trung tâm
        if ($errors = SupplySales::stockErrors($validated['items'], $center)) {
            return response()->json([
                'message' => 'Không đủ tồn kho: '.implode(' ', $errors),
                'errors' => ['items' => $errors],
            ], 422);
        }

        // Giao học cụ = xuất kho; tiền học cụ là khoản phải thu của học viên (công nợ / doanh thu dự kiến),
        // chỉ thành doanh thu thực tế khi lập hóa đơn thanh toán ở trang Hóa đơn
        SupplySales::deductStock($validated['items'], $center);

        // Lưu kèm học cụ trong kho để thu hồi được đúng (nhập lại kho)
        $currentSupplies = $student->supplies ?? [];
        foreach ($validated['items'] as $item) {
            $currentSupplies[] = [
                'id' => uniqid('sup_'),
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'price' => $item['price'] ?? 0,
                'inventory_id' => $item['inventory_id'] ?? null,
                'delivery_date' => $validated['delivery_date'],
                'center' => $center,
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

    /**
     * Thu hồi học cụ giao nhầm (toàn bộ hoặc một phần): bỏ khỏi danh sách học cụ của học viên,
     * nhập lại kho và bớt doanh thu học cụ tương ứng. Ghi nhật ký hoạt động để tra lại.
     */
    public function returnSupply(Request $request, Student $student, string $supplyId): JsonResponse
    {
        $supplies = $student->supplies ?? [];
        $index = collect($supplies)->search(fn ($s) => ($s['id'] ?? null) === $supplyId);
        if ($index === false) {
            return response()->json(['message' => 'Không tìm thấy học cụ đã giao.'], 404);
        }

        $supply = $supplies[$index];
        // Đã thu tiền học cụ này trên hóa đơn: sửa / hủy hóa đơn trước để không lệch tiền
        $paidRow = collect(Tuition::supplies($student))->firstWhere('supply_id', (string) $supplyId);
        if (empty($supply['revenue_id']) && $paidRow && $paidRow['paid'] > 0) {
            return response()->json(['message' => "Học cụ {$supply['name']} đã thu tiền trên hóa đơn, hãy sửa hoặc hủy hóa đơn trước khi thu hồi."], 422);
        }
        $validated = $request->validate([
            'quantity' => 'nullable|integer|min:1|max:'.max(1, (int) $supply['quantity']),
            'reason' => 'nullable|string|max:255',
        ]);
        $quantity = (int) ($validated['quantity'] ?? $supply['quantity']);

        if ($quantity >= (int) $supply['quantity']) {
            unset($supplies[$index]);
        } else {
            $supplies[$index]['quantity'] = (int) $supply['quantity'] - $quantity;
        }
        $student->supplies = array_values($supplies);
        $student->save();

        $center = $supply['center'] ?? $student->center;
        $restocked = SupplySales::restock([...$supply, 'quantity' => $quantity], $center);
        // Chỉ học cụ giao theo cách cũ (ghi doanh thu ngay lúc giao) mới phải bớt doanh thu
        $refund = ! empty($supply['revenue_id']) ? SupplySales::reverseRevenue($supply, $quantity, $student->name) : 0;

        ActivityLogger::log('updated', 'student', $student->name, $student->id, ActivityLogger::diff(
            ['note' => "Đã giao {$supply['name']} x{$supply['quantity']} ngày {$supply['delivery_date']}"],
            ['note' => "Thu hồi {$supply['name']} x{$quantity}".(! empty($validated['reason']) ? " ({$validated['reason']})" : '')],
        ), $center);

        $message = "Đã thu hồi {$supply['name']} x{$quantity} của {$student->name}.";
        $message .= $restocked ? ' Đã nhập lại kho.' : ' Học cụ không còn trong kho nên chưa nhập lại kho, hãy kiểm tra màn Học cụ.';
        if ($refund > 0) {
            $message .= ' Đã bớt '.number_format($refund, 0, ',', '.').'đ doanh thu học cụ.';
        }

        return response()->json([
            'message' => $message,
            'student' => $student,
            'supplies' => $student->supplies,
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

        // Gán lớp: lớp phải có thật (màn Khóa học → Lớp học) và chưa đủ sĩ số
        if (! empty($validated['assigned_class']) && $validated['assigned_class'] !== $student->assigned_class) {
            $class = CourseClass::where('name', $validated['assigned_class'])->first();
            if (! $class) {
                return response()->json(['message' => 'Lớp học không tồn tại.'], 422);
            }
            if ($class->isFull($student->id)) {
                return response()->json([
                    'message' => "Lớp {$class->name} đã đủ sĩ số ({$class->capacity}/{$class->capacity}). Hãy chọn lớp khác hoặc tăng sĩ số lớp.",
                ], 422);
            }
        }

        // Vào lớp có khung giờ thì học viên nhận lịch học, giáo viên, phòng của lớp
        $message = "Cập nhật tiến độ học viên {$student->name} thành công!";
        if (! empty($validated['assigned_class']) && isset($class) && $class->hasSlots()) {
            $schedule = $class->studentSchedule();
            if ($schedule) {
                $validated = [...$validated, ...$schedule];
                $message = "Đã gán vào lớp {$class->name} và cập nhật lịch học theo lớp.";
            } else {
                $message = "Đã gán vào lớp {$class->name}. Lớp có khung giờ khác giờ / khác giáo viên nên hãy xếp lịch học cho học viên tay.";
            }
        }

        $student->update($validated);
        if (isset($schedule) && $schedule) {
            StudentSchedule::relinkAttendanceSlots($student);
        }

        return response()->json([
            'message' => $message,
            'student' => $student,
        ]);
    }

    /**
     * Ngày bắt đầu gợi ý cho khóa học tiếp: buổi theo lịch kế tiếp sau buổi cuối dự kiến của khóa đang học
     * (đã tính tạm dừng, ngày nghỉ, buổi bù đã đặt, nghỉ phép).
     */
    public function suggestCourseStart(Request $request, Student $student): JsonResponse
    {
        $itemId = $request->integer('continue_from') ?: null;

        return response()->json(['start_date' => StudentSchedule::nextStartAfter($student, $itemId)?->toDateString()]);
    }

    /**
     * Thêm khóa học cho học viên:
     *  - "parallel" (học song song môn khác): chọn ca riêng cho khóa (các ca chưa thuộc khóa khác), ngày bắt đầu do giáo vụ chọn.
     *  - "continue" (học tiếp / gia hạn): dùng lại ca của khóa đang học, ngày bắt đầu mặc định là buổi kế tiếp sau khóa đó;
     *    lịch dự kiến tự nối sau khóa cũ, điểm danh trừ khóa cũ tới khi hết buổi rồi mới sang khóa mới.
     * Không đổi "Ngày bắt đầu học" / ngày kết thúc của học viên (đó là ngày của khóa đầu tiên).
     * Học phí khóa mới là khoản phải thu (giá khóa); paid_amount > 0 thì lập luôn hóa đơn thanh toán.
     */
    public function addCourse(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'total_sessions' => 'required|integer|min:1',
            'mode' => 'nullable|in:parallel,continue',
            'continue_from' => 'nullable|integer',
            'schedule_ids' => 'nullable|array',
            'schedule_ids.*' => 'string',
            // Học song song: ca mới cho khóa (thứ, giờ, giáo viên, phòng), thêm vào lịch học của học viên
            'new_slots' => 'nullable|array|max:14',
            'new_slots.*.day' => 'required|integer|between:0,6',
            'new_slots.*.start' => 'required|date_format:H:i',
            'new_slots.*.end' => 'nullable|date_format:H:i',
            'new_slots.*.teacher' => 'nullable|string|max:255',
            'new_slots.*.room' => 'nullable|string|max:100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'sessions_per_week' => 'nullable|string|max:100',
            'paid_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|in:bank_transfer,cash,pos,momo',
        ]);

        // Ghi nhận đã thu tiền = lập hóa đơn: chỉ người có quyền trang Hóa đơn (giáo vụ bị chặn trang này)
        if ((float) ($validated['paid_amount'] ?? 0) > 0 && ! self::canRecordPayment($request)) {
            return response()->json(['message' => 'Bạn không có quyền thu học phí / lập hóa đơn. Khóa mới sẽ hiện "còn nợ", chủ trung tâm hoặc quản lý lập hóa đơn sau.'], 403);
        }

        $course = Course::findOrFail($validated['course_id']);
        $list = $student->registered_courses_list;
        $mode = $validated['mode'] ?? 'parallel';
        $slotIds = array_column(StudentSchedule::slots($student), 'id');

        $previous = null;
        if ($mode === 'continue') {
            $previous = collect($list)->firstWhere('id', (int) ($validated['continue_from'] ?? 0))
                ?? collect($list)->filter(fn ($i) => ($i['status'] ?? 'active') !== 'completed')->last()
                ?? collect($list)->last();
            if (! $previous) {
                return response()->json(['message' => 'Học viên chưa có khóa nào để học tiếp.'], 422);
            }
            // Học tiếp dùng lại đúng ca của khóa trước (khóa trước không chọn ca riêng: các ca chung)
            $scheduleIds = StudentSchedule::courseSlotIds($student, $previous);
            $startDate = $validated['start_date'] ?? StudentSchedule::nextStartAfter($student, (int) $previous['id'])?->toDateString();
        } else {
            $scheduleIds = array_values(array_intersect($validated['schedule_ids'] ?? [], $slotIds));
            // Ca đã thuộc khóa khác đang học thì không gán trùng
            $claimed = collect($list)->filter(fn ($i) => ($i['status'] ?? 'active') !== 'completed')
                ->flatMap(fn ($i) => $i['schedule_ids'] ?? [])->all();
            if ($taken = array_values(array_intersect($scheduleIds, $claimed))) {
                return response()->json(['message' => 'Ca '.implode(', ', array_map(fn ($id) => StudentSchedule::timeLabel(StudentSchedule::find($student, $id) ?? []), $taken))
                    .' đang thuộc khóa khác. Học song song cần ca riêng (thêm ca ở phần lịch học của học viên).'], 422);
            }
            // Ca mới nhập ngay trong form thêm khóa: thêm vào lịch học của học viên và gán cho khóa này
            if ($newSlots = $validated['new_slots'] ?? []) {
                $existing = StudentSchedule::slots($student);
                foreach ($newSlots as $slot) {
                    $clash = collect($existing)->first(fn ($e) => (int) $e['day'] === (int) $slot['day'] && $e['start'] === $slot['start']);
                    if ($clash) {
                        return response()->json(['message' => 'Học viên đã có ca '.StudentSchedule::timeLabel($clash).' '
                            .(StudentSchedule::DAY_LABELS[(int) $slot['day']] ?? '').', chọn ca đó ở trên hoặc đổi giờ khác.'], 422);
                    }
                }
                $merged = StudentSchedule::normalize([...$existing, ...$newSlots]);
                $student->fill($this->scheduleAttributes(['schedules' => $merged]));
                $added = array_values(array_diff(array_column($merged, 'id'), array_column($existing, 'id')));
                $scheduleIds = [...$scheduleIds, ...$added];
                $slotIds = array_column($merged, 'id');
            }
            // Form chọn rõ "học song song" thì phải có ca riêng (gọi cũ không gửi mode: giữ cách cũ, dùng ca chung)
            if (($validated['mode'] ?? null) === 'parallel' && $scheduleIds === [] && $slotIds !== []) {
                return response()->json(['message' => 'Học song song cần ca học riêng: chọn ca có sẵn hoặc thêm ca mới cho khóa.'], 422);
            }
            $startDate = $validated['start_date'] ?? Carbon::today()->format('Y-m-d');
        }

        $newCourse = [
            'id' => (int) collect($list)->max('id') + 1,
            'course_id' => (int) $course->id,
            'course_name' => $course->name,
            'total_sessions' => (int) $validated['total_sessions'],
            'attended_sessions' => 0,
            'remaining_sessions' => (int) $validated['total_sessions'],
            'start_date' => $startDate,
            'end_date' => $validated['end_date'] ?? null,
            'schedule_info' => ! empty($validated['sessions_per_week']) ? "{$validated['sessions_per_week']} buổi/tuần" : ($previous['schedule_info'] ?? '1 buổi/tuần'),
            'sessions_per_week' => $validated['sessions_per_week'] ?? ($previous['sessions_per_week'] ?? ''),
            'allowed_absences' => $course->allowed_absences ?? 3,
            'absences_used' => 0,
            'status' => 'active',
            'schedule_ids' => $scheduleIds,
            'mode' => $mode,
            'continues' => $previous ? (int) $previous['id'] : null,
        ];

        // Học tiếp: khóa trước chưa ghi ca riêng thì ghi rõ (hai khóa cùng sở hữu ca, điểm danh trừ khóa còn buổi trước)
        if ($mode === 'continue') {
            $list = array_map(fn ($i) => (int) $i['id'] === (int) $previous['id'] && empty($i['schedule_ids'])
                ? [...$i, 'schedule_ids' => $scheduleIds] : $i, $list);
        }
        // Khóa đầu tiên chưa lưu danh sách ca riêng: ghi lại ca chung để khóa song song không giành ca của nó
        if ($mode === 'parallel' && $scheduleIds !== []) {
            $list = array_map(fn ($i) => empty($i['schedule_ids']) && ($i['status'] ?? 'active') !== 'completed'
                ? [...$i, 'schedule_ids' => array_values(array_diff($slotIds, $scheduleIds))] : $i, $list);
        }
        $list[] = $newCourse;

        $student->registered_courses = $list;
        // Học tiếp: khóa chính của học viên là khóa mới; học song song: giữ khóa chính
        if ($mode === 'continue') {
            $student->course_id = $course->id;
        }
        $student->total_sessions = array_sum(array_column($list, 'total_sessions'));
        $student->remaining_sessions = array_sum(array_column($list, 'remaining_sessions'));
        if ($mode === 'continue') {
            $student->retention_status = 'renewed';
        }
        $student->save();

        // Phụ huynh đóng tiền luôn lúc đăng ký khóa: lập hóa đơn thanh toán cho khóa mới (không quá học phí)
        $price = Tuition::coursePrice($course->id);
        $paid = min((float) ($validated['paid_amount'] ?? 0), $price > 0 ? $price : (float) ($validated['paid_amount'] ?? 0));
        if ($paid > 0) {
            Invoice::create([
                'invoice_code' => Invoice::nextInvoiceCodeForCenter($student->center),
                'student_id' => $student->id,
                'course_id' => $course->id,
                'course_item_id' => $newCourse['id'],
                'center' => $student->center,
                'amount' => $paid,
                'lines' => [[
                    'type' => 'course', 'name' => $course->name, 'amount' => $paid, 'debt_before' => $price ?: $paid,
                    'course_item_id' => $newCourse['id'], 'course_id' => $course->id, 'fee' => $price ?: $paid, 'discount' => 0,
                    'total_sessions' => $newCourse['total_sessions'],
                ]],
                'sessions_added' => 0,
                'payment_method' => $validated['payment_method'] ?? 'bank_transfer',
                'status' => 'paid',
                'paid_at' => Carbon::now(),
                'note' => $mode === 'continue' ? 'Học phí khóa học tiếp' : 'Học phí khóa học thêm',
                'recorded_by' => $request->user()?->name ?? 'Admin',
            ]);
        }

        $student->load(['course', 'latestAttendance', 'latestRetentionLog']);

        return response()->json([
            'message' => ($mode === 'continue' ? "Đã đăng ký học tiếp khóa {$course->name}" : "Đã thêm khóa học {$course->name}")
                ." cho học viên {$student->name}".($startDate ? ', bắt đầu '.Carbon::parse($startDate)->format('d/m/Y') : '').'!',
            'student' => $student,
            'course_item_id' => $newCourse['id'],
        ]);
    }

    public function updateCourseItem(Request $request, Student $student, int $courseIndex): JsonResponse
    {
        $validated = $request->validate([
            'total_sessions' => 'nullable|integer|min:1',
            'attended_sessions' => 'nullable|integer|min:0',
            'allowed_absences' => 'nullable|integer|min:0',
            'absences_used' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:active,completed,paused',
            // Đổi sang khóa khác (nút bút chì cạnh tên khóa)
            'course_id' => 'nullable|exists:courses,id',
            // Thời gian khóa học (nút "Sửa" cạnh Copy placeholder)
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'sessions_per_week' => 'nullable|integer|min:1|max:14',
            // Các ca của khoá này trên trang Điểm danh (rỗng = dùng chung mọi ca)
            'schedule_ids' => 'nullable|array',
            'schedule_ids.*' => 'string|max:40',
        ], [
            'end_date.after_or_equal' => 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu',
        ]);

        $list = $student->registered_courses_list;
        $found = false;

        foreach ($list as $key => $item) {
            if ($item['id'] == $courseIndex || $key == $courseIndex) {
                if (isset($validated['total_sessions'])) {
                    $list[$key]['total_sessions'] = (int) $validated['total_sessions'];
                }
                if (isset($validated['attended_sessions'])) {
                    $list[$key]['attended_sessions'] = (int) $validated['attended_sessions'];
                }
                $list[$key]['remaining_sessions'] = max(0, (int) $list[$key]['total_sessions'] - (int) ($list[$key]['attended_sessions'] ?? 0));
                if (isset($validated['allowed_absences'])) {
                    $list[$key]['allowed_absences'] = (int) $validated['allowed_absences'];
                }
                if (isset($validated['absences_used'])) {
                    $list[$key]['absences_used'] = (int) $validated['absences_used'];
                }
                if (isset($validated['status'])) {
                    $list[$key]['status'] = $validated['status'];
                } elseif ($list[$key]['remaining_sessions'] <= 0) {
                    $list[$key]['status'] = 'completed';
                }
                if (! empty($validated['course_id'])) {
                    $newCourse = Course::find($validated['course_id']);
                    // Khóa chính của học viên đang là khóa này thì đổi theo
                    if ((int) $student->course_id === (int) ($list[$key]['course_id'] ?? 0)) {
                        $student->course_id = $newCourse->id;
                    }
                    $list[$key]['course_id'] = (int) $newCourse->id;
                    $list[$key]['course_name'] = $newCourse->name;
                }
                if ($request->has('start_date')) {
                    $list[$key]['start_date'] = $validated['start_date'] ?? null;
                }
                if ($request->has('end_date')) {
                    $list[$key]['end_date'] = $validated['end_date'] ?? null;
                }
                if ($request->has('schedule_ids')) {
                    $validIds = array_column(StudentSchedule::slots($student), 'id');
                    $list[$key]['schedule_ids'] = array_values(array_intersect($validated['schedule_ids'] ?? [], $validIds));
                }
                if ($request->has('sessions_per_week')) {
                    $perWeek = $validated['sessions_per_week'] ?? null;
                    $list[$key]['sessions_per_week'] = $perWeek ? (string) $perWeek : '';
                    $list[$key]['schedule_info'] = $perWeek ? "{$perWeek} buổi/tuần" : ($student->schedule_info ?: '1 buổi/tuần');
                }
                $found = true;
                break;
            }
        }

        if (! $found) {
            return response()->json(['error' => 'Không tìm thấy khóa học cần cập nhật.'], 404);
        }

        $student->registered_courses = $list;
        $student->total_sessions = array_sum(array_column($list, 'total_sessions'));
        $student->remaining_sessions = array_sum(array_column($list, 'remaining_sessions'));
        $student->save();
        $student->load(['course', 'latestAttendance', 'latestRetentionLog']);
        $student->setAttribute('course_dates', collect($student->registered_courses_list)
            ->mapWithKeys(fn ($item) => [$item['id'] => $student->courseItemDates($item)]));

        return response()->json([
            'message' => 'Cập nhật khóa học thành công!',
            'student' => $student,
        ]);
    }

    public function deleteCourseItem(Student $student, int $courseIndex): JsonResponse
    {
        $list = $student->registered_courses_list;
        $newList = [];
        foreach ($list as $key => $item) {
            if ($item['id'] != $courseIndex && $key != $courseIndex) {
                $newList[] = $item;
            }
        }

        if (empty($newList)) {
            return response()->json(['error' => 'Không thể xóa tất cả khóa học của học viên.'], 422);
        }

        $student->registered_courses = array_values($newList);
        $student->total_sessions = array_sum(array_column($newList, 'total_sessions'));
        $student->remaining_sessions = array_sum(array_column($newList, 'remaining_sessions'));
        $student->save();
        $student->load(['course', 'latestAttendance', 'latestRetentionLog']);

        return response()->json([
            'message' => 'Đã xóa khóa học thành công!',
            'student' => $student,
        ]);
    }

    public function uploadAvatar(Request $request, Student $student): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,webp|max:3072',
        ]);

        $path = $request->file('avatar')->store('avatars', 'public');
        $avatarUrl = Storage::url($path);

        $student->update(['avatar' => $avatarUrl]);

        return response()->json([
            'message' => 'Tải ảnh đại diện thành công!',
            'avatar' => $avatarUrl,
            'student' => $student,
        ]);
    }

    /**
     * Được ghi nhận thu tiền / lập hóa đơn (không bị chặn trang "Hóa đơn & Thu phí" ở màn Nhân sự).
     */
    private static function canRecordPayment(Request $request): bool
    {
        return ! PagePermissions::isBlocked($request->user(), 'Hóa đơn & Thu phí');
    }

    /**
     * Công nợ học phí / học cụ cho nút Học phí ở danh sách học viên (không gửi kèm cả danh sách phiếu thu).
     */
    private function attachTuition(Student $st): void
    {
        $summary = Tuition::summary($st, $st->invoices);
        $st->setAttribute('tuition', ['debt' => $summary['debt'], 'paid' => $summary['paid'], 'fee' => $summary['fee'],
            'courses' => array_map(fn ($c) => array_intersect_key($c, array_flip(['course_item_id', 'name', 'fee', 'discount', 'paid', 'debt', 'pending_invoice'])), $summary['courses'])]);
        $st->makeHidden('invoices');
    }

    public function tuitionInvoicePdf(Student $student): Response
    {
        $student->load('course');

        $center = CenterStore::findByName($student->center);
        $prefix = CenterStore::prefixForCenter($student->center);
        $invoiceCode = $prefix.'-'.now()->format('Ym').'-'.($student->code ?? $student->id);

        $pdf = Pdf::loadView('pdf.tuition-invoice', [
            'student' => $student,
            'invoiceCode' => $invoiceCode,
            'issuedAt' => now()->format('d/m/Y'),
            // Còn nợ học phí / học cụ thì báo đúng số còn nợ; đã đóng đủ thì báo học phí khóa tiếp theo (giá khóa)
            'amount' => Tuition::summary($student)['debt'] ?: ($student->course?->price ?? 0),
            'center' => $center,
            'brand' => BrandSettings::brand(),
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true);

        return $pdf->stream("{$invoiceCode}.pdf");
    }

    /**
     * Tải file Excel mẫu nhập học viên (có sheet hướng dẫn, cột bắt buộc, danh sách chọn).
     */
    public function downloadTemplate(): StreamedResponse
    {
        return StudentImport::template();
    }

    /**
     * Nhập danh sách học viên từ file Excel (.xlsx/.xls) hoặc CSV theo file mẫu.
     * Trả về số dòng đã nhập, các dòng bị bỏ qua (kèm lý do) và các cảnh báo.
     */
    public function importCsv(Request $request): JsonResponse
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
        ]);

        try {
            $result = StudentImport::import($request->file('csv_file'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return response()->json(['message' => 'Không thể đọc file! Vui lòng dùng file Excel hoặc CSV theo mẫu.'], 400);
        }

        $skipped = count($result['skipped']);
        $message = "Đã nhập thành công {$result['imported_count']} học viên"
            .($skipped ? ", bỏ qua {$skipped} dòng thiếu thông tin" : '').'.';

        return response()->json(['message' => $message, ...$result]);
    }
}
