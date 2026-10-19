# Sao lưu dữ liệu

Mỗi bản sao lưu là một file `sis-backup-YYYYMMDD-HHMMSS.zip` chứa **toàn bộ dữ liệu hệ thống**:

| Trong file zip | Nội dung |
|---|---|
| `database.sql` | Toàn bộ database: học viên, điểm danh, hoá đơn, khách hàng, khoá học, tài khoản, nhật ký… |
| `storage/private/` | Dữ liệu lưu dạng file: trung tâm & phòng học, nhân sự, cài đặt thương hiệu / VAT, doanh thu học cụ, chi phí, kho học cụ, ca dạy, buổi học lẻ |
| `storage/public/` | File tải lên công khai (nếu có) |
| `manifest.json` | Thời điểm tạo, số bảng, số file |

Bản sao lưu được lưu trên máy host (mặc định `backend/storage/app/backups`, giữ 14 bản gần nhất) rồi đẩy lên Google Drive bằng **rclone**.

## 1. Cài rclone và kết nối Google Drive (một lần trên máy host)

1. Tải rclone tại <https://rclone.org/downloads/> và giải nén, ví dụ `C:\rclone\rclone.exe` (Windows) hoặc cài `rclone` qua trình quản lý gói (Linux).
2. Chạy `rclone config` → `n` (new remote) → đặt tên `gdrive` → chọn storage **Google Drive** → làm theo hướng dẫn đăng nhập Google.
   - Máy host không có trình duyệt: chạy `rclone authorize "drive"` trên máy có trình duyệt rồi dán token vào.
3. Kiểm tra: `rclone lsd gdrive:` phải liệt kê được thư mục trên Drive.

> rclone lưu cấu hình theo user chạy lệnh. Nếu PHP / web server chạy bằng user khác, trỏ tới file cấu hình bằng `BACKUP_RCLONE_CONFIG` (xem `rclone config file`).

## 2. Cấu hình `.env` của backend

```env
BACKUP_RCLONE_BINARY=C:\rclone\rclone.exe   # hoặc /usr/bin/rclone
BACKUP_RCLONE_REMOTE=gdrive:SIS-Backups     # remote:thư-mục trên Drive
BACKUP_RCLONE_KEEP_DAYS=30                  # xoá bản trên Drive cũ hơn 30 ngày (0 = không xoá)
BACKUP_KEEP_LOCAL=14                        # số bản giữ trên máy host
BACKUP_SCHEDULE_TIME=02:00                  # giờ sao lưu tự động mỗi ngày
# BACKUP_RCLONE_CONFIG=C:\rclone\rclone.conf
```

Sau đó vào **Cài đặt → Sao lưu dữ liệu**: ô Google Drive phải hiện `Đẩy lên gdrive:SIS-Backups` kèm phiên bản rclone. Bấm **Sao lưu ngay** để thử.

## 3. Bật sao lưu tự động hằng ngày

Lịch sao lưu chạy qua Laravel Scheduler, cần một tác vụ gọi scheduler mỗi phút:

- **Linux (cron):** `* * * * * cd /đường-dẫn/backend && php artisan schedule:run >> /dev/null 2>&1`
- **Windows (Task Scheduler):** tạo task chạy mỗi 1 phút: `C:\xampp\php\php.exe D:\SIS\backend\artisan schedule:run`

Kiểm tra lịch: `php artisan schedule:list`.

## 4. Lệnh dòng lệnh

```bash
php artisan sis:backup                                  # sao lưu ngay (+ đẩy lên Drive)
php artisan sis:backup:restore sis-backup-2026....zip   # khôi phục từ bản trong thư mục backup
php artisan sis:backup:restore D:\tai-ve\sis-backup-....zip   # khôi phục từ file tải về (ví dụ từ Drive)
```

## 5. Khôi phục khi máy host hỏng

1. Cài lại hệ thống, tạo database trống, cấu hình `.env`, chạy `php artisan migrate`.
2. Tải bản sao lưu mới nhất từ Google Drive (thư mục `SIS-Backups`).
3. Chạy `php artisan sis:backup:restore <đường-dẫn-file-zip>`.

Khôi phục **ghi đè toàn bộ dữ liệu hiện tại**. Trước khi khôi phục, hệ thống luôn tự tạo một bản `...-pre-restore.zip` để có thể quay lại.
Trên giao diện, chỉ chủ trung tâm được khôi phục và phải nhập lại mật khẩu.
