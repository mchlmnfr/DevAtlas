/**
    * Filename: migration_007_dd_auto_increment.sql
    * Purpose: Apply the dd auto increment database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- =====================================================================
-- AnalystToolkit :: migration_007_dd_auto_increment.sql
-- Run this if you already installed the original schema.sql.
-- Adds an "Auto Increment" flag to dd_columns, needed for the new
-- per-database "ANNEX E – Data Dictionary" Excel export.
-- =====================================================================

SET search_path TO toolkit, public;

ALTER TABLE dd_columns ADD COLUMN IF NOT EXISTS is_auto_increment BOOLEAN NOT NULL DEFAULT FALSE;
