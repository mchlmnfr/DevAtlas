# Installation configuration in .env

The PHP 8.1 compatibility dependency profile retains this same configuration;
see [PHP 8.1 compatibility and limitations](PHP81_COMPATIBILITY.md).

All installation-specific application settings are read from the root `.env`
file by both CodeIgniter HTTP requests and standalone PHP commands. `.env.example`
is the committed template and contains no real credentials; CodeIgniter’s `env`
template contains the same settings. `.env` and other `.env.*` files remain
ignored by Git; `.env.example` is explicitly tracked; Apache blocks dotfiles, and the public document root does not
contain the environment file.

| Variables | Purpose |
| --- | --- |
| `CI_ENVIRONMENT` | `production` or `development`; controls framework error visibility. |
| `APP_NAME`, `APP_ORIGIN`, `APP_BASE_URL`, `APP_TIMEZONE` | Name, origin, installation URL path and timezone. |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SCHEMA`, `DB_DEBUG` | Main PostgreSQL connection and database exception behavior. |
| `TARGET_DB_HOST`, `TARGET_DB_PORT`, `TARGET_DB_NAME`, `TARGET_DB_USER`, `TARGET_DB_PASS`, `TARGET_DB_SCHEMA` | Legacy default target database connection. |
| `APP_ENCRYPTION_KEY` | Existing server-credential vault encryption key. |

The template derives native `app.baseURL` and `app.appTimezone` from the same
application variables. `APP_BASE_URL` is a path such as `/AnalystToolkit`, without
a trailing slash; use an empty value for a root installation. `APP_ORIGIN` is the
scheme and hostname (plus port, if needed), without a trailing slash.

The existing `DB_*`, `TARGET_DB_*` and `APP_*` constants remain available to the
application. Their values are no longer configured in `config/config.php`.
Native CodeIgniter settings can also be overridden using its normal dotted names
in `.env`. Framework constants and business records such as saved connection
profiles and administrator-managed SMTP settings remain in their existing places.

## New installation

1. Install Composer dependencies.
2. If `.env` does not already exist, copy `.env.example` to `.env` and fill in the settings.
3. Generate a vault key once with
   `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` and put it in
   `APP_ENCRYPTION_KEY`. Never use this step to replace an existing vault key.
4. Keep `.env` readable only by the account running PHP (mode `0600` on Unix).
5. Run `php spark routes` and `php tests/environment.php` to verify configuration.

Passwords may be empty for an explicitly configured local development database,
but their variables must still exist. Missing required settings and invalid
`DB_DEBUG` values fail with variable names only; values are not included in those
errors. Restart long-running PHP/worker processes after changing configuration.
Keep the template's `DB_DEBUG = true`: the domain services rely on database
exceptions for constraint validation. Production error visibility is controlled
by `CI_ENVIRONMENT`, independently of this database setting. Existing `.env`
files that omit `DB_DEBUG` retain the same `true` behavior. An explicit `false`
is honored, and empty or invalid values still fail validation. Native
`database.default.DBDebug` and `database.target.DBDebug` overrides take precedence.

If an older build logs `Required environment setting DB_DEBUG is missing` from
`config/Environment.php` (and a subsequent audit finalization error), add
`DB_DEBUG = true` to the existing root `.env` and restart PHP/Apache. On a Wamp
installation at `C:\wamp64\www\AnalystToolkit`, the file is
`C:\wamp64\www\AnalystToolkit\.env`, alongside `composer.json`. Preserve all
existing credentials and the vault encryption key. The updated build accepts
the omitted setting, so this startup error no longer blocks database access.

## Existing encrypted installation

Before replacing configuration, back up the database and existing protected
installation files. With Composer dependencies installed, run:

```sh
php bin/migrate-env.php
```

This creates a private `.env` using the template and the exact legacy `DB_PASS`,
`TARGET_DB_PASS` and `APP_ENCRYPTION_KEY`. It never prints their values, never
overwrites an existing `.env`, and leaves `config/.local-key` and
`config/.local-secrets.json` intact for recovery. It also honors the legacy
`DEVATLAS_CONFIG_KEY` and `DEVATLAS_SECRETS_FILE` process bindings during migration.
Values that cannot round-trip through CodeIgniter's dotenv parser are rejected
without leaving a partial file.

The non-secret template defaults match the repository's previous settings. If
your installation customized its host, port, database, user, schema, URL or
timezone in PHP, copy those customized values into `.env` before starting the
updated application. If `.env` already exists, merge settings manually and
preserve the exact existing vault key; the migrator will not overwrite it.

After migration, runtime configuration does not read the legacy encrypted files.
Keep them as recovery material until your normal backup process covers `.env`.
`bin/configure-secrets.php` is a legacy utility; it does not update `.env` and
refuses to write the old default store when `.env` exists.

## Loading and overrides

`config/Environment.php` uses CodeIgniter's own dotenv parser, including quoting
and `${NAME}` interpolation. Existing process variables take precedence over file
values. Raw password strings such as `false`, `null` and `0` stay strings. Do not
use `${NAME}` in a literal secret unless you intend CodeIgniter to expand it.

The loader runs before standalone framework preload as well as normal web
bootstrap, so maintenance scripts, scheduled notifications and Spark use the same
configuration. Unit-style configuration tests use disposable files and do not
modify the installed `.env` or contact external services.

Current configuration validation: 42 dotenv/migration assertions pass, including
older `.env` files without `DB_DEBUG`, explicit `false`, invalid values and native
database overrides. The previous full PHP suite run had 16 passes with 738
assertions. The two previously failing suites
(`data-dictionary.php` and `qms-maintenance.php`) still try to instantiate the
abstract framework `BaseConnection` and require a separate fix. HTTP checks verify
DevAtlas branding, logos and favicons on public and authenticated pages using a
disposable schema/account. All 51 view formatting checks pass.
