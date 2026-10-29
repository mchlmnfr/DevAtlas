/**
    * Filename: migration_003_dd_connections.sql
    * Purpose: Apply the dd connections database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- =====================================================================
-- AnalystToolkit :: migration_003_dd_connections.sql
-- Run this if you already installed the original schema.sql.
-- Adds saved connection profiles for the Data Dictionary's
-- "Import from DB" feature (supports PostgreSQL and MySQL/MariaDB targets).
-- =====================================================================

SET search_path TO toolkit, public;

CREATE TABLE IF NOT EXISTS dd_connections (
    connection_id   SERIAL PRIMARY KEY,
    label           VARCHAR(150) NOT NULL,
    db_engine       VARCHAR(20) NOT NULL CHECK (db_engine IN ('postgresql','mysql')),
    host            VARCHAR(255) NOT NULL,
    port            INTEGER NOT NULL,
    username        VARCHAR(150) NOT NULL,
    password        VARCHAR(255),
    database_name   VARCHAR(150) NOT NULL,
    default_schema  VARCHAR(150),
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

DROP TRIGGER IF EXISTS trg_dd_connections_updated ON dd_connections;
CREATE TRIGGER trg_dd_connections_updated BEFORE UPDATE ON dd_connections
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();
