<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogApiController extends Controller
{
    public const SUBJECT_TYPES = [
        'student' => 'Học viên',
        'invoice' => 'Hoá đơn',
        'course' => 'Khoá học',
        'lead' => 'Khách hàng tiềm năng',
        'center' => 'Trung tâm',
        'staff' => 'Nhân sự',
        'teacher_checkin' => 'Check-in ca dạy',
        'message_template' => 'Mẫu tin nhắn',
        'settings' => 'Cài đặt',
        'account' => 'Tài khoản',
        'backup' => 'Sao lưu dữ liệu',
    ];

    public const ACTIONS = [
        'created' => 'Thêm mới',
        'updated' => 'Chỉnh sửa',
        'deleted' => 'Xoá',
        'restored' => 'Khôi phục',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'user_email' => 'nullable|string|max:255',
            'subject_type' => 'nullable|string|max:50',
            'action' => 'nullable|string|max:20',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->subDays(ActivityLog::RETENTION_DAYS)->startOfDay();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now();

        $paginator = ActivityLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($validated['user_email'] ?? null, fn ($q, $email) => $q->where('user_email', $email))
            ->when($validated['subject_type'] ?? null, fn ($q, $type) => $q->where('subject_type', $type))
            ->when($validated['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($validated['search'] ?? null, function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('subject_label', 'like', "%{$search}%")
                        ->orWhere('context', 'like', "%{$search}%")
                        ->orWhere('user_email', 'like', "%{$search}%")
                        ->orWhere('user_name', 'like', "%{$search}%");
                });
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate($this->perPage($request));

        $actors = ActivityLog::query()
            ->whereNotNull('user_email')
            ->select('user_email', 'user_name')
            ->distinct()
            ->orderBy('user_name')
            ->get();

        return response()->json([
            'logs' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'actors' => $actors,
            'subject_types' => self::SUBJECT_TYPES,
            'actions' => self::ACTIONS,
            'retention_days' => ActivityLog::RETENTION_DAYS,
        ]);
    }
}
