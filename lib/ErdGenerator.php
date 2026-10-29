<?php

/**
 * Filename: ErdGenerator.php
 * Purpose: Provide erd generator processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\BaseConnection;

/** ERD snapshots from dictionary metadata; no live target connection or table data is read. */
require_once __DIR__ . '/DataDictionary.php';

class ErdGenerator
{
    public const MAX_TABLES = 50;
    public const MAX_COLUMNS = 5000;
    public const MAX_EDGES = 5000;

    public static function Meta($value): array
    {
        if (is_string($value))
        {
            $value = json_decode($value, true);
        }
        return is_array($value) && (!array_is_list($value) || !$value) ? $value : [];
    }
    public static function Truth($value): bool
    {
        return in_array($value, [true,1,'1','t','true'], true);
    }
    public static function Text($value, int $max, string $label, bool $isRequired = false): string
    {
        if ($value === null)
        {
            $value = '';
        }
        if (!is_string($value) || mb_strlen($value) > $max || str_contains($value, "\0"))
        {
            throw new InvalidArgumentException($label . ' is invalid or too long.');
        }
        $value = trim($value);
        if ($isRequired && $value === '')
        {
            throw new InvalidArgumentException('Enter ' . strtolower($label) . '.');
        }
        return $value;
    }
    public static function Catalog(BaseConnection $db): array
    {
        $rows = $db->query("SELECT t.table_id,t.source_database,t.schema_name,t.table_name,t.display_name,t.object_type,t.is_active,
            COUNT(c.column_id)::INTEGER AS column_count,COUNT(c.column_id) FILTER (WHERE c.is_primary_key)::INTEGER AS primary_keys,
            COUNT(c.column_id) FILTER (WHERE c.is_foreign_key)::INTEGER AS foreign_keys
            FROM dd_tables t LEFT JOIN dd_columns c ON c.table_id=t.table_id GROUP BY t.table_id
            ORDER BY t.source_database,t.schema_name,t.table_name,t.table_id")->getResultArray();
        foreach ($rows as &$row)
        {
            foreach (['table_id','column_count','primary_keys','foreign_keys'] as $field)
            {
                $row[$field] = (int)$row[$field];
            }
            $row['is_active'] = self::Truth($row['is_active']);
        }
        return $rows;
    }
    /** Parse common quoted SQL identifiers without splitting dots inside quotes. */
    private static function Identifiers(string $value): array
    {
        $parts = [];
        $part = '';
        $quote = null;
        $length = strlen($value);
        for ($i = 0;$i < $length;$i++)
        {
            $char = $value[$i];
            if ($quote !== null)
            {
                if ($char === $quote)
                {
                    if ($i + 1 < $length && $value[$i + 1] === $quote)
                    {
                        $part .= $char;
                        $i++;
                    }
                    else
                    {
                        $quote = null;
                    }
                }
                else
                {
                    $part .= $char;
                }
            }
            elseif (in_array($char, ['"','`','['], true))
            {
                $quote = $char === '[' ? ']' : $char;
            }
            elseif ($char === '.')
            {
                $parts[] = trim($part);
                $part = '';
            }
            else
            {
                $part .= $char;
            }
        }
        if ($quote !== null)
        {
            return [];
        }
        $parts[] = trim($part);
        return in_array('', $parts, true) ? [] : $parts;
    }
    /** Resolve only within the same dictionary database label; ambiguity never creates a relationship. */
    public static function Resolve(array $source, string $reference, array $tables): array
    {
        $parts = self::Identifiers(trim($reference));
        if (!$parts || count($parts) > 3)
        {
            return ['id' => null,'reason' => 'The referenced table name is missing or malformed.'];
        }
        if (count($parts) === 3)
        {
            if ($parts[0] !== $source['source_database'])
            {
                return ['id' => null,'reason' => 'Cross-database references need an explicit manual relationship.'];
            }
            array_shift($parts);
        }
        $candidates = array_values(array_filter($tables, static fn ($table) => $table['source_database'] === $source['source_database']));
        $match = static function (array $table, bool $isFold = false) use ($parts, $source, $reference): bool
        {
            $eq = static fn ($a, $b) => $isFold ? mb_strtolower($a) === mb_strtolower($b) : $a === $b;
            if (count($parts) === 2)
            {
                return ($eq($table['schema_name'], $parts[0]) && $eq($table['table_name'], $parts[1]))
                    || (!strpbrk($reference, '"`[') && $eq($table['schema_name'], $source['schema_name']) && $eq($table['table_name'], $reference));
            }
            return $eq($table['table_name'], $parts[0]);
        };
        $exact = array_values(array_filter($candidates, static fn ($table) => $match($table)));
        if (count($parts) === 1)
        {
            $local = array_values(array_filter($exact, static fn ($table) => $table['schema_name'] === $source['schema_name']));
            if ($local)
            {
                $exact = $local;
            }
        }
        if (!$exact && !strpbrk($reference, '"`['))
        {
            $exact = array_values(array_filter($candidates, static fn ($table) => $match($table, true)));
            if (count($parts) === 1)
            {
                $local = array_values(array_filter($exact, static fn ($table) => mb_strtolower($table['schema_name']) === mb_strtolower($source['schema_name'])));
                if ($local)
                {
                    $exact = $local;
                }
            }
        }
        if (count($exact) === 1)
        {
            return ['id' => (int)$exact[0]['table_id'],'reason' => null];
        }
        return ['id' => null,'reason' => $exact ? 'The reference matches more than one documented table. Qualify its schema.' : 'The referenced table is not documented in this dictionary database.'];
    }
    public static function Build(BaseConnection $db, array $ids, bool $isRelated): array
    {
        if (!array_is_list($ids) || !$ids || count($ids) > self::MAX_TABLES)
        {
            throw new InvalidArgumentException('Select between 1 and 50 dictionary tables.');
        }
        foreach ($ids as $id)
        {
            if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D', (string)$id))
            {
                throw new InvalidArgumentException('Choose valid dictionary tables.');
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $all = $db->table('dd_tables')->orderBy('source_database,schema_name,table_name,table_id', '', false)->get()->getResultArray();
        $tables = array_column($all, null, 'table_id');
        foreach ($ids as $id)
        {
            if (!isset($tables[$id]))
            {
                throw new OutOfBoundsException('A selected dictionary table no longer exists. Refresh the selection.');
            }
        }
        $selected = array_fill_keys($ids, true);
        $columns = [];
        $processed = [];
        $total = 0;
        do
        {
            $batch = array_values(array_diff(array_keys($selected), array_keys($processed)));
            if (!$batch)
            {
                break;
            }
            $stmtSql = 'SELECT * FROM dd_columns WHERE table_id IN ('.implode(',', array_fill(0, count($batch), '?')).') ORDER BY table_id,ordinal_position NULLS LAST,column_id';
            $stmt = $db->query($stmtSql, $batch);
            foreach ($batch as $id)
            {
                $columns[$id] = [];
                $processed[$id] = true;
            }
            foreach ($stmt->getResultArray() as $column)
            {
                $columns[(int)$column['table_id']][] = $column;
                $total++;
            }
            if ($total > self::MAX_COLUMNS)
            {
                throw new InvalidArgumentException('This selection exceeds 5,000 columns. Generate smaller ERDs.');
            }
            if ($isRelated)
            {
                foreach ($batch as $id)
                {
                    foreach ($columns[$id] as $column)
                    {
                        if (self::Truth($column['is_foreign_key']))
                        {
                            $target = self::Resolve($tables[$id], $column['fk_ref_table'] ?? '', $all);
                            if ($target['id'] !== null)
                            {
                                $selected[$target['id']] = true;
                            }
                        }
                    }
                }
            }
            if (count($selected) > self::MAX_TABLES)
            {
                throw new InvalidArgumentException('Referenced tables expand the selection beyond 50 tables. Narrow it or turn off Include referenced tables.');
            }
        }
        while ($isRelated);
        $nodes = [];
        $edges = [];
        $issues = [];
        $columnKeys = [];
        foreach ($all as $table)
        {
            $id = (int)$table['table_id'];
            if (!isset($selected[$id]))
            {
                continue;
            }
            $key = 'dd_t'.$id;
            $items = $columns[$id];
            $nodes[] = ['node_key' => $key,'label' => $table['table_name'],'node_subtype' => 'entity','detail' => $table['description'] ?? '',
                'pos_x' => 0,'pos_y' => 0,'width' => 320,'height' => max(90, 66 + count($items) * 24),'fill_color' => '#FFFFFF','parent_node_key' => null,
                'erd_meta' => ['source' => 'dictionary','table_id' => $id,'source_database' => $table['source_database'],'schema_name' => $table['schema_name'],'table_name' => $table['table_name'],'object_type' => $table['object_type']]];
            if (!$items)
            {
                $issues[] = ['table' => $table['schema_name'].'.'.$table['table_name'],'column' => '','message' => 'No columns are documented for this table.'];
            }
            foreach ($items as $column)
            {
                $columnKey = 'dd_c'.(int)$column['column_id'];
                $columnKeys[$id][$column['column_name']] = $columnKey;
                $type = DataDictionary::FormatType($column);
                $pk = self::Truth($column['is_primary_key']);
                $fk = self::Truth($column['is_foreign_key']);
                $nullable = self::Truth($column['is_nullable']);
                $nodes[] = ['node_key' => $columnKey,'label' => $column['column_name'],'node_subtype' => 'attribute','detail' => $type.($pk ? ' [PK]' : '').($fk ? ' [FK]' : '').(!$nullable ? ' NOT NULL' : ''),
                    'pos_x' => 0,'pos_y' => 0,'width' => 304,'height' => 24,'fill_color' => '#FFFFFF','parent_node_key' => $key,
                    'erd_meta' => ['source' => 'dictionary','column_id' => (int)$column['column_id'],'type' => $type,'pk' => $pk,'fk' => $fk,'nullable' => $nullable,'auto_increment' => self::Truth($column['is_auto_increment'])]];
            }
        }
        foreach (array_keys($selected) as $id)
        {
            foreach ($columns[$id] as $column)
            {
                if (!self::Truth($column['is_foreign_key']))
                {
                    continue;
                }
                $target = self::Resolve($tables[$id], $column['fk_ref_table'] ?? '', $all);
                $reason = $target['reason'];
                $targetId = $target['id'];
                $reference = $column['fk_ref_column'] ?? '';
                $parts = self::Identifiers($reference);
                $reference = count($parts) === 1 ? $parts[0] : $reference;
                if (!$reason && !isset($selected[$targetId]))
                {
                    $reason = 'The referenced table is outside this ERD selection.';
                }
                if (!$reason && ($reference === '' || !isset($columnKeys[$targetId][$reference])))
                {
                    $reason = 'The referenced column is missing or not documented in the target table.';
                }
                if ($reason)
                {
                    $issues[] = ['table' => $tables[$id]['schema_name'].'.'.$tables[$id]['table_name'],'column' => $column['column_name'],'message' => $reason];
                    continue;
                }
                $pkCount = count(array_filter($columns[$id], static fn ($item) => self::Truth($item['is_primary_key'])));
                $childMax = self::Truth($column['is_primary_key']) && $pkCount === 1 ? '1' : 'N';
                $edges[] = ['source_node_key' => 'dd_t'.$id,'target_node_key' => 'dd_t'.$targetId,'label' => $column['column_name'],'edge_style' => 'line',
                    'cardinality' => '0..'.$childMax.':'.(self::Truth($column['is_nullable']) ? '0..1' : '1'),
                    'erd_meta' => ['source' => 'dictionary','source_column_key' => 'dd_c'.(int)$column['column_id'],'target_column_key' => $columnKeys[$targetId][$reference],'cardinality_inferred' => true]];
            }
        }
        if (count($edges) > self::MAX_EDGES)
        {
            throw new InvalidArgumentException('This selection exceeds 5,000 relationships.');
        }
        $nodes = self::Layout($nodes);
        return ['nodes' => $nodes,'edges' => $edges,'generation_meta' => ['source' => 'dictionary','requested_table_ids' => $ids,'table_ids' => array_map('intval', array_keys($selected)),
            'include_related' => $isRelated,'generated_at' => Date(DATE_ATOM),'issues' => $issues,
            'cardinality_note' => 'Cardinality is inferred from column nullability and single-column primary keys. Unique constraints and composite foreign-key groupings are not recorded in the dictionary; verify these in the source database.']];
    }
    public static function Layout(array $nodes): array
    {
        $entities = array_filter($nodes, static fn ($node) => $node['node_subtype'] === 'entity');
        $perRow = max(1, (int)ceil(sqrt(count($entities))));
        $index = 0;
        $y = 40;
        $rowHeight = 0;
        foreach ($nodes as &$node)
        {
            if ($node['node_subtype'] === 'entity')
            {
                if ($index > 0 && $index % $perRow === 0)
                {
                    $y += $rowHeight + 100;
                    $rowHeight = 0;
                }
                $node['pos_x'] = 40 + ($index % $perRow) * 440;
                $node['pos_y'] = $y;
                $rowHeight = max($rowHeight, (float)$node['height']);
                $index++;
            }
        }
        return $nodes;
    }
    public static function Get(BaseConnection $db, int $id, bool $isLock = false): array
    {
        $stmtSql = "SELECT * FROM diagrams WHERE diagram_id=? AND diagram_type='ERD'".($isLock ? ' FOR UPDATE' : '');
        $stmt = $db->query($stmtSql, [$id]);
        $diagram = $stmt->getUnbufferedRow('array');
        if (!$diagram)
        {
            throw new OutOfBoundsException('ERD not found.');
        }
        $diagram['generation_meta'] = self::Meta($diagram['generation_meta'] ?? []);
        foreach (['nodes' => 'diagram_nodes','edges' => 'diagram_edges'] as $key => $table)
        {
            $stmtSql = 'SELECT * FROM '.$table.' WHERE diagram_id=? ORDER BY '.($key === 'nodes' ? 'node_id' : 'edge_id');
            $stmt = $db->query($stmtSql, [$id]);
            $diagram[$key] = $stmt->getResultArray();
            foreach ($diagram[$key] as &$row)
            {
                $row['erd_meta'] = self::Meta($row['erd_meta'] ?? []);
            }
            unset($row);
        }
        return $diagram;
    }
    public static function InsertGraph(BaseConnection $db, int $id, array $graph): void
    {
        
        foreach ($graph['nodes'] as $node)
        {
            $node['diagram_id'] = $id;
            $node['erd_meta'] = json_encode($node['erd_meta'] ?? [], JSON_THROW_ON_ERROR);
            $stmt = $db->table('diagram_nodes')->set('diagram_id', ($node)['diagram_id'])->set('node_key', ($node)['node_key'])->set('label', ($node)['label'])->set('node_subtype', ($node)['node_subtype'])->set('detail', ($node)['detail'])->set('pos_x', ($node)['pos_x'])->set('pos_y', ($node)['pos_y'])->set('width', ($node)['width'])->set('height', ($node)['height'])->set('fill_color', ($node)['fill_color'])->set('parent_node_key', ($node)['parent_node_key'])->set('erd_meta', new \CodeIgniter\Database\RawSql('CAST(' . $db->escape(($node)['erd_meta']) . ' AS JSONB)'))->insert();
        }
        
        foreach ($graph['edges'] as $edge)
        {
            $edge['diagram_id'] = $id;
            $edge['erd_meta'] = json_encode($edge['erd_meta'] ?? [], JSON_THROW_ON_ERROR);
            $stmt = $db->table('diagram_edges')->set('diagram_id', ($edge)['diagram_id'])->set('source_node_key', ($edge)['source_node_key'])->set('target_node_key', ($edge)['target_node_key'])->set('label', ($edge)['label'])->set('edge_style', ($edge)['edge_style'])->set('cardinality', ($edge)['cardinality'])->set('erd_meta', new \CodeIgniter\Database\RawSql('CAST(' . $db->escape(($edge)['erd_meta']) . ' AS JSONB)'))->insert();
        }
    }
    public static function Generate(BaseConnection $db, array $data): int
    {
        $name = self::Text($data['diagram_name'] ?? '', 200, 'ERD name', true);
        $description = self::Text($data['description'] ?? '', 5000, 'Description');
        if (!is_array($data['table_ids'] ?? null))
        {
            throw new InvalidArgumentException('Choose dictionary tables.');
        }
        $db->transBegin();
        try
        {
            $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $graph = self::Build($db, $data['table_ids'], self::Truth($data['include_related'] ?? true));
            
            $stmt = $db->query($db->table('diagrams')->set('diagram_type', 'ERD')->set('diagram_name', $name)->set('description', $description)->set('generation_meta', new \CodeIgniter\Database\RawSql('CAST(' . $db->escape(json_encode($graph['generation_meta'], JSON_THROW_ON_ERROR)) . ' AS JSONB)'))->getCompiledInsert() . ' RETURNING diagram_id');
            $id = (int)Database::Scalar($stmt);
            self::InsertGraph($db, $id, $graph);
            $db->transCommit();
            return $id;
        }
        catch (Throwable $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            throw $e;
        }
    }
    private static function Touch(BaseConnection $db, int $id): void
    {
        $db->query("UPDATE diagrams SET updated_at=NOW(),generation_meta=generation_meta||'{\"edited\":true:}'::JSONB WHERE diagram_id=?", [$id]);
    }
    public static function SaveLayout(BaseConnection $db, int $id, $positions): void
    {
        if (!is_array($positions) || !array_is_list($positions) || count($positions) > self::MAX_TABLES)
        {
            throw new InvalidArgumentException('Send at most 50 table positions.');
        }
        $db->transBegin();
        try
        {
            $diagram = self::Get($db, $id, true);
            $entities = [];
            foreach ($diagram['nodes'] as $node)
            {
                if ($node['node_subtype'] === 'entity')
                {
                    $entities[$node['node_key']] = true;
                }
            }
            $seen = [];
            
            foreach ($positions as $position)
            {
                $key = $position['node_key'] ?? '';
                if (!is_string($key) || !isset($entities[$key]) || isset($seen[$key]))
                {
                    throw new InvalidArgumentException('A table position is invalid or duplicated.');
                }
                $seen[$key] = true;
                foreach (['pos_x','pos_y'] as $field)
                {
                    if (!is_numeric($position[$field] ?? null) || !is_finite((float)$position[$field]) || (float)$position[$field] < 0 || (float)$position[$field] > 200000)
                    {
                        throw new InvalidArgumentException('Table coordinates must be between 0 and 200,000.');
                    }
                }
                $stmt = $db->table('diagram_nodes')->set('pos_x', (float)$position['pos_x'])->set('pos_y', (float)$position['pos_y'])->where('diagram_id', $id)->where('node_key', $key)->update();
            }
            self::Touch($db, $id);
            $db->transCommit();
        }
        catch (Throwable $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            throw $e;
        }
    }
    public static function SaveEntity(BaseConnection $db, int $id, array $data): string
    {
        $label = self::Text($data['label'] ?? '', 255, 'Table name', true);
        $detail = self::Text($data['detail'] ?? '', 5000, 'Description');
        $input = $data['columns'] ?? [];
        if (!is_array($input) || !array_is_list($input) || count($input) > 2000)
        {
            throw new InvalidArgumentException('A table can have up to 2,000 columns.');
        }
        $key = self::Text($data['node_key'] ?? '', 50, 'Table reference');
        $db->transBegin();
        try
        {
            $diagram = self::Get($db, $id, true);
            $old = null;
            $children = [];
            $entities = 0;
            $otherColumns = 0;
            foreach ($diagram['nodes'] as $node)
            {
                if ($node['node_subtype'] === 'entity')
                {
                    $entities++;
                }
                if ($node['node_key'] === $key && $node['node_subtype'] === 'entity')
                {
                    $old = $node;
                }
                if ($node['parent_node_key'] === $key)
                {
                    $children[$node['node_key']] = $node;
                }
                elseif ($node['node_subtype'] === 'attribute')
                {
                    $otherColumns++;
                }
            }
            if ($key !== '' && !$old)
            {
                throw new OutOfBoundsException('Table not found in this ERD.');
            }
            if (!$old && $entities >= self::MAX_TABLES)
            {
                throw new InvalidArgumentException('An ERD can contain up to 50 tables.');
            }
            if ($otherColumns + count($input) > self::MAX_COLUMNS)
            {
                throw new InvalidArgumentException('An ERD can contain up to 5,000 columns.');
            }
            $key = $key ?: 'entity_'.bin2hex(random_bytes(8));
            $keep = [];
            $names = [];
            $attrs = [];
            foreach ($input as $column)
            {
                if (!is_array($column))
                {
                    throw new InvalidArgumentException('Invalid column.');
                }
                $name = self::Text($column['name'] ?? '', 128, 'Column name', true);
                if (isset($names[$name]))
                {
                    throw new InvalidArgumentException('Column names must be unique within a table.');
                }
                $names[$name] = true;
                $columnKey = self::Text($column['node_key'] ?? '', 50, 'Column reference');
                if ($columnKey !== '' && !isset($children[$columnKey]))
                {
                    throw new InvalidArgumentException('A column does not belong to this table.');
                }
                $columnKey = $columnKey ?: 'column_'.bin2hex(random_bytes(8));
                if (isset($keep[$columnKey]))
                {
                    throw new InvalidArgumentException('Duplicate column reference.');
                }
                $keep[$columnKey] = true;
                $meta = $children[$columnKey]['erd_meta'] ?? [];
                $meta['type'] = self::Text($column['type'] ?? 'text', 500, 'Data type', true);
                $meta['pk'] = self::Truth($column['pk'] ?? false);
                $meta['fk'] = self::Truth($column['fk'] ?? false);
                $meta['nullable'] = !$meta['pk'] && self::Truth($column['nullable'] ?? true);
                $attrs[] = ['node_key' => $columnKey,'label' => $name,'node_subtype' => 'attribute','detail' => $meta['type'].($meta['pk'] ? ' [PK]' : '').($meta['fk'] ? ' [FK]' : '').(!$meta['nullable'] ? ' NOT NULL' : ''),'pos_x' => 0,'pos_y' => 0,'width' => 304,'height' => 24,'fill_color' => '#FFFFFF','parent_node_key' => $key,'erd_meta' => $meta];
            }
            $meta = $old['erd_meta'] ?? ['source' => 'manual'];
            $meta['edited'] = true;
            $height = max(90, 66 + count($attrs) * 24);
            if ($old)
            {
                $db->table('diagram_nodes')->set('label', $label)->set('detail', $detail)->set('height', $height)->set('erd_meta', new \CodeIgniter\Database\RawSql('CAST(' . $db->escape(json_encode($meta)) . ' AS JSONB)'))->where('diagram_id', $id)->where('node_key', $key)->update();
            }
            else
            {
                self::InsertGraph($db, $id, ['nodes' => [['node_key' => $key,'label' => $label,'node_subtype' => 'entity','detail' => $detail,'pos_x' => 40 + ($entities % 3) * 440,'pos_y' => 40 + (intdiv($entities, 3) * 400),'width' => 320,'height' => $height,'fill_color' => '#FFFFFF','parent_node_key' => null,'erd_meta' => $meta]],'edges' => []]);
            }
            foreach ($children as $childKey => $child)
            {
                if (!isset($keep[$childKey]))
                {
                    $db->query("DELETE FROM diagram_edges WHERE diagram_id=? AND (erd_meta->>'source_column_key'=? OR erd_meta->>'target_column_key'=? OR source_node_key=? OR target_node_key=?)", [$id,$childKey,$childKey,$childKey,$childKey]);
                }
            }
            $db->table('diagram_nodes')->where('diagram_id', $id)->where('parent_node_key', $key)->delete();
            self::InsertGraph($db, $id, ['nodes' => $attrs,'edges' => []]);
            self::Touch($db, $id);
            $db->transCommit();
            return $key;
        }
        catch (Throwable $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            throw $e;
        }
    }
    public static function DeleteEntity(BaseConnection $db, int $id, string $key): void
    {
        $db->transBegin();
        try
        {
            $diagram = self::Get($db, $id, true);
            $entity = array_filter($diagram['nodes'], static fn ($n) => $n['node_key'] === $key && $n['node_subtype'] === 'entity');
            if (!$entity)
            {
                throw new OutOfBoundsException('Table not found.');
            }
            $keys = [$key];
            foreach ($diagram['nodes'] as $node)
            {
                if ($node['parent_node_key'] === $key)
                {
                    $keys[] = $node['node_key'];
                }
            }
            $stmtSql = 'DELETE FROM diagram_edges WHERE diagram_id=? AND (source_node_key=? OR target_node_key=?)';
            foreach ($keys as $item)
            {
                $stmt = $db->query($stmtSql, [$id,$item,$item]);
            }
            $db->query('DELETE FROM diagram_nodes WHERE diagram_id=? AND (node_key=? OR parent_node_key=?)', [$id,$key,$key]);
            self::Touch($db, $id);
            $db->transCommit();
        }
        catch (Throwable $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            throw $e;
        }
    }
    public static function SaveRelationship(BaseConnection $db, int $id, array $data): int
    {
        $edgeId = (int)($data['edge_id'] ?? 0);
        $source = self::Text($data['source_node_key'] ?? '', 50, 'Source table', true);
        $target = self::Text($data['target_node_key'] ?? '', 50, 'Target table', true);
        $sourceColumn = self::Text($data['source_column_key'] ?? '', 50, 'Source column');
        $targetColumn = self::Text($data['target_column_key'] ?? '', 50, 'Target column');
        $label = self::Text($data['label'] ?? '', 255, 'Relationship label');
        $cardinality = $data['cardinality'] ?? '0..N:1';
        if (!in_array($cardinality, ['0..N:1','0..N:0..1','0..1:1','0..1:0..1','1:1','1:N','N:1','N:M'], true))
        {
            throw new InvalidArgumentException('Choose a supported cardinality.');
        }
        $db->transBegin();
        try
        {
            $diagram = self::Get($db, $id, true);
            $nodes = array_column($diagram['nodes'], null, 'node_key');
            foreach ([$source,$target] as $key)
            {
                if (!isset($nodes[$key]) || $nodes[$key]['node_subtype'] !== 'entity')
                {
                    throw new InvalidArgumentException('Both relationship tables must be in this ERD.');
                }
            }
            foreach ([[$sourceColumn,$source],[$targetColumn,$target]] as [$column,$parent])
            {
                if ($column !== '' && (!isset($nodes[$column]) || $nodes[$column]['node_subtype'] !== 'attribute' || $nodes[$column]['parent_node_key'] !== $parent))
                {
                    throw new InvalidArgumentException('A relationship column does not belong to its table.');
                }
            }
            if (!$edgeId && count($diagram['edges']) >= self::MAX_EDGES)
            {
                throw new InvalidArgumentException('This ERD has reached the relationship limit.');
            }
            $old = null;
            foreach ($diagram['edges'] as $edge)
            {
                if ((int)$edge['edge_id'] === $edgeId)
                {
                    $old = $edge;
                }
                elseif ($edge['source_node_key'] === $source && $edge['target_node_key'] === $target && ($edge['erd_meta']['source_column_key'] ?? '') === $sourceColumn && ($edge['erd_meta']['target_column_key'] ?? '') === $targetColumn)
                {
                    throw new InvalidArgumentException('This relationship already exists.');
                }
            }
            if ($edgeId && !$old)
            {
                throw new OutOfBoundsException('Relationship not found in this ERD.');
            }
            $meta = ['source' => 'manual','source_column_key' => $sourceColumn,'target_column_key' => $targetColumn];
            if ($edgeId)
            {
                $db->table('diagram_edges')->set('source_node_key', $source)->set('target_node_key', $target)->set('label', $label)->set('cardinality', $cardinality)->set('erd_meta', new \CodeIgniter\Database\RawSql('CAST(' . $db->escape(json_encode($meta)) . ' AS JSONB)'))->where('edge_id', $edgeId)->where('diagram_id', $id)->update();
            }
            else
            {
                
                $stmt = $db->query($db->table('diagram_edges')->set('diagram_id', $id)->set('source_node_key', $source)->set('target_node_key', $target)->set('label', $label)->set('edge_style', 'line')->set('cardinality', $cardinality)->set('erd_meta', new \CodeIgniter\Database\RawSql('CAST(' . $db->escape(json_encode($meta)) . ' AS JSONB)'))->getCompiledInsert() . ' RETURNING edge_id');
                $edgeId = (int)Database::Scalar($stmt);
            }
            self::Touch($db, $id);
            $db->transCommit();
            return $edgeId;
        }
        catch (Throwable $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            throw $e;
        }
    }
}
