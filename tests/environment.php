<?php

/**
 * Filename: environment.php
 * Purpose: Verify dotenv loading, framework configuration and lossless legacy migration.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Cover CLI bootstrap, secret string handling and refusal to overwrite existing .env files.
 */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/LocalSecrets.php';
require_once __DIR__ . '/../vendor/autoload.php';
$root = dirname(__DIR__);
$fixture = __DIR__ . '/.tmp-environment-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
$checks = 0;
function EnvCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition)
    {
        throw new RuntimeException($message);
    }
}
function EnvRun(array $command): array
{
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'output' => $output . $error];
}
try
{
    $template = file_get_contents($root . '/env');
    file_put_contents($fixture . '/.env', $template . <<<'ENV'

ENV_TEST_PASSWORD = 'false'
ENV_TEST_NULL = 'null'
ENV_TEST_EMPTY = ''
ENV_TEST_FLAG = false
ENV_TEST_BAD_FLAG = 'invalid-boolean-sentinel'
ENV_TEST_ORIGIN = 'https://fixture.example.invalid'
ENV_TEST_NESTED = "${ENV_TEST_ORIGIN}/path/"
ENV_TEST_OVERRIDE = 'file-value'
ENV
    );
    $loader = $fixture . '/loader.php';
    file_put_contents($loader, '<?php require ' . var_export($root . '/config/Environment.php', true) . ';' . <<<'PHP'
putenv('ENV_TEST_OVERRIDE=process-value');
Environment::Load(__DIR__);
$results = [
    Environment::Get('ENV_TEST_PASSWORD') === 'false',
    Environment::Get('ENV_TEST_NULL') === 'null',
    Environment::Get('ENV_TEST_EMPTY', true) === '',
    Environment::Boolean('ENV_TEST_FLAG') === false,
    Environment::Get('ENV_TEST_OVERRIDE') === 'process-value',
    Environment::Get('ENV_TEST_NESTED') === 'https://fixture.example.invalid/path/',
];
foreach (['ENV_TEST_MISSING', 'ENV_TEST_EMPTY', 'ENV_TEST_BAD_FLAG'] as $name)
{
    try
    {
        $name === 'ENV_TEST_BAD_FLAG' ? Environment::Boolean($name) : Environment::Get($name);
        $results[] = false;
    }
    catch (RuntimeException $error)
    {
        $results[] = str_contains($error->getMessage(), $name) && !str_contains($error->getMessage(), 'invalid-boolean-sentinel');
    }
}
Environment::Load('/a/directory/that/does/not/exist');
$results[] = Environment::Get('ENV_TEST_OVERRIDE') === 'process-value';
echo json_encode($results, JSON_THROW_ON_ERROR);
PHP
    );
    $result = EnvRun([PHP_BINARY, $loader]);
    EnvCheck($result['code'] === 0, 'The standalone dotenv loader must run.');
    foreach (json_decode($result['output'], true, 16, JSON_THROW_ON_ERROR) as $value)
    {
        EnvCheck($value === true, 'Dotenv string, override, interpolation or validation behavior changed.');
    }

    // Exercise real application constants and native CodeIgniter config without a DB connection.
    $configuration = str_replace("APP_ENCRYPTION_KEY = ''", "APP_ENCRYPTION_KEY = 'environment-fixture-vault-key-32-bytes'", $template);
    $configuration = str_replace('CI_ENVIRONMENT = production', 'CI_ENVIRONMENT = development', $configuration);
    $configuration = str_replace('DB_DEBUG = true', 'DB_DEBUG = false', $configuration);
    $configuration = str_replace("DB_PASS = ''", "DB_PASS = 'false'", $configuration);
    $configuration = str_replace("APP_ORIGIN = 'http://localhost'", "APP_ORIGIN = 'https://fixture.example.invalid'", $configuration);
    $configuration .= "\ndatabase.default.port = 6543\n";
    file_put_contents($fixture . '/.env', $configuration);
    file_put_contents($fixture . '/bootstrap.php', '<?php require ' . var_export($root . '/config/Environment.php', true) . '; Environment::Load(__DIR__); require ' . var_export($root . '/lib/Framework.php', true) . '; Framework::Boot();' . <<<'PHP'
$app = config('App');
$db = config('Database');
echo json_encode([
    ENVIRONMENT === 'development',
    DB_PASS === 'false',
    TARGET_DB_PASS === 'false',
    $db->default['password'] === DB_PASS,
    $db->target['password'] === TARGET_DB_PASS,
    $db->default['DBDebug'] === false,
    $db->default['port'] === 6543,
    $app->baseURL === 'https://fixture.example.invalid/AnalystToolkit/',
    $app->appTimezone === APP_TIMEZONE,
    APP_ENCRYPTION_KEY === 'environment-fixture-vault-key-32-bytes',
], JSON_THROW_ON_ERROR);
PHP
    );
    $result = EnvRun([PHP_BINARY, $fixture . '/bootstrap.php']);
    EnvCheck($result['code'] === 0, 'Standalone framework bootstrap must load the selected dotenv configuration.');
    foreach (json_decode($result['output'], true, 16, JSON_THROW_ON_ERROR) as $value)
    {
        EnvCheck($value === true, 'Application and native framework configuration must agree.');
    }

    // Run the actual migration entry point in an isolated installation skeleton.
    $installation = $fixture . '/installation';
    foreach (['bin', 'config', 'vendor'] as $directory)
    {
        mkdir($installation . '/' . $directory, 0700, true);
    }
    copy($root . '/bin/migrate-env.php', $installation . '/bin/migrate-env.php');
    copy($root . '/config/LocalSecrets.php', $installation . '/config/LocalSecrets.php');
    copy($root . '/env', $installation . '/env');
    file_put_contents($installation . '/vendor/autoload.php', '<?php require ' . var_export($root . '/vendor/autoload.php', true) . ';');
    $key = random_bytes(32);
    $secrets = ['DB_PASS' => "quote' slash\\ # spaces \" dollar$", 'TARGET_DB_PASS' => '', 'APP_ENCRYPTION_KEY' => bin2hex(random_bytes(32))];
    $envelope = json_encode(LocalSecrets::Encode($secrets, $key), JSON_THROW_ON_ERROR);
    file_put_contents($installation . '/config/.local-key', $key);
    file_put_contents($installation . '/config/.local-secrets.json', $envelope);
    $command = [PHP_BINARY, $installation . '/bin/migrate-env.php'];
    $result = EnvRun($command);
    EnvCheck($result['code'] === 0, 'Legacy migration must create .env.');
    $saved = file_get_contents($installation . '/.env');
    $parser = new CodeIgniter\Config\DotEnv($installation);
    foreach ($secrets as $name => $value)
    {
        preg_match('/^' . $name . '\s*=.*$/m', $saved, $matches);
        EnvCheck(isset($matches[0]) && $parser->normaliseVariable($matches[0])[1] === $value, 'Migration must preserve each secret exactly.');
        EnvCheck($value === '' || !str_contains($result['output'], $value), 'Migration output must not disclose secrets.');
    }
    if (DIRECTORY_SEPARATOR === '/')
    {
        EnvCheck((fileperms($installation . '/.env') & 0777) === 0600, 'The generated .env must have owner-only permissions.');
    }
    EnvCheck(file_get_contents($installation . '/config/.local-key') === $key && file_get_contents($installation . '/config/.local-secrets.json') === $envelope, 'Migration must preserve legacy recovery files.');
    $result = EnvRun($command);
    EnvCheck($result['code'] !== 0 && file_get_contents($installation . '/.env') === $saved, 'Migration must never overwrite an existing .env.');
    unlink($installation . '/.env');
    $secrets['DB_PASS'] = "cannot\nspan-lines";
    file_put_contents($installation . '/config/.local-secrets.json', json_encode(LocalSecrets::Encode($secrets, $key), JSON_THROW_ON_ERROR));
    $result = EnvRun($command);
    EnvCheck($result['code'] !== 0 && !file_exists($installation . '/.env'), 'Unrepresentable values must fail without writing a partial .env.');
}
finally
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file)
    {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($fixture);
}
echo "Environment configuration: $checks assertions passed.\n";
