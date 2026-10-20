# Hướng dẫn cài SIS trên máy Windows + Cloudflare Tunnel

Tài liệu cho người cài đặt / bảo trì hệ thống. Làm lần lượt từ trên xuống, mỗi bước có phần kiểm tra.
(Cài trên laptop Linux xem `CLOUDFLARE_TUNNEL_GUIDE.md`.)

## Mô hình

```text
Nhân viên (điện thoại / máy tính, ở bất kỳ đâu)
        │  https://sis.khoaanhmusic.com
        ▼
Cloudflare (SSL miễn phí, không cần mở port modem)
        │  đường hầm mã hoá (dịch vụ cloudflared)
        ▼
Máy Windows ── Caddy :80 ── /      → giao diện Vue (frontend\dist)
                        └── /api   → 4 tiến trình PHP 8.4 (Laravel) → SQLite (backend\database\database.sqlite)
```

| Thành phần | Vai trò | Chạy bằng |
|---|---|---|
| Caddy | Web server: phục vụ giao diện, chuyển `/api` sang PHP | Tác vụ **EduCRM SIS** |
| php-cgi × 4 | Chạy code Laravel, 4 tiến trình song song | Tác vụ **EduCRM SIS** |
| scheduler | `schedule:work`: sao lưu 02:00, dọn nhật ký 00:00 | Tác vụ **EduCRM SIS** |
| cloudflared | Đường hầm ra Internet | Dịch vụ Windows **Cloudflared** |

Tác vụ **EduCRM SIS** (Task Scheduler) và dịch vụ **Cloudflared** chạy dưới quyền SYSTEM: **tự chạy khi bật máy, không cần đăng nhập Windows**. Tiến trình nào bị tắt thì tự bật lại sau vài giây.

PHP, Node.js, Caddy, cloudflared, rclone được tải về **một thư mục riêng `C:\SIS-runtime`**, không cài vào hệ thống, không ảnh hưởng phần mềm khác trên máy. Database là **SQLite** (một file), không cần cài MySQL.

---

## Bước 0. Chuẩn bị

- Máy **Windows 10 / 11 64-bit**, tài khoản có quyền **Administrator**, có Internet.
- **Cắm sạc** (máy để chạy 24/7) và nên dùng **dây mạng LAN**. Wi-Fi vẫn được: Windows tự kết nối Wi-Fi đã lưu khi bật máy, kể cả chưa đăng nhập.
- Không có phần mềm khác đang dùng cổng 80 (IIS, XAMPP, WampServer, Skype bản cũ...). Có thì tắt, hoặc cài với cổng 8080 (xem Bước 2).
- Một tên miền đã quản lý trên Cloudflare (ví dụ `khoaanhmusic.com`).
- Tài khoản GitHub có quyền đọc repo `git-Ringnet/SIS` và một **Personal Access Token** (repo private).
- Code mới nhất đã được commit và push lên GitHub.

## Bước 1. Tải source code (ví dụ `C:\SIS` hoặc `D:\SIS`)

1. Cài **Git for Windows**: mở PowerShell gõ `winget install --id Git.Git -e`, hoặc tải ở [git-scm.com/download/win](https://git-scm.com/download/win) (cứ Next đến hết).
2. Mở **PowerShell mới** và chạy:

   ```powershell
   git clone https://github.com/git-Ringnet/SIS.git C:\SIS
   ```

   Khi Git hỏi mật khẩu: nhập **Personal Access Token**, không phải mật khẩu GitHub.

   Để ở ổ D thì thay bằng `D:\SIS`. **Trong tài liệu này, mọi chỗ ghi `C:\SIS` thì thay bằng thư mục source thực tế** (ví dụ `D:\SIS\scripts\setup_windows.bat`).

> Đường dẫn **không được có dấu cách** (không đặt trong `Desktop\New folder`...).
>
> PHP, Node.js, Caddy... được tải vào `C:\SIS-runtime` (khoảng 600MB). Ổ C ít dung lượng thì cài với `setup_windows.bat -RuntimeDir D:\SIS-runtime`, khi đó mọi chỗ ghi `C:\SIS-runtime` trong tài liệu thay bằng `D:\SIS-runtime`.

## Bước 2. Chạy cài đặt

Mở thư mục `scripts` trong source (ví dụ `D:\SIS\scripts`), **bấm đúp `setup_windows.bat`** → chọn **Yes** khi Windows hỏi quyền Administrator.

Mất khoảng 10–20 phút (tải khoảng 150MB). Script hỏi 4 câu:

| Câu hỏi | Trả lời |
|---|---|
| Tên miền truy cập | `sis.khoaanhmusic.com` (Enter nếu chưa có, sửa sau ở Bước 5) |
| Email chủ trung tâm | Enter để dùng `admin@khoaanhmusic.com` |
| Mật khẩu system_admin | Enter để dùng `password@123`, hoặc tự gõ mật khẩu khác (không có dấu `'`) |
| Lệnh / token Cloudflare Tunnel | Enter để làm sau ở Bước 3, hoặc dán lệnh lấy ở Bước 3 |

Script tự động:

- Cài Visual C++ Redistributable (PHP cần), tải PHP 8.4, Composer, Node.js LTS, Caddy, cloudflared, rclone vào `C:\SIS-runtime`.
- Tạo `backend\.env` ở chế độ chạy thật (`APP_ENV=production`, `APP_DEBUG=false`).
- Tạo database, bảng và **2 tài khoản** (in ra màn hình, xem bên dưới).
- Build giao diện, cấu hình web server.
- Đăng ký tác vụ **EduCRM SIS** chạy khi bật máy.
- Cắm sạc thì **không ngủ, gập màn hình không làm gì**.
- Mở cổng 80 trong mạng nội bộ (máy khác cùng mạng truy cập `http://<IP máy>`).

**Cổng 80 bị chiếm** (script báo *"Cổng 80 đang bị chiếm bởi..."*): tắt chương trình đó, hoặc cài với cổng 8080. Mở PowerShell (Admin):

```powershell
C:\SIS\scripts\setup_windows.bat -Port 8080
```

Khi đó ở Bước 3, URL của Cloudflare là `localhost:8080`.

**Máy không tải được PHP** (mạng chặn windows.php.net, script tự chuyển sang GitHub; vẫn lỗi): tải tay file `php-8.4.x-nts-Win32-vs17-x64.zip` từ máy khác, rồi chạy `setup_windows.bat -PhpZip D:\php-8.4.x-nts-Win32-vs17-x64.zip`.

### Tài khoản được tạo

| Tài khoản | Dùng cho | Ghi chú |
|---|---|---|
| `admin@khoaanhmusic.com` / `password` | Chủ trung tâm (khách) | **Khách đổi mật khẩu ngay** ở Cài đặt → Đổi mật khẩu |
| `system_admin@mail.com` / `password@123` | Bên triển khai hỗ trợ kỹ thuật | Toàn quyền, không hiện ở màn Nhân sự nên khách không xoá / đổi được |

Nhân viên do chủ trung tâm tạo ở màn **Nhân sự**.

**Kiểm tra:** trên máy mở `http://localhost` → đăng nhập bằng tài khoản admin.

## Bước 3. Cloudflare Tunnel (truy cập từ ngoài mạng)

1. Vào [Cloudflare Dashboard](https://dash.cloudflare.com/) → **Zero Trust** → **Networks** → **Tunnels** → **Create a tunnel**.
2. Chọn **Cloudflared** → đặt tên (ví dụ `SIS-Server`) → **Save tunnel**.
3. Ở mục cài connector chọn **Windows**, Cloudflare hiện lệnh dạng `cloudflared.exe service install eyJhIjoi...`. **Copy cả lệnh** (chỉ cần lệnh này, không cần tải file MSI vì script đã tải sẵn cloudflared).
4. Cài tunnel thành dịch vụ Windows, chọn 1 trong 2 cách:
   - Lúc chạy `setup_windows.bat`, dán lệnh vào câu hỏi cuối cùng; hoặc
   - Mở PowerShell (Admin) chạy: `C:\SIS-runtime\cloudflared\cloudflared.exe service install eyJhIjoi...`
5. Quay lại Cloudflare → **Next** → **Public Hostname**:
   - Subdomain: `sis`, Domain: `khoaanhmusic.com`
   - Type: `HTTP`, URL: `localhost:80` (hoặc `localhost:8080` nếu cài cổng 8080)
6. **Save**.

**Kiểm tra:** tắt Wi-Fi điện thoại, dùng 4G mở `https://sis.khoaanhmusic.com`.

## Bước 4. Đẩy bản sao lưu lên Google Drive (nên làm)

Mặc định bản sao lưu chỉ nằm trên máy. Máy hỏng ổ cứng / bị mất là mất cả dữ liệu lẫn bản sao lưu, nên cần đẩy thêm lên Google Drive.

Mở **PowerShell (Admin)**:

```powershell
C:\SIS-runtime\rclone\rclone.exe config --config C:\SIS-runtime\rclone\rclone.conf
```

Trả lời lần lượt:

- `n` (New remote), name: `gdrive`
- Storage: gõ `drive` (Google Drive)
- `client_id`, `client_secret`: Enter (bỏ trống)
- scope: `1` (Full access)
- `service_account_file`: Enter
- Edit advanced config: `n`
- Use web browser to automatically authenticate: `y` → trình duyệt mở ra, đăng nhập Google của trung tâm → **Allow**
- Configure as Shared Drive: `n`, rồi `y` (Keep this remote), rồi `q` (Quit)

Mở `C:\SIS\backend\.env` bằng Notepad, sửa dòng:

```ini
BACKUP_RCLONE_REMOTE=gdrive:SIS-Backups
```

(`BACKUP_RCLONE_BINARY` và `BACKUP_RCLONE_CONFIG` script đã điền sẵn.) Lưu lại rồi chạy trong PowerShell (Admin):

```powershell
cd C:\SIS\backend
C:\SIS-runtime\php\php.exe artisan config:cache
C:\SIS-runtime\php\php.exe artisan sis:backup     # chạy thử 1 bản sao lưu
powershell -ExecutionPolicy Bypass -File C:\SIS\scripts\windows\sis-control.ps1 restart
```

**Kiểm tra:** trên Google Drive có thư mục `SIS-Backups` chứa file `sis-backup-....zip`.

## Bước 5. Sửa cấu hình sau khi cài (nếu cần)

File cấu hình: `C:\SIS\backend\.env` (mở bằng Notepad). **Mỗi lần sửa xong phải chạy** trong PowerShell (Admin):

```powershell
cd C:\SIS\backend
C:\SIS-runtime\php\php.exe artisan config:cache
powershell -ExecutionPolicy Bypass -File C:\SIS\scripts\windows\sis-control.ps1 restart
```

| Mục | Ý nghĩa | Mặc định |
|---|---|---|
| `APP_URL` | Địa chỉ truy cập, ví dụ `https://sis.khoaanhmusic.com` | `http://localhost` |
| `BACKUP_SCHEDULE_TIME` | Giờ sao lưu tự động mỗi ngày | `02:00` |
| `BACKUP_KEEP_LOCAL` | Số bản sao lưu giữ trên máy | `14` |
| `BACKUP_RCLONE_KEEP_DAYS` | Số ngày giữ bản sao lưu trên Google Drive | `30` |

## Bước 6. Kiểm tra sau khi khởi động lại máy

Khởi động lại máy, **không đăng nhập**, đợi khoảng 1–2 phút rồi dùng điện thoại 4G mở `https://sis.khoaanhmusic.com`.

Sau đó đăng nhập vào máy, mở PowerShell (Admin):

```powershell
powershell -ExecutionPolicy Bypass -File C:\SIS\scripts\windows\sis-control.ps1 status
```

Kết quả đúng:

```text
Tác vụ chạy nền   : Running
Web server (Caddy): đang chạy
Tiến trình PHP    : 4
Lịch sao lưu      : đang chạy
Cloudflare Tunnel : Running
Kiểm tra API      : OK (200)
```

---

## Vận hành hằng ngày

Các lệnh dưới đây chạy trong **PowerShell (Admin)**: chuột phải nút Start → *Terminal (Admin)* hoặc *Windows PowerShell (Admin)*.

### Bật / tắt / khởi động lại / xem trạng thái

```powershell
powershell -ExecutionPolicy Bypass -File C:\SIS\scripts\windows\sis-control.ps1 status
powershell -ExecutionPolicy Bypass -File C:\SIS\scripts\windows\sis-control.ps1 restart
powershell -ExecutionPolicy Bypass -File C:\SIS\scripts\windows\sis-control.ps1 stop
powershell -ExecutionPolicy Bypass -File C:\SIS\scripts\windows\sis-control.ps1 start
```

### Sao lưu tự động

- **Mỗi ngày 1 lần lúc 02:00**: tạo file `sis-backup-YYYYMMDD-HHMMSS.zip` gồm toàn bộ database + file dữ liệu (học cụ, thu chi, nhân sự, trung tâm, cài đặt, logo, ảnh).
- Trên máy (`C:\SIS\backend\storage\app\backups`): giữ **14 bản gần nhất**.
- Trên Google Drive (nếu đã làm Bước 4): giữ **30 ngày**.
- Máy tắt đúng lúc 02:00 thì hôm đó **bỏ qua**, không chạy bù.
- Chủ trung tâm có thể tạo / tải về / khôi phục bản sao lưu ở **Cài đặt → Sao lưu dữ liệu**.

### Nhật ký hoạt động

Dọn tự động mỗi ngày lúc 00:00, xoá các dòng **cũ hơn 7 ngày**.

### Khôi phục dữ liệu từ bản sao lưu

Cách 1: **Cài đặt → Sao lưu dữ liệu** → chọn bản → Khôi phục.

Cách 2: trên máy (ví dụ file tải về từ Google Drive):

```powershell
cd C:\SIS\backend
C:\SIS-runtime\php\php.exe artisan sis:backup:restore C:\Users\<tên>\Downloads\sis-backup-20261020-020000.zip
```

Khôi phục sẽ **ghi đè toàn bộ dữ liệu hiện tại**.

### Quên mật khẩu

- Nhân viên quên: chủ trung tâm vào **Nhân sự** → bấm 🔑 ở dòng nhân viên → đặt mật khẩu mới.
- Chủ trung tâm hoặc system_admin quên:

  ```powershell
  cd C:\SIS\backend
  C:\SIS-runtime\php\php.exe artisan sis:reset-password admin@khoaanhmusic.com                   # tự sinh mật khẩu
  C:\SIS-runtime\php\php.exe artisan sis:reset-password admin@khoaanhmusic.com --password=MatKhauMoi
  ```

### Cập nhật phiên bản mới

Nên tạo 1 bản sao lưu trước (Cài đặt → Sao lưu dữ liệu), rồi:

```powershell
cd C:\SIS
git pull
```

Sau đó bấm đúp lại `C:\SIS\scripts\setup_windows.bat`. Script tự dừng hệ thống, cập nhật, rồi bật lại. Chạy lại là an toàn: không tạo lại `.env`, không đổi khoá mã hoá, không ghi đè mật khẩu đã đổi, không xoá dữ liệu, không tải lại PHP / Node.

### Windows Update

Windows tự khởi động lại sau khi cập nhật thì hệ thống cũng tự chạy lại, không cần đăng nhập. Để tránh khởi động lại giữa giờ làm việc: **Settings → Windows Update → Advanced options → Active hours**, đặt khung giờ trung tâm hoạt động (ví dụ 7:00–22:00).

---

## Xử lý sự cố

| Hiện tượng | Kiểm tra / cách xử lý |
|---|---|
| Script báo **cổng 80 đang bị chiếm** | Tắt IIS (*Turn Windows features on or off* → bỏ *Internet Information Services*), XAMPP, WampServer... hoặc cài lại với `-Port 8080` |
| Script báo **đường dẫn có dấu cách** | Chuyển source về thư mục không dấu cách, ví dụ `D:\SIS` |
| Bấm đúp `.bat` bị **SmartScreen** chặn | Bấm *More info* → *Run anyway* |
| Mở `localhost` **không vào được** | Chạy `sis-control.ps1 status`. Có dòng "KHÔNG chạy" thì `sis-control.ps1 restart`, vẫn lỗi thì xem log `C:\SIS-runtime\logs\` |
| Trang mở được nhưng **lỗi khi thao tác** | Xem log Laravel: `C:\SIS\backend\storage\logs\laravel.log` (mở bằng Notepad, xem cuối file) |
| Ngoài mạng **không vào được**, `localhost` vẫn được | `Get-Service cloudflared` phải *Running*; tunnel trên Cloudflare Zero Trust phải *Healthy*; URL trong Public Hostname đúng cổng |
| **Không có bản sao lưu mới** | `sis-control.ps1 status` dòng *Lịch sao lưu* phải "đang chạy"; chạy thử `php.exe artisan sis:backup` |
| Sửa `.env` nhưng **không có tác dụng** | Chưa chạy `artisan config:cache` và `sis-control.ps1 restart` |
| Máy **tự ngủ** | Đang chạy pin? Cắm sạc. Kiểm tra lại: *Settings → System → Power* đặt *Sleep: Never* khi cắm sạc |
| Mất điện, **máy không tự bật lại** | Bật máy bằng tay. Nếu BIOS có tuỳ chọn *Restore on AC power loss / Power on AC* thì bật lên để máy tự khởi động khi có điện |
