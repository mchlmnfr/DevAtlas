/**
    * Filename: migration_026_head_reviews_updates.sql
    * Purpose: Apply the head reviews updates database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

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
