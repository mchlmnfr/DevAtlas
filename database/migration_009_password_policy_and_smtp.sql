/**
    * Filename: migration_009_password_policy_and_smtp.sql
    * Purpose: Apply the password policy and smtp database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- =====================================================================
-- AnalystToolkit :: migration_009_password_policy_and_smtp.sql
-- Run this if you already installed the original schema.sql.
-- Adds:
--   - Forced password change on first login (must_change_password)
--   - Password expiry tracking (password_changed_at + system_settings.password_expiry_days)
--   - Account lockout after repeated failed logins (failed_login_attempts, is_locked)
--   - system_settings: single-row Parameters (default password, expiry days,
--     max login attempts, SMTP config for the account-creation email)
-- =====================================================================

SET search_path TO toolkit, public;

ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_changed_at TIMESTAMP;
ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_login_attempts INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_locked BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_at TIMESTAMP;

-- Existing accounts (created before this migration) already have a real
-- password in place — don't force them all to change it at once. Only
-- newly-created accounts (via the app, from now on) get must_change_password = true.
UPDATE users SET must_change_password = FALSE, password_changed_at = NOW() WHERE password_changed_at IS NULL;

CREATE TABLE IF NOT EXISTS system_settings (
    id                      SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    default_password        VARCHAR(255) NOT NULL DEFAULT 'Welcome@12345',
    password_expiry_days    INTEGER NOT NULL DEFAULT 90,
    max_login_attempts      INTEGER NOT NULL DEFAULT 5,
    smtp_host               VARCHAR(255),
    smtp_port               INTEGER NOT NULL DEFAULT 587,
    smtp_username           VARCHAR(255),
    smtp_password           VARCHAR(255),
    smtp_encryption         VARCHAR(10) NOT NULL DEFAULT 'tls',
    smtp_from_email         VARCHAR(255),
    smtp_from_name          VARCHAR(150) NOT NULL DEFAULT 'AnalystToolkit',
    updated_at              TIMESTAMP NOT NULL DEFAULT NOW()
);

DROP TRIGGER IF EXISTS trg_system_settings_updated ON system_settings;
CREATE TRIGGER trg_system_settings_updated BEFORE UPDATE ON system_settings
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();

INSERT INTO system_settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING;
