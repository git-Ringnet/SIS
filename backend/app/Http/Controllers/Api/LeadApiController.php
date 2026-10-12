<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'all');
        $courseId = $request->input('course_id');
        $search = $request->input('search');
        $year = $request->input('year', 'all');
        $month = $request->input('month', 'all');
        $center = $request->input('center', 'all');

        $query = Lead::with(['course', 'convertedStudent']);

        if ($status !== 'all' && ! empty($status)) {
            $query->where('status', $status);
        }

        if ($courseId && $courseId !== 'all') {
            $query->where('interested_course_id', $courseId);
        }

        if ($year !== 'all' && ! empty($year)) {
            $query->whereYear('created_at', (int) $year);
        }

        if ($month !== 'all' && ! empty($month)) {
            $query->whereMonth('created_at', (int) $month);
        }

        if ($center !== 'all' && ! empty($center)) {
            $query->where('center', $center);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('parent_name', 'like', "%{$search}%")
                    ->orWhere('student_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $leads = $query->orderBy('created_at', 'desc')->get();
        $courses = Course::where('is_active', true)->get();

        $stages = [
            'new' => Lead::where('status', 'new')->count(),
            'called' => Lead::where('status', 'called')->count(),
            'quote_sent' => Lead::where('status', 'quote_sent')->count(),
            'trial_done' => Lead::where('status', 'trial_done')->count(),
            'registered' => Lead::where('status', 'registered')->count(),
            'lost' => Lead::where('status', 'lost')->count(),
        ];

        $centers = [
            'Khoa Anh Music Hóc Môn',
            'Khoa Anh Music Quận 12',
            'Khoa Anh Music Gò Vấp',
        ];

        return response()->json([
            'leads' => $leads,
            'courses' => $courses,
            'stages' => $stages,
            'centers' => $centers,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_name' => 'required|string|max:255',
            'student_name' => 'nullable|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'interested_course_id' => 'nullable|exists:courses,id',
            'source' => 'required|string|max:50',
            'status' => 'required|string|in:new,called,quote_sent,trial_done,registered,lost',
            'trial_date' => 'nullable|date',
            'expected_revenue' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'assigned_to' => 'nullable|string|max:255',
            'center' => 'nullable|string|max:255',
            'image_url' => 'nullable|string|max:1000',
        ]);

        $lead = Lead::create([
            'parent_name' => $validated['parent_name'],
            'student_name' => $validated['student_name'] ?? null,
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'interested_course_id' => $validated['interested_course_id'] ?? null,
            'source' => $validated['source'],
            'status' => $validated['status'],
            'trial_date' => $validated['trial_date'] ?? null,
            'expected_revenue' => $validated['expected_revenue'] ?? 0,
            'notes' => $validated['notes'] ?? null,
            'assigned_to' => $validated['assigned_to'] ?? 'Tư vấn viên',
            'center' => $validated['center'] ?? 'Khoa Anh Music Hóc Môn',
            'image_url' => $validated['image_url'] ?? null,
        ]);

        $lead->load('course');

        return response()->json([
            'message' => "Thêm khách hàng tiềm năng {$lead->parent_name} thành công!",
            'lead' => $lead,
        ], 201);
    }

    public function updateStatus(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:new,called,quote_sent,trial_done,registered,lost',
            'notes' => 'nullable|string',
            'image_url' => 'nullable|string',
        ]);

        $lead->status = $validated['status'];
        if (! empty($validated['notes'])) {
            $lead->notes = ($lead->notes ? $lead->notes."\n" : '').'['.date('d/m/Y H:i').'] '.$validated['notes'];
        }
        if (isset($validated['image_url'])) {
            $lead->image_url = $validated['image_url'];
        }
        $lead->save();
        $lead->load('course');

        return response()->json([
            'message' => 'Cập nhật thông tin khách hàng thành công!',
            'lead' => $lead,
        ]);
    }

    public function convertToStudent(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'student_name' => 'required|string|max:255',
            'gender' => 'required|string|in:Nam,Nữ,Khác',
            'dob' => 'nullable|date',
            'total_sessions' => 'required|integer|min:1',
            'price' => 'nullable|numeric|min:0',
        ]);

        $course = Course::find($validated['course_id']);
        $latestId = Student::max('id') ?? 0;
        $code = 'HV-'.str_pad($latestId + 1, 3, '0', STR_PAD_LEFT);

        $student = Student::create([
            'code' => $code,
            'name' => $validated['student_name'],
            'dob' => $validated['dob'] ?? null,
            'gender' => $validated['gender'],
            'parent_name' => $lead->parent_name,
            'parent_phone' => $lead->phone,
            'parent_zalo' => $lead->phone,
            'parent_email' => $lead->email,
            'course_id' => $course->id,
            'total_sessions' => $validated['total_sessions'],
            'remaining_sessions' => $validated['total_sessions'],
            'status' => 'active',
            'retention_status' => 'normal',
            'join_date' => Carbon::today(),
            'notes' => "Chuyển đổi từ Lead CRM: {$lead->source} - ".($lead->notes ?? ''),
            'center' => $lead->center ?: 'Khoa Anh Music Hóc Môn',
        ]);

        $amount = $validated['price'] ?? $course->price;
        Invoice::create([
            'invoice_code' => 'HD-'.date('Ym').'-'.str_pad($student->id, 3, '0', STR_PAD_LEFT),
            'student_id' => $student->id,
            'course_id' => $student->course_id,
            'amount' => $amount,
            'sessions_added' => $student->total_sessions,
            'payment_method' => 'bank_transfer',
            'status' => 'paid',
            'paid_at' => Carbon::now(),
            'note' => 'Học phí chuyển đổi từ Khách hàng tiềm năng',
            'recorded_by' => 'Admin',
        ]);

        $lead->status = 'registered';
        $lead->converted_student_id = $student->id;
        $lead->save();

        return response()->json([
            'message' => "Đã chuyển đổi thành công thành Học viên {$student->name} (Mã: {$student->code})!",
            'student' => $student,
            'lead' => $lead,
        ]);
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $name = $lead->parent_name;
        $lead->delete();

        return response()->json([
            'message' => "Đã xóa khách hàng tiềm năng {$name} thành công!",
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $leads = Lead::with('course')->orderBy('created_at', 'desc')->get();
        $fileName = 'khach_hang_tiem_nang_'.date('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($leads) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, [
                'Ngày tiếp nhận',
                'Tên phụ huynh',
                'Tên học viên',
                'Số điện thoại',
                'Khóa học quan tâm',
                'Nguồn',
                'Trạng thái',
                'Cơ sở',
                'Ghi chú / Lịch hẹn',
            ]);

            foreach ($leads as $l) {
                $statusText = match ($l->status) {
                    'new' => 'Mới tiếp nhận',
                    'called' => 'Đã gọi điện',
                    'quote_sent' => 'Gửi báo giá',
                    'trial_done' => 'Đã học thử',
                    'registered' => 'Đã đăng ký',
                    'lost' => 'Đã hủy',
                    default => $l->status,
                };

                fputcsv($output, [
                    $l->created_at ? $l->created_at->format('d/m/Y') : '',
                    $l->parent_name,
                    $l->student_name ?? '',
                    $l->phone,
                    $l->course ? $l->course->name : '',
                    $l->source,
                    $statusText,
                    $l->center ?? 'Khoa Anh Music Hóc Môn',
                    $l->notes ?? '',
                ]);
            }

            fclose($output);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }
}
