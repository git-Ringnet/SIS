<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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
     * Ca đầu tiên trong ngày (như dòng đầu tiên trên trang Điểm danh): ca theo lịch, không có thì buổi học lẻ / học bù
     * đầu tiên của ngày. Bản ghi điểm danh cũ không có ca (slot_id = null) được tính cho ca này.
     */
    public static function firstSlotIdOn(Student $student, string $date): ?string
    {
        $date = Carbon::parse($date)->toDateString();

        return self::slotsOnDate($student, $date)[0]['id']
            ?? collect(SingleLessons::forStudent($student->id))->firstWhere('date', $date)['id']
            ?? null;
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
            $count = self::sessionsBeforeSchedule($student, $item, $day, $perDay);
            for ($i = 0; $i < 3650; $i++, $day = $day->copy()->addDay()) {
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

    /**
     * Số buổi đã trừ của khóa học từ ngày bắt đầu đến trước buổi đầu tiên theo lịch tuần
     * (vd bắt đầu học T5 29/10 rồi mới xếp lịch T3: buổi 29/10 đã là buổi 1, lịch T3 đếm tiếp từ buổi 2).
     * Buổi bù / học lẻ sau khi đã vào lịch không tính, để ngày kết thúc vẫn cố định.
     */
    private static function sessionsBeforeSchedule(Student $student, ?array $item, Carbon $from, array $perDay): int
    {
        $firstScheduled = $from->copy();
        while (! isset($perDay[$firstScheduled->dayOfWeek])) {
            $firstScheduled->addDay();
        }
        if ($firstScheduled->eq($from)) {
            return 0;
        }

        $key = self::itemKey($student, isset($item['id']) ? (int) $item['id'] : null);
        $attendances = $student->relationLoaded('attendances') ? $student->attendances : $student->attendances()->get();

        return $attendances->filter(fn ($a) => $a->deductsSession()
            && self::itemKey($student, $a->course_item_id) === $key
            && $a->attended_at->toDateString() >= $from->toDateString()
            && $a->attended_at->toDateString() < $firstScheduled->toDateString())->count();
    }

    /**
     * Ca học cố định của học viên ngày $date trùng giờ với buổi học lẻ / học bù $time ("20:00" hoặc "20:00 - 21:00"),
     * bỏ qua ca rơi vào ngày trung tâm / giáo viên nghỉ. Dùng để cảnh báo khi giáo vụ đặt buổi bù trùng buổi học thường.
     */
    public static function regularSlotAt(Student $student, string $date, string $time): ?array
    {
        foreach (self::slotsOnDate($student, $date) as $slot) {
            if (self::overlapsLesson($slot, $time) && ! self::offReason($student, $date, $slot)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Mã các ca theo lịch ngày $date bị buổi học lẻ / học bù của chính học viên trùng giờ thay thế:
     * học viên không thể học 2 buổi cùng lúc, nên hôm đó chỉ có buổi bù (một buổi, một số, trừ một buổi, một ca dạy).
     * $lessons: buổi học lẻ / học bù của học viên (truyền vào khi gọi nhiều ngày để khỏi đọc file nhiều lần).
     *
     * @return list<string>
     */
    public static function replacedSlotIds(Student $student, string $date, ?array $lessons = null): array
    {
        $times = array_column(array_filter($lessons ?? SingleLessons::forStudent($student->id),
            fn ($l) => ($l['date'] ?? null) === $date && ! empty($l['time'])), 'time');
        if ($times === []) {
            return [];
        }

        return array_values(array_map(fn ($s) => $s['id'], array_filter(self::slotsOnDate($student, $date),
            fn ($slot) => array_filter($times, fn ($t) => self::overlapsLesson($slot, $t)) !== [])));
    }

    /**
     * Ca $slot có chồng giờ với buổi học lẻ / học bù giờ $time ("20:00" hoặc "20:00 - 21:00", thiếu giờ kết thúc = 1 tiếng).
     */
    private static function overlapsLesson(array $slot, string $time): bool
    {
        [$start, $end] = SingleLessons::timeRange(['time' => $time]);
        if (! $start || empty($slot['start'])) {
            return false;
        }
        $end ??= Carbon::parse($start)->addHour()->format('H:i');
        $slotEnd = ($slot['end'] ?? null) ?: Carbon::parse($slot['start'])->addHour()->format('H:i');

        return $start < $slotEnd && $slot['start'] < $end;
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

    // ------------------------------------------------------------------ Đánh số buổi học

    /**
     * Ca của bản ghi điểm danh; bản ghi không có ca được tính cho ca đầu tiên trong ngày như trang Điểm danh
     * (ngày không có ca theo lịch thì là buổi học lẻ / học bù đầu tiên của ngày).
     */
    public static function slotIdOf(Student $student, Attendance $attendance): ?string
    {
        return self::matchingSlotId($student, $attendance) ?? self::firstSlotIdOn($student, $attendance->attended_at->toDateString());
    }

    /**
     * Ca theo lịch hiện tại của một bản ghi điểm danh. Lịch học sửa sau khi đã điểm danh có thể đổi thứ của một ca
     * mà vẫn giữ mã ca (vd ca Thứ 5 sửa thành Thứ 3): khi đó buổi đã điểm danh thuộc ca của đúng thứ ngày điểm danh,
     * ưu tiên ca trùng giờ. Buổi học lẻ / học bù giữ nguyên.
     */
    public static function matchingSlotId(Student $student, Attendance $attendance): ?string
    {
        $id = $attendance->slot_id;
        if ($id === null || str_starts_with($id, 'lesson_')) {
            return $id;
        }

        $dayOfWeek = $attendance->attended_at->dayOfWeek;
        $slot = self::find($student, $id);
        if ($slot && (int) $slot['day'] === $dayOfWeek) {
            return $id;
        }

        $sameDay = self::slotsOn($student, $dayOfWeek);
        if ($sameDay === []) {
            return $slot ? null : $id; // ca đã sửa sang thứ khác và ngày đó không còn ca nào
        }

        return (collect($sameDay)->firstWhere('start', $attendance->attended_at->format('H:i')) ?? $sameDay[0])['id'];
    }

    /**
     * Gắn lại các bản ghi điểm danh đang trỏ vào ca không còn đúng thứ (sau khi sửa lịch học). Không đổi buổi đã trừ.
     * Ngày đó mà ca đúng thứ đã có điểm danh riêng thì bản ghi lệch là bản ghi thừa (bấm điểm danh khi danh sách của
     * ngày khác chưa tải lại): xoá và trả lại buổi đã trừ, giữ báo bài nếu bản ghi đúng chưa có.
     *
     * @return array{relinked: int, removed: list<int>} số bản ghi đã gắn lại, id các bản ghi thừa đã xoá
     */
    public static function relinkAttendanceSlots(Student $student): array
    {
        $relinked = 0;
        $removed = [];
        $all = Attendance::where('student_id', $student->id)->get();
        foreach ($all->whereNotNull('slot_id') as $attendance) {
            $slotId = self::matchingSlotId($student, $attendance);
            if ($slotId === $attendance->slot_id) {
                continue;
            }

            $date = $attendance->attended_at->toDateString();
            $twin = $all->first(fn ($a) => $a->id !== $attendance->id && ! in_array($a->id, $removed, true)
                && $a->attended_at->toDateString() === $date && $a->slot_id !== null && $a->slot_id === $slotId);
            if ($twin) {
                if ($attendance->hasLessonReport() && ! $twin->hasLessonReport()) {
                    $twin->note = $attendance->note;
                    $twin->saveQuietly();
                }
                AttendanceMarker::remove($student, $attendance);
                $removed[] = $attendance->id;

                continue;
            }

            $attendance->slot_id = $slotId;
            $attendance->saveQuietly();
            $relinked++;
        }

        return ['relinked' => $relinked, 'removed' => $removed];
    }

    /**
     * Khóa của một bản ghi điểm danh (bản ghi cũ chưa gắn khóa tính cho khóa đầu tiên), dạng chuỗi để làm khóa mảng.
     */
    public static function itemKey(Student $student, ?int $courseItemId): string
    {
        return (string) ($courseItemId ?? $student->registered_courses_list[0]['id'] ?? '');
    }

    /**
     * Buổi theo lịch đã qua (tới hôm qua, trong thời gian khóa) mà trung tâm chưa điểm danh, không phải ngày nghỉ.
     * Vẫn là một buổi của khóa (đánh số, dùng 1 buổi còn lại) cho tới khi giáo vụ điểm danh.
     *
     * @return list<array{date: Carbon, slot: array, item: ?array, key: string}> key = "Y-m-d|mã ca"
     */
    public static function unmarkedSessions(Student $student, Collection $attendances): array
    {
        if ($student->status !== 'active') {
            return [];
        }

        $recorded = [];
        $firstMarked = []; // ngày điểm danh đầu tiên của từng khóa
        foreach ($attendances as $attendance) {
            $date = $attendance->attended_at->toDateString();
            $recorded[$date.'|'.self::slotIdOf($student, $attendance)] = true;
            $itemKey = self::itemKey($student, $attendance->course_item_id);
            $firstMarked[$itemKey] = min($firstMarked[$itemKey] ?? $date, $date);
        }

        $yesterday = Carbon::yesterday();
        $periods = TeacherLeaves::all();
        $lessons = SingleLessons::forStudent($student->id);
        $result = [];
        foreach ($student->registered_courses_list ?: [null] as $item) {
            $slotIds = array_column(self::courseSlots($student, $item), 'id');
            // Chỉ xét từ buổi điểm danh đầu tiên của khóa trên hệ thống: trước đó là giai đoạn chưa dùng phần mềm
            // (số buổi đã học nằm trong "Đã học" nhập tay), không phải buổi bị quên điểm danh
            $from = ($item['start_date'] ?? null) ?: $student->join_date;
            $firstDate = $firstMarked[self::itemKey($student, $item['id'] ?? null)] ?? null;
            if (($item['status'] ?? 'active') === 'completed' || $slotIds === [] || ! $from || ! $firstDate) {
                continue;
            }
            $from = max(Carbon::parse($from)->toDateString(), $firstDate);
            // Xét tới hôm qua (khóa còn buổi thì vẫn học tiếp sau ngày kết thúc dự kiến), không quá số buổi còn lại của khóa
            $left = (int) ($item['remaining_sessions'] ?? $student->remaining_sessions);

            for ($day = Carbon::parse($from)->startOfDay()->max($yesterday->copy()->subYear()); $day->lte($yesterday) && $left > 0; $day = $day->copy()->addDay()) {
                $replaced = self::replacedSlotIds($student, $day->toDateString(), $lessons);
                foreach (self::slotsOnDate($student, $day->toDateString()) as $slot) {
                    $key = $day->toDateString().'|'.$slot['id'];
                    // Ca đã được buổi bù trùng giờ thay thế thì không phải buổi bị quên điểm danh
                    if ($left <= 0 || ! in_array($slot['id'], $slotIds, true) || isset($recorded[$key]) || in_array($slot['id'], $replaced, true)
                        || self::offReason($student, $day->toDateString(), $slot, $periods)) {
                        continue;
                    }
                    $left--;
                    $recorded[$key] = true;
                    $result[] = ['date' => $day->copy()->setTimeFromTimeString($slot['start']), 'slot' => $slot, 'item' => $item, 'key' => $key];
                }
            }
        }

        return $result;
    }

    /**
     * Đánh số "Buổi n", riêng từng khóa, theo thứ tự thời gian (cùng cách với Cổng phụ huynh và trang Điểm danh):
     * mọi buổi có trừ buổi đều có số (đã học, vắng không phép, nghỉ có phép hết lượt, kể cả học bù / học lẻ), và buổi
     * theo lịch đã qua mà chưa điểm danh. Chỉ buổi nghỉ có phép không trừ buổi không có số (ngày trung tâm / giáo viên
     * nghỉ không có điểm danh). Buổi đã học trước khi dùng hệ thống (đã trừ vào số buổi nhưng không có điểm danh) tính ở đầu.
     *
     * @return array{numbers: array<int, int>, unmarked: array<string, int>, last: array<string, int>}
     *         số của từng bản ghi (id => n), của từng buổi chưa điểm danh (key => n) và số cuối của từng khóa
     */
    public static function officialNumbers(Student $student, Collection $attendances): array
    {
        $last = [];
        foreach ($student->registered_courses_list ?: [null] as $item) {
            $key = self::itemKey($student, $item['id'] ?? null);
            $deducting = $attendances->filter(fn ($a) => $a->deductsSession() && self::itemKey($student, $a->course_item_id) === $key)->count();
            $last[$key] = max(0, self::attendedSessions($student, $item['id'] ?? null) - $deducting);
        }

        // Bản ghi trừ buổi và buổi chưa điểm danh, xếp theo thời gian rồi đánh số liên tiếp trong từng khóa
        $entries = [];
        foreach ($attendances as $a) {
            if ($a->deductsSession()) {
                $entries[] = ['at' => $a->attended_at->format('Y-m-d H:i'), 'order' => $a->id, 'item' => self::itemKey($student, $a->course_item_id), 'id' => $a->id];
            }
        }
        foreach (self::unmarkedSessions($student, $attendances) as $session) {
            $entries[] = ['at' => $session['date']->format('Y-m-d H:i'), 'order' => 0, 'item' => self::itemKey($student, $session['item']['id'] ?? null),
                'unmarked' => $session['key']];
        }
        usort($entries, fn ($x, $y) => [$x['at'], $x['order']] <=> [$y['at'], $y['order']]);

        $numbers = [];
        $unmarked = [];
        foreach ($entries as $entry) {
            $n = $last[$entry['item']] = ($last[$entry['item']] ?? 0) + 1;
            if (isset($entry['id'])) {
                $numbers[$entry['id']] = $n;
            } else {
                $unmarked[$entry['unmarked']] = $n;
            }
        }

        return ['numbers' => $numbers, 'unmarked' => $unmarked, 'last' => $last];
    }

    /**
     * Lưu lại số buổi chính thức vào các bản ghi điểm danh của học viên (xuất Excel, chấm điểm danh gần đây),
     * và sửa số trong ghi chú tự điền "Điểm danh buổi #n". Gọi sau mỗi lần điểm danh / hoàn tác.
     */
    public static function syncOfficialNumbers(Student $student): void
    {
        $attendances = Attendance::where('student_id', $student->id)->get();
        $numbers = self::officialNumbers($student, $attendances)['numbers'];

        foreach ($attendances as $a) {
            $number = $numbers[$a->id] ?? null;
            $a->session_number = $number;
            if (preg_match('/^Điểm danh buổi #\d*$/u', trim((string) $a->note))) {
                $a->note = $number ? "Điểm danh buổi #{$number}" : 'Điểm danh buổi bù / học lẻ';
            }
            if ($a->isDirty()) {
                $a->saveQuietly();
            }
        }
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

        // Giáo viên nghỉ theo khung giờ chỉ làm off các ca trong khung giờ đó, ca khác trong ngày vẫn học
        $teacher = $slot['teacher'] ?? null;
        $start = $slot['start'] ?? null;
        $end = $slot['end'] ?? null;
        if ($teacher && ! TeacherLeaves::substitutionFor($teacher, $date, $periods, $start, $end)
            && $leave = TeacherLeaves::leaveFor($teacher, $date, $periods, $start, $end)) {
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
     * Ca hôm nay chưa được điểm danh (để nút "Điểm danh hôm nay" điểm danh ca kế tiếp): các ca theo lịch rồi tới buổi
     * học lẻ / học bù của hôm nay (giống thứ tự dòng ở trang Điểm danh), để buổi bù được gắn đúng buổi bù.
     * Trả về [slot|null, đã hết ca chưa điểm danh hay chưa].
     */
    public static function nextUnmarkedSlotToday(Student $student): array
    {
        $today = now();
        $lessons = array_values(array_filter(SingleLessons::forStudent($student->id), fn ($l) => ($l['date'] ?? null) === $today->toDateString()));
        // Ca theo lịch bị buổi bù trùng giờ thay thế thì điểm danh vào buổi bù
        $replaced = self::replacedSlotIds($student, $today->toDateString(), $lessons);
        $slots = [...array_filter(self::slotsOnDate($student, $today->toDateString()), fn ($s) => ! in_array($s['id'], $replaced, true)),
            ...array_map(fn ($l) => ['id' => $l['id']], $lessons)];
        $slots = array_values($slots);
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
     * Các ca sắp tới còn buổi để học, theo số buổi còn lại của từng khóa (từ hôm nay): ["Y-m-d|slotId" => true].
     * Trang Điểm danh dùng để không hiện học viên vào những tuần sau khi khóa đã hết buổi.
     */
    public static function upcomingKeys(Student $student): array
    {
        $keys = [];
        foreach (self::plannedSessions($student) as $session) {
            $keys[$session['date']->toDateString().'|'.$session['slot']['id']] = true;
        }

        return $keys;
    }

    /**
     * Buổi sắp tới theo lịch tuần của từng khóa (Cổng phụ huynh và trang Điểm danh dùng chung, để luôn khớp nhau):
     * đủ số buổi còn lại của khóa, trừ buổi bù / học lẻ đã đặt và buổi đã qua chưa điểm danh (các buổi đó cũng sẽ trừ buổi).
     * Nghỉ có phép không trừ buổi / trung tâm nghỉ thì buổi cuối tự lùi. Khóa chưa có lịch tuần thì không có buổi nào.
     *
     * @return list<array{date: Carbon, slot: array, item: ?array}>
     */
    public static function plannedSessions(Student $student, ?Collection $attendances = null): array
    {
        $attendances ??= $student->relationLoaded('attendances') ? $student->attendances : $student->attendances()->get();
        $today = Carbon::today()->toDateString();
        $recorded = [];
        foreach ($attendances as $attendance) {
            $recorded[$attendance->attended_at->toDateString().'|'.self::slotIdOf($student, $attendance)] = true;
        }
        $lessons = array_filter(SingleLessons::forStudent($student->id),
            fn ($l) => ($l['date'] ?? '') >= $today && ! isset($recorded[$l['date'].'|'.$l['id']]));
        $unmarked = self::unmarkedSessions($student, $attendances);

        $result = [];
        $seen = [];
        foreach ($student->registered_courses_list ?: [null] as $item) {
            if ($item && ($item['status'] ?? 'active') === 'completed') {
                continue;
            }
            $itemId = $item['id'] ?? null;
            $booked = count(array_filter($lessons, fn ($l) => (self::courseItemForSlot($student, $l['id'])['id'] ?? null) === $itemId))
                + count(array_filter($unmarked, fn ($u) => ($u['item']['id'] ?? null) === $itemId));
            $remaining = max(0, (int) ($item['remaining_sessions'] ?? $student->remaining_sessions) - $booked);
            $slotIds = array_column(self::courseSlots($student, $item), 'id');
            if ($slotIds === []) {
                continue;
            }
            foreach (self::upcomingSessions($student, $remaining, $slotIds, ($item['start_date'] ?? null) ?: null) as $session) {
                $key = $session['date']->toDateString().'|'.$session['slot']['id'];
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $result[] = [...$session, 'item' => $item];
                }
            }
        }

        return $result;
    }

    /**
     * Ngày học dự kiến của $count buổi tiếp theo, đi lần lượt theo các ca trong lịch tuần.
     * Bắt đầu từ hôm nay, học viên tạm dừng thì từ ngày hết bảo lưu, và không sớm hơn ngày nhập học.
     * Bỏ các ca đã điểm danh / đã xin nghỉ trước, ca đã có buổi bù trùng giờ thay thế và các ca rơi vào ngày nghỉ (trung tâm off, giáo viên nghỉ),
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
        $lessons = SingleLessons::forStudent($student->id);

        $result = [];
        // Giới hạn 2 năm để không lặp vô hạn khi lịch lỗi
        for ($i = 0; $i < 730 && count($result) < $count; $i++, $day = $day->copy()->addDay()) {
            $started = array_column(self::slotsOnDate($student, $day->toDateString()), 'id');
            $replaced = self::replacedSlotIds($student, $day->toDateString(), $lessons);
            foreach ($slotsOn($day->dayOfWeek) as $slot) {
                // Ca đã có buổi bù / học lẻ trùng giờ thay thế: buổi đó đã là buổi bù (đã trừ khỏi số buổi cần xếp)
                if (! in_array($slot['id'], $started, true) || in_array($slot['id'], $replaced, true) || $isMarked($day, $slot)
                    || self::offReason($student, $day->toDateString(), $slot, $periods)) {
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
