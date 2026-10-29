/**
 * Filename: migration_022_section_reports.sql
 * Purpose: Apply the section reports database upgrade.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

-- Section reports, Analyst monitoring and revised preset permission defaults.
SET search_path TO toolkit, public;
BEGIN;
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(80) PRIMARY KEY,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
DO $$ BEGIN
    IF NOT EXISTS(SELECT 1 FROM schema_migrations WHERE migration_key='022_section_reports') THEN
        UPDATE role_permissions SET is_allowed=FALSE
        WHERE role_key IN('analyst','reviewer','viewer') AND module IN('applications','requirements');
        INSERT INTO role_permissions(role_key,module,permission,is_allowed)
        VALUES ('analyst','teammonitoring','view',TRUE),('analyst','teammonitoring','export',TRUE),
               ('reviewer','teammonitoring','export',TRUE),('admin','teammonitoring','export',TRUE)
        ON CONFLICT(role_key,module,permission) DO UPDATE SET is_allowed=EXCLUDED.is_allowed;
        INSERT INTO schema_migrations(migration_key) VALUES('022_section_reports');
    END IF;
END $$;
COMMIT;
