<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->input('search');
        $courseId = $request->input('course_id');
        $status = $request->input('status', 'all');
        $filterAlert = $request->input('alert'); // 'expiring', 'absence'

        $query = Student::query()
            ->with(['course', 'latestAttendance', 'latestRetentionLog']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('parent_name', 'like', "%{$search}%")
                    ->orWhere('parent_phone', 'like', "%{$search}%");
            });
        }

        if ($courseId && $courseId !== 'all') {
            $query->where('course_id', $courseId);
        }

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($filterAlert === 'expiring') {
            $query->where('status', 'active')->where('remaining_sessions', '<=', 5);
        } elseif ($filterAlert === 'absence') {
            $query->where('status', 'active')->where('consecutive_absences', '>=', 2);
        }

        $students = $query->orderBy('created_at', 'desc')->get();
        $courses = Course::where('is_active', true)->get();

        return Inertia::render('Students/Index', [
            'students' => $students,
            'courses' => $courses,
            'filters' => [
                'search' => $search,
                'course_id' => $courseId,
                'status' => $status,
                'alert' => $filterAlert,
            ],
        ]);
    }

    public function show(Student $student): Response
    {
        $student->load([
            'course',
            'attendances' => fn ($q) => $q->orderBy('attended_at', 'desc'),
            'retentionLogs' => fn ($q) => $q->orderBy('contact_date', 'desc'),
            'invoices' => fn ($q) => $q->orderBy('created_at', 'desc'),
        ]);

        $courses = Course::where('is_active', true)->get();

        return Inertia::render('Students/Show', [
            'student' => $student,
            'courses' => $courses,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'dob' => 'nullable|date',
            'gender' => 'required|string|in:Nam,Nữ,Khác',
            'parent_name' => 'required|string|max:255',
            'parent_phone' => 'required|string|max:20',
            'parent_zalo' => 'nullable|string|max:20',
            'parent_email' => 'nullable|email|max:255',
            'course_id' => 'required|exists:courses,id',
            'total_sessions' => 'required|integer|min:1',
            'status' => 'required|string|in:active,paused,dropped',
            'join_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'create_invoice' => 'nullable|boolean',
            'invoice_amount' => 'nullable|numeric|min:0',
        ]);

        // Auto generate code
        $latestId = Student::max('id') ?? 0;
        $code = 'HV-'.str_pad($latestId + 1, 3, '0', STR_PAD_LEFT);

        $student = Student::create([
            'code' => $code,
            'name' => $validated['name'],
            'dob' => $validated['dob'] ?? null,
            'gender' => $validated['gender'],
            'parent_name' => $validated['parent_name'],
            'parent_phone' => $validated['parent_phone'],
            'parent_zalo' => $validated['parent_zalo'] ?? $validated['parent_phone'],
            'parent_email' => $validated['parent_email'] ?? null,
            'course_id' => $validated['course_id'],
            'total_sessions' => $validated['total_sessions'],
            'remaining_sessions' => $validated['total_sessions'],
            'status' => $validated['status'],
            'join_date' => $validated['join_date'] ?? Carbon::today(),
            'notes' => $validated['notes'] ?? null,
        ]);

        if (! empty($validated['create_invoice'])) {
            $course = Course::find($validated['course_id']);
            $amount = $validated['invoice_amount'] ?? ($course ? $course->price : 0);

            Invoice::create([
                'invoice_code' => 'HD-'.date('Ym').'-'.str_pad($student->id, 3, '0', STR_PAD_LEFT),
                'student_id' => $student->id,
                'course_id' => $student->course_id,
                'amount' => $amount,
                'sessions_added' => $student->total_sessions,
                'payment_method' => 'bank_transfer',
                'status' => 'paid',
                'paid_at' => Carbon::now(),
                'note' => 'Học phí nhập học ban đầu',
                'recorded_by' => auth()->user()->name ?? 'Admin',
            ]);
        }

        return redirect()->back()->with('success', "Thêm học viên {$student->name} thành công!");
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'dob' => 'nullable|date',
            'gender' => 'required|string|in:Nam,Nữ,Khác',
            'parent_name' => 'required|string|max:255',
            'parent_phone' => 'required|string|max:20',
            'parent_zalo' => 'nullable|string|max:20',
            'parent_email' => 'nullable|email|max:255',
            'course_id' => 'required|exists:courses,id',
            'total_sessions' => 'required|integer|min:1',
            'remaining_sessions' => 'required|integer|min:0',
            'status' => 'required|string|in:active,paused,dropped,completed',
            'retention_status' => 'required|string|in:normal,invoice_sent,following_up,renewed',
            'notes' => 'nullable|string',
        ]);

        $student->update($validated);

        return redirect()->back()->with('success', "Cập nhật thông tin học viên {$student->name} thành công!");
    }

    public function destroy(Student $student): RedirectResponse
    {
        $name = $student->name;
        $student->delete();

        return redirect()->route('students.index')->with('success', "Đã xóa học viên {$name}!");
    }

    /**
     * 1-Click Attendance: Deduct 1 session, log attendance, reset consecutive absences
     */
    public function recordAttendance(Request $request, Student $student): RedirectResponse
    {
        if ($student->remaining_sessions <= 0) {
            return redirect()->back()->with('error', "Học viên {$student->name} đã hết buổi học! Vui lòng gia hạn thêm khóa học.");
        }

        // Calculate session number
        $attendedCount = $student->total_sessions - $student->remaining_sessions + 1;

        // Create attendance record
        Attendance::create([
            'student_id' => $student->id,
            'course_id' => $student->course_id,
            'session_number' => $attendedCount,
            'attended_at' => Carbon::now(),
            'status' => 'present',
            'note' => $request->input('note', "Điểm danh buổi #{$attendedCount}"),
            'marked_by' => auth()->user()->name ?? 'Giáo viên / Admin',
        ]);

        // Decrement remaining sessions & reset consecutive absences
        $student->decrement('remaining_sessions');
        $student->consecutive_absences = 0;
        $student->save();

        return redirect()->back()->with('success', "Điểm danh thành công cho học viên {$student->name}. Còn lại {$student->remaining_sessions} buổi.");
    }

    /**
     * Undo last attendance if marked accidentally
     */
    public function undoAttendance(Student $student): RedirectResponse
    {
        $latestAttendance = Attendance::where('student_id', $student->id)
            ->where('status', 'present')
            ->latest('attended_at')
            ->first();

        if ($latestAttendance) {
            $latestAttendance->delete();
            $student->increment('remaining_sessions');
            $student->save();

            return redirect()->back()->with('success', "Đã hoàn tác buổi điểm danh gần nhất của {$student->name}. Số buổi hiện tại: {$student->remaining_sessions}.");
        }

        return redirect()->back()->with('error', 'Không tìm thấy lịch sử điểm danh phù hợp để hoàn tác.');
    }

    /**
     * Record absence (with leave or without leave)
     */
    public function recordAbsence(Request $request, Student $student): RedirectResponse
    {
        $isWithLeave = $request->boolean('with_leave', false);

        Attendance::create([
            'student_id' => $student->id,
            'course_id' => $student->course_id,
            'session_number' => ($student->total_sessions - $student->remaining_sessions),
            'attended_at' => Carbon::now(),
            'status' => $isWithLeave ? 'absent_with_leave' : 'absent_without_leave',
            'note' => $request->input('note', $isWithLeave ? 'Nghỉ có phép' : 'Nghỉ không phép'),
            'marked_by' => auth()->user()->name ?? 'Giáo viên / Admin',
        ]);

        $student->increment('consecutive_absences');
        $student->save();

        return redirect()->back()->with('success', "Đã ghi nhận vắng học cho {$student->name} (Vắng liên tiếp: {$student->consecutive_absences} buổi).");
    }
}
