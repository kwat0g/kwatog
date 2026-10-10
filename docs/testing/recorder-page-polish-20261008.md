# Recorder work-order page verification

The worker detail page now prioritizes earlier-production reporting when live capture is unavailable. Expired trial-capture cards and duplicate access notices are hidden; active legacy trial capture remains available when authorized. Historical pages omit unavailable current receipts. Quantity labels distinguish physical production from QC approval; missing QC totals display an unknown value instead of falsely claiming all output awaits QC.

The API provides a saved schedule interval, including released historical schedules. It bounds that interval to the recorded work-order run and historical crew assignment. Missing, invalid, future-finished intervals show a recovery message. The form displays those times read-only in Asia/Manila with 12-hour formatting. Restored draft timestamps cannot replace schedule values. Existing independent approval, historical evidence, overlap, interval, and idempotency validation remain in force.

Checks:

- `LateProductionReportTest`: 20 passed, 180 assertions, disposable PostgreSQL database `ogami_test_finish_first_core_20261008`.
- Recorder page and late-report component tests: 20 passed; covers locked schedule values, submission payload, missing-schedule blocker, expired trial visibility, and existing recovery paths.
- Targeted ESLint, PHP lint, and task-owned diff whitespace checks passed.
- Full SPA build passed after final visual fixes; result is in `/tmp/recorder-polish-build-final.log`.
- Real recorder account used against disposable browser database at `http://localhost:18084`. Desktop 1440 × 1000 and mobile 390 × 844 screenshots show locked times and no trial card or horizontal overflow. No live production or QC test records were added.

The UI uses a schedule interval, not an independently entered actual batch interval. Timing differences need explanation and management reconciliation. Active legacy QC gates are preserved; this UI refinement does not convert existing production evidence.
