<?php

/**
 * Filename: Traceability.php
 * Purpose: Provide traceability processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Shared asset-label query for the Requirements page and Excel export. */
function TraceabilityCanViewType(string $type): bool
{
    $modules = ['diagram' => 'diagrams','table' => 'datadict','column' => 'datadict','hosted_system' => 'servers','group_system' => 'servers'];
    return isset($modules[$type]) && (!class_exists('Permissions', false) || Permissions::Can($modules[$type]));
}

function TraceabilityLinksSql(): string
{
    return <<<'SQL'
SELECT rl.link_id, rl.requirement_id,
       CASE WHEN rl.diagram_id IS NOT NULL THEN 'diagram'
            WHEN rl.dd_table_id IS NOT NULL THEN 'table'
            WHEN rl.dd_column_id IS NOT NULL THEN 'column'
            WHEN rl.hosted_system_id IS NOT NULL THEN 'hosted_system'
            ELSE 'group_system' END AS asset_type,
       COALESCE(rl.diagram_id, rl.dd_table_id, rl.dd_column_id,
                rl.hosted_system_id, rl.group_system_id) AS asset_id,
       CASE WHEN d.diagram_id IS NOT NULL THEN d.diagram_name || ' (' || d.diagram_type || ')'
            WHEN dt.table_id IS NOT NULL THEN dt.source_database || ' / ' || dt.schema_name || '.' || dt.table_name
            WHEN dc.column_id IS NOT NULL THEN ct.source_database || ' / ' || ct.schema_name || '.' || ct.table_name || '.' || dc.column_name
            WHEN hs.hosted_system_id IS NOT NULL THEN hs.system_name || ' (' || s.server_name || ')'
            ELSE gs.system_name || ' (' || sg.group_name || ')' END AS asset_label
FROM requirement_links rl
LEFT JOIN diagrams d ON d.diagram_id = rl.diagram_id
LEFT JOIN dd_tables dt ON dt.table_id = rl.dd_table_id
LEFT JOIN dd_columns dc ON dc.column_id = rl.dd_column_id
LEFT JOIN dd_tables ct ON ct.table_id = dc.table_id
LEFT JOIN hosted_systems hs ON hs.hosted_system_id = rl.hosted_system_id
LEFT JOIN servers s ON s.server_id = hs.server_id
LEFT JOIN group_systems gs ON gs.group_system_id = rl.group_system_id
LEFT JOIN server_groups sg ON sg.server_group_id = gs.server_group_id
SQL;
}

/** Only these reviewed identifiers may be interpolated into asset queries. */
function TraceabilityAssetSources(): array
{
    return [
        'diagram' => ['column' => 'diagram_id', 'table' => 'diagrams',
            'sql' => "SELECT diagram_id AS id, diagram_name || ' (ERD)' AS label FROM diagrams WHERE diagram_type='ERD' ORDER BY diagram_name"],
        'table' => ['column' => 'dd_table_id', 'table' => 'dd_tables',
            'sql' => "SELECT table_id AS id, source_database || ' / ' || schema_name || '.' || table_name AS label FROM dd_tables ORDER BY source_database, schema_name, table_name"],
        'column' => ['column' => 'dd_column_id', 'table' => 'dd_columns',
            'sql' => "SELECT c.column_id AS id, t.source_database || ' / ' || t.schema_name || '.' || t.table_name || '.' || c.column_name AS label FROM dd_columns c JOIN dd_tables t ON t.table_id=c.table_id ORDER BY t.source_database, t.schema_name, t.table_name, c.ordinal_position NULLS LAST, c.column_name"],
        'hosted_system' => ['column' => 'hosted_system_id', 'table' => 'hosted_systems',
            'sql' => "SELECT h.hosted_system_id AS id, h.system_name || ' (' || s.server_name || ')' AS label FROM hosted_systems h JOIN servers s ON s.server_id=h.server_id ORDER BY h.system_name, s.server_name"],
        'group_system' => ['column' => 'group_system_id', 'table' => 'group_systems',
            'sql' => "SELECT g.group_system_id AS id, g.system_name || ' (' || sg.group_name || ')' AS label FROM group_systems g JOIN server_groups sg ON sg.server_group_id=g.server_group_id ORDER BY g.system_name, sg.group_name"],
    ];
}
