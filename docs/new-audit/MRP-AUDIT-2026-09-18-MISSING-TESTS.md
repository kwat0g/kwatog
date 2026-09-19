# MRP Audit Missing Tests

Date: 2026-09-19
 
## Re-audit 2026-09-19

Current verdict: **test-gap inventory remains valid**.

- Current source now has focused tests for inactive components, duplicate BOM components, plan summary wiring, sorted job identity, daily partial semantics, reaper metadata, and retirement audit behavior.
- Missing proof remains for BOM HTTP lifecycle, same-reason routing edits, deleted-version recreation, depth/UOM/MOQ boundaries, manual/daily MRP overlap, plan-history reconstruction, missing-BOM alerts, and Redis/two-worker behavior.
- Do not treat this file as a passing test report; use `RE-AUDIT-REGISTER-2026-09-19.md` for current module status.
Related audit: `MRP-AUDIT-2026-09-18-FINISHED.md`

The MRP implementation and focused regression coverage are finished. The items
below were not fully verified in this session and should be run later.

## Completed Verification

- Focused MRP/CRM regression set: **43 tests, 160 assertions, passed**.
- Entire `tests/Feature/MRP` suite: **119 tests, 281 assertions, passed**.
- PHP syntax checks: passed.
- PHPStan on the changed application files: passed.
- Pint on the changed PHP files: passed.
- PHPUnit test discovery for the affected classes: passed.

## Test Later

1. **Full API PHPUnit suite**

   The full suite started with 3,404 tests but was stopped by the user after
   2,562 tests. It had failures and errors before it was stopped. Those results
   were not triaged and must not be attributed to this MRP change without a
   clean rerun.

   Run with the isolated local PostgreSQL database:

   ```powershell
   $env:DB_HOST = '127.0.0.1'
   $env:DB_PORT = '5432'
   $env:DB_DATABASE = 'ogami_test_mrp'
   $env:DB_USERNAME = 'ogami'
   $env:DB_PASSWORD = 'ogami_dev_pw'
   php -d memory_limit=512M vendor/bin/phpunit
   ```

2. **Real reaper/per-SO transaction race**

   The code now locks the MRP run row and refreshes its heartbeat before the
   per-SO transaction commits. A live concurrent test with two database
   workers has not been run.

3. **Real queue overlap and follow-up dispatch**

   The job contract is covered, including sorted scope identity,
   `ShouldBeUniqueUntilProcessing`, retry capacity, and failed-run propagation.
   A Redis-backed worker test proving that a follow-up job waits behind the
   plant overlap lock has not been run.

4. **Migration preflight against existing production-like data**

   Migration `0501_guard_duplicate_bom_components.php` intentionally fails if
   duplicate component pairs already exist. Check the target database before
   migrating:

   ```sql
   SELECT bom_id, item_id, COUNT(*)
   FROM bom_items
   GROUP BY bom_id, item_id
   HAVING COUNT(*) > 1;
   ```

   Any returned rows require a business-approved cleanup before migration
   `0501` can be applied.

5. **Full migration and seed run in a production-like environment**

   The focused tests exercised `RefreshDatabase` and the new migrations on the
   local PostgreSQL instance, but a separately reviewed `migrate:fresh --seed`
   run and deployment migration on a copied production database remain to be
   performed.

## Environment

- Local PostgreSQL 18 is installed and running as `postgresql-x64-18`.
- Databases `ogami` and `ogami_test_mrp` exist with the project credentials.
- Docker Desktop and Redis were not installed because the focused PHPUnit
  suite uses array cache and synchronous queues.
