/**
 * Filename: migration_005_dd_source_database.sql
 * Purpose: Apply the dd source database database upgrade.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

-- =====================================================================
-- AnalystToolkit :: migration_005_dd_source_database.sql
-- Run this if you already installed the original schema.sql.
-- Adds a "source_database" grouping column to dd_tables so the Data
-- Dictionary can display tables grouped by originating database
-- (DB 1 > Tables, DB 2 > Tables, ...) instead of one flat list.
-- =====================================================================

SET search_path TO toolkit, public;

ALTER TABLE dd_tables ADD COLUMN IF NOT EXISTS source_database VARCHAR(150) NOT NULL DEFAULT 'Manual Entry';

CREATE INDEX IF NOT EXISTS idx_dd_tables_source_database ON dd_tables(source_database);

-- The old UNIQUE(schema_name, table_name) constraint would collide if you
-- later import the same schema/table name from two different databases,
-- so widen it to include source_database.
DO $$
DECLARE cname text;
BEGIN
  SELECT con.conname INTO cname
  FROM pg_constraint con
  JOIN pg_class rel ON rel.oid = con.conrelid
  JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
  WHERE rel.relname = 'dd_tables' AND nsp.nspname = 'toolkit' AND con.contype = 'u';
  IF cname IS NOT NULL THEN
    EXECUTE format('ALTER TABLE dd_tables DROP CONSTRAINT %I', cname);
  END IF;
END $$;

ALTER TABLE dd_tables ADD CONSTRAINT dd_tables_source_schema_table_uniq UNIQUE (source_database, schema_name, table_name);
