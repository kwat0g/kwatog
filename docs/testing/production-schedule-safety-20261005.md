# Production schedule safety verification

This implementation addresses the twelve findings in [the production schedule audit](production-schedule-audit-20261005.md). It reuses the existing assignment, scheduling, attendance, audit, and notification services.

## Resulting behavior

- Direct WO confirmation, schedule batch confirmation, and reviewed crew confirmation share the crew approval guard. Reviews include the schedule timestamp and crew ID; stale reviews reject transactionally. Second-precision legacy schedule timestamps advance on each model update so two changes in one second cannot reuse a review version.
- Production Schedule handles proposals, timeline review, and readiness only. Selection, bulk confirmation, and inline assignment confirmation were removed at the user’s request. WO links open the record where authorized PPC or Production users review workers and confirm through the shared safety guard. The existing guarded backend batch endpoint remains available to its authorized callers.
- Automatic proposals require enough active Production employees with employee accounts and configured HR coverage. Managers remain excluded. Proposals do not reserve workers; assignment resolves competing commitments under employee locks.
- Working days are explicitly configured on date-effective HR shift assignments. Night shifts use their actual work date and may cross midnight; machine capacity is checked on both dates. Automatic planning skips holidays. Reviewed holiday production requires explicit Production Management authorization recorded for that interval.
- Start and Resume check the remaining duration, authoritative schedule, resource ownership, current crew, shift coverage, leave, and attendance. Known absence or clock-out cannot be overridden. Unknown imported attendance requires an audited managerial presence attestation for the current action.
- Timing and replacement crew can be reviewed together in one transaction. Invalid recorder or eligibility leaves both unchanged. Identical retries retain the saved crew and slot. Live handover retains one recorder and audit history.
- Running production retains equipment ownership and output recording after its planned end. No automatic release, unconfirmation, or change to the customer's agreed delivery date occurs.
- Distinct scheduling diagnostics persist across reloads. Partial runs use warning styling. WO schedule details and reviewed input use Asia/Manila plant time.

## Delegated code review

GPT-5.6-Luna and GPT-6.1-Sol both launched successfully for the final rollout review, with no substitute and no more than two new subagents. The earlier availability statement applied to the earlier tool session, not the final rollout. Older agents visible in the workspace belong to previous work.

Root reviewed the delegated HR calendar model, migration, requests, service, routes, resource, forms, and tests. Review corrected an argument-signature mismatch and checked strict weekday validation, permissions, inactive accounts, audit attribution, pagination, failed reads, and stale form selection. Root implemented and reviewed the shared production and scheduling changes and their callers. Additional fixes covered timing/crew retry identity, same-second review versions, phase-specific readiness, PPC work-order confirmation access, and HR mutation lock coordination.

## Verification evidence

The backend suite ran serially against the disposable PHPUnit database. Browser fixtures were restricted to the isolated audit application at `http://localhost:18080`.

| Check | Result |
| --- | --- |
| Focused attendance, planning, crew, resource, output, repair, material, and QC suites | 108 tests; 484 assertions passed |
| Production, HR calendar, and Gantt frontend suites | 39 tests across 6 files passed |
| Real headless Chromium role scenario | Passed; PPC, Production Manager, QC, recorder, supporting employee, and outsider contexts |
| Separate-process competing worker assignments | Exactly one overlapping assignment committed; the other rejected |
| PHP formatting | 10 final production/calendar files passed |
| Frontend lint | Passed for changed production/calendar surfaces |
| Production build | TypeScript and Vite passed |
| Whitespace check | `git diff --check` passed |

The browser scenario opens an unstaffed row in Schedule list, verifies confirmation and selection controls are absent, follows its WO link, reviews automatic and manual crews, confirms the exact reviewed slot from the WO record, and exercises recorder permissions, output replay, handover, resource release, reviewed recovery, and rejected takeover. Desktop and mobile screenshots were inspected. Unit regressions additionally cover unknown/absent/clocked-out attendance, preceding night-shift dates, a 95-minute job with one minute remaining, stale batch rollback, same-second schedule changes, holiday authorization, combined recovery rollback/retry, and cross-midnight capacity.

This is focused production-process verification, not certification of the full Sales Order-to-payment process. The browser fixture uses a prototype WO without BOM materials; material reservation and issue behavior is covered by the backend suites.

## Development records and rollout

Both additive migrations were applied to the development database: `working_days` on employee shift assignments and `scheduling_diagnostic` on work orders. Neither migration fabricated calendars or changed business transitions, accounts, passwords, payroll, stock movements, or recorded output.

The approved missing-calendar setup was applied under the existing `hr@ogami.test` account through `ShiftAssignmentService`: 148 shop-floor assignments now use Monday–Saturday and 58 office assignments use Monday–Friday. Existing accounts, passwords, shift definitions, assignment dates, and configured calendars were preserved. The setup covers current and future non-expired null calendars; repeat preview reports zero pending changes and 206 preserved calendars. Soft-deleted linked accounts remain deleted and are reported rather than recreated.

Only WO-202610-0011 and WO-202610-0012 were replanned, inside a transaction that required exactly two successes and no conflicts. Both remain planned for the user's manual B2B test. WO-202610-0002 received one automatically suggested eligible employee, Catherine Rivera, who is also its sole output recorder. It remains confirmed with its original approved slot. WO-202610-0013’s existing crew and approved slot were preserved. The transaction checked unchanged customer links, WO planning dates, equipment assignments, output quantities, and lifecycle states. Production was not started.

A private PostgreSQL backup and applied reports were retained under `api/storage/app/production-safety-rollout-20261005/`. Existing unrelated expired commitments remain visible for authorized review; the rollout did not extend customer delivery dates.

Rollback should first prevent new approvals, then restore the corresponding API and frontend together. Preserve calendar and diagnostic columns until no restored caller relies on them. Do not delete crews, schedules, stock reservations, attendance, or output to undo application changes.

## Deliberate limits

The planner uses the existing greedy algorithm and a bounded 90-day shift-boundary search. It does not split one job across multiple shifts or optimize the entire factory. Candidate equipment and employees are serialized during planning; narrower locks are appropriate only if measured planning throughput requires them. Holiday authorization covers production scheduling, not payroll overtime approval.

## Final confirmation-removal checks

After the user's scope change, 38 frontend tests across six files passed, including calendar editing. Three headless Chromium schedule scenarios passed for desktop, mobile, and failed-read recovery. The real six-role crew/output/handover scenario passed again with confirmation performed from the WO record. A live read-only browser check verified PPC and Production have no Schedule confirmation controls, PPC still sees WO-record Confirm, WO-202610-0002 has its recorder, and HR receives configured calendars. The attendance backend suite passed again: 13 tests, 76 assertions. Final TypeScript/Vite build, targeted ESLint, PHP syntax/formatting, whitespace, and the scoped design detector passed.

Root reviewed both subagents' work. Luna's soft-deleted account finding was fixed at the account lookup. Its suggested HR-only actor restriction was unnecessary because existing permission policy authorizes administrators too; the actual rollout used HR. Its future-calendar concern was resolved by making the approved scope explicit. Sol's exact-ID, transactional success, staffing-limit, and preservation checks were implemented. The final schedule-removal review found no blocking issues.
