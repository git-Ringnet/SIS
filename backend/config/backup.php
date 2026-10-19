<?php

return [

    /*
    | Thư mục lưu các bản backup trên máy host (nằm ngoài storage/app/private
    | để bản backup không tự chứa các bản backup cũ).
    */
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    // Số bản backup giữ lại trên máy host, bản cũ hơn tự xoá
    'keep_local' => (int) env('BACKUP_KEEP_LOCAL', 14),

    // Giờ chạy backup tự động mỗi ngày (cần chạy scheduler: php artisan schedule:work hoặc cron / Task Scheduler)
    'schedule_time' => env('BACKUP_SCHEDULE_TIME', '02:00'),

    // Thư mục dữ liệu dạng file được đưa vào backup: tên trong file zip => đường dẫn trên máy host
    'storage_dirs' => [
        'private' => storage_path('app/private'),
        'public' => storage_path('app/public'),
    ],

    // Bảng chỉ sao lưu cấu trúc, bỏ dữ liệu tạm (cache, khoá chống chạy trùng, phiên đăng nhập web)
    'skip_data_tables' => ['cache', 'cache_locks', 'sessions'],

    'rclone' => [
        // Đường dẫn file chạy rclone, ví dụ C:\rclone\rclone.exe hoặc /usr/bin/rclone
        'binary' => env('BACKUP_RCLONE_BINARY', 'rclone'),

        // Remote + thư mục trên Google Drive, ví dụ "gdrive:SIS-Backups". Để trống = không đẩy lên Drive
        'remote' => env('BACKUP_RCLONE_REMOTE'),

        // File cấu hình rclone (nếu không dùng file mặc định của user chạy PHP)
        'config' => env('BACKUP_RCLONE_CONFIG'),

        // Xoá bản backup trên Drive cũ hơn số ngày này (0 = không xoá)
        'keep_days' => (int) env('BACKUP_RCLONE_KEEP_DAYS', 30),

        'timeout' => (int) env('BACKUP_RCLONE_TIMEOUT', 1800),
    ],

];
