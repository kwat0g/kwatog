# WO scheduling and attendance safety

This implements the approved findings in `docs/audits/wo-attendance-scheduling-20261006.md`. The shared assignment service owns the rules for automatic suggestions, manual worker selection, schedule approval, Start/Resume and recorder authorization. No database schema, attendance import, dependency or broad employee permission was added.

## Planning a schedule

The proposed WO interval must fit one continuous HR shift, its configured working days and any approved holiday authorization. Active Production employees with active employee accounts qualify; managers and other departments do not become worker candidates. Other WO reservations, unresolved production responsibility, approved leave and known attendance blockers still exclude candidates.

When scheduling an immediate slot, automatic suggestions prefer eligible workers with a verified current punch-in before applying the existing workload ordering. They can propose a replacement for an unverified current crew, but never save that change without approval. The designated recorder and crew history remain intact until an authorized assignment is committed.

A future shift does not require a punch-in before its start. For example, at 10:00, an employee whose shift is 14:00–22:00 can cover a 15:00–17:00 WO but cannot cover a 12:00–13:00 WO. That candidate is labelled “Upcoming shift; attendance checked at Start.” Workers show the selected shift's hours and originating work date. Overnight attendance belongs to the day on which the shift started, rather than automatically using the date after midnight.

Manual selection uses the same server eligibility checks as automatic selection. Unavailable candidates show why they are disabled. The current selected worker labels refresh from the worker lookup rather than retaining old status text after a time change. A scheduling approval is not proof of physical presence.

## Attendance states and Start

Missing attendance is unverified evidence. It does not mean HR marked the person absent. Biometric imports can be delayed, and the employee's shift may not have started. Production never creates an absence or rewrites an attendance record because the punch is missing.

At Start or Resume, the system checks the actual schedule, remaining production duration, current crew, shift coverage, attendance, equipment ownership, materials and QC prerequisites again. Valid early punches count as present once the shift begins; arriving early does not authorize production outside the approved shift or schedule.

Recorded absence, on-leave status, clock-out, archived attendance and an invalid or mismatched punch cannot be overridden with the physical-presence checkbox. Archived or inconsistent records require HR review. A recorded clock-out inside the proposed WO interval also prevents assigning a worker whose recorded session cannot cover the entire interval.

Only missing verified attendance permits an authorized Production manager to explicitly confirm physical presence. The existing activity event records who confirmed it and the exact crew, schedule and employee identities. This operational confirmation does not create a punch or alter payroll. The open Start dialog refreshes every 30 seconds and clears an older checkbox confirmation; the transaction still performs the final checks when submitted.

## Output recording after attendance changes

The designated employee recorder must still be inside the approved production slot and a valid HR working session. Clock-out, recorded absence, leave, invalid attendance or an expired slot blocks new employee output. The message directs the employee to Production management to report earlier output.

When biometric attendance is missing, the current Start presence evidence can authorize the recorder only for the same crew and production slot within the same shift. A replacement slot or crew requires new evidence. Explicit attendance blockers always take precedence over that confirmation.

Production management can report goods actually made earlier through the existing output form, with an explicit recording reason and lifecycle authority when attendance or scheduling no longer permits ordinary recording. The output preserves the reporting actor, reason, crew and scheduled shift. This does not authorize further production, change actual attendance, bypass QC/material/quantity/equipment checks, or allow new output on a terminal WO. Existing exact idempotent retries still retrieve their original receipt without creating additional stock or output.

## Alerts and half-day leave

The existing minute reminder checks upcoming approved WOs and alerts PPC and Production management when assigned crew eligibility changed. These notices use the latest reservation and crew, respect notification preferences and deduplicate repeated runs. They link to the WO; the system does not silently replace workers or start production. Ordinary upcoming, due and missed-start reminders remain available to the current crew.

AM/PM leave has no authoritative clock interval in the current HR data model. Especially for a night shift, the system cannot safely invent which hours remain available. Such leave therefore stays blocked with a specific HR-review explanation. Supporting the remaining half-shift requires HR to define that interval; approving this work did not introduce guessed noon-based rules.

## Verification

The initial six focused regression tests reproduced the reported gaps before the fix. Permanent coverage now includes early punches, stale punches, archived attendance, recorded short sessions, later shifts, attendance-prioritized suggestions, clock-out, missing attendance attestation, replacement slots, expired slots, half-day leave, deduplicated management alerts and an HTTP late-output report with its saved reason and actor.

Older downstream fixtures were aligned with the existing rules: idempotency output now has an approved slot, missing-attendance tests distinguish missing rows from archived rows, below-target completion supplies its required explanation, and repair fixtures use deterministic production dates instead of random shift coverage. Production safeguards were not weakened to make these tests pass.

Backend verification uses the isolated test database:

```sh
docker exec ogami-api vendor/bin/phpunit -c phpunit.pricing.20261004.xml tests/Feature --filter 'WorkOrderAttendanceSafetyTest|WorkOrderExecutionReadinessTest|WorkOrderCrewTest|ProductionResourceChangeTest|ProductionResourceReservationTest|WorkOrderScheduleReminderTest|WorkOrderInlineReworkTest|WorkOrderOutputIdempotencyKeyTest'
```

Frontend verification covers the crew form, Start dialog, assigned-worker page and schedule page:

```sh
cd spa
npx vitest run src/pages/production/work-orders/CrewPanel.test.tsx src/pages/production/work-orders/StartProductionDialog.test.tsx src/pages/self-service/work-orders.test.tsx src/pages/production/schedule.test.tsx
node e2e-real/work-order-start-readiness.cjs
```

The live headless Playwright script exercises Production, Warehouse, Sales and Catherine's assigned employee account. It checks management restrictions, the worker's read-only task and blocked recording state, explicit rescheduling, suggested and manual timing, approved timing on reopen, and desktop/mobile rendering. It does not approve a slot or start production on the manual B2B WO. The WO's lifecycle, actual start, quantities and batch stay unchanged.

Targeted PHP formatting, ESLint, TypeScript and the production build are part of the gate. The Impeccable detector returned no issues for the changed form and dialog; desktop and mobile screenshots were inspected. The existing host scheduler timer was active, and its existing reminder command remains registered every minute.

Final results: 97 backend tests and 551 assertions passed; 32 frontend tests passed across four files. Headless browser checks passed for all four roles. Targeted PHP formatting, ESLint, TypeScript and the production build passed. No outstanding implementation steps remain for this approved scope.
