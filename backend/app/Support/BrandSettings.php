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

        return [
            'brand_name' => $brand['brand_name'],
            'logo_url' => $brand['logo_url'],
        ];
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
