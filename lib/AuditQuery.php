<?php

/**
 * Filename: AuditQuery.php
 * Purpose: Provide audit query processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\BaseConnection;

require_once __DIR__.'/Audit.php';
/** Bounded, parameterized audit history queries shared by the viewer and export. */
class AuditQuery
{
    public static function Filters(array $input): array
    {
        $out = [];
        foreach (['q' => 200,'module' => 40,'outcome' => 16,'source' => 12,'from' => 10,'to' => 10] as $key => $max)
        {
            $value = $input[$key] ?? '';
            if (!is_string($value) || mb_strlen($value) > $max)
            {
                throw new InvalidArgumentException('Invalid audit filter.');
            }
            $out[$key] = trim($value);
        }
        if ($out['module'] !== '' && !isset(Audit::Modules()[$out['module']]))
        {
            throw new InvalidArgumentException('Choose a valid module.');
        }
        if (!in_array($out['source'], ['','server','browser'], true) || !in_array($out['outcome'], ['','success','failed','denied','partial','reported','in_progress'], true))
        {
            throw new InvalidArgumentException('Choose valid audit filters.');
        }
        foreach (['from','to'] as $key)
        {
            if ($out[$key] !== '')
            {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $out[$key], new DateTimeZone('Asia/Manila'));
                if (!$date || $date->format('Y-m-d') !== $out[$key])
                {
                    throw new InvalidArgumentException('Choose a valid date.');
                }
            }
        }
        if ($out['from'] !== '' && $out['to'] !== '' && $out['from'] > $out['to'])
        {
            throw new InvalidArgumentException('The end date must follow the start date.');
        }
        foreach (['actor','anchor','page'] as $key)
        {
            $v = ($input[$key] ?? '') === '' ? ($key === 'page' ? 1 : 0) : $input[$key];
            if (!is_scalar($v) || !preg_match('/^[0-9]{1,18}$/D', (string)$v))
            {
                throw new InvalidArgumentException('Invalid audit page or user.');
            }
            $out[$key] = (int)$v;
        }
        $out['page'] = max(1, min(1000000, $out['page']));
        return $out;
    }
    private static function Where(array $f): array
    {
        $where = ['e.event_id<=?'];
        $values = [$f['anchor']];
        foreach (['module','outcome','source'] as $key)
        {
            if ($f[$key] !== '')
            {
                $where[] = 'e.'.$key.'=?';
                $values[] = $f[$key];
            }
        }
        if ($f['actor'] > 0)
        {
            $where[] = 'e.actor_id=?';
            $values[] = $f['actor'];
        }
        if ($f['from'] !== '')
        {
            $where[] = 'e.occurred_at>=?';
            $values[] = $f['from'].'T00:00:00+08:00';
        }
        if ($f['to'] !== '')
        {
            $where[] = 'e.occurred_at<?';
            $values[] = (new DateTimeImmutable($f['to'], new DateTimeZone('Asia/Manila')))->modify('+1 day')->format('Y-m-d').'T00:00:00+08:00';
        }
        if ($f['q'] !== '')
        {
            $parts = ["CONCAT_WS(' ',e.actor_name,e.actor_employee_id,e.action,REPLACE(e.action,'_',' '),e.module,e.path,e.details::TEXT,e.request_id::TEXT) ILIKE ?", "EXISTS(SELECT 1 FROM audit_changes c WHERE c.request_id=e.request_id AND CONCAT_WS(' ',c.table_name,c.record_id) ILIKE ?)"];
            $search = '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $f['q']).'%';
            $values[] = $search;
            $values[] = $search;
            foreach (['module' => Audit::Modules(),'action' => Audit::ActionLabels()] as $field => $labels)
            {
                $matches = array_keys(array_filter($labels, static fn ($label) => mb_stripos($label, $f['q']) !== false));
                if ($matches)
                {
                    $parts[] = 'e.'.$field.' IN ('.implode(',', array_fill(0, count($matches), '?')).')';
                    array_push($values, ...$matches);
                }
            }
            $where[] = '('.implode(' OR ', $parts).')';
        }
        return [implode(' AND ', $where),$values];
    }
    public static function Present(array $row): array
    {
        $row['details'] = is_string($row['details'] ?? null) ? json_decode($row['details'], true) : ($row['details'] ?? []);
        $row['event_id'] = (string)$row['event_id'];
        $row['module_label'] = Audit::Modules()[$row['module']] ?? $row['module'];
        $row['action_label'] = $row['source'] === 'browser' ? ucfirst(substr($row['action'], 3)).' · '.Audit::ControlLabel($row['details']['control'] ?? 'Control') : Audit::ActionLabel($row['action']);
        $row['change_count'] = (int)($row['change_count'] ?? 0);
        return $row;
    }
    public static function History(BaseConnection $db, array $input, bool $isExport = false): array
    {
        $f = self::Filters($input);
        if (!$f['anchor'])
        {
            $f['anchor'] = (int)Database::Scalar($db->table('audit_events')->select('COALESCE(MAX(event_id),0)', false)->get());
        }
        [$where,$values] = self::Where($f);
        $stmtSql = "SELECT COUNT(*) total,COUNT(DISTINCT actor_id) people,
            COUNT(*) FILTER(WHERE outcome IN('failed','denied','partial')) attention,
            COUNT(*) FILTER(WHERE EXISTS(SELECT 1 FROM audit_changes c WHERE c.request_id=e.request_id)) changed
            FROM audit_events e WHERE ".$where;
        $stmt = $db->query($stmtSql, $values);
        $stats = $stmt->getUnbufferedRow('array');
        foreach ($stats as &$v)
        {
            $v = (int)$v;
        }
        unset($v);
        if ($isExport && $stats['total'] > 50000)
        {
            throw new InvalidArgumentException('More than 50,000 events match. Narrow the filters before exporting.');
        }
        $size = $isExport ? 50000 : 50;
        $pages = max(1, (int)ceil($stats['total'] / $size));
        $f['page'] = min($f['page'], $pages);
        $offset = $isExport ? 0 : ($f['page'] - 1) * $size;
        $stmtSql = "SELECT e.*,(SELECT COUNT(*) FROM audit_changes c WHERE c.request_id=e.request_id) change_count FROM audit_events e WHERE ".$where." ORDER BY e.event_id DESC LIMIT ".$size." OFFSET ".$offset;
        $stmt = $db->query($stmtSql, $values);
        return ['events' => array_map([self::class,'present'], $stmt->getResultArray()),'stats' => $stats,'anchor' => (string)$f['anchor'],'page' => $f['page'],'pages' => $pages,'page_size' => $size];
    }
    public static function Detail(BaseConnection $db, int $id, int $page): array
    {
        
        $stmt = $db->table('audit_events')->where('event_id', $id)->get();
        $event = $stmt->getUnbufferedRow('array');
        if (!$event)
        {
            throw new OutOfBoundsException('Audit event not found.');
        }
        
        $stmt = $db->table('audit_changes')->select('COUNT(*)', false)->where('request_id', $event['request_id'])->get();
        $total = (int)Database::Scalar($stmt);
        $pages = max(1, (int)ceil($total / 100));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * 100;
        $stmtSql = 'SELECT change_id,occurred_at,table_name,record_id,operation,array_to_json(changed_fields)::TEXT changed_fields,before_values,after_values FROM audit_changes WHERE request_id=? ORDER BY change_id LIMIT 100 OFFSET '.$offset;
        $stmt = $db->query($stmtSql, [$event['request_id']]);
        $changes = $stmt->getResultArray();
        foreach ($changes as &$change)
        {
            foreach (['changed_fields','before_values','after_values'] as $key)
            {
                $change[$key] = json_decode($change[$key], true);
            }
        }
        unset($change);
        $event['change_count'] = $total;
        return ['event' => self::Present($event),'changes' => $changes,'change_count' => $total,'page' => $page,'pages' => $pages];
    }
}
