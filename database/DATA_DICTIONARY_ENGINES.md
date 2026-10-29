# Data Dictionary database support

The application metadata database remains PostgreSQL. The engines below are
targets whose catalogs can be read to document tables and views. Target records
are never imported. Connections use fixed catalog queries or ODBC catalog APIs;
the tool does not accept arbitrary SQL.

## Live adapters

| Engine | PHP support required | Connection fields / catalog behavior |
|---|---|---|
| PostgreSQL | `pdo_pgsql` | Host, port 5432, database, username, password; schemas, identities, full formatted types and composite FK column order. |
| MySQL | `pdo_mysql` | Host, port 3306, database, username, password; schema means database; full column type including unsigned/enum declarations. |
| MariaDB | `pdo_mysql` | Same catalog protocol as MySQL, with a separate engine label. |
| SQL Server / Azure SQL | `pdo_sqlsrv` and Microsoft ODBC Driver | Host, port 1433, database, username, password; system catalogs and identities. TLS encryption and certificate verification are enabled. Configure the server trust chain in the driver environment. |
| Oracle 12c+ | `pdo_oci` and Oracle client libraries | Host, port 1521, **service name**, username, password; accessible owners from ALL_TABLES/ALL_VIEWS. SID/TNS aliases are not separate connection modes. |
| SQLite | `pdo_sqlite` | Absolute path to an existing SQLite file **on the application server**; `main` namespace; read-only open flags and `query_only`. No upload of database records. |
| IBM Db2 LUW | `pdo_ibm` and IBM client libraries | Host, port 50000, database, username, password; SYSCAT catalog metadata. Other Db2 variants can use ODBC or metadata JSON. |
| Firebird 3+ | `pdo_firebird` and Firebird client | Host, port 3050, target-server file path or database alias, username, password; RDB$ catalogs; `default` namespace. |
| ODBC | PHP `odbc`, a compatible vendor driver and configured **System DSN** | DSN name in Host, optional catalog, username and password. DSN connection strings are not accepted. Driver catalog APIs supply schemas/tables/columns/PK/FK metadata. |

The engine picker reports capabilities of the PHP process serving the app. Enable
drivers for the web server's PHP installation and restart Apache after installing
extensions or vendor libraries. CLI PHP and Apache PHP can have different drivers.
Missing drivers do not prevent manual documentation or metadata JSON import.

ODBC is an extension path for compatible databases such as Access, Snowflake and
SAP HANA. Catalog visibility, PK/FK support and nullability depend on the vendor
driver. ODBC does not consistently expose identity flags; confirm them in the field
editor. A failed required catalog call is reported rather than silently omitting
keys. Use metadata JSON when a provider lacks the needed ODBC catalog APIs.

Use a database account permitted to read the intended metadata. The tool runs
read-only catalog statements, but it cannot grant permissions or discover objects
that the target hides. It documents tables/views rather than routines, indexes or
full constraint definitions. A field involved in multiple FK constraints retains
its first reference; composite FK columns retain their individual pairings.

## Portable metadata

This format also represents non-relational engines: map a collection/entity to a
table and fields to columns, preserving the original engine and datatype labels.
Types are explicitly supplied; no inference or sampling of target records occurs.

```json
{
  "format": "analysttoolkit-dictionary",
  "version": 1,
  "source_database": "Customer platform",
  "source_engine": "mongodb",
  "tables": [
    {
      "schema_name": "collections",
      "table_name": "customers",
      "object_type": "table",
      "description": "Customer profiles.",
      "columns": [
        {
          "column_name": "profile.email",
          "data_type": "string",
          "ordinal_position": 1,
          "is_nullable": true,
          "sensitive_flag": true,
          "description": "Contact email.",
          "business_rule": "Validate before use."
        }
      ]
    }
  ]
}
```

Table fields can override `source_database` and `source_engine`. Optional table
fields: `display_name`, `business_owner`, `source_system`, `is_active`.
Optional field metadata: `char_length` (-1 for MAX), `numeric_precision`,
`numeric_scale` (zero and negative scales retained), `default_value`,
`is_primary_key`, `is_foreign_key`, `is_auto_increment`, `fk_ref_table`,
`fk_ref_column`. Use real JSON booleans. Identity is the database label, schema and
table name, with field name identifying a column. Duplicate identities in a file
are rejected before importing. Exported JSON omits internal IDs and credentials.

Re-import refreshes structural metadata and retains existing descriptions,
business rules and sensitivity flags. Missing source fields are kept and reported
for review, preserving requirement links. Metadata import is not a destructive
database synchronization operation.

## Verification scope

Verified locally with actual SQLite and PostgreSQL catalogs, including identities,
generated SQLite fields, quoted names, implicit SQLite FK targets and PostgreSQL
composite foreign keys. API/UI tests use an isolated PostgreSQL schema. SQL Server,
Oracle, Db2, Firebird and ODBC adapters require their drivers and target instances
for live verification; those drivers are not installed in this workspace. MySQL's
PDO driver is installed, but no target credentials were supplied for live testing.

Adapter references: [PHP PDO drivers](https://www.php.net/manual/en/pdo.drivers.php),
[PHP ODBC metadata](https://www.php.net/manual/en/function.odbc-columns.php),
[SQL Server column catalog](https://learn.microsoft.com/en-us/sql/relational-databases/system-catalog-views/sys-columns-transact-sql),
[Oracle column catalog](https://docs.oracle.com/en/database/oracle/oracle-database/19/refrn/ALL_TAB_COLUMNS.html).
