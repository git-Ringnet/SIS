<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Student;
use App\Support\ActivityLogger;
use App\Support\CenterStore;
use App\Support\CheckinToken;
use App\Support\OwnerGuard;
use App\Support\PagePermissions;
use App\Support\ShiftTime;
use App\Support\StudentSchedule;
use App\Support\TeacherLeaves;
use App\Support\TeacherName;
use App\Support\TeacherPayroll;
use App\Support\TeacherRates;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeacherCheckinApiController extends Controller
{
    // Check-in muộn hơn giờ vào ca quá số phút này thì tính là đi trễ
    private const LATE_GRACE_MINUTES = 5;

    private const PAID_STATUSES = TeacherPayroll::PAID_STATUSES;

    private const PATH = TeacherPayroll::CHECKINS_PATH;

    // Dữ liệu ghi lúc check-in, bỏ đi khi khôi phục ca
    private const CHECKIN_FIELDS = ['checked_in_by', 'checkin_method', 'checked_in_at', 'pay_mode', 'unit_rate', 'free_threshold', 'free_per_shift', 'group_size'];

    /**
     * Sinh ca dạy của ngày từ lịch học cố định của học viên (giáo viên + giờ + phòng + trung tâm).
     * Ca chưa check-in được cập nhật lại theo danh sách học viên hiện tại (thêm / bớt học viên, đổi loại ca),
     * ca không còn học viên thì bỏ. Ca đã check-in / báo vắng giữ nguyên làm lịch sử.
     */
    private function syncScheduledShifts(string $date, array $shifts): array
    {
        $carbonDate = Carbon::parse($date);
        $students = Student::with('course')
            ->where('status', 'active')
            ->whereJsonContains('class_days', $carbonDate->dayOfWeek)
            ->orderBy('name')
            ->get();

        // Từng ca của học viên trong ngày; ca có giáo viên mới sinh ca dạy
        $entries = $students->flatMap(fn ($st) => array_map(
            fn ($slot) => ['student' => $st, 'slot' => $slot],
            // Không sinh ca dạy cho buổi học viên chưa bắt đầu học (tránh tính tiền buổi không có thật)
            array_filter(StudentSchedule::slotsOnDate($st, $date), fn ($slot) => ! empty($slot['teacher'])),
        ));

        // Hình thức học theo khóa của từng ca (lớp nhóm / cá nhân 1:1)
        $classTypes = Course::pluck('class_type', 'id');
        $entries = $entries->map(function ($e) use ($classTypes) {
            $item = StudentSchedule::courseItemForSlot($e['student'], $e['slot']['id']);

            return [...$e, 'item' => $item, 'class_type' => $classTypes[$item['course_id'] ?? $e['student']->course_id] ?? null];
        });

        // Ca dạy = gom các học viên cùng giáo viên + giờ + phòng + cơ sở. Khóa cá nhân: mỗi học viên một ca 1:1 riêng.
        // Loại ca theo hình thức của khóa (khóa nhóm còn 1 bạn vẫn là ca nhóm); khóa chưa chọn hình thức thì 1 bạn = ca 1:1.
        $planned = [];
        $groupKey = fn ($e) => implode('|', [$e['slot']['teacher'], $e['slot']['start'], $e['slot']['room'], $e['student']->center])
            .($e['class_type'] === 'individual' ? '|hv'.$e['student']->id : '');
        foreach ($entries->groupBy($groupKey) as $key => $group) {
            $first = $group->first();
            $slot = $first['slot'];
            $st = $first['student'];
            $isOneOnOne = match ($first['class_type']) {
                'individual' => true,
                'group' => false,
                default => $group->count() === 1,
            };
            $item = $first['item'];
            $courseName = $item['course_name'] ?? $st->course?->name ?? 'Lớp học';

            $planned[$date.'|'.$key] = [
                'date' => $date,
                'day_of_week' => $carbonDate->dayOfWeek === 0 ? 'CN' : 'Thứ '.($carbonDate->dayOfWeek + 1),
                'teacher_name' => $slot['teacher'],
                'shift_name' => ShiftTime::label($slot['start']).' '.StudentSchedule::timeLabel($slot),
                'start_time' => $slot['start'],
                'end_time' => $slot['end'],
                'class_name' => $isOneOnOne
                    ? "{$courseName} 1:1 - {$st->name}"
                    : "{$courseName} nhóm ({$group->count()} bạn)",
                'room' => $slot['room'],
                'type' => $isOneOnOne ? 'Ca 1-1' : 'Ca nhóm',
                'center' => $st->center ?: CenterStore::defaultName(),
                'course_id' => $item['course_id'] ?? $st->course_id,
                'student_ids' => $group->map(fn ($e) => $e['student']->id)->values()->all(),
            ];
        }

        $changed = false;
        $seen = [];
        $result = [];
        foreach ($shifts as $shift) {
            $key = $shift['schedule_key'] ?? null;
            if (! $key || ($shift['date'] ?? null) !== $date) {
                $result[] = $shift;

                continue;
            }

            $seen[$key] = true;
            $pending = ($shift['status'] ?? 'not_checked_in') === 'not_checked_in';

            if ($pending && ! isset($planned[$key])) {
                $changed = true; // không còn học viên trong khung giờ này

                continue;
            }

            if ($pending && array_intersect_key($shift, $planned[$key]) != $planned[$key]) {
                $shift = array_merge($shift, $planned[$key]);
                $changed = true;
            }
            $result[] = $shift;
        }

        $nextId = (int) (collect($shifts)->max('id') ?? 0) + 1;
        foreach ($planned as $key => $data) {
            if (isset($seen[$key])) {
                continue;
            }
            $result[] = ['id' => $nextId++, 'schedule_key' => $key, ...$data, 'status' => 'not_checked_in',
                'checkin_time' => null, 'minutes_late' => 0, 'substitute_teacher' => null];
            $changed = true;
        }

        if ($changed) {
            $this->saveCheckins($result);
        }

        return $result;
    }

    /**
     * Áp nghỉ phép / dạy thay theo khoảng ngày lên các ca chưa check-in (chỉ để hiển thị, không lưu).
     * Dạy thay ưu tiên hơn nghỉ phép: giáo viên gốc nghỉ, người dạy thay vào ca.
     */
    private function withPeriods(array $shift, array $periods): array
    {
        if (($shift['status'] ?? 'not_checked_in') !== 'not_checked_in') {
            return $shift;
        }

        // Ngày trung tâm nghỉ: không có ca dạy
        if ($off = TeacherLeaves::centerOffFor($shift['center'] ?? null, $shift['date'], $periods)) {
            return [...$shift, 'status' => 'excused_absence', 'on_leave' => true,
                'period_note' => 'Trung tâm nghỉ'.(! empty($off['reason']) ? ': '.$off['reason'] : '')];
        }

        if (empty($shift['substitute_teacher'])
            && $sub = TeacherLeaves::substitutionFor($shift['teacher_name'], $shift['date'], $periods, $shift['start_time'] ?? null, $shift['end_time'] ?? null)) {
            // Dạy thay theo đợt (trang Nghỉ phép / Dạy thay): gỡ bằng cách xoá đợt đó, không gỡ trên từng ca
            return [...$shift, 'substitute_teacher' => $sub['substitute'], 'substitute_from_period' => true,
                'period_note' => $sub['reason'] ?: 'Phân công dạy thay'];
        }

        if (empty($shift['substitute_teacher'])
            && $leave = TeacherLeaves::leaveFor($shift['teacher_name'], $shift['date'], $periods, $shift['start_time'] ?? null, $shift['end_time'] ?? null)) {
            return [...$shift, 'status' => 'excused_absence', 'on_leave' => true, 'period_note' => $leave['reason'] ?: 'Nghỉ phép'];
        }

        return $shift;
    }

    /**
     * Ca đã xoá nháp: giữ lại (status = deleted) để không bị sinh lại từ lịch học và khôi phục được,
     * nhưng không được tính công / thù lao / check-in / báo vắng ở bất kỳ đâu.
     */
    private static function isDeleted(array $shift): bool
    {
        return ($shift['status'] ?? null) === 'deleted';
    }

    private function loadCheckins(): array
    {
        $data = Storage::exists(self::PATH) ? json_decode(Storage::get(self::PATH), true) : null;

        return is_array($data) ? $data : [];
    }

    private function saveCheckins(array $shifts): void
    {
        Storage::put(self::PATH, json_encode($shifts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Tên giáo viên (theo màn Nhân sự) nếu tài khoản đang đăng nhập là giáo viên, ngược lại null.
     */
    private function ownTeacherName(Request $request): ?string
    {
        return PagePermissions::isTeacher($request->user())
            ? (PagePermissions::member($request->user())['name'] ?? '')
            : null;
    }

    /**
     * Ca có liên quan tới giáo viên: là giáo viên gốc (không bị thay) hoặc là người dạy thay.
     */
    private function belongsTo(array $shift, string $teacher): bool
    {
        return ! empty($shift['substitute_teacher'])
            ? TeacherName::same($shift['substitute_teacher'], $teacher)
            : TeacherName::same($shift['teacher_name'], $teacher);
    }

    /**
     * Người chỉ được xem thù lao của chính mình (tên theo màn Nhân sự); null = chủ trung tâm / quản lý, xem tất cả.
     */
    private function ownPayName(Request $request): ?string
    {
        if (PagePermissions::seesAllPay($request->user())) {
            return null;
        }

        return PagePermissions::member($request->user())['name'] ?? $request->user()?->name ?? '';
    }

    private function forbidUnlessSeesAllPay(Request $request): ?JsonResponse
    {
        return PagePermissions::seesAllPay($request->user())
            ? null
            : response()->json(['message' => 'Chỉ chủ trung tâm hoặc quản lý được xem / sửa đơn giá và thù lao giáo viên.'], 403);
    }

    private function forbidTeacher(Request $request): ?JsonResponse
    {
        return PagePermissions::isTeacher($request->user())
            ? response()->json(['message' => 'Giáo viên không thể thực hiện thao tác này. Vui lòng báo quản lý.'], 403)
            : null;
    }

    public function index(Request $request): JsonResponse
    {
        $date = $request->input('date', Carbon::today()->toDateString());
        $month = $request->input('month', Carbon::now()->format('Y-m'));
        $center = $request->input('center', 'all');
        $shiftType = $request->input('shift_type', 'all');
        $shiftTime = $request->input('shift_time', 'all');
        $teacherName = $request->input('teacher_name', 'all');
        // Giáo viên chỉ xem ca dạy của chính mình (kể cả ca được phân công dạy thay)
        $ownName = $this->ownTeacherName($request);
        if ($ownName !== null) {
            $teacherName = $ownName;
        }
        // Thù lao: chủ trung tâm / quản lý xem tất cả, người khác chỉ xem của chính mình
        $payName = $this->ownPayName($request);

        $periods = TeacherLeaves::all();
        $shifts = array_map(
            fn ($s) => $this->withPeriods($s, $periods),
            array_filter($this->syncScheduledShifts($date, $this->loadCheckins()), fn ($s) => ! self::isDeleted($s)),
        );

        $carbonDate = Carbon::parse($date);
        $dayOfWeekVi = $carbonDate->dayOfWeek === 0 ? 'CN' : 'Thứ '.($carbonDate->dayOfWeek + 1);

        $todayShifts = array_values(array_filter($shifts, function ($s) use ($date, $center, $shiftType, $shiftTime, $teacherName) {
            return $s['date'] === $date
                && ($center === 'all' || $s['center'] === $center)
                && ($shiftType === 'all' || $s['type'] === $shiftType)
                && ($teacherName === 'all' || $this->belongsTo($s, $teacherName) || TeacherName::same($s['teacher_name'], $teacherName))
                && ($shiftTime === 'all' || ShiftTime::of($s['start_time'] ?? null) === $shiftTime);
        }));
        // Ca trong ngày xếp theo giờ vào ca, sớm trước (cùng giờ thì theo tên giáo viên)
        usort($todayShifts, fn ($a, $b) => [$a['start_time'] ?? '99:99', $a['teacher_name'] ?? ''] <=> [$b['start_time'] ?? '99:99', $b['teacher_name'] ?? '']);

        $todayStats = [
            'total' => count($todayShifts),
            'checked_in' => count(array_filter($todayShifts, fn ($s) => in_array($s['status'], ['checked_in', 'late', 'substitute']))),
            'skipped' => count(array_filter($todayShifts, fn ($s) => $s['status'] === 'skipped')),
            'excused_absence' => count(array_filter($todayShifts, fn ($s) => $s['status'] === 'excused_absence')),
            'late' => count(array_filter($todayShifts, fn ($s) => ($s['minutes_late'] ?? 0) > 0)),
            'substitute' => count(array_filter($todayShifts, fn ($s) => ! empty($s['substitute_teacher']))),
        ];

        // Ca dạy trong ngày: ẩn đơn giá đã chốt của ca không phải của người xem
        if ($payName !== null) {
            $todayShifts = array_map(
                fn ($s) => TeacherName::same(TeacherPayroll::earner($s), $payName) ? $s : array_diff_key($s, array_flip(['pay_mode', 'unit_rate', 'free_threshold', 'free_per_shift', 'group_size', 'rate'])),
                $todayShifts,
            );
        }

        // Bảng tổng kết tháng: các ca đã tính công, thù lao tính cho người thực dạy
        $monthlyShifts = array_values(array_filter($shifts, function ($s) use ($month, $center, $payName) {
            return str_starts_with($s['date'], $month)
                && in_array($s['status'], self::PAID_STATUSES, true)
                && ($center === 'all' || $s['center'] === $center)
                && ($payName === null || TeacherName::same(TeacherPayroll::earner($s), $payName));
        }));
        // Ca đã xoá nháp trong tháng (chủ trung tâm / quản lý xem để khôi phục), kèm thù lao trước khi xoá
        $deletedShifts = $payName !== null ? [] : array_values(array_filter(
            array_map(fn ($s) => [...($s['trashed'] ?? $s), 'id' => $s['id'], 'status' => 'deleted', 'deleted_at' => $s['deleted_at'] ?? null,
                'deleted_by' => $s['deleted_by'] ?? null, 'restorable' => ! empty($s['trashed']), 'trashed_status' => $s['trashed']['status'] ?? null],
                array_filter($this->loadCheckins(), fn ($s) => self::isDeleted($s))),
            fn ($s) => str_starts_with($s['date'], $month) && ($center === 'all' || ($s['center'] ?? null) === $center),
        ));

        $present = TeacherPayroll::attendanceByDate([...$monthlyShifts, ...$deletedShifts]);
        $monthlyShifts = array_map(function ($s) use ($present) {
            $pay = TeacherPayroll::pay($s, $present);

            return [...$s, 'earner' => TeacherPayroll::earner($s), 'amount' => $pay['amount'], 'pay_factor' => $pay['factor'], 'pay_note' => $pay['note']];
        }, $monthlyShifts);
        $deletedShifts = array_map(fn ($s) => [...$s, 'earner' => TeacherPayroll::earner($s),
            'amount' => $s['trashed_status'] ? TeacherPayroll::amount([...$s, 'status' => $s['trashed_status']], $present) : 0], $deletedShifts);

        return response()->json([
            'date' => $date,
            'day_of_week' => $dayOfWeekVi,
            'today_stats' => $todayStats,
            'today_shifts' => $todayShifts,
            'monthly_summary' => [
                'month' => $month,
                'total_salary' => array_sum(array_column($monthlyShifts, 'amount')),
                'total_shifts' => count($monthlyShifts),
                'shifts' => $monthlyShifts,
                'deleted_shifts' => $deletedShifts,
            ],
            'teachers' => $ownName !== null ? [$ownName] : TeacherName::all(),
            'centers' => CenterStore::names(),
        ]);
    }

    /**
     * Số phút check-in muộn hơn giờ vào ca (0 nếu đến sớm / đúng giờ hoặc ca không có giờ).
     */
    private function minutesLate(?string $startTime, string $checkTime): int
    {
        if (! $startTime) {
            return 0;
        }
        [$sh, $sm] = array_map('intval', explode(':', $startTime) + [0, 0]);
        [$ch, $cm] = array_map('intval', explode(':', $checkTime) + [0, 0]);

        return max(0, ($ch * 60 + $cm) - ($sh * 60 + $sm));
    }

    public function checkin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shift_id' => 'required|integer',
            'checkin_time' => 'nullable|date_format:H:i',
            // Mã QR đang hiện ở quầy của cơ sở (bắt buộc với giáo viên tự check-in)
            'qr_token' => 'nullable|string|max:64',
            'qr_center' => 'nullable|string|max:255',
        ]);

        $shifts = $this->loadCheckins();
        $periods = TeacherLeaves::all();
        $ownName = $this->ownTeacherName($request);
        $logged = null;

        foreach ($shifts as &$stored) {
            if ($stored['id'] !== $validated['shift_id'] || self::isDeleted($stored)) {
                continue;
            }

            $s = $this->withPeriods($stored, $periods);
            $isToday = Carbon::parse($s['date'])->isToday();
            if ($ownName !== null && (! $isToday || ! $this->belongsTo($s, $ownName))) {
                return response()->json(['message' => 'Bạn chỉ có thể check-in ca dạy của mình trong ngày hôm nay.'], 403);
            }
            // Giáo viên tự check-in phải quét mã QR còn hạn của đúng cơ sở dạy (chống check-in từ xa).
            // Quản lý / giáo vụ check-in hộ thì không cần mã.
            if ($ownName !== null) {
                if (! CheckinToken::isValid($validated['qr_center'] ?? null, $validated['qr_token'] ?? null)) {
                    return response()->json(['message' => 'Mã QR không hợp lệ hoặc đã hết hạn. Hãy quét lại mã QR đang hiện tại quầy của trung tâm.'], 403);
                }
                if (($s['center'] ?? null) !== $validated['qr_center']) {
                    return response()->json(['message' => "Ca này dạy tại {$s['center']}, bạn đang quét mã QR của {$validated['qr_center']}."], 403);
                }
            }
            if (! empty($s['on_leave'])) {
                return response()->json(['message' => "{$s['teacher_name']} đang nghỉ phép ngày này, không check-in được ca này."], 422);
            }
            if ($s['status'] !== 'not_checked_in') {
                return response()->json(['message' => 'Ca dạy này đã được ghi nhận trước đó.'], 422);
            }

            // Ghi bù cho ngày đã qua (không biết giờ đến thật) thì tính đúng giờ vào ca
            $checkTime = ($validated['checkin_time'] ?? null)
                ?: ($isToday ? Carbon::now()->format('H:i') : ($s['start_time'] ?? Carbon::now()->format('H:i')));
            $mins = $this->minutesLate($s['start_time'] ?? null, $checkTime);
            $isLate = $mins > self::LATE_GRACE_MINUTES;
            $substitute = $s['substitute_teacher'] ?? null;
            $earner = $substitute ?: $s['teacher_name'];

            // Chốt đơn giá tại thời điểm check-in: đổi đơn giá sau này không ảnh hưởng ca đã tính
            $stored = [
                ...$stored,
                ...TeacherRates::quote($earner, ($s['type'] ?? '') === 'Ca 1-1', $s['center'] ?? null),
                'status' => $substitute ? 'substitute' : ($isLate ? 'late' : 'checked_in'),
                'substitute_teacher' => $substitute,
                'checkin_time' => $checkTime,
                'minutes_late' => $isLate ? $mins : 0,
                'checked_in_by' => $request->user()?->name,
                'checkin_method' => $ownName === null ? 'manual' : 'qr',
                'checked_in_at' => Carbon::now()->toDateTimeString(),
            ];
            $logged = $stored;
            break;
        }
        unset($stored);

        if (! $logged) {
            return response()->json(['message' => 'Không tìm thấy ca dạy!'], 404);
        }

        $this->saveCheckins($shifts);

        $who = TeacherPayroll::earner($logged);
        $timing = $logged['minutes_late'] > 0 ? "trễ {$logged['minutes_late']} phút" : 'đúng giờ';
        ActivityLogger::log('updated', 'teacher_checkin', "{$who} – {$logged['shift_name']}", $logged['id'],
            ActivityLogger::diff(['status' => 'Chưa check-in'], [
                'status' => ($logged['status'] === 'substitute' ? 'Dạy thay, ' : '').$timing,
                'note' => "Check-in lúc {$logged['checkin_time']} ngày {$logged['date']}",
            ]), $logged['center'] ?? null);

        return response()->json([
            'message' => "Đã check-in {$who} lúc {$logged['checkin_time']}, {$timing}.",
        ]);
    }

    /**
     * Mã QR hiện tại của một cơ sở, cho màn hình QR đặt ở quầy (quản lý / lễ tân mở).
     */
    public function qrToken(Request $request): JsonResponse
    {
        if ($denied = $this->forbidTeacher($request)) {
            return $denied;
        }

        $centers = CenterStore::names();
        $center = $request->input('center') ?: CenterStore::defaultName();
        if (! in_array($center, $centers, true)) {
            return response()->json(['message' => 'Không tìm thấy cơ sở.'], 404);
        }

        return response()->json([
            'center' => $center,
            'centers' => $centers,
            'window_seconds' => CheckinToken::WINDOW_SECONDS,
            ...CheckinToken::current($center),
        ]);
    }

    /**
     * Ghi nhận cho từng ca: bỏ dạy, vắng có phép, hoặc đổi người dạy thay cho riêng ca này.
     */
    public function recordAbsence(Request $request): JsonResponse
    {
        if ($denied = $this->forbidTeacher($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'shift_id' => 'required|integer',
            'status' => 'required|string|in:skipped,excused_absence,substitute',
            'substitute_teacher' => 'required_if:status,substitute|nullable|string|max:255',
            'reason' => 'nullable|string|max:255',
        ]);

        $shifts = $this->loadCheckins();
        $found = false;

        foreach ($shifts as &$s) {
            if ($s['id'] !== $validated['shift_id'] || self::isDeleted($s)) {
                continue;
            }
            if (! in_array($s['status'] ?? 'not_checked_in', ['not_checked_in', 'skipped', 'excused_absence'], true)) {
                return response()->json(['message' => 'Ca dạy này đã check-in, không đổi được.'], 422);
            }

            if ($validated['status'] === 'substitute') {
                // Người dạy thay sẽ tự check-in ca này; tiền ca tính theo đơn giá của người dạy thay
                $s['status'] = 'not_checked_in';
                $s['substitute_teacher'] = $validated['substitute_teacher'];
            } else {
                $s['status'] = $validated['status'];
                $s['substitute_teacher'] = null;
            }
            $s['note'] = $validated['reason'] ?? null;
            $found = true;
            break;
        }
        unset($s);

        if (! $found) {
            return response()->json(['message' => 'Không tìm thấy ca dạy!'], 404);
        }

        $this->saveCheckins($shifts);

        return response()->json([
            'message' => $validated['status'] === 'substitute'
                ? "Đã phân công {$validated['substitute_teacher']} dạy thay ca này."
                : 'Đã cập nhật trạng thái ca dạy.',
        ]);
    }

    /**
     * Khôi phục ca về "Chưa check-in": bỏ giờ check-in, đơn giá đã chốt, báo vắng. Phân công dạy thay giữ nguyên.
     * Ca chưa check-in mà có người dạy thay (phân công riêng cho ca này) thì bỏ dạy thay, ca về giáo viên gốc.
     */
    public function resetShift(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->forbidTeacher($request)) {
            return $denied;
        }

        return $this->updateStoredShift($id, function (array $s) {
            if (($s['status'] ?? 'not_checked_in') === 'not_checked_in') {
                return empty($s['substitute_teacher'])
                    ? response()->json(['message' => 'Ca dạy này chưa được ghi nhận.'], 422)
                    : [...$s, 'substitute_teacher' => null, 'note' => null];
            }

            $reset = array_diff_key($s, array_flip(self::CHECKIN_FIELDS));

            return [...$reset, 'status' => 'not_checked_in', 'checkin_time' => null, 'minutes_late' => 0, 'note' => null];
        }, fn ($old) => ($old['status'] ?? 'not_checked_in') === 'not_checked_in'
            ? ['Bỏ dạy thay', ['substitute_teacher' => $old['substitute_teacher']], ['substitute_teacher' => null]]
            : ['Khôi phục', ['status' => self::statusLabel($old['status'])], ['status' => 'Chưa check-in']]);
    }

    /**
     * Sửa ca đã check-in: giờ check-in (tính lại trễ), đơn giá đã chốt, ghi chú.
     */
    public function updateShift(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->forbidUnlessSeesAllPay($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'checkin_time' => ['required', 'date_format:H:i'],
            'unit_rate' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:255',
        ]);

        return $this->updateStoredShift($id, function (array $s) use ($validated) {
            if (! in_array($s['status'] ?? null, self::PAID_STATUSES, true)) {
                return response()->json(['message' => 'Chỉ sửa được ca đã check-in.'], 422);
            }

            $mins = $this->minutesLate($s['start_time'] ?? null, $validated['checkin_time']);
            $isLate = $mins > self::LATE_GRACE_MINUTES;
            $s['checkin_time'] = $validated['checkin_time'];
            $s['minutes_late'] = $isLate ? $mins : 0;
            if ($s['status'] !== 'substitute') {
                $s['status'] = $isLate ? 'late' : 'checked_in';
            }
            if (array_key_exists('unit_rate', $validated) && $validated['unit_rate'] !== null) {
                // Ca cũ chỉ có đơn giá cố định "rate": chuyển sang đơn giá theo ca
                $s['pay_mode'] ??= 'per_shift';
                $s['unit_rate'] = (float) $validated['unit_rate'];
                unset($s['rate']);
            }
            $s['note'] = $validated['note'] ?? null;

            return $s;
        }, fn ($old, $new) => ['Sửa', [
            'checkin_time' => $old['checkin_time'] ?? null,
            'unit_rate' => $old['unit_rate'] ?? ($old['rate'] ?? null),
        ], [
            'checkin_time' => $new['checkin_time'],
            'unit_rate' => $new['unit_rate'] ?? ($new['rate'] ?? null),
        ]]);
    }

    /**
     * Xoá nháp ca dạy: giữ bản gốc trong "trashed" để khôi phục, ca sinh từ lịch học không bị tự sinh lại.
     * Ca đã tính công (có thù lao, ví dụ chấm sai) chỉ chủ trung tâm được xoá.
     */
    public function destroyShift(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->forbidTeacher($request)) {
            return $denied;
        }
        $isOwner = OwnerGuard::isOwner($request->user());

        return $this->updateStoredShift(
            $id,
            fn (array $s) => in_array($s['status'] ?? null, self::PAID_STATUSES, true) && ! $isOwner
                ? response()->json(['message' => 'Ca dạy đã tính thù lao, chỉ chủ trung tâm được xoá.'], 403)
                : ['id' => $s['id'], 'schedule_key' => $s['schedule_key'] ?? null, 'date' => $s['date'], 'status' => 'deleted',
                    'teacher_name' => $s['teacher_name'], 'shift_name' => $s['shift_name'] ?? '', 'center' => $s['center'] ?? null,
                    'deleted_at' => Carbon::now()->toDateTimeString(), 'deleted_by' => $request->user()?->name, 'trashed' => $s],
            fn ($old) => ['Xoá', ['status' => self::statusLabel($old['status'])], ['status' => 'Đã xoá']],
        );
    }

    /**
     * Khôi phục ca đã xoá nháp về đúng như trước khi xoá (giờ check-in, đơn giá đã chốt...). Chỉ chủ trung tâm.
     */
    public function restoreShift(Request $request, int $id): JsonResponse
    {
        if (! OwnerGuard::isOwner($request->user())) {
            return response()->json(['message' => 'Chỉ chủ trung tâm được khôi phục ca dạy đã xoá.'], 403);
        }

        $shifts = $this->loadCheckins();
        foreach ($shifts as $i => $s) {
            if ($s['id'] !== $id || ! self::isDeleted($s)) {
                continue;
            }
            if (empty($s['trashed'])) {
                return response()->json(['message' => 'Ca này đã xoá từ trước khi có chức năng khôi phục, không khôi phục được.'], 422);
            }

            $shifts[$i] = $s['trashed'];
            $this->saveCheckins($shifts);
            ActivityLogger::log('updated', 'teacher_checkin', TeacherPayroll::earner($s['trashed'])." – {$s['shift_name']}", $id,
                ActivityLogger::diff(['status' => 'Đã xoá'], ['status' => self::statusLabel($s['trashed']['status'] ?? null), 'note' => "Khôi phục ca ngày {$s['date']}"]),
                $s['center'] ?? null);

            return response()->json(['message' => 'Đã khôi phục ca dạy.']);
        }

        return response()->json(['message' => 'Không tìm thấy ca dạy đã xoá!'], 404);
    }

    /**
     * Tìm ca theo id, áp thay đổi rồi lưu + ghi nhật ký.
     * $change trả về ca mới, hoặc một JsonResponse để từ chối.
     */
    private function updateStoredShift(int $id, callable $change, callable $describe): JsonResponse
    {
        $shifts = $this->loadCheckins();

        foreach ($shifts as $i => $s) {
            if ($s['id'] !== $id || self::isDeleted($s)) {
                continue;
            }

            $result = $change($s);
            if ($result instanceof JsonResponse) {
                return $result;
            }

            $shifts[$i] = $result;
            $this->saveCheckins($shifts);

            [$action, $before, $after] = $describe($s, $result);
            ActivityLogger::log($action === 'Xoá' ? 'deleted' : 'updated', 'teacher_checkin', TeacherPayroll::earner($s)." – {$s['shift_name']}", $id,
                ActivityLogger::diff($before, [...$after, 'note' => "{$action} ca ngày {$s['date']}"]), $s['center'] ?? null);

            return response()->json(['message' => match ($action) {
                'Khôi phục' => 'Đã khôi phục ca dạy về chưa check-in.',
                'Bỏ dạy thay' => 'Đã bỏ dạy thay, ca trở về giáo viên gốc.',
                'Xoá' => 'Đã xoá ca dạy.',
                default => 'Đã cập nhật ca dạy.',
            }]);
        }

        return response()->json(['message' => 'Không tìm thấy ca dạy!'], 404);
    }

    private static function statusLabel(?string $status): string
    {
        return match ($status) {
            'checked_in' => 'Đúng giờ',
            'late' => 'Đi trễ',
            'skipped' => 'Bỏ dạy không phép',
            'excused_absence' => 'Vắng có phép',
            'substitute' => 'Dạy thay',
            default => 'Chưa check-in',
        };
    }

    public function exportMonthly(Request $request): StreamedResponse
    {
        $month = $request->input('month', Carbon::now()->format('Y-m'));
        // Chủ trung tâm / quản lý có thể xuất riêng một giáo viên; người khác luôn chỉ xuất của mình
        $payName = $this->ownPayName($request) ?? ($request->input('teacher') ?: null);

        $monthlyShifts = array_values(array_filter($this->loadCheckins(), function ($s) use ($month, $payName) {
            return str_starts_with($s['date'], $month)
                && ! self::isDeleted($s)
                && ($payName === null || TeacherName::same(TeacherPayroll::earner($s), $payName));
        }));
        $present = TeacherPayroll::attendanceByDate($monthlyShifts);

        $fileName = 'cham_cong_ca_day_'.str_replace('-', '', $month).'_'.date('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($monthlyShifts, $present) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, [
                'Ngày', 'Giáo viên gốc', 'Người dạy', 'Ca dạy', 'Loại ca', 'Phòng', 'Cơ sở', 'Trạng thái',
                'Giờ check-in', 'Trễ (phút)', 'Kiểu tính', 'Đơn giá (VNĐ)', 'Hệ số theo điểm danh HV', 'Thù lao ca dạy (VNĐ)',
            ]);

            foreach ($monthlyShifts as $s) {
                $pay = TeacherPayroll::pay($s, $present);
                fputcsv($output, [
                    Carbon::parse($s['date'])->format('d/m/Y'),
                    $s['teacher_name'],
                    TeacherPayroll::earner($s),
                    $s['shift_name'],
                    $s['type'],
                    $s['room'],
                    $s['center'],
                    self::statusLabel($s['status']),
                    $s['checkin_time'] ?: '-',
                    $s['minutes_late'] ?: 0,
                    match ($s['pay_mode'] ?? 'per_shift') {
                        'per_student' => 'Theo lượt HV (cách tính cũ)',
                        'free' => 'Dạy tự do (cách tính cũ)',
                        default => ($s['type'] ?? '') === 'Ca 1-1' ? 'Lương ca 1:1' : 'Lương ca nhóm',
                    },
                    $s['unit_rate'] ?? ($s['rate'] ?? 0),
                    $pay['note'] ?? '100%',
                    $pay['amount'],
                ]);
            }

            fclose($output);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
