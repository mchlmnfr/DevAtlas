/**
    * Filename: migration_023_reports_module.sql
    * Purpose: Apply the reports module database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- Move reporting access into a separate module, preserving configured grants.
SET search_path TO toolkit, public;
BEGIN;
INSERT INTO role_permissions(role_key,module,permission,is_allowed)
SELECT r.role_key,'reports',p.permission,
    r.role_key='admin' OR EXISTS(SELECT 1 FROM role_permissions old WHERE old.role_key=r.role_key AND old.is_allowed AND
        ((p.permission='view' AND old.module IN('dailylogs','teammonitoring') AND old.permission='view') OR
        (p.permission='export' AND old.module='teammonitoring' AND old.permission='export')))
FROM app_roles r CROSS JOIN (VALUES('view'),('export')) p(permission)
ON CONFLICT(role_key,module,permission) DO NOTHING;
COMMIT;
