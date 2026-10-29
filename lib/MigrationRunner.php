<?php

/**
 * Filename: MigrationRunner.php
 * Purpose: Execute SQL upgrades with matching named logs without adding client-only directives to PDO scripts.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Record script checksum, start, completion and failure without logging SQL or secret values.
 */

use CodeIgniter\Database\BaseConnection;
final class MigrationRunner
{
    public static function Run(BaseConnection $connection, string $script, ?string $logDirectory = null): void
    {
        $file = realpath($script);
        if (!$file || !is_file($file) || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'sql')
        {
            throw new InvalidArgumentException('Choose an existing SQL migration.');
        }
        $directory = $logDirectory ?? __DIR__ . '/../logs/migrations';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory))
        {
            throw new RuntimeException('Migration log directory is unavailable.');
        }
        $log = $directory . DIRECTORY_SEPARATOR . pathinfo($file, PATHINFO_FILENAME) . '.log';
        $metadata = ['script' => basename($file), 'sha256' => hash_file('sha256', $file)];
        self::Append($log, $metadata + ['at' => gmdate('c'), 'event' => 'started']);
        $started = microtime(true);
        try
        {
            $connection->query(file_get_contents($file));
        }
        catch (Throwable $error)
        {
            self::Append($log, $metadata + ['at' => gmdate('c'), 'event' => 'failed', 'error_type' => get_class($error)]);
            throw $error;
        }
        self::Append($log, $metadata + ['at' => gmdate('c'), 'event' => 'completed', 'duration_ms' => (int)round((microtime(true) - $started) * 1000)]);
    }

    private static function Append(string $file, array $record): void
    {
        if (file_put_contents($file, json_encode($record, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) === false)
        {
            throw new RuntimeException('Migration log could not be written.');
        }
    }
}
