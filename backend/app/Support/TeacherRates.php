<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Lương ca dạy riêng của từng giáo viên / giáo vụ (lưu shift_rates.json), đặt ở màn Nhân sự khi tạo / sửa:
 *  - group      : tiền mỗi ca nhóm
 *  - one_on_one : tiền mỗi ca 1:1
 * Mỗi lần check-in ca dạy được cộng tiền theo mức của người dạy (chốt lúc check-in).
 * Chưa đặt lương thì ca tính 0 đồng — không có mức chung cho mọi giáo viên.
 *
 * Hệ số theo điểm danh học viên (payFactor()):
 *  - Ca 1:1: học viên vắng không phép → 50%; vắng có phép → không tính lương (không có dạy).
 *  - Ca nhóm: số học viên đi học ≤ một nửa sĩ số lớp nhóm của cơ sở (mặc định 4 → ≤ 2 HV) → 50%.
 *
 * Ca check-in trước khi đổi cách tính có thể còn pay_mode per_student / free: giữ cách tính cũ cho các ca đó
 * (legacyAmount) để thu nhập các tháng trước không đổi.
 */
class TeacherRates
{
    private const PATH = 'shift_rates.json';

    // Sĩ số lớp nhóm mặc định khi cơ sở chưa đặt (lớp nhóm của trung tâm 4 bạn)
    public const DEFAULT_GROUP_SIZE = 4;

    /**
     * @return array<string, array{name: string, group: float, one_on_one: float}>
     */
    public static function all(): array
    {
        $data = Storage::exists(self::PATH) ? json_decode(Storage::get(self::PATH), true) : null;

        return is_array($data['teachers'] ?? null) ? $data['teachers'] : [];
    }

    private static function save(array $teachers): void
    {
        Storage::put(self::PATH, json_encode(['teachers' => $teachers], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Lương ca đang đặt của một người (chưa đặt = 0).
     *
     * @return array{group: float, one_on_one: float}
     */
    public static function forTeacher(?string $name): array
    {
        $cfg = self::all()[TeacherName::key($name)] ?? [];

        return ['group' => (float) ($cfg['group'] ?? 0), 'one_on_one' => (float) ($cfg['one_on_one'] ?? 0)];
    }

    /**
     * Đặt lương ca của giáo viên / giáo vụ. Ô để trống (null) giữ mức đang có.
     */
    public static function set(string $name, ?float $group, ?float $oneOnOne): void
    {
        $teachers = self::all();
        $current = self::forTeacher($name);
        $teachers[TeacherName::key($name)] = [
            'name' => $name,
            'group' => $group ?? $current['group'],
            'one_on_one' => $oneOnOne ?? $current['one_on_one'],
        ];
        self::save($teachers);
    }

    /**
     * Đơn giá áp cho một ca, chốt tại thời điểm check-in (đổi lương sau này không ảnh hưởng ca đã tính).
     *
     * @return array{pay_mode: string, unit_rate: float, group_size?: int}
     */
    public static function quote(?string $teacher, bool $isOneOnOne, ?string $center = null): array
    {
        $pay = self::forTeacher($teacher);

        return [
            'pay_mode' => 'per_shift',
            'unit_rate' => $isOneOnOne ? $pay['one_on_one'] : $pay['group'],
            ...($isOneOnOne ? [] : ['group_size' => self::groupSize($center)]),
        ];
    }

    /**
     * Sĩ số lớp nhóm của cơ sở (để xét "đi học ≤ một nửa thì nhận 50%").
     */
    public static function groupSize(?string $center): int
    {
        foreach (CenterStore::all() as $c) {
            if ($c['name'] === $center && (int) ($c['group_size'] ?? 0) > 0) {
                return (int) $c['group_size'];
            }
        }

        return self::DEFAULT_GROUP_SIZE;
    }

    /**
     * Hệ số lương của ca theo điểm danh học viên của ca.
     * $statuses: trạng thái điểm danh từng học viên của ca (student_id => status), học viên chưa điểm danh thì không có.
     * Học viên chưa điểm danh được coi là đi học (ca vẫn đủ lương cho tới khi giáo vụ điểm danh vắng).
     *
     * @return array{factor: float, note: ?string}
     */
    public static function payFactor(array $shift, array $statuses): array
    {
        $ids = $shift['student_ids'] ?? [];
        if ($ids === []) {
            return ['factor' => 1.0, 'note' => null];
        }

        if (($shift['type'] ?? '') === 'Ca 1-1') {
            return match ($statuses[$ids[0]] ?? null) {
                'absent_without_leave' => ['factor' => 0.5, 'note' => 'HV vắng không phép · 50%'],
                'absent_with_leave' => ['factor' => 0.0, 'note' => 'HV vắng có phép · không tính lương'],
                default => ['factor' => 1.0, 'note' => null],
            };
        }

        $size = (int) ($shift['group_size'] ?? 0) ?: self::groupSize($shift['center'] ?? null);
        $absent = count(array_filter($ids, fn ($id) => str_starts_with((string) ($statuses[$id] ?? ''), 'absent')));
        $attending = count($ids) - $absent;

        return $attending * 2 <= $size
            ? ['factor' => 0.5, 'note' => "{$attending}/{$size} HV đi học · 50%"]
            : ['factor' => 1.0, 'note' => null];
    }

    /**
     * Thù lao ca check-in theo cách tính cũ (trước khi đặt lương riêng ở Nhân sự), null nếu ca theo cách mới.
     *  - per_student: đơn giá × số HV có mặt
     *  - free ("Dạy tự do"): số HV có mặt > ngưỡng thì giá/lượt × số HV, ≤ ngưỡng thì giá/ca
     */
    public static function legacyAmount(array $shift, int $present): ?float
    {
        return match ($shift['pay_mode'] ?? null) {
            'per_student' => ($shift['unit_rate'] ?? 0) * $present,
            'free' => ($shift['free_threshold'] ?? null) !== null && $present > $shift['free_threshold']
                ? ($shift['unit_rate'] ?? 0) * $present
                : (float) ($shift['free_per_shift'] ?? 0),
            default => null,
        };
    }
}
