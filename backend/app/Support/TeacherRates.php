<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Đơn giá ca dạy theo từng giáo viên (lưu shift_rates.json).
 *
 * Mỗi giáo viên chọn một kiểu tính cho ca nhóm:
 *  - per_shift   : tiền cố định mỗi ca nhóm
 *  - per_student : đơn giá × số học viên có mặt trong ca
 *  - free        : "Dạy tự do" (mô hình mỹ thuật): số HV điểm danh có mặt > ngưỡng thì trả giá/lượt × số HV,
 *                  ≤ ngưỡng thì trả giá/ca dạy. Ô nào để trống dùng mức mặc định của cơ sở (màn Trung tâm).
 * Ca 1:1 luôn tính theo đơn giá 1:1. Có thể đặt đơn giá riêng theo khoá học; khoá nào để trống dùng đơn giá chung
 * của giáo viên đó.
 */
class TeacherRates
{
    private const PATH = 'shift_rates.json';

    public const MODES = ['per_shift', 'per_student', 'free'];

    /**
     * @return array<string, array{name: string, mode: string, group: float, one_on_one: float, courses: array<string, array{group: ?float, one_on_one: ?float}>}>
     */
    public static function all(): array
    {
        $data = Storage::exists(self::PATH) ? json_decode(Storage::get(self::PATH), true) : null;

        // Định dạng cũ (một đơn giá chung cho mọi giáo viên) không còn dùng
        return is_array($data['teachers'] ?? null) ? $data['teachers'] : [];
    }

    public static function save(array $teachers): void
    {
        Storage::put(self::PATH, json_encode(['teachers' => $teachers], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public static function forTeacher(?string $name): array
    {
        return self::all()[TeacherName::key($name)] ?? ['name' => (string) $name, 'mode' => 'per_shift', 'group' => 0, 'one_on_one' => 0, 'courses' => []];
    }

    /**
     * Đơn giá áp cho một ca, chốt tại thời điểm check-in.
     *
     * @return array{pay_mode: string, unit_rate: float}
     */
    public static function quote(?string $teacher, ?int $courseId, bool $isOneOnOne, ?string $center = null): array
    {
        $cfg = self::forTeacher($teacher);

        if (! $isOneOnOne && ($cfg['mode'] ?? null) === 'free') {
            $defaults = self::centerFreeDefaults($center);
            $pick = fn ($key) => ($cfg['free'][$key] ?? null) ?? $defaults[$key];

            return [
                'pay_mode' => 'free',
                'unit_rate' => (float) ($pick('per_student') ?? 0),
                'free_threshold' => $pick('threshold') !== null ? (int) $pick('threshold') : null,
                'free_per_shift' => (float) ($pick('per_shift') ?? 0),
            ];
        }

        $field = $isOneOnOne ? 'one_on_one' : 'group';
        $courseRate = $courseId !== null ? ($cfg['courses'][(string) $courseId][$field] ?? null) : null;

        return [
            'pay_mode' => $isOneOnOne ? 'per_shift' : ($cfg['mode'] ?? 'per_shift'),
            'unit_rate' => (float) ($courseRate ?? $cfg[$field] ?? 0),
        ];
    }

    /**
     * Mức "Dạy tự do" mặc định cấu hình ở cơ sở (màn Trung tâm).
     *
     * @return array{threshold: ?int, per_student: ?float, per_shift: ?float}
     */
    public static function centerFreeDefaults(?string $center): array
    {
        foreach (CenterStore::all() as $c) {
            if ($c['name'] === $center) {
                return [
                    'threshold' => $c['free_threshold'] ?? null,
                    'per_student' => $c['free_per_student'] ?? null,
                    'per_shift' => $c['free_per_shift'] ?? null,
                ];
            }
        }

        return ['threshold' => null, 'per_student' => null, 'per_shift' => null];
    }

    /**
     * Thù lao "Dạy tự do": số HV có mặt > ngưỡng thì tính theo lượt HV, ≤ ngưỡng thì giá / ca dạy.
     */
    public static function freeAmount(array $shift, int $present): float
    {
        $threshold = $shift['free_threshold'] ?? null;

        return $threshold !== null && $present > $threshold
            ? ($shift['unit_rate'] ?? 0) * $present
            : (float) ($shift['free_per_shift'] ?? 0);
    }

    /**
     * Cơ sở có bắt giáo viên "Dạy tự do" quét QR khi nhận ca hay không (mặc định: có).
     */
    public static function freeRequiresQr(?string $center): bool
    {
        foreach (CenterStore::all() as $c) {
            if ($c['name'] === $center) {
                return $c['free_require_qr'] ?? true;
            }
        }

        return true;
    }
}
