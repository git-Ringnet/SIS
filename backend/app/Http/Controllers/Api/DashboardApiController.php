<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class DashboardApiController extends Controller
{
    public function index(): JsonResponse
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();
        $startOfYear = Carbon::now()->startOfYear();

        // 1. Overview Statistics
        $activeStudentsCount = Student::where('status', 'active')->count();
        $pausedStudentsCount = Student::where('status', 'paused')->count();
        $leftStudentsCount = Student::where('status', 'dropped')->count();
        $totalStudentsCount = Student::count();

        // 2. Financial Statistics
        $todayRevenue = (float) Invoice::where('status', 'paid')
            ->whereDate('paid_at', $today)
            ->sum('amount');

        $monthRevenue = (float) Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $startOfMonth)
            ->sum('amount');

        $yearRevenue = (float) Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $startOfYear)
            ->sum('amount');

        $debtInvoicesCount = Invoice::where('status', 'pending')->count();
        $debtTotalAmount = (float) Invoice::where('status', 'pending')->sum('amount');

        $totalInvoicesCount = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $startOfMonth)
            ->count();

        // 3. Attendance Statistics (This Month)
        $monthPresentCount = Attendance::where('attended_at', '>=', $startOfMonth)
            ->where('status', 'present')
            ->count();

        $monthAbsentCount = Attendance::where('attended_at', '>=', $startOfMonth)
            ->where('status', 'absent')
            ->count();

        $totalMonthAttendanceRecords = $monthPresentCount + $monthAbsentCount;
        $attendanceRate = $totalMonthAttendanceRecords > 0
            ? round(($monthPresentCount / $totalMonthAttendanceRecords) * 100, 1)
            : 100.0;

        // 4. Urgent Alerts (Eager Loading with courses and latest retention logs)
        $expiringStudents = Student::with(['course', 'latestRetentionLog', 'retentionLogs'])
            ->where('status', 'active')
            ->where('remaining_sessions', '<=', 5)
            ->orderBy('remaining_sessions', 'asc')
            ->get();

        $absenceAlerts = Student::with(['course', 'latestAttendance'])
            ->where('status', 'active')
            ->where('consecutive_absences', '>=', 2)
            ->orderBy('consecutive_absences', 'desc')
            ->get();

        $expiringEndDateStudents = Student::with(['course'])
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$today, $today->copy()->addDays(5)])
            ->get();

        // 5. Leads Overview
        $leadsCount = Lead::whereNotIn('status', ['registered', 'lost'])->count();
        $newLeadsCount = Lead::where('status', 'new')->count();

        // 6. Recent Attendances
        $recentAttendances = Attendance::with(['student.course'])
            ->whereDate('attended_at', $today)
            ->latest('attended_at')
            ->take(8)
            ->get();

        // 7. Courses & Templates
        $courses = Course::withCount('activeStudents')->get();
        $templates = MessageTemplate::all();

        // 8. Việc cần xử lý hôm nay (Today's Urgent Actions)
        $todoDebtInvoices = $debtInvoicesCount;
        $todoTuitionDue = Student::where('status', 'active')
            ->where(function ($query) use ($today) {
                $query->whereDate('end_date', '<=', $today->copy()->addDays(5))
                    ->orWhere('remaining_sessions', '<=', 1);
            })
            ->count();
        $attendedStudentIdsToday = Attendance::whereDate('attended_at', $today)->pluck('student_id');
        $todoNotAttendedToday = Student::where('status', 'active')
            ->whereNotIn('id', $attendedStudentIdsToday)
            ->count();
        $todoConsecutiveAbsences = $absenceAlerts->count();
        $todoBirthdaysThisMonth = Student::where('status', 'active')
            ->whereMonth('dob', Carbon::now()->month)
            ->count();
        $todoExpiringFewSessions = Student::where('status', 'active')
            ->whereBetween('remaining_sessions', [0, 2])
            ->count();
        $todoPausedReturning = Student::where('status', 'paused')->count();

        return response()->json([
            'stats' => [
                'activeStudents' => $activeStudentsCount,
                'pausedStudents' => $pausedStudentsCount,
                'leftStudents' => $leftStudentsCount,
                'totalStudents' => $totalStudentsCount,
                'todayRevenue' => $todayRevenue,
                'monthRevenue' => $monthRevenue,
                'yearRevenue' => $yearRevenue,
                'debtInvoicesCount' => $debtInvoicesCount,
                'debtTotalAmount' => $debtTotalAmount,
                'monthPresentCount' => $monthPresentCount,
                'monthAbsentCount' => $monthAbsentCount,
                'attendanceRate' => $attendanceRate,
                'totalInvoicesCount' => $totalInvoicesCount,
                'expiringCount' => $expiringStudents->count(),
                'absenceCount' => $absenceAlerts->count(),
                'leadsCount' => $leadsCount,
                'newLeadsCount' => $newLeadsCount,
                // Việc cần xử lý hôm nay
                'todoDebtInvoices' => $todoDebtInvoices,
                'todoTuitionDue' => $todoTuitionDue,
                'todoNotAttendedToday' => $todoNotAttendedToday,
                'todoConsecutiveAbsences' => $todoConsecutiveAbsences,
                'todoBirthdaysThisMonth' => $todoBirthdaysThisMonth,
                'todoExpiringFewSessions' => $todoExpiringFewSessions,
                'todoPausedReturning' => $todoPausedReturning,
            ],
            'expiringStudents' => $expiringStudents,
            'absenceAlerts' => $absenceAlerts,
            'expiringEndDateStudents' => $expiringEndDateStudents,
            'recentAttendances' => $recentAttendances,
            'courses' => $courses,
            'templates' => $templates,
        ]);
    }
}
