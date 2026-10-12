<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        // Tạo dữ liệu ca dạy mẫu cho tuần hiện tại
        $defaultShifts = [
            [
                'id' => 1,
                'date' => '2026-10-12', // Thứ 2
                'day_of_week' => 'Thứ 2',
                'teacher_name' => 'Thầy Trương Bảo Đi',
                'shift_name' => 'Ca chiều 17:30 - 18:30',
                'start_time' => '17:30',
                'end_time' => '18:30',
                'class_name' => 'Piano 1:1 - Nguyễn Minh Khang',
                'room' => 'PIANO 01',
                'type' => 'Ca 1-1',
                'center' => 'Khoa Anh Music Hóc Môn',
                'status' => 'not_checked_in', // not_checked_in, checked_in, late, skipped, excused_absence, substitute
                'checkin_time' => null,
                'minutes_late' => 0,
                'rate' => 150000,
                'substitute_teacher' => null,
            ],
            [
                'id' => 2,
                'date' => '2026-10-12',
                'day_of_week' => 'Thứ 2',
                'teacher_name' => 'Thầy Minh',
                'shift_name' => 'Ca tối 18:30 - 20:00',
                'start_time' => '18:30',
                'end_time' => '20:00',
                'class_name' => 'Guitar Nhóm Cơ Bản (4 bạn)',
                'room' => 'GUITAR 01',
                'type' => 'Ca nhóm',
                'center' => 'Khoa Anh Music Hóc Môn',
                'status' => 'not_checked_in',
                'checkin_time' => null,
                'minutes_late' => 0,
                'rate' => 220000,
                'substitute_teacher' => null,
            ],
            [
                'id' => 3,
                'date' => '2026-10-10', // Thứ 7 vừa qua
                'day_of_week' => 'Thứ 7',
                'teacher_name' => 'Thầy Trương Bảo Đi',
                'shift_name' => 'Ca sáng 09:00 - 10:00',
                'start_time' => '09:00',
                'end_time' => '10:00',
                'class_name' => 'Piano 1:1 - Trần Bảo Ngọc',
                'room' => 'PIANO 01',
                'type' => 'Ca 1-1',
                'center' => 'Khoa Anh Music Hóc Môn',
                'status' => 'checked_in',
                'checkin_time' => '08:55',
                'minutes_late' => 0,
                'rate' => 150000,
                'substitute_teacher' => null,
            ],
            [
                'id' => 4,
                'date' => '2026-10-09', // Thứ 6
                'day_of_week' => 'Thứ 6',
                'teacher_name' => 'Cô Mai',
                'shift_name' => 'Ca chiều 17:30 - 19:00',
                'start_time' => '17:30',
                'end_time' => '19:00',
                'class_name' => 'Thanh Nhạc Thiếu Nhi Nhóm 01',
                'room' => 'VOCAL 01',
                'type' => 'Ca nhóm',
                'center' => 'Khoa Anh Music Quận 12',
                'status' => 'checked_in',
                'checkin_time' => '17:40',
                'minutes_late' => 10,
                'rate' => 220000,
                'substitute_teacher' => null,
            ],
        ];

        Storage::put($path, json_encode($defaultShifts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $defaultShifts;
    }

    private function saveCheckins(array $shifts): void
    {
        Storage::put($this->getCheckinsPath(), json_encode($shifts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function index(Request $request): JsonResponse
    {
        $date = $request->input('date', '2026-10-11'); // Ngày xem (mặc định 11/10/2026 như ảnh)
        $month = $request->input('month', '2026-10');
        $center = $request->input('center', 'all');
        $shiftType = $request->input('shift_type', 'all');
        $shiftTime = $request->input('shift_time', 'all');
        $teacherName = $request->input('teacher_name', 'all');

        $shifts = $this->loadCheckins();
        $rates = $this->loadRates();

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
        $todayShifts = array_filter($shifts, function ($s) use ($date, $center, $shiftType, $teacherName) {
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

        $teachers = [
            'Thầy Trương Bảo Đi',
            'Thầy Minh',
            'Cô Mai',
            'Thầy Đức',
        ];

        $centers = [
            'Khoa Anh Music Hóc Môn',
            'Khoa Anh Music Quận 12',
            'Khoa Anh Music Gò Vấp',
        ];

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
                $checkTime = $validated['checkin_time'] ?: Carbon::now()->format('H:i');
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
        $month = $request->input('month', '2026-10');
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
