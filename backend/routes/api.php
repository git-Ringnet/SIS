<?php

use App\Http\Controllers\Api\AbsenceApiController;
use App\Http\Controllers\Api\ActivityLogApiController;
use App\Http\Controllers\Api\AttendanceApiController;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\BackupApiController;
use App\Http\Controllers\Api\BirthdayApiController;
use App\Http\Controllers\Api\CenterApiController;
use App\Http\Controllers\Api\CourseApiController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\ExportApiController;
use App\Http\Controllers\Api\InactiveStudentApiController;
use App\Http\Controllers\Api\InventoryApiController;
use App\Http\Controllers\Api\InvoiceApiController;
use App\Http\Controllers\Api\LeadApiController;
use App\Http\Controllers\Api\MessageTemplateApiController;
use App\Http\Controllers\Api\ReportApiController;
use App\Http\Controllers\Api\RetentionApiController;
use App\Http\Controllers\Api\RevenueApiController;
use App\Http\Controllers\Api\SettingsApiController;
use App\Http\Controllers\Api\StaffApiController;
use App\Http\Controllers\Api\StudentApiController;
use App\Http\Controllers\Api\TeacherCheckinApiController;
use App\Http\Middleware\EnsurePageAccess;
use Illuminate\Support\Facades\Route;

// Authentication
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthApiController::class, 'register']);
    Route::post('/login', [AuthApiController::class, 'login']);
    Route::post('/logout', [AuthApiController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthApiController::class, 'me'])->middleware('auth:sanctum');
});

// Tên + logo thương hiệu cho màn đăng nhập (công khai)
Route::get('/brand', [SettingsApiController::class, 'publicBrand']);

// Toàn bộ API nghiệp vụ bắt buộc đăng nhập (token Sanctum) và tôn trọng quyền "trang bị chặn" của nhân sự
Route::middleware(['auth:sanctum', EnsurePageAccess::class])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardApiController::class, 'index']);

    // Students & 1-Click Attendance
    Route::prefix('students')->group(function () {
        Route::get('/template', [StudentApiController::class, 'downloadTemplate']);
        Route::post('/import', [StudentApiController::class, 'importCsv']);
        Route::get('/', [StudentApiController::class, 'index']);
        Route::post('/', [StudentApiController::class, 'store']);
        Route::get('/{student}', [StudentApiController::class, 'show']);
        Route::put('/{student}', [StudentApiController::class, 'update']);
        Route::delete('/{student}', [StudentApiController::class, 'destroy']);

        Route::post('/{student}/attendance', [StudentApiController::class, 'recordAttendance']);
        Route::post('/{student}/attendance/undo', [StudentApiController::class, 'undoAttendance']);
        Route::post('/{student}/absence', [StudentApiController::class, 'recordAbsence']);
        Route::post('/{student}/supplies', [StudentApiController::class, 'addSupplies']);
        Route::put('/{student}/progress', [StudentApiController::class, 'updateCourseProgress']);
        Route::get('/{student}/tuition-invoice.pdf', [StudentApiController::class, 'tuitionInvoicePdf']);
    });

    // Retention System
    Route::prefix('retention')->group(function () {
        Route::get('/', [RetentionApiController::class, 'index']);
        Route::post('/{student}/status', [RetentionApiController::class, 'updateStatus']);
        Route::post('/{student}/logs', [RetentionApiController::class, 'addLog']);
    });

    // Message Templates
    Route::prefix('message-templates')->group(function () {
        Route::get('/', [MessageTemplateApiController::class, 'index']);
        Route::post('/', [MessageTemplateApiController::class, 'store']);
        Route::put('/{template}', [MessageTemplateApiController::class, 'update']);
        Route::post('/{template}/reset', [MessageTemplateApiController::class, 'reset']);
    });

    // Leads CRM
    Route::prefix('leads')->group(function () {
        Route::get('/', [LeadApiController::class, 'index']);
        Route::get('/export', [LeadApiController::class, 'export']);
        Route::post('/', [LeadApiController::class, 'store']);
        Route::post('/{lead}/status', [LeadApiController::class, 'updateStatus']);
        Route::post('/{lead}/convert', [LeadApiController::class, 'convertToStudent']);
        Route::delete('/{lead}', [LeadApiController::class, 'destroy']);
    });

    // Courses
    Route::prefix('courses')->group(function () {
        Route::get('/', [CourseApiController::class, 'index']);
        Route::post('/', [CourseApiController::class, 'store']);
        Route::put('/{course}', [CourseApiController::class, 'update']);
        Route::delete('/{course}', [CourseApiController::class, 'destroy']);
    });

    // Invoices
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceApiController::class, 'index']);
        Route::post('/', [InvoiceApiController::class, 'store']);
        Route::put('/{invoice}', [InvoiceApiController::class, 'update']);
        Route::delete('/{invoice}', [InvoiceApiController::class, 'destroy']);
        Route::get('/{invoice}/pdf', [InvoiceApiController::class, 'pdf']);
    });

    // Reports
    Route::get('/reports', [ReportApiController::class, 'index']);

    // Data Export
    Route::get('/export/{type}', [ExportApiController::class, 'export']);

    // Settings
    Route::prefix('settings')->group(function () {
        Route::get('/', [SettingsApiController::class, 'index']);
        Route::put('/profile', [SettingsApiController::class, 'updateProfile']);
        Route::put('/password', [SettingsApiController::class, 'updatePassword']);
        Route::put('/brand', [SettingsApiController::class, 'updateBrand']);
        Route::put('/vat', [SettingsApiController::class, 'updateVat']);
        Route::get('/activity-logs', [ActivityLogApiController::class, 'index']);

        // Sao lưu & khôi phục dữ liệu (chỉ chủ trung tâm)
        Route::prefix('backups')->group(function () {
            Route::get('/', [BackupApiController::class, 'index']);
            Route::post('/', [BackupApiController::class, 'store']);
            Route::post('/import', [BackupApiController::class, 'import']);
            Route::get('/{file}/download', [BackupApiController::class, 'download'])->where('file', 'sis-backup-[A-Za-z0-9-]+\.zip');
            Route::post('/{file}/upload', [BackupApiController::class, 'upload'])->where('file', 'sis-backup-[A-Za-z0-9-]+\.zip');
            Route::post('/{file}/restore', [BackupApiController::class, 'restore'])->where('file', 'sis-backup-[A-Za-z0-9-]+\.zip');
            Route::delete('/{file}', [BackupApiController::class, 'destroy'])->where('file', 'sis-backup-[A-Za-z0-9-]+\.zip');
        });
    });

    // Staff
    Route::prefix('staff')->group(function () {
        Route::get('/', [StaffApiController::class, 'index']);
        Route::post('/invites', [StaffApiController::class, 'createInvite']);
        Route::put('/members/{id}/role', [StaffApiController::class, 'updateRole']);
        Route::put('/members/{id}/center', [StaffApiController::class, 'updateCenter']);
        Route::put('/members/{id}/password', [StaffApiController::class, 'resetPassword']);
        Route::put('/members/{id}/permissions', [StaffApiController::class, 'updatePermissions']);
        Route::delete('/members/{id}', [StaffApiController::class, 'destroy']);
    });

    // Centers
    Route::prefix('centers')->group(function () {
        Route::get('/', [CenterApiController::class, 'index']);
        Route::post('/', [CenterApiController::class, 'store']);
        Route::put('/{id}', [CenterApiController::class, 'update']);
        Route::delete('/{id}', [CenterApiController::class, 'destroy']);
        Route::post('/{id}/rooms', [CenterApiController::class, 'addRoom']);
        Route::delete('/{centerId}/rooms/{roomId}', [CenterApiController::class, 'deleteRoom']);
    });

    // Inactive / Paused Students
    Route::prefix('inactive-students')->group(function () {
        Route::get('/', [InactiveStudentApiController::class, 'index']);
        Route::post('/{student}/reactivate', [InactiveStudentApiController::class, 'reactivate']);
        Route::post('/{student}/pause', [InactiveStudentApiController::class, 'pause']);
        Route::post('/{student}/drop', [InactiveStudentApiController::class, 'drop']);
    });

    // Birthdays
    Route::get('/birthdays', [BirthdayApiController::class, 'index']);

    // Inventory / Học cụ
    Route::prefix('inventory')->group(function () {
        Route::get('/', [InventoryApiController::class, 'index']);
        Route::post('/', [InventoryApiController::class, 'store']);
        Route::get('/export', [InventoryApiController::class, 'export']);
        Route::get('/template', [InventoryApiController::class, 'template']);
        Route::post('/import', [InventoryApiController::class, 'import']);
        Route::put('/{id}', [InventoryApiController::class, 'update']);
        Route::delete('/{id}', [InventoryApiController::class, 'destroy']);
        Route::post('/{id}/adjust', [InventoryApiController::class, 'adjust']);
    });

    // Revenue / Doanh thu
    Route::prefix('revenue')->group(function () {
        Route::get('/', [RevenueApiController::class, 'index']);
        Route::post('/', [RevenueApiController::class, 'store']);
        Route::get('/export', [RevenueApiController::class, 'export']);
        Route::post('/expenses', [RevenueApiController::class, 'storeExpense']);
    });

    // Absences / Vắng liên tiếp
    Route::prefix('absences')->group(function () {
        Route::get('/', [AbsenceApiController::class, 'index']);
        Route::post('/{student}/care', [AbsenceApiController::class, 'recordCare']);
        Route::post('/{student}/reset', [AbsenceApiController::class, 'resetAbsences']);
        Route::post('/{student}/makeup', [AbsenceApiController::class, 'scheduleMakeup']);
    });

    // Teacher Check-in / Điểm danh ca dạy giáo viên
    Route::prefix('teacher-checkin')->group(function () {
        Route::get('/', [TeacherCheckinApiController::class, 'index']);
        Route::post('/checkin', [TeacherCheckinApiController::class, 'checkin']);
        Route::post('/absence', [TeacherCheckinApiController::class, 'recordAbsence']);
        Route::post('/rates', [TeacherCheckinApiController::class, 'updateRates']);
        Route::get('/export', [TeacherCheckinApiController::class, 'exportMonthly']);
    });

    // Attendance / Điểm danh học viên hàng ngày
    Route::prefix('attendance')->group(function () {
        Route::get('/', [AttendanceApiController::class, 'index']);
        Route::post('/single-lesson', [AttendanceApiController::class, 'scheduleSingleLesson']);
        Route::post('/{student}/mark', [AttendanceApiController::class, 'markAttendance']);
    });
});
