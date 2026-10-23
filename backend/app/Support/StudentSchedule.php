<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Student;
use Carbon\Carbon;

/**
 * Lịch học nhiều ca của học viên (students.schedules):
 *   [{id: "s1", day: 0-6 (0 = CN), start: "HH:MM", end: "HH:MM"|null, teacher: string|null, room: string|null}, ...]
 * Một ngày có thể có nhiều ca, mỗi ca giờ / giáo viên / phòng riêng.
 *
 * Các cột cũ (class_days, class_start_time, class_end_time, teacher, room, schedule_info) được tính lại từ danh sách ca
 * mỗi khi lưu, để bộ lọc và dữ liệu cũ vẫn dùng được.
 */
class StudentSchedule
{
    public const DAY_LABELS = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 0 => 'CN'];

    /**
     * Chuẩn hoá danh sách ca: bỏ dòng thiếu thứ / giờ, gán id, sắp xếp T2 → CN rồi theo giờ.
     */
    public static function normalize(?array $slots): array
    {
        $usedIds = [];
        $result = [];
        foreach ($slots ?? [] as $slot) {
            if (! isset($slot['day']) || $slot['day'] === '' || empty($slot['start'])) {
                continue;
            }
            $id = (string) ($slot['id'] ?? '');
            if ($id === '' || isset($usedIds[$id])) {
                $id = 's'.substr(md5(uniqid('', true)), 0, 8);
            }
            $usedIds[$id] = true;

            $result[] = [
                'id' => $id,
                'day' => (int) $slot['day'],
                'start' => substr((string) $slot['start'], 0, 5),
                'end' => ! empty($slot['end']) ? substr((string) $slot['end'], 0, 5) : null,
                'teacher' => trim((string) ($slot['teacher'] ?? '')) ?: null,
                'room' => trim((string) ($slot['room'] ?? '')) ?: null,
            ];
        }

        usort($result, fn ($a, $b) => [self::dayOrder($a['day']), $a['start']] <=> [self::dayOrder($b['day']), $b['start']]);

        return $result;
    }

    private static function dayOrder(int $day): int
    {
        return $day === 0 ? 7 : $day;
    }

    /**
     * Danh sách ca của học viên (dữ liệu cũ chưa có schedules thì dựng từ các cột cũ, mỗi thứ một ca).
     */
    public static function slots(Student $student): array
    {
        if (is_array($student->schedules) && $student->schedules !== []) {
            return $student->schedules;
        }

        return self::fromLegacy(
            $student->class_days ?? [],
            $student->class_start_time,
            $student->class_end_time,
            $student->teacher,
            $student->room,
        );
    }

    public static function fromLegacy(?array $days, ?string $start, ?string $end, ?string $teacher, ?string $room): array
    {
        if (! $start || empty($days)) {
            return [];
        }

        return self::normalize(array_map(fn ($d) => [
            'id' => 'd'.(int) $d,
            'day' => (int) $d,
            'start' => $start,
            'end' => $end,
            'teacher' => $teacher,
            'room' => $room,
        ], $days));
    }

    /**
     * Các ca vào một thứ trong tuần.
     */
    public static function slotsOn(Student $student, int $dayOfWeek): array
    {
        return array_values(array_filter(self::slots($student), fn ($s) => (int) $s['day'] === $dayOfWeek));
    }

    /**
     * Các ca thật sự có học vào ngày $date: theo thứ trong tuần, nhưng không có ca nào trước "Ngày bắt đầu học"
     * của học viên, và ca gán cho khóa đăng ký thêm chỉ bắt đầu từ ngày bắt đầu của khóa đó.
     * Dùng cho trang Điểm danh và sinh ca dạy giáo viên (tránh hiện / tính tiền buổi chưa bắt đầu học).
     */
    public static function slotsOnDate(Student $student, string $date): array
    {
        $date = Carbon::parse($date)->toDateString();
        if ($student->join_date && Carbon::parse($student->join_date)->toDateString() > $date) {
            return [];
        }

        $items = $student->registered_courses_list;
        $firstItemId = $items[0]['id'] ?? null;

        return array_values(array_filter(self::slotsOn($student, Carbon::parse($date)->dayOfWeek), function ($slot) use ($items, $firstItemId, $date) {
            foreach ($items as $item) {
                if (in_array($slot['id'], $item['schedule_ids'] ?? [], true)) {
                    // Khóa đầu tiên theo "Ngày bắt đầu học" (đã xét ở trên), khóa thêm theo ngày bắt đầu của khóa
                    return ($item['id'] ?? null) === $firstItemId
                        || empty($item['start_date'])
                        || Carbon::parse($item['start_date'])->toDateString() <= $date;
                }
            }

            return true;
        }));
    }

    /**
     * Các ca của một khóa: các ca đã chọn cho khóa; khóa không chọn ca riêng thì các ca chưa thuộc khóa nào khác
     * (tránh hai khóa cùng tính trên một ca). Không còn ca nào thì dùng mọi ca.
     */
    public static function courseSlots(Student $student, ?array $item): array
    {
        $slots = self::slots($student);
        if ($ids = $item['schedule_ids'] ?? []) {
            return array_values(array_filter($slots, fn ($s) => in_array($s['id'], $ids, true)));
        }

        $claimed = array_merge(...array_map(fn ($i) => $i['schedule_ids'] ?? [], $student->registered_courses_list));

        return array_values(array_filter($slots, fn ($s) => ! in_array($s['id'], $claimed, true))) ?: $slots;
    }

    /**
     * Ngày kết thúc theo kế hoạch của khóa: đi lần lượt các ca trong lịch tuần của khóa từ ngày bắt đầu, đủ tổng số buổi.
     * Không xét ngày trung tâm / giáo viên nghỉ, buổi nghỉ phép hay buổi bù, nên ngày này cố định
     * (vd bắt đầu 04/09, 2 buổi/tuần, 24 buổi → 23/11, có thêm buổi bù cũng không đổi).
     */
    public static function plannedEndDate(Student $student, ?array $item, string $from): ?Carbon
    {
        $total = (int) ($item['total_sessions'] ?? $student->total_sessions);
        if ($total <= 0) {
            return null;
        }

        $slots = self::courseSlots($student, $item);
        $perDay = array_count_values(array_map(fn ($s) => (int) $s['day'], $slots));

        // Nếu khóa học đã có thứ rồi thì ngày kết thúc tính theo thứ đó:
        // đếm lần lượt các ca theo lịch từ ngày bắt đầu đến khi đủ tổng số buổi
        if ($perDay !== []) {
            $day = Carbon::parse($from)->startOfDay();
            for ($count = 0, $i = 0; $i < 3650; $i++, $day = $day->copy()->addDay()) {
                $count += $perDay[$day->dayOfWeek] ?? 0;
                if ($count >= $total) {
                    return $day;
                }
            }

            return null;
        }

        // Chưa có thứ: tính theo số buổi/tuần (buổi cuối cùng thứ với ngày bắt đầu ở tuần cuối)
        $perWeek = (int) ($item['sessions_per_week'] ?? 0)
            ?: (preg_match('/(\d+)\s*buổi/u', (string) ($item['schedule_info'] ?? $student->schedule_info), $m) ? (int) $m[1] : 1);

        return Carbon::parse($from)->startOfDay()->addWeeks((int) ceil($total / max(1, $perWeek)) - 1);
    }

    public static function find(Student $student, ?string $slotId): ?array
    {
        foreach (self::slots($student) as $slot) {
            if ($slot['id'] === $slotId) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Giá trị các cột cũ tính từ danh sách ca (để lưu cùng schedules).
     */
    public static function legacyColumns(array $slots): array
    {
        if ($slots === []) {
            return [
                'class_days' => null,
                'class_start_time' => null,
                'class_end_time' => null,
                'schedule_info' => null,
            ];
        }

        $days = array_values(array_unique(array_map(fn ($s) => $s['day'], $slots)));
        usort($days, fn ($a, $b) => self::dayOrder($a) <=> self::dayOrder($b));
        $join = fn (string $field) => implode(', ', array_values(array_unique(array_filter(array_map(fn ($s) => $s[$field], $slots))))) ?: null;

        return [
            'class_days' => $days,
            'class_start_time' => $slots[0]['start'],
            'class_end_time' => $slots[0]['end'],
            // Nhiều giáo viên / phòng thì ghép "Cô Mai, Thầy Bình" để bộ lọc tìm theo tên vẫn khớp
            'teacher' => $join('teacher'),
            'room' => $join('room'),
            'schedule_info' => self::summary($slots),
        ];
    }

    /**
     * Mô tả ngắn: cùng một giờ thì "T2, T4 • 15:00 - 16:00", khác giờ thì "T2 09:00 · CN 15:30".
     */
    public static function summary(array $slots): ?string
    {
        if ($slots === []) {
            return null;
        }

        $times = array_unique(array_map(fn ($s) => $s['start'].'|'.($s['end'] ?? ''), $slots));
        if (count($times) === 1) {
            $days = array_values(array_unique(array_map(fn ($s) => self::DAY_LABELS[$s['day']] ?? '', $slots)));
            $first = $slots[0];

            return implode(', ', $days).' • '.$first['start'].($first['end'] ? ' - '.$first['end'] : '');
        }

        return implode(' · ', array_map(fn ($s) => (self::DAY_LABELS[$s['day']] ?? '').' '.$s['start'], $slots));
    }

    public static function timeLabel(array $slot): string
    {
        return $slot['start'].(! empty($slot['end']) ? ' - '.$slot['end'] : '');
    }

    // ------------------------------------------------------------------ Khoá học ↔ ca

    /**
     * Khoá học (trong danh sách khoá đã đăng ký) mà một ca thuộc về:
     * khoá có chọn ca này ở "Chọn lịch cho trang điểm danh", nếu không thì khoá đang học đầu tiên không chọn ca riêng.
     */
    public static function courseItemForSlot(Student $student, ?string $slotId): ?array
    {
        $items = $student->registered_courses_list;
        if ($items === []) {
            return null;
        }

        if ($slotId !== null) {
            foreach ($items as $item) {
                if (in_array($slotId, $item['schedule_ids'] ?? [], true)) {
                    return $item;
                }
            }
        }

        $general = array_values(array_filter($items, fn ($i) => empty($i['schedule_ids'])));
        $active = array_values(array_filter($general, fn ($i) => ($i['remaining_sessions'] ?? 1) > 0)) ?: $general;

        return $active[0] ?? $items[0];
    }

    /**
     * Trừ (delta = 1) hoặc trả lại (delta = -1) một buổi vào đúng khoá học, cập nhật cả tổng của học viên.
     * Không lưu: người gọi save() học viên.
     */
    public static function consume(Student $student, ?int $courseItemId, int $delta): void
    {
        $student->remaining_sessions = max(0, (int) $student->remaining_sessions - $delta);

        if (empty($student->registered_courses) || $courseItemId === null) {
            return;
        }

        $list = $student->registered_courses;
        foreach ($list as $key => $item) {
            if ((int) ($item['id'] ?? 0) === $courseItemId) {
                $attended = max(0, (int) ($item['attended_sessions'] ?? 0) + $delta);
                $list[$key]['attended_sessions'] = $attended;
                $list[$key]['remaining_sessions'] = max(0, (int) $item['total_sessions'] - $attended);
                $list[$key]['status'] = $list[$key]['remaining_sessions'] <= 0 ? 'completed' : 'active';
                break;
            }
        }
        $student->registered_courses = $list;
        $student->total_sessions = array_sum(array_column($list, 'total_sessions'));
        $student->remaining_sessions = array_sum(array_column($list, 'remaining_sessions'));
    }

    /**
     * Cộng (delta > 0) / bớt (delta < 0) số buổi đã mua khi thu học phí, vào khoá đăng ký gần nhất của $courseId
     * (không có thì khoá đang học gần nhất). Phải cộng vào khoá, vì tổng của học viên luôn được tính lại từ các khoá
     * mỗi lần điểm danh. Không lưu: người gọi save() học viên.
     */
    public static function addSessions(Student $student, ?int $courseId, int $delta): void
    {
        if (empty($student->registered_courses)) {
            $student->total_sessions = max(0, (int) $student->total_sessions + $delta);
            $student->remaining_sessions = max(0, (int) $student->remaining_sessions + $delta);

            return;
        }

        $list = $student->registered_courses;
        $keys = array_keys($list);
        $key = collect($keys)->last(fn ($k) => (int) ($list[$k]['course_id'] ?? 0) === (int) $courseId)
            ?? collect($keys)->last(fn ($k) => ($list[$k]['status'] ?? 'active') !== 'completed')
            ?? end($keys);

        $attended = (int) ($list[$key]['attended_sessions'] ?? 0);
        $list[$key]['total_sessions'] = max($attended, (int) $list[$key]['total_sessions'] + $delta);
        $list[$key]['remaining_sessions'] = $list[$key]['total_sessions'] - $attended;
        $list[$key]['status'] = $list[$key]['remaining_sessions'] > 0 ? 'active' : 'completed';

        $student->registered_courses = $list;
        $student->total_sessions = array_sum(array_column($list, 'total_sessions'));
        $student->remaining_sessions = array_sum(array_column($list, 'remaining_sessions'));
    }

    /**
     * Khoá học đã đăng ký theo id (danh sách tự dựng từ cột cũ nếu học viên chưa có registered_courses).
     */
    public static function courseItem(Student $student, ?int $courseItemId): ?array
    {
        return $courseItemId === null ? null : collect($student->registered_courses_list)->firstWhere('id', $courseItemId);
    }

    public static function remainingSessions(Student $student, ?int $courseItemId): int
    {
        return (int) (self::courseItem($student, $courseItemId)['remaining_sessions'] ?? $student->remaining_sessions);
    }

    public static function attendedSessions(Student $student, ?int $courseItemId): int
    {
        $item = self::courseItem($student, $courseItemId);

        return (int) ($item['attended_sessions'] ?? ((int) $student->total_sessions - (int) $student->remaining_sessions));
    }

    /**
     * Số buổi còn được nghỉ có phép của khoá (số buổi được nghỉ của khoá − số buổi đã nghỉ có phép).
     */
    public static function leaveLeft(Student $student, ?int $courseItemId): int
    {
        $item = self::courseItem($student, $courseItemId);
        $allowed = (int) ($item['allowed_absences'] ?? $student->allowed_absences ?? 3);

        return max(0, $allowed - (int) ($item['absences_used'] ?? $student->absences_used ?? 0));
    }

    /**
     * Dùng (delta = 1) hoặc trả lại (delta = -1) một lượt nghỉ có phép của khoá. Không lưu: người gọi save() học viên.
     */
    public static function useLeave(Student $student, ?int $courseItemId, int $delta): void
    {
        $student->absences_used = max(0, (int) $student->absences_used + $delta);

        if (empty($student->registered_courses) || $courseItemId === null) {
            return;
        }

        $list = $student->registered_courses;
        foreach ($list as $key => $item) {
            if ((int) ($item['id'] ?? 0) === $courseItemId) {
                $list[$key]['absences_used'] = max(0, (int) ($item['absences_used'] ?? 0) + $delta);
                break;
            }
        }
        $student->registered_courses = $list;
    }

    // ------------------------------------------------------------------ Ngày nghỉ (trung tâm off / giáo viên nghỉ)

    /**
     * Ca $slot ngày $date không học vì trung tâm nghỉ hoặc giáo viên của ca nghỉ phép mà không có người dạy thay.
     *
     * @return array{type: 'center'|'teacher', label: string, reason: ?string}|null
     */
    public static function offReason(Student $student, string $date, array $slot, ?array $periods = null): ?array
    {
        $periods ??= TeacherLeaves::all();

        if ($off = TeacherLeaves::centerOffFor($student->center, $date, $periods)) {
            return ['type' => 'center', 'label' => 'Trung tâm nghỉ', 'reason' => $off['reason'] ?? null];
        }

        $teacher = $slot['teacher'] ?? null;
        if ($teacher && ! TeacherLeaves::substitutionFor($teacher, $date, $periods)
            && $leave = TeacherLeaves::leaveFor($teacher, $date, $periods)) {
            return ['type' => 'teacher', 'label' => 'Giáo viên nghỉ', 'reason' => $leave['reason'] ?? null];
        }

        return null;
    }

    /**
     * Các ca trong lịch tuần rơi vào ngày nghỉ, từ $from đến $to (tối đa 2 năm).
     *
     * @return list<array{date: Carbon, slot: array, off: array}>
     */
    public static function offSessions(Student $student, Carbon $from, Carbon $to): array
    {
        $periods = TeacherLeaves::all();
        if ($periods['center_offs'] === [] && $periods['leaves'] === []) {
            return [];
        }

        $result = [];
        $day = $from->copy()->startOfDay();
        for ($i = 0; $i < 730 && $day->lte($to); $i++, $day = $day->copy()->addDay()) {
            foreach (self::slotsOnDate($student, $day->toDateString()) as $slot) {
                if ($off = self::offReason($student, $day->toDateString(), $slot, $periods)) {
                    $result[] = ['date' => $day->copy(), 'slot' => $slot, 'off' => $off];
                }
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------ Điểm danh theo ca

    /**
     * Ca hôm nay chưa được điểm danh (để nút "Điểm danh hôm nay" điểm danh ca kế tiếp).
     * Trả về [slot|null, đã hết ca chưa điểm danh hay chưa].
     */
    public static function nextUnmarkedSlotToday(Student $student): array
    {
        $today = now();
        $slots = self::slotsOnDate($student, $today->toDateString());
        $marked = Attendance::where('student_id', $student->id)->whereDate('attended_at', $today->toDateString())->get();

        if ($slots === []) {
            // Không có lịch hôm nay: cho điểm danh 1 lần trong ngày
            return [null, $marked->where('status', 'present')->isNotEmpty()];
        }

        $markedSlotIds = $marked->pluck('slot_id')->all();
        foreach ($slots as $i => $slot) {
            // Bản ghi cũ không có slot_id được tính cho ca đầu tiên trong ngày
            $isMarked = in_array($slot['id'], $markedSlotIds, true) || ($i === 0 && in_array(null, $markedSlotIds, true));
            if (! $isMarked) {
                return [$slot, false];
            }
        }

        return [null, true];
    }

    /**
     * Ngày học dự kiến của $count buổi tiếp theo, đi lần lượt theo các ca trong lịch tuần.
     * Bắt đầu từ hôm nay, học viên tạm dừng thì từ ngày hết bảo lưu, và không sớm hơn ngày nhập học.
     * Bỏ các ca đã điểm danh / đã xin nghỉ trước và các ca rơi vào ngày nghỉ (trung tâm off, giáo viên nghỉ),
     * nên ngày kết thúc tự lùi lại. Học viên đã nghỉ / chưa có lịch thì không có ngày dự kiến.
     * $slotIds: chỉ tính các ca của một khoá (null = mọi ca). $from: ngày bắt đầu khoá nếu muộn hơn hôm nay.
     *
     * @return list<array{date: Carbon, slot: array}>
     */
    public static function upcomingSessions(Student $student, int $count, ?array $slotIds = null, ?string $from = null): array
    {
        $slots = self::slots($student);
        if ($slotIds) {
            $slots = array_values(array_filter($slots, fn ($s) => in_array($s['id'], $slotIds, true)));
        }
        if ($count <= 0 || $slots === [] || in_array($student->status, ['inactive', 'dropped'], true)) {
            return [];
        }
        $slotsOn = fn (int $dow) => array_values(array_filter($slots, fn ($s) => (int) $s['day'] === $dow));

        $day = now()->startOfDay();
        foreach ([$student->join_date, $from] as $start) {
            if ($start && Carbon::parse($start)->gt($day)) {
                $day = Carbon::parse($start)->startOfDay();
            }
        }
        if ($student->status === 'paused' && $student->paused_until && Carbon::parse($student->paused_until)->gt($day)) {
            $day = Carbon::parse($student->paused_until)->startOfDay();
        }

        // Ca đã điểm danh (có mặt / vắng) hoặc phụ huynh đã xin nghỉ trước thì không còn là buổi sắp tới.
        // Bản ghi cũ không có ca được tính cho ca đầu tiên trong ngày.
        $marked = Attendance::where('student_id', $student->id)->whereDate('attended_at', '>=', $day->toDateString())
            ->get(['attended_at', 'slot_id'])
            ->groupBy(fn ($a) => $a->attended_at->toDateString())
            ->map(fn ($g) => $g->pluck('slot_id')->all());
        $isMarked = function (Carbon $date, array $slot) use ($marked, $student): bool {
            $ids = $marked->get($date->toDateString(), []);

            return in_array($slot['id'], $ids, true)
                || (in_array(null, $ids, true) && (self::slotsOn($student, $date->dayOfWeek)[0]['id'] ?? null) === $slot['id']);
        };
        $periods = TeacherLeaves::all();

        $result = [];
        // Giới hạn 2 năm để không lặp vô hạn khi lịch lỗi
        for ($i = 0; $i < 730 && count($result) < $count; $i++, $day = $day->copy()->addDay()) {
            $started = array_column(self::slotsOnDate($student, $day->toDateString()), 'id');
            foreach ($slotsOn($day->dayOfWeek) as $slot) {
                if (! in_array($slot['id'], $started, true) || $isMarked($day, $slot) || self::offReason($student, $day->toDateString(), $slot, $periods)) {
                    continue;
                }
                $result[] = ['date' => $day->copy(), 'slot' => $slot];
                if (count($result) >= $count) {
                    break;
                }
            }
        }

        return $result;
    }
}
