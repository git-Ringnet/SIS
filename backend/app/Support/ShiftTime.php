<?php

namespace App\Support;

/**
 * Xếp ca theo giờ bắt đầu, ví dụ "17:30 - 18:30": sáng < 12h, chiều < 17h30, còn lại là tối.
 */
class ShiftTime
{
    public static function of(?string $time): ?string
    {
        if (! $time || ! preg_match('/(\d{1,2}):(\d{2})/', $time, $m)) {
            return null;
        }

        $minutes = (int) $m[1] * 60 + (int) $m[2];

        return match (true) {
            $minutes < 12 * 60 => 'morning',
            $minutes < 17 * 60 + 30 => 'afternoon',
            default => 'evening',
        };
    }

    public static function label(?string $time): string
    {
        return match (self::of($time)) {
            'morning' => 'Ca sáng',
            'afternoon' => 'Ca chiều',
            default => 'Ca tối',
        };
    }
}
