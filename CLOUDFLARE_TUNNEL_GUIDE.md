# Hướng dẫn cài đặt SIS trên laptop Linux + Cloudflare Tunnel

Tài liệu cho người cài đặt / bảo trì hệ thống. Làm lần lượt từ trên xuống, mỗi bước có phần kiểm tra.
(Cài trên máy Windows xem `WINDOWS_INSTALL_GUIDE.md`.)

## Mô hình

```text
Nhân viên (điện thoại / máy tính, ở bất kỳ đâu)
        │  https://sis.khoaanhmusic.com
        ▼
Cloudflare (SSL miễn phí, không cần mở port modem)
        │  đường hầm mã hoá (dịch vụ cloudflared)
        ▼
Laptop Linux ── Nginx :80 ── /      → giao diện Vue (frontend/dist)
                         └── /api   → Laravel (PHP 8.4-FPM) → SQLite (backend/database/database.sqlite)
```

Mọi thành phần chạy ngầm dưới dạng dịch vụ hệ thống, **tự chạy lại khi khởi động máy, không cần đăng nhập vào laptop**:

| Thành phần | Vai trò |
|---|---|
| `nginx` | Phục vụ giao diện và chuyển `/api` sang Laravel |
| `php8.4-fpm` | Chạy code Laravel |
| `cloudflared` | Đường hầm ra Internet |
| `cron` | Gọi `schedule:run` mỗi phút: sao lưu 02:00, dọn nhật ký 00:00 |

Database là **SQLite** (một file), không cần cài MySQL.

---

## Bước 0. Chuẩn bị

- Laptop cài **Ubuntu 22.04 / 24.04** (Server hoặc Desktop) hoặc Debian.
- **Cắm sạc và dây mạng LAN** (ổn định hơn Wi-Fi). Nếu dùng Wi-Fi trên Ubuntu Desktop: vào cài đặt Wi-Fi → bật *"All users may connect to this network"* để máy tự kết nối khi khởi động mà không cần đăng nhập.
- Một tên miền đã quản lý trên Cloudflare (ví dụ `khoaanhmusic.com`).
- Tài khoản GitHub có quyền đọc repo `git-Ringnet/SIS` và một **Personal Access Token** (repo private).
- Code mới nhất đã được commit và push lên GitHub.

## Bước 1. Tải source code

Đặt ở `/var/www/SIS` (đặt trong `/home/...` thường bị Nginx báo lỗi 403 vì không có quyền đọc thư mục home).

```bash
sudo apt update && sudo apt install -y git
sudo git clone https://github.com/git-Ringnet/SIS.git /var/www/SIS
```

Khi Git hỏi mật khẩu: nhập **Personal Access Token**, không phải mật khẩu GitHub.

## Bước 2. Chạy script cài đặt

```bash
cd /var/www/SIS
sudo bash scripts/setup_linux.sh
```

Mất khoảng 10–20 phút. Script hỏi 3 câu:

| Câu hỏi | Trả lời |
|---|---|
| Tên miền truy cập | `sis.khoaanhmusic.com` (bỏ trống nếu chưa có, sửa sau ở Bước 5) |
| Email chủ trung tâm | Enter để dùng `admin@khoaanhmusic.com` |
| Mật khẩu system_admin | Enter để dùng `password@123`, hoặc tự gõ mật khẩu khác |

Script tự động:

- Cài PHP 8.4 (kèm `gd`, `zip`, `sqlite3`...), Composer, Node.js 20, Nginx.
- Tạo `backend/.env` ở chế độ chạy thật (`APP_ENV=production`, `APP_DEBUG=false`).
- Tạo database, bảng và **2 tài khoản** (in ra màn hình, xem bên dưới).
- Build giao diện, cấu hình Nginx, bật dịch vụ tự khởi động.
- Thêm cron cho sao lưu và dọn nhật ký.
- Cấu hình laptop **không ngủ khi gập màn hình**.

### Tài khoản được tạo

Ở bước seed, script in ra dạng:

```text
Đã tạo tài khoản: admin@khoaanhmusic.com / password
Đã tạo tài khoản: system_admin@mail.com / password@123
```

| Tài khoản | Dùng cho | Ghi chú |
|---|---|---|
| `admin@khoaanhmusic.com` / `password` | Chủ trung tâm (khách) | **Khách đổi mật khẩu ngay** ở Cài đặt → Đổi mật khẩu |
| `system_admin@mail.com` / `password@123` | Bên triển khai hỗ trợ kỹ thuật | Toàn quyền, không hiện ở màn Nhân sự nên khách không xoá / đổi được |

Nhân viên do chủ trung tâm tạo ở màn **Nhân sự** (trang tự đăng ký đã bị khoá).

**Kiểm tra:** trên laptop mở `http://localhost` → đăng nhập bằng tài khoản admin.

## Bước 3. Cloudflare Tunnel (truy cập từ ngoài mạng)

1. Vào [Cloudflare Dashboard](https://dash.cloudflare.com/) → **Zero Trust** → **Networks** → **Tunnels** → **Create a tunnel**.
2. Chọn **Cloudflared** → đặt tên (ví dụ `SIS-Server`) → **Save tunnel**.
3. Ở mục cài connector, chọn **Debian / 64-bit**, Cloudflare hiện lệnh dạng:
   ```bash
   sudo cloudflared service install eyJhIjoi...TOKEN...
   ```
   Nếu laptop chưa có `cloudflared`, chạy lệnh cài đặt Cloudflare hiện phía trên lệnh này trước, rồi chạy lệnh `service install`. Lệnh này cài tunnel thành **dịch vụ tự chạy khi khởi động máy**.
4. **Next** → **Public Hostname**:
   - Subdomain: `sis` — Domain: `khoaanhmusic.com`
   - Type: `HTTP` — URL: `localhost:80`
5. **Save**.

**Kiểm tra:** tắt Wi-Fi điện thoại, dùng 4G mở `https://sis.khoaanhmusic.com`.

> Không dùng lệnh `cloudflared tunnel --url http://localhost:80` cho khách: đó là link tạm ngẫu nhiên, mất khi tắt terminal hoặc khởi động lại máy. Chỉ dùng để test nhanh.

## Bước 4. Đẩy bản sao lưu lên Google Drive (nên làm)

Mặc định bản sao lưu chỉ nằm trên laptop. Laptop hỏng ổ cứng / bị mất là mất cả dữ liệu lẫn bản sao lưu, nên cần đẩy thêm lên Google Drive.

**4.1. Cài rclone và tạo kết nối Google Drive**

```bash
sudo apt install -y rclone
sudo mkdir -p /etc/rclone
sudo rclone config --config /etc/rclone/rclone.conf
```

Trả lời lần lượt:

- `n` (New remote) → name: `gdrive`
- Storage: chọn số ứng với **Google Drive** (`drive`)
- `client_id`, `client_secret`: Enter (bỏ trống)
- scope: `1` (Full access)
- `service_account_file`: Enter
- Edit advanced config: `n`
- **Use web browser to automatically authenticate?** → `n` (laptop không có trình duyệt)

rclone in ra một lệnh dạng `rclone authorize "drive" "eyJ..."`. Chạy lệnh đó trên **một máy tính có trình duyệt** (cài rclone cho máy đó tại [rclone.org/downloads](https://rclone.org/downloads/)), đăng nhập Google của trung tâm, rồi copy đoạn mã kết quả dán ngược lại vào laptop.

- Configure as Shared Drive: `n` → `y` (Keep this remote) → `q` (Quit)

**4.2. Cho Laravel dùng file cấu hình này**

```bash
sudo chown www-data:www-data /etc/rclone/rclone.conf
sudo nano /var/www/SIS/backend/.env
```

Sửa / thêm:

```ini
BACKUP_RCLONE_REMOTE=gdrive:SIS-Backups
BACKUP_RCLONE_CONFIG=/etc/rclone/rclone.conf
```

Lưu lại rồi:

```bash
cd /var/www/SIS/backend
sudo php artisan config:cache
sudo -u www-data php artisan sis:backup     # chạy thử 1 bản sao lưu
```

**Kiểm tra:** trên Google Drive có thư mục `SIS-Backups` chứa file `sis-backup-....zip`.

## Bước 5. Sửa cấu hình sau khi cài (nếu cần)

File cấu hình: `/var/www/SIS/backend/.env`. **Mỗi lần sửa xong phải chạy:**

```bash
cd /var/www/SIS/backend && sudo php artisan config:cache
```

Các mục hay sửa:

| Mục | Ý nghĩa | Mặc định |
|---|---|---|
| `APP_URL` | Địa chỉ truy cập, ví dụ `https://sis.khoaanhmusic.com` | `http://localhost:8000` |
| `BACKUP_SCHEDULE_TIME` | Giờ sao lưu tự động mỗi ngày | `02:00` |
| `BACKUP_KEEP_LOCAL` | Số bản sao lưu giữ trên laptop | `14` |
| `BACKUP_RCLONE_KEEP_DAYS` | Số ngày giữ bản sao lưu trên Google Drive | `30` |

## Bước 6. Kiểm tra sau khi khởi động lại máy

```bash
sudo reboot
```

Máy lên lại (không cần đăng nhập), từ máy khác SSH vào hoặc đăng nhập trên laptop chạy:

```bash
systemctl is-active nginx php8.4-fpm cloudflared cron   # cả 4 dòng phải là "active"
cd /var/www/SIS/backend && php artisan schedule:list   # thấy sis:backup lúc 02:00 và model:prune
```

Rồi mở `https://sis.khoaanhmusic.com` bằng 4G trên điện thoại.

---

## Vận hành hằng ngày

### Sao lưu tự động

- **Mỗi ngày 1 lần lúc 02:00**: tạo file `sis-backup-YYYYMMDD-HHMMSS.zip` gồm toàn bộ database + file dữ liệu (học cụ, thu chi, nhân sự, trung tâm, cài đặt, logo, ảnh).
- Trên laptop (`backend/storage/app/backups`): giữ **14 bản gần nhất**. Bản tạo bằng tay cũng tính vào 14 bản này.
- Trên Google Drive (nếu đã làm Bước 4): giữ **30 ngày**.
- Laptop tắt đúng lúc 02:00 thì hôm đó **bỏ qua**, không chạy bù.
- Chủ trung tâm có thể tạo / tải về / khôi phục bản sao lưu ở **Cài đặt → Sao lưu dữ liệu**.

### Nhật ký hoạt động

Dọn tự động mỗi ngày lúc 00:00, xoá các dòng **cũ hơn 7 ngày**.

### Khôi phục dữ liệu từ bản sao lưu

Cách 1: **Cài đặt → Sao lưu dữ liệu** → chọn bản → Khôi phục.

Cách 2: trên server (ví dụ khôi phục từ file tải về từ Google Drive):

```bash
cd /var/www/SIS/backend
sudo -u www-data php artisan sis:backup:restore sis-backup-20261020-020000.zip
# hoặc đường dẫn file zip: sudo -u www-data php artisan sis:backup:restore /tmp/sis-backup-....zip
```

Khôi phục sẽ **ghi đè toàn bộ dữ liệu hiện tại**.

### Quên mật khẩu

- Nhân viên quên: chủ trung tâm vào **Nhân sự** → bấm 🔑 ở dòng nhân viên → đặt mật khẩu mới.
- Chủ trung tâm hoặc system_admin quên: trên server chạy

  ```bash
  cd /var/www/SIS/backend
  sudo -u www-data php artisan sis:reset-password admin@khoaanhmusic.com                   # tự sinh mật khẩu
  sudo -u www-data php artisan sis:reset-password admin@khoaanhmusic.com --password=MatKhauMoi
  ```

### Cập nhật phiên bản mới

```bash
cd /var/www/SIS
sudo git pull
sudo bash scripts/setup_linux.sh
```

Chạy lại script là an toàn: không tạo lại `.env`, không đổi khoá mã hoá, không ghi đè mật khẩu đã đổi, không xoá dữ liệu. Nên tạo 1 bản sao lưu trước khi cập nhật.

---

## Xử lý sự cố

| Hiện tượng | Kiểm tra / cách xử lý |
|---|---|
| Mở `localhost` báo **403 / 404** | Source có nằm ở `/var/www/SIS` không? Nếu đặt chỗ khác, chạy lại script từ đúng thư mục đó để cập nhật đường dẫn Nginx |
| Báo **502 Bad Gateway** | `sudo systemctl restart php8.4-fpm` rồi xem `sudo journalctl -u php8.4-fpm -n 50` |
| Trang mở được nhưng **lỗi khi thao tác** | Xem log Laravel: `tail -n 100 /var/www/SIS/backend/storage/logs/laravel.log` |
| Lỗi **không ghi được file / database** | `sudo chown -R www-data:www-data /var/www/SIS/backend/storage /var/www/SIS/backend/database /var/www/SIS/backend/bootstrap/cache` |
| Ngoài mạng **không vào được**, trong `localhost` vẫn được | `systemctl status cloudflared`; kiểm tra tunnel trên Cloudflare Zero Trust có trạng thái *Healthy* |
| **Không có bản sao lưu mới** | `sudo crontab -u www-data -l` phải có dòng `schedule:run`; chạy thử `sudo -u www-data php artisan sis:backup` |
| Sửa `.env` nhưng **không có tác dụng** | Chưa chạy `sudo php artisan config:cache` |
| Mất điện, pin cạn, **máy không tự bật lại** | Bật máy bằng tay. Nếu BIOS có tuỳ chọn *Power on AC / Restore on AC power loss* thì bật lên để máy tự khởi động khi có điện |
