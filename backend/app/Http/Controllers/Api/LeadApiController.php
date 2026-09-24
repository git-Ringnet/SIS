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

class LeadApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'all');
        $courseId = $request->input('course_id');
        $search = $request->input('search');

        $query = Lead::with(['course', 'convertedStudent']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($courseId && $courseId !== 'all') {
            $query->where('interested_course_id', $courseId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('parent_name', 'like', "%{$search}%")
                    ->orWhere('student_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
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

        return response()->json([
            'leads' => $leads,
            'courses' => $courses,
            'stages' => $stages,
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
        ]);

        $lead->status = $validated['status'];
        if (! empty($validated['notes'])) {
            $lead->notes = ($lead->notes ? $lead->notes."\n" : '').'['.date('d/m/Y H:i').'] '.$validated['notes'];
        }
        $lead->save();
        $lead->load('course');

        return response()->json([
            'message' => 'Cập nhật trạng thái thành công!',
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
}
