<?php

/**
 * Filename: log-viewer.php
 * Purpose: Verify log viewer behavior using isolated regression fixtures.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** CLI fixtures for bounded daily-log reading and plain-language presentation. */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
require_once __DIR__.'/../lib/Logger.php';
require_once __DIR__.'/../lib/LogPresenter.php';
set_error_handler(static function ($severity, $message, $file, $line)
{
    if (error_reporting() & $severity)
    {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
$assertions = 0;
function LgCheck(bool $isCondition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$isCondition)
    {
        throw new RuntimeException($message);
    }
}
$directory = __DIR__.'/.tmp-log-reader-'.bin2hex(random_bytes(5));
mkdir($directory);
$property = new ReflectionProperty(Logger::class, 'directory');
$property->setAccessible(true);
$property->setValue(null, $directory);
try
{
    foreach (['app-2026-10-25.log','app-2024-02-29.log'] as $name)
    {
        LgCheck(Logger::ValidName($name), 'Daily filename accepted.');
    }
    foreach (['.htaccess','../app-2026-10-25.log','app-2026-02-30.log','app-2026-10-25.log.exe','other.log','app-0000-01-01.log'] as $name)
    {
        LgCheck(!Logger::ValidName($name), 'Non-log or invalid date rejected.');
    }
    Logger::Log('info', 'Fixture event', ['module' => 'test']);
    LgCheck(count(Logger::ListFiles()) === 1, 'Logger writer still creates daily files.');
    Logger::ClearFile(Logger::ListFiles()[0]);
    $file = $directory.'/app-2026-10-24.log';
    $handle = fopen($file, 'wb');
    for ($i = 0;$i < 2500;$i++)
    {
        fwrite($handle, json_encode(['time' => '2026-10-24T10:00:00+08:00','level' => 'WARNING','message' => 'Fixture '.$i,'context' => ['ordinal' => $i]])."\n");
    }
    fclose($handle);
    $report = Logger::ReadReport('app-2026-10-24.log', 500);
    LgCheck($report['returned'] === 500 && $report['has_more'], 'Preview count and older-entry indication.');
    LgCheck($report['entries'][0]['message'] === 'Fixture 2499' && $report['entries'][499]['message'] === 'Fixture 2000', 'Newest records returned in reverse file order.');
    LgCheck($report['preview_bytes'] < $report['size'], 'Tail reader does not load an entire large file.');
    LgCheck(Logger::ReadReport('app-2026-10-24.log', 9000)['returned'] === 2000, 'Server limit cannot exceed 2,000.');
    file_put_contents($directory.'/app-2026-10-23.log', "not json\n{\"message\":\"No newline\",\"level\":\"INFO\"}");
    $report = Logger::ReadReport('app-2026-10-23.log');
    LgCheck($report['returned'] === 2 && !$report['has_more'], 'No final newline handled.');
    LgCheck($report['entries'][1]['level'] === 'UNKNOWN' && !$report['entries'][1]['structured'], 'Unstructured lines retained.');
    file_put_contents($directory.'/app-2026-10-22.log', '');
    LgCheck(Logger::ReadReport('app-2026-10-22.log')['returned'] === 0, 'Empty file handled.');
    file_put_contents($directory.'/app-2026-10-21.log', str_repeat('x', 4300000));
    $report = Logger::ReadReport('app-2026-10-21.log');
    LgCheck($report['preview_limited'] && $report['preview_bytes'] <= 4194304 && $report['returned'] === 0, 'Oversized single line is bounded and disclosed.');
    LgCheck(!Logger::ClearFile('.htaccess') && is_file($directory.'/.htaccess'), 'Clear cannot remove the access-control file.');
    LgCheck(Logger::PathFor('../app-2026-10-24.log') === null, 'Traversal rejected.');
    LgCheck(Logger::ClearFile('app-2026-10-22.log') && !is_file($directory.'/app-2026-10-22.log'), 'Explicit daily-file clearing works.');
    LgCheck(count(Logger::FileCatalog()) === 3, 'File catalog counts actual daily files.');
    $base = ['time' => '2026-10-25T10:00:00+08:00','level' => 'WARNING','message' => 'Invalid or missing CSRF token.','context' => ['status' => 419,'method' => 'POST','uri' => '/AnalystToolkit/modules/datadict/api.php?action=save_column&password=fixture-secret']];
    $presented = LogPresenter::Present($base);
    LgCheck($presented['category'] === 'Access & session' && $presented['module'] === 'Data Dictionary', 'Contextual module and session explanation.');
    LgCheck($presented['route'] === '/AnalystToolkit/modules/datadict/api.php?action=save_column', 'Display route excludes credential query values.');
    LgCheck(!str_contains(json_encode($presented), 'fixture-secret'), 'Known URL credentials redacted.');
    $differentTime = $base;
    $differentTime['time'] = '2026-10-25T11:00:00+08:00';
    LgCheck(LogPresenter::Present($differentTime)['group_id'] === $presented['group_id'], 'Repeated events group across timestamps.');
    $differentAction = $base;
    $differentAction['context']['uri'] = '/AnalystToolkit/modules/datadict/api.php?action=delete_table';
    LgCheck(LogPresenter::Present($differentAction)['group_id'] !== $presented['group_id'], 'Different request actions remain separate.');
    foreach ([
        ['Undefined constant "APP_ENCRYPTION_KEY"',0,'Configuration'],['Driver unavailable on this server.',400,'Configuration'],
        ['Permission denied',0,'Server files'],['SQLSTATE[23505]: duplicate key',0,'Input & records'],
        ['SQLSTATE[42703]: undefined column',0,'Database'],['Connection failed.',500,'Database'],['Operation timed out',0,'Connection'],
        ['Undefined array key x',0,'Application code'],['Not found.',404,'Input & records'],['POST required.',405,'Request'],
        ['Invalid input.',400,'Input & records'],['Your session has expired.',401,'Access & session'],['Forbidden.',403,'Access & session'],
    ] as [$message,$status,$category])
    {
        $entry = $base;
        $entry['message'] = $message;
        $entry['context']['status'] = $status;
        LgCheck(LogPresenter::Present($entry)['category'] === $category, 'Expected explanation category: '.$category);
    }
    $entry = ['level' => 'FATAL','message' => 'Unexpected failure','context' => ['file' => 'C:\\app\\modules\\servers\\api.php','line' => 10]];
    $result = LogPresenter::Present($entry);
    LgCheck($result['severity'] === 'error' && $result['severity_label'] === 'Critical error' && $result['module'] === 'Server Inventory', 'Fatal/source-file context shown clearly.');
    LgCheck(LogPresenter::Present(['level' => 'INFO','message' => 'Test log entry created from the Error Logs page.'])['title'] === 'Logging check completed', 'Diagnostic entries explained.');
    LgCheck(LogPresenter::Present(['level' => 'UNKNOWN','message' => '<script>alert(1)</script>','structured' => false])['severity'] === 'other', 'Unknown record severity is not invented.');
    $secret = LogPresenter::Present(['level' => 'ERROR','message' => 'password="fixture-secret" Authorization: Bearer hidden-bearer','context' => ['password' => 'hidden-pass','nested' => ['api_key' => 'hidden-key'],'uri' => '/route?action=test&email=hidden-address']]);
    foreach (['fixture-secret','hidden-bearer','hidden-pass','hidden-key','hidden-address'] as $sensitive)
    {
        LgCheck(!str_contains(json_encode($secret), $sensitive), 'Credential field redacted.');
    }
    LgCheck(str_contains(LogPresenter::CleanText('pgsql://user:password123@host/db'), '[redacted]@'), 'Connection-URI credentials redacted.');
    LgCheck(!str_contains(LogPresenter::CleanText('token=hidden-message-token'), 'hidden-message-token'), 'Bare token assignments redacted.');
    LgCheck(mb_strlen(LogPresenter::CleanText(str_repeat('x', 17000))) < 16100, 'Very long display messages bounded.');
    $nested = [];
    for ($i = 0;$i < 10;$i++)
    {
        $nested = ['nested' => $nested];
    }
    LgCheck(str_contains(json_encode(LogPresenter::Redact($nested)), 'shortened'), 'Nested context bounded.');
    echo "Log viewer fixtures: {$assertions} assertions passed.\n";
}
finally
{
    $property->setValue(null, null);
    foreach (new DirectoryIterator($directory) as $file)
    {
        if (!$file->isDot() && $file->isFile())
        {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
}
