/**
    * Filename: migration_028_action_reminders.sql
    * Purpose: Apply the action reminders database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- Action dashboard and daily-log reminders. Existing email settings and data are preserved.
SET search_path TO toolkit, public;
BEGIN;
ALTER TABLE notification_settings ADD COLUMN IF NOT EXISTS daily_log_enabled BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE notification_settings ADD COLUMN IF NOT EXISTS approval_enabled BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE notification_settings ADD COLUMN IF NOT EXISTS daily_log_hour INTEGER NOT NULL DEFAULT 17 CHECK(daily_log_hour BETWEEN 0 AND 23);
ALTER TABLE notification_settings ADD COLUMN IF NOT EXISTS daily_log_lookback_days INTEGER NOT NULL DEFAULT 14 CHECK(daily_log_lookback_days BETWEEN 1 AND 90);
ALTER TABLE notification_settings ADD COLUMN IF NOT EXISTS approval_delay_days INTEGER NOT NULL DEFAULT 1 CHECK(approval_delay_days BETWEEN 0 AND 30);
ALTER TABLE notification_settings ADD COLUMN IF NOT EXISTS daily_log_email_enabled BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS context JSONB NOT NULL DEFAULT '{}';
CREATE INDEX IF NOT EXISTS idx_daily_logs_pending_actions ON daily_logs(user_id,activity_date,status) WHERE status IN('Submitted','Returned','Draft');
COMMIT;
