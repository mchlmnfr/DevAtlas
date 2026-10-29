<?php

/**
 * Filename: configure-secrets.php
 * Purpose: Initialize or update encrypted installation settings from standard input.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Added a CLI-only configuration utility that preserves unmodified secrets and the existing key.
 */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/LocalSecrets.php';
try
{
    $values = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($values))
    {
        throw new InvalidArgumentException('Supply a JSON object on standard input.');
    }
    $names = ['DB_PASS', 'TARGET_DB_PASS', 'APP_ENCRYPTION_KEY'];
    foreach ($values as $name => $value)
    {
        if (!in_array($name, $names, true) || !is_string($value))
        {
            throw new InvalidArgumentException('Only the three named installation secrets are accepted.');
        }
    }
    $keyFile = __DIR__ . '/../config/.local-key';
    $secretFile = getenv('DEVATLAS_SECRETS_FILE') ?: __DIR__ . '/../config/.local-secrets.json';
    $keyHex = getenv('DEVATLAS_CONFIG_KEY');
    $key = $keyHex !== false ? (ctype_xdigit($keyHex) && strlen($keyHex) === 64 ? hex2bin($keyHex) : '') : (is_file($keyFile) ? file_get_contents($keyFile) : random_bytes(32));
    if (!is_string($key) || strlen($key) !== 32)
    {
        throw new InvalidArgumentException('The configuration key must be 32 bytes.');
    }
    $prior = is_file($secretFile) ? LocalSecrets::Decode(json_decode(file_get_contents($secretFile), true, 16, JSON_THROW_ON_ERROR), $key) : [];
    $next = array_replace($prior, $values);
    foreach ($names as $name)
    {
        if (!is_string($next[$name] ?? null))
        {
            throw new InvalidArgumentException('Supply all three secrets for a new installation.');
        }
    }
    if (!$prior && strlen($next['APP_ENCRYPTION_KEY']) < 32)
    {
        throw new InvalidArgumentException('A new installation requires a vault key of at least 32 bytes.');
    }
    if (isset($prior['APP_ENCRYPTION_KEY']) && !hash_equals($prior['APP_ENCRYPTION_KEY'], $next['APP_ENCRYPTION_KEY']))
    {
        throw new InvalidArgumentException('An existing vault key requires a separate credential migration.');
    }
    $envelope = LocalSecrets::Encode($next, $key);
    if (LocalSecrets::Decode($envelope, $key) !== $next)
    {
        throw new RuntimeException('Configuration verification failed.');
    }
    if ($keyHex === false && !is_file($keyFile))
    {
        $handle = fopen($keyFile, 'xb');
        if ($handle === false || fwrite($handle, $key) !== 32)
        {
            throw new RuntimeException('The configuration key could not be stored.');
        }
        fclose($handle);
        chmod($keyFile, 0600);
    }
    $temporary = $secretFile . '.new-' . bin2hex(random_bytes(4));
    if (file_put_contents($temporary, json_encode($envelope, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false)
    {
        throw new RuntimeException('Configuration could not be stored.');
    }
    chmod($temporary, 0600);
    if (!rename($temporary, $secretFile))
    {
        unlink($temporary);
        throw new RuntimeException('Configuration could not be replaced.');
    }
    echo "Encrypted installation configuration saved.\n";
}
catch (Throwable $error)
{
    fwrite(STDERR, "Configuration was not saved. Check the input, encryption key and protected file permissions.\n");
    exit(1);
}
