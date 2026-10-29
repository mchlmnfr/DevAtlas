<?php

/**
 * Filename: Auth.php
 * Purpose: Provide auth processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\BaseConnection;

/**
 * DevAtlas :: Auth.php
 * Session-based authentication with password and browser-bound email-code login.
 *
 * Enforces, per the settings on the Parameters page (system_settings):
 *   - Account lockout after N consecutive failed attempts
 *   - Password expiry after N days (forces a change, doesn't block login)
 *   - Forced password change on first login (must_change_password)
 *
 * Private pages/APIs use requirePage/requireApi; login, recovery and public
 * directory browsing have explicit guest routes. Versioned sessions expire
 * after password/account changes at the next cached account check.
 */

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/Permissions.php';
require_once __DIR__ . '/helpers.php';

class Auth
{
    /** Attempts to log in with an Employee ID + password. */
    public static function Attempt(string $employeeId, string $password): array
    {
        $db = Database::GetConnection('app');
        
        $stmt = $db->table('users')->where('employee_id', $employeeId)->get();
        $user = $stmt->getUnbufferedRow('array');

        // Same generic message whether the ID doesn't exist or the password is
        // wrong, so a login attempt can't be used to enumerate valid Employee IDs.
        $genericError = 'Invalid Employee ID or password.';

        if (!$user)
        {
            return ['ok' => false, 'error' => $genericError];
        }
        if ($user['is_locked'])
        {
            return ['ok' => false, 'error' => 'This account is locked due to too many failed login attempts. Contact your administrator to unlock it.'];
        }
        if (!$user['is_active'])
        {
            return ['ok' => false, 'error' => 'This account has been disabled. Contact your administrator.'];
        }

        if (!password_verify($password, $user['password_hash']))
        {
            self::RegisterFailedAttempt($db, $user);
            return ['ok' => false, 'error' => $genericError];
        }

        // Correct password â€” reset the failed-attempt counter.
        if ($user['failed_login_attempts'] > 0)
        {
            $db->table('users')->set('failed_login_attempts', 0)->where('user_id', $user['user_id'])->update();
        }

        return self::FinishLogin($db, $user);
    }

    /** A verified email code follows the same account and password-policy gates. */
    public static function AttemptEmailCode(string $id, string $code, string $binding): array
    {
        require_once __DIR__ . '/EmailAuth.php';
        $user = EmailAuth::VerifySignIn($id, $code, $binding);
        if (!$user)
        {
            return ['ok' => false, 'error' => EmailAuth::INVALID_CODE];
        }
        $db = Database::GetConnection('app');
        $db->table('users')->set('failed_login_attempts', 0)->where('user_id', $user['user_id'])->update();
        return self::FinishLogin($db, $user);
    }

    private static function FinishLogin(BaseConnection $db, array $user): array
    {
        $isMustChange = (bool)$user['must_change_password'] || self::IsPasswordExpired($db, $user);
        if ($isMustChange && !$user['must_change_password'])
        {
            // Persist so it's visible in the Users list even before they act on it.
            $db->table('users')->set('must_change_password', true)->where('user_id', $user['user_id'])->update();
        }

        // Regenerate the session ID on privilege change to prevent session fixation.
        session_regenerate_id(true);
        unset($_SESSION['csrf_token'], $_SESSION['email_signin'], $_SESSION['email_auth_nonce'], $_SESSION['password_reset']);
        $_SESSION['user_id'] = (int)$user['user_id'];
        $_SESSION['employee_id'] = $user['employee_id'];
        $_SESSION['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
        $_SESSION['must_change_password'] = $isMustChange;
        $_SESSION['logged_in_at'] = time();
        $_SESSION['auth_checked_at'] = time();
        $_SESSION['auth_version'] = (int)$user['auth_version'];
        self::RefreshProfileCache($db, (int)$user['user_id']);
        if (class_exists('Audit', false))
        {
            Audit::Identify();
        }

        return ['ok' => true];
    }

    /** Increments the failed-attempt counter and locks the account if it hits the configured max. */
    private static function RegisterFailedAttempt(BaseConnection $db, array $user): void
    {
        $settingsStmt = $db->table('system_settings')->select('max_login_attempts', false)->where('id', 1)->get();
        $maxAttempts = (int)(Database::Scalar($settingsStmt) ?: 5);

        $attempts = (int)$user['failed_login_attempts'] + 1;
        if ($attempts >= $maxAttempts)
        {
            $db->table('users')->set('failed_login_attempts', $attempts)->set('is_locked', true)->set('locked_at', new \CodeIgniter\Database\RawSql('NOW()'))->set('auth_version', new \CodeIgniter\Database\RawSql('auth_version + 1'))->where('user_id', $user['user_id'])->update();
        }
        else
        {
            $db->table('users')->set('failed_login_attempts', $attempts)->where('user_id', $user['user_id'])->update();
        }
    }

    /** True if password_expiry_days have passed since this user's password was last changed. */
    private static function IsPasswordExpired(BaseConnection $db, array $user): bool
    {
        if (empty($user['password_changed_at']))
        {
            return true;
        }
        // never changed = treat as expired
        $settingsStmt = $db->table('system_settings')->select('password_expiry_days', false)->where('id', 1)->get();
        $configured = Database::Scalar($settingsStmt);
        $days = $configured !== false ? (int)$configured : 90;
        if ($days <= 0)
        {
            return false;
        }
        // 0 or negative = expiry disabled
        $changed = strtotime($user['password_changed_at']);
        return $changed !== false && (time() - $changed) > ($days * 86400);
    }

    public static function Check(): bool
    {
        if (empty($_SESSION['user_id']))
        {
            return false;
        }
        if (time() - (int)($_SESSION['auth_checked_at'] ?? 0) < 30)
        {
            return true;
        }

        $db = Database::GetConnection('app');
        
        $stmt = $db->table('users')->select('is_active, is_locked, must_change_password, auth_version', false)->where('user_id', (int)$_SESSION['user_id'])->get();
        $user = $stmt->getUnbufferedRow('array');
        $truthy = static fn ($value): bool => in_array($value, [true, 1, '1', 't', 'true'], true);
        if (!$user || !$truthy($user['is_active']) || $truthy($user['is_locked'])
            || (int)$user['auth_version'] !== (int)($_SESSION['auth_version'] ?? 1))
        {
            self::Logout();
            return false;
        }
        $_SESSION['must_change_password'] = $truthy($user['must_change_password']);
        $_SESSION['auth_checked_at'] = time();
        return true;
    }

    public static function MustChangePassword(): bool
    {
        return self::Check() && !empty($_SESSION['must_change_password']);
    }

    /**
     * Caches display-only profile info (position, department, email, avatar
     * path) in the session at login time, and again whenever it changes
     * (e.g. after a profile picture upload) â€” avoids a DB join on every
     * single page load just to render the topbar user menu.
     */
    public static function RefreshProfileCache(BaseConnection $db, int $userId): void
    {
        $stmtSql = "
            SELECT u.email, u.profile_picture_path, pos.position_title, dep.department_name
            FROM users u
            LEFT JOIN org_positions pos ON pos.position_id = u.position_id
            LEFT JOIN org_departments dep ON dep.department_id = u.department_id
            WHERE u.user_id = ?";
        $stmt = $db->query($stmtSql, [$userId]);
        $row = $stmt->getUnbufferedRow('array');
        if (!$row)
        {
            return;
        }
        $_SESSION['email'] = $row['email'];
        $_SESSION['position_title'] = $row['position_title'];
        $_SESSION['department_name'] = $row['department_name'];
        $_SESSION['profile_picture_path'] = $row['profile_picture_path'];
    }

    /** Current session's user info, or null if not logged in. */
    public static function User(): ?array
    {
        if (!self::Check())
        {
            return null;
        }
        return [
            'user_id' => $_SESSION['user_id'],
            'employee_id' => $_SESSION['employee_id'],
            'full_name' => $_SESSION['full_name'],
            'email' => $_SESSION['email'] ?? null,
            'position_title' => $_SESSION['position_title'] ?? null,
            'department_name' => $_SESSION['department_name'] ?? null,
            'profile_picture_path' => $_SESSION['profile_picture_path'] ?? null,
        ];
    }

    public static function Logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies'))
        {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    /** For full-page routes (module index.php files): redirect to login if not authenticated,
     *  or to the forced password-change page if the account requires it. */
    public static function RequirePage(): void
    {
        if (!self::Check())
        {
            if (class_exists('Audit', false))
            {
                Audit::Result('denied');
            }
            $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
            Header('Location: ' . APP_BASE_URL . '/login.php?redirect=' . $redirect);
            \FinishRequest();
        }
        $isChangePasswordPage = (Audit::Route($_SERVER['REQUEST_URI'] ?? '')['file'] ?? '') === 'change_password.php';
        if (self::MustChangePassword() && !$isChangePasswordPage)
        {
            if (class_exists('Audit', false))
            {
                Audit::Result('denied');
            }
            Header('Location: ' . APP_BASE_URL . '/change_password.php');
            \FinishRequest();
        }
        Permissions::EnforceRoute(false);
    }

    /** For JSON API routes: return a 401/403 JSON error instead of redirecting. */
    public static function RequireApi(): void
    {
        if (!self::Check())
        {
            http_response_code(401);
            Header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Your session has expired. Please log in again.']);
            \FinishRequest();
        }
        if (self::MustChangePassword())
        {
            http_response_code(403);
            Header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'You must change your password before continuing. Go to Change Password.']);
            \FinishRequest();
        }
        Permissions::EnforceRoute(true);
    }
}
