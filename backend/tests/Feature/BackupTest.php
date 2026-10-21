<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Services\Backup\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Mọi đường dẫn trỏ vào thư mục tạm, không đụng tới storage thật của máy dev
        $this->root = storage_path('framework/testing/backup-'.Str::random(6));
        config([
            'backup.path' => "{$this->root}/backups",
            'backup.storage_dirs' => ['private' => "{$this->root}/private", 'public' => "{$this->root}/public"],
            'backup.keep_local' => 14,
            'backup.rclone.remote' => null,
        ]);
        File::ensureDirectoryExists("{$this->root}/private");
        File::put("{$this->root}/private/centers_data.json", '{"version":"original"}');

        Process::fake();

        $this->owner = User::factory()->create(['password' => 'secret-pass']);
        Sanctum::actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function makeStudent(string $name): Student
    {
        $course = Course::firstOrCreate(['code' => 'PIANO'], ['name' => 'Piano', 'price' => 1000, 'default_sessions' => 12]);

        return Student::create([
            'code' => 'HV-'.Str::random(5), 'name' => $name, 'parent_name' => 'PH', 'parent_phone' => '09',
            'course_id' => $course->id, 'status' => 'active', 'total_sessions' => 12, 'remaining_sessions' => 12,
        ]);
    }

    public function test_backup_contains_database_and_data_files_and_restore_brings_everything_back(): void
    {
        $this->makeStudent('Nguyễn Văn Gốc');

        $file = $this->postJson('/api/settings/backups')->assertCreated()->json('backup.file');

        // Dữ liệu thay đổi sau khi sao lưu
        Student::query()->delete();
        $this->makeStudent('Học viên mới thêm sau');
        File::put("{$this->root}/private/centers_data.json", '{"version":"changed"}');
        File::put("{$this->root}/private/new_file.json", '{}');

        $this->postJson("/api/settings/backups/{$file}/restore", ['password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('restored', $file);

        $this->assertSame(['Nguyễn Văn Gốc'], Student::pluck('name')->all());
        $this->assertSame('{"version":"original"}', File::get("{$this->root}/private/centers_data.json"));
        $this->assertFileDoesNotExist("{$this->root}/private/new_file.json");

        // Có bản an toàn chứa dữ liệu ngay trước khi khôi phục
        $triggers = collect(app(BackupService::class)->list())->pluck('trigger');
        $this->assertContains('pre-restore', $triggers);
    }

    public function test_backup_is_pushed_to_google_drive_with_rclone(): void
    {
        config(['backup.rclone.remote' => 'gdrive:SIS-Backups', 'backup.rclone.binary' => 'rclone']);

        $res = $this->postJson('/api/settings/backups')->assertCreated();

        $this->assertSame('uploaded', $res->json('backup.drive.status'));
        Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'rclone'
            && $p->command[1] === 'copy'
            && str_ends_with($p->command[2], $res->json('backup.file'))
            && $p->command[3] === 'gdrive:SIS-Backups');
        // Dọn bản cũ trên Drive theo số ngày cấu hình
        Process::assertRan(fn (PendingProcess $p) => $p->command[1] === 'delete' && in_array('--min-age', $p->command, true));
    }

    public function test_failed_drive_upload_is_recorded_and_can_be_retried(): void
    {
        config(['backup.rclone.remote' => 'gdrive:SIS-Backups']);
        Process::fake(['*' => Process::result(errorOutput: 'Failed to copy: token expired', exitCode: 1)]);

        $res = $this->postJson('/api/settings/backups')->assertCreated();
        $this->assertSame('failed', $res->json('backup.drive.status'));
        $this->assertStringContainsString('token expired', $res->json('backup.drive.message'));

        Process::fake();
        $this->postJson('/api/settings/backups/'.$res->json('backup.file').'/upload')
            ->assertOk()
            ->assertJsonPath('drive.status', 'uploaded');
    }

    public function test_restore_rejects_backup_from_another_database_driver_before_touching_data(): void
    {
        $file = $this->postJson('/api/settings/backups')->json('backup.file');
        // Giả lập bản backup tạo từ MySQL (máy chạy test dùng SQLite)
        $zip = new \ZipArchive;
        $zip->open(config('backup.path').DIRECTORY_SEPARATOR.$file);
        $zip->addFromString('database.sql', "-- SIS backup | driver: mysql | 2026-10-21 15:47:22\nSET FOREIGN_KEY_CHECKS=0;\n");
        $zip->close();
        $before = count(File::files(config('backup.path')));

        $this->postJson("/api/settings/backups/{$file}/restore", ['password' => 'secret-pass'])
            ->assertStatus(500)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'không khôi phục được vào database SQLite'));
        // Không tạo bản an toàn, không đụng dữ liệu hiện tại
        $this->assertCount($before, File::files(config('backup.path')));
        $this->assertSame('{"version":"original"}', File::get("{$this->root}/private/centers_data.json"));
    }

    public function test_restore_requires_the_correct_password(): void
    {
        $file = $this->postJson('/api/settings/backups')->json('backup.file');

        $this->postJson("/api/settings/backups/{$file}/restore", ['password' => 'sai-mat-khau'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_only_the_owner_can_manage_backups(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/settings/backups')->assertForbidden();
        $this->postJson('/api/settings/backups')->assertForbidden();
    }

    public function test_download_rejects_paths_outside_backup_folder(): void
    {
        $this->getJson('/api/settings/backups/..%2F..%2F.env/download')->assertNotFound();
        $this->getJson('/api/settings/backups/sis-backup-20990101-000000.zip/download')->assertNotFound();
    }

    public function test_only_the_newest_local_backups_are_kept(): void
    {
        config(['backup.keep_local' => 2]);
        $service = app(BackupService::class);

        foreach (range(1, 3) as $i) {
            $this->travel($i)->minutes();
            $service->create();
        }

        $this->assertCount(2, $service->list());
    }

    public function test_importing_a_non_backup_zip_is_rejected(): void
    {
        $zipPath = "{$this->root}/random.zip";
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('hello.txt', 'hi');
        $zip->close();

        $this->postJson('/api/settings/backups/import', ['file' => new UploadedFile($zipPath, 'random.zip', 'application/zip', null, true)])
            ->assertUnprocessable();
    }
}
