# DevAtlas stack migration ? 2026-10-29

DevAtlas now runs on CodeIgniter 4.7.5, HTMX 2.0.11, Alpine.js 3.17.5 and Tailwind CSS 4.3.3. PHP 8.2 or newer with `intl`, `mbstring` and the existing PostgreSQL/PDO extensions is required. Runtime frontend assets, Poppins, Lucide and SweetAlert2 are local.

## Application structure

- `public/index.php`: CodeIgniter HTTP bootstrap.
- `app/Config/Routes.php`: explicit routes; automatic routing is disabled.
- `app/Controllers/*Controller.php`: module page, API and export actions.
- `app/Views/`: module templates, layouts and partials, formatted with four-space HTML indentation and expanded attributes.
- `lib/`: the existing domain services and review engines, retained to preserve audited PDO transactions and workflow rules.
- `config/`: encrypted installation settings and the shared PostgreSQL PDO connection.
- `resources/css/app.css`: editable Tailwind theme, utilities and shared component source.
- `assets/css/tailwind.css`: generated production CSS; do not edit it manually.
- `assets/vendor/`: pinned runtime dependencies and their licenses.

Root and `modules/*/*.php` files are compatibility front controllers with no module business logic. They forward to CodeIgniter, preserving installed links, report/export URLs, JSON fields and audit route identities. The existing XAMPP application directory remains the compatibility document root. Its `.htaccess` denies source, configuration, dependencies, developer tooling and dot directories. Do not point a new virtual host at `public/` without also configuring the existing `/assets` and `/uploads` URL mappings.

`Config\Database` uses the existing encrypted PostgreSQL settings. Current domain services deliberately retain `Database::GetConnection()` so their transaction boundaries, audit context, schema selection and disposable-schema fixtures remain intact. No database schema migration is part of this conversion.

## Clean URLs and compatibility

Navigation uses explicit extensionless routes, including `/AnalystToolkit/login`, `/AnalystToolkit/change-password`, `/AnalystToolkit/modules/dailylogs/` and `/AnalystToolkit/modules/codereview/history`. The `/AnalystToolkit` installation prefix remains unchanged. `lib/RouteUrls.php` maps canonical URLs to the existing route identities used for authorization and audit actions; `app/Config/Routes.php` declares both route forms with automatic routing disabled.

The private-response filter redirects legacy GET page URLs with HTTP 302, preserving the query string. POST requests, APIs and downloads are served directly at their existing addresses, so request bodies and client integrations remain compatible; explicit extensionless aliases also exist. Controller redirects and rendered local navigation use clean destinations. Forced password changes and the audit collector recognize clean routes. External URLs, uploaded files and assets are unchanged.

New view links should use `H(RouteUrls::Url(APP_BASE_URL . '/modules/example/index.php'))`, or an already canonical local URL. Do not rewrite database grants, API action names or audit action identities to match URL spelling. Route mappings and permission identities are covered by `tests/route-urls.php`; browser suites exercise clean navigation and legacy bookmarks.

## Request and session behavior

`BaseController::_remap()` converts service JSON, redirects and workbook output to CodeIgniter responses. `ResponseComplete` ends an action without terminating normal framework response processing. Status 419 retains the existing CSRF contract. A response filter prevents shared caching and adds security headers without weakening authentication's `no-referrer` policy.

Native PHP login cookies, session-bound CSRF tokens and credential-version checks are retained. `Config\Services::session()` exposes that existing session through the framework container without registering another session handler. Late audit collector requests retain their special cookie protection and bootstrap rejection behavior. Existing cron/Windows notification commands remain usable.

HTMX requests to account search receive escaped HTML fragments; ordinary requests retain the JSON response. Search uses the same permission and section-scoped `RecordSearch` service in both formats. Alpine manages search input and appearance controls. Full page navigation and established module JavaScript workflows remain supported; the application does not depend on a new SPA router. Tailwind is compiled without Preflight so established ERD, calendar, grid and modal styling remains compatible.

## Build and view formatting

```powershell
composer install --no-dev --prefer-dist --no-interaction
npm ci --ignore-scripts
npm run build:css
npm run format:views
npm run check:views
php bin/qms-audit.php
php bin/qms-layout.php
python tests/run-quality.py
```

Node is a development/build dependency only. The local migration used a checksum-verified portable Node installation in the private, ignored `.tools/` directory. Do not commit that directory or `.stack-work/`. Build dependencies are pinned in `package-lock.json`; PHP dependencies are pinned in `composer.lock`.

On this workstation, Node is portable rather than globally installed. In PowerShell, enable it for the current terminal before running the npm commands:

```powershell
$nodeDirectory = Get-Content .tools/node-location.txt
$env:Path = "$nodeDirectory;$env:Path"
```

The view formatter visits only `app/Views/**/*.php`. It verifies PHP executable tokens and string values, runs the PHP syntax checker before writing, preserves preformatted/textarea/script content and checks its own repeatability. Text paragraphs are not hard-wrapped, preserving visible copy and existing text searches. The mixed PHP/HTML Prettier experiment was discarded; its packages and configuration are not part of the final project.

## QMS scope and exceptions

Review authored PHP, JavaScript, CSS, SQL and HTML. Server scans, folder-upload filtering and the repository audit exclude installed `vendor`, `node_modules`, `plugins`, `third_party`, `third-party`, `bower_components`, generated build/runtime directories and hidden tooling. `assets/vendor` and `assets/plugins` are therefore excluded, while authored `assets/js`, `assets/css`, `app` and `resources` remain included. Generated Tailwind CSS is excluded; its editable source is included.

Native CodeIgniter contracts must keep their names: `_remap`, filter `before`/`after`, service `session`, framework configuration properties, and `EXIT__AUTO_MIN`/`EXIT__AUTO_MAX`. Database identifiers, HTTP/JSON/session fields and browser/library interfaces retain compatibility. These are explicit review items, not renamed APIs or silently suppressed diagnostics. Existing manual criteria for the exact QMU template, schema ownership and SAD approval remain owner review items. This migration does not grant database privileges or claim formal QMS certification.

The current raw results are in `docs/quality/qms-current.json`; module validation and individual logs are in `docs/quality/module-validation.json` and `docs/quality/validation/`. Review remaining native-contract, template-layout and historical SQL diagnostics with the existing QMS review notes.

## Installation evidence and recovery

PHP `intl` was enabled in `C:\xampp\php\php.ini`, and the verified local Apache console instance was restarted. The existing encrypted secrets, vault key, database/schema, workflows and task identities were preserved. Composer upgraded PhpSpreadsheet from 1.30.6 to the compatible 1.30.7 security patch, with compatible ZipStream and HTMLPurifier patches.

The private `.stack-work/baseline/` snapshot and its SHA-256 manifest preserve the first-party pre-migration files. `php.ini.before` preserves the earlier PHP configuration. This is an installation recovery checkpoint, not a substitute for a database backup. Do not expose or commit it: it includes installation configuration. Apache denial of private source and tooling paths was checked on the running site.

Completed conversion scripts, duplicate intermediate snapshots, download caches, the unused standalone Tailwind compiler and stale browser-test profiles have been removed. The portable Node installation and installed npm/Composer dependencies remain available for formatting, builds and runtime services.

The former root `includes/` layouts, root `codereview/` reference project and replaced Inter font are archived under the protected `.stack-work/retired-source/` directory. Current layouts load configuration and services directly from `ROOTPATH`; no active application code references the retired folders. The live Code Review module remains in `app/Controllers/CodereviewController.php`, `app/Views/codereview/` and `lib/CodeReview.php`. Root/module PHP compatibility entry points, user uploads, application logs, runtime storage, database migrations and encrypted settings are retained.

The cleanup manifest and dependency evidence are in `docs/quality/cleanup-manifest.json` and `docs/quality/unused-source-review.json`. The archive is recovery material, not application source; it remains excluded from QMS scans and must not be published. Test temporary directories and Python cache files are ignored by version control.

## Final validation checkpoint

All 24 module regression suites passed with 1,705 assertions. PHP syntax checks passed for 275 first-party entry points/classes/templates; all 44 view templates pass the formatter's repeatability check. Tailwind output is reproducible. Composer and npm audits report zero advisories. The live installation still has two users and no daily-log or leave records; both encrypted configuration files exactly match their pre-migration hashes.

The QMS audit retains 804 raw matches across 364 first-party files, with no source-header, unnecessary-comment, brace-layout or multiple-statement findings. Native framework/API naming, historical schema identifiers and other manual/context items are documented in `docs/quality/qms-review.md`. PHP/HTML template depth is reviewed as mixed markup, while indentation units and pure-PHP depth remain checked.
