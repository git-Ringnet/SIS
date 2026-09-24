<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Invoice;
use App\Models\MessageTemplate;
use App\Models\RetentionLog;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RetentionController extends Controller
{
    /**
     * Retention retention dashboard & checklist
     */
    public function index(Request $request): Response
    {
        $statusFilter = $request->input('status', 'all'); // all, normal, invoice_sent, following_up, renewed
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

        // Templates for quick copy
        $templates = MessageTemplate::all();
        $courses = Course::where('is_active', true)->get();

        // Retention Summary counts
        $counts = [
            'total_expiring' => Student::where('status', 'active')->where('remaining_sessions', '<=', $sessionThreshold)->count(),
            'normal' => Student::where('status', 'active')->where('remaining_sessions', '<=', $sessionThreshold)->where('retention_status', 'normal')->count(),
            'invoice_sent' => Student::where('status', 'active')->where('remaining_sessions', '<=', $sessionThreshold)->where('retention_status', 'invoice_sent')->count(),
            'following_up' => Student::where('status', 'active')->where('remaining_sessions', '<=', $sessionThreshold)->where('retention_status', 'following_up')->count(),
            'renewed' => Student::where('status', 'active')->where('remaining_sessions', '<=', $sessionThreshold)->where('retention_status', 'renewed')->count(),
        ];

        return Inertia::render('Retention/Index', [
            'students' => $students,
            'templates' => $templates,
            'courses' => $courses,
            'counts' => $counts,
            'filters' => [
                'status' => $statusFilter,
                'threshold' => $sessionThreshold,
            ],
        ]);
    }

    /**
     * Update student retention status (State Buttons: Hóa đơn, Chăm sóc, Đã đóng)
     */
    public function updateStatus(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:normal,invoice_sent,following_up,renewed',
            'note' => 'nullable|string',
            'renew_sessions' => 'nullable|integer|min:1',
            'renew_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|in:bank_transfer,cash,pos,momo',
        ]);

        $prevStatus = $student->retention_status;
        $newStatus = $validated['status'];

        $student->retention_status = $newStatus;
        $student->last_retention_contact_at = Carbon::now();

        $actionType = match ($newStatus) {
            'invoice_sent' => 'invoice_sent',
            'following_up' => 'call_care',
            'renewed' => 'renewed_payment',
            default => 'note_added',
        };

        $content = $validated['note'];
        if (! $content) {
            $content = match ($newStatus) {
                'invoice_sent' => 'Đã gửi thông báo học phí và thông tin thanh toán cho phụ huynh.',
                'following_up' => 'Đang trong quá trình follow-up, tư vấn lộ trình và nhắc học phí.',
                'renewed' => 'Phụ huynh đã xác nhận đóng phí thành công, gia hạn gói học mới.',
                default => 'Chuyển về trạng thái chờ chăm sóc.',
            };
        }

        // If 'renewed' is clicked, automatically add new sessions & create invoice!
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
                'recorded_by' => auth()->user()->name ?? 'Admin',
            ]);

            $content .= " (Cộng thêm +{$sessionsToAdd} buổi, số dư hiện tại: {$student->remaining_sessions} buổi)";
        }

        $student->save();

        // Create log record
        RetentionLog::create([
            'student_id' => $student->id,
            'action_type' => $actionType,
            'status' => $newStatus,
            'contact_date' => Carbon::now(),
            'content' => $content,
            'created_by' => auth()->user()->name ?? 'Admin / Tư vấn',
        ]);

        $statusText = match ($newStatus) {
            'invoice_sent' => 'Đã gửi Hóa đơn',
            'following_up' => 'Đang Chăm sóc',
            'renewed' => 'Đã Đóng & Gia Hạn Số Buổi',
            default => 'Bình thường',
        };

        return redirect()->back()->with('success', "Cập nhật học viên {$student->name} sang [{$statusText}] thành công!");
    }

    /**
     * Add manual follow-up note / log
     */
    public function addLog(Request $request, Student $student): RedirectResponse
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

        RetentionLog::create([
            'student_id' => $student->id,
            'action_type' => $validated['action_type'],
            'status' => $student->retention_status,
            'contact_date' => Carbon::now(),
            'content' => $validated['content'],
            'next_followup_date' => $validated['next_followup_date'] ?? null,
            'created_by' => auth()->user()->name ?? 'Admin',
        ]);

        return redirect()->back()->with('success', "Đã thêm ghi chú chăm sóc cho {$student->name}!");
    }

    /**
     * API to render dynamic populated message templates for instant copy
     */
    public function renderTemplate(Request $request, Student $student): JsonResponse
    {
        $templateId = $request->input('template_id');
        $template = MessageTemplate::find($templateId);

        if (! $template) {
            return response()->json(['error' => 'Template not found'], 404);
        }

        $rendered = $template->render($student);

        return response()->json([
            'rendered' => $rendered,
            'student_name' => $student->name,
            'parent_phone' => $student->parent_phone,
            'zalo_link' => 'https://zalo.me/'.preg_replace('/[^0-9]/', '', $student->parent_phone),
        ]);
    }
}
