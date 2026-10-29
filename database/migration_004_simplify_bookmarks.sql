/**
    * Filename: migration_004_simplify_bookmarks.sql
    * Purpose: Apply the simplify bookmarks database upgrade.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */

-- =====================================================================
-- AnalystToolkit :: migration_004_simplify_bookmarks.sql
-- Run this if you already installed the original schema.sql.
-- Simplifies the bookmark manager down to title/url/description/tags,
-- dropping categories and the favorite flag. This is DESTRUCTIVE for
-- any category/favorite data you've entered — back up first if unsure:
--   pg_dump -U postgres -d analyst_toolkit -t toolkit.bookmarks -t toolkit.bookmark_categories > bookmarks_backup.sql
-- =====================================================================

SET search_path TO toolkit, public;

ALTER TABLE bookmarks DROP COLUMN IF EXISTS category_id;
ALTER TABLE bookmarks DROP COLUMN IF EXISTS is_favorite;
DROP TABLE IF EXISTS bookmark_categories CASCADE;
DROP INDEX IF EXISTS idx_bookmarks_category;
