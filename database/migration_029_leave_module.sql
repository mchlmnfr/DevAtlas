/**
 * Filename: migration_029_leave_module.sql
 * Purpose: Enable the department leave calendar and independent section leave approvals.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Preserve existing leave rows and grant department visibility to existing roles.
 */
SET search_path TO toolkit, public;
BEGIN;
CREATE INDEX IF NOT EXISTS idx_daily_leave_calendar ON daily_logs(activity_date,user_id) WHERE entry_kind='leave';
INSERT INTO role_permissions(role_key,module,permission,is_allowed)
SELECT r.role_key,'leaves',p.permission,
    CASE WHEN p.permission='view' THEN TRUE
        WHEN p.permission='review' THEN r.role_key='admin'
            OR regexp_replace(LOWER(r.role_key),'[^a-z]','','g')='sectionhead'
            OR regexp_replace(LOWER(r.role_name),'[^a-z]','','g')='sectionhead'
        ELSE EXISTS(SELECT 1 FROM role_permissions old WHERE old.role_key=r.role_key AND old.module='dailylogs' AND old.permission=p.permission AND old.is_allowed)
    END
FROM app_roles r CROSS JOIN(VALUES('view'),('create'),('edit'),('delete'),('review')) p(permission)
ON CONFLICT(role_key,module,permission) DO NOTHING;
COMMIT;
