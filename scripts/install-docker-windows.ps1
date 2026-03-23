<#
install-docker-windows.ps1
Automates installation of Docker Desktop on Windows using winget when available.
Run PowerShell as Administrator to execute this script.
#>

Param()

function Write-Status {
    param([string]$msg)
    Write-Host "[install-docker] $msg"
}

# Check if docker already installed
if (Get-Command docker -ErrorAction SilentlyContinue) {
    Write-Status "Docker is already installed. Use 'docker --version' to verify."
    exit 0
}

# Ensure running elevated
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Status "This script must be run as Administrator. Please launch an elevated PowerShell and re-run."
    exit 1
}

# Try winget first
$winget = Get-Command winget -ErrorAction SilentlyContinue
if ($winget) {
    Write-Status "Found winget. Installing Docker Desktop via winget (may prompt for UI).
If winget prompts for confirmation, allow it."
    try {
        winget install --id Docker.DockerDesktop -e --accept-package-agreements --accept-source-agreements --silent
        if ($LASTEXITCODE -eq 0) {
            Write-Status "winget install finished."
        } else {
            Write-Status "winget returned exit code $LASTEXITCODE. You may need to complete installation via UI."
        }
    } catch {
        Write-Status "winget install failed: $($_.Exception.Message)"
    }
} else {
    Write-Status "winget not found on this system. Falling back to downloading Docker Desktop installer."

    $installerUrl = "https://desktop.docker.com/win/main/amd64/Docker%20Desktop%20Installer.exe"
    $tempPath = Join-Path $env:TEMP "DockerDesktopInstaller.exe"

    Write-Status "Downloading Docker Desktop installer to $tempPath"
    try {
        Invoke-WebRequest -Uri $installerUrl -OutFile $tempPath -UseBasicParsing -ErrorAction Stop
        Write-Status "Downloaded installer. Attempting to run installer (UI will appear)."
        Start-Process -FilePath $tempPath -ArgumentList "" -Wait
    } catch {
        Write-Status "Download or installation failed: $($_.Exception.Message)"
        Write-Status "Please download manually from https://www.docker.com/get-started and run the installer as Administrator."
        exit 1
    }
}

Write-Status "Installation step complete. Ensure Docker Desktop is running."
Write-Status "If prompted, enable WSL2 integration and restart your machine."

Write-Host "`nVerification commands to run after Docker Desktop starts:`n"
Write-Host "PowerShell> docker --version"
Write-Host "PowerShell> docker compose version"
Write-Host "PowerShell> docker info"

Write-Status "If Docker is not starting, open Docker Desktop UI and follow prompts to enable WSL2 or Hyper-V and restart."