#Requires -Version 5.1
# ==============================================================================
# CHẠY NỀN EDUCRM SIS TRÊN WINDOWS (tác vụ "EduCRM SIS" gọi file này khi bật máy, quyền SYSTEM)
# Giữ cho các tiến trình luôn chạy, cái nào tắt thì bật lại sau vài giây:
#   - Caddy        : web server (giao diện + chuyển /api sang PHP)
#   - php-cgi × N  : chạy Laravel (mỗi tiến trình tự khởi động lại sau 500 request để giải phóng bộ nhớ)
#   - scheduler    : php artisan schedule:work (sao lưu hằng ngày, dọn nhật ký cũ)
# Không chạy file này bằng tay khi tác vụ đang chạy; dùng sis-control.ps1 start|stop|restart|status.
# ==============================================================================
param([string]$RuntimeDir = 'C:\SIS-runtime')

$ErrorActionPreference = 'Continue'
$config = Get-Content (Join-Path $RuntimeDir 'sis-service.json') -Raw | ConvertFrom-Json
$logDir = Join-Path $RuntimeDir 'logs'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$logFile = Join-Path $logDir 'sis-service.log'

function Log([string]$Message) {
    # Giữ file log gọn: quá 1MB thì đổi tên thành .old
    if ((Test-Path $logFile) -and (Get-Item $logFile).Length -gt 1MB) { Move-Item $logFile "$logFile.old" -Force }
    Add-Content -Path $logFile -Value ('{0:yyyy-MM-dd HH:mm:ss} {1}' -f (Get-Date), $Message) -Encoding UTF8
}

# Dọn tiến trình còn sót từ lần chạy trước (ví dụ tác vụ bị dừng đột ngột)
Get-Process caddy, php-cgi, php -ErrorAction SilentlyContinue |
    Where-Object { $_.Path -and $_.Path.StartsWith($RuntimeDir, [StringComparison]::OrdinalIgnoreCase) } |
    Stop-Process -Force -ErrorAction SilentlyContinue

$php = Join-Path $RuntimeDir 'php\php.exe'
$backend = Join-Path $config.root 'backend'
$env:PHP_FCGI_MAX_REQUESTS = '500'

$processes = @(
    @{ Name = 'web'; File = (Join-Path $RuntimeDir 'caddy\caddy.exe'); Dir = $RuntimeDir
        Args = "run --config `"$(Join-Path $RuntimeDir 'Caddyfile')`" --adapter caddyfile"; Log = 'web.log' }
    @{ Name = 'scheduler'; File = $php; Dir = $backend; Args = 'artisan schedule:work'; Log = 'scheduler.log' }
)
foreach ($port in $config.php_ports) {
    $processes += @{ Name = "php:$port"; File = (Join-Path $RuntimeDir 'php\php-cgi.exe'); Dir = (Join-Path $backend 'public'); Args = "-b 127.0.0.1:$port" }
}

Log "Khởi động: web cổng $($config.port), $(@($config.php_ports).Count) tiến trình PHP, scheduler"

while ($true) {
    foreach ($p in $processes) {
        if ($p.Proc -and -not $p.Proc.HasExited) { continue }
        if ($p.Proc) {
            # php-cgi thoát mã 0 sau 500 request là bình thường, chỉ ghi log khi lỗi
            if ($p.Proc.ExitCode -ne 0) { Log "$($p.Name) dừng (mã $($p.Proc.ExitCode)), chạy lại" }
        }
        $start = @{ FilePath = $p.File; ArgumentList = $p.Args; WorkingDirectory = $p.Dir; WindowStyle = 'Hidden'; PassThru = $true }
        if ($p.Log) { $start.RedirectStandardError = Join-Path $logDir $p.Log; $start.RedirectStandardOutput = Join-Path $logDir ($p.Log -replace '\.log$', '.out.log') }
        try {
            $p.Proc = Start-Process @start
        } catch {
            Log "Không chạy được $($p.Name): $($_.Exception.Message)"
            $p.Proc = $null
        }
    }
    Start-Sleep -Seconds 2
}
