<?php

use App\Http\Controllers\CourseController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\RetentionController;
use App\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;

// Dashboard
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');

// Students & 1-Click Attendance
Route::prefix('students')->name('students.')->group(function () {
    Route::get('/', [StudentController::class, 'index'])->name('index');
    Route::post('/', [StudentController::class, 'store'])->name('store');
    Route::get('/{student}', [StudentController::class, 'show'])->name('show');
    Route::put('/{student}', [StudentController::class, 'update'])->name('update');
    Route::delete('/{student}', [StudentController::class, 'destroy'])->name('destroy');

    // Attendance actions
    Route::post('/{student}/attendance', [StudentController::class, 'recordAttendance'])->name('attendance');
    Route::post('/{student}/attendance/undo', [StudentController::class, 'undoAttendance'])->name('attendance.undo');
    Route::post('/{student}/absence', [StudentController::class, 'recordAbsence'])->name('absence');
});

// Retention System (Khối Cảnh Báo & Tái Đăng Ký - Highest Priority)
Route::prefix('retention')->name('retention.')->group(function () {
    Route::get('/', [RetentionController::class, 'index'])->name('index');
    Route::post('/{student}/status', [RetentionController::class, 'updateStatus'])->name('status');
    Route::post('/{student}/logs', [RetentionController::class, 'addLog'])->name('logs');
    Route::get('/{student}/render-template', [RetentionController::class, 'renderTemplate'])->name('render-template');
});

// Lead Management (CRM Pipeline)
Route::prefix('leads')->name('leads.')->group(function () {
    Route::get('/', [LeadController::class, 'index'])->name('index');
    Route::post('/', [LeadController::class, 'store'])->name('store');
    Route::post('/{lead}/status', [LeadController::class, 'updateStatus'])->name('status');
    Route::post('/{lead}/convert', [LeadController::class, 'convertToStudent'])->name('convert');
});

// Courses
Route::prefix('courses')->name('courses.')->group(function () {
    Route::get('/', [CourseController::class, 'index'])->name('index');
    Route::post('/', [CourseController::class, 'store'])->name('store');
    Route::put('/{course}', [CourseController::class, 'update'])->name('update');
    Route::delete('/{course}', [CourseController::class, 'destroy'])->name('destroy');
});

// Invoices & Cashflow
Route::prefix('invoices')->name('invoices.')->group(function () {
    Route::get('/', [InvoiceController::class, 'index'])->name('index');
    Route::post('/', [InvoiceController::class, 'store'])->name('store');
});
