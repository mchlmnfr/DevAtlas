<?php

/**
 * Filename: config-secrets.php
 * Purpose: Verify authenticated configuration encryption, tamper rejection and existing vault compatibility.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Added configuration secrecy and compatibility regression checks.
 */
require_once __DIR__ . '/../config/LocalSecrets.php';
$key = random_bytes(32);
$values = ['sample' => bin2hex(random_bytes(20))];
$envelope = LocalSecrets::Encode($values, $key);
if (LocalSecrets::Decode($envelope, $key) !== $values)
{
    throw new RuntimeException('Configuration round trip failed.');
}
if (str_contains(json_encode($envelope), $values['sample']))
{
    throw new RuntimeException('Secret appears in serialized configuration.');
}
$checks = 2;
foreach (['key', 'iv', 'tag', 'ciphertext'] as $part)
{
    $changed = $envelope;
    $otherKey = $key;
    if ($part === 'key')
    {
        $otherKey = random_bytes(32);
    }
    else
    {
        $raw = base64_decode($changed[$part]);
        $raw[0] = chr(ord($raw[0]) ^ 1);
        $changed[$part] = base64_encode($raw);
    }
    try
    {
        LocalSecrets::Decode($changed, $otherKey);
        throw new LogicException('Tampering accepted.');
    }
    catch (RuntimeException $error)
    {
        $checks++;
    }
}
session_set_save_handler(static fn () => true, static fn () => true, static fn () => '', static fn () => true, static fn () => true, static fn () => 0);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Crypto.php';
$plaintext = bin2hex(random_bytes(20));
if (Crypto::Decrypt(Crypto::Encrypt($plaintext)) !== $plaintext)
{
    throw new RuntimeException('Existing vault encryption compatibility failed.');
}
$checks++;
echo "Configuration secrets: $checks assertions passed.\n";
