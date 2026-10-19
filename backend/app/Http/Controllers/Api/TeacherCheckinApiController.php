<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Support\CenterStore;
use App\Support\ShiftTime;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeacherCheckinApiController extends Controller
{
    private function getCheckinsPath(): string
    {
        return 'teacher_checkins.json';
    }

    private function getRatesPath(): string
    {
        return 'shift_rates.json';
    }

    /**
     * Sinh ca dạy của ngày từ lịch học cố định của học viên (giáo viên + giờ + phòng + trung tâm).
     * Ca chưa check-in được cập nhật lại theo danh sách học viên hiện tại (thêm / bớt học viên,
     * đổi loại ca, đơn giá), ca không còn học viên thì bỏ. Ca đã check-in / báo vắng giữ nguyên làm lịch sử.
     */
    private function syncScheduledShifts(string $date, array $shifts, array $rates): array
    {
        $carbonDate = Carbon::parse($date);
        $students = Student::with('course')
            ->where('status', 'active')
            ->whereNotNull('teacher')
            ->whereNotNull('class_start_time')
            ->whereJsonContains('class_days', $carbonDate->dayOfWeek)
            ->orderBy('name')
            ->get();

        // Ca cần có trong ngày, theo schedule_key
        $planned = [];
        foreach ($students->groupBy(fn ($st) => implode('|', [$st->teacher, $st->class_start_time, $st->room, $st->center])) as $group) {
            $first = $group->first();
            $isOneOnOne = $group->count() === 1;
            $time = $first->class_start_time.($first->class_end_time ? ' - '.$first->class_end_time : '');
            $courseName = $first->course?->name ?? 'Lớp học';

            $planned[$date.'|'.implode('|', [$first->teacher, $first->class_start_time, $first->room, $first->center])] = [
                'date' => $date,
                'day_of_week' => $carbonDate->dayOfWeek === 0 ? 'CN' : 'Thứ '.($carbonDate->dayOfWeek + 1),
                'teacher_name' => $first->teacher,
                'shift_name' => ShiftTime::label($first->class_start_time).' '.$time,
                'start_time' => $first->class_start_time,
                'end_time' => $first->class_end_time,
                'class_name' => $isOneOnOne
                    ? "{$courseName} 1:1 - {$first->name}"
                    : "{$courseName} nhóm ({$group->count()} bạn)",
                'room' => $first->room,
                'type' => $isOneOnOne ? 'Ca 1-1' : 'Ca nhóm',
                'center' => $first->center ?: CenterStore::defaultName(),
                'rate' => $isOneOnOne ? ($rates['one_on_one'] ?? 0) : ($rates['group'] ?? 0),
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

    private function loadRates(): array
    {
        $path = $this->getRatesPath();
        if (Storage::exists($path)) {
            $data = json_decode(Storage::get($path), true);
            if (is_array($data)) {
                return $data;
            }
        }

        $default = [
            'one_on_one' => 150000, // Ca 1-1 (60 phút): 150.000 đ
            'group' => 220000,      // Ca nhóm (90 phút): 220.000 đ
            'trial' => 100000,      // Ca dạy thử / test: 100.000 đ
        ];
        Storage::put($path, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $default;
    }

    private function loadCheckins(): array
    {
        $path = $this->getCheckinsPath();
        if (Storage::exists($path)) {
            $data = json_decode(Storage::get($path), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    private function saveCheckins(array $shifts): void
    {
        Storage::put($this->getCheckinsPath(), json_encode($shifts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function index(Request $request): JsonResponse
    {
        $date = $request->input('date', Carbon::today()->toDateString());
        $month = $request->input('month', Carbon::now()->format('Y-m'));
        $center = $request->input('center', 'all');
        $shiftType = $request->input('shift_type', 'all');
        $shiftTime = $request->input('shift_time', 'all');
        $teacherName = $request->input('teacher_name', 'all');

        $rates = $this->loadRates();
        $shifts = $this->syncScheduledShifts($date, $this->loadCheckins(), $rates);

        // Tên thứ theo ngày
        $carbonDate = Carbon::parse($date);
        $dayOfWeekVi = match ($carbonDate->dayOfWeek) {
            0 => 'CN',
            1 => 'Thứ 2',
            2 => 'Thứ 3',
            3 => 'Thứ 4',
            4 => 'Thứ 5',
            5 => 'Thứ 6',
            6 => 'Thứ 7',
        };

        // Lọc các ca dạy trong ngày được chọn
        $todayShifts = array_filter($shifts, function ($s) use ($date, $center, $shiftType, $shiftTime, $teacherName) {
            if ($s['date'] !== $date) {
                return false;
            }
            if ($center !== 'all' && $s['center'] !== $center) {
                return false;
            }
            if ($shiftType !== 'all' && $s['type'] !== $shiftType) {
                return false;
            }
            if ($teacherName !== 'all' && $s['teacher_name'] !== $teacherName) {
                return false;
            }
            if ($shiftTime !== 'all' && ShiftTime::of($s['start_time'] ?? null) !== $shiftTime) {
                return false;
            }

            return true;
        });
        $todayShifts = array_values($todayShifts);

        // Đếm các chỉ số trong ngày
        $todayStats = [
            'total' => count($todayShifts),
            'checked_in' => count(array_filter($todayShifts, fn ($s) => in_array($s['status'], ['checked_in', 'late']))),
            'skipped' => count(array_filter($todayShifts, fn ($s) => $s['status'] === 'skipped')),
            'excused_absence' => count(array_filter($todayShifts, fn ($s) => $s['status'] === 'excused_absence')),
            'late' => count(array_filter($todayShifts, fn ($s) => $s['status'] === 'late')),
            'substitute' => count(array_filter($todayShifts, fn ($s) => $s['status'] === 'substitute')),
        ];

        // Lọc ca dạy trong tháng cho bảng tổng kết tháng
        $monthlyShifts = array_filter($shifts, function ($s) use ($month, $center) {
            if (! str_starts_with($s['date'], $month)) {
                return false;
            }
            if ($center !== 'all' && $s['center'] !== $center) {
                return false;
            }

            return in_array($s['status'], ['checked_in', 'late', 'substitute']);
        });
        $monthlyShifts = array_values($monthlyShifts);

        // Tính tổng tiền trả giáo viên trong tháng
        $totalSalary = array_reduce($monthlyShifts, function ($carry, $s) {
            return $carry + ($s['rate'] ?? 0);
        }, 0);

        // Giáo viên có ca dạy (kể cả ca sinh từ lịch học viên)
        $teachers = collect($shifts)->pluck('teacher_name')->filter()->unique()->sort()->values()->all();

        $centers = CenterStore::names();

        return response()->json([
            'date' => $date,
            'day_of_week' => $dayOfWeekVi,
            'today_stats' => $todayStats,
            'today_shifts' => $todayShifts,
            'monthly_summary' => [
                'month' => $month,
                'total_salary' => $totalSalary,
                'total_shifts' => count($monthlyShifts),
                'shifts' => $monthlyShifts,
            ],
            'rates' => $rates,
            'teachers' => $teachers,
            'centers' => $centers,
        ]);
    }

    public function checkin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shift_id' => 'required|integer',
            'checkin_time' => 'nullable|string',
            'is_late' => 'nullable|boolean',
            'minutes_late' => 'nullable|integer',
        ]);

        $shifts = $this->loadCheckins();
        $found = false;

        foreach ($shifts as &$s) {
            if ($s['id'] === $validated['shift_id']) {
                $checkTime = ($validated['checkin_time'] ?? null) ?: Carbon::now()->format('H:i');
                $isLate = $validated['is_late'] ?? false;
                $mins = $validated['minutes_late'] ?? ($isLate ? 15 : 0);

                $s['status'] = $isLate ? 'late' : 'checked_in';
                $s['checkin_time'] = $checkTime;
                $s['minutes_late'] = $mins;
                $found = true;
                break;
            }
        }

        if (! $found) {
            return response()->json(['message' => 'Không tìm thấy ca dạy!'], 404);
        }

        $this->saveCheckins($shifts);

        return response()->json([
            'message' => 'Check-in ca dạy thành công!',
        ]);
    }

    public function recordAbsence(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shift_id' => 'required|integer',
            'status' => 'required|string|in:skipped,excused_absence,substitute',
            'substitute_teacher' => 'nullable|string',
            'reason' => 'nullable|string',
        ]);

        $shifts = $this->loadCheckins();
        $found = false;

        foreach ($shifts as &$s) {
            if ($s['id'] === $validated['shift_id']) {
                $s['status'] = $validated['status'];
                if ($validated['status'] === 'substitute' && ! empty($validated['substitute_teacher'])) {
                    $s['substitute_teacher'] = $validated['substitute_teacher'];
                }
                $found = true;
                break;
            }
        }

        if (! $found) {
            return response()->json(['message' => 'Không tìm thấy ca dạy!'], 404);
        }

        $this->saveCheckins($shifts);

        return response()->json([
            'message' => 'Cập nhật trạng thái vắng / dạy thay thành công!',
        ]);
    }

    public function updateRates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'one_on_one' => 'required|numeric|min:0',
            'group' => 'required|numeric|min:0',
            'trial' => 'required|numeric|min:0',
        ]);

        Storage::put($this->getRatesPath(), json_encode($validated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return response()->json([
            'message' => 'Cập nhật đơn giá ca dạy thành công!',
            'rates' => $validated,
        ]);
    }

    public function exportMonthly(Request $request): StreamedResponse
    {
        $month = $request->input('month', Carbon::now()->format('Y-m'));
        $shifts = $this->loadCheckins();

        $monthlyShifts = array_filter($shifts, function ($s) use ($month) {
            return str_starts_with($s['date'], $month);
        });

        $fileName = 'cham_cong_ca_day_'.str_replace('-', '', $month).'_'.date('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($monthlyShifts) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, [
                'Ngày',
                'Giáo viên',
                'Ca dạy',
                'Loại ca',
                'Phòng',
                'Cơ sở',
                'Trạng thái',
                'Giờ check-in',
                'Trễ (phút)',
                'Dạy thay bởi',
                'Thù lao ca dạy (VNĐ)',
            ]);

            foreach ($monthlyShifts as $s) {
                $statusVi = match ($s['status']) {
                    'checked_in' => 'Đã check-in đúng giờ',
                    'late' => 'Đi trễ',
                    'skipped' => 'Bỏ dạy không phép',
                    'excused_absence' => 'Vắng có phép',
                    'substitute' => 'Dạy thay',
                    default => 'Chưa check-in',
                };

                fputcsv($output, [
                    Carbon::parse($s['date'])->format('d/m/Y'),
                    $s['teacher_name'],
                    $s['shift_name'],
                    $s['type'],
                    $s['room'],
                    $s['center'],
                    $statusVi,
                    $s['checkin_time'] ?: '-',
                    $s['minutes_late'] ?: 0,
                    $s['substitute_teacher'] ?: '-',
                    $s['rate'],
                ]);
            }

            fclose($output);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }
}
