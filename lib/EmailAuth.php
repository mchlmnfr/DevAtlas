<?php

/**
 * Filename: EmailAuth.php
 * Purpose: Provide email auth processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\BaseConnection;

/**
 * EmailAuth.php: expiring email credentials and encrypted, authenticated previews.
 * Developer: DevAtlas team. Updated: 2026-10-25.
 * Recent updates: password recovery, session-bound email codes and request limits.
 */
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/Mailer.php';

class EmailAuth
{
    public const REQUEST_MESSAGE = 'If the account is eligible, we have prepared an email with the next step. Check your inbox and spam folder. If email is unavailable, ask support to open the protected details link.';
    public const INVALID_CODE = 'This code is invalid, expired or already used. Check the latest email or request a new code.';
    public const INVALID_RESET = 'This reset link is invalid, expired or already used. Request a new link.';
    public const CODE_SECONDS = 600;
    public const RESET_SECONDS = 1800;
    public const RESEND_SECONDS = 60;

    public static function Headers(): void
    {
        Header('Cache-Control: no-store, max-age=0');
        Header('Pragma: no-cache');
        Header('Referrer-Policy: no-referrer');
        Header('X-Frame-Options: DENY');
        Header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }

    public static function SessionBinding(): string
    {
        if (!is_string($_SESSION['email_auth_nonce'] ?? null))
        {
            $_SESSION['email_auth_nonce'] = bin2hex(random_bytes(32));
        }
        return hash('sha256', $_SESSION['email_auth_nonce']);
    }

    public static function ValidId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{32}$/D', $id) === 1;
    }

    public static function NormalizeBaseUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        if (strlen($url) > 500 || preg_match('/[\x00-\x20\\\\]/', $url))
        {
            return null;
        }
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== rtrim(APP_BASE_URL, '/'))
        {
            return null;
        }
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    /** Never construct password-reset links from an untrusted request Host header. */
    public static function BaseUrl(): string
    {
        $value = Database::Scalar(Database::GetConnection('app')->table('system_settings')->select('auth_base_url', false)->where('id', 1)->get());
        return self::NormalizeBaseUrl(is_string($value) ? $value : '') ?? 'http://localhost' . APP_BASE_URL;
    }

    private static function Key(string $purpose): string
    {
        return hash_hmac('sha256', 'email-auth:' . $purpose, APP_ENCRYPTION_KEY, true);
    }

    private static function Digest(string $id, string $purpose, string $secret): string
    {
        return hash_hmac('sha256', $purpose . ':' . $id . ':' . $secret, self::Key('verify'));
    }

    private static function Seal(string $id, string $secret): string
    {
        $nonce = random_bytes(12);
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', self::Key('preview'), OPENSSL_RAW_DATA, $nonce, $tag, $id);
        if ($cipher === false)
        {
            throw new RuntimeException('Could not protect the authentication email.');
        }
        return base64_encode($nonce . $tag . $cipher);
    }

    private static function Unseal(string $id, string $value): ?string
    {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 29)
        {
            return null;
        }
        $secret = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            self::Key('preview'),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
            $id
        );
        return $secret === false ? null : $secret;
    }

    /** Forget plaintext-recoverable credentials after use/expiry; retain delivery history for one day. */
    public static function Cleanup(BaseConnection $db): void
    {
        $db->query("UPDATE auth_email_challenges c SET preview_secret = NULL
            WHERE preview_secret IS NOT NULL AND (expires_at <= NOW() OR consumed_at IS NOT NULL
                OR NOT EXISTS (SELECT 1 FROM users u WHERE u.user_id = c.user_id AND u.auth_version = c.auth_version
                    AND u.is_active AND NOT u.is_locked AND LOWER(COALESCE(u.email, '')) = LOWER(COALESCE(c.recipient, ''))))");
        $db->table('auth_email_challenges')->where('created_at <', new \CodeIgniter\Database\RawSql('NOW() - INTERVAL \'1 day\''))->delete();
        $db->table('auth_email_limits')->where('last_attempt_at <', new \CodeIgniter\Database\RawSql('NOW() - INTERVAL \'1 day\''))->delete();
    }

    /** Persistent, row-locked buckets also apply when the identifier does not exist. */
    private static function ReserveRequest(BaseConnection $db, string $identifier, string $binding, string $ip): bool
    {
        $buckets = [
            hash_hmac('sha256', 'identity:' . mb_strtolower($identifier), self::Key('limits')) => [5, 60],
            hash_hmac('sha256', 'session:' . $binding, self::Key('limits')) => [10, 0],
            hash_hmac('sha256', 'ip:' . $ip, self::Key('limits')) => [30, 0],
        ];
        ksort($buckets);
        $isAllowed = true;
        foreach ($buckets as $key => [$max, $cooldown])
        {
            $db->query('INSERT INTO auth_email_limits (key_hash, attempts) VALUES (?, 0) ON CONFLICT DO NOTHING', [$key]);
            $stmtSql = 'SELECT *, EXTRACT(EPOCH FROM NOW() - window_started_at) AS window_age,
                EXTRACT(EPOCH FROM NOW() - last_attempt_at) AS last_age FROM auth_email_limits WHERE key_hash = ? FOR UPDATE';
            $stmt = $db->query($stmtSql, [$key]);
            $row = $stmt->getUnbufferedRow('array');
            $count = (float)$row['window_age'] >= 900 ? 0 : (int)$row['attempts'];
            if ($count >= $max || ($count > 0 && (float)$row['last_age'] < $cooldown))
            {
                $isAllowed = false;
            }
            $db->table('auth_email_limits')->set('attempts', min($count + 1, 1000000))->set('window_started_at', new \CodeIgniter\Database\RawSql('CASE WHEN window_started_at <= NOW() - INTERVAL \'15 minutes\' THEN NOW() ELSE window_started_at END'))->set('last_attempt_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('key_hash', $key)->update();
        }
        return $isAllowed;
    }

    /** A uniform public result never says whether an account, email or delivery exists. */
    public static function Request(string $purpose, string $identifier, string $binding, string $ip, ?string $previousId = null): array
    {
        if (!in_array($purpose, ['signin', 'reset'], true))
        {
            throw new InvalidArgumentException('Invalid email purpose.');
        }
        $id = bin2hex(random_bytes(16));
        $public = ['id' => $id, 'message' => self::REQUEST_MESSAGE, 'retry_after' => self::RESEND_SECONDS];
        $db = Database::GetConnection('app');
        self::Cleanup($db);
        $db->transBegin();
        try
        {
            if (!self::ReserveRequest($db, $identifier, $binding, $ip))
            {
                $db->transCommit();
                if ($previousId !== null && self::ValidId($previousId))
                {
                    $public['id'] = $previousId;
                }
                return $public;
            }
            
            $stmt = $db->table('users')->select('user_id', false)->where('employee_id', $identifier)->get();
            $userId = Database::Scalar($stmt);
            if (!$userId && filter_var($identifier, FILTER_VALIDATE_EMAIL))
            {
                $stmtSql = 'SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 2';
                $stmt = $db->query($stmtSql, [$identifier]);
                $matches = Database::Column($stmt);
                $userId = count($matches) === 1 ? $matches[0] : false;
            }
            $user = $userId ? self::LockedUser($db, (int)$userId) : null;
            if (!$user || !self::Truth($user['is_active']) || self::Truth($user['is_locked']))
            {
                $db->transCommit();
                return $public;
            }
            // This lock also limits Employee-ID/email aliases and concurrent resend requests.
            
            $stmt = $db->table('auth_email_challenges')->select('1', false)->where('user_id', $userId)->where('created_at >', new \CodeIgniter\Database\RawSql('NOW() - INTERVAL \'60 seconds\''))->limit((int) (1), (int) (0))->get();
            if (Database::Scalar($stmt))
            {
                $db->transCommit();
                if ($previousId !== null && self::ValidId($previousId))
                {
                    $public['id'] = $previousId;
                }
                return $public;
            }
            $secret = $purpose === 'signin' ? str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT) : bin2hex(random_bytes(32));
            $seconds = $purpose === 'signin' ? self::CODE_SECONDS : self::RESET_SECONDS;
            $db->table('auth_email_challenges')->set('consumed_at', new \CodeIgniter\Database\RawSql('NOW()'))->set('preview_secret', null)->where('user_id', $userId)->where('purpose', $purpose)->where(new \CodeIgniter\Database\RawSql('consumed_at IS NULL'))->update();
            $db->query("INSERT INTO auth_email_challenges
                (challenge_id, user_id, purpose, secret_hash, session_hash, auth_version, recipient, preview_secret, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW() + (? * INTERVAL '1 second'))", [$id, $userId, $purpose, self::Digest($id, $purpose, $secret), $purpose === 'signin' ? $binding : null,
                    $user['auth_version'], $user['email'], self::Seal($id, $secret), $seconds]);
            $db->transCommit();
        }
        catch (Throwable $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            // Do not propagate database messages/parameters that might contain credentials.
            throw new RuntimeException('The email request could not be prepared. Please try again later.');
        }
        $url = $purpose === 'reset' ? self::ResetUrl($id, $secret) : null;
        $result = Mailer::SendAuthenticationEmail($user, $purpose, $secret, $url);
        $db->table('auth_email_challenges')->set('delivery_status', $result['status'])->set('delivery_note', $result['note'])->where('challenge_id', $id)->update();
        return $public;
    }

    private static function Truth($value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private static function LockedUser(BaseConnection $db, int $userId): ?array
    {
        
        $stmt = $db->query($db->table('users')->where('user_id', $userId)->getCompiledSelect() . ' FOR UPDATE');
        return $stmt->getUnbufferedRow('array') ?: null;
    }

    private static function Challenge(BaseConnection $db, string $id, bool $isLock = false): ?array
    {
        $stmtSql = 'SELECT *, expires_at > NOW() AS is_fresh FROM auth_email_challenges WHERE challenge_id = ?' . ($isLock ? ' FOR UPDATE' : '');
        $stmt = $db->query($stmtSql, [$id]);
        return $stmt->getUnbufferedRow('array') ?: null;
    }

    private static function Eligible(?array $row, ?array $user, string $purpose): bool
    {
        return $row && $user && $row['purpose'] === $purpose && !$row['consumed_at'] && self::Truth($row['is_fresh'])
            && (int)$row['attempts'] < 5 && self::Truth($user['is_active']) && !self::Truth($user['is_locked'])
            && (int)$row['auth_version'] === (int)$user['auth_version']
            && mb_strtolower($row['recipient'] ?? '') === mb_strtolower($user['email'] ?? '');
    }

    /** Consume atomically, bound to the browser that requested this six-digit code. */
    public static function VerifySignIn(string $id, string $code, string $binding): ?array
    {
        if (!self::ValidId($id))
        {
            return null;
        }
        $db = Database::GetConnection('app');
        $initial = self::Challenge($db, $id);
        if (!$initial)
        {
            return null;
        }
        $db->transBegin();
        try
        {
            // Use the same user -> challenge lock order as requests and password resets.
            $user = self::LockedUser($db, (int)$initial['user_id']);
            $row = self::Challenge($db, $id, true);
            if (!self::Eligible($row, $user, 'signin') || !hash_equals($row['session_hash'] ?? '', $binding))
            {
                $db->transCommit();
                return null;
            }
            if (!preg_match('/^[0-9]{6}$/D', $code) || !hash_equals($row['secret_hash'], self::Digest($id, 'signin', $code)))
            {
                $db->table('auth_email_challenges')->set('attempts', new \CodeIgniter\Database\RawSql('attempts + 1'))->set('consumed_at', new \CodeIgniter\Database\RawSql('CASE WHEN attempts >= 4 THEN NOW() ELSE consumed_at END'))->set('preview_secret', new \CodeIgniter\Database\RawSql('CASE WHEN attempts >= 4 THEN NULL ELSE preview_secret END'))->where('challenge_id', $id)->update();
                $db->transCommit();
                return null;
            }
            $db->table('auth_email_challenges')->set('consumed_at', new \CodeIgniter\Database\RawSql('NOW()'))->set('preview_secret', null)->where('challenge_id', $id)->update();
            $db->transCommit();
            return $user;
        }
        catch (Throwable $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            throw new RuntimeException('Code sign-in is temporarily unavailable. Please try again later.');
        }
    }

    public static function ValidReset(string $id, string $secret): bool
    {
        if (!self::ValidId($id) || !preg_match('/^[a-f0-9]{64}$/D', $secret))
        {
            return false;
        }
        $db = Database::GetConnection('app');
        $row = self::Challenge($db, $id);
        if (!$row)
        {
            return false;
        }
        
        $stmt = $db->table('users')->where('user_id', $row['user_id'])->get();
        return self::Eligible($row, $stmt->getUnbufferedRow('array') ?: null, 'reset')
            && hash_equals($row['secret_hash'], self::Digest($id, 'reset', $secret));
    }

    public static function PasswordError(string $password, string $confirmation): ?string
    {
        if (strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0"))
        {
            return 'Use a password between 8 and 72 bytes (at least 8 characters).';
        }
        if ($password !== $confirmation)
        {
            return 'The passwords do not match.';
        }
        return null;
    }

    public static function ResetPassword(string $id, string $secret, string $password, string $confirmation): array
    {
        if (!self::ValidReset($id, $secret))
        {
            return ['ok' => false, 'error' => self::INVALID_RESET];
        }
        $error = self::PasswordError($password, $confirmation);
        if ($error)
        {
            return ['ok' => false, 'error' => $error];
        }
        $db = Database::GetConnection('app');
        $initial = self::Challenge($db, $id);
        if (!$initial)
        {
            return ['ok' => false, 'error' => self::INVALID_RESET];
        }
        $db->transBegin();
        try
        {
            $user = self::LockedUser($db, (int)$initial['user_id']);
            $row = self::Challenge($db, $id, true);
            if (!self::Eligible($row, $user, 'reset') || !hash_equals($row['secret_hash'], self::Digest($id, 'reset', $secret)))
            {
                $db->transCommit();
                return ['ok' => false, 'error' => self::INVALID_RESET];
            }
            if (password_verify($password, $user['password_hash']))
            {
                $db->transCommit();
                return ['ok' => false, 'error' => 'Choose a password different from your current password.'];
            }
            $db->table('users')->set('password_hash', password_hash($password, PASSWORD_BCRYPT))->set('password_changed_at', new \CodeIgniter\Database\RawSql('NOW()'))->set('must_change_password', false)->set('failed_login_attempts', 0)->set('auth_version', new \CodeIgniter\Database\RawSql('auth_version + 1'))->where('user_id', $user['user_id'])->update();
            self::Revoke($db, (int)$user['user_id']);
            $db->transCommit();
            return ['ok' => true];
        }
        catch (Throwable $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            throw new RuntimeException('Your password could not be updated. Please try again later.');
        }
    }

    public static function Revoke(BaseConnection $db, int $userId): void
    {
        $db->table('auth_email_challenges')->set('consumed_at', new \CodeIgniter\Database\RawSql('COALESCE(consumed_at, NOW())'))->set('preview_secret', null)->where('user_id', $userId)->update();
    }

    public static function ResetUrl(string $id, string $secret): string
    {
        return self::BaseUrl() . '/reset_password.php?id=' . rawurlencode($id) . '&token=' . rawurlencode($secret);
    }

    /** Call only after Auth::requirePage; secrets are never returned to guest request handlers. */
    public static function Preview(string $id): ?array
    {
        if (!self::ValidId($id))
        {
            return null;
        }
        $db = Database::GetConnection('app');
        self::Cleanup($db);
        $row = self::Challenge($db, $id);
        if (!$row)
        {
            return null;
        }
        
        $stmt = $db->table('users')->where('user_id', $row['user_id'])->get();
        $user = $stmt->getUnbufferedRow('array') ?: null;
        $row['employee_id'] = $user['employee_id'] ?? '';
        $row['is_available'] = self::Eligible($row, $user, $row['purpose']) && $row['preview_secret'] !== null;
        $secret = $row['is_available'] ? self::Unseal($id, $row['preview_secret']) : null;
        $row['secret'] = $secret;
        $row['reset_url'] = $secret && $row['purpose'] === 'reset' ? self::ResetUrl($id, $secret) : null;
        unset($row['secret_hash'], $row['session_hash'], $row['preview_secret']);
        return $row;
    }

    public static function Recent(): array
    {
        $db = Database::GetConnection('app');
        self::Cleanup($db);
        return $db->query('SELECT c.challenge_id, c.purpose, c.recipient, c.delivery_status, c.created_at, c.expires_at,
            c.consumed_at, u.employee_id FROM auth_email_challenges c JOIN users u ON u.user_id = c.user_id
            ORDER BY c.created_at DESC LIMIT 50')->getResultArray();
    }
}
