#Requires -Version 5.1
# ==============================================================================
# KIỂM TRA DUNG LƯỢNG Ổ ĐĨA / DỮ LIỆU SIS / RAM (chỉ đọc, không thay đổi gì)
# Chạy được ngay trong cmd:
#   powershell -NoProfile -ExecutionPolicy Bypass -File D:\SIS\scripts\windows\check-storage.ps1
# Mốc nên để ý: ổ đĩa còn dưới 5 GB, hoặc RAM trống thường xuyên dưới 500 MB.
# ==============================================================================
param([string]$SisDir = 'D:\SIS')

$ErrorActionPreference = 'SilentlyContinue'
[Console]::OutputEncoding = [Text.Encoding]::UTF8

function Get-SizeMB([string]$Path) {
    if (-not (Test-Path $Path)) { return '-' }
    $item = Get-Item $Path
    $bytes = if ($item.PSIsContainer) { (Get-ChildItem $Path -Recurse -File | Measure-Object Length -Sum).Sum } else { $item.Length }
    return '{0:N1} MB' -f ($bytes / 1MB)
}

Write-Host '== O DIA ==' -ForegroundColor Cyan
Get-PSDrive -PSProvider FileSystem | Where-Object { $_.Used -ne $null -and ($_.Used + $_.Free) -gt 0 } | ForEach-Object {
    $free = $_.Free / 1GB
    $line = '{0}: con trong {1:N1} GB / tong {2:N1} GB' -f $_.Name, $free, (($_.Used + $_.Free) / 1GB)
    if ($free -lt 5) { Write-Host "$line  <-- SAP DAY, nen don bot" -ForegroundColor Red } else { Write-Host $line }
}

Write-Host ''
Write-Host '== DU LIEU SIS ==' -ForegroundColor Cyan
Write-Host ('Co so du lieu (hoc vien, diem danh, hoa don): ' + (Get-SizeMB "$SisDir\backend\database\database.sqlite"))
Write-Host ('Anh tai len (anh hoc vien, logo, QR...):      ' + (Get-SizeMB "$SisDir\backend\storage\app\public"))
Write-Host ('File du lieu JSON (check-in, nhan su...):      ' + (Get-SizeMB "$SisDir\backend\storage\app\private"))
Write-Host ('Ban sao luu:                                   ' + (Get-SizeMB "$SisDir\backend\storage\app\private\backups"))
Write-Host ('Nhat ky loi:                                   ' + (Get-SizeMB "$SisDir\backend\storage\logs"))
Write-Host ('Toan bo thu muc SIS:                           ' + (Get-SizeMB $SisDir))

Write-Host ''
Write-Host '== RAM ==' -ForegroundColor Cyan
$os = Get-CimInstance Win32_OperatingSystem
$freeGb = $os.FreePhysicalMemory / 1MB
$ramLine = 'RAM con trong {0:N1} GB / tong {1:N1} GB' -f $freeGb, ($os.TotalVisibleMemorySize / 1MB)
if ($freeGb -lt 0.5) { Write-Host "$ramLine  <-- RAM thap" -ForegroundColor Red } else { Write-Host $ramLine }
Get-Process php-cgi, php, caddy | Group-Object ProcessName | ForEach-Object {
    Write-Host ('SIS - {0}: {1} tien trinh, {2:N0} MB RAM' -f $_.Name, $_.Count, (($_.Group | Measure-Object WorkingSet64 -Sum).Sum / 1MB))
}
