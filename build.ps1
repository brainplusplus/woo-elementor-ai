# Build script for Woo Elementor AI plugin distribution
# Reads config from .env.build
# Cross-platform: works on Windows (PowerShell) and Linux/macOS (pwsh)
param(
    [string]$PublicKey,
    [switch]$SkipObfuscate
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path ".env.build")) {
    Write-Host "Error: .env.build not found." -ForegroundColor Red
    exit 1
}

Get-Content ".env.build" | ForEach-Object {
    if ($_ -match '^\s*([^#][^=]+)=(.*)$') {
        $key = $matches[1].Trim()
        $val = $matches[2].Trim()
        Set-Variable -Name "ENV_$key" -Value $val -Scope Script
    }
}

$Version = $ENV_PLUGIN_VERSION
if (-not $Version) {
    Write-Host "Error: PLUGIN_VERSION not set in .env.build" -ForegroundColor Red
    exit 1
}

if ($PublicKey) { $ENV_PUBLIC_KEY = $PublicKey }

if ($ENV_OBFUSCATE -eq 'false' -or $ENV_OBFUSCATE -eq '0' -or $ENV_OBFUSCATE -eq 'no') {
    $SkipObfuscate = $true
}

$DistDir = Join-Path "dist" "woo-elementor-ai"
$ZipName = "woo-elementor-ai-v$Version.zip"

Write-Host "=== Building Woo Elementor AI v$Version ===" -ForegroundColor Cyan

# Clean
if (Test-Path $DistDir) { Remove-Item -Recurse -Force $DistDir }
if (Test-Path $ZipName) { Remove-Item -Force $ZipName }

# Copy source files, exclude dev-only files
$excludeDirs = @('dist', 'docs', '.git', 'woo-ai-licensegen', 'tools', 'vendor')
$excludeFiles = @('Makefile', 'build.ps1', '.env.build', 'composer.json', 'composer.lock', '.gitignore')
New-Item -ItemType Directory -Path $DistDir -Force | Out-Null

$sourceItems = Get-ChildItem -Path . -Exclude $excludeDirs
foreach ($item in $sourceItems) {
    if ($item.PSIsContainer) {
        Copy-Item -Path $item.FullName -Destination $DistDir -Recurse -Force
    } elseif ($excludeFiles -notcontains $item.Name) {
        Copy-Item -Path $item.FullName -Destination $DistDir -Force
    }
}

# Remove excluded files that may have been copied via directory copy
foreach ($ef in $excludeFiles) {
    $fp = Join-Path $DistDir $ef
    if (Test-Path $fp) { Remove-Item -Force $fp }
}
$toolsDir = Join-Path $DistDir "tools"
if (Test-Path $toolsDir) { Remove-Item -Recurse -Force $toolsDir }
$vendorDir = Join-Path $DistDir "vendor"
if (Test-Path $vendorDir) { Remove-Item -Recurse -Force $vendorDir }

# Embed public key
if ($ENV_PUBLIC_KEY) {
    $licenseFile = Join-Path $DistDir "includes\class-license.php"
    $content = Get-Content $licenseFile -Raw
    $content = $content -replace 'PLACEHOLDER_PUBLIC_KEY', $ENV_PUBLIC_KEY
    Set-Content $licenseFile $content -NoNewline
    Write-Host "Public key embedded." -ForegroundColor Green
}

# Embed require license setting
$requireLicense = if ($ENV_REQUIRED_LICENSE_KEY) { $ENV_REQUIRED_LICENSE_KEY } else { 'true' }
$licenseFile = Join-Path $DistDir "includes\class-license.php"
$licenseContent = Get-Content $licenseFile -Raw
$licenseContent = $licenseContent -replace "PLACEHOLDER_REQUIRE_LICENSE", $requireLicense
Set-Content $licenseFile $licenseContent -NoNewline
Write-Host "Required license: $requireLicense" -ForegroundColor Green

# Obfuscation (6-layer pipeline: rename → encrypt strings → goto flow → dead code → eval wrap → integrity)
if (-not $SkipObfuscate) {
    $customScript = Join-Path $PSScriptRoot "tools\obfuscator\obfuscate.php"

    $excludeFromObfuscation = @(
        'class-elementor-data.php',
        'class-image-service.php',
        'class-log-service.php'
    )

    $obfuscateFiles = @()
    $includesDir = Join-Path $DistDir "includes"
    if (Test-Path $includesDir) {
        $obfuscateFiles += Get-ChildItem -Path $includesDir -Filter "*.php" -Recurse | Where-Object {
            $excludeFromObfuscation -notcontains $_.Name
        } | Select-Object -ExpandProperty FullName
    }

    if (Test-Path $customScript) {
        Write-Host "Obfuscating $($obfuscateFiles.Count) PHP files (excluded: $($excludeFromObfuscation -join ', '))..." -ForegroundColor Yellow
        foreach ($file in $obfuscateFiles) {
            php $customScript $file 2>&1 | ForEach-Object { Write-Host "  $_" -ForegroundColor DarkGray }
        }
    } else {
        Write-Host "Obfuscator not found at tools/obfuscator/ - run: cd tools/obfuscator && composer install" -ForegroundColor Red
    }
} else {
    Write-Host "Obfuscation skipped (-SkipObfuscate)." -ForegroundColor DarkYellow
}

# Update version in plugin file
$pluginFile = Join-Path $DistDir "woo-elementor-ai.php"
$pluginContent = Get-Content $pluginFile -Raw
$pluginContent = $pluginContent -replace "Version:     .+", "Version:     $Version"
$pluginContent = $pluginContent -replace "WOO_ELEMENTOR_AI_VERSION', '.+'", "WOO_ELEMENTOR_AI_VERSION', '$Version'"
Set-Content $pluginFile $pluginContent -NoNewline

# Create ZIP
Compress-Archive -Path $DistDir -DestinationPath $ZipName -Force
Write-Host "=== Build complete: $ZipName ===" -ForegroundColor Green
