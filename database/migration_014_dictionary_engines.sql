/**
    * Filename: migration_014_dictionary_engines.sql
    * Purpose: Apply the dictionary engines database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- Data Dictionary engine support and lossless metadata. Safe to rerun.
SET search_path TO toolkit, public;
BEGIN;
ALTER TABLE dd_tables ADD COLUMN IF NOT EXISTS source_engine VARCHAR(30);
ALTER TABLE dd_tables ADD COLUMN IF NOT EXISTS object_type VARCHAR(10) NOT NULL DEFAULT 'table';
ALTER TABLE dd_columns ALTER COLUMN default_value TYPE TEXT;
ALTER TABLE dd_columns ALTER COLUMN data_type TYPE TEXT;
ALTER TABLE dd_columns ALTER COLUMN fk_ref_table TYPE TEXT;
ALTER TABLE dd_columns ALTER COLUMN fk_ref_column TYPE TEXT;
ALTER TABLE dd_connections DROP CONSTRAINT IF EXISTS dd_connections_db_engine_check;
ALTER TABLE dd_connections ADD CONSTRAINT dd_connections_db_engine_check CHECK (db_engine IN ('postgresql','mysql','mariadb','sqlserver','oracle','sqlite','db2','firebird','odbc'));
ALTER TABLE dd_connections ALTER COLUMN password TYPE TEXT;
ALTER TABLE dd_connections ALTER COLUMN database_name TYPE TEXT;
ALTER TABLE dd_connections ADD COLUMN IF NOT EXISTS password_encrypted BOOLEAN NOT NULL DEFAULT FALSE;
CREATE INDEX IF NOT EXISTS idx_dd_columns_sensitive ON dd_columns(table_id) WHERE sensitive_flag;
COMMIT;
