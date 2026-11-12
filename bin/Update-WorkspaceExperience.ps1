param(
    [string]$ProjectPath = 'C:\wamp64\www\AnalystToolkit',
    [string]$PhpPath,
    [string]$OpenUrl = 'http://localhost/AnalystToolkit/',
    [switch]$SkipDatabaseMigration,
    [switch]$SkipRestart,
    [switch]$NoBrowser
)

$ErrorActionPreference = 'Stop'
$patchRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$projectRoot = [IO.Path]::GetFullPath($ProjectPath)
$manifestPath = Join-Path $patchRoot 'patches\workspace-experience-2026-11-12.26-files.json'
$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json

if (!(Test-Path -LiteralPath (Join-Path $projectRoot 'config\Database.php') -PathType Leaf)) {
    throw 'ProjectPath must point to the existing DevAtlas project root.'
}

foreach ($dependency in @('lib\UserUiPreferences.php', 'database\migration_20261112_ui_preferences.sql', 'assets\js\ui-motion.js', 'assets\css\ui-motion.css')) {
    if (!(Test-Path -LiteralPath (Join-Path $projectRoot $dependency) -PathType Leaf)) {
        throw 'This affected-files patch requires DevAtlas main through PR #13. Update the project from main before applying it.'
    }
}

if (!$PhpPath) {
    $command = Get-Command php -ErrorAction SilentlyContinue
    if ($command) { $PhpPath = $command.Source }
    if (!$PhpPath) {
        $wampRoot = Split-Path -Parent (Split-Path -Parent $projectRoot)
        $PhpPath = Get-ChildItem -Path (Join-Path $wampRoot 'bin\php\php*\php.exe') -File -ErrorAction SilentlyContinue |
            Sort-Object { try { [version]($_.Directory.Name -replace '^php', '') } catch { [version]'0.0' } } -Descending |
            Select-Object -First 1 -ExpandProperty FullName
    }
}
if (!$PhpPath -or !(Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw 'PHP was not found. Run again with -PhpPath pointing to WAMP php.exe.'
}

$entries = @()
foreach ($file in $manifest.files) {
    $relative = [string]$file.path
    if ([IO.Path]::IsPathRooted($relative) -or $relative -match '(^|[\\/])\.\.([\\/]|$)') { throw 'Invalid package path.' }
    $source = Join-Path $patchRoot $relative
    $target = Join-Path $projectRoot $relative
    if (!(Test-Path -LiteralPath $source -PathType Leaf)) { throw "Missing package file: $relative" }
    if ((Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash -ne $file.sha256) { throw "Package checksum mismatch: $relative" }
    if ([IO.Path]::GetExtension($relative) -eq '.php') {
        & $PhpPath -l $source | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "PHP syntax check failed: $relative" }
    }
    $entries += [PSCustomObject]@{ File = $relative; Source = $source; Target = $target; Sha256 = [string]$file.sha256 }
}

$entries += [PSCustomObject]@{ File = 'patches\workspace-experience-2026-11-12.26-files.json'; Source = $manifestPath; Target = (Join-Path $projectRoot 'patches\workspace-experience-2026-11-12.26-files.json'); Sha256 = (Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash }

$backupRoot = Join-Path $projectRoot ('backups\workspace-experience-' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
$sameRoot = $patchRoot.TrimEnd('\', '/') -eq $projectRoot.TrimEnd('\', '/')
if (!$sameRoot) {
    New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
    $created = @()
    foreach ($entry in $entries) {
        if (Test-Path -LiteralPath $entry.Target -PathType Leaf) {
            $backup = Join-Path $backupRoot $entry.File
            New-Item -ItemType Directory -Path (Split-Path -Parent $backup) -Force | Out-Null
            Copy-Item -LiteralPath $entry.Target -Destination $backup -Force
        } else { $created += $entry.File }
        New-Item -ItemType Directory -Path (Split-Path -Parent $entry.Target) -Force | Out-Null
        Copy-Item -LiteralPath $entry.Source -Destination $entry.Target -Force
    }
    ConvertTo-Json -InputObject @($created) | Set-Content -LiteralPath (Join-Path $backupRoot 'new-files.json') -Encoding UTF8
    Write-Host "Replaced files backed up to $backupRoot" -ForegroundColor Cyan
}

if (!$SkipDatabaseMigration) {
    & $PhpPath (Join-Path $projectRoot 'bin\install-workspace-experience.php')
    if ($LASTEXITCODE -ne 0) { throw 'Files were installed, but the database upgrade failed. Review the installer output and retry the dated installer.' }
}

if (!$SkipRestart) {
    try {
        $apache = @(Get-Service -Name 'wampapache*' -ErrorAction Stop | Where-Object { $_.Status -eq 'Running' })
        if ($apache.Count) { $apache | Restart-Service -ErrorAction Stop; Write-Host 'Apache restarted.' -ForegroundColor Green }
        else { Write-Warning 'Restart Apache through WAMP if PHP caching is enabled.' }
    } catch { Write-Warning 'Restart Apache through WAMP, or run this script as Administrator to restart its service.' }
}

$entries | ForEach-Object { [PSCustomObject]@{ File = $_.File; Installed = ((Get-FileHash -LiteralPath $_.Target -Algorithm SHA256).Hash -eq $_.Sha256) } } | Format-Table -AutoSize
Write-Host ('Workspace experience ' + $manifest.version + ' files installed. Open Dashboard and press Ctrl+F5.') -ForegroundColor Green
if (!$NoBrowser) {
    $separator = if ($OpenUrl.Contains('?')) { '&' } else { '?' }
    Start-Process ($OpenUrl + $separator + 'reload=' + [DateTimeOffset]::UtcNow.ToUnixTimeSeconds())
}
