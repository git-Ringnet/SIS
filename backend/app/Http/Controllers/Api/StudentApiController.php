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
            ->with(['course', 'latestAttendance', 'latestRetentionLog', 'attendances', 'latestPaidInvoice', 'pendingInvoice']);

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
            // Phụ huynh đã đóng học phí lúc đăng ký chưa; chưa đóng thì phiếu ở trạng thái chờ thu (công nợ)
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

        if (! empty($validated['create_invoice'])) {
            $amount = $validated['invoice_amount'] ?? ($course ? $course->price : 0);
            $paid = (bool) ($validated['invoice_paid'] ?? false);

            Invoice::create([
                'invoice_code' => Invoice::nextInvoiceCodeForCenter($student->center),
                'student_id' => $student->id,
                'course_id' => $student->course_id,
                'center' => $student->center,
                'amount' => $amount,
                'sessions_added' => $student->total_sessions,
                'payment_method' => 'bank_transfer',
                // Chưa đóng: không tính doanh thu, cổng phụ huynh hiện "Chưa thanh toán" cho tới khi xác nhận đã thu
                'status' => $paid ? 'paid' : 'pending',
                'paid_at' => $paid ? Carbon::now() : null,
                'note' => $paid ? 'Học phí nhập học ban đầu' : 'Học phí nhập học ban đầu (chờ thu)',
                'recorded_by' => 'Admin Thu Ngân',
            ]);
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

        $student->update([...$validated, ...$this->scheduleAttributes($validated)]);
        $student->load(['course', 'latestAttendance', 'latestRetentionLog']);

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
        $result = AttendanceMarker::mark($student, Carbon::today()->toDateString(), 'present', $request->input('note'), $slot['id'] ?? null);
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

        // Giao học cụ = xuất kho + ghi doanh thu học cụ (nếu có giá)
        SupplySales::deductStock($validated['items'], $center);
        $revenue = SupplySales::recordRevenue($validated['items'], [
            'date' => $validated['delivery_date'],
            'student_name' => $student->name,
            'center' => $center,
            'payment_method' => $validated['payment_method'] ?? 'Tiền mặt',
            'recorded_by' => $request->user()?->name ?? 'Admin',
            'note' => 'Giao học cụ cho học viên '.$student->code,
        ]);

        // Lưu kèm học cụ trong kho và dòng doanh thu để thu hồi được đúng (nhập lại kho, bớt doanh thu)
        $currentSupplies = $student->supplies ?? [];
        foreach ($validated['items'] as $item) {
            $currentSupplies[] = [
                'id' => uniqid('sup_'),
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'price' => $item['price'] ?? 0,
                'inventory_id' => $item['inventory_id'] ?? null,
                'revenue_id' => $revenue['id'] ?? null,
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
        $refund = SupplySales::reverseRevenue($supply, $quantity, $student->name);

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

        return response()->json([
            'message' => $message,
            'student' => $student,
        ]);
    }

    public function addCourse(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'total_sessions' => 'required|integer|min:1',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'sessions_per_week' => 'nullable|string|max:100',
        ]);

        $course = Course::findOrFail($validated['course_id']);
        $list = $student->registered_courses_list;

        $newCourse = [
            'id' => count($list) + 1,
            'course_id' => (int) $course->id,
            'course_name' => $course->name,
            'total_sessions' => (int) $validated['total_sessions'],
            'attended_sessions' => 0,
            'remaining_sessions' => (int) $validated['total_sessions'],
            'start_date' => $validated['start_date'] ?? Carbon::today()->format('Y-m-d'),
            'end_date' => $validated['end_date'] ?? null,
            'schedule_info' => ! empty($validated['sessions_per_week']) ? "{$validated['sessions_per_week']} buổi/tuần" : '1 buổi/tuần',
            'sessions_per_week' => $validated['sessions_per_week'] ?? '',
            'allowed_absences' => $course->allowed_absences ?? 3,
            'absences_used' => 0,
            'status' => 'active',
        ];

        $list[] = $newCourse;

        $overallTotal = array_sum(array_column($list, 'total_sessions'));
        $overallRemaining = array_sum(array_column($list, 'remaining_sessions'));

        $student->registered_courses = $list;
        $student->course_id = $course->id;
        $student->total_sessions = $overallTotal;
        $student->remaining_sessions = $overallRemaining;
        if (! empty($validated['start_date'])) {
            $student->join_date = $validated['start_date'];
        }
        if (! empty($validated['end_date'])) {
            $student->end_date = $validated['end_date'];
        }
        if (! empty($validated['sessions_per_week'])) {
            $student->schedule_info = "{$validated['sessions_per_week']} buổi/tuần";
        }
        $student->save();
        $student->load(['course', 'latestAttendance', 'latestRetentionLog']);

        return response()->json([
            'message' => "Đã thêm khóa học {$course->name} cho học viên {$student->name} thành công!",
            'student' => $student,
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
            'amount' => $student->course?->price ?? 0,
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
