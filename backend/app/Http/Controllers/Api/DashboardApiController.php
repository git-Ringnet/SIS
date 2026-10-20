<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Student;
use App\Support\PagePermissions;
use App\Support\StudentSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();
        $startOfYear = Carbon::now()->startOfYear();

        $center = $request->input('center');
        $centerFilter = ($center && $center !== 'all') ? trim($center) : null;

        // 1. Overview Statistics
        $activeStudentsCount = Student::where('status', 'active')
            ->when($centerFilter, fn ($q) => $q->where('center', 'like', "%{$centerFilter}%"))
            ->count();
        $pausedStudentsCount = Student::where('status', 'paused')
            ->when($centerFilter, fn ($q) => $q->where('center', 'like', "%{$centerFilter}%"))
            ->count();
        $leftStudentsCount = Student::where('status', 'dropped')
            ->when($centerFilter, fn ($q) => $q->where('center', 'like', "%{$centerFilter}%"))
            ->count();
        $totalStudentsCount = Student::query()
            ->when($centerFilter, fn ($q) => $q->where('center', 'like', "%{$centerFilter}%"))
            ->count();

        // 2. Financial Statistics
        $todayRevenue = (float) Invoice::where('status', 'paid')
            ->whereDate('paid_at', $today)
            ->when($centerFilter, fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('center', 'like', "%{$centerFilter}%")))
            ->sum('amount');

        $monthRevenue = (float) Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $startOfMonth)
            ->when($centerFilter, fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('center', 'like', "%{$centerFilter}%")))
            ->sum('amount');

        $yearRevenue = (float) Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $startOfYear)
            ->when($centerFilter, fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('center', 'like', "%{$centerFilter}%")))
            ->sum('amount');

        $debtInvoicesCount = Invoice::where('status', 'pending')
            ->when($centerFilter, fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('center', 'like', "%{$centerFilter}%")))
            ->count();
        $debtTotalAmount = (float) Invoice::where('status', 'pending')
            ->when($centerFilter, fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('center', 'like', "%{$centerFilter}%")))
            ->sum('amount');

        $totalInvoicesCount = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $startOfMonth)
            ->when($centerFilter, fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('center', 'like', "%{$centerFilter}%")))
            ->count();

        // 3. Attendance Statistics (This Month)
        $monthPresentCount = Attendance::where('attended_at', '>=', $startOfMonth)
            ->where('status', 'present')
            ->when($centerFilter, fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('center', 'like', "%{$centerFilter}%")))
            ->count();

        $monthAbsentCount = Attendance::where('attended_at', '>=', $startOfMonth)
            ->where('status', 'absent')
            ->when($centerFilter, fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('center', 'like', "%{$centerFilter}%")))
            ->count();

        $totalMonthAttendanceRecords = $monthPresentCount + $monthAbsentCount;
        $attendanceRate = $totalMonthAttendanceRecords > 0
            ? round(($monthPresentCount / $totalMonthAttendanceRecords) * 100, 1)
            : 100.0;

        // 4. Urgent Alerts (Eager Loading with courses and latest retention logs)
        $expiringStudents = Student::with(['course', 'latestRetentionLog', 'retentionLogs'])
            ->where('status', 'active')
            ->where('remaining_sessions', '<=', 5)
            ->when($centerFilter, fn ($q) => $q->where('center', 'like', "%{$centerFilter}%"))
            ->orderBy('remaining_sessions', 'asc')
            ->get();

        $absenceAlerts = Student::with(['course', 'latestAttendance'])
            ->where('status', 'active')
            ->where('consecutive_absences', '>=', 2)
            ->when($centerFilter, fn ($q) => $q->where('center', 'like', "%{$centerFilter}%"))
            ->orderBy('consecutive_absences', 'desc')
            ->get();

        // Đến hạn đóng học phí: còn <= 1 buổi hoặc khóa học kết thúc trong 5 ngày tới (kể cả đã quá hạn)
        $expiringEndDateStudents = Student::with(['course'])
            ->where('status', 'active')
            ->when($centerFilter, fn ($q) => $q->where('center', 'like', "%{$centerFilter}%"))
            ->where(function ($query) use ($today) {
                $query->whereDate('end_date', '<=', $today->copy()->addDays(5))
                    ->orWhere('remaining_sessions', '<=', 1);
            })
            ->orderBy('remaining_sessions', 'asc')
            ->orderBy('end_date', 'asc')
            ->get();

        // 5. Leads Overview
        $leadsCount = Lead::whereNotIn('status', ['registered', 'lost'])
            ->when($centerFilter, fn ($q) => $q->where('center', 'like', "%{$centerFilter}%"))
            ->count();
        $newLeadsCount = Lead::where('status', 'new')
            ->when($centerFilter, fn ($q) => $q->where('center', 'like', "%{$centerFilter}%"))
            ->count();

        // 6. Recent Attendances
        $recentAttendances = Attendance::with(['student.course'])
            ->whereDate('attended_at', $today)
            ->when($centerFilter, fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('center', 'like', "%{$centerFilter}%")))
            ->latest('attended_at')
            ->take(8)
            ->get();

        // 7. Courses & Templates
        $courses = Course::withCount(['activeStudents' => function ($q) use ($centerFilter) {
            if ($centerFilter) {
                $q->where('center', 'like', "%{$centerFilter}%");
            }
        }])->get();
        $templates = MessageTemplate::all();

        // 8. Việc cần xử lý hôm nay (Today's Urgent Actions)
        $todoDebtInvoices = $debtInvoicesCount;
        $todoTuitionDue = $expiringEndDateStudents->count();
        // Học viên có ca học hôm nay mà còn ca chưa điểm danh (một ngày có thể nhiều ca)
        $todayRecords = Attendance::whereDate('attended_at', $today)->get(['student_id', 'slot_id'])->groupBy('student_id');
        $todoNotAttendedToday = Student::where('status', 'active')
            ->when($centerFilter, fn ($q) => $q->where('center', 'like', "%{$centerFilter}%"))
            ->whereJsonContains('class_days', $today->dayOfWeek)
            ->get()
            ->filter(function ($st) use ($todayRecords, $today) {
                $slots = StudentSchedule::slotsOn($st, $today->dayOfWeek);
                $marked = $todayRecords->get($st->id, collect())->pluck('slot_id')->all();
                foreach ($slots as $i => $slot) {
                    if (! in_array($slot['id'], $marked, true) && ! ($i === 0 && in_array(null, $marked, true))) {
                        return true;
                    }
                }

                return false;
            })
            ->count();
        $todoConsecutiveAbsences = $absenceAlerts->count();
        $todoBirthdaysThisMonth = Student::where('status', 'active')
            ->when($centerFilter, fn ($q) => $q->where('center', 'like', "%{$centerFilter}%"))
            ->whereMonth('dob', Carbon::now()->month)
            ->count();
        $todoExpiringFewSessions = Student::where('status', 'active')
            ->when($centerFilter, fn ($q) => $q->where('center', 'like', "%{$centerFilter}%"))
            ->whereBetween('remaining_sessions', [0, 2])
            ->count();
        $todoPausedReturning = Student::where('status', 'paused')
            ->when($centerFilter, fn ($q) => $q->where('center', 'like', "%{$centerFilter}%"))
            ->count();

        // Nhân sự bị chặn Doanh thu / Hóa đơn thì không trả số tiền về giao diện
        if (PagePermissions::isBlocked($request->user(), 'Doanh thu')) {
            $todayRevenue = $monthRevenue = $yearRevenue = null;
        }
        if (PagePermissions::isBlocked($request->user(), 'Hóa đơn & Thu phí')) {
            $debtTotalAmount = null;
        }

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
