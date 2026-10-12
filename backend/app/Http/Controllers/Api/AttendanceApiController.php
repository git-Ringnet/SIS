<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Student;
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
        $dateStr = $request->input('date', '2026-10-11');
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
        $dayAttendances = Attendance::whereDate('attended_at', $dateStr)->get()->keyBy('student_id');

        // Lấy tất cả học viên có thể học trong ngày này (học bù, học lẻ, hoặc theo lịch)
        $singleLessons = $this->loadSingleLessons();
        $singleStudentIds = array_map(fn ($l) => $l['student_id'], array_filter($singleLessons, fn ($l) => $l['date'] === $dateStr));

        // Học viên active có lịch khớp thứ này (hoặc có buổi học lẻ hôm nay)
        $query = Student::with('course')->where(function ($q) use ($singleStudentIds, $dayOfWeekVi) {
            $q->whereIn('id', $singleStudentIds);

            // Hoặc học viên có lịch khớp ngày hôm nay
            if ($dayOfWeekVi === 'CN') {
                $q->orWhere('schedule_info', 'like', '%CN%')
                    ->orWhere('schedule_info', 'like', '%Chủ nhật%');
            } elseif ($dayOfWeekVi === 'Thứ 2') {
                $q->orWhere('schedule_info', 'like', '%T2%')
                    ->orWhere('schedule_info', 'like', '%Thứ 2%');
            } elseif ($dayOfWeekVi === 'Thứ 3') {
                $q->orWhere('schedule_info', 'like', '%T3%')
                    ->orWhere('schedule_info', 'like', '%Thứ 3%');
            } elseif ($dayOfWeekVi === 'Thứ 4') {
                $q->orWhere('schedule_info', 'like', '%T4%')
                    ->orWhere('schedule_info', 'like', '%Thứ 4%');
            } elseif ($dayOfWeekVi === 'Thứ 5') {
                $q->orWhere('schedule_info', 'like', '%T5%')
                    ->orWhere('schedule_info', 'like', '%Thứ 5%');
            } elseif ($dayOfWeekVi === 'Thứ 6') {
                $q->orWhere('schedule_info', 'like', '%T6%')
                    ->orWhere('schedule_info', 'like', '%Thứ 6%');
            } elseif ($dayOfWeekVi === 'Thứ 7') {
                $q->orWhere('schedule_info', 'like', '%T7%')
                    ->orWhere('schedule_info', 'like', '%Thứ 7%');
            }
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

        $studentsFormatted = $students->map(function ($st) use ($dayAttendances) {
            $att = $dayAttendances->get($st->id);
            $status = 'not_checked';
            $checkinNote = '';

            if ($st->status === 'paused') {
                $status = 'paused';
            } elseif ($att) {
                if ($att->status === 'present') {
                    $status = 'present';
                } elseif ($att->status === 'absent_with_leave') {
                    $status = 'absent_with_leave';
                } elseif ($att->status === 'absent_without_leave') {
                    $status = 'absent_without_leave';
                }
                $checkinNote = $att->note ?: '';
            }

            $currentSessionNumber = ($st->total_sessions - $st->remaining_sessions) + ($status === 'present' ? 0 : 1);
            if ($currentSessionNumber > $st->total_sessions) {
                $currentSessionNumber = $st->total_sessions;
            }

            return [
                'id' => $st->id,
                'code' => $st->code,
                'name' => $st->name,
                'dob' => $st->dob ? Carbon::parse($st->dob)->format('d/m/Y') : null,
                'parent_name' => $st->parent_name,
                'parent_phone' => $st->parent_phone,
                'parent_zalo' => $st->parent_zalo,
                'course' => $st->course ? [
                    'id' => $st->course->id,
                    'name' => $st->course->name,
                    'color' => $st->course->color,
                ] : null,
                'center' => $st->center ?: 'Khoa Anh Music Hóc Môn',
                'room' => $st->course && str_contains($st->course->name, 'Piano') ? 'PIANO 01' : 'GUITAR 01',
                'teacher' => 'Thầy Trương Bảo Đi',
                'shift_time' => '17:30 - 18:30',
                'total_sessions' => (int) $st->total_sessions,
                'remaining_sessions' => (int) $st->remaining_sessions,
                'current_session' => $currentSessionNumber,
                'status' => $status,
                'student_system_status' => $st->status,
                'checkin_note' => $checkinNote,
            ];
        });

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

        $centers = [
            'Khoa Anh Music Hóc Môn',
            'Khoa Anh Music Quận 12',
            'Khoa Anh Music Gò Vấp',
        ];

        $classes = [
            'Lớp Piano Căn Bản',
            'Lớp Guitar Đệm Hát',
            'Lớp Thanh Nhạc',
            'Lớp Trống Jazz',
        ];

        $rooms = [
            'PIANO 01',
            'PIANO 02',
            'GUITAR 01',
            'VOCAL 01',
            'DRUM 01',
            'GROUP',
        ];

        $teachers = [
            'Thầy Trương Bảo Đi',
            'Thầy Minh',
            'Cô Mai',
            'Thầy Đức',
        ];

        return response()->json([
            'date' => $dateStr,
            'day_of_week' => $dayOfWeekVi,
            'day_of_week_en' => $dayOfWeekEn,
            'metrics' => $metrics,
            'students' => $studentsFormatted->values(),
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
        ]);

        $dateStr = $validated['date'];
        $newStatus = $validated['status'];
        $note = $validated['note'] ?? '';

        // Tìm điểm danh đã có trong ngày
        $existing = Attendance::where('student_id', $student->id)
            ->whereDate('attended_at', $dateStr)
            ->first();

        if ($newStatus === 'not_checked') {
            // Hoàn tác điểm danh nếu có
            if ($existing) {
                if ($existing->status === 'present') {
                    $student->increment('remaining_sessions');
                } elseif (in_array($existing->status, ['absent_with_leave', 'absent_without_leave'])) {
                    $student->decrement('consecutive_absences');
                }
                $existing->delete();
                $student->save();
            }

            return response()->json([
                'message' => "Đã hoàn tác điểm danh cho {$student->name}.",
                'student' => $student,
            ]);
        }

        if ($existing) {
            // Nếu đổi từ vắng sang có mặt hoặc ngược lại
            if ($existing->status === 'present' && $newStatus !== 'present') {
                $student->increment('remaining_sessions');
                $student->increment('consecutive_absences');
            } elseif ($existing->status !== 'present' && $newStatus === 'present') {
                $student->decrement('remaining_sessions');
                $student->consecutive_absences = 0;
            }

            $existing->status = $newStatus;
            $existing->note = $note ?: $existing->note;
            $existing->save();
        } else {
            // Tạo mới điểm danh
            if ($newStatus === 'present') {
                if ($student->remaining_sessions <= 0) {
                    return response()->json(['message' => 'Học viên đã hết buổi học!'], 422);
                }
                $student->decrement('remaining_sessions');
                $student->consecutive_absences = 0;
            } else {
                $student->increment('consecutive_absences');
            }

            $attendedCount = $student->total_sessions - $student->remaining_sessions;
            Attendance::create([
                'student_id' => $student->id,
                'course_id' => $student->course_id,
                'session_number' => $attendedCount > 0 ? $attendedCount : 1,
                'attended_at' => Carbon::parse($dateStr.' 18:00:00'),
                'status' => $newStatus,
                'note' => $note ?: ($newStatus === 'present' ? "Điểm danh buổi #{$attendedCount}" : 'Vắng học'),
                'marked_by' => 'Giáo viên / Admin',
            ]);
        }

        $student->save();

        return response()->json([
            'message' => "Điểm danh thành công cho {$student->name}!",
            'student' => $student,
        ]);
    }

    public function scheduleSingleLesson(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'date' => 'required|date',
            'room' => 'nullable|string',
            'teacher' => 'nullable|string',
            'time' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        $singleLessons = $this->loadSingleLessons();
        $singleLessons[] = [
            'id' => uniqid('lesson_'),
            'student_id' => (int) $validated['student_id'],
            'date' => $validated['date'],
            'room' => $validated['room'] ?? 'PIANO 01',
            'teacher' => $validated['teacher'] ?? 'Thầy Trương Bảo Đi',
            'time' => $validated['time'] ?? '18:00 - 19:00',
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
