/**
    * Filename: migration_024_weekly_approval_holidays.sql
    * Purpose: Apply the weekly approval holidays database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- One review decision per employee/week and an explicit working-day calendar.
SET search_path TO toolkit, public;
BEGIN;
CREATE TABLE IF NOT EXISTS daily_log_week_reviews (
    week_review_id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE RESTRICT,
    week_start DATE NOT NULL CHECK(EXTRACT(ISODOW FROM week_start)=1),
    status VARCHAR(10) NOT NULL CHECK(status IN('Submitted','Reviewed','Returned')),
    review_comment TEXT NOT NULL DEFAULT '',
    reviewed_by INTEGER REFERENCES users(user_id) ON DELETE RESTRICT,
    reviewed_at TIMESTAMPTZ,
    version INTEGER NOT NULL DEFAULT 1,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(user_id,week_start)
);
CREATE TABLE IF NOT EXISTS daily_log_holidays (
    holiday_id BIGSERIAL PRIMARY KEY,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(200) NOT NULL CHECK(BTRIM(holiday_name)<>''),
    version INTEGER NOT NULL DEFAULT 1,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_daily_holiday_date ON daily_log_holidays(holiday_date);
DROP TRIGGER IF EXISTS audit_row_change ON daily_log_week_reviews;
CREATE TRIGGER audit_row_change AFTER INSERT OR UPDATE OR DELETE ON daily_log_week_reviews FOR EACH ROW EXECUTE FUNCTION toolkit.audit_capture_change('week_review_id');
DROP TRIGGER IF EXISTS audit_row_change ON daily_log_holidays;
CREATE TRIGGER audit_row_change AFTER INSERT OR UPDATE OR DELETE ON daily_log_holidays FOR EACH ROW EXECUTE FUNCTION toolkit.audit_capture_change('holiday_id');
COMMIT;
