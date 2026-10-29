<?php

/**
 * Filename: DatabaseEngines.php
 * Purpose: Provide database engines processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Driver capabilities and validated connection settings for dictionary targets. */
final class DatabaseEngines
{
    public const IDS = ['postgresql','mysql','mariadb','sqlserver','oracle','sqlite','db2','firebird','odbc'];
    public static function Catalog(): array
    {
        $definitions = [
            ['postgresql','PostgreSQL','pgsql',5432,'public','PDO PostgreSQL (pdo_pgsql)'],
            ['mysql','MySQL','mysql',3306,'','PDO MySQL (pdo_mysql)'],
            ['mariadb','MariaDB','mysql',3306,'','PDO MySQL (pdo_mysql)'],
            ['sqlserver','SQL Server / Azure SQL','sqlsrv',1433,'dbo','Microsoft PDO SQLSRV and Microsoft ODBC Driver'],
            ['oracle','Oracle','oci',1521,'','PDO OCI and Oracle client libraries (Oracle 12c+)'],
            ['sqlite','SQLite','sqlite',0,'main','PDO SQLite; an existing SQLite file on this server'],
            ['db2','IBM Db2','ibm',50000,'','PDO IBM and IBM Db2 client libraries (Db2 LUW)'],
            ['firebird','Firebird','firebird',3050,'default','PDO Firebird and Firebird client libraries (Firebird 3+)'],
            ['odbc','Other database via ODBC','odbc',0,'','PHP ODBC and a configured System DSN; metadata support depends on the driver'],
        ];
        $drivers = PDO::getAvailableDrivers();
        return array_map(static fn ($row) => ['id' => $row[0],'label' => $row[1],'driver' => $row[2],'port' => $row[3],
            'schema' => $row[4],'requirement' => $row[5], 'available' => $row[0] === 'odbc' ? function_exists('odbc_connect') : in_array($row[2], $drivers, true)], $definitions);
    }
    public static function Definition(string $engine): array
    {
        foreach (self::Catalog() as $entry)
        {
            if ($entry['id'] === $engine)
            {
                return $entry;
            }
        }
        throw new InvalidArgumentException('Choose a supported database engine.');
    }
    public static function Validate(array $input): array
    {
        foreach (['db_engine','host','port','username','password','database_name','default_schema','label'] as $field)
        {
            if (isset($input[$field]) && !is_scalar($input[$field]))
            {
                throw new InvalidArgumentException('Invalid connection field: '.$field.'.');
            }
        }
        $engine = strtolower((string)($input['db_engine'] ?? 'postgresql'));
        $definition = self::Definition($engine);
        $result = ['db_engine' => $engine,'host' => trim((string)($input['host'] ?? '')),
            'port' => filter_var($input['port'] ?? $definition['port'], FILTER_VALIDATE_INT),
            'username' => trim((string)($input['username'] ?? '')), 'password' => (string)($input['password'] ?? ''),
            'database_name' => trim((string)($input['database_name'] ?? '')), 'default_schema' => trim((string)($input['default_schema'] ?? $definition['schema'])),
            'label' => trim((string)($input['label'] ?? ''))];
        foreach (['host' => 255,'username' => 150,'database_name' => 1000,'default_schema' => 150,'label' => 150] as $key => $limit)
        {
            if (mb_strlen($result[$key]) > $limit || preg_match('/[\x00-\x1f]/', $result[$key]))
            {
                throw new InvalidArgumentException('Invalid or overly long '.str_replace('_', ' ', $key).'.');
            }
        }
        if (strlen($result['password']) > 4096 || str_contains($result['password'], "\0"))
        {
            throw new InvalidArgumentException('Password is too long or contains an invalid character.');
        }
        if ($engine === 'sqlite')
        {
            if ($result['database_name'] === '')
            {
                throw new InvalidArgumentException('Enter the absolute path of an existing SQLite file on this server.');
            }
            if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/]{2}|/)~', $result['database_name']))
            {
                throw new InvalidArgumentException('SQLite requires an absolute server file path.');
            }
            $result['host'] = '';
            $result['port'] = 0;
            $result['username'] = '';
            $result['password'] = '';
        }
        elseif ($engine === 'odbc')
        {
            if ($result['host'] === '' || preg_match('/[;={}]/', $result['host']))
            {
                throw new InvalidArgumentException('Enter a configured ODBC System DSN name, not a connection string.');
            }
            $result['port'] = 0;
        }
        else
        {
            if ($result['host'] === '' || $result['username'] === '' || $result['database_name'] === '')
            {
                throw new InvalidArgumentException('Host, username and database/service name are required.');
            }
            if ($result['port'] === false || $result['port'] < 1 || $result['port'] > 65535)
            {
                throw new InvalidArgumentException('Port must be between 1 and 65535.');
            }
            foreach (['host','database_name'] as $key)
            {
                if (preg_match('/[;{}]/', $result[$key]))
                {
                    throw new InvalidArgumentException('Connection fields cannot contain connection-string delimiters.');
                }
            }
            if ($engine === 'oracle' && (str_contains($result['host'], '/') || str_contains($result['database_name'], '/')))
            {
                throw new InvalidArgumentException('Use a host name and an Oracle service name in their separate fields.');
            }
        }
        return $result;
    }
    public static function Dsn(array $connection): string
    {
        $c = self::Validate($connection);
        switch ($c['db_engine'])
        {
            case 'postgresql': return "pgsql:host={$c['host']};port={$c['port']};dbname={$c['database_name']};connect_timeout=5";
            case 'mysql': case 'mariadb': return "mysql:host={$c['host']};port={$c['port']};dbname={$c['database_name']};charset=utf8mb4";
            case 'sqlserver': return "sqlsrv:Server={$c['host']},{$c['port']};Database={$c['database_name']};LoginTimeout=5;Encrypt=yes;TrustServerCertificate=no";
            case 'oracle': return "oci:dbname=//{$c['host']}:{$c['port']}/{$c['database_name']};charset=AL32UTF8";
            case 'db2': return "ibm:DRIVER={IBM DB2 ODBC DRIVER};DATABASE={$c['database_name']};HOSTNAME={$c['host']};PORT={$c['port']};PROTOCOL=TCPIP;";
            case 'firebird': return "firebird:dbname={$c['host']}/{$c['port']}:{$c['database_name']};charset=UTF8";
            case 'sqlite': return 'sqlite:'.$c['database_name'];
            default: return $c['host'];
        }
    }
}
