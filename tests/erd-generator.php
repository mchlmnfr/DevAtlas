<?php

/**
 * Filename: erd-generator.php
 * Purpose: Verify erd generator behavior using isolated regression fixtures.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Pure regression fixtures for reference resolution, ERD imports, XML and public URL validation. */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
require_once __DIR__.'/../lib/ErdWorkbook.php';
require_once __DIR__.'/../lib/GraphMLBuilder.php';
require_once __DIR__.'/../lib/AccessLinks.php';
set_error_handler(static function ($severity, $message, $file, $line)
{
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$checks = 0;
function ErdCheck(bool $isOk, string $message): void
{
    global $checks;
    $checks++;
    if (!$isOk)
    {
        throw new RuntimeException($message);
    }
}
function ErdReject(callable $fn, string $message): void
{
    try
    {
        $fn();
    }
    catch (InvalidArgumentException $e)
    {
        ErdCheck(true, $message);
        return;
    }
    ErdCheck(false, $message);
}
$source = ['source_database' => 'Finance','schema_name' => 'sales'];
$tables = [
    ['table_id' => 1,'source_database' => 'Finance','schema_name' => 'sales','table_name' => 'customers'],
    ['table_id' => 2,'source_database' => 'Finance','schema_name' => 'audit','table_name' => 'customers'],
    ['table_id' => 3,'source_database' => 'Other','schema_name' => 'sales','table_name' => 'customers'],
    ['table_id' => 4,'source_database' => 'Finance','schema_name' => 'sales','table_name' => 'order.items'],
    ['table_id' => 5,'source_database' => 'Finance','schema_name' => 'CaseSchema','table_name' => 'CaseTable']];
foreach (['customers','sales.customers','Finance.sales.customers','"sales"."customers"','[sales].[customers]','`sales`.`customers`','SALES.CUSTOMERS'] as $ref)
{
    ErdCheck(ErdGenerator::Resolve($source, $ref, $tables)['id'] === 1, 'Resolve common qualified identifiers.');
}
ErdCheck(ErdGenerator::Resolve($source, 'audit.customers', $tables)['id'] === 2, 'Schema qualification chooses the correct table.');
ErdCheck(ErdGenerator::Resolve($source, '"order.items"', $tables)['id'] === 4, 'Quoted dots do not split table names.');
ErdCheck(ErdGenerator::Resolve($source, 'order.items', $tables)['id'] === 4, 'Literal dotted table names can resolve locally.');
ErdCheck(ErdGenerator::Resolve($source, 'Other.sales.customers', $tables)['id'] === null, 'Cross-database references are not guessed.');
ErdCheck(ErdGenerator::Resolve($source, '"caseschema"."casetable"', $tables)['id'] === null, 'Quoted identifiers retain case.');
ErdCheck(ErdGenerator::Resolve($source, 'caseschema.casetable', $tables)['id'] === 5, 'Unique unquoted case fallback.');
ErdCheck(ErdGenerator::Resolve(['source_database' => 'Finance','schema_name' => 'missing'], 'customers', $tables)['id'] === null, 'Ambiguous references do not create edges.');
foreach (['','a.b.c.d','"broken','schema.'] as $ref)
{
    ErdCheck(ErdGenerator::Resolve($source, $ref, $tables)['id'] === null, 'Malformed references are unresolved.');
}
$nodes = [
    ['node_key' => 'a','label' => '=2+3 <customer>','node_subtype' => 'entity','detail' => '','pos_x' => 40,'pos_y' => 40],
    ['node_key' => 'b','label' => 'orders','node_subtype' => 'entity'],
    ['node_key' => 'id','label' => 'id','node_subtype' => 'attribute','detail' => 'integer [PK] NOT NULL','parent_node_key' => 'a'],
    ['node_key' => 'fk','label' => 'customer_id','node_subtype' => 'attribute','detail' => 'numeric(10,0) [FK]','parent_node_key' => 'b']];
$edge = ['source_node_key' => 'fk','target_node_key' => 'id','label' => 'Reference','edge_style' => 'line','cardinality' => '0..N:1'];
$graph = ErdWorkbook::Graph($nodes, [$edge]);
ErdCheck($graph['edges'][0]['source_node_key'] === 'b' && $graph['edges'][0]['target_node_key'] === 'a', 'Legacy attribute endpoints upgrade to table endpoints.');
ErdCheck($graph['edges'][0]['erd_meta']['source_column_key'] === 'fk', 'Column linkage survives upgrades.');
ErdCheck($graph['nodes'][2]['erd_meta']['pk'] && !$graph['nodes'][2]['erd_meta']['nullable'], 'PK and nullability import correctly.');
ErdCheck($graph['nodes'][3]['erd_meta']['type'] === 'numeric(10,0)', 'Precision and zero scale remain intact.');
ErdCheck($graph['nodes'][0]['height'] === 90, 'Table geometry is based on its columns.');
$bad = $nodes;
$bad[] = $nodes[0];
ErdReject(fn () => ErdWorkbook::Graph($bad, []), 'Duplicate keys rejected.');
$bad = $nodes;
$bad[2]['parent_node_key'] = 'missing';
ErdReject(fn () => ErdWorkbook::Graph($bad, []), 'Orphan columns rejected.');
$bad = $nodes;
$bad[0]['node_subtype'] = 'process';
ErdReject(fn () => ErdWorkbook::Graph($bad, []), 'Non-ERD nodes rejected.');
$bad = $nodes;
$bad[0]['pos_x'] = -1;
ErdReject(fn () => ErdWorkbook::Graph($bad, []), 'Negative coordinates rejected.');
$bad = $nodes;
$bad[0]['fill_color'] = '" onload="bad';
ErdReject(fn () => ErdWorkbook::Graph($bad, []), 'Invalid colors rejected.');
$bad = $edge;
$bad['source_node_key'] = 'unknown';
ErdReject(fn () => ErdWorkbook::Graph($nodes, [$bad]), 'Missing endpoints rejected.');
$bad = $edge;
$bad['source_node_key'] = 'b';
$bad['target_node_key'] = 'a';
$bad['erd_meta'] = ['source_column_key' => 'id'];
ErdReject(fn () => ErdWorkbook::Graph($nodes, [$bad]), 'Columns cannot reference another endpoint table.');
$bad = $edge;
$bad['cardinality'] = 'bad';
ErdReject(fn () => ErdWorkbook::Graph($nodes, [$bad]), 'Invalid cardinality rejected.');
$xml = (new GraphMLBuilder('ERD', 'Fixture & <model>', $graph['nodes'], $graph['edges']))->Build();
$doc = new DOMDocument();
ErdCheck($doc->loadXML($xml), 'GraphML parses as XML.');
ErdCheck(str_contains($xml, '=2+3 &lt;customer&gt;') && str_contains($xml, '[0..N:1]'), 'Labels are escaped and cardinality exported.');
$evil = $graph['nodes'];
$evil[0]['fill_color'] = '" onload="evil';
ErdCheck((new DOMDocument())->loadXML((new GraphMLBuilder('ERD', 'Safe', $evil, []))->Build()), 'Legacy invalid colors cannot break XML.');
foreach (['https://example.invalid/path?q=1&next=2','http://localhost:8080/tool','https://example.invalid/#part'] as $url)
{
    ErdCheck(AccessLinks::SafeUrl($url) === $url, 'Safe HTTP links allowed.');
}
foreach (['javascript:alert(1)','data:text/html,bad','//example.invalid','https://user:secret@example.invalid','https://example.invalid/ bad','https://example.invalid\\@evil.invalid',str_repeat('x', 501)] as $url)
{
    ErdCheck(AccessLinks::SafeUrl($url) === null, 'Unsafe public links rejected.');
}
$fields = AccessLinks::Fields(['title' => ' Resource ','url' => 'https://example.invalid','tags' => ' one, two, one ','description' => ' Details ']);
ErdCheck($fields['title'] === 'Resource' && $fields['tags'] === 'one, two', 'Link fields normalize and deduplicate tags.');
ErdReject(fn () => AccessLinks::Fields(['title' => '','url' => 'https://example.invalid']), 'Title is required.');
ErdReject(fn () => AccessLinks::Fields(['title' => 'Link','url' => 'ftp://example.invalid']), 'Unsafe destinations cannot be saved.');
echo "ERD / Quick Links regression: {$checks} assertions passed.\n";
