/**
 * Filename: migration_030_leave_ranges.sql
 * Purpose: Group working-day leave entries into one versioned date-range application.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Preserve historical single-day applications and support atomic range approval.
 */
-- Executed with matching migration_030_leave_ranges.log by MigrationRunner.
SET search_path TO toolkit, public;
BEGIN;
CREATE TABLE IF NOT EXISTS leave_applications (
    request_id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE RESTRICT,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL CHECK(date_to>=date_from),
    version INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS leave_request_id BIGINT REFERENCES leave_applications(request_id) ON DELETE RESTRICT;
INSERT INTO leave_applications(request_id,user_id,date_from,date_to,version,created_at,updated_at)
SELECT log_id,user_id,activity_date,activity_date,version,created_at,updated_at FROM daily_logs
WHERE entry_kind='leave' AND leave_request_id IS NULL
ON CONFLICT(request_id) DO NOTHING;
UPDATE daily_logs SET leave_request_id=log_id WHERE entry_kind='leave' AND leave_request_id IS NULL;
SELECT setval('leave_applications_request_id_seq',GREATEST(COALESCE((SELECT MAX(request_id) FROM leave_applications),1),
    (SELECT last_value FROM leave_applications_request_id_seq)),TRUE);
CREATE UNIQUE INDEX IF NOT EXISTS idx_leave_request_day ON daily_logs(leave_request_id,activity_date) WHERE leave_request_id IS NOT NULL;
DROP TRIGGER IF EXISTS audit_row_change ON leave_applications;
CREATE TRIGGER audit_row_change AFTER INSERT OR UPDATE OR DELETE ON leave_applications FOR EACH ROW EXECUTE FUNCTION toolkit.audit_capture_change('request_id');
COMMIT;
