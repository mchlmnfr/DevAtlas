<?php
/**
 * Filename: Builder.php
 * Purpose: Retain the native SQLite Query Builder for read-only catalog connections.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Use CodeIgniter result and identifier handling for metadata targets.
 */

namespace App\Database\ReadonlySQLite;

class Builder extends \CodeIgniter\Database\SQLite3\Builder
{
}
