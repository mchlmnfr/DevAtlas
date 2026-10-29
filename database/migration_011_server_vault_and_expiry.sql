/**
    * Filename: migration_011_server_vault_and_expiry.sql
    * Purpose: Apply the server vault and expiry database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- =====================================================================
-- AnalystToolkit :: migration_011_server_vault_and_expiry.sql
-- Run this if you already installed the original schema.sql.
-- Adds:
--   - Multi-select "current environment" on hosted_systems/group_systems
--     (widened to hold a comma-separated list; base options: Dev, SIT,
--     UAT, SAT, Prod, DR, Non-Prod(DR))
--   - repository_url on hosted_systems/group_systems (GitHub link)
--   - server_access_protocols (RDP/SSH/FTP/WinRM/...)
--   - server_credentials + server_credential_shares (encrypted vault
--     with creator audit trail and explicit per-user sharing)
--   - expiry_items (SSL certs, licenses, other services)
-- IMPORTANT: the credentials vault encrypts passwords using
-- config.php's APP_ENCRYPTION_KEY. Set that to a real random value
-- BEFORE anyone stores a credential — see config.php for instructions.
-- =====================================================================

SET search_path TO toolkit, public;

ALTER TABLE hosted_systems ALTER COLUMN current_environment TYPE VARCHAR(150);
ALTER TABLE hosted_systems ADD COLUMN IF NOT EXISTS repository_url VARCHAR(500);

ALTER TABLE group_systems ALTER COLUMN current_environment TYPE VARCHAR(150);
ALTER TABLE group_systems ADD COLUMN IF NOT EXISTS repository_url VARCHAR(500);

CREATE TABLE IF NOT EXISTS server_access_protocols (
    protocol_id     SERIAL PRIMARY KEY,
    server_id       INTEGER NOT NULL REFERENCES servers(server_id) ON DELETE CASCADE,
    protocol        VARCHAR(20) NOT NULL,
    port            VARCHAR(10),
    notes           TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS server_credentials (
    credential_id       SERIAL PRIMARY KEY,
    server_id            INTEGER NOT NULL REFERENCES servers(server_id) ON DELETE CASCADE,
    label                VARCHAR(150) NOT NULL,
    username             VARCHAR(150) NOT NULL,
    password_encrypted   TEXT NOT NULL,
    created_by           INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS server_credential_shares (
    share_id              SERIAL PRIMARY KEY,
    credential_id          INTEGER NOT NULL REFERENCES server_credentials(credential_id) ON DELETE CASCADE,
    shared_with_user_id    INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    shared_by_user_id      INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    shared_at              TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (credential_id, shared_with_user_id)
);

CREATE TABLE IF NOT EXISTS expiry_items (
    expiry_id            SERIAL PRIMARY KEY,
    item_type             VARCHAR(30) NOT NULL CHECK (item_type IN ('SSL Certificate','License','Other Service')),
    item_name             VARCHAR(200) NOT NULL,
    server_id              INTEGER REFERENCES servers(server_id) ON DELETE SET NULL,
    hosted_system_id       INTEGER REFERENCES hosted_systems(hosted_system_id) ON DELETE SET NULL,
    issued_date            DATE,
    expiry_date            DATE NOT NULL,
    alert_days_before       INTEGER NOT NULL DEFAULT 30,
    notes                   TEXT,
    created_at               TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_server_access_protocols_server ON server_access_protocols(server_id);
CREATE INDEX IF NOT EXISTS idx_server_credentials_server ON server_credentials(server_id);
CREATE INDEX IF NOT EXISTS idx_server_credential_shares_cred ON server_credential_shares(credential_id);
CREATE INDEX IF NOT EXISTS idx_server_credential_shares_user ON server_credential_shares(shared_with_user_id);
CREATE INDEX IF NOT EXISTS idx_expiry_items_expiry_date ON expiry_items(expiry_date);
CREATE INDEX IF NOT EXISTS idx_expiry_items_server ON expiry_items(server_id);

DROP TRIGGER IF EXISTS trg_server_credentials_updated ON server_credentials;
CREATE TRIGGER trg_server_credentials_updated BEFORE UPDATE ON server_credentials
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();
DROP TRIGGER IF EXISTS trg_expiry_items_updated ON expiry_items;
CREATE TRIGGER trg_expiry_items_updated BEFORE UPDATE ON expiry_items
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();
