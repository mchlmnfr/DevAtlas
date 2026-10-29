/**
 * Filename: migration_031_user_birthdays.sql
 * Purpose: Store optional personnel birthdays for recurring department calendar events.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Add birthdays without changing existing account details.
 */
-- Executed with matching migration_031_user_birthdays.log by MigrationRunner.
SET search_path TO toolkit, public;
BEGIN;
ALTER TABLE users ADD COLUMN IF NOT EXISTS birthday DATE;
COMMIT;
