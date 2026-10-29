<?php

/**
 * Filename: CodeReview.php
 * Purpose: Provide code review processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Review first-party source only; exclude installed plugins and generated Tailwind output.
 */

/** Bounded, read-only static checks for PHP, JS, SQL, CSS, and HTML source. */
require_once __DIR__ . '/SourceCodeInventory.php';
require_once __DIR__ . '/QmsCodeSniffer.php';
require_once __DIR__ . '/OwaspReview.php';
final class CodeReview
{
    public const MAX_FILES = 3000;
    public const MAX_FILE_BYTES = 262144;
    public const MAX_TOTAL_BYTES = 50331648;
    public const MAX_REQUEST_BYTES = 100663296;
    public const MAX_REPORT_BYTES = 25165824;
    public const MAX_FINDINGS = 5000;
    public const MAX_FUNCTIONS = 3000;
    public const EXTENSIONS = ['php', 'phtml', 'inc', 'js', 'css', 'sql', 'html', 'htm'];
    public const EXCLUDED_DIRS = ['.git', '.svn', 'vendor', 'node_modules', 'logs', 'uploads', 'dist', 'build', 'plugins', 'third_party', 'third-party', 'bower_components', 'writable'];

    public static function ServerProjects(): array
    {
        $base = realpath(dirname(__DIR__, 2));
        if ($base === false)
        {
            return [];
        }
        $names = [];
        foreach (new DirectoryIterator($base) as $entry)
        {
            if ($entry->isDot() || !$entry->isDir() || $entry->isLink())
            {
                continue;
            }
            $name = $entry->getFilename();
            if ($name[0] === '.' || in_array(strtolower($name), self::EXCLUDED_DIRS, true))
            {
                continue;
            }
            $names[] = $name;
        }
        natcasesort($names);
        return array_values($names);
    }

    public static function ResolveServerProject(string $name): ?string
    {
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') ||
            str_contains($name, '\\') || str_contains($name, "\0") || $name[0] === '.' ||
            in_array(strtolower($name), self::EXCLUDED_DIRS, true))
        {
            return null;
        }
        $base = realpath(dirname(__DIR__, 2));
        if ($base === false)
        {
            return null;
        }
        $path = realpath($base . DIRECTORY_SEPARATOR . $name);
        if ($path === false || !is_dir($path) || is_link($path))
        {
            return null;
        }
        $prefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncasecmp($path, $prefix, strlen($prefix)) !== 0)
        {
            return null;
        }
        return $path;
    }

    public static function Options(array $input): array
    {
        $engine = (string)($input['engine'] ?? 'standard');
        $type = strtolower((string)($input['type'] ?? ''));
        $days = (int)($input['modified_days'] ?? 0);
        $search = trim((string)($input['search'] ?? ''));
        if (!in_array($engine, ['standard', 'security', 'inventory', 'qms', 'owasp'], true))
        {
            throw new InvalidArgumentException('Invalid review engine.');
        }
        if ($type !== '' && !in_array($type, self::EXTENSIONS, true))
        {
            throw new InvalidArgumentException('Invalid file type.');
        }
        if (!in_array($days, [0, 1, 7, 30], true))
        {
            throw new InvalidArgumentException('Invalid modified date filter.');
        }
        if (mb_strlen($search) > 100)
        {
            throw new InvalidArgumentException('Search is too long.');
        }
        $scope = (string)($input['qms_scope'] ?? 'existing');
        $indent = (string)($input['qms_indent'] ?? '4');
        if (!in_array($scope, ['existing','new','modified'], true))
        {
            throw new InvalidArgumentException('Invalid QMS change scope.');
        }
        if (!in_array($indent, ['2','4','tabs'], true))
        {
            throw new InvalidArgumentException('Invalid QMS indentation.');
        }
        return ['engine' => $engine, 'type' => $type, 'modified_days' => $days, 'search' => $search,
                'qms_scope' => $scope, 'qms_indent' => $indent];
    }

    public static function ScanServer(string $projectName, array $options): array
    {
        $root = self::ResolveServerProject($projectName);
        if ($root === null)
        {
            throw new InvalidArgumentException('Choose a valid folder under the server web root.');
        }
        $entries = [];
        $skipped = 0;
        $bytes = 0;
        $seen = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $item): bool
            {
                if ($item->isLink())
                {
                    return false;
                }
                if ($item->isDir())
                {
                    return $item->isReadable() && !in_array(strtolower($item->getFilename()), CodeReview::EXCLUDED_DIRS, true) && $item->getFilename()[0] !== '.';
                }
                return $item->isFile() && $item->isReadable();
            }
        ));
        foreach ($iterator as $file)
        {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (!self::Matches($relative, (int)$file->getMTime(), $options))
            {
                continue;
            }
            if (++$seen > 5000)
            {
                $skipped++;
                break;
            }
            $size = (int)$file->getSize();
            if ($size > self::MAX_FILE_BYTES || count($entries) >= self::MAX_FILES || $bytes + $size > self::MAX_TOTAL_BYTES)
            {
                $skipped++;
                continue;
            }
            $content = @file_get_contents($file->getPathname());
            if ($content === false || strlen($content) > self::MAX_FILE_BYTES)
            {
                $skipped++;
                continue;
            }
            $entries[] = ['path' => $relative, 'content' => $content, 'size' => $size, 'modified' => (int)$file->getMTime()];
            $bytes += strlen($content);
        }
        return self::ScanEntries($entries, $options, $projectName, $skipped);
    }

    public static function ScanClient(array $files, array $options, string $projectName): array
    {
        if (!$files)
        {
            throw new InvalidArgumentException('Choose a folder with supported source files.');
        }
        if (count($files) > self::MAX_FILES)
        {
            throw new InvalidArgumentException('Too many files. Select at most ' . self::MAX_FILES . ' supported source files.');
        }
        $entries = [];
        $bytes = 0;
        foreach ($files as $file)
        {
            if (!is_array($file) || !is_string($file['path'] ?? null) || !is_string($file['content'] ?? null))
            {
                throw new InvalidArgumentException('Invalid file payload.');
            }
            $path = str_replace('\\', '/', $file['path']);
            if (strlen($path) > 500 || str_starts_with($path, '/') || preg_match('/(^|\/)\.\.?($|\/)|^[A-Za-z]:/', $path))
            {
                throw new InvalidArgumentException('Invalid relative file path.');
            }
            $segments = explode('/', $path);
            foreach (array_slice($segments, 0, -1) as $segment)
            {
                if ($segment === '' || $segment[0] === '.' || in_array(strtolower($segment), self::EXCLUDED_DIRS, true))
                {
                    throw new InvalidArgumentException('Excluded folder was sent.');
                }
            }
            $content = $file['content'];
            $size = strlen($content);
            if ($size > self::MAX_FILE_BYTES)
            {
                throw new InvalidArgumentException('A file exceeds the 256 KB limit.');
            }
            $bytes += $size;
            if ($bytes > self::MAX_TOTAL_BYTES)
            {
                throw new InvalidArgumentException('Selected source exceeds the 48 MB limit.');
            }
            $modified = (int)($file['modified'] ?? 0);
            if (!self::Matches($path, $modified, $options))
            {
                continue;
            }
            $entries[] = ['path' => $path, 'content' => $content, 'size' => $size, 'modified' => $modified];
        }
        return self::ScanEntries($entries, $options, $projectName, 0);
    }

    private static function Matches(string $path, int $modified, array $options): bool
    {
        if (str_ends_with(strtolower(str_replace('\\', '/', $path)), 'assets/css/tailwind.css'))
        {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXTENSIONS, true))
        {
            return false;
        }
        if ($options['engine'] === 'inventory' && !in_array($ext, ['php', 'phtml', 'inc'], true))
        {
            return false;
        }
        if ($options['type'] !== '' && $options['type'] !== $ext)
        {
            return false;
        }
        if ($options['search'] !== '' && stripos(basename($path), $options['search']) === false)
        {
            return false;
        }
        if ($options['modified_days'] > 0 && $modified < time() - $options['modified_days'] * 86400)
        {
            return false;
        }
        return true;
    }

    private static function ScanEntries(array $entries, array $options, string $project, int $skipped): array
    {
        $files = [];
        $inventory = [];
        $isInventoryLimited = false;
        $findingCount = 0;
        $isFindingsLimited = false;
        $totals = ['lines' => 0, 'functions' => 0, 'todos' => 0, 'evals' => 0, 'long_lines' => 0];
        foreach ($entries as $entry)
        {
            $review = self::ReviewFile($entry['path'], $entry['content'], $options['engine'], $options);
            $isFindingsLimited = $isFindingsLimited || ($review['findings_limited'] ?? false);
            if ($options['engine'] === 'inventory')
            {
                $functions = SourceCodeInventory::Extract($entry['path'], $entry['content']);
                $review['metrics']['functions'] = count($functions);
                foreach ($functions as $function)
                {
                    if (count($inventory) >= self::MAX_FUNCTIONS)
                    {
                        $isInventoryLimited = true;
                        break;
                    }
                    $function['function_id'] = sprintf('FN-%05d', count($inventory) + 1);
                    $inventory[] = $function;
                }
            }
            if ($findingCount + count($review['findings']) > self::MAX_FINDINGS)
            {
                $isFindingsLimited = true;
                $review['findings'] = array_slice($review['findings'], 0, max(0, self::MAX_FINDINGS - $findingCount));
            }
            $findingCount += count($review['findings']);
            foreach ($totals as $key => &$value)
            {
                $value += $review['metrics'][$key];
            }
            unset($value);
            $files[] = ['path' => $entry['path'], 'type' => strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION)),
                        'size' => $entry['size'], 'modified' => $entry['modified'],
                        'metrics' => $review['metrics'], 'findings' => $review['findings']];
            if ($options['engine'] === 'qms')
            {
                $last = count($files) - 1;
                $files[$last]['qms_checks'] = $review['qms_checks'];
                $files[$last]['findings_limited'] = count($review['findings']) < array_sum(array_column($review['qms_checks'], 'finding_count')) || ($review['findings_limited'] ?? false);
            }
        }
        $report = ['project' => mb_substr($project, 0, 150), 'engine' => $options['engine'],
                'files' => $files, 'file_count' => count($files), 'finding_count' => $findingCount,
                'skipped' => $skipped, 'findings_limited' => $isFindingsLimited,
                'totals' => $totals, 'inventory' => $inventory, 'function_count' => count($inventory),
                'inventory_limited' => $isInventoryLimited];
        if ($options['engine'] === 'qms')
        {
            $report['qms'] = ['profile' => QmsCodeSniffer::Profile($options), 'criteria' => QmsCodeSniffer::Summary($files)];
        }
        if ($options['engine'] === 'owasp')
        {
            $report['owasp'] = OwaspReview::Summary($files);
        }
        return $report;
    }

    public static function ReviewFile(string $path, string $content, string $engine, array $options = []): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $lines = preg_split('/\r\n|\n|\r/', $content);
        $metrics = ['lines' => count($lines), 'functions' => 0, 'todos' => 0, 'evals' => 0, 'long_lines' => 0];
        $findings = [];
        if (in_array($ext, ['php', 'phtml', 'inc'], true))
        {
            preg_match_all('/\bfunction\s+&?\s*[A-Za-z_][A-Za-z0-9_]*\s*\(/', $content, $functions);
            $metrics['functions'] = count($functions[0]);
            try
            {
                token_get_all($content, TOKEN_PARSE);
            }
            catch (ParseError $e)
            {
                $findings[] = ['line' => max(1, $e->getLine()), 'severity' => 'High', 'rule' => 'PHP-PARSE', 'message' => 'PHP parse error; correct the syntax and rerun the review.'];
            }
        }
        elseif ($ext === 'js')
        {
            preg_match_all('/\bfunction\s+[A-Za-z_$][\w$]*\s*\(|\b(?:const|let|var)\s+[A-Za-z_$][\w$]*\s*=\s*(?:async\s*)?\([^)]*\)\s*=>/', $content, $functions);
            $metrics['functions'] = count($functions[0]);
        }
        foreach ($lines as $index => $line)
        {
            $number = $index + 1;
            if (preg_match('/\b(?:TODO|FIXME)\b/i', $line))
            {
                $metrics['todos']++;
            }
            if (preg_match('/\beval\s*\(/i', $line))
            {
                $metrics['evals']++;
            }
            if (strlen($line) > 120)
            {
                $metrics['long_lines']++;
            }
            if ($engine === 'standard')
            {
                if (preg_match('/\b(?:TODO|FIXME)\b/i', $line))
                {
                    $findings[] = ['line' => $number, 'severity' => 'Info', 'rule' => 'TODO', 'message' => 'Open TODO or FIXME marker.'];
                }
                if (strlen($line) > 120)
                {
                    $findings[] = ['line' => $number, 'severity' => 'Low', 'rule' => 'LONG-LINE', 'message' => 'Line exceeds 120 bytes.'];
                }
                if (preg_match('/[ \t]+$/', $line))
                {
                    $findings[] = ['line' => $number, 'severity' => 'Low', 'rule' => 'TRAILING-SPACE', 'message' => 'Trailing whitespace.'];
                }
            }
            elseif ($engine === 'security')
            {
                foreach (self::SecurityRules($ext) as $rule)
                {
                    if (preg_match($rule[0], $line) && (!isset($rule[4]) || !preg_match($rule[4], $line)))
                    {
                        $findings[] = ['line' => $number, 'severity' => $rule[1], 'rule' => $rule[2], 'message' => $rule[3]];
                    }
                }
            }
        }
        if ($engine === 'qms')
        {
            $qms = QmsCodeSniffer::Review($path, $content, $options);
            if ($findings)
            {
                foreach ($qms['qms_checks'] as &$check)
                {
                    if ($check['status'] === 'No issues detected')
                    {
                        $check['status'] = 'Manual review';
                    }
                }
                unset($check);
            }
            $qms['metrics'] = $metrics;
            $qms['findings'] = array_merge($findings, $qms['findings']);
            return $qms;
        }
        if ($engine === 'owasp')
        {
            $owasp = OwaspReview::Review($path, $content);
            $owasp['metrics'] = $metrics;
            $owasp['findings'] = array_merge($findings, $owasp['findings']);
            return $owasp;
        }
        return ['metrics' => $metrics, 'findings' => array_slice($findings, 0, 50), 'findings_limited' => count($findings) > 50];
    }

    private static function SecurityRules(string $ext): array
    {
        if (in_array($ext, ['php', 'phtml', 'inc'], true))
        {
            return [
                ['/\beval\s*\(/i', 'High', 'PHP-EVAL', 'Dynamic code execution; verify the input cannot be influenced by users.'],
                ['/\b(?:include|require)(?:_once)?\s*(?:\(\s*)?\$_(?:GET|POST|REQUEST)\b/i', 'High', 'PHP-INCLUDE', 'Potential request-driven file inclusion.'],
                ['/\b(?:exec|shell_exec|system|passthru|popen)\s*\([^;]*\$_(?:GET|POST|REQUEST)\b/i', 'High', 'PHP-SHELL', 'Potential request-driven shell command.'],
                ['/\bunserialize\s*\([^;]*\$_(?:GET|POST|REQUEST)\b/i', 'High', 'PHP-UNSERIALIZE', 'Potential deserialization of request data.'],
                ['/\b(?:echo|print)\s+\$_(?:GET|POST|REQUEST)\b/i', 'Medium', 'PHP-OUTPUT', 'Potential unescaped request value in HTML output.', '/\b(?:htmlspecialchars|htmlentities)\s*\(/i'],
                ['/\b(?:SELECT|INSERT|UPDATE|DELETE)\b.*\$_(?:GET|POST|REQUEST)\b/i', 'Medium', 'PHP-SQL', 'Potential SQL built from request data; verify parameter binding.'],
                ['/\bini_set\s*\(\s*[\'\"]display_errors[\'\"]\s*,\s*[\'\"]?(?:1|on)[\'\"]?/i', 'Medium', 'PHP-ERRORS', 'Display of PHP errors is enabled; review production configuration.'],
                ['/\bdefine\s*\(\s*[\'\"](?:DB_PASS|API_KEY|APP_SECRET)[\'\"]\s*,\s*[\'\"][^\'\"]+[\'\"]/i', 'Medium', 'PHP-SECRET', 'A credential or API secret appears to be hardcoded.'],
                ['/\b(?:md5|sha1)\s*\([^)]*(?:password|passwd)/i', 'Medium', 'PHP-HASH', 'A password appears to use a weak hash function.'],
                ['/\bheader\s*\(\s*[\'\"]Access-Control-Allow-Origin\s*:\s*\*[\'\"]/i', 'Medium', 'PHP-CORS', 'Wildcard CORS origin; verify this is intended.'],
            ];
        }
        if ($ext === 'js')
        {
            return [
                ['/\beval\s*\(/i', 'High', 'JS-EVAL', 'Dynamic JavaScript execution; verify input safety.'],
                ['/\.innerHTML\s*=\s*(?![\'\"])/i', 'Medium', 'JS-HTML', 'Dynamic innerHTML assignment; verify content is escaped or trusted.'],
                ['/\bdocument\.write\s*\(/i', 'Medium', 'JS-WRITE', 'document.write can inject untrusted HTML.'],
            ];
        }
        return [];
    }
}
