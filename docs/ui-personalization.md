# Dashboard personalization and shared motion

The dashboard's **Customize** button opens a preview panel. Users can show or hide their available widgets, reorder them by dragging the grip or using the up/down buttons, and choose Comfortable or Compact spacing. **Save changes** stores the layout against the signed-in account; **Cancel** restores the saved layout. **Restore defaults** previews the default layout until Save is selected.

The widget list follows existing module and data permissions. Personalization does not grant access to dashboard data or module pages. Summary cards, My week, Weekly approvals, Team progress, Renewals, Workspace records and Saved access links appear when available to the user. All widgets may be hidden; Customize stays available to restore them.

The motion control applies throughout DevAtlas. Standard adds short card entrances and control/dialog transitions. Subtle uses shorter fades; Off removes decorative motion. The operating system's reduced-motion preference always wins. Login, recovery and documentation pages receive the same shared motion assets, with reduced-motion support; signed-in module pages use the account's saved motion setting.

Preferences are stored in PostgreSQL, so they follow the account across browsers. Saved layouts use revision checks: a tab editing an older revision must reload saved settings before overwriting newer changes. Motion and layout previews are discarded on Cancel. Dashboard personalization requires JavaScript; widgets remain visible and usable without it according to their server-rendered visibility and layout order.

## Install an existing WAMP deployment

Copy the patch files into `C:\wamp64\www\AnalystToolkit`, preserving their paths. Run the installer with the WAMP PHP executable, or `php` if the correct PHP version is on PATH:

```powershell
Set-Location 'C:\wamp64\www\AnalystToolkit'
php .\bin\install-ui-preferences.php
```

The dated migration creates `user_ui_preferences` and preserves existing layouts on repeat runs. It does not replace a locally customized numbered leave migration. Fresh installations also receive the table from `database/schema.sql`.

Before the migration is installed, modules use default motion and the dashboard opens with default widgets. The customization panel explains that saving is unavailable. Run the installer to enable account persistence, then refresh the page.

## Implementation

`lib/UserUiPreferences.php` validates a fixed widget catalog, boolean visibility, spacing and motion enums. The API at `/modules/account/ui-preferences` loads and saves only the current session's user ID, requires authentication, and checks CSRF for writes. Account IDs, arbitrary widget markup and URLs are not accepted as preferences.

`assets/css/ui-motion.css` and `assets/js/ui-motion.js` are loaded through the shared UI assets partial, so module, authentication and documentation frames receive the same animation behavior. Entrances use opacity and transforms, do not delay navigation or API calls, and limit newly animated table rows. Existing dialog, search and approval behavior continues to use its current event handlers.

Run the database-free preference checks with `php tests/user-ui-preferences.php`. Release history adds `2026-11-12.25` while keeping earlier entries.
