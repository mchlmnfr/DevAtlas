# Upcoming functions

This roadmap records the features approved for DevAtlas. Items 1–4 and 7, plus
OWASP support in Code Review, and Team Monitoring / Daily Logs are implemented. Remaining
items are approved ideas for later work, not part of this implementation.

The in-app **Documents Wiki** provides module guides and a roadmap. This built-in
guide is delivered; the versioned Document & Template Library below remains
planned. Permission-aware global record search is delivered in Search workspace (Ctrl / Cmd + K).
Daily-log reports and weekly approvals are delivered; the cross-module Report
Builder and broader Approval Workflows remain planned. Planned items have no
committed delivery dates or implementation order.

| # | Function | Scope | Status |
|---|---|---|---|
| 1 | Roles & Permissions | Six protected presets including Section Head and Department Head, plus custom roles with independent permission profiles, copying, renaming and safe deletion; user assignments; protected exports, credentials and support previews. | Implemented |
| 2 | Notifications & Reminders | In-app notifications and optional email; renewal reminders, review assignments and overdue reviews; delivery status and scheduled processing. | Implemented |
| 3 | Code Review History & Tracking | Save findings and metadata without raw source; assign reviewers, record finding dispositions and notes, compare review runs. | Implemented |
| 4 | Application Catalog | Central application ownership and lifecycle records linked to inventory, dictionary tables, requirements, ERDs, links and reviews. | Implemented |
| 5 | Database Schema Comparison | Compare snapshots or live metadata to identify added, removed and changed objects. | Planned |
| 6 | Approval Workflows | Review requirements, dictionary changes and important inventory updates before publishing. | Planned |
| 7 | Global Record Search | Search readable records and modules from the topbar, with section and role boundaries. | Implemented |
| 8 | Document & Template Library | Versioned specifications, SOPs, deployment guides and QMU templates linked to related records. | Planned |
| 9 | Project Workspaces | Group requirements, diagrams, reviews, inventories, documents and tasks by project. | Planned |
| 10 | Release & Deployment Register | Versions, environments, deployment dates, approvals and rollback instructions. | Planned |
| 11 | Dependency & Impact Map | Visual relationships among applications, servers, tables and requirements. | Planned |
| 12 | Scheduled Jobs & Health Monitor | Operations dashboard for connectivity, email, job results and failed background tasks. | Planned |
| 13 | Backup & Recovery Center | Application backup packages and restoration history. | Planned |
| 14 | Report Builder | Reusable reports combining inventory, documentation, review and audit information. | Planned |
| 15 | Bulk Import with Preview | Validated Excel/CSV imports, duplicate detection and a preview before saving. | Planned |
| Additional | OWASP review engine | OWASP Top 10:2025 category mapping, bounded static checks, remediation guidance and explicit manual review coverage. | Implemented |

| Additional | Team Monitoring and Daily Logs | Personal activity and leave drafts/submissions; unit-scoped approval/return and bulk review; daily and weekly team completion with approved leave/undertime adjustments. | Implemented |
| Additional | Weekly approvals and Reports | One decision per employee/week; entered and remaining hours; section Excel workbooks with employee worksheets and formatted report names. | Implemented |
| Additional | Holidays and Documents Wiki | Separate organization-wide Holidays module, searchable guides for all modules, and a system roadmap. Administration modules are grouped under Parameter. | Implemented |
| Additional | Head self-approval and help | Section Head / Department Head weekly self-approval; standalone Wiki from top-bar Help; What's New modal with newest-first history and per-user acknowledgement. Holidays is under Parameter. | Implemented |

## Implementation notes

- Preserve the public, read-only Call Directory and Quick Links.
- Enforce permissions on the server and reflect them in navigation and controls.
- Keep existing credentials, accounts and records intact during migrations.
- Keep raw source contents and secrets out of persisted review records and notifications.
- Treat automated security matches as review prompts, not proof of vulnerability
  or OWASP certification. Design, access-control coverage and operational controls
  still need human review.
- Deliver all new database changes through repeatable migrations and fresh schema definitions.
- Verify using disposable schemas, accounts, browser profiles and local SMTP fixtures.

## References

- [OWASP Top 10:2025](https://top10.owasp.org/2025/)
- [OWASP Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html)

## Progress log

- Items 1–4 and OWASP support implemented; migration 018 applied.
- Windows task installed: AnalystToolkit Notifications, every 15 minutes, hidden
  PHP executable, current-user interactive logon. First run completed successfully.
  Windows denied S4U/background-logon registration; the installed task requires
  this Windows account to be signed in. No PowerShell execution policy was changed.
- Reminder email remains disabled until enabled by an Administrator in Notifications.
- Automated verification: 41 workflow/OWASP component checks and 91 browser/HTTP
  checks using disposable schemas/accounts and loopback SMTP, plus existing regressions.
- Items 5–15 remain planned for a later implementation.
- Custom roles implemented; migration 019 applied without changing existing
  accounts, role names or grants. Verified with 43 custom-role component checks
  and 124 workflow/browser checks, including assignment, privilege enforcement,
  preset protections, audit capture and deletion safeguards.

- Team Monitoring, My Daily Logs and Review Daily Logs implemented; migration 020 applied.
  Verified with 70 daily-log component assertions and 52 browser/API assertions,
  plus 43 custom-role and 41 workflow regression assertions.

- Explicit unit/section daily-log reviewer assignments implemented; migration 021 applied.
  Administrators manage assignments in Review Daily Logs. Non-administrators need
  both the section assignment and the relevant role permissions; only administrators
  can read/approve across sections. Component and browser checks cover assignment,
  replacement, removal, unauthorized approvals and cross-section batches.
