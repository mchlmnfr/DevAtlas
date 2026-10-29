<?php
/**
 * Filename: ServersController.php
 * Purpose: Handle Servers routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Throwable;

use Auth;
use Crypto;
use Database;
use DateTimeImmutable;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;

class ServersController extends BaseController
{
    public function Api()
    {
        require_once APPPATH . 'Helpers/servers_helper.php';
        /**
         * modules/servers/api.php
         */
        require_once (ROOTPATH . 'modules/servers') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/servers') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/servers') . '/../../config/Database.php';
        require_once (ROOTPATH . 'modules/servers') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/servers') . '/../../lib/Crypto.php';

        Auth::RequireApi();
        // every action below requires a logged-in session

        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        if (!is_string($action))
        {
            JsonError('Invalid action.');
        }
        $readActions = ['list_server_groups', 'get_server_group', 'list_servers', 'get_server',
            'list_protocols', 'list_credentials', 'reveal_credential', 'list_credential_shares',
            'list_shareable_users', 'list_expiry_items'];
        if (in_array($action, $readActions, true))
        {
            if ($_SERVER['REQUEST_METHOD'] !== 'GET')
            {
                Header('Allow: GET');
                JsonError('GET required.', 405);
            }
        }
        else
        {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST')
            {
                Header('Allow: POST');
                JsonError('POST required.', 405);
            }
            RequireCsrf();
        }
        Header('Cache-Control: no-store');
        $db = Database::GetConnection('app');

        // Environment is a strict dropdown in the UI (validated below on save);
        // not DB-enforced so any older rows with other values still display fine.
        $ENVIRONMENTS = ['Dev', 'Prod', 'SIT', 'UAT', 'SAT', 'SIT & UAT', 'UAT & SAT', 'SIT, UAT & SAT'];
        $HOSTING_TYPES = ['AWS', 'On-Premise'];
        $SERVER_TYPES = ['App Server', 'DB Server', 'App & DB Server'];

        try
        {
            switch ($action)
            {

                // ---------------------------------------------------------------
                // Server Groups (e.g. "Zior Cluster": Zior/Prod, Berith/SIT, Ava/UAT-SIT, Calcol/Dev)
                // ---------------------------------------------------------------

                case 'list_server_groups':
                    {
                        $search = CleanStr($_GET['q'] ?? null);
                        $sql = "SELECT g.*,
                          (SELECT COUNT(*) FROM servers s WHERE s.server_group_id=g.server_group_id) AS server_count,
                          (SELECT COUNT(*) FROM group_systems gs WHERE gs.server_group_id=g.server_group_id) AS system_count
                        FROM server_groups g WHERE 1=1";
                        $params = [];
                        if ($search)
                        {
                            $sql .= " AND g.group_name ILIKE :s:";
                            $params[':s'] = "%$search%";
                        }
                        $sql .= " ORDER BY g.group_name";
                        $stmtSql = $sql;
                        $stmt = $db->query($stmtSql, $params);
                        JsonResponse($stmt->getResultArray());
                    }

                case 'get_server_group':
                    {
                        $id = (int)($_GET['server_group_id'] ?? 0);
                        
                        $stmt = $db->table('server_groups')->where('server_group_id', $id)->get();
                        $g = $stmt->getUnbufferedRow('array');
                        if (!$g)
                        {
                            JsonError('Server group not found', 404);
                        }

                        
                        $s = $db->table('servers')->where('server_group_id', $id)->orderBy('environment, server_name', '', false)->get();
                        $g['servers'] = $s->getResultArray();

                        
                        $sys = $db->table('group_systems')->where('server_group_id', $id)->orderBy('system_name', '', false)->get();
                        $g['systems'] = $sys->getResultArray();

                        JsonResponse($g);
                    }

                case 'save_server_group':
                    {
                        $data = ReadJsonBody();
                        $id = (int)($data['server_group_id'] ?? 0);
                        $name = CleanStr($data['group_name'] ?? null);
                        if (!$name)
                        {
                            JsonError('Group name is required.');
                        }
                        $desc = CleanStr($data['description'] ?? null);
                        if ($id)
                        {
                            $db->table('server_groups')->set('group_name', $name)->set('description', $desc)->where('server_group_id', $id)->update();
                        }
                        else
                        {
                            
                            $stmt = $db->query($db->table('server_groups')->set('group_name', $name)->set('description', $desc)->getCompiledInsert() . ' RETURNING server_group_id');
                            $id = Database::Scalar($stmt);
                        }
                        JsonResponse(['ok' => true,'server_group_id' => $id]);
                    }

                case 'delete_server_group':
                    {
                        $data = ReadJsonBody();
                        $db->table('server_groups')->where('server_group_id', (int)($data['server_group_id'] ?? 0))->delete();
                        JsonResponse(['ok' => true]);
                    }

                    // Systems that run across a whole group (e.g. BMIPORTAL SSO, HROCS)
                case 'save_group_system':
                    {
                        $data = ReadJsonBody();
                        $id = (int)($data['group_system_id'] ?? 0);
                        $fields = [
                            'server_group_id' => (int)($data['server_group_id'] ?? 0),
                            'system_name' => CleanStr($data['system_name'] ?? null),
                            'current_environment' => JoinEnvironments($data['current_environment'] ?? null),
                            'repository_url' => CleanStr($data['repository_url'] ?? null),
                            'description' => CleanStr($data['description'] ?? null),
                        ];
                        if (!$fields['server_group_id'] || !$fields['system_name'])
                        {
                            JsonError('server_group_id and system_name are required.');
                        }

                        if ($id)
                        {
                            $sql = "UPDATE group_systems SET system_name=:system_name:, current_environment=:current_environment:,
                            repository_url=:repository_url:, description=:description: WHERE group_system_id=:id:";
                            $updateFields = $fields;
                            unset($updateFields['server_group_id']);
                            // not part of this UPDATE â€” PDO (native prepares) rejects unused bound keys
                            $updateFields['id'] = $id;
                            $db->query($sql, $updateFields);
                        }
                        else
                        {
                            $stmt = $db->query($db->table('group_systems')->set($fields)->getCompiledInsert() . ' RETURNING group_system_id');
                            $id = Database::Scalar($stmt);
                        }
                        JsonResponse(['ok' => true,'group_system_id' => $id]);
                    }

                case 'delete_group_system':
                    {
                        $data = ReadJsonBody();
                        $db->table('group_systems')->where('group_system_id', (int)($data['group_system_id'] ?? 0))->delete();
                        JsonResponse(['ok' => true]);
                    }

                    // ---------------------------------------------------------------
                    // Servers
                    // ---------------------------------------------------------------

                case 'list_servers':
                    {
                        $env = CleanStr($_GET['environment'] ?? null);
                        $groupId = (int)($_GET['server_group_id'] ?? 0);
                        $search = CleanStr($_GET['q'] ?? null);
                        // matches server name, IP, hosted or group system name
                        $sql = "SELECT s.*, g.group_name,
                          (SELECT COUNT(*) FROM hosted_systems h WHERE h.server_id=s.server_id) AS hosted_count,
                          (SELECT string_agg(h2.system_name, ', ' ORDER BY h2.system_name)
                             FROM hosted_systems h2 WHERE h2.server_id=s.server_id) AS hosted_names,
                          (SELECT string_agg(gs.system_name, ', ' ORDER BY gs.system_name)
                             FROM group_systems gs WHERE gs.server_group_id=s.server_group_id) AS group_system_names
                        FROM servers s
                        LEFT JOIN server_groups g ON g.server_group_id = s.server_group_id
                        WHERE 1=1";
                        $params = [];
                        if ($env)
                        {
                            $sql .= " AND s.environment = :env:";
                            $params[':env'] = $env;
                        }
                        if ($groupId)
                        {
                            $sql .= " AND s.server_group_id = :gid:";
                            $params[':gid'] = $groupId;
                        }
                        if ($search)
                        {
                            $sql .= " AND (s.server_name ILIKE :s: OR s.ip_address ILIKE :s: OR EXISTS (
                                SELECT 1 FROM hosted_systems h3 WHERE h3.server_id = s.server_id AND h3.system_name ILIKE :s:)
                                OR EXISTS (
                                SELECT 1 FROM group_systems gs WHERE gs.server_group_id = s.server_group_id AND gs.system_name ILIKE :s:))";
                            $params[':s'] = "%$search%";
                        }
                        $sql .= " ORDER BY s.environment, s.server_name";
                        $stmtSql = $sql;
                        $stmt = $db->query($stmtSql, $params);
                        JsonResponse($stmt->getResultArray());
                    }

                case 'get_server':
                    {
                        $id = (int)($_GET['server_id'] ?? 0);
                        $stmtSql = "SELECT s.*, g.group_name FROM servers s
                                        LEFT JOIN server_groups g ON g.server_group_id = s.server_group_id
                                        WHERE s.server_id=?";
                        $stmt = $db->query($stmtSql, [$id]);
                        $s = $stmt->getUnbufferedRow('array');
                        if (!$s)
                        {
                            JsonError('Server not found', 404);
                        }
                        
                        $h = $db->table('hosted_systems')->where('server_id', $id)->orderBy('system_name', '', false)->get();
                        $s['hosted_systems'] = $h->getResultArray();
                        JsonResponse($s);
                    }

                case 'save_server':
                    {
                        $data = ReadJsonBody();
                        $id = (int)($data['server_id'] ?? 0);
                        $env = CleanStr($data['environment'] ?? null);
                        if (!$env)
                        {
                            JsonError('Environment is required.');
                        }
                        if (!in_array($env, $ENVIRONMENTS, true))
                        {
                            
                            $existing = $db->table('servers')->select('environment', false)->where('server_id', $id)->get();
                            if (!$id || Database::Scalar($existing) !== $env)
                            {
                                JsonError('Invalid environment value.');
                            }
                        }

                        $hostingType = CleanStr($data['hosting_type'] ?? null);
                        if ($hostingType && !in_array($hostingType, $HOSTING_TYPES, true))
                        {
                            JsonError('Invalid hosting type.');
                        }
                        $serverType = CleanStr($data['server_type'] ?? null);
                        if ($serverType && !in_array($serverType, $SERVER_TYPES, true))
                        {
                            JsonError('Invalid server type.');
                        }

                        $fields = [
                            'server_group_id' => !empty($data['server_group_id']) ? (int)$data['server_group_id'] : null,
                            'environment' => $env,
                            'hosting_type' => $hostingType,
                            'server_type' => $serverType,
                            'server_name' => CleanStr($data['server_name'] ?? null),
                            'ip_address' => CleanStr($data['ip_address'] ?? null),
                            'server_url' => CleanStr($data['server_url'] ?? null),
                            'os_platform' => CleanStr($data['os_platform'] ?? null),
                            'cpu_cores' => isset($data['cpu_cores']) && $data['cpu_cores'] !== '' ? (int)$data['cpu_cores'] : null,
                            'memory_gb' => isset($data['memory_gb']) && $data['memory_gb'] !== '' ? (float)$data['memory_gb'] : null,
                            'storage_gb' => isset($data['storage_gb']) && $data['storage_gb'] !== '' ? (float)$data['storage_gb'] : null,
                            'notes' => CleanStr($data['notes'] ?? null),
                            'is_active' => PgBool($data['is_active'] ?? true),
                        ];
                        if (!$fields['server_name'] || !$fields['ip_address'])
                        {
                            JsonError('server_name and ip_address are required.');
                        }

                        if ($id)
                        {
                            $sql = "UPDATE servers SET server_group_id=:server_group_id:, environment=:environment:,
                            hosting_type=:hosting_type:, server_type=:server_type:, server_name=:server_name:,
                            ip_address=:ip_address:, server_url=:server_url:, os_platform=:os_platform:, cpu_cores=:cpu_cores:,
                            memory_gb=:memory_gb:, storage_gb=:storage_gb:, notes=:notes:, is_active=:is_active: WHERE server_id=:id:";
                            $fields['id'] = $id;
                            $db->query($sql, $fields);
                        }
                        else
                        {
                            $stmt = $db->query($db->table('servers')->set($fields)->getCompiledInsert() . ' RETURNING server_id');
                            $id = Database::Scalar($stmt);
                        }
                        JsonResponse(['ok' => true,'server_id' => $id]);
                    }

                case 'delete_server':
                    {
                        $data = ReadJsonBody();
                        $db->table('servers')->where('server_id', (int)($data['server_id'] ?? 0))->delete();
                        JsonResponse(['ok' => true]);
                    }

                case 'save_hosted_system':
                    {
                        $data = ReadJsonBody();
                        $id = (int)($data['hosted_system_id'] ?? 0);
                        $fields = [
                            'server_id' => (int)($data['server_id'] ?? 0),
                            'system_name' => CleanStr($data['system_name'] ?? null),
                            'version' => CleanStr($data['version'] ?? null),
                            'port' => CleanStr($data['port'] ?? null),
                            'current_environment' => JoinEnvironments($data['current_environment'] ?? null),
                            'repository_url' => CleanStr($data['repository_url'] ?? null),
                            'description' => CleanStr($data['description'] ?? null),
                        ];
                        if (!$fields['server_id'] || !$fields['system_name'])
                        {
                            JsonError('server_id and system_name are required.');
                        }

                        if ($id)
                        {
                            $sql = "UPDATE hosted_systems SET system_name=:system_name:, version=:version:, port=:port:,
                            current_environment=:current_environment:, repository_url=:repository_url:,
                            description=:description: WHERE hosted_system_id=:id:";
                            $updateFields = $fields;
                            unset($updateFields['server_id']);
                            // not part of this UPDATE â€” PDO (native prepares) rejects unused bound keys
                            $updateFields['id'] = $id;
                            $db->query($sql, $updateFields);
                        }
                        else
                        {
                            $stmt = $db->query($db->table('hosted_systems')->set($fields)->getCompiledInsert() . ' RETURNING hosted_system_id');
                            $id = Database::Scalar($stmt);
                        }
                        JsonResponse(['ok' => true,'hosted_system_id' => $id]);
                    }

                case 'delete_hosted_system':
                    {
                        $data = ReadJsonBody();
                        $db->table('hosted_systems')->where('hosted_system_id', (int)($data['hosted_system_id'] ?? 0))->delete();
                        JsonResponse(['ok' => true]);
                    }

                    // ---------------------------------------------------------------
                    // Server access protocols (RDP / SSH / FTP / WinRM / ...)
                    // ---------------------------------------------------------------

                case 'list_protocols':
                    {
                        $serverId = (int)($_GET['server_id'] ?? 0);
                        
                        $stmt = $db->table('server_access_protocols')->where('server_id', $serverId)->orderBy('protocol', '', false)->get();
                        JsonResponse($stmt->getResultArray());
                    }

                case 'save_protocol':
                    {
                        $data = ReadJsonBody();
                        $id = (int)($data['protocol_id'] ?? 0);
                        $serverId = (int)($data['server_id'] ?? 0);
                        $protocol = CleanStr($data['protocol'] ?? null);
                        if (!$serverId || !$protocol)
                        {
                            JsonError('server_id and protocol are required.');
                        }
                        $port = CleanStr($data['port'] ?? null);
                        $notes = CleanStr($data['notes'] ?? null);

                        if ($id)
                        {
                            $db->table('server_access_protocols')->set('protocol', $protocol)->set('port', $port)->set('notes', $notes)->where('protocol_id', $id)->update();
                        }
                        else
                        {
                            
                            $stmt = $db->query($db->table('server_access_protocols')->set('server_id', $serverId)->set('protocol', $protocol)->set('port', $port)->set('notes', $notes)->getCompiledInsert() . ' RETURNING protocol_id');
                            $id = Database::Scalar($stmt);
                        }
                        JsonResponse(['ok' => true,'protocol_id' => $id]);
                    }

                case 'delete_protocol':
                    {
                        $data = ReadJsonBody();
                        $db->table('server_access_protocols')->where('protocol_id', (int)($data['protocol_id'] ?? 0))->delete();
                        JsonResponse(['ok' => true]);
                    }

                    // ---------------------------------------------------------------
                    // Server Credentials Vault
                    // Passwords are AES-256-CBC encrypted (lib/Crypto.php) â€” never
                    // returned in list responses, only via the explicit reveal action,
                    // and only to the creator or someone the credential was shared with.
                    // ---------------------------------------------------------------

                case 'list_credentials':
                    {
                        $serverId = (int)($_GET['server_id'] ?? 0);
                        $stmtSql = "
                    SELECT c.credential_id, c.server_id, c.label, c.username, c.created_at,
                           c.created_by, u.employee_id AS created_by_employee_id, u.first_name AS created_by_first_name, u.last_name AS created_by_last_name,
                           EXISTS (SELECT 1 FROM server_credential_shares sh WHERE sh.credential_id=c.credential_id AND sh.shared_with_user_id=?) AS is_shared
                    FROM server_credentials c
                    LEFT JOIN users u ON u.user_id = c.created_by
                    WHERE c.server_id = ?
                    ORDER BY c.label";
                        $stmt = $db->query($stmtSql, [(int)$_SESSION['user_id'], $serverId]);
                        $rows = $stmt->getResultArray();

                        $myUserId = (int)($_SESSION['user_id'] ?? 0);
                        foreach ($rows as &$row)
                        {
                            $row['created_by_name'] = trim(($row['created_by_first_name'] ?? '') . ' ' . ($row['created_by_last_name'] ?? '')) ?: $row['created_by_employee_id'];
                            unset($row['created_by_first_name'], $row['created_by_last_name']);
                            $row['is_owner'] = ((int)$row['created_by'] === $myUserId);
                            $row['can_reveal'] = $row['is_owner'] || in_array($row['is_shared'], [true, 1, '1', 't', 'true'], true);
                            unset($row['created_by'], $row['is_shared']);
                        }
                        JsonResponse($rows);
                    }

                case 'save_credential':
                    {
                        $data = ReadJsonBody();
                        $id = (int)($data['credential_id'] ?? 0);
                        $serverId = (int)($data['server_id'] ?? 0);
                        $label = CleanStr($data['label'] ?? null);
                        $username = CleanStr($data['username'] ?? null);
                        if (!$serverId || !$label || !$username)
                        {
                            JsonError('server_id, label and username are required.');
                        }

                        if ($id)
                        {
                            // Editing: only the creator may change label/username. Password (if provided) is re-encrypted.
                            
                            $ownerStmt = $db->table('server_credentials')->select('created_by', false)->where('credential_id', $id)->get();
                            $ownerId = (int)Database::Scalar($ownerStmt);
                            if ($ownerId !== (int)($_SESSION['user_id'] ?? 0))
                            {
                                JsonError('Only the credential\'s creator can edit it.', 403);
                            }

                            $password = (string)($data['password'] ?? '');
                            if ($password !== '')
                            {
                                $db->table('server_credentials')->set('label', $label)->set('username', $username)->set('password_encrypted', Crypto::Encrypt($password))->where('credential_id', $id)->update();
                            }
                            else
                            {
                                $db->table('server_credentials')->set('label', $label)->set('username', $username)->where('credential_id', $id)->update();
                            }
                        }
                        else
                        {
                            $password = (string)($data['password'] ?? '');
                            if ($password === '')
                            {
                                JsonError('Password is required.');
                            }
                            
                            $stmt = $db->query($db->table('server_credentials')->set('server_id', ([
                                's' => $serverId, 'l' => $label, 'u' => $username,
                                'p' => Crypto::Encrypt($password), 'cb' => (int)($_SESSION['user_id'] ?? 0),
                            ])['s'])->set('label', ([
                                's' => $serverId, 'l' => $label, 'u' => $username,
                                'p' => Crypto::Encrypt($password), 'cb' => (int)($_SESSION['user_id'] ?? 0),
                            ])['l'])->set('username', ([
                                's' => $serverId, 'l' => $label, 'u' => $username,
                                'p' => Crypto::Encrypt($password), 'cb' => (int)($_SESSION['user_id'] ?? 0),
                            ])['u'])->set('password_encrypted', ([
                                's' => $serverId, 'l' => $label, 'u' => $username,
                                'p' => Crypto::Encrypt($password), 'cb' => (int)($_SESSION['user_id'] ?? 0),
                            ])['p'])->set('created_by', ([
                                's' => $serverId, 'l' => $label, 'u' => $username,
                                'p' => Crypto::Encrypt($password), 'cb' => (int)($_SESSION['user_id'] ?? 0),
                            ])['cb'])->getCompiledInsert() . ' RETURNING credential_id');
                            $id = Database::Scalar($stmt);
                        }
                        JsonResponse(['ok' => true,'credential_id' => $id]);
                    }

                case 'reveal_credential':
                    {
                        $id = (int)($_GET['credential_id'] ?? 0);
                        
                        $stmt = $db->table('server_credentials')->where('credential_id', $id)->get();
                        $cred = $stmt->getUnbufferedRow('array');
                        if (!$cred)
                        {
                            JsonError('Credential not found.', 404);
                        }

                        $myUserId = (int)($_SESSION['user_id'] ?? 0);
                        $isOwner = ((int)$cred['created_by'] === $myUserId);
                        $isShared = false;
                        if (!$isOwner)
                        {
                            
                            $shareStmt = $db->table('server_credential_shares')->select('1', false)->where('credential_id', $id)->where('shared_with_user_id', $myUserId)->get();
                            $isShared = (bool)Database::Scalar($shareStmt);
                        }
                        if (!$isOwner && !$isShared)
                        {
                            JsonError('You do not have access to view this credential. Ask the creator to share it with you.', 403);
                        }

                        try
                        {
                            $password = Crypto::Decrypt($cred['password_encrypted']);
                        }
                        catch (Throwable $e)
                        {
                            if ($e instanceof \App\Http\ResponseComplete)
                            {
                                throw $e;
                            }
                            JsonError('Could not decrypt this credential: ' . $e->getMessage(), 500);
                        }
                        JsonResponse(['ok' => true, 'username' => $cred['username'], 'password' => $password]);
                    }

                case 'delete_credential':
                    {
                        $data = ReadJsonBody();
                        $id = (int)($data['credential_id'] ?? 0);
                        
                        $ownerStmt = $db->table('server_credentials')->select('created_by', false)->where('credential_id', $id)->get();
                        $ownerId = (int)Database::Scalar($ownerStmt);
                        if ($ownerId !== (int)($_SESSION['user_id'] ?? 0))
                        {
                            JsonError('Only the credential\'s creator can delete it.', 403);
                        }
                        $db->table('server_credentials')->where('credential_id', $id)->delete();
                        JsonResponse(['ok' => true]);
                    }

                case 'list_credential_shares':
                    {
                        $id = (int)($_GET['credential_id'] ?? 0);
                        $stmtSql = "
                    SELECT s.share_id, s.shared_with_user_id, u.employee_id, u.first_name, u.last_name
                    FROM server_credential_shares s
                    JOIN users u ON u.user_id = s.shared_with_user_id
                    WHERE s.credential_id = ? ORDER BY u.last_name, u.first_name";
                        $stmt = $db->query($stmtSql, [$id]);
                        JsonResponse($stmt->getResultArray());
                    }

                case 'share_credential':
                    {
                        $data = ReadJsonBody();
                        $credentialId = (int)($data['credential_id'] ?? 0);
                        $withUserId = (int)($data['user_id'] ?? 0);
                        if (!$credentialId || !$withUserId)
                        {
                            JsonError('credential_id and user_id are required.');
                        }

                        
                        $ownerStmt = $db->table('server_credentials')->select('created_by', false)->where('credential_id', $credentialId)->get();
                        $ownerId = (int)Database::Scalar($ownerStmt);
                        if ($ownerId !== (int)($_SESSION['user_id'] ?? 0))
                        {
                            JsonError('Only the credential\'s creator can share it.', 403);
                        }

                        try
                        {
                            $stmtSql = "INSERT INTO server_credential_shares (credential_id, shared_with_user_id, shared_by_user_id)
                                            VALUES (:c:,:w:,:b:) ON CONFLICT (credential_id, shared_with_user_id) DO NOTHING";
                            $stmt = $db->query($stmtSql, ['c' => $credentialId, 'w' => $withUserId, 'b' => (int)($_SESSION['user_id'] ?? 0)]);
                        }
                        catch (DatabaseException $e)
                        {
                            JsonError('Could not share credential: ' . $e->getMessage(), 500);
                        }
                        JsonResponse(['ok' => true]);
                    }

                case 'unshare_credential':
                    {
                        $data = ReadJsonBody();
                        $credentialId = (int)($data['credential_id'] ?? 0);
                        $withUserId = (int)($data['user_id'] ?? 0);

                        
                        $ownerStmt = $db->table('server_credentials')->select('created_by', false)->where('credential_id', $credentialId)->get();
                        $ownerId = (int)Database::Scalar($ownerStmt);
                        if ($ownerId !== (int)($_SESSION['user_id'] ?? 0))
                        {
                            JsonError('Only the credential\'s creator can change sharing.', 403);
                        }

                        $db->table('server_credential_shares')->where('credential_id', $credentialId)->where('shared_with_user_id', $withUserId)->delete();
                        JsonResponse(['ok' => true]);
                    }

                case 'list_shareable_users':
                    {
                        // Everyone except the current user, for the "share with" picker.
                        $myUserId = (int)($_SESSION['user_id'] ?? 0);
                        
                        $stmt = $db->table('users')->select('user_id, employee_id, first_name, last_name', false)->where('user_id !=', $myUserId)->where('is_active', true)->orderBy('last_name, first_name', '', false)->get();
                        JsonResponse($stmt->getResultArray());
                    }

                    // ---------------------------------------------------------------
                    // Expiry tracking (SSL certificates, licenses, other services)
                    // ---------------------------------------------------------------

                case 'list_expiry_items':
                    {
                        $serverId = (int)($_GET['server_id'] ?? 0);
                        $sql = "SELECT e.*, s.server_name, h.system_name AS hosted_system_name
                        FROM expiry_items e
                        LEFT JOIN servers s ON s.server_id = e.server_id
                        LEFT JOIN hosted_systems h ON h.hosted_system_id = e.hosted_system_id
                        WHERE 1=1";
                        $params = [];
                        if ($serverId)
                        {
                            $sql .= " AND e.server_id = :sid:";
                            $params[':sid'] = $serverId;
                        }
                        $sql .= " ORDER BY e.expiry_date ASC";
                        $stmtSql = $sql;
                        $stmt = $db->query($stmtSql, $params);
                        JsonResponse($stmt->getResultArray());
                    }

                case 'save_expiry_item':
                    {
                        $data = ReadJsonBody();
                        $id = (int)($data['expiry_id'] ?? 0);
                        $itemType = CleanStr($data['item_type'] ?? null);
                        if (!in_array($itemType, ['SSL Certificate','License','Other Service'], true))
                        {
                            JsonError('Invalid item_type.');
                        }
                        $itemName = CleanStr($data['item_name'] ?? null);
                        $expiryDate = CleanStr($data['expiry_date'] ?? null);
                        if (!$itemName || !$expiryDate)
                        {
                            JsonError('item_name and expiry_date are required.');
                        }

                        $fields = [
                            'item_type' => $itemType,
                            'item_name' => $itemName,
                            'server_id' => !empty($data['server_id']) ? (int)$data['server_id'] : null,
                            'hosted_system_id' => !empty($data['hosted_system_id']) ? (int)$data['hosted_system_id'] : null,
                            'issued_date' => CleanStr($data['issued_date'] ?? null),
                            'expiry_date' => $expiryDate,
                            'alert_days_before' => (int)($data['alert_days_before'] ?? 30),
                            'notes' => CleanStr($data['notes'] ?? null),
                        ];

                        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expiryDate);
                        if (!$date || $date->format('Y-m-d') !== $expiryDate)
                        {
                            JsonError('Enter a valid expiry date.');
                        }
                        if ($fields['issued_date'])
                        {
                            $issued = DateTimeImmutable::createFromFormat('!Y-m-d', $fields['issued_date']);
                            if (!$issued || $issued->format('Y-m-d') !== $fields['issued_date'] || $fields['issued_date'] > $expiryDate)
                            {
                                JsonError('Issued date must be valid and on or before the expiry date.');
                            }
                        }
                        if ($fields['alert_days_before'] < 0)
                        {
                            JsonError('Alert days cannot be negative.');
                        }
                        if ($fields['hosted_system_id'])
                        {
                            
                            $hosted = $db->table('hosted_systems')->select('server_id', false)->where('hosted_system_id', $fields['hosted_system_id'])->get();
                            if (!$fields['server_id'] || (int)Database::Scalar($hosted) !== $fields['server_id'])
                            {
                                JsonError('Choose an application on the selected server.');
                            }
                        }

                        if ($id)
                        {
                            $db->table('expiry_items')->where('expiry_id', $id)->update($fields);
                        }
                        else
                        {
                            $stmt = $db->query($db->table('expiry_items')->set($fields)->getCompiledInsert() . ' RETURNING expiry_id');
                            $id = Database::Scalar($stmt);
                        }
                        JsonResponse(['ok' => true,'expiry_id' => $id]);
                    }

                case 'delete_expiry_item':
                    {
                        $data = ReadJsonBody();
                        $db->table('expiry_items')->where('expiry_id', (int)($data['expiry_id'] ?? 0))->delete();
                        JsonResponse(['ok' => true]);
                    }

                default:
                    JsonError('Unknown action.', 400);
            }
        }
        catch (DatabaseException $error)
        {
            if (Database::SqlState($error) === '23505')
            {
                JsonError('A record with these details already exists.', 409);
            }
            if (Database::SqlState($error) === '23503')
            {
                JsonError('A linked record no longer exists. Refresh the inventory and try again.', 409);
            }
            if (in_array(Database::SqlState($error), ['22001', '22P02', '23514'], true))
            {
                JsonError('Check the field values and lengths before saving.');
            }
            throw $error;
        }

        /**
         * The "current environment" fields on hosted/group systems are multi-select
         * in the UI (Dev, SIT, UAT, SAT, Prod, DR, Non-Prod(DR)) and stored as a
         * single comma-separated string. Accepts either a JSON array (from the
         * multi-select checkboxes) or a plain string (already-joined), for
         * flexibility, and normalizes to a clean comma-separated value or null.
         */
    }

    public function Index()
    {
        require_once APPPATH . 'Helpers/servers_helper.php';
        $pageTitle = 'Server Inventory';
        $pageSubtitle = 'Infrastructure, applications, access, and renewals';
        $activeNav = 'servers';
        $bodyClass = 'inventory-page';
        $pageScript = 'assets/js/servers.js';
        $pageStyles = ['assets/css/servers.css'];
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('servers/index', get_defined_vars());
    }

    public function RdpDownload()
    {
        require_once APPPATH . 'Helpers/servers_helper.php';
        /**
         * modules/servers/rdp_download.php
         * Streams a minimal .rdp file for a server's RDP access protocol entry â€”
         * double-clicking it on Windows opens Remote Desktop Connection pre-filled
         * with the server's address. Opened via a direct link (new tab), so this
         * uses page-style auth (redirect to login) rather than a JSON error.
         */
        require_once (ROOTPATH . 'modules/servers') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/servers') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/servers') . '/../../config/Database.php';
        require_once (ROOTPATH . 'modules/servers') . '/../../lib/helpers.php';

        Auth::RequirePage();
        // opened via a direct link/new tab, so redirect to login rather than a JSON error

        $protocolId = (int)($_GET['protocol_id'] ?? 0);
        if (!$protocolId)
        {
            http_response_code(400);
            die('protocol_id is required.');
        }

        $db = Database::GetConnection('app');
        $stmtSql = "
            SELECT p.*, s.server_name, s.ip_address
            FROM server_access_protocols p
            JOIN servers s ON s.server_id = p.server_id
            WHERE p.protocol_id = ?";
        $stmt = $db->query($stmtSql, [$protocolId]);
        $protocol = $stmt->getUnbufferedRow('array');

        if (!$protocol)
        {
            http_response_code(404);
            die('Protocol entry not found.');
        }
        if (strtoupper($protocol['protocol']) !== 'RDP')
        {
            http_response_code(400);
            die('This entry is not an RDP protocol.');
        }

        $address = $protocol['ip_address'] . ($protocol['port'] ? ':' . $protocol['port'] : '');

        $rdpContent = "full address:s:{$address}\r\n"
                    . "prompt for credentials:i:1\r\n"
                    . "administrative session:i:0\r\n"
                    . "screen mode id:i:2\r\n";

        $filename = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $protocol['server_name']) . '.rdp';

        Header('Content-Type: application/x-rdp');
        Header('Content-Disposition: attachment; filename="' . $filename . '"');
        Header('Content-Length: ' . strlen($rdpContent));
        echo $rdpContent;
    }
}
