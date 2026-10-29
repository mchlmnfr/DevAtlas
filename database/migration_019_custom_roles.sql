/**
    * Filename: migration_019_custom_roles.sql
    * Purpose: Apply the custom roles database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- Custom access roles. Existing accounts and permission choices are preserved.
SET search_path TO toolkit, public;
BEGIN;
ALTER TABLE app_roles ADD COLUMN IF NOT EXISTS description VARCHAR(500) NOT NULL DEFAULT '';
ALTER TABLE app_roles ADD COLUMN IF NOT EXISTS is_system BOOLEAN NOT NULL DEFAULT FALSE;
UPDATE app_roles SET is_system=TRUE WHERE role_key IN ('admin','analyst','reviewer','viewer') AND NOT is_system;
CREATE UNIQUE INDEX IF NOT EXISTS idx_app_roles_name ON app_roles(LOWER(BTRIM(role_name)));
DROP TRIGGER IF EXISTS audit_row_change ON app_roles;
CREATE TRIGGER audit_row_change AFTER INSERT OR UPDATE OR DELETE ON app_roles
    FOR EACH ROW EXECUTE FUNCTION toolkit.audit_capture_change('role_key');
COMMIT;
