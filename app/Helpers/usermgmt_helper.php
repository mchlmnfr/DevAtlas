<?php
/**
 * Filename: usermgmt_helper.php
 * Purpose: Provide reusable Usermgmt request validation and export helpers.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

use CodeIgniter\Database\BaseConnection;

function GuardUserChange(BaseConnection $localDb, int $id, ?string $role = null, ?bool $isActive = null, bool $isDelete = false): void
{
    try
    {
        Permissions::GuardAccount($localDb, $id, $role, $isActive, $isDelete);
    }
    catch (InvalidArgumentException $e)
    {
        if (Database::InTransaction($localDb))
        {
            $localDb->transRollback();
        }
        JsonError($e->getMessage(), 422);
    }
    catch (OutOfBoundsException $e)
    {
        if (Database::InTransaction($localDb))
        {
            $localDb->transRollback();
        }
        JsonError($e->getMessage(), 404);
    }
}

function UserBirthday($value): ?string
{
    if ($value === null || $value === '')
    {
        return null;
    }
    $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
    $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    if (!$date || $date->format('Y-m-d') !== $value || $value < '1900-01-01' || $value > $today)
    {
        JsonError('Enter a valid birthday between 1900 and today.', 422);
    }
    return $value;
}

function ValidateUserPlacement(BaseConnection $localDb, array $data): array
{
    $levels = [
        'group_id' => ['org_groups', null],
        'division_id' => ['org_divisions', 'group_id'],
        'department_id' => ['org_departments', 'division_id'],
        'unit_id' => ['org_units', 'department_id'],
        'position_id' => ['org_positions', 'unit_id'],
    ];
    $ids = [];
    foreach ($levels as $key => [$table, $parent])
    {
        $raw = $data[$key] ?? null;
        if ($raw === null || $raw === '')
        {
            $ids[$key] = null;
            continue;
        }
        if (!is_scalar($raw) || !preg_match('/^[1-9][0-9]*$/', (string)$raw))
        {
            JsonError('Invalid organization selection.');
        }
        $ids[$key] = (int)$raw;
        if ($parent !== null && $ids[$parent] === null)
        {
            JsonError('Select each parent level before a child level.');
        }
        $column = $parent ?? $key;
        $stmtSql = "SELECT {$column} FROM {$table} WHERE {$key} = ?";
        $stmt = $localDb->query($stmtSql, [$ids[$key]]);
        $found = Database::Scalar($stmt);
        if ($found === false || ($parent !== null && (int)$found !== $ids[$parent]))
        {
            JsonError('The selected organization levels do not belong together. Refresh the form and try again.');
        }
    }
    return $ids;
}
