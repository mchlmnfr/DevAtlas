/**
    * Filename: migration_008_units_positions_users.sql
    * Purpose: Apply the units positions users database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- =====================================================================
-- AnalystToolkit :: migration_008_units_positions_users.sql
-- Run this if you already installed the original schema.sql.
-- Adds:
--   - org_units (belongs to a Department)
--   - org_positions (belongs to a Unit)
--   - users (Employee ID + password login, full org placement)
-- Seeds one default admin account so there's a way to log in:
--   Employee ID: admin   Password: Admin@12345
--   CHANGE THIS PASSWORD IMMEDIATELY AFTER YOUR FIRST LOGIN.
-- IMPORTANT: after this migration, every page and API endpoint requires
-- a logged-in session (see lib/Auth.php). Log in at /login.php.
-- =====================================================================

SET search_path TO toolkit, public;

CREATE TABLE IF NOT EXISTS org_units (
    unit_id         SERIAL PRIMARY KEY,
    department_id   INTEGER NOT NULL REFERENCES org_departments(department_id) ON DELETE CASCADE,
    unit_name       VARCHAR(150) NOT NULL,
    description     TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (department_id, unit_name)
);

CREATE TABLE IF NOT EXISTS org_positions (
    position_id     SERIAL PRIMARY KEY,
    unit_id         INTEGER NOT NULL REFERENCES org_units(unit_id) ON DELETE CASCADE,
    position_title  VARCHAR(150) NOT NULL,
    description     TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (unit_id, position_title)
);

CREATE INDEX IF NOT EXISTS idx_org_units_department ON org_units(department_id);
CREATE INDEX IF NOT EXISTS idx_org_positions_unit ON org_positions(unit_id);

DROP TRIGGER IF EXISTS trg_org_units_updated ON org_units;
CREATE TRIGGER trg_org_units_updated BEFORE UPDATE ON org_units
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();
DROP TRIGGER IF EXISTS trg_org_positions_updated ON org_positions;
CREATE TRIGGER trg_org_positions_updated BEFORE UPDATE ON org_positions
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();

CREATE TABLE IF NOT EXISTS users (
    user_id         SERIAL PRIMARY KEY,
    employee_id     VARCHAR(50) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    last_name       VARCHAR(100) NOT NULL,
    first_name      VARCHAR(100) NOT NULL,
    middle_name     VARCHAR(100),
    group_id        INTEGER REFERENCES org_groups(group_id) ON DELETE RESTRICT,
    division_id     INTEGER REFERENCES org_divisions(division_id) ON DELETE RESTRICT,
    department_id   INTEGER REFERENCES org_departments(department_id) ON DELETE RESTRICT,
    unit_id         INTEGER REFERENCES org_units(unit_id) ON DELETE RESTRICT,
    position_id     INTEGER REFERENCES org_positions(position_id) ON DELETE RESTRICT,
    email           VARCHAR(200),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_users_employee_id ON users(employee_id);
CREATE INDEX IF NOT EXISTS idx_users_department ON users(department_id);
CREATE INDEX IF NOT EXISTS idx_users_unit ON users(unit_id);
CREATE INDEX IF NOT EXISTS idx_users_position ON users(position_id);

DROP TRIGGER IF EXISTS trg_users_updated ON users;
CREATE TRIGGER trg_users_updated BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();

INSERT INTO users (employee_id, password_hash, last_name, first_name, is_active)
VALUES ('admin', '$2b$12$b1cos8GbGlccKJEY/7WCQeGGvO8vPn6OvU3RfFCj4z2rjD.TZIoOm', 'Administrator', 'System', TRUE)
ON CONFLICT (employee_id) DO NOTHING;
