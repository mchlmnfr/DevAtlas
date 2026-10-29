# PHP 8.1 compatibility profile

DevAtlas's application and locked dependencies now support **PHP 8.1+**. The
previous CodeIgniter 4.7.5 dependency required PHP 8.2, so changing only the HTTP
version check would not have fixed the installation.

| Setting/package | Compatible selection |
| --- | --- |
| Root PHP requirement | `^8.1` |
| Composer resolution platform | `8.1.0` |
| `codeigniter4/framework` | `4.6.5` |
| `laminas/laminas-escaper` | `2.17.0` |
| `maennchen/zipstream-php` | `3.1.1` |

Other application dependencies retain their locked versions. The HTTP bootstrap
accepts PHP 8.1, and the stack response header reports the installed framework
version. Existing `.env` settings, passwords, vault key, data and URLs are retained.

## Install the updated dependencies

Use the updated `composer.json` **and** `composer.lock` together:

```sh
composer install --no-dev --prefer-dist --no-interaction
composer check-platform-reqs
php spark routes
```

Run these commands using the PHP 8.1 executable configured for the application.
PHP needs `intl`, `mbstring`, PostgreSQL support and the extensions listed by
Composer. Never bypass platform checks. Copy `.env.example` to `.env` only for a
new installation; preserve the existing file and vault key on upgrades.

The source ZIP excludes installed dependencies and private configuration. Run
Composer on the destination machine before starting the application. The server
must actually use the freshly installed dependencies, rather than an old 4.7.5
`vendor` directory from a previous deployment.

## Validation

Executed on Ubuntu's patched **PHP 8.1.2-1ubuntu2.28** binary:

- Composer platform requirements and repeatable locked installation pass.
- All 228 application/bootstrap/helper/test PHP syntax checks pass.
- All 51 view formatting checks pass; Spark commands and routes load.
- 16 PHP suites pass with 738 assertions. `data-dictionary.php` and
  `qms-maintenance.php` retain their pre-existing abstract `BaseConnection`
  instantiation failures.
- HTTP checks verify public pages, sign-in, dashboard, daily logs, Wiki and
  account pages with DevAtlas branding using a disposable schema/account.
- All 14 authenticated Logs HTTP checks pass, including native log previews,
  stack traces, redaction, raw downloads, threshold-aware diagnostics and deletion.
- A real XLSX workbook exports and reads back successfully with ZipStream 3.1.1.

Browser automation remains unrun; the repository's browser runners use
Windows-specific PHP/Edge paths.

## Known security limitation

**This is a functional PHP 8.1 compatibility profile, not a clean security audit.**
CodeIgniter 4.6.5 is the latest published release compatible with PHP 8.1 in the
package metadata inspected for this change. Composer's dependency-resolution
metadata reported five advisories for it. The official framework advisory page
also lists subsequent fixes released in 4.7.5. Those patched framework versions
require PHP 8.2. No advisories have been ignored or marked resolved, and no vendor
patches have been applied to imply otherwise.

The official advisories reviewed include:

- [SQL injection in Query Builder deleteBatch](https://github.com/codeigniter4/CodeIgniter4/security/advisories/GHSA-c9w5-rwh3-7pm9)
- [Spoofable forwarded HTTPS headers](https://github.com/codeigniter4/CodeIgniter4/security/advisories/GHSA-7wmf-pw8j-mc78)
- [UploadedFile filename traversal](https://github.com/codeigniter4/CodeIgniter4/security/advisories/GHSA-hhmc-q9hp-r662)
- [Image upload validation bypass](https://github.com/codeigniter4/CodeIgniter4/security/advisories/GHSA-mmj4-63m4-r6h5)
- [Parser escaping bypass](https://github.com/codeigniter4/CodeIgniter4/security/advisories/GHSA-vgrf-mv2j-gp8w)
- [Upload filename validation bypass](https://github.com/codeigniter4/CodeIgniter4/security/advisories/GHSA-4hwf-v4mp-hf6c)
- [Parser conditional code injection](https://github.com/codeigniter4/CodeIgniter4/security/advisories/GHSA-4q58-jw8x-8cm7)
- [Template source overrides through view data](https://github.com/codeigniter4/CodeIgniter4/security/advisories/GHSA-c29x-ffjj-8r7x)
- [CURLRequest options retained after exceptions](https://github.com/codeigniter4/CodeIgniter4/security/advisories/GHSA-9g9v-xgwj-697h)
- [Multi-file upload validation bypass](https://github.com/codeigniter4/CodeIgniter4/security/advisories/GHSA-cwxp-v62r-xwx2)

The application call-site review found no uses of `deleteBatch`, View Parser,
`renderString`, native `UploadedFile` handling or `CURLRequest`. Its avatar upload
uses separate validation and `move_uploaded_file`. This limited review does not
establish that the dependency is safe or that indirect framework usage is
unaffected. A production deployment needs a security decision; upgrading PHP to
use the patched framework remains the route to upstream fixes.

The standalone Packagist audit API was blocked by this environment's network
policy. The observations above come from the successful Composer resolution
metadata and the official public framework advisories; a successful full
`composer audit` is not claimed.
