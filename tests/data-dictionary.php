<?php

/**
 * Filename: data-dictionary.php
 * Purpose: Verify data dictionary behavior using isolated regression fixtures.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;

/** CLI fixtures: metadata normalization, engine DSNs, secrets and real SQLite catalogs. */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
require_once __DIR__.'/../lib/SchemaIntrospector.php';
require_once __DIR__.'/../lib/DataDictionary.php';
if (!defined('APP_ENCRYPTION_KEY'))
{
    define('APP_ENCRYPTION_KEY', 'dictionary-regression-key-2026');
}
set_error_handler(static function ($severity, $message, $file, $line)
{
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$assertions = 0;
function DdCheck(bool $isCondition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$isCondition)
    {
        throw new RuntimeException($message);
    }
}
function DdReject(callable $operation, string $message): void
{
    try
    {
        $operation();
    }
    catch (InvalidArgumentException $error)
    {
        DdCheck(true, $message);
        return;
    }
    DdCheck(false, $message);
}
$base = ['host' => 'db.internal','username' => 'reader','password' => 'secret','database_name' => 'sample'];
foreach (DatabaseEngines::Catalog() as $engine)
{
    DdCheck(is_bool($engine['available']), 'Availability is explicit.');
    $input = $base + ['db_engine' => $engine['id']];
    if ($engine['id'] === 'sqlite')
    {
        $input['database_name'] = __FILE__;
    }
    if ($engine['id'] === 'odbc')
    {
        $input['host'] = 'Fixture System DSN';
    }
    $validated = DatabaseEngines::Validate($input);
    DdCheck($validated['port'] === $engine['port'], 'Engine default port.');
    DdCheck(!str_contains(DatabaseEngines::Dsn($input), 'secret'), 'DSNs contain no credentials.');
}
foreach ([['db_engine' => 'unsupported'],['db_engine' => 'postgresql','host' => 'host;password=bad'],['db_engine' => 'sqlserver','database_name' => 'db;Encrypt=no'],['db_engine' => 'odbc','host' => 'Driver={SQL Server}'],['db_engine' => 'mysql','port' => 70000]] as $bad)
{
    DdReject(fn () => DatabaseEngines::Validate($bad + $base), 'Malformed settings rejected.');
}
DdCheck(str_contains(DatabaseEngines::Dsn(['db_engine' => 'sqlserver'] + $base), 'Encrypt=yes;TrustServerCertificate=no'), 'SQL Server verifies TLS certificates.');
DdReject(fn () => DatabaseEngines::Validate(['db_engine' => ['sqlite']] + $base), 'Malformed array connection field rejected.');
DdReject(fn () => DatabaseEngines::Validate(['db_engine' => 'sqlite','database_name' => 'relative.sqlite']), 'Relative SQLite file path rejected.');
DdCheck(str_contains(DatabaseEngines::Dsn(['db_engine' => 'oracle'] + $base), '//db.internal:1521/sample'), 'Oracle service-name DSN.');
DdCheck(DataDictionary::Column(['column_name' => 'amount','data_type' => 'NUMERIC','numeric_scale' => '0'])['numeric_scale'] === 0, 'Zero scale preserved.');
DdCheck(DataDictionary::Column(['column_name' => 'name','data_type' => 'varchar','char_length' => -1])['char_length'] === -1, 'MAX length preserved.');
DdCheck(DataDictionary::FormatType(['data_type' => 'numeric','numeric_precision' => 10,'numeric_scale' => 0]) === 'numeric(10,0)', 'Formatted zero scale.');
DdCheck(DataDictionary::FormatType(['data_type' => 'integer','numeric_precision' => 32]) === 'integer', 'Integer bit precision is not a display length.');
DdCheck(DataDictionary::FormatType(['data_type' => 'VARCHAR(50)','char_length' => 50]) === 'VARCHAR(50)', 'Already formatted type is not duplicated.');
DdCheck(DataDictionary::FormatType(['data_type' => 'nvarchar','char_length' => -1]) === 'nvarchar(MAX)', 'SQL Server MAX formatted.');
DdReject(fn () => DataDictionary::Column(['column_name' => 'x','data_type' => 'INTEGER','numeric_precision' => 'no']), 'Bad numeric values rejected.');
DdReject(fn () => DataDictionary::Column(['column_name' => 'x','data_type' => 'INTEGER','is_nullable' => 'maybe']), 'Bad booleans rejected.');
DdReject(fn () => DataDictionary::Table(['table_name' => 'x','object_type' => 'procedure']), 'Unsupported object type rejected.');
$document = ['format' => 'analysttoolkit-dictionary','version' => 1,'source_database' => 'Fixture','source_engine' => 'mongodb','tables' => [['schema_name' => 'collections','table_name' => 'customers','columns' => [['column_name' => 'profile.email','data_type' => 'string','is_nullable' => true,'sensitive_flag' => true]]]]];
$tables = DataDictionary::Document($document);
DdCheck(DataDictionary::Document(array_replace($document, ['format' => 'devatlas-dictionary'])) === $tables, 'DevAtlas exports and legacy metadata imports share the same version 1 structure.');
DdCheck($tables[0]['source_engine'] === 'mongodb' && $tables[0]['columns'][0]['sensitive_flag'], 'Portable metadata supports arbitrary engine labels and dotted fields.');
DdReject(fn () => DataDictionary::Document(['format' => 'invalid']), 'Unknown metadata format rejected.');
$duplicate = $document;
$duplicate['tables'][] = $duplicate['tables'][0];
DdReject(fn () => DataDictionary::Document($duplicate), 'Duplicate table identity rejected.');
$duplicate = $document;
$duplicate['tables'][0]['columns'][] = $duplicate['tables'][0]['columns'][0];
DdReject(fn () => DataDictionary::Document($duplicate), 'Duplicate columns rejected.');
DdReject(fn () => DataDictionary::Document(array_replace($document, ['version' => 2])), 'Unsupported version rejected.');
$secret = DataDictionary::Encrypt('fixture-secret');
DdCheck(DataDictionary::Decrypt($secret) === 'fixture-secret', 'Connection encryption round trip.');
DdCheck(DataDictionary::Encrypt('fixture-secret') !== $secret, 'Encryption uses a fresh nonce.');
DdCheck(DataDictionary::Decrypt(DataDictionary::Encrypt('')) === '', 'Empty password round trip.');
$raw = base64_decode($secret);
$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
try
{
    DataDictionary::Decrypt(base64_encode($raw));
    DdCheck(false, 'Tampering rejected.');
}
catch (RuntimeException $error)
{
    DdCheck(true, 'Tampering rejected.');
}
$path = __DIR__.'/.tmp-dictionary-'.getmypid().'.sqlite';
try
{
    $sqlite = new BaseConnection('sqlite:'.$path);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->query('CREATE TABLE parent (id INTEGER PRIMARY KEY, name TEXT NOT NULL); CREATE TABLE child (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES parent, amount NUMERIC(10,0) DEFAULT 0, calculated TEXT GENERATED ALWAYS AS (parent_id || id) VIRTUAL); CREATE VIEW child_view AS SELECT id,parent_id FROM child; CREATE TABLE composite (a INTEGER,b INTEGER,PRIMARY KEY(a,b)) WITHOUT ROWID; CREATE TABLE "odd\'name" ("field\'name" TEXT);');
    $sqlite = null;
    $before = hash_file('sha256', $path);
    $intro = new SchemaIntrospector('sqlite', '', '0', '', '', $path);
    DdCheck($intro->ListSchemas() === ['main'], 'SQLite schema list.');
    DdCheck(count($intro->ListObjects('main')) === 5 && count($intro->ListTables('main')) === 4, 'Table/view discovery.');
    $columns = $intro->GetColumns('main', 'child');
    $byName = array_column($columns, null, 'column_name');
    DdCheck($byName['id']['is_auto_increment'] && !$byName['id']['is_nullable'], 'SQLite rowid identity recognized.');
    DdCheck($byName['parent_id']['fk_ref_table'] === 'parent' && $byName['parent_id']['fk_ref_column'] === 'id', 'Implicit SQLite FK target column resolved.');
    DdCheck($byName['amount']['data_type'] === 'NUMERIC(10,0)' && $byName['amount']['column_default'] === '0', 'SQLite full type and default retained.');
    DdCheck(isset($byName['calculated']), 'Generated column included.');
    $columns = $intro->GetColumns('main', 'composite');
    DdCheck(!$columns[0]['is_auto_increment'] && !$columns[0]['is_nullable'], 'WITHOUT ROWID composite key handled.');
    DdCheck($intro->GetColumns('main', "odd'name")[0]['column_name'] === "field'name", 'Quoted identifiers handled safely.');
    $property = new ReflectionProperty(SchemaIntrospector::class, 'pdo');
    $property->setAccessible(true);
    $connection = $property->getValue($intro);
    try
    {
        $connection->query('CREATE TABLE forbidden (id INTEGER)');
        DdCheck(false, 'Target is read-only.');
    }
    catch (DatabaseException $error)
    {
        DdCheck(true, 'Target is read-only.');
    }
    $connection = null;
    $intro = null;
    DdCheck(hash_file('sha256', $path) === $before, 'Introspection leaves the file unchanged.');
    DdReject(fn () => new SchemaIntrospector('sqlite', '', '0', '', '', __FILE__), 'Non-SQLite files rejected.');
    DdReject(fn () => new SchemaIntrospector('sqlite', '', '0', '', '', $path.'.missing'), 'Missing file rejected without creation.');
    DdCheck(!file_exists($path.'.missing'), 'Missing SQLite file was not created.');
}
finally
{
    $sqlite = null;
    $connection = null;
    $intro = null;
    if (file_exists($path))
    {
        unlink($path);
    }
}
echo "Dictionary regression fixtures: {$assertions} assertions passed.\n";
