#Requires -Version 5.1
# ==============================================================================
# QUẢN LÝ EDUCRM SIS TRÊN WINDOWS (chạy bằng PowerShell quyền Administrator)
#   powershell -ExecutionPolicy Bypass -File scripts\windows\sis-control.ps1 status
#   ... start | stop | restart
# ==============================================================================
param(
    [ValidateSet('start', 'stop', 'restart', 'status')]
    [string]$Action = 'status',
    [string]$RuntimeDir = 'C:\SIS-runtime'
)

[Console]::OutputEncoding = [Text.Encoding]::UTF8
$TaskName = 'EduCRM SIS'
$config = $null
$configFile = Join-Path $RuntimeDir 'sis-service.json'
if (Test-Path $configFile) { $config = Get-Content $configFile -Raw | ConvertFrom-Json }
$port = if ($config) { $config.port } else { 80 }

function Get-SisProcesses {
    Get-Process caddy, php-cgi, php -ErrorAction SilentlyContinue |
        Where-Object { $_.Path -and $_.Path.StartsWith($RuntimeDir, [StringComparison]::OrdinalIgnoreCase) }
}

function Stop-Sis {
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    # Dừng tác vụ chỉ tắt tiến trình PowerShell quản lý, phải tắt thêm Caddy / PHP
    Get-SisProcesses | Stop-Process -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 1
    Write-Host 'Đã dừng hệ thống.'
}

function Start-Sis {
    if (-not (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue)) {
        Write-Host "Chưa có tác vụ '$TaskName'. Hãy chạy scripts\setup_windows.bat trước." -ForegroundColor Red
        exit 1
    }
    Start-ScheduledTask -TaskName $TaskName
    Write-Host "Đã bật hệ thống (http://localhost$(if ($port -ne 80) { ":$port" }))."
}

function Show-Status {
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Write-Host ("Tác vụ chạy nền   : {0}" -f $(if ($task) { $task.State } else { 'chưa đăng ký' }))
    $procs = @(Get-SisProcesses)
    Write-Host ("Web server (Caddy): {0}" -f $(if ($procs | Where-Object ProcessName -eq 'caddy') { 'đang chạy' } else { 'KHÔNG chạy' }))
    Write-Host ("Tiến trình PHP    : {0}" -f @($procs | Where-Object ProcessName -eq 'php-cgi').Count)
    Write-Host ("Lịch sao lưu      : {0}" -f $(if ($procs | Where-Object ProcessName -eq 'php') { 'đang chạy' } else { 'KHÔNG chạy' }))
    $tunnel = Get-Service -Name 'cloudflared' -ErrorAction SilentlyContinue
    Write-Host ("Cloudflare Tunnel : {0}" -f $(if ($tunnel) { $tunnel.Status } else { 'chưa cài' }))
    try {
        $code = (Invoke-WebRequest "http://127.0.0.1:$port/api/brand" -UseBasicParsing -TimeoutSec 5).StatusCode
        Write-Host "Kiểm tra API      : OK ($code)" -ForegroundColor Green
    } catch {
        Write-Host "Kiểm tra API      : LỖI ($($_.Exception.Message))" -ForegroundColor Red
        Write-Host "  Xem log: $RuntimeDir\logs và backend\storage\logs\laravel.log"
    }
}

switch ($Action) {
    'stop' { Stop-Sis }
    'start' { Start-Sis }
    'restart' { Stop-Sis; Start-Sis }
    'status' { Show-Status }
}
