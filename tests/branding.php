<?php

/**
 * Filename: branding.php
 * Purpose: Verify branding behavior using isolated regression fixtures.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Sender-name migration must preserve operational settings, data and access grants. */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
session_set_save_handler(static fn () => true, static fn () => true, static fn () => '', static fn () => true, static fn () => true, static fn () => 0);
require_once __DIR__.'/../lib/Permissions.php';
set_exception_handler(static function (Throwable $e): void
{
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
});
$db = Database::GetConnection('app');
$schema = 'brand_fixture_'.bin2hex(random_bytes(6));
$checks = 0;
function BrandCheck(bool $isOk, string $message): void
{
    global $checks;
    $checks++;
    if (!$isOk)
    {
        throw new RuntimeException($message);
    }
}
try
{
    $db->query(preg_replace('/\btoolkit\b/', $schema, file_get_contents(__DIR__.'/../database/schema.sql')));
    $db->query('SET search_path TO "'.$schema.'",public');
    BrandCheck(Database::Scalar($db->query('SELECT smtp_from_name FROM system_settings WHERE id=1')) === 'DevAtlas', 'Fresh installs use the DevAtlas mail identity.');
    $db->query("UPDATE system_settings SET smtp_from_name='AnalystToolKit ',smtp_host='fixture.internal',smtp_password='fixture-secret' WHERE id=1");
    $settings = $db->query('SELECT * FROM system_settings WHERE id=1')->getUnbufferedRow('array');
    unset($settings['smtp_from_name'],$settings['updated_at']);
    $queries = ['SELECT user_id,role_key,is_active FROM users ORDER BY user_id','SELECT * FROM role_permissions ORDER BY permission_id','SELECT * FROM daily_logs ORDER BY log_id','SELECT * FROM daily_log_holidays ORDER BY holiday_id','SELECT * FROM daily_log_week_reviews ORDER BY week_review_id'];
    $before = array_map(fn ($sql) => hash('sha256', json_encode($db->query($sql)->getResultArray())), $queries);
    $migration = preg_replace('/\btoolkit\b/', $schema, file_get_contents(__DIR__.'/../database/migration_027_devatlas_brand.sql'));
    $db->query($migration);
    $db->query($migration);
    BrandCheck(Database::Scalar($db->query('SELECT smtp_from_name FROM system_settings WHERE id=1')) === 'DevAtlas', 'The legacy default sender is rebranded idempotently.');
    $after = $db->query('SELECT * FROM system_settings WHERE id=1')->getUnbufferedRow('array');
    unset($after['smtp_from_name'],$after['updated_at']);
    BrandCheck($after === $settings, 'SMTP credentials, connection settings and policies are unchanged.');
    BrandCheck($before === array_map(fn ($sql) => hash('sha256', json_encode($db->query($sql)->getResultArray())), $queries), 'Rebranding preserves accounts, permissions, logs, holidays and decisions.');
    $db->query("UPDATE system_settings SET smtp_from_name='Department IT Services' WHERE id=1");
    $db->query($migration);
    BrandCheck(Database::Scalar($db->query('SELECT smtp_from_name FROM system_settings WHERE id=1')) === 'Department IT Services', 'Customized sender names are preserved.');
    $_SESSION['user_id'] = (int)Database::Scalar($db->query("SELECT user_id FROM users WHERE role_key='admin' LIMIT 1"));
    Permissions::Reset();
    BrandCheck(!Permissions::Visible('applications') && !Permissions::Visible('requirements') && Permissions::Visible('dailylogs'), 'Temporary navigation visibility hides only the requested modules.');
    BrandCheck(Permissions::Can('applications') && Permissions::Can('requirements'), 'Navigation hiding preserves existing administrative access grants.');
    echo "DevAtlas migration fixtures: {$checks} assertions passed.\n";
}
finally
{
    if (Database::InTransaction($db))
    {
        $db->transRollback();
    }
    $db->query('SET search_path TO public');
    $db->query('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
    Permissions::Reset();
}
