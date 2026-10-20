<?php

namespace App\Support;

use App\Models\Student;
use Illuminate\Support\Facades\Storage;

/**
 * Tên giáo viên được gõ trong lịch học của học viên và ở màn Nhân sự: so khớp không phân biệt
 * hoa thường / khoảng trắng thừa.
 */
class TeacherName
{
    public static function key(?string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $name)));
    }

    public static function same(?string $a, ?string $b): bool
    {
        return self::key($a) !== '' && self::key($a) === self::key($b);
    }

    /**
     * Danh sách giáo viên: tài khoản vai trò Giáo viên ở màn Nhân sự + tên giáo viên đang có trong lịch học.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $names = [];
        $staff = json_decode((string) Storage::get('staff_data.json'), true) ?: [];
        foreach ($staff['members'] ?? [] as $m) {
            if (str_contains($m['role'] ?? '', 'Giáo viên') && ! empty($m['name'])) {
                $names[self::key($m['name'])] = $m['name'];
            }
        }
        // Cột teacher của học viên có thể ghép nhiều giáo viên "Cô Mai, Thầy Bình" (lịch nhiều ca)
        foreach (Student::whereNotNull('teacher')->where('teacher', '!=', '')->distinct()->pluck('teacher') as $joined) {
            foreach (array_filter(array_map('trim', explode(',', $joined))) as $t) {
                $names[self::key($t)] ??= $t;
            }
        }

        $list = array_values($names);
        sort($list);

        return $list;
    }
}
