<?php

namespace App\Support;

/**
 * Ảnh trong file PDF (logo, mã QR). DomPDF không tự mở được đường dẫn tương đối kiểu "/storage/centers/logo.png"
 * (ảnh tải lên ở màn Trung tâm) nên hiện ô dấu X: đọc file từ ổ đĩa và nhúng thẳng vào PDF (data URI).
 */
class PdfImage
{
    public static function src(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || str_starts_with($url, 'data:')) {
            return $url ?: null;
        }

        // Đường dẫn ảnh đã tải lên (tương đối hoặc đầy đủ theo tên miền của web) → file trong public/storage
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if (str_starts_with($path, '/storage/')) {
            $file = storage_path('app/public/'.substr(rawurldecode($path), strlen('/storage/')));
            $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', default => null,
            };
            if ($mime && is_file($file)) {
                return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($file));
            }

            return null; // file không còn: bỏ ảnh thay vì hiện ô dấu X
        }

        return $url;
    }
}
