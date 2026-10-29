<?php
/**
 * Filename: organization_helper.php
 * Purpose: Provide reusable Organization request validation and export helpers.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;

function OrgName($value, string $label, int $maxLength): string
{
    if (!is_scalar($value))
    {
        JsonError("{$label} is required.");
    }
    $name = CleanStr($value);
    if ($name === null)
    {
        JsonError("{$label} is required.");
    }
    if (mb_strlen($name) > $maxLength)
    {
        JsonError("{$label} must be {$maxLength} characters or fewer.");
    }
    return $name;
}

function DeleteOrgNode(BaseConnection $localDb, string $table, string $idColumn, int $id, array $blockers): void
{
    if ($id <= 0)
    {
        JsonError('Choose a valid organization item.');
    }
    $localDb->transBegin();
    try
    {
        $lockSql = "SELECT 1 FROM {$table} WHERE {$idColumn} = ? FOR UPDATE";
        $lock = $localDb->query($lockSql, [$id]);
        if (!Database::Scalar($lock))
        {
            $localDb->transRollback();
            JsonError('Organization item not found.', 404);
        }
        foreach ($blockers as [$childTable, $foreignKey, $label])
        {
            $checkSql = "SELECT 1 FROM {$childTable} WHERE {$foreignKey} = ? LIMIT 1";
            $check = $localDb->query($checkSql, [$id]);
            if (Database::Scalar($check))
            {
                $localDb->transRollback();
                JsonError("Remove or reassign {$label} before deleting this item.", 409);
            }
        }
        $localDb->query("DELETE FROM {$table} WHERE {$idColumn} = ?", [$id]);
        $localDb->transCommit();
    }
    catch (DatabaseException $e)
    {
        if (Database::InTransaction($localDb))
        {
            $localDb->transRollback();
        }
        if (Database::SqlState($e) === '23503')
        {
            JsonError('This organization item is still in use.', 409);
        }
        throw $e;
    }
}
