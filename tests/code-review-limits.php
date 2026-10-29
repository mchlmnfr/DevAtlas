<?php

/**
 * Filename: code-review-limits.php
 * Purpose: Verify code review limits behavior using isolated regression fixtures.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Scan boundaries for full project folders; no production records are written. */
require_once __DIR__ . '/../lib/CodeReview.php';
$checks = 0;
function Check(bool $isOk, string $label): void
{
    global $checks;
    $checks++;
    if (!$isOk)
    {
        throw new RuntimeException($label);
    }
}
function Rejected(callable $run, string $message): void
{
    try
    {
        $run();
    }
    catch (InvalidArgumentException $e)
    {
        Check(str_contains($e->getMessage(), $message), 'Expected rejection: '.$message);
        return;
    }
    throw new RuntimeException('Missing rejection: '.$message);
}
$source = "<?php\nfunction FixtureValue(\$value) { return \$value; }\n";
$files = [];
for ($i = 1; $i <= 3000; $i++)
{
    $files[] = ['path' => sprintf('fixture/file-%04d.php', $i),'content' => $source];
}
foreach (['standard','security','owasp','qms','inventory'] as $engine)
{
    $report = CodeReview::ScanClient($files, CodeReview::Options(['engine' => $engine]), 'Boundary fixture');
    Check($report['file_count'] === 3000 && count($report['files']) === 3000, $engine.' reviews all 3,000 files');
    Check($report['files'][2999]['path'] === 'fixture/file-3000.php', $engine.' reaches the last file');
    Check($report['finding_count'] <= CodeReview::MAX_FINDINGS, $engine.' retains finding bounds');
    if ($engine === 'qms')
    {
        Check(array_sum(array_map(static fn ($f) => count($f['qms_checks']), $report['files'])) === 72000, 'QMS retains every file criterion after display limits');
        Check(strlen(json_encode($report, JSON_THROW_ON_ERROR)) < CodeReview::MAX_REPORT_BYTES, 'Full QMS report fits export allowance');
    }
    if ($engine === 'inventory')
    {
        Check($report['function_count'] === 3000 && !$report['inventory_limited'], 'Exactly 3,000 single-function files retain all inventory rows');
    }
    unset($report);
}
$files[] = ['path' => 'fixture/file-3001.php','content' => $source];
Rejected(static fn () => CodeReview::ScanClient($files, CodeReview::Options([]), 'Fixture'), 'at most 3000');
unset($files);

// Exercise byte boundaries independently of file count, including the previous 8 MB limit.
$padded = '<?php /*'.str_repeat('x', CodeReview::MAX_FILE_BYTES - 11).' */';
Check(strlen($padded) === CodeReview::MAX_FILE_BYTES, 'Exact per-file boundary fixture');
$files = [];
for ($i = 0;$i < 192;$i++)
{
    $files[] = ['path' => "fixture/padded-$i.php",'content' => $padded];
}
$options = CodeReview::Options(['engine' => 'security']);
$report = CodeReview::ScanClient($files, $options, '48 MB fixture');
Check($report['file_count'] === 192 && array_sum(array_column($report['files'], 'size')) === CodeReview::MAX_TOTAL_BYTES, '48 MB of source is accepted');
unset($report);
$files[] = ['path' => 'fixture/extra.php','content' => 'x'];
Rejected(static fn () => CodeReview::ScanClient($files, $options, 'Fixture'), '48 MB');
Rejected(static fn () => CodeReview::ScanClient([['path' => 'large.php','content' => $padded.'x']], $options, 'Fixture'), '256 KB');
Rejected(static fn () => CodeReview::ScanClient([['path' => 'vendor/fixture.php','content' => $source]], $options, 'Fixture'), 'Excluded folder');
foreach (['assets/plugins/editor/editor.js', 'plugins/chart/chart.js', 'node_modules/library/index.js', 'third_party/library.php', 'assets/vendor/alpine/alpine.min.js'] as $dependency)
{
    Rejected(static fn () => CodeReview::ScanClient([['path' => $dependency, 'content' => $source]], $options, 'Fixture'), 'Excluded folder');
}
$owned = CodeReview::ScanClient([['path' => 'app/Views/fixture.php', 'content' => $source], ['path' => 'assets/js/owned.js', 'content' => 'const value = 1;'], ['path' => 'assets/css/tailwind.css', 'content' => 'body{}']], CodeReview::Options(['engine' => 'qms']), 'Fixture');
Check($owned['file_count'] === 2, 'Review includes our views and JavaScript and excludes generated Tailwind output');
unset($files,$padded);

// Verify server-folder scanning reaches the same boundary without exposing fixture source.
$prefix = 'scan-limit-'.bin2hex(random_bytes(5));
$fixture = __DIR__.DIRECTORY_SEPARATOR.$prefix;
mkdir($fixture);
file_put_contents($fixture.'/.htaccess', "Require all denied\n");
try
{
    for ($i = 1;$i <= 3001;$i++)
    {
        file_put_contents($fixture.'/'.sprintf('%s-%04d.php', $prefix, $i), $source);
    }
    $report = CodeReview::ScanServer(basename(dirname(__DIR__)), CodeReview::Options(['engine' => 'security','search' => $prefix]));
    Check($report['file_count'] === 3000 && $report['skipped'] === 1, 'Server folder reviews 3,000 files and discloses the excess file');
}
finally
{
    if (realpath(dirname($fixture)) !== realpath(__DIR__) || basename($fixture) !== $prefix)
    {
        throw new RuntimeException('Invalid fixture cleanup path.');
    }
    foreach (new DirectoryIterator($fixture) as $entry)
    {
        if (!$entry->isDot() && $entry->isFile())
        {
            unlink($entry->getPathname());
        }
    }
    rmdir($fixture);
}
echo "Code Review scan limits: $checks assertions passed.\n";
