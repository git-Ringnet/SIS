<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupService;
use App\Support\ActivityLogger;
use App\Support\OwnerGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BackupApiController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        return response()->json([
            'status' => $this->backups->status(),
            'backups' => $this->backups->list(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);
        @set_time_limit(0);

        try {
            $entry = $this->backups->create('manual', $request->user());
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Sao lưu thất bại: '.$e->getMessage()], 500);
        }

        ActivityLogger::log('created', 'backup', $entry['file'], null, [], 'Drive: '.$entry['drive']['status']);

        return response()->json([
            'message' => match ($entry['drive']['status']) {
                'uploaded' => 'Đã sao lưu và đẩy lên Google Drive thành công!',
                'failed' => 'Đã sao lưu trên máy chủ nhưng đẩy lên Google Drive thất bại.',
                default => 'Đã sao lưu trên máy chủ (chưa cấu hình Google Drive).',
            },
            'backup' => $entry,
        ], 201);
    }

    public function upload(Request $request, string $file): JsonResponse
    {
        $this->authorizeOwner($request);
        @set_time_limit(0);

        try {
            $drive = $this->backups->upload($file);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'message' => $drive['status'] === 'uploaded' ? 'Đã đẩy bản backup lên Google Drive!' : ($drive['message'] ?? 'Không đẩy được lên Google Drive.'),
            'drive' => $drive,
        ], $drive['status'] === 'uploaded' ? 200 : 422);
    }

    public function download(Request $request, string $file): BinaryFileResponse|JsonResponse
    {
        $this->authorizeOwner($request);

        try {
            return response()->download($this->backups->path($file), $file);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function restore(Request $request, string $file): JsonResponse
    {
        $this->authorizeOwner($request);
        $request->validate(['password' => 'required|string'], ['password.required' => 'Vui lòng nhập mật khẩu để xác nhận khôi phục.']);

        if (! Hash::check($request->input('password'), $request->user()->password)) {
            return response()->json(['message' => 'Mật khẩu không đúng.', 'errors' => ['password' => ['Mật khẩu không đúng.']]], 422);
        }

        @set_time_limit(0);

        try {
            $result = $this->backups->restore($file, $request->user());
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Khôi phục thất bại: '.$e->getMessage()], 500);
        }

        // Ghi sau khi khôi phục để dòng nhật ký không bị ghi đè bởi dữ liệu cũ
        ActivityLogger::log('restored', 'backup', $file, null, [], 'Bản an toàn trước khi khôi phục: '.$result['safety_backup']);

        return response()->json([
            'message' => 'Đã khôi phục dữ liệu từ bản backup. Bạn có thể cần đăng nhập lại.',
            ...$result,
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);
        $request->validate(['file' => 'required|file|mimes:zip|max:1048576'], ['file.mimes' => 'Chỉ nhận file .zip của bản backup.']);

        try {
            $name = $this->backups->import($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalName());
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLogger::log('created', 'backup', $name, null, [], 'Tải lên từ máy tính');

        return response()->json(['message' => "Đã tải lên bản backup {$name}.", 'file' => $name], 201);
    }

    public function destroy(Request $request, string $file): JsonResponse
    {
        $this->authorizeOwner($request);

        try {
            $this->backups->delete($file);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        ActivityLogger::log('deleted', 'backup', $file);

        return response()->json(['message' => "Đã xoá bản backup {$file} trên máy chủ."]);
    }

    private function authorizeOwner(Request $request): void
    {
        abort_unless(OwnerGuard::isOwner($request->user()), 403, 'Chỉ chủ trung tâm được quản lý sao lưu dữ liệu.');
    }
}
