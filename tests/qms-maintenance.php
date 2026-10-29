<?php

/**
 * Filename: qms-maintenance.php
 * Purpose: Verify matching migration logs and safe encrypted configuration updates with isolated fixtures.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Added success, failure, secret-redaction and partial-update regression checks.
 */

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
require_once __DIR__ . '/../lib/MigrationRunner.php';
require_once __DIR__ . '/../config/LocalSecrets.php';
$checks = 0;
function Verify(bool $isValid, string $message): void
{
    global $checks;
    if (!$isValid)
    {
        throw new RuntimeException($message);
    }
    $checks++;
}
function Configure(array $values, array $environment): array
{
    $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/configure-secrets.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
    fwrite($pipes[0], json_encode($values, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => $output . $error];
}
$fixture = __DIR__ . '/.tmp-qms-maintenance-' . bin2hex(random_bytes(6));
mkdir($fixture);
file_put_contents($fixture . '/.htaccess', "Require all denied\n");
try
{
    $connection = new BaseConnection('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $script = $fixture . '/fixture.sql';
    file_put_contents($script, 'CREATE TABLE fixture (id INTEGER);');
    MigrationRunner::Run($connection, $script, $fixture);
    $events = array_map(static fn ($line) => json_decode($line, true), file($fixture . '/fixture.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    Verify(array_column($events, 'event') === ['started', 'completed'], 'A successful script writes its matching named log.');
    Verify($events[0]['sha256'] === hash_file('sha256', $script), 'The migration log identifies the executed script checksum.');
    Verify(Database::Scalar($connection->query("SELECT COUNT(*) FROM sqlite_master WHERE name='fixture'")) == 1, 'Migration logging preserves script execution.');
    file_put_contents($script, 'INVALID SECRET_SENTINEL_SQL');
    try
    {
        MigrationRunner::Run($connection, $script, $fixture);
        throw new LogicException('Invalid SQL was accepted.');
    }
    catch (DatabaseException $error)
    {
        $checks++;
    }
    $log = file_get_contents($fixture . '/fixture.log');
    Verify(str_contains($log, '"event":"failed"'), 'A failed migration records its failure.');
    Verify(!str_contains($log, 'SECRET_SENTINEL_SQL'), 'Migration logs exclude SQL contents and error values.');
    $key = random_bytes(32);
    $environment = array_replace(getenv(), ['DEVATLAS_CONFIG_KEY' => bin2hex($key), 'DEVATLAS_SECRETS_FILE' => $fixture . '/settings.json']);
    $values = ['DB_PASS' => bin2hex(random_bytes(12)), 'TARGET_DB_PASS' => bin2hex(random_bytes(12)), 'APP_ENCRYPTION_KEY' => bin2hex(random_bytes(32))];
    $result = Configure($values, $environment);
    Verify($result['exit'] === 0, 'CLI utility initializes encrypted installation settings.');
    $initial = json_decode(file_get_contents($fixture . '/settings.json'), true);
    Verify(LocalSecrets::Decode($initial, $key) === $values, 'Encrypted CLI settings round trip exactly.');
    $newPassword = bin2hex(random_bytes(12));
    $result = Configure(['DB_PASS' => $newPassword], $environment);
    $next = LocalSecrets::Decode(json_decode(file_get_contents($fixture . '/settings.json'), true), $key);
    Verify($result['exit'] === 0 && $next['DB_PASS'] === $newPassword, 'A partial configuration update changes the requested value.');
    Verify($next['TARGET_DB_PASS'] === $values['TARGET_DB_PASS'] && $next['APP_ENCRYPTION_KEY'] === $values['APP_ENCRYPTION_KEY'], 'Partial updates preserve the target password and vault key.');
    Verify(!str_contains($result['output'], $newPassword), 'The CLI never echoes a supplied secret.');
    $before = file_get_contents($fixture . '/settings.json');
    $result = Configure(['APP_ENCRYPTION_KEY' => bin2hex(random_bytes(32))], $environment);
    Verify($result['exit'] !== 0 && file_get_contents($fixture . '/settings.json') === $before, 'Configuration updates cannot invalidate the existing vault key.');
    $weakEnvironment = array_replace($environment, ['DEVATLAS_SECRETS_FILE' => $fixture . '/weak.json']);
    $result = Configure(array_replace($values, ['APP_ENCRYPTION_KEY' => 'short']), $weakEnvironment);
    Verify($result['exit'] !== 0 && !is_file($fixture . '/weak.json'), 'A new installation rejects a short vault key before saving.');
    $environment['DEVATLAS_CONFIG_KEY'] = bin2hex(random_bytes(32));
    $result = Configure(['DB_PASS' => 'rejected-value'], $environment);
    Verify($result['exit'] !== 0 && file_get_contents($fixture . '/settings.json') === $before, 'Wrong-key updates fail without changing the saved configuration.');
}
finally
{
    if (realpath(dirname($fixture)) !== realpath(__DIR__) || !str_starts_with(basename($fixture), '.tmp-qms-maintenance-'))
    {
        throw new RuntimeException('Invalid fixture cleanup path.');
    }
    foreach (new DirectoryIterator($fixture) as $file)
    {
        if (!$file->isDot() && $file->isFile())
        {
            unlink($file->getPathname());
        }
    }
    rmdir($fixture);
}
echo "QMS maintenance: $checks assertions passed.\n";
