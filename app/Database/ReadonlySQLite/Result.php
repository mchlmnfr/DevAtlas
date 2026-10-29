<?php
/**
 * Filename: Result.php
 * Purpose: Use native CodeIgniter SQLite result handling for read-only metadata targets.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Share SQLite3 result APIs with application metadata services.
 */

namespace App\Database\ReadonlySQLite;

class Result extends \CodeIgniter\Database\SQLite3\Result
{
}
