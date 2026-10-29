<?php

/**
 * Filename: RecordSearch.php
 * Purpose: Provide record search processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Open personal leave record matches in the leave calendar.
 */

use CodeIgniter\Database\BaseConnection;

require_once __DIR__.'/ActionCenter.php';
/** Bounded searches of readable fields only; credentials and hidden modules are excluded. */
class RecordSearch
{
    private static function Matches(string $expression, array $words, array &$params): string
    {
        $sql = '';
        foreach ($words as $word)
        {
            $sql .= ' AND '.$expression.' ILIKE ?';
            $params[] = '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $word).'%';
        }
        return $sql;
    }
    public static function Find(BaseConnection $db, int $actor, string $query): array
    {
        $p = ActionCenter::Person($db, $actor);
        if (!$p)
        {
            throw new DomainException('Your account is unavailable.');
        }
        $query = trim($query);
        if (!mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 120 || preg_match('/[\x00-\x1F]/u', $query))
        {
            throw new InvalidArgumentException('Use up to 120 characters to search.');
        }
        if (mb_strlen($query) < 2)
        {
            return ['query' => $query,'results' => []];
        }
        $words = array_slice(preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY), 0, 8);
        $results = [];
        $add = static function (string $module, string $type, string $icon, array $rows, callable $format) use (&$results): void
        {
            foreach ($rows as $row)
            {
                [$title,$detail,$parameters] = $format($row);
                $results[] = ['module' => $module,'type' => $type,'icon' => $icon,'title' => mb_substr($title, 0, 150),'detail' => mb_substr($detail, 0, 200),
                    'href' => APP_BASE_URL.'/modules/'.$module.'/'.($module === 'codereview' ? 'history.php' : 'index.php').'?'.http_build_query($parameters)];
            }
        };
        $select = static function (string $sql, string $expression, array $params = []) use ($db, $words): array
        {
            $sql .= self::Matches($expression, $words, $params).' ORDER BY 1 LIMIT 5';
            $sSql = $sql;
            $s = $db->query($sSql, $params);
            return $s->getResultArray();
        };
        $can = static fn (string $module) => Permissions::Visible($module) && Permissions::UserCan($db, $actor, $module, 'view');
        $personModule = $can('usermgmt') ? 'usermgmt' : ($can('teammonitoring') ? 'teammonitoring' : ($can('reviewlogs') ? 'reviewlogs' : null));
        if ($personModule)
        {
            $where = '';
            $params = [];
            if ($personModule !== 'usermgmt' && $p['role_key'] !== 'admin')
            {
                if ($personModule === 'reviewlogs' && !Permissions::HasDailyLogAssignment($db, $actor))
                {
                    $where = ' AND u.user_id=?';
                    $params[] = $actor;
                }
                else
                {
                    $where = ' AND u.unit_id=?';
                    $params[] = $p['unit_id'] ?? 0;
                }
            }
            $rows = $select("SELECT u.user_id,u.employee_id,CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name,COALESCE(un.unit_name,'Unassigned') unit_name FROM users u LEFT JOIN org_units un ON un.unit_id=u.unit_id WHERE u.is_active".$where, "CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name,u.employee_id)", $params);
            $add($personModule, 'Personnel', 'user-round', $rows, static fn ($r) => [$r['employee_name'],$r['employee_id'].' · '.$r['unit_name'],['q' => $r['employee_id'],'user_id' => $r['user_id']]]);
        }
        if ($can('servers'))
        {
            $rows = $select('SELECT server_id,server_name,ip_address,environment FROM servers WHERE TRUE', "CONCAT_WS(' ',server_name,ip_address,environment)");
            $add('servers', 'Server', 'server', $rows, static fn ($r) => [$r['server_name'],$r['environment'].' · '.$r['ip_address'],['server_id' => $r['server_id']]]);
        }
        if ($can('datadict'))
        {
            $rows = $select('SELECT t.table_id,t.table_name,t.display_name,t.schema_name,t.source_database FROM dd_tables t WHERE TRUE', "CONCAT_WS(' ',t.table_name,t.display_name,t.schema_name,t.source_database)");
            $add('datadict', 'Table / view', 'database', $rows, static fn ($r) => [$r['display_name'] ?: $r['table_name'],$r['source_database'].' · '.$r['schema_name'].'.'.$r['table_name'],['table_id' => $r['table_id']]]);
            $rows = $select('SELECT c.column_id,c.column_name,t.table_id,t.table_name,t.source_database FROM dd_columns c JOIN dd_tables t ON t.table_id=c.table_id WHERE TRUE', "CONCAT_WS(' ',c.column_name,t.table_name,t.source_database)");
            $add('datadict', 'Dictionary field', 'list', $rows, static fn ($r) => [$r['column_name'],$r['source_database'].' · '.$r['table_name'],['table_id' => $r['table_id'],'column_id' => $r['column_id']]]);
        }
        if ($can('diagrams'))
        {
            $rows = $select("SELECT diagram_id,diagram_name,COALESCE(description,'') description FROM diagrams WHERE diagram_type='ERD'", "CONCAT_WS(' ',diagram_name,description)");
            $add('diagrams', 'ERD', 'workflow', $rows, static fn ($r) => [$r['diagram_name'],'Entity relationship diagram',['diagram_id' => $r['diagram_id']]]);
        }
        if ($can('codereview'))
        {
            $rows = $select('SELECT review_id,title,project,engine FROM review_runs WHERE TRUE', "CONCAT_WS(' ',title,project,engine)");
            $add('codereview', 'Code review', 'code-xml', $rows, static fn ($r) => [$r['title'],$r['project'].' · '.$r['engine'],['review_id' => $r['review_id']]]);
        }
        // The directory and links already expose these fields publicly; their unsafe URL fields are never searched.
        $rows = $select('SELECT d.department_id,d.department_name FROM org_departments d WHERE TRUE', "CONCAT_WS(' ',d.department_name,d.description,(SELECT string_agg(e.email,' ') FROM department_emails e WHERE e.department_id=d.department_id),(SELECT string_agg(n.phone_number,' ') FROM department_numbers n WHERE n.department_id=d.department_id))");
        $add('directory', 'Department contacts', 'contact', $rows, static fn ($r) => [$r['department_name'],'Call Directory',['q' => $r['department_name']]]);
        $rows = $select('SELECT bookmark_id,title,description FROM bookmarks WHERE TRUE', "CONCAT_WS(' ',title,description,tags)");
        $add('bookmarks', 'Access link', 'external-link', $rows, static fn ($r) => [$r['title'],'Shared access link',['q' => $r['title'],'bookmark_id' => $r['bookmark_id']]]);
        if ($can('dailylogs'))
        {
            $rows = $select("SELECT log_id,activity_date,status,activity_type,leave_type,actual_activity,duration_hours FROM daily_logs WHERE entry_kind='activity' AND user_id=?", "CONCAT_WS(' ',actual_activity,planned_task,remarks,activity_type)", [$actor]);
            $add('dailylogs', 'My daily log', 'notebook-pen', $rows, static fn ($r) => [$r['actual_activity'] ?: ($r['activity_type'] ?: $r['leave_type']),$r['activity_date'].' · '.$r['duration_hours'].' hrs · '.$r['status'],['log_id' => $r['log_id']]]);
        }
        if ($can('leaves'))
        {
            $rows = $select("SELECT log_id,activity_date,status,leave_type,duration_hours FROM daily_logs WHERE entry_kind='leave' AND user_id=?", "CONCAT_WS(' ',leave_type,remarks,activity_date)", [$actor]);
            $add('leaves', 'My leave application', 'calendar-days', $rows, static fn ($r) => [$r['leave_type'].' leave',$r['activity_date'].' · '.$r['duration_hours'].' hrs · '.$r['status'],['log_id' => $r['log_id'],'month' => substr($r['activity_date'], 0, 7)]]);
        }
        usort($results, static fn ($a, $b) => (int)!str_starts_with(mb_strtolower($a['title']), mb_strtolower($query)) <=> (int)!str_starts_with(mb_strtolower($b['title']), mb_strtolower($query)) ?: strcmp($a['type'], $b['type']) ?: strcasecmp($a['title'], $b['title']));
        return ['query' => $query,'results' => array_slice($results, 0, 30)];
    }
}
