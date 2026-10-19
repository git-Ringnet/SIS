<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class BackupRestore extends Command
{
    protected $signature = 'sis:backup:restore
        {file : Tên bản backup trong thư mục backup, hoặc đường dẫn tới file zip (ví dụ tải về từ Google Drive)}
        {--force : Không hỏi xác nhận}';

    protected $description = 'Khôi phục toàn bộ dữ liệu hệ thống từ một bản backup (ghi đè dữ liệu hiện tại)';

    public function handle(BackupService $backups): int
    {
        $file = $this->argument('file');

        try {
            // File nằm ngoài thư mục backup (ví dụ dựng lại máy host mới từ bản trên Drive) thì nhập vào trước
            if (File::exists($file)) {
                $file = $backups->import($file, basename($file));
                $this->line("Đã nhập file vào thư mục backup: {$file}");
            }
            $backups->path($file);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Toàn bộ dữ liệu hiện tại sẽ bị ghi đè bởi {$file}. Tiếp tục?")) {
            return self::FAILURE;
        }

        try {
            $result = $backups->restore($file);
        } catch (Throwable $e) {
            report($e);
            $this->error('Khôi phục thất bại: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Đã khôi phục từ {$result['restored']}. Bản sao lưu dữ liệu trước khi khôi phục: {$result['safety_backup']}");

        return self::SUCCESS;
    }
}
