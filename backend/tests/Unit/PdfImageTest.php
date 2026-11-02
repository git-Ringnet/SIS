<?php

namespace Tests\Unit;

use App\Support\PdfImage;
use Tests\TestCase;

/**
 * Logo / QR tải lên ở màn Trung tâm ("/storage/centers/...") phải được nhúng vào PDF, không thì DomPDF hiện ô dấu X.
 */
class PdfImageTest extends TestCase
{
    public function test_uploaded_image_path_is_embedded_as_data_uri(): void
    {
        $dir = storage_path('app/public/centers');
        @mkdir($dir, 0777, true);
        $file = $dir.'/pdf-image-test.png';
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

        try {
            $expected = 'data:image/png;base64,'.base64_encode((string) file_get_contents($file));
            $this->assertSame($expected, PdfImage::src('/storage/centers/pdf-image-test.png'));
            // Đường dẫn đầy đủ theo tên miền của web cũng đọc từ ổ đĩa
            $this->assertSame($expected, PdfImage::src('https://sis.khoaanhmusic.com/storage/centers/pdf-image-test.png'));
        } finally {
            @unlink($file);
        }

        // File không còn → bỏ ảnh (không hiện ô dấu X); ảnh nhúng sẵn / trống giữ nguyên
        $this->assertNull(PdfImage::src('/storage/centers/khong-co.png'));
        $this->assertSame('data:image/png;base64,AAA', PdfImage::src('data:image/png;base64,AAA'));
        $this->assertNull(PdfImage::src(''));
    }
}
