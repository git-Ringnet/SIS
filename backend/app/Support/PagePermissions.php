<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Phân quyền theo "trang bị chặn" cấu hình ở màn Nhân sự.
 * Chủ trung tâm (OwnerGuard) luôn có toàn quyền.
 */
class PagePermissions
{
    /**
     * Tên trang (khớp danh sách ở màn Nhân sự) => các API bị chặn.
     * 'all' chặn mọi method, 'write' chỉ chặn thêm / sửa / xoá
     * (dùng cho API mà trang khác vẫn cần đọc, ví dụ danh sách khoá học trong form học viên).
     */
    public const PAGE_APIS = [
        'Doanh thu' => ['all' => ['revenue']],
        'Hóa đơn & Thu phí' => ['all' => ['invoices']],
        'Khách hàng tiềm năng' => ['all' => ['leads']],
        'Cài đặt' => ['all' => ['settings/brand', 'settings/vat', 'settings/activity-logs', 'settings/backups']],
        'Dữ liệu' => ['all' => ['export']],
        'Báo cáo' => ['all' => ['reports']],
        'Học cụ' => ['all' => ['inventory/export', 'inventory/template', 'inventory/import'], 'write' => ['inventory']],
        'Khóa học' => ['write' => ['courses']],
        'Nhân sự' => ['write' => ['staff']],
        'Trung tâm' => ['write' => ['centers']],
    ];

    /**
     * Danh sách trang bị chặn của người dùng (rỗng nếu là chủ trung tâm hoặc không có trong danh sách nhân sự).
     *
     * @return list<string>
     */
    public static function blockedPages(?User $user): array
    {
        if (! $user || OwnerGuard::isOwner($user) || ! Storage::exists('staff_data.json')) {
            return [];
        }

        $staff = json_decode(Storage::get('staff_data.json'), true);
        foreach ($staff['members'] ?? [] as $member) {
            if (($member['email'] ?? null) === $user->email) {
                return array_values($member['blocked_pages'] ?? []);
            }
        }

        return [];
    }

    public static function isBlocked(?User $user, string $page): bool
    {
        return in_array($page, self::blockedPages($user), true);
    }

    /**
     * Trang bị chặn mà request này đụng tới (null nếu được phép).
     */
    public static function blockedPageForRequest(Request $request): ?string
    {
        $blocked = self::blockedPages($request->user());
        if ($blocked === []) {
            return null;
        }

        $path = preg_replace('#^api/#', '', trim($request->path(), '/'));
        $isWrite = ! $request->isMethodSafe();

        foreach ($blocked as $page) {
            $rules = self::PAGE_APIS[$page] ?? [];
            $prefixes = [...($rules['all'] ?? []), ...($isWrite ? ($rules['write'] ?? []) : [])];
            foreach ($prefixes as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                    return $page;
                }
            }
        }

        return null;
    }
}
