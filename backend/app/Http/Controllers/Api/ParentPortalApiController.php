<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Student;
use App\Support\AttendanceMarker;
use App\Support\BrandSettings;
use App\Support\CenterStore;
use App\Support\SingleLessons;
use App\Support\StudentSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParentPortalApiController extends Controller
{
    private const WEEKDAYS = [1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5', 5 => 'Thứ 6', 6 => 'Thứ 7', 0 => 'Chủ nhật'];

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

    private function phoneMatches(Student $student, string $entered): bool
    {
        foreach ([$student->parent_phone, $student->parent_zalo] as $phone) {
            $clean = preg_replace('/[^0-9]/', '', $phone ?? '');
            if (strlen($clean) >= 4 && substr($clean, -4) === $entered) {
                return true;
            }
        }

        return false;
    }

    /**
     * Xác thực 4 số cuối số điện thoại phụ huynh/học viên và trả về toàn bộ dữ liệu học tập.
     */
    public function verify(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'phone_last4' => 'required|string|size:4',
        ]);

        if (! $this->phoneMatches($student, trim($validated['phone_last4']))) {
            return response()->json([
                'message' => '4 số cuối số điện thoại không chính xác. Vui lòng kiểm tra lại số điện thoại đã đăng ký với trung tâm.',
            ], 422);
        }

        // Tải chi tiết học viên, điểm danh, khóa học và học phí
        $student->load([
            'course',
            'attendances' => fn ($q) => $q->reorder()->orderBy('attended_at', 'asc'),
            'invoices' => fn ($q) => $q->orderBy('created_at', 'desc'),
        ]);

        $totalSessions = (int) $student->total_sessions;
        $remainingSessions = (int) $student->remaining_sessions;
        $usedSessions = max(0, $totalSessions - $remainingSessions);

        $attendances = $student->attendances;
        $absentWithLeave = $attendances->where('status', 'absent_with_leave')->count();
        $absentWithoutLeave = $attendances->where('status', 'absent_without_leave')->count();
        $deductedAbsences = $attendances->filter(fn ($a) => $a->status !== 'present' && $a->deductsSession())->count();
        $completedSessions = max($attendances->where('status', 'present')->count(), $usedSessions - $deductedAbsences);

        // Số buổi được nghỉ có phép tính riêng từng khóa (khóa đã học xong thì không hiện, trừ khi chỉ còn khóa đó)
        $leaveByCourse = collect($student->registered_courses_list)
            ->map(function ($i) use ($student) {
                $allowed = (int) ($i['allowed_absences'] ?? $student->allowed_absences ?? 3);
                $used = (int) ($i['absences_used'] ?? 0);

                return [
                    'course_item_id' => $i['id'],
                    'course_name' => $i['course_name'] ?? 'Khóa học',
                    'allowed' => $allowed,
                    'used' => $used,
                    'left' => max(0, $allowed - $used),
                    'completed' => ($i['status'] ?? 'active') === 'completed',
                ];
            });
        $leaveByCourse = ($leaveByCourse->reject(fn ($c) => $c['completed'])->values()->all() ?: $leaveByCourse->values()->all());
        $allowedAbsences = array_sum(array_column($leaveByCourse, 'allowed'));
        $leaveUsed = array_sum(array_column($leaveByCourse, 'used'));

        $sessions = $this->timeline($student);

        $brand = BrandSettings::brand();
        $cleanParentPhone = preg_replace('/[^0-9]/', '', $student->parent_phone ?? '');
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
            // Ngày bắt đầu / kết thúc từng khóa (chưa nhập ngày kết thúc thì dự kiến theo lịch học và số buổi còn lại)
            'courses' => collect($student->registered_courses_list)->map(fn ($item) => [
                'course_item_id' => $item['id'],
                'course_name' => $item['course_name'] ?? $student->course?->name ?? 'Khóa học',
                'total_sessions' => (int) ($item['total_sessions'] ?? 0),
                'remaining_sessions' => (int) ($item['remaining_sessions'] ?? 0),
                'completed' => ($item['status'] ?? 'active') === 'completed',
                ...$student->courseItemDates($item),
            ])->values(),
            'stats' => [
                'total_sessions' => $totalSessions,
                'completed_sessions' => $completedSessions,
                'used_sessions' => $usedSessions,
                'remaining_sessions' => $remainingSessions,
                'total_absences' => $absentWithLeave + $absentWithoutLeave,
                'absent_with_leave' => $absentWithLeave,
                'absent_without_leave' => $absentWithoutLeave,
                'deducted_absences' => $deductedAbsences,
                'allowed_absences' => $allowedAbsences,
                'leave_used' => $leaveUsed,
                'leave_left' => array_sum(array_column($leaveByCourse, 'left')),
                // Học nhiều khóa: mỗi khóa có số buổi được nghỉ riêng, buổi nghỉ trừ vào khóa của buổi đó
                'leave_by_course' => $leaveByCourse,
                'off_sessions' => count(array_filter($sessions, fn ($s) => $s['status'] === 'off')),
                'makeup_sessions' => count(array_filter($sessions, fn ($s) => $s['is_makeup'])),
                'progress_percent' => $totalSessions > 0 ? round(($usedSessions / $totalSessions) * 100) : 0,
            ],
            'sessions' => $sessions,
            'supplies' => $student->supplies ?? [],
            'invoices' => $student->invoices->where('status', '!=', 'cancelled')->values()->map(fn ($inv) => [
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
                // Quy định nghỉ học do trung tâm soạn ở Cài đặt (mỗi dòng một ý)
                'leave_rules' => BrandSettings::portalLeaveRules(),
            ],
        ]);
    }

    /**
     * Toàn bộ buổi học theo thời gian: đã điểm danh (có mặt / nghỉ), buổi trung tâm off / giáo viên nghỉ,
     * buổi bù / học lẻ và các buổi sắp tới theo lịch học hằng tuần (đủ số buổi còn lại).
     * Chỉ buổi trừ vào số buổi học (có mặt, vắng bị trừ buổi, buổi sắp tới) mới được đánh số "Buổi n".
     */
    private function timeline(Student $student): array
    {
        $today = Carbon::today();
        $lessons = collect(SingleLessons::forStudent($student->id))->keyBy('id');
        $attendances = $student->attendances;
        $rows = [];

        // Ca đã có điểm danh theo ngày (bản ghi cũ không có ca được tính cho ca đầu tiên trong ngày)
        $recorded = [];
        foreach ($attendances as $att) {
            $date = $att->attended_at->toDateString();
            $slotId = StudentSchedule::slotIdOf($student, $att);
            $recorded[$date.'|'.$slotId] = true;

            $slot = StudentSchedule::find($student, $slotId);
            $lesson = $slotId !== null ? $lessons->get($slotId) : null;
            $deducts = $att->deductsSession();
            $time = $slot ? StudentSchedule::timeLabel($slot) : ($lesson['time'] ?? $att->attended_at->format('H:i'));

            $rows[] = $this->row([
                'key' => 'att-'.$att->id,
                'status' => $att->status,
                'status_label' => match (true) {
                    $att->status === 'present' => 'Đã học',
                    $att->status === 'absent_with_leave' && ! $deducts => 'Vắng có phép',
                    $att->status === 'absent_with_leave' => 'Vắng có phép (hết lượt nghỉ)',
                    $att->status === 'absent_without_leave' => 'Vắng không phép',
                    default => 'Đã điểm danh',
                },
                'deducts_session' => $deducts,
                'date' => $att->attended_at,
                'time' => $time === '00:00' ? null : $time,
                'teacher' => $slot['teacher'] ?? $lesson['teacher'] ?? null,
                // Ghi chú tự điền ("Điểm danh buổi #n", "Vắng học"...) không phải nhận xét của giáo viên: không hiện cho phụ huynh
                'note' => Attendance::isAutoNote($att->note) && ! str_starts_with((string) $att->note, 'PH xin nghỉ') ? null : $att->note,
                'marked_by' => $att->marked_by,
                'is_makeup' => ! empty($lesson['is_makeup']),
                'is_single_lesson' => $lesson !== null,
                'attendance_id' => $att->id,
                'course_item' => StudentSchedule::courseItem($student, $att->course_item_id),
            ]);
        }

        // Buổi bù / học lẻ đã đặt mà chưa điểm danh
        $upcomingLessons = $lessons->filter(fn ($l) => ($l['date'] ?? '') >= $today->toDateString() && ! isset($recorded[$l['date'].'|'.$l['id']]));
        foreach ($upcomingLessons as $lesson) {
            $rows[] = $this->row([
                'key' => 'lesson-'.$lesson['id'],
                'status' => 'upcoming',
                // Loại buổi (Buổi bù / Học lẻ) đã có nhãn riêng bên cạnh, trạng thái chỉ cần "Chưa học" như buổi thường
                'status_label' => 'Chưa học',
                'date' => Carbon::parse($lesson['date'].' '.(SingleLessons::startTime($lesson) ?? '00:00')),
                'time' => $lesson['time'] ?? null,
                'teacher' => $lesson['teacher'] ?? null,
                'note' => $lesson['note'] ?? null,
                'is_makeup' => ! empty($lesson['is_makeup']),
                'is_single_lesson' => true,
                'slot_id' => $lesson['id'],
                'can_request_leave' => true,
                // Buổi bù / học lẻ cũng trừ 1 buổi nên được đánh số như buổi thường
                'official' => true,
                'course_item' => StudentSchedule::courseItemForSlot($student, $lesson['id']),
            ]);
        }

        $items = $student->registered_courses_list ?: [null];
        $unscheduled = []; // [khóa, số buổi chưa có ngày]

        // Buổi theo lịch đã qua mà trung tâm chưa điểm danh: vẫn là một buổi của khóa (có số, dùng 1 buổi còn lại)
        $unmarked = StudentSchedule::unmarkedSessions($student, $attendances);
        foreach ($unmarked as ['date' => $date, 'slot' => $slot, 'item' => $unmarkedItem, 'key' => $key]) {
            $recorded[$key] = true;
            $rows[] = $this->row([
                'key' => 'unmarked-'.$date->toDateString().'-'.$slot['id'],
                'status' => 'unmarked',
                'status_label' => 'Chưa điểm danh',
                'date' => $date,
                'time' => StudentSchedule::timeLabel($slot),
                'teacher' => $slot['teacher'] ?? null,
                'note' => 'Trung tâm chưa cập nhật điểm danh buổi này.',
                'slot_id' => $slot['id'],
                'unmarked_key' => $key,
                'course_item' => $unmarkedItem,
            ]);
        }
        foreach ($items as $item) {
            if ($item && ($item['status'] ?? 'active') === 'completed') {
                continue;
            }
            if (StudentSchedule::courseSlots($student, $item) === [] || in_array($student->status, ['inactive', 'dropped'], true)) {
                // Chưa có lịch tuần / đã nghỉ học: các buổi còn lại chưa có ngày (trừ buổi bù / học lẻ đã đặt, buổi chưa điểm danh)
                $booked = $upcomingLessons->filter(fn ($l) => (StudentSchedule::courseItemForSlot($student, $l['id'])['id'] ?? null) === ($item['id'] ?? null))->count()
                    + collect($unmarked)->filter(fn ($u) => ($u['item']['id'] ?? null) === ($item['id'] ?? null))->count();
                $unscheduled[] = [$item, max(0, (int) ($item['remaining_sessions'] ?? $student->remaining_sessions) - $booked)];
            }
        }

        // Buổi sắp tới theo lịch tuần (số buổi còn lại của từng khóa), nhưng chỉ tới đúng ngày kết thúc dự kiến của khóa:
        // khách chốt Cổng PH không tự cộng thêm buổi sau ngày này (buổi thiếu do giáo vụ tự đặt buổi bù, buổi bù vẫn có số)
        $planned = in_array($student->status, ['inactive', 'dropped'], true) ? [] : array_values(array_filter(
            StudentSchedule::plannedSessions($student, $attendances),
            function ($session) use ($student) {
                $end = $student->courseItemDates($session['item'])['end'];

                return ! $end || $session['date']->lte(Carbon::createFromFormat('d/m/Y', $end)->endOfDay());
            },
        ));
        foreach ($planned as ['date' => $date, 'slot' => $slot]) {
            $rows[] = $this->row([
                'key' => 'plan-'.$date->toDateString().'-'.$slot['id'],
                'status' => 'upcoming',
                'status_label' => 'Chưa học',
                'date' => $date->copy()->setTimeFromTimeString($slot['start']),
                'time' => StudentSchedule::timeLabel($slot),
                'teacher' => $slot['teacher'] ?? null,
                'slot_id' => $slot['id'],
                'can_request_leave' => true,
                'official' => true,
                'course_item' => StudentSchedule::courseItemForSlot($student, $slot['id']),
            ]);
        }

        // Ngày trung tâm nghỉ / giáo viên nghỉ: từ ngày nhập học (hoặc buổi đầu tiên) đến buổi sắp tới cuối cùng
        $from = collect([$student->join_date, $attendances->first()?->attended_at])->filter()->min() ?? $today;
        $to = $planned !== [] ? collect($planned)->max('date') : $today;
        foreach (StudentSchedule::offSessions($student, Carbon::parse($from)->max($today->copy()->subYear()), $to) as ['date' => $date, 'slot' => $slot, 'off' => $off]) {
            if (isset($recorded[$date->toDateString().'|'.$slot['id']])) {
                continue;
            }
            $rows[] = $this->row([
                'key' => 'off-'.$date->toDateString().'-'.$slot['id'],
                'status' => 'off',
                'status_label' => $off['label'],
                'date' => $date->copy()->setTimeFromTimeString($slot['start']),
                'time' => StudentSchedule::timeLabel($slot),
                'teacher' => $slot['teacher'] ?? null,
                'note' => $off['reason'] ? "{$off['label']}: {$off['reason']}" : $off['label'],
                'off_type' => $off['type'],
                'course_item' => StudentSchedule::courseItemForSlot($student, $slot['id']),
            ]);
        }

        usort($rows, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        // Học viên chưa có lịch tuần: các buổi còn lại chưa có ngày dự kiến
        foreach ($unscheduled as $n => [$item, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $rows[] = $this->row(['key' => "todo-{$n}-{$i}", 'status' => 'upcoming', 'status_label' => 'Chưa học', 'official' => true,
                    'note' => 'Buổi học trong kế hoạch đào tạo', 'course_item' => $item]);
            }
        }

        // Đánh số "Buổi n" riêng từng khóa (cùng số với trang Điểm danh): mọi buổi trừ buổi (đã học, vắng không phép,
        // nghỉ có phép hết lượt, học bù, học lẻ), buổi đã qua chưa điểm danh và các buổi sắp tới. Không đánh số:
        // nghỉ có phép còn lượt, trung tâm off / giáo viên nghỉ.
        ['numbers' => $numbers, 'unmarked' => $unmarkedNumbers, 'last' => $last] = StudentSchedule::officialNumbers($student, $attendances);
        foreach ($rows as &$row) {
            if ($row['attendance_id'] !== null) {
                $row['session_number'] = $numbers[$row['attendance_id']] ?? null;
            } elseif ($row['unmarked_key'] !== null) {
                $row['session_number'] = $unmarkedNumbers[$row['unmarked_key']] ?? null;
            } elseif ($row['official']) {
                $key = StudentSchedule::itemKey($student, $row['course_item_id']);
                $row['session_number'] = $last[$key] = ($last[$key] ?? 0) + 1;
            }
            unset($row['sort'], $row['official'], $row['attendance_id'], $row['unmarked_key']);
        }

        return $rows;
    }

    private function row(array $data): array
    {
        $date = $data['date'] ?? null;
        $status = $data['status'];

        return [
            'key' => $data['key'],
            'session_number' => null,
            'status' => $status,
            'status_label' => $data['status_label'],
            'deducts_session' => $data['deducts_session'] ?? $status === 'upcoming',
            'date' => $date?->format('d/m/Y'),
            'date_raw' => $date?->toDateString(),
            'weekday' => $date ? self::WEEKDAYS[$date->dayOfWeek] : null,
            'time' => $data['time'] ?? null,
            'teacher' => $data['teacher'] ?? null,
            'note' => $data['note'] ?? null,
            'marked_by' => $data['marked_by'] ?? null,
            'is_completed' => $status === 'present',
            'is_absent' => str_starts_with($status, 'absent'),
            'is_makeup' => $data['is_makeup'] ?? false,
            'is_single_lesson' => $data['is_single_lesson'] ?? false,
            'off_type' => $data['off_type'] ?? null,
            'slot_id' => $data['slot_id'] ?? null,
            // Khóa của buổi học (học nhiều khóa: buổi nghỉ trừ vào số buổi được nghỉ của khóa này)
            'course_item_id' => $data['course_item']['id'] ?? null,
            'course_name' => $data['course_item']['course_name'] ?? null,
            'can_request_leave' => ($data['can_request_leave'] ?? false) && $date && $date->toDateString() >= Carbon::today()->toDateString(),
            'sort' => $date ? $date->format('Y-m-d H:i') : '9999',
            'official' => $data['official'] ?? false,
            'attendance_id' => $data['attendance_id'] ?? null,
            'unmarked_key' => $data['unmarked_key'] ?? null,
        ];
    }

    /**
     * Phụ huynh xin nghỉ có phép cho một buổi học sắp tới từ Cổng thông tin.
     * Còn lượt nghỉ có phép thì không trừ buổi; hết lượt thì buổi nghỉ bị trừ như vắng không phép.
     */
    public function requestLeave(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'phone_last4' => 'required|string|size:4',
            'date' => 'required|date',
            // Ca xin nghỉ (id ca trong lịch học hoặc id buổi học bù); trống = ca đầu tiên trong ngày
            'slot_id' => 'nullable|string|max:60',
            'reason' => 'required|string|max:500',
        ]);

        if (! $this->phoneMatches($student, trim($validated['phone_last4']))) {
            return response()->json([
                'message' => 'Xác thực không hợp lệ. Vui lòng thử lại.',
            ], 422);
        }

        $date = Carbon::parse($validated['date'])->startOfDay();
        if ($date->lt(Carbon::today())) {
            return response()->json(['message' => 'Chỉ có thể xin nghỉ cho buổi học từ hôm nay trở đi.'], 422);
        }
        $dateStr = $date->toDateString();

        $daySlots = StudentSchedule::slotsOn($student, $date->dayOfWeek);
        $dayLessons = array_values(array_filter(SingleLessons::forStudent($student->id), fn ($l) => ($l['date'] ?? null) === $dateStr));
        $slotId = $validated['slot_id'] ?? ($daySlots[0]['id'] ?? $dayLessons[0]['id'] ?? null);

        $slot = collect($daySlots)->firstWhere('id', $slotId);
        $lesson = collect($dayLessons)->firstWhere('id', $slotId);
        if (! $slot && ! $lesson) {
            return response()->json(['message' => 'Con không có lịch học vào ngày này.'], 422);
        }

        if ($slot && $off = StudentSchedule::offReason($student, $dateStr, $slot)) {
            return response()->json(['message' => "Ngày này {$off['label']}, phụ huynh không cần xin nghỉ."], 422);
        }

        $records = Attendance::where('student_id', $student->id)->whereDate('attended_at', $dateStr)->get();
        $isFirstSlot = ($daySlots[0]['id'] ?? null) === $slotId;
        if ($records->firstWhere('slot_id', $slotId) || ($isFirstSlot && $records->first(fn ($r) => $r->slot_id === null))) {
            return response()->json(['message' => 'Buổi học này đã được điểm danh hoặc đã xin nghỉ trước đó.'], 422);
        }

        $result = AttendanceMarker::mark(
            $student,
            $dateStr,
            'absent_with_leave',
            'PH xin nghỉ qua Cổng phụ huynh: '.$validated['reason'],
            $slotId,
            AttendanceMarker::PARENT_PORTAL,
        );
        $attendance = $result['attendance'];
        $left = StudentSchedule::leaveLeft($student, $attendance->course_item_id);

        return response()->json([
            'message' => $attendance->deducts_session
                ? 'Đã gửi đơn xin nghỉ. Con đã hết số buổi được nghỉ có phép nên buổi này bị trừ 1 buổi học.'
                : "Đã gửi đơn xin nghỉ phép thành công, buổi này không bị trừ (còn được nghỉ {$left} buổi). Trung tâm và giáo viên sẽ nhận được thông tin.",
            'attendance' => $attendance,
        ]);
    }
}
