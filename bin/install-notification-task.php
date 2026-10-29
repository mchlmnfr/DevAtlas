<?php

/**
 * Filename: install-notification-task.php
 * Purpose: Provide install notification task processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Native Windows task installation; no PowerShell execution-policy changes. */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
if (PHP_OS_FAMILY !== 'Windows')
{
    fwrite(STDERR, "Use cron to run bin/notifications.php --deliver every 15 minutes.\n");
    exit(1);
}
$root = realpath(__DIR__.'/..');
$windows = getenv('SystemRoot') ?: getenv('WINDIR');
if (!$windows)
{
    throw new RuntimeException('The Windows system directory is unavailable.');
}
$php = is_file(PHP_BINDIR . DIRECTORY_SEPARATOR . 'php-win.exe') ? PHP_BINDIR . DIRECTORY_SEPARATOR . 'php-win.exe' : PHP_BINARY;
if (!is_file($php))
{
    throw new RuntimeException('The hidden PHP executable was not found.');
}
function TaskCommand(array $localCommand): array
{
    $process = proc_open($localCommand, [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes, null, null, ['bypass_shell' => true,'create_no_window' => true]);
    if (!is_resource($process))
    {
        throw new RuntimeException('Task Scheduler command could not start.');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process),$out,$error];
}
[$code,$who,$error] = TaskCommand([$windows.'\\System32\\whoami.exe','/user','/fo','csv','/nh']);
if ($code || !preg_match('/S-1-(?:\d+-)*\d+/', $who, $match))
{
    fwrite(STDERR, "Could not determine the current Windows task principal.\n");
    exit(1);
}
$sid = $match[0];
$logon = in_array('--interactive', $argv, true) ? 'InteractiveToken' : 'S4U';
$xmlEscape = static fn ($s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$start = (new DateTimeImmutable('+1 minute'))->format('Y-m-d\TH:i:s');
$command = $xmlEscape($php);
$arguments = $xmlEscape('"'.$root.'\\bin\\notifications.php" --deliver');
$working = $xmlEscape($root);
$xml = <<<XML
<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
<RegistrationInfo><Description>DevAtlas reminders and optional email delivery, every 15 minutes.</Description></RegistrationInfo>
<Triggers><TimeTrigger><Repetition><Interval>PT15M</Interval><StopAtDurationEnd>false</StopAtDurationEnd></Repetition><StartBoundary>{$start}</StartBoundary><Enabled>true</Enabled></TimeTrigger></Triggers>
<Principals><Principal id="Author"><UserId>{$sid}</UserId><LogonType>{$logon}</LogonType><RunLevel>LeastPrivilege</RunLevel></Principal></Principals>
<Settings><MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy><DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries><StopIfGoingOnBatteries>false</StopIfGoingOnBatteries><StartWhenAvailable>true</StartWhenAvailable><Enabled>true</Enabled><Hidden>true</Hidden><ExecutionTimeLimit>PT14M</ExecutionTimeLimit></Settings>
<Actions Context="Author"><Exec><Command>{$command}</Command><Arguments>{$arguments}</Arguments><WorkingDirectory>{$working}</WorkingDirectory></Exec></Actions>
</Task>
XML;
$file = tempnam(__DIR__, '.notification-task-');
try
{
    file_put_contents($file, "\xFF\xFE".mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'));
    [$code,$out,$error] = TaskCommand([$windows.'\\System32\\schtasks.exe','/Create','/TN','AnalystToolkit Notifications','/XML',$file,'/F']);
    fwrite($code ? STDERR : STDOUT, $out.$error);
}
finally
{
    if (is_file($file))
    {
        unlink($file);
    }
}
exit($code);
