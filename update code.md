cd /d D:\SIS\backend
C:\SIS-runtime\php\php.exe C:\SIS-runtime\composer.phar install --no-dev --optimize-autoloader --no-interaction --no-progress
C:\SIS-runtime\php\php.exe artisan migrate --force
C:\SIS-runtime\php\php.exe artisan config:cache
C:\SIS-runtime\php\php.exe artisan route:cache
C:\SIS-runtime\php\php.exe artisan view:cache

set PATH=C:\SIS-runtime\node;%PATH%
cd /d D:\SIS\frontend
npm ci --no-audit --no-fund
npm run build
dir dist\index.html
