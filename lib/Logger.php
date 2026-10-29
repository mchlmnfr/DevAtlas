<?php

/**
 * Filename: Logger.php
 * Purpose: Provide logger processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Daily JSON-line logs with bounded previews and strict daily-file access. */
class Logger
{
    private static ?string $directory = null;
    private const MAX_PREVIEW_BYTES = 4194304;

    private static function Dir(): string
    {
        $dir = self::$directory ?? __DIR__.'/../logs';
        if (!is_dir($dir))
        {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && !file_exists($dir.'/.htaccess'))
        {
            @file_put_contents($dir.'/.htaccess', "Require all denied\n");
        }
        return $dir;
    }
    public static function Log(string $level, string $message, array $context = []): void
    {
        $entry = ['time' => Date('c'),'level' => strtoupper($level),'message' => $message,'context' => $context];
        @file_put_contents(self::Dir().'/app-'.Date('Y-m-d').'.log', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n", FILE_APPEND | LOCK_EX);
    }
    public static function ValidName(string $filename): bool
    {
        return preg_match('/^app-(\d{4})-(\d{2})-(\d{2})\.log$/D', $filename, $match) === 1 && checkdate((int)$match[2], (int)$match[3], (int)$match[1]);
    }
    public static function PathFor(string $filename): ?string
    {
        if (!self::ValidName($filename))
        {
            return null;
        }
        $root = realpath(self::Dir());
        if (!$root)
        {
            return null;
        }
        $path = $root.DIRECTORY_SEPARATOR.$filename;
        if (is_link($path) || !is_file($path))
        {
            return null;
        }
        $resolved = realpath($path);
        return $resolved !== false && strncasecmp($resolved, $root.DIRECTORY_SEPARATOR, strlen($root) + 1) === 0 ? $resolved : null;
    }
    public static function ListFiles(): array
    {
        $files = [];
        foreach (glob(self::Dir().'/app-*.log') ?: [] as $path)
        {
            if (self::PathFor(basename($path)) !== null)
            {
                $files[] = basename($path);
            }
        }
        rsort($files);
        return $files;
    }
    public static function FileCatalog(): array
    {
        $files = [];
        foreach (self::ListFiles() as $name)
        {
            $path = self::PathFor($name);
            if (!$path)
            {
                continue;
            }
            $files[] = ['name' => $name,'date' => substr($name, 4, 10),'size' => (int)@filesize($path),'modified' => (int)@filemtime($path)];
        }
        return $files;
    }
    /** Read the tail rather than loading an entire daily file into memory. */
    public static function ReadReport(string $filename, int $limit = 500): array
    {
        if (!self::ValidName($filename))
        {
            throw new InvalidArgumentException('Choose a valid daily application log.');
        }
        $path = self::PathFor($filename);
        if (!$path)
        {
            throw new RuntimeException('The selected log file no longer exists. Refresh the file list.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false)
        {
            throw new RuntimeException('The selected log file could not be read. Check its server file permissions.');
        }
        $limit = max(1, min(2000, $limit));
        $buffer = '';
        $bytes = 0;
        $newlines = 0;
        try
        {
            flock($handle, LOCK_SH);
            $stat = fstat($handle);
            $position = (int)$stat['size'];
            while ($position > 0 && $newlines <= $limit && $bytes < self::MAX_PREVIEW_BYTES)
            {
                $length = min(16384, $position, self::MAX_PREVIEW_BYTES - $bytes);
                $position -= $length;
                fseek($handle, $position);
                $chunk = fread($handle, $length);
                if ($chunk === false || $chunk === '')
                {
                    throw new RuntimeException('The selected log file could not be read completely. Refresh and try again.');
                }
                $buffer = $chunk.$buffer;
                $bytes += strlen($chunk);
                $newlines += substr_count($chunk, "\n");
            }
        }
        finally
        {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $isPreviewLimited = $position > 0 && $bytes >= self::MAX_PREVIEW_BYTES;
        if ($position > 0)
        {
            $firstBreak = strpos($buffer, "\n");
            $buffer = $firstBreak === false ? '' : substr($buffer, $firstBreak + 1);
        }
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $buffer), static fn ($line) => trim($line) !== ''));
        $isHasMore = $position > 0 || count($lines) > $limit;
        $entries = [];
        foreach (array_reverse(array_slice($lines, -$limit)) as $line)
        {
            $decoded = json_decode($line, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_array($decoded) || !array_key_exists('message', $decoded))
            {
                $entries[] = ['time' => null,'level' => 'UNKNOWN','message' => mb_convert_encoding($line, 'UTF-8', 'UTF-8'),'context' => [],'structured' => false];
                continue;
            }
            $entries[] = ['time' => is_string($decoded['time'] ?? null) ? mb_substr($decoded['time'], 0, 100) : null,
                'level' => is_string($decoded['level'] ?? null) ? strtoupper(mb_substr(trim($decoded['level']), 0, 30)) : 'UNKNOWN',
                'message' => is_string($decoded['message']) ? $decoded['message'] : (json_encode($decoded['message'], JSON_INVALID_UTF8_SUBSTITUTE) ?: ''),
                'context' => is_array($decoded['context'] ?? null) ? $decoded['context'] : [], 'structured' => true];
        }
        return ['file' => $filename,'size' => (int)$stat['size'],'modified' => (int)$stat['mtime'],'entries' => $entries,
            'returned' => count($entries),'limit' => $limit,'has_more' => $isHasMore,'preview_limited' => $isPreviewLimited,'preview_bytes' => $bytes];
    }
    public static function ReadFile(string $filename, int $limit = 500): array
    {
        if (self::PathFor($filename) === null)
        {
            return [];
        }
        return self::ReadReport($filename, $limit)['entries'];
    }
    public static function ClearFile(string $filename): bool
    {
        $path = self::PathFor($filename);
        return $path !== null && @unlink($path);
    }
}
