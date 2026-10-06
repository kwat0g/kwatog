# Time In / Time Out (geofenced self-service punch) — Design

Date: 2026-10-06
Chain: 3 (Hire to Retire) — feeds Biometric/DTR → Payroll.

## Goal

Employees punch time in/out themselves from their own phone in the SPA. A punch is accepted only when the phone's GPS places the employee inside the plant geofence. The punch feeds the existing `attendances` row, so DTR, OT and payroll are unchanged and remain the source of truth.

Today attendance enters only by biometric CSV import (`DTRImportService`) or HR manual entry (`AttendanceService::create`, `is_manual_entry = true`). `pages/self-service/dtr.tsx` is read-only. Plant coordinates already exist as settings `company.latitude` / `company.longitude`.

## Decisions (agreed)

1. **Check:** GPS only. Accept iff `distance <= company.punch_radius_m` (default 200) AND `accuracy <= company.punch_max_accuracy_m` (default 100). Distance is haversine from `company.latitude/longitude`.
2. **Centre:** admin settings page gets a "Use my current location" button that writes `company.latitude/longitude` from the admin's browser fix.
3. **Failure:** out of range, no GPS, or poor accuracy is a hard block (422, specific message). Real exceptions go through HR manual entry. No missed-punch request flow.
4. **Scope:** all employees, own session, personal phone. No exemptions, no kiosk mode.
5. **Window:** punch allowed only from `attendance.punch_early_minutes` before shift start to `attendance.punch_late_minutes` after shift end. Shift is resolved exactly like the DTR: employee assignment, then default shift. If neither exists, block with "ask HR to configure a shift".
6. **Direction:** server-decided from the employee's last punch (none or `out` → `in`; `in` → `out`). Client never sends direction. Cooldown ~60 s rejects double taps. Multiple in/out per day is allowed.
7. **Pairing / date anchoring:**
   - An `in` with no open row starts a row for today's date.
   - An `out` is only possible when the last punch was an `in` within the last 16 hours, and it attaches to that punch's row (covers night shifts ending next morning). An `in` older than 16 hours is stale, so the next punch is an `in` and starts a new day.
   - On a non-night shift an `out` not after `time_in` is rejected with a clear message (the engine would otherwise throw `InvalidArgumentException`).
   - A further `in` while a row is open is logged but does not move `time_in` (first-in wins). Each new `out` replaces `time_out` (last-out wins).
8. **Biometric merge:** both `DTRImportService` paths (`import()` and `importRawPunches()`) take the earliest in and latest out across the CSV and that day's logged `attendance_punches`, instead of overwriting. Existing manual/on-leave/archived guards are unchanged.
9. **Forgotten time-out:** no auto-close (would fabricate pay data). Add a "missing time-out" filter to the existing attendance list and a daily scheduled notification to HR listing yesterday's open rows.
10. **Locks:** a punch on a payroll-locked day (`AttendanceDateMutabilityGuard`) or against a soft-deleted row is rejected with "this day is already in payroll / was removed, contact HR". A user with no linked employee gets the existing 403. A day whose row is an HR manual entry (`is_manual_entry`) or `on_leave` also rejects the punch (`manual_entry` / `on_leave`), mirroring `DTRImportService::manualCorrectionGuard` (AT-02).
11. **Data:** new table `attendance_punches`. Raw `latitude`/`longitude` are nulled after 90 days by a scheduled command; `distance_m`, `accuracy_m` and the result are kept permanently. The punch screen shows a one-line notice that location is read only at the moment of punching (RA 10173).
12. **Visibility:** raw location requires new permission `attendance.punch.audit` (hr_officer, system_admin) and shows in a modal opened from a row action on the attendance list (`/hr/attendance`); there is no attendance detail page. Employees see only their own punch times.
13. **UI:** Time In/Out card on the existing self-service home (no new route): one large button, state line ("In since 06:02, shift 06:00–14:00"), today's punch list, display-only late warning (reads `attendances.tardiness_minutes`, which the DTR engine already computes on each punch; nothing new is stored).
14. **nginx:** `Permissions-Policy` `geolocation=()` → `geolocation=(self)` in `security-headers-dev.conf`, `security-headers-prod.conf`, and the inline copies in `default.conf:45` and `prod.conf:100`. Camera and microphone stay blocked. The SPA `X-Frame-Options` carve-out is untouched.

## Out of scope

Offline queueing, multiple sites, kiosk mode, rotating QR / IP allowlist, missed-punch request flow, a global punch-log page, auto-closing open rows.

## Backend

Module: `api/app/Modules/Attendance/` (own Controllers/Models/Services/Requests/Resources).

- **Migration** `0500_create_attendance_punches_table` (confirm real max first; highest seen is 0499; it touches only `employees`/`attendances`, which come from `0NNN_` migrations — verify no `2026_*` dependency before naming). Columns: `id`, `employee_id` FK, `attendance_id` FK nullable, `direction` (string 3, enum), `punched_at` timestamp, `latitude` / `longitude` decimal(9,6) nullable (purgeable), `accuracy_m` decimal(7,2), `distance_m` decimal(9,2), `ip`, `user_agent`, timestamps. Index `(employee_id, punched_at)`.
- **Settings migration/seed:** `company.punch_radius_m` (200), `company.punch_max_accuracy_m` (100), `attendance.punch_early_minutes`, `attendance.punch_late_minutes`, plus the admin-editable registration in `SettingsSeeder`.
- **Enum** `PunchDirection` (`in`, `out`). **Model** `AttendancePunch` with `HasHashId`, no `status`; never exposed with raw `id`.
- **Service** `PunchService` (inside `DB::transaction()`, employee row locked):
  1. Resolve employee from `$request->user()->employee_id` (never from input).
  2. Resolve shift (assignment → default); enforce window.
  3. Validate accuracy and haversine distance.
  4. Enforce cooldown; decide direction; choose or open the day row per decision 7.
  5. `assertMutable`; insert punch; set `time_in` / `time_out`; reuse `DTRComputationService::computeForRecord` and OT auto-detect via the existing `recomputeForEmployeeOnDate` path.
  6. Audit via `HasAuditLog` (web guard, so no `SystemUserResolver` needed).
- **Distance helper** `GeoDistance::meters()` as a small pure class in `Attendance/Support/`.
- **Endpoints** in `Attendance/routes.php` (so the `feature:attendance` toggle gates them), auth-only, with `throttle:sensitive` on the POST:
  - `GET /attendance/punch/status` → state, next action, today's punches, shift, late warning.
  - `POST /attendance/punch` → `{lat, lng, accuracy}` (FormRequest validates ranges).
- **HR audit:** `GET attendance/attendances/{attendance}/punches` behind `attendance.punch.audit`; the route is gated on the permission, so its resource always includes coordinates (null after the 90-day purge).
- **Attendance list:** `missing_time_out=1` filter.
- **Import merge:** change in `DTRImportService` per decision 8.
- **Scheduled commands** in `routes/console.php`:
  - `attendance:notify-missing-timeout` daily, new `NotificationCatalog` entry `attendance.missing_time_out` to HR. It must exit non-zero if every send throws (no swallowed failure, per the dead-subsystem rule in CLAUDE.md).
  - `attendance:purge-punch-coordinates` daily, nulls coordinates older than 90 days.
- **Permission** `attendance.punch.audit` seeded; check `ApprovalChainRolePermissionDriftTest` is unaffected (no approval chain involved).

## Frontend

- `spa/src/pages/self-service/time-clock` card on the self-service index, lazy-loaded, route under the existing self-service guards. Uses `navigator.geolocation.getCurrentPosition` with `enableHighAccuracy`. Distinct messages for: permission denied, unavailable/timeout, out of range (with distance), low accuracy, outside window, cooldown, locked day, no shift.
- Buttons disabled while pending; TanStack Query mutation with `toast` and `invalidateQueries`; decimals as strings; `font-mono tabular-nums` for times; status via `<Chip>`. Floor palette is not used (this is not a shop-floor PWA).
- Admin settings: "Use my current location" button on the company location settings.
- Attendance detail drawer for punches, shown only with `attendance.punch.audit`.

## Error handling

Every rejection is a 422 `BusinessRuleException` with a stable `code` the SPA maps to a message. Unexpected exceptions are not swallowed. A failed geolocation read never reaches the server.

## Testing

- Feature: in range, out of range, accuracy too poor, no GPS, outside window, no shift, cooldown, direction toggling, night shift across midnight, non-night out-before-in rejection, locked day, soft-deleted row, no employee link, biometric merge (CSV before and after an app punch, both import paths), missing-time-out filter, coordinate masking by permission, purge command, notify command failure exit code.
- Unit: `GeoDistance` (known coordinate pairs, zero distance).
- SPA: component test for each failure state and the disabled-while-pending button.
- E2E (Chromium, not Lightpanda): Playwright with geolocation permission and a faked position inside and outside the radius.
- Run on an isolated test database (`ogami_test_verify`), not the shared `ogami_test`.
- One manual real-phone check over HTTPS before go-live (dev `localhost` is a secure context; a LAN IP over HTTP is not).

## Known limits

GPS can be spoofed with mock-location apps or browser overrides. Accepted by decision; the retained distance and accuracy per punch, and the 90-day raw coordinates, are the HR audit trail. The plant centre must be set correctly (decision 2) or everyone is blocked.
