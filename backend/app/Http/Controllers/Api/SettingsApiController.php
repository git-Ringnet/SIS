<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SettingsApiController extends Controller
{
    private function getSettingsPath(): string
    {
        return 'settings.json';
    }

    private function loadBrandSettings(): array
    {
        $path = $this->getSettingsPath();
        if (Storage::exists($path)) {
            $data = json_decode(Storage::get($path), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [
            'brand' => [
                'owner_email' => 'kfreestyle15@gmail.com',
                'brand_name' => 'Khoa Anh Music',
                'phone' => '0792324514',
                'address' => '123 Tô Ký, Hóc Môn, TP.HCM',
                'category' => 'Âm nhạc',
                'scale' => '100 – 300',
                'logo_url' => '',
            ],
            'vat' => [
                'company_name' => '',
                'tax_code' => '',
                'vat_email' => '',
                'vat_address' => '',
            ],
        ];
    }

    private function saveBrandSettings(array $settings): void
    {
        Storage::put($this->getSettingsPath(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user() ?? User::first();
        $stored = $this->loadBrandSettings();

        $activityLogs = [
            [
                'id' => 1,
                'admin_name' => $user ? $user->name : 'Khoa Anh Music Admin',
                'action' => 'Cập nhật trạng thái tái ký học viên MIN THÚI sang [Đã đóng]',
                'created_at' => Carbon::now()->subMinutes(12)->toIso8601String(),
                'ip' => '127.0.0.1',
            ],
            [
                'id' => 2,
                'admin_name' => 'Khoa Anh Music Admin',
                'action' => 'Gửi thông báo học phí qua Zalo cho phụ huynh bé Trần Bảo Ngọc',
                'created_at' => Carbon::now()->subHours(2)->toIso8601String(),
                'ip' => '127.0.0.1',
            ],
            [
                'id' => 3,
                'admin_name' => 'Khoa Anh Music Admin',
                'action' => 'Điểm danh 1-click cho học viên Nguyễn Minh Khang (+ 1 buổi)',
                'created_at' => Carbon::now()->subHours(5)->toIso8601String(),
                'ip' => '127.0.0.1',
            ],
            [
                'id' => 4,
                'admin_name' => 'Khoa Anh Music Admin',
                'action' => 'Tạo mới khóa học Piano Căn Bản & Cổ Điển',
                'created_at' => Carbon::now()->subDays(1)->toIso8601String(),
                'ip' => '127.0.0.1',
            ],
        ];

        return response()->json([
            'user' => [
                'id' => $user ? $user->id : 1,
                'name' => $user ? $user->name : 'Khoa Anh Music Admin',
                'email' => $user ? $user->email : 'kfreestyle15@gmail.com',
                'role' => 'Chủ trung tâm',
            ],
            'brand' => $stored['brand'],
            'vat' => $stored['vat'],
            'activity_logs' => $activityLogs,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = $request->user() ?? User::first();
        if ($user) {
            $user->name = $request->input('name');
            $user->save();
        }

        return response()->json([
            'message' => 'Cập nhật họ và tên thành công!',
            'user' => [
                'id' => $user ? $user->id : 1,
                'name' => $user ? $user->name : $request->input('name'),
                'email' => $user ? $user->email : 'kfreestyle15@gmail.com',
                'role' => 'Chủ trung tâm',
            ],
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:6',
            'password_confirmation' => 'required|string|same:password',
        ], [
            'password.required' => 'Vui lòng nhập mật khẩu mới',
            'password.min' => 'Mật khẩu phải có ít nhất 6 ký tự',
            'password_confirmation.same' => 'Nhập lại mật khẩu không khớp',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user() ?? User::first();
        if ($user) {
            $user->password = Hash::make($request->password);
            $user->save();
        }

        return response()->json([
            'message' => 'Đổi mật khẩu thành công!',
        ]);
    }

    public function updateBrand(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner_email' => 'nullable|email',
            'brand_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:100',
            'scale' => 'nullable|string|max:100',
            'logo_url' => 'nullable|string',
        ]);

        $stored = $this->loadBrandSettings();
        $stored['brand'] = array_merge($stored['brand'], $validated);
        $this->saveBrandSettings($stored);

        return response()->json([
            'message' => 'Lưu thông tin thương hiệu thành công!',
            'brand' => $stored['brand'],
        ]);
    }

    public function updateVat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => 'nullable|string|max:255',
            'tax_code' => 'nullable|string|max:100',
            'vat_email' => 'nullable|email|max:255',
            'vat_address' => 'nullable|string|max:255',
        ]);

        $stored = $this->loadBrandSettings();
        $stored['vat'] = array_merge($stored['vat'], $validated);
        $this->saveBrandSettings($stored);

        return response()->json([
            'message' => 'Lưu thông tin xuất hóa đơn VAT thành công!',
            'vat' => $stored['vat'],
        ]);
    }
}
