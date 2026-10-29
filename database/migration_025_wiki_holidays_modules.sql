/**
    * Filename: migration_025_wiki_holidays_modules.sql
    * Purpose: Apply the wiki holidays modules database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- Documentation and the existing organization holiday calendar get separate navigation and view grants.
SET search_path TO toolkit, public;
BEGIN;
INSERT INTO role_permissions(role_key,module,permission,is_allowed)
SELECT role_key,'wiki','view',TRUE FROM app_roles
ON CONFLICT(role_key,module,permission) DO NOTHING;
INSERT INTO role_permissions(role_key,module,permission,is_allowed)
SELECT r.role_key,'holidays','view',r.role_key='admin' OR EXISTS(
    SELECT 1 FROM role_permissions p WHERE p.role_key=r.role_key
    AND p.module='teammonitoring' AND p.permission='view' AND p.is_allowed
) FROM app_roles r
ON CONFLICT(role_key,module,permission) DO NOTHING;
COMMIT;
