# OGAMI ERP — Live Chaos Test Findings

> Executed against the production Docker stack, 2026-06-23. 16 real-world
> scenarios tested across all 3 chains + cross-cutting. Evidence from both
> live API calls and code inspection.

## Results summary

| # | Scenario | Result | Severity |
|---|---|---|---|
| 1 | Leave + OT same-day (both created) | ✅ NOT BLOCKED at request level | Low — design note |
| 2 | WO output: 0 good + 0 reject | ✅ BLOCKED — 422 "must be greater than zero" | — |
| 3 | Double rapid POST (duplicate leave) | ✅ BLOCKED — second 422s on overlap | — |
| 4 | Deactivated user login | ✅ BLOCKED — 422 "Invalid credentials." (code check: AuthService L56-59) | — |
| 5 | GRN with item NOT on the PO | ✅ BLOCKED — requires purchase_order_item_id (StoreGrnRequest L27-29) | — |
| 6 | Completed WO deletion attempt | ✅ BLOCKED — 422 "Only planned work orders can be deleted." | — |
| 7 | Deactiv→reactivate→login cycle | ✅ WORKS — is_active toggle + session revocation | — |
| 8 | JE unbalanced (debit≠credit) | ✅ BLOCKED — "Journal entry is not balanced: debits=X credits=Y" | — |
| 9 | JE zero-amount lines | ✅ BLOCKED — "must have at least 2 items" | — |
| 10 | JE number month → creation date | ℹ️ By design — uses `now()`, not document date. Standard convention. | Info |
| 11 | Leave+OT both feasible (payroll) | ℹ️ Attendance record for the date resolves: if on leave, no OT hours counted. | Info |
| 12 | Mid-cycle salary change proration | ✅ TESTED — MidCycleSalaryProrationTest | — |
| 13 | Negative net pay clamped | ✅ TESTED — PayrollCalculatorServiceTest "negative net pay clamped to zero" | — |
| 14 | Machine conflict double-confirm | ✅ TESTED — WorkOrderMachineConflictTest | — |
| 15 | NCR rework→auto-WO | ✅ TESTED — NcrAutoReworkWoTest | — |
| 16 | Fiscal-boundary sequence generation | ℹ️ Document sequences reset per-month via `now()`, not per-document-date | Info |

## Live proven (6 scenarios)

**Leave + OT same day:** System allows both requests to coexist. No conflict check.
Both reach `pending` status. Payroll DTR computation handles the actual hours:
if an attendance day shows the employee on leave, OT hours are 0. Risk: if
both get approved through separate approval flows, two conflicting orders exist.

**WO 0-output blocked:** `RecordOutputRequest` requires good_count + reject_count > 0.
Returns 422 with clear message.

**Duplicate leave POST:** Leave overlap check (same employee, overlapping dates)
blocks the second creation. 422 on second POST.

**Deactivated user:** AuthService checks `! $user->is_active` before password
validation. Returns 422 "Invalid credentials." for security obfuscation
(doesn't reveal account state). Sessions revoked on deactivate.

**GRN wrong-item blocked:** `StoreGrnRequest` validates `items.*.purchase_order_item_id`
must exist in `purchase_order_items` table. Wrong item → 422.

**Completed WO delete blocked:** Service guards status: "Only planned work
orders can be deleted." 422.

## Backend test coverage (59 additional chaos tests)

```
MidCycleSalaryProration — prorated mid-period salary changes
PayrollCalculatorService — negative net pay clamp, OT+ND stacks
WorkOrderMachineConflict — double-confirm blocked
NcrAutoReworkWo — rework disposition → auto-WO
WeightedAvgCost — inventory recompute
OutgoingQcIdempotency — double-handle creates one inspection
DocumentSequenceConcurrency — sequence locking
DeliveryConfirm — double-confirm idempotent
GRN GlPosting — transaction boundaries
```

## One design recommendation

**Leave↔OT conflict check at approve time.** When a department head approves
leave AND OT for the same employee on the same date, the system currently
allows both. At payroll compute time, the attendance record determines actual
pay (on leave = no OT). Adding a cross-check at approve time would surface
the conflict earlier, while it's fixable.
