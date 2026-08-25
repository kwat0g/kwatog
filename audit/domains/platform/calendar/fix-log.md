# M010 Calendar — Fix Log

## 2026-08-25 implementation session

### 1. CAL-01, CAL-02, CAL-03, CAL-04, CAL-16 — visibility and destination authorization

- `api/app/Common/Services/CalendarAggregatorService.php:29-150,316-393,397-423,445-529`
- `api/app/Common/Controllers/CalendarController.php:27-93`
- Before: layer access was broader than the destination modules, leave rows were not row-scoped, payroll periods used the own-payroll permission, arbitrary department filters could fall through, and every event carried an actionable link.
- After: each layer uses its destination read permission; leave is own/department/HR scoped; department choices are active and hash-encoded; invalid or out-of-scope filters are rejected; links are nullable and emitted only for authorized destinations; narrow delivery read and production work-order read are supported.

### 2. CAL-05, CAL-06 — source-record and range correctness

- `api/app/Common/Services/CalendarAggregatorService.php:293-332,399-407,428-443,493-500`
- Before: raw reads could include soft-deleted records and maintenance used point-in-range checks.
- After: soft-deleted holidays, leave requests, employees, deliveries, and production work orders are excluded; maintenance uses interval-overlap logic with an explicit created-at fallback for not-yet-started work.

### 3. CAL-07, CAL-08 — deterministic range metadata and layer input

- `api/app/Common/Controllers/CalendarController.php:39-60,84-93`
- `api/app/Common/Services/CalendarAggregatorService.php:39-47,172-244,276-288`
- Before: duplicate layers could be requested and per-layer caps were silent.
- After: duplicate layer input is rejected/normalized, effective authorized layers are returned, opaque event IDs are used, and capped layers report returned counts plus `truncated` metadata.

### 4. CAL-09, CAL-10, CAL-11, CAL-12, CAL-13, CAL-14 — SPA interaction and accessibility

- `spa/src/pages/calendar/index.tsx:29-152,201-391,393-441`
- `spa/src/types/calendar.ts:31-78`
- Before: date-only values were parsed as UTC, the responsive grid collapsed below seven columns, overflow was noninteractive, options failures were ambiguous, event links were assumed, and controls lacked date context/target sizing.
- After: date-only values are parsed as local calendar dates; the grid stays seven columns with deliberate horizontal scrolling; overflow opens a keyboard-accessible modal; options have loading/error/retry states; event cells and controls have semantic labels, today state, focus styling, and 44px minimum targets; the nullable-link contract is reflected in SPA types.

### 5. CAL-15 — primary navigation

- `spa/src/components/layout/Sidebar.tsx:149`
- Before: the guarded `/calendar` route had no primary navigation entry.
- After: Calendar is visible in Overview only when `calendar.view` is present.

### 6. Regression coverage

- `api/tests/Feature/Calendar/CalendarAggregatorTest.php:23-245`
- `spa/src/components/layout/Sidebar.permissions.test.tsx:44-58`
- `spa/src/pages/calendar/index.test.tsx:1-104`
- Added API coverage for own/department/HR leave visibility, payroll permission separation, authorized links, soft-delete/range behavior, narrow delivery permission, duplicate layers, and invalid department filters; added calendar overflow/date/options regressions and a sidebar permission regression.

## Verification

- Passed: PHP syntax checks for controller, service, and feature test; targeted PHPStan; targeted ESLint; `git diff --check`.
- `npm run typecheck` reaches only existing errors in `src/pages/assets/detail.tsx` (`qrcode`/implicit-any) and `src/pages/return-management/detail.tsx` (duplicate JSX attribute); no calendar errors were reported.
- The API feature suite is currently blocked by the repository's pre-existing test-database migration failure: migration `2026_08_25_210000_enforce_one_active_holiday_per_date` attempts to drop the constraint-backed `holidays_date_name_unique` index. The unrelated migration was not changed.
- The focused SPA test could not start in the host checkout because `spa/node_modules/.vite-temp` is root-owned; the alternate runner then exposed the existing ESM `__dirname` issue in `spa/vitest.config.ts`. The test file passes targeted ESLint.
