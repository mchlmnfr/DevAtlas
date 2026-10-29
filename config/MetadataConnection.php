<?php

/**
 * Filename: MetadataConnection.php
 * Purpose: Open configured metadata connections separately from catalog queries and output.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Centralized driver checks, timeouts and read-only SQLite connection setup.
 */
require_once __DIR__ . '/../lib/DatabaseEngines.php';
final class MetadataConnection
{
    public static function Open(array $c): array
    {
        $pdo = null;
        $odbc = null;
        $definition = DatabaseEngines::Definition($c['db_engine']);
        if (!$definition['available'])
        {
            throw new RuntimeException('Driver unavailable on this server. Required: '.$definition['requirement'].'. You can use metadata JSON import instead.');
        }
        try
        {
            if ($c['db_engine'] === 'odbc')
            {
                $odbc = @odbc_connect($c['host'], $c['username'], $c['password']);
                if (!$odbc)
                {
                    throw new RuntimeException('ODBC connection failed.');
                }
                return ['pdo' => null, 'odbc' => $odbc];
            }
            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
            if (in_array($c['db_engine'], ['postgresql','mysql','mariadb','sqlite'], true))
            {
                $options[PDO::ATTR_TIMEOUT] = 5;
            }
            if ($c['db_engine'] === 'sqlite')
            {
                $path = realpath($c['database_name']);
                if (!$path || !is_file($path) || !is_readable($path))
                {
                    throw new InvalidArgumentException('SQLite file does not exist or is not readable by the web server.');
                }
                $handle = fopen($path, 'rb');
                $magic = fread($handle, 16);
                fclose($handle);
                if ($magic !== "SQLite format 3\0")
                {
                    throw new InvalidArgumentException('The selected server file is not a SQLite database.');
                }
                $c['database_name'] = $path;
                $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
            }
            $pdo = new PDO(DatabaseEngines::Dsn($c), $c['username'], $c['password'], $options);
            if ($c['db_engine'] === 'sqlite')
            {
                $pdo->exec('PRAGMA query_only = ON');
            }
            if (in_array($c['db_engine'], ['postgresql','mysql','mariadb','sqlite'], true))
            {
                $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
            }
        }
        catch (InvalidArgumentException $e)
        {
            throw $e;
        }
        catch (Throwable $e)
        {
            throw new RuntimeException('Connection failed. Check the host, port, database/service, credentials, network access and driver configuration.');
        }

        return ['pdo' => $pdo, 'odbc' => $odbc];
    }
}
