<?php

/**
 * Filename: ReviewStore.php
 * Purpose: Provide review store processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\BaseConnection;

/** Persist server-produced review metadata and findings, never source or inventory literal values. */
require_once __DIR__.'/Applications.php';
class ReviewStore
{
    public static function Save(BaseConnection $db, array $report, array $input, array $actor): int
    {
        $app = empty($input['application_id']) ? null : (int)$input['application_id'];
        if ($app)
        {
            Permissions::require('applications');
            Applications::Get($db, $app);
        }
        $project = Applications::Text($report['project'], 200, true);
        $origin = $input['mode'];
        $meta = ['files' => [],'totals' => $report['totals'],'skipped' => $report['skipped'],'findings_limited' => $report['findings_limited'],
            'function_count' => $report['function_count'],'inventory_limited' => $report['inventory_limited']];
        foreach ($report['files'] as $file)
        {
            $safe = array_intersect_key($file, array_flip(['path','type','size','modified','metrics','qms_checks','findings_limited']));
            $meta['files'][] = $safe;
        }
        foreach (['qms','owasp'] as $key)
        {
            if (isset($report[$key]))
            {
                $meta[$key] = $report[$key];
            }
        }
        $db->transBegin();
        try
        {
            
            $stmt = $db->query($db->table('review_runs')->set('application_id', $app)->set('title', $project.' review')->set('project', $project)->set('engine', $report['engine'])->set('origin', $origin)->set('created_by', $actor['user_id'])->set('creator_name', mb_substr($actor['full_name'], 0, 200))->set('file_count', $report['file_count'])->set('finding_count', $report['finding_count'])->set('report_meta', new \CodeIgniter\Database\RawSql('CAST(' . $db->escape(json_encode($meta, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) . ' AS JSONB)'))->getCompiledInsert() . ' RETURNING review_id');
            $id = (int)Database::Scalar($stmt);
            $priorSql = 'SELECT review_id FROM review_runs WHERE review_id<? AND engine=? AND origin=? AND application_id IS NOT DISTINCT FROM CAST(? AS BIGINT) AND (CAST(? AS BIGINT) IS NOT NULL OR project=?) ORDER BY review_id DESC LIMIT 1';
            $prior = $db->query($priorSql, [$id,$report['engine'],$origin,$app,$app,$project]);
            $baseline = (int)Database::Scalar($prior);
            $accepted = [];
            if ($baseline)
            {
                
                $old = $db->table('review_findings')->select('fingerprint,disposition_note', false)->where('review_id', $baseline)->where('status', 'Accepted')->get();
                $accepted = Database::Pairs($old);
            }
            
            $occurrences = [];
            foreach ($report['files'] as $file)
            {
                foreach ($file['findings'] as $finding)
                {
                    $message = $finding['rule'] === 'PHP-PARSE' ? 'PHP parse error; correct syntax and rerun the review.' : $finding['message'];
                    $key = implode("\0", [$report['engine'],$file['path'],$finding['rule'],$message]);
                    $number = $occurrences[$key] ?? 0;
                    $occurrences[$key] = $number + 1;
                    $fingerprint = hash('sha256', $key."\0".$number);
                    $insert = $db->table('review_findings')->set('review_id', $id)->set('fingerprint', $fingerprint)->set('file_path', mb_substr($file['path'], 0, 500))->set('line_number', max(1, (int)$finding['line']))->set('severity', $finding['severity'])->set('rule', mb_substr($finding['rule'], 0, 100))->set('category', $finding['category'] ?? null)->set('message', mb_substr($message, 0, 2000))->set('remediation', isset($finding['remediation']) ? mb_substr($finding['remediation'], 0, 2000) : null)->set('status', array_key_exists($fingerprint, $accepted) ? 'Accepted' : 'Open')->set('disposition_note', $accepted[$fingerprint] ?? null)->insert();
                }
            }
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
    public static function Get(BaseConnection $db, int $id): array
    {
        $stmtSql = "SELECT r.*,a.application_name,u.first_name||' '||u.last_name assignee_name FROM review_runs r LEFT JOIN applications a ON a.application_id=r.application_id LEFT JOIN users u ON u.user_id=r.assigned_to WHERE review_id=?";
        $stmt = $db->query($stmtSql, [$id]);
        $run = $stmt->getUnbufferedRow('array');
        if (!$run)
        {
            throw new OutOfBoundsException('Saved review not found.');
        }
        $run['report_meta'] = json_decode($run['report_meta'], true);
        return $run;
    }
    public static function Comparison(BaseConnection $db, int $current, int $baseline): array
    {
        $a = self::Get($db, $current);
        $b = self::Get($db, $baseline);
        if ($a['engine'] !== $b['engine'] || $a['application_id'] !== $b['application_id'] || (!$a['application_id'] && $a['project'] !== $b['project']))
        {
            throw new InvalidArgumentException('Compare runs for the same engine and application, or the same unlinked project.');
        }
        
        $stmt = $db->table('review_findings')->select('finding_id,fingerprint,file_path,line_number,severity,rule,status', false)->where('review_id', $current)->orderBy('finding_id', '', false)->get();
        $new = array_column($stmt->getResultArray(), null, 'fingerprint');
        $stmt = $db->table('review_findings')->select('finding_id,fingerprint,file_path,line_number,severity,rule,status', false)->where('review_id', $baseline)->orderBy('finding_id', '', false)->get();
        $old = array_column($stmt->getResultArray(), null, 'fingerprint');
        return ['current' => $current,'baseline' => $baseline,'new' => array_values(array_diff_key($new, $old)),'continuing' => array_values(array_intersect_key($new, $old)),
            'not_detected' => array_values(array_diff_key($old, $new)),'note' => 'Not detected means absent from these scan results, not a verified fix. Results depend on file selection, scan limits and pattern coverage. Fingerprints match path, rule, message and occurrence order, not source context.'];
    }
    public static function Report(BaseConnection $db, int $id): array
    {
        $run = self::Get($db, $id);
        $meta = $run['report_meta'];
        $files = [];
        foreach ($meta['files'] as $file)
        {
            $file['findings'] = [];
            $files[$file['path']] = $file;
        }
        
        $stmt = $db->table('review_findings')->select('file_path,line_number line,severity,rule,category,message,remediation', false)->where('review_id', $id)->orderBy('finding_id', '', false)->get();
        foreach ($stmt->getResultArray() as $finding)
        {
            $path = $finding['file_path'];
            unset($finding['file_path']);
            if (isset($files[$path]))
            {
                $files[$path]['findings'][] = $finding;
            }
        }
        return ['project' => $run['project'],'engine' => $run['engine'],'files' => array_values($files),'file_count' => $run['file_count'],'finding_count' => $run['finding_count'],'totals' => $meta['totals'],
            'skipped' => $meta['skipped'],'findings_limited' => $meta['findings_limited'],'inventory' => [],'function_count' => $meta['function_count'],'inventory_limited' => true] + array_intersect_key($meta, array_flip(['qms','owasp']));
    }
}
