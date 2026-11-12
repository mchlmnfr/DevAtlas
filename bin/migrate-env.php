<?php

/**
 * Filename: migrate-env.php
 * Purpose: Move legacy encrypted installation secrets into a new, private .env file.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Preserve the exact vault key and passwords without printing them or overwriting .env.
 */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/LocalSecrets.php';
$file = __DIR__ . '/../.env';
$handle = null;
try
{
    if (file_exists($file) || is_link($file))
    {
        throw new RuntimeException('An environment file already exists.');
    }
    $template = file_get_contents(__DIR__ . '/../env');
    if ($template === false)
    {
        throw new RuntimeException('The environment template is unavailable.');
    }
    $parser = new CodeIgniter\Config\DotEnv(dirname(__DIR__));
    foreach (['DB_PASS', 'TARGET_DB_PASS', 'APP_ENCRYPTION_KEY'] as $name)
    {
        $value = LocalSecrets::Get($name);
        if (strpbrk($value, "\r\n\0") !== false || ($name === 'APP_ENCRYPTION_KEY' && $value === ''))
        {
            throw new RuntimeException('A legacy value cannot be represented safely.');
        }
        $quoted = "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
        if ($parser->normaliseVariable($name, $quoted)[1] !== $value)
        {
            throw new RuntimeException('A legacy value would change during dotenv parsing.');
        }
        $count = 0;
        $template = preg_replace_callback('/^' . $name . '\s*=.*$/m', static fn () => $name . ' = ' . $quoted, $template, -1, $count);
        if ($count !== 1)
        {
            throw new RuntimeException('The environment template does not match the installation.');
        }
    }
    $mask = umask(0077);
    $handle = fopen($file, 'x');
    umask($mask);
    if ($handle === false || fwrite($handle, $template) !== strlen($template))
    {
        throw new RuntimeException('The environment file could not be created.');
    }
    fclose($handle);
    $handle = null;
    echo ".env created with existing passwords and vault key preserved. Review the non-secret connection and application settings. Legacy files were retained.\n";
}
catch (Throwable $error)
{
    if (is_resource($handle))
    {
        fclose($handle);
        unlink($file);
    }
    fwrite(STDERR, ".env was not created. Check legacy settings, permissions and template formatting; an existing .env is never overwritten.\n");
    exit(1);
}
