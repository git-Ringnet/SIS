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
        'Bảng điều khiển' => ['all' => ['dashboard']],
        'Học viên' => ['all' => ['students/template', 'students/import'], 'write' => ['students']],
        'Điểm danh' => ['all' => ['attendance']],
        'Check-in ca dạy' => ['all' => ['teacher-checkin']],
        'Vắng liên tiếp' => ['all' => ['absences']],
        'Khách hàng tiềm năng' => ['all' => ['leads']],
        'Doanh thu' => ['all' => ['revenue']],
        'Hóa đơn & Thu phí' => ['all' => ['invoices']],
        'Học cụ' => ['all' => ['inventory/export', 'inventory/template', 'inventory/import'], 'write' => ['inventory']],
        'Sinh nhật' => ['all' => ['birthdays']],
        'HV Tạm dừng - Nghỉ' => ['all' => ['inactive-students']],
        'Khóa học' => ['write' => ['courses']],
        'Trung tâm' => ['write' => ['centers']],
        'Nhân sự' => ['write' => ['staff']],
        'Cài đặt' => ['all' => ['settings/brand', 'settings/vat', 'settings/activity-logs', 'settings/backups']],
        'Dữ liệu' => ['all' => ['export']],
        'Báo cáo' => ['all' => ['reports']],
    ];

    /**
     * Trang mặc định bị chặn theo vai trò khi tạo tài khoản / đổi vai trò.
     * Giáo viên chỉ dùng Điểm danh và Check-in ca dạy; chủ trung tâm mở thêm ở màn Nhân sự nếu cần.
     */
    public static function defaultBlockedPages(string $role): array
    {
        $all = array_keys(self::PAGE_APIS);

        return match ($role) {
            'Chủ trung tâm' => [],
            'Quản lý / Lễ tân' => ['Cài đặt', 'Dữ liệu'],
            'Giáo viên' => array_values(array_diff($all, ['Điểm danh', 'Check-in ca dạy'])),
            default => ['Doanh thu', 'Hóa đơn & Thu phí', 'Khách hàng tiềm năng', 'Cài đặt', 'Dữ liệu'],
        };
    }

    /**
     * Thành viên ở màn Nhân sự ứng với tài khoản (null nếu không có trong danh sách).
     */
    public static function member(?User $user): ?array
    {
        if (! $user || ! Storage::exists('staff_data.json')) {
            return null;
        }

        $staff = json_decode(Storage::get('staff_data.json'), true);
        foreach ($staff['members'] ?? [] as $member) {
            if (($member['email'] ?? null) === $user->email) {
                return $member;
            }
        }

        return null;
    }

    /**
     * Tài khoản là giáo viên (không phải chủ trung tâm): chỉ được xem / check-in ca dạy của chính mình.
     */
    public static function isTeacher(?User $user): bool
    {
        return ! OwnerGuard::isOwner($user) && str_contains(self::member($user)['role'] ?? '', 'Giáo viên');
    }

    /**
     * Được điểm danh học viên (đi học / vắng / hoàn tác / đặt buổi học lẻ): chủ trung tâm, quản lý, giáo vụ.
     * Giáo viên chỉ xem và ghi báo bài cho buổi đã được điểm danh.
     */
    public static function canMarkAttendance(?User $user): bool
    {
        return ! self::isTeacher($user);
    }

    /**
     * Được xem thù lao / đơn giá của mọi giáo viên: chủ trung tâm và quản lý.
     * Người khác (giáo viên, tư vấn viên...) chỉ xem thù lao của chính mình.
     */
    public static function seesAllPay(?User $user): bool
    {
        return OwnerGuard::isOwner($user) || str_contains(self::member($user)['role'] ?? '', 'Quản lý');
    }

    /**
     * Danh sách trang bị chặn của người dùng (rỗng nếu là chủ trung tâm hoặc không có trong danh sách nhân sự).
     *
     * @return list<string>
     */
    public static function blockedPages(?User $user): array
    {
        if (! $user || OwnerGuard::isOwner($user)) {
            return [];
        }

        return array_values(self::member($user)['blocked_pages'] ?? []);
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
