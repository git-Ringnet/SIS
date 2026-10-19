#!/bin/bash
# ==============================================================================
# SCRIPT CÀI ĐẶT TỰ ĐỘNG HỆ THỐNG EDUCRM SIS TRÊN LINUX (UBUNTU / DEBIAN)
# Hỗ trợ: PHP 8.4, Composer, Node.js 20, Nginx, PM2 & Cấu hình chống ngủ gập máy
# ==============================================================================

set -e

echo "=========================================================="
echo "🚀 BẮT ĐẦU CÀI ĐẶT HỆ THỐNG EDUCRM SIS TRÊN LINUX..."
echo "=========================================================="

# 1. Cập nhật hệ thống
echo "📦 [1/7] Cập nhật các gói hệ thống..."
sudo apt update && sudo apt upgrade -y
sudo apt install -y curl wget git unzip zip software-properties-common ca-certificates lsb-release

# 2. Cài đặt PHP 8.4 và các extension cần thiết
echo "🐘 [2/7] Cài đặt PHP 8.4 & Extensions..."
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.4 php8.4-fpm php8.4-cli php8.4-common php8.4-sqlite3 \
    php8.4-curl php8.4-mbstring php8.4-xml php8.4-zip php8.4-bcmath php8.4-intl php8.4-gd

# 3. Cài đặt Composer
echo "🎼 [3/7] Cài đặt Composer..."
if ! command -v composer &> /dev/null; then
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
fi

# 4. Cài đặt Node.js 20 LTS & PM2
echo "⚡ [4/7] Cài đặt Node.js 20 LTS & PM2..."
if ! command -v node &> /dev/null; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
    sudo apt install -y nodejs
fi
sudo npm install -g pm2

# 5. Cài đặt Nginx
echo "🌐 [5/7] Cài đặt Nginx Web Server..."
sudo apt install -y nginx

# 6. Cấu hình laptop Linux chạy 24/7 khi gập màn hình (Không bị sleep)
echo "💻 [6/7] Cấu hình laptop không bị ngủ khi gập màn hình..."
sudo sed -i 's/#HandleLidSwitch=suspend/HandleLidSwitch=ignore/' /etc/systemd/logind.conf
sudo sed -i 's/HandleLidSwitch=suspend/HandleLidSwitch=ignore/' /etc/systemd/logind.conf
sudo systemctl restart systemd-logind

# 7. Setup Backend & Frontend
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

echo "⚙️ [7/7] Cấu hình Source Code tại $ROOT_DIR..."
cd "$ROOT_DIR/backend"

FIRST_INSTALL=false
if [ ! -f .env ]; then
    FIRST_INSTALL=true
    cp .env.example .env
    # Chạy thật: tắt debug để không lộ thông tin lỗi / cấu hình ra Internet
    sed -i 's/^APP_ENV=.*/APP_ENV=production/' .env
    sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env
    sed -i 's/^LOG_LEVEL=.*/LOG_LEVEL=warning/' .env
    sed -i 's/^APP_LOCALE=.*/APP_LOCALE=vi/' .env

    # Tên miền truy cập qua Cloudflare Tunnel (dùng cho đường dẫn logo / file tải lên)
    read -r -p "Tên miền truy cập, ví dụ sis.khoaanhmusic.com [bỏ trống nếu chưa có]: " DOMAIN_INPUT
    if [ -n "$DOMAIN_INPUT" ]; then
        sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN_INPUT}|" .env
    fi

    # Nhấn Enter để giữ mặc định trong .env.example (admin@khoaanhmusic.com / password)
    read -r -p "Email chủ trung tâm [admin@khoaanhmusic.com]: " ADMIN_EMAIL_INPUT
    if [ -n "$ADMIN_EMAIL_INPUT" ]; then
        sed -i "s|^ADMIN_EMAIL=.*|ADMIN_EMAIL=${ADMIN_EMAIL_INPUT}|" .env
    fi
    # Mật khẩu tài khoản kỹ thuật system_admin (Enter để tự sinh ngẫu nhiên, in ra ở bước seed)
    read -r -s -p "Mật khẩu system_admin@sis.local [tự sinh]: " SYSTEM_PASSWORD_INPUT
    echo
    if [ -n "$SYSTEM_PASSWORD_INPUT" ]; then
        ESCAPED_PASSWORD=$(printf '%s' "$SYSTEM_PASSWORD_INPUT" | sed -e 's/[\/&|\\]/\\&/g')
        sed -i "s|^SYSTEM_ADMIN_PASSWORD=.*|SYSTEM_ADMIN_PASSWORD=${ESCAPED_PASSWORD}|" .env
    fi
fi

# Script chạy bằng sudo: cho phép composer chạy dưới root mà không hỏi lại
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader
touch database/database.sqlite

# Chỉ tạo APP_KEY khi cài lần đầu: tạo lại khi chạy lại script sẽ làm hỏng dữ liệu đã mã hoá
if [ "$FIRST_INSTALL" = true ]; then
    php artisan key:generate --force
fi

php artisan migrate --force
# Chỉ tạo tài khoản quản trị + mẫu tin nhắn (không có dữ liệu demo). Mật khẩu tự sinh được in ra bên dưới.
php artisan db:seed --force
php artisan storage:link || true
php artisan config:cache
php artisan route:cache

# Phân quyền thư mục lưu trữ
sudo chown -R www-data:www-data "$ROOT_DIR/backend/storage" "$ROOT_DIR/backend/bootstrap/cache" "$ROOT_DIR/backend/database"
sudo chmod -R 775 "$ROOT_DIR/backend/storage" "$ROOT_DIR/backend/bootstrap/cache" "$ROOT_DIR/backend/database"
sudo chmod 664 "$ROOT_DIR/backend/database/database.sqlite"

# Lịch chạy tự động (sao lưu hằng ngày, dọn nhật ký cũ) cần cron gọi schedule:run mỗi phút
CRON_LINE="* * * * * cd $ROOT_DIR/backend && php artisan schedule:run >> /dev/null 2>&1"
( sudo crontab -u www-data -l 2>/dev/null | grep -v 'artisan schedule:run' ; echo "$CRON_LINE" ) | sudo crontab -u www-data -

# Build Frontend
cd "$ROOT_DIR/frontend"
npm install
npm run build

# Cấu hình Nginx (giao diện + API chung cổng 80), trỏ đúng thư mục source hiện tại
sed "s|set \$base_dir \".*\";|set \$base_dir \"$ROOT_DIR\";|" "$ROOT_DIR/scripts/nginx_sis.conf" | sudo tee /etc/nginx/sites-available/sis > /dev/null
sudo ln -sf /etc/nginx/sites-available/sis /etc/nginx/sites-enabled/sis
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
# Bật tự khởi động cùng máy và chạy lại với cấu hình mới
sudo systemctl enable --now nginx php8.4-fpm cron
sudo systemctl restart php8.4-fpm nginx

echo "=========================================================="
echo "✅ CÀI ĐẶT THÀNH CÔNG! Mở http://localhost trên laptop để kiểm tra."
echo "🔑 Tài khoản đăng nhập được in ở bước seed phía trên. Khách nên đổi mật khẩu admin ở màn Cài đặt."
echo "🛠  Quên mật khẩu tài khoản nào: cd backend && php artisan sis:reset-password <email>"
echo "👉 Tiếp theo: Cloudflare Tunnel (Bước 3) và sao lưu Google Drive (Bước 4) trong CLOUDFLARE_TUNNEL_GUIDE.md"
echo "=========================================================="
