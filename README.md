# DevAtlas

DevAtlas runs on **CodeIgniter 4 + HTMX + Alpine.js + Tailwind CSS**, with PostgreSQL and the existing departmental workflows. Module controllers live in `app/Controllers`; readable module templates and shared layouts live in `app/Views`. Navigation uses clean URLs such as `/AnalystToolkit/login` and `/AnalystToolkit/modules/dailylogs/`. Existing `.php` page bookmarks redirect to their clean URLs and retain query parameters. Legacy POST and API endpoints remain compatible.

Requires PHP 8.1+, `intl`, `mbstring` and the existing PostgreSQL extensions. Node is needed only for development tooling and CSS compilation. See [stack migration and installation notes](docs/STACK_MIGRATION.md) for architecture, session compatibility, build commands, Apache configuration and recovery information. The [project handoff](docs/PROJECT_HANDOFF.md) retains historical implementation details; the [framework inventory](docs/FRAMEWORK_STRUCTURE.md) explains the CodeIgniter scaffold and cleanup.

```powershell
composer install --no-dev --prefer-dist
npm ci --ignore-scripts
npm run build:css
npm run check:views
python tests/run-quality.py
```

Code Review and the repository QMS audit target our source. Installed vendor libraries, plugins, Node packages, generated CSS/runtime data and hidden tooling are excluded. Authored `assets/js`, `assets/css` and `resources/css` remain included. Framework-required method/configuration names and existing database/API contracts are documented review exceptions; raw diagnostics remain visible.

The PHP 8.1 profile pins CodeIgniter 4.6.5 and compatible dependencies. See
[PHP 8.1 compatibility](docs/PHP81_COMPATIBILITY.md) for installation, current
validation and the older framework’s known security advisories.

## DevAtlas design and compatibility

The login page now introduces the connected DevAtlas workspace. Call Directory
and Quick Links sit in the login header, separate from sign-in forms. Protected
email fallback messages have been removed from email-code sign-in and recovery;
authorized support can still use User Management email previews.

Applications and Requirements are temporarily hidden from navigation, module
search, Wiki launch links and the permission-matrix editor. Visibility is held in
`Permissions::HIDDEN_MODULES`. Saved grants and records are preserved; this is a
navigation change, so existing integrations continue to work.

Existing installations run `C:\xampp\php\php.exe bin/install-brand.php` for migration
027. It updates only the legacy default SMTP sender name and its column default;
custom sender names are preserved. URLs, database/schema names and scheduled job
identifiers stay compatible. Dependencies and report-template formats are retained.
New dictionary JSON exports use `devatlas-dictionary`; legacy version 1 imports
remain supported.

Verify with `python tests/devatlas-browser.py`, `python tests/email-auth-browser.py`
and `python tests/daily-logs-browser.py`. Browser checks use disposable schemas,
accounts and profiles. No production data is used by the fixtures.

## QMS source maintenance and validation

First-party PHP, JavaScript, CSS and SQL use the QMS source headers, PascalCase
routines, UPPER_SNAKE_CASE constants, four-space layout, vertical braces and
separate statements. Existing database columns, HTTP/JSON fields, browser APIs
and library-required names retain their contracts. Review those findings using
`docs/quality/QMS-review.md`; automated diagnostics do not record formal approval.
Third-party libraries, generated data and deliberately noncompliant review samples
are excluded. The Error Logs and Code Review modules are included.

Run `php bin/qms-audit.php` for the current per-file checklist and diagnostic counts.
Run `php bin/qms-layout.php` to check PHP layout, or add `--fix` to format whitespace
with executable-token and literal-value verification. `.php-cs-fixer.dist.php`
defines the base PHP formatting rules. The current audit covers Existing scope;
New/Modified scope adds comment and change-trail checks that require diff review.

Run `python tests/run-quality.py` for every PHP and browser regression suite.
Use `--php-only` for PHP fixtures or `--suite workflow-browser.py` for one suite.
Results and individual logs are retained under `docs/quality/`, which Apache denies
directly serving. Fixtures use disposable schemas and browser profiles; mail tests
use loopback SMTP. External database engines and production SMTP delivery require
their own configured environment checks. The validation report records scope and limits.

All installation configuration lives in the ignored root `.env` file: database
connections and passwords, application name/URL/timezone, and the vault encryption
key. `config/config.php` reads these values instead of containing installation
values. The committed `.env.example` file is a template without real credentials; the
CodeIgniter `env` template contains the same settings.

For a new installation, copy `.env.example` to `.env` if it does not already exist, fill
in the settings and generate a vault key once. Existing encrypted installations
run `php bin/migrate-env.php` to preserve the exact passwords and vault key in a
new `.env`; the command never overwrites an existing file or prints secrets.
Review customized non-secret settings before startup. The old encrypted files
are retained for recovery and are no longer used by the application at runtime.

See [environment configuration](docs/ENVIRONMENT.md) for the complete variable
list, migration steps, file permissions and override behavior. Do not regenerate
an existing vault key: doing so makes existing encrypted credentials unreadable.

Migration installers use `MigrationRunner` to write start, completion and failure
records to `logs/migrations/<script-basename>.log`, including the script checksum.
SQL and secret values are excluded from the log. SQL files remain executable through
PDO; client-only SPOOL directives are intentionally handled by the caller. For a
manual `psql` run, use `-L <script-basename>.log` with `-f <script-basename>.sql`.

## Documents Wiki and navigation

Open **Help (?) > Documents Wiki** in the top bar for a standalone page with
searchable guides covering every module,
the daily-log workflow and the system roadmap. Guides describe purpose, steps,
access and operating rules. Open-module links follow the current user's grants.
The roadmap separates delivered features from the ten planned areas in
`Upcoming functions.md`; no delivery dates are committed. The built-in guide is
maintained in `lib/SystemWiki.php`.

The Wiki opens in a separate tab and is not in the application sidebar.
**Help > What's New** opens a modal with release history, newest first. Each
new release opens this modal automatically until the user acknowledges it.
Acknowledgements are stored per account, so they persist across browsers.

**Holidays** is a separate module under **Parameter**. Its year-filtered
calendar applies to the whole organization; administrators maintain dates and
names. Holiday View is an independent role permission. Users, Organization,
Roles & Permissions, Parameters and Holidays are grouped under **Parameter**, in both
the sidebar and permission matrix.

For existing installations already through migration 024, run:

```
C:\xampp\php\php.exe bin/install-wiki.php
```

Migration 025 adds Wiki View to existing roles and copies existing monitoring
visibility to Holiday View. It retains calendar records and configured grants
on reapplication. New minimal custom roles include Wiki View; Holidays can be
granted independently. Fresh installations include these grants in `schema.sql`.

Existing installations through migration 025 run `bin/install-updates.php` with
PHP to apply migration 026. It adds Section Head and Department Head presets,
their initial daily-log/review grants, and per-user update acknowledgements.
Existing accounts keep their roles. Reapplication preserves explicit permission
choices and acknowledgement state. The Wiki and daily-log installers also apply
migration 026 after their prerequisites.

For **each release**, prepend an entry to `docs/releases.json`: use a new immutable
`id`, a higher integer `order`, its `date`, `title`, and a list of `changes`.
Retain earlier entries. Dates must follow order, including a higher order for
another release on the same date. `SystemUpdates::releases()` validates the
manifest; both modal history and new-release detection read it. Code edits alone
do not invent release notes. Update the manifest as part of publishing changes.

## Team Monitoring and daily logs

The **Team Monitoring** navigation group contains **Team Monitoring**, **My Daily
Logs**, **Review Daily Logs**, and **Reports**, according to the signed-in user's permissions.

- **My Daily Logs:** add work activities with activity date, classification,
  activity type, projected/planned task, actual activity performed, decimal hours
  and remarks. All three classifications and twenty activity types are available.
  Leave and undertime applications are managed in the separate **Leaves** module.
- Save incomplete entries as **Draft**, or **Submit** completed entries for
  review. Draft and returned entries can be edited or deleted by their owner.
  Owners with Edit permission can also edit Submitted entries until approval.
  Submitted edits remain submitted, and reviewers must refresh a changed weekly
  snapshot before approving. Reviewed entries stay locked. Returned entries show the reviewer's
  comment and can be corrected and resubmitted. Changes check the record version
  to prevent overwriting a concurrent edit or review.
- **Leaves:** a separate department calendar shows submitted and approved leave
  from every section in the signed-in user's department. **Date From / Date To**
  creates one application covering working days only, with one approval for the
  entire range. The calendar also shows recurring personnel birthdays; enter
  birthdays when creating or editing a user. Birth years and ages stay hidden.
  Apply for VL, SL, ML,
  EL or UT on a working day; AM/PM is 4 hours, whole-day leave is 8 hours, and
  undertime uses entered hours up to 8. Draft and returned requests stay private.
  Remarks are readable only by the applicant and authorized section approvers.
  Grant **Leaves ? View and Review** to the role allowed to approve requests in
  its own section, through **Approvals > Leave**. Section Heads have Review by default and may approve their
  own leave; other roles need another approver. Approval and return use the
  current request version. Approved requests must be returned before editing.
  Existing leave records are retained, as are monitoring and report totals.
  New roles receive calendar View by default. Other grants remain configurable.
  Existing installations run `php bin/install-leaves.php` (migrations 029-031).
- **Approvals > Daily Logs:** one row per employee/week, Monday–Sunday, with all
  submitted work entries. **Approve Week** approves the entire work week;
  **Return / Reopen Week** returns it with one required comment. Several employee
  weeks can be reviewed atomically. Section Head and Department Head roles may
  approve or return their own weeks with View and Review grants, without an
  assigned section reviewer. Other roles cannot approve their own weeks. No
  reviewer can read another person's private drafts. New or corrected submissions invalidate the previous
  approval for the whole week. Concurrent changes require a fresh review.
- **Team Monitoring:** active team members, Monday-Friday daily hours, approved
  leave/undertime, adjusted weekly work target, completion and member log details.
  Under each employee, **hours entered** and **hours still needed** show submitted
  and approved entries against each working day's target. Drafts and returned
  entries do not count. Extra hours on one day do not replace missing hours on
  another. Approved completion remains separate from entered hours.
  Working days are Monday–Friday, excluding organization holidays, 8 hours/day.
  Submitted or approved leave reduces hours still to enter; only approved leave
  reduces the approved work target. Weekends/holidays do not satisfy the target.

Administrators maintain the shared calendar with **Parameter > Holidays**.
Add, edit or delete dates and names; changes recalculate targets for every section.
No holidays are automatically assumed. Users with Holiday View can view
the calendar. Leave/undertime submissions require a working day. Work on weekends
or holidays may still be recorded and remains visible in detailed reports.

Open the separate **Reports** module under Team Monitoring. Its default **Section
Daily Logs / Weekly Summary** matches `Sample Output.xls`: one worksheet per
employee, the orange title/name/totals, blue SD/SCR/Project headers, twenty activity
codes and totals, plus a separate leave table (date, leave type, period and hours).
Only approved weekday work contributes to this summary; approved leave is listed
separately. Blank template sheets are not exported.

Choose **Detailed Daily Entries** for a row-by-row report,
a section, inclusive start/end dates and an optional status, then **Export Excel**.
One workbook contains a worksheet for every person assigned to the section,
including inactive personnel and people with no matching entries. Every dated
work or leave entry is included, with classification, activity type, planned task,
actual work, duration, remarks, submission date and review details. Weekends inside
the date range are included. Status defaults to all Submitted, Reviewed and
Returned entries; private Drafts are always excluded. Work and leave hours have
separate totals. Preview shows the personnel, entry counts and totals before export.

Choose **Weekly Summary** in Reports for the previous spreadsheet layout, or
**My Weekly Summary** for your personal report. It displays the report name, Monday-Friday dates,
all twenty activity types, SD/SCR/Project hours, row totals and column totals in
the reference spreadsheet layout. It includes approved (Reviewed) work only;
leave, undertime and weekend entries are excluded. UT here means Unit Testing.
Choose another week and **Generate Report**, or use **Print / Save PDF**. Report
names use the initial of every first-name word, the first middle-name initial and
the full surname, all uppercase: Michael Adrian Dimaano Monfero becomes
**MADMONFERO**. Missing middle names are omitted; compound surnames are retained.
Personal reports always use the signed-in employee.

In **Reports**, select **Weekly Summary**, a section and week, then **Export Excel**.
One XLSX workbook contains a worksheet for every person assigned to that section,
including inactive personnel and people with no approved work. Each sheet uses
the report name and the same approved weekday hours and totals as the on-screen report. Duplicate or
long worksheet names receive safe, unique Excel titles; the report heading keeps
the full formatted name.

Any preset or custom role can use **Team Monitoring** when an administrator
enables its **View** permission in Roles & Permissions. New custom roles start
with this permission unchecked; enable it and assign the role in Users. No
reviewer assignment is required to monitor. Non-administrators can view only
their own section, and private drafts are excluded. Reports retains separate
View/Export controls. Review Daily Logs still requires its own grants and an
explicit reviewer assignment.

Administrators assign one reviewer per unit/section in **Review Daily Logs →
Section Reviewers**. Eligible users must be active, belong to that section, and
have Review Daily Logs view and review permissions. Choose **Unassigned** to
remove an assignment. No assignments are inferred or populated automatically.
Unassigned sections can only be reviewed by an administrator.

Users with Team Monitoring View can monitor their own section regardless of role.
Reports requires its own permissions as well as section access.
Approving or returning other employees' logs requires reviewer assignment and review grants for non-administrators.
This restriction covers lists, details, filters and individual/bulk decisions,
including forged requests. Removing/replacing an assignment, moving the reviewer
to another section or disabling the account removes review access on the next
request. Role permissions continue to restrict the available modules and actions. Approval transactions hold the assignment
stable, and stale assignment forms are rejected. Administrators can access and
approve across sections without an assignment. Section Head and Department Head roles may approve their own submissions. Other
roles need another authorized reviewer, including an administrator. Record and assignment changes
participate in the existing audit trail without copying private activity/remarks
text into audit metadata.

Existing installations apply migrations 020 through 026 after migration 019:

```
C:\xampp\php\php.exe bin/install-daily-logs.php
```

Fresh installations use `database/schema.sql`, which includes these migrations.
Migration 022 enables Analyst monitoring and section exports, and disables
Applications/Requirements for the non-Administrator presets on its first run.
Custom role choices are preserved. Reapplying it preserves subsequent permission
changes, reviewer assignments and logs.
Migration 023 adds the independent Reports View/Export controls, copies existing
daily-log view and section-export grants, and preserves them on reapplication.
Report controls now live in Reports instead of the daily log/monitoring pages.
Migration 024 adds weekly decision records and the organization holiday calendar.
Existing logs and their historical review values are preserved. Reapplying the
migration preserves entered holidays and weekly decisions.
Verification uses disposable PostgreSQL schemas, users and browser sessions:

```
C:\xampp\php\php.exe tests/daily-logs.php
python tests/daily-logs-browser.py
```

## Roles, reminders, review tracking and application ownership

The current roadmap is [Upcoming functions.md](<Upcoming functions.md>). Items
1–4 and 7, OWASP support and Team Monitoring are implemented; the other ten roadmap items remain planned.

### Roles & Permissions

Administrators create custom roles and manage their permission matrix under
**Roles & Permissions**, then assign roles through **Users**. Choose **New role**
to enter a name, stable key and optional description. Start with minimal access
(dashboard, personal tools and public viewing), or copy any non-Administrator
role's current permissions. Copies are independent. Custom role names/descriptions
can be edited; their keys stay fixed. The page shows assigned user counts and warns
about unsaved permissions. Reassign every user before deleting a custom role.
The six preset roles remain available and cannot be renamed or deleted.
Role changes and permission changes are recorded in Audit Logs.

View is required before other module actions apply.
Applications and Requirements are excluded from Analyst, Reviewer and Viewer
defaults; administrators can explicitly enable them in the permission matrix.
Credentials and protected authentication email previews have dedicated controls.
The vault also retains its creator/share checks. Navigation and controls follow
the server authorization rules; forging a request does not bypass them.

| Role | Initial access |
|---|---|
| Administrator | All modules/actions; role administration. |
| Analyst | Maintain business records, start reviews, monitor their own section and export reports. Applications and Requirements require explicit grants. No administration, vault or formal review decisions by default. |
| Reviewer | Read enabled business modules, assign reviewers and record finding decisions. Applications and Requirements require explicit grants. Monitoring follows the View permission; review decisions require a section assignment. |
| Viewer | Read enabled business records; Applications, Requirements, exports and mutations require explicit grants. |
| Section Head | Personal daily logs, section monitoring, reports, holiday viewing and weekly self-approval. Reviewing other employees requires a section reviewer assignment. |
| Department Head | Personal daily logs, section monitoring, reports, holiday viewing and weekly self-approval. This role alone does not grant department-wide approvals. |

On first upgrade, existing accounts default to Analyst. If no active Administrator
exists, the active employee ID `admin` is preferred, otherwise the oldest active
account is promoted. Existing credentials/profile values remain intact; assigning
the initial role naturally updates the Users modification timestamp. The last
active Administrator cannot be demoted, disabled or deleted through the app.
Non-administrators cannot change roles or manage Administrator accounts.
Role/matrix changes take effect on the next request.

Public Call Directory and Quick Links viewing remains available without signing
in. Account self-service and personal notifications remain available to signed-in
users. Roles control modules/actions, rather than assigning private application
workspaces. Application ownership determines reminder recipients.

### Application Catalog

Open **Applications** to manage a unique code/name, description, lifecycle,
criticality, business/technical owners, department and application/repository URLs.
Link existing servers, hosted/group systems, dictionary tables, ERDs, requirements
and Quick Links. Foreign keys and duplicate-link protection keep relationships
valid. Source-module permissions govern related-asset lookup/visibility.
Deleting a catalog entry removes its links while retaining existing inventory
records and saved reviews.

Choose an application when scanning code or exporting Program Inventory.
Application details show recent reviews and inventory export counts/versions.
Inventory files and raw source contents are not stored in the catalog.

### Saved reviews and OWASP support

Every successful source scan saves server-produced file metadata, counts and
findings. Use **Code Review → Saved reviews** to link an application, assign an
eligible reviewer and due date, record notes and finding decisions, and compare
runs. Finding states are Open, In Progress, Fixed and Accepted. Fixed/Accepted
require a verification or exception note. Review completion requires a note and
no open/in-progress findings. Updates detect concurrent finding changes.

Comparison identifies new, continuing and no-longer-detected findings. Absence
does not establish a fix: file selection, skipped files, caps and pattern coverage
affect the result. Fingerprints use path, rule, message and occurrence order.
Accepted exceptions carry to the next comparable run. Notes and decisions retain
their authors and timestamps. Saved findings export to CSV with formula escaping.
Raw source and function inventory literal values are excluded from stored reviews;
the original full function matrix remains available in the active scan's export.

The fifth engine is **OWASP Top 10:2025**, based on the
[official OWASP release](https://top10.owasp.org/2025/). It provides bounded static
patterns, category IDs, suggested remediation and ten manual coverage checkpoints.
Its XLSX/ODS export includes category/remediation columns and an OWASP Coverage
sheet. No-findings categories remain Manual review required. Design, dependencies,
authorization flows and deployment behavior require human review; these checks
do not certify OWASP compliance or query a dependency vulnerability database.

### Notifications & Reminders

Open **Notifications** for a personal read/unread inbox and email preference.
Assignment creates an immediate review notification. Renewal reminders go to
eligible business/technical owners linked through servers or hosted systems;
active Administrators receive reminders when eligible owners are unavailable.
Overdue review reminders go to the assigned eligible reviewer.

Administrators configure expiry intervals (initially 30, 14, 7, 1 and 0 days),
overdue repeats (initially 7 days), and email delivery. The next check catches a
missed threshold, and duplicate checks do not create duplicate inbox items.
Email starts disabled. Configure/test SMTP and the canonical Application URL in
Parameters, then enable reminder email in Notifications. Enabling email queues
unread reminders from the previous 24 hours for users with email enabled.
Disabled, locked, opted-out or unauthorized recipients are skipped.

The worker processes at most 25 queued emails per run, claims jobs safely,
retries failures up to five times and records safe delivery status. Email can be
retried after an interrupted worker, so delivery is not guaranteed exactly once.
Messages contain a generic reminder and an authenticated internal link, without
source, credentials or review excerpts.

On this workspace, **AnalystToolkit Notifications** is installed in Windows Task
Scheduler, hidden, every 15 minutes, using `C:\\xampp\\php\\php-win.exe`. Its first
run completed with result 0. Windows denied background S4U logon, so the installed
task runs while the current Windows account is signed in. No execution policy
was changed. Configure an approved unattended task account for a server that
must deliver reminders while nobody is signed in.

Other Windows installations can run:

```
C:\\xampp\\php\\php.exe bin/install-notification-task.php --interactive
```

Omit `--interactive` when the task account is authorized for background S4U logon.
The native installer uses UTF-16 task XML and does not change PowerShell policy.
A PowerShell installer is also supplied for environments permitting local scripts.
On other operating systems, schedule `php bin/notifications.php --deliver`
every 15 minutes. Opening Notifications also generates due in-app reminders, but
email delivery requires the worker. Use `php bin/notifications.php` to generate
in-app reminders without delivering email.

### Daily-log and weekly-approval reminders

Apply `database/migration_028_action_reminders.sql` to an existing installation;
new installations include it in `database/schema.sql`. It adds reminder settings,
notification context and an action-query index, retaining existing operational
records, grants and email settings.

In **Notifications > Reminder delivery settings**, administrators can enable
employee and approval digests independently, choose the Asia/Manila check hour
(default 17), calendar-day lookback (default 14) and approval delay (default 1 day
since the latest submitted entry in a week). Checks run on weekdays excluding
organization holidays, using the existing 15-minute task and throttled inbox /
unread-count checks. Missing hours include Submitted and Reviewed work/leave;
Draft and Returned entries do not satisfy required hours. Missing-hour reminders
start at the account creation date and require daily-log creation access. Returned
week reminders require editing access. Each user receives at most one digest of
each type per working day. A late worker creates the digest at its next working-day
check after the configured hour.

Weekly reminders count employee weeks. Assigned eligible section reviewers receive
their queue; named heads can receive their own self-review reminder. Administrators
cover weeks without an eligible reviewer, including an ordinary reviewer's own
week. Role revocation, account lock, transfer, approval and corrections are checked
again before delivery. Resolved, disabled or previous-day digests are marked read.

New daily-log reminder email is **off by default**, even when other reminder email
is already enabled. Sending requires the separate log-email option, global email
delivery, the recipient email preference and the existing `--deliver` worker.
No SMTP work is performed by the dashboard or search. The existing Windows task
remains installed under its compatibility name, **AnalystToolkit Notifications**.

### Search workspace

The shared topbar combines module navigation and record results. Type at least two
characters (up to 120), then use arrow keys / Enter or click a result. Searches are
debounced, canceled when superseded and limited to 30 results, with up to five per
record category. Only authenticated users can call the record endpoint.

Results cover authorized personnel, server names / IPs / environments, dictionary
tables and fields, ERDs, saved code reviews, public department contacts and access
links, and the actor's own daily logs (including private drafts). Personnel results
follow Users access or the user's authorized team / review scope. Other people's
private drafts, vault values, stored connection settings, server notes and unsafe
URL fields are never searched. Applications and Requirements remain hidden.
Results open record details or the relevant filtered workflow; dictionary fields
open their table with the selected field filtered into view.

Verify these features with `C:\xampp\php\php.exe tests/action-center.php`,
`python tests/devatlas-browser.py` and `python tests/daily-logs-browser.py`.

### Workflow installation and verification

Existing installations need `database/migration_018_workflow.sql` after migration
017, followed by `database/migration_019_custom_roles.sql`. Both are applied to this
workspace; fresh `database/schema.sql` includes them.
Customized permissions survive reapplication. New workflow tables have audit
change triggers. The component/browser suites use disposable schemas, accounts,
sessions, browser profiles and a loopback SMTP catcher:

```
C:\\xampp\\php\\php.exe tests/workflows.php
C:\\xampp\\php\\php.exe tests/custom-roles.php
python tests/workflow-browser.py
```

## Audit Logs

Open **Logs → Audit** to review activity across all modules. The read-only viewer
shows who acted, when, the module and action, the outcome, HTTP status, IP address
and affected record references. Filter by user, module, outcome, activity source
and dates; search history, open event details or export the filtered results to CSV.
Times in the viewer use Asia/Manila. Pagination keeps a snapshot boundary until
you refresh, so new activity does not shift the current page.

Shared request hooks cover page views, API reads, create/update/delete operations,
imports, exports, credential reveals, account changes, sign-in/sign-out, recovery
and failed or denied requests. Database triggers attach committed row changes
to the request, including cascaded changes. Rolled-back changes are excluded.
Authenticated browser reports supplement these with clicks, submissions, copying,
filter/appearance changes and search interactions. They are labelled **Reported**
because they do not prove an operation succeeded; closed tabs and network loss
can interrupt browser reporting.

Passwords, hashes, credential values, one-time codes, reset tokens, source contents,
filenames, typed search text and free-text record values are excluded. Changes
retain table/record IDs and changed field names, plus before/after values only
for an explicit list of safe status, policy and type fields. Actor snapshots remain
after an account is deleted. History requires the Audit Logs View permission (Administrators have it by default).
CSV export requires Audit Logs Export permission. The UI
has no edit/delete operation, and database triggers reject changes to finalized
history or truncation. A database owner can still alter those protections.

History starts when auditing is enabled; earlier actions cannot be reconstructed.
CLI maintenance and direct external database writes have no HTTP actor and are
outside this trail. There is no automatic history deletion. The installation's
existing backup process should include `audit_events` and `audit_changes`.

`database/migration_017_audit_logs.sql` has been applied to this workspace's
configured database with existing records preserved. Apply it after migration
016 on other existing installations. Fresh `database/schema.sql` includes it.
Verification uses disposable schemas, accounts, sessions and browser profiles:

```
C:\xampp\php\php.exe tests\audit.php
python tests/audit-browser.py
```

## Sign in

The login page has a responsive illustrated workspace panel, a focused sign-in
form, light/dark themes, password visibility, Caps Lock feedback and a submission
indicator. Failed submissions retain the employee ID and show an accessible error
message without echoing the password. The public Call Directory and Quick Links remain available
from the sign-in page. Choose **Password** or **Email code**, or use **Forgot
password?**. Email requests accept an Employee ID or an unambiguous registered
email. Sign-in codes expire in 10 minutes, are bound to the requesting browser,
allow five incorrect attempts and work once. Reset links expire in 30 minutes
and work once. Resending replaces the earlier credential; request limits and a
60-second cooldown apply. Account locks, disabled status and required password
changes are respected. Return paths are restricted to this application.

Set outgoing SMTP and the **Application URL for password reset links** in
**Parameters**. The default URL is `http://localhost/AnalystToolkit`; use the
server address when users access the app from other devices. Test SMTP after
saving. PHPMailer is now included in the lock file and installed by `composer
install`. The existing ZipStream lock was adjusted to 3.1.1 for the project's
PHP 8.1 platform target; spreadsheet export/import was checked after this change.

**Email unavailable? View protected details** opens a new tab. It requires the
Users View and Support permissions (Administrators have both by default).
Authorized support users can view delivery status and the active
code/reset link, including when SMTP or the registered address is unavailable.
The requester should keep the original code tab open and have support open the
details link in their own browser. Recent details are also linked from Users and
Parameters. Unknown, disabled, locked and ambiguous accounts create no email
record; public request confirmations never reveal account or delivery status.

Preview credentials are encrypted with AES-256-GCM using APP_ENCRYPTION_KEY;
verification uses keyed hashes. Preserve the installation's encryption key.
Used, expired or revoked credentials cannot be displayed; recoverable copies are
cleared on use and during subsequent cleanup. Delivery records and hashed request
buckets are retained for at most 24 hours before the next cleanup. Preview reads
are audited without logging codes or reset links. Password resets revoke pending
credentials and invalidate old sessions at their next account check (within
30 seconds); recovery does not automatically sign the person in.

Existing installations need `database/migration_015_email_auth.sql` after migration
014. It adds challenge/request-limit tables, session versions and the canonical
application URL. Fresh `database/schema.sql` includes these definitions. Migration
015 has been applied to this workspace's configured database with existing account
and SMTP values preserved. Verification commands:

```
C:\xampp\php\php.exe tests\email-auth.php
python tests/email-auth-browser.py
```

The browser suite uses disposable accounts/schema/sessions and a loopback SMTP
catcher; Microsoft Edge and the configured local PostgreSQL service are required.

## Organization and Users

Organization and Users use a shared responsive workspace layout with summaries,
search, loading/error/empty states and native dialogs. Organization has a five-level
explorer, ancestor-preserving search, expand/collapse controls, a selected-entry
details panel and one editor for all hierarchy levels. Users has clickable status
totals, department/status filters, sorting, 15-account pagination, compact account
actions and a cascading placement editor. Destructive actions require a named
confirmation; password resets also require matching confirmation fields. Mobile
user rows become cards, and dialog save controls stay visible as the form scrolls.

Organization and Users are separate modules in the sidebar. Organization owns the
Group → Division → Department → Unit → Position tree and its read-only lookups.
Users owns account creation, placement, password resets, unlocking, and account
status. Call Directory links to Organization for structure changes.

Organization deletion requires an empty node; child records, department contacts,
and assigned users must be removed or reassigned first. User saves validate that
the selected organization levels form one parent-child chain. The Users page adds
account-status filtering, quick enable/disable, and protection against duplicate
save clicks. Sessions recheck account status every 30 seconds so disabled or
deleted accounts lose access. No database migration is required for this split.

## Parameters

Password Policy, Email Delivery and Recovery Links are separate sections with
their own validation, Save/Discard controls and unsaved-change indicators. Saving
one section preserves drafts in the other sections and updates only that section
in the database. The API retains the existing full-settings save format for older
callers. SMTP passwords are never returned; a saved-password indicator explains
whether leaving the field blank will keep an existing value. Test email uses the
saved connection settings. Summary cards show saved values; Configured indicates
that SMTP host and sender are present, rather than confirmed inbox delivery.

## Program Inventory

Choose **This computer** for a browser-selected folder or **Application server**
for a path on the host. Enter the project name, version and developer, then review
local file metadata with search, file-type filters and 20-file pagination. The
export summary shows included/excluded counts, total size and the first file ID.
Changing preview filters does not change which included files are exported.

Only file metadata is sent from the browser. Folder exclusions apply to directory
segments, not identically named files. Server paths are scanned when Scan & Export
is selected, with progress feedback and inline errors. Both modes download the
existing 11-column Excel format. String values are written as literal spreadsheet
text, and leading dots in directory names are preserved. Sample project/person/path
defaults have been removed; developer defaults to the signed-in person's name.

## DevAtlas identity

DevAtlas uses a folded atlas mark, local Poppins typography and Lucide SVG icons.
The shared enterprise palette is electric blue, midnight navy and cyan, with a
navy sidebar in both themes. Native mark/wordmark SVGs and transparent PNGs are
in `assets/brand`. Legacy asset filenames display the new identity as well.
Third-party asset licenses and pinned sources are in `assets/vendor`.

Poppins fonts, the Lucide sprite and SweetAlert2 11.26.25 are served locally on
every module and account page. `AT.toast(message, type)` displays queued
notifications; `await AT.confirmAction(message, title, action)` resolves to a
boolean; `await AT.alert(message, type, title)` shows informational feedback.
`WUI.confirm(title, message, action)` uses the same SweetAlert2 confirmation.
Always await a confirmation before sending a mutation. Confirmations open above
existing editors and focus Cancel by default. Feedback follows the shared Light,
Dark and System theme. No database changes are required for these UI assets.

Run `python tests/devatlas-browser.py` for typography, local assets, theme,
responsive layout and SweetAlert2 integration checks across all module pages.

Run `python tests/workspace-browser.py` for the disposable module UI/HTTP fixtures.
The suite uses an isolated PostgreSQL schema, test accounts, loopback SMTP and
real Excel downloads. No database migration or new package is needed for this UI
refresh. Shared styles/scripts are `assets/css/workspace.css` and
`assets/js/workspace-ui.js`, with a scoped stylesheet and script for each module.

## Call Directory

The Call Directory at `/AnalystToolkit/modules/directory/index.php` is available
without signing in. Guests can search department names, descriptions, groups,
divisions, emails, and phone numbers; filter by organization or contact type;
switch between cards and a list; copy contacts; open email and telephone links;
and print the filtered directory. Filtered URLs can be shared. The sign-in page
includes a link to browse the directory.

Signed-in users can add, edit, and remove department emails and phone numbers in
the contact editor. The API allows anonymous GET requests only for directory
list/detail reads; all mutations require a valid session, completed password
changes, POST, and CSRF validation. Contact saves validate formats and lengths,
reject duplicates, and check department ownership. Other modules remain protected.
No database migration is required.

## Server Inventory

Server Inventory has three views: Servers, Groups, and Renewals. The server list
uses five columns, search, environment/group/status filters, and pagination. Select
a server name for its Overview, Applications, Access, and Credentials tabs. Group
members use the same server list through a group filter; shared applications stay
in the group view. Renewals can be filtered by type and expiry status.

One reusable editor handles servers, groups, applications, access methods,
credentials, and renewals. Optional server hardware fields are collapsed for new
records. Existing RDP downloads, connection copying, encrypted credentials, and
owner-controlled sharing remain available. Password reveal is temporary, and
password copying uses a generic confirmation. Access remains restricted to signed-in
users. No database migration is required.

## Data Dictionary (migration 014)

The catalog has database/engine/status/documentation filters, coverage indicators,
sensitivity badges, pagination and a focused table detail view with Overview,
Columns and Relationships tabs. Fields include descriptions, business rules,
defaults, identity flags and sensitive/PII annotations. Editors validate metadata
and show errors inside the dialog.

Live metadata adapters cover PostgreSQL, MySQL, MariaDB, SQL Server/Azure SQL,
Oracle, SQLite, IBM Db2 LUW, Firebird and generic ODBC catalogs. The import dialog
reports the installed driver capabilities; live access requires the corresponding
PHP driver, vendor client and database catalog permissions. SQLite uses an existing
server file opened read-only. ODBC System DSNs can cover additional compatible
engines; metadata completeness depends on the driver. Any engine can be documented
manually or through the portable metadata JSON format, without a live driver.

Imports browse schemas, preview fields and select tables/views before processing
one object at a time, with progress, results, retry selection and Stop after current
table. Re-import preserves annotations and reports missing source columns retained
for review. Connection passwords stay out of URLs and profile responses. Newly
saved passwords use authenticated encryption with `APP_ENCRYPTION_KEY`; legacy
plaintext values are upgraded when a connection is used. Back up this key with
the configuration, separately from database backups.

Database exports include the Annex E Excel layout plus a Field Details sheet with
rules, defaults and sensitivity flags, or round-trippable metadata JSON. Download
an example JSON file from Import metadata. Portable limits are 6 MB, 500 objects,
2,000 fields per object and 25,000 total fields; live import processes at most 25
objects per API request, with the UI sending one at a time.

Existing installations need `database/migration_014_dictionary_engines.sql` after
migration 013; it has been applied to this workspace's configured database with
record counts preserved. Fresh installs use `database/schema.sql`. Driver setup,
engine versions and format details are in [database/DATA_DICTIONARY_ENGINES.md](database/DATA_DICTIONARY_ENGINES.md).
Run `C:\xampp\php\php.exe tests\data-dictionary.php` for the regression fixtures.

## ERD Generator

The former Diagram Generator now focuses on ERDs. Choose **Generate ERD**, select
documented Data Dictionary tables and optionally include referenced tables. Each
model includes column types, PK/FK markers and required-column indicators.
Generation uses saved metadata; it does not connect to the source database or
read table records. Data Dictionary table details also provide a **Generate ERD**
shortcut.

Foreign keys resolve within the same dictionary database group, using qualified
schema names where available. Missing/ambiguous references produce visible
warnings rather than guessed edges. Cardinality is inferred from nullability and
single-column primary keys: unique constraints and composite foreign-key groupings
are not available in the dictionary and need human verification.

Each saved ERD is an independent snapshot. Drag headers or use arrow keys to
arrange tables, save the layout, edit table columns, and add/edit relationships.
Generate a new ERD to include subsequent dictionary changes. GraphML opens in yEd;
SVG exports the canvas; Excel preserves stable keys, types, relationship metadata
and literal text. Excel import explicitly replaces the current snapshot after
confirmation and validation, rejects formulas and invalid references, and leaves
the saved model unchanged on errors. Limits are 50 tables, 5,000 columns and
5,000 relationships per ERD.

Apply `database/migration_016_erd_generator.sql` after the existing migrations.
It has been applied to this workspace's configured database, with existing
diagram/node/edge contents verified unchanged. Fresh schema.sql includes the
new metadata columns. Existing DFD/SFD/PFD records remain stored; the ERD module
does not list or edit them.

## Access Links / Quick Links

The resource catalog at `modules/bookmarks/index.php` is available without
sign-in and is linked from the login page. Visitors can search, filter tags,
sort, page through cards, open/copy destinations and pin favorites on their
device. Pins use browser storage and do not change the shared catalog.

Creating, editing and deleting links requires normal signed-in access and CSRF.
The editor accepts only complete HTTP/HTTPS addresses without embedded credentials.
Unsafe legacy destinations are marked unavailable, and their addresses are hidden
from guest API responses. All catalog titles, descriptions and tags are public;
signed-in editors are told that new links will be visible to visitors.

Verification:

```
C:\\xampp\\php\\php.exe tests\\erd-generator.php
python tests/modeling-browser.py
```

The browser suite uses disposable metadata/accounts/sessions and a local Edge
profile. It checks guest access boundaries, dictionary generation, saved layouts,
manual edits, literal spreadsheet values, atomic import rejection, all five review
engines, exports, light/dark themes and 320–1366 px layouts.

## Code Review

Code Review uses source selection, five engine cards, optional scan filters and
a progress/cancel bar. Results identify the engine and source used; changing the
configuration marks the old report as stale. Standard/security results have
searchable findings, severity filters, a files-reviewed view and pagination.
The PHP function inventory uses a compact preview with expandable details; full
spreadsheet exports retain the complete matrix. QMS retains all 24 criteria,
coverage notes and manual review statuses.

The source chooser uses selectable **This computer** and **Application server**
cards, a centered **Choose folder** action, and a selected-folder summary with
file count and size. Use **Change folder** to replace the selection. The server
card uses the existing application-directory list. Its notice explains when
source contents are read or sent for analysis.

The Code Review module can scan a folder selected in the browser or one project
folder under the server web root. It reports per-file lines, functions, TODOs,
`eval()` calls, long lines, and findings from either standard diagnostics or
security pattern checks. The Source code inventory engine builds a PHP function and
method matrix with paths, IDs, descriptions from doc comments, arguments, assigned
variables, inferred types, out parameters, and returns. It includes search and
pagination. Filters include type, modification age, and filename.
**QMS CodeSniffer** adds the 24-criterion QMS checklist, per-file statuses,
criterion/severity/search filters and line findings. Select Existing, New or
Modified scope and 2-space, 4-space or tab indentation. The opening comment must
contain Filename, Purpose, Developer, Date and Recent updates. New/modified block
comments and modified-file change trails are checked according to the selected
scope; a reviewer verifies each change-trail placement against a diff.
Exact QMU database naming and SQL template rules remain manual until supplied.
Semantic checks, SAD approval, database ownership, password encryption outside
the source and successful log creation require reviewer evidence. “No issues
detected” refers to the static checks performed, rather than a compliance verdict.
Results can be exported to XLSX or ODS, including a Source Inventory sheet when
that engine is selected. QMS exports include QMS Checklist, QMS File Criteria and
QMS Review Profile sheets, plus criterion and reviewer-confirmation columns in
Findings. Browser scans send supported source file contents
to the server for the request only; saved review history retains findings and metadata,
not raw source files. Both computer-folder and server-folder scans support up to
3,000 files, 256 KB per file, and 48 MB of source text. Folder exclusions still apply.
Encoded scan requests are capped at 96 MB and export report payloads at 24 MB.
Code Review supplies a scoped 128 MB PHP POST allowance and 300-second timeout
through `.htaccess` (Apache module) and `.user.ini` (CGI/FastCGI). Large QMS workbook
exports can raise the request's memory allowance to 1 GB. Inventory
reports are capped at 3,000 functions. Inferred types and security checks require
human review. The samples in `codereview/`
is retained as a reference and blocked from direct web access.
QMS findings are capped at 25 per criterion per file and 5,000 displayed findings
per scan; checklist counts retain detected matches and limits are shown explicitly.
Run `C:\xampp\php\php.exe tests\qms-codesniffer.php` for the QMS regression fixtures.
Run `C:\xampp\php\php.exe tests\code-review-limits.php` for scan file and byte boundaries.

## Requirements Traceability (migration 013)

The Requirements module records requirement IDs, descriptions, status, priority, and
owners. It links requirements to diagrams, Data Dictionary tables or columns, and
Server Inventory hosted or group systems. The Impact view lists requirements linked
to an asset; for a table, it also includes links to that table's columns. The Excel
export produces one row per requirement and linked asset, including requirements with
no links.

For an existing installation, run `database/migration_013_requirements_traceability.sql`
after migration 012. A new installation gets the same objects from
`database/schema.sql`. Excel export uses the existing Composer dependencies.

A local-first, offline-capable System Analyst Tool for a **WAMP + PostgreSQL** stack.
Zero CDN dependencies, zero AI integrations — every CSS/JS asset is self-hosted, and all
data lives in your own local PostgreSQL instance.

## 1. Directory structure

The current XAMPP workspace is `C:/xampp/htdocs/AnalystToolkit`.

```text
AnalystToolkit/
  app/
    Config/          Explicit routes and framework configuration
    Controllers/     Module page, API and export actions
    Views/           Readable module templates, layouts and partials
    Helpers/         Application presentation helpers
  public/            CodeIgniter HTTP bootstrap and public defaults
  resources/css/     Editable Tailwind source
  assets/            Local runtime styles, scripts, fonts and icons
  lib/               Domain services and review engines
  config/            Dotenv loader, compatibility constants and DB connections
  database/          Schema and incremental migrations
  modules/           Compatibility entry points for existing integrations
  bin/               Maintenance and formatting commands
  tests/             First-party regression suites
  docs/              Release history, architecture and quality evidence
  uploads/           User files; preserve when deploying
  writable/          Framework runtime storage
  vendor/            Installed Composer runtime dependencies
  node_modules/      Installed development/build dependencies
  index.php          Compatibility bootstrap for the application root
  spark              CodeIgniter command-line entry point
  env                CodeIgniter environment template (copy to ignored .env)
```

Module business logic lives in controllers and domain services; shared layouts
live in `app/Views/layouts`. The retained PHP entry points preserve existing links
and integrations. The application, dependencies and private recovery files are
protected by Apache rules; only runtime assets and permitted routes are public.

The private, ignored `.tools/` directory provides this workstation's portable
Node executable. `.stack-work/` retains protected installation recovery files.
See [migration notes](docs/STACK_MIGRATION.md) for build and recovery details.

## 2. Setup

### a) PostgreSQL
1. Install PostgreSQL locally (works alongside WAMP, listens on `127.0.0.1:5432` by default).
2. Create the database and run the schema:
   ```
   createdb -U postgres analyst_toolkit
   psql -U postgres -d analyst_toolkit -f database/schema.sql
   ```
   All application tables are created inside a dedicated `toolkit` schema.

   **Already installed the original schema and don't want to drop it?** Run the additive
   migration instead, which adds Server Groups / Group Systems and relaxes the environment
   field to free text without touching your existing data:
   ```
   psql -U postgres -d analyst_toolkit -f database/migration_002_server_groups.sql
   psql -U postgres -d analyst_toolkit -f database/migration_003_dd_connections.sql
   psql -U postgres -d analyst_toolkit -f database/migration_004_simplify_bookmarks.sql
   ```

### b) PHP / Apache
1. Use PHP 8.1 or newer with `intl`, `mbstring`, `pdo_pgsql`, `pgsql`, and the
   extensions required by Composer. Additional database engines need their own
   PDO/ODBC drivers. `composer check-platform-reqs` verifies installed dependencies.
2. Install the locked dependencies in the application root:
   ```sh
   composer install --no-dev --prefer-dist --no-interaction
   npm ci --ignore-scripts
   npm run build:css
   npm run check:views
   ```
   Composer is required for CodeIgniter itself as well as Excel and mail features.
   Node is needed for build/formatting tools only. Runtime assets are self-hosted.
3. Configure all installation variables in the ignored root `.env`. For a new
   installation, copy `.env.example` to `.env` only if it does not exist. Existing encrypted
   installations use `php bin/migrate-env.php` to retain passwords and the vault
   key, then review any non-secret values customized on that installation.
4. Set database credentials, the existing vault key, application name, URL and
   timezone in `.env`; see [environment configuration](docs/ENVIRONMENT.md).
   Native CodeIgniter settings use its normal dotted names in the same file.
5. Keep `writable/cache`, `writable/debugbar`, `writable/logs`, `writable/session`
   and `writable/uploads` writable by PHP. Git retains their placeholder files;
   generated content is ignored. Existing user uploads remain under root `uploads/`.
6. Use the Apache application directory at `/AnalystToolkit/` as the compatibility
   document root with `mod_rewrite` and `.htaccess` enabled. Do not switch to
   `public/` without configuring the existing asset/upload mappings; see the
   [deployment notes](docs/STACK_MIGRATION.md).
7. Run `php spark list` and `php spark routes` to verify framework startup.

No outbound internet access is required at runtime. Dependency installation and
updates require access to the package hosts. Browser regression suites currently
use Windows PHP/Edge paths; the existing PHP suites can also be run individually
with `php tests/<suite>.php` on a configured Linux installation.

## 3. Modules

The **Dashboard** prioritizes working-day hours to catch up, returned employee
weeks, private draft counts, and weekly approvals the current person can review.
Today becomes due at the configured reminder hour (initially 17:00 Asia/Manila).
The weekly balance includes upcoming working days, while overdue hours exclude
future dates, holidays and weekends. Overtime on one date cannot cover another
date. Section progress covers all authorized members, without list pagination.
Review links open the selected employee week; self-review retains the named-head
exception. Renewals follow each inventory item's alert interval. Compact inventory
counts and saved access links remain available. Applications and Requirements
remain hidden.

Cards and panels reflow to fill the available space. The header stays at the top while scrolling,
with workspace search (Ctrl/Cmd+K), a sidebar toggle and a compact date/time display.
The right-side module shortcut buttons have been removed. Sticky panels and
scroll targets account for the header's responsive height.
The sidebar brand row aligns with the topbar. The hamburger collapses navigation
to icons while preserving the logo and account avatar; the preference is saved
in this browser. Workspace search displays a dropdown below its input without
filtering or expanding the sidebar. Use arrow keys and Enter, or click a result;
Escape, Tab and outside clicks dismiss it. Quick Links also finds Access Links.
The account menu remains in the sidebar footer,
with account actions available as navigation scrolls. The account dropdown shows
your identity and sign-in time, expandable profile/contact details with copy
actions, photo upload with size/type guidance and status, Change password,
Light/Dark/System appearance buttons and Sign out. Profile details remain read-only;
administrators maintain names, contact information and organization assignments.
The dropdown stays within the viewport, scrolls on short screens, supports
keyboard opening and Escape, and closes when focus or a click leaves it.

| # | Module | Notes |
|---|--------|-------|
| 1 | **Data Dictionary** | Filtered catalog, documentation coverage, table/column editors, relationships, selective live imports across nine engine/driver options, portable metadata JSON and Excel exports. See the Data Dictionary section and engine setup guide. |
| 2 | **ERD Generator** | Generate editable ERD snapshots from Data Dictionary tables, columns, PK/FK metadata and documented relationships. Arrange tables, edit columns/cardinalities, and export GraphML, SVG or Excel; validated Excel imports replace the snapshot. |
| 3 | **Call Directory** | Public department contact browsing with search, organization/contact filters, card/list layouts, copy/email/call actions, and printing. Signed-in users maintain emails and phone numbers; structure is managed in Organization. |
| 4 | **Access Links / Quick Links** | Public view-only resource catalog with search, tags, sorting, device pins and pagination. Sign-in is required to create, edit or delete shared links. |
| 5 | **Server Inventory** | Servers, Groups, and Renewals views; searchable and filtered server list; server detail tabs for applications, access, and credentials; group-shared applications; certificate/license expiry tracking; one reusable record editor. Existing environment values are preserved when editing. |
| 6 | **Audit Logs** | Read-only user activity across every module, committed record changes, actor/module/date/outcome filters, event details and filtered CSV export. |

## 4. Security notes

- All state-changing API calls require a session-bound CSRF token (`X-CSRF-Token` header),
  generated per page load and validated server-side.
- All SQL uses parameterized PDO statements — no string-concatenated queries.
- `config/`, `lib/`, and `includes/` are denied direct web access via `.htaccess`.
- `CI_ENVIRONMENT` in `.env` controls error visibility. Use `production` outside
  local development.

## 6. Error Logs

The Logs module reads CodeIgniter 4 file-handler logs, normally
`writable/logs/log-YYYY-MM-DD.log`. The configured framework directory, extension
and date format are respected. Exceptions and their multiline stack traces remain
one event. Existing application logging calls now use the native framework logger;
old `logs/app-*.log` files are retained but excluded from this viewer.

CodeIgniter Logs shows plain-language explanations and suggested checks, groups
repeated events, and provides severity/area/search filters, occurrence counts,
sorting and pagination. Original messages and technical context are expandable;
Copy summary produces a support-ready report with common credentials hidden.

Counts describe the loaded preview, which can contain the latest 500 or 2,000
events. The reader scans at most the last 4 MB and identifies omitted history.
Times use Asia/Manila, including the clock on this page. Optional automatic
refresh runs every 30 seconds while the page is visible. Recorded events are
historical; suggested checks are rule-based guidance rather than confirmed causes.

Raw downloads retain original content. The More menu contains the diagnostic
entry action and daily-file deletion, with confirmation that the entire selected
file will be removed. Diagnostic entries use the framework information level;
when that level is disabled, the page reports that no entry was recorded. Set
`logger.threshold = 7` or `9` in `.env` to include informational diagnostics. Reads require sign-in; writes require POST and CSRF. File
access is restricted to valid daily log names, and `logs/` remains blocked from
direct HTTP access. Existing logs are not rewritten by the viewer. No migration
or new dependency is needed. Run `C:\xampp\php\php.exe tests\log-viewer.php` for
the reader and presentation fixtures.

## 7. Appearance

The DevAtlas palette is defined in `assets/css/app.css`, with the shared component
finish in `assets/css/devatlas.css`. Poppins and Lucide assets are self-hosted.
Choose **Light**, **Dark** or **System** in the account menu. Standalone/public
pages place the selector in the footer; headers have no theme selector. System
is the default and tracks live device changes. Browser preferences use
`devatlas_theme`; earlier `at_theme` preferences are accepted on first load.

## 8. Extending

- Add new yEd shapes by extending `GraphMLBuilder::shapeForNode()`.
- Add new Data Dictionary fields by altering `dd_columns` and mirroring the change in
  `modules/datadict/api.php` (`save_column`) and the modal form in `index.php`.
- The CSS in `assets/css/app.css` uses CSS custom properties (`:root { --primary: ... }`,
  overridden per-theme under `[data-theme="dark"]`) — retheme by editing the variables at the
  top of the file.
- Any PHP boolean value headed into a PDO `execute()` params array for a Postgres boolean
  column must go through the `pg_bool()` helper in `lib/helpers.php` first — binding a raw
  PHP `false` produces an empty string, which Postgres rejects.
