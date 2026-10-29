# CodeIgniter 4 structure and cleanup

The current PHP 8.1 compatibility profile pins **CodeIgniter 4.6.5** in
`composer.lock`; see [compatibility and security limitations](PHP81_COMPATIBILITY.md).
The scaffold restoration described below was originally compared with 4.7.5,
before this compatibility change. Existing application customizations are retained.
Installation settings now come from `.env`; see [environment configuration](ENVIRONMENT.md).

## Framework files

`composer install` supplies the complete framework under
`vendor/codeigniter4/framework`, including `system/`. Do not copy that directory
to the project root, commit `vendor/`, or delete unused-looking framework classes.
Composer and the framework autoloader manage those files. Run
`composer check-platform-reqs`, `php spark list` and `php spark routes` to check
installation and bootstrap.

All 44 upstream configuration files already existed in `app/Config`. Their
application-specific settings, routes, controllers, database adapters and native
login/session integration are retained. These standard scaffold files have been
restored:

- `app/Common.php`, the early application function override hook.
- `app/Language/en/Validation.php`, the application validation message override.
- `app/Database/Migrations`, `app/Database/Seeds`, `app/Libraries`, `app/Models`
  and `app/ThirdParty`, retained with `.gitkeep` files for future development.
- `app/Views/errors/cli` and `app/Views/errors/html`, including the CSS/JavaScript
  used by framework exception pages. `Config\Exceptions` now uses this local path.
- `app/index.html` and the index placeholders for `writable/`, `cache/`,
  `debugbar/`, `logs/`, `session/` and `uploads/`. Generated runtime contents stay
  ignored by Git, so fresh checkouts retain the required directories.
- `public/robots.txt` and a non-secret root `env` template. `.env` remains ignored.

The copied scaffold comes from the CodeIgniter release above; its MIT notice is
retained in [licenses/CodeIgniter4.txt](licenses/CodeIgniter4.txt). Error views are
formatted using the project's PHP-token-preserving view formatter.

The starter `Home` controller and `welcome_message` view are intentionally omitted:
DevAtlas already has `HomeController` and its dashboard. The existing DevAtlas SVG
favicon replaces the starter favicon. Optional preload/PHPUnit examples remain
available inside the installed framework; they are not required runtime files and
are not enabled as part of this cleanup. The existing regression suites remain.

## Cleanup

- Removed 24 generated `docs/quality/validation/*.log` files and ignored future
  generated logs. Historical JSON summaries and manual audit records are retained;
  they do not establish current test results.
- Moved `PROJECT_HANDOFF(1).md` to [PROJECT_HANDOFF.md](PROJECT_HANDOFF.md) and fixed
  its relative links. Historical implementation notes are retained.
- Removed the accidental UTF-16/NUL fragments appended to `README.md` and corrected
  the old setup text that described Composer as optional.

Compatibility entry points under root and `modules/`, legacy asset filenames,
database migrations, tests, uploads, logs, legacy configuration recovery files and
installed dependencies are retained. Their names or locations alone do not make
them obsolete. The application still uses the `/AnalystToolkit` prefix and the
secured compatibility document root described in [STACK_MIGRATION.md](STACK_MIGRATION.md).

## Validation of this cleanup

Verified against installed tag `v4.7.5`:

- All 752 tracked upstream package files exist; framework `system/` is unchanged.
- All applicable application scaffold files exist, with the demo exceptions above.
- Composer platform requirements, Spark bootstrap and namespaces pass; the route
  table is unchanged apart from its generated timestamp.
- All 146 application/bootstrap PHP syntax checks and all 51 view formatting
  checks pass.
- Login, Call Directory, Quick Links and the SVG asset return expected HTTP 200
  content. Missing/private routes return 404. Local production/development HTML
  and CLI exception templates render correctly; production hides exception details.
- The existing PHP suites have 15 passes with 683 assertions. Two existing suites,
  `data-dictionary.php` and `qms-maintenance.php`, still fail because they attempt
  to instantiate CodeIgniter's abstract `BaseConnection`; they were not changed.

HTTP checks use PHP's local development server. Apache rewrite rules and the
Windows-specific browser suites were not exercised in this Linux cleanup.
