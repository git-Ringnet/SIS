<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Student;
use App\Support\BrandSettings;
use App\Support\CenterStore;
use App\Support\StudentSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParentPortalApiController extends Controller
{
    /**
     * Thông tin sơ bộ công khai cho màn hình xác thực 4 số cuối SĐT.
     */
    public function info(Student $student): JsonResponse
    {
        $brand = BrandSettings::brand();
        $cleanPhone = preg_replace('/[^0-9]/', '', $student->parent_phone ?? '');
        $phoneHint = strlen($cleanPhone) >= 2 ? '•••'.substr($cleanPhone, -2) : '••••';

        return response()->json([
            'student_id' => $student->id,
            'student_code' => $student->code,
            'student_name' => $student->name,
            'center' => $student->center ?: CenterStore::defaultName(),
            'course_name' => $student->course?->name,
            'phone_hint' => $phoneHint,
            'brand' => [
                'brand_name' => $brand['brand_name'] ?? 'EduCRM SIS',
                'logo_url' => $brand['logo_url'] ?? null,
                'phone' => $brand['phone'] ?? null,
                'address' => $brand['address'] ?? null,
            ],
        ]);
    }

    /**
     * Xác thực 4 số cuối số điện thoại phụ huynh/học viên và trả về toàn bộ dữ liệu học tập.
     */
    public function verify(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'phone_last4' => 'required|string|size:4',
        ]);

        $entered = trim($validated['phone_last4']);
        $cleanParentPhone = preg_replace('/[^0-9]/', '', $student->parent_phone ?? '');
        $cleanParentZalo = preg_replace('/[^0-9]/', '', $student->parent_zalo ?? '');

        $isMatch = false;
        if (strlen($cleanParentPhone) >= 4 && substr($cleanParentPhone, -4) === $entered) {
            $isMatch = true;
        } elseif (strlen($cleanParentZalo) >= 4 && substr($cleanParentZalo, -4) === $entered) {
            $isMatch = true;
        }

        if (! $isMatch) {
            return response()->json([
                'message' => '4 số cuối số điện thoại không chính xác. Vui lòng kiểm tra lại số điện thoại đã đăng ký với trung tâm.',
            ], 422);
        }

        // Tải chi tiết học viên, điểm danh, khóa học và học phí
        $student->load([
            'course',
            'attendances' => fn ($q) => $q->orderBy('attended_at', 'asc'),
            'invoices' => fn ($q) => $q->orderBy('created_at', 'desc'),
        ]);

        $totalSessions = (int) $student->total_sessions;
        $remainingSessions = (int) $student->remaining_sessions;
        $completedSessions = max(
            $student->attendances->where('status', 'present')->count(),
            max(0, $totalSessions - $remainingSessions)
        );

        $absentWithLeave = $student->attendances->where('status', 'absent_with_leave')->count();
        $absentWithoutLeave = $student->attendances->where('status', 'absent_without_leave')->count();
        $totalAbsences = $absentWithLeave + $absentWithoutLeave;

        // Xây dựng danh sách toàn bộ các buổi học (đã học, vắng, và chưa diễn ra)
        $sessionList = [];
        $existingAttendances = $student->attendances->values();
        $usedSessionNumbers = [];

        foreach ($existingAttendances as $index => $att) {
            $sessionNum = $att->session_number ?: ($index + 1);
            $usedSessionNumbers[] = $sessionNum;

            $statusText = match ($att->status) {
                'present' => 'Đã học (Có mặt)',
                'absent_with_leave' => 'Nghỉ có phép',
                'absent_without_leave' => 'Vắng không phép',
                default => 'Đã điểm danh',
            };

            $sessionList[] = [
                'session_number' => $sessionNum,
                'status' => $att->status,
                'status_label' => $statusText,
                'date' => $att->attended_at?->format('d/m/Y'),
                'time' => $att->attended_at?->format('H:i'),
                'full_datetime' => $att->attended_at?->format('d/m/Y H:i'),
                'note' => $att->note ?: 'Hoàn thành buổi học',
                'marked_by' => $att->marked_by ?: 'Giáo viên',
                'is_completed' => $att->status === 'present',
                'is_absent' => str_starts_with($att->status, 'absent'),
            ];
        }

        // Sắp xếp các buổi đã học theo số buổi
        usort($sessionList, fn ($a, $b) => $a['session_number'] <=> $b['session_number']);

        // Bổ sung các buổi học trong tương lai (chưa học) đến đủ total_sessions
        $maxRecorded = count($sessionList) > 0 ? max(array_column($sessionList, 'session_number')) : 0;
        $targetSessions = max($totalSessions, $maxRecorded);

        for ($i = 1; $i <= $targetSessions; $i++) {
            if (! in_array($i, $usedSessionNumbers)) {
                $sessionList[] = [
                    'session_number' => $i,
                    'status' => 'upcoming',
                    'status_label' => 'Chưa học',
                    'date' => null,
                    'time' => null,
                    'full_datetime' => null,
                    'note' => 'Buổi học trong kế hoạch đào tạo',
                    'marked_by' => null,
                    'is_completed' => false,
                    'is_absent' => false,
                ];
            }
        }

        // Sắp xếp lại danh sách buổi học theo thứ tự 1, 2, 3...
        usort($sessionList, fn ($a, $b) => $a['session_number'] <=> $b['session_number']);

        // Ngày học dự kiến của các buổi chưa học, theo lịch học hằng tuần
        $upcomingIndexes = array_keys(array_filter($sessionList, fn ($s) => $s['status'] === 'upcoming'));
        $planned = StudentSchedule::upcomingSessions($student, count($upcomingIndexes));
        $weekdays = [1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5', 5 => 'Thứ 6', 6 => 'Thứ 7', 0 => 'Chủ nhật'];
        foreach ($planned as $n => ['date' => $date, 'slot' => $slot]) {
            $sessionList[$upcomingIndexes[$n]] = [
                ...$sessionList[$upcomingIndexes[$n]],
                'planned_date' => $date->format('d/m/Y'),
                'planned_weekday' => $weekdays[$date->dayOfWeek],
                'planned_time' => StudentSchedule::timeLabel($slot),
                'planned_teacher' => $slot['teacher'] ?? null,
            ];
        }

        $brand = BrandSettings::brand();
        $maskedPhone = strlen($cleanParentPhone) >= 6
            ? substr($cleanParentPhone, 0, 3).'••••'.substr($cleanParentPhone, -3)
            : $cleanParentPhone;

        return response()->json([
            'verified' => true,
            'student' => [
                'id' => $student->id,
                'code' => $student->code,
                'name' => $student->name,
                'avatar' => $student->avatar,
                'dob' => $student->dob?->format('d/m/Y'),
                'gender' => $student->gender,
                'parent_name' => $student->parent_name,
                'masked_phone' => $maskedPhone,
                'center' => $student->center ?: CenterStore::defaultName(),
                'assigned_class' => $student->assigned_class,
                'teacher' => $student->teacher,
                'join_date' => $student->join_date?->format('d/m/Y'),
                'end_date' => $student->end_date?->format('d/m/Y'),
                'schedule_info' => $student->schedule_info,
                'schedule_summary' => $student->scheduleSummary(),
                'status' => $student->status,
                'attendance_dots' => $student->attendance_dots,
                'attendance_status' => $student->attendance_status,
                'course' => $student->course ? [
                    'id' => $student->course->id,
                    'name' => $student->course->name,
                    'code' => $student->course->code,
                    'category' => $student->course->category,
                    'default_sessions' => $student->course->default_sessions,
                ] : null,
            ],
            'stats' => [
                'total_sessions' => $totalSessions,
                'completed_sessions' => $completedSessions,
                'remaining_sessions' => $remainingSessions,
                'total_absences' => $totalAbsences,
                'absent_with_leave' => $absentWithLeave,
                'absent_without_leave' => $absentWithoutLeave,
                'allowed_absences' => (int) $student->allowed_absences,
                'progress_percent' => $totalSessions > 0 ? round(($completedSessions / $totalSessions) * 100) : 0,
            ],
            'sessions' => $sessionList,
            'supplies' => $student->supplies ?? [],
            'invoices' => $student->invoices->map(fn ($inv) => [
                'id' => $inv->id,
                'code' => $inv->code,
                'amount' => $inv->amount,
                'status' => $inv->status,
                'payment_method' => $inv->payment_method,
                'created_at' => $inv->created_at?->format('d/m/Y'),
            ]),
            'brand' => [
                'brand_name' => $brand['brand_name'] ?? 'EduCRM SIS',
                'logo_url' => $brand['logo_url'] ?? null,
                'phone' => $brand['phone'] ?? null,
                'address' => $brand['address'] ?? null,
            ],
        ]);
    }

    /**
     * Phụ huynh gửi yêu cầu xin nghỉ phép trực tiếp từ Cổng thông tin.
     */
    public function requestLeave(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'phone_last4' => 'required|string|size:4',
            'date' => 'required|date',
            'reason' => 'required|string|max:500',
        ]);

        $entered = trim($validated['phone_last4']);
        $cleanParentPhone = preg_replace('/[^0-9]/', '', $student->parent_phone ?? '');
        $cleanParentZalo = preg_replace('/[^0-9]/', '', $student->parent_zalo ?? '');

        $isMatch = false;
        if (strlen($cleanParentPhone) >= 4 && substr($cleanParentPhone, -4) === $entered) {
            $isMatch = true;
        } elseif (strlen($cleanParentZalo) >= 4 && substr($cleanParentZalo, -4) === $entered) {
            $isMatch = true;
        }

        if (! $isMatch) {
            return response()->json([
                'message' => 'Xác thực không hợp lệ. Vui lòng thử lại.',
            ], 422);
        }

        // Ghi nhận vắng có phép
        $leaveDate = Carbon::parse($validated['date'])->setTime(12, 0);
        $attendance = Attendance::create([
            'student_id' => $student->id,
            'course_id' => $student->course_id,
            'session_number' => max(1, (int) $student->total_sessions - (int) $student->remaining_sessions),
            'attended_at' => $leaveDate,
            'status' => 'absent_with_leave',
            'note' => 'PH xin nghỉ qua Cổng phụ huynh: '.$validated['reason'],
            'marked_by' => 'Phụ huynh (Cổng PH)',
        ]);

        $student->increment('absences_used');
        $student->increment('consecutive_absences');
        $student->save();

        return response()->json([
            'message' => 'Đã gửi đơn xin nghỉ phép thành công. Trung tâm và giáo viên sẽ nhận được thông tin.',
            'attendance' => $attendance,
        ]);
    }
}
