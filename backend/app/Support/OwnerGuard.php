<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Xác định chủ trung tâm: tài khoản đầu tiên của hệ thống, hoặc thành viên được đánh dấu
 * is_owner ở màn Nhân sự. Dùng cho các chức năng nhạy cảm như sao lưu / khôi phục dữ liệu.
 */
class OwnerGuard
{
    public static function isOwner(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->id === User::orderBy('id')->value('id')) {
            return true;
        }

        // Tài khoản kỹ thuật của bên triển khai (config app.system_admin)
        $systemEmail = config('app.system_admin.email');
        if ($systemEmail && $user->email === $systemEmail) {
            return true;
        }

        if (Storage::exists('staff_data.json')) {
            $staff = json_decode(Storage::get('staff_data.json'), true);
            foreach ($staff['members'] ?? [] as $member) {
                if (($member['email'] ?? null) === $user->email && ! empty($member['is_owner'])) {
                    return true;
                }
            }
        }

        return false;
    }
}
