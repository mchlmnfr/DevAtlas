<?php

/**
 * Filename: DataDictionary.php
 * Purpose: Provide data dictionary processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\BaseConnection;

/** Normalization, non-destructive metadata refresh and encrypted connection secrets. */
require_once __DIR__.'/DatabaseEngines.php';
final class DataDictionary
{
    public static function Text($value, int $limit, string $label, bool $isRequired = false): ?string
    {
        if ($value !== null && !is_scalar($value))
        {
            throw new InvalidArgumentException('Invalid '.$label.'.');
        }
        $value = trim((string)$value);
        if (mb_strlen($value) > $limit || str_contains($value, "\0"))
        {
            throw new InvalidArgumentException($label.' is too long or contains an invalid character.');
        }
        if ($isRequired && $value === '')
        {
            throw new InvalidArgumentException($label.' is required.');
        }
        return $value === '' ? null : $value;
    }
    public static function Integer($value, string $label, bool $isSigned = false): ?int
    {
        if ($value === null || $value === '')
        {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number > 2147483647 || $number < ($isSigned ? -2147483648 : 0))
        {
            throw new InvalidArgumentException('Invalid '.$label.'.');
        }
        return $number;
    }
    public static function Boolean($value): bool
    {
        if (in_array($value, [true,1,'1','true','t','YES','Y'], true))
        {
            return true;
        }
        if (in_array($value, [false,0,'0','false','f','NO','N',null,''], true))
        {
            return false;
        }
        throw new InvalidArgumentException('Boolean fields must be true or false.');
    }
    public static function Column(array $input, int $position = 1): array
    {
        $c = ['column_name' => self::Text($input['column_name'] ?? null, 128, 'Column name', true),
            'ordinal_position' => self::Integer($input['ordinal_position'] ?? $position, 'ordinal position'),
            'data_type' => self::Text($input['data_type'] ?? null, 8192, 'Data type', true),
            'char_length' => self::Integer($input['char_length'] ?? $input['character_maximum_length'] ?? null, 'character length', true),
            'numeric_precision' => self::Integer($input['numeric_precision'] ?? null, 'numeric precision'),
            'numeric_scale' => self::Integer($input['numeric_scale'] ?? null, 'numeric scale', true),
            'default_value' => self::Text($input['default_value'] ?? $input['column_default'] ?? null, 65535, 'Default value'),
            'fk_ref_table' => self::Text($input['fk_ref_table'] ?? null, 1000, 'Reference table'),
            'fk_ref_column' => self::Text($input['fk_ref_column'] ?? null, 1000, 'Reference column'),
            'description' => self::Text($input['description'] ?? null, 65535, 'Description'),
            'business_rule' => self::Text($input['business_rule'] ?? null, 65535, 'Business rule')];
        foreach (['is_nullable' => true,'is_primary_key' => false,'is_foreign_key' => false,'is_auto_increment' => false,'sensitive_flag' => false] as $field => $default)
        {
            $c[$field] = self::Boolean($input[$field] ?? $default);
        }
        if ($c['char_length'] !== null && $c['char_length'] < -1)
        {
            throw new InvalidArgumentException('Character length must be positive or -1 for MAX.');
        }
        return $c;
    }
    public static function Table(array $input): array
    {
        $engine = self::Text($input['source_engine'] ?? null, 30, 'Source engine');
        $type = $input['object_type'] ?? 'table';
        if (!in_array($type, ['table','view'], true))
        {
            throw new InvalidArgumentException('Object type must be table or view.');
        }
        return ['source_database' => self::Text($input['source_database'] ?? 'Manual Entry', 150, 'Database label', true),
            'schema_name' => self::Text($input['schema_name'] ?? 'public', 128, 'Schema', true),
            'table_name' => self::Text($input['table_name'] ?? null, 128, 'Table name', true),
            'source_engine' => $engine,'object_type' => $type,
            'display_name' => self::Text($input['display_name'] ?? null, 200, 'Display name'),
            'description' => self::Text($input['description'] ?? null, 65535, 'Description'),
            'business_owner' => self::Text($input['business_owner'] ?? null, 150, 'Business owner'),
            'source_system' => self::Text($input['source_system'] ?? null, 150, 'Source system'),
            'is_active' => self::Boolean($input['is_active'] ?? true)];
    }
    public static function Document(array $input): array
    {
        if (!in_array($input['format'] ?? '', ['devatlas-dictionary','analysttoolkit-dictionary'], true) || ($input['version'] ?? null) !== 1)
        {
            throw new InvalidArgumentException('Use the version 1 dictionary metadata format. Download the example for its structure.');
        }
        $tables = $input['tables'] ?? null;
        if (!is_array($tables) || !array_is_list($tables) || !$tables || count($tables) > 500)
        {
            throw new InvalidArgumentException('Metadata must contain 1 to 500 tables/views.');
        }
        $result = [];
        $seen = [];
        $total = 0;
        foreach ($tables as $table)
        {
            if (!is_array($table))
            {
                throw new InvalidArgumentException('Invalid table metadata.');
            }
            $fields = self::Table($table + ['source_database' => $input['source_database'] ?? 'Metadata import','source_engine' => $input['source_engine'] ?? null]);
            $identity = json_encode([$fields['source_database'],$fields['schema_name'],$fields['table_name']]);
            if (isset($seen[$identity]))
            {
                throw new InvalidArgumentException('Duplicate table identity in metadata.');
            }
            $seen[$identity] = true;
            $columns = $table['columns'] ?? [];
            if (!is_array($columns) || !array_is_list($columns) || count($columns) > 2000 || ($total += count($columns)) > 25000)
            {
                throw new InvalidArgumentException('Metadata exceeds 2,000 columns per table or 25,000 total columns.');
            }
            $names = [];
            $fields['columns'] = [];
            foreach ($columns as $index => $column)
            {
                if (!is_array($column))
                {
                    throw new InvalidArgumentException('Invalid column metadata.');
                }
                $column = self::Column($column, $index + 1);
                if (isset($names[$column['column_name']]))
                {
                    throw new InvalidArgumentException('Duplicate column in metadata.');
                }
                $names[$column['column_name']] = true;
                $fields['columns'][] = $column;
            }
            $result[] = $fields;
        }
        return $result;
    }
    public static function Upsert(BaseConnection $db, array $table, array $columns): array
    {
        $table = self::Table($table);
        $table['is_active'] = $table['is_active'] ? 'true' : 'false';
        $updates = [
            'source_engine' => new \CodeIgniter\Database\RawSql('COALESCE(EXCLUDED.source_engine,dd_tables.source_engine)'),
            'object_type' => new \CodeIgniter\Database\RawSql('EXCLUDED.object_type'),
            'description' => new \CodeIgniter\Database\RawSql('COALESCE(dd_tables.description,EXCLUDED.description)'),
        ];
        $builder = $db->table('dd_tables')->set($table)->onConstraint(['source_database', 'schema_name', 'table_name'])->updateFields($updates);
        $statement = $db->query($builder->getCompiledUpsert() . ' RETURNING table_id');
        $tableId = (int)Database::Scalar($statement);
        
        $existing = $db->table('dd_columns')->select('column_name', false)->where('table_id', $tableId)->get();
        $old = Database::Column($existing);
        $created = 0;
        $updated = 0;
        $names = [];
        foreach ($columns as $position => $column)
        {
            $column = self::Column($column, $position + 1);
            $names[] = $column['column_name'];
            if (in_array($column['column_name'], $old, true))
            {
                $updated++;
            }
            else
            {
                $created++;
            }
            foreach (['is_nullable','is_primary_key','is_foreign_key','is_auto_increment','sensitive_flag'] as $key)
            {
                $column[$key] = $column[$key] ? 'true' : 'false';
            }
            $column['table_id'] = $tableId;
            $keys = array_keys($column);
            $updates = [];
            foreach ($keys as $key)
            {
                if (!in_array($key, ['table_id','column_name','description','business_rule','sensitive_flag'], true))
                {
                    $updates[$key] = new \CodeIgniter\Database\RawSql('EXCLUDED.' . $db->escapeIdentifiers($key));
                }
            }
            $updates['description'] = new \CodeIgniter\Database\RawSql('COALESCE(dd_columns.description,EXCLUDED.description)');
            $updates['business_rule'] = new \CodeIgniter\Database\RawSql('COALESCE(dd_columns.business_rule,EXCLUDED.business_rule)');
            $db->table('dd_columns')->onConstraint(['table_id', 'column_name'])->updateFields($updates)->upsert($column);
        }
        return ['table_id' => $tableId,'column_count' => count($columns),'created_columns' => $created,'updated_columns' => $updated,'retained_columns' => count(array_diff($old, $names))];
    }
    public static function Encrypt(string $password): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($password, 'aes-256-gcm', hash('sha256', APP_ENCRYPTION_KEY, true), OPENSSL_RAW_DATA, $iv, $tag, 'dictionary-connection', 16);
        if ($encrypted === false)
        {
            throw new RuntimeException('Could not encrypt connection password.');
        }
        return base64_encode($iv.$tag.$encrypted);
    }
    public static function Decrypt(string $password): string
    {
        $raw = base64_decode($password, true);
        if ($raw === false || strlen($raw) < 28)
        {
            throw new RuntimeException('Saved password cannot be decrypted. Enter the password again.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', APP_ENCRYPTION_KEY, true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'dictionary-connection');
        if ($plain === false)
        {
            throw new RuntimeException('Saved password cannot be decrypted. The application encryption key may have changed.');
        }
        return $plain;
    }
    public static function FormatType(array $column): string
    {
        $type = $column['data_type'];
        if (str_contains($type, '('))
        {
            return $type;
        }
        if (($column['char_length'] ?? null) !== null)
        {
            return $type.'('.((int)$column['char_length'] === -1 ? 'MAX' : $column['char_length']).')';
        }
        if (preg_match('/^(?:numeric|decimal|number|decfloat)$/i', $type) && ($column['numeric_precision'] ?? null) !== null)
        {
            return $type.'('.$column['numeric_precision'].(($column['numeric_scale'] ?? null) !== null ? ','.$column['numeric_scale'] : '').')';
        }
        return $type;
    }
}
