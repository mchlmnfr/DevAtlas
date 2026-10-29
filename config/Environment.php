<?php

/**
 * Filename: Environment.php
 * Purpose: Load installation settings consistently for HTTP and standalone CLI entry points.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Read .env through CodeIgniter while preserving process overrides and empty passwords.
 */
final class Environment
{
    private static bool $loaded = false;

    public static function Load(?string $directory = null): void
    {
        if (self::$loaded)
        {
            return;
        }
        require_once __DIR__ . '/../vendor/autoload.php';
        (new CodeIgniter\Config\DotEnv($directory ?? dirname(__DIR__)))->load();
        self::$loaded = true;
    }

    public static function Get(string $name, bool $allowEmpty = false): string
    {
        self::Load();
        // Read raw strings: passwords such as "false" or "null" are not booleans.
        $value = getenv($name);
        if ($value === false || (!$allowEmpty && $value === ''))
        {
            throw new RuntimeException('Required environment setting ' . $name . ' is missing.');
        }
        return $value;
    }

    public static function Boolean(string $name, ?bool $default = null): bool
    {
        self::Load();
        // Defaults cover omitted optional settings; explicit empty/invalid values still fail.
        if ($default !== null && getenv($name) === false)
        {
            return $default;
        }
        $value = filter_var(self::Get($name), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null)
        {
            throw new RuntimeException('Environment setting ' . $name . ' must be a boolean.');
        }
        return $value;
    }
}
