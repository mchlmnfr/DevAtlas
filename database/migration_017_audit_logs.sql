/**
 * Filename: migration_017_audit_logs.sql
 * Purpose: Apply the audit logs database upgrade.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

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
