# Production scheduling and worker availability audit

Scope: production schedule proposals and confirmation, WO confirmation, assignment, start/resume, HR coverage, and the schedule page. This audit changes no product behavior or business records.

## Confirmed observations

The development database contains WO-202610-0002 in confirmed status without a current ordinary production crew. The focused existing test `test_confirm_promotes_the_work_order_and_schedule_as_one_process` passes after creating no crew: one test, four assertions. Its successful result reproduces the missing crew requirement, rather than proving the desired rule.

The four work orders shown in the user's conflict panel have planning deadlines in the past: WO-202610-0009, WO-202610-0010, WO-QC-DEMO-20261003, and WO-QC-30-20261003. Run Scheduler does not extend these deadlines. Three display the same deadline failure repeatedly; the fourth displays a generic mold-capacity failure. These are planning failures returned by the application, not evidence of a server crash.

## Findings and recommendations

1. **High: confirmation can bypass crew review.** `CapacityPlanningService::confirm` calls `WorkOrderService::confirm`; neither requires a current crew or one eligible output recorder. The schedule UI checks proposal status, timing, and equipment, but ignores staffing blockers. The direct WO confirmation endpoint has the same service gap. Reuse the reviewed assignment flow from the WO page for single-row approval, and require valid crew readiness at the shared confirmation boundary. Keep bulk approval only for already staffed, valid proposals.

2. **High: schedule generation is not aware of labor capacity.** `placeWorkOrder` finds machine/mold capacity and immediately returns a slot. It does not find enough available employee-role Production workers or their HR coverage. A proposal at 03:06 can therefore have no eligible crew. Include worker coverage in the bounded slot search and report a staffing shortage distinctly from equipment shortage. Do not assign employees merely by saving a proposal.

3. **High: scheduler reruns can move previously assigned workers into invalid hours.** Successful runs supersede pending proposals and save replacement timings without calling `assertScheduledCrew`. Equipment reassignment and priority reordering already have worker checks. Preserve assigned crew coverage or report a conflict before replacing a pending proposal.

4. **High: late starts ignore remaining time.** `assertExecutionSlot` checks only that the current time lies inside the approved interval. Resource validation measures duration from the original scheduled start, not the actual start. A 95-minute job can start one minute before the interval ends. Recheck remaining production duration from the actual start or resume time and require an approved replacement slot when it will not fit. Normal completion must remain possible after an overrun; never release occupied equipment automatically.

5. **High: absence and rest days are not part of worker readiness.** Assignment checks active employee accounts, department, shift coverage, approved leave, and overlapping assignments. It does not query Attendance, including `is_rest_day`, `time_in`, or `time_out`. Shift assignments supply date ranges and hours, not weekly working days. Reject explicit absence or off-duty evidence at execution; define how missing attendance is handled before enforcing a clock-in requirement. Use the night shift's actual work date for 03:06 production. Future proposals should not require attendance records that do not exist yet.

6. **High: live handover bypasses shift and leave checks.** Worker options disable HR checks for active or paused ordinary production, and assignment enforces full shift coverage only before start or before repair starts. A replacement may be off duty or on approved leave. Check the replacement against the remaining execution interval and current attendance. Preserve manager-approved handover history without granting unrestricted off-duty access.

7. **Medium: expired proposals can still be confirmed.** Confirmation validates positive duration, resource capacity, and WO deadlines, but has no explicit future/unexpired check. An otherwise valid historical proposal can become a confirmed WO that cannot start. Reject expired proposals and return the required scheduling action.

8. **Medium: approval has no server-side reviewed version.** `ConfirmScheduleRequest` submits schedule IDs only. The UI refreshes and compares times and machine/mold codes, but a change between that read and the approval is still possible. A pending row retains its ID after reassign or reorder. Submit expected schedule versions and reject changed rows under the existing transaction locks. Include the reviewed crew version if staffing is part of approval.

9. **Medium: priority reordering can violate an individual WO's earliest start.** `reflowMachineTimeline` starts from the earliest pending slot on the machine, then advances a shared cursor. It checks each WO's deadline but does not clamp its new start to that WO's `planned_start`. Respect each work order's earliest start when calculating the next slot.

10. **Medium: calendar rules are incomplete.** Equipment capacity is a daily minute budget; it does not identify factory operating hours or rest days. `nextFreeSlot` rejects slots crossing midnight and moves them to the next midnight even though HR supports night shifts. Shift coverage skips holidays entirely, without an approved holiday-work path. Use the existing HR shift boundaries for candidate times and make overnight/holiday limitations explicit. Do not infer that Sunday is a working day or a rest day from an eight-hour shift alone.

11. **Medium: readiness messages are weaker than execution guards.** Schedule readiness checks crew membership eligibility but does not verify all staffing limits or active recorder validity, and skips those checks for active or paused WOs. A displayed recorder name does not prove the account can record. Use a shared non-mutating readiness check consistent with confirmation and execution; separate planning blockers from execution blockers.

12. **Medium: scheduler failure presentation hides recovery actions.** Each unsuccessful machine/mold candidate appends the same exception message; the frontend joins every reason into one paragraph. Expired deadlines become repeated capacity warnings, mold-life rejection is reduced to `no_mold_with_capacity`, and all run outcomes use success styling even when conflicts remain. Conflict results exist only in component state, so reopening the page loses the failed-run panel. Return distinct reasons with enough context for the next action, deduplicate messages, use warning styling for partial success, and retain or recompute unresolved scheduling failures.

## Suggested implementation order

First close confirmation bypasses and stale approval, using the existing reviewed assignment and shared server validation. Next add remaining-time checks and enforce worker availability across reruns and handovers. Then connect execution to reliable absence/off-duty data and align equipment proposals with HR coverage. Finally make conflicts concise, actionable, and persistent or reproducible.

Existing safeguards should remain: transactional material reservation, machine/mold overlap checks, authoritative runtime ownership, department/account restrictions, crew versioning, and recorder-only output. Do not automatically unconfirm business records, move confirmed plans, extend customer delivery dates, or release running equipment.

## Verification limits

This is a focused read-only audit with one existing test executed against the disposable test database. It is not an E2E certification or an applied fix. The development queries only read work-order status, crew existence, and planning dates. Broader prior passing tests do not cover the newly identified requirements.
