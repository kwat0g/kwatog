# M010 Calendar — Audit Report

**Audit status:** Complete — Plan Ready  
**Module:** `platform/calendar`  
**Audited:** 2026-08-25  
**Scope:** Calendar API aggregation, permissions and source visibility, SPA page behavior, discoverability, accessibility, and design-system fit. Dependency modules were read-only reference points.

## Inventory

The module is implemented end to end:

- API routes expose authenticated `/calendar/options` and `/calendar/events`, both behind `calendar.view` (`api/routes/api.php:130-138`).
- `CalendarController` validates the date range and layer names, applies the configured 90-day default maximum, and delegates to `CalendarAggregatorService` (`api/app/Common/Controllers/CalendarController.php:23-75`; `api/database/migrations/0327_seed_calendar_range_setting.php:8-22`).
- The service aggregates holidays, approved leave, deliveries, maintenance work orders, payroll periods, and production work-order due dates (`api/app/Common/Services/CalendarAggregatorService.php:82-126`).
- The SPA has API/types, a routed page, month navigation, layer toggles, event details, and record links (`spa/src/api/calendar.ts:11-23`; `spa/src/types/calendar.ts:10-65`; `spa/src/pages/calendar/index.tsx:58-294`; `spa/src/routes/dashboardRoutes.tsx:81-85`).

## Findings

### Hardening — security and correctness

#### CAL-01 — Broken: leave events bypass existing leave visibility scope

`CalendarAggregatorService` makes the leave layer available with `leave.view` and then selects every approved leave in the requested range, including employee full names and department names (`api/app/Common/Services/CalendarAggregatorService.php:27-35,171-206`). The controller also accepts an arbitrary decoded `department_id`; a failed decode silently becomes `null`, which removes that filter (`api/app/Common/Controllers/CalendarController.php:56-61`).

This conflicts with the established leave policy: `LeaveRequestService` scopes ordinary users to their own requests, department approvers to their department, and HR to all requests (`api/app/Modules/Leave/Services/LeaveRequestService.php:130-153`). The dedicated leave calendar likewise requires department/HR approval permission and applies the user’s department (`api/app/Modules/Leave/Controllers/LeaveCalendarController.php:22-52`; `api/app/Modules/Leave/routes.php:23-25`). Seeded employee-type roles receive `leave.view` through self-service (`api/database/seeders/RolePermissionSeeder.php:731-738`), and all seeded roles receive `calendar.view` (`api/database/seeders/RolePermissionSeeder.php:772-788`).

**Impact:** a regular calendar user can learn other employees’ approved leave, names, and departments; a crafted department filter can also produce an unscoped request. This is a cross-module RBAC/privacy defect.

#### CAL-02 — Broken: payroll layer exposes all payroll periods under an own-payroll permission

The payroll layer is gated by `payroll.view`, whose seeded label is “View Own Payroll,” but the aggregator returns every payroll period in range (`api/app/Common/Services/CalendarAggregatorService.php:32,268-288`; `api/database/seeders/RolePermissionSeeder.php:108-112`). The payroll module distinguishes own payroll from period access: period list/detail routes require `payroll.periods.view`, while own-payroll routes use `payroll.view` (`api/app/Modules/Payroll/routes.php:49-52,68,101-105`). The existing security test explicitly verifies that `payroll.view` alone cannot list payroll periods (`api/tests/Feature/Security/PayrollAuthorizationTest.php:17-33`).

**Impact:** the calendar discloses payroll cutoff dates to users who only have own-payroll access, and its period links point to pages those users cannot open.

#### CAL-03 — Broken: record links are emitted without matching record-page authorization

Every mapped event receives a non-null SPA link, and the event modal always renders an “Open record” action (`api/app/Common/Services/CalendarAggregatorService.php:151-311`; `spa/src/pages/calendar/index.tsx:248-294`). Several links target routes protected by permissions that are not implied by `calendar.view`: holidays require attendance edit/holiday-management permission (`api/app/Modules/Attendance/routes.php:23-30`), leave pages require approver/manage permissions (`spa/src/routes/hrRoutes.tsx:138-154`), and payroll periods require `payroll.periods.view` (`spa/src/routes/payrollRoutes.tsx:20-35`).

**Impact:** users can receive an apparently actionable link that predictably returns an authorization failure; the problem is especially visible for leave and payroll events. The API contract should either return only links the caller can open or make links nullable and hide/disable the action.

#### CAL-04 — Incomplete: delivery visibility ignores the narrow delivery permission

The calendar allows deliveries only when the user has broad `supply_chain.view` (`api/app/Common/Services/CalendarAggregatorService.php:29-35`). Delivery read routes and the SPA route correctly accept either broad supply-chain access or `supply_chain.deliveries.view` (`api/app/Modules/SupplyChain/routes.php:86-100`; `spa/src/routes/supplyChainRoutes.tsx:26-34`). `warehouse_staff` is intentionally seeded with the narrow permission but not broad supply-chain view (`api/database/seeders/RolePermissionSeeder.php:604-629`).

**Impact:** warehouse users who can open deliveries cannot see the delivery layer in Calendar. The calendar permission check is stricter than the destination module.

#### CAL-05 — Broken: soft-deleted source records can appear in calendar results

Migration 0444 adds `deleted_at` to holidays, leave requests, deliveries, and work orders (`api/database/migrations/0444_add_soft_deletes_to_all_tables.php:25,28,38,44,65-72`), and the corresponding models use `SoftDeletes` (`api/app/Modules/Attendance/Models/Holiday.php:14-16`; `api/app/Modules/Leave/Models/LeaveRequest.php:20-22`; `api/app/Modules/SupplyChain/Models/Delivery.php:22-24`; `api/app/Modules/Production/Models/WorkOrder.php:24-26`). The calendar uses raw query-builder reads for those sources without `deleted_at IS NULL` predicates (`api/app/Common/Services/CalendarAggregatorService.php:151-168,171-206,210-231,290-311`).

**Impact:** archived events may be shown and their record links may lead to a missing/hidden record. The query should explicitly exclude deleted rows, or use model scopes where practical.

#### CAL-06 — Broken: maintenance events spanning the requested window are omitted

The maintenance query includes work orders only when `started_at`, `completed_at`, or (for open work) `created_at` falls inside the requested range (`api/app/Common/Services/CalendarAggregatorService.php:234-266`). A work order that started before `from` and completed after `to` has no timestamp in the window even though its mapped event spans the full window.

**Impact:** month/range results are incomplete for long-running maintenance work. The predicate should test interval overlap, with explicit handling for open records and null timestamps.

#### CAL-07 — Incomplete: hard per-layer caps silently truncate results

Leave, delivery, and production work-order queries are capped at 500 rows; maintenance is capped at 300, while payroll has no equivalent cap (`api/app/Common/Services/CalendarAggregatorService.php:171-311`). The response reports only the returned row count and does not expose truncation or a continuation mechanism (`api/app/Common/Controllers/CalendarController.php:65-75`).

**Impact:** dense ranges look complete when they are not. Add a documented cap with `truncated`/per-layer counts, or paginate/aggregate by range in a way the client can represent.

#### CAL-08 — Incomplete: layer input and response metadata are not canonicalized

`layers.*` validates membership but not uniqueness (`api/app/Common/Controllers/CalendarController.php:34-37`), and `filterByPermission` preserves duplicate requested layers (`api/app/Common/Services/CalendarAggregatorService.php:135-147`). The response echoes the raw request rather than the effective, permission-filtered layer set (`api/app/Common/Controllers/CalendarController.php:70-73`).

**Impact:** duplicate query parameters can duplicate events, and clients cannot tell which requested layers were actually authorized. Normalize with `distinct` and return effective layers separately.

### Polish — frontend, responsive behavior, accessibility, and discoverability

#### CAL-09 — Broken: the responsive CSS stops being a seven-column calendar

The weekday header and body use `grid-cols-2 sm:grid-cols-4 xl:grid-cols-7`, while the body retains six grid rows (`spa/src/pages/calendar/index.tsx:191-202`). At mobile and tablet widths, 42 date cells flow into two or four columns, so rows no longer represent calendar weeks and the header does not align with the body.

**Impact:** the primary calendar interaction is structurally incorrect on smaller screens. Use a fixed seven-column calendar with intentional horizontal scrolling/minimum width, or switch to a clearly designed agenda/list presentation at narrow widths.

#### CAL-10 — Broken: date-only event values can shift by one day in local time

The API maps date values as `YYYY-MM-DD` strings (`api/app/Common/Services/CalendarAggregatorService.php:151-168,171-206,210-231,268-311`), while the SPA constructs `Date` objects directly from those values (`spa/src/pages/calendar/index.tsx:88-100`). JavaScript parses date-only ISO strings at UTC midnight; in the configured Asia/Manila timezone that can render on the preceding local date.

**Impact:** events can appear on the wrong day. Parse date-only values as local calendar dates or use a date library/API representation that preserves the intended calendar day.

#### CAL-11 — Incomplete: hidden events are not operable or discoverable

Each day renders at most three event buttons; additional events are represented by a noninteractive `+N more` span (`spa/src/pages/calendar/index.tsx:220-237`). There is no way to inspect or activate the omitted events from the calendar.

**Impact:** important events are inaccessible in dense days. Make the overflow control interactive and provide a keyboard-accessible day detail/popover or agenda view.

#### CAL-12 — Incomplete: calendar cells and events lack sufficient semantic date context

Date cells are generic `div` elements, today is conveyed only through styling, and event buttons use the event title without a date in their accessible name (`spa/src/pages/calendar/index.tsx:203-233`). The design system requires keyboard-reachable controls, visible focus, text plus color for status, semantic tabular data where applicable, and 44px floor targets (`docs/DESIGN-SYSTEM.md:523-532`; `spa/src/styles/tokens.css:251-255`).

**Impact:** screen-reader and keyboard users do not get a reliable date/today relationship, and event controls are visually dense. Add date labels/roles, `aria-current="date"` for today, contextual event names, and a sufficiently large interactive target.

#### CAL-13 — Incomplete: event controls are below the project hit-target token

Event buttons use `text-2xs` with `px-1.5 py-0.5` (`spa/src/pages/calendar/index.tsx:220-233`), producing an approximately 18px-high target. The project token defines `--hit-min:28px` and the design-system floor is 44px (`spa/src/styles/tokens.css:81-85,251-255`).

**Impact:** dense events are difficult to click/tap and fail the repository’s own target guidance. Preserve visual density with a larger button box, padding, or an alternate day-detail interaction.

#### CAL-14 — Incomplete: options failure can present an empty, non-diagnosable calendar

The options query has no error/retry handling, and event loading starts with an empty layer set until options initialize (`spa/src/pages/calendar/index.tsx:58-86`). The page has a general event-query error state, but not a specific options failure path (`spa/src/pages/calendar/index.tsx:165-187`).

**Impact:** a failed options request can leave users without layer controls or a clear recovery action. Surface options errors with retry and gate event loading on a successful options response.

#### CAL-15 — Missing: Calendar is not discoverable in primary navigation

The route exists at `/calendar` behind `calendar.view` (`spa/src/routes/dashboardRoutes.tsx:81-85`), but the Sidebar Overview navigation contains no Calendar item (`spa/src/components/layout/Sidebar.tsx:119-149`). A repository search found no other calendar navigation entry.

**Impact:** users must know or guess the deep link. Add a permission-aware navigation item or another documented entry point.

#### CAL-16 — Incomplete: department filtering exists in the API but not the page

The API accepts `department_id` and passes it to aggregation (`api/app/Common/Controllers/CalendarController.php:38-61`; `spa/src/api/calendar.ts:11-23`), but the page renders only layer toggles and exposes no department selector (`spa/src/pages/calendar/index.tsx:149-163`).

**Impact:** users who need a department-scoped view cannot use the supported filter from the UI. Resolve the authorization model first (CAL-01), then expose only permitted department choices.

## Test and verification evidence

- `php -l api/app/Common/Controllers/CalendarController.php` — passed.
- `php -l api/app/Common/Services/CalendarAggregatorService.php` — passed.
- `npm run typecheck` from `spa` — passed.
- `php artisan test --filter=Calendar --list-tests` — passed, but listed only unrelated month/dashboard and leave-calendar tests; no CalendarAggregatorService/CalendarController or `/calendar/events` coverage was found.
- No product files were changed during this audit session.

## Open questions

- Should payroll cutoff dates be visible to ordinary employees at all, or only to payroll-period viewers?
- Should the calendar expose full employee names for department/HR users, or use a less identifying display for shared calendar views?
- Which maintenance statuses (cancelled, closed, archived) should be included? The current query does not document a status policy.
- For capped ranges, is an explicit truncation indicator sufficient, or is a day/agenda detail endpoint required?
