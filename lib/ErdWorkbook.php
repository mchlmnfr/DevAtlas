<?php

/**
 * Filename: ErdWorkbook.php
 * Purpose: Provide erd workbook processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Validate ERD workbook snapshots before replacing any saved model. */
require_once __DIR__.'/ErdGenerator.php';
class ErdWorkbook
{
    public const NODES = ['node_key','label','node_subtype','detail','pos_x','pos_y','width','height','fill_color','parent_node_key','erd_meta'];
    public const EDGES = ['source_node_key','target_node_key','label','edge_style','cardinality','erd_meta'];
    public static function Rows($sheet, array $required, int $limit): array
    {
        if (!$sheet)
        {
            throw new InvalidArgumentException('The workbook must contain Nodes and Edges sheets.');
        }
        if ($sheet->getHighestDataRow() > $limit + 1 || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > 20)
        {
            throw new InvalidArgumentException('Workbook dimensions exceed the ERD limits.');
        }
        // Never calculate user-provided formulas or external references.
        foreach ($sheet->getRowIterator() as $row)
        {
            foreach ($row->getCellIterator() as $cell)
            {
                if ($cell->getDataType() === 'f')
                {
                    throw new InvalidArgumentException('Formula cells are not supported. Paste values before importing.');
                }
            }
        }
        $data = $sheet->toArray(null, false, false, false);
        $headers = array_map(static fn ($v) => strtolower(trim((string)$v)), array_shift($data) ?? []);
        if (count(array_unique($headers)) !== count($headers))
        {
            throw new InvalidArgumentException('Workbook headers must be unique.');
        }
        foreach ($required as $name)
        {
            if (!in_array($name, $headers, true))
            {
                throw new InvalidArgumentException('Missing workbook column: '.$name);
            }
        }
        $rows = [];
        foreach ($data as $row)
        {
            if (!array_filter($row, static fn ($v) => $v !== null && $v !== ''))
            {
                continue;
            }
            $item = [];
            foreach ($headers as $i => $name)
            {
                $item[$name] = $row[$i] ?? null;
            }
            $rows[] = $item;
        }
        return $rows;
    }
    private static function Number($value, float $default, float $min, float $max): float
    {
        if ($value === null || $value === '')
        {
            return $default;
        }
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < $min || (float)$value > $max)
        {
            throw new InvalidArgumentException('Invalid workbook geometry.');
        }
        return (float)$value;
    }
    public static function Graph(array $nodeRows, array $edgeRows): array
    {
        if (count($nodeRows) > 5050 || count($edgeRows) > 5000)
        {
            throw new InvalidArgumentException('The workbook exceeds ERD limits.');
        }
        $nodes = [];
        $keys = [];
        $entities = 0;
        $columns = 0;
        foreach ($nodeRows as $row)
        {
            $key = ErdGenerator::Text($row['node_key'] ?? '', 50, 'Node key') ?: 'import_'.bin2hex(random_bytes(8));
            if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $key) || isset($keys[$key]))
            {
                throw new InvalidArgumentException('Node keys must be unique letters, digits, dots, hyphens or underscores.');
            }
            $kind = $row['node_subtype'] ?? '';
            if (!in_array($kind, ['entity','attribute'], true))
            {
                throw new InvalidArgumentException('ERDs support entity and attribute nodes only.');
            }
            $kind === 'entity' ? $entities++ : $columns++;
            $meta = ErdGenerator::Meta($row['erd_meta'] ?? []);
            $detail = ErdGenerator::Text($row['detail'] ?? '', 5000, 'Detail');
            if ($kind === 'attribute')
            {
                // The visible detail is the editable representation in Excel.
                $meta['pk'] = (bool)preg_match('/\[PK\]/i', $detail);
                $meta['fk'] = (bool)preg_match('/\[FK\]/i', $detail);
                $meta['nullable'] = !$meta['pk'] && !preg_match('/\bNOT\s+NULL\b/i', $detail);
                $meta['type'] = trim(preg_replace('/\[(?:PK|FK)\]|\bNOT\s+NULL\b/i', '', $detail)) ?: 'text';
            }
            $color = $row['fill_color'] ?? '#FFFFFF';
            if (!$color)
            {
                $color = '#FFFFFF';
            }
            if (!preg_match('/^#[A-Fa-f0-9]{6}$/D', $color))
            {
                throw new InvalidArgumentException('Fill colors must be six-digit hex colors.');
            }
            $node = ['node_key' => $key,'label' => ErdGenerator::Text($row['label'] ?? '', 255, 'Label', true),'node_subtype' => $kind,'detail' => $detail,
                'pos_x' => self::Number($row['pos_x'] ?? null, 40, 0, 200000),'pos_y' => self::Number($row['pos_y'] ?? null, 40, 0, 200000),
                'width' => self::Number($row['width'] ?? null, 320, 20, 5000),'height' => self::Number($row['height'] ?? null, 90, 20, 125000),
                'fill_color' => $color,'parent_node_key' => ErdGenerator::Text($row['parent_node_key'] ?? '', 50, 'Parent key') ?: null,'erd_meta' => $meta];
            $keys[$key] = $node;
            $nodes[] = $node;
        }
        if ($entities > 50 || $columns > 5000)
        {
            throw new InvalidArgumentException('An ERD supports 50 tables and 5,000 columns.');
        }
        foreach ($nodes as $node)
        {
            $parent = $node['parent_node_key'];
            if ($node['node_subtype'] === 'attribute' && (!$parent || !isset($keys[$parent]) || $keys[$parent]['node_subtype'] !== 'entity'))
            {
                throw new InvalidArgumentException('Every attribute must belong to a table entity.');
            }
            if ($node['node_subtype'] === 'entity' && $parent)
            {
                throw new InvalidArgumentException('Table entities cannot have parents.');
            }
        }
        $edges = [];
        foreach ($edgeRows as $row)
        {
            $source = ErdGenerator::Text($row['source_node_key'] ?? '', 50, 'Source key', true);
            $target = ErdGenerator::Text($row['target_node_key'] ?? '', 50, 'Target key', true);
            if (!isset($keys[$source],$keys[$target]))
            {
                throw new InvalidArgumentException('Relationship endpoints must exist in Nodes.');
            }
            $meta = ErdGenerator::Meta($row['erd_meta'] ?? []);
            // Upgrade older workbooks whose relationship endpoints were attribute rows.
            foreach ([['source_column_key',&$source],['target_column_key',&$target]] as &$end)
            {
                if ($keys[$end[1]]['node_subtype'] === 'attribute')
                {
                    $meta[$end[0]] = $end[1];
                    $end[1] = $keys[$end[1]]['parent_node_key'];
                }
            }
            unset($end);
            foreach ([['source_column_key',$source],['target_column_key',$target]] as [$field,$parent])
            {
                if (!empty($meta[$field]) && (!is_string($meta[$field]) || !isset($keys[$meta[$field]]) || $keys[$meta[$field]]['parent_node_key'] !== $parent))
                {
                    throw new InvalidArgumentException('Relationship columns must belong to their endpoint tables.');
                }
            }
            $style = ($row['edge_style'] ?? '') ?: 'line';
            if (!in_array($style, ['line','dashed','flow'], true))
            {
                throw new InvalidArgumentException('Invalid relationship style.');
            }
            $card = ErdGenerator::Text($row['cardinality'] ?? '', 20, 'Cardinality');
            if ($card !== '' && !in_array($card, ['0..N:1','0..N:0..1','0..1:1','0..1:0..1','1:1','1:N','N:1','N:M'], true))
            {
                throw new InvalidArgumentException('Unsupported cardinality.');
            }
            $edges[] = ['source_node_key' => $source,'target_node_key' => $target,'label' => ErdGenerator::Text($row['label'] ?? '', 255, 'Relationship label'),'edge_style' => $style,'cardinality' => $card,'erd_meta' => $meta];
        }
        foreach ($nodes as &$node)
        {
            if ($node['node_subtype'] === 'entity')
            {
                $node['height'] = max(90, 66 + 24 * count(array_filter($nodes, static fn ($child) => $child['parent_node_key'] === $node['node_key'])));
            }
        }
        unset($node);
        return ['nodes' => $nodes,'edges' => $edges];
    }
}
