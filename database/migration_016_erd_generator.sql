/**
    * Filename: migration_016_erd_generator.sql
    * Purpose: Apply the erd generator database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- ERD snapshots and dictionary provenance. Existing diagrams are retained.
SET search_path TO toolkit, public;
BEGIN;
ALTER TABLE diagrams ADD COLUMN IF NOT EXISTS generation_meta JSONB NOT NULL DEFAULT '{}'::JSONB;
ALTER TABLE diagram_nodes ADD COLUMN IF NOT EXISTS erd_meta JSONB NOT NULL DEFAULT '{}'::JSONB;
ALTER TABLE diagram_edges ADD COLUMN IF NOT EXISTS erd_meta JSONB NOT NULL DEFAULT '{}'::JSONB;
CREATE INDEX IF NOT EXISTS idx_diagrams_erd_updated ON diagrams(updated_at DESC) WHERE diagram_type = 'ERD';
COMMIT;
