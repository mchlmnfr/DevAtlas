<?php

/**
 * Filename: Logger.php
 * Purpose: Read CodeIgniter file logs and forward legacy logging calls to the framework.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** CodeIgniter file logging with bounded, multiline-aware previews. */
class Logger
{
    // Retained as an isolated-directory override for regression fixtures.
    private static ?string $directory = null;
    private const MAX_PREVIEW_BYTES = 4194304;
    private const HEADER = '/^(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG) - (.+?) --> ?(.*)$/m';

    private static function Settings(): ?array
    {
        require_once __DIR__ . '/Framework.php';
        Framework::Boot();
        $config = config('Logger');
        $handler = $config->handlers[CodeIgniter\Log\Handlers\FileHandler::class] ?? null;
        if (!is_array($handler))
        {
            return null;
        }
        $extension = ltrim($handler['fileExtension'] ?? '', '.');
        $extension = $extension === '' ? 'log' : $extension;
        if (!preg_match('/^[a-zA-Z0-9]+$/D', $extension))
        {
            throw new RuntimeException('The framework log file extension is not supported.');
        }
        $path = self::$directory ?? (($handler['path'] ?? '') ?: WRITEPATH . 'logs/');
        return ['directory' => rtrim($path, '/\\'), 'extension' => $extension, 'date_format' => $config->dateFormat];
    }

    public static function CurrentFilename(): ?string
    {
        $settings = self::Settings();
        return $settings === null ? null : 'log-' . date('Y-m-d') . '.' . $settings['extension'];
    }

    public static function Log(string $level, string $message, array $context = []): void
    {
        try
        {
            require_once __DIR__ . '/Framework.php';
            Framework::Boot();
            $level = strtolower($level);
            $level = ['fatal' => 'critical', 'php_warning' => 'warning', 'warn' => 'warning', 'success' => 'info'][$level] ?? $level;
            if ($context !== [])
            {
                $message .= "\nContext: " . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
            $logger = service('logger');
            if (self::$directory !== null)
            {
                $config = clone config('Logger');
                $config->handlers = [CodeIgniter\Log\Handlers\FileHandler::class => array_replace($config->handlers[CodeIgniter\Log\Handlers\FileHandler::class], ['path' => rtrim(self::$directory, '/\\') . DIRECTORY_SEPARATOR])];
                $logger = new CodeIgniter\Log\Logger($config, false);
            }
            // A constant placeholder prevents message text from expanding {env:...}
            // or other framework placeholders supplied by request data.
            $logger->log($level, '{devatlas_message}', ['devatlas_message' => $message]);
        }
        catch (Throwable $error)
        {
            error_log('[DevAtlas] CodeIgniter logging could not write an event.');
        }
    }

    public static function ValidName(string $filename): bool
    {
        $settings = self::Settings();
        return $settings !== null && preg_match('/^log-(\d{4})-(\d{2})-(\d{2})\.' . preg_quote($settings['extension'], '/') . '$/D', $filename, $match) === 1 && checkdate((int)$match[2], (int)$match[3], (int)$match[1]);
    }
    public static function PathFor(string $filename): ?string
    {
        if (!self::ValidName($filename))
        {
            return null;
        }
        $settings = self::Settings();
        $root = $settings === null ? false : realpath($settings['directory']);
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
        $settings = self::Settings();
        if ($settings === null)
        {
            return [];
        }
        foreach (glob($settings['directory'] . '/log-*.' . $settings['extension']) ?: [] as $path)
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
            throw new InvalidArgumentException('Choose a valid daily CodeIgniter log.');
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
        $headers = 0;
        try
        {
            flock($handle, LOCK_SH);
            $stat = fstat($handle);
            $position = (int)$stat['size'];
            while ($position > 0 && $headers <= $limit && $bytes < self::MAX_PREVIEW_BYTES)
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
                $headers = preg_match_all(self::HEADER, $buffer);
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
        // A tail window may begin inside a stack trace: discard the incomplete
        // oldest event instead of presenting its frames as independent entries.
        if ($position > 0)
        {
            $buffer = preg_match(self::HEADER, $buffer, $first, PREG_OFFSET_CAPTURE) ? substr($buffer, $first[0][1]) : '';
        }
        $records = [];
        $current = null;
        foreach (explode("\n", str_replace("\r\n", "\n", $buffer)) as $line)
        {
            if (preg_match(self::HEADER, $line, $header))
            {
                if ($current !== null)
                {
                    $records[] = $current;
                }
                $current = ['time' => $header[2], 'level' => $header[1], 'message' => $header[3], 'context' => [], 'structured' => true];
            }
            elseif (trim($line) === "<?php defined('SYSTEMPATH') || exit('No direct script access allowed'); ?>")
            {
                continue;
            }
            elseif ($current !== null)
            {
                $current['message'] .= "\n" . $line;
            }
            elseif (trim($line) !== '')
            {
                $current = ['time' => null, 'level' => 'UNKNOWN', 'message' => $line, 'context' => [], 'structured' => false];
            }
        }
        if ($current !== null)
        {
            $records[] = $current;
        }
        $isHasMore = $position > 0 || count($records) > $limit;
        $entries = array_map([self::class, 'ParseEntry'], array_reverse(array_slice($records, -$limit)));
        return ['file' => $filename,'size' => (int)$stat['size'],'modified' => (int)$stat['mtime'],'entries' => $entries,
            'returned' => count($entries),'limit' => $limit,'has_more' => $isHasMore,'preview_limited' => $isPreviewLimited,'preview_bytes' => $bytes];
    }
    private static function ParseEntry(array $entry): array
    {
        $entry['message'] = mb_convert_encoding(rtrim($entry['message']), 'UTF-8', 'UTF-8');
        if (!$entry['structured'])
        {
            return $entry;
        }
        $settings = self::Settings();
        $format = ['c' => DateTimeInterface::ATOM, 'r' => DateTimeInterface::RFC2822][$settings['date_format']] ?? $settings['date_format'];
        $date = str_contains($entry['time'], "\0") ? false : DateTimeImmutable::createFromFormat('!' . $format, $entry['time'], new DateTimeZone(config('App')->appTimezone));
        $errors = DateTimeImmutable::getLastErrors();
        $entry['time'] = $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) ? $date->format('c') : null;
        if (preg_match('/\nContext: (\{[^\r\n]*\})$/D', $entry['message'], $match))
        {
            $context = json_decode($match[1], true);
            if (is_array($context))
            {
                $entry['context'] = $context;
                $entry['message'] = substr($entry['message'], 0, -strlen($match[0]));
            }
        }
        if (preg_match('/^\[Method: ([^,\r\n]+), Route: ([^\r\n]*)\]$/m', $entry['message'], $match))
        {
            $entry['context'] += ['method' => $match[1], 'uri' => '/' . ltrim($match[2], '/')];
        }
        if (preg_match('/^in (.+) on line (\d+)\.?$/m', $entry['message'], $match))
        {
            $entry['context'] += ['file' => $match[1], 'line' => (int)$match[2]];
        }
        return $entry;
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
