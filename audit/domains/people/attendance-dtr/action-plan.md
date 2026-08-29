# M018 — Attendance & DTR action plan

Status: 🔁 Needs Re-audit
Last updated: 2026-08-30 (supersedes the 2026-08-27 plan)
Overall recommendation: separate-recommended

## Closed

| Finding | Closed by |
|---|---|
| M018-F01 … F07 | 2026-08-25 implementation, executed and verified 2026-08-27 |
| M018-F13 nullable correction fields | commit `7311f052` |
| **M018-F18** archived-day write break + SQL disclosure | 2026-08-30, this module |
| **M018-F23** unvalidated sort direction | 2026-08-30, this module |
| **M018-F14** loose attendance date rule | 2026-08-30, this module |

## Ordered actions

Ordered by blast radius, not by size: anything that can put a wrong number on a
payslip comes before anything that only annoys a user.

| # | Finding | Class | Scope | Session | Deliverable / acceptance evidence |
|--:|---|---|---|---|---|
| 1 | **M018-F20** extended 6AM–6PM shift pays zero OT | Question → Broken | large | **separate-recommended** | A human picks reading A or B (see audit report). Then either the `is_extended` branch measures excess against a normal-day length and `test_extended_shift_full_pays_auto_ot` starts asserting what its name says, or the test is renamed and CLAUDE.md corrected. **Do not touch code before the decision** — every extended-shift payslip moves. |
| 2 | **M018-F10** payroll fence uses live employee attributes | Broken P0 | large | **separate-recommended** | The write fence consults **frozen** payroll membership (`payrolls` rows / `payroll_cycle_claims`) in addition to the period lock. Tests, all inside the transaction fence: a paid employee moved OUT of scope stays locked; an employee moved IN who was never paid stays mutable; a company-wide period still locks everyone. Cross-module (reads Payroll) — hence separate. |
| 3 | **M018-F19** extended auto-OT ignores the 30-min min and 4-h max | Broken P1, money | medium | **separate-recommended** | The `is_extended` branch honours `attendance.ot.minimum_minutes` and `attendance.ot.maximum_minutes`, and `auto_ot_hours` validation is bounded by the same maximum instead of a hardcoded `max:8`. Regression tests at 10 min excess, at exactly 240 min, and above. Sequence **after** item 1 — the same branch is in play. |
| 4 | **M018-F25** approvable OT ceiling (8 h) ≠ payable ceiling (4 h) | Incomplete P2, money | small | **separate-recommended** | One number governs the field, or the create form warns that hours above the payable cap will not be paid. Needs the item-1/3 decision first, since all three concern the same ceiling. |
| 5 | **M018-F11** bulk-OT returns internal exception text | Broken P1 | small | **separate-recommended** | Expected domain failures keep their actionable message; anything else is logged with the OT id and returned as a stable safe reason/code. Regression test injects a non-`BusinessRuleException` and asserts no raw text and no SQL reaches JSON. Touches the contract `spa/src/pages/attendance/overtime/index.tsx` reads. |
| 6 | **M018-F22** bulk-OT leaks integer PKs; drops bad hashes silently | Broken P2 | small | **separate-recommended** | `failed[].id` is a HashID; a non-existent id is indistinguishable from a state refusal; undecodable hashes are **reported**, not filtered away, so counts add up. API contract change → separate. |
| 7 | **M018-F21** import creates attendance for separated employees | Broken P2 | medium | **separate-recommended** | Policy chosen (refuse / flag / bound by `date_hired`…`clearances.separation_date`), enforced in **both** import loops and in manual create, with tests for pre-hire, post-separation and reissued-badge rows. Payroll consumes the result → separate. |
| 8 | **M018-F26** no `Cancelled` OT state; withdrawal reads as rejection | Incomplete P2 | medium | **separate-recommended** | `OvertimeStatus` gains `Cancelled`; `cancel()` writes it; a cancellation event/type replaces the false-decision reuse; `status=rejected` no longer returns withdrawals. Migration for the existing `status` check constraint + a backfill keyed on `cancelled_at`. Adds an enum case and a notification type → separate. |
| 9 | **M018-F15** recurring holidays ignored outside their year | Missing P1 | medium | **separate-recommended** | Recurrence semantics defined (including 29 February), then either expanded at lookup or materialised yearly. DTR tests across two years plus cache-invalidation tests. Changes `day_type_rate`, so it is a pay change → separate. |
| 10 | **M018-F12** archived/inactive shifts assignable | Incomplete P2 | small | **separate-recommended** | Assignment resolves the `Shift` inside the write transaction and rejects trashed ids; the inactive-shift rule is documented and enforced; `spa/src/pages/attendance/shifts/assign.tsx:34-38` filters to `is_active: true`. Single + bulk tests for missing / trashed / inactive / active. Needs the inactive policy decision → separate. |
| 11 | **M018-F08** raw-punch product surface | Missing P2 | medium | **separate-recommended** | Unchanged from 2026-08-27: expose with an explicit tested mode + UI, or mark internal and stop implying raw support. Gated on knowing what the FCIE terminals export. |
| 12 | "New OT request" button ungated; route guard names the wrong permission | Polish / RBAC | small | **separate-recommended** | `spa/src/pages/attendance/overtime/index.tsx:203-205` gates on `attendance.ot.create`; `hrRoutes.tsx:130` guards the create route on `attendance.ot.create` rather than `attendance.edit`, matching `routes.php:44`. Touches RBAC → separate, and best done with item 5/6. |
| 13 | **M018-F09** contention + browser evidence | Incomplete P1 | large | **separate-recommended** | Two-connection PostgreSQL contention tests over `AttendanceDateMutabilityGuard`, `ShiftAssignmentService` and `OvertimeService`; an authenticated Chromium walk over correction, archive/restore, OT decisions, import errors and locked-period messaging. **Chromium, not Lightpanda** — the assertions are geometric. |
| 14 | **M018-F17** zero-minute auto-OT threshold | Incomplete P3 | small | same-session-ok | `autoDetectFromAttendance()` requires strictly positive excess, or the setting is bounded `>= 1`. Test: punch-out exactly at shift end with the threshold set to 0 creates nothing. Unreachable at the seeded value 30, so low urgency. |
| 15 | **M018-F24** raw importer discards the `direction` column | Incomplete P3 | small | same-session-ok | Either `PunchSessionizer` uses `direction` to anchor IN/OUT, or the column stops being parsed and the docblock stops listing it. |
| 16 | Missing stale state on all four list pages | Polish | small | same-session-ok | `isPlaceholderData`/`isFetching` affordance per `docs/PATTERNS.md:1522-1546` on `index.tsx`, `shifts/index.tsx`, `holidays/index.tsx`, `overtime/index.tsx`. |
| 17 | `import.tsx` never invalidates the attendance query | Polish | small | same-session-ok | `queryClient.invalidateQueries(['attendance','attendances'])` in the import mutation's `onSuccess`. |
| 18 | Two translucency violations | Polish | small | same-session-ok | `index.tsx:143` `bg-danger/10` → `bg-danger-bg`; `holidays/index.tsx:309` drop `opacity-70`. |
| 19 | `auto_ot_hours` Zod schema has no numeric bound | Polish | small | same-session-ok | Client schema coerces to a number and mirrors the server bound — **after** item 3 settles what that bound is. |
| 20 | `font-mono` without `tabular-nums`; two `meta.total` without `formatInt` | Polish | small | same-session-ok | Cited sites in the audit report's polish pass. |

## Gate application

Nine of the twelve substantive items (1–13, excluding the P3s) are
`separate-recommended`: money (1, 3, 4), state machine (8), RBAC/API contract
(5, 6, 12), cross-module payroll (2, 7), product contract (11, 15-as-9). The
`same-session-ok` items are all P3 or polish. The gate is therefore **not**
"fix everything now", and it was not attempted.

Three items were fixed this session under the escape hatch (F18, F23, F14):
one root cause, one theme — an unhandled fault reaching the client instead of an
actionable message — and no overlap with anything gated. See `fix-log.md`.

## Verification sequence for the next session

1. **Get the two pay decisions first** (items 1 and 4). Items 1, 3 and 4 all move
   the same `is_extended` / OT-ceiling arithmetic; implementing any of them before
   the decision risks landing a payslip change that has to be reverted.
2. Write the F10 frozen-membership test **before** the implementation, and keep
   the existing locked-row transaction fence intact — the current guard is
   correct for unscoped periods and must stay so.
3. Establish the safe-error contract (item 5) before item 6, since both reshape
   the same `failed[]` payload, and land item 12 with them so the OT surface
   changes once.
4. Then items 7–11, each with its policy decision recorded in the audit report
   before code moves.
5. Item 13 last: contention tests and the Chromium walk are the acceptance gate
   for the whole module, and running them before the above lands just means
   running them twice.
6. Polish items 14–20 can ride along with whichever backend item touches the same
   file, except 19 which depends on item 3.
7. Always a private `DB_DATABASE`; never `ogami_test`. Commit explicit paths only.
   Do not release M018 as ✅ Verified until items 1, 2, 3 and 13 all have evidence.
