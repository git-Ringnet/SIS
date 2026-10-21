<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use App\Support\BrandSettings;
use App\Support\OwnerGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class SettingsApiController extends Controller
{
    private function loadBrandSettings(): array
    {
        return BrandSettings::all();
    }

    private function saveBrandSettings(array $settings): void
    {
        BrandSettings::save($settings);
    }

    /**
     * Tên + logo thương hiệu cho màn đăng nhập (không cần đăng nhập).
     */
    public function publicBrand(): JsonResponse
    {
        return response()->json(BrandSettings::publicBrand());
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $stored = $this->loadBrandSettings();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => OwnerGuard::isOwner($user) ? 'Chủ trung tâm' : 'Nhân sự',
            ],
            'brand' => $stored['brand'],
            'vat' => $stored['vat'],
            'default_portal_leave_rules' => BrandSettings::DEFAULT_PORTAL_LEAVE_RULES,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = $request->user();
        if ($user) {
            $changes = ActivityLogger::diff(['name' => $user->name], ['name' => $request->input('name')]);
            $user->name = $request->input('name');
            $user->save();

            if ($changes) {
                ActivityLogger::log('updated', 'account', $user->email, $user->id, $changes);
            }
        }

        return response()->json([
            'message' => 'Cập nhật họ và tên thành công!',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
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

        $user = $request->user();
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
            'bank_name' => 'nullable|string|max:255',
            'bank_account' => 'nullable|string|max:50',
            'bank_holder' => 'nullable|string|max:255',
            // Quy định nghỉ học hiện ở Cổng phụ huynh (mỗi dòng một ý); trống = nội dung mặc định
            'portal_leave_rules' => 'nullable|string|max:3000',
        ]);

        $stored = $this->loadBrandSettings();
        // Email chủ sở hữu không được sửa từ màn Cài đặt
        unset($validated['owner_email']);
        $changes = ActivityLogger::diff(
            array_intersect_key($stored['brand'], $validated),
            $validated,
        );
        $stored['brand'] = array_merge($stored['brand'], $validated);
        $this->saveBrandSettings($stored);

        if ($changes) {
            ActivityLogger::log('updated', 'settings', 'Thương hiệu', null, $changes);
        }

        return response()->json([
            'message' => 'Lưu thông tin thương hiệu thành công!',
            'brand' => $stored['brand'],
            'default_portal_leave_rules' => BrandSettings::DEFAULT_PORTAL_LEAVE_RULES,
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
        $changes = ActivityLogger::diff(array_intersect_key($stored['vat'], $validated), $validated);
        $stored['vat'] = array_merge($stored['vat'], $validated);
        $this->saveBrandSettings($stored);

        if ($changes) {
            ActivityLogger::log('updated', 'settings', 'Thông tin xuất hoá đơn', null, $changes);
        }

        return response()->json([
            'message' => 'Lưu thông tin xuất hóa đơn VAT thành công!',
            'vat' => $stored['vat'],
        ]);
    }
}
