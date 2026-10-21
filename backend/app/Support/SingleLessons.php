<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Buổi học lẻ / học bù đặt riêng theo ngày (lưu scheduled_single_lessons.json):
 *   [{id, student_id, date: Y-m-d, time, teacher, room, is_makeup, note, ...}, ...]
 */
class SingleLessons
{
    private const PATH = 'scheduled_single_lessons.json';

    public static function all(): array
    {
        $data = Storage::exists(self::PATH) ? json_decode(Storage::get(self::PATH), true) : [];

        return is_array($data) ? $data : [];
    }

    public static function save(array $lessons): void
    {
        Storage::put(self::PATH, json_encode($lessons, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public static function add(array $lesson): array
    {
        $lessons = self::all();
        $lessons[] = $lesson;
        self::save($lessons);

        return $lesson;
    }

    public static function forStudent(int $studentId): array
    {
        return array_values(array_filter(self::all(), fn ($l) => (int) ($l['student_id'] ?? 0) === $studentId));
    }

    public static function find(?string $id): ?array
    {
        return $id === null ? null : collect(self::all())->firstWhere('id', $id);
    }

    /**
     * Giờ bắt đầu "HH:MM" trong chuỗi giờ của buổi ("18:00 - 19:00"), null nếu không có.
     */
    public static function startTime(array $lesson): ?string
    {
        return isset($lesson['time']) && preg_match('/\d{1,2}:\d{2}/', $lesson['time'], $m) ? $m[0] : null;
    }
}
