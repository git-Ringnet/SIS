@echo off
rem EduCRM SIS - cai dat tren Windows. Bam dup (hoac chuot phai > Run as administrator).
rem Tham so them (neu can): setup_windows.bat -Port 8080
net session >nul 2>&1
if %errorlevel% neq 0 (
    rem Chua co quyen Administrator: tu xin quyen roi chay lai
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -ArgumentList '%*' -Verb RunAs"
    exit /b
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup_windows.ps1" %*
echo.
pause
