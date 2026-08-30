# TSO Admin Notices Manager — phpcs-check
# Usage: .\scripts\phpcs-check.ps1
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $Root

$failed = $false

Write-Host '== php -l ==' -ForegroundColor Cyan
Get-ChildItem $Root -Recurse -Filter *.php |
    Where-Object { $_.FullName -notmatch '[\\/](vendor|node_modules)[\\/]' } |
    ForEach-Object {
        & php -l $_.FullName 2>&1 | Out-Host
        if ($LASTEXITCODE -ne 0) { $failed = $true }
    }

Write-Host '== duplicate function tsoan_ ==' -ForegroundColor Cyan
$phpFiles = @( Get-ChildItem $Root -Recurse -Filter *.php | Where-Object { $_.FullName -notmatch 'node_modules' } )
$fns = @()
if ( $phpFiles.Count -gt 0 ) {
	$fns = @( Select-String -Path $phpFiles.FullName -Pattern '^\s*function\s+(tsoan_\w+)' -AllMatches -ErrorAction SilentlyContinue )
}
$names = @()
foreach ( $hit in $fns ) {
	foreach ( $m in $hit.Matches ) {
		$names += $m.Groups[1].Value
	}
}
$dupes = $names | Group-Object | Where-Object { $_.Count -gt 1 }
if ( $dupes ) {
	$dupes | ForEach-Object { Write-Host "DUPLICATE: $($_.Name) x$($_.Count)" -ForegroundColor Red }
	$failed = $true
} else {
	Write-Host 'OK: no duplicate tsoan_ functions.' -ForegroundColor Green
}

Write-Host '== direct $_POST / $_GET outside helpers ==' -ForegroundColor Cyan
$hits = Select-String -Path (Get-ChildItem $Root -Recurse -Filter *.php | Where-Object { $_.FullName -notmatch 'node_modules|uninstall\.php' }).FullName -Pattern '\$_(POST|GET)\b'
if ($hits) {
    $hits | ForEach-Object { Write-Host "$($_.Filename):$($_.LineNumber) $($_.Line.Trim())" -ForegroundColor Red }
    $failed = $true
} else {
    Write-Host 'OK: no direct $_POST/$_GET.' -ForegroundColor Green
}

$phpcs = Get-Command phpcs -ErrorAction SilentlyContinue
if ($phpcs) {
    Write-Host '== phpcs ==' -ForegroundColor Cyan
    & phpcs --standard=WordPress --extensions=php --ignore=*/node_modules/*,*/vendor/* $Root
    if ($LASTEXITCODE -ne 0) { $failed = $true }
} else {
    Write-Host 'SKIP: phpcs not in PATH' -ForegroundColor Yellow
}

if ($failed) {
    Write-Host 'FAILED' -ForegroundColor Red
    exit 1
}
Write-Host 'OK: phpcs-check passed.' -ForegroundColor Green
exit 0
