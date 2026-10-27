<?php

namespace App\Support;

use App\Models\Attendance;
use Illuminate\Support\Facades\Storage;

/**
 * Thù lao ca dạy (dùng chung cho trang Check-in ca dạy và tab Chi phí ở Doanh thu, để hai nơi ra cùng một số):
 * lương ca đã chốt lúc check-in × hệ số theo điểm danh học viên (TeacherRates::payFactor).
 * Ca dạy lưu ở teacher_checkins.json.
 */
class TeacherPayroll
{
    public const CHECKINS_PATH = 'teacher_checkins.json';

    // Trạng thái được tính công (có thù lao)
    public const PAID_STATUSES = ['checked_in', 'late', 'substitute'];

    public static function loadShifts(): array
    {
        $data = Storage::exists(self::CHECKINS_PATH) ? json_decode(Storage::get(self::CHECKINS_PATH), true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * Người được trả thù lao cho ca: người dạy thay nếu có, ngược lại giáo viên gốc.
     */
    public static function earner(array $shift): ?string
    {
        return ($shift['substitute_teacher'] ?? null) ?: ($shift['teacher_name'] ?? null);
    }

    public static function amount(array $shift, array $attendance): float
    {
        return self::pay($shift, $attendance)['amount'];
    }

    /**
     * @param  array  $attendance  kết quả attendanceByDate() của các ca cần tính
     * @return array{amount: float, factor: float, note: ?string}
     */
    public static function pay(array $shift, array $attendance): array
    {
        $full = fn (float $amount) => ['amount' => $amount, 'factor' => 1.0, 'note' => null];

        if (! in_array($shift['status'] ?? null, self::PAID_STATUSES, true)) {
            return $full(0);
        }
        if (! isset($shift['unit_rate'])) {
            return $full((float) ($shift['rate'] ?? 0)); // ca cũ, trước khi có đơn giá theo giáo viên
        }
        $statuses = self::shiftStatuses($shift, $attendance);
        // Ca check-in theo cách tính cũ (theo lượt HV / dạy tự do) giữ nguyên thù lao như lúc đó
        $legacy = TeacherRates::legacyAmount($shift, count(array_filter($statuses, fn ($st) => $st === 'present')));
        if ($legacy !== null) {
            return $full($legacy);
        }

        $factor = TeacherRates::payFactor($shift, $statuses);

        return ['amount' => (float) $shift['unit_rate'] * $factor['factor'], ...$factor];
    }

    /**
     * Trạng thái điểm danh của từng học viên trong ca (student_id => status), chỉ học viên đã được điểm danh.
     * Học viên có nhiều ca trong ngày thì lấy bản ghi đúng giờ vào ca.
     */
    private static function shiftStatuses(array $shift, array $attendance): array
    {
        $statuses = [];
        foreach ($shift['student_ids'] ?? [] as $id) {
            $records = $attendance[$shift['date']][$id] ?? [];
            if ($records === []) {
                continue;
            }
            $match = collect($records)->firstWhere('time', substr((string) ($shift['start_time'] ?? ''), 0, 5));
            $statuses[$id] = ($match ?? $records[0])['status'];
        }

        return $statuses;
    }

    /**
     * Điểm danh học viên theo ngày của các ca (để tính hệ số lương theo số HV đi học).
     *
     * @return array<string, array<int, list<array{time: string, status: string}>>>
     */
    public static function attendanceByDate(array $shifts): array
    {
        $dates = collect($shifts)->pluck('date')->filter()->unique();
        if ($dates->isEmpty()) {
            return [];
        }

        $result = [];
        $records = Attendance::where(function ($q) use ($dates) {
            foreach ($dates as $d) {
                $q->orWhereDate('attended_at', $d);
            }
        })->get(['student_id', 'attended_at', 'status']);
        foreach ($records as $a) {
            $result[$a->attended_at->toDateString()][$a->student_id][] = ['time' => $a->attended_at->format('H:i'), 'status' => $a->status];
        }

        return $result;
    }

    /**
     * Tổng lương giáo viên / giáo vụ của một tháng ("Y-m"), theo từng người (nhiều tiền nhất trước).
     * Chỉ ca đã tính công, không tính ca đã xoá nháp. $center = 'all' là mọi cơ sở.
     *
     * @return array{total: float, shifts: int, teachers: list<array{name: string, amount: float, shifts: int}>}
     */
    public static function monthly(string $month, string $center = 'all'): array
    {
        $shifts = array_values(array_filter(self::loadShifts(), fn ($s) => str_starts_with($s['date'] ?? '', $month)
            && in_array($s['status'] ?? null, self::PAID_STATUSES, true)
            && ($center === 'all' || ($s['center'] ?? null) === $center)));
        $attendance = self::attendanceByDate($shifts);

        $teachers = [];
        foreach ($shifts as $s) {
            $name = self::earner($s) ?? '';
            $key = TeacherName::key($name);
            $teachers[$key] ??= ['name' => $name, 'amount' => 0.0, 'shifts' => 0];
            $teachers[$key]['amount'] += self::amount($s, $attendance);
            $teachers[$key]['shifts']++;
        }
        $teachers = array_values($teachers);
        usort($teachers, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return ['total' => array_sum(array_column($teachers, 'amount')), 'shifts' => count($shifts), 'teachers' => $teachers];
    }
}
