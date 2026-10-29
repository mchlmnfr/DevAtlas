# DevAtlas project handoff

**Current checkpoint:** 2026-10-29, Asia/Manila. **Release:** `2026-10-29.14`.

This checkpoint supersedes stack and source-location descriptions in the earlier handoff below. Functional rules recorded there continue to apply.

The application has been migrated to **CodeIgniter 4.7.5 + HTMX 2.0.11 + Alpine.js 3.17.5 + Tailwind CSS 4.3.3**. Every existing module URL forwards to explicit framework routes and controllers. Templates are in `app/Views`, with aligned HTML tags, expanded attributes, four-space indentation and repeatable token-checked formatting. Domain services remain in `lib` to preserve existing audited PDO workflows.

Navigation now uses clean routes such as `/AnalystToolkit/login` and `/AnalystToolkit/modules/dailylogs/`. Legacy `.php` page GET requests redirect with their query strings intact. Existing POST/API/download URLs remain directly supported; equivalent extensionless aliases are explicit framework routes. `RouteUrls` preserves the original authorization/audit identities, and forced password changes recognize both route forms. The installation prefix is unchanged.

Project cleanup removed stale test profiles, download caches, intermediate snapshots and completed migration tooling. Unused legacy layouts/reference pages and the replaced Inter font are archived in the protected `.stack-work/retired-source/` folder. Active layouts resolve configuration and services directly from `ROOTPATH`. The portable Node installation, installed dependencies, compatibility routes, data/uploads/logs and original installation recovery snapshot are retained. See [cleanup details](docs/quality/cleanup-manifest.json) and [dependency verification](docs/quality/unused-source-review.json).

HTMX renders actual permission-scoped record-search fragments, with JSON compatibility for existing clients. Alpine powers shared search/appearance components. Tailwind source is `resources/css/app.css`; compiled production CSS is `assets/css/tailwind.css`. Poppins, Lucide and SweetAlert2 remain local.

PHP 8.2 and `intl` are required; intl was enabled and local Apache restarted. The installed PostgreSQL database/schema, encrypted configuration, vault key, native login/CSRF contract, reports and notification task identities remain compatible. No database schema migration accompanies this stack conversion. PhpSpreadsheet is patched to 1.30.7.

Use `npm run format:views`, `npm run check:views`, `npm run build:css`, `php bin/qms-audit.php`, `php bin/qms-layout.php` and `python tests/run-quality.py`. Installed plugins/dependencies and generated artifacts are excluded from source review; our application, view, JS, CSS and Tailwind source are included. Framework-required names and existing database/API identifiers remain explicit manual review items. Do not claim formal QMU/SAD certification.

Final validation: **24/24 suites, 1,705 assertions; 275 PHP syntax checks; 44 repeatable view-format checks**. Tailwind rebuild hash is stable; Composer and npm advisories are zero. QMS retains **804 raw compatibility/context matches across 364 first-party files**, with zero header, unnecessary-comment, brace or multiple-statement findings. The encrypted installation files match the baseline hashes; live counts remain two users and zero daily logs/leaves.

See [migration notes](docs/STACK_MIGRATION.md), [route manifest](docs/quality/stack-route-manifest.json), [module validation](docs/quality/module-validation.json) and [QMS audit](docs/quality/qms-current.json). The local `.stack-work/` recovery checkpoint and `.tools/` portable tooling are private and ignored; the recovery snapshot includes installation configuration and must not be published.

---

## Earlier handoff checkpoint ? retained for workflow detail

**Handoff updated:** 2026-10-29, Asia/Manila.  
**Latest recorded application verification:** 2026-10-28.  
**Latest application release:** `2026-10-28.11` in [docs/releases.json](docs/releases.json).

This current-state summary supersedes the older implementation notes preserved
below. Older sections headed "Current TODO", "Current Implementation" or "Next
Recommended Step" describe their historical checkpoint. They are not the current
backlog or authorization to implement more work.

## Current project and environment

DevAtlas is the renamed enterprise IT management platform previously called
AnalystToolkit. It is a centrally hosted PHP/PostgreSQL application accessed by
department personnel over the intranet. Runtime assets and dependencies are local;
the application does not depend on a CDN or an external AI service.

| Item | Current state |
| --- | --- |
| Workspace | `C:\xampp\htdocs\AnalystToolkit` |
| PHP executable | `C:\xampp\php\php.exe`; CLI version checked during this handoff update: **8.2.12** |
| Compatibility target | PHP `^8.1`; Composer platform remains **8.1.26** |
| Application database | PostgreSQL database `analyst_toolkit`, schema `toolkit` |
| Application URL prefix | `/AnalystToolkit`; retained for compatibility despite the DevAtlas product name |
| Frontend | Server-rendered PHP, vanilla JavaScript, shared `AT` namespace and module scripts |
| Libraries | Local PhpSpreadsheet and PHPMailer; local SweetAlert2 **11.26.25** |
| Typography and icons | Local **Poppins** fonts and **Lucide** icons |
| Test tooling | Python **3.11.9**, PHP CLI, PostgreSQL fixtures and headless Microsoft Edge |

Daily-log, leave, birthday and reminder code explicitly uses **Asia/Manila**.
The global `APP_TIMEZONE` in `config/config.php` remains `Asia/Singapore`, also
UTC+08:00. This documentation update does not change configuration or application
behavior. Older WAMP paths below describe a previous environment.

## Current navigation and module status

Navigation and actions follow the signed-in user's effective permissions.

| Sidebar group | Modules |
| --- | --- |
| Overview | Dashboard; Notifications |
| Workspace | Data Dictionary; ERD Generator; Code Review |
| Inventory | Server Inventory; Program Inventory; Call Directory; Access Links |
| Team Monitoring | Team Monitoring; My Daily Logs; **Leaves**; **Approvals**; Reports |
| Parameter | Users; Organization; Roles & Permissions; Parameters; **Holidays** |
| Logs | Error; Audit |

Applications and Requirements remain hidden from normal navigation, workspace
search, the permission matrix and dashboard panels. Their stored data, existing
grants and backend routes are retained. Re-enabling them is a future product
decision, not part of this handoff.

Documents Wiki opens as a separate help page through **Help**, outside the sidebar.
**What's New** opens in a modal, displays release history newest first, and tracks
the latest acknowledged release per user. Feature updates belong in
`docs/releases.json` with an increasing release order; preserve existing history.

The account menu contains Light, Dark and System appearance options. The header
theme control was removed. Email-code and password-recovery pages no longer show
the old auth-fallback message; public directory and link access was repositioned
on the login page.

## Daily work, leave, approvals and reporting

### My Daily Logs

- Daily Logs now records **work activities only**. Leave and undertime applications
  belong in Leaves; daily-log write routes reject leave submissions or attempts to
  convert existing leave into work.
- Work classifications are SD, SCR and PROJECT. All twenty activity codes remain
  supported. Add and Edit show the selected code's full description in the visible,
  accessible helper below the selector; see `DailyLogs::ACTIVITY_DESCRIPTIONS`.
- Owners with Edit permission can edit Draft, Submitted and Returned work logs.
  Submitted edits use **Save Changes** and remain submitted. Reviewed entries stay
  locked until the reviewer returns the week. Delete remains limited to the owner's
  unchanged Draft and Returned entries.
- Record versions and a locked owner row protect concurrent edits and decisions.
  Changing a submitted work entry invalidates the reviewer's older weekly snapshot.

### Leaves and the department calendar

- Leaves is a separate application/calendar module. **Date From** and **Date To**
  create one application covering its working dates, for up to 366 calendar days.
  Weekends and organization holidays are skipped; ranges without working days fail.
- Leave types are VL, SL, ML, EL and UT. AM/PM is 4 hours per working day, whole-day
  leave is 8 hours per working day, and undertime accepts entered hours up to 8.
  Overlaps and combined daily-hour limits are validated before a range is saved.
- Everyone with Leaves View can see submitted and approved leave in their department,
  across its sections. Existing roles and newly created roles receive calendar View
  by default. An administrator can select a department; an unassigned account has
  its own calendar until placed in the organization.
- Drafts are private to their applicant. Returned applications and remarks are
  visible only to the applicant and authorized section approvers. Department peers
  receive public calendar details without private reasons or approval actions.
- Submitted applications remain editable by their owner with Leaves Edit; they
  stay submitted. Approved applications must be returned before editing. A request
  version protects the entire range, and range edits and decisions are atomic.

### One Approvals module, two independent tabs

| Tab | Permission and scope | Decision and self-approval rules |
| --- | --- | --- |
| Daily Logs | Existing `reviewlogs` View/Review grants; reviewer assignment scopes other personnel's work for non-administrators | One decision per employee/week, Monday-Sunday, for work entries. Section Head and Department Head roles may review their own week with the required grants. Other roles, including Administrator, cannot self-review a work week. |
| Leave | **Leaves View and Review**; approver and applicant must belong to the same section | One decision covers the complete leave date range. **Only Section Heads** may approve their own leave. Department Head and custom approver roles do not inherit this exception. |

Grant **Leaves > Review** in Roles & Permissions to a chosen approval role. Section
Heads receive it by default. Leave approval uses the role grant and the current
section; it does not use the daily-log reviewer assignment table.

The leave calendar contains application controls and links eligible reviewers to
Approvals. Approval buttons and the leave queue are in **Approvals > Leave**.
Returning an application requires a comment. Approval rechecks the latest version,
working dates and daily constraints.

The main route is `modules/approvals/index.php`, with `tab=dailylogs` or `tab=leaves`.
Approvals visibility derives from effective daily-log review View or Leaves Review;
there is no separate stored `approvals` permission profile. The underlying grants
remain `reviewlogs` and `leaves`. The permission matrix groups daily-log review under
the Approvals sidebar entry. Legacy `modules/reviewlogs/index.php` links redirect
to the Daily Logs tab while preserving their query parameters; its APIs retain the
existing authorization checks.

Work-week approval and leave approval are independent. Returning or reopening a
work week does not reset leave decisions; a leave change does not alter work-week
review snapshots. New or corrected work submissions reopen the complete work week.

### Team Monitoring and required hours

Any role can receive Team Monitoring View. Non-administrators monitor their own
section; administrators can monitor across sections. Each person shows entered
hours and hours still required, with approved completion displayed separately.

Working days are Monday-Friday, **8 hours per day**, excluding the organization-wide
holiday calendar. Submitted and Reviewed work/leave contribute to entered hours;
Draft and Returned entries do not. Approved leave reduces the approved work target.
Extra hours on one date cannot fill a shortfall on another. Holiday/weekend work
can still be logged and reported. Administrators maintain holidays in
**Parameter > Holidays**; dates are entered explicitly and apply to the whole
organization.

### Reports

- Reports is a separate module. One section Excel export contains a worksheet for
  every assigned employee, including personnel without matching entries.
- The default weekly summary follows the supplied sample: orange title/name/totals,
  blue SD/SCR/Project headers, twenty activity rows and a separate approved-leave
  table. It counts approved weekday work. Detailed exports include dated work and
  leave, including weekend work, while excluding private Drafts.
- Employee report/sheet names use **every first-name word's initial**, the first
  middle-name initial and the full surname, in uppercase. Michael Adrian + Dimaano
  + Monfero becomes **MADMONFERO**.
- Report View/Export grants and section access remain required; department calendar
  visibility alone does not grant reporting or work-log review access.

### Birthdays

Users has an optional **Birthday** field in both creation and editing. The API
validates real dates from 1900 through today, supports clearing the field, and
preserves an existing birthday when an older account-update request omits it.

Birthdays recur on the department leave calendar across all of its sections. Events
show the person's name and occurrence date, without birth years or ages. Only active,
unlocked accounts are included. February 29 birthdays appear on February 28 in
non-leap years and on February 29 in leap years. Birthday events do not create leave
entries or change working-hour totals.

## Other completed changes since the October 25 checkpoint

- **Dashboard:** action-oriented missing-hour, returned-week and approval work;
  actionable review/renewal context. Applications/Requirements panels are removed.
- **Search:** searches actual authorized records as well as modules, with role and
  ownership scopes. Own leave matches open the Leaves module. Credential values and
  hidden modules are excluded.
- **Reminders:** daily-log and weekly-work-approval reminders use working days and
  current permissions. Defaults are 17:00 Asia/Manila, a 14-day lookback and a
  one-day approval delay. Email and delivery settings remain configurable.
- **Code Review:** up to **3,000 supported files**, 48 MiB total source and 256 KiB
  per file, for client/server project scans. Generated/dependency folders are
  excluded. Finding and function-inventory display limits remain disclosed. Review
  history and Excel/ODS exports remain supported.
- **Permissions:** six protected preset roles now exist: Administrator, Analyst,
  Reviewer, Viewer, Section Head and Department Head. Custom roles remain supported.
  Module/action checks run on the server; browser controls mirror them.
- **Identity:** DevAtlas branding, local Poppins, Lucide and shared SweetAlert2;
  responsive light/dark layouts. Database names, URL prefix and the installed
  scheduler name retain their legacy identifiers for compatibility.
- **Maintenance:** QMS source conventions across first-party code, encrypted
  installation secrets, repeatable audits and matching migration execution logs.

## Current implementation map

| Concern | Main sources |
| --- | --- |
| Authentication and authorization | [lib/Auth.php](lib/Auth.php), [lib/Permissions.php](lib/Permissions.php), [lib/Roles.php](lib/Roles.php) |
| Navigation and shared feedback/themes | [includes/header.php](includes/header.php), [includes/user_menu.php](includes/user_menu.php), [assets/js/app.js](assets/js/app.js), [assets/css/app.css](assets/css/app.css) |
| Work logs, weekly review and completion | [lib/DailyLogs.php](lib/DailyLogs.php), [lib/WeeklyLogReview.php](lib/WeeklyLogReview.php), [lib/daily_logs_page.php](lib/daily_logs_page.php), [assets/js/daily-logs.js](assets/js/daily-logs.js) |
| Leave ranges, calendar and birthday events | [lib/LeaveRequests.php](lib/LeaveRequests.php), [modules/leaves/index.php](modules/leaves/index.php), [modules/leaves/api.php](modules/leaves/api.php), [assets/js/leaves.js](assets/js/leaves.js) |
| Approval entry point and tabs | [modules/approvals/index.php](modules/approvals/index.php), [lib/approval_tabs.php](lib/approval_tabs.php), [lib/leave_approvals_page.php](lib/leave_approvals_page.php) |
| Holiday calendar | [lib/DailyLogCalendar.php](lib/DailyLogCalendar.php), [modules/holidays/index.php](modules/holidays/index.php) |
| Section exports | [lib/DailyLogReports.php](lib/DailyLogReports.php), [lib/DailyLogsWorkbook.php](lib/DailyLogsWorkbook.php), [lib/WeeklySummaryWorkbook.php](lib/WeeklySummaryWorkbook.php) |
| User birthdays and organization placement | [modules/usermgmt/api.php](modules/usermgmt/api.php), [modules/usermgmt/index.php](modules/usermgmt/index.php), [assets/js/users.js](assets/js/users.js) |
| Dashboard, search and reminders | [lib/ActionCenter.php](lib/ActionCenter.php), [lib/RecordSearch.php](lib/RecordSearch.php), [lib/LogReminders.php](lib/LogReminders.php), [lib/Notifications.php](lib/Notifications.php) |
| Help and release history | [lib/SystemWiki.php](lib/SystemWiki.php), [lib/SystemUpdates.php](lib/SystemUpdates.php), [docs/releases.json](docs/releases.json) |
| QMS review and migration logging | [lib/QmsCodeSniffer.php](lib/QmsCodeSniffer.php), [lib/QmsSourceSupport.php](lib/QmsSourceSupport.php), [lib/MigrationRunner.php](lib/MigrationRunner.php) |

The new `leave_applications` table stores the applicant, date range and application
version. Each covered working day remains a `daily_logs` leave row linked through
`leave_request_id`. Historical single-day leave is associated with one application
without replacing its daily record. This preserves existing monitoring and export
queries. Work approvals filter `entry_kind='activity'`; leave decisions operate on
the complete application. Keep these boundaries when extending either workflow.

Other relevant tables are `daily_log_reviewers`, `daily_log_week_reviews`,
`daily_log_holidays`, `user_update_reads`, `role_permissions` and
`notification_settings`. The optional account field is `users.birthday`. The latest
audit fixtures expect **43** audited domain-table triggers, including applications
for leave.

## Database upgrades and installation state

[database/schema.sql](database/schema.sql) includes changes through migration 031
for fresh installations. Upgrade an existing database with its outstanding numbered
migrations; do not replace an existing database with the fresh-install schema.

| Migration | Purpose |
| --- | --- |
| 020 | Daily work and leave records |
| 021 | Section work-log reviewer assignments |
| 022 | Section report support |
| 023 | Separate Reports module |
| 024 | Weekly approvals and organization holidays |
| 025 | Wiki and standalone Holidays view grants |
| 026 | Head-role weekly self-review and per-user release acknowledgements |
| 027 | DevAtlas default sender branding, preserving custom names |
| 028 | Action/reminder settings and notification context |
| 029 | Leaves module grants and calendar index |
| 030 | Versioned leave ranges and links to preserved daily entries |
| 031 | Optional user birthdays |

The local installation applied **029-031 on 2026-10-28**. Their matching logs under
`logs/migrations/` end in `completed`. The protected installation record confirms
matching hashes of original account and daily-log fields before and after the
upgrade. That database had two users and zero daily logs at the recorded upgrade;
populated historical-leave migration scenarios are covered by isolated fixtures.

Existing-installation entry points:

- `bin/install-daily-logs.php`: migrations 020-026.
- `bin/install-wiki.php`: migrations 025-026; `bin/install-updates.php`: 026.
- `bin/install-brand.php`: migration 027.
- `bin/install-leaves.php`: migrations 029-031; earlier prerequisites must exist.
- Migration 028 is a numbered SQL upgrade; it is not part of `install-leaves.php`.

`MigrationRunner` writes start/completed/failed records and the script checksum to
`logs/migrations/<script-basename>.log`, without logging SQL or secrets. For direct
`psql` execution, use its matching `-L` log option; client-only SPOOL directives are
not added to PDO-executed SQL.

## Configuration, backups and operating notes

Installation secrets are authenticated-encrypted through
[config/LocalSecrets.php](config/LocalSecrets.php). Preserve the protected
`config/.local-secrets.json` and its 32-byte `config/.local-key` with the database and
required uploads when moving or restoring this installation. Deployments can supply
`DEVATLAS_CONFIG_KEY` and `DEVATLAS_SECRETS_FILE` instead. Do not put secret values
in source, this handoff or public documentation.

`bin/configure-secrets.php` accepts settings on standard input. Omitted existing
values are retained. The credentials-vault encryption key was preserved; changing
an existing `APP_ENCRYPTION_KEY` requires a separate credential migration and is
rejected by this utility. Do not regenerate a working installation's encryption
keys as a routine setup step. Configuration and quality evidence are blocked from
direct HTTP access.

The last documented Windows task is **AnalystToolkit Notifications**, every fifteen
minutes using PHP without a visible window. Its installed InteractiveToken mode
requires that Windows account to be signed in. An unattended deployment needs an
appropriately configured task account. This handoff refresh did not inspect or
change Task Scheduler or SMTP settings. Production SMTP delivery and unattended
operation are separate environment checks; regression mail tests use loopback.

## Verification checkpoint and maintenance commands

The latest saved run, dated **2026-10-28**, has **23/23 passing suites and 1,628
assertions**. It includes 59 leave backend checks, 239 daily-log/leave browser checks
and 245 shared branding/theme/interface checks. All 160 audited PHP files passed
syntax checks. Authentication, roles, audits, reminders, reports, scans, modeling
and other modules are covered by the remaining suites.

| Evidence | Contents |
| --- | --- |
| [docs/quality/module-validation.json](docs/quality/module-validation.json) | Suite outcomes, counts, timings and links to individual logs |
| [docs/quality/leave-workflow-review.md](docs/quality/leave-workflow-review.md) | Leave/approval/birthday implementation and verification scope |
| [docs/quality/leave-installation.json](docs/quality/leave-installation.json) | Upgrade preservation comparison |
| [docs/quality/leave-http-validation.json](docs/quality/leave-http-validation.json) | Last live Apache sign-in, guest API and private-path checks |
| [docs/quality/qms-current.json](docs/quality/qms-current.json) | Current first-party QMS audit |
| [docs/quality/QMS-review.md](docs/quality/QMS-review.md) | Refactor history, compatibility exceptions and manual-review limits |

The current QMS report covers **246 first-party source files** and retains **698
raw diagnostic matches** for compatibility/manual review. PHP layout reports zero
files requiring changes, with executable tokens checked. The new leave service
and client pass the implemented diagnostics. This is not a zero-finding or formal
QMU/SAD certification claim. SQL spool matches remain visible even though the
matching MigrationRunner logs were verified. Dependencies, generated data and
deliberately noncompliant root `codereview/` samples are excluded; the Code Review
and Error Logs application modules are included.

Run the following from the workspace when application changes justify verification:

```powershell
Set-Location 'C:\xampp\htdocs\AnalystToolkit'
& 'C:\xampp\php\php.exe' bin/qms-audit.php
& 'C:\xampp\php\php.exe' bin/qms-layout.php
python tests/run-quality.py
```

For focused work, the runner accepts `--suite leaves.php` or
`--suite daily-logs-browser.py`; selected-suite results merge with retained results.
`--php-only` writes PHP-only coverage, not a full browser-validation checkpoint.
Fixtures use disposable PostgreSQL schemas, temporary files and Edge profiles;
they do not use real personnel for workflow tests. The runner currently references
Windows/XAMPP tools. PHP 8.1 runtime parity, other deployment hosts, external metadata
engines and production SMTP need their own configured checks.

This **2026-10-29 update is documentation only**. The recorded application tests were
not rerun for this handoff edit; the figures above are the verified October 28
checkpoint, not a claim of a new test run today.

## Maintenance rules and continuation

Follow the QMS headers, PascalCase routines, camelCase fields where applicable,
UPPER_SNAKE_CASE constants, four-space indentation, vertical braces and separate
statements in first-party source. Preserve established database, HTTP/JSON,
session, browser and library contracts. Shared PHP helper names now include
`JsonResponse`, `JsonError`, `ReadJsonBody`, `CsrfToken`, `RequireCsrf`,
`RequireVendorAutoload` and `UiIcon`; browser exports retain contracts such as
`AT.api`, `AT.escapeHtml` and `AT.confirmAction`. Older code examples below may
predate these names.

The requested functional changes through release 11 are complete at the recorded
checkpoint. No additional feature implementation was requested with this handoff
refresh. Roadmap suggestions remain proposals; follow the user's next request
before implementing more improvements. Preserve the separate approval rules,
existing data/encryption keys, hidden-module state and local asset strategy.

## Historical implementation notes through 2026-10-25

The original body below is retained as project history. Its earlier branding,
paths, role counts, feature statuses, helper spellings, test counts and TODOs are
superseded by the current-state summary above where they differ.


---

## 1. Project Overview

**Purpose:** AnalystToolkit is a local-first, offline-capable web workbench of internal
tools for a systems/business analyst team. It bundles several previously-separate
workflows (data dictionary documentation, diagram generation, org/call directory,
bookmarks, server inventory, file cataloging) into one PHP/PostgreSQL application with
**zero online/CDN dependencies** and **no AI integrations**.

**Main users:** Internal system analysts and IT staff at one organization, accessing a
centrally hosted instance of the app from their own workstations.

**Deployment model (IMPORTANT, confirmed late in the project):** The application is
**hosted on a server**; users connect to it remotely from their own local machines over
the network/intranet. This is NOT a per-analyst local install. This corrected an earlier
wrong assumption (see Section 11) that shaped a redesign of the Program Inventory module.

**Main workflows:**
- Documenting database schemas (manually or by importing live DB structure)
- Generating ERD/DFD/SFD/PFD diagrams and exporting them to yEd-compatible GraphML
- Maintaining an organizational hierarchy and a department-contact directory
- Bookmarking frequently used internal tools/links
- Tracking server inventory: hardware, environments, hosted software, access methods,
  credentials, and expiring certificates/licenses
- Cataloging project files (their own machine's or the server's) into a formatted Excel
  report
- User account administration with password policy enforcement

**Current objectives:** The functional build is complete across all requested modules
(see Section 5). The most recent work item (Program Inventory dual-mode scanning) was
delivered. No further work was explicitly requested at handoff time; Section 14 proposes
what to tackle next.

---

## 2. Technology Stack

- **Language:** PHP (target/confirmed runtime: **PHP 8.1.26** — see Section 12 constraints)
- **Database:** PostgreSQL, exclusively for the app's own data, inside a dedicated
  `toolkit` schema. MySQL/MariaDB is supported only as a *target* for the Data
  Dictionary's live-introspection feature (documenting someone else's database) — it is
  never the app's own datastore.
- **Server/environment:** WAMP stack (Apache + PHP + PostgreSQL), Windows-hosted
  (`C:\wamp64\www\AnalystToolkit\`)
- **Frontend:** No framework. Hand-rolled CSS (`assets/css/app.css`) and vanilla
  JavaScript (`assets/js/app.js`, a single `AT` namespace object) using native
  `fetch()`-based AJAX. jQuery was explicitly evaluated and **rejected** (see Section 11).
- **PHP libraries (via Composer, `composer.json`):**
  - `phpoffice/phpspreadsheet` (^1.29) — Excel (.xlsx) generation/parsing, used by Data
    Dictionary export, Diagram Generator Excel import/export, Program Inventory export
  - `phpmailer/phpmailer` (^6.9) — SMTP email sending for account-creation notices
  - `composer.json` sets `config.platform.php: "8.1.26"` so Composer resolves
    dependency versions compatible with the real server PHP version (see Section 12)
- **No CDN usage anywhere.** `composer install` is treated as an acceptable one-time,
  offline-after-install exception to the "zero online dependencies" rule (same as
  installing any local PHP library) — the app must still run 100% offline afterward.
- **Build/deployment tools:** None beyond Composer for PHP dependencies. No bundler, no
  CI/CD pipeline, no test framework was set up during this project.
- **APIs/integrations:** None external. SMTP (outbound email) is the only network-facing
  integration, and it's optional/configurable (Parameters page), pointed at whatever mail
  server the organization provides (internal or hosted) — not a bundled third-party API.

---

## 3. System Architecture

### Overall architecture
Classic server-rendered PHP app with a REST-ish JSON API layer per module, consumed by
vanilla JS via `fetch()`. Each module is a self-contained folder under `modules/` with:
- `index.php` — renders the page shell (via `includes/header.php` / `footer.php`) plus
  an inline `<script>` block driving that page's interactivity
- `api.php` — a single file per module handling all of that module's JSON actions via
  `$_GET['action']` / `$_POST['action']` dispatch (`switch` statement)
- Some modules also have dedicated file-streaming endpoints (exports, downloads,
  uploads) that are separate `.php` files, not part of the JSON `api.php`

### Modules/components (in final sidebar order)
1. **Data Dictionary** (`modules/datadict/`)
2. **Diagram Generator** (`modules/diagrams/`)
3. **Call Directory** (`modules/directory/`)
4. **Access Links** (`modules/bookmarks/`)
5. **Server Inventory** (`modules/servers/`)
6. **Program Inventory** (`modules/proginventory/`)
7. **Org & Users** (`modules/usermgmt/`)
8. **Parameters** (`modules/settings/`)
9. **Error Logs** (`modules/logs/`)
10. **Account** (`modules/account/`) — not a nav item; just the avatar-upload endpoint

### How components interact
- **Org & Users is the single source of truth** for the organizational hierarchy
  (Group → Division → Department → Unit → Position). Call Directory does **not** own
  this structure — it only *reads* Departments from it (via its own `api.php`, which
  queries the same tables) to attach contact info. This was an explicit refactor (see
  Section 8, Decision D-9).
- Server Inventory's Program Inventory-style exports and Data Dictionary's exports both
  depend on the shared PhpSpreadsheet vendor library, loaded defensively via
  `lib/vendor_bootstrap.php::require_vendor_autoload()` (page-style, calls `json_error()`
  + `exit` if missing) or, where that would wrongly abort a non-export request, a local
  private "return false instead of exiting" variant (see `lib/Mailer.php`).
- Program Inventory's two scan modes share one rendering library
  (`lib/ProgramInventoryReport.php`) so both produce byte-identical formatting.

### Authentication / authorization
- **`lib/Auth.php`** is the single authentication class. Login is by **Employee ID +
  password** (bcrypt via PHP's native `password_hash()`/`password_verify()`).
- Every page load goes through `includes/header.php`, which calls
  `Auth::requirePage()` — redirects to `login.php` if not authenticated, or to
  `change_password.php` if `must_change_password` is set in session.
- Every module's `api.php` (and every file-streaming endpoint) individually calls
  `Auth::requireApi()` at the top — returns a JSON 401/403 instead of a redirect, so
  `fetch()` calls fail cleanly instead of receiving an HTML login page as "data".
- `login.php`, `logout.php`, `change_password.php` are the only routes NOT gated behind
  a check that would create a redirect loop.
- **No role-based access control exists.** Every logged-in user can reach every module.
  The only per-user permission model in the whole app is the Credentials Vault's
  explicit share list (Section 5, Server Inventory).
- Session also caches (set at login, refreshed on avatar upload) display-only profile
  fields — `position_title`, `department_name`, `email`, `profile_picture_path` — to
  avoid a DB join on every single page load just to render the topbar.

### Important technical patterns
- **CSRF protection:** every state-changing request must include an `X-CSRF-Token`
  header (or, for plain HTML form posts like `login.php`, a hidden `csrf_token` field),
  checked via `lib/helpers.php::require_csrf()` / `csrf_verify()` against
  `$_SESSION['csrf_token']`.
- **PDO everywhere, parameterized, `ATTR_EMULATE_PREPARES => false`.** This last setting
  is the source of a recurring, easy-to-reintroduce bug class — see Section 10 / 11.
- **`pg_bool()` helper** (`lib/helpers.php`) — REQUIRED wrapper any time a PHP boolean is
  bound into a PDO parameter array destined for a Postgres boolean column. Raw PHP
  `false` binds as an empty string `''`, and native Postgres prepares reject that as an
  invalid boolean literal. `to_bool()` (returns a real PHP bool) is for logic; `pg_bool()`
  (returns the string `'true'`/`'false'`) is for anything going into `->execute()`.
- **Engine-agnostic schema introspection** (`lib/SchemaIntrospector.php`) normalizes
  PostgreSQL and MySQL/MariaDB `information_schema` differences behind one API
  (`listSchemas()`, `listTables()`, `getColumns()`), returning identical lowercase-keyed
  arrays regardless of source engine.
- **N+1 query avoidance:** hierarchical/list endpoints (Org tree, Call Directory
  department-contact batching, etc.) always fetch each "level" in one query and assemble
  the tree in PHP — never one query per parent row. This was a deliberate performance
  fix (see Section 8).
- **File-streaming endpoints** (`*_export.php`, `download.php`, `rdp_download.php`)
  choose `Auth::requirePage()` (redirect-style, for direct links/new-tab opens) vs.
  `Auth::requireApi()` (JSON-error-style, for `fetch()`-driven downloads) based on how
  the frontend actually invokes them.
- **Logger** (`lib/Logger.php`): structured JSON-line logs, one file per day
  (`logs/app-YYYY-MM-DD.log`), written automatically by `json_error()` and by global
  `set_exception_handler`/`set_error_handler` in `config.php`. Viewable/searchable/
  downloadable/clearable via the Error Logs module. The `logs/` folder is denied direct
  web access via `.htaccess`.

---

## 4. Database

All tables live in a PostgreSQL schema named **`toolkit`**. `database/schema.sql` is the
full fresh-install script; `database/migration_002.sql` through `migration_012.sql` are
additive, re-runnable migrations for upgrading an existing install (there is no
`migration_001` — `schema.sql` itself is the baseline). **Always run migrations in
numeric order.**

### Tables (grouped by feature)

**Data Dictionary**
- `dd_tables` (table_id PK; **source_database** VARCHAR(150) DEFAULT 'Manual Entry' —
  groups the UI display by originating DB; schema_name; table_name; display_name;
  description; business_owner; source_system; is_active; UNIQUE(source_database,
  schema_name, table_name))
- `dd_columns` (column_id PK; table_id FK→dd_tables CASCADE; column_name;
  ordinal_position; data_type; char_length; numeric_precision; numeric_scale;
  is_nullable; is_primary_key; is_foreign_key; **is_auto_increment** [real field, not
  cosmetic — detected via Postgres `nextval()` default heuristic or MySQL `EXTRA`
  column]; fk_ref_table; fk_ref_column; default_value; business_rule; description;
  sensitive_flag; UNIQUE(table_id, column_name))
- `dd_connections` (connection_id PK; label; db_engine CHECK IN
  ('postgresql','mysql'); host; port; username; password [**stored plaintext** — local
  tool tradeoff, documented, not encrypted]; database_name; default_schema) — saved
  connection profiles for "Import from DB"

**Diagram Generator**
- `diagrams` (diagram_id PK; diagram_type CHECK IN ('ERD','DFD','SFD','PFD');
  diagram_name; description)
- `diagram_nodes` (node_id PK; diagram_id FK CASCADE; node_key [unique per diagram];
  label; node_subtype; detail; pos_x/pos_y; width/height; fill_color;
  parent_node_key [for ERD attribute-under-entity nesting]; UNIQUE(diagram_id, node_key))
- `diagram_edges` (edge_id PK; diagram_id FK CASCADE; source_node_key; target_node_key;
  label; edge_style DEFAULT 'line'; cardinality)

**Organization structure (owned by Org & Users; consumed read-only by Call Directory)**
- `org_groups` (group_id PK; group_name UNIQUE; description)
- `org_divisions` (division_id PK; group_id FK CASCADE; division_name; description;
  UNIQUE(group_id, division_name))
- `org_departments` (department_id PK; division_id FK CASCADE; department_name
  VARCHAR(200); description; UNIQUE(division_id, department_name))
- `org_units` (unit_id PK; department_id FK CASCADE; unit_name; description;
  UNIQUE(department_id, unit_name))
- `org_positions` (position_id PK; unit_id FK CASCADE; position_title; description;
  UNIQUE(unit_id, position_title))

**Call Directory (department-level contact info — NO named individuals; see Section 8
Decision D-3)**
- `department_emails` (email_id PK; department_id FK CASCADE; email)
- `department_numbers` (number_id PK; department_id FK CASCADE; phone_number; label
  DEFAULT 'Local')

**Users / Authentication**
- `users` (user_id PK; **employee_id** UNIQUE [login identifier]; password_hash;
  last_name; first_name; middle_name; group_id/division_id/department_id/unit_id/
  position_id — all FK **ON DELETE RESTRICT** [an org node with active users attached
  can't be silently deleted]; email; is_active; **must_change_password** DEFAULT TRUE;
  password_changed_at; failed_login_attempts DEFAULT 0; is_locked; locked_at;
  **profile_picture_path** [only self-editable field])
- `system_settings` (id SMALLINT PK DEFAULT 1, `CHECK (id = 1)` — enforces a single
  row; default_password DEFAULT 'Welcome@12345'; password_expiry_days DEFAULT 90;
  max_login_attempts DEFAULT 5; smtp_host/port/username/password/encryption/
  from_email/from_name)

**Access Links** (simplified — categories/favorites were removed, see Section 8
Decision D-4)
- `bookmarks` (bookmark_id PK; title; url; description; tags [comma-separated])

**Server Inventory**
- `server_groups` (server_group_id PK; group_name UNIQUE; description)
- `servers` (server_id PK; server_group_id FK SET NULL; environment [fixed dropdown:
  Dev, Prod, SIT, UAT, SAT, "SIT & UAT", "UAT & SAT", "SIT, UAT & SAT"]; **hosting_type**
  [AWS | On-Premise]; **server_type** [App Server | DB Server | App & DB Server];
  server_name; ip_address; server_url; os_platform; cpu_cores; memory_gb; storage_gb;
  notes; is_active; UNIQUE(server_name, environment))
- `hosted_systems` (hosted_system_id PK; server_id FK CASCADE; system_name; version;
  port; **current_environment** VARCHAR(150) [multi-select, comma-joined string; base
  options: Dev, SIT, UAT, SAT, Prod, DR, "Non-Prod(DR)" — a DIFFERENT, simpler list than
  the server's own environment dropdown]; **repository_url** [GitHub link]; description)
- `group_systems` (group_system_id PK; server_group_id FK CASCADE; system_name;
  current_environment [same multi-select]; repository_url; description)
- `server_access_protocols` (protocol_id PK; server_id FK CASCADE; protocol [RDP | SSH
  | FTP | WinRM | Other]; port; notes)
- `server_credentials` (credential_id PK; server_id FK CASCADE; label; username;
  **password_encrypted** [AES-256-CBC via `lib/Crypto.php`, never plaintext]; created_by
  FK→users SET NULL [audit trail: who created it])
- `server_credential_shares` (share_id PK; credential_id FK CASCADE;
  shared_with_user_id FK→users CASCADE; shared_by_user_id FK→users SET NULL;
  UNIQUE(credential_id, shared_with_user_id)) — explicit per-user access grants
- `expiry_items` (expiry_id PK; item_type CHECK IN ('SSL Certificate','License','Other
  Service'); item_name; server_id FK SET NULL [optional]; hosted_system_id FK SET NULL
  [optional]; issued_date; expiry_date; alert_days_before DEFAULT 30; notes)

### Relationships summary
`org_groups 1—N org_divisions 1—N org_departments 1—N org_units 1—N org_positions`.
`org_departments 1—N department_emails/department_numbers`. `users` has FIVE separate
nullable FKs into the org hierarchy (group/division/unit/position are each stored
directly on the user row, not derived only through department, to avoid deep joins for
display — some denormalization is intentional). `server_groups 1—N servers`;
`servers 1—N hosted_systems/server_access_protocols/server_credentials`;
`server_groups 1—N group_systems`; `server_credentials 1—N server_credential_shares`.

### Important queries / patterns
- Org tree (`modules/usermgmt/api.php`, action `tree`): **exactly 5 queries** (groups,
  divisions, departments, units, positions), assembled into a nested structure in PHP —
  not N+1.
- Call Directory department-contact batching (`modules/directory/api.php`): **2
  queries** (`WHERE department_id IN (...)`) regardless of how many departments are
  displayed — not one query per department.
- Data Dictionary's "Import ALL Tables" runs one transaction per table (not one giant
  transaction), so one bad table doesn't block the rest of the import.

### Database decisions already made
- Single PostgreSQL schema (`toolkit`) for the whole app; MySQL is introspection-target
  only, never the app's own store.
- `ON DELETE RESTRICT` on `users`' org FKs (protective — can't delete an org node with
  users still attached); `ON DELETE CASCADE` almost everywhere else (child records are
  meaningless without the parent); `ON DELETE SET NULL` used for optional/soft links
  (e.g. `expiry_items.server_id`, `servers.server_group_id`, `server_credentials.
  created_by`).
- `dd_connections.password` and `dd_connections` in general: plaintext by design,
  documented tradeoff for a local tool (contrast with `server_credentials`, which IS
  encrypted — the difference is intentional: DB *documentation* connections vs. actual
  server *login* credentials).
- Environment fields are deliberately NOT DB-enforced with a `CHECK` constraint (only
  validated in the API layer against a PHP array) specifically so that older/other
  values already in the database from before a dropdown list changed don't break.

---

## 5. Features

### Data Dictionary — **COMPLETED**
Manual CRUD for table/column documentation, grouped and displayed **by originating
database** (collapsible accordion, **collapsed by default** — "only view what I want to
view" was an explicit requirement; searching auto-expands matches; expand/collapse
state persists across edits via a JS `Set`).
**Import from DB:** full connection picker (engine PostgreSQL/MySQL, host, port,
user, password, database, schema) — no more hardcoded single target. Can save/reuse
named connection profiles (`dd_connections`). **Import pulls ALL tables in a schema in
one click** (not one-at-a-time), with a per-table success/fail results list.
**Per-database Excel export** ("ANNEX E – Data Dictionary" template, replicated
cell-by-cell from a user-supplied reference file): title banner, dark-blue `#1F4E78`
header row, Table Name column merged/shaded per table group, hairline borders, exact
column widths, columns = Table Name | Field Name | Datatype/Size | Null | PK | FK |
Auto Increment | Description.
*Known limitation:* export/import features require `composer install` to have been run.

### Diagram Generator — **COMPLETED**
ERD / DFD / SFD / PFD diagrams, custom drag-and-drop canvas editor (basic — no
zoom/multi-select). Exports valid yEd GraphML (`lib/GraphMLBuilder.php`) — ERD entities
render as yEd group nodes with nested attribute rows; DFD/SFD/PFD use appropriate yEd
shapes per node subtype. Also supports **Excel export/import** of a diagram's nodes and
edges (bulk-edit in Excel, re-import replaces the diagram's contents — a destructive
"full replace," not a merge, by design).

### Call Directory — **COMPLETED** (redesigned twice — see Section 8)
Final form: **department-level shared contact cards, no named individuals.** Each
department card shows title, description, multiple copy-to-clipboard **Email** entries,
multiple copy-to-clipboard **Telephone/Local Number** entries (each with a label:
Local/Mobile/Hotline). Search across all departments by name/email/number. **Owns no
structural CRUD** — Group/Division/Department creation/editing happens in Org & Users;
Call Directory is a pure read-only consumer of Department records, only managing the
attached contact info itself.

### Access Links — **COMPLETED** (simplified — see Section 8)
Just Title, URL, Description, Tags — shown as a searchable card grid, no
categories/favorites (removed by explicit request).

### Server Inventory — **COMPLETED**
- **Server Groups** (cluster of servers across environments) with their own **Group
  Systems** CRUD.
- Per-server **Hosted Systems** CRUD.
- Servers capture: fixed-dropdown **Environment** (8 values, see Section 4), **Hosting**
  (AWS/On-Premise), **Server Type** (App Server/DB Server/App & DB Server).
- Hosted/Group Systems have a **multi-select "Current Environment"** (pill-style
  checkboxes; 7 base values, see Section 4 — deliberately a different/simpler list than
  the server dropdown) and an optional **Repository URL** (GitHub link).
- **Access Protocols** per server (RDP/SSH/FTP/WinRM/Other) with an auto-built copyable
  connection string per protocol, and a **one-click `.rdp` file download** for RDP
  entries (`modules/servers/rdp_download.php`).
- **Credentials Vault:** AES-256-CBC encrypted passwords (`lib/Crypto.php`), creator
  audit trail (`created_by`), and **explicit per-user sharing** — only the creator or an
  explicitly-shared user can decrypt/reveal a password (enforced server-side in
  `reveal_credential`, not just hidden in the UI).
- **Expiry Tracking:** page-wide table (not server-scoped) for SSL certs/licenses/other
  services, optionally linked to a server and/or hosted system, color-coded by days
  remaining (red = expired, amber = within its configurable `alert_days_before` window,
  green = fine). No automated notifications — visual only, computed at page load.

### Program Inventory — **COMPLETED** (redesigned once — see Section 8/11, critical fix)
Two scan modes, since the app is centrally hosted with remote users:
- **"Scan My Computer" (default):** reads a folder on the *user's own machine* via the
  browser's File API (`<input type="file" webkitdirectory>`). Only file metadata
  (name, relative path, size, last-modified) is sent to the server as JSON — **file
  contents are never uploaded.** Server builds and streams back the .xlsx; JS receives
  it as a Blob and triggers the download.
- **"Scan Server Path":** the original server-side `RecursiveDirectoryIterator` scan,
  kept for the legitimate case of cataloging an application actually deployed on the
  server itself.
Both share one rendering library (`lib/ProgramInventoryReport.php`) for identical
output. 11 fixed columns exactly as specified (ID, File Name, File Size (KB), File Type,
Date Modified, System Affected, File Version, Programmer/Developer, Path, Interface
(Action/System), Remarks). ID auto-increments as `<Initials>-00001` (initials derived
from each word's first letter in "System Affected", e.g. "BMI Online Clearance" →
`BOC`). File size as real numeric cells formatted `#,##0.00`. Date format
`MM-DD-YYYY hh:mm AM/PM`. Styling: gridlines on, dark-blue `#1E3A8A` header w/ bold
white Segoe UI, thin borders, zebra striping `#F8FAFC`, specified column alignments,
auto-sized columns. Configurable exclude-folder list (default `.git, node_modules,
vendor`) prunes those trees **before** descending into them (both scan modes).

### Org & Users — **COMPLETED**
Single source of truth for the 5-level hierarchy (Group → Division → Department →
Unit → Position), one collapsible tree with full CRUD at every level, parent-child
integrity via FKs. **User management:** Employee ID, Last/First/Middle Name, cascading
Group→Division→Department→Unit→Position dropdowns (each level's options load based on
the parent picked), Email, Enable/Disable toggle. New user's password defaults to the
org-wide **Default Password** (Parameters page) unless a custom one is typed; if an
email is on file, credentials are emailed automatically (best-effort — see Parameters).
Table shows **Must Change Pwd** / **🔒 Locked** status badges with an **Unlock** action.

### Parameters — **COMPLETED**
- **Password policy:** Default Password (used for new accounts), Password Expiry (days;
  0 disables), Max Login Attempts (before auto-lock).
- **SMTP settings:** host/port/username/password/encryption(none|tls|ssl)/from-address,
  with a **Send Test Email** button.

### Authentication & Password Policy — **COMPLETED**
- Login by Employee ID + password; generic error message either way (no user
  enumeration).
- **Account lockout:** tracked via `failed_login_attempts`; hitting
  `max_login_attempts` sets `is_locked = true` — checked *before* password
  verification, so a locked account is rejected even with the correct password.
- **Password expiry:** computed at login time from `password_changed_at` +
  `password_expiry_days` — no cron/background job.
- **Forced password change:** every new account (including the seeded admin) has
  `must_change_password = true` until changed via `change_password.php`. Existing
  accounts from before this feature was added (via migration) are NOT retroactively
  forced.
- **Account-creation email:** `lib/Mailer.php` (PHPMailer wrapper), styled HTML template
  (branded header, credentials box, security-notice callout, CTA button, footer) plus a
  plain-text alt body. Never blocks user creation if SMTP fails/isn't configured.
- Default seed account: **Employee ID `admin`, password `Admin@12345`** — forced to
  change on first login.

### Topbar User Menu — **COMPLETED**
Moved logout out of the sidebar into a topbar dropdown (avatar/name trigger). Topbar
also shows a live clock and the user's Position. Dropdown contains: read-only info card
(avatar, name, Employee ID, Position, Department, Email), **Change Profile Picture**
upload (the ONLY self-editable field — validated by actual image content via
`getimagesize()`, not just extension; 2MB cap; JPG/PNG/GIF/WEBP), a light/dark theme
toggle switch, and Logout. Session profile cache is refreshed immediately after upload.

### Error Logs — **COMPLETED**
Structured JSON-line daily log files, in-app viewer with level/text filtering, expandable
context/stack traces, download raw file, clear a file. Automatically fed by
`json_error()` and global PHP exception/error handlers.

### Appearance / Theming — **COMPLETED**
Skydash-inspired palette (indigo/blue/purple/coral), CSS custom properties for
light/dark themes, dark mode toggle persisted via `localStorage`, applied before paint
(no flash of wrong theme) via a blocking `<script>` in `<head>`.

### Performance work — **COMPLETED**
- Fixed a real N+1 pattern in the org tree endpoint (was N+1 queries, now fixed at 3,
  later 5 after Units/Positions were added).
- gzip compression (`mod_deflate`) + browser caching headers (`mod_expires`) for
  CSS/JS via `.htaccess`.
- Explicitly did NOT add jQuery (see Section 11) — kept vanilla `fetch()`.

---

## 6. UI/UX

**Design system / palette (Skydash-inspired):**
- Primary: `#4B49AC` (indigo)
- Primary light: `#98BDFF`
- Accent blue: `#7DA0FA`, accent purple: `#7978E9`, accent coral: `#F3797E`
- Status colors: green `#1f9d55`, red `#d64545`, amber `#c8860d`
- Defined as CSS custom properties under `:root` (light theme) and overridden under
  `[data-theme="dark"]` (dark theme) in `assets/css/app.css`

**Typography:** App UI uses system font stack (`-apple-system, "Segoe UI", Roboto,
Arial, sans-serif`). Generated Excel exports specifically use **Segoe UI** (data
dictionary/program inventory templates) and **Consolas** (ANNEX E title banner) to match
the reference templates supplied by the user.

**Layout:** Fixed left sidebar (nav + brand), top bar (page title/subtitle on the left;
clock + position + user-menu dropdown on the right), scrollable content area. Modals
are overlay-style (`.modal-overlay` / `.modal`), consistent across all modules.

**Key reusable components:**
- `.card` — the base content container used everywhere
- `.badge` (+ color variants) — status/tag pills
- `.env-checkbox-group` — pill-style multi-select checkboxes (Server Inventory system
  environments)
- `.contact-pill` — Call Directory email/number rows with inline copy button
- `.dept-card` — Call Directory department card layout
- `.quicklink-card` — Dashboard's read-only rectangular bookmark cards (no edit/delete
  buttons, whole card is a clickable link)
- `.user-menu` / `.user-menu-dropdown` / `.avatar` — topbar account menu
- `.theme-switch` — checkbox-styled dark-mode toggle (dropdown); a plain icon-button
  fallback (`#themeToggleBtn`) is kept on `login.php`/`change_password.php`, which don't
  have the full dropdown
- Accordion pattern (`.tree`, `.tree-node`, `.tree-toggle`) — used by both the Org tree
  and the Data Dictionary's per-database groups

**Dashboard layout (top to bottom):**
1. Greeting hero card (gradient), live clock, greeting text keyed to time of day and the
   ACTUAL logged-in user's name (not a generic placeholder)
2. Four colored stat tiles
3. Call Directory preview / Servers preview (two-column)
4. Quick Links (read-only cards) / Recent Diagrams (two-column)
5. System status strip with a link to Error Logs

**User flows worth knowing:**
- New user creation → optional auto-email of credentials → forced password change on
  first login → normal use.
- Data Dictionary: pick/save a DB connection → browse schema → "Import All Tables" →
  annotate business fields → export per-database Excel.
- Server Inventory: create a Server Group → add member Servers → add Hosted/Group
  Systems → optionally add Access Protocols and Credentials → optionally track
  Expiry items.
- Program Inventory: pick scan mode → (client mode) browser folder picker → fill in
  System Affected/Version/Developer/Excludes → generate → blob download.

**Responsive behavior:** NOT a stated priority at any point in the conversation; the
app was built and discussed purely as a desktop/browser tool. No explicit mobile
breakpoints or testing were done. Treat as **UNKNOWN/NEEDS CONFIRMATION** whether mobile
support matters.

---

## 7. Business Rules

- **ID generation (Program Inventory):** `<Initials>-00001`, initials = first letter of
  each whitespace-separated word in "System Affected", uppercased, concatenated (e.g.
  "BMI Online Clearance" → `BOC`). Sequence resets to 1 per export run, zero-padded to 5
  digits.
- **Program Inventory INTERFACE column** must always equal the SYSTEM AFFECTED value
  (hard rule, not user-editable per row).
- **File type** derivation: `pathinfo($name, PATHINFO_EXTENSION)`; if empty, falls back
  to the literal string `"FILE"`. Note: PHP's `pathinfo()` naturally returns e.g.
  `"htaccess"` for `.htaccess` (matches the reference template's observed behavior with
  no special-casing needed).
- **Password rules:** minimum 8 characters everywhere a password is set (create, reset,
  change). New password must differ from the current one (checked in
  `change_password.php`).
- **Account lockout math:** `failed_login_attempts` increments on wrong password; when
  it reaches `system_settings.max_login_attempts`, `is_locked = true` is set
  atomically in the same query. A correct login resets the counter to 0.
- **Password expiry math:** expired if `password_changed_at IS NULL` OR
  `(NOW() - password_changed_at) > (password_expiry_days * 1 day)`.
  `password_expiry_days <= 0` disables expiry entirely.
- **Credentials Vault access rule:** a password can be revealed only by
  `created_by = current_user` OR an existing row in `server_credential_shares` for
  `(credential_id, current_user)`. Enforced server-side in the reveal/edit/delete/share
  actions — never just a UI-level hide.
- **Self-service profile editing:** a logged-in user may change ONLY their own profile
  picture. Name, position, department, email, employee ID are admin-managed exclusively
  via Org & Users — there is no self-service edit form for those fields anywhere.
- **Org deletion protection:** deleting a Group/Division/Department/Unit/Position that
  still has users assigned to it via the `users` table's `RESTRICT` FKs will fail at the
  database level (by design — prevents silently orphaning user records).
- **Bookmark/Call Directory/Server Inventory** all use case-insensitive (`ILIKE`)
  search across their relevant text columns.
- **Diagram Excel re-import is destructive:** importing a spreadsheet into an existing
  diagram **replaces** all of that diagram's nodes/edges — it is not a merge.

---

## 8. Important Decisions

**D-1 — No CDN, ever; hand-roll CSS/JS instead of vendoring jQuery/Bootstrap.**
*Why:* explicit "zero online dependencies" requirement, plus the build environment had
no internet access, so a real copy of jQuery/Bootstrap could not be fetched — and
reproducing a large third-party library from memory was rejected as both inaccurate and
a licensing risk. Vanilla `fetch()`-based AJAX was already functionally equivalent and
lighter.

**D-2 — `composer install` is an acceptable one-time exception to "offline only."**
*Why:* installing a local PHP library once (like any dependency) doesn't compromise the
app's ability to run fully offline afterward. Applied to PhpSpreadsheet and PHPMailer.

**D-3 — Call Directory has NO named individuals; contacts are department-level shared
info (multiple emails + multiple phone numbers per department).**
*Why:* explicit user request, based on a reference screenshot showing exactly this
pattern (a department card with pooled Email/Telephone entries, no person's name). This
fully replaced an earlier Group→Division→Department→**Personnel** design that had
individual employee records with name/title/email/phone — that table (`org_personnel`)
was dropped entirely.

**D-4 — Access Links simplified to Title/URL/Description/Tags only; no categories, no
favorites.**
*Why:* explicit "simplify" request; matching card display like Call Directory's cards.

**D-5 — Server/system Environment fields are fixed dropdowns, not free text, and use
TWO DIFFERENT option lists depending on context.**
*Why:* explicit requirement for exact, closed value sets. Servers themselves use an
8-value list including combos (`Dev, Prod, SIT, UAT, SAT, SIT & UAT, UAT & SAT, SIT,
UAT & SAT`). Hosted/Group **Systems'** "Current Environment" is a **separate, simpler
7-value list** (`Dev, SIT, UAT, SAT, Prod, DR, Non-Prod(DR)`) used as a **multi-select**
(a system can run in several environments simultaneously) — explicitly NOT the same
list or input style as the server dropdown.

**D-6 — Server Credentials are encrypted (AES-256-CBC); Data Dictionary connection
passwords are NOT.**
*Why:* explicit "secure vault" requirement for server login credentials specifically.
DB documentation connection passwords were treated as a lower-stakes, already-existing,
documented tradeoff for a local/internal tool and were never asked to be hardened.

**D-7 — Credentials sharing is per-user only; no roles/permissions system exists.**
*Why:* the app never built RBAC at any point; "share with specific users/roles" was
implemented as user-level sharing only, since roles don't exist to share with.

**D-8 — User self-service is limited to profile picture only.**
*Why:* explicit instruction: "The only part a user can modify is picture." Name,
position, department, email remain admin-only fields, edited exclusively via Org & Users.

**D-9 — Organization structure CRUD (Group/Division/Department) moved OUT of Call
Directory and INTO Org & Users; Call Directory becomes a pure consumer.**
*Why:* explicit "move Organization Setup away from Call Directory, Call Directory
adopts it" request, once Org & Users (with its deeper Unit/Position tiers) existed as a
more natural home for structural management. **This superseded the original design**
where Call Directory owned Group/Division/Department CRUD directly.

**D-10 — Program Inventory needed a client-side (browser File API) scan mode as the
DEFAULT, with server-path scanning kept only as a secondary option.**
*Why:* explicit correction — "the system is hosted in a server, users access it on
their local machine" — made clear that PHP (server-side) scanning could never see a
remote user's own disk. **This superseded the original single-mode design** that
assumed the app and the files-to-scan were on the same machine.

**D-11 — `pg_bool()` must wrap every boolean destined for a PDO bound-parameter array.**
*Why:* discovered as a recurring real bug (see Section 10/11) caused by
`ATTR_EMULATE_PREPARES => false` combined with PHP's implicit `false → ''` string
cast. Documented as a hard rule for all future code in this codebase.

**D-12 — UPDATE statements must never bind array keys that aren't actual placeholders
in that specific SQL string.**
*Why:* same `ATTR_EMULATE_PREPARES => false` setting makes native Postgres prepares
reject any *extra* unused bound parameter, not just missing ones. Fixed in five
locations by building a filtered copy of the array (`$updateFields`) before `execute()`
rather than reusing the INSERT-shaped array. See Section 10/11 for the full list.

---

## 9. Current Implementation

**Directory layout (`C:\wamp64\www\AnalystToolkit\`):**
```
.htaccess, index.php, login.php, change_password.php, logout.php, composer.json
config/            config.php, Database.php
lib/               helpers.php, Auth.php, Logger.php, Mailer.php, Crypto.php,
                   GraphMLBuilder.php, SchemaIntrospector.php,
                   ProgramInventoryReport.php, vendor_bootstrap.php
includes/          header.php, footer.php
assets/            css/app.css, js/app.js
uploads/avatars/   (.htaccess disables PHP execution here)
database/          schema.sql, migration_002.sql .. migration_012.sql
modules/
  datadict/        index.php, api.php, excel_export.php
  diagrams/        index.php, api.php, graphml_export.php, excel_export.php,
                   excel_import.php
  directory/       index.php, api.php
  bookmarks/       index.php, api.php
  servers/         index.php, api.php, rdp_download.php
  account/         upload_avatar.php
  usermgmt/        index.php, api.php
  settings/        index.php, api.php
  proginventory/   index.php, export.php, export_client.php
  logs/            index.php, api.php, download.php
```

**Important functions/classes and where they live:**
- `Database::getConnection('app'|'target')` — PDO singleton, `ATTR_EMULATE_PREPARES
  => false` (this setting is the root cause behind D-11/D-12 — do not remove without
  re-auditing every UPDATE statement in the codebase)
- `Auth::attempt()/check()/mustChangePassword()/refreshProfileCache()/user()/
  logout()/requirePage()/requireApi()`
- `helpers.php`: `json_response()`, `json_error()` (also logs via `Logger`),
  `csrf_token()/csrf_verify()/require_csrf()`, `h()` (HTML-escape), `clean_str()`,
  `to_bool()`, `pg_bool()`
- `Crypto::encrypt()/decrypt()` — AES-256-CBC, key = `hash('sha256', APP_ENCRYPTION_KEY,
  true)`, random IV prepended to ciphertext, base64-encoded for storage
- `SchemaIntrospector` — `listSchemas()`, `listTables()`, `getColumns()`
- `GraphMLBuilder` — builds yEd-compatible XML per diagram type/subtype
- `ProgramInventoryReport.php` — `pi_derive_initials()`, `pi_build_row()`,
  `pi_stream_workbook()` (shared by both Program Inventory scan modes)
- `Mailer::sendAccountCreatedEmail()/sendTestEmail()` — has its OWN private
  `ensureAutoloaded()` (returns bool) rather than reusing the shared
  `require_vendor_autoload()` helper, specifically so a missing vendor dir degrades
  gracefully instead of aborting the whole user-creation request (see Section 11)

**Code patterns to follow when extending:**
- Every new `api.php` action: `Auth::requireApi()` at top, `require_csrf()` for non-GET,
  parameterized PDO, `json_response()`/`json_error()` for output.
- Any new UPDATE statement: build the bound-params array to contain **exactly** the
  placeholders present in that UPDATE's SQL string — no more, no less. If reusing an
  array built for INSERT, copy it and `unset()` the extra keys first.
- Any new boolean going into a bound-params array: wrap with `pg_bool()`.
- New file-streaming/export endpoint: decide `requirePage()` vs `requireApi()` based on
  whether the frontend opens it as a direct link/new tab vs. a `fetch()` call.
- New "which environment" field: check whether it's a *server* (8-value single-select)
  or a *system* (7-value multi-select) context — they are intentionally different.

---

## 10. Current Problems

*(Latest known state of each — none reported as actively broken at handoff; these are
gaps/limitations, not confirmed live bugs.)*

- **No RBAC / roles system.** Every logged-in user can reach every module and every
  action. Credentials Vault sharing is the only per-user permission concept in the app.
  If the organization needs tiered admin/analyst permissions, this is unbuilt.
- **`APP_ENCRYPTION_KEY` ships as a placeholder** in `config.php` — the Credentials
  Vault's encryption is only as strong as this being changed to a real random secret
  before use. Nothing in the code *forces* this change (it's a README/comment
  instruction, not a runtime check).
- **No automated Expiry Tracking alerts.** Color-coding is computed only when the page
  is loaded; there is no email/notification when something crosses its alert window
  while nobody is looking at the page (no cron/scheduled task infrastructure exists).
- **`display_errors` is ON** in `config/config.php` — fine for the current
  development/internal phase, but should be turned off before any broader/production
  exposure.
- **No pagination anywhere** — Users list, Servers list, Data Dictionary tables, Error
  Logs, etc. all load their full result set client-side. Not yet a reported problem, but
  a latent scaling risk.
- **No automated tests, no CI/CD.** All verification during development was manual:
  custom Python scripts counting braces/parens for PHP structural sanity, and
  `node --check` on extracted `<script>` blocks for JS syntax (there is no real PHP
  linter available in the build environment). **Known false-positive:** this brace/paren
  checker misfires on `lib/SchemaIntrospector.php` specifically, because apostrophes
  inside code comments (e.g. "MySQL's") confuse its naive string-stripping regex — this
  file was manually verified correct; don't be alarmed by a mismatch report there
  specifically, but don't blindly trust the checker on new files either.
- **Program Inventory client-mode has no explicit file-count/size cap.** A very large
  folder selection could produce a large JSON metadata payload. Not tested against a
  concrete upper bound.
- **Responsive/mobile layout is unaddressed** — desktop-only design and testing
  throughout (see Section 6).

---

## 11. Previous Failed Approaches

1. **Single hardcoded target-DB connection for Data Dictionary's "Import from DB."**
   Inflexible — replaced entirely with a connection picker + saved profiles
   (`dd_connections` table, supports PostgreSQL and MySQL/MariaDB).

2. **Loading `app.js` in the page footer.** Standard-looking pattern, but broke because
   several modules' own inline `<script>` blocks called `AT.*` functions synchronously
   at top-level (not inside a later-fired event handler) and executed *before* the
   footer-loaded `app.js` had defined `AT` at all — a `ReferenceError: AT is not
   defined` that silently killed page initialization on multiple pages (Data
   Dictionary, Access Links, Server Inventory all showed symptoms of this same root
   cause under different reported bug descriptions). **Fixed by moving the `app.js`
   `<script>` tag into `<head>`**, immediately after the CSRF `<meta>` tag it depends on.

3. **A stray `var q;` redeclaring an existing `const q`** inside Call Directory's page
   script. This is a `SyntaxError`, which (unlike a runtime error) prevents the ENTIRE
   surrounding `<script>` block from parsing at all — every function in that file,
   including the one the user reported as "not defined," was a casualty of this single
   stray line elsewhere in the same file. Lesson: an "X is not defined" report can mean
   the whole script failed to parse, not that X specifically is missing — check for
   syntax errors in the *whole file* first.

4. **Program Inventory assumed as server-side-only filesystem scanning.** Built first
   with a `$basePathPrefix` PHP `RecursiveDirectoryIterator` scan, on the assumption the
   app and the "local" files to catalog were on the same machine. **This does not work**
   once it was clarified the app is centrally hosted and users are remote — PHP simply
   cannot see a remote browser's local disk. Corrected by adding a genuine client-side
   scan mode using the browser's File API, sending only metadata (not file contents) to
   the server. The server-path mode was kept, relabeled for its one legitimate remaining
   use case (cataloging code actually deployed on the server).

5. **Considered vendoring jQuery to satisfy an "ajax/jquery" phrasing in a request.**
   Rejected — no internet access in the build environment to fetch a genuine copy, and
   reproducing a large third-party library from memory was judged both likely-inaccurate
   and a licensing risk. Vanilla `fetch()` (already in use) was pointed out as already
   equivalent and lighter; the user accepted this reasoning.

6. **`Mailer.php` initially reused the shared `require_vendor_autoload()` helper**
   (which calls `json_error()` + `exit` when `vendor/autoload.php` is missing). This
   would have **aborted the entire user-creation HTTP request** just because email
   sending's dependency wasn't installed — clearly wrong, since a missing/failed email
   should degrade gracefully, not block account creation. Fixed with a private
   `Mailer::ensureAutoloaded(): bool` that returns false instead of exiting, letting
   `sendAccountCreatedEmail()` return a normal `['ok' => false, 'error' => ...]` result.

7. **Server `environment` was originally free text with an HTML5 `<datalist>`
   suggestion list** (to allow ad-hoc combos like "UAT/SIT"). Superseded once exact
   fixed dropdown values were specified — the field is now a strict `<select>`, and the
   `<datalist>` approach was removed for servers. (Note: this is different from D-5's
   system-level multi-select, which uses checkboxes, not a datalist or a single select.)

8. **First-pass UPDATE statements for `save_group_system`, `save_hosted_system`,
   `save_column` (Data Dictionary), `save_node`/`save_edge` (Diagrams)** all reused the
   same associative array that had been built with extra keys needed only for the
   INSERT branch (e.g. `server_group_id`, `server_id`, `table_id`, `diagram_id`). Under
   `ATTR_EMULATE_PREPARES => false`, PDO's native Postgres driver throws
   `SQLSTATE[HY093]: Invalid parameter number` for any bound key that isn't an actual
   placeholder in that specific SQL string. One instance was reported as a live bug;
   the other four were found proactively afterward by writing a small script to scan
   every `api.php` file's `$fields = [...]` blocks against their nearby `UPDATE ... SET`
   clauses. All five were fixed the same way: build a filtered copy of the array before
   `execute()`.

---

## 12. Requirements & Constraints

- **"Zero online dependencies"** for the deployed, running app — no CDNs, all CSS/JS
  self-hosted. `composer install` is the one accepted exception (one-time, offline
  after).
- **"No AI integrations."**
- **WAMP stack** — Apache, PHP, PostgreSQL. Confirmed real server PHP version:
  **8.1.26** — any new Composer dependency must resolve compatibly with this (use
  `composer.json`'s `config.platform.php` override, don't just bump version constraints
  blindly).
- **"The only part a user can modify [in their profile] is picture."** Hard rule — do
  not add self-service editing for name/position/department/email.
- **"The system is hosted in a server. Users are accessing it on their local machine."**
  Hard architectural fact — any future feature that seems to need "local" file/resource
  access must be evaluated against this: does it mean local to the SERVER or local to
  the USER'S BROWSER? Get this right before building (Program Inventory got it wrong
  once — see Section 11 #4).
- **Diagram exports must be valid, yEd-openable GraphML** — this was validated against
  real yEd semantics (group nodes, shape types per diagram type), not just "some XML."
- **CSRF token required on every state-changing request.**
- **Parameterized PDO only — never string-concatenated SQL** — maintained throughout,
  including in every new module added.
- **Never bind an unused array key into a PDO UPDATE call** (see D-12) and **always
  `pg_bool()` a boolean before binding it** (see D-11) — both are hard, established
  rules for this specific codebase's PDO configuration, not general PHP advice.
- **Environment value lists are exact and were dictated verbatim by the user** — do not
  "simplify" or merge the server 8-value list and the system 7-value list; they are
  deliberately different.
- **Call Directory: never reintroduce named-individual contact records** — the explicit
  design is shared department-level contact info only (superseded an earlier
  Personnel-based design; do not revert).
- **Org structure is single-sourced in Org & Users** — do not reintroduce structural
  CRUD (Group/Division/Department create/edit/delete) into Call Directory or any other
  consumer module.

---

## 13. Current TODO

*(No explicit backlog was dictated by the user beyond what's been completed. This list
is inferred from Section 10's open problems, roughly prioritized.)*

1. **Force a real `APP_ENCRYPTION_KEY`** — at minimum, add a startup check/warning if
   the config value still matches the shipped placeholder, before the Credentials Vault
   is used in earnest.
2. **Decide on and document a production `display_errors` posture** (currently on for
   dev convenience).
3. **Consider basic role-based permissions** if the organization needs tiered access
   (currently: authenticated = full access to everything).
4. **Add pagination** to the largest list views (Users, Servers, Data Dictionary tables)
   before they become a real performance problem.
5. **Decide whether Expiry Tracking needs real alerting** (email/notification) beyond
   in-page color coding, and if so, design a scheduled-task mechanism (none exists yet).
6. **Add an explicit cap/warning for very large Program Inventory client-mode scans**
   (file count and/or payload size).
7. **Set up automated testing** — none exists; all validation has been manual syntax
   checks plus user-reported runtime bugs.
8. **Evaluate mobile/responsive needs** — never addressed; confirm whether it matters
   for this user base before investing in it.

---

## 14. Next Recommended Step

Based strictly on the current state (a functionally complete, feature-rich internal
tool with no open bug reports at handoff, but several unenforced security/operational
loose ends): **the highest-leverage next step is hardening the Credentials Vault
encryption key handling** (TODO #1) — it's the one place where a default/placeholder
value silently undermines a feature explicitly built for security ("secure server
credentials vault"). This is small, self-contained, and directly protects the feature
most likely to hold sensitive data. After that, pagination (#4) and the
`display_errors` decision (#2) are the next most concrete, low-risk items.

---

## 15. Context for Another AI

- This is a **PHP 8.1.26 / PostgreSQL (schema `toolkit`) / WAMP** app with **no
  frontend framework** — vanilla JS (`AT` namespace in `assets/js/app.js`) and hand-
  rolled CSS. Do not introduce a JS/CSS framework or CDN assets without cause; the
  project has explicitly and repeatedly rejected that direction.
- **PDO is configured with `ATTR_EMULATE_PREPARES => false`.** This has caused real,
  repeated bugs (Section 10/11, items 8 and D-11/D-12). Before writing or reviewing ANY
  new SQL that binds a PHP array to a prepared statement: (a) every array key must be an
  actual placeholder in that exact SQL string, no extras; (b) every boolean must go
  through `pg_bool()`, never a raw PHP bool. This is the single most important
  codebase-specific gotcha to internalize.
- **The app is centrally hosted; users are remote.** Never assume "local" means local to
  the PHP server unless the feature is explicitly about the server's own filesystem
  (e.g., cataloging code deployed on the server itself). If a feature needs the *user's*
  local machine/files, it must go through the browser (File API, uploads, etc.) — PHP
  has no other way to reach it.
- **Org & Users owns the organizational hierarchy.** Any feature that needs
  Group/Division/Department/Unit/Position data should read from the existing tables via
  its own read-only queries, not duplicate CRUD for that structure elsewhere.
- **Call Directory has no concept of a named individual** — don't reintroduce a
  "Personnel" or "employee contact" table there; that was explicitly removed.
- **Two different environment value lists exist on purpose** (servers: 8-value
  single-select; systems: 7-value multi-select) — don't unify them.
- The **build/verification environment used throughout this project had no PHP CLI and
  no internet access.** All PHP "syntax checking" done during development was a
  hand-rolled brace/paren counter (with one known false-positive file — see Section 10)
  plus manual code review; all JS syntax checking used `node --check` on extracted
  `<script>` blocks. If your environment has a real PHP linter/interpreter, use it —
  it's strictly better than what was available here.
- **Every deliverable throughout this project was packaged and delivered as a full
  ZIP of the `AnalystToolkit` folder** after each change, alongside a numbered SQL
  migration file for any schema change (never edit `schema.sql` alone without also
  producing a corresponding `migration_0NN_description.sql` for upgrading existing
  installs — this pattern is expected to continue).
- No test suite, no CI, no staging environment were ever set up or discussed — treat
  their absence as the current, unremarkable status quo, not an oversight to
  immediately fix unless directed.

---

## 16. Audit Addendum — Details Missed in the First Pass

*(Added after re-auditing the full conversation against the document above. Organized
by which section each item supplements.)*

### Supplementing Section 2 (Stack) / Section 12 (Constraints) — Composer/PHP version issue
The PHP 8.1 vs. PhpSpreadsheet ≥8.2 conflict was a **real error the user hit**, not a
preemptive fix:
```
Composer detected issues in your platform: Your Composer dependencies require a PHP
version ">= 8.2.0". You are running 8.1.26.
```
Fix applied: added `"config": {"platform": {"php": "8.1.26"}}` to `composer.json` so
Composer resolves PhpSpreadsheet/PHPMailer sub-dependency versions compatible with the
real runtime, instead of pinning a guessed package version. **Recovery commands given
to the user** (needed any time `vendor/` was built against the wrong platform
assumption):
```
rmdir /s /q vendor
del composer.lock
composer install
```
If `APP_ENCRYPTION_KEY` or any other new Composer dependency is added later, re-check
this platform override still matches the real server's PHP version first.

### Supplementing Section 9 (Implementation) — how the seed admin password hash was made
The build sandbox had **no PHP CLI available at all** (confirmed via `which php` /
`apt-get install php-cli` both failing — no network access to install it either). The
seeded admin's bcrypt hash was generated with **Python's `crypt` module**
(`crypt.mksalt(crypt.METHOD_BLOWFISH)`), producing a `$2b$...` hash, then verified
self-consistent in Python. `$2b$` bcrypt hashes are fully compatible with PHP's
`password_verify()` (PHP treats `$2a$/$2b$/$2x$/$2y$` as equivalent bcrypt variants). If
a new default/seed hash is ever needed again in an environment without PHP available,
this is the proven fallback method — don't hand-roll a hash without verifying
cross-compatibility.

### Supplementing Section 3/9 — retrofitting Auth into existing modules
When authentication was added well after several modules already existed, `Auth::
requireApi()` was injected into **all six existing `api.php` files** (datadict,
diagrams, directory, bookmarks, servers, logs) via a **scripted patch** (a small Python
script inserting the `require` and the guard call at the right point in each file),
followed by a manual line-by-line review of each patched file — not hand-edited one at
a time. If a new module's `api.php` is added later, follow the same require-order
convention already established in the patched files: `config.php` → `Auth.php` →
`Database.php` → `helpers.php` → (module-specific requires) → `Auth::requireApi()` →
rest of the file.

### Supplementing Section 4 (Database) — schema.sql vs. migration file discipline
**Hard pattern established and followed throughout:** every schema change produced
BOTH an update to `schema.sql` (for fresh installs) AND a standalone, numbered,
re-runnable `migration_0NN_description.sql` (for upgrading an existing install).
Migrations never assume they run against a fresh DB — they use `ADD COLUMN IF NOT
EXISTS`, `CREATE TABLE IF NOT EXISTS`, etc. Quick index of what each migration does:

| Migration | Adds |
|---|---|
| 002 | `server_groups`, `servers.server_group_id`; relaxes `environment` to free text |
| 003 | `dd_connections` (saved DB connection profiles) |
| 004 | Drops `bookmark_categories`/`is_favorite` (simplifies `bookmarks`) |
| 005 | `dd_tables.source_database` (per-DB grouping) |
| 006 | `department_emails`, `department_numbers`; **drops `org_personnel`** |
| 007 | `dd_columns.is_auto_increment` |
| 008 | `org_units`, `org_positions`, `users`; seeds default admin |
| 009 | `system_settings`; `users.must_change_password/password_changed_at/failed_login_attempts/is_locked/locked_at` |
| 010 | `servers.hosting_type/server_type`; `hosted_systems`/`group_systems.current_environment` |
| 011 | `hosted_systems`/`group_systems.repository_url`; `server_access_protocols`, `server_credentials`, `server_credential_shares`, `expiry_items` |
| 012 | `users.profile_picture_path` |

This table should be kept up to date — add a row every time a new migration file is
created.

### Supplementing Section 5 — GraphML shape mapping (Diagram Generator)
`node_subtype` drives which yEd shape `GraphMLBuilder.php` emits, per diagram type:
- **ERD:** `entity` → a yEd **group node** containing nested **attribute** child rows
  (so an entity visually looks like a mini table with its columns inside it in yEd)
- **DFD:** `external_entity` → rectangle; `process` → rounded/circular shape;
  `data_store` → open-ended rectangle (parallel-line look)
- **SFD:** `system`/`component`/`database`/`interface` → distinct rectangle variants
  per subtype
- **PFD:** `start`/`end` → rounded terminator shapes; `decision` → diamond; `step` →
  plain rectangle
**Caveat:** this was built to match the documented yEd GraphML XML schema; it was
**never opened in an actual copy of yEd** to visually confirm correctness — treat exact
shape fidelity as unverified if a user ever reports a rendering issue in real yEd.

### Supplementing Section 5 — Server Inventory: exact connection-string templates
`buildConnectionString()` (client-side JS, `modules/servers/index.php`) produces:
- **RDP:** `mstsc /v:{ip}{:port if set}`
- **SSH:** `ssh <user>@{ip}{ -p {port} if set}` (username is always the literal
  placeholder `<user>` — the app doesn't store a "default SSH username" anywhere)
- **FTP:** `ftp://{ip}{:port if set}`
- **WinRM:** `Enter-PSSession -ComputerName {ip}{ -Port {port} if set}`

The one-click **`.rdp` file** (`modules/servers/rdp_download.php`) contains exactly:
```
full address:s:{ip}:{port}
prompt for credentials:i:1
administrative session:i:0
screen mode id:i:2
```

### Supplementing Section 5 — Credentials Vault: precise visibility rules
Clarifying what's gated vs. not (the original doc undersold this nuance):
- **Listing** a server's credentials (label, username, `created_by` name, `created_at`)
  is visible to **any logged-in user** who opens that server's detail view — this is
  NOT restricted to the creator.
- **Revealing** the decrypted password, **editing** (label/username/password), 
  **deleting**, and **sharing/unsharing** are ALL creator-only (or, for reveal only,
  also allowed for an explicitly shared user) — every one of these four actions does
  its own `created_by === current_user` (or share-table) check server-side and returns
  HTTP 403 otherwise. It is not just "reveal" that's gated.

### Supplementing Section 5 — Program Inventory: literal "config at top of script" requirement
The user's functional requirement text was explicit: *"Include clean configuration
variables at the top of the script for easy modification: System Affected, File
Version, Programmer/Developer, Base Path Prefix."* This was honored literally —
`modules/proginventory/export.php` opens with plain PHP variable assignments
(`$systemAffected`, `$fileVersion`, `$programmerDeveloper`, `$basePathPrefix`,
`$excludeFolders`) as the first executable lines, BEFORE any `require_once`. The web
form on `index.php` pre-fills from these same default values and can override every one
of them via POST — the config block is a default, not a hard-coded value. Keep this
pattern (literal top-of-file variables, not buried in a config array) if this file is
ever refactored, since it was a specific, explicit ask.

### Supplementing Section 5/9 — Program Inventory PATH column construction
- **Server-path mode:** `PATH` = the file's containing directory, relative to
  `$basePathPrefix`, backslash-separated, with a single leading backslash (e.g.
  `\BMIPORTAL\HROCS\application`). Computed by stripping the realpath'd base from the
  realpath'd containing directory.
- **Client (browser) mode:** derived from the File API's `webkitRelativePath` (which
  already includes the top-level selected folder's name), stripping the filename and
  converting `/` to `\`, with the same leading-backslash convention — kept intentionally
  identical in *shape* to server mode even though the underlying source differs, so the
  two modes' output is visually consistent.
- Folder exclusion is done by **exact folder-name match on any path segment**, not a
  glob/wildcard pattern — `.git`, `node_modules`, `vendor` (defaults) block any
  directory with that literal name anywhere in the tree. Server mode prunes via PHP's
  `RecursiveCallbackFilterIterator` (never descends into excluded folders at all);
  client mode filters in JavaScript **before** the metadata is even sent to the server
  (keeps the payload small for large trees like `node_modules`).

### Supplementing Section 7 (Business Rules) — additions
- **Program Inventory REMARKS column is always emitted blank** — it exists in the
  template for manual human annotation after the fact; the exporter never populates it
  programmatically, in either scan mode.
- **Date/time greeting format (Dashboard) is an exact literal spec:** the user gave the
  example `July 27, 1999 09:30 AM` (i.e., `Month D, YYYY hh:mm AM/PM`, 12-hour clock,
  zero-padded minutes, no zero-padded day). The dashboard's JS builds this manually
  (month-name lookup array + manual 12-hour conversion), not via a library.
- **Password-expiry detection is persisted, not just a session flag:** when
  `Auth::attempt()` determines a password is expired at login time (even though the
  DB's `must_change_password` was previously `false`), it **writes
  `must_change_password = TRUE` back to the `users` row** in the same request, not only
  into `$_SESSION`. This means an admin browsing the Users list will correctly see
  "Must Change Pwd" for a user whose password expired, even before that user's next
  login attempt fully completes.

### Supplementing Section 9 (Implementation) — a few omitted `AT` namespace functions and CSS variable names
`assets/js/app.js`'s `AT` object also exposes **`AT.copyText(text)`** (clipboard-copy
helper with a toast confirmation) — used throughout Call Directory (email/phone pills),
Server Inventory (connection strings, revealed credential values), and elsewhere. Any
new "copy to clipboard" UI should reuse this, not reimplement clipboard logic.

Theme CSS variables (`assets/css/app.css`) follow this naming convention (defined under
`:root`, overridden under `[data-theme="dark"]`): `--primary`, `--accent-blue`,
`--accent-purple`, `--accent-coral`, `--bg`, `--card`, `--border`, `--text`, `--muted`,
`--hover-bg`. The saved theme preference lives in `localStorage` under the key
**`at_theme`** (values: `"light"` / `"dark"`), applied to `<html data-theme="...">` by a
blocking inline script at the very top of `<head>` (before CSS paint) to avoid a flash
of the wrong theme.

Sidebar nav, in final order, with the exact emoji icons used (useful if grepping
`header.php`): 📊 Dashboard · 📖 Data Dictionary · 🗺️ Diagram Generator · 👥 Call
Directory · 🔗 Access Links · 🖥️ Server Inventory · 🗂️ Program Inventory · 🧑‍💼 Org &
Users · ⚙️ Parameters · 🪵 Error Logs.

### Supplementing Section 9 — avatar upload cleanup detail
`modules/account/upload_avatar.php` deletes any **previous** avatar file for the user
(via `glob('user_{id}.*')`-style matching, regardless of its old extension) before
saving the newly uploaded one — avoids orphaned image files accumulating in
`uploads/avatars/` across repeated picture changes.

### Supplementing Section 10 (Current Problems) — two additional known limitations
- **Auto-increment detection is heuristic, not authoritative.** Postgres detection
  relies on the column default containing `nextval(` — a hand-crafted default that
  happens to call `nextval()` on a sequence not actually used as a primary key /
  auto-increment source would be a (very unlikely) false positive. MySQL detection
  relies on the `EXTRA` column containing the substring `auto_increment` — reliable in
  practice, no known false-positive path.
- **GraphML/yEd output was never opened in an actual copy of yEd** during development
  to visually verify correctness (no yEd installation was available). It was built
  strictly to the documented XML schema. Treat visual fidelity in real yEd as unverified
  until someone confirms it.

### Supplementing Section 11 (Failed Approaches) — one clarification
Item 7 (server `environment` datalist → strict dropdown) should be read alongside D-5:
the **datalist removal only ever applied to the `servers` table's own environment
field.** The *systems'* ("Current Environment" on hosted/group systems) multi-select
checkbox UI was a **separate, later addition** — it was never a datalist at any point;
it was designed as checkboxes from the start once the multi-select requirement was
given.

### New: upload-directory hardening pattern (for any future file-upload feature)
`uploads/avatars/.htaccess` establishes the pattern to reuse for any future
user-upload feature: a folder that must serve files directly over HTTP (so `<img>` tags
work) but must **never execute code**, even if a malicious file is disguised with an
image extension. Technique used: `php_flag engine off`, an explicit `<FilesMatch
"\.(php|php\d?|phtml|phar)$"> Require all denied </FilesMatch>`, and `Options
-ExecCGI`. Combined with **content-based validation** at upload time
(`getimagesize()`, not filename/extension trust) in `upload_avatar.php`. Any new
upload feature should copy this pattern rather than reinvent it.

---

## 17. Post-handoff update: Requirements Traceability

Added a Requirements module (`modules/requirements/`) with requirement CRUD, owner,
status and priority, links to diagrams, Data Dictionary tables/columns, and hosted or
group systems. The Impact view finds requirements linked to a selected asset; selecting
a database table also includes links to its columns. Excel export produces a
traceability matrix with one row per requirement/asset link and includes unlinked
requirements. Spreadsheet cells are written as text to avoid formula execution.

`database/schema.sql` includes the new objects for fresh installs.
`database/migration_013_requirements_traceability.sql` upgrades existing installs;
it has been applied to the local `analyst_toolkit` database. The new link table uses
real foreign keys, an exactly-one-asset check, and partial unique indexes. PHP lint,
transactional database checks, and an Excel workbook smoke test passed. The database
test records were rolled back.

---

## 18. Post-handoff update: Code Review

Added `modules/codereview/` with browser-folder and server-project scanning,
standard diagnostics, security pattern checks, file metrics, type/date/name filters,
grouped findings, and XLSX/ODS export. Browser scans transmit source file contents to
the server for analysis but do not persist them. Scans are limited to 500 files,
256 KB per file, and 8 MB of source text. Server projects are limited to immediate
folders under the server web root; common generated and sensitive directories are
excluded. No database migration is needed. The supplied `codereview/` sample is
reference-only and denied direct HTTP access via its `.htaccess` file.

## 19. Post-handoff update: Source code inventory

The Code Review module now has a Source code inventory engine for PHP, PHTML,
and INC files. It tokenizes source without executing it and lists named functions
and methods with path, source code ID, sequential function ID, doc-comment
description, assigned variables, arguments, by-reference out parameters, and
return information. The matrix has search and 25-row pagination, and XLSX/ODS
exports include a Source Inventory sheet. Browser and server folder scans use
the existing read-only limits; the inventory is capped at 3,000 function rows.
Types inferred from assignments are estimates. No database migration is needed.

## 20. Post-handoff update: Organization and Users split

Organization structure now lives in `modules/organization/` with its own page,
API, and sidebar link. `modules/usermgmt/` now handles user accounts only and
reads organization options from the Organization API. Call Directory links to
Organization for structural changes. Organization deletions reject nodes with
children, department contact details, or assigned users; edits cannot silently
move a node to another parent. User saves validate the full selected hierarchy,
email and field lengths, and cannot disable the current account. The Users page
adds status filtering, quick enable/disable, and prevents duplicate account save
clicks. Session authentication rechecks status every 30 seconds to revoke access
for disabled, locked, or deleted accounts. No database migration is needed.

## 21. Post-handoff update: Dashboard and sidebar account menu

The dashboard now links its summary counts to the relevant modules, adds a work
queue for unlinked or unowned requirements and departments without contact details,
and shows requirement status distribution. Existing directory, server, bookmark,
and diagram previews remain. The user menu moved from the topbar to a fixed-height
sidebar footer; the navigation scrolls independently. On narrow screens the sidebar
becomes a horizontal navigation row. The account dropdown retains profile picture
upload, dark mode, and logout. No database migration is needed.

## 22. Post-handoff update: Dashboard visual refresh

The dashboard now follows the supplied light admin-dashboard reference: four white
summary cards with six-month SVG sparklines, a requirement status donut, monthly
creation bars, a recent requirements table, concentric coverage rings, saved links,
and recent diagrams. All counts and charts come from the application database.
The shared shell has a grouped sidebar, a compact header with quick links and a
working module search (Ctrl/Cmd+K), plus a sidebar toggle. The user menu stays in
the sidebar footer. The layout has responsive breakpoints and supports dark mode.
No database migration or external chart dependency is required. PHP lint and a
live-database render smoke check passed.

### Dashboard layout fixes

Shared CSS and JavaScript URLs now include a content version via `asset_url()` in
`lib/helpers.php`. Apache caches these files for a week, so versioned URLs are
required when their contents change. Header, footer, sign-in, and password pages
use the helper. The header wraps at tablet widths, cards and headings allow long
content, and the mobile account dropdown opens below its trigger. The requirements
availability check now respects the configured database search path. PHP lint and
headless Edge checks at 320, 390, 768, 820, 1024, and 1366 pixels passed, including
module search, sidebar toggling, account dropdown placement, and dark mode.

## 23. Post-handoff update: Public Call Directory and contact editor

`modules/directory/index.php` now supports public viewing with a dedicated guest
header, sign-in link, and footer. Authenticated users retain the normal application
shell and contact editor. Accounts requiring a password change can browse the
public view but cannot maintain contacts. The login page links to the directory.
The shared header and authentication gates for other modules are unchanged.

`assets/js/directory.js` supplies instant multi-field search (including phone
format normalization), group/division/contact-type filters, alphabetical sorting,
card/list views, 12-department pagination, shareable filter URLs, copy actions,
email links, telephone links for non-local numbers, and printing of all filtered
departments. The responsive layout supports dark mode and has loading, empty,
no-match, and retry states. The native contact dialog supports add/edit/remove
for emails and numbers, custom number labels, inline errors, and duplicate-submit
protection. It is omitted entirely from the guest page.

Only `list_departments` and `get_department` allow anonymous GET requests in the
directory API. Every write action requires `Auth::requireApi()`, POST, and CSRF.
Writes validate contact values and lengths, scope contact IDs to their department,
and lock the department while checking duplicates and saving. Public reads select
only organizational department fields and shared contacts, with three batched
queries for the list. Responses are not cached. No schema migration is needed.

Validation: PHP lint; production anonymous page/API reads and protected-page
redirects; isolated API tests covering all six write actions, CSRF, forced password
changes, validation, duplicates, CRUD, and department ownership; headless Edge
checks from 320 to 1366 pixels, public filters/search/pagination/printing, escaped
text, signed-in contact editing, dialog sizing, and dark mode. Test contacts lived
in a temporary schema, which was removed after verification.

## 24. Post-handoff update: Server Inventory simplification

Replaced the long page of simultaneous tables and detail cards with Servers,
Groups, and Renewals views. The default server list has five columns, local search
including group application names, environment/group/status filters, and 15-row
pagination. Selecting a server opens one detail panel with Overview, Applications,
Access, and Credentials tabs. Protocol and credential lists load on demand and
reuse their results until the selected server is refreshed. Group members use a
filter on the main server list rather than a second member-server table. Shared
group applications retain their distinct scope and management workflow.

`assets/js/servers.js` replaces the page's inline script and duplicate record
dialogs with one field-driven native editor, plus a separate credential sharing
dialog. Secondary delete/share actions are inside the editor. All original record
fields, per-server and group applications, connection commands, RDP downloads,
credential ownership/sharing, and renewal records remain available. Server hardware
fields are optional and collapsible. Renewals have status/type/search filters and
use local calendar dates for expiry calculations, including an "Expires today"
status. `assets/css/servers.css` is scoped to the module and loaded through the new
optional `$pageStyles` list in the shared header, using versioned asset URLs.

The API now enforces GET for reads and POST plus CSRF for writes. Credential lists
calculate owner/share permissions in one query and expose `can_reveal`, avoiding
per-row owner queries. Passwords are revealed only on request, clear after 30
seconds or when leaving the section, and never appear in copy confirmation toasts.
Existing unusual server environments can be preserved when editing. Duplicate and
invalid-field database errors receive useful messages. Renewal dates, nonnegative
alert windows, and application/server association are validated. No migration is
required and no inventory records were removed by this UI change.

Verification: PHP lint; real anonymous server page/API access checks; isolated API
tests for record workflows, method/CSRF gates, ownership, credential sharing and
revocation, duplicate handling, legacy environments, and renewal validation;
headless Edge tests at 320, 390, 768, 820, 1024, and 1366 pixels. Browser checks
covered filters/pagination, group-member navigation, editor workflows, application
names containing quotes, escaped descriptions, access command caching, password
reveal/copy and sharing, renewal selection/statuses, and dark mode. Fixtures used
a temporary schema with independent identity sequences and were removed afterward.

## 25. Post-handoff update: QMS CodeSniffer review engine

Added QMS CodeSniffer alongside Standard diagnostics, Security patterns and PHP
Source code inventory. `lib/QmsCodeSniffer.php` provides a 24-criterion catalog,
bounded static checks and per-file checklist statuses: Needs attention, Manual
review, No issues detected and Not applicable. PHP tokenization and masked
JS/SQL/CSS/HTML literals/comments reduce matches in non-executable text. Findings
contain file/line/rule references without source snippets or literal secrets.
PHP parse errors and incomplete tokenization require correction and re-review.

The header fields confirmed by the user are Filename, Purpose, Developer, Date
and Recent updates. Header fields must be in opening comments, have nonempty
values, a matching filename and a valid date. Author/Name and common label aliases
are accepted. The engine accepts the supplied `temp_Value` property example and
meaningful boolean “is” words, including `isValid`, `userIsActive` and `is_valid`.
Scope is explicitly Existing, New or Modified; criterion 7 applies to New/Modified
and criterion 16 to Modified. A multiline summary/developer/date change trail is
detected, while placement before every modified section needs a diff review.
Indentation can be 2 spaces, 4 spaces or tabs.

SQL checks cover CREATE TABLE column names (including nested datatype commas),
GRANT approval prompts, uppercase keyword patterns, schema qualification/settings
and spool/output filename stems. Qualification does not prove ownership; output
directives do not prove successful file creation. Exact QMU object naming and SQL
templates were not provided and remain manual. Comment usefulness, full dynamic
type/data-flow coverage, externally stored password encryption and approval
evidence also require reviewer confirmation. No compliance pass/percentage is
inferred from the absence of pattern findings.

`assets/js/qms-review.js` and `assets/css/code-review.css` supply the responsive
checklist, coverage notes, per-file statuses, searchable criterion/severity-filtered
findings and 25-row pagination. XLSX/ODS exports include QMS Checklist, QMS File
Criteria and QMS Review Profile sheets plus criterion/reviewer columns in Findings.
Catalog text is reconstructed on the server for export; checklist IDs, counts,
statuses and profile options are validated. Spreadsheet cells remain explicit text.

Scans retain the existing authentication, CSRF, read-only behavior and source/file
limits. Nothing is executed or saved. QMS limits are 25 findings per criterion/file
and 5,000 displayed findings per scan; full detected counts and limit indicators
remain visible. No migration or additional dependency is required.

Verification: PHP lint; `tests/qms-codesniffer.php` (82 assertions including
comments/literals, naming, dates, scope, SQL structure, secret redaction, finding
limits and existing-engine regressions); authenticated fixture API tests for scan,
validation, authentication and CSRF; XLSX/ODS workbook structure and formula-safety
checks; headless Edge client-folder scan, all 24 criteria, per-file results, filters,
escaped filenames, engine switching, dark mode and layouts from 320 to 1366 pixels.
Browser/API fixtures used no application database records and were removed.

## 26. Post-handoff update: Data Dictionary redesign and database adapters

Rebuilt the Data Dictionary as a catalog with documentation coverage, database /
engine / status / sensitivity filters, 15-object pagination, and a focused detail
panel. Overview, Columns and Relationships tabs expose purpose/owner metadata,
searchable 25-field pages, business rules, defaults, PII flags, outgoing references
and incoming documented references. Native editors replace inline JSON handlers;
all dynamic text is escaped and validation errors appear inside the editor.

`lib/DatabaseEngines.php` provides driver capabilities, defaults, validated fields
and DSNs for PostgreSQL, MySQL, MariaDB, SQL Server/Azure SQL, Oracle, SQLite, Db2
LUW, Firebird and ODBC. `lib/SchemaIntrospector.php` now has native catalog adapters,
exact composite FK pairings for PostgreSQL and read-only SQLite opening. ODBC uses
configured System DSNs and the catalog APIs, with explicit driver-dependent limits.
No arbitrary SQL or target data reads are exposed. Catalogs include selectable views.
Missing drivers are shown in the UI with setup requirements and JSON import fallback.

Import is now connect/browse, select schema, preview/select objects, then import
one object per request with progress, failure details, retry selection and Stop
after current table. The API accepts at most 25 objects per live request and 2,000
columns per object. It preserves existing annotations and retains missing source
columns with a review count, protecting documentation and requirement links.
`lib/DataDictionary.php` normalizes fields, validates bounded portable metadata,
formats types without duplicating precision, and handles metadata upserts.

Portable metadata JSON import/export supports arbitrary engine labels, including
non-relational entity/field documentation. Format version 1 uses explicit types and
real JSON booleans; limits are 6 MB, 500 objects and 25,000 total fields. File-level
identity/duplicate validation runs before import. Database-specific Excel exports
retain the Annex E layout and add a Field Details sheet for schema/engine, business
rules, defaults and sensitive flags. User-controlled spreadsheet values are text.

All target connection operations now require POST and CSRF; credentials no longer
travel in URLs. Profile list/detail APIs omit passwords. New saved passwords use
AES-256-GCM with APP_ENCRYPTION_KEY and random nonces; existing plaintext profiles
are encrypted when first used. Password edits support keep/replace/clear without
returning stored secrets to the browser. Connection failures are sanitized.

Migration `database/migration_014_dictionary_engines.sql` adds engine/object type
metadata, expands connection engine constraints and secret/path/type/default/ref
storage, and adds a sensitivity index. Fresh schema definitions match. The migration
was applied to the configured application database with table/column/profile counts
preserved. Existing records were not removed. Keep APP_ENCRYPTION_KEY stable.

Verification: 67 CLI regression assertions, real PostgreSQL and SQLite catalog
fixtures, migration idempotence in an isolated schema, API CRUD/ownership/validation,
method/authentication/CSRF gates, profile encryption upgrades, annotation/extra-field
preservation, JSON round trips and Excel formula safety. Headless Edge covered
catalog filters, escaped names, editors, zero-scale types, relationships, live/JSON
imports, preview/progress/stop behavior, dark mode and 320–1366 pixel layouts.
Other vendor adapters still need their drivers and a target instance for live
verification. MySQL's PDO driver is present; no target credentials were supplied.
See `database/DATA_DICTIONARY_ENGINES.md` for setup and scope. Temporary browser and
database fixtures were removed after verification.

## 27. Post-handoff update: Login design and understandable Error Logs

Redesigned `login.php` with a CSS/SVG workspace illustration, branded header,
responsive sign-in form, theme button, public-directory shortcut and access-help
copy. `assets/css/login.css` and `assets/js/login.js` add password visibility,
Caps Lock feedback, submission loading/duplicate-submit prevention and reset on
browser back navigation. Native POST/CSRF and the existing Auth::attempt workflow
remain in use. Errors retain the employee ID, focus an accessible alert and never
echo the submitted password. Redirect targets must be within APP_BASE_URL and
reject control characters, backslashes and protocol-relative paths.

Error Logs now has clickable preview counts, human-readable daily-file selection,
severity/area/search filters, repeated-event grouping, occurrence counts, sorting,
20-group/event pagination, optional 30-second refresh and support-summary copying.
`assets/js/logs.js` and `assets/css/logs.css` replace the inline page script and
raw-JSON-first layout. Cards explain the recorded event and suggest a check; the
original message and context stay in an expandable technical section. Common
credentials and URL query values are hidden in the view and copied summaries.
Raw downloads retain the original data. There is no inferred resolution status.

`lib/LogPresenter.php` uses deterministic rules for sessions/CSRF, access,
validation, missing database drivers/configuration, file permissions, database
structure/connections, timeouts and application code errors. Guidance describes
possible checks rather than asserting a root cause. Group signatures include the
recorded level/message, source file/line, request route/action and HTTP status.
Unknown or malformed records retain an Other/unrecognized classification.

`lib/Logger.php` keeps the original writer/readFile/listFiles/clearFile interfaces,
adds fileCatalog/readReport and reads bounded tails instead of whole daily files.
Previews return at most 2,000 entries from the last 4 MB, indicate earlier or
oversized omitted data, preserve unstructured lines, and tolerate missing final
newlines. The viewer explicitly labels preview counts. Reads/downloads/deletion
accept only valid dated application-log names and exclude links; deletion cannot
target `.htaccess` or other files. Log API read/write methods are enforced; writes
need POST plus CSRF. Daily-file deletion uses a dedicated confirmation dialog,
and the diagnostic-entry action is in More.

Event times, refresh times and the log-page topbar use Asia/Manila even when the
browser is in another zone. The shared clock now reads its configured zone from
the header's data-time-zone attribute (APP_TIMEZONE by default, optional per-page
override). No database schema change or new dependency was introduced.

Verification: PHP lint; 51 CLI reader/presentation assertions; isolated log API
tests for bounded previews, explanations, redaction, raw downloads, file checks,
method/CSRF/authentication/forced-password gates and deletion; native login POST
tests for CSRF/required fields, employee-ID escaping, password omission and safe
redirects. Headless Edge covered password visibility, Caps Lock, themes, loading,
back navigation, login error retention, log summaries/grouping/filters/pagination,
copying redacted summaries, Manila times with a UTC browser, and cancel/confirm
deletion at widths from 320 to 1366 pixels. Test requests used isolated log files
and no real account sign-in attempts; existing log hashes were unchanged.
Temporary fixtures and browser artifacts were removed after verification.

## 28. Post-handoff update: Password recovery and email-code sign-in

Login now offers native POST Password / Email code options and a Forgot password
link. `forgot_password.php` requests a reset email; `reset_password.php` stages the
email token into server-side session state and redirects to a clean URL before
rendering assets. `includes/auth_layout.php` shares the recovery-page frame.
`assets/js/login.js` supports both forms, resend countdowns, loading/back-navigation
states and optional form controls. Recovery pages share the responsive login design.

`lib/EmailAuth.php` issues 256-bit reset tokens (30 minutes) and six-digit sign-in
codes (10 minutes, five wrong attempts, original-browser binding). PostgreSQL row
locks enforce single use and serialize per-account resends; persistent hashed
identifier/IP/session request buckets and a 60-second resend interval limit email
requests, including nonexistent identifiers. Responses are generic. Employee ID
lookup takes precedence; registered-email lookup must match exactly one account.
Locked/disabled/ambiguous accounts receive no credential. Eligible accounts without
an address can still use the authorized support-preview fallback.

The user selected **protected email preview** for unavailable email. New-tab links
open `auth_email_preview.php`, protected by the same Auth::requirePage gate as User
Management, including forced password change. There is no separate administrator
role in this project. The page shows delivery state, safe troubleshooting notes,
Manila request/expiry times and an active code or copyable reset link. Support must
verify the requester and use their own browser so code-requester session state is
preserved. Users and Parameters link to the latest 50 requests from the last day.
Preview access is audited with numeric viewer/target IDs and purpose, never secrets.

Verifier hashes use purpose-separated HMAC keys. Preview secrets use AES-256-GCM,
random nonces and challenge IDs as associated data, derived from the existing
APP_ENCRYPTION_KEY. Keep that key stable. Secrets disappear from previews after
use/expiry/revocation; cleanup clears invalid encrypted copies and deletes delivery
history/request buckets older than a day on subsequent requests/preview reads.
Auth pages send no-store/no-referrer/frame restrictions. Public handlers never
return delivery status or codes. No token is rendered into the reset form or assets.

`Mailer::sendAuthenticationEmail` uses the existing SMTP parameters, bounded waits,
HTML/plain messages and sanitized delivery notes. PHPMailer was declared in
composer.json but missing from composer.lock/vendor; it is now locked/installed
at 6.12.0. Existing ZipStream 3.1.2 conflicted with the configured PHP 8.1 target;
the lock/install now uses 3.1.1. Other package versions were retained. Composer
validation/platform checks and an actual XLSX save/load round trip passed.

Parameters adds the canonical application URL for reset links. Links never use an
untrusted request Host header. Default is http://localhost/AnalystToolkit; set the
reachable server address for other devices. SMTP settings and this URL should be
saved/tested before use. Settings mutation methods now require POST plus CSRF.

`users.auth_version` invalidates old sessions on password resets and account
identity/status changes (existing account checks run every 30 seconds). Password
and email-code sign-in share policy/session setup, including session-ID/CSRF
rotation, disabled/locked checks and forced password change. Zero-day expiry now
correctly disables expiry. Reset never automatically logs a user in. Manual
User Management resets and the existing Change Password form revoke outstanding
credentials; own password/email edits preserve the current updated session version.
Password changes reject bcrypt truncation beyond 72 bytes and null bytes.

Migration `database/migration_015_email_auth.sql` adds challenge/limit tables,
auth_version and auth_base_url. It is rerunnable, included in fresh schema.sql,
and applied to the configured app database with before/after account and SMTP
snapshots unchanged apart from the new columns.

Verification: PHP lint; 72 CLI authentication assertions; 58 real HTTP/headless
Edge assertions, including loopback SMTP delivery, code sign-in, native reset and
confirmation validation, guest preview denial, protected fallback without email,
clean reset URLs, CSRF/method gates, canonical-URL validation, manual resets,
password/email changes and session revocation. Browser layouts were checked at
320–1366 pixels with dark theme and no runtime errors. Existing spreadsheet
export/import passed after dependency resolution. Tests use isolated schemas,
accounts, SMTP, sessions and logs; no real user's password was changed and no
real email was sent. Disposable database/browser/session fixtures are cleaned.

## 29. Post-handoff update: Administration/Program Inventory UI and app logo

Organization, Users, Parameters and Program Inventory now use scoped responsive
workspace styles, summary cards, clearer headings/actions, native dialogs and
loading/error/empty feedback. Shared files are `assets/css/workspace.css` and
`assets/js/workspace-ui.js`; each module has its own stylesheet and external script.
`includes/footer.php` supports an optional pageScripts array before pageScript,
allowing shared helpers without loading them on unrelated pages. No new package
or database migration is required.

Organization replaces the repeated per-row action buttons and five editors with
a searchable five-level explorer, entry details panel and one level-aware editor.
Search includes ancestor paths; expand/collapse state is preserved through reloads.
Counts describe real hierarchy entries. Create/edit/delete use the existing APIs
and retain all empty-node/user/contact deletion protections and named confirmations.

Users has clickable status summaries, name/ID/email search, department/status
facets, sorting and 15-account pagination. Rows group identity, placement, email
and statuses, with Edit and a dedicated account-actions dialog. Small-screen rows
become cards. New/edit forms use a freshly fetched organization tree for immediate
cascading selections, clear dependent levels on parent changes and retain backend
placement validation. Reset passwords have confirmation fields. Self disable/delete
are blocked in both UI and API. Native-dialog headers/save controls remain visible
while long form bodies scroll.

Parameters separates Password Policy, Email Delivery and Recovery Links into
keyboard-accessible sections with independent Save/Discard and unsaved indicators.
Saving one section retains other sections' drafts. The settings API accepts an
optional section whitelist, validates only that section and updates only its fields;
the legacy full-settings payload remains supported. SMTP password blanks retain
the saved secret, with a non-secret saved-password flag returned by GET. Summary
cards describe saved policy/configuration, including local-only recovery URLs.
Test mail explicitly describes SMTP acceptance rather than guaranteed delivery.

Program Inventory now has source selection, report details, local metadata preview
and a persistent export summary. Search, type filtering and 20-file pagination
affect only the preview; export includes all files passing directory exclusions.
Included/excluded counts, total size and initial file ID are shown. Browser mode
sends only name/path/size/modified metadata. Server mode scans on Scan & Export,
downloads via fetch and displays errors inline. Sample project/person/path defaults
are removed; developer defaults to the current user. Both modes preserve the
11-column workbook. Folder exclusions match directory segments rather than leaf
filenames, leading dots in directory names are preserved, and spreadsheet strings
are explicit text to prevent formula interpretation. Server export requires POST,
CSRF and normal API authentication.

Created a connected-node A logo as native SVG: `assets/brand/analysttoolkit-mark.svg`,
`analysttoolkit-logo.svg` and `analysttoolkit-logo-dark.svg`. The mark represents
data/systems relationships, using indigo/blue and a mint focal node. Transparent
PNG copies are included at 1230×240 for wordmarks and 256×256 for the icon. The
shared sidebar, sign-in/recovery pages, Change Password and public Call Directory
use the new identity; shared/standalone pages include the SVG favicon. All assets
are self hosted. See `assets/brand/README.md` for variants and palette.

Verification: PHP lint; 74 isolated HTTP/headless Edge assertions covering all
organization levels, editor hydration, search/filters/pagination, user creation and
placement, reset confirmation and account disable, independent settings saves and
draft retention, SMTP tests, canonical-URL validation and method gates. Real local
and server Excel downloads were inspected for row/column counts, literal formula-
looking names and exclusion behavior. Browser payloads contained no file contents.
Widths 320–1366, visible dialog save controls, light/dark layouts, logo rendering
and runtime/PHP errors were checked. The prior email authentication browser suite
also passed all 58 assertions after the changes. Tests use disposable schemas,
accounts, files, sessions, logs and loopback SMTP; real account/settings data was
not edited and no real email was sent. Temporary fixtures/screenshots are removed
after visual review.

## 30. Post-handoff update: Code Review, public Quick Links and ERD Generator

Code Review now uses the shared workspace design, source chooser, four engine
cards, optional filters, QMS settings/help, inline errors, progress and cancellation.
Configuration is disabled during scans. Reports show the source/engine used;
changed settings mark results as stale. Standard/security results include a
searchable findings table, severity filter, files-reviewed view and 25-row
pagination. Function Inventory uses five preview columns and expandable details;
full exports retain the original matrix. QMS keeps all 24 criteria, coverage
notes and manual review outcomes. Its backend and source-analysis rules are
unchanged. Source contents are sent for analysis but are not saved in reports.
Controller moved from inline markup to assets/js/code-review.js.

Access Links is a public read-only catalog at modules/bookmarks/index.php.
Guests use a branded standalone page; normal signed-in users use the shared
shell and see editors. Both support search, tag facets, sorting, 12-card pages,
safe new-tab opening, copy and device-local pins. A login-page shortcut exposes
public access. Only GET list_bookmarks is public; every write requires normal
API authentication, POST and CSRF. lib/AccessLinks.php validates HTTP/HTTPS URLs,
rejects embedded credentials and unsafe schemes, and normalizes fields. Invalid
legacy addresses are never launched and are masked in guest responses. Editors
are explicitly told that catalog entries are public. Unsafe existing addresses
can be corrected by a signed-in editor.

The former Diagram Generator now displays only ERDs, with a saved-model library,
dictionary table picker, include-referenced option, SVG canvas, table/column
editor, relationship editor, drag/keyboard layout, zoom/fit and saved positions.
Data Dictionary details link to a preselected ERD generation dialog. The module
URL remains modules/diagrams for compatibility; sidebar, dashboard metrics and
activity, new traceability selections and log labels now refer to ERDs.
Legacy non-ERD diagrams and existing traceability links are retained in storage.

lib/ErdGenerator.php builds atomic snapshots in a repeatable-read transaction
from dd_tables/dd_columns. It resolves qualified/unqualified and common quoted
identifiers within one source_database label, avoids ambiguous or missing edges,
and stores warnings and generation provenance. Column nodes retain full types,
PK/FK/nullability flags and stable dictionary keys. Relationships retain endpoint
column keys and inferred cardinalities. Unique constraints and composite foreign
key grouping are not present in dictionary metadata: the UI reports this limit.
Models support 50 tables, 5,000 columns and 5,000 relationships. Local edits and
imports affect only the snapshot, not the dictionary or source database.

GraphML and Excel routes require sign-in and an ERD. SVG exports the canvas.
Workbook strings are explicit text; lib/ErdWorkbook.php validates ERD nodes,
parents, endpoints, sizes, colors and cardinalities, upgrades legacy attribute
endpoints, preserves relationship metadata, and never evaluates formulas.
Imports bound archive expansion and sheet dimensions, reject formula cells, and
replace graph contents in a transaction only after complete validation and a
visible confirmation. Errors preserve the saved model. GraphML sanitizes legacy
fill colors and includes schema-qualified table labels.

Migration database/migration_016_erd_generator.sql adds JSONB generation/node/edge
metadata and an ERD update index. Fresh schema.sql includes them. The migration
was tested twice against disposable legacy rows, then applied to the configured
app database. Hash comparisons of all pre-existing diagram, node and edge fields
confirmed that their contents were preserved.

Verification: all changed PHP files linted; tests/erd-generator.php passed 47
resolver/workbook/XML/URL assertions; tests/modeling-browser.py passed 76 real
HTTP/headless Edge assertions. It covers guest write denial and legacy URL masking,
link CRUD, CSRF/method gates, dictionary FK expansion and unresolved warnings,
saved layouts, manual table/relationship edits, GraphML/SVG/Excel exports, literal
formula-like names, import round-trip and atomic rejection, four review engines,
inventory ODS export, cancellation, stale-report indicators, light/dark themes,
320–1366 px widths, runtime errors and PHP warnings. Existing QMS (82), dictionary
(67), log viewer (51) and email-authentication (72) CLI suites also passed.
Browser fixtures use disposable schemas, accounts, sessions, files and Edge
profiles; real catalog/account records are not edited.
The existing email authentication HTTP/browser suite also passed all 58 checks.
The final integration pass verifies the Data Dictionary generation shortcut and
ensures guest searches cannot match masked legacy credentials. Dashboard link
launching uses the same safe URL validator as the public catalog.

## 31. Post-handoff update: Code Review source chooser reference

The source section now follows the supplied reference: bordered card with a
numbered header, selectable This computer/Application server cards, selected
checkmark, and a centered folder icon/picker with Choose folder. Selection shows
the folder name, file count and total size and changes the action to Change
folder. Application server retains the existing project-directory dropdown in
a matching panel. The notice accurately explains that local source contents
are sent for analysis on Review files; it does not claim metadata-only uploads.

Changes are scoped to modules/codereview/index.php, assets/css/code-review.css
and assets/js/code-review.js. Source-mode styling uses aria-pressed and survives
switching modes. Native folder selection remains accessible through a keyboard
button; cards stack on narrow screens and support light/dark themes. Backend
scan limits, engines and exports continue to use their existing paths.

Verification: PHP lint and the existing 76-check disposable HTTP/Edge suite
passed, including four real source reviews, exports, scan cancellation and
320–1366 px layouts. Light/dark screenshots were visually inspected and removed.

## 32. Post-handoff update: Sidebar account menu

Redesigned includes/user_menu.php with an identity header, two-letter initials,
signed-in indicator, expandable read-only profile details, employee-ID/email copy
buttons, photo upload guidance/status, a direct Change password action, explicit
Light/Dark appearance choices, Sign out and the actual session sign-in time.
Missing email explains how to enable email sign-in/recovery. Name/contact/placement
changes continue to be maintained through Users and Organization. No new profile
editing privilege, database migration or dependency is introduced.

The footer trigger and dropdown use scoped account styles in assets/css/app.css.
The panel is positioned within the viewport by assets/js/app.js, using available
space beside or above/below the footer trigger. Its body scrolls independently;
very short windows allow the whole panel to scroll so every action is reachable.
Keyboard opening moves focus into the panel; Escape and the Close button return
focus to the trigger. Outside click/focus dismiss it. Native Tab navigation can
leave the panel. Geometry updates when the viewport, scroll position or expanded
profile details change. Existing standalone-page theme controls remain supported.

AT.setTheme supports the appearance buttons, preserves the current light/dark
storage key and handles unavailable storage. Theme changes sync across tabs.
The avatar workflow adds client size/type checks, duplicate-submission prevention,
upload feedback and safe DOM image updates. It retains the existing authenticated
multipart/CSRF upload endpoint. The clipboard fallback now preserves focus inside
the panel instead of inadvertently dismissing it.

Verification: PHP lint; 33 focused account interactions plus the existing 76-check
HTTP/Edge suite passed (109 total). Checks cover real keyboard opening/Escape/Tab,
outside dismissal, clipboard fallback, both appearance modes, 320–1366 px and
400 px-high viewports, photo type/size validation, busy/success/error feedback,
both avatar updates, password-form access and actual sign-out/session denial.
Photo responses were mocked against a local static image so no real avatar or
account data was changed. Existing fixture schemas/accounts/sessions were used
and cleaned. The one-off check wrapper and reviewed light/dark/mobile screenshots
were removed after verification.

## 33. Post-handoff update: Sticky workspace topbar

The shared topbar is now a semantic header with position:sticky/top:0, an opaque
theme surface and a subtle shadow. It remains visible during window scrolling,
including after the mobile sidebar leaves view. The right-side Requirements,
Code Review and Parameters shortcut buttons are removed. A noninteractive clock
icon, compact date and time replace them; narrow layouts show time only.

The sidebar toggle and module search use local SVG icons, clearer spacing,
rounded borders and visible focus treatment. Search retains Ctrl/Cmd+K, sidebar
filtering and Enter navigation. Its keyboard hint disappears on small screens.
The existing account menu remains in the sidebar footer above the header layer.

assets/js/app.js measures #appTopbar with ResizeObserver and exposes the actual
height through --app-topbar-height. Root scroll padding and the Organization,
Parameters and Program Inventory sticky panels use this value so titles/panels
remain clear of the header. Date/time use the existing configured clock timezone;
the time element also carries an ISO timestamp.

Verification: PHP lint and 20 focused scroll/header interactions alongside the
existing 76-check disposable HTTP/Edge suite passed (96 total). The header was
scrolled and checked for viewport attachment and stacking at 320, 390, 768,
1024 and 1366 pixels. Search shortcut/filtering, sidebar toggling, responsive
height offsets, absence of right-side buttons, light/dark views, runtime errors
and existing module flows were checked. Screenshots were visually inspected;
temporary wrappers, images, database fixtures and browser profiles were removed.

## 34. Post-handoff update: Aligned header, collapsed navigation and module search

The sidebar brand row uses the measured --app-topbar-height so its bottom border
aligns with the shared topbar on desktop, including responsive header wrapping.
Mobile keeps a separate fixed-height brand row.

Hamburger behavior now collapses navigation labels instead of hiding the sidebar.
Desktop uses a 76 px icon rail with the app logo and account avatar retained.
Mobile uses a compact logo/account row and horizontally scrollable icon navigation.
Module links retain accessible names, native title tooltips and active-page state.
Expanding restores all labels. The browser preference is stored under
at_sidebar_collapsed and applied before the page renders. The old sidebar-hidden
behavior is removed; account options remain usable in the collapsed layout.

Module search now renders a dropdown below its field and never filters or expands
the sidebar. The local catalog comes from existing navigation links, with aliases
for terms such as Quick Links and QMS CodeSniffer. It supports all-module browsing,
case-insensitive keyword matching, result counts, current-page indication, empty
feedback, real link clicks, arrow-key selection with scrolling, Enter navigation,
Escape/Tab/outside dismissal and Ctrl/Cmd+K. ARIA combobox/listbox state is updated
as results and selection change. No server request, query persistence or database
change is involved. The panel fits the available viewport below the sticky header.

Changes are in includes/header.php, assets/css/app.css and assets/js/app.js.
Verification: PHP lint and 32 focused navigation checks plus the existing 76-check
disposable HTTP/Edge suite passed (108 total). Checks cover border alignment,
visible logo/avatar, icon-only labels, tooltips/accessibility, account access,
unchanged sidebar links during search, aliases, empty states, keyboard wrapping
and visible selection, mouse/Enter navigation, persisted collapse after navigation,
expansion and dropdown fit at 320–1366 px, dark mode and runtime errors.
Screenshots were reviewed; the one-off wrapper, images and all fixture resources
were removed after verification.

## 35. Post-handoff update: User audit trail across every module

The new Audit Logs workspace is available under Logs → Audit. It uses the same
normal signed-in gate as Users; the existing application has no separate admin
role. It is read-only and includes actor snapshots, module/action/outcome, request
time, HTTP status, IP, duration, a non-reversible session reference and record
change details. Filters cover users, modules, sources, outcomes and date ranges.
Search includes displayed action/module names and table/record references.
History pages use a stable event-ID boundary until refresh. Record-change details
are paged in batches of 100. Filtered CSV exports are capped at 50,000 events,
include a timezone-bearing timestamp and escape spreadsheet formula prefixes.

lib/Audit.php starts from config/config.php after session initialization and
finalizes requests at shutdown, including exit paths and native auth forms.
Database.php attaches request context to the app PDO connection; helpers.php
collects only allowed IDs/options/counts from input and JSON responses. Auth
identifies successful sign-ins, preserves the actor through logout and marks
protected page redirects as denied. Native form failures returning HTTP 200 are
marked failed. Protected authentication email access retains the target user ID
without storing its challenge, code or reset link. Server routes cover dashboard,
authentication, account uploads and all existing module pages/APIs/exports.

Migration 017 adds audit_events and audit_changes, indexes, append-only guards
and row-change triggers on all 26 domain tables. Pending request stubs and row
changes participate in the business transaction, so rolled-back work leaves no
committed change record. Shutdown finalization also rolls back any abandoned
transaction and marks the request failed. Cascaded updates/deletions are included.
Finalized events and row history reject UPDATE/DELETE/TRUNCATE. Actor IDs have
no cascading FK to Users, preserving snapshots when accounts are removed.
Database owners can change these protections; this is not a cryptographic ledger.

No passwords/hashes, credential values, SMTP secrets, one-time codes, reset tokens,
source contents, filenames, raw query strings, search text or free-text row values
are retained. Changed field names and record IDs are stored. Before/after values
are limited to an explicit status/policy/type allowlist in the SQL trigger.
Auditing failures produce a generic Error Logs entry with a request reference
and error class/code, excluding raw exception text and payloads.

assets/js/audit-client.js supplements server events with authenticated, CSRF-bound
browser reports for clicks, select/checkbox/file changes, submissions, copying
and search adjustments. It never sends values, text labels, filenames or copied
contents. The collector verifies the session actor, validates/deduplicates event
UUIDs, bounds batches and rate-limits reports. Browser events are explicitly
labelled Reported and do not establish an operation's success. The collector
does not recursively create request logs; other audit viewing/export requests
are audited. Closed tabs and network loss can interrupt browser reporting.

History begins at enablement, with no fabricated prior activity or automatic
deletion. Direct SQL and CLI maintenance without HTTP context are outside this
trail. Backups should include both audit tables. Fresh schema.sql includes the
complete definitions. migration_017_audit_logs.sql was applied to the configured
workspace database after disposable-schema verification; before/after hashes
confirmed existing data in all 28 pre-existing tables was preserved.

New files: lib/Audit.php, lib/AuditQuery.php, modules/audit/{index,api,client,export}.php,
assets/css/audit.css, assets/js/audit.js, assets/js/audit-client.js,
database/migration_017_audit_logs.sql, tests/audit.php and tests/audit-browser.py.
The shared header includes the collector and navigation/search entry. The user
has grouped Error/Audit under a Logs sidebar section; preserve this organization.
Existing browser fixtures allow the collector route, and the minimal email-auth
fixture applies migration 017 to avoid missing-table fallback errors.

Verification: PHP lint; 35 transactional/privacy fixtures and 81 audit browser/HTTP
assertions, plus the existing 72 authentication, 82 QMS, 67 Data Dictionary,
51 Error Logs and 47 ERD/Quick Links fixtures; existing browser suites passed
76 modeling/Code Review, 74 administration/inventory and 58 email-auth checks.
All data-changing verification used disposable schemas/accounts/sessions, local
files and loopback SMTP only. Audit checks cover every module, binary exports,
credential reveals, CRUD/cascades, failed/denied requests, actor spoofing, secret
exclusion, browser deduplication, pagination, filters, details, CSV protection,
account deletion and 320–1366 px light/dark layouts. Temporary screenshots and
fixture resources were removed after review.

## 36. Post-handoff update: Roles, reminders, saved reviews, catalog and OWASP

Upcoming functions.md records all 15 approved roadmap items. Items 1–4 plus OWASP
support are implemented; items 5–15 remain planned. README now documents current
authorization, review storage, reminders, task installation and the roadmap.

Migration 018 (database/migration_018_workflow.sql) is applied. Fresh schema.sql
includes it. It adds four app_roles, role_permissions, users.role_key,
applications/application_assets/application_inventory_runs, review_runs/findings/
notes, and notification_settings/preferences/notifications/deliveries. The new 11
domain tables have audit row triggers (37 domain tables total). The audit safe
status allowlist includes role/status/preference fields. Reapplying preserves
customized permissions. No original credential/profile fields are changed by the
upgrade. Assigning the bootstrap Administrator naturally updates users.updated_at
through the existing timestamp trigger; an isolated upgrade check confirmed this
was the original preservation check's timestamp-only difference.

lib/Permissions.php enforces role/module/action gates from Auth::requirePage and
Auth::requireApi, including exports, scans, credential actions and support preview
access. Administrator access is fixed; other roles have configurable applicable
permissions. View is required for other operations. Defaults: Analyst maintains
business records/scans/exports; Reviewer makes review decisions and exports selected
reports; Viewer reads business data. User/settings/log administration and vault
access are restricted by default. Credential owner/share checks still apply.
Account self-service, personal inbox access and public directory/Quick Links viewing
remain available. The bootstrap prefers an active admin employee ID, otherwise the
oldest active account. User edits use transactional last-Administrator protection
and prevent non-Administrators from managing Administrator accounts or changing
roles. Role changes increment credential versions as needed; effective permissions
are fetched on each request. No private application ownership scopes are implied.

The shared header provides permission-filtered navigation and a client permission
matrix. assets/js/permissions-ui.js mirrors gates for static/dynamic controls; server
checks remain authoritative. The user menu shows the access role. Public modules
keep the workspace shell for signed-in Viewers and hide management controls.
Quick Links masks unsafe legacy URLs for users without management rights as well
as guests. Dashboard data and Requirements asset lookups/exports respect source
module access. Role administration is in modules/permissions with an applicable
action matrix, rather than checkboxes for unsupported module operations.

Application Catalog (modules/applications; lib/Applications.php) provides validated
codes/names, lifecycle, criticality, business/technical owners, department and safe
HTTP(S) URLs without embedded credentials. Existing server/hosted/group system,
dictionary table, ERD, requirement and Quick Link records can be linked through
real FKs and unique indexes. Related lookups honor source module access. Deleting
an application removes its links while preserving source records and reviews.
Code Review and Program Inventory offer optional application selection. Application
details list recent reviews and inventory export metadata. Inventory export
tracking commits after successful workbook generation; file contents are not saved.

lib/ReviewStore.php saves server-produced scan metadata and findings automatically.
Raw source and function inventory literal values are excluded; PHP parse errors
use a generic message to avoid storing unexpected source tokens. Saved reviews are
under Code Review → Saved reviews. Runs support titles, application links, eligible
reviewer assignments/due dates, finding statuses Open/In Progress/Fixed/Accepted,
notes, completion, comparison and escaped CSV export. Fixed/Accepted require a
reason, and completion requires a note with no open/in-progress findings. Finding
updates use optimistic timestamp checks. Comparison reports new/continuing/not
detected; absence does not prove a fix. Fingerprints use engine/path/rule/message/
occurrence order. Accepted exceptions carry to comparable scans. Review decisions
require the dedicated Review capability. Scan creation and general metadata edits
have separate rights.

lib/OwaspReview.php is the fifth review engine, mapped to OWASP Top 10:2025 using
https://top10.owasp.org/2025/. It supplies bounded PHP/JS/HTML pattern checks,
category IDs, static remediation text and manual checkpoints for all ten categories.
PHP comments are excluded from matches and matched secret values are not quoted.
Insecure Design is manual, and every no-finding category still requires manual
review. Dependency advisories, complete taint analysis and deployment/security
certification are outside this engine. Live XLSX/ODS exports add category/remediation
columns and an OWASP Coverage sheet; saved findings export independently to CSV.

Notifications (modules/notifications; lib/Notifications.php) provides a personal
read/unread inbox, own email preference and Administrator delivery configuration/
status. Review assignment creates an inbox item. Expiry reminders use configurable
thresholds (30/14/7/1/0 by default) and overdue repeats (7 days). They go to eligible
application owners through server/hosted-system links, falling back to active
Administrators if owners are unavailable. Overdue review reminders go to the assigned
eligible reviewer. Thresholds catch missed runs, and recipient/dedup keys prevent
duplicate inbox items. Generic messages link to the matching renewal/review. Inbox
mutations always scope to the authenticated user. Email defaults off; enabling it
queues the previous 24 hours of unread reminders for users with email enabled.
SMTP is configured in Parameters. The worker claims queued deliveries with row
locks/SKIP LOCKED, checks preferences/access again, retries up to five times and
stores generic failure text. SMTP is not guaranteed exactly once after interruption.

bin/notifications.php is CLI-only. Without --deliver it only generates in-app
reminders; --deliver also processes up to 25 emails. bin/ is denied direct HTTP
access. The hidden Windows task AnalystToolkit Notifications is installed every
15 minutes using C:\xampp\php\php-win.exe, InteractiveToken, LeastPrivilege and
IgnoreNew overlap behavior. The first run returned result 0. This Windows account
must be signed in for the installed task to run. Windows denied background S4U
registration; PowerShell scripts were also disabled. No execution policy was
changed. bin/install-notification-task.php uses native Task Scheduler XML (UTF-16),
and --interactive selects the installed mode. A PowerShell installer is retained
for permitted environments. An approved unattended task account is needed when
reminders must run while nobody is signed in. Production reminder email was
verified disabled with an empty outbound queue before scheduling; development
delivery tests used a loopback SMTP catcher only.

A sign-out regression exposed late keepalive audit requests replacing a fresh
login cookie. config/config.php now starts only the audit collector session without
cookie output and with strict ID validation. An expired collector session returns
401 without setting/restoring cookies. Other page/API/auth session behavior is
unchanged. The browser suite explicitly sends late old-session reports after logout.

Verification: PHP lint; 41 workflow/OWASP component fixtures; 91 workflow browser/
HTTP checks; existing 35 audit, 72 auth, 82 QMS, 67 dictionary, 51 Error Logs and
47 ERD/Quick Links fixtures; 76 modeling, 74 administration/inventory, 81 audit and
58 email-auth browser checks. Coverage includes roles, forged requests, immediate
revocation, protected previews, related-asset gates, catalog links, safe URLs,
server-produced review persistence, secret exclusion, OWASP exports/coverage,
review decisions/completion, owner fallback, notification ownership/deduplication,
loopback email delivery, renewal links, session-cookie races and light/dark views
at 320–1366 px. The headless inventory fixture uses distinct report names to avoid
same-second download overwriting. Temporary fixture schemas/profiles/files and
reviewed screenshots were removed after verification.

## 37. Custom roles and dynamic account assignments (2026-10-25)

User requested roles beyond Administrator, Analyst, Reviewer and Viewer.
Administrators can now create roles in Roles & Permissions, start with minimal
access or copy an existing non-Administrator permission profile, edit custom
names/descriptions, configure module/action rights, and delete unused custom roles.
Each role has a stable lowercase key (1–20 characters), a unique display name
(case-insensitive, up to 80 characters), and optional description (500 characters).
Copies are independent; Administrator privileges cannot be copied. Minimal roles
start with dashboard, personal account/notifications and existing public viewing.

lib/Roles.php owns transactional validation, role-row locking, creation/copy,
metadata updates, permission upserts and deletion. All role administration routes
require Administrator authorization and POST actions require CSRF. The four preset
roles cannot be renamed/deleted; only admin has unconditional access and role
administration. Existing last-active-Administrator protections remain enforced.
Custom deletion checks every assigned user, including inactive accounts, and
requires reassignment first. Foreign keys protect concurrent assignment/deletion.

Users now validates roles against app_roles and refreshes the role picker whenever
the editor opens. User rows/search and the account menu use the stored display
name. Non-administrators still cannot assign/change roles or manage Administrator
accounts. Permissions apply to custom keys with the same per-request checks as
presets. The role page shows assigned user counts and an unsaved-permissions state.

Migration 019 is applied: app_roles description/is_system, case-insensitive name
index and audit change trigger. Fresh schema includes it. Existing account rows,
role names and permission rows matched their complete before/after hashes.
Migration 018 now names its INSERT columns and seeds only the four preset roles,
so reapplying it after 019 neither fails on extra columns nor expands custom grants.
There are now 38 audited domain tables. Custom role identifiers are allowlisted
in request audit metadata; free-text descriptions are excluded from snapshots.
Role lifecycle and permission saves have explicit audit action labels.

Verification: changed PHP files lint clean; 43 custom-role component assertions
(including upgrade/idempotency and audit privacy), 124 workflow browser/HTTP
assertions (including create/copy/rename/delete, user assignment, account display,
forged requests, self-promotion rejection and responsive widths 320–1366), 41
workflow/OWASP fixtures, 35 audit fixtures and 72 email-auth fixtures passed.
Browser/schema/accounts/SMTP tests used isolated fixtures; no real email sent.
README and Upcoming functions.md include custom roles and migration 019.
