/**
    * Filename: migration_015_email_auth.sql
    * Purpose: Apply the email auth database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- Email password recovery, one-time sign-in codes and protected delivery previews.
-- Safe to rerun. Existing passwords, account status and SMTP settings are retained.
SET search_path TO toolkit, public;
BEGIN;
ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_version INTEGER NOT NULL DEFAULT 1;
ALTER TABLE system_settings ADD COLUMN IF NOT EXISTS auth_base_url VARCHAR(500);

CREATE TABLE IF NOT EXISTS auth_email_challenges (
    challenge_id CHAR(32) PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    purpose VARCHAR(10) NOT NULL CHECK (purpose IN ('reset', 'signin')),
    secret_hash CHAR(64) NOT NULL,
    session_hash CHAR(64),
    auth_version INTEGER NOT NULL,
    recipient VARCHAR(200),
    preview_secret TEXT,
    attempts INTEGER NOT NULL DEFAULT 0,
    delivery_status VARCHAR(15) NOT NULL DEFAULT 'pending'
        CHECK (delivery_status IN ('pending', 'sent', 'unavailable', 'failed')),
    delivery_note VARCHAR(250),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_auth_email_user ON auth_email_challenges(user_id, purpose);
CREATE INDEX IF NOT EXISTS idx_auth_email_expiry ON auth_email_challenges(expires_at);

-- Only hashed identifiers/IPs/session nonces are stored in the rate-limit table.
CREATE TABLE IF NOT EXISTS auth_email_limits (
    key_hash CHAR(64) PRIMARY KEY,
    window_started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_attempt_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    attempts INTEGER NOT NULL DEFAULT 0
);
COMMIT;
