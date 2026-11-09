# WordPress import integrity verification

Verified locally on 2026-11-09 with PHP 8.3 and MariaDB in the isolated WordPress lab.
No customer account or customer WordPress credentials were used.

## Additional view regression

A valid view selecting two columns through an alias equal to its own name was rejected as
cyclic. The previous regular expression treated the SELECT-list comma as a table reference.
The same parser also confused an apostrophe inside a backtick identifier with a string literal.

The exporter now tokenizes identifiers and literals, and reads dependencies in table-reference
positions. It preserves SELECT expressions and literals, removes the source schema from table
references, and refuses references to another database. Parenthesized joins and nested SELECTs
retain their dependency ordering.

`tests/import-integrity.php` fails with `view dependencies could not be ordered` on the previous
implementation and passes with the fix. It restores views into a second schema, checks their
rows, drops the source schema, and confirms every restored view remains readable. Cases include
aliases equal to the view name, apostrophes and escaped backticks in identifiers, dependent and
empty views, parenthesized joins, nested SELECTs, literal preservation, and refused foreign-schema
references through ordinary and comma joins. Both temporary schemas are removed in `finally`.

## Results

- `php tests/run.php`, as the WordPress container user: **3440 passed, 0 failed**.
- `php tests/import-integrity.php`, with database credentials supplied through environment
  variables: **PASS**. This database-backed test is run separately from the unit suite.
- `node scripts/check-language.mjs wordpress/cowork/lib`: **PASS**.
- `bash wordpress/cowork/build.sh`: **PASS**, installable plugin ZIP generated.
- Tracy importer at merged commit `fb6aa239a310da92dd9c1106a9543fab4b87d8ad`:
  **31 tests passed** and **23 HTTP/MariaDB fault and restore scenarios behaved as expected**.
  Scenarios cover typed data round trips, views, malformed replies, stalled cursors, missing
  table lists, storage failures/retries, trailing notices, and concurrent keyless deletion.

These reproduce independent defects and verify their fixes. Historical customer failures have
no saved detailed response, so their original cause remains unproven. Mutation checks are not a
whole-database transaction snapshot. No production release or deployment is asserted here.
