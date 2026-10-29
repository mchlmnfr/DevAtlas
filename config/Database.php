<?php
/**
 * Filename: Database.php
 * Purpose: Share native CodeIgniter connections and typed result helpers across application services.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Replace application PDO access with Query Builder and native transaction services.
 */

use App\Database\QueryException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Database\Exceptions\DatabaseException;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../lib/Framework.php';

final class Database
{
    public static function GetConnection(string $which = 'app'): BaseConnection
    {
        Framework::Boot();
        try
        {
            $db = Config\Database::connect($which === 'target' ? 'target' : 'default');
            $db->initialize();
            $db->transException(true)->transStrict(false);
        }
        catch (DatabaseException $error)
        {
            error_log('[DevAtlas] Database connection unavailable.');
            JsonError('Database connection failed. Confirm the database settings and PostgreSQL availability.', 500);
        }
        if ($which === 'app' && class_exists('Audit', false))
        {
            Audit::Attach($db);
        }
        return $db;
    }

    public static function Scalar(BaseResult $result, int $column = 0)
    {
        $row = $result->getUnbufferedRow('array');
        if (!$row)
        {
            return false;
        }
        $values = array_values($row);
        return array_key_exists($column, $values) ? $values[$column] : false;
    }

    public static function Column(BaseResult $result, int $column = 0): array
    {
        return array_map(static fn (array $row) => array_values($row)[$column] ?? null, $result->getResultArray());
    }

    public static function Pairs(BaseResult $result): array
    {
        $fields = $result->getFieldNames();
        return array_column($result->getResultArray(), $fields[1], $fields[0]);
    }

    public static function InTransaction(BaseConnection $db): bool
    {
        if ($db->connID instanceof PgSql\Connection)
        {
            return in_array(pg_transaction_status($db->connID), [PGSQL_TRANSACTION_INTRANS, PGSQL_TRANSACTION_INERROR, PGSQL_TRANSACTION_ACTIVE], true);
        }
        return $db->transDepth > 0;
    }

    public static function SqlState(Throwable $error): string
    {
        do
        {
            if ($error instanceof QueryException)
            {
                return $error->SqlState();
            }
            $error = $error->getPrevious();
        }
        while ($error !== null);
        return '';
    }

    public static function Ping(string $which = 'app'): array
    {
        try
        {
            $db = self::GetConnection($which);
            return ['ok' => true, 'version' => self::Scalar($db->query('SELECT version()'))];
        }
        catch (Throwable $error)
        {
            return ['ok' => false, 'error' => 'Database connectivity could not be verified.'];
        }
    }
}
