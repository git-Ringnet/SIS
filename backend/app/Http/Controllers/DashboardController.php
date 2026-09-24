<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Student;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();

        // 1. Overview Statistics
        $activeStudentsCount = Student::where('status', 'active')->count();
        $pausedStudentsCount = Student::where('status', 'paused')->count();
        $totalStudentsCount = Student::count();

        // 2. Financial Statistics
        $todayRevenue = (float) Invoice::where('status', 'paid')
            ->whereDate('paid_at', $today)
            ->sum('amount');

        $monthRevenue = (float) Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $startOfMonth)
            ->sum('amount');

        $totalInvoicesCount = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $startOfMonth)
            ->count();

        // 3. Urgent Alerts (Eager Loading with courses and latest retention logs)
        // A: Expiring sessions (remaining_sessions <= 5)
        $expiringStudents = Student::with(['course', 'latestRetentionLog'])
            ->where('status', 'active')
            ->where('remaining_sessions', '<=', 5)
            ->orderBy('remaining_sessions', 'asc')
            ->get();

        // B: Consecutive absences (>= 2) needing immediate parent outreach
        $absenceAlerts = Student::with(['course', 'latestAttendance'])
            ->where('status', 'active')
            ->where('consecutive_absences', '>=', 2)
            ->orderBy('consecutive_absences', 'desc')
            ->get();

        // 4. Leads Overview in Pipeline
        $leadsCount = Lead::whereNotIn('status', ['registered', 'lost'])->count();
        $newLeadsCount = Lead::where('status', 'new')->count();

        // 5. Recent Attendances today
        $recentAttendances = Attendance::with(['student.course'])
            ->whereDate('attended_at', $today)
            ->latest('attended_at')
            ->take(8)
            ->get();

        // 6. Quick list of all courses for shortcuts
        $courses = Course::withCount('activeStudents')->get();

        return Inertia::render('Dashboard', [
            'stats' => [
                'activeStudents' => $activeStudentsCount,
                'pausedStudents' => $pausedStudentsCount,
                'totalStudents' => $totalStudentsCount,
                'todayRevenue' => $todayRevenue,
                'monthRevenue' => $monthRevenue,
                'totalInvoicesCount' => $totalInvoicesCount,
                'expiringCount' => $expiringStudents->count(),
                'absenceCount' => $absenceAlerts->count(),
                'leadsCount' => $leadsCount,
                'newLeadsCount' => $newLeadsCount,
            ],
            'expiringStudents' => $expiringStudents,
            'absenceAlerts' => $absenceAlerts,
            'recentAttendances' => $recentAttendances,
            'courses' => $courses,
        ]);
    }
}
