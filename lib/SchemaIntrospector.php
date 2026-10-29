<?php

/**
 * Filename: SchemaIntrospector.php
 * Purpose: Provide schema introspector processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Read-only catalog adapters; never selects application rows or executes user SQL. */
require_once __DIR__.'/DatabaseEngines.php';
require_once __DIR__.'/../config/MetadataConnection.php';
class SchemaIntrospector
{
    public const ENGINES = DatabaseEngines::IDS;
    private ?PDO $pdo = null;
    private $odbc = null;
    private string $engine;
    private string $database;

    public function __construct(string $engine, string $host, string $port, string $user, string $pass, string $dbname)
    {
        $c = DatabaseEngines::Validate(['db_engine' => $engine,'host' => $host,'port' => $port,'username' => $user,'password' => $pass,'database_name' => $dbname]);
        $this->engine = $c['db_engine'];
        $this->database = $c['database_name'];
        $handles = MetadataConnection::Open($c);
        $this->pdo = $handles['pdo'];
        $this->odbc = $handles['odbc'];
    }
    public function Engine(): string
    {
        return $this->engine;
    }
    private function Rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map(static fn ($row) => array_change_key_case($row, CASE_LOWER), $statement->fetchAll());
    }
    private function OdbcRows($statement): array
    {
        if (!$statement)
        {
            throw new RuntimeException('This ODBC driver could not return the requested metadata. Use metadata JSON import for unsupported catalog operations.');
        }
        $rows = [];
        while ($row = odbc_fetch_array($statement))
        {
            $rows[] = array_change_key_case($row, CASE_LOWER);
            if (count($rows) > 25000)
            {
                throw new RuntimeException('Catalog response is too large; choose a narrower schema.');
            }
        }
        odbc_free_result($statement);
        return $rows;
    }
    public function Ping(): string
    {
        $queries = ['postgresql' => 'SELECT version() AS version','mysql' => 'SELECT VERSION() AS version','mariadb' => 'SELECT VERSION() AS version',
            'sqlserver' => 'SELECT @@VERSION AS version','oracle' => "SELECT banner AS version FROM v\$version WHERE ROWNUM=1",'sqlite' => 'SELECT sqlite_version() AS version',
            'db2' => "SELECT service_level AS version FROM sysibmadm.env_inst_info",'firebird' => "SELECT RDB\$GET_CONTEXT('SYSTEM','ENGINE_VERSION') AS version FROM RDB\$DATABASE"];
        if ($this->engine === 'odbc')
        {
            return 'ODBC connection established; catalog support depends on the configured driver.';
        }
        try
        {
            return (string)($this->Rows($queries[$this->engine])[0]['version'] ?? 'Connected');
        }
        catch (PDOException $e)
        {
            return DatabaseEngines::Definition($this->engine)['label'].' connection established (version not accessible).';
        }
    }
    public function ListSchemas(): array
    {
        switch ($this->engine)
        {
            case 'sqlite': return ['main'];
            case 'firebird': return ['default'];
            case 'mysql': case 'mariadb': $sql = "SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys') ORDER BY schema_name";
                break;
            case 'postgresql': $sql = "SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN ('pg_catalog','information_schema') AND schema_name NOT LIKE 'pg_toast%' AND schema_name NOT LIKE 'pg_temp_%' ORDER BY schema_name";
                break;
            case 'sqlserver': $sql = "SELECT DISTINCT s.name AS schema_name FROM sys.schemas s JOIN sys.objects o ON o.schema_id=s.schema_id WHERE o.type IN ('U','V') AND o.is_ms_shipped=0 ORDER BY s.name";
                break;
            case 'oracle': $sql = 'SELECT DISTINCT owner AS schema_name FROM all_tables UNION SELECT DISTINCT owner AS schema_name FROM all_views ORDER BY schema_name';
                break;
            case 'db2': $sql = "SELECT DISTINCT tabschema AS schema_name FROM syscat.tables WHERE type IN ('T','V') AND tabschema NOT LIKE 'SYS%' ORDER BY tabschema";
                break;
            default:
                $rows = $this->OdbcRows(@odbc_tables($this->odbc, $this->database ?: null, null, null, null));
                $schemas = array_unique(array_map(static fn ($row) => trim($row['table_schem'] ?? '') ?: 'default', $rows));
                sort($schemas);
                return array_values($schemas);
        }
        return array_map(static fn ($row) => trim($row['schema_name']), $this->Rows($sql));
    }
    public function ListTables(string $schema): array
    {
        return array_column($this->ListObjects($schema, false), 'table_name');
    }
    public function ListObjects(string $schema, bool $isIncludeViews = true): array
    {
        switch ($this->engine)
        {
            case 'sqlite':
                if ($schema !== 'main')
                {
                    throw new InvalidArgumentException('SQLite imports use the main schema.');
                }
                $rows = $this->Rows("SELECT name AS table_name, CASE type WHEN 'view' THEN 'view' ELSE 'table' END AS object_type FROM sqlite_schema WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY name");
                break;
            case 'firebird':
                if ($schema !== 'default')
                {
                    throw new InvalidArgumentException('Firebird imports use the default catalog namespace.');
                }
                $rows = $this->Rows('SELECT TRIM(RDB$RELATION_NAME) AS table_name, CASE WHEN RDB$VIEW_BLR IS NULL THEN \'table\' ELSE \'view\' END AS object_type FROM RDB$RELATIONS WHERE COALESCE(RDB$SYSTEM_FLAG,0)=0 ORDER BY RDB$RELATION_NAME');
                break;
            case 'oracle': $rows = $this->Rows("SELECT table_name, 'table' AS object_type FROM all_tables WHERE owner=? UNION ALL SELECT view_name AS table_name, 'view' AS object_type FROM all_views WHERE owner=? ORDER BY table_name", [$schema,$schema]);
                break;
            case 'sqlserver': $rows = $this->Rows("SELECT o.name AS table_name, CASE o.type WHEN 'V' THEN 'view' ELSE 'table' END AS object_type FROM sys.objects o JOIN sys.schemas s ON s.schema_id=o.schema_id WHERE s.name=? AND o.type IN ('U','V') AND o.is_ms_shipped=0 ORDER BY o.name", [$schema]);
                break;
            case 'db2': $rows = $this->Rows("SELECT tabname AS table_name, CASE type WHEN 'V' THEN 'view' ELSE 'table' END AS object_type FROM syscat.tables WHERE tabschema=? AND type IN ('T','V') ORDER BY tabname", [$schema]);
                break;
            case 'odbc':
                $rows = [];
                foreach ($this->OdbcRows(@odbc_tables($this->odbc, $this->database ?: null, $schema === 'default' ? null : $schema, null, "'TABLE','VIEW'")) as $row)
                {
                    if ((trim($row['table_schem'] ?? '') ?: 'default') !== $schema)
                    {
                        continue;
                    }
                    $rows[] = ['table_name' => trim($row['table_name']),'object_type' => strtoupper(trim($row['table_type'])) === 'VIEW' ? 'view' : 'table'];
                }
                break;
            default: $rows = $this->Rows("SELECT table_name, CASE table_type WHEN 'VIEW' THEN 'view' ELSE 'table' END AS object_type FROM information_schema.tables WHERE table_schema=? AND table_type IN ('BASE TABLE','VIEW') ORDER BY table_name", [$schema]);
        }
        $rows = array_map(static fn ($row) => ['table_name' => trim($row['table_name']),'object_type' => trim($row['object_type'])], $rows);
        return array_values(array_filter($rows, static fn ($row) => $isIncludeViews || $row['object_type'] === 'table'));
    }
    public function GetColumns(string $schema, string $table): array
    {
        switch ($this->engine)
        {
            case 'sqlite': return $this->SqliteColumns($table);
            case 'odbc': return $this->OdbcColumns($schema, $table);
            case 'postgresql':
                $columns = $this->Rows('SELECT c.column_name,c.ordinal_position,pg_catalog.format_type(a.atttypid,a.atttypmod) AS data_type,c.character_maximum_length,c.numeric_precision,c.numeric_scale,c.is_nullable,c.column_default,c.is_identity FROM information_schema.columns c JOIN pg_catalog.pg_namespace n ON n.nspname=c.table_schema JOIN pg_catalog.pg_class t ON t.relnamespace=n.oid AND t.relname=c.table_name JOIN pg_catalog.pg_attribute a ON a.attrelid=t.oid AND a.attname=c.column_name WHERE c.table_schema=? AND c.table_name=? ORDER BY c.ordinal_position', [$schema,$table]);
                $keys = $this->Rows("SELECT a.attname AS column_name, c.contype AS key_type, ns.nspname AS ref_schema, r.relname AS ref_table, ra.attname AS ref_column FROM pg_catalog.pg_constraint c JOIN pg_catalog.pg_class t ON t.oid=c.conrelid JOIN pg_catalog.pg_namespace n ON n.oid=t.relnamespace CROSS JOIN LATERAL unnest(c.conkey) WITH ORDINALITY AS k(attnum,pos) JOIN pg_catalog.pg_attribute a ON a.attrelid=t.oid AND a.attnum=k.attnum LEFT JOIN pg_catalog.pg_class r ON r.oid=c.confrelid LEFT JOIN pg_catalog.pg_namespace ns ON ns.oid=r.relnamespace LEFT JOIN pg_catalog.pg_attribute ra ON ra.attrelid=c.confrelid AND ra.attnum=c.confkey[k.pos] WHERE n.nspname=? AND t.relname=? AND c.contype IN ('p','f') ORDER BY c.oid,k.pos", [$schema,$table]);
                break;
            case 'mysql': case 'mariadb':
                $columns = $this->Rows('SELECT column_name,ordinal_position,column_type AS data_type,character_maximum_length,numeric_precision,numeric_scale,is_nullable,column_default,extra FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position', [$schema,$table]);
                $keys = $this->Rows("SELECT column_name, CASE WHEN constraint_name='PRIMARY' THEN 'p' ELSE 'f' END AS key_type,referenced_table_schema AS ref_schema,referenced_table_name AS ref_table,referenced_column_name AS ref_column FROM information_schema.key_column_usage WHERE table_schema=? AND table_name=? AND (constraint_name='PRIMARY' OR referenced_table_name IS NOT NULL)", [$schema,$table]);
                break;
            case 'sqlserver':
                $columns = $this->Rows("SELECT c.name AS column_name,c.column_id AS ordinal_position,ty.name AS data_type,CASE WHEN ty.name IN ('nchar','nvarchar') AND c.max_length>0 THEN c.max_length/2 WHEN ty.name IN ('char','varchar','binary','varbinary') THEN c.max_length ELSE NULL END AS character_maximum_length,c.precision AS numeric_precision,c.scale AS numeric_scale,c.is_nullable,c.is_identity,d.definition AS column_default FROM sys.columns c JOIN sys.objects o ON o.object_id=c.object_id JOIN sys.schemas s ON s.schema_id=o.schema_id JOIN sys.types ty ON ty.user_type_id=c.user_type_id LEFT JOIN sys.default_constraints d ON d.object_id=c.default_object_id WHERE s.name=? AND o.name=? ORDER BY c.column_id", [$schema,$table]);
                $keys = $this->Rows("SELECT c.name AS column_name,'p' AS key_type,NULL AS ref_schema,NULL AS ref_table,NULL AS ref_column FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id JOIN sys.objects o ON o.object_id=i.object_id JOIN sys.schemas s ON s.schema_id=o.schema_id WHERE i.is_primary_key=1 AND s.name=? AND o.name=? UNION ALL SELECT c.name,'f',rs.name,rt.name,rc.name FROM sys.foreign_key_columns fk JOIN sys.columns c ON c.object_id=fk.parent_object_id AND c.column_id=fk.parent_column_id JOIN sys.objects o ON o.object_id=c.object_id JOIN sys.schemas s ON s.schema_id=o.schema_id JOIN sys.objects rt ON rt.object_id=fk.referenced_object_id JOIN sys.schemas rs ON rs.schema_id=rt.schema_id JOIN sys.columns rc ON rc.object_id=rt.object_id AND rc.column_id=fk.referenced_column_id WHERE s.name=? AND o.name=?", [$schema,$table,$schema,$table]);
                break;
            case 'oracle':
                $columns = $this->Rows("SELECT column_name,column_id AS ordinal_position,data_type,CASE WHEN data_type IN ('CHAR','NCHAR','VARCHAR2','NVARCHAR2') THEN char_length END AS character_maximum_length,data_precision AS numeric_precision,data_scale AS numeric_scale,nullable AS is_nullable,data_default AS column_default,identity_column AS is_identity FROM all_tab_columns WHERE owner=? AND table_name=? ORDER BY column_id", [$schema,$table]);
                $keys = $this->Rows("SELECT cc.column_name,CASE c.constraint_type WHEN 'P' THEN 'p' ELSE 'f' END AS key_type,rc.owner AS ref_schema,rc.table_name AS ref_table,rcc.column_name AS ref_column FROM all_constraints c JOIN all_cons_columns cc ON cc.owner=c.owner AND cc.constraint_name=c.constraint_name LEFT JOIN all_constraints rc ON rc.owner=c.r_owner AND rc.constraint_name=c.r_constraint_name LEFT JOIN all_cons_columns rcc ON rcc.owner=rc.owner AND rcc.constraint_name=rc.constraint_name AND rcc.position=cc.position WHERE c.owner=? AND c.table_name=? AND c.constraint_type IN ('P','R') ORDER BY cc.position", [$schema,$table]);
                break;
            case 'db2':
                $columns = $this->Rows("SELECT colname AS column_name,colno+1 AS ordinal_position,typename AS data_type,CASE WHEN typename IN ('CHARACTER','VARCHAR','CLOB','GRAPHIC','VARGRAPHIC') THEN length END AS character_maximum_length,CASE WHEN typename IN ('DECIMAL','DECFLOAT') THEN length END AS numeric_precision,scale AS numeric_scale,nulls AS is_nullable,\"DEFAULT\" AS column_default,identity AS is_identity FROM syscat.columns WHERE tabschema=? AND tabname=? ORDER BY colno", [$schema,$table]);
                $keys = $this->Rows("SELECT k.colname AS column_name,CASE t.type WHEN 'P' THEN 'p' ELSE 'f' END AS key_type,r.reftabschema AS ref_schema,r.reftabname AS ref_table,rk.colname AS ref_column FROM syscat.tabconst t JOIN syscat.keycoluse k ON k.tabschema=t.tabschema AND k.tabname=t.tabname AND k.constname=t.constname LEFT JOIN syscat.references r ON r.tabschema=t.tabschema AND r.tabname=t.tabname AND r.constname=t.constname LEFT JOIN syscat.keycoluse rk ON rk.tabschema=r.reftabschema AND rk.tabname=r.reftabname AND rk.constname=r.refkeyname AND rk.colseq=k.colseq WHERE t.tabschema=? AND t.tabname=? AND t.type IN ('P','F')", [$schema,$table]);
                break;
            default: return $this->FirebirdColumns($table);
        }
        $out = [];
        foreach ($columns as $column)
        {
            $column['is_primary_key'] = false;
            $column['is_foreign_key'] = false;
            $column['fk_ref_table'] = null;
            $column['fk_ref_column'] = null;
            foreach ($keys as $key)
            {
                if (trim($key['column_name']) === trim($column['column_name']))
                {
                    if (strtolower($key['key_type']) === 'p')
                    {
                        $column['is_primary_key'] = true;
                    }
                    elseif (!$column['is_foreign_key'])
                    {
                        $column['is_foreign_key'] = true;
                        $column['fk_ref_table'] = ($key['ref_schema'] && trim($key['ref_schema']) !== $schema ? trim($key['ref_schema']).'.' : '').trim($key['ref_table']);
                        $column['fk_ref_column'] = trim($key['ref_column']);
                    }
                }
            }
            $column['is_nullable'] = in_array(strtoupper((string)$column['is_nullable']), ['YES','Y','1','TRUE','T'], true);
            $column['is_auto_increment'] = in_array(strtoupper((string)($column['is_identity'] ?? '')), ['YES','Y','1'], true) || stripos((string)($column['extra'] ?? ''), 'auto_increment') !== false || stripos((string)($column['column_default'] ?? ''), 'nextval(') !== false;
            if (is_resource($column['column_default'] ?? null))
            {
                $column['column_default'] = stream_get_contents($column['column_default']);
            }
            $out[] = $column;
        }
        return $out;
    }
    private function SqliteColumns(string $table): array
    {
        $quote = str_replace("'", "''", $table);
        $columns = $this->Rows("PRAGMA main.table_xinfo('{$quote}')");
        $fks = $this->Rows("PRAGMA main.foreign_key_list('{$quote}')");
        $definition = $this->Rows('SELECT sql FROM sqlite_schema WHERE name=?', [$table]);
        $isWithoutRowid = (bool)preg_match('/\bWITHOUT\s+ROWID\b/i', $definition[0]['sql'] ?? '');
        $pkCount = count(array_filter($columns, static fn ($row) => (int)$row['pk'] > 0));
        $out = [];
        foreach ($columns as $column)
        {
            if ((int)($column['hidden'] ?? 0) === 1)
            {
                continue;
            }
            $fk = null;
            foreach ($fks as $candidate)
            {
                if ($candidate['from'] === $column['name'])
                {
                    $fk = $candidate;
                    break;
                }
            }
            if ($fk && $fk['to'] === null)
            {
                $referenceQuote = str_replace("'", "''", $fk['table']);
                $reference = array_values(array_filter($this->Rows("PRAGMA main.table_info('{$referenceQuote}')"), static fn ($row) => (int)$row['pk'] > 0));
                usort($reference, static fn ($a, $b) => $a['pk'] <=> $b['pk']);
                $fk['to'] = $reference[(int)$fk['seq']]['name'] ?? null;
            }
            $isIdentity = (int)$column['pk'] > 0 && $pkCount === 1 && strtoupper(trim($column['type'])) === 'INTEGER' && !$isWithoutRowid;
            $isStrict = (bool)preg_match('/\)\s*(?:WITHOUT\s+ROWID\s*,\s*)?STRICT\b/i', $definition[0]['sql'] ?? '');
            $out[] = ['column_name' => $column['name'],'ordinal_position' => (int)$column['cid'] + 1,'data_type' => $column['type'] ?: 'ANY',
                'character_maximum_length' => null,'numeric_precision' => null,'numeric_scale' => null,
                'is_nullable' => !(bool)$column['notnull'] && !$isIdentity && !(((int)$column['pk'] > 0) && ($isWithoutRowid || $isStrict)),
                'column_default' => $column['dflt_value'],'is_primary_key' => (int)$column['pk'] > 0,'is_foreign_key' => $fk !== null,
                'is_auto_increment' => $isIdentity,'fk_ref_table' => $fk['table'] ?? null,'fk_ref_column' => $fk['to'] ?? null];
        }
        return $out;
    }
    private function OdbcColumns(string $schema, string $table): array
    {
        $s = $schema === 'default' ? null : $schema;
        $catalog = $this->database ?: null;
        $columns = $this->OdbcRows(@odbc_columns($this->odbc, $catalog, $s, $table, null));
        $pks = $this->OdbcRows(@odbc_primarykeys($this->odbc, $catalog, $s, $table));
        $fks = $this->OdbcRows(@odbc_foreignkeys($this->odbc, null, null, null, $catalog, $s, $table));
        $out = [];
        foreach ($columns as $column)
        {
            if (trim($column['table_name']) !== $table || (trim($column['table_schem'] ?? '') ?: 'default') !== $schema)
            {
                continue;
            }
            $name = trim($column['column_name']);
            $fk = null;
            foreach ($fks as $key)
            {
                if (trim($key['fkcolumn_name']) === $name)
                {
                    $fk = $key;
                    break;
                }
            }
            $out[] = ['column_name' => $name,'ordinal_position' => $column['ordinal_position'] ?? count($out) + 1,'data_type' => $column['type_name'] ?: 'UNKNOWN',
                'character_maximum_length' => $column['char_octet_length'] ?? null,'numeric_precision' => $column['column_size'] ?? null,'numeric_scale' => $column['decimal_digits'] ?? null,
                'is_nullable' => (int)($column['nullable'] ?? 1) !== 0,'column_default' => $column['column_def'] ?? null,
                'is_primary_key' => in_array($name, array_map(static fn ($key) => trim($key['column_name']), $pks), true),'is_foreign_key' => $fk !== null,
                'is_auto_increment' => false,'fk_ref_table' => $fk ? ((trim($fk['pktable_schem'] ?? '') && trim($fk['pktable_schem']) !== $schema ? trim($fk['pktable_schem']).'.' : '').trim($fk['pktable_name'])) : null,'fk_ref_column' => $fk['pkcolumn_name'] ?? null];
        }
        return $out;
    }
    private function FirebirdColumns(string $table): array
    {
        $columns = $this->Rows('SELECT TRIM(r.RDB$FIELD_NAME) AS column_name,r.RDB$FIELD_POSITION+1 AS ordinal_position,f.RDB$FIELD_TYPE AS field_type,f.RDB$FIELD_SUB_TYPE AS sub_type,f.RDB$CHARACTER_LENGTH AS character_maximum_length,f.RDB$FIELD_PRECISION AS numeric_precision,f.RDB$FIELD_SCALE AS numeric_scale,r.RDB$NULL_FLAG AS null_flag,r.RDB$DEFAULT_SOURCE AS column_default,r.RDB$IDENTITY_TYPE AS identity_type FROM RDB$RELATION_FIELDS r JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME=r.RDB$FIELD_SOURCE WHERE r.RDB$RELATION_NAME=? ORDER BY r.RDB$FIELD_POSITION', [$table]);
        $keys = $this->Rows('SELECT TRIM(seg.RDB$FIELD_NAME) AS column_name,TRIM(c.RDB$CONSTRAINT_TYPE) AS key_type,TRIM(rc.RDB$RELATION_NAME) AS ref_table,TRIM(rs.RDB$FIELD_NAME) AS ref_column FROM RDB$RELATION_CONSTRAINTS c JOIN RDB$INDEX_SEGMENTS seg ON seg.RDB$INDEX_NAME=c.RDB$INDEX_NAME LEFT JOIN RDB$REF_CONSTRAINTS f ON f.RDB$CONSTRAINT_NAME=c.RDB$CONSTRAINT_NAME LEFT JOIN RDB$RELATION_CONSTRAINTS rc ON rc.RDB$CONSTRAINT_NAME=f.RDB$CONST_NAME_UQ LEFT JOIN RDB$INDEX_SEGMENTS rs ON rs.RDB$INDEX_NAME=rc.RDB$INDEX_NAME AND rs.RDB$FIELD_POSITION=seg.RDB$FIELD_POSITION WHERE c.RDB$RELATION_NAME=? AND c.RDB$CONSTRAINT_TYPE IN (\'PRIMARY KEY\',\'FOREIGN KEY\')', [$table]);
        $types = [7 => 'SMALLINT',8 => 'INTEGER',10 => 'FLOAT',12 => 'DATE',13 => 'TIME',14 => 'CHAR',16 => 'BIGINT',23 => 'BOOLEAN',27 => 'DOUBLE PRECISION',35 => 'TIMESTAMP',37 => 'VARCHAR',261 => 'BLOB'];
        $out = [];
        foreach ($columns as $column)
        {
            $column['data_type'] = in_array((int)$column['field_type'], [7,8,16,26], true) && in_array((int)$column['sub_type'], [1,2], true) ? ((int)$column['sub_type'] === 1 ? 'NUMERIC' : 'DECIMAL') : ($types[(int)$column['field_type']] ?? 'TYPE '.(int)$column['field_type']);
            $column['numeric_scale'] = $column['numeric_scale'] !== null ? abs((int)$column['numeric_scale']) : null;
            $column['is_nullable'] = !(bool)$column['null_flag'];
            $column['is_auto_increment'] = $column['identity_type'] !== null;
            $column['is_primary_key'] = false;
            $column['is_foreign_key'] = false;
            $column['fk_ref_table'] = null;
            $column['fk_ref_column'] = null;
            foreach ($keys as $key)
            {
                if ($key['column_name'] === $column['column_name'])
                {
                    if ($key['key_type'] === 'PRIMARY KEY')
                    {
                        $column['is_primary_key'] = true;
                    }
                    elseif (!$column['is_foreign_key'])
                    {
                        $column['is_foreign_key'] = true;
                        $column['fk_ref_table'] = $key['ref_table'];
                        $column['fk_ref_column'] = $key['ref_column'];
                    }
                }
            }
            if (is_resource($column['column_default']))
            {
                $column['column_default'] = stream_get_contents($column['column_default']);
            }
            $out[] = $column;
        }
        return $out;
    }
}
