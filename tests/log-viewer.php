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
require_once __DIR__ . '/../lib/Framework.php';
Framework::Boot();
$config = config('Logger');
$originalThreshold = $config->threshold;
$originalDateFormat = $config->dateFormat;
$originalHandlers = $config->handlers;
$config->threshold = 9;
$config->dateFormat = 'Y-m-d H:i:s';
file_put_contents($directory . '/.htaccess', "Require all denied\n");
try
{
    foreach (['log-2026-10-25.log','log-2024-02-29.log'] as $name)
    {
        LgCheck(Logger::ValidName($name), 'Daily filename accepted.');
    }
    foreach (['.htaccess','../log-2026-10-25.log','log-2026-02-30.log','log-2026-10-25.log.exe','other.log','log-0000-01-01.log'] as $name)
    {
        LgCheck(!Logger::ValidName($name), 'Non-log or invalid date rejected.');
    }
    Logger::Log('info', 'Fixture event', ['module' => 'test']);
    LgCheck(count(Logger::ListFiles()) === 1, 'Logger writer still creates daily files.');
    Logger::ClearFile(Logger::ListFiles()[0]);
    $file = $directory.'/log-2026-10-24.log';
    $handle = fopen($file, 'wb');
    for ($i = 0;$i < 2500;$i++)
    {
        fwrite($handle, 'WARNING - 2026-10-24 10:00:00 --> Fixture ' . $i . "\n");
    }
    fclose($handle);
    $report = Logger::ReadReport('log-2026-10-24.log', 500);
    LgCheck($report['returned'] === 500 && $report['has_more'], 'Preview count and older-entry indication.');
    LgCheck($report['entries'][0]['message'] === 'Fixture 2499' && $report['entries'][499]['message'] === 'Fixture 2000', 'Newest records returned in reverse file order.');
    LgCheck($report['preview_bytes'] < $report['size'], 'Tail reader does not load an entire large file.');
    LgCheck(Logger::ReadReport('log-2026-10-24.log', 9000)['returned'] === 2000, 'Server limit cannot exceed 2,000.');
    file_put_contents($directory.'/log-2026-10-23.log', "not a framework event\nINFO - 2026-10-23 10:00:00 --> No newline");
    $report = Logger::ReadReport('log-2026-10-23.log');
    LgCheck($report['returned'] === 2 && !$report['has_more'], 'No final newline handled.');
    LgCheck($report['entries'][1]['level'] === 'UNKNOWN' && !$report['entries'][1]['structured'], 'Unstructured lines retained.');
    file_put_contents($directory.'/log-2026-10-22.log', '');
    LgCheck(Logger::ReadReport('log-2026-10-22.log')['returned'] === 0, 'Empty file handled.');
    file_put_contents($directory.'/log-2026-10-21.log', str_repeat('x', 4300000));
    $report = Logger::ReadReport('log-2026-10-21.log');
    LgCheck($report['preview_limited'] && $report['preview_bytes'] <= 4194304 && $report['returned'] === 0, 'Oversized single line is bounded and disclosed.');
    LgCheck(!Logger::ClearFile('.htaccess') && is_file($directory.'/.htaccess'), 'Clear cannot remove the access-control file.');
    LgCheck(Logger::PathFor('../log-2026-10-24.log') === null, 'Traversal rejected.');
    LgCheck(Logger::ClearFile('log-2026-10-22.log') && !is_file($directory.'/log-2026-10-22.log'), 'Explicit daily-file clearing works.');
    LgCheck(count(Logger::FileCatalog()) === 3, 'File catalog counts actual daily files.');
    file_put_contents($directory . '/app-2026-10-20.log', '{"message":"Legacy custom event"}');
    LgCheck(!in_array('app-2026-10-20.log', Logger::ListFiles(), true), 'Legacy custom log files are excluded.');
    LgCheck(Logger::PathFor('app-2026-10-20.log') === null && !Logger::ClearFile('app-2026-10-20.log'), 'Legacy files cannot be downloaded or deleted through the framework viewer.');
    $multiline = "WARNING - 2026-10-20 09:00:00 --> Older warning\r\n"
        . "CRITICAL - 2026-10-20 10:00:00 --> RuntimeException: Connection refused\r\n"
        . "[Method: GET, Route: modules/servers/api]\r\n"
        . "in APPPATH/Controllers/ServersController.php on line 42.\r\n"
        . " 1 SYSTEMPATH/CodeIgniter.php(973): App\\Controllers\\ServersController->Index()\r\n"
        . " 2 [internal function]: dispatch()";
    file_put_contents($directory . '/log-2026-10-20.log', $multiline);
    $report = Logger::ReadReport('log-2026-10-20.log', 1);
    LgCheck($report['returned'] === 1 && $report['has_more'], 'Preview limits count complete events rather than stack frames.');
    $event = $report['entries'][0];
    LgCheck($event['level'] === 'CRITICAL' && str_contains($event['message'], 'dispatch()'), 'CRLF exception stack traces stay attached to their header.');
    LgCheck($event['context']['file'] === 'APPPATH/Controllers/ServersController.php' && $event['context']['line'] === 42, 'Framework exception source file and line are extracted.');
    LgCheck($event['context']['method'] === 'GET' && $event['context']['uri'] === '/modules/servers/api', 'Framework method and route metadata are extracted.');
    $expected = new DateTimeImmutable('2026-10-20 10:00:00', new DateTimeZone(config('App')->appTimezone));
    LgCheck($event['time'] === $expected->format('c'), 'Framework timestamps use the configured application timezone.');
    LgCheck(LogPresenter::Present($event)['module'] === 'Server Inventory', 'Native framework exceptions retain affected-area filtering.');
    LgCheck(LogPresenter::Present(['message' => 'Native exception', 'context' => ['file' => 'APPPATH/Controllers/DailylogsController.php']])['module'] === 'My Daily Logs', 'Controller source paths identify the affected area when no route was logged.');
    LgCheck(LogPresenter::Present(['message' => 'Native exception', 'context' => ['uri' => '/login']])['module'] === 'Sign in', 'Clean framework routes identify the sign-in area.');
    Logger::Log('php_warning', 'Literal {env:APP_ENCRYPTION_KEY} and {session_vars}', ['status' => 419, 'uri' => '/modules/datadict/api?action=save']);
    $event = Logger::ReadReport(Logger::CurrentFilename(), 1)['entries'][0];
    LgCheck($event['level'] === 'WARNING' && $event['context']['status'] === 419, 'Compatibility calls use native severity and retain application context.');
    LgCheck(str_contains($event['message'], '{env:APP_ENCRYPTION_KEY}') && str_contains($event['message'], '{session_vars}'), 'Message text cannot expand sensitive framework placeholders.');
    $nativeConfig = clone $config;
    $nativeConfig->handlers[CodeIgniter\Log\Handlers\FileHandler::class]['path'] = $directory . '/';
    $native = new CodeIgniter\Log\Logger($nativeConfig, false);
    $native->critical('Event from the native CodeIgniter logger');
    LgCheck(Logger::ReadReport(Logger::CurrentFilename(), 1)['entries'][0]['message'] === 'Event from the native CodeIgniter logger', 'The reader accepts real framework FileHandler output.');
    $config->threshold = 4;
    $before = file_get_contents(Logger::PathFor(Logger::CurrentFilename()));
    Logger::Log('info', 'Suppressed informational diagnostic');
    LgCheck(file_get_contents(Logger::PathFor(Logger::CurrentFilename())) === $before, 'Application logging respects the configured framework threshold.');
    $config->threshold = 9;
    file_put_contents($directory . '/log-2026-10-19.log', "CRITICAL - 2026-10-19 10:00:00 --> Huge exception\n" . str_repeat(" stack frame\n", 400000) . "ERROR - 2026-10-19 11:00:00 --> Complete newest event\n");
    $report = Logger::ReadReport('log-2026-10-19.log');
    LgCheck($report['preview_limited'] && $report['has_more'] && $report['preview_bytes'] <= 4194304, 'Multiline previews retain the byte cap and disclose omitted history.');
    LgCheck($report['returned'] === 1 && $report['entries'][0]['message'] === 'Complete newest event', 'An oversized partial stack trace is not shown as many unknown events.');
    file_put_contents($directory . '/log-2026-10-18.log', "ERROR - invalid timestamp --> Still readable\nWARNING - bad\0timestamp --> Also readable\n");
    $report = Logger::ReadReport('log-2026-10-18.log');
    LgCheck($report['returned'] === 2 && $report['entries'][0]['time'] === null && $report['entries'][1]['time'] === null, 'Malformed timestamps cannot break the entire preview.');
    if (DIRECTORY_SEPARATOR === '/')
    {
        symlink(__FILE__, $directory . '/log-2026-10-17.log');
        LgCheck(Logger::PathFor('log-2026-10-17.log') === null, 'Symlinked daily files cannot escape the configured log directory.');
    }
    // Respect the framework's custom file extension, path and timestamp format.
    $property->setValue(null, null);
    $config->handlers[CodeIgniter\Log\Handlers\FileHandler::class]['path'] = $directory . '/';
    $config->handlers[CodeIgniter\Log\Handlers\FileHandler::class]['fileExtension'] = 'php';
    $config->dateFormat = 'Y-m-d H:i:s.u';
    $native = new CodeIgniter\Log\Logger($config, false);
    $native->error('Custom framework handler event');
    $report = Logger::ReadReport(Logger::CurrentFilename());
    LgCheck(str_ends_with($report['file'], '.php') && $report['returned'] === 1, 'Configured PHP log extensions and their protection header are supported.');
    LgCheck($report['entries'][0]['time'] !== null && $report['entries'][0]['message'] === 'Custom framework handler event', 'Configured timestamp formats are parsed.');
    LgCheck(count(Logger::ListFiles()) === 1, 'The selected framework path and extension define the file catalog.');
    $config->handlers = [];
    LgCheck(Logger::ListFiles() === [] && Logger::CurrentFilename() === null, 'Non-file logger configurations do not expose unrelated files.');
    $config->handlers = $originalHandlers;
    $config->dateFormat = $originalDateFormat;
    $property->setValue(null, $directory);
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
    $config->threshold = $originalThreshold;
    $config->dateFormat = $originalDateFormat;
    $config->handlers = $originalHandlers;
    $property->setValue(null, null);
    foreach (new DirectoryIterator($directory) as $file)
    {
        if (!$file->isDot() && ($file->isFile() || $file->isLink()))
        {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
}
