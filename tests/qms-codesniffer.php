<?php

/**
 * Filename: qms-codesniffer.php
 * Purpose: Verify qms codesniffer behavior using isolated regression fixtures.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** CLI regression fixtures for the read-only QMS review engine. */
require_once __DIR__ . '/../lib/CodeReview.php';
set_error_handler(static function ($severity, $message, $file, $line)
{
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$assertions = 0;
function Check(bool $isCondition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$isCondition)
    {
        throw new RuntimeException($message);
    }
}
function Review(string $source, string $path = 'sample.php', string $scope = 'existing'): array
{
    return CodeReview::ReviewFile($path, $source, 'qms', ['qms_scope' => $scope,'qms_indent' => '4']);
}
function Has(array $localReport, int $criterion): bool
{
    return in_array($criterion, array_column($localReport['findings'], 'criterion'), true);
}
function Status(array $localReport, int $criterion): string
{
    return $localReport['qms_checks'][$criterion - 1]['status'];
}
$header = <<<'SRC'
<?php
/*
 * Filename: sample.php
 * Purpose: Validate records.
 * Developer: Sample Developer
 * Date: 2026-10-25
 * Recent updates: Added validation.
 */

SRC;
$valid = $header.<<<'SRC'
const MAX_RECORDS = 5;
$globalValue = 1;
// Validate an input record.
function ValidateInput($input)
{
    $isValid = true;
    $userIsActive = false;
    $is_valid = true;
    return $isValid;
}
class Example
{
    public string $firstName;
    public string $temp_Value;
}
SRC;
$result = Review($valid);
Check(count($result['qms_checks']) === 24, 'All 24 criteria are present.');
foreach ([1,2,3,4,5,8,9,10] as $criterion)
{
    Check(!Has($result, $criterion), 'Valid fixture failed criterion '.$criterion);
}
Check(Status($result, 7) === 'Not applicable', 'Existing scope excludes block-comment requirement.');
Check(Status($result, 16) === 'Not applicable', 'Existing scope excludes modification trail.');
Check(Status($result, 6) === 'Manual review', 'Comment necessity remains manual.');
$bad = $header.<<<'SRC'
const max_records = 5;
$sharedName = 1;
function bad_name() {
 $sharedName = 2; $valid = true;
 $thisValue = false;
}
class Example { public $Bad_field; }
$pdo = new PDO('mysql:host=localhost;dbname=sample', 'user', 'fixture-secret');
SRC;
$result = Review($bad);
foreach ([1,2,3,4,8,9,10,11,14,15] as $criterion)
{
    Check(Has($result, $criterion), 'Bad fixture missed criterion '.$criterion);
}
Check(!str_contains(json_encode($result), 'fixture-secret'), 'Findings must not disclose literal secrets.');
Check(Has(Review(str_replace('2026-10-25', '2026-02-30', $valid)), 5), 'Invalid calendar date rejected.');
Check(!Has(Review(str_replace('2026-10-25', '25/10/2026', $valid)), 5), 'Day/month/year header date supported.');
Check(Has(Review(str_replace('Filename: sample.php', 'Filename: other.php', $valid)), 5), 'Header filename verified.');
Check(Has(Review("<?php echo 1;\n".$header), 5), 'Late header rejected.');
Check(Has(Review($header.'function ValidateInput() { return true; }', 'sample.php', 'new'), 7), 'New block needs comment.');
Check(Has(Review(str_replace('Recent updates: Added validation.', 'Revision history: Initial release.', $valid), 'sample.php', 'modified'), 16), 'Modified file requires change trail.');
$trail = "\n/*\n * Changed: Added validation; removed old check.\n * Developer: Sample Developer\n * Date: 2026-10-25\n */\n";
$result = Review($valid.$trail, 'sample.php', 'modified');
Check(!Has($result, 16) && Status($result, 16) === 'Manual review', 'Trail detection still requires diff/placement review.');
$noise = $header.<<<'SRC'
// function bad_name() { $bad = true; $x = 2; }
$text = 'function wrong_name() { $bad = false; }';
$text = "{$item->value} { ; ; }";
for ($i = 0; $i < 3; $i++)
{
    echo $i;
}
SRC;
$result = Review($noise);
foreach ([1,3,8,10] as $criterion)
{
    Check(!Has($result, $criterion), 'Comments/strings/for header produced noise '.$criterion);
}
$result = Review($header."for (\$i=0; \$i<2; \$i++) Run(); Run();\n");
Check(Has($result, 10), 'Additional statements after for header still detected.');
Check(Has(Review($header.'$shared = 1; function ValidateInput($shared) { return $shared; }'), 11), 'Parameter shadowing detected.');
Check(!Has(Review($header.'function ValidateInput($value = 1) { return $value; }'), 11), 'Defaulted parameter is not a file global.');
Check(!Has(Review($header.'class Example { public $value = 1; function ValidateInput() { $value = 2; } }'), 11), 'Class property is not a file global.');
Check(Has(Review('class Example { invalid_name() { return 1; } }', 'example.js'), 1), 'JS method declaration checked.');
Check(!Has(Review('const MATCH_PATTERN = /[{};]function wrong_name()/;', 'example.js'), 1), 'JS regex literals ignored.');
Check(!Has(Review('const MATCH_PATTERN = /[{};]function wrong_name()/;', 'example.js'), 8), 'JS regex braces ignored.');
Check(Has(Review('let i = 2; i--; Run();', 'example.js'), 10), 'JS decrement does not consume following statements.');
Check(Has(Review("<?php define('DB_PASS', 'fixture-secret');", 'config/db.php'), 15), 'Separated configuration literal password detected.');
$sql = <<<'SRC'
/*
Filename: example.sql
Purpose: Create a fixture table.
Developer: Sample Developer
Date: 2026-10-25
Recent updates: Added the table.
*/
SPOOL example.log
CREATE TABLE app.records
(
    id INTEGER,
    amount DECIMAL(12,2),
    record_name VARCHAR(50),
    strValue VARCHAR(50),
    valid BOOLEAN,
    CONSTRAINT records_pk PRIMARY KEY (id)
);
-- grant select on app.records to reader;
GRANT SELECT ON app.records TO reader;
select 'lowercase select; GRANT; password=fixture-secret' FROM app.records;
SPOOL OFF
SRC;
$result = Review($sql, 'example.sql');
foreach ([3,17,18,19,20] as $criterion)
{
    Check(Has($result, $criterion), 'SQL fixture missed '.$criterion);
}
Check(!Has($result, 21) && Status($result, 21) === 'Manual review', 'Schema qualification does not prove ownership.');
Check(!Has($result, 23) && Status($result, 23) === 'Manual review', 'Log filename detected; runtime creation remains manual.');
foreach ([22,24] as $criterion)
{
    Check(Status($result, $criterion) === 'Manual review', 'Manual standards are not guessed.');
}
Check(count(array_filter($result['findings'], fn ($finding) => ($finding['criterion'] ?? 0) === 19)) === 1, 'Comment/string GRANT ignored.');
Check(Has(Review(str_replace('example.log', 'wrong.log', $sql), 'example.sql'), 23), 'Mismatched log stem flagged.');
Check(Has(Review('CREATE TABLE records (id INTEGER);', 'example.sql'), 21), 'Unqualified object flagged.');
Check(!Has(Review('SET search_path TO app; CREATE TABLE records (id INTEGER);', 'example.sql'), 21), 'Explicit schema setting recognized.');
Check(!Has(Review('CREATE TABLE app.accounts (id INTEGER, amount DECIMAL(12,2), name VARCHAR(20));', 'example.sql'), 17), 'Nested type commas do not become columns.');
Check(!Has(Review('CREATE FUNCTION app.ValidateInput() RETURNS TEXT AS $$ begin return \'grant; select\'; end; $$ LANGUAGE plpgsql;', 'example.sql'), 19), 'Dollar-quoted routine bodies are not mistaken for executable GRANT.');
Check(!Has(Review('CREATE TABLE [app].[records] ([select] INTEGER);', 'example.sql'), 20), 'Quoted SQL identifiers are not keyword matches.');
$result = Review('<link rel="stylesheet" href="app.css"><p style="color:red">X</p><style>p{color:red}</style>', 'example.html');
Check(Has($result, 12), 'Inline styles detected.');
Check(!Has(Review('<!-- <p style="color:red"> --> <link rel="stylesheet" href="app.css">', 'example.html'), 12), 'Commented markup ignored.');
Check(Has(Review('element.setAttribute("style", "color:red");', 'example.js'), 12), 'JS inline style attribute assignment detected.');
Check(!Has(Review('<?php /* <p style="color:red"> */', 'example.php'), 12), 'PHP-commented markup ignored.');
Check(Has(Review($header.'$endpoint = "https://fixture.invalid/api";'), 13), 'Environment-specific endpoint flagged.');
Check(!Has(Review($header.'$endpoint = "https://fixture.invalid/api";', 'config/settings.php'), 13), 'Central configuration can contain endpoint values.');
$result = Review($header.str_repeat("function invalid_name() {}\n", 60));
Check($result['findings_limited'], 'Per-criterion limit disclosed.');
Check($result['qms_checks'][0]['finding_count'] === 60, 'Checklist retains full detected match count.');
Check(count(array_filter($result['findings'], fn ($f) => ($f['criterion'] ?? 0) === 1)) === 25, 'Criterion finding list bounded.');
$report = CodeReview::ScanClient([['path' => 'sample.php','content' => $bad]], CodeReview::Options(['engine' => 'qms']), 'Fixture');
Check(count($report['qms']['criteria']) === 24 && count($report['files'][0]['qms_checks']) === 24, 'Scan carries aggregate and file checklists.');
Check($report['qms']['profile']['scope'] === 'existing', 'Default scope is existing.');
Check(!Has(Review("<?php function Broken( { 'fixture-secret';"), 15), 'Parse errors do not expose input text.');
Check(!str_contains(json_encode(Review("<?php function Broken( { 'fixture-secret';")), 'fixture-secret'), 'Parse error text redacted.');
Check(in_array('QMS-LEX', array_column(Review('const NAME = "unterminated', 'example.js')['findings'], 'rule'), true), 'Incomplete tokenization disclosed.');
$largeFiles = [];
for ($index = 0;$index < 110;$index++)
{
    $largeFiles[] = ['path' => 'file'.$index.'.php','content' => $header.str_repeat("function bad_name() {}\n", 35)];
}
$limited = CodeReview::ScanClient($largeFiles, CodeReview::Options(['engine' => 'qms']), 'Bounded fixture');
Check($limited['findings_limited'] && $limited['finding_count'] === 5000, 'Global finding cap disclosed and enforced.');
Check(count($limited['files']) === 110 && count($limited['files'][109]['qms_checks']) === 24, 'Checks remain available after displayed finding cap.');
Check($limited['qms']['criteria'][0]['finding_count'] === 3850, 'Aggregate counts retain undisplayed matches.');
Check(count(CodeReview::ReviewFile('x.php', '<?php // TODO', 'standard')['findings']) === 1, 'Standard engine retained.');
Check(count(CodeReview::ReviewFile('x.js', 'eval(input);', 'security')['findings']) === 1, 'Security engine retained.');
Check(count(CodeReview::ScanClient([['path' => 'sample.php','content' => $valid]], CodeReview::Options(['engine' => 'inventory']), 'Fixture')['inventory']) > 0, 'Inventory retained.');
foreach ([['qms_scope' => 'invalid'],['qms_indent' => '3'],['engine' => 'invalid']] as $invalid)
{
    try
    {
        CodeReview::Options($invalid);
        Check(false, 'Invalid options accepted.');
    }
    catch (InvalidArgumentException $e)
    {
        Check(true, 'Invalid options rejected.');
    }
}
Check(!Has(Review('const TEXT = ready === true ? "Ready" : "Waiting";', 'sample.js'), 3), 'String ternaries are not inferred as booleans.');
Check(!Has(Review('const TEXT = !ready ? "Ready" : "Waiting";', 'sample.js'), 3), 'Negated conditions with string branches are not boolean values.');
Check(!Has(Review('const VALUE = first || second;', 'sample.js'), 3), 'Value fallbacks are not inferred as booleans.');
Check(!Has(Review('const IS_READY = true;', 'sample.js'), 3), 'Boolean constant has an uppercase IS word.');
Check(Has(Review('let ready = true;', 'sample.js'), 3), 'Actual boolean without is still fails.');
Check(Has(Review('let ready = count > 1;', 'sample.js'), 3), 'Direct comparison without is still fails.');
Check(!Has(Review('<?php $statement = $pdo->prepare($sql);'), 3), 'PHP object arrows are not comparisons.');
Check(!Has(Review('const Render = value => value === true;', 'sample.js'), 3), 'Callback bindings are not boolean variables.');
$nestedTemplate = 'const TEXT = `Outer ${ready ? `Inner ${value}` : "Waiting"} <p>{fake; braces}</p>`;';
Check(!Has(Review($nestedTemplate, 'sample.js'), 8), 'Nested template markup is not checked as code braces.');
Check(!Has(Review($nestedTemplate, 'sample.js'), 10), 'Nested template markup semicolons are not code statements.');
Check(!in_array('QMS-LEX', array_column(Review($nestedTemplate, 'sample.js')['findings'], 'rule'), true), 'Nested templates tokenize completely.');
Check(in_array('QMS-LEX', array_column(Review('const TEXT = `unfinished ${value;', 'sample.js')['findings'], 'rule'), true), 'Unterminated templates retain a lexer finding.');
$switch = "<?php\nswitch (\$value)\n{\n    case 1:\n        Run();\n        break;\n}\n";
Check(!Has(Review($switch), 9), 'Indented switch case bodies follow logical indentation.');
$switchBlock = "<?php\nswitch (\$value)\n{\n    case 1:\n    {\n        Run();\n        break;\n    }\n}\n";
Check(!Has(Review($switchBlock), 9), 'Explicit switch blocks are not counted twice.');
Check(Has(Review(str_replace('        Run();', '  Run();', $switch)), 9), 'Incorrect switch indentation remains a finding.');
Check(!Has(Review('<?php ?><div><?php if ($isReady): ?>Ready<?php endif; ?></div>'), 9), 'Inline markup is not mistaken for PHP leading whitespace.');
$alignedTemplate = "<?php ?>\n<section>\n    <a\n        href=\"<?= H(\$url) ?>\"\n    >Open</a>\n</section>";
Check(!Has(Review($alignedTemplate), 9), 'Aligned HTML attributes containing PHP are reviewed as mixed-template layout.');
Check(Has(Review("<?php ?>\n<section>\n<?php\n  \$value = 1;\n?>\n</section>"), 9), 'Mixed templates still reject incorrect indentation units.');
Check(Has(Review("<?php\nfunction Work()\n{\n\$value = 1;\n}\n"), 9), 'Pure PHP still checks incorrect curly-block indentation depth.');

echo "QMS regression fixtures: {$assertions} assertions passed.\n";
