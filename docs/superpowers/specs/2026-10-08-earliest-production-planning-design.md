# Earliest production planning and date selection

Status: approved and implemented. See [verification and manual handoff](../../testing/earliest-production-planning-20261008.md). The evidence below describes the behavior observed before implementation.

## Goal and scope

Schedule production at the earliest feasible time, let an authorized planner choose a preferred production day before confirming a work order, and keep Start focused on checking and beginning actual production. Cover automatic MRP, the scheduler, WO confirmation, rescheduling, and their existing material, quality, attendance, and dependency connections.

## Evidence

`MrpEngineService` currently sets root WO `planned_start` to the sales-order line's current delivery date minus two days, and `planned_end` to that delivery date minus one day. `CapacityPlanningService::placeWorkOrder` treats that planned start as a lower bound. For `WO-202610-0003`, delivery is October 20, 2026, planned start is October 18, and production deadline is October 19.

A read-only preview that changed only the in-memory lower bound found an October 8, 2026, 7:11 PM–9:11 PM equipment and crew window. This was a scheduling probe, not a saved booking or proof that production could start immediately.

The scheduler also returns the first valid equipment pair in its preferred tonnage order and advances a per-machine cursor. These choices can hide earlier feasible windows on other machines or in gaps. Child WOs currently get dates by subtracting whole days from the parent's planned start, which would create expired child deadlines if the parent start were simply changed to today.

The Confirm modal currently generates a proposal only when none exists, and its worker-count override does not reach equipment placement. Date selection exists in the Start rescheduling flow after confirmation. These are connected causes of the confusing workflow.

## Alternatives

1. **Earliest feasible time, with an optional preferred day — recommended.** Uses idle capacity, leaves time for QC and replacements, and keeps an explicit planner choice.
2. **Keep delivery-based planning and add date selection only.** Smallest UI change, but every order still needs manual intervention to produce earlier.
3. **Add selectable earliest/latest scheduling strategies.** Useful for a factory deliberately minimizing finished-goods storage, but adds settings and explanations that this request does not need.

## Dates and policy

- Earliest feasible scheduling becomes the default for MRP-generated Planned WOs. New root WOs receive a planning reference at the MRP run time rather than delivery minus two days.
- Keep `planned_end` as the production deadline, currently one calendar day before the **current agreed** sales-order line delivery date. Keep requested delivery visible separately. Do not silently change either customer delivery date or that one-day buffer.
- Add nullable `preferred_production_date` to WOs. Null means earliest available. A date means search from that day, in Asia/Manila, with an explicit alternative if the preferred day has no feasible slot. A date is not a promise that equipment will be ready at midnight.
- Manual WOs retain their explicitly entered `planned_start` lower bound. Do not infer that all future planned starts are unwanted delays.
- For MRP WOs, legacy generated `planned_start` does not prevent an earlier proposal. Already confirmed, paused, or running WOs retain their approved timing unless an authorized user explicitly reschedules or extends them.
- Proposals must start no earlier than now and finish at or before `planned_end`. Equality at the deadline is allowed. Selecting the same calendar day is only allowed if the full interval fits before the exact deadline timestamp.
- A date-only deadline displayed as midnight must remain explicit: October 19 at 12:00 AM is the beginning of October 19, not its end. Do not reinterpret existing timestamps as end-of-day.
- An expired deadline produces a review blocker. Automatic scheduling never extends the deadline. Existing controlled late-production approval for execution/resumption remains a separate process.

## Scheduler behavior

Use the existing finite-capacity planner and resource guards. Compute the earliest feasible slot for every compatible machine/mold pair, then choose the earliest start; break ties by earliest end, smallest machine tonnage, and stable resource IDs. Fill feasible gaps using occupied windows instead of making a cursor from a later job an absolute lower bound.

Preserve priority and dependency ordering, with production deadline as the next deterministic ordering key for equal-priority independent WOs. This is a bounded greedy planner, not an optimization solver. An earliest slot for one WO is always relative to higher-priority commitments and already allocated windows.

The requested worker count, from 1 to 4, must reach equipment placement and staffing suggestion together. Manual assignment must obey identical slot eligibility. Pending proposals check that enough eligible workers exist; they do not reserve named employees. Actual assignment and confirmation lock and recheck workers so two WOs cannot confirm the same overlapping crew.

Keep machine capacity, running occupancy, mold life, compatibility, approved leave, HR shift coverage and working days, archived resources, and database overlap protections. No automatic overtime, holiday authorization, attendance fabrication, or manager inclusion in worker lists.

## Materials, subassemblies, and quality

- A free equipment window is an available **proposal**, not Start readiness. Show material and setup blockers beside it.
- Reuse stock availability and lot/hold rules: unreceived purchase orders, incoming-QC holds, quarantine/scrap locations, expired lots, and stock reserved for other WOs do not qualify as material ready now. Avoid creating a second inventory calculation.
- Keep confirmation's atomic material reservation and dependency guards. Failure must leave WO, crew, material reservations, and previous approved schedule unchanged. Keep Start's lot-aware issue, quality-specification, process-quality, and actual attendance checks.
- Do not fabricate a material-ready date from a purchase order ETA. If material is not received and released, show the appropriate warehouse/procurement/QC blocker. Preserve the existing stock-change-driven MRP replanning path so released stock can lead to a fresh proposal.
- New MRP subassembly WOs receive a non-past planning reference and inherit the root production deadline. Replace the recursive subtraction of whole days. The existing dependency graph must schedule descendants before ancestors and place a parent after the latest child proposal end.
- A child proposal end does not prove the child is complete or QC released. Parent confirmation/start must continue to require the existing actual good-output and usable-stock evidence. Explicitly include this distinction in diagnostics.
- Single-WO planning must account for descendants omitted from the request: use their current authoritative commitments, include unplanned required descendants in scoped planning where permitted, or return a dependency blocker. Never place the parent as if omitted descendants did not exist.
- WO operation timing currently divides `planned_start` through `planned_end` across routing operations. Once the planning reference becomes today, that interval could span many days. Pending operation projections must instead use the authoritative proposed/approved production slot when available. Keep started/completed operation timestamps, routing definitions, and linked QC evidence unchanged.
- Do not rewrite supplier requested dates, supplier lead times, purchase approvals, customer delivery promises, or quality disposition to make an earlier slot look ready.

## Confirm experience

Audience: PPC and Production Management users already authorized to assign and confirm. Preserve the current Atelier components and dense ERP layout.

The modal order is:

1. **Production day:** `Earliest available` selected by default, or `Choose day` showing one date input. Show current delivery and production deadline nearby.
2. **Workers:** existing Auto assign / Manual assign controls; worker count 1–4 only in automatic mode, selected workers and recorder in manual mode.
3. **Suggested schedule:** one readable date/time range, duration, machine/mold, crew, and concise readiness blockers.
4. **Confirm work order:** one primary action. Initial assignment reasons remain generated automatically; no new required explanation field for ordinary date selection.

Changing day or worker count refreshes the proposal once after a 300 ms debounce. Ignore out-of-order responses, retain the current preview while loading with confirmation disabled, and never refresh because the user pauses to read, focuses the window, or the clock advances. A failed request offers Retry. A day without capacity shows the reason and a later alternative requiring explicit approval; the original preferred date remains visible.

Manual workers selected for an old slot must be revalidated after a date change. Keep still-eligible selections; show and remove or require replacement of invalid ones before confirming. Never silently replace a manual crew or recorder. Keep removed-worker controls red and all warnings short, actionable, and accessible.

Preview generation may replace a pending proposal, as the existing POST already does. A choice becomes a saved WO preference only when assignment/confirmation or an explicit reschedule succeeds. Cancelling the modal does not persist a new WO preferred date, assign workers, reserve materials, or confirm production.

## Start and rescheduling

Start reads the approved schedule and rechecks equipment, workers, attendance, materials, quality, and dependencies. Retain the approved 15-minute late-start grace, verified-presence early start, and separately acknowledged very late start when all physical and deadline guards pass. Start never silently changes the scheduled day or starts production after a scheduling approval alone.

Offer Reschedule as the recovery action when timing or crew availability needs it. Retain explicit rescheduling of a valid future booking by authorized users. Reuse the same date choice and preview conventions. Choosing a new day triggers one suggestion; approving it saves timing, eligible crew, recorder, and preferred day together with existing history/version checks. No additional Check again button on the ordinary future-schedule message.

Future attendance is provisional. Known absence or approved leave blocks the affected day; missing attendance is not absence. Today's upcoming shift can be proposed without pretending the employee is present now. Early actual Start still requires verified presence and full coverage of the execution interval. Unknown attendance at normal execution retains the existing authorized, action-specific attestation; it cannot override recorded absence.

## Connected pages and compatibility

- WO record and MRP plan detail distinguish planning reference, preferred day, proposed/approved production, production deadline, requested delivery, and current delivery. Show 12-hour times in Asia/Manila using existing production date helpers.
- WO attention cards use the authoritative schedule for late-start warnings. An automatically generated planning reference of today must not make every unscheduled WO look late. Keep actual overdue-deadline warnings.
- Production Schedule stays a review surface with links to the WO; do not restore confirmation buttons there. Legacy confirmation endpoints must retain their complete guards and be tested for no bypass.
- Scheduler runs preserve approved bookings and manually saved preferences. Failed replanning must not release a usable prior reservation. Multiple automatic runs must not duplicate active WOs, crews, or reservations.
- Keep the current duration policy: reserve at least two hours, or the production estimate plus its existing buffer when longer. Do not shorten a large WO to two hours or treat two hours as a guaranteed completion time.
- Keep existing permissions and row scope. A crew-only actor cannot confirm or change a planning preference through a forged request. Do not grant new roles just to remove a blocker.
- Additive API fields use HashIDs and existing request validation. Existing clients without a preferred-date field retain their saved preference; an explicit null means earliest available.

## Acceptance and stop condition

The change is complete when an order due October 20 can receive an earlier feasible proposal automatically, WO-202610-0003 can choose today or a later preferred day before confirmation, all automatic/manual/readiness guards remain intact, existing confirmed schedules and manual start constraints are preserved, and focused backend, frontend, and browser scenarios pass.

Do not add a new planning dashboard, strategy settings, a scheduling solver, an arrival-date prediction system, biometric integration, or unrelated cleanup. Do not start/confirm a live test WO merely to prove the UI. Validate mutations on isolated fixtures, then hand the manual test sequence back to the user.
