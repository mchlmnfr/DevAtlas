/**
    * Filename: migration_018_workflow.sql
    * Purpose: Apply the workflow database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

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
