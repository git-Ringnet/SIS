<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\MessageTemplate;
use App\Models\RetentionLog;
use App\Models\Student;
use App\Support\CenterStore;
use App\Support\SingleLessons;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AbsenceApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $center = $request->input('center', 'all');
        $threshold = (int) $request->input('threshold', 2);

        $query = Student::with(['course', 'latestAttendance', 'latestRetentionLog'])
            ->where('status', 'active')
            ->where('consecutive_absences', '>=', $threshold);

        if ($center !== 'all' && ! empty($center)) {
            $query->where('center', $center);
        }

        $students = $query->orderBy('consecutive_absences', 'desc')->get();

        $studentsFormatted = $students->map(function ($s) {
            $latestAttDate = null;
            if ($s->latestAttendance && $s->latestAttendance->attended_at) {
                $latestAttDate = Carbon::parse($s->latestAttendance->attended_at)->format('d/m/Y');
            }

            return [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'dob' => $s->dob ? Carbon::parse($s->dob)->format('d/m/Y') : null,
                'parent_name' => $s->parent_name,
                'parent_phone' => $s->parent_phone,
                'parent_zalo' => $s->parent_zalo,
                'consecutive_absences' => (int) $s->consecutive_absences,
                'remaining_sessions' => (int) $s->remaining_sessions,
                'total_sessions' => (int) $s->total_sessions,
                'center' => $s->center ?: CenterStore::defaultName(),
                'course' => $s->course ? [
                    'id' => $s->course->id,
                    'name' => $s->course->name,
                    'color' => $s->course->color,
                ] : null,
                'teacher' => $s->teacher,
                'room' => $s->room,
                'assigned_class' => $s->assigned_class,
                'latest_attendance_date' => $latestAttDate,
                'notes' => $s->notes,
                'latest_log' => $s->latestRetentionLog ? [
                    'content' => $s->latestRetentionLog->content,
                    'contact_date' => Carbon::parse($s->latestRetentionLog->contact_date)->format('d/m/Y H:i'),
                    'created_by' => $s->latestRetentionLog->created_by,
                ] : null,
            ];
        });

        $templates = MessageTemplate::where('category', 'absence_care')->get();
        if ($templates->isEmpty()) {
            $templates = collect([
                [
                    'id' => 1,
                    'title' => 'Hỏi thăm học viên vắng 2 buổi & Hẹn lịch học bù',
                    'template_content' => 'Dạ trung tâm xin chào phụ huynh {ten_hoc_vien} ạ! Thầy cô thấy bé đã vắng 2 buổi học gần đây nên nhắn tin hỏi thăm tình hình sức khỏe và lịch trình của bé ạ. Trung tâm có thể hỗ trợ sắp xếp lịch học bù bổ sung kiến thức cho con vào tuần tới, anh/chị tiện giờ nào để trung tâm xếp lớp cho bé ạ? ❤️',
                ],
                [
                    'id' => 2,
                    'title' => 'Nhắc lịch học và giữ số buổi bảo lưu',
                    'template_content' => 'Chào anh/chị phụ huynh bé {ten_hoc_vien}! Khóa học {khoa_hoc} của con hiện còn {buoi_con_lai} buổi. Để đảm bảo tiến độ và phản xạ ngón đàn của bé, trung tâm gửi tin nhắn để hỗ trợ gia đình mình sắp xếp lịch học sớm nhất nhé ạ!',
                ],
            ]);
        }

        $centers = CenterStore::names();

        // Lấy danh sách phòng học theo trung tâm hoặc toàn bộ cơ sở
        $rooms = array_values(array_unique(array_filter(array_merge(
            CenterStore::roomNames($center !== 'all' ? $center : null),
            Student::whereNotNull('room')->where('room', '!=', '')->distinct()->pluck('room')->toArray()
        ))));
        if (empty($rooms)) {
            $rooms = CenterStore::roomNames();
        }

        // Lấy danh sách giáo viên từ bảng students và staff_data.json
        $teachers = Student::whereNotNull('teacher')->where('teacher', '!=', '')->distinct()->pluck('teacher')
            ->flatMap(fn ($t) => array_map('trim', explode(',', $t)))->filter()->unique()->values()->toArray();
        if (Storage::exists('staff_data.json')) {
            $staffData = json_decode(Storage::get('staff_data.json'), true);
            if (! empty($staffData['members'])) {
                foreach ($staffData['members'] as $m) {
                    if (! empty($m['name']) && str_contains($m['role'] ?? '', 'Giáo viên')) {
                        $teachers[] = $m['name'];
                    }
                }
            }
        }
        $teachers = array_values(array_unique(array_filter($teachers)));

        // Danh sách đơn xin nghỉ phép từ Cổng phụ huynh
        $parentLeaveRequests = Attendance::with(['student.course'])
            ->where('status', 'absent_with_leave')
            ->where(function ($q) {
                $q->where('marked_by', 'Phụ huynh (Cổng PH)')
                    ->orWhere('note', 'like', '%Cổng phụ huynh%');
            })
            ->orderBy('attended_at', 'desc')
            ->limit(30)
            ->get()
            ->map(fn ($att) => [
                'id' => $att->id,
                'student_id' => $att->student_id,
                'student_name' => $att->student?->name,
                'student_code' => $att->student?->code,
                'teacher' => $att->student?->teacher,
                'room' => $att->student?->room,
                'parent_phone' => $att->student?->parent_phone,
                'parent_name' => $att->student?->parent_name,
                'course_name' => $att->course?->name ?? $att->student?->course?->name,
                'leave_date' => $att->attended_at?->format('d/m/Y'),
                'leave_date_raw' => $att->attended_at?->toDateString(),
                'reason' => $att->parentLeaveReason(),
                'created_at' => $att->created_at?->format('d/m/Y H:i'),
            ]);

        return response()->json([
            'threshold' => $threshold,
            'center' => $center,
            'total' => $studentsFormatted->count(),
            'students' => $studentsFormatted,
            'parent_leave_requests' => $parentLeaveRequests,
            'templates' => $templates,
            'centers' => $centers,
            'rooms' => $rooms,
            'teachers' => $teachers,
        ]);
    }

    public function recordCare(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:1000',
            'next_followup_date' => 'nullable|date',
            'created_by' => 'nullable|string|max:100',
        ]);

        $log = RetentionLog::create([
            'student_id' => $student->id,
            'action_type' => 'call_care',
            'status' => 'following_up',
            'contact_date' => Carbon::now(),
            'content' => $validated['content'],
            'next_followup_date' => $validated['next_followup_date'] ?? null,
            'created_by' => $validated['created_by'] ?? 'Tư vấn viên',
        ]);

        $student->notes = ($student->notes ? $student->notes."\n" : '').'['.date('d/m/Y').'] '.$validated['content'];
        $student->last_retention_contact_at = Carbon::now();
        $student->save();

        return response()->json([
            'message' => "Đã ghi nhận chăm sóc học viên {$student->name}!",
            'log' => $log,
        ]);
    }

    public function resetAbsences(Request $request, Student $student): JsonResponse
    {
        $student->consecutive_absences = 0;
        $student->notes = ($student->notes ? $student->notes."\n" : '').'['.date('d/m/Y').'] Học viên đã quay lại lớp / hoàn thành buổi học bù.';
        $student->save();

        return response()->json([
            'message' => "Đã xác nhận học viên {$student->name} quay lại lớp học!",
            'student' => $student,
        ]);
    }

    public function scheduleMakeup(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'time' => 'required|string',
            'room' => 'nullable|string',
            'teacher' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        $makeupNote = "Lịch học bù: Ngày {$validated['date']} ({$validated['time']}) - Phòng: ".($validated['room'] ?? 'N/A').' - GV: '.($validated['teacher'] ?? 'N/A');
        $student->notes = ($student->notes ? $student->notes."\n" : '').'['.date('d/m/Y').'] '.$makeupNote;
        $student->save();

        // Buổi bù hiện ở trang Điểm danh ngày đó và trên Cổng phụ huynh
        SingleLessons::add([
            'id' => uniqid('lesson_'),
            'student_id' => $student->id,
            'date' => Carbon::parse($validated['date'])->toDateString(),
            'center' => $student->center,
            'course_id' => $student->course_id,
            'room' => $validated['room'] ?? null,
            'teacher' => $validated['teacher'] ?? null,
            'time' => $validated['time'],
            'is_makeup' => true,
            'note' => $validated['note'] ?? 'Buổi học bù',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        RetentionLog::create([
            'student_id' => $student->id,
            'action_type' => 'schedule_makeup',
            'status' => 'following_up',
            'contact_date' => Carbon::now(),
            'content' => $makeupNote,
            'next_followup_date' => $validated['date'],
            'created_by' => 'Tư vấn viên',
        ]);

        return response()->json([
            'message' => "Đã xếp lịch học bù cho học viên {$student->name}!",
        ]);
    }
}
