<?php

/**
 * Filename: email-auth.php
 * Purpose: Verify email auth behavior using isolated regression fixtures.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** CLI integration fixtures. Uses a disposable PostgreSQL schema and never sends real email. */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
session_set_save_handler(static fn () => true, static fn () => true, static fn () => '', static fn () => true, static fn () => true, static fn () => 0);
require_once __DIR__ . '/../lib/EmailAuth.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/helpers.php';
set_exception_handler(static function (Throwable $e): void
{
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
});
set_error_handler(static function ($severity, $message, $file, $line)
{
    if (error_reporting() & $severity)
    {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
$db = Database::GetConnection('app');
$schema = 'email_auth_fixture_' . bin2hex(random_bytes(6));
$checks = 0;
function EaCheck(bool $isOk, string $message): void
{
    global $checks;
    $checks++;
    if (!$isOk)
    {
        throw new RuntimeException($message);
    }
}
function EaRelease(): void
{
    global $db;
    $db->query('TRUNCATE auth_email_limits');
    $db->query("UPDATE auth_email_challenges SET created_at = NOW() - INTERVAL '2 minutes'");
}
function EaRequest(string $purpose = 'signin', string $identifier = 'fixture', string $binding = 'browser-one'): array
{
    return EmailAuth::Request($purpose, $identifier, hash('sha256', $binding), '127.0.0.10');
}
try
{
    $db->query('CREATE SCHEMA "' . $schema . '"');
    $db->query('SET search_path TO "' . $schema . '", public');
    $db->query('CREATE TABLE org_positions (position_id SERIAL PRIMARY KEY, position_title TEXT);
        CREATE TABLE org_departments (department_id SERIAL PRIMARY KEY, department_name TEXT);
        CREATE TABLE users (user_id SERIAL PRIMARY KEY, employee_id VARCHAR(50) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL, first_name TEXT, last_name TEXT, email VARCHAR(200),
            is_active BOOLEAN DEFAULT TRUE, is_locked BOOLEAN DEFAULT FALSE, must_change_password BOOLEAN DEFAULT FALSE,
            password_changed_at TIMESTAMP DEFAULT NOW(), failed_login_attempts INTEGER DEFAULT 0, locked_at TIMESTAMP,
            position_id INTEGER, department_id INTEGER, profile_picture_path TEXT);
        CREATE TABLE system_settings (LIKE toolkit.system_settings INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');
    $migration = str_replace(
        'SET search_path TO toolkit, public;',
        'SET search_path TO "' . $schema . '", public;',
        file_get_contents(__DIR__ . '/../database/migration_015_email_auth.sql')
    );
    $db->query($migration);
    $db->query($migration);
    EaCheck((int)Database::Scalar($db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$schema' AND table_name LIKE 'auth_email_%'")) === 2, 'Migration is idempotent.');
    $db->query("INSERT INTO system_settings (id, password_expiry_days, auth_base_url) VALUES (1, 0, 'https://trusted.example/AnalystToolkit')");
    $hash = password_hash('Original123!', PASSWORD_BCRYPT);
    $stmtSql = "INSERT INTO users (employee_id,password_hash,first_name,last_name,email) VALUES (?,?,'Test','Fixture',?) RETURNING user_id";
    foreach (['fixture' => 'fixture@example.invalid', 'locked' => 'locked@example.invalid', 'disabled' => 'disabled@example.invalid',
        'noemail' => null, 'duplicate1' => 'shared@example.invalid', 'duplicate2' => 'shared@example.invalid'] as $employee => $email)
    {
        $stmt = $db->query($stmtSql, [$employee, $hash, $email]);
    }
    $db->query("UPDATE users SET is_locked = TRUE WHERE employee_id = 'locked'; UPDATE users SET is_active = FALSE WHERE employee_id = 'disabled'");
    $request = EaRequest();
    $preview = EmailAuth::Preview($request['id']);
    EaCheck($request['message'] === EmailAuth::REQUEST_MESSAGE && !isset($request['secret'], $request['email']), 'Guest response is generic and contains no credential.');
    EaCheck($preview && preg_match('/^[0-9]{6}$/D', $preview['secret']) === 1, 'Six-digit code is available in protected preview.');
    EaCheck($preview['delivery_status'] === 'unavailable', 'Missing SMTP configuration is clear in protected details.');
    $row = $db->query('SELECT * FROM auth_email_challenges')->getUnbufferedRow('array');
    EaCheck($row['preview_secret'] !== $preview['secret'] && !str_contains($row['preview_secret'], $preview['secret']), 'Preview credential is encrypted.');
    EaCheck($row['secret_hash'] !== $preview['secret'] && !isset($preview['secret_hash'], $preview['session_hash']), 'Verifier is hashed and omitted from preview.');
    EaCheck(Database::Scalar($db->query("SELECT password_hash FROM users WHERE employee_id='fixture'")) === $hash, 'Requesting a code does not alter a password.');
    EaCheck(EmailAuth::VerifySignIn($request['id'], $preview['secret'], hash('sha256', 'different-browser')) === null, 'A code is bound to the original requesting browser.');
    EaCheck((int)Database::Scalar($db->query('SELECT attempts FROM auth_email_challenges')) === 0, 'A different browser cannot exhaust code attempts.');
    $result = Auth::AttemptEmailCode($request['id'], $preview['secret'], hash('sha256', 'browser-one'));
    EaCheck($result['ok'] && $_SESSION['employee_id'] === 'fixture' && !$_SESSION['must_change_password'], 'Code establishes the normal authenticated session; zero-day expiry stays disabled.');
    EaCheck(!isset($_SESSION['csrf_token'], $_SESSION['email_auth_nonce']), 'Sign-in rotates CSRF and discards guest challenges.');
    EaCheck(EmailAuth::VerifySignIn($request['id'], $preview['secret'], hash('sha256', 'browser-one')) === null, 'Code cannot be replayed.');
    EaCheck(EmailAuth::Preview($request['id'])['secret'] === null, 'Used code cannot be previewed.');
    $_SESSION = [];
    foreach (['unknown', 'locked', 'disabled', 'shared@example.invalid'] as $identifier)
    {
        EaRelease();
        $before = (int)Database::Scalar($db->query('SELECT COUNT(*) FROM auth_email_challenges'));
        $answer = EaRequest('reset', $identifier);
        EaCheck($answer['message'] === $request['message'], 'Unknown, locked, disabled and ambiguous accounts use the same response.');
        EaCheck((int)Database::Scalar($db->query('SELECT COUNT(*) FROM auth_email_challenges')) === $before, 'Ineligible accounts create no credential.');
    }
    EaRelease();
    $noEmail = EaRequest('reset', 'noemail');
    EaCheck(EmailAuth::Preview($noEmail['id'])['secret'] !== null && EmailAuth::Preview($noEmail['id'])['delivery_status'] === 'unavailable', 'Support fallback works for an eligible account without email.');
    EaRelease();
    $email = EaRequest('signin', 'FIXTURE@example.invalid');
    EaCheck(EmailAuth::Preview($email['id'])['employee_id'] === 'fixture', 'Registered email lookup is case insensitive.');
    EaRelease();
    $first = EaRequest();
    $firstCode = EmailAuth::Preview($first['id'])['secret'];
    $throttled = EmailAuth::Request('signin', 'fixture', hash('sha256', 'browser-one'), '127.0.0.10', $first['id']);
    EaCheck($throttled['id'] === $first['id'] && EmailAuth::Preview($first['id'])['secret'] === $firstCode, 'Immediate resend keeps the current code and enforces cooldown.');
    EaRelease();
    $second = EaRequest();
    EaCheck(EmailAuth::Preview($first['id'])['secret'] === null, 'A permitted resend revokes the old code.');
    $secondCode = EmailAuth::Preview($second['id'])['secret'];
    $wrong = $secondCode === '999999' ? '888888' : '999999';
    for ($i = 0; $i < 5; $i++)
    {
        EaCheck(EmailAuth::VerifySignIn($second['id'], $wrong, hash('sha256', 'browser-one')) === null, 'Wrong code rejected.');
    }
    EaCheck(EmailAuth::VerifySignIn($second['id'], $secondCode, hash('sha256', 'browser-one')) === null, 'Five wrong attempts invalidate the correct code too.');
    EaCheck(EmailAuth::Preview($second['id'])['secret'] === null, 'Exhausted code preview is removed.');
    EaRelease();
    $expired = EaRequest();
    $expiredCode = EmailAuth::Preview($expired['id'])['secret'];
    $db->query("UPDATE auth_email_challenges SET expires_at=NOW()-INTERVAL '1 second' WHERE challenge_id=?", [$expired['id']]);
    EaCheck(EmailAuth::VerifySignIn($expired['id'], $expiredCode, hash('sha256', 'browser-one')) === null, 'Expired code rejected.');
    EaCheck(EmailAuth::Preview($expired['id'])['secret'] === null, 'Expired preview credential is purged.');
    EaRelease();
    $reset = EaRequest('reset');
    $resetPreview = EmailAuth::Preview($reset['id']);
    $token = $resetPreview['secret'];
    EaCheck(strlen($token) === 64 && EmailAuth::ValidReset($reset['id'], $token), 'Reset link has a valid 256-bit token.');
    $_SERVER['HTTP_HOST'] = 'attacker.example';
    EaCheck(str_starts_with($resetPreview['reset_url'], 'https://trusted.example/AnalystToolkit/'), 'Reset links use the configured origin, not request Host.');
    EaCheck(!EmailAuth::ValidReset($reset['id'], str_repeat('0', 64)), 'Incorrect reset token rejected.');
    EaCheck(!EmailAuth::ResetPassword($reset['id'], $token, 'short', 'short')['ok'], 'Short password rejected.');
    EaCheck(!EmailAuth::ResetPassword($reset['id'], $token, str_repeat('a', 73), str_repeat('a', 73))['ok'], 'Bcrypt truncation is prevented.');
    EaCheck(!EmailAuth::ResetPassword($reset['id'], $token, 'NewPassword123!', 'mismatch')['ok'], 'Mismatched confirmation rejected.');
    EaCheck(!EmailAuth::ResetPassword($reset['id'], $token, 'Original123!', 'Original123!')['ok'], 'Reusing the current password rejected.');
    EaCheck(EmailAuth::ValidReset($reset['id'], $token), 'Validation errors do not consume a reset link.');
    EaRelease();
    $pendingCode = EaRequest();
    $pendingSecret = EmailAuth::Preview($pendingCode['id'])['secret'];
    $userId = (int)Database::Scalar($db->query("SELECT user_id FROM users WHERE employee_id='fixture'"));
    $_SESSION = ['user_id' => $userId, 'auth_version' => 1, 'auth_checked_at' => 0];
    $result = EmailAuth::ResetPassword($reset['id'], $token, 'NewPassword123!', 'NewPassword123!');
    EaCheck($result['ok'], 'Reset updates the password.');
    $user = $db->query("SELECT * FROM users WHERE employee_id='fixture'")->getUnbufferedRow('array');
    EaCheck(password_verify('NewPassword123!', $user['password_hash']) && !$user['must_change_password'] && (int)$user['auth_version'] === 2, 'Reset completes password policy and increments session version.');
    EaCheck(!EmailAuth::ValidReset($reset['id'], $token) && !EmailAuth::ResetPassword($reset['id'], $token, 'Another123!', 'Another123!')['ok'], 'Reset token cannot be replayed.');
    EaCheck(EmailAuth::VerifySignIn($pendingCode['id'], $pendingSecret, hash('sha256', 'browser-one')) === null, 'Password reset revokes pending sign-in codes.');
    EaCheck((int)Database::Scalar($db->query("SELECT COUNT(*) FROM auth_email_challenges WHERE user_id=$userId AND preview_secret IS NOT NULL")) === 0, 'Reset deletes all recoverable credentials for the user.');
    EaCheck(!Auth::Check(), 'An old session is invalidated at the next account check.');
    session_start();
    EaCheck(Auth::Attempt('fixture', 'NewPassword123!')['ok'], 'New password can sign in.');
    EaCheck((int)$_SESSION['auth_version'] === 2, 'Normal login captures the new session version.');
    $_SESSION = [];
    EaRelease();
    $db->query("UPDATE users SET must_change_password=TRUE WHERE employee_id='fixture'");
    $forced = EaRequest();
    $forcedSecret = EmailAuth::Preview($forced['id'])['secret'];
    EaCheck(Auth::AttemptEmailCode($forced['id'], $forcedSecret, hash('sha256', 'browser-one'))['ok'] && Auth::MustChangePassword(), 'Email sign-in retains mandatory password change.');
    $_SESSION = [];
    EaRelease();
    $disabledAfter = EaRequest();
    $disabledSecret = EmailAuth::Preview($disabledAfter['id'])['secret'];
    $db->query("UPDATE users SET is_active=FALSE WHERE employee_id='fixture'");
    EaCheck(EmailAuth::VerifySignIn($disabledAfter['id'], $disabledSecret, hash('sha256', 'browser-one')) === null, 'Disabling an account invalidates an existing code.');
    EaCheck(EmailAuth::Preview($disabledAfter['id'])['secret'] === null, 'Disabling an account removes its preview.');
    $db->query("UPDATE users SET is_active=TRUE, must_change_password=FALSE WHERE employee_id='fixture'");
    EaRelease();
    $changedEmail = EaRequest('reset');
    $changedToken = EmailAuth::Preview($changedEmail['id'])['secret'];
    $db->query("UPDATE users SET email='changed@example.invalid' WHERE employee_id='fixture'");
    EaCheck(!EmailAuth::ValidReset($changedEmail['id'], $changedToken), 'Changing the registered email invalidates existing reset links.');
    foreach (['https://trusted.example/AnalystToolkit', 'http://intranet:8080/AnalystToolkit/'] as $url)
    {
        EaCheck(EmailAuth::NormalizeBaseUrl($url) !== null, 'Configured application URL accepted.');
    }
    foreach (['//evil/AnalystToolkit', 'javascript:alert(1)', 'https://trusted.example/Other', 'https://user:pass@trusted.example/AnalystToolkit',
        'https://trusted.example/AnalystToolkit?q=x', 'https://trusted.example/AnalystToolkit#x', "https://trusted.example/AnalystToolkit\r\nX: y"] as $url)
    {
        EaCheck(EmailAuth::NormalizeBaseUrl($url) === null, 'Unsafe application URL rejected.');
    }
    EaCheck(EmailAuth::Preview('../../config/config.php') === null, 'Invalid preview references rejected.');
    EaCheck(EmailAuth::PasswordError("password\0value", "password\0value") !== null, 'Null-byte password rejected.');
    EaRelease();
    $lockedRequest = EaRequest('reset');
    $lockedToken = EmailAuth::Preview($lockedRequest['id'])['secret'];
    $db->query('UPDATE system_settings SET max_login_attempts=1');
    EaCheck(!Auth::Attempt('fixture', 'Incorrect123!')['ok'], 'Incorrect password triggers the configured account lock.');
    EaCheck(!EmailAuth::ValidReset($lockedRequest['id'], $lockedToken), 'Account lock invalidates existing email credentials.');
    $db->query("UPDATE users SET is_locked=FALSE,failed_login_attempts=0 WHERE employee_id='fixture'");
    EaCheck(!EmailAuth::ValidReset($lockedRequest['id'], $lockedToken), 'Unlocking cannot revive a credential issued before account lock.');
    EaRelease();
    for ($i = 0;$i < 30;$i++)
    {
        EmailAuth::Request('signin', 'unknown-ip-'.$i, hash('sha256', 'different-session-'.$i), '127.0.0.11');
    }
    $limited = EmailAuth::Request('signin', 'fixture', hash('sha256', 'new-session'), '127.0.0.11');
    EaCheck(EmailAuth::Preview($limited['id']) === null, 'IP request cap holds across new sessions and identifiers.');
    EaRelease();
    for ($i = 0;$i < 10;$i++)
    {
        EmailAuth::Request('signin', 'unknown-session-'.$i, hash('sha256', 'same-session'), '127.0.1.'.$i);
    }
    $limited = EmailAuth::Request('signin', 'fixture', hash('sha256', 'same-session'), '127.0.2.1');
    EaCheck(EmailAuth::Preview($limited['id']) === null, 'Session request cap holds across identifiers and client addresses.');
    EaRelease();
    $db->query("UPDATE system_settings SET smtp_host='127.0.0.1',smtp_port=1,smtp_from_email='test@example.invalid',smtp_encryption='none'");
    $failed = EaRequest('reset');
    EaCheck(EmailAuth::Preview($failed['id'])['delivery_status'] === 'failed' && EmailAuth::Preview($failed['id'])['secret'] !== null, 'SMTP failure keeps an encrypted support preview.');
    $db->query("UPDATE auth_email_challenges SET created_at=NOW()-INTERVAL '2 days'; UPDATE auth_email_limits SET last_attempt_at=NOW()-INTERVAL '2 days'");
    EmailAuth::Cleanup($db);
    EaCheck((int)Database::Scalar($db->query('SELECT COUNT(*) FROM auth_email_challenges')) === 0 && (int)Database::Scalar($db->query('SELECT COUNT(*) FROM auth_email_limits')) === 0, 'One-day cleanup bounds delivery history and request buckets.');
}
finally
{
    if (Database::InTransaction($db))
    {
        $db->transRollback();
    }
    $db->query('SET search_path TO toolkit, public');
    if (preg_match('/^email_auth_fixture_[a-f0-9]{12}$/D', $schema))
    {
        $db->query('DROP SCHEMA IF EXISTS "' . $schema . '" CASCADE');
    }
}
echo "Email authentication: $checks assertions passed.\n";
