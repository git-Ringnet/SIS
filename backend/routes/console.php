<?php

use App\Models\ActivityLog;
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
