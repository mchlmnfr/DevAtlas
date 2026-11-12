# Workspace experience — 2026-11-12.26

Open **Dashboard → Edit dashboard** to add widgets, notes, checklists and shortcuts. Each copy has its own title, content, position and size. Drag its handle or use the move buttons; resize its corner or enter a size in Settings. Controls appear on hover or when the handle has keyboard focus. Mobile layouts stack widgets in their saved reading order. Save keeps the layout for your account; Cancel restores the saved layout, Undo reverses an edit, and Reset previews the defaults.

The widget catalog follows your current permissions. Existing summary widgets retain their server-side access checks. The calendar uses the existing department-scoped leave service. Reports open the selected reporting month. Notes, checklists and shortcuts belong to the signed-in account. They do not create business records or grant access to modules. Up to 30 widget instances fit on the 12-column grid.

Choose **Appearance** on any module for an accent palette, larger text, dashboard spacing and Standard, Subtle or Off motion. The device's reduced-motion setting takes priority. Appearance has its own Save action, independent of dashboard layout editing.

## Shared improvements

| Approved items | Available behavior |
| --- | --- |
| 1–3 | Sidebar and content transitions, shared card/menu/dialog motion, animated expandable trees. |
| 4–6 | Initial data readiness overlay with a 300 ms minimum; requests begin immediately. Later requests show a smaller status indicator and elapsed time. Transfer percentages appear only when an XMLHttpRequest supplies a measurable total. A slow initial request offers View page. |
| 7 | Existing module errors remain visible, with shared Retry/Refresh and filter guidance for empty results. |
| 8–9 | Local Flatpickr 4.6.13 date, month and time controls, Sunday-first calendars, weekend shading, Today/Now/Clear, keyboard support and existing bounds. Date ranges offer Today, This week, This month and Last month. Business-week calculations remain Monday based. Escape closes an open picker before its editor. |
| 10–11 | Account-owned filters, active filter chips, Clear filters, native list page sizes and up to 12 named views per module. Explicit URL filters take priority when opening a link. |
| 12–14 | Scrollable tables, sticky headers and action columns, saved column visibility and widths, browser visit restoration for pages, scrolling, expandable groups and eligible selections. Resizing separators support left/right arrow keys. |
| 15–18 | Inline validation and first-invalid focus, input retention, unsaved-change protection, duplicate API submission prevention, processing labels, contained tables and mobile form actions. |
| 19–20 | Opt-in recovery drafts for new activity and leave forms; recover/discard controls; expanded text editors. Password and credential fields are excluded from recovery storage. Work drafts are limited to 64 KiB per document. |
| 21–23 | Undo for filter/table display changes; last-refresh, stale-view and cross-tab new-data notices; up to 12 favourite modules. |
| 24–27 | Search inputs for long dropdowns, copy controls for supported displayed fields and text, Back to filtered list links, a shortcut guide and visible keyboard focus. Collapsed navigation retains module tooltips. |
| 28–31 | Changed-row highlighting, semantic status badges, selected record context in bulk confirmations and expandable notification groups with unread counts. Notification grouping is within the current inbox page. |
| 32–35 | Larger text, personal accent palettes, read-only/unavailable explanations and the responsive dashboard builder described above. |

Filters, table layouts, saved views, favourites, appearance, drafts and dashboard layouts use separate account-owned JSON documents. Revision checks prevent a stale manual dashboard save from overwriting another tab. Automatic filter changes reapply their specific update to the latest revision. Multi-document and legacy appearance writes are transactional. Visits and eligible selections are scoped to the account and page in this browser session.

## Installation

This patch is based on `mchlmnfr/DevAtlas` main commit `f7755c5f976d255430c432720d93bc47347edf9e`. It contains affected files only and requires main through PR #13, including the earlier shared motion and user preference files. Keep a backup of local modifications when installing a source patch.

1. Extract the affected-files ZIP into a separate folder.
2. Run the included PowerShell installer from that folder, or copy the listed files into the project root.
3. Run `php bin/install-workspace-experience.php` using the PHP installation used by WAMP. It applies only the two dated workspace preference migrations and preserves existing preferences. It does **not** execute `database/schema.sql` against your existing database.
4. Restart Apache through WAMP if needed, then open the Dashboard and press Ctrl+F5.

The PowerShell installer validates package hashes, checks PHP syntax, backs up replaced files, copies only the manifest entries, runs the dated installer, attempts an Apache restart and opens the Dashboard. Supply `-ProjectPath`, `-PhpPath` or `-OpenUrl` when needed. `-SkipDatabaseMigration`, `-SkipRestart` and `-NoBrowser` are available for administrators handling those steps separately.

Before the new migration is installed, pages retain usable defaults and explain that workspace saving is unavailable. Fresh installations receive the new table from the updated schema. Existing numbered migrations, including Birthday Leave, are preserved.

## Verification

Run `php tests/workspace-experience.php` for pure validation checks without database writes. Browser verification uses an isolated PostgreSQL database and sample accounts. It covers account isolation, permissions, CSRF, revision conflicts, atomic rollback, widget editing, safe note/link rendering, filters, columns, drafts, validation, reduced motion and mobile layout. The source patch is not a deployment to a running WAMP installation.
