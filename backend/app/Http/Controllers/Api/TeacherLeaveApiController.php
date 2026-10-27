<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use App\Support\CenterStore;
use App\Support\PagePermissions;
use App\Support\TeacherLeaves;
use App\Support\TeacherName;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Nghỉ phép theo khoảng ngày và phân công dạy thay theo khoảng ngày (chỉ quản lý / chủ trung tâm).
 */
class TeacherLeaveApiController extends Controller
{
    private function forbidTeacher(Request $request): ?JsonResponse
    {
        return PagePermissions::isTeacher($request->user())
            ? response()->json(['message' => 'Giáo viên không thể thực hiện thao tác này. Vui lòng báo quản lý.'], 403)
            : null;
    }

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->forbidTeacher($request)) {
            return $denied;
        }

        $teacher = $request->input('teacher');
        $status = $request->input('status', 'all');
        $from = $request->input('from');
        $to = $request->input('to');

        // Lọc: giáo viên liên quan, trạng thái (sắp tới / đang diễn ra / đã kết thúc), khoảng ngày giao nhau
        $filter = function (array $items, array $nameFields) use ($teacher, $status, $from, $to) {
            return collect($items)
                ->map(fn ($p) => [...$p, 'status' => TeacherLeaves::periodStatus($p)])
                ->filter(fn ($p) => ! $teacher || collect($nameFields)->contains(fn ($f) => TeacherName::same($p[$f], $teacher)))
                ->filter(fn ($p) => $status === 'all' || $p['status'] === $status)
                ->filter(fn ($p) => (! $from || $p['to'] >= $from) && (! $to || $p['from'] <= $to))
                ->sortByDesc('from')
                ->values()
                ->all();
        };

        $data = TeacherLeaves::all();

        return response()->json([
            'leaves' => $filter($data['leaves'], ['teacher']),
            'substitutions' => $filter($data['substitutions'], ['original', 'substitute']),
            // Ngày nghỉ của trung tâm không gắn giáo viên nên chỉ lọc theo trạng thái / khoảng ngày
            'center_offs' => $teacher ? [] : $filter($data['center_offs'], []),
            'teachers' => TeacherName::all(),
            'centers' => CenterStore::names(),
        ]);
    }

    private function periodRules(): array
    {
        return [
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'reason' => 'nullable|string|max:255',
        ];
    }

    /**
     * Khung giờ nghỉ / dạy thay trong ngày (tuỳ chọn, để trống = cả ngày): chỉ các ca giao với khung giờ này bị ảnh hưởng.
     */
    private function timeRules(): array
    {
        return [
            'start_time' => 'nullable|date_format:H:i|required_with:end_time',
            'end_time' => 'nullable|date_format:H:i|required_with:start_time|after:start_time',
        ];
    }

    private const TIME_MESSAGES = [
        'start_time.required_with' => 'Vui lòng nhập Từ giờ (hoặc để trống cả hai = cả ngày)',
        'end_time.required_with' => 'Vui lòng nhập Đến giờ (hoặc để trống cả hai = cả ngày)',
        'end_time.after' => 'Đến giờ phải sau Từ giờ',
    ];

    private function periodNote(array $p): string
    {
        return "{$p['from']} → {$p['to']}".TeacherLeaves::timeLabel($p).(! empty($p['reason']) ? " ({$p['reason']})" : '');
    }

    public function storeLeave(Request $request): JsonResponse
    {
        if ($denied = $this->forbidTeacher($request)) {
            return $denied;
        }

        $validated = $request->validate(['teacher' => 'required|string|max:255', ...$this->periodRules(), ...$this->timeRules()], [
            'teacher.required' => 'Vui lòng chọn giáo viên',
            'to.after_or_equal' => 'Đến ngày phải sau hoặc bằng Từ ngày',
            ...self::TIME_MESSAGES,
        ]);

        $data = TeacherLeaves::all();
        $leave = [
            'id' => (string) Str::uuid(),
            ...$validated,
            'start_time' => $validated['start_time'] ?? null,
            'end_time' => $validated['end_time'] ?? null,
            'reason' => $validated['reason'] ?? null,
            'created_by' => $request->user()?->name,
            'created_at' => Carbon::now()->toDateTimeString(),
        ];
        $data['leaves'][] = $leave;
        TeacherLeaves::save($data);

        ActivityLogger::log('created', 'teacher_checkin', "Nghỉ phép: {$leave['teacher']}", null, ActivityLogger::diff([], [
            'note' => $this->periodNote($leave),
        ]));

        return response()->json(['message' => "Đã lưu nghỉ phép cho {$leave['teacher']}.", 'leave' => $leave], 201);
    }

    public function storeSubstitution(Request $request): JsonResponse
    {
        if ($denied = $this->forbidTeacher($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'original' => 'required|string|max:255',
            'substitute' => 'required|string|max:255',
            ...$this->periodRules(),
            ...$this->timeRules(),
        ], [
            'original.required' => 'Vui lòng chọn giáo viên gốc',
            'substitute.required' => 'Vui lòng chọn giáo viên dạy thay',
            'to.after_or_equal' => 'Đến ngày phải sau hoặc bằng Từ ngày',
            ...self::TIME_MESSAGES,
        ]);

        if (TeacherName::same($validated['original'], $validated['substitute'])) {
            return response()->json(['message' => 'Giáo viên dạy thay phải khác giáo viên gốc.'], 422);
        }

        $data = TeacherLeaves::all();
        $sub = [
            'id' => (string) Str::uuid(),
            ...$validated,
            'start_time' => $validated['start_time'] ?? null,
            'end_time' => $validated['end_time'] ?? null,
            'reason' => $validated['reason'] ?? null,
            'created_by' => $request->user()?->name,
            'created_at' => Carbon::now()->toDateTimeString(),
        ];
        $data['substitutions'][] = $sub;
        TeacherLeaves::save($data);

        ActivityLogger::log('created', 'teacher_checkin', "Dạy thay: {$sub['substitute']} thay {$sub['original']}", null, ActivityLogger::diff([], [
            'note' => $this->periodNote($sub),
        ]));

        return response()->json(['message' => "Đã phân công {$sub['substitute']} dạy thay {$sub['original']}.", 'substitution' => $sub], 201);
    }

    /**
     * Ngày nghỉ của trung tâm (lễ, Tết...): học viên không học, không trừ buổi, lịch học tự lùi lại.
     */
    public function storeCenterOff(Request $request): JsonResponse
    {
        if ($denied = $this->forbidTeacher($request)) {
            return $denied;
        }

        $validated = $request->validate(['center' => 'nullable|string|max:255', ...$this->periodRules()], [
            'to.after_or_equal' => 'Đến ngày phải sau hoặc bằng Từ ngày',
        ]);

        $data = TeacherLeaves::all();
        $off = [
            'id' => (string) Str::uuid(),
            ...$validated,
            // Không chọn cơ sở = áp cho mọi cơ sở
            'center' => ($validated['center'] ?? '') ?: null,
            'reason' => $validated['reason'] ?? null,
            'created_by' => $request->user()?->name,
            'created_at' => Carbon::now()->toDateTimeString(),
        ];
        $data['center_offs'][] = $off;
        TeacherLeaves::save($data);

        $where = $off['center'] ?? 'tất cả cơ sở';
        ActivityLogger::log('created', 'teacher_checkin', "Trung tâm nghỉ: {$where}", null, ActivityLogger::diff([], [
            'note' => "{$off['from']} → {$off['to']}".($off['reason'] ? " ({$off['reason']})" : ''),
        ]));

        return response()->json(['message' => "Đã lưu ngày nghỉ của trung tâm ({$where}).", 'center_off' => $off], 201);
    }

    public function destroy(Request $request, string $type, string $id): JsonResponse
    {
        if ($denied = $this->forbidTeacher($request)) {
            return $denied;
        }

        $key = match ($type) {
            'leaves' => 'leaves',
            'center-offs' => 'center_offs',
            default => 'substitutions',
        };
        $data = TeacherLeaves::all();
        $removed = collect($data[$key])->firstWhere('id', $id);
        if (! $removed) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $data[$key] = array_values(array_filter($data[$key], fn ($p) => $p['id'] !== $id));
        TeacherLeaves::save($data);

        $label = match ($key) {
            'leaves' => "Nghỉ phép: {$removed['teacher']}",
            'center_offs' => 'Trung tâm nghỉ: '.($removed['center'] ?? 'tất cả cơ sở'),
            default => "Dạy thay: {$removed['substitute']} thay {$removed['original']}",
        };
        ActivityLogger::log('deleted', 'teacher_checkin', $label, null, ActivityLogger::diff(['note' => $this->periodNote([...$removed, 'reason' => null])], []));

        return response()->json(['message' => 'Đã xoá. Các ca chưa check-in trong khoảng ngày này trở lại bình thường.']);
    }
}
