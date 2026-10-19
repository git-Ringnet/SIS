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
        if (Storage::exists(self::PATH)) {
            $data = json_decode(Storage::get(self::PATH), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return self::seed();
    }

    public static function save(array $centers): void
    {
        Storage::put(self::PATH, json_encode(array_values($centers), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
                'is_main' => true,
                'rooms' => [
                    ['id' => 101, 'name' => 'GROUP', 'capacity' => 6, 'description' => 'Phòng học nhóm hòa tấu / nhạc lý'],
                    ['id' => 102, 'name' => 'PIANO 01', 'capacity' => 1, 'description' => 'Phòng Piano cơ 1-1 chuyên sâu'],
                    ['id' => 103, 'name' => 'PIANO 02', 'capacity' => 1, 'description' => 'Phòng Piano điện thực hành'],
                    ['id' => 104, 'name' => 'GUITAR 01', 'capacity' => 4, 'description' => 'Phòng Guitar đệm hát & Classic'],
                    ['id' => 105, 'name' => 'VOCAL 01', 'capacity' => 5, 'description' => 'Phòng Thanh nhạc cách âm'],
                    ['id' => 106, 'name' => 'DRUM 01', 'capacity' => 2, 'description' => 'Phòng Trống jazz tiêu âm'],
                ],
            ],
            [
                'id' => 2,
                'name' => 'Khoa Anh Music Quận 12',
                'address' => '45 Lê Văn Khương, Phường Thới An, Quận 12, TP.HCM',
                'phone' => '0912345678',
                'is_main' => false,
                'rooms' => [
                    ['id' => 201, 'name' => 'PIANO Q12', 'capacity' => 2, 'description' => 'Phòng Piano 1:2'],
                    ['id' => 202, 'name' => 'GUITAR Q12', 'capacity' => 4, 'description' => 'Phòng Guitar nhóm'],
                ],
            ],
            [
                'id' => 3,
                'name' => 'Khoa Anh Music Gò Vấp',
                'address' => '78 Quang Trung, Phường 10, Gò Vấp, TP.HCM',
                'phone' => '0987654321',
                'is_main' => false,
                'rooms' => [
                    ['id' => 301, 'name' => 'STUDIO GV', 'capacity' => 6, 'description' => 'Phòng đa năng'],
                ],
            ],
        ];
    }
}
