<?php

/**
 * Filename: Applications.php
 * Purpose: Provide applications processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\BaseConnection;

/** Application records connect existing assets through real foreign keys. */
require_once __DIR__.'/Permissions.php';
require_once __DIR__.'/helpers.php';
class Applications
{
    public const ASSETS = [
        'server' => ['module' => 'servers','table' => 'servers','id' => 'server_id','label' => 'server_name'],
        'hosted_system' => ['module' => 'servers','table' => 'hosted_systems','id' => 'hosted_system_id','label' => 'system_name'],
        'group_system' => ['module' => 'servers','table' => 'group_systems','id' => 'group_system_id','label' => 'system_name'],
        'table' => ['module' => 'datadict','table' => 'dd_tables','id' => 'table_id','label' => 'table_name'],
        'diagram' => ['module' => 'diagrams','table' => 'diagrams','id' => 'diagram_id','label' => 'diagram_name'],
        'requirement' => ['module' => 'requirements','table' => 'requirements','id' => 'requirement_id','label' => 'title'],
        'bookmark' => ['module' => 'bookmarks','table' => 'bookmarks','id' => 'bookmark_id','label' => 'title']
    ];
    public static function Text($value, int $max, bool $isRequired = false): string
    {
        if (!is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value))
        {
            throw new InvalidArgumentException('A field is invalid or too long.');
        }
        $value = trim($value);
        if ($isRequired && $value === '')
        {
            throw new InvalidArgumentException('Complete all required fields.');
        }
        return $value;
    }
    public static function Url($value): ?string
    {
        $value = self::Text($value ?? '', 1000);
        if ($value === '')
        {
            return null;
        }
        $parts = parse_url($value);
        if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true) || isset($parts['user']) || isset($parts['pass']))
        {
            throw new InvalidArgumentException('Use an http:// or https:// URL without embedded credentials.');
        }
        return $value;
    }
    public static function Get(BaseConnection $db, int $id): array
    {
        $stmtSql = "SELECT a.*,u.first_name||' '||u.last_name owner_name,t.first_name||' '||t.last_name technical_owner_name,d.department_name FROM applications a LEFT JOIN users u ON u.user_id=a.owner_user_id LEFT JOIN users t ON t.user_id=a.technical_owner_id LEFT JOIN org_departments d ON d.department_id=a.department_id WHERE application_id=?";
        $stmt = $db->query($stmtSql, [$id]);
        $app = $stmt->getUnbufferedRow('array');
        if (!$app)
        {
            throw new OutOfBoundsException('Application not found.');
        }
        return $app;
    }
    public static function Save(BaseConnection $db, array $data, int $actor): int
    {
        $id = (int)($data['application_id'] ?? 0);
        $code = strtoupper(self::Text($data['application_code'] ?? '', 40, true));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,39}$/D', $code))
        {
            throw new InvalidArgumentException('Application code may contain letters, numbers, dots, hyphens and underscores.');
        }
        $life = $data['lifecycle'] ?? 'Active';
        $critical = $data['criticality'] ?? 'Medium';
        if (!in_array($life, ['Planned','Active','Maintenance','Retired'], true) || !in_array($critical, ['Low','Medium','High','Critical'], true))
        {
            throw new InvalidArgumentException('Choose valid application status and criticality.');
        }
        $fields = ['application_code' => $code,'application_name' => self::Text($data['application_name'] ?? '', 180, true),'description' => self::Text($data['description'] ?? '', 5000),
            'lifecycle' => $life,'criticality' => $critical,'owner_user_id' => empty($data['owner_user_id']) ? null : (int)$data['owner_user_id'],
            'technical_owner_id' => empty($data['technical_owner_id']) ? null : (int)$data['technical_owner_id'],'department_id' => empty($data['department_id']) ? null : (int)$data['department_id'],
            'repository_url' => self::Url($data['repository_url'] ?? ''),'application_url' => self::Url($data['application_url'] ?? '')];
        foreach (['owner_user_id','technical_owner_id'] as $key)
        {
            if ($fields[$key])
            {
                
                $stmt = $db->table('users')->select('1', false)->where('user_id', $fields[$key])->where('is_active', true)->get();
                if (!Database::Scalar($stmt))
                {
                    throw new InvalidArgumentException('Choose an active application owner.');
                }
            }
        }
        if ($id)
        {
            self::Get($db, $id);
            $db->table('applications')->where('application_id', $id)->set('updated_at', 'NOW()', false)->update($fields);
        }
        else
        {
            $fields['created_by'] = $actor;
            $stmt = $db->query($db->table('applications')->set($fields)->getCompiledInsert() . ' RETURNING application_id');
            $id = (int)Database::Scalar($stmt);
        }
        return $id;
    }
    public static function Candidates(BaseConnection $db, string $type): array
    {
        $asset = self::ASSETS[$type] ?? null;
        if (!$asset)
        {
            throw new InvalidArgumentException('Choose an asset type.');
        }
        Permissions::require($asset['module']);
        $where = $type === 'diagram' ? " WHERE diagram_type='ERD'" : '';
        return $db->query('SELECT '.$asset['id'].' id,'.$asset['label'].' label FROM '.$asset['table'].$where.' ORDER BY '.$asset['label'].' LIMIT 5000')->getResultArray();
    }
    public static function Links(BaseConnection $db, int $id): array
    {
        $out = [];
        foreach (self::ASSETS as $type => $asset)
        {
            if (Permissions::Can($asset['module']))
            {
                $stmtSql = 'SELECT l.asset_link_id,l.'.$asset['id'].' id,a.'.$asset['label'].' label FROM application_assets l JOIN '.$asset['table'].' a ON a.'.$asset['id'].'=l.'.$asset['id'].' WHERE l.application_id=? ORDER BY l.asset_link_id';
                $stmt = $db->query($stmtSql, [$id]);
                foreach ($stmt->getResultArray() as $row)
                {
                    $out[] = $row + ['type' => $type,'module' => $asset['module']];
                }
            }
        }
        return $out;
    }
    public static function Link(BaseConnection $db, int $id, string $type, int $assetId): void
    {
        self::Get($db, $id);
        $asset = self::ASSETS[$type] ?? null;
        if (!$asset || $assetId <= 0)
        {
            throw new InvalidArgumentException('Choose a valid asset.');
        }
        Permissions::require($asset['module']);
        $stmtSql = 'SELECT 1 FROM '.$asset['table'].' WHERE '.$asset['id'].'=?'.($type === 'diagram' ? " AND diagram_type='ERD'" : '');
        $stmt = $db->query($stmtSql, [$assetId]);
        if (!Database::Scalar($stmt))
        {
            throw new OutOfBoundsException('Asset not found.');
        }
        $db->query('INSERT INTO application_assets(application_id,'.$asset['id'].')VALUES(?,?) ON CONFLICT DO NOTHING', [$id,$assetId]);
    }
}
