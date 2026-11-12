<?php
/** Regression fixtures for function descriptions. Source strings are tokenized, never executed. */
require_once __DIR__ . '/../lib/SourceCodeInventory.php';
$checks = 0;
function InventoryCheck(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}

$cases = [
    'PHPDoc prose' => ["/** Read the records. */\nfunction ReadRecords() {}", 'Read the records.'],
    'plain block' => ["/* Read the records. */\nfunction ReadRecords() {}", 'Read the records.'],
    'slash comment' => ["// Read the records.\nfunction ReadRecords() {}", 'Read the records.'],
    'hash comment' => ["# Read the records.\nfunction ReadRecords() {}", 'Read the records.'],
    'adjacent slash lines' => ["// Read the records\n// for the current user.\nfunction ReadRecords() {}", 'Read the records for the current user.'],
    'adjacent hash lines' => ["# Read the records\n# for the current user.\nfunction ReadRecords() {}", 'Read the records for the current user.'],
    'multiline block' => ["/*\n * Read the records\n * for the current user.\n */\nfunction ReadRecords() {}", 'Read the records for the current user.'],
    'description annotation' => ["/**\n * @description Read the records\n * for the current user.\n * @param int \$id The user ID\n *     additional argument explanation\n * @return array Records\n */\nfunction ReadRecords(\$id) {}", 'Read the records for the current user.'],
    'summary annotation' => ["/** @summary Read the records. */\nfunction ReadRecords() {}", 'Read the records.'],
    'description label' => ["/*\n * Function Name: ReadRecords\n * Description: Read the records\n * for the current user.\n * Developer: Example developer\n * Date: 2026-11-11\n */\nfunction ReadRecords() {}", 'Read the records for the current user.'],
    'purpose label' => ["// Purpose: Read the records.\nfunction ReadRecords() {}", 'Read the records.'],
    'annotation continuations excluded' => ["/**\n * Read the records.\n * @param int \$id A user ID\n *   continued parameter detail\n * @return array\n *   continued return detail\n */\nfunction ReadRecords(\$id) {}", 'Read the records.'],
    'line annotation continuations excluded' => ["// Read the records.\n// @param int \$id A user ID\n//   continued parameter detail\nfunction ReadRecords(\$id) {}", 'Read the records.'],
    'only type annotations' => ["/**\n * @param int \$id A user ID\n *   continued explanation\n * @return array\n */\nfunction ReadRecords(\$id) {}", ''],
    'file header excluded' => ["/**\n * Filename: fixture.php\n * Purpose: A file summary.\n */\nfunction ReadRecords() {}", ''],
    'line file header excluded' => ["// Filename: fixture.php\n// Purpose: A file summary.\nfunction ReadRecords() {}", ''],
    'separate banner excluded' => ["/* Unrelated banner. */\n\n// Read the records.\nfunction ReadRecords() {}", 'Read the records.'],
    'unrelated statement stops lookup' => ["// Not function documentation.\n\$value = 1;\nfunction ReadRecords() {}", ''],
    'previous trailing comment excluded' => ["\$value = 1; // An assignment note.\nfunction ReadRecords() {}", ''],
    'body notes excluded' => ["function ReadRecords() { // Implementation note.\n return 1;\n}", ''],
    'attributes and modifiers' => ["class Fixture {\n/** Read the records. */\n#[Example(options: ['a' => [']']])]\n#[Second]\nfinal public static function ReadRecords(): array { return []; }\n}", 'Read the records.'],
    'comment after attribute' => ["class Fixture {\n#[Example]\n// Read the records.\npublic function ReadRecords() {}\n}", 'Read the records.'],
    'reference return' => ["// Read the records.\nfunction &ReadRecords(&\$data) { return \$data; }", 'Read the records.'],
    'interface method' => ["interface Fixture {\n// Read the records.\npublic function ReadRecords();\n}", 'Read the records.'],
    'CRLF comments' => ["// Read the records\r\n// for the current user.\r\nfunction ReadRecords() {}", 'Read the records for the current user.'],
    'Unicode description' => ["// Read café 日本語 records.\nfunction ReadRecords() {}", 'Read café 日本語 records.'],
    'length limit' => ["// ".str_repeat('界', 501)."\nfunction ReadRecords() {}", str_repeat('界', 500)],
    'HTML remains source text' => ["/* <b>Read</b> the records. */\nfunction ReadRecords() {}", '<b>Read</b> the records.'],
    'ordinary reference link' => ["// Read records from https://example.invalid/docs.\nfunction ReadRecords() {}", 'Read records from https://example.invalid/docs.'],
];
foreach ($cases as $label => [$source, $description])
{
    $rows = SourceCodeInventory::Extract('fixture.php', "<?php\n".$source);
    InventoryCheck(count($rows) === 1, $label . ': function count');
    InventoryCheck($rows[0]['description'] === $description, $label . ': description');
}
$source = <<<'PHP'
<?php
// First function.
function First(int &$id): int { $count = 1; return $count; }
// Second function.
function Second() { return 'text'; }
function Third() {}
// A closure is not a named inventory function.
$callback = function () {};
throw new RuntimeException('Extract must never execute source');
PHP;
$rows = SourceCodeInventory::Extract('folder/fixture.php', $source);
InventoryCheck(array_column($rows, 'function_name') === ['First', 'Second', 'Third'], 'Named functions remain separate');
InventoryCheck(array_column($rows, 'description') === ['First function.', 'Second function.', ''], 'Descriptions do not leak to the next function');
InventoryCheck($rows[0]['line'] === 3 && $rows[0]['path'] === 'folder/fixture.php', 'Line and path preserved');
InventoryCheck($rows[0]['arguments'][0]['name'] === '$id' && $rows[0]['return_data_type'] === 'int', 'Argument and return extraction preserved');
echo "Source Code Inventory: $checks checks passed.\n";
