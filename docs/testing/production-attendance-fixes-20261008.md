# Production attendance connection fixes

The approved attendance audit findings are implemented. Clock-in evidence now stays separate from HR shift eligibility: an employee can be clocked in while remaining unavailable for an early WO start. Readiness names that coverage problem rather than reporting missing attendance. Start offers direct worker editing and a worker refresh without generating another schedule proposal.

Open overnight punch sessions resolve the attendance work date and stored shift, including after a next-day assignment rotation. Accepted clock-in after a clock-out restores current Production presence without deleting daily payroll first-in/last-out. Manual corrections, archived attendance, recorded absence, leave and mismatched shift dates remain authoritative.

Worker selection batches accounts, shifts, attendance, punches, leave and other crew reservations. The regression scenario dropped from 1,809 database queries to 16 for 41 employees. Public reservation checks still query current database state; batching is limited to the selection read.

Attendance, leave, employment or account changes issue durable in-app staffing notices to Production management and PPC. Running WO details show staffing warnings. New setup/start/resume operations require all assigned workers to be present or covered by the existing valid management attestation. Operation output after crew availability changes requires a management reason for earlier output; machines are not automatically stopped and crews are not silently replaced.

HR shift and calendar changes reject broken coverage for committed WOs and require review of active runs whose reservation expired. Recorded app punches and locked payroll protect the referenced shift rules. Retroactive assignment changes cannot replace a recorded punch date. Future-effective assignments remain available. Mutable attendance without recorded punches retains the existing HR recalculation behavior.

Missing explicit HR working days receive a clear calendar warning. Approved overtime hours alone do not define production shift coverage; HR must provide a shift covering the full production interval.

Validation: 113 backend tests / 675 assertions passed across production attendance safety, crew assignment, punch sessions/import merging, manual correction and HR hardening. A focused connection suite also passed 9 tests / 35 assertions, including future half-day leave, account deactivation and notice deduplication. 52 frontend tests passed; TypeScript/Vite production build, scoped ESLint, PHP formatting and diff whitespace checks passed. Desktop 1440×900 and mobile 390×844 checks confirmed accurate blocking copy, usable worker editor and no horizontal overflow. Browser checks intercepted all API requests; no live test mutations were made.

Limitations: no physical time-clock/device test or production-scale bulk HR edit load test. The work does not infer overtime clock intervals, automatically stop equipment, or revise customer delivery commitments. Two existing frontend test fixtures were completed to clear build type errors; one exact-label assertion was adjusted for its existing required-field marker.
