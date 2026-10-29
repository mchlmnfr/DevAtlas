<?php

/**
 * Filename: LocalSecrets.php
 * Purpose: Read legacy encrypted secrets for migration and recovery without exposing their values.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Separated connection passwords and the existing vault key from source configuration.
 */
final class LocalSecrets
{
    private static ?array $values = null;
    private const CIPHER = 'aes-256-gcm';

    public static function Encode(array $values, string $key): array
    {
        if (strlen($key) !== 32)
        {
            throw new InvalidArgumentException('A 32-byte configuration key is required.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(json_encode($values, JSON_THROW_ON_ERROR), self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false)
        {
            throw new RuntimeException('Configuration encryption failed.');
        }
        return ['version' => 1, 'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'ciphertext' => base64_encode($ciphertext)];
    }

    public static function Decode(array $envelope, string $key): array
    {
        if (($envelope['version'] ?? null) !== 1 || strlen($key) !== 32)
        {
            throw new RuntimeException('Invalid encrypted configuration.');
        }
        $iv = base64_decode((string)($envelope['iv'] ?? ''), true);
        $tag = base64_decode((string)($envelope['tag'] ?? ''), true);
        $ciphertext = base64_decode((string)($envelope['ciphertext'] ?? ''), true);
        if ($iv === false || strlen($iv) !== 12 || $tag === false || strlen($tag) !== 16 || $ciphertext === false)
        {
            throw new RuntimeException('Invalid encrypted configuration.');
        }
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false)
        {
            throw new RuntimeException('Configuration authentication failed.');
        }
        $values = json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($values))
        {
            throw new RuntimeException('Invalid configuration values.');
        }
        return $values;
    }

    public static function Get(string $name): string
    {
        if (self::$values === null)
        {
            $keyHex = getenv('DEVATLAS_CONFIG_KEY');
            $key = $keyHex !== false ? (ctype_xdigit($keyHex) && strlen($keyHex) === 64 ? hex2bin($keyHex) : '') : @file_get_contents(__DIR__ . '/.local-key');
            $file = getenv('DEVATLAS_SECRETS_FILE') ?: __DIR__ . '/.local-secrets.json';
            if (!is_string($key) || strlen($key) !== 32 || !is_readable($file))
            {
                throw new RuntimeException('Installation secrets are unavailable. Restore the protected configuration files.');
            }
            self::$values = self::Decode(json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR), $key);
        }
        if (!is_string(self::$values[$name] ?? null))
        {
            throw new RuntimeException('Required installation setting is missing.');
        }
        return self::$values[$name];
    }
}
