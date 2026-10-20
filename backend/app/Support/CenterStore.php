<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Danh sách trung tâm / cơ sở do người dùng quản lý ở màn Trung tâm (lưu file JSON).
 * Mọi nơi cần danh sách trung tâm đều lấy từ đây thay vì viết cứng.
 */
class CenterStore
{
    private const PATH = 'centers_data.json';

    public static function all(): array
    {
        $centers = [];
        if (Storage::exists(self::PATH)) {
            $data = json_decode(Storage::get(self::PATH), true);
            if (is_array($data)) {
                $centers = $data;
            }
        } else {
            $centers = self::seed();
        }

        return array_map(function ($c) {
            return array_merge([
                'invoice_prefix' => '',
                'logo_url' => '',
                'bank_info' => '',
                'qr_url' => '',
                'free_threshold' => null,
                'free_per_student' => null,
                'free_per_shift' => null,
                'free_require_qr' => true,
            ], $c);
        }, $centers);
    }

    public static function save(array $centers): void
    {
        Storage::put(self::PATH, json_encode(array_values($centers), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Tìm trung tâm theo tên. Nếu không tìm thấy, trả về trung tâm mặc định (hoặc trung tâm đầu tiên).
     */
    public static function findByName(?string $name): ?array
    {
        $centers = self::all();
        if ($name) {
            foreach ($centers as $center) {
                if ($center['name'] === $name) {
                    return $center;
                }
            }
        }

        foreach ($centers as $center) {
            if (! empty($center['is_main'])) {
                return $center;
            }
        }

        return $centers[0] ?? null;
    }

    /**
     * Tiền tố hoá đơn của trung tâm. Mặc định 'INV' nếu để trống.
     */
    public static function prefixForCenter(?string $centerName = null): string
    {
        $center = self::findByName($centerName);
        $prefix = trim($center['invoice_prefix'] ?? '');

        return $prefix !== '' ? $prefix : 'INV';
    }

    /**
     * Tên các trung tâm, dùng cho dropdown lọc.
     */
    public static function names(): array
    {
        return array_values(array_map(fn ($c) => $c['name'], self::all()));
    }

    /**
     * Trung tâm mặc định (trung tâm chính, nếu không có thì trung tâm đầu tiên).
     */
    public static function defaultName(): string
    {
        $centers = self::all();
        foreach ($centers as $center) {
            if (! empty($center['is_main'])) {
                return $center['name'];
            }
        }

        return $centers[0]['name'] ?? '';
    }

    /**
     * Tên các phòng học (của một trung tâm hoặc tất cả).
     */
    public static function roomNames(?string $centerName = null): array
    {
        $rooms = [];
        foreach (self::all() as $center) {
            if ($centerName && $centerName !== 'all' && $center['name'] !== $centerName) {
                continue;
            }
            foreach ($center['rooms'] ?? [] as $room) {
                $rooms[] = $room['name'];
            }
        }

        return array_values(array_unique($rooms));
    }

    /**
     * Dữ liệu khởi tạo khi chưa có file (lần chạy đầu tiên).
     */
    private static function seed(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Khoa Anh Music Hóc Môn',
                'address' => '123 Tô Ký, Xã Thới Tam Thôn, Hóc Môn, TP.HCM',
                'phone' => '0792324514',
                'invoice_prefix' => 'INV',
                'logo_url' => '',
                'bank_info' => '',
                'qr_url' => '',
                'is_main' => true,
                'rooms' => [],
            ],
        ];
    }
}
