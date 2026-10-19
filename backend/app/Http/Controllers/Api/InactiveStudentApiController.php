<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RetentionLog;
use App\Models\Student;
use App\Support\CenterStore;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InactiveStudentApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $centerFilter = $request->input('center', 'all');
        $statusFilter = $request->input('status', 'all');
        $upcomingDays = (int) $request->input('upcoming_days', 7);

        $query = Student::with(['course', 'latestAttendance', 'latestRetentionLog']);

        if ($statusFilter === 'all') {
            $query->whereIn('status', ['paused', 'inactive', 'dropped']);
        } elseif ($statusFilter === 'paused') {
            $query->where('status', 'paused');
        } elseif ($statusFilter === 'inactive') {
            $query->whereIn('status', ['inactive', 'dropped']);
        }

        if ($centerFilter !== 'all') {
            $query->where('center', $centerFilter);
        }

        // Đếm trên toàn bộ kết quả lọc, không phụ thuộc trang đang xem
        $counts = [
            'total' => (clone $query)->count(),
            'paused' => (clone $query)->where('status', 'paused')->count(),
            'inactive' => (clone $query)->whereIn('status', ['inactive', 'dropped'])->count(),
        ];

        $query->orderBy('updated_at', 'desc')->orderBy('id', 'desc');
        $pagination = null;
        if ($this->wantsPagination($request)) {
            $paginator = $query->paginate($this->perPage($request));
            $students = $paginator->items();
            $pagination = $this->paginationMeta($paginator);
        } else {
            $students = $query->get();
        }

        // Học viên tạm dừng sắp đi học lại (dựa trên cột end_date)
        $now = Carbon::now();
        $targetDate = Carbon::now()->addDays($upcomingDays);

        $upcomingStudents = Student::with('course')
            ->where('status', 'paused')
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$now->format('Y-m-d'), $targetDate->format('Y-m-d')])
            ->get();

        $centers = CenterStore::names();

        return response()->json([
            'students' => $students,
            'pagination' => $pagination,
            'upcoming_return_students' => $upcomingStudents,
            'centers' => $centers,
            'counts' => [
                ...$counts,
                'upcoming' => $upcomingStudents->count(),
            ],
        ]);
    }

    public function reactivate(Request $request, Student $student): JsonResponse
    {
        $student->status = 'active';
        $student->consecutive_absences = 0;
        $student->save();

        RetentionLog::create([
            'student_id' => $student->id,
            'action_type' => 'call_care',
            'status' => 'normal',
            'contact_date' => Carbon::now(),
            'content' => 'Kích hoạt học viên đi học lại thành công.',
            'created_by' => 'Admin / Quản lý',
        ]);

        return response()->json([
            'message' => "Đã kích hoạt học viên {$student->name} quay trở lại học thành công!",
            'student' => $student->load('course'),
        ]);
    }

    public function pause(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'end_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $student->status = 'paused';
        if (! empty($validated['end_date'])) {
            $student->end_date = $validated['end_date'];
        }
        if (! empty($validated['notes'])) {
            $student->notes = $validated['notes'];
        }
        $student->save();

        RetentionLog::create([
            'student_id' => $student->id,
            'action_type' => 'note_added',
            'status' => 'following_up',
            'contact_date' => Carbon::now(),
            'content' => 'Chuyển học viên sang trạng thái Tạm dừng/Bảo lưu. Dự kiến đi học lại: '.($student->end_date ? Carbon::parse($student->end_date)->format('d/m/Y') : 'Chưa rõ'),
            'created_by' => 'Admin',
        ]);

        return response()->json([
            'message' => "Đã chuyển học viên {$student->name} sang trạng thái Tạm dừng!",
            'student' => $student->load('course'),
        ]);
    }

    public function drop(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $student->status = 'inactive';
        if (! empty($validated['notes'])) {
            $student->notes = $validated['notes'];
        }
        $student->save();

        RetentionLog::create([
            'student_id' => $student->id,
            'action_type' => 'note_added',
            'status' => 'normal',
            'contact_date' => Carbon::now(),
            'content' => 'Học viên đã kết thúc/nghỉ học: '.($validated['notes'] ?? 'Hoàn thành khóa học'),
            'created_by' => 'Admin',
        ]);

        return response()->json([
            'message' => "Đã chuyển học viên {$student->name} sang trạng thái Đã nghỉ!",
            'student' => $student->load('course'),
        ]);
    }
}
