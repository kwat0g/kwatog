# Work-order Start readiness — 2026-10-06

## Behavior

Start and Resume now open a readiness review. A read-only server endpoint checks lifecycle state, material coverage, subassembly readiness, quality setup or release, physical equipment availability, the approved production interval, crew eligibility, and attendance. Actual Start and Resume retain their transaction-locked guards; the preview grants no permission and changes no stock.

Valid timing permits production only after required physical-presence confirmation. A future, expired, missing, or insufficient slot offers an available replacement and eligible Production employees, with one output recorder. Approval changes timing and crew through the existing version-checked review transaction and then rechecks readiness. It does not start production. The separate Review production slot action is hidden on the standard WO details card. Manual dates remain available through Choose another time, including existing holiday authorization rules.

Suggestions reuse the capacity planner's shift-boundary and equipment-window search. They preserve assigned machine and mold, existing crew size, departmental employee restrictions, HR calendars, leave and absence exclusions, daily capacity, mold limits, reservations, and the WO deadline. Search is bounded to 90 days. Suggestions start from the current minute when resources and crew are available and reserve five minutes of start tolerance; these minutes also count against crew coverage and equipment capacity. Suggestions create no reservation until approval. Changes made after preview are rejected or revalidated by the existing approval/start guards.

## Validation

- Backend combined gate: 42 tests, 180 assertions passed across execution readiness, resource reservations, start QC, occupancy, and capacity planning. A subsequent focused execution-readiness gate passed 8 tests and 51 assertions, including off-shift crew replacement, early-start rejection, and successful production start inside the newly approved slot.
- Frontend: 24 tests passed across StartProductionDialog, CrewPanel, and WO details. Covered preflight before Start, attendance confirmation, approval without automatic Start, renewed readiness checks, and failure recovery.
- Targeted ESLint, PHP formatting, TypeScript and production build passed.
- Live headless Playwright checked Production, Warehouse, and Sales accounts against the real application. Production saw Start readiness, suggested timing/crew, manual fallback and usable desktop/mobile layout without errors or horizontal page overflow. Warehouse and Sales were denied management readiness and suggestions with HTTP 403.
- Live checks did not approve or start WO-202610-0014. Its status, actual start, production quantities and batch number remained unchanged.

Reproduce the read-only live checks from the repository root:

```sh
node spa/e2e-real/work-order-start-readiness.cjs
```

Backend checks use the isolated `phpunit.pricing.20261004.xml` test database, not the application database. The live script is tied to the currently confirmed manual-test WO and uses the existing test-role accounts.

## Manual scheduling refinement

Choose another time now opens a compact form with a prefilled start and an automatically calculated finish, using the suggested or reserved duration. A collapsed option allows a longer finish. Current workers remain visible; availability is checked after a 300 ms debounce and unavailable workers block approval. Worker replacement and holiday authorization stay optional. Common scheduling reasons use a dropdown; only Other reason requires explanation. Approval still updates scheduling without starting production, and existing server checks remain authoritative.

Live verification exposed an existing request serialization error: GET worker lookup sent holiday authorization as strings `false` or `true`, which Laravel rejected. The shared API boundary now sends numeric `0` or `1`, preserving the boolean validation rule.

The focused frontend gate passed 40 tests across five files, including prefilled timing, finish recalculation, unavailable workers, custom duration, required Other explanation, request serialization, and existing Start/rework behavior. Targeted ESLint, TypeScript, and the production build passed. Headless Playwright checked the real Production account on desktop and mobile, including reason selection, optional worker changes, optional finish changes, and returning to suggested timing. Warehouse and Sales remained forbidden from management readiness and suggestions. No live approval, production start, stock change, or WO mutation occurred.

## Approved schedules and preferred production day

Start now retains approved scheduling across dialog reopenings. A valid future slot reports waiting, rather than requiring rescheduling, while keeping early Start blocked. Future slots still undergo duration, equipment-reservation and crew-coverage checks. Expired or insufficient slots remain blocked. Rescheduling opens only through an explicit action available to authorized users; approving a change returns to Start readiness without starting production.

Reschedule includes a preferred production day in the plant timezone. The server validates dates from today through the existing 90-day planning horizon and searches from the selected day while preserving the WO deadline, machine/mold reservations, capacity, eligibility and HR calendars. If the earliest feasible slot falls on a later day, it is labelled Next available schedule with an explanation and requires approval. The selected date never silently changes. Manual time selection remains available. Old proposals are hidden while a different day's lookup is pending.

Validation: 43 frontend tests passed across the five affected files. The backend safety gate passed 29 tests and 165 assertions covering readiness, resource reservations, QC Start gates and occupancy. A subsequent focused readiness run passed 11 tests and 84 assertions, including future-slot duration/crew revalidation and retention of an approved schedule. TypeScript, production build, targeted ESLint and PHP formatting passed. Live headless checks with Production, Warehouse and Sales verified explicit rescheduling, preferred-day lookup, desktop/mobile layout and preservation of the live WO. Live checks perform no business mutations.

## Immediate scheduling and upcoming reminders

Available replacement slots can begin in the current minute without a fixed five-minute wait. Choose another time includes Use current time and calculates the finish automatically. Approval rechecks readiness and does not start production. The actual Start transaction still validates equipment ownership, reservations, remaining duration, crew coverage, attendance, materials and QC release. Insufficient-time messages state minutes needed, minutes left and the Reschedule action.

The existing minute scheduler runs `production:notify-upcoming-work-orders`. It sends in-app reminders to PPC, Production managers and the current eligible Production crew within 30 minutes of an approved slot, at its start, and after five minutes without a start. Worker links open My work orders; management links open the production WO. Notifications respect user preferences and deduplicate each recipient, reservation, start time and reminder stage. Released, unapproved, expired and started slots are excluded. A reschedule uses the latest reservation and current crew. Reminders never start production or grant access.

Validation: 32 backend tests and 210 assertions passed across reminders, execution readiness, reservations and the notification catalog. Covered duplicate scheduler runs, recipient restrictions, stage transitions, replaced reservations, changed crews, immediate readiness after approval, early-start rejection and successful execution inside the approved slot. A subsequent reminder gate passed 3 tests and 31 assertions after tailoring worker messages to contact Production management. The future-day execution fixture refreshes its authenticated actor's activity after clock advancement rather than disabling session timeout. All 58 frontend tests across six files passed, including the notification catalog/metadata contract. PHP formatting, targeted ESLint, TypeScript and production build passed. Live headless Playwright verified Use current time, the immediate read-only suggestion, Production UI on desktop/mobile and denied Warehouse/Sales access without changing the manual-test WO. Separate live checks confirmed the reminder appeared in the Production and assigned employee inboxes and both role-specific links opened the correct WO. Durable scheduler evidence recorded successful reminder runs and no duplicate messages across repeated ticks. The host's existing system timer runs the scheduler every minute; no additional worker is required.
