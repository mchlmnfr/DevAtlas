# Install/update the current user's reminder task. No credentials are put in task arguments.
$ErrorActionPreference = 'Stop'
$projectPath = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$phpPath = 'C:\xampp\php\php.exe'
$workerPath = Join-Path $projectPath 'bin\notifications.php'
if (!(Test-Path -LiteralPath $phpPath) -or !(Test-Path -LiteralPath $workerPath)) { throw 'PHP or the notification worker was not found.' }
$action = New-ScheduledTaskAction -Execute 'C:\xampp\php\php-win.exe' -Argument ('"' + $workerPath + '" --deliver') -WorkingDirectory $projectPath
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 15)
$settings = New-ScheduledTaskSettingsSet -Hidden -StartWhenAvailable -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName 'AnalystToolkit Notifications' -Action $action -Trigger $trigger -Settings $settings -Description 'Generate DevAtlas reminders and deliver email when explicitly enabled in notification settings.' -Force
