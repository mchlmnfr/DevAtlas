<?php
/**
 * Filename: QueryException.php
 * Purpose: Retain PostgreSQL constraint identities through native CodeIgniter exceptions.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Preserve SQLSTATE validation messages without exposing SQL in API responses.
 */

namespace App\Database;

use CodeIgniter\Database\Exceptions\DatabaseException;

class QueryException extends DatabaseException
{
    public function __construct(string $message, private string $sqlState)
    {
        parent::__construct($message);
    }

    public function SqlState(): string
    {
        return $this->sqlState;
    }
}
