<?php
/**
 * Filename: Result.php
 * Purpose: Preserve JSON boolean and integer contracts using PostgreSQL result metadata.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Cast native pgsql values without changing decimal precision or null values.
 */

namespace App\Database\Postgre;

use CodeIgniter\Database\Postgre\Result as PostgreResult;

class Result extends PostgreResult
{
    private ?array $fieldTypes = null;

    // Native CodeIgniter result hook; its name and signature are required.
    protected function fetchAssoc()
    {
        $row = parent::fetchAssoc();
        if ($row === false)
        {
            return false;
        }
        if ($this->fieldTypes === null)
        {
            $this->fieldTypes = [];
            for ($index = 0; $index < pg_num_fields($this->resultID); $index++)
            {
                $this->fieldTypes[pg_field_name($this->resultID, $index)] = pg_field_type($this->resultID, $index);
            }
        }
        foreach ($row as $field => $value)
        {
            if ($value === null)
            {
                continue;
            }
            $type = $this->fieldTypes[$field];
            if ($type === 'bool')
            {
                $row[$field] = $value === 't';
            }
            elseif (in_array($type, ['int2', 'int4', 'int8', 'oid'], true) && filter_var($value, FILTER_VALIDATE_INT) !== false)
            {
                $row[$field] = (int) $value;
            }
            elseif (in_array($type, ['float4', 'float8'], true))
            {
                $row[$field] = (float) $value;
            }
        }
        return $row;
    }
}
