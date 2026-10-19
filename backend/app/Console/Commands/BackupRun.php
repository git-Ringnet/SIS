<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use App\Support\ActivityLogger;
use Illuminate\Console\Command;
use Throwable;

class BackupRun extends Command
{
    protected $signature = 'sis:backup {--trigger=manual : manual | schedule}';

    protected $description = 'Sao lưu toàn bộ dữ liệu (database + file dữ liệu) và đẩy lên Google Drive bằng rclone';

    public function handle(BackupService $backups): int
    {
        try {
            $entry = $backups->create($this->option('trigger') === 'schedule' ? 'schedule' : 'manual');
        } catch (Throwable $e) {
            report($e);
            $this->error('Sao lưu thất bại: '.$e->getMessage());

            return self::FAILURE;
        }

        ActivityLogger::log('created', 'backup', $entry['file'], null, [], 'Drive: '.$entry['drive']['status']);

        $this->info("Đã tạo {$entry['file']} (".number_format($entry['size'] / 1024, 1).' KB, '.$entry['tables'].' bảng, '.$entry['files'].' file).');
        $this->line('Google Drive: '.$entry['drive']['status'].($entry['drive']['message'] ? ' – '.$entry['drive']['message'] : ''));

        return $entry['drive']['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
