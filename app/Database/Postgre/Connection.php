<?php
/**
 * Filename: Connection.php
 * Purpose: Extend CodeIgniter PostgreSQL queries with reliable native SQLSTATE reporting.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Preserve constraint validation and typed results on the native pgsql driver.
 */

namespace App\Database\Postgre;

use App\Database\QueryException;
use CodeIgniter\Database\Postgre\Connection as PostgreConnection;

class Connection extends PostgreConnection
{
    public function __construct(array $params)
    {
        parent::__construct($params);
        $this->DBDriver = 'Postgre';
    }

    // Native CodeIgniter driver hook; its name and signature are required.
    protected function execute(string $sql)
    {
        if (!@pg_send_query($this->connID, $sql))
        {
            throw new QueryException('The database could not execute the request.', '08006');
        }
        $result = false;
        $error = null;
        while ($next = pg_get_result($this->connID))
        {
            if ($result !== false)
            {
                pg_free_result($result);
            }
            $result = $next;
            if (in_array(pg_result_status($next), [PGSQL_FATAL_ERROR, PGSQL_BAD_RESPONSE, PGSQL_NONFATAL_ERROR], true))
            {
                $error ??= new QueryException(pg_result_error($next), pg_result_error_field($next, PGSQL_DIAG_SQLSTATE) ?: 'HY000');
            }
        }
        if ($error !== null)
        {
            if ($result !== false)
            {
                pg_free_result($result);
            }
            throw $error;
        }
        return $result;
    }
}
