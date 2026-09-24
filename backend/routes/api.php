<?php

use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\CourseApiController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\InvoiceApiController;
use App\Http\Controllers\Api\LeadApiController;
use App\Http\Controllers\Api\RetentionApiController;
use App\Http\Controllers\Api\StudentApiController;
use Illuminate\Support\Facades\Route;

// Authentication
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthApiController::class, 'register']);
    Route::post('/login', [AuthApiController::class, 'login']);
    Route::post('/logout', [AuthApiController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthApiController::class, 'me'])->middleware('auth:sanctum');
});

// Dashboard
Route::get('/dashboard', [DashboardApiController::class, 'index']);

// Students & 1-Click Attendance
Route::prefix('students')->group(function () {
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
});

// Retention System
Route::prefix('retention')->group(function () {
    Route::get('/', [RetentionApiController::class, 'index']);
    Route::post('/{student}/status', [RetentionApiController::class, 'updateStatus']);
    Route::post('/{student}/logs', [RetentionApiController::class, 'addLog']);
});

// Leads CRM
Route::prefix('leads')->group(function () {
    Route::get('/', [LeadApiController::class, 'index']);
    Route::post('/', [LeadApiController::class, 'store']);
    Route::post('/{lead}/status', [LeadApiController::class, 'updateStatus']);
    Route::post('/{lead}/convert', [LeadApiController::class, 'convertToStudent']);
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
});
