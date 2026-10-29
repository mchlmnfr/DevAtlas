/**
    * Filename: migration_021_daily_log_reviewers.sql
    * Purpose: Apply the daily log reviewers database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- Explicit reviewer assignment. No reviewer is inferred from a role or position.
SET search_path TO toolkit, public;
BEGIN;
CREATE TABLE IF NOT EXISTS daily_log_reviewers (
    unit_id INTEGER PRIMARY KEY REFERENCES org_units(unit_id) ON DELETE CASCADE,
    reviewer_user_id INTEGER REFERENCES users(user_id) ON DELETE RESTRICT,
    assigned_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE daily_log_reviewers ALTER COLUMN reviewer_user_id DROP NOT NULL;
CREATE INDEX IF NOT EXISTS idx_daily_log_reviewers_user ON daily_log_reviewers(reviewer_user_id);
DROP TRIGGER IF EXISTS audit_row_change ON daily_log_reviewers;
CREATE TRIGGER audit_row_change AFTER INSERT OR UPDATE OR DELETE ON daily_log_reviewers
    FOR EACH ROW EXECUTE FUNCTION toolkit.audit_capture_change('unit_id');
COMMIT;
