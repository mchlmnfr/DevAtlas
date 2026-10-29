<?php

/**
 * Filename: Roles.php
 * Purpose: Provide roles processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Give new roles department calendar viewing without automatic leave approval.
 */

use CodeIgniter\Database\BaseConnection;

/** Role definitions and permission profiles. Endpoints restrict administration to Administrators. */
require_once __DIR__.'/Permissions.php';
class Roles
{
    public const SYSTEM_KEYS = ['admin','analyst','reviewer','viewer','section_head','department_head'];

    public static function Catalog(BaseConnection $db): array
    {
        return $db->query('SELECT r.role_key,r.role_name,r.description,r.is_system,COUNT(u.user_id)::INTEGER user_count
            FROM app_roles r LEFT JOIN users u ON u.role_key=r.role_key
            GROUP BY r.role_key ORDER BY r.is_system DESC,LOWER(r.role_name),r.role_key')->getResultArray();
    }

    public static function ValidKey($key): bool
    {
        return is_string($key) && preg_match('/^[a-z][a-z0-9_]{0,19}$/D', $key) === 1;
    }

    private static function Locked(BaseConnection $db, $key): array
    {
        if (!self::ValidKey($key))
        {
            throw new InvalidArgumentException('Choose a valid role.');
        }
        
        $stmt = $db->query($db->table('app_roles')->where('role_key', $key)->getCompiledSelect() . ' FOR UPDATE');
        $role = $stmt->getUnbufferedRow('array');
        if (!$role)
        {
            throw new OutOfBoundsException('This role no longer exists. Refresh and try again.');
        }
        return $role;
    }

    private static function System(array $role): bool
    {
        return $role['is_system'] || in_array($role['role_key'], self::SYSTEM_KEYS, true);
    }

    /** Every write locks the role so cloning, assignment and deletion remain consistent. */
    private static function Transaction(BaseConnection $db, callable $task): void
    {
        $db->transBegin();
        try
        {
            $task();
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

    private static function Text(array $data, string $key, int $limit, bool $isRequired = false): string
    {
        $value = $data[$key] ?? '';
        if (!is_string($value))
        {
            throw new InvalidArgumentException('Enter a valid role name and description.');
        }
        $value = trim($value);
        if (($isRequired && $value === '') || mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value))
        {
            throw new InvalidArgumentException($isRequired ? 'Enter a role name of 1–80 characters.' : 'Keep the description within 500 characters.');
        }
        return $value;
    }

    private static function Mandatory(string $module, string $permission): bool
    {
        return (in_array($module, ['account','notifications'], true) && in_array($permission, ['view','edit'], true))
            || (in_array($module, ['directory','bookmarks','updates'], true) && $permission === 'view');
    }

    public static function Create(BaseConnection $db, array $data): string
    {
        $key = $data['role_key'] ?? '';
        if (!self::ValidKey($key))
        {
            throw new InvalidArgumentException('Use 1–20 lowercase letters, numbers or underscores for the role key, starting with a letter.');
        }
        $name = self::Text($data, 'role_name', 80, true);
        $description = self::Text($data, 'description', 500);
        $source = $data['copy_from'] ?? '';
        if (!is_string($source) || ($source !== '' && !self::ValidKey($source)))
        {
            throw new InvalidArgumentException('Choose a valid starting permission profile.');
        }
        if ($source === 'admin')
        {
            throw new InvalidArgumentException('Administrator access cannot be copied. Choose another role or start with minimal access.');
        }
        self::Transaction($db, static function () use ($db, $key, $name, $description, $source): void
        {
            $copied = [];
            if ($source !== '')
            {
                self::Locked($db, $source);
                
                $stmt = $db->table('role_permissions')->select('module,permission,is_allowed', false)->where('role_key', $source)->get();
                foreach ($stmt->getResultArray() as $row)
                {
                    $copied[$row['module']][$row['permission']] = (bool)$row['is_allowed'];
                }
            }
            $db->table('app_roles')->set('role_key', $key)->set('role_name', $name)->set('description', $description)->insert();
            
            foreach (Permissions::Capabilities() as $module => $actions)
            {
                foreach ($actions as $permission)
                {
                    $isAllowed = $module !== 'permissions' && (self::Mandatory($module, $permission) || ($module === 'leaves' && $permission === 'view') || ($source === '' ? in_array($module, ['dashboard','wiki'], true) && $permission === 'view' : !empty($copied[$module][$permission])));
                    $stmt = $db->table('role_permissions')->set('role_key', $key)->set('module', $module)->set('permission', $permission)->set('is_allowed', $isAllowed ? 'true' : 'false')->insert();
                }
            }
        });
        return $key;
    }

    public static function Update(BaseConnection $db, array $data): void
    {
        $name = self::Text($data, 'role_name', 80, true);
        $description = self::Text($data, 'description', 500);
        self::Transaction($db, static function () use ($db, $data, $name, $description): void
        {
            $role = self::Locked($db, $data['role_key'] ?? '');
            if (self::System($role))
            {
                throw new InvalidArgumentException('Preset role names are protected. Create a custom role instead.');
            }
            $db->table('app_roles')->set('role_name', $name)->set('description', $description)->where('role_key', $role['role_key'])->update();
        });
    }

    public static function Delete(BaseConnection $db, $key): void
    {
        self::Transaction($db, static function () use ($db, $key): void
        {
            $role = self::Locked($db, $key);
            if (self::System($role))
            {
                throw new InvalidArgumentException('Preset roles cannot be deleted.');
            }
            
            $stmt = $db->table('users')->select('COUNT(*)', false)->where('role_key', $key)->get();
            $count = (int)Database::Scalar($stmt);
            if ($count)
            {
                throw new DomainException('This role is assigned to '.$count.' user'.($count === 1 ? '' : 's').'. Reassign them in Users before deleting it.');
            }
            $db->table('role_permissions')->where('role_key', $key)->delete();
            $db->table('app_roles')->where('role_key', $key)->delete();
        });
    }

    public static function SavePermissions(BaseConnection $db, array $data): void
    {
        $grants = $data['grants'] ?? null;
        if (!is_array($grants) || count($grants) > 144)
        {
            throw new InvalidArgumentException('Choose valid module permissions.');
        }
        self::Transaction($db, static function () use ($db, $data, $grants): void
        {
            $role = self::Locked($db, $data['role_key'] ?? '');
            if ($role['role_key'] === 'admin')
            {
                throw new InvalidArgumentException('Administrator permissions are protected.');
            }
            $stmtSql = 'INSERT INTO role_permissions(role_key,module,permission,is_allowed)VALUES(?,?,?,?)
                ON CONFLICT(role_key,module,permission)DO UPDATE SET is_allowed=EXCLUDED.is_allowed';
            foreach ($grants as $grant)
            {
                if (!is_array($grant) || !is_string($grant['module'] ?? null) || !is_string($grant['permission'] ?? null)
                    || !in_array($grant['permission'], Permissions::Capabilities()[$grant['module']] ?? [], true) || !is_bool($grant['is_allowed'] ?? null))
                {
                    throw new InvalidArgumentException('Invalid permission row.');
                }
                $isAllowed = $grant['module'] !== 'permissions' && (self::Mandatory($grant['module'], $grant['permission']) || $grant['is_allowed']);
                $stmt = $db->query($stmtSql, [$role['role_key'],$grant['module'],$grant['permission'],$isAllowed ? 'true' : 'false']);
            }
        });
    }
}
