/**
 * Filename: migration_002_server_groups.sql
 * Purpose: Apply the server groups database upgrade.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

-- =====================================================================
-- AnalystToolkit :: migration_002_server_groups.sql
-- Run this ONLY if you already installed the original schema.sql and
-- don't want to drop/recreate. Safe to re-run (uses IF NOT EXISTS).
-- Adds:
--   - server_groups table (logical clusters, e.g. "Zior Cluster")
--   - servers.server_group_id column (assigns a server to a group)
--   - group_systems table (systems that run across a whole group,
--     e.g. BMIPORTAL SSO, HROCS)
--   - relaxes servers.environment from a fixed CHECK list to free text
--     (so values like "UAT/SIT" are allowed alongside Dev/SIT/UAT/SAT/Prod)
-- =====================================================================

SET search_path TO toolkit, public;

CREATE TABLE IF NOT EXISTS server_groups (
    server_group_id  SERIAL PRIMARY KEY,
    group_name        VARCHAR(150) NOT NULL UNIQUE,
    description        TEXT,
    created_at         TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMP NOT NULL DEFAULT NOW()
);

ALTER TABLE servers ADD COLUMN IF NOT EXISTS server_group_id INTEGER
    REFERENCES server_groups(server_group_id) ON DELETE SET NULL;

-- Drop the old fixed-list CHECK constraint if it exists, and widen the column
DO $$
DECLARE cname text;
BEGIN
  SELECT con.conname INTO cname
  FROM pg_constraint con
  JOIN pg_class rel ON rel.oid = con.conrelid
  JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
  WHERE rel.relname = 'servers' AND nsp.nspname = 'toolkit' AND con.contype = 'c'
    AND pg_get_constraintdef(con.oid) ILIKE '%environment%';
  IF cname IS NOT NULL THEN
    EXECUTE format('ALTER TABLE servers DROP CONSTRAINT %I', cname);
  END IF;
END $$;

ALTER TABLE servers ALTER COLUMN environment TYPE VARCHAR(30);

CREATE TABLE IF NOT EXISTS group_systems (
    group_system_id   SERIAL PRIMARY KEY,
    server_group_id   INTEGER NOT NULL REFERENCES server_groups(server_group_id) ON DELETE CASCADE,
    system_name       VARCHAR(150) NOT NULL,
    description        TEXT,
    created_at         TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_servers_group ON servers(server_group_id);
CREATE INDEX IF NOT EXISTS idx_group_systems_group ON group_systems(server_group_id);
CREATE INDEX IF NOT EXISTS idx_group_systems_name ON group_systems(system_name);

DROP TRIGGER IF EXISTS trg_server_groups_updated ON server_groups;
CREATE TRIGGER trg_server_groups_updated BEFORE UPDATE ON server_groups
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();

DROP TRIGGER IF EXISTS trg_group_systems_updated ON group_systems;
CREATE TRIGGER trg_group_systems_updated BEFORE UPDATE ON group_systems
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();

-- Example seed matching the sample in the requirements (safe to delete):
-- INSERT INTO server_groups (group_name, description) VALUES ('Zior Cluster', 'Core portal/SSO cluster');
