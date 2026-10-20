<?php

namespace App\Support;

/**
 * Mã QR check-in ca dạy đổi liên tục theo từng cơ sở.
 * Mã = HMAC(cơ sở + khung thời gian 30 giây) bằng APP_KEY, nên không cần lưu và không đoán trước được.
 * Giáo viên phải quét mã còn hạn đang hiện trên màn hình ở quầy mới check-in được.
 */
class CheckinToken
{
    // Mã đổi sau mỗi khoảng này
    public const WINDOW_SECONDS = 30;

    // Mã còn được chấp nhận thêm bao nhiêu khung sau khi đã đổi (để kịp mở trang / đăng nhập sau khi quét)
    private const GRACE_WINDOWS = 2;

    private static function window(?int $time = null): int
    {
        return intdiv($time ?? now()->getTimestamp(), self::WINDOW_SECONDS);
    }

    private static function sign(string $center, int $window): string
    {
        return substr(hash_hmac('sha256', "teacher-checkin|{$center}|{$window}", (string) config('app.key')), 0, 20);
    }

    /**
     * Mã hiện tại của cơ sở và số giây trước khi đổi mã.
     *
     * @return array{token: string, expires_in: int}
     */
    public static function current(string $center): array
    {
        $now = now()->getTimestamp();

        return [
            'token' => self::sign($center, self::window($now)),
            'expires_in' => self::WINDOW_SECONDS - ($now % self::WINDOW_SECONDS),
        ];
    }

    public static function isValid(?string $center, ?string $token): bool
    {
        if (! $center || ! $token) {
            return false;
        }

        $window = self::window();
        foreach (range(0, self::GRACE_WINDOWS) as $back) {
            if (hash_equals(self::sign($center, $window - $back), $token)) {
                return true;
            }
        }

        return false;
    }
}
