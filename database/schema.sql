/**
 * Filename: schema.sql
 * Purpose: Define the DevAtlas database schema and defaults.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

-- =====================================================================
-- DevAtlas :: PostgreSQL Schema
-- Target: PostgreSQL 13+ running locally alongside WAMP
-- Run as:  psql -U postgres -f schema.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- 0. DATABASE (run manually if you want a dedicated DB; otherwise
--    point config.php at an existing database and just run the rest
--    of this script inside it)
-- ---------------------------------------------------------------------
-- CREATE DATABASE analyst_toolkit WITH ENCODING 'UTF8';
-- \c analyst_toolkit

CREATE SCHEMA IF NOT EXISTS toolkit;
SET search_path TO toolkit, public;

-- Generic updated_at trigger function, reused by every table below
CREATE OR REPLACE FUNCTION toolkit.set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- =====================================================================
-- 1. DATA DICTIONARY
--    Documents tables/columns. Can either mirror an introspected
--    live schema (any DB) or hold purely manual documentation.
-- =====================================================================

CREATE TABLE IF NOT EXISTS dd_tables (
    table_id        SERIAL PRIMARY KEY,
    source_database VARCHAR(150) NOT NULL DEFAULT 'Manual Entry', -- groups tables by originating DB in the UI
    source_engine   VARCHAR(30),
    object_type     VARCHAR(10) NOT NULL DEFAULT 'table',
    schema_name     VARCHAR(128) NOT NULL DEFAULT 'public',
    table_name      VARCHAR(128) NOT NULL,
    display_name    VARCHAR(200),
    description     TEXT,
    business_owner  VARCHAR(150),
    source_system   VARCHAR(150),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (source_database, schema_name, table_name)
);

CREATE INDEX IF NOT EXISTS idx_dd_tables_source_database ON dd_tables(source_database);

CREATE TABLE IF NOT EXISTS dd_columns (
    column_id           SERIAL PRIMARY KEY,
    table_id             INTEGER NOT NULL REFERENCES dd_tables(table_id) ON DELETE CASCADE,
    column_name          VARCHAR(128) NOT NULL,
    ordinal_position      INTEGER,
    data_type            TEXT NOT NULL,
    char_length           INTEGER,
    numeric_precision     INTEGER,
    numeric_scale         INTEGER,
    is_nullable           BOOLEAN NOT NULL DEFAULT TRUE,
    is_primary_key         BOOLEAN NOT NULL DEFAULT FALSE,
    is_foreign_key         BOOLEAN NOT NULL DEFAULT FALSE,
    is_auto_increment      BOOLEAN NOT NULL DEFAULT FALSE,
    fk_ref_table          TEXT,
    fk_ref_column         TEXT,
    default_value         TEXT,
    business_rule          TEXT,
    description           TEXT,
    sensitive_flag         BOOLEAN NOT NULL DEFAULT FALSE,
    created_at            TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (table_id, column_name)
);

CREATE INDEX IF NOT EXISTS idx_dd_columns_table ON dd_columns(table_id);

DROP TRIGGER IF EXISTS trg_dd_tables_updated ON dd_tables;
CREATE TRIGGER trg_dd_tables_updated BEFORE UPDATE ON dd_tables
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();

DROP TRIGGER IF EXISTS trg_dd_columns_updated ON dd_columns;
CREATE TRIGGER trg_dd_columns_updated BEFORE UPDATE ON dd_columns
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();

-- Saved connection profiles for the Data Dictionary's "Import from DB" feature.
-- Lets an analyst introspect ANY reachable database (Postgres or MySQL/MariaDB),
-- not just the app's own database, without retyping credentials each time.
-- New credentials use authenticated encryption with APP_ENCRYPTION_KEY.
-- Legacy unencrypted values are upgraded when used by the application.
CREATE TABLE IF NOT EXISTS dd_connections (
    connection_id   SERIAL PRIMARY KEY,
    label           VARCHAR(150) NOT NULL,
    db_engine       VARCHAR(20) NOT NULL CHECK (db_engine IN ('postgresql','mysql','mariadb','sqlserver','oracle','sqlite','db2','firebird','odbc')),
    host            VARCHAR(255) NOT NULL,
    port            INTEGER NOT NULL,
    username        VARCHAR(150) NOT NULL,
    password        TEXT,
    password_encrypted BOOLEAN NOT NULL DEFAULT FALSE,
    database_name   TEXT NOT NULL,
    default_schema  VARCHAR(150),
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

DROP TRIGGER IF EXISTS trg_dd_connections_updated ON dd_connections;
CREATE TRIGGER trg_dd_connections_updated BEFORE UPDATE ON dd_connections
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();


-- =====================================================================
-- 2. DIAGRAM GENERATOR (yEd GraphML export)
--    Stores the logical diagram model; the .graphml file is generated
--    on demand from these rows (never stored as a blob so it can
--    always be regenerated / edited).
-- =====================================================================

CREATE TABLE IF NOT EXISTS diagrams (
    diagram_id      SERIAL PRIMARY KEY,
    diagram_type    VARCHAR(10) NOT NULL CHECK (diagram_type IN ('ERD','DFD','SFD','PFD')),
    diagram_name    VARCHAR(200) NOT NULL,
    description     TEXT,
    generation_meta JSONB NOT NULL DEFAULT '{}',
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Nodes are generic across all 4 diagram types; node_subtype carries the
-- diagram-specific meaning (see comments) and drives the yEd shape used.
--   ERD: entity | attribute
--   DFD: external_entity | process | data_store
--   SFD: system | component | database | interface
--   PFD: start | step | decision | end
CREATE TABLE IF NOT EXISTS diagram_nodes (
    node_id         SERIAL PRIMARY KEY,
    diagram_id      INTEGER NOT NULL REFERENCES diagrams(diagram_id) ON DELETE CASCADE,
    node_key        VARCHAR(50) NOT NULL,       -- stable key referenced by edges, unique per diagram
    label           VARCHAR(255) NOT NULL,
    node_subtype    VARCHAR(30) NOT NULL,
    detail          TEXT,                        -- e.g. attribute type / PK flag / process notes
    pos_x           NUMERIC DEFAULT 0,
    pos_y           NUMERIC DEFAULT 0,
    width           NUMERIC DEFAULT 140,
    height          NUMERIC DEFAULT 40,
    fill_color      VARCHAR(10) DEFAULT '#FFFFFF',
    parent_node_key VARCHAR(50),                 -- for ERD attributes belonging to an entity
    erd_meta        JSONB NOT NULL DEFAULT '{}',
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (diagram_id, node_key)
);

CREATE TABLE IF NOT EXISTS diagram_edges (
    edge_id         SERIAL PRIMARY KEY,
    diagram_id      INTEGER NOT NULL REFERENCES diagrams(diagram_id) ON DELETE CASCADE,
    source_node_key VARCHAR(50) NOT NULL,
    target_node_key VARCHAR(50) NOT NULL,
    label           VARCHAR(255),
    edge_style      VARCHAR(30) DEFAULT 'line',  -- line | dashed | flow (arrowed data flow)
    cardinality     VARCHAR(20),                  -- ERD only: 1:1, 1:N, N:M etc.
    erd_meta        JSONB NOT NULL DEFAULT '{}',
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_diagram_nodes_diagram ON diagram_nodes(diagram_id);
CREATE INDEX IF NOT EXISTS idx_diagram_edges_diagram ON diagram_edges(diagram_id);
CREATE INDEX IF NOT EXISTS idx_diagrams_erd_updated ON diagrams(updated_at DESC) WHERE diagram_type='ERD';

DROP TRIGGER IF EXISTS trg_diagrams_updated ON diagrams;
CREATE TRIGGER trg_diagrams_updated BEFORE UPDATE ON diagrams
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();


-- =====================================================================
-- 3. CALL DIRECTORY :: Group -> Division -> Department -> Personnel
-- =====================================================================

CREATE TABLE IF NOT EXISTS org_groups (
    group_id      SERIAL PRIMARY KEY,
    group_name    VARCHAR(150) NOT NULL UNIQUE,
    description   TEXT,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS org_divisions (
    division_id   SERIAL PRIMARY KEY,
    group_id      INTEGER NOT NULL REFERENCES org_groups(group_id) ON DELETE CASCADE,
    division_name VARCHAR(150) NOT NULL,
    description   TEXT,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (group_id, division_name)
);

CREATE TABLE IF NOT EXISTS org_departments (
    department_id   SERIAL PRIMARY KEY,
    division_id     INTEGER NOT NULL REFERENCES org_divisions(division_id) ON DELETE CASCADE,
    department_name VARCHAR(200) NOT NULL,
    description     TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (division_id, department_name)
);

-- Departments are contacted directly (a shared inbox / hotline / local
-- extension) rather than through a named individual, so contact info is
-- multi-valued and lives on the department itself.
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
    label           VARCHAR(50) NOT NULL DEFAULT 'Local', -- Local, Mobile, Hotline, etc.
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_org_divisions_group ON org_divisions(group_id);
CREATE INDEX IF NOT EXISTS idx_org_departments_division ON org_departments(division_id);
CREATE INDEX IF NOT EXISTS idx_department_emails_dept ON department_emails(department_id);
CREATE INDEX IF NOT EXISTS idx_department_numbers_dept ON department_numbers(department_id);

DROP TRIGGER IF EXISTS trg_org_groups_updated ON org_groups;
CREATE TRIGGER trg_org_groups_updated BEFORE UPDATE ON org_groups
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();
DROP TRIGGER IF EXISTS trg_org_divisions_updated ON org_divisions;
CREATE TRIGGER trg_org_divisions_updated BEFORE UPDATE ON org_divisions
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();
DROP TRIGGER IF EXISTS trg_org_departments_updated ON org_departments;
CREATE TRIGGER trg_org_departments_updated BEFORE UPDATE ON org_departments
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();

-- ---------------------------------------------------------------------
-- 3b. Deeper hierarchy for User Management:
--     Group -> Division -> Department -> Unit -> Position
--     (Unit/Position sit below Department; Call Directory's contact
--      cards above are unaffected and still stop at Department.)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS org_units (
    unit_id         SERIAL PRIMARY KEY,
    department_id   INTEGER NOT NULL REFERENCES org_departments(department_id) ON DELETE CASCADE,
    unit_name       VARCHAR(150) NOT NULL,
    description     TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (department_id, unit_name)
);

-- Each Unit maintains its own list of Positions.
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

-- ---------------------------------------------------------------------
-- 3c. Users :: authentication + full organizational placement.
--     FKs use ON DELETE RESTRICT so an org node with active users
--     attached can't be silently deleted out from under them.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS users (
    user_id                 SERIAL PRIMARY KEY,
    employee_id             VARCHAR(50) NOT NULL UNIQUE,   -- login identifier
    password_hash           VARCHAR(255) NOT NULL,
    last_name               VARCHAR(100) NOT NULL,
    first_name              VARCHAR(100) NOT NULL,
    middle_name             VARCHAR(100),
    group_id                INTEGER REFERENCES org_groups(group_id) ON DELETE RESTRICT,
    division_id             INTEGER REFERENCES org_divisions(division_id) ON DELETE RESTRICT,
    department_id           INTEGER REFERENCES org_departments(department_id) ON DELETE RESTRICT,
    unit_id                 INTEGER REFERENCES org_units(unit_id) ON DELETE RESTRICT,
    position_id             INTEGER REFERENCES org_positions(position_id) ON DELETE RESTRICT,
    email                   VARCHAR(200),
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    must_change_password    BOOLEAN NOT NULL DEFAULT TRUE,   -- forces a password change at next login
    password_changed_at     TIMESTAMP,                        -- null = never changed by the user yet
    failed_login_attempts   INTEGER NOT NULL DEFAULT 0,
    auth_version            INTEGER NOT NULL DEFAULT 1,
    is_locked               BOOLEAN NOT NULL DEFAULT FALSE,   -- auto-locked after too many failed attempts
    locked_at               TIMESTAMP,
    profile_picture_path    VARCHAR(255),   -- relative path under uploads/avatars/, the only self-editable field
    created_at              TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_users_employee_id ON users(employee_id);
CREATE INDEX IF NOT EXISTS idx_users_department ON users(department_id);
CREATE INDEX IF NOT EXISTS idx_users_unit ON users(unit_id);
CREATE INDEX IF NOT EXISTS idx_users_position ON users(position_id);

DROP TRIGGER IF EXISTS trg_users_updated ON users;
CREATE TRIGGER trg_users_updated BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();

-- ---------------------------------------------------------------------
-- 3d. System Parameters :: single-row settings (password policy + SMTP)
--     managed from the "Parameters" page.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS system_settings (
    id                      SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1), -- enforce a single row
    default_password        VARCHAR(255) NOT NULL DEFAULT 'Welcome@12345',
    password_expiry_days    INTEGER NOT NULL DEFAULT 90,
    max_login_attempts      INTEGER NOT NULL DEFAULT 5,
    smtp_host               VARCHAR(255),
    smtp_port               INTEGER NOT NULL DEFAULT 587,
    smtp_username           VARCHAR(255),
    smtp_password            VARCHAR(255),
    smtp_encryption          VARCHAR(10) NOT NULL DEFAULT 'tls', -- none | tls | ssl
    smtp_from_email          VARCHAR(255),
    smtp_from_name           VARCHAR(150) NOT NULL DEFAULT 'DevAtlas',
    auth_base_url            VARCHAR(500),
    updated_at               TIMESTAMP NOT NULL DEFAULT NOW()
);

DROP TRIGGER IF EXISTS trg_system_settings_updated ON system_settings;
CREATE TRIGGER trg_system_settings_updated BEFORE UPDATE ON system_settings
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();

INSERT INTO system_settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING;

-- Seed a default administrator so there's a way to log in on a fresh
-- install. Employee ID: admin / Password: Admin@12345
-- CHANGE THIS PASSWORD IMMEDIATELY AFTER YOUR FIRST LOGIN.
INSERT INTO users (employee_id, password_hash, last_name, first_name, is_active)
VALUES ('admin', '$2b$12$b1cos8GbGlccKJEY/7WCQeGGvO8vPn6OvU3RfFCj4z2rjD.TZIoOm', 'Administrator', 'System', TRUE)
ON CONFLICT (employee_id) DO NOTHING;


--    Simple by design: title, url, description, tags — shown as cards.
-- =====================================================================

CREATE TABLE IF NOT EXISTS bookmarks (
    bookmark_id    SERIAL PRIMARY KEY,
    title          VARCHAR(200) NOT NULL,
    url             VARCHAR(500) NOT NULL,
    description    TEXT,
    tags           VARCHAR(255),          -- comma separated, simple filter/search
    created_at     TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMP NOT NULL DEFAULT NOW()
);

DROP TRIGGER IF EXISTS trg_bookmarks_updated ON bookmarks;
CREATE TRIGGER trg_bookmarks_updated BEFORE UPDATE ON bookmarks
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();


-- =====================================================================
-- 5. SERVER MONITORING & SYSTEM INVENTORY
-- =====================================================================

-- A "server group" is a logical cluster of servers that together host one
-- environment ladder for a set of applications, e.g.:
--   Group "Zior Cluster": Zior(Prod), Berith(SIT), Ava(UAT/SIT), Calcol(Dev)
--   Systems using that whole group: BMIPORTAL SSO, HROCS
CREATE TABLE IF NOT EXISTS server_groups (
    server_group_id  SERIAL PRIMARY KEY,
    group_name        VARCHAR(150) NOT NULL UNIQUE,
    description        TEXT,
    created_at         TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS servers (
    server_id        SERIAL PRIMARY KEY,
    server_group_id  INTEGER REFERENCES server_groups(server_group_id) ON DELETE SET NULL,
    environment      VARCHAR(30) NOT NULL,          -- Dev, Prod, SIT, UAT, SAT, SIT & UAT, UAT & SAT, SIT, UAT & SAT
    hosting_type     VARCHAR(20),                    -- AWS | On-Premise
    server_type      VARCHAR(20),                    -- App Server | DB Server
    server_name      VARCHAR(150) NOT NULL,
    ip_address       VARCHAR(45) NOT NULL,          -- supports IPv4/IPv6
    server_url       VARCHAR(500),
    os_platform      VARCHAR(100),
    cpu_cores        INTEGER,
    memory_gb        NUMERIC(10,2),
    storage_gb       NUMERIC(12,2),
    notes            TEXT,
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (server_name, environment)
);

-- Systems/applications tied to an individual server (e.g. what's running on that one box)
CREATE TABLE IF NOT EXISTS hosted_systems (
    hosted_system_id     SERIAL PRIMARY KEY,
    server_id            INTEGER NOT NULL REFERENCES servers(server_id) ON DELETE CASCADE,
    system_name          VARCHAR(150) NOT NULL,
    version              VARCHAR(50),
    port                 VARCHAR(20),
    current_environment  VARCHAR(150),  -- multi-select, stored comma-separated (Dev, SIT, UAT, SAT, Prod, DR, Non-Prod(DR))
    repository_url       VARCHAR(500),  -- GitHub (or other VCS) repo link for this app
    description          TEXT,
    created_at           TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Systems/applications tied to a whole server GROUP (e.g. BMIPORTAL SSO runs
-- across the group's Prod/SIT/UAT/Dev servers as one logical deployment)
CREATE TABLE IF NOT EXISTS group_systems (
    group_system_id      SERIAL PRIMARY KEY,
    server_group_id      INTEGER NOT NULL REFERENCES server_groups(server_group_id) ON DELETE CASCADE,
    system_name           VARCHAR(150) NOT NULL,
    current_environment  VARCHAR(150),  -- multi-select, stored comma-separated
    repository_url        VARCHAR(500),
    description            TEXT,
    created_at              TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Access protocols supported by a server (RDP, SSH, FTP, WinRM, ...), with
-- enough detail to build a one-click connection string / .rdp download.
CREATE TABLE IF NOT EXISTS server_access_protocols (
    protocol_id     SERIAL PRIMARY KEY,
    server_id       INTEGER NOT NULL REFERENCES servers(server_id) ON DELETE CASCADE,
    protocol        VARCHAR(20) NOT NULL,   -- RDP | SSH | FTP | WinRM | Other
    port            VARCHAR(10),
    notes           TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ---------------------------------------------------------------------
-- Server credentials vault. Passwords are stored ENCRYPTED (see
-- lib/Crypto.php, AES-256-CBC using config.php's APP_ENCRYPTION_KEY) —
-- never plain text. Visibility is explicit: only the creator, or a user
-- the credential has been shared with, can decrypt/reveal the password.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS server_credentials (
    credential_id       SERIAL PRIMARY KEY,
    server_id            INTEGER NOT NULL REFERENCES servers(server_id) ON DELETE CASCADE,
    label                VARCHAR(150) NOT NULL,          -- e.g. "Local Administrator", "svc_backup"
    username             VARCHAR(150) NOT NULL,
    password_encrypted   TEXT NOT NULL,
    created_by           INTEGER REFERENCES users(user_id) ON DELETE SET NULL, -- audit: who added it
    created_at            TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Explicit grants: who else besides the creator may reveal this credential's password.
CREATE TABLE IF NOT EXISTS server_credential_shares (
    share_id              SERIAL PRIMARY KEY,
    credential_id          INTEGER NOT NULL REFERENCES server_credentials(credential_id) ON DELETE CASCADE,
    shared_with_user_id    INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    shared_by_user_id      INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    shared_at              TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (credential_id, shared_with_user_id)
);

-- ---------------------------------------------------------------------
-- Expiry tracking: SSL certificates, software/server licenses, and other
-- time-sensitive services tied to a server and/or a hosted system.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS expiry_items (
    expiry_id            SERIAL PRIMARY KEY,
    item_type             VARCHAR(30) NOT NULL CHECK (item_type IN ('SSL Certificate','License','Other Service')),
    item_name             VARCHAR(200) NOT NULL,
    server_id              INTEGER REFERENCES servers(server_id) ON DELETE SET NULL,
    hosted_system_id       INTEGER REFERENCES hosted_systems(hosted_system_id) ON DELETE SET NULL,
    issued_date            DATE,
    expiry_date            DATE NOT NULL,
    alert_days_before       INTEGER NOT NULL DEFAULT 30,  -- flagged as "expiring soon" within this many days
    notes                   TEXT,
    created_at               TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_servers_environment ON servers(environment);
CREATE INDEX IF NOT EXISTS idx_servers_group ON servers(server_group_id);
CREATE INDEX IF NOT EXISTS idx_hosted_systems_server ON hosted_systems(server_id);
CREATE INDEX IF NOT EXISTS idx_hosted_systems_name ON hosted_systems(system_name);
CREATE INDEX IF NOT EXISTS idx_group_systems_group ON group_systems(server_group_id);
CREATE INDEX IF NOT EXISTS idx_group_systems_name ON group_systems(system_name);
CREATE INDEX IF NOT EXISTS idx_server_access_protocols_server ON server_access_protocols(server_id);
CREATE INDEX IF NOT EXISTS idx_server_credentials_server ON server_credentials(server_id);
CREATE INDEX IF NOT EXISTS idx_server_credential_shares_cred ON server_credential_shares(credential_id);
CREATE INDEX IF NOT EXISTS idx_server_credential_shares_user ON server_credential_shares(shared_with_user_id);
CREATE INDEX IF NOT EXISTS idx_expiry_items_expiry_date ON expiry_items(expiry_date);
CREATE INDEX IF NOT EXISTS idx_expiry_items_server ON expiry_items(server_id);

DROP TRIGGER IF EXISTS trg_server_groups_updated ON server_groups;
CREATE TRIGGER trg_server_groups_updated BEFORE UPDATE ON server_groups
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();
DROP TRIGGER IF EXISTS trg_servers_updated ON servers;
CREATE TRIGGER trg_servers_updated BEFORE UPDATE ON servers
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();
DROP TRIGGER IF EXISTS trg_hosted_systems_updated ON hosted_systems;
CREATE TRIGGER trg_hosted_systems_updated BEFORE UPDATE ON hosted_systems
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();
DROP TRIGGER IF EXISTS trg_group_systems_updated ON group_systems;
CREATE TRIGGER trg_group_systems_updated BEFORE UPDATE ON group_systems
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();
DROP TRIGGER IF EXISTS trg_server_credentials_updated ON server_credentials;
CREATE TRIGGER trg_server_credentials_updated BEFORE UPDATE ON server_credentials
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();
DROP TRIGGER IF EXISTS trg_expiry_items_updated ON expiry_items;
CREATE TRIGGER trg_expiry_items_updated BEFORE UPDATE ON expiry_items
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();


-- =====================================================================
-- REQUIREMENTS TRACEABILITY
-- =====================================================================

CREATE TABLE IF NOT EXISTS requirements (
    requirement_id SERIAL PRIMARY KEY,
    requirement_code VARCHAR(40) NOT NULL UNIQUE,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'Draft'
        CHECK (status IN ('Draft', 'Approved', 'Implemented', 'Retired')),
    priority VARCHAR(20) NOT NULL DEFAULT 'Medium'
        CHECK (priority IN ('Low', 'Medium', 'High', 'Critical')),
    owner_user_id INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS requirement_links (
    link_id SERIAL PRIMARY KEY,
    requirement_id INTEGER NOT NULL REFERENCES requirements(requirement_id) ON DELETE CASCADE,
    diagram_id INTEGER REFERENCES diagrams(diagram_id) ON DELETE CASCADE,
    dd_table_id INTEGER REFERENCES dd_tables(table_id) ON DELETE CASCADE,
    dd_column_id INTEGER REFERENCES dd_columns(column_id) ON DELETE CASCADE,
    hosted_system_id INTEGER REFERENCES hosted_systems(hosted_system_id) ON DELETE CASCADE,
    group_system_id INTEGER REFERENCES group_systems(group_system_id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT requirement_links_one_asset CHECK (
        num_nonnulls(diagram_id, dd_table_id, dd_column_id, hosted_system_id, group_system_id) = 1
    )
);

CREATE INDEX IF NOT EXISTS idx_requirements_status ON requirements(status);
CREATE INDEX IF NOT EXISTS idx_requirements_owner ON requirements(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_requirement_links_requirement ON requirement_links(requirement_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_requirement_links_diagram ON requirement_links(requirement_id, diagram_id) WHERE diagram_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_requirement_links_table ON requirement_links(requirement_id, dd_table_id) WHERE dd_table_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_requirement_links_column ON requirement_links(requirement_id, dd_column_id) WHERE dd_column_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_requirement_links_hosted ON requirement_links(requirement_id, hosted_system_id) WHERE hosted_system_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_requirement_links_group ON requirement_links(requirement_id, group_system_id) WHERE group_system_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_requirement_links_diagram ON requirement_links(diagram_id) WHERE diagram_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_requirement_links_table ON requirement_links(dd_table_id) WHERE dd_table_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_requirement_links_column ON requirement_links(dd_column_id) WHERE dd_column_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_requirement_links_hosted ON requirement_links(hosted_system_id) WHERE hosted_system_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_requirement_links_group ON requirement_links(group_system_id) WHERE group_system_id IS NOT NULL;

DROP TRIGGER IF EXISTS trg_requirements_updated ON requirements;
CREATE TRIGGER trg_requirements_updated BEFORE UPDATE ON requirements
    FOR EACH ROW EXECUTE FUNCTION toolkit.set_updated_at();

-- =====================================================================
-- SEED DATA (minimal, safe to delete)
-- =====================================================================

INSERT INTO org_groups (group_name, description) VALUES
    ('Information Technology', 'IT Group')
ON CONFLICT (group_name) DO NOTHING;

-- =====================================================================
-- EMAIL AUTHENTICATION
-- =====================================================================

CREATE TABLE IF NOT EXISTS auth_email_challenges (
    challenge_id CHAR(32) PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    purpose VARCHAR(10) NOT NULL CHECK (purpose IN ('reset', 'signin')),
    secret_hash CHAR(64) NOT NULL,
    session_hash CHAR(64),
    auth_version INTEGER NOT NULL,
    recipient VARCHAR(200),
    preview_secret TEXT,
    attempts INTEGER NOT NULL DEFAULT 0,
    delivery_status VARCHAR(15) NOT NULL DEFAULT 'pending'
        CHECK (delivery_status IN ('pending', 'sent', 'unavailable', 'failed')),
    delivery_note VARCHAR(250),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_auth_email_user ON auth_email_challenges(user_id, purpose);
CREATE INDEX IF NOT EXISTS idx_auth_email_expiry ON auth_email_challenges(expires_at);

-- Only hashed identifiers/IPs/session nonces are stored in the rate-limit table.
CREATE TABLE IF NOT EXISTS auth_email_limits (
    key_hash CHAR(64) PRIMARY KEY,
    window_started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_attempt_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    attempts INTEGER NOT NULL DEFAULT 0
);

-- =====================================================================
-- Audit history (migration 017)
-- Application audit trail. No credential, source-code or free-text record snapshots.
SET search_path TO toolkit, public;
BEGIN;
CREATE TABLE IF NOT EXISTS audit_events (
    event_id BIGSERIAL PRIMARY KEY,
    request_id UUID NOT NULL UNIQUE,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    finalized_at TIMESTAMPTZ,
    source VARCHAR(12) NOT NULL CHECK (source IN ('server','browser')),
    actor_id INTEGER,
    actor_employee_id VARCHAR(50),
    actor_name VARCHAR(200),
    session_ref VARCHAR(24),
    module VARCHAR(40) NOT NULL,
    action VARCHAR(100) NOT NULL,
    outcome VARCHAR(16) NOT NULL CHECK (outcome IN ('in_progress','success','failed','denied','partial','reported')),
    http_method VARCHAR(12),
    path VARCHAR(240) NOT NULL,
    http_status SMALLINT,
    ip_address VARCHAR(45),
    duration_ms INTEGER,
    details JSONB NOT NULL DEFAULT '{}'
);
CREATE TABLE IF NOT EXISTS audit_changes (
    change_id BIGSERIAL PRIMARY KEY,
    request_id UUID NOT NULL REFERENCES audit_events(request_id),
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    table_name VARCHAR(80) NOT NULL,
    record_id VARCHAR(120) NOT NULL,
    operation VARCHAR(8) NOT NULL CHECK (operation IN ('INSERT','UPDATE','DELETE')),
    changed_fields TEXT[] NOT NULL,
    before_values JSONB NOT NULL DEFAULT '{}',
    after_values JSONB NOT NULL DEFAULT '{}'
);
CREATE INDEX IF NOT EXISTS idx_audit_events_time ON audit_events(occurred_at DESC,event_id DESC);
CREATE INDEX IF NOT EXISTS idx_audit_events_actor ON audit_events(actor_id,event_id DESC);
CREATE INDEX IF NOT EXISTS idx_audit_events_module ON audit_events(module,event_id DESC);
CREATE INDEX IF NOT EXISTS idx_audit_changes_request ON audit_changes(request_id,change_id);
CREATE INDEX IF NOT EXISTS idx_audit_changes_record ON audit_changes(table_name,record_id);

CREATE OR REPLACE FUNCTION toolkit.audit_protect_history() RETURNS TRIGGER
LANGUAGE plpgsql SET search_path=pg_catalog AS $$
BEGIN
    IF TG_TABLE_NAME='audit_events' AND TG_OP='UPDATE' AND OLD.finalized_at IS NULL
       AND NEW.request_id=OLD.request_id AND NEW.source='server' AND OLD.source='server'
       AND NEW.finalized_at IS NOT NULL THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'Audit history is append-only';
END;
$$;
DROP TRIGGER IF EXISTS audit_events_immutable ON audit_events;
CREATE TRIGGER audit_events_immutable BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION toolkit.audit_protect_history();
DROP TRIGGER IF EXISTS audit_changes_immutable ON audit_changes;
CREATE TRIGGER audit_changes_immutable BEFORE UPDATE OR DELETE ON audit_changes FOR EACH ROW EXECUTE FUNCTION toolkit.audit_protect_history();
DROP TRIGGER IF EXISTS audit_events_no_truncate ON audit_events;
CREATE TRIGGER audit_events_no_truncate BEFORE TRUNCATE ON audit_events FOR EACH STATEMENT EXECUTE FUNCTION toolkit.audit_protect_history();
DROP TRIGGER IF EXISTS audit_changes_no_truncate ON audit_changes;
CREATE TRIGGER audit_changes_no_truncate BEFORE TRUNCATE ON audit_changes FOR EACH STATEMENT EXECUTE FUNCTION toolkit.audit_protect_history();

CREATE OR REPLACE FUNCTION toolkit.audit_capture_change() RETURNS TRIGGER
LANGUAGE plpgsql SET search_path=pg_catalog AS $$
DECLARE
    ctx JSONB;
    prior_row JSONB := '{}';
    next_row JSONB := '{}';
    changed TEXT[];
    safe_keys TEXT[] := ARRAY['is_active','is_locked','must_change_password','is_primary_key','is_foreign_key',
        'is_nullable','is_auto_increment','sensitive_flag','password_expiry_days','max_login_attempts',
        'status','priority','db_engine','source_engine','object_type','diagram_type'];
    before_safe JSONB := '{}';
    after_safe JSONB := '{}';
    key_name TEXT;
    value_item JSONB;
    record_key TEXT;
BEGIN
    IF NULLIF(current_setting('analyst.audit_context',true),'') IS NULL THEN
        RETURN NULL; -- CLI maintenance has no authenticated HTTP actor.
    END IF;
    ctx := current_setting('analyst.audit_context',true)::JSONB;
    IF TG_OP<>'INSERT' THEN prior_row:=to_jsonb(OLD); END IF;
    IF TG_OP<>'DELETE' THEN next_row:=to_jsonb(NEW); END IF;
    SELECT array_agg(key ORDER BY key) INTO changed
    FROM (SELECT jsonb_object_keys(prior_row||next_row) AS key) AS keys
    WHERE key NOT IN ('created_at','updated_at') AND prior_row->key IS DISTINCT FROM next_row->key;
    IF changed IS NULL THEN RETURN NULL; END IF;
    FOREACH key_name IN ARRAY safe_keys LOOP
        IF key_name=ANY(changed) THEN
            value_item:=prior_row->key_name;
            IF jsonb_typeof(value_item) IN ('boolean','number','null')
               OR (jsonb_typeof(value_item)='string' AND length(value_item#>>'{}')<=80) THEN
                before_safe:=before_safe||jsonb_build_object(key_name,value_item);
            END IF;
            value_item:=next_row->key_name;
            IF jsonb_typeof(value_item) IN ('boolean','number','null')
               OR (jsonb_typeof(value_item)='string' AND length(value_item#>>'{}')<=80) THEN
                after_safe:=after_safe||jsonb_build_object(key_name,value_item);
            END IF;
        END IF;
    END LOOP;
    record_key:=COALESCE(next_row->>TG_ARGV[0],prior_row->>TG_ARGV[0],'unknown');
    -- The stub and row change participate in the business transaction.
    EXECUTE format('INSERT INTO %I.audit_events(request_id,occurred_at,source,actor_id,actor_employee_id,actor_name,session_ref,module,action,outcome,http_method,path,ip_address,details)
      VALUES($1,$2,''server'',$3,$4,$5,$6,$7,$8,''in_progress'',$9,$10,$11,$12) ON CONFLICT(request_id) DO NOTHING',TG_TABLE_SCHEMA)
    USING (ctx->>'request_id')::UUID,(ctx->>'occurred_at')::TIMESTAMPTZ,(ctx->>'actor_id')::INTEGER,
       ctx->>'actor_employee_id',ctx->>'actor_name',ctx->>'session_ref',ctx->>'module',ctx->>'action',ctx->>'http_method',ctx->>'path',ctx->>'ip_address',COALESCE(ctx->'details','{}');
    EXECUTE format('INSERT INTO %I.audit_changes(request_id,table_name,record_id,operation,changed_fields,before_values,after_values) VALUES($1,$2,$3,$4,$5,$6,$7)',TG_TABLE_SCHEMA)
    USING (ctx->>'request_id')::UUID,TG_TABLE_NAME,left(record_key,120),TG_OP,changed,before_safe,after_safe;
    RETURN NULL;
END;
$$;
DO $$
DECLARE item TEXT[]; pair TEXT;
BEGIN
    FOREACH pair IN ARRAY ARRAY[
      'dd_tables:table_id','dd_columns:column_id','dd_connections:connection_id',
      'diagrams:diagram_id','diagram_nodes:node_id','diagram_edges:edge_id',
      'org_groups:group_id','org_divisions:division_id','org_departments:department_id',
      'org_units:unit_id','org_positions:position_id','department_emails:email_id','department_numbers:number_id',
      'users:user_id','system_settings:id','bookmarks:bookmark_id','server_groups:server_group_id','servers:server_id',
      'hosted_systems:hosted_system_id','group_systems:group_system_id','server_access_protocols:protocol_id',
      'server_credentials:credential_id','server_credential_shares:share_id','expiry_items:expiry_id',
      'requirements:requirement_id','requirement_links:link_id']
    LOOP
        item:=string_to_array(pair,':');
        IF to_regclass(format('%I.%I','toolkit',item[1])) IS NOT NULL THEN
            EXECUTE format('DROP TRIGGER IF EXISTS audit_row_change ON %I.%I','toolkit',item[1]);
            EXECUTE format('CREATE TRIGGER audit_row_change AFTER INSERT OR UPDATE OR DELETE ON %I.%I FOR EACH ROW EXECUTE FUNCTION toolkit.audit_capture_change(%L)','toolkit',item[1],item[2]);
        END IF;
    END LOOP;
END;
$$;
COMMIT;

-- Workflow features (migration 018)
-- Roles, application ownership, saved reviews and notification delivery.
SET search_path TO toolkit, public;
BEGIN;
CREATE TABLE IF NOT EXISTS app_roles (
    role_key VARCHAR(20) PRIMARY KEY,
    role_name VARCHAR(80) NOT NULL
);
INSERT INTO app_roles(role_key,role_name) VALUES ('admin','Administrator'),('analyst','Analyst'),('reviewer','Reviewer'),('viewer','Viewer') ON CONFLICT DO NOTHING;
ALTER TABLE users ADD COLUMN IF NOT EXISTS role_key VARCHAR(20) NOT NULL DEFAULT 'analyst' REFERENCES app_roles(role_key);
DO $$ BEGIN
    IF NOT EXISTS(SELECT 1 FROM users WHERE role_key='admin' AND is_active) THEN
        UPDATE users SET role_key='admin' WHERE user_id=(SELECT user_id FROM users ORDER BY is_active DESC,(LOWER(employee_id)='admin') DESC,user_id LIMIT 1);
    END IF;
END $$;
CREATE TABLE IF NOT EXISTS role_permissions (
    permission_id BIGSERIAL PRIMARY KEY,
    role_key VARCHAR(20) NOT NULL REFERENCES app_roles(role_key),
    module VARCHAR(40) NOT NULL,
    permission VARCHAR(24) NOT NULL,
    is_allowed BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE(role_key,module,permission)
);
-- Explicit defaults. Administrator rights cannot be removed in the matrix.
INSERT INTO role_permissions(role_key,module,permission,is_allowed)
SELECT r.role_key,m.module,p.permission,
    r.role_key='admin' OR
    (m.module NOT IN('applications','requirements') AND ((p.permission='view' AND m.module NOT IN('usermgmt','settings','logs','audit','permissions')) OR
    (r.role_key='analyst' AND m.module IN('applications','organization','datadict','diagrams','requirements','bookmarks','directory','servers','codereview','proginventory') AND p.permission IN('create','edit','delete','export')) OR
    (r.role_key='reviewer' AND m.module IN('codereview','applications','requirements','datadict','diagrams') AND p.permission IN('export','review'))))
FROM app_roles r
CROSS JOIN (VALUES('dashboard'),('account'),('applications'),('notifications'),('permissions'),('organization'),('usermgmt'),('settings'),('logs'),('audit'),('datadict'),('diagrams'),('requirements'),('bookmarks'),('directory'),('servers'),('codereview'),('proginventory'))m(module)
CROSS JOIN (VALUES('view'),('create'),('edit'),('delete'),('export'),('review'),('credentials'),('support'))p(permission)
WHERE r.role_key IN('admin','analyst','reviewer','viewer')
ON CONFLICT(role_key,module,permission) DO NOTHING;

CREATE TABLE IF NOT EXISTS applications (
    application_id BIGSERIAL PRIMARY KEY,
    application_code VARCHAR(40) NOT NULL UNIQUE,
    application_name VARCHAR(180) NOT NULL,
    description TEXT,
    lifecycle VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK(lifecycle IN('Planned','Active','Maintenance','Retired')),
    criticality VARCHAR(16) NOT NULL DEFAULT 'Medium' CHECK(criticality IN('Low','Medium','High','Critical')),
    owner_user_id INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    technical_owner_id INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    department_id INTEGER REFERENCES org_departments(department_id) ON DELETE SET NULL,
    repository_url VARCHAR(1000),
    application_url VARCHAR(1000),
    created_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS application_assets (
    asset_link_id BIGSERIAL PRIMARY KEY,
    application_id BIGINT NOT NULL REFERENCES applications(application_id) ON DELETE CASCADE,
    server_id INTEGER REFERENCES servers(server_id) ON DELETE CASCADE,
    hosted_system_id INTEGER REFERENCES hosted_systems(hosted_system_id) ON DELETE CASCADE,
    group_system_id INTEGER REFERENCES group_systems(group_system_id) ON DELETE CASCADE,
    table_id INTEGER REFERENCES dd_tables(table_id) ON DELETE CASCADE,
    diagram_id INTEGER REFERENCES diagrams(diagram_id) ON DELETE CASCADE,
    requirement_id INTEGER REFERENCES requirements(requirement_id) ON DELETE CASCADE,
    bookmark_id INTEGER REFERENCES bookmarks(bookmark_id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK(num_nonnulls(server_id,hosted_system_id,group_system_id,table_id,diagram_id,requirement_id,bookmark_id)=1)
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_application_server ON application_assets(application_id,server_id) WHERE server_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS ux_application_hosted ON application_assets(application_id,hosted_system_id) WHERE hosted_system_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS ux_application_group_system ON application_assets(application_id,group_system_id) WHERE group_system_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS ux_application_table ON application_assets(application_id,table_id) WHERE table_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS ux_application_diagram ON application_assets(application_id,diagram_id) WHERE diagram_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS ux_application_requirement ON application_assets(application_id,requirement_id) WHERE requirement_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS ux_application_bookmark ON application_assets(application_id,bookmark_id) WHERE bookmark_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS review_runs (
    review_id BIGSERIAL PRIMARY KEY,
    application_id BIGINT REFERENCES applications(application_id) ON DELETE SET NULL,
    title VARCHAR(200) NOT NULL,
    project VARCHAR(200) NOT NULL,
    engine VARCHAR(20) NOT NULL CHECK(engine IN('standard','security','qms','inventory','owasp')),
    origin VARCHAR(12) NOT NULL CHECK(origin IN('client','server')),
    created_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    creator_name VARCHAR(200) NOT NULL,
    assigned_to INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    due_at DATE,
    file_count INTEGER NOT NULL,
    finding_count INTEGER NOT NULL,
    report_meta JSONB NOT NULL DEFAULT '{}',
    completed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_review_runs_application ON review_runs(application_id,engine,review_id DESC);
CREATE INDEX IF NOT EXISTS idx_review_runs_assignee ON review_runs(assigned_to,due_at);
CREATE TABLE IF NOT EXISTS review_findings (
    finding_id BIGSERIAL PRIMARY KEY,
    review_id BIGINT NOT NULL REFERENCES review_runs(review_id) ON DELETE CASCADE,
    fingerprint CHAR(64) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    line_number INTEGER NOT NULL,
    severity VARCHAR(12) NOT NULL,
    rule VARCHAR(100) NOT NULL,
    category VARCHAR(100),
    message VARCHAR(2000) NOT NULL,
    remediation VARCHAR(2000),
    status VARCHAR(20) NOT NULL DEFAULT 'Open' CHECK(status IN('Open','In Progress','Fixed','Accepted')),
    disposition_note VARCHAR(2000),
    updated_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(review_id,fingerprint)
);
CREATE INDEX IF NOT EXISTS idx_review_findings_run ON review_findings(review_id,finding_id);
CREATE TABLE IF NOT EXISTS review_notes (
    note_id BIGSERIAL PRIMARY KEY,
    review_id BIGINT NOT NULL REFERENCES review_runs(review_id) ON DELETE CASCADE,
    finding_id BIGINT REFERENCES review_findings(finding_id) ON DELETE CASCADE,
    author_id INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    author_name VARCHAR(200) NOT NULL,
    note VARCHAR(2000) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS application_inventory_runs (
    inventory_run_id BIGSERIAL PRIMARY KEY,
    application_id BIGINT NOT NULL REFERENCES applications(application_id) ON DELETE CASCADE,
    created_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    origin VARCHAR(12) NOT NULL,
    file_count INTEGER NOT NULL,
    file_version VARCHAR(100),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS notification_settings (
    id INTEGER PRIMARY KEY CHECK(id=1),
    email_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    reminder_days INTEGER[] NOT NULL DEFAULT ARRAY[30,14,7,1,0],
    overdue_repeat_days INTEGER NOT NULL DEFAULT 7 CHECK(overdue_repeat_days BETWEEN 1 AND 90),
    last_generated_at TIMESTAMPTZ,
    last_worker_at TIMESTAMPTZ
);
INSERT INTO notification_settings(id)VALUES(1) ON CONFLICT DO NOTHING;
CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id INTEGER PRIMARY KEY REFERENCES users(user_id) ON DELETE CASCADE,
    email_enabled BOOLEAN NOT NULL DEFAULT TRUE
);
CREATE TABLE IF NOT EXISTS notifications (
    notification_id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    kind VARCHAR(30) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    target_path VARCHAR(300) NOT NULL,
    dedup_key VARCHAR(180) NOT NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    read_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(user_id,dedup_key)
);
CREATE INDEX IF NOT EXISTS idx_notifications_inbox ON notifications(user_id,notification_id DESC);
CREATE TABLE IF NOT EXISTS notification_deliveries (
    delivery_id BIGSERIAL PRIMARY KEY,
    notification_id BIGINT NOT NULL UNIQUE REFERENCES notifications(notification_id) ON DELETE CASCADE,
    status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK(status IN('pending','processing','sent','failed','skipped')),
    attempts INTEGER NOT NULL DEFAULT 0,
    available_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    claimed_at TIMESTAMPTZ,
    sent_at TIMESTAMPTZ,
    last_error VARCHAR(250)
);
CREATE INDEX IF NOT EXISTS idx_notification_delivery_pending ON notification_deliveries(status,available_at);

-- Keep the existing audit capture function; extend its safe status-only allowlist.
DO $$ DECLARE definition TEXT; pair TEXT; item TEXT[];
BEGIN
    definition:=pg_get_functiondef('toolkit.audit_capture_change()'::regprocedure);
    IF strpos(definition,'''role_key''')=0 THEN
        definition:=replace(definition,'''is_active'',''is_locked''','''role_key'',''is_allowed'',''lifecycle'',''criticality'',''is_read'',''email_enabled'',''is_active'',''is_locked''');
        EXECUTE definition;
    END IF;
    FOREACH pair IN ARRAY ARRAY['role_permissions:permission_id','applications:application_id','application_assets:asset_link_id','review_runs:review_id','review_findings:finding_id','review_notes:note_id','application_inventory_runs:inventory_run_id','notification_settings:id','notification_preferences:user_id','notifications:notification_id','notification_deliveries:delivery_id'] LOOP
        item:=string_to_array(pair,':');
        EXECUTE format('DROP TRIGGER IF EXISTS audit_row_change ON %I.%I','toolkit',item[1]);
        EXECUTE format('CREATE TRIGGER audit_row_change AFTER INSERT OR UPDATE OR DELETE ON %I.%I FOR EACH ROW EXECUTE FUNCTION toolkit.audit_capture_change(%L)','toolkit',item[1],item[2]);
    END LOOP;
END $$;
COMMIT;

-- Custom access roles. Existing accounts and permission choices are preserved.
SET search_path TO toolkit, public;
BEGIN;
ALTER TABLE app_roles ADD COLUMN IF NOT EXISTS description VARCHAR(500) NOT NULL DEFAULT '';
ALTER TABLE app_roles ADD COLUMN IF NOT EXISTS is_system BOOLEAN NOT NULL DEFAULT FALSE;
UPDATE app_roles SET is_system=TRUE WHERE role_key IN ('admin','analyst','reviewer','viewer') AND NOT is_system;
CREATE UNIQUE INDEX IF NOT EXISTS idx_app_roles_name ON app_roles(LOWER(BTRIM(role_name)));
DROP TRIGGER IF EXISTS audit_row_change ON app_roles;
CREATE TRIGGER audit_row_change AFTER INSERT OR UPDATE OR DELETE ON app_roles
    FOR EACH ROW EXECUTE FUNCTION toolkit.audit_capture_change('role_key');
COMMIT;

-- Team monitoring and personal daily activity / leave logs.
SET search_path TO toolkit, public;
BEGIN;
CREATE TABLE IF NOT EXISTS daily_logs (
    log_id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE RESTRICT,
    activity_date DATE NOT NULL,
    entry_kind VARCHAR(10) NOT NULL CHECK (entry_kind IN ('activity','leave')),
    classification VARCHAR(10) CHECK (classification IN ('SD','SCR','PROJECT')),
    activity_type VARCHAR(2) CHECK (activity_type IN ('AD','AN','CR','DD','DP','DR','ED','EM','HD','MR','PI','RV','SF','ST','TD','TM','UA','UM','UT','VC')),
    planned_task TEXT NOT NULL DEFAULT '',
    actual_activity TEXT NOT NULL DEFAULT '',
    duration_hours NUMERIC(5,2) NOT NULL DEFAULT 0 CHECK (duration_hours BETWEEN 0 AND 24),
    leave_type VARCHAR(2) CHECK (leave_type IN ('VL','SL','ML','EL','UT')),
    leave_period VARCHAR(5) CHECK (leave_period IN ('AM','PM','WHOLE')),
    remarks TEXT NOT NULL DEFAULT '',
    status VARCHAR(10) NOT NULL DEFAULT 'Draft' CHECK (status IN ('Draft','Submitted','Reviewed','Returned')),
    review_comment TEXT NOT NULL DEFAULT '',
    reviewed_by INTEGER REFERENCES users(user_id) ON DELETE RESTRICT,
    submitted_at TIMESTAMPTZ,
    reviewed_at TIMESTAMPTZ,
    version INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK ((entry_kind='activity' AND leave_type IS NULL AND leave_period IS NULL)
        OR (entry_kind='leave' AND classification IS NULL AND activity_type IS NULL)),
    CHECK (status NOT IN ('Submitted','Reviewed') OR (duration_hours>0 AND
        ((entry_kind='activity' AND classification IS NOT NULL AND activity_type IS NOT NULL AND BTRIM(planned_task)<>'' AND BTRIM(actual_activity)<>'')
        OR (entry_kind='leave' AND leave_type IS NOT NULL AND duration_hours<=8 AND
            ((leave_type='UT' AND leave_period IS NULL) OR (leave_type<>'UT' AND leave_period IS NOT NULL))))))
);
CREATE INDEX IF NOT EXISTS idx_daily_logs_owner_date ON daily_logs(user_id,activity_date DESC,log_id DESC);
CREATE INDEX IF NOT EXISTS idx_daily_logs_status_date ON daily_logs(status,activity_date DESC);
DROP TRIGGER IF EXISTS audit_row_change ON daily_logs;
CREATE TRIGGER audit_row_change AFTER INSERT OR UPDATE OR DELETE ON daily_logs
    FOR EACH ROW EXECUTE FUNCTION toolkit.audit_capture_change('log_id');

-- New grants only: reapplying the migration preserves customized permissions.
INSERT INTO role_permissions(role_key,module,permission,is_allowed)
SELECT r.role_key,m.module,p.permission,
    CASE WHEN m.module='dailylogs' THEN TRUE WHEN r.role_key IN ('admin','reviewer') THEN TRUE ELSE FALSE END
FROM app_roles r CROSS JOIN (VALUES ('dailylogs'),('teammonitoring'),('reviewlogs')) m(module)
CROSS JOIN (VALUES ('view'),('create'),('edit'),('delete'),('review')) p(permission)
WHERE (m.module='dailylogs' AND p.permission IN ('view','create','edit','delete'))
   OR (m.module='teammonitoring' AND p.permission='view')
   OR (m.module='reviewlogs' AND p.permission IN ('view','review'))
ON CONFLICT(role_key,module,permission) DO NOTHING;
COMMIT;


-- Explicit reviewer assignment. No reviewer is inferred from a role or position.
SET search_path TO toolkit, public;
BEGIN;
CREATE TABLE IF NOT EXISTS daily_log_reviewers (
    unit_id INTEGER PRIMARY KEY REFERENCES org_units(unit_id) ON DELETE CASCADE,
    reviewer_user_id INTEGER REFERENCES users(user_id) ON DELETE RESTRICT,
    assigned_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE daily_log_reviewers ALTER COLUMN reviewer_user_id DROP NOT NULL;
CREATE INDEX IF NOT EXISTS idx_daily_log_reviewers_user ON daily_log_reviewers(reviewer_user_id);
DROP TRIGGER IF EXISTS audit_row_change ON daily_log_reviewers;
CREATE TRIGGER audit_row_change AFTER INSERT OR UPDATE OR DELETE ON daily_log_reviewers
    FOR EACH ROW EXECUTE FUNCTION toolkit.audit_capture_change('unit_id');
COMMIT;


-- END OF SCHEMA
-- =====================================================================

-- Section reports, Analyst monitoring and revised preset permission defaults.
SET search_path TO toolkit, public;
BEGIN;
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(80) PRIMARY KEY,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
DO $$ BEGIN
    IF NOT EXISTS(SELECT 1 FROM schema_migrations WHERE migration_key='022_section_reports') THEN
        UPDATE role_permissions SET is_allowed=FALSE
        WHERE role_key IN('analyst','reviewer','viewer') AND module IN('applications','requirements');
        INSERT INTO role_permissions(role_key,module,permission,is_allowed)
        VALUES ('analyst','teammonitoring','view',TRUE),('analyst','teammonitoring','export',TRUE),
               ('reviewer','teammonitoring','export',TRUE),('admin','teammonitoring','export',TRUE)
        ON CONFLICT(role_key,module,permission) DO UPDATE SET is_allowed=EXCLUDED.is_allowed;
        INSERT INTO schema_migrations(migration_key) VALUES('022_section_reports');
    END IF;
END $$;
COMMIT;

-- Move reporting access into a separate module, preserving configured grants.
SET search_path TO toolkit, public;
BEGIN;
INSERT INTO role_permissions(role_key,module,permission,is_allowed)
SELECT r.role_key,'reports',p.permission,
    r.role_key='admin' OR EXISTS(SELECT 1 FROM role_permissions old WHERE old.role_key=r.role_key AND old.is_allowed AND
        ((p.permission='view' AND old.module IN('dailylogs','teammonitoring') AND old.permission='view') OR
         (p.permission='export' AND old.module='teammonitoring' AND old.permission='export')))
FROM app_roles r CROSS JOIN (VALUES('view'),('export')) p(permission)
ON CONFLICT(role_key,module,permission) DO NOTHING;
COMMIT;

-- One review decision per employee/week and an explicit working-day calendar.
SET search_path TO toolkit, public;
BEGIN;
CREATE TABLE IF NOT EXISTS daily_log_week_reviews (
    week_review_id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE RESTRICT,
    week_start DATE NOT NULL CHECK(EXTRACT(ISODOW FROM week_start)=1),
    status VARCHAR(10) NOT NULL CHECK(status IN('Submitted','Reviewed','Returned')),
    review_comment TEXT NOT NULL DEFAULT '',
    reviewed_by INTEGER REFERENCES users(user_id) ON DELETE RESTRICT,
    reviewed_at TIMESTAMPTZ,
    version INTEGER NOT NULL DEFAULT 1,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(user_id,week_start)
);
CREATE TABLE IF NOT EXISTS daily_log_holidays (
    holiday_id BIGSERIAL PRIMARY KEY,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(200) NOT NULL CHECK(BTRIM(holiday_name)<>''),
    version INTEGER NOT NULL DEFAULT 1,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_daily_holiday_date ON daily_log_holidays(holiday_date);
DROP TRIGGER IF EXISTS audit_row_change ON daily_log_week_reviews;
CREATE TRIGGER audit_row_change AFTER INSERT OR UPDATE OR DELETE ON daily_log_week_reviews FOR EACH ROW EXECUTE FUNCTION toolkit.audit_capture_change('week_review_id');
DROP TRIGGER IF EXISTS audit_row_change ON daily_log_holidays;
CREATE TRIGGER audit_row_change AFTER INSERT OR UPDATE OR DELETE ON daily_log_holidays FOR EACH ROW EXECUTE FUNCTION toolkit.audit_capture_change('holiday_id');
COMMIT;

-- Documentation and the existing organization holiday calendar get separate navigation and view grants.
SET search_path TO toolkit, public;
BEGIN;
INSERT INTO role_permissions(role_key,module,permission,is_allowed)
SELECT role_key,'wiki','view',TRUE FROM app_roles
ON CONFLICT(role_key,module,permission) DO NOTHING;
INSERT INTO role_permissions(role_key,module,permission,is_allowed)
SELECT r.role_key,'holidays','view',r.role_key='admin' OR EXISTS(
    SELECT 1 FROM role_permissions p WHERE p.role_key=r.role_key
    AND p.module='teammonitoring' AND p.permission='view' AND p.is_allowed
) FROM app_roles r
ON CONFLICT(role_key,module,permission) DO NOTHING;
COMMIT;

-- Head-role self-review and a per-user acknowledgement of the release notice.
SET search_path TO toolkit, public;
BEGIN;
INSERT INTO app_roles(role_key,role_name,description,is_system)
VALUES('section_head','Section Head','Section monitoring and weekly self-approval. Reviewing others requires a section reviewer assignment.',TRUE),
      ('department_head','Department Head','Section monitoring and weekly self-approval. Reviewing others requires a section reviewer assignment.',TRUE)
ON CONFLICT DO NOTHING;
INSERT INTO role_permissions(role_key,module,permission,is_allowed)
SELECT r.role_key,g.module,g.permission,TRUE FROM app_roles r CROSS JOIN(VALUES
    ('dashboard','view'),('wiki','view'),('holidays','view'),('teammonitoring','view'),
    ('dailylogs','view'),('dailylogs','create'),('dailylogs','edit'),('dailylogs','delete'),
    ('reviewlogs','view'),('reviewlogs','review'),('reports','view'),('reports','export'),
    ('account','view'),('account','edit'),('notifications','view'),('notifications','edit'),
    ('directory','view'),('bookmarks','view')
) g(module,permission)
WHERE regexp_replace(LOWER(r.role_key),'[^a-z]','','g') IN('sectionhead','departmenthead')
   OR regexp_replace(LOWER(r.role_name),'[^a-z]','','g') IN('sectionhead','departmenthead')
ON CONFLICT(role_key,module,permission) DO NOTHING;
INSERT INTO role_permissions(role_key,module,permission,is_allowed)
SELECT role_key,'updates','view',TRUE FROM app_roles
ON CONFLICT(role_key,module,permission) DO NOTHING;
CREATE TABLE IF NOT EXISTS user_update_reads (
    user_id INTEGER PRIMARY KEY REFERENCES users(user_id) ON DELETE CASCADE,
    release_id VARCHAR(80) NOT NULL,
    release_order INTEGER NOT NULL CHECK(release_order>0),
    seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMIT;

-- Rebrand only the built-in mail sender. Preserve custom SMTP names and all operational records.
SET search_path TO toolkit, public;
BEGIN;
ALTER TABLE system_settings ALTER COLUMN smtp_from_name SET DEFAULT 'DevAtlas';
UPDATE system_settings SET smtp_from_name='DevAtlas'
WHERE regexp_replace(LOWER(BTRIM(smtp_from_name)),'[^a-z]','','g')='analysttoolkit';
COMMIT;

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

/**
 * Filename: migration_029_leave_module.sql
 * Purpose: Enable the department leave calendar and independent section leave approvals.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Preserve existing leave rows and grant department visibility to existing roles.
 */
SET search_path TO toolkit, public;
BEGIN;
CREATE INDEX IF NOT EXISTS idx_daily_leave_calendar ON daily_logs(activity_date,user_id) WHERE entry_kind='leave';
INSERT INTO role_permissions(role_key,module,permission,is_allowed)
SELECT r.role_key,'leaves',p.permission,
    CASE WHEN p.permission='view' THEN TRUE
        WHEN p.permission='review' THEN r.role_key='admin'
            OR regexp_replace(LOWER(r.role_key),'[^a-z]','','g')='sectionhead'
            OR regexp_replace(LOWER(r.role_name),'[^a-z]','','g')='sectionhead'
        ELSE EXISTS(SELECT 1 FROM role_permissions old WHERE old.role_key=r.role_key AND old.module='dailylogs' AND old.permission=p.permission AND old.is_allowed)
    END
FROM app_roles r CROSS JOIN(VALUES('view'),('create'),('edit'),('delete'),('review')) p(permission)
ON CONFLICT(role_key,module,permission) DO NOTHING;
COMMIT;

/**
 * Filename: migration_030_leave_ranges.sql
 * Purpose: Group working-day leave entries into one versioned date-range application.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Preserve historical single-day applications and support atomic range approval.
 */
-- Executed with matching migration_030_leave_ranges.log by MigrationRunner.
SET search_path TO toolkit, public;
BEGIN;
CREATE TABLE IF NOT EXISTS leave_applications (
    request_id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE RESTRICT,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL CHECK(date_to>=date_from),
    version INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS leave_request_id BIGINT REFERENCES leave_applications(request_id) ON DELETE RESTRICT;
INSERT INTO leave_applications(request_id,user_id,date_from,date_to,version,created_at,updated_at)
SELECT log_id,user_id,activity_date,activity_date,version,created_at,updated_at FROM daily_logs
WHERE entry_kind='leave' AND leave_request_id IS NULL
ON CONFLICT(request_id) DO NOTHING;
UPDATE daily_logs SET leave_request_id=log_id WHERE entry_kind='leave' AND leave_request_id IS NULL;
SELECT setval('leave_applications_request_id_seq',GREATEST(COALESCE((SELECT MAX(request_id) FROM leave_applications),1),
    (SELECT last_value FROM leave_applications_request_id_seq)),TRUE);
CREATE UNIQUE INDEX IF NOT EXISTS idx_leave_request_day ON daily_logs(leave_request_id,activity_date) WHERE leave_request_id IS NOT NULL;
DROP TRIGGER IF EXISTS audit_row_change ON leave_applications;
CREATE TRIGGER audit_row_change AFTER INSERT OR UPDATE OR DELETE ON leave_applications FOR EACH ROW EXECUTE FUNCTION toolkit.audit_capture_change('request_id');
COMMIT;

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


-- Each account owns one dashboard layout and interface-motion preference.
SET search_path TO toolkit, public;
BEGIN;
CREATE TABLE IF NOT EXISTS user_ui_preferences (
    user_id BIGINT PRIMARY KEY REFERENCES users(user_id) ON DELETE CASCADE,
    preferences JSONB NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(preferences) = 'object'),
    revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
COMMIT;
