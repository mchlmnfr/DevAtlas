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
