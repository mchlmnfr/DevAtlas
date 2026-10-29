<?php
/**
 * Filename: OdbcCatalog.php
 * Purpose: Isolate native ODBC catalog access for Db2, Firebird and generic DSN imports.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Retain approved metadata adapters without application PDO access.
 */

final class OdbcCatalog
{
    public static function Dsn(array $connection, bool $isLegacyFirebird = false): string
    {
        $c = DatabaseEngines::Validate($connection);
        return match ($c['db_engine'])
        {
            'db2' => "DRIVER={IBM DB2 ODBC DRIVER};DATABASE={$c['database_name']};HOSTNAME={$c['host']};PORT={$c['port']};PROTOCOL=TCPIP;",
            'firebird' => 'DRIVER={' . ($isLegacyFirebird ? 'Firebird/InterBase(r) driver' : 'Firebird ODBC Driver') . "};DBNAME={$c['host']}/{$c['port']}:{$c['database_name']};CHARSET=UTF8;",
            'odbc' => $c['host'],
            default => throw new InvalidArgumentException('This database uses a native CodeIgniter driver.'),
        };
    }

    public static function Open(array $connection)
    {
        $handle = @odbc_connect(self::Dsn($connection), $connection['username'], $connection['password']);
        if (!$handle && $connection['db_engine'] === 'firebird' && in_array(odbc_error(), ['IM002', 'IM003'], true))
        {
            $handle = @odbc_connect(self::Dsn($connection, true), $connection['username'], $connection['password']);
        }
        if (!$handle)
        {
            throw new RuntimeException('ODBC connection failed. Confirm the installed driver or System DSN and connection settings.');
        }
        return $handle;
    }

    public static function Rows($connection, string $sql, array $parameters): array
    {
        foreach ($parameters as $parameter)
        {
            if (is_string($parameter) && strlen($parameter) >= 2 && $parameter[0] === "'" && str_ends_with($parameter, "'"))
            {
                throw new InvalidArgumentException('ODBC metadata names cannot begin and end with a single quote. Use metadata JSON import for this name.');
            }
        }
        $statement = @odbc_prepare($connection, $sql);
        if (!$statement || !@odbc_execute($statement, $parameters))
        {
            if ($statement)
            {
                odbc_free_result($statement);
            }
            throw new RuntimeException('The ODBC driver could not read this catalog. Use metadata JSON import for unsupported catalog operations.');
        }
        try
        {
            $rows = [];
            while ($row = odbc_fetch_array($statement))
            {
                $rows[] = array_change_key_case($row, CASE_LOWER);
                if (count($rows) > 25000)
                {
                    throw new RuntimeException('Catalog response is too large; choose a narrower schema.');
                }
            }
            return $rows;
        }
        finally
        {
            odbc_free_result($statement);
        }
    }
}
