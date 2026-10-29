/**
    * Filename: migration_013_requirements_traceability.sql
    * Purpose: Apply the requirements traceability database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- Requirements traceability for existing installs. Run after migration_012.
SET search_path TO toolkit, public;

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
