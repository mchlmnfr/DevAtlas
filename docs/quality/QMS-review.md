# Current QMS review ? 2026-10-29

The stack migration audit covers **364 first-party source files** and retains **804 raw diagnostic matches**. Installed dependencies/plugins, generated assets/runtime data and hidden tooling are excluded; our `app/Views`, controllers, services, JS, CSS and Tailwind source are included. Nine unused legacy PHP layouts were archived in the private recovery area during cleanup; their removal from the active tree changes the file count without removing diagnostic matches. Counts are not a formal approval or a claim that every diagnostic has been waived.

**All 24 regression suites passed: 1,705 assertions.** PHP syntax validation checked **275 files with zero failures**. All **44 view templates** pass repeatable formatting checks with executable PHP-token verification. The Tailwind production build repeats with the same SHA-256. Composer and npm advisory checks reported zero advisories at this checkpoint.

There are no current missing-source-header, unnecessary-comment, commented-code, brace-layout or multiple-statement findings. Remaining diagnostics concern native contracts, historical database identifiers, template/continuation context, runtime styling and manual criteria.

## Evidence

- [Raw per-file audit and all 24 criteria](qms-current.json)
- [Individual findings and review context](qms-review-items.json)
- [Module suites and assertion totals](module-validation.json)
- [PHP syntax results](stack-syntax.json)
- [Cleanup manifest](cleanup-manifest.json) and [retired-source dependency review](unused-source-review.json)
- [Actual CodeIgniter route manifest](stack-route-manifest.json)
- [Live Apache clean URLs and compatibility redirects](clean-url-validation.json)
- [Composer advisories](composer-audit.json) and [npm advisories](npm-audit.json)
- [Architecture, formatting, deployment and recovery](../STACK_MIGRATION.md)

## Remaining review items

| Criterion | Raw matches | Review context |
| --- | ---: | --- |
| QMS-01 | 13 | Native CodeIgniter hooks/config methods, installed SQL routine names, and the theme IIFE retain their required identities. |
| QMS-02 | 168 | Existing DB/JSON/browser interfaces, CodeIgniter configuration fields, and formatter option keys retain external API spelling. |
| QMS-03 | 408 | Browser state properties, existing data flags, CodeIgniter settings and library options retain their API contracts. |
| QMS-04 | 2 | CodeIgniter requires the exact EXIT__AUTO_MIN and EXIT__AUTO_MAX constant names. |
| QMS-09 | 47 | Review continuation, literal and mixed-template alignment in context; mixed PHP/HTML indentation units remain checked and pure-code curly depth is enforced. |
| QMS-11 | 2 | Review callback/local captures and exported values in context; shared/native identities are preserved. |
| QMS-12 | 35 | Review email-client inline styling and runtime-calculated coordinates/CSS properties; authored static CSS is external. |
| QMS-13 | 50 | Review protocol placeholders, validation patterns, fixture addresses (including clean-URL boundary cases) and installation defaults separately from runtime configuration. |
| QMS-14 | 2 | PDO connection creation is isolated in config/Database.php and config/MetadataConnection.php; processing receives the established handles. |
| QMS-15 | 15 | Passwords are retrieved from authenticated encrypted installation settings. Inspect placeholders/field labels and deliberate test sentinels; no secret rotation occurred. |
| QMS-17 | 30 | Installed database/foreign-key identifiers are preserved; changing them requires a coordinated schema/API migration. |
| QMS-18 | 1 | The existing char_length dictionary field describes source metadata and is retained for compatibility. |
| QMS-23 | 31 | PDO migration execution uses matching named logs through MigrationRunner; native SQL client SPOOL is not executed through PDO. |

Native framework method names (`_remap`, filter `before`/`after`, `session`, exception/MIME helpers), required configuration names, HTTP/JSON/session fields and browser interfaces must retain their spelling. These are explicit compatibility review items, not silently renamed APIs. Tests exercise the preserved boundaries.

QMS-09 now distinguishes mixed PHP/HTML templates from pure-code curly nesting. It still checks indentation units in templates and preserves pure-PHP depth checks. Regression cases cover aligned dynamic attributes, incorrectly indented mixed templates and incorrectly indented pure PHP. Readable views use `npm run format:views` and `npm run check:views`; the pure-PHP layout utility delegates that directory to the view formatter.

The exact QMU naming/template guide and SAD authorization have not been supplied. Schema ownership, grants, historical SQL format, comment accuracy and New/Modified scope change trails still need the responsible owner's review. This conversion performs no database schema migration or GRANT and does not certify these manual criteria. External production SMTP, scheduled task execution and live remote database drivers remain environment-specific checks.
