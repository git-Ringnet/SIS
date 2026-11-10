<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Thông tin thương hiệu, chuyển khoản và xuất hoá đơn VAT do người dùng nhập ở màn Cài đặt.
 * Sidebar, màn đăng nhập, phiếu học phí PDF... đều lấy từ đây thay vì viết cứng.
 */
class BrandSettings
{
    private const PATH = 'settings.json';

    /**
     * Quy định nghỉ học hiện ở Cổng phụ huynh khi trung tâm chưa tự soạn (mỗi dòng một ý, **in đậm**,
     * {so_buoi_duoc_nghi} = số buổi được nghỉ có phép của học viên).
     */
    public const DEFAULT_PORTAL_LEAVE_RULES = 'Nghỉ có phép (báo trước) **không bị trừ buổi**, tối đa {so_buoi_duoc_nghi} buổi cho khóa học.
'
        .'Vắng không phép, hoặc nghỉ có phép khi đã hết số buổi được nghỉ: **trừ 1 buổi**.
'
        .'Ngày trung tâm nghỉ / giáo viên nghỉ: **không trừ buổi**, lịch học tự lùi sang buổi kế tiếp.';

    /**
     * Quy định nghỉ học cho Cổng phụ huynh: nội dung trung tâm soạn ở Cài đặt, để trống thì dùng mặc định.
     */
    public static function portalLeaveRules(): string
    {
        return trim((string) (self::brand()['portal_leave_rules'] ?? '')) ?: self::DEFAULT_PORTAL_LEAVE_RULES;
    }

    public static function all(): array
    {
        $stored = [];
        if (Storage::exists(self::PATH)) {
            $data = json_decode(Storage::get(self::PATH), true);
            $stored = is_array($data) ? $data : [];
        }

        $defaults = self::defaults();

        return [
            'brand' => array_merge($defaults['brand'], $stored['brand'] ?? []),
            'vat' => array_merge($defaults['vat'], $stored['vat'] ?? []),
        ];
    }

    public static function brand(): array
    {
        return self::all()['brand'];
    }

    public static function save(array $settings): void
    {
        Storage::put(self::PATH, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Phần được phép xem khi chưa đăng nhập (màn đăng nhập / đăng ký).
     */
    public static function publicBrand(): array
    {
        $brand = self::brand();
        $logo = (string) ($brand['logo_url'] ?? '');

        // Logo tải lên ở Cài đặt lưu dạng base64 (có thể hơn 1MB): trả đường dẫn ảnh để trình duyệt tải một lần rồi cache,
        // thay vì gửi cả chuỗi base64 mỗi lần mở app. ?v= đổi khi đổi logo.
        if (self::logoImage($logo)) {
            $logo = '/api/brand/logo?v='.substr(md5($logo), 0, 12);
        }

        return [
            'brand_name' => $brand['brand_name'],
            'logo_url' => $logo,
        ];
    }

    /**
     * Ảnh logo dạng data URI: [mime, nội dung nhị phân]; logo là đường dẫn thường hoặc trống thì null.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function logoImage(?string $logo = null): ?array
    {
        $logo ??= (string) (self::brand()['logo_url'] ?? '');
        if (! preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', $logo, $m)) {
            return null;
        }
        $binary = base64_decode($m[2], true);

        return $binary === false ? null : [$m[1], $binary];
    }

    /**
     * Giá trị khởi tạo khi chưa lưu cài đặt lần nào.
     */
    private static function defaults(): array
    {
        return [
            'brand' => [
                // Email chủ sở hữu = email tài khoản đầu tiên (chủ trung tâm), không cho sửa ở màn Cài đặt
                'owner_email' => User::orderBy('id')->value('email') ?? '',
                'brand_name' => 'Khoa Anh Music',
                'phone' => '0792324514',
                'address' => '123 Tô Ký, Hóc Môn, TP.HCM',
                'category' => 'Âm nhạc',
                'scale' => '100 – 300',
                'logo_url' => '',
                'bank_name' => 'MB Bank (Quân Đội)',
                'bank_account' => '15119928888',
                'bank_holder' => 'KHOA ANH MUSIC',
                // Trống = dùng DEFAULT_PORTAL_LEAVE_RULES
                'portal_leave_rules' => '',
            ],
            'vat' => [
                'company_name' => '',
                'tax_code' => '',
                'vat_email' => '',
                'vat_address' => '',
            ],
        ];
    }
}
