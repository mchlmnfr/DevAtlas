SET search_path TO toolkit, public;
BEGIN;
CREATE TABLE IF NOT EXISTS user_workspace_state (
    user_id BIGINT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    state_key VARCHAR(100) NOT NULL,
    value JSONB NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(value)='object'),
    revision INTEGER NOT NULL DEFAULT 1 CHECK (revision>0),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY(user_id,state_key)
);
COMMIT;
