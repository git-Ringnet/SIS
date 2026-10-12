<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\MessageTemplate;
use App\Models\RetentionLog;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetentionApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $statusFilter = $request->input('status', 'all');
        $sessionThreshold = (int) $request->input('threshold', 5);

        $query = Student::with(['course', 'latestRetentionLog', 'retentionLogs'])
            ->where('status', 'active')
            ->where('remaining_sessions', '<=', $sessionThreshold);

        if ($statusFilter !== 'all') {
            $query->where('retention_status', $statusFilter);
        }

        $students = $query->orderBy('remaining_sessions', 'asc')
            ->orderBy('last_retention_contact_at', 'desc')
            ->get();

        $templates = MessageTemplate::all();
        $courses = Course::where('is_active', true)->get();

        $counts = [
            'total_expiring' => Student::where('status', 'active')->where('remaining_sessions', '<=', $sessionThreshold)->count(),
            'normal' => Student::where('status', 'active')->where('remaining_sessions', '<=', $sessionThreshold)->where('retention_status', 'normal')->count(),
            'invoice_sent' => Student::where('status', 'active')->where('remaining_sessions', '<=', $sessionThreshold)->where('retention_status', 'invoice_sent')->count(),
            'following_up' => Student::where('status', 'active')->where('remaining_sessions', '<=', $sessionThreshold)->where('retention_status', 'following_up')->count(),
            'renewed' => Student::where('status', 'active')->where('remaining_sessions', '<=', $sessionThreshold)->where('retention_status', 'renewed')->count(),
        ];

        return response()->json([
            'students' => $students,
            'templates' => $templates,
            'courses' => $courses,
            'counts' => $counts,
        ]);
    }

    public function updateStatus(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:normal,invoice_sent,following_up,renewed',
            'note' => 'nullable|string',
            'renew_sessions' => 'nullable|integer|min:1',
            'renew_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|in:bank_transfer,cash,pos,momo',
        ]);

        $newStatus = $validated['status'];
        $student->retention_status = $newStatus;
        $student->last_retention_contact_at = Carbon::now();

        $actionType = match ($newStatus) {
            'invoice_sent' => 'invoice_sent',
            'following_up' => 'call_care',
            'renewed' => 'renewed_payment',
            default => 'note_added',
        };

        $content = $validated['note'] ?? null;
        if (! $content) {
            $content = match ($newStatus) {
                'invoice_sent' => 'Đã gửi thông báo học phí và thông tin thanh toán cho phụ huynh.',
                'following_up' => 'Đang trong quá trình follow-up, tư vấn lộ trình và nhắc học phí.',
                'renewed' => 'Phụ huynh đã xác nhận đóng phí thành công, gia hạn gói học mới.',
                default => 'Chuyển về trạng thái chờ chăm sóc.',
            };
        }

        if ($newStatus === 'renewed') {
            $sessionsToAdd = $validated['renew_sessions'] ?? ($student->course ? $student->course->default_sessions : 12);
            $amount = $validated['renew_amount'] ?? ($student->course ? $student->course->price : 0);
            $payMethod = $validated['payment_method'] ?? 'bank_transfer';

            $student->remaining_sessions += $sessionsToAdd;
            $student->total_sessions += $sessionsToAdd;

            Invoice::create([
                'invoice_code' => 'HD-RENEW-'.date('Ymd').'-'.str_pad($student->id, 3, '0', STR_PAD_LEFT).'-'.rand(10, 99),
                'student_id' => $student->id,
                'course_id' => $student->course_id,
                'amount' => $amount,
                'sessions_added' => $sessionsToAdd,
                'payment_method' => $payMethod,
                'status' => 'paid',
                'paid_at' => Carbon::now(),
                'note' => 'Học phí tái đăng ký giữ chân học viên (+ '.$sessionsToAdd.' buổi)',
                'recorded_by' => 'Admin',
            ]);

            $content .= " (Cộng thêm +{$sessionsToAdd} buổi, số dư hiện tại: {$student->remaining_sessions} buổi)";
        }

        $student->save();

        RetentionLog::create([
            'student_id' => $student->id,
            'action_type' => $actionType,
            'status' => $newStatus,
            'contact_date' => Carbon::now(),
            'content' => $content,
            'created_by' => 'Admin / Tư vấn',
        ]);

        $student->load(['course', 'latestRetentionLog', 'retentionLogs']);

        return response()->json([
            'message' => "Cập nhật học viên {$student->name} sang trạng thái mới thành công!",
            'student' => $student,
        ]);
    }

    public function addLog(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string',
            'action_type' => 'required|string|in:invoice_sent,call_care,sms_zalo_sent,renewed_payment,note_added',
            'status' => 'nullable|string|in:normal,invoice_sent,following_up,renewed',
            'next_followup_date' => 'nullable|date',
        ]);

        if (! empty($validated['status'])) {
            $student->retention_status = $validated['status'];
        }
        $student->last_retention_contact_at = Carbon::now();
        $student->save();

        $log = RetentionLog::create([
            'student_id' => $student->id,
            'action_type' => $validated['action_type'],
            'status' => $student->retention_status,
            'contact_date' => Carbon::now(),
            'content' => $validated['content'],
            'next_followup_date' => $validated['next_followup_date'] ?? null,
            'created_by' => 'Admin',
        ]);

        $student->load(['course', 'latestRetentionLog', 'retentionLogs']);

        return response()->json([
            'message' => "Đã thêm ghi chú chăm sóc cho {$student->name}!",
            'log' => $log,
            'student' => $student,
        ]);
    }
}
