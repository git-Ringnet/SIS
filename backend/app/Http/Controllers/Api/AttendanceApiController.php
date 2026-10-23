<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\CourseClass;
use App\Models\Student;
use App\Support\AttendanceMarker;
use App\Support\CenterStore;
use App\Support\PagePermissions;
use App\Support\ShiftTime;
use App\Support\SingleLessons;
use App\Support\StudentSchedule;
use App\Support\TeacherLeaves;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $dateStr = $request->input('date', Carbon::today()->toDateString());
        $center = $request->input('center', 'all');
        $classFilter = $request->input('class', 'all');
        $roomFilter = $request->input('room', 'all');
        $teacherFilter = $request->input('teacher', 'all');
        $shiftFilter = $request->input('shift', 'all');
        $search = trim($request->input('search', ''));

        $carbonDate = Carbon::parse($dateStr);
        $dayOfWeekVi = match ($carbonDate->dayOfWeek) {
            0 => 'CN',
            1 => 'Thứ 2',
            2 => 'Thứ 3',
            3 => 'Thứ 4',
            4 => 'Thứ 5',
            5 => 'Thứ 6',
            6 => 'Thứ 7',
        };

        $dayOfWeekEn = match ($carbonDate->dayOfWeek) {
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        };

        // Lấy danh sách điểm danh đã ghi nhận trong ngày này
        $dayAttendances = Attendance::whereDate('attended_at', $dateStr)->get()->groupBy('student_id');

        // Lấy tất cả học viên có thể học trong ngày này (học bù, học lẻ, hoặc theo lịch)
        $singleLessons = SingleLessons::all();
        // Buổi học lẻ trong ngày, theo student_id (dùng phòng / giáo viên / giờ thật của buổi đó); một ngày có thể có nhiều buổi
        $todayLessons = collect($singleLessons)->where('date', $dateStr)->groupBy('student_id');
        $singleStudentIds = $todayLessons->keys()->all();

        // Từ khoá lịch kiểu cũ (schedule_info dạng chữ) để tương thích dữ liệu chưa nhập lịch theo thứ
        $legacyKeywords = match ($carbonDate->dayOfWeek) {
            0 => ['CN', 'Chủ nhật'],
            default => ['T'.($carbonDate->dayOfWeek + 1), 'Thứ '.($carbonDate->dayOfWeek + 1)],
        };

        // Học viên có buổi học lẻ hôm nay, hoặc đang học / tạm dừng và có lịch cố định vào thứ này
        $query = Student::with('course')->where(function ($q) use ($singleStudentIds, $carbonDate, $legacyKeywords) {
            $q->whereIn('id', $singleStudentIds)
                ->orWhere(function ($scheduled) use ($carbonDate, $legacyKeywords) {
                    $scheduled->whereIn('status', ['active', 'paused'])
                        ->where(function ($days) use ($carbonDate, $legacyKeywords) {
                            $days->whereJsonContains('class_days', $carbonDate->dayOfWeek);
                            foreach ($legacyKeywords as $keyword) {
                                $days->orWhere(fn ($legacy) => $legacy->whereNull('class_days')->where('schedule_info', 'like', "%{$keyword}%"));
                            }
                        });
                });
        });

        if ($center !== 'all' && ! empty($center)) {
            $query->where('center', $center);
        }

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('parent_phone', 'like', "%{$search}%");
            });
        }

        $students = $query->get();

        $dayOfWeek = $carbonDate->dayOfWeek;

        // Mỗi ca học trong ngày là một dòng (một học viên có thể có nhiều ca / ngày), cộng buổi học lẻ / học bù
        $periods = TeacherLeaves::all();
        $studentsFormatted = $students->flatMap(function ($st) use ($dayAttendances, $todayLessons, $dateStr, $periods) {
            $records = $dayAttendances->get($st->id, collect());
            $rows = [];

            // Chỉ các ca đã bắt đầu học (không hiện buổi trước "Ngày bắt đầu học" / trước ngày bắt đầu của khóa thêm)
            foreach (StudentSchedule::slotsOnDate($st, $dateStr) as $i => $slot) {
                $rows[] = ['slot_id' => $slot['id'], 'first' => $i === 0, 'room' => $slot['room'], 'teacher' => $slot['teacher'],
                    'shift_time' => StudentSchedule::timeLabel($slot), 'is_single_lesson' => false, 'is_makeup' => false,
                    'off' => StudentSchedule::offReason($st, $dateStr, $slot, $periods)];
            }
            foreach ($todayLessons->get($st->id, []) as $lesson) {
                $rows[] = ['slot_id' => $lesson['id'], 'first' => $rows === [], 'room' => $lesson['room'] ?? null, 'teacher' => $lesson['teacher'] ?? null,
                    'shift_time' => $lesson['time'] ?? null, 'is_single_lesson' => true, 'is_makeup' => ! empty($lesson['is_makeup']), 'off' => null];
            }
            // Dữ liệu cũ chỉ có lịch dạng chữ (schedule_info) → một dòng chung cho cả ngày
            if ($rows === [] && StudentSchedule::slots($st) === [] && ! ($st->join_date && $st->join_date->toDateString() > $dateStr)) {
                $rows[] = ['slot_id' => null, 'first' => true, 'room' => $st->room, 'teacher' => $st->teacher, 'shift_time' => null,
                    'is_single_lesson' => false, 'is_makeup' => false, 'off' => null];
            }

            return array_map(function ($row) use ($st, $records) {
                // Bản ghi theo đúng ca; bản ghi cũ không có ca được tính cho ca đầu tiên trong ngày
                $att = $records->firstWhere('slot_id', $row['slot_id'])
                    ?? ($row['first'] ? $records->first(fn ($r) => $r->slot_id === null) : null);

                // Ca rơi vào ngày trung tâm nghỉ / giáo viên nghỉ (chưa điểm danh) thì không học, không trừ buổi
                $status = match (true) {
                    $st->status === 'paused' => 'paused',
                    $att !== null => $att->status,
                    $row['off'] !== null => 'off',
                    default => 'not_checked',
                };

                $item = $att?->course_item_id
                    ? StudentSchedule::courseItem($st, $att->course_item_id)
                    : StudentSchedule::courseItemForSlot($st, $row['slot_id']);
                $total = (int) ($item['total_sessions'] ?? $st->total_sessions);
                $remaining = (int) ($item['remaining_sessions'] ?? $st->remaining_sessions);
                // Buổi đã trừ buổi thì đã nằm trong số buổi đã học; chưa điểm danh / nghỉ không trừ buổi thì là buổi kế tiếp
                $deducts = $att?->deductsSession() ?? false;
                $currentSession = min($total, ($total - $remaining) + ($deducts ? 0 : 1));
                $allowedAbsences = (int) ($item['allowed_absences'] ?? $st->allowed_absences ?? 3);

                return [
                    'id' => $st->id,
                    'row_key' => $st->id.'-'.($row['slot_id'] ?? 'day'),
                    'slot_id' => $row['slot_id'],
                    'code' => $st->code,
                    'name' => $st->name,
                    'dob' => $st->dob ? Carbon::parse($st->dob)->format('d/m/Y') : null,
                    'parent_name' => $st->parent_name,
                    'parent_phone' => $st->parent_phone,
                    'parent_zalo' => $st->parent_zalo,
                    'course' => [
                        'id' => $item['course_id'] ?? $st->course?->id,
                        'name' => $item['course_name'] ?? $st->course?->name,
                        'color' => $st->course?->color,
                    ],
                    'center' => $st->center ?: CenterStore::defaultName(),
                    'assigned_class' => $st->assigned_class,
                    'room' => $row['room'],
                    'teacher' => $row['teacher'],
                    'shift_time' => $row['shift_time'],
                    'is_single_lesson' => $row['is_single_lesson'],
                    'is_makeup' => $row['is_makeup'],
                    'off_label' => $row['off'] ? $row['off']['label'].($row['off']['reason'] ? ': '.$row['off']['reason'] : '') : null,
                    'total_sessions' => $total,
                    'remaining_sessions' => $remaining,
                    'current_session' => max(1, $currentSession),
                    // Số buổi đã học (đã trừ): buổi không trừ (nghỉ có phép trong lượt, buổi off) hiện "Đã học x/y"
                    'attended_sessions' => max(0, $total - $remaining),
                    'status' => $status,
                    'deducts_session' => $deducts,
                    'allowed_absences' => $allowedAbsences,
                    'leave_left' => StudentSchedule::leaveLeft($st, $item['id'] ?? null),
                    'student_system_status' => $st->status,
                    'checkin_note' => $att?->note ?: '',
                    // Giáo viên đã báo bài buổi này → nút báo bài hiện màu xanh
                    'has_report' => $att?->hasLessonReport() ?? false,
                    'marked_by' => $att?->marked_by,
                    'is_parent_request' => $att && ($att->marked_by === AttendanceMarker::PARENT_PORTAL || str_contains($att->note ?? '', 'Cổng phụ huynh')),
                ];
            }, $rows);
        })->sortBy(fn ($r) => ($r['shift_time'] ?? '99').'|'.$r['name'])->values();

        // Danh sách lựa chọn cho bộ lọc lấy từ dữ liệu thật (trước khi lọc) gộp với danh sách mặc định
        $classOptions = $studentsFormatted->pluck('assigned_class')->filter()->unique()->sort()->values()->all();
        $roomOptions = $studentsFormatted->pluck('room')->filter()->unique()->all();
        $teacherOptions = $studentsFormatted->pluck('teacher')->filter()->unique()->all();

        $studentsFormatted = $studentsFormatted
            ->when($classFilter !== 'all', fn ($c) => $c->where('assigned_class', $classFilter))
            ->when($roomFilter !== 'all', fn ($c) => $c->where('room', $roomFilter))
            ->when($teacherFilter !== 'all', fn ($c) => $c->where('teacher', $teacherFilter))
            ->when($shiftFilter !== 'all', fn ($c) => $c->filter(fn ($st) => ShiftTime::of($st['shift_time']) === $shiftFilter))
            ->values();

        // 5 Thống kê Metric Pills (Khớp 100% hình tham khảo)
        $totalToday = $studentsFormatted->count();
        $actualPresent = $studentsFormatted->where('status', 'present')->count();
        $pausedCount = $studentsFormatted->where('status', 'paused')->count();
        $absentCount = $studentsFormatted->whereIn('status', ['absent_with_leave', 'absent_without_leave'])->count();
        $notCheckedCount = $studentsFormatted->where('status', 'not_checked')->count();
        $offCount = $studentsFormatted->where('status', 'off')->count();

        $metrics = [
            'total_today' => $totalToday,
            'actual_present' => $actualPresent,
            'paused' => $pausedCount,
            'absent' => $absentCount,
            'not_checked' => $notCheckedCount,
            'off' => $offCount,
        ];

        $centers = CenterStore::names();

        $classes = $classOptions;

        // Phòng học theo màn Trung tâm (+ phòng đang có lịch trong ngày), giáo viên có lịch dạy trong ngày
        $rooms = array_values(array_unique([...CenterStore::roomNames($center), ...$roomOptions]));
        $teachers = array_values($teacherOptions);

        // Danh sách đơn xin nghỉ phép gần đây từ Cổng phụ huynh
        $parentLeaveRequests = Attendance::with(['student.course'])
            ->where('status', 'absent_with_leave')
            ->where(function ($q) {
                $q->where('marked_by', AttendanceMarker::PARENT_PORTAL)
                    ->orWhere('note', 'like', '%Cổng phụ huynh%');
            })
            ->whereDate('attended_at', '>=', Carbon::today()->subDays(7))
            ->orderBy('attended_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn ($att) => [
                'id' => $att->id,
                'student_id' => $att->student_id,
                'student_name' => $att->student?->name,
                'student_code' => $att->student?->code,
                'parent_phone' => $att->student?->parent_phone,
                'parent_name' => $att->student?->parent_name,
                'course_name' => $att->course?->name ?? $att->student?->course?->name,
                'leave_date' => $att->attended_at?->format('d/m/Y'),
                'leave_date_raw' => $att->attended_at?->toDateString(),
                'reason' => str_replace('PH xin nghỉ qua Cổng phụ huynh: ', '', $att->note ?? ''),
                'created_at' => $att->created_at?->format('d/m/Y H:i'),
            ]);

        // Phân trang sau khi đã tính metrics trên toàn bộ học viên trong ngày
        $pagination = null;
        $pageStudents = $studentsFormatted->values()->all();
        if ($this->wantsPagination($request)) {
            $paginator = $this->paginateArray($pageStudents, $request);
            $pageStudents = $paginator->items();
            $pagination = $this->paginationMeta($paginator);
        }

        return response()->json([
            // Giáo viên chỉ xem + ghi báo bài, không điểm danh
            'can_mark' => PagePermissions::canMarkAttendance($request->user()),
            'date' => $dateStr,
            'day_of_week' => $dayOfWeekVi,
            'day_of_week_en' => $dayOfWeekEn,
            'metrics' => $metrics,
            'students' => $pageStudents,
            'parent_leave_requests' => $parentLeaveRequests,
            'pagination' => $pagination,
            'centers' => $centers,
            'classes' => $classes,
            'rooms' => $rooms,
            'teachers' => $teachers,
        ]);
    }

    private function forbidTeacher(Request $request): ?JsonResponse
    {
        return PagePermissions::canMarkAttendance($request->user())
            ? null
            : response()->json(['message' => 'Giáo viên không điểm danh được, giáo vụ / quản lý sẽ điểm danh. Giáo viên chỉ ghi báo bài.'], 403);
    }

    public function markAttendance(Request $request, Student $student): JsonResponse
    {
        if ($denied = $this->forbidTeacher($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'date' => 'required|date',
            'status' => 'required|string|in:present,absent_with_leave,absent_without_leave,not_checked',
            'note' => 'nullable|string',
            // Ca được điểm danh (id ca trong lịch học, id buổi học lẻ, hoặc trống = cả ngày)
            'slot_id' => 'nullable|string|max:60',
        ]);

        $result = AttendanceMarker::mark(
            $student,
            $validated['date'],
            $validated['status'],
            $validated['note'] ?? '',
            $validated['slot_id'] ?? null,
        );

        return response()->json([
            'message' => $result['message'],
            'student' => $student,
            // Nghỉ có phép mà đã hết lượt nghỉ → bị trừ buổi, màn điểm danh hiện cảnh báo
            'deducts_session' => (bool) $result['attendance']?->deducts_session,
        ]);
    }

    /**
     * Ghi báo bài (nhận xét) cho buổi đã được điểm danh, không đổi trạng thái điểm danh. Giáo viên dùng được.
     */
    public function saveNote(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'slot_id' => 'nullable|string|max:60',
            'note' => 'required|string',
        ]);
        $slotId = $validated['slot_id'] ?? null;

        // Bản ghi của đúng ca (bản ghi cũ không có ca được tính cho ca đầu tiên trong ngày)
        $daySlots = StudentSchedule::slotsOn($student, Carbon::parse($validated['date'])->dayOfWeek);
        $records = Attendance::where('student_id', $student->id)->whereDate('attended_at', $validated['date'])->get();
        $attendance = $records->firstWhere('slot_id', $slotId)
            ?? (($slotId === null || ($daySlots[0]['id'] ?? null) === $slotId) ? $records->first(fn ($r) => $r->slot_id === null) : null);

        if (! $attendance) {
            return response()->json(['message' => 'Buổi này chưa được điểm danh. Báo bài sau khi giáo vụ đã điểm danh học viên.'], 422);
        }

        $attendance->update(['note' => $validated['note']]);

        return response()->json(['message' => "Đã lưu báo bài cho {$student->name}.", 'attendance' => $attendance]);
    }

    public function availableGroupSlots(Request $request): JsonResponse
    {
        $dateStr = $request->input('date', Carbon::today()->toDateString());
        $teacher = trim((string) $request->input('teacher', ''));
        $center = $request->input('center', 'all');

        $carbonDate = Carbon::parse($dateStr);
        $dayOfWeek = $carbonDate->dayOfWeek; // 0: CN, 1: T2, ..., 6: T7

        $classes = CourseClass::with('course')->get();

        if ($center !== 'all' && ! empty($center)) {
            $classes = $classes->filter(fn ($c) => empty($c->center) || $c->center === $center);
        }

        $slots = [];

        foreach ($classes as $class) {
            $schedules = $class->schedules ?? [];
            if (! is_array($schedules)) {
                continue;
            }

            foreach ($schedules as $idx => $sched) {
                $schedDay = isset($sched['day']) ? (int) $sched['day'] : null;
                if ($schedDay !== $dayOfWeek) {
                    continue;
                }

                $schedTeacher = trim((string) ($sched['teacher'] ?? $class->teacher ?? ''));
                if ($teacher !== '' && $schedTeacher !== '' && mb_strtolower($schedTeacher) !== mb_strtolower($teacher)) {
                    continue;
                }

                // Kiểm tra sĩ số lớp
                $currentCount = $class->students_count;
                $capacity = $class->capacity;
                if ($capacity && $currentCount >= $capacity) {
                    continue; // Lớp đã đầy
                }

                $timeLabel = trim(($sched['start'] ?? '').' - '.($sched['end'] ?? ''));
                if ($timeLabel === '-') {
                    $timeLabel = 'Theo ca lớp';
                }

                $slotId = 'c'.$class->id.'-'.$dayOfWeek.'-'.$idx;
                $label = $timeLabel.' — Lớp '.$class->name.($class->course ? ' ('.$class->course->name.')' : '').' ['.$currentCount.'/'.($capacity ?: '∞').' HV]';

                $slots[] = [
                    'id' => $slotId,
                    'class_id' => $class->id,
                    'class_name' => $class->name,
                    'course_name' => $class->course?->name,
                    'time' => $timeLabel,
                    'start' => $sched['start'] ?? null,
                    'end' => $sched['end'] ?? null,
                    'teacher' => $schedTeacher,
                    'room' => $class->room,
                    'label' => $label,
                    'current_students' => $currentCount,
                    'capacity' => $capacity,
                ];
            }
        }

        return response()->json([
            'slots' => $slots,
            'total' => count($slots),
        ]);
    }

    public function scheduleSingleLesson(Request $request): JsonResponse
    {
        if ($denied = $this->forbidTeacher($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'date' => 'required|date',
            'center' => 'nullable|string',
            'course_id' => 'nullable',
            'room' => 'nullable|string',
            'teacher' => 'nullable|string',
            'time' => 'nullable|string',
            'merged_slot_id' => 'nullable|string',
            'merged_class_id' => 'nullable',
            'note' => 'nullable|string',
            'is_makeup' => 'nullable|boolean',
        ]);

        SingleLessons::add([
            'id' => uniqid('lesson_'),
            'student_id' => (int) $validated['student_id'],
            'date' => $validated['date'],
            'center' => $validated['center'] ?? null,
            'course_id' => $validated['course_id'] ?? null,
            'room' => $validated['room'] ?? null,
            'teacher' => $validated['teacher'] ?? null,
            'time' => $validated['time'] ?? '18:00 - 19:00',
            'merged_slot_id' => $validated['merged_slot_id'] ?? null,
            'merged_class_id' => $validated['merged_class_id'] ?? null,
            'is_merged' => ! empty($validated['merged_slot_id']),
            // Buổi học bù (bù cho buổi nghỉ / ngày trung tâm off): cổng phụ huynh hiển thị là "Buổi bù"
            'is_makeup' => (bool) ($validated['is_makeup'] ?? false),
            'note' => $validated['note'] ?? 'Buổi học lẻ / học bù',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $student = Student::find($validated['student_id']);

        return response()->json([
            'message' => "Đã đặt buổi học lẻ ngày {$validated['date']} cho học viên {$student->name}!",
        ], 201);
    }

    /**
     * Xóa buổi học lẻ / học bù đặt nhầm. Buổi đã điểm danh phải hoàn tác trước để trả lại buổi đã trừ.
     */
    public function destroySingleLesson(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->forbidTeacher($request)) {
            return $denied;
        }

        $lesson = SingleLessons::find($id);
        if (! $lesson) {
            return response()->json(['message' => 'Không tìm thấy buổi học lẻ / học bù này.'], 404);
        }

        if (Attendance::where('student_id', $lesson['student_id'])->where('slot_id', $id)->exists()) {
            return response()->json(['message' => 'Buổi này đã được điểm danh. Hoàn tác điểm danh trước khi xóa buổi.'], 422);
        }

        SingleLessons::remove($id);
        $label = ! empty($lesson['is_makeup']) ? 'buổi học bù' : 'buổi học lẻ';
        $student = Student::find($lesson['student_id']);

        return response()->json([
            'message' => "Đã xóa {$label} ngày ".Carbon::parse($lesson['date'])->format('d/m/Y').($student ? " của học viên {$student->name}" : '').'.',
        ]);
    }
}
