/**
    * Filename: migration_020_daily_logs.sql
    * Purpose: Apply the daily logs database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- Team monitoring and personal daily activity / leave logs.
SET search_path TO toolkit, public;
BEGIN;
CREATE TABLE IF NOT EXISTS daily_logs (
    log_id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE RESTRICT,
    activity_date DATE NOT NULL,
    entry_kind VARCHAR(10) NOT NULL CHECK (entry_kind IN ('activity','leave')),
    classification VARCHAR(10) CHECK (classification IN ('SD','SCR','PROJECT')),
    activity_type VARCHAR(2) CHECK (activity_type IN ('AD','AN','CR','DD','DP','DR','ED','EM','HD','MR','PI','RV','SF','ST','TD','TM','UA','UM','UT','VC')),
    planned_task TEXT NOT NULL DEFAULT '',
    actual_activity TEXT NOT NULL DEFAULT '',
    duration_hours NUMERIC(5,2) NOT NULL DEFAULT 0 CHECK (duration_hours BETWEEN 0 AND 24),
    leave_type VARCHAR(2) CHECK (leave_type IN ('VL','SL','ML','EL','UT')),
    leave_period VARCHAR(5) CHECK (leave_period IN ('AM','PM','WHOLE')),
    remarks TEXT NOT NULL DEFAULT '',
    status VARCHAR(10) NOT NULL DEFAULT 'Draft' CHECK (status IN ('Draft','Submitted','Reviewed','Returned')),
    review_comment TEXT NOT NULL DEFAULT '',
    reviewed_by INTEGER REFERENCES users(user_id) ON DELETE RESTRICT,
    submitted_at TIMESTAMPTZ,
    reviewed_at TIMESTAMPTZ,
    version INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK ((entry_kind='activity' AND leave_type IS NULL AND leave_period IS NULL)
        OR (entry_kind='leave' AND classification IS NULL AND activity_type IS NULL)),
    CHECK (status NOT IN ('Submitted','Reviewed') OR (duration_hours>0 AND
        ((entry_kind='activity' AND classification IS NOT NULL AND activity_type IS NOT NULL AND BTRIM(planned_task)<>'' AND BTRIM(actual_activity)<>'')
        OR (entry_kind='leave' AND leave_type IS NOT NULL AND duration_hours<=8 AND
            ((leave_type='UT' AND leave_period IS NULL) OR (leave_type<>'UT' AND leave_period IS NOT NULL))))))
);
CREATE INDEX IF NOT EXISTS idx_daily_logs_owner_date ON daily_logs(user_id,activity_date DESC,log_id DESC);
CREATE INDEX IF NOT EXISTS idx_daily_logs_status_date ON daily_logs(status,activity_date DESC);
DROP TRIGGER IF EXISTS audit_row_change ON daily_logs;
CREATE TRIGGER audit_row_change AFTER INSERT OR UPDATE OR DELETE ON daily_logs
    FOR EACH ROW EXECUTE FUNCTION toolkit.audit_capture_change('log_id');

-- New grants only: reapplying the migration preserves customized permissions.
INSERT INTO role_permissions(role_key,module,permission,is_allowed)
SELECT r.role_key,m.module,p.permission,
    CASE WHEN m.module='dailylogs' THEN TRUE WHEN r.role_key IN ('admin','reviewer') THEN TRUE ELSE FALSE END
FROM app_roles r CROSS JOIN (VALUES ('dailylogs'),('teammonitoring'),('reviewlogs')) m(module)
CROSS JOIN (VALUES ('view'),('create'),('edit'),('delete'),('review')) p(permission)
WHERE (m.module='dailylogs' AND p.permission IN ('view','create','edit','delete'))
    OR (m.module='teammonitoring' AND p.permission='view')
    OR (m.module='reviewlogs' AND p.permission IN ('view','review'))
ON CONFLICT(role_key,module,permission) DO NOTHING;
COMMIT;
