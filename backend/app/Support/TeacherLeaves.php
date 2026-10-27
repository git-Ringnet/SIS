<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Nghỉ phép, phân công dạy thay và ngày nghỉ của trung tâm theo khoảng ngày (lưu teacher_leaves.json).
 * Được áp lên các ca chưa check-in / buổi học chưa điểm danh khi xem, nên xoá một đợt thì các ca đó tự trở lại bình thường.
 */
class TeacherLeaves
{
    private const PATH = 'teacher_leaves.json';

    /**
     * center_offs: [{id, center: tên cơ sở | null (mọi cơ sở), from, to, reason}]
     *
     * @return array{leaves: list<array>, substitutions: list<array>, center_offs: list<array>}
     */
    public static function all(): array
    {
        $data = Storage::exists(self::PATH) ? json_decode(Storage::get(self::PATH), true) : [];

        return [
            'leaves' => $data['leaves'] ?? [],
            'substitutions' => $data['substitutions'] ?? [],
            'center_offs' => $data['center_offs'] ?? [],
        ];
    }

    public static function save(array $data): void
    {
        Storage::put(self::PATH, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private static function covers(array $period, string $date): bool
    {
        return $period['from'] <= $date && $date <= $period['to'];
    }

    /**
     * Đợt có khung giờ (start_time - end_time, mỗi ngày trong khoảng ngày) chỉ áp cho ca giao với khung giờ đó;
     * không có khung giờ = cả ngày. Ca không rõ giờ bắt đầu thì coi như trúng.
     */
    private static function coversTime(array $period, ?string $start, ?string $end): bool
    {
        if (empty($period['start_time']) || empty($period['end_time']) || ! $start) {
            return true;
        }
        $start = substr($start, 0, 5);
        $end = $end ? substr($end, 0, 5) : $start;

        return $start < $period['end_time'] && ($end > $period['start_time'] || $start >= $period['start_time']);
    }

    /**
     * Khung giờ của đợt để hiển thị, ví dụ " 16:00 - 17:00" (cả ngày = chuỗi rỗng).
     */
    public static function timeLabel(array $period): string
    {
        return ! empty($period['start_time']) && ! empty($period['end_time']) ? " {$period['start_time']} - {$period['end_time']}" : '';
    }

    public static function leaveFor(?string $teacher, string $date, ?array $data = null, ?string $start = null, ?string $end = null): ?array
    {
        foreach (($data ?? self::all())['leaves'] as $l) {
            if (TeacherName::same($l['teacher'], $teacher) && self::covers($l, $date) && self::coversTime($l, $start, $end)) {
                return $l;
            }
        }

        return null;
    }

    public static function substitutionFor(?string $teacher, string $date, ?array $data = null, ?string $start = null, ?string $end = null): ?array
    {
        foreach (($data ?? self::all())['substitutions'] as $s) {
            if (TeacherName::same($s['original'], $teacher) && self::covers($s, $date) && self::coversTime($s, $start, $end)) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Đợt nghỉ của trung tâm áp cho cơ sở $center vào ngày $date (đợt không chọn cơ sở áp cho mọi cơ sở).
     */
    public static function centerOffFor(?string $center, string $date, ?array $data = null): ?array
    {
        $center = $center ?: CenterStore::defaultName();
        foreach (($data ?? self::all())['center_offs'] as $off) {
            if ((empty($off['center']) || $off['center'] === $center) && self::covers($off, $date)) {
                return $off;
            }
        }

        return null;
    }

    /**
     * Trạng thái của một đợt so với hôm nay: sắp tới / đang diễn ra / đã kết thúc.
     */
    public static function periodStatus(array $period): string
    {
        $today = Carbon::today()->toDateString();

        return match (true) {
            $period['from'] > $today => 'upcoming',
            $period['to'] < $today => 'ended',
            default => 'active',
        };
    }
}
