<?php

namespace App\Services\Backup;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Sao lưu toàn bộ dữ liệu hệ thống: database + các file dữ liệu trong storage/app.
 * Bản backup (zip) được lưu trên máy host, sau đó đẩy lên Google Drive bằng rclone.
 */
class BackupService
{
    // Tên file hợp lệ, tránh truyền đường dẫn tuỳ ý từ request (path traversal)
    private const FILE_PATTERN = '/^sis-backup-\d{8}-\d{6}(-[a-z-]+)?\.zip$/';

    public function __construct(private DatabaseDumper $dumper) {}

    public function directory(): string
    {
        $dir = config('backup.path');
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * Danh sách bản backup trên máy host, mới nhất trước.
     */
    public function list(): array
    {
        $index = $this->readIndex();

        return collect(File::files($this->directory()))
            ->filter(fn ($f) => preg_match(self::FILE_PATTERN, $f->getFilename()))
            ->map(fn ($f) => array_merge([
                'file' => $f->getFilename(),
                'trigger' => 'manual',
                'created_by' => null,
                'drive' => ['status' => 'skipped', 'message' => null, 'uploaded_at' => null],
            ], $index[$f->getFilename()] ?? [], [
                'file' => $f->getFilename(),
                'size' => $f->getSize(),
                'created_at' => $index[$f->getFilename()]['created_at'] ?? date(DATE_ATOM, $f->getMTime()),
            ]))
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    /**
     * Tạo bản backup mới, đẩy lên Google Drive (nếu đã cấu hình) và dọn bản cũ.
     *
     * @param  string  $trigger  manual | schedule | pre-restore
     */
    public function create(string $trigger = 'manual', ?User $by = null): array
    {
        return $this->withLock(function () use ($trigger, $by) {
            $suffix = $trigger === 'manual' ? '' : '-'.$trigger;
            $file = 'sis-backup-'.now()->format('Ymd-His').$suffix.'.zip';
            $zipPath = $this->directory().DIRECTORY_SEPARATOR.$file;
            $workDir = $this->tempDir();

            try {
                $tableCount = $this->dumper->dump($workDir.'/database.sql');

                $zip = new ZipArchive;
                if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException("Không tạo được file {$zipPath}");
                }

                $zip->addFile($workDir.'/database.sql', 'database.sql');
                $fileCount = 0;
                foreach (config('backup.storage_dirs') as $name => $dir) {
                    $fileCount += $this->addDirectory($zip, $dir, "storage/{$name}");
                }
                $zip->addFromString('manifest.json', json_encode([
                    'app' => config('app.name'),
                    'created_at' => now()->toIso8601String(),
                    'db_driver' => config('database.default'),
                    'tables' => $tableCount,
                    'files' => $fileCount,
                    'storage_dirs' => array_keys(config('backup.storage_dirs')),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $zip->close();
            } catch (Throwable $e) {
                File::delete($zipPath);
                throw $e;
            } finally {
                File::deleteDirectory($workDir);
            }

            $entry = [
                'file' => $file,
                'created_at' => now()->toIso8601String(),
                'trigger' => $trigger,
                'created_by' => $by?->name ?? 'Hệ thống (tự động)',
                'tables' => $tableCount,
                'files' => $fileCount,
                'drive' => ['status' => 'skipped', 'message' => 'Chưa cấu hình Google Drive (BACKUP_RCLONE_REMOTE).', 'uploaded_at' => null],
            ];
            $this->updateIndex($file, $entry);

            if ($this->rcloneRemote()) {
                $entry['drive'] = $this->upload($file);
            }

            $this->pruneLocal();
            $this->pruneRemote();

            return array_merge($entry, ['size' => filesize($zipPath)]);
        });
    }

    /**
     * Đẩy (lại) một bản backup lên Google Drive bằng rclone.
     */
    public function upload(string $file): array
    {
        $path = $this->path($file);

        if (! $this->rcloneRemote()) {
            $drive = ['status' => 'skipped', 'message' => 'Chưa cấu hình Google Drive (BACKUP_RCLONE_REMOTE).', 'uploaded_at' => null];
        } else {
            $result = $this->rclone(['copy', $path, $this->rcloneRemote()]);
            $drive = $result->successful()
                ? ['status' => 'uploaded', 'message' => 'Đã đẩy lên '.$this->rcloneRemote(), 'uploaded_at' => now()->toIso8601String()]
                : ['status' => 'failed', 'message' => Str::limit(trim($result->errorOutput() ?: $result->output()), 500), 'uploaded_at' => null];
        }

        $this->updateIndex($file, ['drive' => $drive]);

        return $drive;
    }

    /**
     * Khôi phục toàn bộ hệ thống từ một bản backup.
     * Trước khi ghi đè, tự tạo một bản backup an toàn của dữ liệu hiện tại.
     */
    public function restore(string $file, ?User $by = null): array
    {
        $path = $this->path($file);
        $safety = $this->create('pre-restore', $by);

        $this->withLock(function () use ($path) {
            $workDir = $this->tempDir();
            try {
                $zip = new ZipArchive;
                if ($zip->open($path) !== true || $zip->locateName('database.sql') === false) {
                    throw new RuntimeException('File backup không hợp lệ (thiếu database.sql).');
                }
                $zip->extractTo($workDir);
                $zip->close();

                $this->dumper->restore($workDir.'/database.sql');

                foreach (config('backup.storage_dirs') as $name => $target) {
                    $source = $workDir."/storage/{$name}";
                    File::ensureDirectoryExists($target);
                    File::cleanDirectory($target);
                    if (File::isDirectory($source)) {
                        File::copyDirectory($source, $target);
                    }
                }
            } finally {
                File::deleteDirectory($workDir);
            }
        });

        return ['restored' => $file, 'safety_backup' => $safety['file']];
    }

    /**
     * Nhập một file backup từ bên ngoài (ví dụ tải về từ Google Drive) vào thư mục backup.
     */
    public function import(string $sourcePath, ?string $originalName = null): string
    {
        $name = $originalName && preg_match(self::FILE_PATTERN, $originalName)
            ? $originalName
            : 'sis-backup-'.now()->format('Ymd-His').'-imported.zip';

        $zip = new ZipArchive;
        if ($zip->open($sourcePath) !== true || $zip->locateName('database.sql') === false) {
            throw new RuntimeException('File không phải bản backup của hệ thống (thiếu database.sql).');
        }
        $zip->close();

        File::copy($sourcePath, $this->directory().DIRECTORY_SEPARATOR.$name);
        $this->updateIndex($name, ['file' => $name, 'created_at' => now()->toIso8601String(), 'trigger' => 'imported']);

        return $name;
    }

    public function delete(string $file): void
    {
        File::delete($this->path($file));
        $index = $this->readIndex();
        unset($index[$file]);
        $this->writeIndex($index);
    }

    /**
     * Đường dẫn tuyệt đối của bản backup, chỉ chấp nhận tên file hợp lệ đang tồn tại.
     */
    public function path(string $file): string
    {
        $file = basename($file);
        $path = $this->directory().DIRECTORY_SEPARATOR.$file;

        if (! preg_match(self::FILE_PATTERN, $file) || ! File::exists($path)) {
            throw new RuntimeException('Không tìm thấy bản backup này.');
        }

        return $path;
    }

    /**
     * Tình trạng cấu hình để hiển thị trên màn quản lý backup.
     */
    public function status(): array
    {
        $rclone = ['configured' => (bool) $this->rcloneRemote(), 'remote' => $this->rcloneRemote(), 'available' => false, 'version' => null, 'error' => null];

        try {
            $result = $this->rclone(['version'], 15);
            $rclone['available'] = $result->successful();
            $rclone['version'] = $result->successful() ? trim(strtok($result->output(), "\n")) : null;
            $rclone['error'] = $result->successful() ? null : 'Không chạy được rclone: '.Str::limit(trim($result->errorOutput()), 200);
        } catch (Throwable $e) {
            $rclone['error'] = 'Không tìm thấy rclone trên máy host ('.config('backup.rclone.binary').').';
        }

        return [
            'path' => $this->directory(),
            'keep_local' => config('backup.keep_local'),
            'schedule_time' => config('backup.schedule_time'),
            'rclone' => $rclone + ['keep_days' => config('backup.rclone.keep_days')],
        ];
    }

    private function pruneLocal(): void
    {
        $keep = max(1, (int) config('backup.keep_local'));
        foreach (array_slice($this->list(), $keep) as $old) {
            $this->delete($old['file']);
        }
    }

    private function pruneRemote(): void
    {
        $days = (int) config('backup.rclone.keep_days');
        if ($days > 0 && $this->rcloneRemote()) {
            $this->rclone(['delete', $this->rcloneRemote(), '--min-age', "{$days}d", '--include', 'sis-backup-*.zip']);
        }
    }

    private function rclone(array $args, ?int $timeout = null)
    {
        $command = [config('backup.rclone.binary'), ...$args];
        if ($config = config('backup.rclone.config')) {
            $command = [...$command, '--config', $config];
        }

        return Process::timeout($timeout ?? config('backup.rclone.timeout'))->run($command);
    }

    private function rcloneRemote(): ?string
    {
        return config('backup.rclone.remote') ?: null;
    }

    private function addDirectory(ZipArchive $zip, string $source, string $prefix): int
    {
        if (! File::isDirectory($source)) {
            return 0;
        }

        $count = 0;
        foreach (File::allFiles($source, true) as $file) {
            // Bỏ qua file .gitignore mặc định của Laravel
            if ($file->getFilename() === '.gitignore') {
                continue;
            }
            $zip->addFile($file->getPathname(), $prefix.'/'.str_replace('\\', '/', $file->getRelativePathname()));
            $count++;
        }

        return $count;
    }

    private function withLock(callable $callback)
    {
        $lock = Cache::lock('sis-backup', 3600);
        if (! $lock->get()) {
            throw new RuntimeException('Đang có một tác vụ sao lưu / khôi phục khác chạy, vui lòng thử lại sau.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function tempDir(): string
    {
        $dir = storage_path('framework/backup-'.Str::random(8));
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    private function readIndex(): array
    {
        $file = $this->directory().'/index.json';
        $data = File::exists($file) ? json_decode(File::get($file), true) : [];

        return is_array($data) ? $data : [];
    }

    private function writeIndex(array $index): void
    {
        File::put($this->directory().'/index.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function updateIndex(string $file, array $data): void
    {
        $index = $this->readIndex();
        $index[$file] = array_merge($index[$file] ?? [], $data);
        $this->writeIndex($index);
    }
}
