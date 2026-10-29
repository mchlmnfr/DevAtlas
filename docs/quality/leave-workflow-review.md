# Leave calendar, approvals and birthdays

Completed on 2026-10-28. Migrations 029-031 are installed in the local application database.

- Leaves is a separate module for applications and the department calendar. Date From / Date To creates one application with a daily entry for each working day. Weekends and organization holidays are excluded.
- Approvals has separate Daily Logs and Leave tabs. Daily Logs retains weekly decisions and existing reviewer assignments. Leave requires its own Review permission and limits decisions to the approver's section. Section Heads may approve their own leave.
- Leave range edits and decisions check one application version. A decision updates all covered dates atomically. Existing leave IDs, dates, hours, statuses and report contributions are preserved.
- User creation and editing accept an optional birthday. Department calendar events recur annually and expose the person's name and occurrence date, without the birth year or age. Leap-day birthdays appear on February 28 in non-leap years.
- Draft leave is private to its applicant. Returned applications and remarks are readable by the applicant and authorized section approvers. Calendar peers receive public leave details only.

## Verification

All **23 regression suites passed, with 1,628 assertions**. This includes 59 leave backend checks, 239 daily-log/leave browser checks, and 245 shared branding, theme and interface checks. The remaining suites cover authentication, role administration, audit capture, reminders, search, exports, modeling, scans and supporting modules.

- [Module results and individual suite logs](module-validation.json)
- [Database installation and preservation verification](leave-installation.json)
- [Live Apache route checks](leave-http-validation.json)

The installation verification compares hashes of all pre-existing account and daily-log fields before and after the upgrades. Original fields match. Historical leave is associated with its application without replacing its daily record or approval history. Named migration logs record successful completion under `logs/migrations/`.

The current QMS audit covers **246 first-party source files**. PHP layout checks report zero files requiring changes, with unchanged executable tokens verified. The new leave service and client pass the implemented QMS diagnostics. The three new migration scripts are executed by MigrationRunner, which creates matching named logs; the scanner's SQL spool-directive matches remain visible for manual review. Existing compatibility and manual-review findings from the earlier codebase review remain in the [current audit](qms-current.json); this is not a claim that all raw matches are resolved or that formal QMU/SAD approval has been issued.

Authenticated workflow checks use disposable PostgreSQL schemas and Edge profiles. Live Apache checks confirm sign-in protection and private installation evidence; no real personnel applications or accounts were created for testing.
