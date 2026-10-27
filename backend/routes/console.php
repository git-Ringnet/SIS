<?php

use App\Models\ActivityLog;
use App\Models\Student;
use App\Support\StudentSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Xoá nhật ký hoạt động cũ hơn ActivityLog::RETENTION_DAYS ngày
Schedule::command('model:prune', ['--model' => [ActivityLog::class]])->daily();

// Sao lưu toàn bộ dữ liệu mỗi ngày và đẩy lên Google Drive (cấu hình trong config/backup.php / .env)
Schedule::command('sis:backup --trigger=schedule')
    ->dailyAt(config('backup.schedule_time'))
    ->withoutOverlapping();

// Đánh số lại "Buổi n" chính thức cho dữ liệu điểm danh cũ (học bù / học lẻ / nghỉ có phép không có số). Chạy một lần khi cập nhật.
Artisan::command('sis:renumber-sessions', function () {
    $count = 0;
    Student::query()->each(function (Student $student) use (&$count) {
        StudentSchedule::syncOfficialNumbers($student);
        $count++;
    });
    $this->info("Đã đánh số lại buổi học chính thức cho {$count} học viên.");
})->purpose('Đánh số lại buổi học chính thức cho dữ liệu điểm danh cũ');
