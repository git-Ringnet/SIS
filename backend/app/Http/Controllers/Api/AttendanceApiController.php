<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\CourseClass;
use App\Models\Student;
use App\Support\CenterStore;
use App\Support\ShiftTime;
use App\Support\StudentSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttendanceApiController extends Controller
{
    private function getSingleLessonsPath(): string
    {
        return 'scheduled_single_lessons.json';
    }

    private function loadSingleLessons(): array
    {
        $path = $this->getSingleLessonsPath();
        if (Storage::exists($path)) {
            $data = json_decode(Storage::get($path), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    private function saveSingleLessons(array $lessons): void
    {
        Storage::put($this->getSingleLessonsPath(), json_encode($lessons, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

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
        $singleLessons = $this->loadSingleLessons();
        // Buổi học lẻ trong ngày, theo student_id (dùng phòng / giáo viên / giờ thật của buổi đó)
        $todayLessons = collect($singleLessons)->where('date', $dateStr)->keyBy('student_id');
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
        $studentsFormatted = $students->flatMap(function ($st) use ($dayAttendances, $todayLessons, $dayOfWeek) {
            $records = $dayAttendances->get($st->id, collect());
            $rows = [];

            foreach (StudentSchedule::slotsOn($st, $dayOfWeek) as $i => $slot) {
                $rows[] = ['slot_id' => $slot['id'], 'first' => $i === 0, 'room' => $slot['room'], 'teacher' => $slot['teacher'],
                    'shift_time' => StudentSchedule::timeLabel($slot), 'is_single_lesson' => false];
            }
            if ($lesson = $todayLessons->get($st->id)) {
                $rows[] = ['slot_id' => $lesson['id'], 'first' => $rows === [], 'room' => $lesson['room'] ?? null, 'teacher' => $lesson['teacher'] ?? null,
                    'shift_time' => $lesson['time'] ?? null, 'is_single_lesson' => true];
            }
            // Dữ liệu cũ chỉ có lịch dạng chữ (schedule_info) → một dòng chung cho cả ngày
            if ($rows === []) {
                $rows[] = ['slot_id' => null, 'first' => true, 'room' => $st->room, 'teacher' => $st->teacher, 'shift_time' => null, 'is_single_lesson' => false];
            }

            return array_map(function ($row) use ($st, $records) {
                // Bản ghi theo đúng ca; bản ghi cũ không có ca được tính cho ca đầu tiên trong ngày
                $att = $records->firstWhere('slot_id', $row['slot_id'])
                    ?? ($row['first'] ? $records->first(fn ($r) => $r->slot_id === null) : null);

                $status = match (true) {
                    $st->status === 'paused' => 'paused',
                    $att !== null => $att->status,
                    default => 'not_checked',
                };

                $item = StudentSchedule::courseItemForSlot($st, $row['slot_id']);
                $total = (int) ($item['total_sessions'] ?? $st->total_sessions);
                $remaining = (int) ($item['remaining_sessions'] ?? $st->remaining_sessions);
                $currentSession = min($total, ($total - $remaining) + ($status === 'present' ? 0 : 1));

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
                    'total_sessions' => $total,
                    'remaining_sessions' => $remaining,
                    'current_session' => max(1, $currentSession),
                    'status' => $status,
                    'student_system_status' => $st->status,
                    'checkin_note' => $att?->note ?: '',
                    'marked_by' => $att?->marked_by,
                    'is_parent_request' => $att && ($att->marked_by === 'Phụ huynh (Cổng PH)' || str_contains($att->note ?? '', 'Cổng phụ huynh')),
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

        $metrics = [
            'total_today' => $totalToday,
            'actual_present' => $actualPresent,
            'paused' => $pausedCount,
            'absent' => $absentCount,
            'not_checked' => $notCheckedCount,
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
                $q->where('marked_by', 'Phụ huynh (Cổng PH)')
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

    public function markAttendance(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'status' => 'required|string|in:present,absent_with_leave,absent_without_leave,not_checked',
            'note' => 'nullable|string',
            // Ca được điểm danh (id ca trong lịch học, id buổi học lẻ, hoặc trống = cả ngày)
            'slot_id' => 'nullable|string|max:60',
        ]);

        $dateStr = $validated['date'];
        $newStatus = $validated['status'];
        $note = $validated['note'] ?? '';
        $slotId = $validated['slot_id'] ?? null;

        $daySlots = StudentSchedule::slotsOn($student, Carbon::parse($dateStr)->dayOfWeek);
        $isFirstSlot = $slotId === null || ($daySlots[0]['id'] ?? null) === $slotId;

        // Điểm danh đã có của đúng ca này (bản ghi cũ không có ca được tính cho ca đầu tiên)
        $records = Attendance::where('student_id', $student->id)->whereDate('attended_at', $dateStr)->get();
        $existing = $records->firstWhere('slot_id', $slotId)
            ?? ($isFirstSlot ? $records->first(fn ($r) => $r->slot_id === null) : null);

        $item = $existing?->course_item_id
            ? collect($student->registered_courses_list)->firstWhere('id', $existing->course_item_id)
            : StudentSchedule::courseItemForSlot($student, $slotId);
        $itemId = $item['id'] ?? null;
        $outOfSessions = fn () => (int) ($item['remaining_sessions'] ?? $student->remaining_sessions) <= 0;

        if ($newStatus === 'not_checked') {
            if ($existing) {
                if ($existing->status === 'present') {
                    StudentSchedule::consume($student, $existing->course_item_id ?? $itemId, -1);
                } elseif ($student->consecutive_absences > 0) {
                    $student->consecutive_absences--;
                }
                $existing->delete();
                $student->save();
            }

            return response()->json(['message' => "Đã hoàn tác điểm danh cho {$student->name}.", 'student' => $student]);
        }

        if ($existing) {
            if ($existing->status === 'present' && $newStatus !== 'present') {
                StudentSchedule::consume($student, $existing->course_item_id ?? $itemId, -1);
                $student->consecutive_absences++;
            } elseif ($existing->status !== 'present' && $newStatus === 'present') {
                if ($outOfSessions()) {
                    return response()->json(['message' => 'Học viên đã hết buổi của khóa này!'], 422);
                }
                StudentSchedule::consume($student, $itemId, 1);
                $student->consecutive_absences = 0;
            }

            $existing->status = $newStatus;
            $existing->note = $note ?: $existing->note;
            $existing->slot_id ??= $slotId;
            $existing->course_item_id ??= $itemId;
            $existing->save();
        } else {
            if ($newStatus === 'present') {
                if ($outOfSessions()) {
                    return response()->json(['message' => 'Học viên đã hết buổi của khóa này!'], 422);
                }
                StudentSchedule::consume($student, $itemId, 1);
                $student->consecutive_absences = 0;
            } else {
                $student->consecutive_absences++;
            }

            // Giờ của buổi học: theo ca; buổi học lẻ theo giờ đã đặt; không có thì giờ bấm (hôm nay) hoặc đầu ngày
            $slot = StudentSchedule::find($student, $slotId);
            $lesson = $slot ? null : collect($this->loadSingleLessons())->firstWhere('id', $slotId);
            $time = $slot['start']
                ?? (isset($lesson['time']) && preg_match('/\d{1,2}:\d{2}/', $lesson['time'], $m) ? $m[0] : null)
                ?? (Carbon::parse($dateStr)->isToday() ? Carbon::now()->format('H:i') : '00:00');
            $attendedCount = (int) ($student->total_sessions - $student->remaining_sessions);

            Attendance::create([
                'student_id' => $student->id,
                'course_id' => $item['course_id'] ?? $student->course_id,
                'slot_id' => $slotId,
                'course_item_id' => $itemId,
                'session_number' => max(1, $attendedCount),
                'attended_at' => Carbon::parse("{$dateStr} {$time}"),
                'status' => $newStatus,
                'note' => $note ?: ($newStatus === 'present' ? "Điểm danh buổi #{$attendedCount}" : 'Vắng học'),
                'marked_by' => 'Giáo viên / Admin',
            ]);
        }

        $student->save();

        return response()->json(['message' => "Điểm danh thành công cho {$student->name}!", 'student' => $student]);
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
        ]);

        $singleLessons = $this->loadSingleLessons();
        $singleLessons[] = [
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
            'note' => $validated['note'] ?? 'Buổi học lẻ / học bù',
            'created_at' => Carbon::now()->toDateTimeString(),
        ];

        $this->saveSingleLessons($singleLessons);

        $student = Student::find($validated['student_id']);

        return response()->json([
            'message' => "Đã đặt buổi học lẻ ngày {$validated['date']} cho học viên {$student->name}!",
        ], 201);
    }
}
