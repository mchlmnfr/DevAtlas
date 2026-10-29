<?php
/**
 * Filename: Connection.php
 * Purpose: Open existing SQLite metadata targets through CodeIgniter with filesystem read-only flags.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Preserve strict read-only metadata imports on the native SQLite3 driver.
 */

namespace App\Database\ReadonlySQLite;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\SQLite3\Connection as SQLiteConnection;
use Throwable;

class Connection extends SQLiteConnection
{
    public function __construct(array $params)
    {
        parent::__construct($params);
        $this->DBDriver = 'SQLite3';
    }

    // Native CodeIgniter driver hook; its name and signature are required.
    public function connect(bool $persistent = false)
    {
        try
        {
            $connection = new \SQLite3($this->database, SQLITE3_OPEN_READONLY);
            $connection->enableExceptions(true);
            return $connection;
        }
        catch (Throwable $error)
        {
            throw new DatabaseException('The SQLite metadata file could not be opened read-only.', 0, $error);
        }
    }
}
