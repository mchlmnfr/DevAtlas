# Data Management / Data Conversion

This patch integrates `centraldb_consol_importer_noduplicates` into DevAtlas. It adds the **Data Management → Data Conversion** sidebar entry, CodeIgniter routes, session authentication, role permissions, upload validation, previews and SQL downloads.

## Install on WAMP

1. Back up the matching existing files, then copy the patch's `app`, `assets`, `bin`, `database`, `docs`, `lib` and `modules` folders into `C:\wamp64\www\AnalystToolkit`, preserving their paths. The ZIP contains only added or changed files.
2. From the project directory, run the installer using the WAMP PHP executable (or `php` if the correct PHP version is on PATH):

   ```powershell
   Set-Location 'C:\wamp64\www\AnalystToolkit'
   php .\bin\install-data-conversion.php
   ```

   The installer registers View, Create and Export permissions in DevAtlas's PostgreSQL database. It is safe to run again and preserves existing grants. Its migration has a descriptive date-based filename, so it does not replace a local `migration_032` or any Birthday Leave customization.
3. Sign in as an Administrator. Open **Data Management → Data Conversion**, or visit `http://localhost/AnalystToolkit/modules/dataconversion/`. Use Ctrl+F5 after copying the patch. The shared asset helper also changes the JS/CSS cache key when their contents change.
4. In **Roles & Permissions**, grant **View**, **Create** and **Export** to the roles that should use the converter. Other roles start without access. View is required before Create or Export applies.

PHP 8.1+ is required by DevAtlas. Excel uploads also need `zip`, `XMLReader` and `SimpleXML`; enable them in WAMP's active PHP configuration if missing, then restart Apache. No new Composer dependency is needed.

The module accepts up to 25 MB per file and shows the effective PHP upload limit on screen. To use the full limit, configure `upload_max_filesize = 25M` and `post_max_size = 64M` in the active Apache PHP configuration, then restart Apache. PHP memory and request time limits still apply. The private CodeIgniter `writable` directory must remain writable by Apache and protected from direct HTTP access; DevAtlas already supplies its deny rule.

## Use

### Report consolidation

Upload UTF-8 CSV or `.xlsx` for `central_db.dbo.consol_data`. Download the CSV template from the module for an example with fictional data.

- Required headers: `ReportCode`, `ReportName`.
- Optional headers: `SMFCode`, `Recipient`, `Department`, `GroupCode`, `Frequency`, `Schedule`, `Fields`, `SourceSystem`, `Remarks`.
- The workbook's aliases, including `Report_Code`, `SMF Numbers`, `Data Extract Files (name of report)`, `Group` and `Source`, map to the database column names.
- `ReportCode` must match `RPT-0000-000`. Duplicate codes in the selected source records are rejected. `ReportName` is limited to 500 characters and the supplied optional column lengths are checked.
- Input `TableName` and `InsertedAt` values are excluded. `TableName` is derived from ReportCode (`RPT-2026-001` → `rpt_2026_001`); InsertedAt uses the destination default.
- Excel defaults to all report worksheets, excluding `Summary Count Per Dept`. After validation, choose one report worksheet or all report worksheets.
- The download uses SQL Server 2012-compatible transactions and one INSERT per record. At execution time, existing ReportCode **or** TableName values are skipped, with inserted and skipped counts returned.

The existing SQL Server trigger must honor explicitly supplied ReportCode values. The generated script retains that note and does not disable or alter triggers, create tables or modify the destination schema. Destination unique constraints remain the final duplicate safeguard.

### Existing SQL Server table

Choose a `schema.table` and upload CSV. Supported delimiters: comma, semicolon, tab and pipe. The schema defaults to `dbo` if omitted.

Optionally download the read-only schema export query, run it in the destination SQL Server database, export its results to UTF-8 CSV with headers, and upload that snapshot. It checks that the table and columns exist and excludes identity/computed inserts. The snapshot is not a data-type validator.

Generic conversion excludes `ReportCode` and `TableName`, preserves nonblank text, converts blank values to NULL, escapes Unicode string literals, and creates transaction-wrapped INSERT batches of 500 rows. Generic tables need their own unique keys or trigger rules for duplicate prevention; the converter cannot infer those keys.

### Preview, downloads and temporary files

Only the first five records appear in the preview, with long values shortened for display. Downloads retain complete values, including embedded line breaks. Excel formula cells use saved cached values and are never evaluated. Recalculate and save the workbook in Excel if a formula has no saved value. Excel serial dates are not reformatted; use the intended textual values in the source. Legacy `.xls` files are not supported.

Limits: 100,000 data records, 1,024 columns, 100 worksheets, 200,000 populated Excel cells, 80 MB total uncompressed XLSX content, 16 MB per workbook component and 128 MB generated SQL. Workbooks with XML entities, external worksheet references, invalid cell addresses or malformed content are rejected.

Sources and generated SQL stay under private `writable/data-conversion/<random-job-id>/`. Access belongs to the signed-in session and expires one hour after the upload. Clear upload removes the current files; replacement removes the prior upload. Expired files are removed when accessed, and older abandoned folders are pruned during later uploads. Previews and uploaded records are not written to application or audit logs.

DevAtlas generates the script for you to review and run in SQL Server. Conversion does not connect to the destination database or execute generated SQL.

## What's New

`docs/releases.json` adds **2026-11-10.22 — Data Management: Data Conversion**, preserving earlier release entries, including the leave and daily-log patches.

## Patch files

```text
app/Config/Routes.php
app/Controllers/DataconversionController.php
app/Views/dataconversion/index.php
app/Views/layouts/header.php
assets/css/data-conversion.css
assets/js/data-conversion.js
bin/install-data-conversion.php
database/migration_20261110_data_conversion.sql
docs/data-conversion.md
docs/releases.json
lib/Audit.php
lib/DataConversion.php
lib/DataConversion/consol.php
lib/DataConversion/lib.php
lib/DataConversion/xlsx.php
lib/Permissions.php
modules/dataconversion/api.php
modules/dataconversion/index.php
```

The shared files (`Routes.php`, layout header, Audit, Permissions and release history) extend the repository version. If you have additional local edits in those same files, merge the corresponding changes instead of replacing those local edits. The accompanying unified diff makes these additions reviewable.
