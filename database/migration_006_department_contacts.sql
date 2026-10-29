/**
    * Filename: migration_006_department_contacts.sql
    * Purpose: Apply the department contacts database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- =====================================================================
-- AnalystToolkit :: migration_006_department_contacts.sql
-- Run this if you already installed the original schema.sql.
-- Replaces the Call Directory's "Personnel" (named individuals) with
-- department-level contact info: a department can list multiple emails
-- and multiple local/hotline numbers directly, with no person attached.
-- DESTRUCTIVE: this drops org_personnel entirely. Back up first if you
-- have real data in it:
--   pg_dump -U postgres -d analyst_toolkit -t toolkit.org_personnel > org_personnel_backup.sql
-- =====================================================================

SET search_path TO toolkit, public;

CREATE TABLE IF NOT EXISTS department_emails (
    email_id        SERIAL PRIMARY KEY,
    department_id   INTEGER NOT NULL REFERENCES org_departments(department_id) ON DELETE CASCADE,
    email           VARCHAR(200) NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS department_numbers (
    number_id       SERIAL PRIMARY KEY,
    department_id   INTEGER NOT NULL REFERENCES org_departments(department_id) ON DELETE CASCADE,
    phone_number    VARCHAR(50) NOT NULL,
    label           VARCHAR(50) NOT NULL DEFAULT 'Local',
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_department_emails_dept ON department_emails(department_id);
CREATE INDEX IF NOT EXISTS idx_department_numbers_dept ON department_numbers(department_id);

DROP TABLE IF EXISTS org_personnel CASCADE;

ALTER TABLE org_departments ALTER COLUMN department_name TYPE VARCHAR(200);
