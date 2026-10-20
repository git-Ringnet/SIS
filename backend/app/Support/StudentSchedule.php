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

    // ------------------------------------------------------------------ Điểm danh theo ca

    /**
     * Ca hôm nay chưa được điểm danh (để nút "Điểm danh hôm nay" điểm danh ca kế tiếp).
     * Trả về [slot|null, đã hết ca chưa điểm danh hay chưa].
     */
    public static function nextUnmarkedSlotToday(Student $student): array
    {
        $today = now();
        $slots = self::slotsOn($student, $today->dayOfWeek);
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
     * Bắt đầu từ hôm nay (bỏ các ca hôm nay đã điểm danh), học viên tạm dừng thì từ ngày hết bảo lưu,
     * và không sớm hơn ngày nhập học. Học viên đã nghỉ / chưa có lịch thì không có ngày dự kiến.
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

        // Ca hôm nay đã điểm danh (có mặt / vắng) thì không còn là buổi sắp tới
        $markedToday = [];
        if ($day->isToday()) {
            $marked = Attendance::where('student_id', $student->id)->whereDate('attended_at', $day->toDateString())->pluck('slot_id')->all();
            foreach (self::slotsOn($student, $day->dayOfWeek) as $i => $slot) {
                if (in_array($slot['id'], $marked, true) || ($i === 0 && in_array(null, $marked, true))) {
                    $markedToday[] = $slot['id'];
                }
            }
        }

        $result = [];
        // Giới hạn 2 năm để không lặp vô hạn khi lịch lỗi
        for ($i = 0; $i < 730 && count($result) < $count; $i++, $day = $day->copy()->addDay()) {
            foreach ($slotsOn($day->dayOfWeek) as $slot) {
                if ($i === 0 && in_array($slot['id'], $markedToday, true)) {
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
