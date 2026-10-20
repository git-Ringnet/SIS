#Requires -Version 5.1
# ==============================================================================
# CÀI ĐẶT EDUCRM SIS TRÊN WINDOWS 10 / 11
# - Tải PHP 8.4, Composer, Node.js LTS, Caddy (web server), cloudflared, rclone vào thư mục riêng (mặc định C:\SIS-runtime)
# - Cấu hình backend (SQLite) + build giao diện
# - Đăng ký tác vụ chạy nền "EduCRM SIS" dưới quyền SYSTEM: tự chạy khi bật máy, không cần đăng nhập
# Chạy lại script để cập nhật phiên bản mới: không tạo lại .env, không đổi khoá mã hoá, không xoá dữ liệu.
# ==============================================================================
param(
    # Thư mục chứa PHP / Node / Caddy... (không có dấu cách)
    [string]$RuntimeDir = 'C:\SIS-runtime',
    # Cổng web trên máy. Cổng 80 bị chiếm (IIS, XAMPP...) thì dùng 8080 và trỏ Cloudflare Tunnel về localhost:8080
    [int]$Port = 80,
    # Số tiến trình PHP chạy song song
    [int]$PhpWorkers = 4,
    # File zip PHP tải sẵn (khi máy không tải được từ Internet)
    [string]$PhpZip = '',
    # Chỉ cài code + công cụ, không đăng ký chạy nền / không đổi nguồn điện / tường lửa (dùng để thử)
    [switch]$NoService,
    # Không hỏi gì: dùng các giá trị truyền vào bên dưới hoặc mặc định
    [switch]$UseDefaults,
    [string]$Domain = '',
    [string]$AdminEmail = '',
    [string]$SystemAdminPassword = '',
    [string]$TunnelToken = ''
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # Invoke-WebRequest tải nhanh hơn nhiều khi tắt thanh tiến trình
[Console]::OutputEncoding = [Text.Encoding]::UTF8
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Root = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $Root 'backend'
$Frontend = Join-Path $Root 'frontend'
$TaskName = 'EduCRM SIS'
$Downloads = Join-Path $RuntimeDir 'downloads'
$PhpDir = Join-Path $RuntimeDir 'php'
$Php = Join-Path $PhpDir 'php.exe'
$NodeDir = Join-Path $RuntimeDir 'node'
$Composer = Join-Path $RuntimeDir 'composer.phar'
$Caddy = Join-Path $RuntimeDir 'caddy\caddy.exe'
$Cloudflared = Join-Path $RuntimeDir 'cloudflared\cloudflared.exe'
$Rclone = Join-Path $RuntimeDir 'rclone\rclone.exe'

function Step([string]$Text) { Write-Host "`n==> $Text" -ForegroundColor Cyan }
function Info([string]$Text) { Write-Host "    $Text" }
function Warn([string]$Text) { Write-Host "    ! $Text" -ForegroundColor Yellow }
function Fail([string]$Text) {
    Write-Host "`nLỖI: $Text" -ForegroundColor Red
    exit 1
}

function Download([string]$Url, [string]$OutFile) {
    Info "Tải $Url"
    # Mạng chập chờn: thử lại tối đa 3 lần, tải vào file .part rồi mới đổi tên để không giữ file tải dở
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        try {
            Invoke-WebRequest -Uri $Url -OutFile "$OutFile.part" -UseBasicParsing
            Move-Item "$OutFile.part" $OutFile -Force
            return
        } catch {
            Remove-Item "$OutFile.part" -Force -ErrorAction SilentlyContinue
            if ($attempt -eq 3) { Fail "Không tải được $Url ($($_.Exception.Message)). Kiểm tra mạng rồi chạy lại." }
            Warn "Tải lỗi ($($_.Exception.Message)), thử lại lần $($attempt + 1)..."
            Start-Sleep -Seconds 5
        }
    }
}

# Ghi file UTF-8 không BOM (.env có BOM thì Laravel đọc sai dòng đầu)
function Write-Utf8NoBom([string]$Path, [string]$Text) {
    [IO.File]::WriteAllText($Path, $Text, (New-Object Text.UTF8Encoding $false))
}

# Chạy lệnh ngoài, dừng cài đặt nếu lệnh lỗi
function Run([string]$Exe, [string[]]$Arguments) {
    # Composer / npm in tiến trình ra stderr: không coi đó là lỗi, chỉ xét mã thoát
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { & $Exe @Arguments } finally { $ErrorActionPreference = $previous }
    if ($LASTEXITCODE -ne 0) { Fail "Lệnh thất bại ($LASTEXITCODE): $Exe $($Arguments -join ' ')" }
}

function Artisan([string[]]$Arguments) {
    Push-Location $Backend
    try { Run $Php (@('artisan') + $Arguments) } finally { Pop-Location }
}

# Giải nén zip, nếu bên trong chỉ có 1 thư mục thì lấy nội dung thư mục đó
# Giải nén vào thư mục tạm tên ngắn (Node.js có đường dẫn rất sâu, dễ vượt giới hạn 260 ký tự của Windows)
function Expand-Flat([string]$Zip, [string]$Dest) {
    $tmp = Join-Path $RuntimeDir ('_x' + (Get-Random -Maximum 9999))
    New-Item -ItemType Directory -Force -Path $tmp | Out-Null
    $tar = Join-Path $env:SystemRoot 'System32\tar.exe'
    if (Test-Path $tar) {
        & $tar -xf $Zip -C $tmp
        if ($LASTEXITCODE -ne 0) { Fail "Giải nén lỗi: $Zip" }
    } else {
        Expand-Archive -Path $Zip -DestinationPath $tmp -Force
    }
    $items = @(Get-ChildItem $tmp)
    $src = $tmp
    if ($items.Count -eq 1 -and $items[0].PSIsContainer) { $src = $items[0].FullName }
    if (Test-Path $Dest) {
        Copy-Item -Path (Join-Path $src '*') -Destination $Dest -Recurse -Force
    } else {
        New-Item -ItemType Directory -Force -Path (Split-Path $Dest) | Out-Null
        Move-Item -Path $src -Destination $Dest
    }
    if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
}

function Set-EnvValue([string]$Content, [string]$Key, [string]$Value) {
    $line = "$Key=$Value"
    $pattern = "(?m)^#?\s*$([regex]::Escape($Key))=.*$"
    $regex = [regex]$pattern
    if ($regex.IsMatch($Content)) {
        # Thay bằng MatchEvaluator để giá trị có ký tự $ hay \ không bị hiểu là cú pháp regex
        return $regex.Replace($Content, [Text.RegularExpressions.MatchEvaluator] { param($m) $line }.GetNewClosure(), 1)
    }
    return $Content.TrimEnd() + "`n$line`n"
}

function Get-EnvValue([string]$Content, [string]$Key) {
    $m = [regex]::Match($Content, "(?m)^$([regex]::Escape($Key))=(.*)$")
    if ($m.Success) { return $m.Groups[1].Value.Trim().Trim('"', "'") }
    return ''
}

# ------------------------------------------------------------------------------
Write-Host '=========================================================='
Write-Host ' CÀI ĐẶT HỆ THỐNG EDUCRM SIS TRÊN WINDOWS' -ForegroundColor Green
Write-Host "   Source : $Root"
Write-Host "   Runtime: $RuntimeDir   Cổng web: $Port"
Write-Host '=========================================================='

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin -and -not $NoService) {
    Fail 'Hãy chạy bằng quyền Administrator: chuột phải scripts\setup_windows.bat → Run as administrator.'
}
if ($Root -match '\s' -or $RuntimeDir -match '\s') {
    Fail "Đường dẫn không được có dấu cách. Hãy đặt source ở C:\SIS (hiện tại: $Root)."
}
if (-not (Test-Path (Join-Path $Backend 'artisan'))) { Fail "Không thấy backend\artisan trong $Root" }

New-Item -ItemType Directory -Force -Path $RuntimeDir, $Downloads, (Join-Path $RuntimeDir 'logs') | Out-Null

# Cập nhật phiên bản: dừng hệ thống đang chạy để thay file (Windows khoá file đang dùng)
$control = Join-Path $PSScriptRoot 'windows\sis-control.ps1'
if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Step 'Dừng hệ thống đang chạy để cập nhật'
    & $control stop -RuntimeDir $RuntimeDir
}

# ------------------------------------------------------------------------------
Step '[1/9] Visual C++ Redistributable (PHP cần)'
$vc = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\X64' -ErrorAction SilentlyContinue
if ($vc -and $vc.Installed -eq 1 -and $vc.Minor -ge 30) {
    Info "Đã có ($($vc.Version))"
} elseif (-not $isAdmin) {
    Warn 'Chưa có, cần quyền Administrator để cài. Bỏ qua.'
} else {
    $vcExe = Join-Path $Downloads 'vc_redist.x64.exe'
    Download 'https://aka.ms/vs/17/release/vc_redist.x64.exe' $vcExe
    $p = Start-Process -FilePath $vcExe -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
    if ($p.ExitCode -notin 0, 1638, 3010) { Fail "Cài Visual C++ lỗi (mã $($p.ExitCode))" }
    Info 'Đã cài'
}

# ------------------------------------------------------------------------------
Step '[2/9] PHP 8.4'
if (-not (Test-Path $Php)) {
    $zip = Join-Path $Downloads 'php.zip'
    if ($PhpZip) {
        Copy-Item $PhpZip $zip -Force
    } else {
        try {
            # Nguồn chính thức
            $rel = Invoke-RestMethod 'https://windows.php.net/downloads/releases/releases.json' -TimeoutSec 20
            Download "https://windows.php.net/downloads/releases/$($rel.'8.4'.'nts-vs17-x64'.zip.path)" $zip
        } catch {
            # Dự phòng: bản build Windows trên GitHub (windows.php.net hay bị chặn ở một số mạng)
            Warn 'Không tải được từ windows.php.net, chuyển sang GitHub'
            $gh = Invoke-RestMethod 'https://api.github.com/repos/shivammathur/php-builder-windows/releases/tags/php8.4'
            $asset = $gh.assets | Where-Object { $_.name -match '^php-8\.4\.\d+-nts-Win32-vs17-x64\.zip$' } | Select-Object -First 1
            if (-not $asset) { Fail 'Không tìm được bản PHP 8.4 để tải. Tải tay file php-8.4.x-nts-Win32-vs17-x64.zip rồi chạy lại với -PhpZip <đường dẫn>.' }
            Download $asset.browser_download_url $zip
        }
    }
    Expand-Flat $zip $PhpDir
}

# php.ini: lấy bản production rồi ghi thêm cấu hình của hệ thống (chạy lại script là tạo lại)
$caFile = Join-Path $PhpDir 'extras\ssl\cacert.pem'
if (-not (Test-Path $caFile)) {
    New-Item -ItemType Directory -Force -Path (Split-Path $caFile) | Out-Null
    Download 'https://curl.se/ca/cacert.pem' $caFile
}
$ini = Get-Content (Join-Path $PhpDir 'php.ini-production') -Raw
$ext = (Join-Path $PhpDir 'ext').Replace('\', '/')
$ca = $caFile.Replace('\', '/')
$ini += @"

; ===================== EduCRM SIS =====================
extension_dir = "$ext"
extension=curl
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
extension=zip
zend_extension=opcache
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=128
opcache.validate_timestamps=1
opcache.revalidate_freq=2
memory_limit=512M
max_execution_time=300
upload_max_filesize=50M
post_max_size=50M
date.timezone=Asia/Ho_Chi_Minh
curl.cainfo="$ca"
openssl.cafile="$ca"
"@
Write-Utf8NoBom (Join-Path $PhpDir 'php.ini') $ini
$phpVersion = & $Php -r 'echo PHP_VERSION;'
if ($LASTEXITCODE -ne 0) { Fail 'PHP không chạy được (thiếu Visual C++ Redistributable?)' }
$modules = @(& $Php -m) | ForEach-Object { $_.Trim().ToLower() }
$missing = @('curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_sqlite', 'zip', 'zend opcache') | Where-Object { $modules -notcontains $_ }
if ($missing) { Fail "PHP thiếu extension: $($missing -join ', ')" }
Info "PHP $phpVersion"

# ------------------------------------------------------------------------------
Step '[3/9] Composer, Node.js, Caddy, cloudflared, rclone'
if (-not (Test-Path $Composer)) { Download 'https://getcomposer.org/download/latest-stable/composer.phar' $Composer }

if (-not (Test-Path (Join-Path $NodeDir 'node.exe'))) {
    # Gán vào biến trước rồi mới lọc: PowerShell 5.1 không tách mảng JSON khi pipe thẳng từ Invoke-RestMethod
    $releases = Invoke-RestMethod 'https://nodejs.org/dist/index.json'
    $lts = $releases | Where-Object { $_.lts -and $_.files -contains 'win-x64-zip' } | Select-Object -First 1
    $zip = Join-Path $Downloads 'node.zip'
    Download "https://nodejs.org/dist/$($lts.version)/node-$($lts.version)-win-x64.zip" $zip
    Expand-Flat $zip $NodeDir
}
Info ('Node.js ' + (& (Join-Path $NodeDir 'node.exe') -v))

if (-not (Test-Path $Caddy)) {
    $zip = Join-Path $Downloads 'caddy.zip'
    $gh = Invoke-RestMethod 'https://api.github.com/repos/caddyserver/caddy/releases/latest'
    Download (($gh.assets | Where-Object { $_.name -like '*_windows_amd64.zip' } | Select-Object -First 1).browser_download_url) $zip
    Expand-Flat $zip (Split-Path $Caddy)
}
Info ('Caddy ' + ((& $Caddy version) -split ' ')[0])

if (-not (Test-Path $Cloudflared)) {
    New-Item -ItemType Directory -Force -Path (Split-Path $Cloudflared) | Out-Null
    Download 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe' $Cloudflared
}

if (-not (Test-Path $Rclone)) {
    $zip = Join-Path $Downloads 'rclone.zip'
    Download 'https://downloads.rclone.org/rclone-current-windows-amd64.zip' $zip
    Expand-Flat $zip (Split-Path $Rclone)
}

# ------------------------------------------------------------------------------
Step '[4/9] Cấu hình backend (.env)'
$envFile = Join-Path $Backend '.env'
$firstInstall = -not (Test-Path $envFile)
if ($firstInstall) {
    $envText = Get-Content (Join-Path $Backend '.env.example') -Raw
    # Chạy thật: tắt debug để không lộ thông tin lỗi / cấu hình ra Internet
    $envText = Set-EnvValue $envText 'APP_ENV' 'production'
    $envText = Set-EnvValue $envText 'APP_DEBUG' 'false'
    $envText = Set-EnvValue $envText 'LOG_LEVEL' 'warning'
    $envText = Set-EnvValue $envText 'APP_LOCALE' 'vi'
    $envText = Set-EnvValue $envText 'DB_CONNECTION' 'sqlite'

    $domain = $Domain
    if (-not $UseDefaults -and -not $domain) { $domain = Read-Host 'Tên miền truy cập, ví dụ sis.khoaanhmusic.com [Enter nếu chưa có]' }
    if ($domain) {
        $envText = Set-EnvValue $envText 'APP_URL' ('https://' + ($domain.Trim() -replace '^https?://', '').TrimEnd('/'))
    } else {
        $envText = Set-EnvValue $envText 'APP_URL' ($(if ($Port -eq 80) { 'http://localhost' } else { "http://localhost:$Port" }))
    }

    $adminEmail = $AdminEmail
    if (-not $UseDefaults -and -not $adminEmail) { $adminEmail = Read-Host 'Email chủ trung tâm [Enter = admin@khoaanhmusic.com]' }
    if ($adminEmail) { $envText = Set-EnvValue $envText 'ADMIN_EMAIL' $adminEmail.Trim() }

    while ($true) {
        $plain = $SystemAdminPassword
        if (-not $UseDefaults -and -not $plain) {
            $secure = Read-Host 'Mật khẩu system_admin@mail.com [Enter = password@123]' -AsSecureString
            $plain = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))
        }
        if (-not $plain) { break }
        if ($plain.Contains("'")) {
            if ($SystemAdminPassword) { Fail "Mật khẩu không được chứa dấu nháy đơn (')." }
            Warn "Mật khẩu không được chứa dấu nháy đơn ('), nhập lại."
            continue
        }
        $envText = Set-EnvValue $envText 'SYSTEM_ADMIN_PASSWORD' "'$plain'"
        break
    }
}
else {
    $envText = Get-Content $envFile -Raw
    Info 'Đã có .env, giữ nguyên cấu hình (chỉ cập nhật đường dẫn rclone nếu chưa đặt)'
}

# Sao lưu Google Drive dùng rclone trong thư mục runtime
if (@('', 'rclone') -contains (Get-EnvValue $envText 'BACKUP_RCLONE_BINARY')) {
    $envText = Set-EnvValue $envText 'BACKUP_RCLONE_BINARY' $Rclone
}
if (-not (Get-EnvValue $envText 'BACKUP_RCLONE_CONFIG')) {
    $envText = Set-EnvValue $envText 'BACKUP_RCLONE_CONFIG' (Join-Path $RuntimeDir 'rclone\rclone.conf')
}
Write-Utf8NoBom $envFile $envText

# ------------------------------------------------------------------------------
Step '[5/9] Cài thư viện PHP và tạo database'
Push-Location $Backend
try { Run $Php @($Composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress') } finally { Pop-Location }

$db = Join-Path $Backend 'database\database.sqlite'
if (-not (Test-Path $db)) { New-Item -ItemType File -Path $db | Out-Null }

# Chỉ tạo APP_KEY khi cài lần đầu: tạo lại sẽ làm hỏng dữ liệu đã mã hoá
if ($firstInstall -or -not (Get-EnvValue (Get-Content $envFile -Raw) 'APP_KEY')) { Artisan @('key:generate', '--force') }
Artisan @('config:clear')
Artisan @('migrate', '--force')
# Chỉ tạo tài khoản quản trị + mẫu tin nhắn (không có dữ liệu demo), in tài khoản ra màn hình
Artisan @('db:seed', '--force')
if (-not (Test-Path (Join-Path $Backend 'public\storage'))) { Artisan @('storage:link') }
Artisan @('config:cache')
Artisan @('route:cache')
Artisan @('view:cache')

# Lối tắt chạy lệnh artisan không cần nhớ đường dẫn PHP: <source>\sis.bat sis:backup
$shortcut = "@echo off`r`nrem Tao tu dong boi scripts\setup_windows.ps1. Vi du: sis.bat sis:backup`r`ncd /d `"%~dp0backend`"`r`n`"$Php`" artisan %*`r`n"
[IO.File]::WriteAllText((Join-Path $Root 'sis.bat'), $shortcut, [Text.Encoding]::ASCII)
Info "Lối tắt lệnh hệ thống: $Root\sis.bat (ví dụ: $Root\sis.bat sis:backup)"

# ------------------------------------------------------------------------------
Step '[6/9] Build giao diện'
$env:Path = "$NodeDir;$env:Path"
Push-Location $Frontend
try {
    Run (Join-Path $NodeDir 'npm.cmd') @('ci', '--no-audit', '--no-fund')
    Run (Join-Path $NodeDir 'npm.cmd') @('run', 'build')
} finally { Pop-Location }
if (-not (Test-Path (Join-Path $Frontend 'dist\index.html'))) { Fail 'Build giao diện không tạo ra frontend\dist\index.html' }

# ------------------------------------------------------------------------------
Step '[7/9] Cấu hình web server (Caddy)'
$phpPorts = 1..$PhpWorkers | ForEach-Object { 9100 + $_ }
$slash = { param($p) $p.Replace('\', '/') }
$caddyfile = @"
# Tạo tự động bởi scripts\setup_windows.ps1, chạy lại script sẽ ghi đè
{
	admin off
	auto_https off
}

:$Port {
	encode gzip
	request_body {
		max_size 50MB
	}

	# API -> Laravel (nhiều tiến trình PHP chạy song song)
	handle /api/* {
		root * "$(& $slash (Join-Path $Backend 'public'))"
		php_fastcgi $(($phpPorts | ForEach-Object { "127.0.0.1:$_" }) -join ' ') {
			lb_policy least_conn
			lb_try_duration 10s
			fail_duration 5s
		}
	}

	# File tải lên (logo, ảnh...)
	handle_path /storage/* {
		root * "$(& $slash (Join-Path $Backend 'storage\app\public'))"
		file_server
	}

	# Giao diện Vue (SPA)
	handle {
		root * "$(& $slash (Join-Path $Frontend 'dist'))"
		try_files {path} /index.html
		file_server
	}
}
"@
Write-Utf8NoBom (Join-Path $RuntimeDir 'Caddyfile') $caddyfile
Run $Caddy @('validate', '--config', (Join-Path $RuntimeDir 'Caddyfile'), '--adapter', 'caddyfile')

# Cấu hình cho tiến trình chạy nền (scripts\windows\sis-service.ps1)
$serviceConfig = @{ root = $Root; runtime = $RuntimeDir; port = $Port; php_ports = @($phpPorts) } | ConvertTo-Json
Write-Utf8NoBom (Join-Path $RuntimeDir 'sis-service.json') $serviceConfig

if ($NoService) {
    Write-Host "`nĐã cài xong (chế độ -NoService, không đăng ký chạy nền)." -ForegroundColor Green
    Write-Host "Chạy thử: powershell -ExecutionPolicy Bypass -File `"$PSScriptRoot\windows\sis-service.ps1`" -RuntimeDir `"$RuntimeDir`""
    exit 0
}

# ------------------------------------------------------------------------------
Step '[8/9] Chạy nền khi bật máy, nguồn điện, tường lửa'

# Cổng web phải trống (IIS / XAMPP / Skype hay chiếm cổng 80)
$busy = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
if ($busy) {
    $owner = (Get-Process -Id $busy.OwningProcess -ErrorAction SilentlyContinue).ProcessName
    if ($owner -eq 'System') { $owner = 'System (thường là IIS / dịch vụ World Wide Web Publishing)' }
    Fail "Cổng $Port đang bị chiếm bởi: $owner. Tắt chương trình đó, hoặc chạy lại với -Port 8080."
}

$service = Join-Path $PSScriptRoot 'windows\sis-service.ps1'
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$service`" -RuntimeDir `"$RuntimeDir`""
$trigger = New-ScheduledTaskTrigger -AtStartup
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings `
    -Description 'EduCRM SIS: web server + PHP + lịch sao lưu. Quản lý bằng scripts\windows\sis-control.ps1' -Force | Out-Null
Info "Đã đăng ký tác vụ '$TaskName' (chạy khi bật máy, không cần đăng nhập)"

# Cắm sạc: không ngủ / ngủ đông, gập màn hình không làm gì
powercfg /change standby-timeout-ac 0
powercfg /change hibernate-timeout-ac 0
powercfg /setacvalueindex SCHEME_CURRENT SUB_BUTTONS LIDACTION 0
powercfg /setactive SCHEME_CURRENT
Info 'Đã tắt ngủ khi cắm sạc và khi gập màn hình'

# Cho máy khác trong mạng LAN truy cập (Cloudflare Tunnel không cần mở cổng)
if (-not (Get-NetFirewallRule -DisplayName 'EduCRM SIS (web)' -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -DisplayName 'EduCRM SIS (web)' -Direction Inbound -Protocol TCP -LocalPort $Port -Action Allow -Profile Private, Domain | Out-Null
    Info "Đã mở cổng $Port trong mạng nội bộ"
}

& $control start -RuntimeDir $RuntimeDir
$ok = $false
for ($i = 0; $i -lt 30 -and -not $ok; $i++) {
    Start-Sleep -Seconds 2
    try { $ok = (Invoke-WebRequest "http://127.0.0.1:$Port/api/brand" -UseBasicParsing -TimeoutSec 5).StatusCode -eq 200 } catch { }
}
if (-not $ok) { Fail "Hệ thống chưa phản hồi ở http://localhost:$Port. Xem log trong $RuntimeDir\logs và backend\storage\logs\laravel.log" }
Info "Hệ thống đang chạy: http://localhost$(if ($Port -ne 80) { ":$Port" })"

# ------------------------------------------------------------------------------
Step '[9/9] Cloudflare Tunnel (truy cập từ ngoài mạng)'
if (Get-Service -Name 'cloudflared' -ErrorAction SilentlyContinue) {
    Info 'Đã cài dịch vụ cloudflared từ trước, bỏ qua'
} else {
    $tunnel = $TunnelToken
    if (-not $UseDefaults -and -not $tunnel) { $tunnel = Read-Host 'Dán lệnh / token Cloudflare Tunnel (Enter để làm sau)' }
    $token = [regex]::Match($tunnel, 'eyJ[A-Za-z0-9_\-\.=]+').Value
    if ($token) {
        Run $Cloudflared @('service', 'install', $token)
        Info 'Đã cài Cloudflare Tunnel thành dịch vụ Windows (tự chạy khi bật máy)'
    } else {
        Warn 'Chưa cài Cloudflare Tunnel. Xem Bước 3 trong WINDOWS_INSTALL_GUIDE.md'
    }
}

Write-Host "`n==========================================================" -ForegroundColor Green
Write-Host ' CÀI ĐẶT THÀNH CÔNG' -ForegroundColor Green
Write-Host "  - Mở http://localhost$(if ($Port -ne 80) { ":$Port" }) trên máy này để kiểm tra"
Write-Host '  - Tài khoản đăng nhập in ở bước seed phía trên. Khách nên đổi mật khẩu admin ở màn Cài đặt.'
Write-Host "  - Quản lý: powershell -ExecutionPolicy Bypass -File `"$control`" status|restart|stop"
Write-Host '=========================================================='
