<?php
/**
 * Filename: directory_helper.php
 * Purpose: Provide reusable Directory request validation and export helpers.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

use CodeIgniter\Database\BaseConnection;

function DirectoryId($value, string $label): int
{
    $id = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if ($id === false)
    {
        JsonError("Choose a valid {$label}.");
    }
    return (int)$id;
}

function DirectoryText($value, string $label, int $maxLength): string
{
    if (!is_string($value) || trim($value) === '')
    {
        JsonError("{$label} is required.");
    }
    $value = trim($value);
    if (mb_strlen($value) > $maxLength)
    {
        JsonError("{$label} must be {$maxLength} characters or fewer.");
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $value))
    {
        JsonError("{$label} contains invalid characters.");
    }
    return $value;
}

function DirectoryDepartments(BaseConnection $localDb, ?int $id = null, ?string $search = null): array
{
    // Deliberately select only directory fields: no users, credentials, or personnel details.
    $sql = 'SELECT dep.department_id, dep.department_name, dep.description, dep.division_id,
                   div.division_name, g.group_id, g.group_name
            FROM org_departments dep
            JOIN org_divisions div ON div.division_id = dep.division_id
            JOIN org_groups g ON g.group_id = div.group_id WHERE 1=1';
    $params = [];
    if ($id !== null)
    {
        $sql .= ' AND dep.department_id = ?';
        $params[] = $id;
    }
    if ($search !== null)
    {
        $sql .= ' AND (dep.department_name ILIKE ? OR dep.description ILIKE ?
                      OR div.division_name ILIKE ? OR g.group_name ILIKE ?
                      OR EXISTS (SELECT 1 FROM department_emails e WHERE e.department_id=dep.department_id AND e.email ILIKE ?)
                      OR EXISTS (SELECT 1 FROM department_numbers n WHERE n.department_id=dep.department_id AND n.phone_number ILIKE ?))';
        array_push($params, ...array_fill(0, 6, '%' . $search . '%'));
    }
    $sql .= ' ORDER BY LOWER(dep.department_name), dep.department_id';
    $statementSql = $sql;
    $statement = $localDb->query($statementSql, $params);
    return AttachDepartmentContacts($localDb, $statement->getResultArray());
}

function AttachDepartmentContacts(BaseConnection $localDb, array $departments): array
{
    if (!$departments)
    {
        return [];
    }
    $ids = array_column($departments, 'department_id');
    $emails = $localDb->table('department_emails')->select('email_id,department_id,email')->whereIn('department_id', $ids)->orderBy('LOWER(email),email_id', '', false)->get()->getResultArray();
    $emailMap = [];
    foreach ($emails as $email)
    {
        $emailMap[$email['department_id']][] = $email;
    }
    $numbers = $localDb->table('department_numbers')->select('number_id,department_id,phone_number,label')->whereIn('department_id', $ids)->orderBy('label,phone_number,number_id')->get()->getResultArray();
    $numberMap = [];
    foreach ($numbers as $number)
    {
        $numberMap[$number['department_id']][] = $number;
    }
    foreach ($departments as &$department)
    {
        $department['emails'] = $emailMap[$department['department_id']] ?? [];
        $department['numbers'] = $numberMap[$department['department_id']] ?? [];
    }
    unset($department);
    return $departments;
}
