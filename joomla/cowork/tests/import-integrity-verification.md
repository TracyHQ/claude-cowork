# Joomla import integrity verification

Verified locally on 2026-11-09 against Joomla 6.2.0, PHP 8.4.27 and MariaDB 11.
The source was an isolated throwaway site with this branch's receiver installed. The exporter
was built from Tracy develop commit `340cffa95f`. No customer account was accessed.

## Reproduced defects

- A valid backtick in a table name caused `mysqli_sql_exception` during export.
- Nonempty views produced SQL that failed to restore. Empty views disappeared from the copy.
- Deleting a row in a keyless table between cursor pages returned success with different rows
  in the restored copy; a direct probe confirmed an unexported row had been skipped.

The Joomla engine now escapes identifiers by doubling backticks, includes portable ordered
view definitions in optional `db.tables.views`, and checks keyless-table checksums before and
after reading each page. Joomla's table-details metadata and snapshot copy operation remain.
The snapshot/copy, rename and drop paths are covered with escaped identifiers as well.

## Acceptance results

- **24/24 HTTP/MariaDB scenarios** produced the expected outcome after the fix. They cover
  keyed pagination, nullable/keyless tables, foreign keys, binary/Unicode/decimal data,
  generated/invisible columns, escaped identifiers, empty/nonempty views and matching aliases,
  stalled cursors, malformed/empty/truncated SQL, missing table lists, transient upload resets
  and 503 responses, permanent 403 responses, trailing notices and concurrent keyless deletion.
  The mutation case now refuses the export before uploading an incomplete database.
- **16/16 front/admin endpoint checks** passed: info, core manifest, database table metadata,
  paged and batched dumps, file listing, and refused wrong/missing tokens. Refusals are checked
  by the API's `unauthorized` result; HTTP status alone is not the authentication contract.
- **Whole-site restore passed:** 82 tables, 2,500 articles, 9,665 files and 95,704,064 packed
  bytes in 12 parts. All 9,665 restored file SHA-1 hashes matched the source listing.
  All base-table checksums matched except the intentionally excluded session table.
  The restored site's homepage and article answered HTTP 200, and the article contained its
  imported title and body. The source and copy used separate schemas and separate webroots.
- Real MariaDB `tests/import-integrity.php`: **PASS**. Escaped identifiers, snapshot copy,
  alias/view name collisions, dependent/empty views, parenthesized joins, nested SELECTs,
  preserved literals, refused external-schema references and keyless mutation are exercised.
  Dropping the source schema leaves every restored view readable.
- Tracy export/reply tests: **31 passed, 0 failed**.
- `bash joomla/cowork/build.sh`: **PASS**, installable package and contract assets generated.
- `node scripts/check-language.mjs joomla/cowork/lib`: **PASS**.

The test harness was corrected to read the final checksum output row rather than its column
header. The copied site's configuration was made writable solely to point it at the isolated
restore schema. No application fix was inferred from those harness/setup issues.

## Limits

Live CMS acceptance covers Joomla 6.2 only. PHP compatibility CI is not a live Joomla 3/4/5
acceptance test. The old numeric-offset API keeps its existing semantics. Checksums cost scans
and detect changes; they are not a transaction snapshot of the whole database. Cross-database
views are refused. No production deployment or Joomla release is asserted by this report.
