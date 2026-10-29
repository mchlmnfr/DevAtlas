<?php

/**
 * Filename: qms-audit.php
 * Purpose: Provide qms audit processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../lib/QmsCodeSniffer.php';
require_once __DIR__ . '/../lib/CodeReview.php';
$root = dirname(__DIR__);
$isBaseline = in_array('--baseline', $argv, true);
$files = [];
$counts = array_fill(1, 24, 0);
$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    static function (SplFileInfo $entry) use ($root): bool
    {
        if ($entry->isLink())
        {
            return false;
        }
        if ($entry->isDir())
        {
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            return $entry->getFilename()[0] !== '.' && !in_array(strtolower($entry->getFilename()), CodeReview::EXCLUDED_DIRS, true) && !in_array($relative, ['vendor', 'assets/vendor', 'uploads', 'logs', 'codereview'], true);
        }
        return $entry->isFile();
    }
));
foreach ($iterator as $entry)
{
    $path = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
    if ($path === 'assets/css/tailwind.css' || str_starts_with($path, 'assets/vendor/') || !in_array(strtolower($entry->getExtension()), ['php', 'phtml', 'inc', 'js', 'css', 'sql', 'html', 'htm'], true))
    {
        continue;
    }
    $review = QmsCodeSniffer::Review($path, file_get_contents($entry->getPathname()), ['qms_scope' => 'existing', 'qms_indent' => '4']);
    foreach ($review['qms_checks'] as $check)
    {
        $counts[$check['id']] += $check['finding_count'];
    }
    $files[] = ['path' => $path] + $review;
}
usort($files, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
$report = ['generated_at' => gmdate('c'), 'scope' => 'existing', 'indent' => '4', 'file_count' => count($files),
    'excluded' => ['third-party libraries', 'generated Tailwind CSS (resources/css/app.css is scanned)', 'generated uploads and logs', 'intentionally noncompliant codereview samples', 'temporary working directories'],
    'criteria' => QmsCodeSniffer::Summary($files), 'files' => $files];
$directory = $root . '/docs/quality';
if (!is_dir($directory))
{
    mkdir($directory, 0775, true);
}
$target = $directory . ($isBaseline ? '/qms-baseline.json' : '/qms-current.json');
file_put_contents($target, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo 'QMS audit: ' . count($files) . ' first-party source files; ' . array_sum($counts) . " detected findings.\n";
foreach (QmsCodeSniffer::Catalog() as $criterion)
{
    echo sprintf("%s: %d - %s\n", $criterion['rule'], $counts[$criterion['id']], $criterion['title']);
}
