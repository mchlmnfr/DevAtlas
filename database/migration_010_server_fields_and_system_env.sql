/**
    * Filename: migration_010_server_fields_and_system_env.sql
    * Purpose: Apply the server fields and system env database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- =====================================================================
-- AnalystToolkit :: migration_010_server_fields_and_system_env.sql
-- Run this if you already installed the original schema.sql.
-- Adds:
--   - servers.hosting_type   (AWS | On-Premise)
--   - servers.server_type    (App Server | DB Server)
--   - hosted_systems.current_environment  (which env this system runs in now)
--   - group_systems.current_environment
-- The Environment field is now a fixed dropdown in the UI (Dev, Prod, SIT,
-- UAT, SAT, SIT & UAT, UAT & SAT, SIT, UAT & SAT) instead of free text —
-- no DB constraint is added for this, so existing rows with older/other
-- values (e.g. "Non-Prod/DR") are left as-is rather than breaking the
-- migration; only new entries going forward are constrained by the UI.
-- =====================================================================

SET search_path TO toolkit, public;

ALTER TABLE servers ADD COLUMN IF NOT EXISTS hosting_type VARCHAR(20);
ALTER TABLE servers ADD COLUMN IF NOT EXISTS server_type VARCHAR(20);

ALTER TABLE hosted_systems ADD COLUMN IF NOT EXISTS current_environment VARCHAR(30);
ALTER TABLE group_systems ADD COLUMN IF NOT EXISTS current_environment VARCHAR(30);
