/**
    * Filename: migration_012_user_profile_picture.sql
    * Purpose: Apply the user profile picture database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- =====================================================================
-- AnalystToolkit :: migration_012_user_profile_picture.sql
-- Run this if you already installed the original schema.sql.
-- Adds a profile picture path — the only field a user can self-edit
-- from the new topbar account menu.
-- =====================================================================

SET search_path TO toolkit, public;

ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture_path VARCHAR(255);
