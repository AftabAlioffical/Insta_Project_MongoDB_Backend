<#
setup-xampp.ps1
Helper script to deploy this project into XAMPP's htdocs and import the DB.
Run PowerShell as Administrator after XAMPP is installed and Apache/MySQL are stopped/started as needed.
#>

param(
    [string]$XamppPath = 'C:\\xampp',
    [string]$ProjectPath = 'C:\\Users\\admin\\Desktop\\Insta_Project'
)

function Write-Status { param($m) Write-Host "[setup-xampp] $m" }

# Verify XAMPP path
if (-not (Test-Path $XamppPath)) {
    Write-Status "XAMPP not found at $XamppPath. Please install XAMPP and update the script parameter."
    exit 1
}

$target = Join-Path $XamppPath 'htdocs\Insta_Project'
Write-Status "Copying project to $target (this will overwrite existing files)..."

# Remove existing and copy
if (Test-Path $target) { Remove-Item -Recurse -Force $target }
Copy-Item -Path $ProjectPath -Destination $target -Recurse -Force

Write-Status "Project copied. Setting Apache document root to the project's 'public' folder is recommended."

Write-Host "
Recommended Apache VirtualHost (add to $XamppPath\apache\conf\extra\httpd-vhosts.conf):
" 
Write-Host "<VirtualHost *:80>"
Write-Host "    ServerName insta.local"
Write-Host "    DocumentRoot \"$target\\public\""
Write-Host "    <Directory \"$target\\public\">"
Write-Host "        Options Indexes FollowSymLinks"
Write-Host "        AllowOverride All"
Write-Host "        Require all granted"
Write-Host "    </Directory>"
Write-Host "</VirtualHost>"

Write-Host "
Add the following line to your hosts file (run editor as Administrator):
127.0.0.1 insta.local
"

# Import database using XAMPP MySQL CLI
$mysqlExe = Join-Path $XamppPath 'mysql\bin\mysql.exe'
if (Test-Path $mysqlExe) {
    Write-Status "Importing database schema and seed (using MySQL CLI). The XAMPP MySQL server must be running."
    & $mysqlExe -u root -e "CREATE DATABASE IF NOT EXISTS insta_app;"
    & $mysqlExe -u root insta_app < (Join-Path $target 'database\schema.sql')
    & $mysqlExe -u root insta_app < (Join-Path $target 'database\seed.sql')
    Write-Status "Database import completed (errors may be printed above)."
} else {
    Write-Status "MySQL client not found at $mysqlExe. You can import schema via phpMyAdmin or MySQL CLI manually."
}

Write-Status "Setup script finished. Start Apache and MySQL from XAMPP control panel and open http://insta.local/login.html (or http://localhost/Insta_Project/public/login.html)."
Write-Host "Don't forget to update .env (DB_HOST, DB_USER, DB_PASS) if needed."
