/**
    * Filename: migration_027_devatlas_brand.sql
    * Purpose: Apply the devatlas brand database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- Rebrand only the built-in mail sender. Preserve custom SMTP names and all operational records.
SET search_path TO toolkit, public;
BEGIN;
ALTER TABLE system_settings ALTER COLUMN smtp_from_name SET DEFAULT 'DevAtlas';
UPDATE system_settings SET smtp_from_name='DevAtlas'
WHERE regexp_replace(LOWER(BTRIM(smtp_from_name)),'[^a-z]','','g')='analysttoolkit';
COMMIT;
