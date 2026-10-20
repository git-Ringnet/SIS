<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Student;
use App\Support\CenterStore;
use App\Support\StudentSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $month = $request->input('month', 'current');
        $center = $request->input('center', 'all');
        $roomFilter = $request->input('room', 'all');
        $dayFilter = $request->input('day', 'all');
        $onlyAvailable = filter_var($request->input('only_available', false), FILTER_VALIDATE_BOOLEAN);

        // 1. Các mốc thời gian: theo năm = 12 tháng, theo quý = 3 tháng của quý, theo tháng = từng ngày
        $period = $request->input('period', 'year');
        $monthNum = $month === 'current' || ! $month ? now()->month : max(1, min(12, (int) $month));
        $buckets = [];
        if ($period === 'month') {
            $first = Carbon::create($year, $monthNum, 1)->startOfDay();
            for ($d = 1; $d <= $first->daysInMonth; $d++) {
                $day = $first->copy()->day($d);
                $buckets[] = ['label' => (string) $d, 'num' => $d, 'start' => $day->copy()->startOfDay(), 'end' => $day->copy()->endOfDay()];
            }
        } else {
            $months = $period === 'quarter'
                ? range(intdiv($monthNum - 1, 3) * 3 + 1, intdiv($monthNum - 1, 3) * 3 + 3)
                : range(1, 12);
            foreach ($months as $m) {
                $first = Carbon::create($year, $m, 1);
                $buckets[] = ['label' => "Th{$m}", 'num' => $m, 'start' => $first->copy()->startOfMonth(), 'end' => $first->copy()->endOfMonth()];
            }
        }
        $rangeStart = $buckets[0]['start'];
        $rangeEnd = end($buckets)['end'];

        $students = Student::query()
            ->when($center !== 'all' && $center, fn ($q) => $q->where('center', $center))
            ->get(['id', 'status', 'join_date', 'created_at', 'updated_at']);
        $attendances = Attendance::whereBetween('attended_at', [$rangeStart, $rangeEnd])
            ->when($center !== 'all' && $center, fn ($q) => $q->whereIn('student_id', $students->pluck('id')))
            ->get(['attended_at', 'status']);

        $joinedAt = fn ($s) => $s->join_date ? Carbon::parse($s->join_date)->startOfDay() : $s->created_at;
        // Ngày nghỉ học: lần cập nhật cuối của học viên đã chuyển sang Đã nghỉ
        $isDropped = fn ($s) => in_array($s->status, ['inactive', 'dropped'], true);

        $activeTrend = [];
        $newVsDrop = [];
        $attendanceRates = [];
        $today = now()->endOfDay();

        foreach ($buckets as $b) {
            $future = $b['start']->gt($today);
            $pointEnd = $b['end']->lt($today) ? $b['end'] : $today;

            // Đang học + tạm dừng tại cuối mốc: đã nhập học và chưa nghỉ
            $activeCount = $future ? null : $students->filter(function ($s) use ($pointEnd, $joinedAt, $isDropped) {
                if ($joinedAt($s)->gt($pointEnd)) {
                    return false;
                }

                return ! ($isDropped($s) && $s->updated_at->lte($pointEnd));
            })->count();

            $newCount = $students->filter(fn ($s) => $joinedAt($s)->between($b['start'], $b['end']))->count();
            $dropCount = $students->filter(fn ($s) => $isDropped($s) && $s->updated_at->between($b['start'], $b['end']))->count();

            // Tỷ lệ đi học = có mặt / (có mặt + vắng) trong mốc
            $inBucket = $attendances->filter(fn ($a) => $a->attended_at->between($b['start'], $b['end']));
            $present = $inBucket->where('status', 'present')->count();
            $absent = $inBucket->whereIn('status', ['absent_with_leave', 'absent_without_leave'])->count();
            $marked = $present + $absent;

            $common = ['label' => $b['label'], 'month' => $b['label'], 'month_num' => $b['num']];
            $activeTrend[] = [...$common, 'count' => $activeCount];
            $newVsDrop[] = [...$common, 'new_students' => $newCount, 'drop_students' => $dropCount];
            $attendanceRates[] = [...$common, 'rate' => $marked > 0 ? (int) round($present / $marked * 100) : ($future ? null : 0), 'attended' => $present, 'absent' => $absent];
        }

        // 2. Công suất phòng học theo khung giờ, tính từ lịch học cố định của học viên đang học
        $roomCenter = $request->input('room_center', 'all');
        $dayLabels = [1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5', 5 => 'Thứ 6', 6 => 'Thứ 7', 0 => 'Chủ nhật'];

        // Sức chứa theo tên phòng (ưu tiên phòng thuộc đúng trung tâm của học viên)
        $capacities = [];
        foreach (CenterStore::all() as $c) {
            foreach ($c['rooms'] ?? [] as $r) {
                $capacities[$c['name'].'|'.$r['name']] = (int) ($r['capacity'] ?? 1);
                $capacities['*|'.$r['name']] ??= (int) ($r['capacity'] ?? 1);
            }
        }

        // Công suất phòng theo từng ca học (mỗi ca có phòng riêng)
        $scheduledStudents = Student::where('status', 'active')
            ->whereNotNull('class_days')
            ->when($roomCenter !== 'all', fn ($q) => $q->where('center', $roomCenter))
            ->get();

        $slots = [];
        foreach ($scheduledStudents as $st) {
            foreach (StudentSchedule::slots($st) as $slot) {
                if (empty($slot['room'])) {
                    continue;
                }
                $day = (int) $slot['day'];
                $key = "{$slot['room']}|{$day}|{$slot['start']}";
                $slots[$key] ??= [
                    'room' => $slot['room'],
                    'day_number' => $day,
                    'day' => $dayLabels[$day] ?? '',
                    'time' => $slot['start'],
                    'capacity' => $capacities[$st->center.'|'.$slot['room']] ?? $capacities['*|'.$slot['room']] ?? 1,
                    'current_students' => 0,
                ];
                $slots[$key]['current_students']++;
            }
        }

        $roomSlots = collect($slots)
            ->sortBy(fn ($s) => sprintf('%d %s %s', $s['day_number'] === 0 ? 7 : $s['day_number'], $s['time'], $s['room']))
            ->values()
            ->map(function ($s, $i) {
                $available = max(0, $s['capacity'] - $s['current_students']);

                return [
                    'id' => $i + 1,
                    'room' => $s['room'],
                    'day' => $s['day'],
                    'time' => $s['time'],
                    'schedule_label' => "{$s['day']} - {$s['time']}",
                    'current_students' => $s['current_students'],
                    'capacity' => $s['capacity'],
                    'available' => $available,
                    'status' => $available === 0 ? 'full' : ($available <= max(1, intdiv($s['capacity'], 4)) ? 'medium' : 'high'),
                ];
            })
            ->all();

        // Lọc dữ liệu phòng học
        $filteredSlots = collect($roomSlots);

        if ($roomFilter !== 'all') {
            $filteredSlots = $filteredSlots->filter(fn ($s) => $s['room'] === $roomFilter);
        }

        if ($dayFilter !== 'all') {
            $filteredSlots = $filteredSlots->filter(fn ($s) => $s['day'] === $dayFilter);
        }

        if ($onlyAvailable) {
            $filteredSlots = $filteredSlots->filter(fn ($s) => $s['available'] > 0);
        }

        return response()->json([
            'filters' => [
                'period' => $period,
                'year' => $year,
                'month' => $month,
                'center' => $center,
                'room' => $roomFilter,
                'day' => $dayFilter,
                'only_available' => $onlyAvailable,
            ],
            'centers' => CenterStore::names(),
            'rooms' => CenterStore::roomNames($roomCenter),
            'days' => [
                'Tất cả các ngày',
                'Thứ 2',
                'Thứ 3',
                'Thứ 4',
                'Thứ 5',
                'Thứ 6',
                'Thứ 7',
                'Chủ nhật',
            ],
            'active_students_trend' => $activeTrend,
            'new_vs_drop_students' => $newVsDrop,
            'attendance_rate_trend' => $attendanceRates,
            'room_slots' => $filteredSlots->values(),
        ]);
    }
}
