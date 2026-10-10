# Scheduler snapshot schema repair

The request from `production@ogami.test` at `2026-10-09T18:16:00.232Z` failed because the deployed scheduler resource checks queried `maintenance_windows` while its migration was still pending. The application log contains PostgreSQL `42P01` for that table. The maintenance assignment checker also encountered `42703` for `repair_submitted_at`.

The repair applied only these existing additive migrations, in live batch 23:

- `2026_10_09_230200_add_maintenance_repair_verification`
- `2026_10_09_230400_add_maintenance_windows`

No code workaround bypasses maintenance reservations. No role grants, reseeding, production starts or schedule changes were applied. Existing maintenance orders retain legacy completion behavior, and the independent verification setting remains disabled.

Verification:

- A separate database restored from the live backup reproduced HTTP 500 before migration and returned HTTP 200 afterward.
- Migration rollback preserved all existing business columns. Reapplication succeeded, and another migration run was a no-op.
- Three focused tests passed with ten assertions: scheduler snapshot context, planning around maintenance windows, and machine/mold reservation conflicts with valid adjacent slots.
- The live HTTP kernel handled `/api/v1/mrp/scheduler/snapshot` with the production account and returned HTTP 200, twelve machine rows and three schedule bars in `Asia/Manila`. The probe used a read-only transaction and did not alter the account or its login session.
- Fingerprints of 250 business tables were identical before and after live repair, excluding newly introduced columns and the new disabled setting. Runtime tables were excluded.

Backup, migration copies, temporary test configuration and verification artifacts are under `/tmp/scheduler-repair-20261009`. Retain that backup through the normal operational backup policy. The database repair does not require a frontend rebuild. A fresh interactive login and full manufacturing workflow were not exercised.
